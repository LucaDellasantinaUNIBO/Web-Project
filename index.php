<?php
ob_start();
include 'db/db_config.php';
include 'includes/auth.php';

$isAdmin = is_admin();

$allowedStatuses = ['all', 'available'];
$filterStatus = $_GET['status'] ?? 'all';
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'all';
}

function status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'available') {
        return 'Available';
    }
    if ($status === 'rented') {
        return 'Rented';
    }
    if ($status === 'maintenance') {
        return 'Maintenance';
    }
    if ($status === 'renovation') {
        return 'Renovation';
    }
    return ucfirst($status);
}

function status_badge_class(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'available') {
        return 'bg-success';
    }
    if ($status === 'rented') {
        return 'bg-warning text-dark';
    }
    if ($status === 'maintenance') {
        return 'bg-secondary';
    }
    if ($status === 'renovation') {
        return 'bg-danger';
    }
    return 'bg-light text-dark border';
}

function type_label(string $type): string
{
    return ucfirst($type);
}

$query = "SELECT * FROM properties";
$params = [];
$types = '';

$locationSearch = $_GET['location'] ?? '';
$maxPrice = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (int) $_GET['max_price'] : '';

if ($filterStatus === 'available') {
    $query .= " WHERE status = ?";
    $params[] = 'available';
    $types .= 's';
}

if (!empty($locationSearch)) {
    if (strpos($query, 'WHERE') !== false) {
        $query .= " AND location LIKE ?";
    } else {
        $query .= " WHERE location LIKE ?";
    }
    $params[] = '%' . $locationSearch . '%';
    $types .= 's';
}

if (!empty($maxPrice)) {
    if (strpos($query, 'WHERE') !== false) {
        $query .= " AND monthly_price <= ?";
    } else {
        $query .= " WHERE monthly_price <= ?";
    }
    $params[] = $maxPrice;
    $types .= 'd';
}

$query .= " ORDER BY id DESC";

$stmt = mysqli_prepare($conn, $query);
if ($stmt && $params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = false;
}

include 'includes/header.php';
?>

