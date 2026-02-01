<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_admin(): bool
{
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    return strtolower((string) $_SESSION['user_role']) === 'admin';
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
?>