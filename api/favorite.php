<?php
// Endpoint to save or remove a listing from the user's favourites (AJAX, with form fallback)
include '../db/db_config.php';
include '../includes/auth.php';

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function sanitize_redirect(string $redirect): string
{
    if ($redirect === '' || preg_match('/^https?:/i', $redirect) || strpos($redirect, '//') === 0) {
        return 'search.php';
    }
    return $redirect;
}

$redirect = sanitize_redirect($_POST['redirect'] ?? 'search.php');

function respond(bool $isAjax, array $payload, string $redirect, int $code = 200): void
{
    if ($isAjax) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload);
    } else {
        header('Location: ../' . $redirect);
    }
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respond($isAjax, ['success' => false, 'message' => 'Devi accedere per salvare gli annunci.'], 'login.php', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond($isAjax, ['success' => false, 'message' => 'Metodo non consentito.'], $redirect, 405);
}

if (is_admin()) {
    respond($isAjax, ['success' => false, 'message' => 'Gli amministratori non possono salvare annunci.'], $redirect, 403);
}

$userId = (int) $_SESSION['user_id'];
$listingId = (int) ($_POST['listing_id'] ?? 0);

if ($listingId <= 0) {
    respond($isAjax, ['success' => false, 'message' => 'Annuncio non valido.'], $redirect, 400);
}

$stmt = mysqli_prepare($conn, "SELECT id FROM listings WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $listingId);
mysqli_stmt_execute($stmt);
$exists = mysqli_stmt_get_result($stmt);
$listingExists = $exists && mysqli_fetch_assoc($exists);
mysqli_stmt_close($stmt);

if (!$listingExists) {
    respond($isAjax, ['success' => false, 'message' => 'Annuncio non trovato.'], $redirect, 404);
}

$stmt = mysqli_prepare($conn, "SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $userId, $listingId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$favorite = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if ($favorite) {
    $stmt = mysqli_prepare($conn, "DELETE FROM favorites WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $favorite['id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $saved = false;
} else {
    $stmt = mysqli_prepare($conn, "INSERT INTO favorites (user_id, listing_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ii", $userId, $listingId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $saved = true;
}

respond($isAjax, [
    'success' => true,
    'saved' => $saved,
    'message' => $saved ? 'Annuncio salvato.' : 'Annuncio rimosso dai salvati.'
], $redirect);
