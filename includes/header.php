<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['user_role']) && strtolower((string) $_SESSION['user_role']) === 'admin';
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Housing Rentals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="CSS/theme.css">
    <link rel="stylesheet" href="CSS/style.css?v=<?php echo time(); ?>">
    <style>
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: #2E8B57;
            /* changed to green for housing */
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
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
                <div class="bg-primary text-white rounded-2 d-flex justify-content-center align-items-center me-2"
                    style="width: 32px; height: 32px;">H</div>
                <span style="color: #2D2D2D;">Housing Rentals</span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <?php if ($isLoggedIn): ?>
                    <span class="small text-muted d-none d-md-inline">Hi,
                        <?php echo htmlspecialchars($userName); ?>
                    </span>
                    <?php if (!$isAdmin): ?>
                        <a href="wallet.php" class="btn btn-outline-secondary btn-sm">Wallet</a>
                        <a href="roulette.php" class="btn btn-primary btn-sm fas fa-gamepad me-1">Roulette</a>
                    <?php endif; ?>
                    <a href="profile.php" class="btn btn-light rounded-circle fas fa-user" aria-label="Profile"></a>
                    <?php if ($isAdmin): ?>
                        <a href="admin.php" class="btn btn-primary btn-sm">Admin Panel</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-outline-secondary btn-sm">Login</a>
                    <a href="register.php" class="btn btn-primary btn-sm">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>