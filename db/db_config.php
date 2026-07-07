<?php
$host = "localhost";
$user = "root";
$password = "";
$database = "almacasa";

$conn = mysqli_connect($host, $user, $password);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($conn, $database);

mysqli_set_charset($conn, "utf8mb4");
?>
