<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';

require_admin();

$errors = [];
$userEmailError = '';
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$adminId = (int) $_SESSION['user_id'];

$sections = ['dashboard', 'annunci', 'studenti', 'richieste', 'affitti', 'pagamenti', 'impostazioni'];
$section = $_GET['section'] ?? 'annunci';
if (!in_array($section, $sections, true)) {
    $section = 'annunci';
}

$allowedTypes = array_keys(LISTING_TYPES);
$listingStatuses = ['published', 'draft', 'archived'];

function listing_status_meta(string $status): array
{
    switch ($status) {
        case 'published': return ['Pubblicato', 'bg-success'];
        case 'draft':     return ['Bozza', 'bg-secondary'];
        case 'archived':  return ['Archiviato', 'bg-dark'];
        default:          return [ucfirst($status), 'bg-light text-dark border'];
    }
}

function request_status_meta_admin(string $status): array
{
    switch (strtolower(trim($status))) {
        case 'accepted': return ['Accettata', 'bg-success'];
        case 'declined': return ['Rifiutata', 'bg-danger'];
        case 'closed':   return ['Chiusa', 'bg-secondary'];
        default:         return ['In attesa', 'bg-warning text-dark'];
    }
}

// Record an admin action in the change log 
function log_change(mysqli $conn, int $adminId, string $action, string $entity, int $entityId, string $details): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO change_log (admin_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, "issis", $adminId, $action, $entity, $entityId, $details);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function send_request_status_email(array $data): bool
{
    $statusText = [
        'accepted' => 'accettata',
        'declined' => 'rifiutata',
        'closed'   => 'chiusa',
        'open'     => 'in attesa',
    ][$data['status']] ?? $data['status'];

    $typeText = $data['type'] === 'contact' ? 'richiesta di contatto' : 'richiesta di visita';
    $subject = 'AlmaCasa · La tua richiesta è stata ' . $statusText;

    $lines = [
        'Ciao ' . $data['name'] . ',',
        '',
        'la tua ' . $typeText . ' per l\'annuncio "' . $data['listing'] . '" è stata ' . strtoupper($statusText) . '.',
    ];
    if ($data['notes'] !== '') {
        $lines[] = '';
        $lines[] = 'Messaggio dal gestore:';
        $lines[] = $data['notes'];
    }
    $lines[] = '';
    $lines[] = 'Puoi vedere lo stato aggiornato nel tuo profilo su AlmaCasa.';
    $lines[] = '';
    $lines[] = '— Il team AlmaCasa · Campus di Cesena';
    $body = implode("\r\n", $lines);

    $headers = implode("\r\n", [
        'From: AlmaCasa <no-reply@almacasa.it>',
        'Reply-To: no-reply@almacasa.it',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    $sent = @mail($data['to'], $subject, $body, $headers);

    $logDir = __DIR__ . '/db/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $entry = "==== " . date('Y-m-d H:i:s') . " ====\r\n"
        . 'To: ' . $data['to'] . "\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . 'Delivered: ' . ($sent ? 'yes (mail())' : 'no — logged only') . "\r\n\r\n"
        . $body . "\r\n\r\n";
    @file_put_contents($logDir . '/mail_outbox.log', $entry, FILE_APPEND);

    return (bool) $sent;
}


if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $title = null;
    $stmt = mysqli_prepare($conn, "SELECT title FROM listings WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    $title = $row['title'] ?? null;
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "DELETE FROM listings WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
        log_change($conn, $adminId, 'delete', 'listing', $id, $title ? "Eliminato annuncio {$title}." : "Eliminato annuncio #{$id}.");
        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Annuncio eliminato.'];
    } else {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'message' => 'Eliminazione non riuscita.'];
    }
    mysqli_stmt_close($stmt);
    header('Location: admin.php?section=annunci');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create', 'update'], true)) {
    $action = $_POST['action'];
    $title = trim($_POST['title'] ?? '');
    $type = $_POST['type'] ?? '';
    $zone = trim($_POST['zone'] ?? '');
    $distance = (float) ($_POST['distance_km'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);
    $deposit = (float) ($_POST['deposit'] ?? 0);
    $sizeSqm = (int) ($_POST['size_sqm'] ?? 0);
    $beds = (int) ($_POST['beds'] ?? 1);
    $description = trim($_POST['description'] ?? '');
    $verified = isset($_POST['verified']) ? 1 : 0;
    $status = $_POST['status'] ?? 'published';
    // The picture is fixed per listing type
    $imageValue = listing_image($type);
    $lat = ($_POST['lat'] ?? '') === '' ? null : (float) $_POST['lat'];
    $lng = ($_POST['lng'] ?? '') === '' ? null : (float) $_POST['lng'];

    if ($title === '') { $errors[] = 'Il titolo è obbligatorio.'; }
    if (!in_array($type, $allowedTypes, true)) { $errors[] = 'Tipo non valido.'; }
    if ($zone === '') { $errors[] = 'La zona è obbligatoria.'; }
    if (!in_array($status, $listingStatuses, true)) { $errors[] = 'Stato non valido.'; }
    if ($price <= 0) { $errors[] = 'Il prezzo deve essere maggiore di zero.'; }
    if ($distance < 0) { $errors[] = 'Distanza non valida.'; }
    if ($beds < 1) { $beds = 1; }
    if (strlen($description) < 10) { $errors[] = 'La descrizione deve contenere almeno 10 caratteri.'; }

    if (!$errors) {
        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO listings (title, type, zone, distance_km, price, deposit, size_sqm, beds, description, verified, status, lat, lng, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssdddiisisdds", $title, $type, $zone, $distance, $price, $deposit, $sizeSqm, $beds, $description, $verified, $status, $lat, $lng, $imageValue);
            if (mysqli_stmt_execute($stmt)) {
                $newId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                log_change($conn, $adminId, 'create', 'listing', $newId, "Creato annuncio {$title}.");
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Annuncio creato.'];
                header('Location: admin.php?section=annunci');
                exit;
            }
            $errors[] = 'Creazione non riuscita: ' . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE listings SET title=?, type=?, zone=?, distance_km=?, price=?, deposit=?, size_sqm=?, beds=?, description=?, verified=?, status=?, lat=?, lng=?, image_url=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssdddiisisddsi", $title, $type, $zone, $distance, $price, $deposit, $sizeSqm, $beds, $description, $verified, $status, $lat, $lng, $imageValue, $id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                log_change($conn, $adminId, 'update', 'listing', $id, "Modificato annuncio {$title}.");
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Annuncio aggiornato.'];
                header('Location: admin.php?section=annunci');
                exit;
            }
            $errors[] = 'Aggiornamento non riuscito: ' . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        }
    }
    $section = 'annunci';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_credentials') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $adminId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $me = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$me || !password_verify($current, $me['password'])) {
        $errors[] = 'La password attuale non è corretta.';
    }
    if ($name === '') {
        $errors[] = 'Il nome è obbligatorio.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido.';
    }
    if ($new !== '' && strlen($new) < 6) {
        $errors[] = 'La nuova password deve contenere almeno 6 caratteri.';
    }
    if ($new !== '' && $new !== $confirm) {
        $errors[] = 'Le nuove password non coincidono.';
    }
    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id <> ?");
        mysqli_stmt_bind_param($stmt, "si", $email, $adminId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && mysqli_fetch_assoc($res)) {
            $errors[] = 'Questa email è già usata da un altro account.';
        }
        mysqli_stmt_close($stmt);
    }
    if (!$errors) {
        if ($new !== '') {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sssi", $name, $email, $hash, $adminId);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE users SET name = ?, email = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssi", $name, $email, $adminId);
        }
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            log_change($conn, $adminId, 'update', 'user', $adminId, 'Aggiornate le credenziali amministratore.');
            $_SESSION['user_name'] = $name;
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Credenziali aggiornate.'];
            header('Location: admin.php?section=impostazioni');
            exit;
        }
        mysqli_stmt_close($stmt);
        $errors[] = 'Aggiornamento non riuscito.';
    }
    $section = 'impostazioni';
}

