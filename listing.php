<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';
include 'includes/wallet.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = is_admin();

const CAMPUS_LAT = 44.1477;
const CAMPUS_LNG = 12.2357;

$errors = [];
$flash = $_SESSION['listing_flash'] ?? null;
unset($_SESSION['listing_flash']);

$listingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listingId = (int) ($_POST['listing_id'] ?? 0);
}

$listing = null;
if ($listingId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM listings WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $listingId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $listing = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request' && $listing) {
    if (!$isLoggedIn) {
        header('Location: login.php?redirect=' . urlencode('listing.php?id=' . $listingId));
        exit;
    }
    if ($isAdmin) {
        $errors[] = 'Gli amministratori non possono inviare richieste.';
    } else {
        $requestType = ($_POST['request_type'] ?? 'visit') === 'contact' ? 'contact' : 'visit';
        $message = trim($_POST['message'] ?? '');
        $preferredDate = trim($_POST['preferred_date'] ?? '');

        if (strlen($message) < 10) {
            $errors[] = 'Scrivi un messaggio di almeno 10 caratteri.';
        }
        if ($preferredDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) {
            $errors[] = 'Data preferita non valida.';
        }

        if (!$errors) {
            $dateValue = $preferredDate === '' ? null : $preferredDate;
            $stmt = mysqli_prepare($conn, "INSERT INTO requests (user_id, listing_id, type, message, preferred_date) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iisss", $_SESSION['user_id'], $listingId, $requestType, $message, $dateValue);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $_SESSION['listing_flash'] = [
                    'type' => 'success',
                    'message' => $requestType === 'visit'
                        ? 'Richiesta di visita inviata! Riceverai presto una risposta.'
                        : 'Messaggio inviato al proprietario! Ti risponderà al più presto.'
                ];
                header('Location: listing.php?id=' . $listingId);
                exit;
            }
            $errors[] = 'Invio della richiesta non riuscito. Riprova.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay' && $listing) {
    if (!$isLoggedIn) {
        header('Location: login.php?redirect=' . urlencode('listing.php?id=' . $listingId));
        exit;
    }
    if ($isAdmin) {
        $errors[] = 'Gli amministratori non possono affittare alloggi.';
    } else {
        $uid = (int) $_SESSION['user_id'];
        $months = (int) ($_POST['months'] ?? 0);
        $monthlyCost = (float) $listing['price'];
        $depositCost = (float) $listing['deposit'];
        $total = $depositCost + $monthlyCost * $months;

        if ($months < 6 || $months > 12) {
            $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Scegli una durata tra 6 e 12 mesi.'];
            header('Location: listing.php?id=' . $listingId);
            exit;
        } elseif (get_active_rental($conn, $uid, $listingId)) {
            $errors[] = 'Hai già un affitto attivo per questo alloggio.';
        } elseif (get_wallet_balance($conn, $uid) + 0.001 < $total) {
            $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Saldo insufficiente: ricarica il portafoglio prima di affittare.'];
            header('Location: listing.php?id=' . $listingId);
            exit;
        } else {
            $startDate = date('Y-m-d');
            $endDate = (new DateTime($startDate))->modify("+{$months} month")->format('Y-m-d');
            $description = 'Affitto ' . $listing['title'] . ' · ' . $months . ' mes' . ($months === 1 ? 'e' : 'i') . ' (caparra + canone)';

            // Charge the wallet, save the rental and close open requests as one atomic step
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ? AND wallet_balance >= ?");
                mysqli_stmt_bind_param($stmt, "did", $total, $uid, $total);
                mysqli_stmt_execute($stmt);
                $ok = mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);

                if (!$ok) {
                    throw new RuntimeException('balance');
                }

                $stmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, description, listing_id) VALUES (?, 'payment', ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "idsi", $uid, $total, $description, $listingId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, listing_id, months, monthly_cost, deposit, total_paid, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "iiidddss", $uid, $listingId, $months, $monthlyCost, $depositCost, $total, $startDate, $endDate);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $closeNote = 'Chiusa automaticamente: hai affittato un alloggio.';
                $closeStmt = mysqli_prepare($conn, "UPDATE requests SET status = 'closed', admin_notes = ?, reviewed_at = NOW() WHERE user_id = ? AND status = 'open'");
                mysqli_stmt_bind_param($closeStmt, "si", $closeNote, $uid);
                mysqli_stmt_execute($closeStmt);
                mysqli_stmt_close($closeStmt);

                mysqli_commit($conn);
                $_SESSION['listing_flash'] = ['type' => 'success', 'message' => 'Affitto attivato per ' . $months . ' mes' . ($months === 1 ? 'e' : 'i') . '! Scadenza il ' . date('d/m/Y', strtotime($endDate)) . '.'];
                header('Location: listing.php?id=' . $listingId);
                exit;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Pagamento non riuscito. Riprova.'];
                header('Location: listing.php?id=' . $listingId);
                exit;
            }
        }
    }
}

