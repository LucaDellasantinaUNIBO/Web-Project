<?php
include '../db/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    exit; // Silent fail
}

$currentUserId = $_SESSION['user_id'];
$otherUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$rentalId = isset($_GET['rental_id']) ? (int) $_GET['rental_id'] : 0;

if ($otherUserId) {
    // User-to-User (Admin-User) Chat
    $query = "SELECT m.*, u.name as sender_name, u.role as sender_role 
              FROM messages m 
              JOIN users u ON m.sender_id = u.id 
              WHERE (m.sender_id = $currentUserId AND m.receiver_id = $otherUserId)
                 OR (m.sender_id = $otherUserId AND m.receiver_id = $currentUserId)
              ORDER BY m.created_at ASC";
} elseif ($rentalId) {
    // Legacy Rental Chat
    $query = "SELECT m.*, u.name as sender_name, u.role as sender_role 
              FROM messages m 
              JOIN users u ON m.sender_id = u.id 
              WHERE m.rental_id = $rentalId 
              ORDER BY m.created_at ASC";
} else {
    exit;
}
$res = mysqli_query($conn, $query);

while ($row = mysqli_fetch_assoc($res)) {
    $isMe = ($row['sender_id'] == $currentUserId);
    $align = $isMe ? 'text-end' : 'text-start';
    $bg = $isMe ? 'bg-chat-me' : 'bg-white border';
    $radius = $isMe ? 'rounded-top-left-3 rounded-bottom-3' : 'rounded-top-right-3 rounded-bottom-3';

    echo "<div class='mb-2 $align'>";
    echo "<div class='d-inline-block p-2 px-3 rounded-4 $bg' style='max-width: 80%;'>";
    echo "<div class='small fw-bold mb-1'>" . htmlspecialchars($row['sender_name']) . "</div>";
    echo "<div>" . nl2br(htmlspecialchars($row['message'])) . "</div>";
    echo "<div class='small text-end mt-1 text-white' style='font-size: 0.7em;'>" . date('H:i', strtotime($row['created_at'])) . "</div>";
    echo "</div>";
    echo "</div>";
}
?>