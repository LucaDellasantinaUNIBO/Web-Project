<?php
include '../db/db_config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$rentalId = isset($_POST['rental_id']) ? (int) $_POST['rental_id'] : null;
$receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
$message = trim($_POST['message']);
$senderId = $_SESSION['user_id'];

// Compatibility: If rental_id is set but receiver_id is missing
if ($rentalId && !$receiverId) {
    // If sender is NOT admin, send to ADMIN
    // Find an admin (e.g. first one)
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $receiverId = $row['id'];
    }
}

if (($rentalId || $receiverId) && $message) {
    // If rental_id is 0/null, make sure we insert NULL
    $rIdVal = $rentalId ? $rentalId : null;
    $stmt = mysqli_prepare($conn, "INSERT INTO messages (rental_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iiis", $rIdVal, $senderId, $receiverId, $message);
    $success = mysqli_stmt_execute($stmt);
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false]);
}
?>