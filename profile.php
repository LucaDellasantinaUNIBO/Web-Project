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
$profileErrors = [];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $cardNumber = str_replace(' ', '', trim($_POST['card_number'] ?? ''));
    $cardCvc = trim($_POST['card_cvc'] ?? '');
    $cardExpiry = trim($_POST['card_expiry'] ?? '');

    if (strlen($phone) > 20) {
        $profileErrors[] = 'Phone number is too long.';
    }
    if (strlen($city) > 100) {
        $profileErrors[] = 'City name is too long.';
    }
    if ($cardNumber !== '' && !preg_match('/^\d{13,19}$/', $cardNumber)) {
        $profileErrors[] = 'Invalid card number.';
    }
    if ($cardCvc !== '' && !preg_match('/^\d{3,4}$/', $cardCvc)) {
        $profileErrors[] = 'Invalid CVV.';
    }
    if ($cardExpiry !== '' && !preg_match('/^\d{2}\/\d{2}$/', $cardExpiry)) {
        $profileErrors[] = 'Invalid expiry (use MM/YY format).';
    }

    if (!$profileErrors) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET phone = ?, city = ?, mock_card_number = ?, mock_cvc = ?, mock_expiry = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssssi", $phone, $city, $cardNumber, $cardCvc, $cardExpiry, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($ok) {
            $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Profile updated successfully.'];
            header('Location: profile.php');
            exit;
        }
        $profileErrors[] = 'Profile update failed.';
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

$stmt = mysqli_prepare($conn, "SELECT name, email, credit, phone, city, mock_card_number, mock_cvc, mock_expiry FROM users WHERE id = ?");
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
        <h1 class="display-6 fw-bold text-dark mb-4">
            <?php echo $isAdmin ? 'Admin Dashboard' : 'Account Overview'; ?>
        </h1>

        <div class="row">
            <?php if (!$isAdmin): ?>
                <?php include 'includes/user_sidebar.php'; ?>
            <?php endif; ?>

            <div class="<?php echo $isAdmin ? 'col-12' : 'col-lg-9'; ?>">
                <div class="bg-white rounded-4 shadow-sm p-5 mb-4">
                    <h2 class="h5 fw-bold mb-1">Personal Information</h2>
                    <p class="text-muted mb-4">Manage your personal details and contact preferences.</p>

                    <?php if ($profileErrors): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($profileErrors as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['no_card'])): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please add your card details below to recharge your wallet.
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">Full Name</label>
                                <div class="p-3 bg-light rounded-3 border-0">
                                    <i class="far fa-user text-muted me-2"></i>
                                    <?php echo htmlspecialchars($user['name'] ?? $_SESSION['user_name']); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">Email Address</label>
                                <div class="p-3 bg-light rounded-3 border-0">
                                    <i class="far fa-envelope text-muted me-2"></i>
                                    <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold" for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="form-control" 
                                    value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                    placeholder="Enter phone number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold" for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control" 
                                    value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" 
                                    placeholder="Enter city">
                            </div>
                            <div class="col-md-12">
                                <hr class="my-3">
                                <h3 class="h6 text-muted small fw-bold mb-3">Payment Information</h3>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold" for="card_number">Card Number</label>
                                <input type="text" id="card_number" name="card_number" class="form-control" 
                                    value="<?php echo htmlspecialchars($user['mock_card_number'] ?? ''); ?>" 
                                    placeholder="1234 5678 9012 3456" maxlength="19">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small fw-bold" for="card_expiry">Expiry (MM/YY)</label>
                                <input type="text" id="card_expiry" name="card_expiry" class="form-control" 
                                    value="<?php echo htmlspecialchars($user['mock_expiry'] ?? ''); ?>" 
                                    placeholder="12/25" maxlength="5">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small fw-bold" for="card_cvc">CVV</label>
                                <input type="text" id="card_cvc" name="card_cvc" class="form-control" 
                                    value="<?php echo htmlspecialchars($user['mock_cvc'] ?? ''); ?>" 
                                    placeholder="123" maxlength="3">
                            </div>
                        </div>
                        <div class="text-end mt-4">
                            <button type="submit" class="btn btn-success text-white px-4 rounded-3"><i
                                    class="fas fa-pen me-2"></i> Save Changes</button>
                        </div>
                        <script>
                        // Auto-format card number with spaces every 4 digits
                        document.getElementById('card_number').addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\s/g, '').replace(/\D/g, '');
                            let formatted = value.match(/.{1,4}/g)?.join(' ') || value;
                            e.target.value = formatted;
                        });
                        
                        // Auto-format expiry date
                        document.getElementById('card_expiry').addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\D/g, '');
                            if (value.length >= 2) {
                                // Validate month is between 01-12
                                let month = parseInt(value.slice(0, 2));
                                if (month > 12) {
                                    value = '12' + value.slice(2);
                                } else if (month === 0) {
                                    value = '01' + value.slice(2);
                                }
                                value = value.slice(0, 2) + '/' + value.slice(2, 4);
                            }
                            e.target.value = value;
                        });
                        
                        // Only allow digits in CVV
                        document.getElementById('card_cvc').addEventListener('input', function(e) {
                            e.target.value = e.target.value.replace(/\D/g, '');
                        });
                        </script>
                    </form>
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
                                                    <option value="open" <?php echo $reportStatus === 'open' ? 'selected' : ''; ?>>
                                                        Open
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
                    <!-- Transaction history moved to wallet.php -->


                    <!-- Admin dashboard implementation retained elsewhere -->
                    <div class="bg-white rounded-4 shadow-sm p-4 mt-4 d-none" id="delete-account">
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


                        <form method="post">
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
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>