// Extend an active rental: the student pays the extra months from the wallet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extend' && $listing) {
    if (!$isLoggedIn) {
        header('Location: login.php?redirect=' . urlencode('listing.php?id=' . $listingId));
        exit;
    }
    if (!$isAdmin) {
        $uid = (int) $_SESSION['user_id'];
        $rental = get_active_rental($conn, $uid, $listingId);
        $addMonths = (int) ($_POST['add_months'] ?? 0);
        $extendCost = (float) $listing['price'] * $addMonths;

        if (!$rental) {
            $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Non hai un affitto attivo da prolungare.'];
        } elseif ($addMonths < 1 || $addMonths > 12) {
            $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Scegli una proroga tra 1 e 12 mesi.'];
        } elseif (get_wallet_balance($conn, $uid) + 0.001 < $extendCost) {
            $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Saldo insufficiente: ricarica il portafoglio prima di prolungare.'];
        } else {
            $description = 'Proroga affitto ' . $listing['title'] . ' · +' . $addMonths . ' mes' . ($addMonths === 1 ? 'e' : 'i');
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ? AND wallet_balance >= ?");
                mysqli_stmt_bind_param($stmt, "did", $extendCost, $uid, $extendCost);
                mysqli_stmt_execute($stmt);
                $ok = mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);
                if (!$ok) {
                    throw new RuntimeException('balance');
                }

                $stmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, description, listing_id) VALUES (?, 'payment', ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "idsi", $uid, $extendCost, $description, $listingId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $rentalId = (int) $rental['id'];
                $stmt = mysqli_prepare($conn, "UPDATE rentals SET months = months + ?, total_paid = total_paid + ?, end_date = DATE_ADD(end_date, INTERVAL ? MONTH) WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "idii", $addMonths, $extendCost, $addMonths, $rentalId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $_SESSION['listing_flash'] = ['type' => 'success', 'message' => 'Affitto prolungato di ' . $addMonths . ' mes' . ($addMonths === 1 ? 'e' : 'i') . '!'];
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['listing_flash'] = ['type' => 'danger', 'message' => 'Proroga non riuscita. Riprova.'];
            }
        }
        header('Location: listing.php?id=' . $listingId);
        exit;
    }
}

$isFavorite = false;
$activeRental = null;
$walletBalanceListing = 0.0;
if ($isLoggedIn && !$isAdmin && $listing) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $listingId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $isFavorite = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    $activeRental = get_active_rental($conn, (int) $_SESSION['user_id'], $listingId);
    $walletBalanceListing = get_wallet_balance($conn, (int) $_SESSION['user_id']);
}

