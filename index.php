<?php
/** @var mysqli $conn */
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
    $types .= 'd'; // Decimal/Double (or integer)
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

    <!-- Hero Section -->
    <section class="position-relative w-100 d-flex align-items-center justify-content-center"
        style="height: 600px; background: url('https://images.unsplash.com/photo-1564013799919-ab600027ffc6?ixlib=rb-4.0.3&auto=format&fit=crop&w=2000&q=80') center/cover no-repeat;">
        <div class="position-absolute top-0 start-0 w-100 h-100"
            style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.6) 100%);"></div>

        <div class="container position-relative z-2 text-center text-white">
            <h1 class="display-3 fw-bold mb-3">Find Your Perfect <span class="text-success">Home</span></h1>
            <p class="fs-4 fw-light mb-5 opacity-75">Discover luxury apartments, cozy homes, and modern villas for rent
                in your favorite locations.</p>

            <!-- Search Bar -->
            <div class="bg-white p-2 rounded-4 shadow-lg mx-auto d-flex align-items-center gap-2"
                style="max-width: 800px; padding-right: 8px !important;">

                <form class="w-100 d-flex align-items-center gap-2 m-0" method="get" id="filters">
                    <div class="flex-grow-1 border-end px-3 py-2 d-none d-md-block">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <i class="fas fa-map-marker-alt small"></i>
                            <span class="small fw-bold text-uppercase" style="font-size: 0.7rem;">Location</span>
                        </div>
                        <input type="text" name="location" class="form-control border-0 p-0 shadow-none fw-medium"
                            placeholder="Location (e.g. Los Angeles)"
                            value="<?php echo htmlspecialchars($locationSearch); ?>">
                    </div>

                    <div class="flex-grow-1 px-3 py-2">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <i class="fas fa-home small"></i>
                            <span class="small fw-bold text-uppercase" style="font-size: 0.7rem;">Property Type</span>
                        </div>
                        <select class="form-select border-0 p-0 shadow-none fw-medium" name="status"
                            id="status-filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Types
                            </option>
                            <option value="available" <?php echo $filterStatus === 'available' ? 'selected' : ''; ?>>
                                Available</option>
                        </select>
                    </div>

                    <div class="flex-grow-1 border-start px-3 py-2 d-none d-md-block">
                        <div class="d-flex align-items-center gap-2 text-muted mb-1">
                            <i class="fas fa-euro-sign small"></i>
                            <span class="small fw-bold text-uppercase" style="font-size: 0.7rem;">Max Price</span>
                        </div>
                        <input type="number" name="max_price" class="form-control border-0 p-0 shadow-none fw-medium"
                            placeholder="e.g. 1500" value="<?php echo htmlspecialchars((string) $maxPrice); ?>">
                    </div>

                    <button
                        class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-sm d-flex align-items-center gap-2">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Map Section -->
    <section class="py-5 bg-white border-bottom">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h2 class="fw-bold mb-1" style="color: var(--primary-dark);">Explore Locations</h2>
                    <p class="text-muted">View properties on the interactive map</p>
                </div>
                <button id="expand-map-btn" class="btn btn-outline-dark rounded-pill px-4 fw-medium"
                    onclick="toggleMapSize()">
                    <i class="fas fa-expand-alt me-2"></i> Expand Map
                </button>
            </div>
            <div id="map-container" style="height: 400px; transition: height 0.5s ease;"
                class="rounded-4 shadow-sm overflow-hidden position-relative border">
                <div id="map" style="height: 100%; width: 100%;"></div>
            </div>
        </div>
    </section>

    <!-- Properties Section -->
    <section class="py-5 bg-light" id="properties-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6 mb-2" style="color: var(--primary-dark);">Featured Properties</h2>
                <p class="text-muted">Explore our hand-picked selection of premium rental properties.</p>
            </div>

            <div class="row row-cols-1 row-cols-md-3 g-4">
                <?php
                $mapProperties = []; // Array to hold data for JS map
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rooms = (int) $row['rooms'];
                        $sqft = isset($row['sqft']) ? (int) $row['sqft'] : 1200; // Fallback if null
                        // Collect data for map
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

                        // Badge logic
                        $badgeColor = 'bg-primary';
                        if ($statusLabel == 'Rented')
                            $badgeColor = 'bg-warning text-dark';
                        if ($statusLabel == 'Maintenance')
                            $badgeColor = 'bg-secondary';
                        ?>
                        <div class="col">
                            <div class="card h-100 overflow-hidden border-0 shadow-sm hover-lift">
                                <!-- Image Wrapper -->
                                <div class="position-relative">
                                    <?php if ($imageUrl): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" class="card-img-top" alt="Property"
                                            style="height: 240px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="image-placeholder d-flex align-items-center justify-content-center bg-light"
                                            style="height: 240px;">
                                            <div class="text-muted small">No image</div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Price Pill -->
                                    <div
                                        class="position-absolute top-0 start-0 m-3 bg-white text-dark rounded-pill px-3 py-1 fw-bold shadow-sm small">
                                        €<?php echo htmlspecialchars((string) $row['monthly_price']); ?>/mo
                                    </div>

                                    <!-- Status Badge -->
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
                                        <i class="fas fa-map-marker-alt me-1 text-success"></i>
                                        <?php echo htmlspecialchars($row['location']); ?>
                                    </div>

                                    <!-- Specs Row -->
                                    <div class="d-flex align-items-center justify-content-between my-4 px-1">
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <i class="fas fa-bed"></i>
                                            <span class="small fw-medium"><?php echo htmlspecialchars((string) $rooms); ?>
                                                Beds</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <i class="fas fa-bath"></i>
                                            <span class="small fw-medium">2 Baths</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted">
                                            <i class="far fa-square"></i>
                                            <span class="small fw-medium"><?php echo $sqft; ?> sqft</span>
                                        </div>
                                    </div>

                                    <?php if ($isAdmin): ?>
                                        <a href="admin.php?tab=properties&edit=<?php echo $row['id']; ?>#property-form"
                                            class="btn btn-outline-primary w-100 rounded-pill fw-bold">
                                            <i class="fas fa-edit me-1"></i> Modify Property
                                        </a>
                                    <?php elseif ($isAvailable): ?>
                                        <a href="booking.php?property_id=<?php echo $row['id']; ?>"
                                            class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm icon-link-hover">
                                            <i class="fas fa-key small me-2"></i> Rent Now
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
    (function () {
        var form = document.getElementById('filters');
        if (!form) return;
        var select = form.querySelector('select');
        if (!select) return;
        select.addEventListener('change', function () {
            form.submit();
        });
    })();

    // Map Implementation
    var map;
    var isExpanded = false;

    function initMap() {
        // Initialize map centered on Italy
        map = L.map('map').setView([41.8719, 12.5674], 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var properties = <?php echo json_encode($mapProperties); ?>;

        properties.forEach(function (p) {
            var marker = L.marker([p.lat, p.lng]).addTo(map);

            var popupContent = `
                <div style="width: 200px;">
                    ${p.image ? `<img src="${p.image}" style="width: 100%; height: 120px; object-fit: cover; border-radius: 8px 8px 0 0;">` : ''}
                    <div class="p-2">
                        <h6 class="fw-bold mb-1">${p.name}</h6>
                        <div class="text-success fw-bold text-end">€${p.price}/mo</div>
                    </div>
                </div>
            `;

            marker.bindPopup(popupContent);

            // Show popup on hover
            marker.on('mouseover', function (e) {
                this.openPopup();
            });
            marker.on('mouseout', function (e) {
                // this.closePopup(); // Optional: keep open or close
            });
            marker.on('click', function (e) {
                // Scroll to card? or go to booking?
            });
        });
    }

    function toggleMapSize() {
        var container = document.getElementById('map-container');
        var btn = document.getElementById('expand-map-btn');

        if (!isExpanded) {
            container.style.height = '600px';
            btn.innerHTML = '<i class="fas fa-compress-alt me-1"></i> Shrink Map';
            isExpanded = true;
        } else {
            container.style.height = '400px';
            btn.innerHTML = '<i class="fas fa-expand-alt me-1"></i> Expand Map';
            isExpanded = false;
        }

        setTimeout(function () {
            map.invalidateSize();
        }, 505); // Wait for transition
    }

    // Load map when DOM is ready
    document.addEventListener('DOMContentLoaded', initMap);

</script>

<?php include 'includes/footer.php'; ?>