<?php
include 'db_config.php';

echo "Starting migration: Split names...\n";

// 1. Add columns if they don't exist
$check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'first_name'");
if (mysqli_num_rows($check) == 0) {
    echo "Adding first_name and last_name columns...\n";
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN first_name VARCHAR(50) NOT NULL AFTER id");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN last_name VARCHAR(50) NOT NULL AFTER first_name");
} else {
    echo "Columns already exist. Skipping ADD COLUMN.\n";
}

// 2. Migrate data
echo "Migrating existing names...\n";
$res = mysqli_query($conn, "SELECT id, name FROM users");
while ($row = mysqli_fetch_assoc($res)) {
    $fullName = trim($row['name']);
    $parts = explode(' ', $fullName, 2);

    $firstName = $parts[0];
    $lastName = $parts[1] ?? ''; // Handle single names

    $stmt = mysqli_prepare($conn, "UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $firstName, $lastName, $row['id']);
    mysqli_stmt_execute($stmt);
}

// 3. Drop old column (Optional, maybe keep it for compatibility or drop later? Plan said drop/replace logic implies we use new ones)
// For safety, let's keep 'name' for now or update it to be generated? 
// The prompt implies we want to identify by name and surname separately. 
// Let's NOT drop 'name' yet to avoid breaking other un-updated scripts immediately, 
// OR we can drop it to force us to fix everything. 
// Given the prompt "identificati con nome e cognome", I'll focus on adding and using them.
// I will NOT drop the 'name' column in this script to be safe, but I will make the code use first/last.
// Actually, to be clean, I should eventually remove it. But let's stick to adding for now.

echo "Migration completed successfully.\n";
?>