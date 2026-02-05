<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_admin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$action = $_POST['action'] ?? '';

$currentTab = $_GET['tab'] ?? 'overview';
if (!in_array($currentTab, ['overview', 'properties', 'bookings', 'users', 'messages', 'settings'])) {
    $currentTab = 'overview';
}

$allowedTypes = ['apartment', 'villa', 'studio', 'room'];
$allowedStatuses = ['available', 'rented', 'maintenance', 'renovation'];

$defaultImages = [
    'apartment' => 'images/trilocale.png',
    'villa' => 'images/villa.png',
    'studio' => 'images/dimorastorica.png',
    'room' => 'images/monolocale.png'
];

$statusFilter = $_GET['status'] ?? 'all';
$allowedFilters = array_merge(['all'], $allowedStatuses);
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

function status_label(string $status): string
{
    return ucfirst($status);
}

function status_badge_class(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'available') {
        return 'bg-success';
    }
    if ($status === 'rented') {
        return 'bg-warning text-dark';
    }
    if ($status === 'maintenance') {
        return 'bg-secondary';
    }
    if ($status === 'renovation') {
        return 'bg-danger';
    }
    return 'bg-light text-dark border';
}

function type_label(string $type): string
{
    return ucfirst($type);
}

function log_change(mysqli $conn, int $adminId, string $action, string $entity, int $entityId, string $details): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO change_log (admin_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, "issis", $adminId, $action, $entity, $entityId, $details);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $propertyName = null;
    $stmt = mysqli_prepare($conn, "SELECT name FROM properties WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $propertyName = $row['name'] ?? null;
        mysqli_stmt_close($stmt);
    }
    mysqli_begin_transaction($conn);

    $deleteOk = true;
    $errorText = '';

    $stmt = mysqli_prepare($conn, "DELETE FROM issues WHERE property_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        $deleteOk = mysqli_stmt_execute($stmt);
        $errorText = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $deleteOk = false;
        $errorText = mysqli_error($conn);
    }

    if ($deleteOk) {
        $stmt = mysqli_prepare($conn, "DELETE FROM rentals WHERE property_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            $deleteOk = mysqli_stmt_execute($stmt);
            $errorText = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
    }

    if ($deleteOk) {
        $stmt = mysqli_prepare($conn, "DELETE FROM properties WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            $deleteOk = mysqli_stmt_execute($stmt);
            $errorText = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
    }

    if ($deleteOk) {
        mysqli_commit($conn);
        $details = $propertyName ? "Deleted property {$propertyName}." : "Deleted property #{$id}.";
        log_change($conn, $_SESSION['user_id'], 'delete', 'property', $id, $details);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Property deleted.'];
    } else {
        mysqli_rollback($conn);
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed: ' . $errorText];
    }

    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'delete_all') {
        $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM properties");
        $countRow = $countResult ? mysqli_fetch_assoc($countResult) : null;
        $propertyCount = $countRow ? (int) $countRow['total'] : 0;
        if ($countResult) {
            mysqli_free_result($countResult);
        }

        mysqli_begin_transaction($conn);
        $deleteOk = true;
        $errorText = '';

        if (!mysqli_query($conn, "DELETE FROM issues")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
        if ($deleteOk && !mysqli_query($conn, "DELETE FROM rentals")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
        if ($deleteOk && !mysqli_query($conn, "DELETE FROM properties")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }

        if ($deleteOk) {
            mysqli_commit($conn);
            $details = $propertyCount > 0 ? "Deleted all properties ({$propertyCount})." : "Delete all properties request (none found).";
            log_change($conn, $_SESSION['user_id'], 'delete', 'property', 0, $details);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'All properties deleted.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed: ' . $errorText];
        }

        header('Location: admin.php');
        exit;
    }

    if (in_array($action, ['create', 'update'])) {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $status = $_POST['status'] ?? '';
        $location = trim($_POST['location'] ?? '');
        // $rooms = (int) ($_POST['rooms'] ?? 1); // Deprecated
        $sqm = (int) ($_POST['sqm'] ?? 50);
        $beds = (int) ($_POST['beds'] ?? 1);
        $baths = (int) ($_POST['baths'] ?? 1);
        $lat = !empty($_POST['lat']) ? (float) $_POST['lat'] : null;
        $lng = !empty($_POST['lng']) ? (float) $_POST['lng'] : null;
        $monthlyPrice = (float) ($_POST['monthly_price'] ?? 0);

        $useCustomImage = isset($_POST['use_custom_image']);
        $customImageUrl = trim($_POST['image_url'] ?? '');

        if ($useCustomImage) {
            $imageUrl = $customImageUrl === '' ? null : $customImageUrl;
        } else {
            $imageUrl = $defaultImages[$type] ?? null;
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Invalid type.';
        }
        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Invalid status.';
        }
        if ($location === '') {
            $errors[] = 'Location is required.';
        }
        if ($beds < 1 && $baths < 1) {
            $errors[] = 'Must have at least 1 Bed or 1 Bath.';
        }
        if ($monthlyPrice <= 0) {
            $errors[] = 'Invalid price.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = mysqli_prepare($conn, "INSERT INTO properties (name, type, status, location, sqm, beds, baths, monthly_price, image_url, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssssiiidsdd", $name, $type, $status, $location, $sqm, $beds, $baths, $monthlyPrice, $imageUrl, $lat, $lng);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $newId = mysqli_insert_id($conn);
                log_change($conn, $_SESSION['user_id'], 'create', 'property', $newId, "Created property {$name}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Property created.'];
                header('Location: admin.php');
                exit;
            }
            if ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = mysqli_prepare($conn, "UPDATE properties SET name = ?, type = ?, status = ?, location = ?, sqm = ?, beds = ?, baths = ?, monthly_price = ?, image_url = ?, lat = ?, lng = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssssiiidsddi", $name, $type, $status, $location, $sqm, $beds, $baths, $monthlyPrice, $imageUrl, $lat, $lng, $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                log_change($conn, $_SESSION['user_id'], 'update', 'property', $id, "Updated property {$name}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Property updated.'];
                header('Location: admin.php');
                exit;
            }
        }
    }
}


$editProperty = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM properties WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $editId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editProperty = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

$formMode = $editProperty ? 'update' : 'create';
$formData = [
    'id' => $editProperty['id'] ?? '',
    'name' => $editProperty['name'] ?? '',
    'type' => $editProperty['type'] ?? 'apartment',
    'status' => $editProperty['status'] ?? 'available',
    'location' => $editProperty['location'] ?? '',
    'sqm' => $editProperty['sqm'] ?? 50,
    // 'rooms' => $editProperty['rooms'] ?? 1,
    'beds' => $editProperty['beds'] ?? 1,
    'baths' => $editProperty['baths'] ?? 1,
    'monthly_price' => $editProperty['monthly_price'] ?? 500.00,
    'lat' => $editProperty['lat'] ?? '',
    'lng' => $editProperty['lng'] ?? '',
    'image_url' => $editProperty['image_url'] ?? ''
];

$currentTab = $_GET['tab'] ?? 'properties';
$allowedTabs = ['properties', 'users'];
if (!in_array($currentTab, $allowedTabs, true)) {
    $currentTab = 'properties';
}

// User Actions
if (isset($_GET['delete_user'])) {
    $id = (int) $_GET['delete_user'];
    if ($id === $_SESSION['user_id']) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You cannot delete yourself.'];
    } else {
        mysqli_begin_transaction($conn);
        $deleteOk = true;

        $tables = ['issues', 'rentals', 'transactions', 'change_log'];
        foreach ($tables as $table) {
            $col = (($table === 'change_log') ? 'admin_id' : 'user_id');
            if ($deleteOk && !mysqli_query($conn, "DELETE FROM $table WHERE $col = $id")) {
                $deleteOk = false;
            }
        }

        if ($deleteOk && !mysqli_query($conn, "DELETE FROM users WHERE id = $id")) {
            $deleteOk = false;
        }

        if ($deleteOk) {
            mysqli_commit($conn);
            log_change($conn, $_SESSION['user_id'], 'delete', 'user', $id, "Deleted user #{$id}.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'User deleted.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed.'];
        }
    }
    // Redirect removed
}


if ($action === 'status_toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? 'available';

    if (in_array($newStatus, ['available', 'maintenance'])) {
        $stmt = mysqli_prepare($conn, "UPDATE properties SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $newStatus, $id);
        if (mysqli_stmt_execute($stmt)) {
            $msg = $newStatus === 'maintenance' ? "Property put into maintenance." : "Property is now available.";
            log_change($conn, $_SESSION['user_id'], 'update', 'property', $id, $msg);
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Status update failed.'];
        }
        mysqli_stmt_close($stmt);
    }
    // Redirect removed
}

if ($action === 'approve_rental') {
    $rentalId = (int) ($_POST['rental_id'] ?? 0);
    // Fetch rental details
    $rentQ = mysqli_query($conn, "SELECT property_id, total_cost FROM rentals WHERE id = $rentalId AND status = 'pending'");
    $rentalData = mysqli_fetch_assoc($rentQ);

    if ($rentalData) {
        $propId = $rentalData['property_id'];
        mysqli_begin_transaction($conn);
        // 1. Update Rental Status
        $updRental = mysqli_query($conn, "UPDATE rentals SET status = 'approved' WHERE id = $rentalId");

        // 2. Update Property Status
        $updProp = mysqli_query($conn, "UPDATE properties SET status = 'rented' WHERE id = $propId");

        if ($updRental && $updProp) {
            // 3. Auto-reject other pending requests for the same property
            $conflictQ = mysqli_query($conn, "SELECT id, user_id, total_cost FROM rentals WHERE property_id = $propId AND status = 'pending' AND id != $rentalId");
            while ($conflict = mysqli_fetch_assoc($conflictQ)) {
                $cId = $conflict['id'];
                $cUser = $conflict['user_id'];
                $cCost = $conflict['total_cost'];

                // Reject
                mysqli_query($conn, "UPDATE rentals SET status = 'rejected' WHERE id = $cId");

                // Refund
                mysqli_query($conn, "UPDATE users SET credit = credit + $cCost WHERE id = $cUser");

                // Log Transaction
                $balQ = mysqli_query($conn, "SELECT credit FROM users WHERE id = $cUser");
                $newBal = mysqli_fetch_assoc($balQ)['credit'];
                $desc = "Refund for rejected rental #$cId (Property rented to another user)";
                mysqli_query($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES ($cUser, 'topup', $cCost, $newBal, '$desc')");

                log_change($conn, $_SESSION['user_id'], 'reject', 'rental', $cId, "Auto-rejected rental #$cId.");
            }

            mysqli_commit($conn);
            log_change($conn, $_SESSION['user_id'], 'approve', 'rental', $rentalId, "Approved rental #$rentalId.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Rental approved and conflicting requests rejected.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Error approving rental.'];
        }
    }
    // Redirect removed
}

if ($action === 'reject_rental') {
    $rentalId = (int) ($_POST['rental_id'] ?? 0);
    // Fetch rental details including user for refund
    $rentQ = mysqli_query($conn, "SELECT user_id, total_cost, property_id FROM rentals WHERE id = $rentalId AND status = 'pending'");
    $rentalData = mysqli_fetch_assoc($rentQ);

    if ($rentalData) {
        $refundAmount = $rentalData['total_cost'];
        $userId = $rentalData['user_id'];

        mysqli_begin_transaction($conn);

        // 1. Update Rental Status
        $updRental = mysqli_query($conn, "UPDATE rentals SET status = 'rejected' WHERE id = $rentalId");

        // 2. Refund User
        $updUser = mysqli_query($conn, "UPDATE users SET credit = credit + $refundAmount WHERE id = $userId");

        // 3. Log Transaction
        // Fetch new balance
        $balQ = mysqli_query($conn, "SELECT credit FROM users WHERE id = $userId");
        $newBal = mysqli_fetch_assoc($balQ)['credit'];

        $desc = "Refund for rejected rental #$rentalId";
        $insTrans = mysqli_query($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES ($userId, 'topup', $refundAmount, $newBal, '$desc')");

        if ($updRental && $updUser && $insTrans) {
            mysqli_commit($conn);
            log_change($conn, $_SESSION['user_id'], 'reject', 'rental', $rentalId, "Rejected rental #$rentalId.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Rental rejected and user refunded.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Error rejecting rental.'];
        }
    }
    // Redirect removed
}

if (strpos($action, 'user_') === 0) {
    if ($action === 'user_create') {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $errors[] = 'All fields are required.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_fetch($stmt)) {
                $errors[] = 'Email already exists.';
                mysqli_stmt_close($stmt);
            } else {
                mysqli_stmt_close($stmt);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $passwordHash, $role, $status);
                if (mysqli_stmt_execute($stmt)) {
                    log_change($conn, $_SESSION['user_id'], 'create', 'user', mysqli_insert_id($conn), "Created user {$name}.");
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'User created successfully!'];
                    header('Location: admin.php?tab=users');
                    exit;
                } else {
                    $errors[] = 'Database error: ' . mysqli_error($conn);
                }
            }
        }
    } elseif ($action === 'user_update') {
        $editUserId = (int) ($_POST['user_id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';

        if ($editUserId <= 0) {
            $errors[] = 'Invalid user ID.';
        } else {
            if (!empty($password)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, password=?, role=?, status=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "sssssi", $name, $email, $passwordHash, $role, $status, $editUserId);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "ssssi", $name, $email, $role, $status, $editUserId);
            }

            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $_SESSION['user_id'], 'update', 'user', $editUserId, "Updated user {$name}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated successfully!'];
                header('Location: admin.php?tab=users');
                exit;
            } else {
                $errors[] = 'Update failed: ' . mysqli_error($conn);
            }
        }
    }

}

// Handle Settings Update
if ($action === 'update_settings') {
    $siteName = trim($_POST['site_name'] ?? '');
    $siteDesc = trim($_POST['site_description'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    $currentPass = $_POST['current_password'] ?? '';

    // Update Site Settings
    $settingsToUpdate = [
        'site_name' => $siteName,
        'site_description' => $siteDesc
    ];

    foreach ($settingsToUpdate as $key => $val) {
        // Upsert logic (Insert on Duplicate Key Update)
        $stmt = mysqli_prepare($conn, "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        mysqli_stmt_bind_param($stmt, "sss", $key, $val, $val);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Update Admin Email
    if (!empty($adminEmail)) {
        // Check if email belongs to another user
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
        mysqli_stmt_bind_param($stmt, "si", $adminEmail, $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_fetch($stmt)) {
            $errors[] = "Email '$adminEmail' is already taken by another user.";
            mysqli_stmt_close($stmt);
        } else {
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "UPDATE users SET email = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $adminEmail, $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
            $_SESSION['user_email'] = $adminEmail; // Update session
            mysqli_stmt_close($stmt);
        }
    }

    // Update Password logic (existing logic placeholder was removed in previous steps or needs to be merged)
    if (!empty($newPass)) {
        // Verify current password first
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $me = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$me || !password_verify($currentPass, $me['password'])) {
            $errors[] = "Current password is incorrect.";
        } elseif ($newPass !== $confirmPass) {
            $errors[] = "New passwords do not match.";
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $newHash, $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    if (empty($errors)) {
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Settings updated successfully!'];
        // Reload to reflect changes
        header('Location: admin.php?tab=settings');
        exit;
    }
}
if ($currentTab === 'properties') {
    $listQuery = "SELECT * FROM properties";
    $listParams = [];
    $listTypes = '';
    if ($statusFilter !== 'all') {
        $listQuery .= " WHERE status = ?";
        $listParams[] = $statusFilter;
        $listTypes .= 's';
    }
    $listQuery .= " ORDER BY id DESC";
    $listStmt = mysqli_prepare($conn, $listQuery);
    if ($listStmt && $listParams) {
        mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
    }
    if ($listStmt) {
        mysqli_stmt_execute($listStmt);
        $listResult = mysqli_stmt_get_result($listStmt);
        while ($row = mysqli_fetch_assoc($listResult)) {
            $properties[] = $row;
        }
        mysqli_stmt_close($listStmt);
    }
}

$usersList = [];
$editUser = null;
if ($currentTab === 'users') {
    if (isset($_GET['delete_user'])) {
        $delId = (int) $_GET['delete_user'];
        // Prevent self-deletion
        if ($delId === $_SESSION['user_id']) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You cannot delete yourself.'];
        } else {
            $stmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
        }
    }


}

if (isset($_GET['edit_user'])) {
    $editUserId = (int) $_GET['edit_user'];
    $stmt = mysqli_prepare($conn, "SELECT name, email, role, status FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $editUserId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $editUser = $res ? mysqli_fetch_assoc($res) : null;
    if ($editUser)
        $editUser['id'] = $editUserId;
}
// Fetch users with new columns
// Fetch users (Schema has 'name', not first/last)
$res = mysqli_query($conn, "SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($res)) {
    $usersList[] = $row;
}

$messageUsers = [];
if ($currentTab === 'messages') {
    // Fetch users who have messages with the CURRENT admin
    $myId = $_SESSION['user_id'];
    $msgQ = "SELECT DISTINCT u.id, u.name, u.email, u.role,
             (SELECT message FROM messages m2 
              WHERE (m2.sender_id = u.id AND m2.receiver_id = $myId) 
                 OR (m2.sender_id = $myId AND m2.receiver_id = u.id) 
              ORDER BY m2.created_at DESC LIMIT 1) as last_msg,
             (SELECT created_at FROM messages m3 
              WHERE (m3.sender_id = u.id AND m3.receiver_id = $myId) 
                 OR (m3.sender_id = $myId AND m3.receiver_id = u.id) 
              ORDER BY m3.created_at DESC LIMIT 1) as last_msg_time
             FROM users u
             JOIN messages m ON (
                (u.id = m.sender_id AND m.receiver_id = $myId) OR 
                (u.id = m.receiver_id AND m.sender_id = $myId)
             )
             WHERE u.id != $myId
             ORDER BY last_msg_time DESC";
    $msgRes = mysqli_query($conn, $msgQ);
    if ($msgRes) {
        while ($row = mysqli_fetch_assoc($msgRes)) {
            $messageUsers[] = $row;
        }
    }
}




// ... (User Actions logic remains above, stopping at $usersList calculation)

// Dashboard Stats Calculation
$totalRevenue = 0;
$activeRentals = 0;
$pendingRequests = 0;
$activeUsersCount = 0;
$recentBookings = [];
$revenueGrowth = 0;
$newRentalsCount = 0;
$newUsersCount = 0;

// Recent Bookings

// Recent Bookings
include 'includes/header.php';
?>
<div class="d-flex" id="wrapper">
    <?php include 'includes/admin_sidebar.php'; ?>

    <!-- Page Content -->
    <main id="page-content-wrapper" class="bg-light w-100">
        <div class="container-fluid px-4 py-5">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show"
                    role="alert">
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($currentTab === 'overview'): ?>
                <?php
                // --- CALCULATION LOGIC MOVED HERE ---
                // Total Revenue
                $revRes = mysqli_query($conn, "SELECT SUM(total_cost) as total FROM rentals WHERE status = 'approved'");
                $revRow = ($revRes) ? mysqli_fetch_assoc($revRes) : null;
                $totalRevenue = $revRow['total'] ?? 0;

                // Revenue Growth
                $currentMonthRevSql = "SELECT SUM(total_cost) as total FROM rentals WHERE status = 'approved' AND MONTH(start_date) = MONTH(CURRENT_DATE()) AND YEAR(start_date) = YEAR(CURRENT_DATE())";
                $lastMonthRevSql = "SELECT SUM(total_cost) as total FROM rentals WHERE status = 'approved' AND MONTH(start_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(start_date) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)";

                $curRevRes = mysqli_query($conn, $currentMonthRevSql);
                $curRevRow = ($curRevRes) ? mysqli_fetch_assoc($curRevRes) : null;
                $curRev = $curRevRow['total'] ?? 0;

                $lastRevRes = mysqli_query($conn, $lastMonthRevSql);
                $lastRevRow = ($lastRevRes) ? mysqli_fetch_assoc($lastRevRes) : null;
                $lastRev = $lastRevRow['total'] ?? 0;

                $revenueGrowth = 0;
                if ($lastRev > 0) {
                    $revenueGrowth = (($curRev - $lastRev) / $lastRev) * 100;
                } elseif ($curRev > 0) {
                    $revenueGrowth = 100;
                }

                // Active Rentals
                $activeRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM properties WHERE status = 'rented'");
                $activeRow = ($activeRes) ? mysqli_fetch_assoc($activeRes) : null;
                $activeRentals = $activeRow['total'] ?? 0;

                // New Rentals
                $newRentalsRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM rentals WHERE status = 'approved' AND start_date >= DATE(NOW() - INTERVAL 7 DAY)");
                $newRentalsRow = ($newRentalsRes) ? mysqli_fetch_assoc($newRentalsRes) : null;
                $newRentalsCount = $newRentalsRow['total'] ?? 0;

                // Active Users
                $userRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'user'");
                $userRow = ($userRes) ? mysqli_fetch_assoc($userRes) : null;
                $activeUsersCount = $userRow['total'] ?? 0;

                // New Users
                $newUsersRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE status = 'active' AND created_at >= DATE(NOW() - INTERVAL 30 DAY)");
                $newUsersRow = ($newUsersRes) ? mysqli_fetch_assoc($newUsersRes) : null;
                $newUsersCount = $newUsersRow['total'] ?? 0;

                $pendingRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM rentals WHERE status = 'pending'");
                $pendingRow = ($pendingRes) ? mysqli_fetch_assoc($pendingRes) : null;
                $pendingRequests = $pendingRow['total'] ?? 0;

                // Fetch Pending List Details
                $pendingList = [];
                if ($pendingRequests > 0) {
                    $pListQ = "SELECT r.*, p.name as property_name, u.name as user_name 
                               FROM rentals r 
                               JOIN properties p ON r.property_id = p.id 
                               JOIN users u ON r.user_id = u.id 
                               WHERE r.status = 'pending' 
                               ORDER BY r.start_date ASC";
                    $pListRes = mysqli_query($conn, $pListQ);
                    while ($row = mysqli_fetch_assoc($pListRes)) {
                        $pendingList[] = $row;
                    }
                }
                ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Overview</h2>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="label">Total Revenue</div>
                                    <div class="value">$<?php echo number_format($totalRevenue, 2, ',', '.'); ?></div>
                                    <div class="change <?php echo $revenueGrowth >= 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo ($revenueGrowth >= 0 ? '+' : '') . number_format($revenueGrowth, 1, ',', '.'); ?>%
                                        from last month
                                    </div>
                                </div>
                                <div class="icon-box">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="label">Active Rentals</div>
                                    <div class="value"><?php echo $activeRentals; ?></div>
                                    <div class="change positive">+<?php echo $newRentalsCount; ?> started this week</div>
                                </div>
                                <div class="icon-box">
                                    <i class="fas fa-home"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="label">Pending Requests</div>
                                    <div class="value"><?php echo $pendingRequests; ?></div>
                                    <div class="change text-muted">Awaiting approval</div>
                                </div>
                                <div class="icon-box">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="label">Active Users</div>
                                    <div class="value"><?php echo $activeUsersCount; ?></div>
                                    <div class="change positive">+<?php echo $newUsersCount; ?> new this month</div>
                                </div>
                                <div class="icon-box">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Requests Section -->
                <?php if (!empty($pendingList)): ?>
                    <div class="mb-4">
                        <h3 class="h5 fw-bold mb-3">Pending Approvals</h3>
                        <div class="bg-white rounded-4 shadow-sm overflow-hidden">
                            <table class="table mb-0 align-middle">
                                <thead class="bg-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="px-4 py-3">Property</th>
                                        <th>User</th>
                                        <th>Dates</th>
                                        <th>Total</th>
                                        <th class="text-end px-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingList as $req): ?>
                                        <tr>
                                            <td class="px-4 fw-bold">
                                                <?php echo htmlspecialchars($req['property_name']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($req['user_name']); ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?php echo date('M j', strtotime($req['start_date'])); ?> -
                                                <?php echo date('M j', strtotime($req['end_date'])); ?>
                                            </td>
                                            <td class="fw-bold">$
                                                <?php echo number_format($req['total_cost'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="text-end px-4">
                                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 me-1"
                                                    data-bs-toggle="modal" data-bs-target="#chatModal"
                                                    data-rental-id="<?php echo $req['id']; ?>" aria-label="Chat with User">
                                                    <i class="fas fa-comment-alt"></i>
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="approve_rental">
                                                    <input type="hidden" name="rental_id" value="<?php echo $req['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3">
                                                        <i class="fas fa-check me-1"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline ms-1">
                                                    <input type="hidden" name="action" value="reject_rental">
                                                    <input type="hidden" name="rental_id" value="<?php echo $req['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                        <i class="fas fa-times me-1"></i> Reject
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>



            <?php elseif ($currentTab === 'properties'): ?>
                <div class="property-header">
                    <div>
                        <h2>Properties</h2>
                        <p>Manage your property listings</p>
                    </div>
                    <a href="#property-form" class="btn-add-property"
                        onclick="if(document.getElementById('property-form').classList.contains('d-none')) { document.getElementById('property-form').classList.remove('d-none'); } else { document.getElementById('property-form').scrollIntoView(); }">
                        <i class="fas fa-plus"></i> Add Property
                    </a>
                </div>

                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="property-search" placeholder="Search properties..." onkeyup="filterProperties()">
                </div>

                <!-- Hidden Form for Creating/Editing (Toggleable) -->
                <div class="form-section p-4 shadow-sm mb-4 bg-white rounded-4 <?php echo ($formMode === 'create' && !isset($_GET['edit']) && empty($errors)) ? 'd-none' : ''; ?>"
                    id="property-form">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h5 fw-bold mb-0"><?php echo $formMode === 'update' ? 'Edit Property' : 'New Property'; ?>
                        </h3>
                        <button type="button" class="btn-close" aria-label="Close"
                            onclick="document.getElementById('property-form').classList.add('d-none');"></button>
                    </div>

                    <form method="post">
                        <input type="hidden" name="action" value="<?php echo $formMode; ?>">
                        <?php if ($formMode === 'update'): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label fw-bold">Name</label>
                                <input type="text" name="name" id="name" class="form-control"
                                    value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="type" class="form-label fw-bold">Type</label>
                                <select name="type" id="type" class="form-select">
                                    <option value="apartment" <?php echo $formData['type'] === 'apartment' ? 'selected' : ''; ?>>Apartment</option>
                                    <option value="villa" <?php echo $formData['type'] === 'villa' ? 'selected' : ''; ?>>Villa
                                    </option>
                                    <option value="studio" <?php echo $formData['type'] === 'studio' ? 'selected' : ''; ?>>
                                        Studio</option>
                                    <option value="room" <?php echo $formData['type'] === 'room' ? 'selected' : ''; ?>>Room
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label fw-bold">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <option value="available" <?php echo $formData['status'] === 'available' ? 'selected' : ''; ?>>Active</option>
                                    <option value="rented" <?php echo $formData['status'] === 'rented' ? 'selected' : ''; ?>>
                                        Rented</option>
                                    <option value="maintenance" <?php echo $formData['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="renovation" <?php echo $formData['status'] === 'renovation' ? 'selected' : ''; ?>>Renovation</option>
                                </select>
                            </div>
                            <div class="col-md-6 position-relative">
                                <label for="location" class="form-label fw-bold">Location</label>
                                <input type="text" name="location" id="location" class="form-control"
                                    value="<?php echo htmlspecialchars($formData['location']); ?>" required
                                    autocomplete="off" placeholder="Type city...">
                                <input type="hidden" name="lat" id="lat"
                                    value="<?php echo htmlspecialchars((string) ($formData['lat'] ?? '')); ?>">
                                <input type="hidden" name="lng" id="lng"
                                    value="<?php echo htmlspecialchars((string) ($formData['lng'] ?? '')); ?>">
                                <div id="location-suggestions" class="list-group position-absolute w-100 shadow-sm"
                                    style="z-index: 1050; display: none;"></div>
                            </div>
                            <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    const locInput = document.getElementById('location');
                                    const latInput = document.getElementById('lat');
                                    const lngInput = document.getElementById('lng');
                                    const suggestions = document.getElementById('location-suggestions');
                                    let debounce;

                                    locInput.addEventListener('input', function () {
                                        clearTimeout(debounce);
                                        const q = this.value;
                                        if (q.length < 3) { suggestions.style.display = 'none'; return; }

                                        debounce = setTimeout(() => {
                                            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&countrycodes=it&limit=5`)
                                                .then(r => r.json())
                                                .then(data => {
                                                    suggestions.innerHTML = '';
                                                    if (data.length) {
                                                        suggestions.style.display = 'block';
                                                        data.forEach(item => {
                                                            const a = document.createElement('a');
                                                            a.className = 'list-group-item list-group-item-action cursor-pointer small';
                                                            a.textContent = item.display_name;
                                                            a.href = '#';
                                                            a.onclick = (e) => {
                                                                e.preventDefault();
                                                                locInput.value = item.display_name;
                                                                latInput.value = item.lat;
                                                                lngInput.value = item.lon;
                                                                suggestions.style.display = 'none';
                                                            };
                                                            suggestions.appendChild(a);
                                                        });
                                                    } else {
                                                        suggestions.style.display = 'none';
                                                    }
                                                });
                                        }, 400);
                                    });
                                    document.addEventListener('click', e => {
                                        if (e.target !== locInput) suggestions.style.display = 'none';
                                    });
                                });
                            </script>
                            <div class="col-md-2">
                                <label for="sqm" class="form-label fw-bold">Size (m²)</label>
                                <input type="number" min="1" name="sqm" id="sqm" class="form-control"
                                    value="<?php echo htmlspecialchars((string) ($formData['sqm'] ?? 50)); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label for="beds" class="form-label fw-bold">Beds</label>
                                <input type="number" min="1" max="20" name="beds" id="beds" class="form-control"
                                    value="<?php echo htmlspecialchars((string) $formData['beds']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label for="baths" class="form-label fw-bold">Baths</label>
                                <input type="number" min="1" max="20" name="baths" id="baths" class="form-control"
                                    value="<?php echo htmlspecialchars((string) $formData['baths']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="monthly_price" class="form-label fw-bold">Price</label>
                                <input type="number" step="0.01" name="monthly_price" id="monthly_price"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars((string) $formData['monthly_price']); ?>" required>
                            </div>
                            <div class="col-md-12">
                                <?php
                                $currentImage = $formData['image_url'] ?? '';
                                $useCustomChecked = $currentImage !== '';
                                ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="use_custom_image"
                                        name="use_custom_image" <?php echo $useCustomChecked ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="use_custom_image">Use custom image URL</label>
                                </div>
                                <div id="image_url_container"
                                    style="<?php echo $useCustomChecked ? '' : 'display: none;'; ?>">
                                    <label for="image_url" class="form-label fw-bold">Image URL</label>
                                    <input type="text" name="image_url" id="image_url" class="form-control"
                                        value="<?php echo htmlspecialchars((string) $formData['image_url']); ?>">
                                </div>
                            </div>
                        </div>

                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                var checkbox = document.getElementById('use_custom_image');
                                var container = document.getElementById('image_url_container');
                                function toggleImageInput() { container.style.display = checkbox.checked ? 'block' : 'none'; }
                                checkbox.addEventListener('change', toggleImageInput);
                            });
                        </script>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit"
                                class="btn btn-primary"><?php echo $formMode === 'update' ? 'Update' : 'Create'; ?></button>
                        </div>
                    </form>
                </div>

                <div class="bg-white shadow-sm rounded-4 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table mb-0 property-table">
                            <thead class="bg-light text-muted small text-uppercase">
                                <tr>
                                    <th class="px-4 py-3">Property</th>
                                    <th class="py-3">Location</th>
                                    <th class="py-3">Price</th>
                                    <th class="py-3">Status</th>
                                    <th class="text-end px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="property-list">
                                <?php if ($properties): ?>
                                    <?php foreach ($properties as $row): ?>
                                        <?php
                                        // Determine image
                                        $imgSrc = $row['image_url'] ?: ($defaultImages[$row['type']] ?? 'images/placeholder.png');
                                        // Map status for pill
                                        $pillClass = 'inactive';
                                        $pillText = 'Inactive';
                                        if ($row['status'] === 'available') {
                                            $pillClass = 'active';
                                            $pillText = 'Active';
                                        } elseif ($row['status'] === 'rented') {
                                            $pillClass = 'inactive';
                                            $pillText = 'Rented';
                                        } else {
                                            $pillClass = 'inactive';
                                            $pillText = ucfirst($row['status']);
                                        }
                                        ?>
                                        <tr class="align-middle border-bottom">
                                            <td class="px-4">
                                                <div class="d-flex align-items-center">
                                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="Thumb"
                                                        class="property-thumbnail shadow-sm">
                                                    <span class="property-name"><?php echo htmlspecialchars($row['name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="text-muted"><?php echo htmlspecialchars($row['location']); ?></td>
                                            <td class="property-price">
                                                $<?php echo htmlspecialchars((string) $row['monthly_price']); ?>/mo</td>
                                            <td>
                                                <span class="status-pill <?php echo $pillClass; ?>"><?php echo $pillText; ?></span>
                                            </td>
                                            <td class="text-end px-4">
                                                <div class="dropdown">
                                                    <button class="btn btn-link action-menu text-decoration-none p-0" type="button"
                                                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><a class="dropdown-item small"
                                                                href="admin.php?tab=properties&edit=<?php echo $row['id']; ?>"><i
                                                                    class="fas fa-edit me-2"></i> Edit</a></li>
                                                        <li><a class="dropdown-item small text-danger"
                                                                href="admin.php?tab=properties&delete=<?php echo $row['id']; ?>"><i
                                                                    class="fas fa-trash me-2"></i> Delete</a></li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">No properties found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    function filterProperties() {
                        var input = document.getElementById("property-search");
                        var filter = input.value.toLowerCase();
                        var tbody = document.getElementById("property-list");
                        var tr = tbody.getElementsByTagName("tr");

                        for (var i = 0; i < tr.length; i++) {
                            var nameCol = tr[i].getElementsByClassName("property-name")[0];
                            if (nameCol) {
                                var txtValue = nameCol.textContent || nameCol.innerText;
                                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                                    tr[i].style.display = "";
                                } else {
                                    tr[i].style.display = "none";
                                }
                            }
                        }
                    }
                </script>

            <?php elseif ($currentTab === 'users'): ?>
                <!-- Users Content (Reused) -->
                <div class="property-header">
                    <div>
                        <h2>Users</h2>
                        <p>Manage user accounts and permissions</p>
                    </div>
                </div>

                <div class="form-section p-4 shadow-sm mb-4 bg-white rounded-4" id="user-form">
                    <h3 class="h5 fw-bold mb-3"><?php echo $editUser ? 'Edit User' : 'Create User'; ?></h3>
                    <form method="post">
                        <input type="hidden" name="action" value="<?php echo $editUser ? 'user_update' : 'user_create'; ?>">
                        <?php if ($editUser): ?>
                            <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                        <?php endif; ?>
                        <!-- User Fields -->
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label fw-bold">Full Name</label>
                                <input type="text" name="name" id="name" class="form-control"
                                    value="<?php echo htmlspecialchars($editUser['name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-12">
                                <label for="email" class="form-label fw-bold">Email</label>
                                <input type="email" name="email" id="email" class="form-control"
                                    value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="role" class="form-label fw-bold">Role</label>
                                <select name="role" id="role" class="form-select">
                                    <option value="user" <?php echo ($editUser['role'] ?? '') === 'user' ? 'selected' : ''; ?>>
                                        User</option>
                                    <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>
                                        Admin</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label fw-bold">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <option value="active" <?php echo ($editUser['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="blocked" <?php echo ($editUser['status'] ?? '') === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="password" class="form-label fw-bold">Password</label>
                                <input type="password" name="password" id="password" class="form-control"
                                    placeholder="<?php echo $editUser ? 'Leave blank to keep' : 'Required'; ?>" <?php echo $editUser ? '' : 'required'; ?>>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit"
                                class="btn btn-primary"><?php echo $editUser ? 'Update User' : 'Create User'; ?></button>
                            <?php if ($editUser): ?>
                                <a href="admin.php?tab=users" class="btn btn-outline-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>


                <div class="bg-white shadow-sm rounded-4 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr class="border-bottom">
                                    <th class="px-4 py-3" colspan="7">
                                    <th class="px-4 py-3" colspan="7">
                                        <h3 class="h6 fw-bold mb-0 text-dark text-capitalize">Registered Users</h3>
                                    </th>
                                    </th>
                                </tr>
                                <tr>
                                    <th class="px-4">ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th class="text-end px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usersList as $u): ?>
                                    <tr class="align-middle">
                                        <td class="px-4">#<?php echo $u['id']; ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($u['name']); ?></td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td>
                                            <?php if ($u['role'] === 'admin'): ?>
                                                <span class="badge bg-primary">Admin</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">User</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['status'] === 'blocked'): ?>
                                                <span class="badge bg-danger">Blocked</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($u['created_at']); ?></td>
                                        <td class="text-end px-4">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill me-1"
                                                data-bs-toggle="modal" data-bs-target="#chatModal"
                                                data-user-id="<?php echo $u['id']; ?>"
                                                data-user-name="<?php echo htmlspecialchars($u['name']); ?>"
                                                aria-label="Chat with <?php echo htmlspecialchars($u['name']); ?>">
                                                <i class="fas fa-comment-dots"></i>
                                            </button>
                                            <a href="admin.php?tab=users&edit_user=<?php echo $u['id']; ?>#user-form"
                                                class="btn btn-sm btn-outline-primary fas fa-edit"
                                                aria-label="Edit <?php echo htmlspecialchars($u['name']); ?>"></a>
                                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                <a href="admin.php?tab=users&delete_user=<?php echo $u['id']; ?>"
                                                    class="btn btn-sm btn-outline-danger fas fa-trash"
                                                    aria-label="Delete <?php echo htmlspecialchars($u['name']); ?>"></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($currentTab === 'messages'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Messages</h2>
                </div>

                <div class="bg-white shadow-sm rounded-4 overflow-hidden">
                    <div class="list-group list-group-flush">
                        <?php if ($messageUsers): ?>
                            <?php foreach ($messageUsers as $mu): ?>
                                <div class="list-group-item p-4 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary fw-bold"
                                            style="width: 50px; height: 50px;">
                                            <?php echo strtoupper(substr($mu['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($mu['name']); ?></h5>
                                            <p class="mb-0 text-muted small text-truncate" style="max-width: 300px;">
                                                <?php echo htmlspecialchars($mu['last_msg'] ?? ''); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted d-block mb-2">
                                            <?php echo $mu['last_msg_time'] ? date('M j, H:i', strtotime($mu['last_msg_time'])) : ''; ?>
                                        </small>
                                        <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal"
                                            data-bs-target="#chatModal" data-user-id="<?php echo $mu['id']; ?>"
                                            data-user-name="<?php echo htmlspecialchars($mu['name']); ?>"
                                            aria-label="Chat with <?php echo htmlspecialchars($mu['name']); ?>">
                                            <i class="fas fa-comment-dots me-2"></i> Chat
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-5 text-center text-muted">No messages found.</div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($currentTab === 'settings'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Settings</h2>
                </div>

                <div class="row g-4">
                    <div class="col-md-8">
                        <form method="post">
                            <input type="hidden" name="action" value="update_settings">

                            <!-- General Settings -->
                            <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                                <h3 class="h5 fw-bold mb-3">General Information</h3>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="site_name" class="form-label fw-bold">Site Name</label>
                                        <input type="text" class="form-control" id="site_name" name="site_name"
                                            value="<?php echo htmlspecialchars($siteSettings['site_name'] ?? 'Housing Rentals'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="admin_email" class="form-label fw-bold">Admin Email</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email"
                                            value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? 'admin@example.com'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label for="site_description" class="form-label fw-bold">Site Description</label>
                                        <textarea class="form-control" id="site_description" name="site_description"
                                            rows="2"><?php echo htmlspecialchars($siteSettings['site_description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Security Settings -->
                            <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                                <h3 class="h5 fw-bold mb-3">Security</h3>
                                <div class="mb-3">
                                    <label for="current_password" class="form-label fw-bold">Current Password</label>
                                    <input type="password" class="form-control" id="current_password"
                                        name="current_password">
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="new_password" class="form-label fw-bold">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="confirm_password" class="form-label fw-bold">Confirm Password</label>
                                        <input type="password" class="form-control" id="confirm_password"
                                            name="confirm_password">
                                    </div>
                                </div>
                            </div>



                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                            </div>
                        </form>
                    </div>

                    <!-- Side Panel Info -->
                    <div class="col-md-4">
                        <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                            <h3 class="h5 fw-bold mb-3">System Info</h3>
                            <ul class="list-unstyled mb-0 text-muted small">
                                <li class="mb-2 d-flex justify-content-between">
                                    <span>PHP Version:</span>
                                    <span class="fw-bold"><?php echo phpversion(); ?></span>
                                </li>
                                <li class="mb-2 d-flex justify-content-between">
                                    <span>Server:</span>
                                    <span class="fw-bold"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Apache'; ?></span>
                                </li>
                                <li class="mb-2 d-flex justify-content-between">
                                    <span>Database:</span>
                                    <span class="fw-bold">MySQL</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Last Backup:</span>
                                    <span class="fw-bold">Never</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
        <?php include 'includes/footer_content.php'; ?>
    </main>
    <!-- /#page-content-wrapper -->
</div>
<script>
    var el = document.getElementById("wrapper");
    var toggleButton = document.getElementById("menu-toggle");

    if (toggleButton) {
        toggleButton.onclick = function () {
            el.classList.toggle("toggled");
        };
    }
</script>
<?php include 'includes/scripts.php'; ?>

<!-- Chat Modal -->
<div class="modal fade" id="chatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-sm border-0">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">Chat with User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="chat-messages" class="p-3 bg-light" style="height: 300px; overflow-y: auto;">
                    <div class="text-center text-muted mt-5">Loading messages...</div>
                </div>
                <form id="chat-form" class="p-3 border-top">
                    <input type="hidden" name="rental_id" id="chat-rental-id">
                    <input type="hidden" name="receiver_id" id="chat-receiver-id">
                    <div class="input-group">
                        <input type="text" name="message" class="form-control border-0 bg-light rounded-start-pill ps-3"
                            placeholder="Type a message..." required>
                        <button class="btn btn-primary rounded-end-pill px-4" type="submit" aria-label="Send message">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Chat Script -->
<script>
    const chatModal = document.getElementById('chatModal');
    if (chatModal) {
        chatModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const rentalId = button.getAttribute('data-rental-id');
            const userId = button.getAttribute('data-user-id');
            const userName = button.getAttribute('data-user-name');

            const title = chatModal.querySelector('.modal-title');
            const hiddenRental = document.getElementById('chat-rental-id');
            const hiddenReceiver = document.getElementById('chat-receiver-id'); // Need to add this input

            // Reset inputs
            hiddenRental.value = '';
            if (hiddenReceiver) hiddenReceiver.value = '';

            if (userId) {
                // User Chat
                hiddenReceiver.value = userId;
                title.textContent = 'Chat with ' + userName;
                loadMessages(null, userId);
            } else if (rentalId) {
                // Rental Chat
                hiddenRental.value = rentalId;
                title.textContent = 'Rental Chat'; // Or specific
                loadMessages(rentalId, null);
            }
        });

        document.getElementById('chat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            const rentalId = document.getElementById('chat-rental-id').value;
            const receiverId = document.getElementById('chat-receiver-id').value;
            const msg = this.message.value;

            let body = 'message=' + encodeURIComponent(msg);
            if (receiverId) body += '&receiver_id=' + receiverId;
            else if (rentalId) body += '&rental_id=' + rentalId;

            fetch('api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    this.message.value = '';
                    loadMessages(rentalId, receiverId);
                }
            });
        });
    }

    function loadMessages(rentalId, userId) {
        let url = 'api/get_messages.php?';
        if (userId) url += 'user_id=' + userId;
        else if (rentalId) url += 'rental_id=' + rentalId;

        fetch(url)
            .then(res => res.text())
            .then(html => {
                document.getElementById('chat-messages').innerHTML = html;
                const container = document.getElementById('chat-messages');
                container.scrollTop = container.scrollHeight;
            });
    }
</script>