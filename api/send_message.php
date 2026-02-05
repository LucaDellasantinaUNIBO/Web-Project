<?php
ob_start();
include '../includes/functions.php';
include '../db/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    sec_session_start();
}

ob_clean();
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/send_message_errors.log');

try {

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized', 'error' => 'Not logged in']);
        exit;
    }


    $rentalId = isset($_POST['rental_id']) && $_POST['rental_id'] !== '' ? (int) $_POST['rental_id'] : null;
    $receiverId = isset($_POST['receiver_id']) && $_POST['receiver_id'] !== '' ? (int) $_POST['receiver_id'] : 0;
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $senderId = $_SESSION['user_id'];


    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Empty message', 'error' => 'Message is required']);
        exit;
    }


    if (!$receiverId) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Database error', 'error' => mysqli_error($conn)]);
            exit;
        }

        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($res)) {
            $receiverId = $row['id'];
        } else {
            echo json_encode(['success' => false, 'message' => 'No admin found', 'error' => 'No admin user in database']);
            exit;
        }
        mysqli_stmt_close($stmt);
    }


    if (!$receiverId) {
        echo json_encode(['success' => false, 'message' => 'No receiver', 'error' => 'Could not determine message receiver']);
        exit;
    }


    $stmt = mysqli_prepare($conn, "INSERT INTO messages (rental_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Query preparation failed', 'error' => mysqli_error($conn)]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "iiis", $rentalId, $senderId, $receiverId, $message);
    $success = mysqli_stmt_execute($stmt);

    if (!$success) {
        echo json_encode(['success' => false, 'message' => 'Insert failed', 'error' => mysqli_error($conn)]);
        exit;
    }

    $insertId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    echo json_encode([
        'success' => true,
        'message' => 'Message sent',
        'id' => $insertId,
        'receiver_id' => $receiverId
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception', 'error' => $e->getMessage()]);
}