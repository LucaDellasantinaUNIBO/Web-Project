<?php
include __DIR__ . '/db_config.php';

echo "Checking rentals table for status column...\n";

$result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'status'");
if ($result && $result->num_rows > 0) {
    echo "Column 'status' already exists.\n";
} else {
    echo "Adding 'status' column...\n";
    $sql = "ALTER TABLE rentals ADD COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved'";
    if ($conn->query($sql) === TRUE) {
        echo "Column 'status' added successfully.\n";
    } else {
        echo "Error creating column: " . $conn->error . "\n";
    }
}
$conn->close();
?>