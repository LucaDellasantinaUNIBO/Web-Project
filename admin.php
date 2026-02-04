<?php
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
    $action = $_POST['action'] ?? '';

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
        $rooms = (int) ($_POST['rooms'] ?? 1);
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
        if ($rooms < 1) {
            $errors[] = 'Rooms must be at least 1.';
        }
        if ($monthlyPrice <= 0) {
            $errors[] = 'Invalid price.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = mysqli_prepare($conn, "INSERT INTO properties (name, type, status, location, rooms, monthly_price, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssssids", $name, $type, $status, $location, $rooms, $monthlyPrice, $imageUrl);
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
                $stmt = mysqli_prepare($conn, "UPDATE properties SET name = ?, type = ?, status = ?, location = ?, rooms = ?, monthly_price = ?, image_url = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssssidsi", $name, $type, $status, $location, $rooms, $monthlyPrice, $imageUrl, $id);
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
    'rooms' => $editProperty['rooms'] ?? 1,
    'monthly_price' => $editProperty['monthly_price'] ?? 500.00,
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
    header('Location: admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($action, 'user_') === 0) {
    if ($action === 'user_create') { // Fixed logic: explicitly check action type
        $firstName = $_POST['first_name'] ?? '';
        $lastName = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $fullName = $firstName . ' ' . $lastName;

        if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
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
                $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
                $passwordHash = hash('sha512', $password . $salt);

                $stmt = mysqli_prepare($conn, "INSERT INTO users (first_name, last_name, name, email, password, salt, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssssssss", $firstName, $lastName, $fullName, $email, $passwordHash, $salt, $role, $status);
                if (mysqli_stmt_execute($stmt)) {
                    log_change($conn, $_SESSION['user_id'], 'create', 'user', mysqli_insert_id($conn), "Created user {$fullName}.");
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
        $firstName = $_POST['first_name'] ?? '';
        $lastName = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $fullName = $firstName . ' ' . $lastName;

        if ($editUserId <= 0) {
            $errors[] = 'Invalid user ID.';
        } else {
            if (!empty($password)) {
                $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
                $passwordHash = hash('sha512', $password . $salt);
                $stmt = mysqli_prepare($conn, "UPDATE users SET first_name=?, last_name=?, name=?, email=?, password=?, salt=?, role=?, status=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "ssssssssi", $firstName, $lastName, $fullName, $email, $passwordHash, $salt, $role, $status, $editUserId);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE users SET first_name=?, last_name=?, name=?, email=?, role=?, status=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "ssssssi", $firstName, $lastName, $fullName, $email, $role, $status, $editUserId);
            }

            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $_SESSION['user_id'], 'update', 'user', $editUserId, "Updated user {$fullName}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated successfully!'];
                header('Location: admin.php?tab=users');
                exit;
            } else {
                $errors[] = 'Update failed: ' . mysqli_error($conn);
            }
        }
    }
}

$properties = [];
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
            $uName = ($res && $r = mysqli_fetch_assoc($res)) ? $r['name'] : "#$delId";
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delId);
            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $_SESSION['user_id'], 'delete', 'user', $delId, "Deleted user $uName.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'User deleted successfully.'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed: ' . mysqli_error($conn)];
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: admin.php?tab=users');
        exit;
    }

    if (isset($_GET['edit_user'])) {
        $editUserId = (int) $_GET['edit_user'];
        $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, email, role, status FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $editUserId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $editUser = $res ? mysqli_fetch_assoc($res) : null;
        if ($editUser)
            $editUser['id'] = $editUserId;
    }
    // Fetch users with new columns
    $res = mysqli_query($conn, "SELECT id, first_name, last_name, email, role, status, created_at FROM users ORDER BY id DESC");
    while ($row = mysqli_fetch_assoc($res)) {
        $usersList[] = $row;
    }
}

// ... (User Actions logic remains above, stopping at $usersList calculation)

// Dashboard Stats Calculation
$totalRevenue = 0;
$activeRentals = 0;
$pendingRequests = 0;
$activeUsersCount = 0;
$recentBookings = [];

if ($currentTab === 'overview') {
    // Total Revenue
    $revRes = mysqli_query($conn, "SELECT SUM(total_cost) as total FROM rentals WHERE status = 'approved'");
    $revRow = mysqli_fetch_assoc($revRes);
    $totalRevenue = $revRow['total'] ?? 0;

    // Active Rentals
    $activeRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM rentals WHERE status = 'approved' AND end_date >= CURDATE()");
    $activeRow = mysqli_fetch_assoc($activeRes);
    $activeRentals = $activeRow['total'] ?? 0;

    // Pending Requests
    $pendingRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM rentals WHERE status = 'pending'");
    $pendingRow = mysqli_fetch_assoc($pendingRes);
    $pendingRequests = $pendingRow['total'] ?? 0;

    // Active Users
    $userRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $userRow = mysqli_fetch_assoc($userRes);
    $activeUsersCount = $userRow['total'] ?? 0;

    // Recent Bookings
    $recentBookings = [];
    $recentQuery = "SELECT r.*, p.name as property_name, u.name as user_name 
                    FROM rentals r 
                    JOIN properties p ON r.property_id = p.id 
                    JOIN users u ON r.user_id = u.id 
                    ORDER BY r.id DESC LIMIT 5";
    $recentRes = mysqli_query($conn, $recentQuery);
    while ($row = mysqli_fetch_assoc($recentRes)) {
        $recentBookings[] = $row;
    }
}