$pageTitle = $listing ? $listing['title'] : 'Alloggio';
$activePage = 'search';
include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <a href="search.php" class="text-decoration-none d-inline-block mb-3">&larr; Torna agli alloggi</a>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?><div><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$listing): ?>
        <div class="form-section p-5 text-center shadow-sm">
            <p class="h5 fw-bold mb-2">Annuncio non trovato</p>
            <a href="search.php" class="btn btn-outline-ac mt-2">Torna agli alloggi</a>
        </div>
    <?php else: ?>
        <?php
        $image = listing_image($listing['type']);
        $hasImage = $image !== '';
        $distanceLabel = rtrim(rtrim(number_format((float) $listing['distance_km'], 1, '.', ''), '0'), '.');
        $price = (float) $listing['price'];
        $deposit = (float) $listing['deposit'];
        $hasCoords = $listing['lat'] !== null && $listing['lng'] !== null;
        ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="position-relative mb-3">
                    <?php if ($hasImage): ?>
                        <img src="<?php echo htmlspecialchars($image); ?>" class="w-100 ac-photo-hero" style="object-fit: cover;" alt="">
                    <?php else: ?>
                        <div class="ac-photo ac-photo-hero">[ galleria foto ]</div>
                    <?php endif; ?>
                    <?php if ((int) $listing['verified'] === 1): ?>
                        <span class="badge badge-verified position-absolute top-0 start-0 m-3">&check; Proprietà verificata</span>
                    <?php endif; ?>
                </div>
                <div class="row row-cols-3 g-3 mb-4">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="col">
                            <?php if ($hasImage): ?>
                                <img src="<?php echo htmlspecialchars($image); ?>" class="w-100 ac-photo-thumb" style="object-fit: cover;" alt="">
                            <?php else: ?>
                                <div class="ac-photo ac-photo-thumb"></div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <p class="label-mono mb-1"><?php echo htmlspecialchars(listing_type_label($listing['type'])); ?></p>
                <h1 class="fw-bold mb-1"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <p class="text-muted mb-4"><?php echo htmlspecialchars($listing['zone']); ?>, Cesena · a <?php echo htmlspecialchars($distanceLabel); ?> km dal campus</p>

                <div class="row row-cols-3 g-3 mb-4">
                    <div class="col"><div class="ac-chip"><div class="ac-chip-value"><?php echo (int) $listing['size_sqm']; ?> m&sup2;</div><div class="ac-chip-label">superficie</div></div></div>
                    <div class="col"><div class="ac-chip"><div class="ac-chip-value"><?php echo (int) $listing['beds']; ?></div><div class="ac-chip-label">post<?php echo (int) $listing['beds'] === 1 ? 'o' : 'i'; ?> letto</div></div></div>
                    <div class="col"><div class="ac-chip"><div class="ac-chip-value"><?php echo htmlspecialchars($distanceLabel); ?> km</div><div class="ac-chip-label">dal campus</div></div></div>
                </div>

                <h2 class="h5 fw-bold">Descrizione</h2>
                <p class="text-muted"><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p>

                <?php if ($hasCoords): ?>
                    <h2 class="h5 fw-bold mt-4">Dove si trova</h2>
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
                    <div class="shadow-sm overflow-hidden rounded-3"><div id="listing-map"></div></div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            var listing = [<?php echo (float) $listing['lat']; ?>, <?php echo (float) $listing['lng']; ?>];
                            var campus = [<?php echo CAMPUS_LAT; ?>, <?php echo CAMPUS_LNG; ?>];
                            var map = L.map('listing-map');
                            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 19, attribution: '&copy; OpenStreetMap'
                            }).addTo(map);
                            L.marker(listing).addTo(map).bindPopup('<strong><?php echo htmlspecialchars(addslashes($listing['title'])); ?></strong>').openPopup();
                            L.marker(campus).addTo(map).bindPopup('Campus di Cesena · Scienze Informatiche e Architettura');
                            L.polyline([listing, campus], { color: '#c0592e', dashArray: '6,8' }).addTo(map);
                            map.fitBounds(L.latLngBounds([listing, campus]).pad(0.3));
                        });
                    </script>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="form-section p-4 shadow-sm position-sticky" style="top: 90px;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="h2 fw-bold mb-0">&euro;<?php echo eur0($price); ?></span>
                            <span class="text-muted">/mese</span>
                        </div>
                        <?php if (!$isAdmin): ?>
                            <form method="post" action="api/favorite.php" class="ac-fav-form">
                                <input type="hidden" name="listing_id" value="<?php echo $listingId; ?>">
                                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                <?php if ($isLoggedIn): ?>
                                    <button type="submit" class="ac-heart <?php echo $isFavorite ? 'is-active' : ''; ?>" aria-pressed="<?php echo $isFavorite ? 'true' : 'false'; ?>" aria-label="<?php echo $isFavorite ? 'Rimuovi dai salvati' : 'Salva annuncio'; ?>">
                                        <span class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></span>
                                    </button>
                                <?php else: ?>
                                    <a href="login.php" class="ac-heart" aria-label="Accedi per salvare"><span class="far fa-heart" aria-hidden="true"></span></a>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <?php
                    $beds = max(1, (int) $listing['beds']);
                    $isPerBed = $listing['type'] === 'posto_letto';
                    $canShare = $beds > 1 && !$isPerBed;
                    $total = $price;
                    ?>
                    <h2 class="h6 fw-bold"><span class="fas fa-table-list text-ac me-1" aria-hidden="true"></span> Costo dell'alloggio</h2>
                    <p class="text-muted small">
                        <?php echo $isPerBed
                            ? 'Prezzo per singolo posto letto: paghi solo il tuo.'
                            : ($canShare
                                ? 'Affitti l\'intera unità: il costo pieno è tuo, ma puoi dividerlo con i coinquilini.'
                                : 'Costo mensile dell\'alloggio.'); ?>
                    </p>

                    <div class="ac-split-box p-3 mb-3">
                        <div class="label-mono mb-1">Costo mensile</div>
                        <div class="h2 fw-bold text-ac mb-2">&euro;<?php echo eur0($total); ?></div>
                        <div class="d-flex justify-content-between small pt-2 border-top text-muted">
                            <span>Caparra</span>
                            <span class="fw-bold">&euro;<?php echo eur0($deposit); ?></span>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                        <button class="btn btn-outline-secondary w-100" disabled>Gli admin non inviano richieste</button>
                    <?php elseif (!$isLoggedIn): ?>
                        <a href="login.php?redirect=<?php echo urlencode('listing.php?id=' . $listingId); ?>" class="btn btn-ac w-100 mb-2">Accedi per richiedere una visita</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-ac w-100" data-bs-toggle="modal" data-bs-target="#requestModal" data-request-type="visit">Richiedi una visita</button>
                    <?php endif; ?>
                    <p class="text-center text-muted small mt-3 mb-0">Risposta media in <strong>4 ore</strong></p>

                    <?php if ($isLoggedIn && !$isAdmin): ?>
                        <?php $monthlyCost = $price; ?>
                        <hr>
                        <h2 class="h6 fw-bold"><span class="fas fa-key text-ac me-1" aria-hidden="true"></span> Affitta questo alloggio</h2>
                        <?php if ($activeRental): ?>
                            <div class="alert alert-success">
                                <span class="fas fa-check me-1" aria-hidden="true"></span>
                                Affitto attivo fino al <strong><?php echo date('d/m/Y', strtotime($activeRental['end_date'])); ?></strong>.
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Canone mensile</span>
                                <span class="fw-bold">&euro;<?php echo eur0($price); ?></span>
                            </div>
                            <form method="post" data-confirm="Confermi la proroga? L'importo verrà scalato dal portafoglio.">
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="listing_id" value="<?php echo $listingId; ?>">
                                <label class="form-label fw-bold small" for="extend-months">Prolunga l'affitto di</label>
                                <select id="extend-months" name="add_months" class="form-select mb-3">
                                    <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                                        <option value="<?php echo $mo; ?>"><?php echo $mo; ?> mes<?php echo $mo === 1 ? 'e' : 'i'; ?> &middot; &euro;<?php echo eur0($price * $mo); ?></option>
                                    <?php endfor; ?>
                                </select>
                                <button type="submit" class="btn btn-ac w-100">Proroga e paga</button>
                                <p class="text-center text-muted small mt-2 mb-0">Saldo disponibile: &euro;<?php echo eur2($walletBalanceListing); ?></p>
                            </form>
                        <?php else: ?>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Canone mensile</span>
                                <span class="fw-bold">&euro;<?php echo eur0($monthlyCost); ?></span>
                            </div>
                            <div class="d-flex justify-content-between small mb-3">
                                <span class="text-muted">Caparra</span>
                                <span class="fw-bold">&euro;<?php echo eur0($deposit); ?></span>
                            </div>
                            <form method="post" id="rent-form" data-monthly="<?php echo $monthlyCost; ?>" data-deposit="<?php echo $deposit; ?>" data-balance="<?php echo $walletBalanceListing; ?>"
                                  data-confirm="Confermi l'affitto? L'importo verrà scalato dal portafoglio.">
                                <input type="hidden" name="action" value="pay">
                                <input type="hidden" name="listing_id" value="<?php echo $listingId; ?>">
                                <label class="form-label fw-bold small" for="rent-months">Durata dell'affitto</label>
                                <select id="rent-months" name="months" class="form-select mb-3">
                                    <?php for ($mo = 6; $mo <= 12; $mo++): ?>
                                        <option value="<?php echo $mo; ?>"><?php echo $mo; ?> mesi</option>
                                    <?php endfor; ?>
                                </select>
                                <div class="ac-split-box p-3 mb-3">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>Canone × <span id="rent-months-label">6</span></span>
                                        <span class="fw-bold">&euro;<span id="rent-rent-part"><?php echo eur0($monthlyCost * 6); ?></span></span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-2">
                                        <span>Caparra</span>
                                        <span class="fw-bold">&euro;<?php echo eur0($deposit); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between pt-2 border-top">
                                        <span class="fw-bold">Totale</span>
                                        <span class="fw-bold text-ac">&euro;<span id="rent-total"><?php echo eur0($deposit + $monthlyCost * 6); ?></span></span>
                                    </div>
                                </div>
                                <div id="rent-insufficient" class="<?php echo $walletBalanceListing + 0.001 < $deposit + $monthlyCost * 6 ? '' : 'd-none'; ?>">
                                    <p class="small text-danger mb-2">Saldo insufficiente (hai &euro;<?php echo eur2($walletBalanceListing); ?>).</p>
                                    <a href="wallet.php" class="btn btn-ac w-100">Ricarica il portafoglio</a>
                                </div>
                                <button type="submit" id="rent-submit" class="btn btn-ac w-100 <?php echo $walletBalanceListing + 0.001 < $deposit + $monthlyCost * 6 ? 'd-none' : ''; ?>">Affitta e paga</button>
                                <p class="text-center text-muted small mt-2 mb-0">Saldo disponibile: &euro;<?php echo eur2($walletBalanceListing); ?></p>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isLoggedIn && !$isAdmin): ?>
            <div class="modal fade" id="requestModal" tabindex="-1" aria-labelledby="requestModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post">
                            <input type="hidden" name="action" value="request">
                            <input type="hidden" name="listing_id" value="<?php echo $listingId; ?>">
                            <input type="hidden" name="request_type" id="request-type-field" value="visit">
                            <div class="modal-header">
                                <h2 class="h5 modal-title" id="requestModalLabel">Invia una richiesta</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted small">Annuncio: <strong><?php echo htmlspecialchars($listing['title']); ?></strong></p>
                                <div class="mb-3" id="date-field">
                                    <label class="form-label fw-bold" for="preferred_date">Data preferita per la visita <span class="text-muted fw-normal">(facoltativa)</span></label>
                                    <input type="date" class="form-control" id="preferred_date" name="preferred_date" min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label fw-bold" for="message">Messaggio</label>
                                    <textarea class="form-control" id="message" name="message" rows="4" minlength="10" maxlength="1000" required placeholder="Presentati e indica le tue esigenze..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                                <button type="submit" class="btn btn-ac">Invia richiesta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</main>

