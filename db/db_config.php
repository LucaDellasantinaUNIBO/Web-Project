<?php
ob_start(); // Start output buffering to catch any accidental whitespace
$host = "localhost";
$user = "root";
$password = "";
$database = "housing_rentals";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
$mysqli = $conn;
