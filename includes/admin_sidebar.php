<?php
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