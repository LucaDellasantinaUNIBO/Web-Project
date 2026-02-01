<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$errors = [];
$success = null;
$amountInput = '';
$cardholderInput = '';
$expiryInput = '';

$userId = (int) ($_SESSION['user_id'] ?? 0);

function fetch_credit(mysqli $conn, int $userId): float
{
    $stmt = mysqli_prepare($conn, "SELECT credit FROM users WHERE id = ?");
    if (!$stmt) {
        return 0.0;
    }
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return isset($row['credit']) ? (float) $row['credit'] : 0.0;
}

function is_valid_expiry(string $expiry): bool
{
    if (!preg_match('/^(0[1-9]|1[0-2])\\/(\\d{2}|\\d{4})$/', $expiry, $matches)) {
        return false;
    }
    return true;
}

$currentCredit = fetch_credit($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amountInput = trim($_POST['amount'] ?? '');
    $cardholderInput = trim($_POST['cardholder'] ?? '');
    $expiryInput = trim($_POST['expiry'] ?? '');
    $cardNumberRaw = trim($_POST['card_number'] ?? '');
    $cvcRaw = trim($_POST['cvc'] ?? '');

    $amountValue = (float) $amountInput;
    if ($amountInput === '' || $amountValue <= 0) {
        $errors[] = 'Enter a valid amount.';
    } elseif ($amountValue < 50 || $amountValue > 5000) {
        $errors[] = 'Amount must be between EUR 50 and EUR 5000.';
    }

    if ($cardholderInput === '' || strlen($cardholderInput) < 3) {
        $errors[] = 'Cardholder name is too short.';
    }

    $cardNumber = preg_replace('/\\D+/', '', $cardNumberRaw);
    if (strlen($cardNumber) !== 16) {
        $errors[] = 'Card number must be 16 digits.';
    }

    if (!is_valid_expiry($expiryInput)) {
        $errors[] = 'Expiry date must be in MM/YY or MM/YYYY format.';
    }

    $cvc = preg_replace('/\\D+/', '', $cvcRaw);
    if (strlen($cvc) < 3 || strlen($cvc) > 4) {
        $errors[] = 'CVC must be 3 or 4 digits.';
    }

    if (!$errors) {
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
            $success = 'Wallet updated successfully.';
            $amountInput = '';
            $cardholderInput = '';
            $expiryInput = '';
            $currentCredit = $newBalance;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Wallet update failed. Please try again.';
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" style="max-width: 720px;" id="main-content">
        <h1 class="fw-bold mb-3">Wallet</h1>
        <p class="text-muted">Add funds to pay for your rent.</p>

        <div class="form-section p-4 shadow-sm mb-4">
            <div class="small text-muted">Current balance</div>
            <div class="display-6 fw-bold text-success">$
                <?php echo number_format($currentCredit, 2); ?>
            </div>
        </div>

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

        <div class="form-section p-4 shadow-sm">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="wallet-amount">Add credit amount ($)</label>
                        <input type="number" id="wallet-amount" step="0.01" min="50" max="5000" name="amount"
                            class="form-control" value="<?php echo htmlspecialchars($amountInput); ?>" required>
                        <div class="small text-muted mt-2">Quick amounts</div>
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="document.getElementById('wallet-amount').value='500';">$500</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="document.getElementById('wallet-amount').value='1000';">$1000</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="document.getElementById('wallet-amount').value='1500';">$1500</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="document.getElementById('wallet-amount').value='2000';">$2000</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="wallet-cardholder">Cardholder name</label>
                        <input type="text" id="wallet-cardholder" name="cardholder" class="form-control"
                            value="<?php echo htmlspecialchars($cardholderInput); ?>" autocomplete="cc-name" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold" for="wallet-card-number">Card number</label>
                        <input type="text" id="wallet-card-number" name="card_number" class="form-control"
                            placeholder="1111 2222 3333 4444" autocomplete="cc-number" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold" for="wallet-expiry">Expiry</label>
                        <input type="text" id="wallet-expiry" name="expiry" class="form-control" placeholder="MM/YY"
                            value="<?php echo htmlspecialchars($expiryInput); ?>" autocomplete="cc-exp" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold" for="wallet-cvc">CVC</label>
                        <input type="text" id="wallet-cvc" name="cvc" class="form-control" placeholder="123"
                            autocomplete="cc-csc" required>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('wallet-cardholder').value='Alexa Tenant';document.getElementById('wallet-card-number').value='4111 1111 1111 1111';document.getElementById('wallet-expiry').value='12/2030';document.getElementById('wallet-cvc').value='123';">Fill
                        card</button>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Add credit</button>
                    <a href="profile.php" class="btn btn-outline-secondary">Back to profile</a>
                </div>
            </form>
            <p class="text-muted small mt-3 mb-0">This is a simulation. No real card data is stored.</p>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>