<main>
    <section class="position-relative w-100 d-flex align-items-center justify-content-center"
        style="height: 600px; background: url('https://images.unsplash.com/photo-1564013799919-ab600027ffc6?ixlib=rb-4.0.3&auto=format&fit=crop&w=2000&q=80') center/cover no-repeat;">
        <div class="position-absolute top-0 start-0 w-100 h-100"
            style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.6) 100%);"></div>

        <div class="container position-relative z-2 text-center text-white">
            <h1 class="display-3 fw-bold mb-3">Find Your Perfect Home</h1>
            <p class="fs-4 fw-light mb-5 opacity-75">Discover luxury apartments, cozy homes, and modern villas for rent
                in your favorite locations.</p>
            <div class="bg-white p-2 rounded-4 shadow-lg mx-auto d-flex align-items-center gap-2"
                style="max-width: 800px; padding-right: 8px !important;">
                <form class="w-100 d-flex align-items-center gap-2 m-0" method="get" id="filters">
                    <div class="flex-grow-1 border-end px-3 py-2 d-none d-md-block">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <span class="fas fa-map-marker-alt small" aria-hidden="true"></span>
                            <label for="location-input" class="small fw-bold text-uppercase mb-0"
                                style="font-size: 0.7rem;">Location</label>
                        </div>
                        <input type="text" name="location" id="location-input"
                            class="form-control border-0 p-0 shadow-none fw-medium"
                            placeholder="Location (e.g. Los Angeles)" aria-label="Location"
                            value="<?php echo htmlspecialchars($locationSearch); ?>">
                    </div>

                    <div class="flex-grow-1 px-3 py-2">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <span class="fas fa-home small" aria-hidden="true"></span>
                            <label for="status-filter-select" class="small fw-bold text-uppercase mb-0"
                                style="font-size: 0.7rem;">Property Type</label>
                        </div>
                        <select class="form-select border-0 p-0 shadow-none fw-medium" name="status"
                            id="status-filter-select" aria-label="Property Type">
                            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Types
                            </option>
                            <option value="available" <?php echo $filterStatus === 'available' ? 'selected' : ''; ?>>
                                Available</option>
                        </select>
                    </div>

                    <div class="flex-grow-1 border-start px-3 py-2 d-none d-md-block">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <span class="fas fa-euro-sign small" aria-hidden="true"></span>
                            <label for="max-price-input" class="small fw-bold text-uppercase mb-0"
                                style="font-size: 0.7rem;">Max Price</label>
                        </div>
                        <input type="number" name="max_price" id="max-price-input"
                            class="form-control border-0 p-0 shadow-none fw-medium" placeholder="Max Price"
                            aria-label="Max Price" value="<?php echo htmlspecialchars($maxPrice); ?>">
                    </div>

                    <button type="submit"
                        class="btn btn-primary rounded-circle p-3 shadow-sm d-flex align-items-center justify-content-center"
                        style="width: 48px; height: 48px;" aria-label="Search Properties">
                        <span class="fas fa-search" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    <section class="py-5 bg-white border-bottom">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h2 class="fw-bold mb-1" style="color: var(--primary-dark);">Explore Locations</h2>
                    <p class="text-muted">View properties on the interactive map</p>
                </div>
                <button id="expand-map-btn" class="btn btn-outline-dark rounded-pill px-4 fw-medium"
                    onclick="toggleMapSize()">
                    <span class="fas fa-expand-alt me-2" aria-hidden="true"></span> Expand Map
                </button>
            </div>
            <div id="map-container" style="height: 400px; transition: height 0.5s ease;"
                class="rounded-4 shadow-sm overflow-hidden position-relative border">
                <div id="map" style="height: 100%; width: 100%;"></div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light" id="properties-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6 mb-2" style="color: var(--primary-dark);">Featured Properties</h2>
                <p class="text-muted">Explore our hand-picked selection of premium rental properties.</p>
            </div>

            <div class="row row-cols-1 row-cols-md-3 g-4">
                <?php
                $mapProperties = [];
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $sqm = isset($row['sqm']) ? (int) $row['sqm'] : 60;

                        $beds = (int) ($row['beds'] ?? 1);
                        $baths = (int) ($row['baths'] ?? 1);

                        if (!empty($row['lat']) && !empty($row['lng'])) {
                            $mapProperties[] = [
                                'name' => $row['name'],
                                'lat' => (float) $row['lat'],
                                'lng' => (float) $row['lng'],
                                'price' => $row['monthly_price'],
                                'image' => $row['image_url'] ?? '',
                                'id' => $row['id']
                            ];
                        }

                        $statusLabel = status_label($row['status']);
                        $isAvailable = strtolower(trim($row['status'])) === 'available';
                        $imageUrl = trim((string) ($row['image_url'] ?? ''));


                        $badgeColor = 'bg-primary';
                        if ($statusLabel == 'Rented')
                            $badgeColor = 'bg-warning text-dark';
                        if ($statusLabel == 'Maintenance')
                            $badgeColor = 'bg-secondary';
                        ?>
                        <div class="col">
                            <div class="card h-100 overflow-hidden border-0 shadow-sm hover-lift">
                                <div class="position-relative">
                                    <?php if ($imageUrl): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" class="card-img-top"
                                            alt="<?php echo htmlspecialchars($row['name']); ?>"
                                            style="height: 240px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="image-placeholder d-flex align-items-center justify-content-center bg-light"
                                            style="height: 240px;">
                                            <div class="text-muted small">No image</div>
                                        </div>
                                    <?php endif; ?>

                                    <div
                                        class="position-absolute top-0 start-0 m-3 bg-white text-dark rounded-pill px-3 py-1 fw-bold shadow-sm small">
                                        €<?php echo htmlspecialchars((string) $row['monthly_price']); ?>/mo
                                    </div>

                                    <div
                                        class="position-absolute top-0 end-0 m-3 badge <?php echo $badgeColor; ?> rounded-pill px-3 shadow-sm">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </div>
                                </div>

                                <div class="card-body p-4">
                                    <h3 class="h5 fw-bold text-dark mb-1">
                                        <?php echo htmlspecialchars($row['name']); ?>
                                    </h3>
                                    <div class="d-flex align-items-center text-muted small mb-3">
                                        <span class="fas fa-map-marker-alt me-1 text-success" aria-hidden="true"></span>
                                        <?php echo htmlspecialchars($row['location']); ?>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between my-4 px-1">
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <span class="fas fa-bed" aria-hidden="true"></span>
                                            <span class="small fw-medium"><?php echo $beds; ?> Beds</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <span class="fas fa-bath" aria-hidden="true"></span>
                                            <span class="small fw-medium"><?php echo $baths; ?> Baths</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <span class="far fa-square" aria-hidden="true"></span>
                                            <span class="small fw-medium"><?php echo $sqm; ?> m²</span>
                                        </div>
                                    </div>

                                    <?php if ($isAdmin): ?>
                                        <a href="admin.php?tab=properties&edit=<?php echo $row['id']; ?>"
                                            class="btn btn-outline-primary w-100 rounded-pill fw-bold">
                                            <span class="fas fa-edit me-1" aria-hidden="true"></span> Modify Property
                                        </a>
                                    <?php elseif ($isAvailable): ?>
                                        <a href="booking.php?property_id=<?php echo $row['id']; ?>"
                                            class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm icon-link-hover"
                                            aria-label="Rent <?php echo htmlspecialchars($row['name']); ?>">
                                            <span class="fas fa-key small me-2" aria-hidden="true"></span> Rent Now
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary w-100 rounded-pill py-2 text-white"
                                            disabled>Unavailable</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo "<div class='col-12'><div class='alert alert-info rounded-4'>No properties found matching your criteria.</div></div>";
                }
                ?>
            </div>
        </div>
    </section>
