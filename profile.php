<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';
include 'includes/wallet.php';

require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

$profileErrors = [];
$accountErrors = [];
$cardErrors = [];
$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

function request_type_label(string $type): string
{
    return $type === 'contact' ? 'Contatto' : 'Visita';
}

function request_status_meta(string $status): array
{
    switch (strtolower(trim($status))) {
        case 'accepted': return ['Accettata', 'bg-success'];
        case 'declined': return ['Rifiutata', 'bg-danger'];
        case 'closed':   return ['Chiusa', 'bg-secondary'];
        default:         return ['In attesa', 'bg-warning text-dark'];
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($name === '') {
        $profileErrors[] = 'Il nome non può essere vuoto.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $profileErrors[] = 'La nuova password deve contenere almeno 6 caratteri.';
    }
    if ($password !== '' && $password !== $confirm) {
        $profileErrors[] = 'Le password non coincidono.';
    }

    if (!$profileErrors) {
        $phoneValue = $phone === '' ? null : $phone;
        $courseValue = $course === '' ? null : $course;

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, phone = ?, course = ?, password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssssi", $name, $phoneValue, $courseValue, $passwordHash, $userId);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, phone = ?, course = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sssi", $name, $phoneValue, $courseValue, $userId);
        }
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['user_name'] = $name;
            $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Profilo aggiornato.'];
            mysqli_stmt_close($stmt);
            header('Location: profile.php');
            exit;
        }
        mysqli_stmt_close($stmt);
        $profileErrors[] = 'Aggiornamento non riuscito.';
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_request_message') {
    $reqId = (int) ($_POST['request_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($reqId > 0 && $body !== '') {
        $chk = mysqli_prepare($conn, "SELECT id FROM requests WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($chk, "ii", $reqId, $userId);
        mysqli_stmt_execute($chk);
        $chkRes = mysqli_stmt_get_result($chk);
        $owns = $chkRes && mysqli_fetch_assoc($chkRes);
        mysqli_stmt_close($chk);
        if ($owns) {
            $ins = mysqli_prepare($conn, "INSERT INTO request_messages (request_id, sender, body) VALUES (?, 'user', ?)");
            mysqli_stmt_bind_param($ins, "is", $reqId, $body);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Messaggio inviato all\'amministrazione.'];
        }
    }
    header('Location: profile.php');
    exit;
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_card') {
    $cardHolder = trim($_POST['card_holder'] ?? '');
    $cardNumber = trim($_POST['card_number'] ?? '');
    $cardExpiry = trim($_POST['card_expiry'] ?? '');
    $cardCvc = trim($_POST['card_cvc'] ?? '');

    if ($cardHolder === '') {
        $cardErrors[] = 'Inserisci l\'intestatario della carta.';
    }
    if (!card_number_valid($cardNumber)) {
        $cardErrors[] = 'Il numero della carta deve avere esattamente 16 cifre.';
    }
    if (!card_expiry_valid($cardExpiry)) {
        $cardErrors[] = 'Scadenza della carta non valida o già passata.';
    }
    if (!preg_match('/^\d{3,4}$/', $cardCvc)) {
        $cardErrors[] = 'Il CVC deve avere 3 o 4 cifre.';
    }

    if (!$cardErrors) {
        // Store only the last 4 digits and a hash of the card, never the full number
        $last4 = substr(preg_replace('/\D/', '', $cardNumber), -4);
        $expiryCanon = canonical_expiry($cardExpiry);
        $fingerprint = card_fingerprint($cardNumber, $cardExpiry, $cardHolder, $cardCvc);

        $stmt = mysqli_prepare($conn, "UPDATE users SET card_holder = ?, card_last4 = ?, card_expiry = ?, card_fingerprint = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssssi", $cardHolder, $last4, $expiryCanon, $fingerprint, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Carta salvata. Usala per ricaricare il portafoglio.'];
            mysqli_stmt_close($stmt);
            header('Location: profile.php');
            exit;
        }
        mysqli_stmt_close($stmt);
        $cardErrors[] = 'Salvataggio della carta non riuscito.';
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    $password = $_POST['password'] ?? '';
    $confirmation = trim($_POST['confirm'] ?? '');

    if ($password === '') {
        $accountErrors[] = 'Inserisci la password.';
    }
    if ($confirmation !== 'ELIMINA') {
        $accountErrors[] = 'Scrivi ELIMINA per confermare.';
    }

    if (!$accountErrors) {
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row || !password_verify($password, $row['password'])) {
            $accountErrors[] = 'Password non corretta.';
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION = [];
                session_destroy();
                header('Location: index.php');
                exit;
            }
            mysqli_stmt_close($stmt);
            $accountErrors[] = 'Eliminazione non riuscita.';
        }
    }
}

