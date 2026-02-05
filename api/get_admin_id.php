<?php
include '../db/db_config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($res)) {
    echo json_encode(['success' => true, 'admin_id' => $row['id']]);
} else {
    echo json_encode(['success' => false, 'message' => 'No admin found']);
}
mysqli_stmt_close($stmt);
?>