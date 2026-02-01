<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$userId = $_SESSION['user_id'];
$isAdmin = is_admin();
$user = null;
$rentals = [];
$totalSpent = 0.0;
$profileFlash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);
$issueErrors = [];
$now = date('Y-m-d');
$activeRental = null;
$changeLog = [];
$changeLogError = null;
$transactions = [];
$transactionsError = null;
$issueReports = [];
$issueReportsError = null;
$adminIssueErrors = [];
$accountErrors = [];

function type_label(string $type): string
{
    return ucfirst($type);
}

function transaction_label(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'topup') {
        return 'Credit added';
    }
    if ($type === 'rental') {
        return 'Rent payment';
    }
    return ucfirst($type);
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_issue') {
    $issueId = (int) ($_POST['issue_id'] ?? 0);
    $status = strtolower(trim($_POST['status'] ?? 'open'));
    $notes = trim($_POST['admin_notes'] ?? '');
    $allowedStatuses = ['open', 'closed'];

    if ($issueId <= 0) {
        $adminIssueErrors[] = 'Invalid issue.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $adminIssueErrors[] = 'Invalid status.';
    }
    if (strlen($notes) > 2000) {
        $adminIssueErrors[] = 'Notes are too long.';
    }

    if (!$adminIssueErrors) {
        $stmt = mysqli_prepare($conn, "UPDATE issues SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssii", $status, $notes, $userId, $issueId);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($ok) {
                $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Issue updated successfully.'];
                header('Location: profile.php#issue-reports');
                exit;
            }
            $adminIssueErrors[] = 'Issue update failed.';
        } else {
            $adminIssueErrors[] = 'Issue update failed.';
        }
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_issue') {
    $rentalId = (int) ($_POST['rental_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($rentalId <= 0) {
        $issueErrors[] = 'Invalid lease.';
    }
    if (strlen($description) < 10) {
        $issueErrors[] = 'Description is too short (minimum 10 characters).';
    }

    if (!$issueErrors) {
        $stmt = mysqli_prepare($conn, "SELECT id, property_id FROM rentals WHERE id = ? AND user_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
        mysqli_stmt_bind_param($stmt, "iiss", $rentalId, $userId, $now, $now);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $activeRow = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$activeRow) {
            $issueErrors[] = 'The selected lease is not active.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO issues (user_id, property_id, rental_id, description) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iiis", $userId, $activeRow['property_id'], $rentalId, $description);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Issue reported successfully.'];
                header('Location: profile.php#active-rental');
                exit;
            }
            $issueErrors[] = 'Issue report failed.';
        }
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    $password = $_POST['password'] ?? '';
    $confirmation = trim($_POST['confirm'] ?? '');

    if ($password === '') {
        $accountErrors[] = 'Enter your password.';
    }
    if ($confirmation !== 'DELETE') {
        $accountErrors[] = 'Type DELETE to confirm.';
    }

    if (!$accountErrors) {
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?"); // Use password instead of password_hash if plain, but code uses hash logic usually.
        // Original schema used 'password' column but hash function.
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
        } else {
            $row = null;
        }

        // Need salt to verify?
        // Ah, original used SHA512 manually.
        $stmtSalt = mysqli_prepare($conn, "SELECT salt, password FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmtSalt, "i", $userId);
        mysqli_stmt_execute($stmtSalt);
        $resSalt = mysqli_stmt_get_result($stmtSalt);
        $userSaltRow = mysqli_fetch_assoc($resSalt);
        mysqli_stmt_close($stmtSalt);

        if (!$userSaltRow) {
            $accountErrors[] = 'User not found.';
        } else {
            $check_password = hash('sha512', $password . $userSaltRow['salt']);
            if ($check_password !== $userSaltRow['password']) {
                $accountErrors[] = 'Password confirmation failed.';
            }
        }
    }

    if (!$accountErrors) {
        mysqli_begin_transaction($conn);
        $deleteOk = true;
        $errorText = '';

        $stmt = mysqli_prepare($conn, "DELETE FROM issues WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $userId);
            $deleteOk = mysqli_stmt_execute($stmt);
            $errorText = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }

        if ($deleteOk) {
            $stmt = mysqli_prepare($conn, "DELETE FROM rentals WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $userId);
                $deleteOk = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $deleteOk = false;
                $errorText = mysqli_error($conn);
            }
        }

        if ($deleteOk) {
            $stmt = mysqli_prepare($conn, "DELETE FROM transactions WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $userId);
                $deleteOk = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $deleteOk = false;
                $errorText = mysqli_error($conn);
            }
        }

        if ($deleteOk) {
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $userId);
                $deleteOk = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $deleteOk = false;
                $errorText = mysqli_error($conn);
            }
        }

        if ($deleteOk) {
            mysqli_commit($conn);
            $_SESSION = [];
            session_destroy();
            header('Location: index.php');
            exit;
        }

        mysqli_rollback($conn);
        $accountErrors[] = 'Account deletion failed: ' . $errorText;
    }
}

