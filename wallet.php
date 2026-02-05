<?php

include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$userId = $_SESSION['user_id'];
$success = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_funds') {
    $amountInput = trim($_POST['amount'] ?? '');
    $cardholderInput = trim($_POST['cardholder'] ?? '');
    $expiryInput = trim($_POST['expiry'] ?? '');
    $cardNumberRaw = trim($_POST['card_number'] ?? '');
    $cvcRaw = trim($_POST['cvc'] ?? '');

    $amountValue = (float) $amountInput;
    if ($amountInput === '' || $amountValue <= 0) {
        $errors[] = 'Enter a valid amount.';
    }

    if ($cardholderInput === '' || strlen($cardholderInput) < 3) {
        $errors[] = 'Cardholder name is too short.';
    }

    
    if (empty($errors)) {
        $stmt = mysqli_prepare($conn, "SELECT mock_card_number, mock_cvc, mock_expiry FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $userCardData = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        $dbCard = $userCardData['mock_card_number'] ?? '';
        $dbCvc = $userCardData['mock_cvc'] ?? '';
        $dbExp = $userCardData['mock_expiry'] ?? '';

        
        $cardNumberClean = str_replace(' ', '', $cardNumberRaw);

        if ($cardNumberClean !== $dbCard || $cvcRaw !== $dbCvc || $expiryInput !== $dbExp) {
            $errors[] = 'Payment failed: Card details do not match our records.';
        }
    }

    if (empty($errors)) {
        
        $stmt = mysqli_prepare($conn, "SELECT credit FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $userRow = mysqli_fetch_assoc($res);
        $currentCredit = $userRow ? (float) $userRow['credit'] : 0.0;
        mysqli_stmt_close($stmt);

        $newBalance = $currentCredit + $amountValue;

        mysqli_begin_transaction($conn);
        $updateOk = false;
        $stmt = mysqli_prepare($conn, "UPDATE users SET credit = credit + ? WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "di", $amountValue, $userId);
            $updateOk = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        $transactionOk = true;
        
        $transactionStmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, 'topup', ?, ?, 'Wallet credit')");
        if ($transactionStmt) {
            mysqli_stmt_bind_param($transactionStmt, "idd", $userId, $amountValue, $newBalance);
            $transactionOk = mysqli_stmt_execute($transactionStmt);
            mysqli_stmt_close($transactionStmt);
        }

        if ($updateOk && $transactionOk) {
            mysqli_commit($conn);
            $success = "Successfully added €" . number_format($amountValue, 2, ',', '.') . " to your wallet.";
        } else {
            mysqli_rollback($conn);
            $errors[] = "Failed to process transaction.";
        }
    }
}

$credit = 0.0;
$userCardNumber = '';
$stmt = mysqli_prepare($conn, "SELECT credit, mock_card_number FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if ($row = mysqli_fetch_assoc($result)) {
    $credit = (float) $row['credit'];
    $userCardNumber = $row['mock_card_number'] ?? '';
}
mysqli_stmt_close($stmt);

$totalPaid = 0.0;
$lastYearPaid = 0.0;
$startOfYear = date('Y-01-01');
$endOfYear = date('Y-12-31');
$startOfLastYear = date('Y-01-01', strtotime('-1 year'));
$endOfLastYear = date('Y-12-31', strtotime('-1 year'));

$stmt = mysqli_prepare($conn, "SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'rental' AND created_at >= ? AND created_at <= ?");
mysqli_stmt_bind_param($stmt, "iss", $userId, $startOfYear, $endOfYear);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $totalPaid = abs((float) $r['total']); 
}
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'rental' AND created_at >= ? AND created_at <= ?");
mysqli_stmt_bind_param($stmt, "iss", $userId, $startOfLastYear, $endOfLastYear);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $lastYearPaid = abs((float) $r['total']);
}
mysqli_stmt_close($stmt);

$spendingGrowth = 0;
if ($lastYearPaid > 0) {
    $spendingGrowth = (($totalPaid - $lastYearPaid) / $lastYearPaid) * 100;
} elseif ($totalPaid > 0) {
    $spendingGrowth = 100;
}

$lastPayment = 0.0;
$lastPaymentDate = '-';
$stmt = mysqli_prepare($conn, "SELECT amount, created_at FROM transactions WHERE user_id = ? AND type = 'rental' ORDER BY created_at DESC LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $lastPayment = abs((float) $r['amount']);
    $lastPaymentDate = date('M j, Y', strtotime($r['created_at']));
}
mysqli_stmt_close($stmt);

$transactions = [];
$stmt = mysqli_prepare($conn, "SELECT type, amount, balance_after, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $transactions[] = $row;
    }
}
mysqli_stmt_close($stmt);

