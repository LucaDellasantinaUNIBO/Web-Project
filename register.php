<?php
/** @var mysqli $conn */
include 'db/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Enter your first and last name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($exists) {
            $errors[] = 'Email already registered.';
        } else {
            $fullName = $firstName . ' ' . $lastName;
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, 'user')");
            mysqli_stmt_bind_param($stmt, "sssss", $fullName, $firstName, $lastName, $email, $passwordHash);
            $success = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($success) {
                $_SESSION['user_id'] = mysqli_insert_id($conn);
                $_SESSION['user_name'] = $fullName; // Keep session using full name for now or update it? Let's keep full name for simplicity in display
                $_SESSION['user_role'] = 'user';
                header('Location: profile.php');
                exit;
            }
            $errors[] = 'Registration failed.';
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" style="max-width: 520px;" id="main-content">
        <h1 class="fw-bold mb-4">Sign up</h1>
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
                <div class="row mb-3">
                    <div class="col">
                        <label class="form-label fw-bold" for="reg-first">First Name</label>
                        <input type="text" id="reg-first" name="first_name" class="form-control"
                            autocomplete="given-name" required>
                    </div>
                    <div class="col">
                        <label class="form-label fw-bold" for="reg-last">Last Name</label>
                        <input type="text" id="reg-last" name="last_name" class="form-control"
                            autocomplete="family-name" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" for="reg-email">Email</label>
                    <input type="email" id="reg-email" name="email" class="form-control" autocomplete="username"
                        required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" for="reg-password">Password</label>
                    <input type="password" id="reg-password" name="password" class="form-control"
                        autocomplete="new-password" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold" for="reg-confirm">Confirm password</label>
                    <input type="password" id="reg-confirm" name="confirm" class="form-control"
                        autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create account</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>