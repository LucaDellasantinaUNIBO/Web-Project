<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="col-lg-3 mb-4 mb-lg-0">
    <div class="bg-white rounded-4 shadow-sm p-4 h-100">
        <div class="text-center mb-4">
            <div class="position-relative d-inline-block">
                <!-- Placeholder Avatar -->
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3 overflow-hidden"
                    style="width: 100px; height: 100px;">
                    <i class="fas fa-user fa-3x text-secondary"></i>
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
                <i class="far fa-user fa-fw"></i> Overview
            </a>
            <a href="my_rentals.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'my_rentals.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <i class="fas fa-home fa-fw"></i> My Rentals
            </a>
            <a href="wallet.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'wallet.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <i class="far fa-credit-card fa-fw"></i> Wallet
            </a>
            <a href="settings.php"
                class="btn btn-lg text-start <?php echo $currentPage === 'settings.php' ? 'btn-light-success text-success fw-bold' : 'btn-ghost text-muted'; ?> d-flex align-items-center gap-3">
                <i class="fas fa-cog fa-fw"></i> Settings
            </a>

            <hr class="my-2 opacity-10">

            <a href="logout.php" class="btn btn-lg text-start btn-ghost text-danger d-flex align-items-center gap-3">
                <i class="fas fa-sign-out-alt fa-fw"></i> Sign Out
            </a>
        </div>
    </div>
</div>