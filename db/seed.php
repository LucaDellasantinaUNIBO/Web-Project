<?php
include 'db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$shouldRun = isset($_GET['run']) && $_GET['run'] === '1';
$forceReset = isset($_GET['reset']) && $_GET['reset'] === '1';
$alerts = [];
$report = [
    'users_added' => 0,
    'users_skipped' => 0
];
$requiredTables = ['users', 'listings', 'favorites', 'requests', 'change_log'];
$missingTables = [];

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
    return $result && mysqli_num_rows($result) > 0;
}

foreach ($requiredTables as $table) {
    if (!table_exists($conn, $table)) {
        $missingTables[] = $table;
    }
}

$schemaReady = empty($missingTables);
$schemaLoaded = false;
$schemaErrors = [];
$schemaPath = __DIR__ . '/schema.sql';

if ($shouldRun && (!$schemaReady || $forceReset)) {
    if (!is_readable($schemaPath)) {
        $schemaErrors[] = 'Schema not found in db/schema.sql.';
    } else {
        $schemaSql = file_get_contents($schemaPath);
        if ($schemaSql === false || trim($schemaSql) === '') {
            $schemaErrors[] = 'Schema is empty or not readable.';
        } elseif (!mysqli_multi_query($conn, $schemaSql)) {
            $schemaErrors[] = 'Table creation error: ' . mysqli_error($conn);
        } else {
            do {
                $result = mysqli_store_result($conn);
                if ($result) {
                    mysqli_free_result($result);
                }
            } while (mysqli_more_results($conn) && mysqli_next_result($conn));

            if (mysqli_errno($conn)) {
                $schemaErrors[] = 'Table creation error: ' . mysqli_error($conn);
            } else {
                $schemaLoaded = true;
            }
        }
    }

    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!table_exists($conn, $table)) {
            $missingTables[] = $table;
        }
    }
    $schemaReady = empty($missingTables);
}

if ($schemaLoaded) {
    $alerts[] = ['type' => 'success', 'message' => 'Schema created successfully.'];
}
foreach ($schemaErrors as $error) {
    $alerts[] = ['type' => 'danger', 'message' => $error];
}
if (!$schemaReady) {
    $alerts[] = ['type' => 'warning', 'message' => 'Missing tables: ' . implode(', ', $missingTables) . '.'];
}

if ($shouldRun && $schemaReady) {
    // Create only the administrator account; everything else is added from the app
    $adminName = 'Admin AlmaCasa';
    $adminEmail = 'admin@almacasa.it';
    $adminPhone = '0547 000000';
    $adminHash = password_hash('admin123', PASSWORD_DEFAULT);

    $selectUserStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($selectUserStmt, "s", $adminEmail);
    mysqli_stmt_execute($selectUserStmt);
    $result = mysqli_stmt_get_result($selectUserStmt);
    if ($result && mysqli_fetch_assoc($result)) {
        $report['users_skipped']++;
    } else {
        $insertUserStmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'admin', ?)");
        mysqli_stmt_bind_param($insertUserStmt, "ssss", $adminName, $adminEmail, $adminHash, $adminPhone);
        if (mysqli_stmt_execute($insertUserStmt)) {
            $report['users_added']++;
        }
        mysqli_stmt_close($insertUserStmt);
    }
    mysqli_stmt_close($selectUserStmt);

    $alerts[] = ['type' => 'success', 'message' => 'Seed completato.'];
}

include '../includes/header.php';
?>
<main>
<div class="container py-5" style="max-width: 720px;" id="main-content">
    <h1 class="fw-bold mb-3">Dati dimostrativi</h1>
    <p class="text-muted">Script per creare le tabelle e l'account amministratore nel database locale.</p>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
            <?php echo htmlspecialchars($alert['message']); ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$shouldRun): ?>
        <div class="form-section p-4 shadow-sm">
            <h2 class="h5 fw-bold mb-3">Credenziali demo</h2>
            <ul class="mb-4">
                <li>Admin: <code>admin@almacasa.it</code> / <code>admin123</code></li>
                <li>Studente: <code>marco.conti@studio.unibo.it</code> / <code>student123</code></li>
                <li>Studente: <code>giulia.rossi@studio.unibo.it</code> / <code>student123</code></li>
            </ul>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-ac" href="seed.php?run=1">Esegui seed</a>
                <a class="btn btn-outline-danger" href="seed.php?run=1&amp;reset=1"
                   data-confirm="Ricreare tutte le tabelle da zero? Tutti i dati attuali verranno eliminati." data-confirm-danger>Ricrea tutto da zero</a>
            </div>
            <p class="text-muted small mt-3 mb-0">Usa <strong>Ricrea tutto da zero</strong> dopo una modifica allo schema del database (elimina e ricrea tutte le tabelle, poi ricrea l'account amministratore).</p>
        </div>
    <?php else: ?>
        <div class="form-section p-4 shadow-sm">
            <h2 class="h5 fw-bold mb-3">Risultato</h2>
            <ul class="mb-3">
                <li>Amministratore creato: <?php echo (int) $report['users_added']; ?> (già presente: <?php echo (int) $report['users_skipped']; ?>)</li>
            </ul>
            <a class="btn btn-ac" href="../index.php">Vai al sito</a>
        </div>
    <?php endif; ?>
</div>
</main>
<?php include '../includes/footer.php'; ?>