include 'includes/header.php';
?>
<div class="d-flex" id="wrapper">
    <?php include 'includes/admin_sidebar.php'; ?>

    <!-- Page Content -->
    <div id="page-content-wrapper" class="bg-light w-100">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Overview</h2>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="label">Total Revenue</div>
                                    <div class="value">$<?php echo number_format($totalRevenue, 2); ?></div>
                                    <div class="change positive">+20.1% from last month</div>
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
                                    <div class="change positive">+2 since last week</div>
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
                                    <div class="change positive">+4 new today</div>
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
                                    <div class="change positive">+201 since last month</div>
                                </div>
                                <div class="icon-box">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-4 shadow-sm p-4">
                    <h3 class="h5 fw-bold mb-3">Recent Bookings</h3>
                    <p class="text-muted mb-4">You have <?php echo $pendingRequests; ?> pending booking requests.</p>

                    <?php if ($recentBookings): ?>
                        <?php foreach ($recentBookings as $booking): ?>
                            <div class="booking-item">
                                <div class="booking-info">
                                    <div class="booking-icon">
                                        <?php
                                        $iconClass = 'fa-home'; // Default
                                        // You could add logic here to choose icon based on property type if available in join
                                        ?>
                                        <i class="fas <?php echo $iconClass; ?> text-muted"></i>
                                    </div>
                                    <div class="booking-details">
                                        <h4><?php echo htmlspecialchars($booking['property_name']); ?></h4>
                                        <p>Requested by <?php echo htmlspecialchars($booking['user_name']); ?> •
                                            <?php echo date('Y-m-d', strtotime($booking['start_date'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-4">
                                    <span class="booking-price">$<?php echo number_format($booking['total_cost'], 0); ?>/mo</span>
                                    <span class="badge-status <?php echo strtolower($booking['status']); ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No recent bookings found.</p>
                    <?php endif; ?>
                </div>

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
                            <div class="col-md-6">
                                <label for="location" class="form-label fw-bold">Location</label>
                                <input type="text" name="location" id="location" class="form-control"
                                    value="<?php echo htmlspecialchars($formData['location']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="rooms" class="form-label fw-bold">Rooms</label>
                                <input type="number" min="1" max="20" name="rooms" id="rooms" class="form-control"
                                    value="<?php echo htmlspecialchars((string) $formData['rooms']); ?>" required>
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
                                                        data-bs-toggle="dropdown" aria-expanded="false">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Users</h2>
                </div>

                <div class="form-section p-4 shadow-sm mb-4 bg-white rounded-4" id="user-form">
                    <h3 class="h5 fw-bold mb-3"><?php echo $editUser ? 'Edit User' : 'Create User'; ?></h3>
                    <form method="post">
                        <input type="hidden" name="action" value="<?php echo $editUser ? 'user_update' : 'user_create'; ?>">
                        <?php if ($editUser): ?>
                            <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                        <?php endif; ?>
                        <!-- User Fields -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label fw-bold">First Name</label>
                                <input type="text" name="first_name" id="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($editUser['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label fw-bold">Last Name</label>
                                <input type="text" name="last_name" id="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($editUser['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-12">
                                <label for="email" class="form-label fw-bold">Email</label>
                                <input type="email" name="email" id="email" class="form-control"
                                    value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="role" class="form-label fw-bold">Role</label>
                                <select name="role" id="role" class="form-select">
                                    <option value="user" <?php echo ($editUser['role'] ?? '') === 'user' ? 'selected' : ''; ?>>User</option>
                                    <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
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
                    <div class="p-3 border-bottom">
                        <h3 class="h6 fw-bold mb-0">Registered Users</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="px-4">ID</th>
                                    <th>First Name</th>
                                    <th>Last Name</th>
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
                                        <td class="fw-bold"><?php echo htmlspecialchars($u['first_name']); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($u['last_name']); ?></td>
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
                                            <a href="admin.php?tab=users&edit_user=<?php echo $u['id']; ?>#user-form"
                                                class="btn btn-sm btn-outline-primary fas fa-edit"></a>
                                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                <a href="admin.php?tab=users&delete_user=<?php echo $u['id']; ?>"
                                                    class="btn btn-sm btn-outline-danger fas fa-trash"></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($currentTab === 'bookings'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Bookings</h2>
                </div>
                <!-- TODO: Full bookings management list -->
                <p>Full bookings management would go here.</p>

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
                                            value="AdminPanel" readonly>
                                        <div class="form-text">Defined in config.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="admin_email" class="form-label fw-bold">Admin Email</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email"
                                            value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? 'admin@example.com'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label for="site_description" class="form-label fw-bold">Site Description</label>
                                        <textarea class="form-control" id="site_description"
                                            rows="2">Admin Panel for Property Management.</textarea>
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

                            <!-- Preferences -->
                            <div class="bg-white rounded-4 shadow-sm p-4 mb-4">
                                <h3 class="h5 fw-bold mb-3">Preferences</h3>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="email_notifications"
                                        name="email_notifications" checked>
                                    <label class="form-check-label" for="email_notifications">Enable Email
                                        Notifications</label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="maintenance_mode"
                                        name="maintenance_mode">
                                    <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="dark_mode" name="dark_mode">
                                    <label class="form-check-label" for="dark_mode">Dark Mode Support (Beta)</label>
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
    </div>
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