include 'includes/header.php';
?>
<main>
    <div class="container py-5 mx-auto" style="max-width: 1000px;" id="main-content">
        <h1 class="display-6 fw-bold text-dark mb-4">Wallet</h1>

        <div class="row">
            <?php render_user_sidebar(); ?>

            <div class="col-lg-9">
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php foreach ($errors as $e)
                            echo htmlspecialchars($e) . "<br>"; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div
                            class="bg-success text-white rounded-4 p-4 h-100 shadow-sm position-relative overflow-hidden">
                            <div class="position-relative z-1">
                                <p class="mb-1 opacity-75">Total Balance Due</p>
                                <h2 class="h3 fw-bold mb-1">
                                    €<?php echo number_format(0, 2, ',', '.'); ?>
                                </h2>
                                <p class="small mb-0"><i class="fas fa-check-circle me-1"></i> All payments up to date
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="bg-white rounded-4 p-4 h-100 shadow-sm">
                            <p class="text-muted mb-1">Last Payment</p>
                            <h2 class="h3 fw-bold mb-1">€<?php echo number_format($lastPayment, 2, ',', '.'); ?></h2>
                            <p class="text-muted small mb-0"><i class="far fa-clock me-1"></i>
                                <?php echo $lastPaymentDate; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="bg-white rounded-4 p-4 h-100 shadow-sm">
                            <p class="text-muted mb-1">Total Paid (YTD)</p>
                            <h2 class="h3 fw-bold mb-1">€<?php echo number_format($totalPaid, 2, ',', '.'); ?></h2>
                            <p
                                class="<?php echo $spendingGrowth >= 0 ? 'text-success' : 'text-danger'; ?> small mb-0 fw-bold">
                                <?php echo ($spendingGrowth >= 0 ? '+' : '') . number_format($spendingGrowth, 1, ',', '.'); ?>%
                                from last year
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Current Balance & Add Funds (Custom Addition) -->
                <div class="bg-white rounded-4 shadow-sm p-4 mb-4 d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-0">Available Wallet Credit</p>
                        <h2 class="h3 fw-bold text-success mb-0">€<?php echo number_format($credit, 2, ',', '.'); ?>
                        </h2>
                    </div>
                    <button class="btn btn-primary" id="addFundsBtn">
                        <i class="fas fa-plus me-2"></i> Add Funds
                    </button>
                </div>

                <script>
                    document.getElementById('addFundsBtn').addEventListener('click', function () {
                        var hasCard = <?php echo json_encode(!empty($userCardNumber)); ?>;
                        if (!hasCard) {
                            
                            window.location.href = 'profile.php?no_card=1';
                        } else {
                            
                            var modal = new bootstrap.Modal(document.getElementById('addFundsModal'));
                            modal.show();
                        }
                    });
                </script>

                <!-- History -->
                <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                    <h2 class="h5 fw-bold mb-3">Payment History</h2>
                    <p class="text-muted mb-4 small">Your rental payment history for the last 6 months.</p>

                    <?php if (empty($transactions)): ?>
                        <p class="text-muted">No transactions found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-muted small fw-bold">Date</th>
                                        <th class="text-muted small fw-bold">Description</th>
                                        <th class="text-muted small fw-bold">Type</th>
                                        <th class="text-end text-muted small fw-bold">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $t): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                                            <td class="fw-medium text-dark"><?php echo htmlspecialchars($t['description']); ?>
                                            </td>
                                            <td>
                                                <?php if ($t['type'] === 'topup'): ?>
                                                    <span class="badge bg-light-success text-success rounded-pill">Credit
                                                        Added</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark rounded-pill">Payment</span>
                                                <?php endif; ?>
                                            </td>
                                            <td
                                                class="text-end fw-bold <?php echo $t['type'] === 'topup' ? 'text-success' : ''; ?>">
                                                <?php echo ($t['type'] === 'topup' ? '+' : '') . '€' . number_format($t['amount'], 2, ',', '.'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Funds Modal -->
    <div class="modal fade" id="addFundsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold">Add Funds to Wallet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="text-muted small mb-4">Securely add funds using your credit card.</p>

                    <form method="post" action="wallet.php">
                        <input type="hidden" name="action" value="add_funds">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Amount (€)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">€</span>
                                <input type="number" name="amount" class="form-control border-start-0 ps-0"
                                    placeholder="0.00" step="0.01" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Card Details</label>
                            <div class="p-3 bg-light rounded-3 border">
                                <div class="mb-3">
                                    <input type="text" name="card_number" id="card_number_input"
                                        class="form-control bg-white" placeholder="0000 0000 0000 0000" maxlength="19"
                                        required>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="text" name="expiry" class="form-control bg-white"
                                            placeholder="MM/YY" maxlength="5" required>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="cvc" class="form-control bg-white" placeholder="CVC"
                                            maxlength="3" required>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <input type="text" name="cardholder" class="form-control bg-white"
                                        placeholder="Cardholder Name" required>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted"><i class="fas fa-lock me-1"></i> SSL Secure Payment</small>
                                <img src="https://upload.wikimedia.org/wikipedia/commons/5/5e/Visa_Inc._logo.svg"
                                    height="12" alt="Visa" class="opacity-50">
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg rounded-3">Add Funds Now</button>
                        </div>
                    </form>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const cardInput = document.getElementById('card_number_input');
                            if (cardInput) {
                                cardInput.addEventListener('input', function (e) {
                                    
                                    let value = e.target.value.replace(/\D/g, '');
                                    
                                    value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
                                    e.target.value = value;
                                });
                            }

                            
                            const expiryInputs = document.querySelectorAll('input[name="expiry"]');
                            expiryInputs.forEach(function (expiryInput) {
                                expiryInput.addEventListener('input', function (e) {
                                    let value = e.target.value.replace(/\D/g, '');
                                    if (value.length >= 2) {
                                        
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
                            });
                        });
                    </script>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>