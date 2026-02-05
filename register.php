<?php

include 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    sec_session_start();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['p'] ?? '';







    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Enter your first and last name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    }
    if (strlen($password) != 128) {
        $errors[] = 'Invalid password configuration.';
    }

    if (!$errors) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = 'Email already registered.';
            $stmt->close();
        } else {
            $stmt->close();


            $random_salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));

            $password = hash('sha512', $password . $random_salt);

            $fullName = $firstName . ' ' . $lastName;



            if ($insert_stmt = $conn->prepare("INSERT INTO users (name, first_name, last_name, email, password, salt, role) VALUES (?, ?, ?, ?, ?, ?, 'user')")) {
                $insert_stmt->bind_param('ssssss', $fullName, $firstName, $lastName, $email, $password, $random_salt);

                if (!$insert_stmt->execute()) {
                    $errors[] = 'Registration failed: ' . $insert_stmt->error;
                } else {
                    $_SESSION['user_id'] = $insert_stmt->insert_id;
                    $_SESSION['username'] = $fullName;
                    $_SESSION['name'] = $fullName;
                    $_SESSION['role'] = 'user';









                    $user_browser = $_SERVER['HTTP_USER_AGENT'];
                    $user_id = preg_replace("/[^0-9]+/", "", $_SESSION['user_id']);
                    $_SESSION['login_string'] = hash('sha512', $password . $user_browser);

                    header('Location: profile.php');
                    exit;
                }
            } else {
                $errors[] = 'Database error.';
            }
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" style="max-width: 520px;" id="main-content">
        <script type="text/JavaScript" src="js/sha512.js"></script>
        <script type="text/JavaScript" src="js/forms.js"></script>

        <h1 class="fw-bold mb-4">Sign up</h1>
        <div class="form-section p-4 shadow-sm">
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="js-error" class="alert alert-danger d-none"></div>

            <form method="post" action="<?php echo esc_url($_SERVER['PHP_SELF']); ?>" name="registration_form">
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
                    <div class="form-text">Password must be at least 6 characters.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold" for="reg-confirm">Confirm password</label>
                    <input type="password" id="reg-confirm" name="confirm" class="form-control"
                        autocomplete="new-password" required>
                </div>
                <button type="button" class="btn btn-primary w-100" id="btn-register">
                    Create account
                </button>
            </form>
            <p class="small text-muted mt-3 mb-0">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>
    <script>
        document.getElementById('btn-register').addEventListener('click', function() {
            var form = this.form;
            var pass = form.password.value;
            var conf = form.confirm.value;
            var err = document.getElementById('js-error');
            
            // Reset error
            err.classList.add('d-none');
            err.textContent = '';

            if (pass === '' || conf === '') {
                err.textContent = 'Please enter and confirm your password.';
                err.classList.remove('d-none');
                return;
            }

            if (pass !== conf) {
                err.textContent = 'Passwords do not match.';
                err.classList.remove('d-none');
                return;
            }

            // Se tutto ok, procedi con l'hashing
            formhash(form, form.password);
        });
    </script>
</main>
<?php include 'includes/footer.php'; ?>