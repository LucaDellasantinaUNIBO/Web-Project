<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';

require_login();

$isAdmin = is_admin();
$pageTitle = 'Salvati';
$activePage = 'favorites';

if ($isAdmin) {
    include 'includes/header.php';
    ?>
    <main>
    <div class="container py-5" id="main-content">
        <div class="alert alert-warning">Gli account amministratore non hanno una lista di annunci salvati.</div>
        <a class="btn btn-outline-ac" href="admin.php">Vai al pannello admin</a>
    </div>
    </main>
    <?php
    include 'includes/footer.php';
    exit;
}

$userId = (int) $_SESSION['user_id'];
$listings = [];
$stmt = mysqli_prepare($conn, "SELECT l.* FROM favorites f JOIN listings l ON f.listing_id = l.id WHERE f.user_id = ? ORDER BY f.created_at DESC");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($result && $row = mysqli_fetch_assoc($result)) {
    $listings[] = $row;
}
mysqli_stmt_close($stmt);

include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <h1 class="fw-bold mb-1">Annunci salvati</h1>
    <p class="text-muted mb-4"><strong><?php echo count($listings); ?></strong> allogg<?php echo count($listings) === 1 ? 'io salvato' : 'i salvati'; ?></p>

    <?php if ($listings): ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4">
            <?php foreach ($listings as $listing): ?>
                <?php render_listing_card($listing, true, true, false); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="form-section p-5 text-center shadow-sm">
            <span class="far fa-heart text-muted mb-3" style="font-size: 2rem;" aria-hidden="true"></span>
            <p class="h5 fw-bold mb-2">Non hai ancora salvato alcun alloggio</p>
            <p class="text-muted mb-3">Tocca il cuore su un annuncio per ritrovarlo qui.</p>
            <a href="search.php" class="btn btn-ac">Cerca alloggi</a>
        </div>
    <?php endif; ?>
</div>
</main>
<?php include 'includes/footer.php'; ?>