<?php if ($listing): ?>
<script>
(function () {
    var modal = document.getElementById('requestModal');
    if (!modal) { return; }
    var typeField = document.getElementById('request-type-field');
    var dateField = document.getElementById('date-field');
    var title = document.getElementById('requestModalLabel');
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var type = trigger ? trigger.getAttribute('data-request-type') : 'visit';
        typeField.value = type;
        if (type === 'contact') {
            dateField.style.display = 'none';
            title.textContent = 'Contatta il proprietario';
        } else {
            dateField.style.display = 'block';
            title.textContent = 'Richiedi una visita';
        }
    });
})();

(function () {
    var form = document.getElementById('rent-form');
    if (!form) { return; }
    var monthly = parseFloat(form.dataset.monthly) || 0;
    var deposit = parseFloat(form.dataset.deposit) || 0;
    var balance = parseFloat(form.dataset.balance) || 0;
    var select = document.getElementById('rent-months');
    var submit = document.getElementById('rent-submit');
    var insufficient = document.getElementById('rent-insufficient');

    function euro(n) { return Math.round(n).toLocaleString('it-IT'); }

    function update() {
        var months = parseInt(select.value, 10) || 1;
        var rentPart = monthly * months;
        var total = deposit + rentPart;
        document.getElementById('rent-months-label').textContent = months;
        document.getElementById('rent-rent-part').textContent = euro(rentPart);
        document.getElementById('rent-total').textContent = euro(total);

        var enough = balance + 0.001 >= total;
        submit.classList.toggle('d-none', !enough);
        insufficient.classList.toggle('d-none', enough);
    }

    select.addEventListener('change', update);
    update();
})();
</script>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
