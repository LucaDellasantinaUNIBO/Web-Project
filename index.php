<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = is_admin();

$listingCount = 0;
$studentCount = 0;
$minPrice = null;

$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c, MIN(price) AS min_price FROM listings WHERE status = 'published' AND id NOT IN (SELECT listing_id FROM rentals WHERE end_date >= CURDATE())"));
if ($row) {
    $listingCount = (int) $row['c'];
    $minPrice = $row['min_price'] !== null ? (float) $row['min_price'] : null;
}
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'user'"));
if ($row) {
    $studentCount = (int) $row['c'];
}

// Load the 3 verified listings closest to the campus for the home page
$featured = [];
$result = mysqli_query($conn, "SELECT * FROM listings WHERE status = 'published' AND verified = 1 AND id NOT IN (SELECT listing_id FROM rentals WHERE end_date >= CURDATE()) ORDER BY distance_km ASC LIMIT 3");
while ($result && $row = mysqli_fetch_assoc($result)) {
    $featured[] = $row;
}

$favIds = ($isLoggedIn && !$isAdmin) ? get_user_favorite_ids($conn, (int) $_SESSION['user_id']) : [];

$pageTitle = 'Trova casa a Cesena';
$activePage = 'home';
include 'includes/header.php';
?>
<main id="main-content">

    <section class="ac-hero py-5">
        <div class="container py-lg-4">
            <div class="row align-items-center g-5">
                <div class="col-lg-9">
                    <p class="label-mono mb-3">SOLO STUDENTI @studio.unibo.it</p>
                    <h1 class="mb-3">Trova casa a Cesena<br>senza stress.</h1>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <a href="search.php" class="btn btn-ac btn-lg px-4">Cerca alloggi</a>
                        <?php if (!$isLoggedIn): ?>
                            <a href="login.php" class="btn btn-outline-ac btn-lg px-4">Accedi con email UniBo</a>
                        <?php endif; ?>
                    </div>
                    <div class="row row-cols-2 g-3" style="max-width: 22rem;">
                        <div class="col">
                            <div class="ac-stat-value text-ac"><?php echo $listingCount; ?></div>
                            <div class="ac-stat-label">annunci verificati</div>
                        </div>
                        <div class="col">
                            <div class="ac-stat-value"><?php echo number_format($studentCount, 0, ',', '.'); ?></div>
                            <div class="ac-stat-label">studenti attivi</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-surface">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold mb-0">In evidenza vicino al campus</h2>
                <a href="search.php" class="fw-semibold text-decoration-none">Vedi tutti &rarr;</a>
            </div>
            <?php if ($featured): ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php foreach ($featured as $listing): ?>
                        <?php render_listing_card($listing, isset($favIds[(int) $listing['id']]), $isLoggedIn, $isAdmin); ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">Nessun annuncio disponibile al momento.</p>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
