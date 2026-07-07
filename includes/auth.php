<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const CAMPUS_EMAIL_DOMAIN = 'studio.unibo.it';

// Log the user out automatically if their account no longer exists in the database
function validate_session_user(mysqli $conn): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $exists = $res && mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    } catch (mysqli_sql_exception $e) {
        return;    }
    if (!$exists) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    }
}

function is_admin(): bool
{
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    return strtolower((string) $_SESSION['user_role']) === 'admin';
}

function is_campus_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    return $domain === CAMPUS_EMAIL_DOMAIN;
}

function require_login(): void
{
    if (!isset($_SESSION['user_id'])) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header("Location: login.php?redirect={$redirect}");
        exit;
    }
}

function require_admin(): void
{
    if (!isset($_SESSION['user_id'])) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header("Location: login.php?redirect={$redirect}");
        exit;
    }
    if (!is_admin()) {
        header("Location: index.php?error=forbidden");
        exit;
    }
}

function user_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'U';
}

if (isset($conn) && $conn instanceof mysqli) {
    validate_session_user($conn);
}
?>
