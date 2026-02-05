<?php
/** @var mysqli $conn */
include 'db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$shouldRun = isset($_GET['run']) && $_GET['run'] === '1';
$showSchemaUpdates = true;
$alerts = [];
$report = [
    'users_added' => 0,
    'users_skipped' => 0,
    'properties_added' => 0,
    'properties_skipped' => 0,
    'rentals_added' => 0,
    'rentals_skipped' => 0,
    'rentals_missing' => 0
];
$requiredTables = ['users', 'properties', 'rentals', 'transactions', 'issues', 'change_log'];
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

// Move reset logic to top to ensure schema re-load works
if ($shouldRun && isset($_GET['reset']) && $_GET['reset'] === '1') {
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
    foreach ($requiredTables as $t) {
        mysqli_query($conn, "DROP TABLE IF EXISTS $t");
    }
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    $alerts[] = ['type' => 'warning', 'message' => 'Database dropped and reset successfully. Re-creating schema...'];
    // Force missing tables check to confirm they are gone
    $missingTables = $requiredTables;
    $schemaReady = false;
}

if ($shouldRun) {
    // 1. Check/Run Schema
    if (!$schemaReady) {
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
}

if (!$schemaReady) {
    $alerts[] = ['type' => 'warning', 'message' => 'Missing tables: ' . implode(', ', $missingTables) . '.'];
}

// 2.5 Ensure Schema Updates (Add columns if missing)
if ($shouldRun && $schemaReady) {
    function check_and_add_column($conn, $table, $column, $definition)
    {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            return true;
        }
        return false;
    }

    $colsAdded = 0;
    if (check_and_add_column($conn, 'properties', 'lat', 'DECIMAL(10, 8) DEFAULT NULL'))
        $colsAdded++;
    if (check_and_add_column($conn, 'properties', 'lng', 'DECIMAL(11, 8) DEFAULT NULL'))
        $colsAdded++;

    if ($colsAdded > 0) {
        $alerts[] = ['type' => 'success', 'message' => "Updated schema with $colsAdded new columns (lat/lng)."];
    }
}

