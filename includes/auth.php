<?php

include_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    sec_session_start();
}

function require_login()
{
    global $mysqli;
    if (!login_check($mysqli)) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function is_admin()
{
    global $mysqli;
    if (login_check($mysqli) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }
    return false;
}

function require_admin()
{
    if (!is_admin()) {
        header('Location: login.php');
        exit;
    }
}