if (isset($_GET['delete_user'])) {
    $id = (int) $_GET['delete_user'];
    if ($id === $adminId) {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'message' => 'Non puoi eliminare te stesso.'];
    } else {
        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        if (mysqli_stmt_execute($stmt)) {
            log_change($conn, $adminId, 'delete', 'user', $id, "Eliminato utente #{$id}.");
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Utente eliminato.'];
        } else {
            $_SESSION['admin_flash'] = ['type' => 'danger', 'message' => 'Eliminazione non riuscita.'];
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: admin.php?section=studenti');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['user_create', 'user_update'], true)) {
    $action = $_POST['action'];
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $status = ($_POST['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active';
    $password = $_POST['password'] ?? '';

    if ($name === '') {
        $errors[] = 'Il nome è obbligatorio.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $userEmailError = 'Questo non è un indirizzo email valido.';
    }

    if (!$errors && $userEmailError === '' && $action === 'user_create') {
        if (strlen($password) < 6) {
            $errors[] = 'La password (min 6 caratteri) è obbligatoria per i nuovi utenti.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_fetch_assoc($res)) {
                $userEmailError = 'Questa email è già registrata.';
            }
            mysqli_stmt_close($stmt);
        }
        if (!$errors && $userEmailError === '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hash, $role, $status);
            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $adminId, 'create', 'user', mysqli_insert_id($conn), "Creato utente {$name}.");
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Utente creato.'];
                mysqli_stmt_close($stmt);
                header('Location: admin.php?section=studenti');
                exit;
            }
            $errors[] = 'Creazione non riuscita.';
            mysqli_stmt_close($stmt);
        }
    } elseif (!$errors && $userEmailError === '' && $action === 'user_update') {
        $editId = (int) ($_POST['id'] ?? 0);
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, role=?, status=?, password=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $email, $role, $status, $hash, $editId);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "ssssi", $name, $email, $role, $status, $editId);
        }
        if (mysqli_stmt_execute($stmt)) {
            log_change($conn, $adminId, 'update', 'user', $editId, "Modificato utente {$name}.");
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Utente aggiornato.'];
            mysqli_stmt_close($stmt);
            header('Location: admin.php?section=studenti');
            exit;
        }
        $errors[] = 'Aggiornamento non riuscito.';
        mysqli_stmt_close($stmt);
    }
    $section = 'studenti';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_request') {
    $reqId = (int) ($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');

    $curStmt = mysqli_prepare($conn, "SELECT status FROM requests WHERE id = ?");
    mysqli_stmt_bind_param($curStmt, "i", $reqId);
    mysqli_stmt_execute($curStmt);
    $curRes = mysqli_stmt_get_result($curStmt);
    $curRow = $curRes ? mysqli_fetch_assoc($curRes) : null;
    mysqli_stmt_close($curStmt);
    $currentStatus = $curRow['status'] ?? null;

    if (!in_array($status, ['accepted', 'declined'], true)) {
        $errors[] = 'Azione non valida.';
    } elseif ($currentStatus !== 'open') {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'message' => 'Questa richiesta è già stata gestita e non può essere modificata.'];
        header('Location: admin.php?section=richieste');
        exit;
    } else {
        $notesValue = $notes === '' ? null : $notes;
        $stmt = mysqli_prepare($conn, "UPDATE requests SET status=?, admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssii", $status, $notesValue, $adminId, $reqId);
        if (mysqli_stmt_execute($stmt)) {
            log_change($conn, $adminId, 'update', 'request', $reqId, "Richiesta #{$reqId} -> {$status}.");
            $statusMessages = [
                'accepted' => 'Richiesta accettata.',
                'declined' => 'Richiesta rifiutata.',
                'closed'   => 'Richiesta chiusa.',
                'open'     => 'Richiesta aggiornata.',
            ];
            $flashMessage = $statusMessages[$status] ?? 'Richiesta aggiornata.';

            if ($notes !== '') {
                $msgStmt = mysqli_prepare($conn, "INSERT INTO request_messages (request_id, sender, body) VALUES (?, 'admin', ?)");
                mysqli_stmt_bind_param($msgStmt, "is", $reqId, $notes);
                mysqli_stmt_execute($msgStmt);
                mysqli_stmt_close($msgStmt);
            }

            if (in_array($status, ['accepted', 'declined', 'closed'], true)) {
                $infoStmt = mysqli_prepare($conn, "SELECT r.user_id, u.email, u.name, l.title, r.type FROM requests r JOIN users u ON r.user_id = u.id JOIN listings l ON r.listing_id = l.id WHERE r.id = ?");
                mysqli_stmt_bind_param($infoStmt, "i", $reqId);
                mysqli_stmt_execute($infoStmt);
                $infoRes = mysqli_stmt_get_result($infoStmt);
                $info = $infoRes ? mysqli_fetch_assoc($infoRes) : null;
                mysqli_stmt_close($infoStmt);

                if ($info) {
                    $sent = send_request_status_email([
                        'to' => $info['email'],
                        'name' => $info['name'],
                        'listing' => $info['title'],
                        'type' => $info['type'],
                        'status' => $status,
                        'notes' => $notes,
                    ]);
                    if ($status === 'accepted') {
                        $studentId = (int) $info['user_id'];
                        $lapseNote = 'Chiusa automaticamente: hai già ottenuto un altro alloggio.';
                        $lapseStmt = mysqli_prepare($conn, "UPDATE requests SET status = 'closed', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND id <> ? AND status = 'open'");
                        mysqli_stmt_bind_param($lapseStmt, "siii", $lapseNote, $adminId, $studentId, $reqId);
                        mysqli_stmt_execute($lapseStmt);
                        $lapsed = mysqli_stmt_affected_rows($lapseStmt);
                        mysqli_stmt_close($lapseStmt);

                        if ($lapsed > 0) {
                            log_change($conn, $adminId, 'update', 'request', $reqId, "Chiuse automaticamente {$lapsed} altre richieste in attesa dello studente.");
                        }
                    }
                }
            }

            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => $flashMessage];
            mysqli_stmt_close($stmt);
            header('Location: admin.php?section=richieste');
            exit;
        }
        mysqli_stmt_close($stmt);
        $errors[] = 'Aggiornamento non riuscito.';
    }
    $section = 'richieste';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $reqId = (int) ($_POST['request_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($reqId > 0 && $body !== '') {
        $stmt = mysqli_prepare($conn, "INSERT INTO request_messages (request_id, sender, body) VALUES (?, 'admin', ?)");
        mysqli_stmt_bind_param($stmt, "is", $reqId, $body);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header('Location: admin.php?section=richieste');
    exit;
}

// Compute the dashboard stats: listings, students, open requests and occupancy rate
$stats = ['listings' => 0, 'students' => 0, 'open_requests' => 0, 'occupancy' => 0];
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total, SUM(status='published') AS published FROM listings"));
$totalListings = (int) ($row['total'] ?? 0);
$stats['listings'] = (int) ($row['published'] ?? 0);
$stats['occupancy'] = $totalListings > 0 ? (int) round(100 * ($totalListings - $stats['listings']) / $totalListings) : 0;
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'user'"));
$stats['students'] = (int) ($row['c'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM requests WHERE status = 'open'"));
$stats['open_requests'] = (int) ($row['c'] ?? 0);

$listings = [];
$editListing = null;
if ($section === 'annunci' || $section === 'dashboard') {
    if (isset($_GET['edit'])) {
        $editId = (int) $_GET['edit'];
        $stmt = mysqli_prepare($conn, "SELECT * FROM listings WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $editId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $editListing = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    $res = mysqli_query($conn, "SELECT * FROM listings ORDER BY id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $listings[] = $r;
    }
}

$students = [];
$editUser = null;
if ($section === 'studenti') {
    if (isset($_GET['edit_user'])) {
        $euId = (int) $_GET['edit_user'];
        $stmt = mysqli_prepare($conn, "SELECT id, name, email, role, status FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $euId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $editUser = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    $res = mysqli_query($conn, "SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $students[] = $r;
    }
}

$userEmailValue = $editUser['email'] ?? '';
if ($userEmailValue === '' && in_array($_POST['action'] ?? '', ['user_create', 'user_update'], true)) {
    $userEmailValue = trim($_POST['email'] ?? '');
}

$requests = [];
$requestMessages = [];
if ($section === 'richieste') {
    $res = mysqli_query($conn, "SELECT r.id, r.type, r.message, r.preferred_date, r.status, r.admin_notes, r.created_at, u.name AS user_name, u.email AS user_email, l.title AS listing_title, l.id AS listing_id FROM requests r JOIN users u ON r.user_id = u.id JOIN listings l ON r.listing_id = l.id ORDER BY r.created_at DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $requests[] = $r;
    }
    if ($requests) {
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $requests));
        $mres = mysqli_query($conn, "SELECT request_id, sender, body, created_at FROM request_messages WHERE request_id IN ($ids) ORDER BY created_at ASC, id ASC");
        while ($mres && $m = mysqli_fetch_assoc($mres)) {
            $requestMessages[(int) $m['request_id']][] = $m;
        }
    }
}

$changeLog = [];
if ($section === 'dashboard') {
    $res = mysqli_query($conn, "SELECT c.action, c.entity, c.entity_id, c.details, c.created_at, u.name AS admin_name FROM change_log c JOIN users u ON c.admin_id = u.id ORDER BY c.created_at DESC LIMIT 15");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $changeLog[] = $r;
    }
}

$rentals = [];
if ($section === 'affitti') {
    $res = mysqli_query($conn, "SELECT r.id, r.months, r.start_date, r.end_date, r.total_paid, r.end_date >= CURDATE() AS active, u.name AS user_name, u.email AS user_email, l.title AS listing_title, l.id AS listing_id FROM rentals r JOIN users u ON r.user_id = u.id LEFT JOIN listings l ON r.listing_id = l.id ORDER BY r.end_date DESC, r.id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $rentals[] = $r;
    }
}

$adminProfile = ['name' => $_SESSION['user_name'] ?? '', 'email' => ''];
if ($section === 'impostazioni') {
    $stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $adminId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $adminProfile = ($res ? mysqli_fetch_assoc($res) : null) ?: $adminProfile;
    if (($_POST['action'] ?? '') === 'admin_credentials') {
        $adminProfile['name'] = trim($_POST['name'] ?? $adminProfile['name']);
        $adminProfile['email'] = trim($_POST['email'] ?? $adminProfile['email']);
    }
}

$payments = [];
$paymentsTotal = 0.0;
$rentsTotal = 0.0;
$depositsTotal = 0.0;
if ($section === 'pagamenti') {
    $res = mysqli_query($conn, "SELECT r.deposit, r.total_paid, r.months, r.start_date, u.name AS user_name, u.email AS user_email, l.title AS listing_title, l.id AS listing_id FROM rentals r JOIN users u ON r.user_id = u.id LEFT JOIN listings l ON r.listing_id = l.id ORDER BY r.start_date DESC, r.id DESC");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $r['rent_part'] = (float) $r['total_paid'] - (float) $r['deposit'];
        $payments[] = $r;
        $rentsTotal += (float) $r['rent_part'];
        $depositsTotal += (float) $r['deposit'];
        $paymentsTotal += (float) $r['total_paid'];
    }
}

$lf = [
    'id' => $editListing['id'] ?? '',
    'title' => $editListing['title'] ?? '',
    'type' => $editListing['type'] ?? 'monolocale',
    'zone' => $editListing['zone'] ?? '',
    'distance_km' => $editListing['distance_km'] ?? '1.0',
    'price' => $editListing['price'] ?? '',
    'deposit' => $editListing['deposit'] ?? '',
    'size_sqm' => $editListing['size_sqm'] ?? '',
    'beds' => $editListing['beds'] ?? '1',
    'description' => $editListing['description'] ?? '',
    'verified' => (int) ($editListing['verified'] ?? 1),
    'status' => $editListing['status'] ?? 'published',
    'lat' => $editListing['lat'] ?? '',
    'lng' => $editListing['lng'] ?? '',
    'image_url' => $editListing['image_url'] ?? '',
];

$sectionTitles = [
    'dashboard' => ['Dashboard', 'Panoramica del servizio'],
    'annunci' => ['Gestione annunci', 'Crea, modifica ed elimina gli alloggi pubblicati'],
    'studenti' => ['Studenti', 'Gestisci gli account verificati'],
    'richieste' => ['Richieste', 'Visite e contatti inviati dagli studenti'],
    'affitti' => ['Affitti', 'Contratti attivi degli studenti'],
    'pagamenti' => ['Pagamenti', 'Incassi ricevuti dagli studenti'],
    'impostazioni' => ['Impostazioni', 'Gestisci le tue credenziali di accesso'],
];
$navItems = [
    'dashboard' => ['Dashboard', 'fa-gauge'],
    'annunci' => ['Annunci', 'fa-house'],
    'studenti' => ['Studenti', 'fa-users'],
    'richieste' => ['Richieste', 'fa-envelope'],
    'affitti' => ['Affitti', 'fa-key'],
    'pagamenti' => ['Pagamenti', 'fa-wallet'],
    'impostazioni' => ['Impostazioni', 'fa-gear'],
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sectionTitles[$section][0]); ?> · AlmaCasa Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="CSS/theme.css">
    <link rel="stylesheet" href="CSS/style.css?v=<?php echo @filemtime(__DIR__ . '/CSS/style.css'); ?>">
</head>
<body>
<a href="#admin-main" class="skip-link">Salta al contenuto</a>
<div class="ac-admin">
    <nav class="ac-sidebar" aria-label="Navigazione amministratore">
        <div class="ac-side-brand d-flex align-items-center gap-2 p-3 mb-2">
            <span class="ac-logo" aria-hidden="true">A</span>
            <div>
                <div class="fw-bold text-white">AlmaCasa</div>
                <div class="ac-mono small" style="color:#cdbfac;background:#2d2a26;">admin</div>
            </div>
        </div>
        <?php foreach ($navItems as $key => [$label, $icon]): ?>
            <a href="admin.php?section=<?php echo $key; ?>" class="ac-side-link <?php echo $section === $key ? 'active' : ''; ?>">
                <span class="fas <?php echo $icon; ?>" aria-hidden="true"></span> <?php echo $label; ?>
            </a>
        <?php endforeach; ?>
        <div class="mt-auto p-3 border-top" style="border-color: rgba(255,255,255,0.1) !important;">
            <div class="d-flex align-items-center gap-2">
                <span class="ac-avatar" style="background:#4a463f;color:#fff;"><?php echo htmlspecialchars(user_initials($_SESSION['user_name'])); ?></span>
                <div class="small">
                    <div class="text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <a href="logout.php" style="color:#cdbfac;background:#2d2a26;" class="text-decoration-none">Esci</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="ac-admin-main" id="admin-main">
        <div class="p-4 p-lg-5">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h1 class="fw-bold mb-0"><?php echo htmlspecialchars($sectionTitles[$section][0]); ?></h1>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($sectionTitles[$section][1]); ?></p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($section !== 'impostazioni'): ?>
                <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Annunci attivi</div><div class="v"><?php echo $stats['listings']; ?></div></div></div>
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Studenti verificati</div><div class="v"><?php echo number_format($stats['students'], 0, ',', '.'); ?></div></div></div>
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Richieste aperte</div><div class="v"><?php echo $stats['open_requests']; ?></div></div></div>
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Tasso occupazione</div><div class="v"><?php echo $stats['occupancy']; ?>%</div></div></div>
                </div>
            <?php endif; ?>

            <?php
            if ($section === 'dashboard'): ?>
                <div class="ac-stat-card">
                    <h2 class="h5 fw-bold mb-3">Attività recenti</h2>
                    <?php if (!$changeLog): ?>
                        <p class="text-muted mb-0">Nessuna attività registrata.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th scope="col">Data</th><th scope="col">Admin</th><th scope="col">Azione</th><th scope="col">Dettagli</th></tr></thead>
                                <tbody>
                                <?php foreach ($changeLog as $c): ?>
                                    <tr>
                                        <td class="small text-muted"><?php echo htmlspecialchars(substr($c['created_at'], 0, 10)); ?></td>
                                        <td><?php echo htmlspecialchars($c['admin_name']); ?></td>
                                        <td><span class="badge badge-soft"><?php echo htmlspecialchars(ucfirst($c['action'])); ?> <?php echo htmlspecialchars($c['entity']); ?></span></td>
                                        <td class="small"><?php echo htmlspecialchars($c['details']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php
            elseif ($section === 'annunci'): ?>
                <div class="ac-stat-card mb-4" id="listing-form">
                    <h2 class="h5 fw-bold mb-3"><?php echo $editListing ? 'Modifica annuncio' : 'Nuovo annuncio'; ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="<?php echo $editListing ? 'update' : 'create'; ?>">
                        <?php if ($editListing): ?><input type="hidden" name="id" value="<?php echo (int) $lf['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="f-title">Titolo</label>
                                <input type="text" id="f-title" name="title" class="form-control" value="<?php echo htmlspecialchars($lf['title']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="f-type">Tipo</label>
                                <select id="f-type" name="type" class="form-select">
                                    <?php foreach (LISTING_TYPES as $k => $label): ?>
                                        <option value="<?php echo $k; ?>" <?php echo $lf['type'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="f-status">Stato</label>
                                <select id="f-status" name="status" class="form-select">
                                    <option value="published" <?php echo $lf['status'] === 'published' ? 'selected' : ''; ?>>Pubblicato</option>
                                    <option value="draft" <?php echo $lf['status'] === 'draft' ? 'selected' : ''; ?>>Bozza</option>
                                    <option value="archived" <?php echo $lf['status'] === 'archived' ? 'selected' : ''; ?>>Archiviato</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold" for="f-zone">Zona</label>
                                <input type="text" id="f-zone" name="zone" class="form-control" value="<?php echo htmlspecialchars($lf['zone']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold" for="f-distance">Distanza (km)</label>
                                <input type="number" step="0.1" min="0" id="f-distance" name="distance_km" class="form-control" value="<?php echo htmlspecialchars((string) $lf['distance_km']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold" for="f-price">Prezzo (&euro;)</label>
                                <input type="number" step="0.01" min="0" id="f-price" name="price" class="form-control" value="<?php echo htmlspecialchars((string) $lf['price']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold" for="f-deposit">Caparra (&euro;)</label>
                                <input type="number" step="0.01" min="0" id="f-deposit" name="deposit" class="form-control" value="<?php echo htmlspecialchars((string) $lf['deposit']); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold" for="f-size">Superficie (m&sup2;)</label>
                                <input type="number" min="0" id="f-size" name="size_sqm" class="form-control" value="<?php echo htmlspecialchars((string) $lf['size_sqm']); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold" for="f-beds">Posti letto</label>
                                <input type="number" min="1" id="f-beds" name="beds" class="form-control" value="<?php echo htmlspecialchars((string) $lf['beds']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="f-lat">Latitudine</label>
                                <input type="number" step="any" id="f-lat" name="lat" class="form-control" value="<?php echo htmlspecialchars((string) $lf['lat']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="f-lng">Longitudine</label>
                                <input type="number" step="any" id="f-lng" name="lng" class="form-control" value="<?php echo htmlspecialchars((string) $lf['lng']); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold" for="f-description">Descrizione</label>
                                <textarea id="f-description" name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($lf['description']); ?></textarea>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="f-verified" name="verified" <?php echo $lf['verified'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="f-verified">Annuncio verificato</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-ac"><?php echo $editListing ? 'Aggiorna' : 'Crea annuncio'; ?></button>
                            <?php if ($editListing): ?><a href="admin.php?section=annunci" class="btn btn-outline-secondary">Annulla</a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="ac-stat-card p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="px-4">Annuncio</th>
                                    <th scope="col">Tipo</th>
                                    <th scope="col">Prezzo</th>
                                    <th scope="col">Distanza</th>
                                    <th scope="col">Stato</th>
                                    <th scope="col" class="text-end px-4">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$listings): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nessun annuncio.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listings as $l): ?>
                                    <?php [$sl, $sc] = listing_status_meta($l['status']); ?>
                                    <tr>
                                        <td class="px-4">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="ac-photo" style="width:42px;height:42px;border-radius:8px;font-size:0;" aria-hidden="true"></span>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($l['title']); ?></div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($l['zone']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars(listing_type_label($l['type'])); ?></td>
                                        <td class="fw-bold">&euro;<?php echo eur0($l['price']); ?></td>
                                        <td class="text-ac"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $l['distance_km'], 1, '.', ''), '0'), '.')); ?> km</td>
                                        <td><span class="badge <?php echo $sc; ?>"><?php echo htmlspecialchars($sl); ?></span></td>
                                        <td class="text-end px-4">
                                            <a href="admin.php?section=annunci&edit=<?php echo (int) $l['id']; ?>#listing-form" class="text-decoration-none me-2">Modifica</a>
                                            <a href="admin.php?delete=<?php echo (int) $l['id']; ?>" class="text-danger text-decoration-none" data-confirm="Eliminare questo annuncio?" data-confirm-danger>Elimina</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php
            elseif ($section === 'studenti'): ?>
                <div class="ac-stat-card mb-4" id="user-form">
                    <h2 class="h5 fw-bold mb-3"><?php echo $editUser ? 'Modifica utente' : 'Nuovo utente'; ?></h2>
                    <form method="post" novalidate>
                        <input type="hidden" name="action" value="<?php echo $editUser ? 'user_update' : 'user_create'; ?>">
                        <?php if ($editUser): ?><input type="hidden" name="id" value="<?php echo (int) $editUser['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="u-name">Nome</label>
                                <input type="text" id="u-name" name="name" class="form-control" value="<?php echo htmlspecialchars($editUser['name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="u-email">Email</label>
                                <input type="email" id="u-email" name="email" class="form-control<?php echo $userEmailError !== '' ? ' is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($userEmailValue); ?>" required>
                                <?php if ($userEmailError !== ''): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($userEmailError); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="u-role">Ruolo</label>
                                <select id="u-role" name="role" class="form-select">
                                    <option value="user" <?php echo ($editUser['role'] ?? 'user') === 'user' ? 'selected' : ''; ?>>Studente</option>
                                    <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="u-status">Stato</label>
                                <select id="u-status" name="status" class="form-select">
                                    <option value="active" <?php echo ($editUser['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Attivo</option>
                                    <option value="blocked" <?php echo ($editUser['status'] ?? '') === 'blocked' ? 'selected' : ''; ?>>Bloccato</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold" for="u-password">Password</label>
                                <input type="password" id="u-password" name="password" class="form-control" placeholder="<?php echo $editUser ? 'invariata' : 'obbligatoria'; ?>" <?php echo $editUser ? '' : 'required'; ?>>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-ac"><?php echo $editUser ? 'Aggiorna utente' : 'Crea utente'; ?></button>
                            <?php if ($editUser): ?><a href="admin.php?section=studenti" class="btn btn-outline-secondary">Annulla</a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="ac-stat-card p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th scope="col" class="px-4">Nome</th><th scope="col">Email</th><th scope="col">Ruolo</th><th scope="col">Stato</th><th scope="col" class="text-end px-4">Azioni</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $u): ?>
                                <tr>
                                    <td class="px-4 fw-bold"><?php echo htmlspecialchars($u['name']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo $u['role'] === 'admin' ? '<span class="badge bg-dark">Admin</span>' : '<span class="badge badge-soft">Studente</span>'; ?></td>
                                    <td><?php echo $u['status'] === 'blocked' ? '<span class="badge bg-danger">Bloccato</span>' : '<span class="badge bg-success">Attivo</span>'; ?></td>
                                    <td class="text-end px-4">
                                        <a href="admin.php?section=studenti&edit_user=<?php echo (int) $u['id']; ?>#user-form" class="text-decoration-none me-2">Modifica</a>
                                        <?php if ((int) $u['id'] !== $adminId): ?>
                                            <a href="admin.php?delete_user=<?php echo (int) $u['id']; ?>" class="text-danger text-decoration-none" data-confirm="Eliminare questo utente?" data-confirm-danger>Elimina</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php
            elseif ($section === 'richieste'): ?>
                <?php if (!$requests): ?>
                    <div class="ac-stat-card"><p class="text-muted mb-0">Nessuna richiesta ricevuta.</p></div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($requests as $r): ?>
                            <?php [$rsl, $rsc] = request_status_meta_admin($r['status']); ?>
                            <div class="ac-stat-card">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <span class="badge badge-soft me-1"><?php echo $r['type'] === 'contact' ? 'Contatto' : 'Visita'; ?></span>
                                        <a href="listing.php?id=<?php echo (int) $r['listing_id']; ?>" class="fw-bold text-decoration-none"><?php echo htmlspecialchars($r['listing_title']); ?></a>
                                        <div class="text-muted small mt-1">
                                            Da <?php echo htmlspecialchars($r['user_name']); ?> (<?php echo htmlspecialchars($r['user_email']); ?>) · <?php echo htmlspecialchars(substr($r['created_at'], 0, 10)); ?>
                                            <?php echo $r['preferred_date'] ? ' · data preferita ' . htmlspecialchars($r['preferred_date']) : ''; ?>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo $rsc; ?> align-self-start rounded-pill"><?php echo htmlspecialchars($rsl); ?></span>
                                </div>
                                <div class="border rounded-3 p-3 my-3 bg-surface">
                                    <div class="mb-2">
                                        <span class="badge badge-soft">Studente</span>
                                        <div class="small mt-1"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                                    </div>
                                    <?php foreach (($requestMessages[(int) $r['id']] ?? []) as $m): ?>
                                        <div class="mb-2 <?php echo $m['sender'] === 'admin' ? 'text-end' : ''; ?>">
                                            <span class="badge <?php echo $m['sender'] === 'admin' ? 'bg-dark' : 'badge-soft'; ?>"><?php echo $m['sender'] === 'admin' ? 'Amministrazione' : 'Studente'; ?></span>
                                            <div class="small mt-1"><?php echo nl2br(htmlspecialchars($m['body'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($r['status'] === 'open'): ?>
                                    <form method="post" class="d-flex gap-2 mb-2">
                                        <input type="hidden" name="action" value="update_request">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                                        <button type="submit" name="status" value="accepted" class="btn btn-success btn-sm">Accetta</button>
                                        <button type="submit" name="status" value="declined" class="btn btn-outline-danger btn-sm">Rifiuta</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" class="row g-2 align-items-end">
                                    <input type="hidden" name="action" value="send_message">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                                    <div class="col-md-9">
                                        <label class="visually-hidden" for="msg-<?php echo (int) $r['id']; ?>">Messaggio per lo studente</label>
                                        <input type="text" id="msg-<?php echo (int) $r['id']; ?>" name="body" class="form-control form-control-sm" maxlength="1000" placeholder="Scrivi un messaggio allo studente..." required>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-outline-ac btn-sm w-100">Invia messaggio</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php
            elseif ($section === 'affitti'): ?>
                <div class="ac-stat-card p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="px-4">Studente</th>
                                    <th scope="col">Alloggio</th>
                                    <th scope="col">Durata</th>
                                    <th scope="col">Scadenza</th>
                                    <th scope="col" class="px-4">Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$rentals): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Nessun affitto attivo.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rentals as $r): ?>
                                    <?php $active = (int) $r['active'] === 1; ?>
                                    <tr>
                                        <td class="px-4">
                                            <div class="fw-bold"><?php echo htmlspecialchars($r['user_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($r['user_email']); ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['listing_title'])): ?>
                                                <a href="listing.php?id=<?php echo (int) $r['listing_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($r['listing_title']); ?></a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?php echo (int) $r['months']; ?> mesi</td>
                                        <td class="small"><?php echo date('d/m/Y', strtotime($r['end_date'])); ?></td>
                                        <td class="px-4"><span class="badge <?php echo $active ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $active ? 'Attivo' : 'Scaduto'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php
            elseif ($section === 'pagamenti'): ?>
                <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Affitti (canoni)</div><div class="h2 fw-bold text-ac mb-0">&euro;<?php echo eur2($rentsTotal); ?></div></div></div>
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Caparre</div><div class="h2 fw-bold text-ac mb-0">&euro;<?php echo eur2($depositsTotal); ?></div></div></div>
                    <div class="col"><div class="ac-stat-card"><div class="label-mono mb-1">Totale incassato</div><div class="h2 fw-bold mb-0">&euro;<?php echo eur2($paymentsTotal); ?></div></div></div>
                </div>
                <div class="ac-stat-card p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="px-4">Data</th>
                                    <th scope="col">Studente</th>
                                    <th scope="col">Alloggio</th>
                                    <th scope="col">Durata</th>
                                    <th scope="col" class="text-end">Affitto</th>
                                    <th scope="col" class="text-end">Caparra</th>
                                    <th scope="col" class="text-end px-4">Totale</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$payments): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Nessun pagamento ricevuto.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td class="px-4 small text-muted"><?php echo htmlspecialchars(substr((string) $p['start_date'], 0, 10)); ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($p['user_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($p['user_email']); ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['listing_title'])): ?>
                                                <a href="listing.php?id=<?php echo (int) $p['listing_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($p['listing_title']); ?></a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?php echo (int) $p['months']; ?> mesi</td>
                                        <td class="text-end fw-bold text-success">&euro;<?php echo eur2($p['rent_part']); ?></td>
                                        <td class="text-end fw-bold text-ac">&euro;<?php echo eur2($p['deposit']); ?></td>
                                        <td class="text-end px-4 fw-bold">&euro;<?php echo eur2($p['total_paid']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php
            elseif ($section === 'impostazioni'): ?>
                <div class="ac-stat-card" style="max-width: 640px;">
                    <h2 class="h5 fw-bold mb-1">Credenziali amministratore</h2>
                    <p class="text-muted small mb-3">Aggiorna i tuoi dati di accesso. Per confermare qualsiasi modifica inserisci la password attuale.</p>
                    <form method="post" novalidate>
                        <input type="hidden" name="action" value="admin_credentials">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="ac-name">Nome</label>
                                <input type="text" id="ac-name" name="name" class="form-control" value="<?php echo htmlspecialchars($adminProfile['name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="ac-email">Email</label>
                                <input type="email" id="ac-email" name="email" class="form-control" value="<?php echo htmlspecialchars($adminProfile['email']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="ac-new">Nuova password</label>
                                <input type="password" id="ac-new" name="new_password" class="form-control" autocomplete="new-password" placeholder="Lascia vuoto per non modificare">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="ac-confirm">Conferma nuova password</label>
                                <input type="password" id="ac-confirm" name="confirm" class="form-control" autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="ac-current">Password attuale</label>
                                <input type="password" id="ac-current" name="current_password" class="form-control" autocomplete="current-password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-ac mt-3">Salva credenziali</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/modal.php'; ?>
</body>
</html>
