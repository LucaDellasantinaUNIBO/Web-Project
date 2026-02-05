<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load auth and settings (now consolidated in auth.php)
include_once __DIR__ . '/auth.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
$userName = $isLoggedIn ? (isset($_SESSION['username']) ? $_SESSION['username'] : ($_SESSION['name'] ?? 'User')) : '';

// Clean any accidental output (whitespace, BOM) before sending headers/HTML
if (ob_get_length())
    ob_clean();

// ============================================================================
// COMPONENT RENDER FUNCTIONS
// ============================================================================

function render_user_sidebar() {
    $currentPage = basename($_SERVER['PHP_SELF']);
    ?>
<div class="col-lg-3 mb-4 mb-lg-0">
    <div class="bg-white rounded-4 shadow-sm p-4 h-100">
        <div class="text-center mb-4">
            <div class="position-relative d-inline-block">
                <!-- Placeholder Avatar -->
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3 overflow-hidden"
                    style="width: 100px; height: 100px;">
                    <span class="fas fa-user fa-3x text-secondary"></span>
                </div>
            </div>
            <h2 class="h5 fw-bold mb-1">
                <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
            </h2>
            <p class="text-muted small mb-0">Member since
                <?php echo date('F Y'); ?>
            </p>
        </div>

        <div class="d-grid gap-2">
            <a href="profile.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'profile.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <span class="far fa-user fa-fw"></span> Overview
            </a>
            <a href="my_rentals.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'my_rentals.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <span class="fas fa-home fa-fw"></span> My Rentals
            </a>
            <a href="wallet.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'wallet.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <span class="far fa-credit-card fa-fw"></span> Wallet
            </a>
            <a href="settings.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'settings.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <span class="fas fa-cog fa-fw"></span> Settings
            </a>

            <hr class="my-2 opacity-10">

            <a href="logout.php" class="btn btn-lg text-start btn-ghost text-danger d-flex align-items-center gap-3">
                <span class="fas fa-sign-out-alt fa-fw"></span> Sign Out
            </a>
        </div>
    </div>
</div>
<?php
}

function render_admin_sidebar() {
    $currentTab = $_GET['tab'] ?? 'overview';
    if (!in_array($currentTab, ['overview', 'properties', 'bookings', 'users', 'settings'])) {
        $currentTab = 'overview';
    }
    ?>
<div class="d-flex" id="wrapper">
    <div class="bg-dark" id="sidebar-wrapper">
        <div class="sidebar-heading">
            <div class="brand-icon d-flex justify-content-center align-items-center shadow-sm"
                style="width: 32px; height: 32px; font-size: 1rem;">
                <span class="fas fa-home text-white" aria-hidden="true"></span>
            </div>
            <span>AdminPanel</span>
        </div>
        <div class="list-group list-group-flush my-3">
            <a href="admin.php?tab=overview"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'overview' ? 'active' : ''; ?>">
                <span class="fas fa-th-large me-2" aria-hidden="true"></span> Overview
            </a>
            <a href="admin.php?tab=properties"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'properties' ? 'active' : ''; ?>">
                <span class="fas fa-home me-2" aria-hidden="true"></span> Properties
            </a>

            <a href="admin.php?tab=users"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'users' ? 'active' : ''; ?>">
                <span class="fas fa-users me-2" aria-hidden="true"></span> Users
            </a>
            <a href="admin.php?tab=settings"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'settings' ? 'active' : ''; ?>">
                <span class="fas fa-cog me-2" aria-hidden="true"></span> Settings
            </a>
        </div>

        <div class="sidebar-footer">
            <img src="https://ui-avatars.com/api/?name=Admin+User&background=random" alt="Admin">
            <div class="sidebar-user-info">
                <h6>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin User'); ?>
                </h6>
                <small>Super Admin</small>
            </div>
        </div>

    </div>
<?php
}

function render_rental_card($rental) {
?>
<div class="bg-white rounded-4 shadow-sm p-0 overflow-hidden">
    <div class="row g-0">
        <div class="col-md-4">
            <div style="height: 100%; min-height: 200px; background-color: 
                <?php if ($rental['image_url']): ?>
                    <img src="<?php echo htmlspecialchars($rental['image_url']); ?>"
                        alt="<?php echo htmlspecialchars($rental['property_name']); ?>"
                        class="w-100 h-100 object-fit-cover">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                        <span class="fas fa-image fa-2x"></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-8">
            <div class="p-4 d-flex flex-column h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h3 class="h5 fw-bold mb-1">
                            <?php echo htmlspecialchars($rental['property_name']); ?>
                        </h3>
                        <p class="text-muted small mb-0"><span class="fas fa-map-marker-alt me-1"></span>
                            <?php echo htmlspecialchars($rental['location']); ?>
                        </p>
                    </div>
                    <span class="badge <?php echo $rental['status_class']; ?> rounded-pill px-3 py-2 fw-medium">
                        <?php echo $rental['display_status']; ?>
                    </span>
                </div>

                <div class="row mt-3 g-3">
                    <div class="col-6">
                        <div class="text-muted small">Monthly Rent</div>
                        <div class="fw-bold">
                            €
                            <?php echo number_format($rental['total_cost'] / max($rental['months'], 1), 2, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Dates</div>
                        <div class="fw-bold">
                            <?php echo date('M Y', strtotime($rental['start_date'])); ?> -
                            <?php echo $rental['end_date'] ? date('M Y', strtotime($rental['end_date'])) : 'Indefinite'; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-auto pt-3 d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm px-3" data-bs-toggle="modal"
                        data-bs-target="
                        <span class="fas fa-comment-alt me-1"></span> Chat
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
}

?><!DOCTYPE html>
<html lang="en" xml:lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <title><?php echo !empty($SITE_NAME) ? htmlspecialchars($SITE_NAME) : 'Housing Rentals'; ?> - Find Your Perfect Home</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($SITE_DESC); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="CSS/theme.css?v=<?php echo time(); ?>">
    <?php if (basename($_SERVER['PHP_SELF']) === 'admin.php'): ?>
        <link rel="stylesheet" href="CSS/admin.css?v=<?php echo time(); ?>">

    <?php endif; ?>
    <link rel="stylesheet" href="CSS/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: var(--primary);

            color: white;
            padding: 8px;
            z-index: 10000;
            transition: top 0.2s;
        }

        .skip-link:focus {
            top: 0;
        }
    </style>
</head>

<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <nav class="navbar navbar-expand-lg glass-nav sticky-top py-3">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <div class="brand-icon d-flex justify-content-center align-items-center shadow-sm">
                    <span class="fas fa-home text-white" aria-hidden="true"></span>
                </div>
                <span class="tracking-tight"
                    style="color: var(--foreground);"><?php echo htmlspecialchars($SITE_NAME); ?></span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <?php if ($isLoggedIn): ?>
                    <span class="small text-muted d-none d-md-inline">Hi,
                        <?php echo htmlspecialchars($userName); ?>
                    </span>
                    <?php if (!$isAdmin): ?>
                        <a href="wallet.php" class="btn btn-outline-secondary btn-sm">Wallet</a>
                    <?php endif; ?>
                    <a href="profile.php" class="btn btn-light rounded-circle fas fa-user" aria-label="Profile"><span class="visually-hidden">Profile</span></a>
                    <?php if ($isAdmin): ?>
                        <a href="admin.php" class="btn btn-primary btn-sm">Admin Panel</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-ghost btn-sm fw-medium">Log in</a>
                    <a href="register.php" class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm fw-bold">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>