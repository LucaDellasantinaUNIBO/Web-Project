<?php
include 'db_config.php';

$sql = "CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rental_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id)
)";

if (mysqli_query($conn, $sql)) {
    echo "Messages table created successfully.";
} else {
    echo "Error creating table: " . mysqli_error($conn);
}
?>