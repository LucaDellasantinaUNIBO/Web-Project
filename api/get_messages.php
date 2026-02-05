<?php
include '../includes/functions.php';
include '../db/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    sec_session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo '<div class="text-center text-muted">Not logged in</div>';
    exit;
}

$currentUserId = $_SESSION['user_id'];
$otherUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$rentalId = isset($_GET['rental_id']) ? (int) $_GET['rental_id'] : 0;

if ($otherUserId) {
    
    $query = "SELECT m.*, 
              (SELECT name FROM users WHERE id = m.sender_id LIMIT 1) as sender_name,
              (SELECT role FROM users WHERE id = m.sender_id LIMIT 1) as sender_role
              FROM messages m 
              WHERE (m.sender_id = $currentUserId AND m.receiver_id = $otherUserId)
                 OR (m.sender_id = $otherUserId AND m.receiver_id = $currentUserId)
              ORDER BY m.created_at ASC";
} elseif ($rentalId) {
    
    $query = "SELECT m.*,
              (SELECT name FROM users WHERE id = m.sender_id LIMIT 1) as sender_name,
              (SELECT role FROM users WHERE id = m.sender_id LIMIT 1) as sender_role
              FROM messages m 
              WHERE m.rental_id = $rentalId 
              ORDER BY m.created_at ASC";
} else {
    
    $adminQ = mysqli_query($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    if ($adminRow = mysqli_fetch_assoc($adminQ)) {
        $otherUserId = $adminRow['id'];
        $query = "SELECT m.*,
                  (SELECT name FROM users WHERE id = m.sender_id LIMIT 1) as sender_name,
                  (SELECT role FROM users WHERE id = m.sender_id LIMIT 1) as sender_role
                  FROM messages m 
                  WHERE (m.sender_id = $currentUserId AND m.receiver_id = $otherUserId)
                     OR (m.sender_id = $otherUserId AND m.receiver_id = $currentUserId)
                  ORDER BY m.created_at ASC";
    } else {
        echo '<div class="text-center text-muted">No admin found</div>';
        exit;
    }
}

$res = mysqli_query($conn, $query);

if (!$res) {
    echo '<div class="text-center text-danger">Error loading messages: ' . htmlspecialchars(mysqli_error($conn)) . '</div>';
    exit;
}

if (mysqli_num_rows($res) == 0) {
    echo '<div class="text-center text-muted">No messages yet. Start the conversation!</div>';
    exit;
}

while ($row = mysqli_fetch_assoc($res)) {
    $isMe = ($row['sender_id'] == $currentUserId);
    $align = $isMe ? 'text-end' : 'text-start';
    $bg = $isMe ? 'bg-primary text-white' : 'bg-light border';

    $senderName = $row['sender_name'] ?? 'Unknown';

    echo "<div class='mb-2 $align'>";
    echo "<div class='d-inline-block p-2 px-3 rounded-3 $bg' style='max-width: 80%;'>";
    echo "<div class='small fw-bold mb-1'>" . htmlspecialchars($senderName) . "</div>";
    echo "<div>" . nl2br(htmlspecialchars($row['message'])) . "</div>";
    echo "<div class='small mt-1 opacity-75' style='font-size: 0.75em;'>" . date('H:i', strtotime($row['created_at'])) . "</div>";
    echo "</div>";
    echo "</div>";
}
?>