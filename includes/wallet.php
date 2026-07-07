<?php

function get_wallet_balance(mysqli $conn, int $userId): float
{
    try {
        $stmt = mysqli_prepare($conn, "SELECT wallet_balance FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ? (float) $row['wallet_balance'] : 0.0;
    } catch (mysqli_sql_exception $e) {
        return 0.0;
    }
}

function card_number_valid(string $number): bool
{
    return strlen(preg_replace('/\D/', '', $number)) === 16;
}

// Return true only if the expiry date is well-formed and not in the past
function card_expiry_valid(string $expiry): bool
{
    if (!preg_match('/^\s*(\d{1,2})\s*\/\s*(\d{2}|\d{4})\s*$/', $expiry, $m)) {
        return false;
    }
    $month = (int) $m[1];
    $year = (int) $m[2];
    if ($year < 100) {
        $year += 2000;
    }
    if ($month < 1 || $month > 12) {
        return false;
    }
    $now = new DateTime('first day of this month');
    $exp = DateTime::createFromFormat('Y-n-j', $year . '-' . $month . '-1');
    return $exp !== false && $exp >= $now;
}

function canonical_expiry(string $expiry): string
{
    if (!preg_match('/^\s*(\d{1,2})\s*\/\s*(\d{2}|\d{4})\s*$/', $expiry, $m)) {
        return '';
    }
    $month = (int) $m[1];
    $year = (int) $m[2];
    if ($year >= 100) {
        $year %= 100;
    }
    return sprintf('%02d/%02d', $month, $year);
}

// Hash the card data so we can match it later without storing the full number
function card_fingerprint(string $number, string $expiry, string $holder, string $cvc): string
{
    $number = preg_replace('/\D/', '', $number);
    $cvc = preg_replace('/\D/', '', $cvc);
    $holder = strtolower(trim($holder));
    $expiry = canonical_expiry($expiry);
    return md5($number . $expiry . $holder . $cvc);
}

function get_user_card(mysqli $conn, int $userId): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT card_holder, card_last4, card_expiry, card_fingerprint FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return ($row && $row['card_fingerprint']) ? $row : null;
}

function get_active_rental(mysqli $conn, int $userId, int $listingId): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT id, months, start_date, end_date, total_paid FROM rentals WHERE user_id = ? AND listing_id = ? AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $userId, $listingId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}