// 2. Run Seed Logic
if ($shouldRun && $schemaReady) {
    function get_auth_data($password)
    {
        return ['password' => password_hash($password, PASSWORD_DEFAULT)];
    }

    // 2.5 Ensure Schema Updates (Add columns if missing)
    if ($showSchemaUpdates) {
        $colsAdded = 0;
        if (check_and_add_column($conn, 'properties', 'lat', 'DECIMAL(10, 8) DEFAULT NULL'))
            $colsAdded++;
        if (check_and_add_column($conn, 'properties', 'lng', 'DECIMAL(11, 8) DEFAULT NULL'))
            $colsAdded++;
        if (check_and_add_column($conn, 'properties', 'sqm', 'INT DEFAULT NULL'))
            $colsAdded++;
        if (check_and_add_column($conn, 'properties', 'beds', 'TINYINT DEFAULT 1'))
            $colsAdded++;
        if (check_and_add_column($conn, 'properties', 'baths', 'TINYINT DEFAULT 1'))
            $colsAdded++;

        // Mock Card Columns
        if (check_and_add_column($conn, 'users', 'mock_card_number', 'VARCHAR(20) DEFAULT NULL'))
            $colsAdded++;
        if (check_and_add_column($conn, 'users', 'mock_cvc', 'VARCHAR(5) DEFAULT NULL'))
            $colsAdded++;
        if (check_and_add_column($conn, 'users', 'mock_expiry', 'VARCHAR(7) DEFAULT NULL'))
            $colsAdded++;

        if ($colsAdded > 0) {
            $alerts[] = ['type' => 'success', 'message' => "Updated schema with $colsAdded new columns."];
        }
    }

    $adminAuth = get_auth_data('admin123');
    $userAuth = get_auth_data('student123');

    // --- Users Data ---
    $users = [
        [
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => $adminAuth['password'],
            'role' => 'admin',
            'credit' => 0.00,
            'card' => null
        ],
        [
            'first_name' => 'Alex',
            'last_name' => 'Tenant',
            'email' => 'alex@tenant.com',
            'password' => $userAuth['password'],
            'role' => 'user',
            'credit' => 1500.00,
            'card' => ['4111 2222 3333 4444', '123', '12/30']
        ],
        [
            'first_name' => 'Maya',
            'last_name' => 'Lee',
            'email' => 'maya@tenant.com',
            'password' => $userAuth['password'],
            'role' => 'user',
            'status' => 'active',
            'credit' => 500.00,
            'card' => ['5555 6666 7777 8888', '456', '09/28']
        ]
    ];

    // --- Insert Users ---
    $selectUserStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    $insertUserStmt = mysqli_prepare($conn, "INSERT INTO users (first_name, last_name, name, email, password, role, status, credit, mock_card_number, mock_cvc, mock_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($users as $user) {
        mysqli_stmt_bind_param($selectUserStmt, "s", $user['email']);
        mysqli_stmt_execute($selectUserStmt);
        $result = mysqli_stmt_get_result($selectUserStmt);
        $exists = $result ? mysqli_fetch_assoc($result) : null;

        $cardNum = $user['card'][0] ?? null;
        $cardCvc = $user['card'][1] ?? null;
        $cardExp = $user['card'][2] ?? null;
        $fullName = $user['first_name'] . ' ' . $user['last_name'];

        if ($exists) {
            // Update existing user
            $updateUserStmt = mysqli_prepare($conn, "UPDATE users SET first_name=?, last_name=?, name=?, password=?, role=?, status=?, credit=?, mock_card_number=?, mock_cvc=?, mock_expiry=? WHERE email=?");
            $status = $user['status'] ?? 'active';
            mysqli_stmt_bind_param($updateUserStmt, "ssssssdssss", $user['first_name'], $user['last_name'], $fullName, $user['password'], $user['role'], $status, $user['credit'], $cardNum, $cardCvc, $cardExp, $user['email']);
            mysqli_stmt_execute($updateUserStmt);
            $report['users_added']++; // Count as processed
            continue;
        }

        $status = $user['status'] ?? 'active';
        mysqli_stmt_bind_param($insertUserStmt, "sssssssdsss", $user['first_name'], $user['last_name'], $fullName, $user['email'], $user['password'], $user['role'], $status, $user['credit'], $cardNum, $cardCvc, $cardExp);
        if (mysqli_stmt_execute($insertUserStmt)) {
            $report['users_added']++;
        }
    }

    mysqli_stmt_close($selectUserStmt);
    mysqli_stmt_close($insertUserStmt);

    // --- Properties Data ---
    $properties = [
        [
            'name' => 'Trilocale Luminoso Centro',
            'type' => 'Appartamento',
            'status' => 'available',
            'location' => 'Bologna, Centro Storico',
            'rooms' => 3,
            'monthly_price' => 1200.00,
            'image_url' => 'images/trilocale.png',
            'lat' => 44.4949,
            'lng' => 11.3426,
            'sqm' => 85,
            'beds' => 2,
            'baths' => 1
        ],
        [
            'name' => 'Villa Esclusiva con Parco',
            'type' => 'Villa',
            'status' => 'available',
            'location' => 'Cesena, Zona Mare',
            'rooms' => 5,
            'monthly_price' => 3500.00,
            'image_url' => 'images/villa.png',
            'lat' => 44.1333,
            'lng' => 12.2333,
            'sqm' => 220,
            'beds' => 4,
            'baths' => 3
        ],
        [
            'name' => 'Trilocale Città Studi',
            'type' => 'Appartamento',
            'status' => 'available',
            'location' => 'Milano, Città Studi',
            'rooms' => 3,
            'monthly_price' => 1600.00,
            'image_url' => 'images/trilocale.png',
            'lat' => 45.4773,
            'lng' => 9.2276,
            'sqm' => 95,
            'beds' => 2,
            'baths' => 1
        ],
        [
            'name' => 'Monolocale Moderno',
            'type' => 'Monolocale',
            'status' => 'available',
            'location' => 'Roma, Garbatella',
            'rooms' => 1,
            'monthly_price' => 850.00,
            'image_url' => 'images/monolocale.png',
            'lat' => 41.8603,
            'lng' => 12.4800,
            'sqm' => 40,
            'beds' => 1,
            'baths' => 1
        ],
        [
            'name' => 'Antico Casale in Mattoni',
            'type' => 'Casale',
            'status' => 'available',
            'location' => 'Napoli, Vomero',
            'rooms' => 6,
            'monthly_price' => 2500.00,
            'image_url' => 'images/dimorastorica.png',
            'lat' => 40.8446,
            'lng' => 14.2343,
            'sqm' => 180,
            'beds' => 4,
            'baths' => 2
        ],
        [
            'name' => 'Villa Familiare',
            'type' => 'Villa',
            'status' => 'available',
            'location' => 'Firenze, Fiesole',
            'rooms' => 5,
            'monthly_price' => 2800.00,
            'image_url' => 'images/villa.png',
            'lat' => 43.8066,
            'lng' => 11.2917,
            'sqm' => 280,
            'beds' => 5,
            'baths' => 4
        ]
    ];

    // --- Rentals Data ---
    $rentals = [
        [
            'email' => 'alex@tenant.com',
            'property_name' => 'Luxury Penthouse',
            'start' => '2025-01-01',
            'months' => 12
        ]
    ];

    // --- Insert Properties ---
    $selectPropStmt = mysqli_prepare($conn, "SELECT id FROM properties WHERE name = ?");
    $insertPropStmt = mysqli_prepare($conn, "INSERT INTO properties (name, type, status, location, rooms, monthly_price, image_url, lat, lng, sqm, beds, baths) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($properties as $p) {
        mysqli_stmt_bind_param($selectPropStmt, "s", $p['name']);
        mysqli_stmt_execute($selectPropStmt);
        $result = mysqli_stmt_get_result($selectPropStmt);
        $exists = $result ? mysqli_fetch_assoc($result) : null;

        if ($exists) {
            // Update existing property
            $updatePropStmt = mysqli_prepare($conn, "UPDATE properties SET type=?, status=?, location=?, rooms=?, monthly_price=?, image_url=?, lat=?, lng=?, sqm=?, beds=?, baths=? WHERE name=?");
            mysqli_stmt_bind_param($updatePropStmt, "sssidsddiiis", $p['type'], $p['status'], $p['location'], $p['rooms'], $p['monthly_price'], $p['image_url'], $p['lat'], $p['lng'], $p['sqm'], $p['beds'], $p['baths'], $p['name']);
            mysqli_stmt_execute($updatePropStmt);
            $report['properties_added']++; // Count as processed
            continue;
        }

        $beds = $p['beds'] ?? 1;
        $baths = $p['baths'] ?? 1;

        mysqli_stmt_bind_param(
            $insertPropStmt,
            "ssssidsddiii",
            $p['name'],
            $p['type'],
            $p['status'],
            $p['location'],
            $p['rooms'],
            $p['monthly_price'],
            $p['image_url'],
            $p['lat'],
            $p['lng'],
            $p['sqm'],
            $beds,
            $baths
        );
        if (mysqli_stmt_execute($insertPropStmt)) {
            $report['properties_added']++;
        }
    }
    mysqli_stmt_close($selectPropStmt);
    mysqli_stmt_close($insertPropStmt);


    // --- Rentals Data ---
    $rentals = [
        [
            'email' => 'alex@tenant.com',
            'property_name' => 'Luxury Penthouse',
            'start' => '2025-01-01',
            'months' => 12
        ]
    ];

    // --- Insert Rentals ---
    $lookupRentalStmt = mysqli_prepare($conn, "SELECT u.id AS user_id, p.id AS property_id, p.monthly_price FROM users u CROSS JOIN properties p WHERE u.email = ? AND p.name = ? LIMIT 1");
    $checkRentalStmt = mysqli_prepare($conn, "SELECT id FROM rentals WHERE user_id = ? AND property_id = ? AND start_date = ? LIMIT 1");
    $insertRentalStmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, property_id, start_date, end_date, months, total_cost) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($rentals as $rental) {
        mysqli_stmt_bind_param($lookupRentalStmt, "ss", $rental['email'], $rental['property_name']);
        mysqli_stmt_execute($lookupRentalStmt);
        $result = mysqli_stmt_get_result($lookupRentalStmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        if (!$row) {
            $report['rentals_missing']++;
            continue;
        }

        $userId = (int) $row['user_id'];
        $propertyId = (int) $row['property_id'];
        $start = $rental['start'];
        $months = (int) $rental['months'];

        mysqli_stmt_bind_param($checkRentalStmt, "iis", $userId, $propertyId, $start);
        mysqli_stmt_execute($checkRentalStmt);
        $exists = mysqli_stmt_get_result($checkRentalStmt);

        if ($exists && mysqli_fetch_assoc($exists)) {
            $report['rentals_skipped']++;
            continue;
        }

        // Calculate end date
        $startDt = new DateTime($start);
        $endDt = clone $startDt;
        $endDt->modify("+$months months");
        $end = $endDt->format('Y-m-d');

        $cost = (float) $row['monthly_price'] * $months;

        mysqli_stmt_bind_param($insertRentalStmt, "iissid", $userId, $propertyId, $start, $end, $months, $cost);
        if (mysqli_stmt_execute($insertRentalStmt)) {
            $report['rentals_added']++;
        }
    }

    mysqli_stmt_close($lookupRentalStmt);
    mysqli_stmt_close($checkRentalStmt);
    mysqli_stmt_close($insertRentalStmt);

    $alerts[] = ['type' => 'success', 'message' => 'Seed completed.'];
} elseif ($shouldRun && !$schemaReady) {
    $alerts[] = ['type' => 'danger', 'message' => 'Seed cannot run without tables. Import db/schema.sql or check database connection.'];
}