</main>

<script>

    var map;
    var isExpanded = false;

    function initMap() {

        map = L.map('map').setView([41.8719, 12.5674], 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var properties = <?php echo json_encode($mapProperties); ?>;

        properties.forEach(function (p) {
            var marker = L.marker([p.lat, p.lng]).addTo(map);

            var popupContent = `
<div style="width: 200px;">
    ${p.image ? `<img src="${p.image}" alt="${p.name}"
        style="width: 100%; height: 120px; object-fit: cover; border-radius: 8px 8px 0 0;">` : ''}
    <div class="p-2">
        <div class="fw-bold mb-1">${p.name}</div>
        <div class="text-success fw-bold text-end">€${p.price}/mo</div>
    </div>
</div>
`;

            marker.bindPopup(popupContent);


            marker.on('mouseover', function (e) {
                this.openPopup();
            });
            marker.on('mouseout', function (e) {

            });
            marker.on('click', function (e) {

            });
        });
    }

    function toggleMapSize() {
        var container = document.getElementById('map-container');
        var btn = document.getElementById('expand-map-btn');

        if (!isExpanded) {
            container.style.height = '600px';
            btn.innerHTML = '<span class="fas fa-compress-alt me-1" aria-hidden="true"></span> Shrink Map';
            isExpanded = true;
        } else {
            container.style.height = '400px';
            btn.innerHTML = '<span class="fas fa-expand-alt me-1" aria-hidden="true"></span> Expand Map';
            isExpanded = false;
        }

        setTimeout(function () {
            map.invalidateSize();
        }, 505);
    }


    document.addEventListener('DOMContentLoaded', initMap);

</script>

<?php include 'includes/footer.php'; ?>