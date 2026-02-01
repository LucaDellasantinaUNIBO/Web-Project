<?php
include 'db_config.php';

echo "Seeding properties...\n";

// Clear existing
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
mysqli_query($conn, "TRUNCATE TABLE properties");
mysqli_query($conn, "TRUNCATE TABLE rentals");
mysqli_query($conn, "TRUNCATE TABLE issues");
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");

$properties = [
    ['name' => 'Sunset Apartment', 'type' => 'apartment', 'status' => 'available', 'location' => 'Downtown', 'rooms' => 2, 'monthly_price' => 1200.00, 'image_url' => 'images/apartment.svg'],
    ['name' => 'Seaside Villa', 'type' => 'villa', 'status' => 'available', 'location' => 'Coastal Road', 'rooms' => 4, 'monthly_price' => 3500.00, 'image_url' => 'images/villa.svg'],
    ['name' => 'City Studio', 'type' => 'studio', 'status' => 'available', 'location' => 'University District', 'rooms' => 1, 'monthly_price' => 600.00, 'image_url' => 'images/studio.svg'],
    ['name' => 'Cozy Room', 'type' => 'room', 'status' => 'available', 'location' => 'Suburbs', 'rooms' => 1, 'monthly_price' => 400.00, 'image_url' => 'images/room.svg'],
    ['name' => 'Luxury Penthouse', 'type' => 'apartment', 'status' => 'rented', 'location' => 'Skyline Tower', 'rooms' => 3, 'monthly_price' => 2500.00, 'image_url' => 'images/apartment.svg'],
    ['name' => 'Family Home', 'type' => 'villa', 'status' => 'maintenance', 'location' => 'Green Valley', 'rooms' => 5, 'monthly_price' => 2800.00, 'image_url' => 'images/villa.svg'],
    ['name' => 'Student Dorm', 'type' => 'room', 'status' => 'available', 'location' => 'Campus A', 'rooms' => 1, 'monthly_price' => 350.00, 'image_url' => 'images/room.svg']
];

$stmt = mysqli_prepare($conn, "INSERT INTO properties (name, type, status, location, rooms, monthly_price, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");

foreach ($properties as $p) {
    mysqli_stmt_bind_param($stmt, "ssssids", $p['name'], $p['type'], $p['status'], $p['location'], $p['rooms'], $p['monthly_price'], $p['image_url']);
    mysqli_stmt_execute($stmt);
}

mysqli_stmt_close($stmt);

echo "Seeded " . count($properties) . " properties.\n";

// Ensure Admin User
$email = 'admin@housing.com';
$check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
if (mysqli_num_rows($check) == 0) {
    $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
    $pass = hash('sha512', 'admin123' . $salt);
    $name = 'Admin';
    $role = 'admin';
    $status = 'active';

    $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, salt, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssssss", $name, $email, $pass, $salt, $role, $status);
    mysqli_stmt_execute($stmt);
    echo "Created admin user: $email / admin123\n";
}

// Ensure Standard User
$email = 'alex@tenant.com';
$check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
if (mysqli_num_rows($check) == 0) {
    $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
    $pass = hash('sha512', 'student123' . $salt);
    $name = 'Alex Tenant';
    $role = 'user';
    $status = 'active';

    $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, salt, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssssss", $name, $email, $pass, $salt, $role, $status);
    mysqli_stmt_execute($stmt);
    echo "Created standard user: $email / student123\n";
}

echo "Done.\n";
