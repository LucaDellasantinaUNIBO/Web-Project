<?php
include 'includes/auth.php';

sec_session_start();

$errors = [];
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';

if (preg_match('/^https?:/i', $redirect) || strpos($redirect, "\n") !== false || strpos($redirect, "\r") !== false) {
    $redirect = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? 'index.php';
    if (preg_match('/^https?:/i', $redirect) || strpos($redirect, "\n") !== false || strpos($redirect, "\r") !== false) {
        $redirect = 'index.php';
    }

    if (login($email, $password, $mysqli) == true) {



        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            if ($redirect === 'index.php') {
                $redirect = 'admin.php';
            }
        } elseif (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
            if ($redirect === 'admin.php') {
                $redirect = 'index.php';
            }
        }

        header("Location: $redirect");
        exit;
    } else {


        $errors[] = 'Invalid credentials or account blocked.';

    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" style="max-width: 520px;" id="main-content">

        <h1 class="fw-bold mb-4">Login</h1>
        <div class="form-section p-4 shadow-sm">
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" name="login_form">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold" for="login-email">Email</label>
                    <input type="email" id="login-email" name="email" class="form-control" autocomplete="username"
                        required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" for="login-password">Password</label>
                    <input type="password" id="login-password" name="password" class="form-control"
                        autocomplete="current-password" required>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <!-- Note: Auto-fill buttons need to work with plain text before hashing. 
                          The formhash function works on 'password' field. 
                          So filling the fields is fine. -->
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('login-email').value='admin@housing.com';document.getElementById('login-password').value='admin123';">Fill
                        admin</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('login-email').value='alex@tenant.com';document.getElementById('login-password').value='student123';">Fill
                        user</button>
                    <!-- Note: existing users won't work until reset because passwords are not hashed with salt yet. -->
                </div>

                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Don't have an account? <a href="register.php">Sign up</a></p>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>