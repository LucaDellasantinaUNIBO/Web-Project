<?php
$currentTab = $_GET['tab'] ?? 'overview';
if (!in_array($currentTab, ['overview', 'properties', 'bookings', 'users', 'settings'])) {
    $currentTab = 'overview';
}
?>
<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div class="bg-dark" id="sidebar-wrapper">
        <div class="sidebar-heading">
            <div class="brand-icon d-flex justify-content-center align-items-center shadow-sm"
                style="width: 32px; height: 32px; font-size: 1rem;">
                <i class="fas fa-home text-white"></i>
            </div>
            <span>AdminPanel</span>
        </div>
        <div class="list-group list-group-flush my-3">
            <a href="admin.php?tab=overview"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-th-large"></i> Overview
            </a>
            <a href="admin.php?tab=properties"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'properties' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Properties
            </a>
            <a href="admin.php?tab=bookings"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'bookings' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Bookings
            </a>
            <a href="admin.php?tab=users"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'users' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="admin.php?tab=settings"
                class="list-group-item list-group-item-action <?php echo $currentTab === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Settings
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
        <a href="index.php" class="exit-btn">
            <i class="fas fa-sign-out-alt"></i> Exit Admin
        </a>
    </div>
    <!-- /#sidebar-wrapper -->