include '../includes/header.php';
?>
<main>
    <div class="container py-5" style="max-width: 720px;">
        <h1 class="fw-bold mb-3">Housing Data Seed</h1>
        <p class="text-muted">Script to insert sample data into the Housing Rental database.</p>

        <?php if ($alerts): ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
                    <?php echo htmlspecialchars($alert['message']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$shouldRun): ?>
            <div class="form-section p-4 shadow-sm">
                <h2 class="fw-bold mb-3">Demo credentials</h2>
                <ul class="mb-4">
                    <li>Admin: admin@housing.com / admin123</li>
                    <li>Tenant: alex@tenant.com / student123</li>
                    <li>Tenant: maya@tenant.com / student123</li>
                </ul>
                <a class="btn btn-primary me-2" href="seed.php?run=1">Run Seed</a>
                <a class="btn btn-danger" href="seed.php?run=1&reset=1"
                    onclick="return confirm('Are you sure? This will delete all existing data.')">Reset & Run Seed</a>
            </div>
        <?php else: ?>
            <div class="form-section p-4 shadow-sm">
                <h2 class="fw-bold mb-3">Result</h2>
                <ul class="mb-0">
                    <li>Users added: <?php echo (int) $report['users_added']; ?></li>
                    <li>Users already present: <?php echo (int) $report['users_skipped']; ?></li>
                    <li>Properties added: <?php echo (int) $report['properties_added']; ?></li>
                    <li>Properties already present: <?php echo (int) $report['properties_skipped']; ?></li>
                    <li>Rentals added: <?php echo (int) $report['rentals_added']; ?></li>
                    <li>Rentals already present: <?php echo (int) $report['rentals_skipped']; ?></li>
                    <li>Rentals skipped (missing data): <?php echo (int) $report['rentals_missing']; ?></li>
                </ul>
                <div class="mt-3">
                    <a href="../index.php" class="btn btn-outline-primary">Go to Home</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include '../includes/footer.php'; ?>