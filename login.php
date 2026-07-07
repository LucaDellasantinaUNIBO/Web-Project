<?php
include 'db/db_config.php';
include 'includes/auth.php';

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

function normalize_login_email(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strpos($raw, '@') !== false) {
        return $raw;
    }
    return $raw . '@' . CAMPUS_EMAIL_DOMAIN;
}

$redirect = sanitize_redirect($_GET['redirect'] ?? 'index.php');

if (isset($_SESSION['user_id'])) {
    header('Location: ' . (is_admin() ? 'admin.php' : 'index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_login_email($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = sanitize_redirect(trim($_POST['redirect'] ?? 'index.php'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido.';
    }
    if ($password === '') {
        $errors[] = 'Inserisci la password.';
    }

    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id, name, password, role, status FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        // Check the password against the stored bcrypt hash
        if ($user && password_verify($password, $user['password'])) {
            if (($user['status'] ?? 'active') === 'blocked') {
                $errors[] = 'Il tuo account è stato bloccato. Contatta l\'amministrazione.';
            } else {
                $role = strtolower(trim((string) $user['role'])) === 'admin' ? 'admin' : 'user';
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
            $errors[] = 'Credenziali non valide.';
        }
    }
}

$pageTitle = 'Accedi';
$activePage = 'login';
include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <div class="text-center mb-4">
        <span class="ac-logo mx-auto mb-3" style="width:48px;height:48px;font-size:1.4rem;" aria-hidden="true">A</span>
        <h1 class="fw-bold mb-1">Bentornato su AlmaCasa</h1>
        <p class="text-muted">Accesso riservato agli studenti del Campus di Cesena – Università di Bologna.</p>
    </div>

    <div class="form-section p-4 shadow-sm mx-auto" style="max-width: 440px;">
        <div class="d-flex bg-surface rounded-3 p-1 mb-4" role="group" aria-label="Accedi o registrati">
            <a href="login.php" class="btn btn-light flex-fill fw-bold shadow-sm" aria-current="page">Accedi</a>
            <a href="register.php" class="btn flex-fill fw-semibold text-muted">Registrati</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
            <div class="mb-3">
                <label class="form-label fw-bold" for="login-email">Email istituzionale</label>
                <div class="input-group">
                    <input type="text" id="login-email" name="email" class="form-control" placeholder="nome.cognome" autocomplete="username" required>
                    <span class="input-group-text ac-mono text-muted">@studio.unibo.it</span>
                </div>
                <div class="form-text">Solo indirizzi <strong>@studio.unibo.it</strong> sono accettati.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" for="login-password">Password</label>
                <input type="password" id="login-password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('login-email').value='admin@almacasa.it';document.getElementById('login-password').value='admin123';">Demo admin</button>
            </div>
            <button type="submit" class="btn btn-ac w-100">Accedi</button>
        </form>
        <p class="text-center mt-3 mb-0">
            <a href="#" class="small text-muted" onclick="alert('Funzione dimostrativa: contatta l\'amministrazione per reimpostare la password.');return false;">Password dimenticata?</a>
        </p>
    </div>
</div>
</main>
<?php include 'includes/footer.php'; ?>
