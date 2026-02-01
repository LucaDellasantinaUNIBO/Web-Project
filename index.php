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

if ($filterStatus === 'available') {
    $query .= " WHERE status = ?";
    $params[] = 'available';
    $types .= 's';
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

    <section class="py-5" style="background-color: #2E8B57; color: #ffffff;" id="main-content">
        <div class="container text-center py-4">
            <h1 class="display-5 fw-bold mb-3" style="color: #ffffff;">Find Your Ideal Home</h1>
            <p class="fs-2" style="color: #ffffff;">Comfortable and affordable rentals</p>

            <div class="bg-white p-4 rounded-4 shadow-lg text-dark mx-auto mt-5"
                style="max-width: 520px; background-color: #ffffff;">
                <form class="row g-3" method="get" id="filters">
                    <div class="col-12 text-start">
                        <label class="form-label fw-bold" for="status-filter-select"
                            style="color: #000000;">Status</label>
                        <select class="form-select border-2" name="status" id="status-filter-select"
                            onchange="this.form.submit()">
                            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All properties
                            </option>
                            <option value="available" <?php echo $filterStatus === 'available' ? 'selected' : ''; ?>
                                >Available</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <h2 class="fw-bold mb-4">Properties</h2>
            <div class="row row-cols-1 row-cols-md-3 g-4">

                <?php
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rooms = (int) $row['rooms'];

                        $statusLabel = status_label($row['status']);
                        $badgeClass = status_badge_class($row['status']);
                        $isAvailable = strtolower(trim($row['status'])) === 'available';
                        $imageUrl = trim((string) ($row['image_url'] ?? ''));
                        $showImage = $imageUrl !== '';
                        ?>
                        <div class="col">
                            <div class="card h-100 overflow-hidden shadow-sm border-0">
                                <div class="position-relative">
                                    <?php if ($showImage): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" class="card-img-top" alt="Property"
                                            style="height: 200px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="image-placeholder d-flex align-items-center justify-content-center bg-light"
                                            style="height: 200px;">
                                            <div class="text-muted small">No image</div>
                                        </div>
                                    <?php endif; ?>
                                    <span
                                        class="position-absolute top-0 end-0 m-3 badge <?php echo $badgeClass; ?> rounded-pill px-3">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </div>
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between">
                                        <h3 class="h5 fw-bold text-dark">
                                            <?php echo htmlspecialchars($row['name']); ?>
                                        </h3>
                                        <span class="h4 fw-bold text-success">$
                                            <?php echo htmlspecialchars((string) $row['monthly_price']); ?>/mo
                                        </span>
                                    </div>
                                    <p class="text-muted small mb-1"><i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo htmlspecialchars($row['location']); ?>
                                    </p>
                                    <p class="text-muted small">Type:
                                        <?php echo htmlspecialchars(type_label($row['type'])); ?>
                                    </p>

                                    <div class="my-3 text-dark">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-bed me-2"></i>
                                            <span>
                                                <?php echo htmlspecialchars((string) $rooms); ?> Room
                                                <?php echo $rooms !== 1 ? 's' : ''; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                        <button class="btn btn-outline-secondary w-100 mt-2" disabled>Admins cannot book</button>
                                    <?php elseif ($isAvailable): ?>
                                        <a href="booking.php?property_id=<?php echo $row['id']; ?>"
                                            class="btn btn-primary w-100 mt-2">Rent Now</a>
                                    <?php else: ?>
                                        <button class="btn btn-outline-secondary w-100 mt-2" disabled>Unavailable</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo "<div class='col-12'><p class='text-center'>No properties found.</p></div>";
                }
                ?>

            </div>
        </div>
    </section>
</main>

<script>
    (function () {
        var form = document.getElementById('filters');
        if (!form) {
            return;
        }
        var select = form.querySelector('select');
        if (!select) {
            return;
        }
        select.addEventListener('change', function () {
            form.submit();
        });
    })();
</script>

<?php include 'includes/footer.php'; ?>