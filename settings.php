<?php

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

    // Recupera la password hashata e il salt attuali dal DB
    $stmt = mysqli_prepare($conn, "SELECT password, salt FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$user) {
        $errors[] = "User not found.";
    } else {
        // Verifica la password corrente
        // Step 1: Hash della password in chiaro (come farebbe il client js)
        $currentPassHash = hash('sha512', $currentPass);

        // Step 2: Hash con il salt (come fa il server in login)
        $checkPassword = hash('sha512', $currentPassHash . $user['salt']);

        if ($checkPassword !== $user['password']) {
            $errors[] = "Current password is incorrect.";
        } elseif ($newPass !== $confirmPass) {
            $errors[] = "New passwords do not match.";
        } elseif (strlen($newPass) < 6) {
            $errors[] = "Password must be at least 6 characters.";
        } else {
            // Genera nuovo salt e hash della nuova password
            $newSalt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
            $newPassHashClient = hash('sha512', $newPass);
            $newPassHashServer = hash('sha512', $newPassHashClient . $newSalt);

            $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, salt = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssi", $newPassHashServer, $newSalt, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Password updated successfully.";
            } else {
                $errors[] = "Failed to update password.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5 mx-auto" style="max-width: 1000px;" id="main-content">
        <h1 class="display-6 fw-bold text-dark mb-4">Settings</h1>

        <div class="row">
            <?php render_user_sidebar(); ?>

            <div class="col-lg-9">
                <div class="bg-white rounded-4 shadow-sm p-4 p-lg-5">
                    <h2 class="h5 fw-bold mb-1">Account Settings</h2>
                    <p class="text-muted mb-4">Manage your account security.</p>

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

                    <!-- Password Change -->
                    <div class="py-4">
                        <h3 class="h6 fw-bold mb-3">Change Password</h3>
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