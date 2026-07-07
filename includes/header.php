<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/wallet.php';
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['user_role']) && strtolower((string) $_SESSION['user_role']) === 'admin';
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
$activePage = $activePage ?? '';
$pageTitle = $pageTitle ?? 'AlmaCasa';
// Show the wallet balance in the navbar only for logged-in students
$walletBalance = ($isLoggedIn && !$isAdmin && isset($conn)) ? get_wallet_balance($conn, (int) $_SESSION['user_id']) : null;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> · AlmaCasa</title>
    <meta name="description" content="AlmaCasa: alloggi verificati per gli studenti del Campus di Cesena (Scienze Informatiche e Architettura). Distanze reali dal campus e strumenti per dividere l'affitto.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="CSS/theme.css">
    <link rel="stylesheet" href="CSS/style.css?v=<?php echo @filemtime(__DIR__ . '/../CSS/style.css'); ?>">
</head>
<body>
<a href="#main-content" class="skip-link">Salta al contenuto principale</a>
<nav class="navbar navbar-expand-lg ac-navbar sticky-top" aria-label="Navigazione principale">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
            <span class="ac-logo me-2" aria-hidden="true">A</span>
            <span class="ac-brand-text">AlmaCasa</span>
            <?php if ($isAdmin): ?>
                <span class="ac-mono text-muted ms-2 small">admin</span>
            <?php endif; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Apri il menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav mx-lg-auto mb-2 mb-lg-0 gap-lg-1">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activePage === 'search' ? 'active' : ''; ?>" href="search.php"<?php echo $activePage === 'search' ? ' aria-current="page"' : ''; ?>>Cerca alloggi</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activePage === 'favorites' ? 'active' : ''; ?>" href="favorites.php"<?php echo $activePage === 'favorites' ? ' aria-current="page"' : ''; ?>>Salvati</a>
                </li>
                <?php if ($isLoggedIn && !$isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activePage === 'wallet' ? 'active' : ''; ?>" href="wallet.php"<?php echo $activePage === 'wallet' ? ' aria-current="page"' : ''; ?>>Portafoglio</a>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($isLoggedIn): ?>
                    <?php if ($isAdmin): ?>
                        <a href="admin.php" class="btn btn-ac btn-sm">Pannello admin</a>
                    <?php endif; ?>
                    <?php if ($walletBalance !== null): ?>
                        <a href="wallet.php" class="badge badge-soft text-decoration-none py-2 px-3" aria-label="Saldo portafoglio: <?php echo number_format($walletBalance, 2, ',', '.'); ?> euro">&euro;<?php echo number_format($walletBalance, 2, ',', '.'); ?></a>
                    <?php endif; ?>
                    <a href="profile.php" class="ac-avatar text-decoration-none" aria-label="Vai al profilo di <?php echo htmlspecialchars($userName); ?>"><?php echo htmlspecialchars(user_initials($userName)); ?></a>
                    <a href="logout.php" class="btn btn-outline-secondary btn-sm">Esci</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-link ac-link fw-semibold text-decoration-none">Accedi</a>
                    <a href="register.php" class="btn btn-ac btn-sm">Registrati</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
