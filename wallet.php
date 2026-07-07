<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';
include 'includes/wallet.php';

require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

if ($isAdmin) {
    header('Location: admin.php');
    exit;
}

$errors = [];
$amountError = '';
$flash = $_SESSION['wallet_flash'] ?? null;
unset($_SESSION['wallet_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'topup') {
    $amount = (float) str_replace(',', '.', trim($_POST['amount'] ?? ''));
    $cardNumber = trim($_POST['card_number'] ?? '');
    $cardHolder = trim($_POST['card_holder'] ?? '');
    $cardExpiry = trim($_POST['card_expiry'] ?? '');
    $cardCvc = trim($_POST['card_cvc'] ?? '');

    $savedCard = get_user_card($conn, $userId);

    if ($amount <= 0) {
        $amountError = 'Inserisci un importo maggiore di zero.';
    }
    if (!$savedCard) {
        $errors[] = 'Devi prima registrare una carta nel tuo profilo.';
    } elseif ($cardHolder === '' || $cardNumber === '' || $cardExpiry === '' || $cardCvc === '') {
        $errors[] = 'Inserisci tutti i dati della carta.';
    } elseif (card_fingerprint($cardNumber, $cardExpiry, $cardHolder, $cardCvc) !== $savedCard['card_fingerprint']) {
        $errors[] = 'I dati della carta non corrispondono a quella salvata nel profilo.';
    }

    if (!$errors && $amountError === '') {
        $last4 = substr(preg_replace('/\D/', '', $cardNumber), -4);
        $description = 'Ricarica con carta •••• ' . $last4;

        // Add the funds and record the movement together, so they never get out of sync
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "di", $amount, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, description, card_last4) VALUES (?, 'topup', ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "idss", $userId, $amount, $description, $last4);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            $_SESSION['wallet_flash'] = ['type' => 'success', 'message' => 'Ricarica di €' . eur2($amount) . ' effettuata.'];
            header('Location: wallet.php');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Ricarica non riuscita. Riprova.';
        }
    }
}

$balance = get_wallet_balance($conn, $userId);
$card = get_user_card($conn, $userId);

$transactions = [];
$stmt = mysqli_prepare($conn, "SELECT t.type, t.amount, t.description, t.created_at, l.title AS listing_title, l.id AS listing_id FROM transactions t LEFT JOIN listings l ON t.listing_id = l.id WHERE t.user_id = ? ORDER BY t.created_at DESC, t.id DESC");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($res && $row = mysqli_fetch_assoc($res)) {
    $transactions[] = $row;
}
mysqli_stmt_close($stmt);

$pageTitle = 'Portafoglio';
$activePage = 'wallet';
include 'includes/header.php';
?>
<main>
<div class="container py-5" style="max-width: 760px;" id="main-content">
    <h1 class="fw-bold mb-4">Il mio portafoglio</h1>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
    <?php endif; ?>

    <div class="ac-split-box p-4 mb-4">
        <div class="label-mono mb-1">Saldo disponibile</div>
        <div class="display-5 fw-bold text-ac">&euro;<?php echo eur2($balance); ?></div>
    </div>

    <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
        <h2 class="h5 fw-bold mb-1">Ricarica il portafoglio</h2>
        <p class="text-muted small">Inserisci i dati della carta per aggiungere fondi.</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$card): ?>
            <div class="alert alert-warning mb-0">
                Non hai ancora registrato una carta. Aggiungila nel tuo <a href="profile.php" class="fw-semibold">profilo</a> per poter ricaricare il portafoglio.
            </div>
        <?php else: ?>
        <div class="alert alert-light border small d-flex align-items-center gap-2">
            <span class="fas fa-credit-card text-ac" aria-hidden="true"></span>
            <span>I dati devono corrispondere alla carta salvata: <strong>&bull;&bull;&bull;&bull; <?php echo htmlspecialchars($card['card_last4']); ?></strong> · scad. <?php echo htmlspecialchars($card['card_expiry']); ?></span>
        </div>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="topup">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold" for="w-amount">Importo (&euro;)</label>
                    <input type="text" inputmode="decimal" id="w-amount" name="amount" class="form-control<?php echo $amountError !== '' ? ' is-invalid' : ''; ?>" placeholder="es. 100" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
                    <?php if ($amountError !== ''): ?>
                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($amountError); ?></div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php foreach ([50, 100, 250, 500] as $preset): ?>
                            <button type="button" class="ac-pill py-1 px-3" onclick="document.getElementById('w-amount').value='<?php echo $preset; ?>';"><?php echo $preset; ?> &euro;</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-bold" for="w-card-holder">Intestatario carta</label>
                    <input type="text" id="w-card-holder" name="card_holder" class="form-control" autocomplete="cc-name" value="<?php echo htmlspecialchars($_POST['card_holder'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="w-card-number">Numero carta</label>
                    <input type="text" inputmode="numeric" id="w-card-number" name="card_number" class="form-control" placeholder="1234 5678 9012 3456" autocomplete="cc-number" value="<?php echo htmlspecialchars($_POST['card_number'] ?? ''); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold" for="w-card-expiry">Scadenza</label>
                    <input type="text" id="w-card-expiry" name="card_expiry" class="form-control" placeholder="MM/AA" autocomplete="cc-exp" value="<?php echo htmlspecialchars($_POST['card_expiry'] ?? ''); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold" for="w-card-cvc">CVC</label>
                    <input type="text" inputmode="numeric" id="w-card-cvc" name="card_cvc" class="form-control" placeholder="123" autocomplete="cc-csc" required>
                </div>
            </div>
            <button type="submit" class="btn btn-ac mt-3">Aggiungi fondi</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-4 shadow-sm p-4">
        <h2 class="h5 fw-bold mb-3">Movimenti</h2>
        <?php if (!$transactions): ?>
            <p class="text-muted mb-0">Nessun movimento. Ricarica il portafoglio per iniziare.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th scope="col">Data</th><th scope="col">Descrizione</th><th scope="col" class="text-end">Importo</th></tr></thead>
                    <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <?php $isTopup = $t['type'] === 'topup'; ?>
                        <tr>
                            <td class="small text-muted"><?php echo htmlspecialchars(substr($t['created_at'], 0, 10)); ?></td>
                            <td>
                                <?php echo htmlspecialchars($t['description']); ?>
                                <?php if (!empty($t['listing_title'])): ?>
                                    <a href="listing.php?id=<?php echo (int) $t['listing_id']; ?>" class="small text-decoration-none">· <?php echo htmlspecialchars($t['listing_title']); ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $isTopup ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $isTopup ? '+' : '−'; ?>&euro;<?php echo eur2($t['amount']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</main>
<script src="assets/js/card-format.js"></script>
<?php include 'includes/footer.php'; ?>