$stmt = mysqli_prepare($conn, "SELECT name, email, phone, course, created_at FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

$pageTitle = 'Profilo';
$activePage = 'profile';

if ($isAdmin) {
    include 'includes/header.php';
    ?>
    <main>
    <div class="container py-5" style="max-width: 720px;" id="main-content">
        <h1 class="fw-bold mb-4">Profilo amministratore</h1>
        <div class="bg-white rounded-4 shadow-sm p-4">
            <p class="text-muted mb-1">Nome</p>
            <h2 class="h4 fw-bold"><?php echo htmlspecialchars($user['name'] ?? $_SESSION['user_name']); ?></h2>
            <p class="text-muted mb-3"><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
            <a href="admin.php" class="btn btn-ac">Vai al pannello admin</a>
        </div>
    </div>
    </main>
    <?php
    include 'includes/footer.php';
    exit;
}

$requests = [];
$stmt = mysqli_prepare($conn, "SELECT r.id, r.type, r.message, r.preferred_date, r.status, r.admin_notes, r.created_at, l.id AS listing_id, l.title FROM requests r JOIN listings l ON r.listing_id = l.id WHERE r.user_id = ? AND r.status <> 'closed' ORDER BY r.created_at DESC");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($result && $row = mysqli_fetch_assoc($result)) {
    $requests[] = $row;
}
mysqli_stmt_close($stmt);

$requestMessages = [];
if ($requests) {
    $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $requests));
    $mres = mysqli_query($conn, "SELECT request_id, sender, body, created_at FROM request_messages WHERE request_id IN ($ids) ORDER BY created_at ASC, id ASC");
    while ($mres && $m = mysqli_fetch_assoc($mres)) {
        $requestMessages[(int) $m['request_id']][] = $m;
    }
}

$savedCount = 0;
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM favorites WHERE user_id = " . $userId));
if ($row) {
    $savedCount = (int) $row['c'];
}

$card = get_user_card($conn, $userId);

$rentals = [];
$stmt = mysqli_prepare($conn, "SELECT r.months, r.total_paid, r.start_date, r.end_date, r.end_date >= CURDATE() AS active, l.id AS listing_id, l.title AS listing_title FROM rentals r LEFT JOIN listings l ON r.listing_id = l.id WHERE r.user_id = ? ORDER BY r.end_date DESC, r.id DESC");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($result && $row = mysqli_fetch_assoc($result)) {
    $rentals[] = $row;
}
mysqli_stmt_close($stmt);

