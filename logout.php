<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear the session and log the user out
$_SESSION = [];
session_destroy();

header('Location: index.php');
exit;
?>