$stmt = mysqli_prepare($conn, "SELECT name, email, credit FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if ($isAdmin) {
    $stmt = mysqli_prepare($conn, "SELECT c.action, c.entity, c.entity_id, c.details, c.created_at, u.name AS admin_name FROM change_log c JOIN users u ON c.admin_id = u.id ORDER BY c.created_at DESC LIMIT 50");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $changeLog[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $changeLogError = 'Change log is not available.';
    }

    $stmt = mysqli_prepare($conn, "SELECT i.id, i.description, i.status, i.admin_notes, i.reviewed_at, i.created_at, u.name AS user_name, u.email AS user_email, p.name AS property_name, p.type AS property_type, r.start_date AS rental_start, reviewer.name AS reviewer_name FROM issues i JOIN users u ON i.user_id = u.id JOIN properties p ON i.property_id = p.id LEFT JOIN rentals r ON i.rental_id = r.id LEFT JOIN users reviewer ON i.reviewed_by = reviewer.id ORDER BY i.created_at DESC");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $issueReports[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $issueReportsError = 'Issue reports are not available.';
    }
} else {
    // Active Lease
    $stmt = mysqli_prepare($conn, "SELECT r.id, r.property_id, r.start_date, r.end_date, r.months, p.name AS property_name, p.type AS property_type FROM rentals r JOIN properties p ON r.property_id = p.id WHERE r.user_id = ? AND r.start_date <= ? AND (r.end_date IS NULL OR r.end_date >= ?) ORDER BY r.start_date DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "iss", $userId, $now, $now);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $activeRental = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT r.id, r.start_date, r.end_date, r.months, r.total_cost, p.name AS property_name, p.type AS property_type FROM rentals r JOIN properties p ON r.property_id = p.id WHERE r.user_id = ? ORDER BY r.start_date DESC");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rentals[] = $row;
            if (isset($row['total_cost'])) {
                $totalSpent += (float) $row['total_cost'];
            }
        }
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT type, amount, balance_after, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $transactions[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $transactionsError = 'Transactions are not available.';
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-8 mx-auto" style="max-width: 900px;" id="main-content">
        <h1 class="display-5 fw-bold text-dark mb-4">
            <?php echo $isAdmin ? 'Admin Dashboard' : 'Profile'; ?>
        </h1>
        <div class="bg-white rounded-4 shadow-sm p-5 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <p class="text-muted mb-1">Name</p>
                    <h2 class="h4 fw-bold mb-0">
                        <?php echo htmlspecialchars($user['name'] ?? $_SESSION['user_name']); ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                    </p>
                </div>
                <div class="text-end">
                    <?php if ($isAdmin): ?>
                        <p class="text-muted mb-1">Role</p>
                        <h2 class="h4 fw-bold mb-0">Administrator</h2>
                    <?php else: ?>
                        <p class="text-muted mb-1">Total Rent Paid</p>
                        <h2 class="h4 fw-bold text-danger mb-0">$
                            <?php echo number_format($totalSpent, 2); ?>
                        </h2>
                        <div class="mt-3">
                            <p class="text-muted mb-1">Wallet Credit</p>
                            <h2 class="h4 fw-bold text-success mb-0">$
                                <?php echo number_format((float) ($user['credit'] ?? 0), 2); ?>
                            </h2>
                            <a href="wallet.php" class="btn btn-outline-secondary btn-sm mt-2">Open wallet</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <hr>
            <p class="small text-muted mb-0">Status: Verified Tenant.</p>
        </div>

        <?php if ($profileFlash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($profileFlash['type']); ?>">
                <?php echo htmlspecialchars($profileFlash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="bg-white rounded-4 shadow-sm p-4 mb-4" id="issue-reports">
                <h2 class="h5 fw-bold mb-3">Maintenance Issues</h2>

                <?php if ($adminIssueErrors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($adminIssueErrors as $error): ?>
                            <div>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($issueReportsError): ?>
                    <div class="alert alert-warning">
                        <?php echo htmlspecialchars($issueReportsError); ?>
                    </div>
                <?php elseif (!$issueReports): ?>
                    <p class="text-muted mb-0">No reports yet.</p>
                <?php else: ?>
                    <?php foreach ($issueReports as $report): ?>
                        <?php
                        $reportStatus = strtolower(trim($report['status']));
                        $statusClass = $reportStatus === 'closed' ? 'bg-success' : 'bg-warning text-dark';
                        $reviewedLabel = $report['reviewed_at']
                            ? 'Reviewed ' . $report['reviewed_at'] . ($report['reviewer_name'] ? ' by ' . $report['reviewer_name'] : '')
                            : 'Not reviewed yet';
                        ?>
                        <div class="border rounded-4 p-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($report['property_name']); ?> (
                                        <?php echo htmlspecialchars(type_label($report['property_type'])); ?>)
                                    </div>
                                    <div class="text-muted small">Reported by
                                        <?php echo htmlspecialchars($report['user_name']); ?> (
                                        <?php echo htmlspecialchars($report['user_email']); ?>)
                                    </div>
                                    <div class="text-muted small">Reported on
                                        <?php echo htmlspecialchars($report['created_at']); ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo $statusClass; ?> rounded-pill align-self-start">
                                    <?php echo htmlspecialchars(ucfirst($reportStatus)); ?>
                                </span>
                            </div>
                            <div class="mt-3">
                                <div class="small text-muted">Issue Description</div>
                                <div class="fw-bold">
                                    <?php echo nl2br(htmlspecialchars($report['description'])); ?>
                                </div>
                            </div>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="action" value="update_issue">
                                <input type="hidden" name="issue_id"
                                    value="<?php echo htmlspecialchars((string) $report['id']); ?>">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="open" <?php echo $reportStatus === 'open' ? 'selected' : ''; ?>>Open
                                            </option>
                                            <option value="closed" <?php echo $reportStatus === 'closed' ? 'selected' : ''; ?>>Closed
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold">Admin notes</label>
                                        <textarea name="admin_notes" class="form-control" rows="2" maxlength="2000"
                                            placeholder="Add internal notes."><?php echo htmlspecialchars($report['admin_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                                    <div class="small text-muted">
                                        <?php echo htmlspecialchars($reviewedLabel); ?>
                                    </div>
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Save</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4">
                <h2 class="h5 fw-bold mb-3">Change log</h2>
                <?php if ($changeLogError): ?>
                    <div class="alert alert-warning">
                        <?php echo htmlspecialchars($changeLogError); ?>
                    </div>
                <?php elseif (!$changeLog): ?>
                    <p class="text-muted mb-0">No changes recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Admin</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Entity</th>
                                    <th scope="col">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($changeLog as $entry): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($entry['created_at']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($entry['admin_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(ucfirst($entry['action'])); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($entry['entity']); ?> #
                                            <?php echo htmlspecialchars((string) $entry['entity_id']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($entry['details']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-4 shadow-sm p-4 mb-3" id="active-rental">
                <h2 class="h5 fw-bold mb-3">Active Lease</h2>

                <?php if ($issueErrors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($issueErrors as $error): ?>
                            <div>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($activeRental): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Property</div>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($activeRental['property_name']); ?>
                            </div>
                            <div class="text-muted small">Type:
                                <?php echo htmlspecialchars(type_label($activeRental['property_type'])); ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Lease Start</div>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($activeRental['start_date']); ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Lease End</div>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($activeRental['end_date'] ?? 'Ongoing'); ?>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h3 class="h6 fw-bold mb-2">Report a maintenance issue</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="report_issue">
                        <input type="hidden" name="rental_id"
                            value="<?php echo htmlspecialchars((string) $activeRental['id']); ?>">
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" maxlength="500"
                                placeholder="Describe the maintenance issue." required></textarea>
                        </div>
                        <button class="btn btn-outline-danger" type="submit">Submit Request</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No active leases right now.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4">
                <h2 class="h5 fw-bold mb-3">Rental History</h2>
                <?php if (!$rentals): ?>
                    <p class="text-muted mb-0">No past rentals.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Property</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Start Date</th>
                                    <th scope="col">End Date</th>
                                    <th scope="col">Duration</th>
                                    <th scope="col">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rentals as $rental): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($rental['property_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(type_label($rental['property_type'])); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($rental['start_date']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($rental['end_date'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars((string) ($rental['months'] ?? 0)); ?> months
                                        </td>
                                        <td>$
                                            <?php echo htmlspecialchars(number_format((float) $rental['total_cost'], 2)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4 mt-4">
                <h2 class="h5 fw-bold mb-3">Transactions</h2>
                <?php if ($transactionsError): ?>
                    <div class="alert alert-warning">
                        <?php echo htmlspecialchars($transactionsError); ?>
                    </div>
                <?php elseif (!$transactions): ?>
                    <p class="text-muted mb-0">No transactions yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Balance after</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <?php
                                    $amount = (float) $transaction['amount'];
                                    $amountLabel = ($amount > 0 ? '+' : '') . number_format($amount, 2);
                                    $amountClass = $amount >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($transaction['created_at']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(transaction_label($transaction['type'])); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction['description']); ?>
                                        </td>
                                        <td class="<?php echo $amountClass; ?>">$
                                            <?php echo htmlspecialchars($amountLabel); ?>
                                        </td>
                                        <td>$
                                            <?php echo htmlspecialchars(number_format((float) $transaction['balance_after'], 2)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4 mt-4" id="delete-account">
                <h2 class="h5 fw-bold mb-2">Delete account</h2>
                <p class="text-muted small">This permanently removes your profile and all data.</p>

                <?php if ($accountErrors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($accountErrors as $error): ?>
                            <div>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Delete your account permanently?');">
                    <input type="hidden" name="action" value="delete_account">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="delete-password">Confirm password</label>
                        <input type="password" id="delete-password" name="password" class="form-control"
                            autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="delete-confirm">Type DELETE to confirm</label>
                        <input type="text" id="delete-confirm" name="confirm" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger">Delete my account</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>