include 'includes/header.php';
?>
<main>
<div class="container py-5" style="max-width: 900px;" id="main-content">
    <h1 class="fw-bold mb-4">Il mio profilo</h1>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="ac-avatar" style="width:56px;height:56px;font-size:1.2rem;"><?php echo htmlspecialchars(user_initials($user['name'] ?? 'U')); ?></span>
                <div>
                    <h2 class="h4 fw-bold mb-0"><?php echo htmlspecialchars($user['name'] ?? ''); ?></h2>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <?php if (!empty($user['course'])): ?>
                        <span class="badge badge-soft mt-1"><?php echo htmlspecialchars($user['course']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="ac-stat-value text-ac"><?php echo $savedCount; ?></div>
                <a href="favorites.php" class="ac-stat-label text-decoration-none">annunci salvati</a>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
        <h2 class="h5 fw-bold mb-3">Le mie richieste</h2>
        <?php if (!$requests): ?>
            <p class="text-muted mb-0">Non hai ancora inviato richieste. <a href="search.php">Cerca un alloggio</a> e contatta il proprietario.</p>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($requests as $req): ?>
                    <?php [$statusLabel, $statusClass] = request_status_meta($req['status']); ?>
                    <div class="border rounded-3 p-3">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <span class="badge badge-soft me-1"><?php echo htmlspecialchars(request_type_label($req['type'])); ?></span>
                                <a href="listing.php?id=<?php echo (int) $req['listing_id']; ?>" class="fw-bold text-decoration-none"><?php echo htmlspecialchars($req['title']); ?></a>
                                <div class="text-muted small mt-1">Inviata il <?php echo htmlspecialchars(substr($req['created_at'], 0, 10)); ?><?php echo $req['preferred_date'] ? ' · data preferita ' . htmlspecialchars($req['preferred_date']) : ''; ?></div>
                            </div>
                            <span class="badge <?php echo $statusClass; ?> align-self-start rounded-pill"><?php echo htmlspecialchars($statusLabel); ?></span>
                        </div>
                        <div class="bg-surface rounded-3 p-3 mt-2">
                            <div class="mb-2 text-end">
                                <span class="badge bg-dark">Tu</span>
                                <div class="small mt-1"><?php echo nl2br(htmlspecialchars($req['message'])); ?></div>
                            </div>
                            <?php foreach (($requestMessages[(int) $req['id']] ?? []) as $m): ?>
                                <div class="mb-2 <?php echo $m['sender'] === 'user' ? 'text-end' : ''; ?>">
                                    <span class="badge <?php echo $m['sender'] === 'user' ? 'bg-dark' : 'badge-soft'; ?>"><?php echo $m['sender'] === 'user' ? 'Tu' : 'Amministrazione'; ?></span>
                                    <div class="small mt-1"><?php echo nl2br(htmlspecialchars($m['body'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="post" class="row g-2 align-items-end mt-2">
                            <input type="hidden" name="action" value="send_request_message">
                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                            <div class="col-md-9">
                                <label class="visually-hidden" for="reqmsg-<?php echo (int) $req['id']; ?>">Messaggio all'amministrazione</label>
                                <input type="text" id="reqmsg-<?php echo (int) $req['id']; ?>" name="body" class="form-control form-control-sm" maxlength="1000" placeholder="Scrivi all'amministrazione..." required>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-outline-ac btn-sm w-100">Invia</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($rentals): ?>
        <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
            <h2 class="h5 fw-bold mb-3">I miei affitti</h2>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($rentals as $rent): ?>
                    <?php $isActive = (int) $rent['active'] === 1; ?>
                    <div class="border rounded-3 p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <?php if (!empty($rent['listing_title'])): ?>
                                    <a href="listing.php?id=<?php echo (int) $rent['listing_id']; ?>" class="fw-bold text-decoration-none"><?php echo htmlspecialchars($rent['listing_title']); ?></a>
                                <?php else: ?>
                                    <span class="fw-bold text-muted">Alloggio non più disponibile</span>
                                <?php endif; ?>
                                <div class="text-muted small mt-1">
                                    Durata <?php echo (int) $rent['months']; ?> mes<?php echo (int) $rent['months'] === 1 ? 'e' : 'i'; ?> ·
                                    dal <?php echo date('d/m/Y', strtotime($rent['start_date'])); ?>
                                    al <strong>scadenza <?php echo date('d/m/Y', strtotime($rent['end_date'])); ?></strong>
                                </div>
                            </div>
                            <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?> align-self-start rounded-pill"><?php echo $isActive ? 'Attivo' : 'Scaduto'; ?></span>
                        </div>
                        <?php if ($isActive): ?>
                            <div class="alert alert-light border mt-2 mb-0 small">
                                <span class="fas fa-circle-info me-1" aria-hidden="true"></span>
                                Per <strong>rinnovare o prolungare</strong> l'affitto contatta l'amministrazione: vi accorderete per allungare la durata.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-3 mb-0">Tutti i movimenti sono nel <a href="wallet.php">portafoglio</a>.</p>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
        <h2 class="h5 fw-bold mb-3">Dati personali</h2>
        <?php if ($profileErrors): ?>
            <div class="alert alert-danger">
                <?php foreach ($profileErrors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="update_profile">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-name">Nome e cognome</label>
                    <input type="text" id="pf-name" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-email">Email</label>
                    <input type="email" id="pf-email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-phone">Telefono</label>
                    <input type="text" id="pf-phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" autocomplete="tel">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-course">Corso di studi</label>
                    <input type="text" id="pf-course" name="course" class="form-control" value="<?php echo htmlspecialchars($user['course'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-password">Nuova password</label>
                    <input type="password" id="pf-password" name="password" class="form-control" autocomplete="new-password" placeholder="Lascia vuoto per non modificare">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pf-confirm">Conferma nuova password</label>
                    <input type="password" id="pf-confirm" name="confirm" class="form-control" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" class="btn btn-ac mt-3">Salva modifiche</button>
        </form>
    </div>

    <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
        <h2 class="h5 fw-bold mb-1">Carta di pagamento</h2>
        <p class="text-muted small">Registra la carta con cui ricaricherai il portafoglio.</p>

        <?php if ($card): ?>
            <div class="alert alert-light border d-flex align-items-center gap-2">
                <span class="fas fa-credit-card text-ac" aria-hidden="true"></span>
                <span>Carta salvata: <strong>&bull;&bull;&bull;&bull; <?php echo htmlspecialchars($card['card_last4']); ?></strong> · <?php echo htmlspecialchars($card['card_holder']); ?> · scad. <?php echo htmlspecialchars($card['card_expiry']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($cardErrors): ?>
            <div class="alert alert-danger">
                <?php foreach ($cardErrors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="action" value="save_card">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="cd-holder">Intestatario carta</label>
                    <input type="text" id="cd-holder" name="card_holder" class="form-control" autocomplete="cc-name" value="<?php echo htmlspecialchars($card['card_holder'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="cd-number">Numero carta</label>
                    <input type="text" inputmode="numeric" id="cd-number" name="card_number" class="form-control" placeholder="1234 5678 9012 3456" autocomplete="cc-number" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold" for="cd-expiry">Scadenza</label>
                    <input type="text" id="cd-expiry" name="card_expiry" class="form-control" placeholder="MM/AA" autocomplete="cc-exp" value="<?php echo htmlspecialchars($card['card_expiry'] ?? ''); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold" for="cd-cvc">CVC</label>
                    <input type="text" inputmode="numeric" id="cd-cvc" name="card_cvc" class="form-control" placeholder="123" autocomplete="cc-csc" required>
                </div>
            </div>
            <button type="submit" class="btn btn-ac mt-3"><?php echo $card ? 'Aggiorna carta' : 'Salva carta'; ?></button>
        </form>
    </div>

    <div class="bg-white rounded-4 shadow-sm p-4">
        <h2 class="h5 fw-bold mb-2">Elimina account</h2>
        <p class="text-muted small">Questa operazione rimuove definitivamente il profilo, i salvati e le richieste.</p>
        <?php if ($accountErrors): ?>
            <div class="alert alert-danger">
                <?php foreach ($accountErrors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" data-confirm="Eliminare definitivamente il tuo account? L'operazione è irreversibile." data-confirm-danger>
            <input type="hidden" name="action" value="delete_account">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="del-password">Conferma password</label>
                    <input type="password" id="del-password" name="password" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="del-confirm">Scrivi ELIMINA per confermare</label>
                    <input type="text" id="del-confirm" name="confirm" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-outline-danger mt-3">Elimina il mio account</button>
        </form>
    </div>
</div>
</main>
<script src="assets/js/card-format.js"></script>
<?php include 'includes/footer.php'; ?>
