<?php
include 'db/db_config.php';
include 'includes/auth.php';
include 'includes/listings.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = is_admin();

$type = $_GET['type'] ?? 'all';
if ($type !== 'all' && !isset(LISTING_TYPES[$type])) {
    $type = 'all';
}

$maxPrice = isset($_GET['max_price']) ? (int) $_GET['max_price'] : 700;
$maxPrice = max(100, min(1500, $maxPrice));

$maxDistance = isset($_GET['max_distance']) ? (float) $_GET['max_distance'] : 3.0;
$maxDistance = max(0.5, min(5.0, $maxDistance));

$sortOptions = [
    'distance'   => 'Distanza dal campus',
    'price_asc'  => 'Prezzo crescente',
    'price_desc' => 'Prezzo decrescente',
    'recent'     => 'Più recenti',
];
$sort = $_GET['sort'] ?? 'distance';
if (!isset($sortOptions[$sort])) {
    $sort = 'distance';
}
$orderBy = [
    'distance'   => 'distance_km ASC, price ASC',
    'price_asc'  => 'price ASC',
    'price_desc' => 'price DESC',
    'recent'     => 'created_at DESC, id DESC',
][$sort];

// Build the search query dynamically from the selected filters
$query = "SELECT * FROM listings WHERE status = 'published' AND id NOT IN (SELECT listing_id FROM rentals WHERE end_date >= CURDATE()) AND price <= ? AND distance_km <= ?";
$params = [$maxPrice, $maxDistance];
$types = 'id';
if ($type !== 'all') {
    $query .= " AND type = ?";
    $params[] = $type;
    $types .= 's';
}
$query .= " ORDER BY {$orderBy}";

$listings = [];
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $listings[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$favIds = ($isLoggedIn && !$isAdmin) ? get_user_favorite_ids($conn, (int) $_SESSION['user_id']) : [];

function search_url(array $overrides, string $type, int $maxPrice, float $maxDistance, string $sort): string
{
    $params = array_merge([
        'type' => $type,
        'max_price' => $maxPrice,
        'max_distance' => $maxDistance,
        'sort' => $sort,
    ], $overrides);
    return 'search.php?' . http_build_query($params);
}

$distanceLabel = rtrim(rtrim(number_format($maxDistance, 1, '.', ''), '0'), '.');

$pageTitle = 'Cerca alloggi';
$activePage = 'search';
include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <h1 class="fw-bold mb-1">Alloggi a Cesena</h1>
    <p class="text-muted mb-4"><strong><?php echo count($listings); ?></strong> allogg<?php echo count($listings) === 1 ? 'io corrisponde' : 'i corrispondono'; ?> ai filtri</p>

    <div class="d-flex flex-wrap gap-2 mb-4" role="group" aria-label="Filtra per tipo di alloggio">
        <a href="<?php echo htmlspecialchars(search_url(['type' => 'all'], $type, $maxPrice, $maxDistance, $sort)); ?>"
           class="ac-pill <?php echo $type === 'all' ? 'active' : ''; ?>">Tutti</a>
        <?php foreach (LISTING_TYPES as $key => $label): ?>
            <a href="<?php echo htmlspecialchars(search_url(['type' => $key], $type, $maxPrice, $maxDistance, $sort)); ?>"
               class="ac-pill <?php echo $type === $key ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="form-section p-4 shadow-sm mb-4" id="filter-form">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
        <div class="row g-4 align-items-end">
            <div class="col-md-4">
                <div class="d-flex justify-content-between">
                    <label class="form-label fw-bold mb-1" for="max_price">Prezzo massimo</label>
                    <span class="fw-bold text-ac">&euro;<span id="price-output"><?php echo $maxPrice; ?></span></span>
                </div>
                <input type="range" class="ac-range" id="max_price" name="max_price" min="100" max="1500" step="20"
                       value="<?php echo $maxPrice; ?>" oninput="document.getElementById('price-output').textContent=this.value">
            </div>
            <div class="col-md-4">
                <div class="d-flex justify-content-between">
                    <label class="form-label fw-bold mb-1" for="max_distance">Distanza dal campus</label>
                    <span class="fw-bold text-ac">entro <span id="distance-output"><?php echo $distanceLabel; ?></span> km</span>
                </div>
                <input type="range" class="ac-range" id="max_distance" name="max_distance" min="0.5" max="5" step="0.5"
                       value="<?php echo $maxDistance; ?>" oninput="document.getElementById('distance-output').textContent=this.value">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold mb-1" for="sort">Ordina per</label>
                <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                    <?php foreach ($sortOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $sort === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <noscript><button type="submit" class="btn btn-ac btn-sm mt-3">Applica filtri</button></noscript>
    </form>

    <h2 class="visually-hidden">Risultati della ricerca</h2>
    <?php if ($listings): ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4">
            <?php foreach ($listings as $listing): ?>
                <?php render_listing_card($listing, isset($favIds[(int) $listing['id']]), $isLoggedIn, $isAdmin); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="form-section p-5 text-center shadow-sm">
            <p class="h5 fw-bold mb-2">Nessun alloggio trovato</p>
            <p class="text-muted mb-3">Prova ad aumentare il prezzo massimo o la distanza dal campus.</p>
            <a href="search.php" class="btn btn-outline-ac">Reimposta filtri</a>
        </div>
    <?php endif; ?>
</div>
</main>
<script>
(function () {
    var form = document.getElementById('filter-form');
    if (!form) { return; }
    ['max_price', 'max_distance'].forEach(function (id) {
        var input = document.getElementById(id);
        if (input) {
            input.addEventListener('change', function () { form.submit(); });
        }
    });
})();
</script>
<?php include 'includes/footer.php'; ?>
