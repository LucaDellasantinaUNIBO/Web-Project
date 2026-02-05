<?php
/** @var mysqli $conn */
include 'db/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
function sanitize_redirect(string $redirect): string
{
    if (preg_match('/^https?:/i', $redirect)) {
        return 'index.php';
    }
    if (strpos($redirect, '//') === 0) {
        return 'index.php';
    }
    return $redirect === '' ? 'index.php' : $redirect;
}

$redirect = sanitize_redirect(isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = sanitize_redirect(trim($_POST['redirect'] ?? 'index.php'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    }
    if ($password === '') {
        $errors[] = 'Enter your password.';
    }

    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id, name, password, role, status FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($user) {
            if (password_verify($password, $user['password'])) {
                if (($user['status'] ?? 'active') === 'blocked') {
                    $errors[] = 'Your account has been blocked.';
                } else {
                    $role = strtolower(trim((string) $user['role']));
                    if ($role !== 'admin') {
                        $role = 'user';
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $role;
                    if ($redirect === 'index.php' && $role === 'admin') {
                        $redirect = 'admin.php';
                    }
                    if ($redirect === 'admin.php' && $role !== 'admin') {
                        $redirect = 'index.php';
                    }
                    header("Location: {$redirect}");
                    exit;
                }
            } else {
                $errors[] = 'Invalid credentials.';
            }
        } else {
            $errors[] = 'Invalid credentials.';
        }
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

            <form method="post" novalidate>
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
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('login-email').value='admin@housing.com';document.getElementById('login-password').value='admin123';">Fill
                        admin</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('login-email').value='alex@tenant.com';document.getElementById('login-password').value='student123';">Fill
                        user</button>
                </div>

                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Don't have an account? <a href="register.php">Sign up</a></p>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>