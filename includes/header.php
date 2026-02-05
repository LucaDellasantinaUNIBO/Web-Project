<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once __DIR__ . '/settings_loader.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
$userName = $isLoggedIn ? (isset($_SESSION['username']) ? $_SESSION['username'] : ($_SESSION['name'] ?? 'User')) : '';

// Clean any accidental output (whitespace, BOM) before sending headers/HTML
if (ob_get_length())
    ob_clean();
?><!DOCTYPE html>
<html lang="en" xml:lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <title><?php echo !empty($SITE_NAME) ? htmlspecialchars($SITE_NAME) : 'Housing Rentals'; ?> - Find Your Perfect Home

    </title>
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
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <!-- Leaflet JS -->
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