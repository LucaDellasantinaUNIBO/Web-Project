<?php

if (!isset($conn)) {
    return;
}

$siteSettings = [];

$tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'site_settings'");
if (mysqli_num_rows($tableCheck) == 0) {

    $sql = "CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )";
    mysqli_query($conn, $sql);

    $defaults = [
        'site_name' => 'Housing Rentals',
        'site_description' => 'Find your perfect home with Housing Rentals. Browser properties, contact owners, and easy booking.'
    ];

    foreach ($defaults as $key => $val) {
        $stmt = mysqli_prepare($conn, "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "ss", $key, $val);
        mysqli_stmt_execute($stmt);
    }
}

$res = mysqli_query($conn, "SELECT * FROM site_settings");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $siteSettings[$row['setting_key']] = $row['setting_value'];
    }
}

$SITE_NAME = $siteSettings['site_name'] ?? 'Housing Rentals';
$SITE_DESC = $siteSettings['site_description'] ?? 'Housing Rentals Platform';