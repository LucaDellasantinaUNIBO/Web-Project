<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

if (is_admin()) {
    include 'includes/header.php';
    ?>
    <div class="container py-5" style="max-width: 720px;">
        <div class="alert alert-warning">
            Admin accounts cannot rent properties.
        </div>
        <a class="btn btn-outline-secondary" href="admin.php">Go to admin panel</a>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

$errors = [];
$success = null;
$property = null;

function normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['available', 'rented', 'maintenance', 'renovation'], true)) {
        return $status;
    }
    return 'available';
}

function type_label(string $type): string
{
    return ucfirst($type);
}

$propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $propertyId = (int) ($_POST['property_id'] ?? 0);
}

if ($propertyId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM properties WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $propertyId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $property = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

if (!$property) {
    $errors[] = 'Property not found.';
}

$userCredit = 0.00;
if (isset($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT credit FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $userCredit = (float) $row['credit'];
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $months = (int) ($_POST['months'] ?? 0);
    $startDateInput = $_POST['start_date'] ?? date('Y-m-d');
    $status = normalize_status($property['status']);
    $estimatedCost = 0.0;

    if ($status !== 'available') {
        $errors[] = 'Property not available.';
    }
    if ($months <= 0) {
        $errors[] = 'Enter lease duration in months.';
    }

    // Validate Start Date
    $startDate = DateTime::createFromFormat('Y-m-d', $startDateInput);
    if (!$startDate) {
        $errors[] = 'Invalid start date.';
    }

    if ($property) {
        $estimatedCost = round((float) $property['monthly_price'] * $months, 2);
        if ($userCredit < $estimatedCost) {
            $errors[] = 'Insufficient credit. Please add funds to your wallet.';
        }
    }

    if (!$errors) {
        $start = $startDate->format('Y-m-d');
        $endDate = clone $startDate;
        $endDate->modify("+$months months");
        $end = $endDate->format('Y-m-d');

        $cost = $estimatedCost;
        $newBalance = $userCredit - $cost;

        mysqli_begin_transaction($conn);

        $stmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, property_id, start_date, end_date, months, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iisssd", $_SESSION['user_id'], $propertyId, $start, $end, $months, $cost);
        $insertOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE users SET credit = credit - ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "di", $cost, $_SESSION['user_id']);
        $walletUpdateOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $transactionOk = true;
        $transactionStmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, 'rental', ?, ?, ?)");
        if ($transactionStmt) {
            $amount = 0 - $cost;
            $description = "Rent for {$property['name']}.";
            mysqli_stmt_bind_param($transactionStmt, "idds", $_SESSION['user_id'], $amount, $newBalance, $description);
            $transactionOk = mysqli_stmt_execute($transactionStmt);
            mysqli_stmt_close($transactionStmt);
        }

        $updateOk = false;
        if ($insertOk) {
            $stmt = mysqli_prepare($conn, "UPDATE properties SET status = 'rented' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $propertyId);
            $updateOk = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($insertOk && $updateOk && $walletUpdateOk && $transactionOk) {
            mysqli_commit($conn);
            $success = 'Rental confirmed! You can see the summary in your profile.';
            $property['status'] = 'rented';
            $userCredit -= $cost;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Booking failed.';
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" id="main-content">
        <div class="row g-4">
            <div class="col-lg-7">
                <h1 class="fw-bold mb-3">Rent this Property</h1>
                <p class="text-muted">Lease this home instantly.</p>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($property): ?>
                    <div class="form-section p-4 shadow-sm mb-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h2 class="h5 fw-bold mb-1">
                                    <?php echo htmlspecialchars($property['name']); ?>
                                </h2>
                                <p class="text-muted mb-0">Type:
                                    <?php echo htmlspecialchars(type_label($property['type'])); ?>
                                </p>
                            </div>
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars(ucfirst(normalize_status($property['status']))); ?>
                            </span>
                        </div>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small text-muted">Location</div>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($property['location']); ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small text-muted">Rooms</div>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars((string) $property['rooms']); ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small text-muted">Monthly Rent</div>
                                <div class="fw-bold">€
                                    <?php echo htmlspecialchars((string) $property['monthly_price']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section p-4 shadow-sm">
                        <form method="post" class="booking-form">
                            <input type="hidden" name="property_id"
                                value="<?php echo htmlspecialchars((string) $propertyId); ?>">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold" for="start-date-input">Start Date</label>
                                    <input id="start-date-input" type="date" name="start_date" class="form-control"
                                        value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold" for="months-input">Duration (Months)</label>
                                    <input id="months-input" type="number" min="1" max="60" name="months"
                                        class="form-control" value="12" required>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted">Total Cost</div>
                                    <div class="display-6 fw-bold text-success" id="price-estimate"
                                        data-monthly-price="<?php echo htmlspecialchars((string) ((float) $property['monthly_price'])); ?>">
                                        €0.00</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted">Lease End</div>
                                    <div class="fw-bold" id="end-estimate">--</div>
                                </div>
                            </div>
                            <div class="mb-4 mt-3">
                                <div class="small text-muted">Wallet balance</div>
                                <div class="fw-bold">€
                                    <?php echo number_format($userCredit, 2); ?>
                                </div>
                                <div class="small text-muted mt-1">Rent is charged upfront.</div>
                                <div id="credit-warning" class="alert alert-warning mt-3 d-none">
                                    Not enough credit to confirm this rental.
                                    <a class="alert-link" href="wallet.php">Open wallet</a>
                                </div>
                            </div>
                            <button id="confirm-booking" type="submit" class="btn btn-primary w-100 mt-2">Confirm
                                Rental</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <div class="form-section p-4 shadow-sm h-100 bg-light">
                    <h3 class="h5 fw-bold">Why rent with us?</h3>
                    <ul class="list-unstyled mt-3">
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Verified Properties</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Secure Payments</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> 24/7 Support</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="creditModal" tabindex="-1" role="dialog" aria-labelledby="creditModalLabel"
        aria-hidden="true" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="h5 modal-title" id="creditModalLabel">Insufficient credit</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Your wallet balance is not enough to cover this rental. Please add funds to continue.
                </div>
                <div class="modal-footer">
                    <a href="wallet.php" class="btn btn-primary">Open wallet</a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    (function () {
        var monthsInput = document.getElementById('months-input');
        var dateInput = document.getElementById('start-date-input');
        var estimate = document.getElementById('price-estimate');
        var endEstimate = document.getElementById('end-estimate');
        var form = document.querySelector('.booking-form');
        var creditWarning = document.getElementById('credit-warning');
        var creditModal = document.getElementById('creditModal');

        if (!monthsInput || !estimate || !endEstimate) {
            return;
        }

        var monthlyPrice = parseFloat(estimate.dataset.monthlyPrice || '0');
        var userCredit = <?php echo json_encode((float) $userCredit); ?>;

        var updateCreditState = function (total) {
            var insufficient = total > userCredit;
            if (creditWarning) {
                creditWarning.classList.toggle('d-none', !insufficient);
            }
            return insufficient;
        };

        var updateEstimate = function () {
            var months = parseInt(monthsInput.value, 10);
            if (isNaN(months) || months <= 0) {
                estimate.textContent = '€0.00';
                endEstimate.textContent = '--';
                updateCreditState(0);
                return;
            }
            var total = months * monthlyPrice;
            estimate.textContent = '€' + total.toFixed(2);

            var startDateStr = dateInput.value;
            if (startDateStr) {
                var startDate = new Date(startDateStr);
                var endDate = new Date(startDate.setMonth(startDate.getMonth() + months));
                endEstimate.textContent = endDate.toLocaleDateString();
            } else {
                endEstimate.textContent = '--';
            }

            updateCreditState(total);
        };

        monthsInput.addEventListener('input', updateEstimate);
        dateInput.addEventListener('change', updateEstimate);

        if (form) {
            form.addEventListener('submit', function (event) {
                var months = parseInt(monthsInput.value, 10);
                var total = 0;
                if (!isNaN(months) && months > 0) {
                    total = months * monthlyPrice;
                }
                if (total > userCredit) {
                    event.preventDefault();
                    if (creditModal && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(creditModal).show();
                    }
                }
            });
        }
        updateEstimate();
    })();
</script>

<?php include 'includes/footer.php'; ?>