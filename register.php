<?php
include 'db/db_config.php';
include 'includes/auth.php';

$errors = [];
$nameInput = '';
$emailLocalInput = '';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . (is_admin() ? 'admin.php' : 'index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameInput = trim($_POST['name'] ?? '');
    $emailLocalInput = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $emailLocal = strtolower(preg_replace('/@.*/', '', $emailLocalInput));
    $email = $emailLocal . '@' . CAMPUS_EMAIL_DOMAIN;

    if ($nameInput === '') {
        $errors[] = 'Inserisci nome e cognome.';
    }
    if ($emailLocal === '' || !is_campus_email($email)) {
        $errors[] = 'Inserisci un indirizzo @studio.unibo.it valido.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'La password deve contenere almeno 6 caratteri.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Le password non coincidono.';
    }

    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($exists) {
            $errors[] = 'Questa email è già registrata.';
        } else {
            // Hash the password with bcrypt before saving it
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
            mysqli_stmt_bind_param($stmt, "sss", $nameInput, $email, $passwordHash);
            $success = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($success) {
                $_SESSION['user_id'] = mysqli_insert_id($conn);
                $_SESSION['user_name'] = $nameInput;
                $_SESSION['user_role'] = 'user';
                header('Location: index.php');
                exit;
            }
            $errors[] = 'Registrazione non riuscita. Riprova.';
        }
    }
}

$pageTitle = 'Registrati';
$activePage = 'register';
include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <div class="text-center mb-4">
        <span class="ac-logo mx-auto mb-3" style="width:48px;height:48px;font-size:1.4rem;" aria-hidden="true">A</span>
        <h1 class="fw-bold mb-1">Crea il tuo account</h1>
        <p class="text-muted">Registrati con la tua email istituzionale per accedere agli annunci.</p>
    </div>

    <div class="form-section p-4 shadow-sm mx-auto" style="max-width: 440px;">
        <div class="d-flex bg-surface rounded-3 p-1 mb-4" role="group" aria-label="Accedi o registrati">
            <a href="login.php" class="btn flex-fill fw-semibold text-muted">Accedi</a>
            <a href="register.php" class="btn btn-light flex-fill fw-bold shadow-sm" aria-current="page">Registrati</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="mb-3">
                <label class="form-label fw-bold" for="reg-name">Nome e cognome</label>
                <input type="text" id="reg-name" name="name" class="form-control" value="<?php echo htmlspecialchars($nameInput); ?>" autocomplete="name" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" for="reg-email">Email istituzionale</label>
                <div class="input-group">
                    <input type="text" id="reg-email" name="email" class="form-control" placeholder="nome.cognome" value="<?php echo htmlspecialchars($emailLocalInput); ?>" autocomplete="username" required>
                    <span class="input-group-text ac-mono text-muted">@studio.unibo.it</span>
                </div>
                <div class="form-text">Solo indirizzi <strong>@studio.unibo.it</strong> sono accettati.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" for="reg-password">Password</label>
                <input type="password" id="reg-password" name="password" class="form-control" autocomplete="new-password" required>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold" for="reg-confirm">Conferma password</label>
                <input type="password" id="reg-confirm" name="confirm" class="form-control" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-ac w-100">Crea account</button>
        </form>
    </div>
</div>
</main>
<?php include 'includes/footer.php'; ?>
