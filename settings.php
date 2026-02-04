<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();
$userId = $_SESSION['user_id'];

$success = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_password') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Add real password change logic here, simplified for display
    if ($newPass !== $confirmPass) {
        $errors[] = "New passwords do not match.";
    } elseif (strlen($newPass) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    } else {
        // Mock success
        $success = "Password updated successfully.";
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5 mx-auto" style="max-width: 1000px;" id="main-content">
        <h1 class="display-6 fw-bold text-dark mb-4">Settings</h1>

        <div class="row">
            <?php include 'includes/user_sidebar.php'; ?>

            <div class="col-lg-9">
                <div class="bg-white rounded-4 shadow-sm p-4 p-lg-5">
                    <h2 class="h5 fw-bold mb-1">Account Settings</h2>
                    <p class="text-muted mb-4">Manage your account security and notifications.</p>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $e)
                                echo htmlspecialchars($e) . "<br>"; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Notifications -->
                    <div class="d-flex justify-content-between align-items-center py-4 border-bottom">
                        <div>
                            <h6 class="fw-bold mb-1">Email Notifications</h6>
                            <p class="text-muted small mb-0">Receive updates about your rentals and payments.</p>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="emailNotif" checked>
                        </div>
                    </div>

                    <!-- 2FA -->
                    <div class="d-flex justify-content-between align-items-center py-4 border-bottom">
                        <div>
                            <h6 class="fw-bold mb-1">Two-Factor Authentication</h6>
                            <p class="text-muted small mb-0">Add an extra layer of security to your account.</p>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="2faSwitch">
                        </div>
                    </div>

                    <!-- Password Change -->
                    <div class="py-4">
                        <h6 class="fw-bold mb-3">Change Password</h6>
                        <form method="post">
                            <input type="hidden" name="action" value="update_password">
                            <div class="mb-3">
                                <input type="password" name="current_password"
                                    class="form-control bg-light border-0 py-3" placeholder="Current Password" required>
                            </div>
                            <div class="mb-3">
                                <input type="password" name="new_password" class="form-control bg-light border-0 py-3"
                                    placeholder="New Password" required>
                            </div>
                            <div class="mb-3">
                                <input type="password" name="confirm_password"
                                    class="form-control bg-light border-0 py-3" placeholder="Confirm New Password"
                                    required>
                            </div>
                            <button type="submit" class="btn btn-outline-dark">Update Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>