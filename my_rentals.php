<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$userId = $_SESSION['user_id'];
$rentals = [];
$now = date('Y-m-d');

// Fetch all rentals with property details
$stmt = mysqli_prepare($conn, "SELECT r.id, r.start_date, r.end_date, r.months, r.total_cost, p.name AS property_name, p.location, p.image_url, p.type AS property_type FROM rentals r JOIN properties p ON r.property_id = p.id WHERE r.user_id = ? ORDER BY r.start_date DESC");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Determine status
            $status = 'Completed';
            $statusClass = 'bg-light text-secondary';

            if ($row['end_date'] >= $now || is_null($row['end_date'])) {
                $status = 'Active';
                $statusClass = 'bg-light-success text-success';
            }

            $row['status'] = $status;
            $row['status_class'] = $statusClass;
            $rentals[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5 mx-auto" style="max-width: 1000px;" id="main-content">
        <h1 class="display-6 fw-bold text-dark mb-4">My Rentals</h1>

        <div class="row">
            <?php include 'includes/user_sidebar.php'; ?>

            <div class="col-lg-9">
                <?php if (empty($rentals)): ?>
                    <div class="bg-white rounded-4 shadow-sm p-5 text-center">
                        <div class="mb-3">
                            <i class="fas fa-home fa-3x text-muted opacity-25"></i>
                        </div>
                        <h3 class="h5 fw-bold">No rentals found</h3>
                        <p class="text-muted">You haven't rented any properties yet.</p>
                        <a href="index.php" class="btn btn-primary mt-2">Browse Properties</a>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($rentals as $rental): ?>
                            <div class="bg-white rounded-4 shadow-sm p-0 overflow-hidden">
                                <div class="row g-0">
                                    <div class="col-md-4">
                                        <div style="height: 100%; min-height: 200px; background-color: #f0f0f0;">
                                            <?php if ($rental['image_url']): ?>
                                                <img src="<?php echo htmlspecialchars($rental['image_url']); ?>"
                                                    alt="<?php echo htmlspecialchars($rental['property_name']); ?>"
                                                    class="w-100 h-100 object-fit-cover">
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                                    <i class="fas fa-image fa-2x"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="p-4 d-flex flex-column h-100">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <h3 class="h5 fw-bold mb-1">
                                                        <?php echo htmlspecialchars($rental['property_name']); ?>
                                                    </h3>
                                                    <p class="text-muted small mb-0"><i class="fas fa-map-marker-alt me-1"></i>
                                                        <?php echo htmlspecialchars($rental['location']); ?>
                                                    </p>
                                                </div>
                                                <span
                                                    class="badge <?php echo $rental['status_class']; ?> rounded-pill px-3 py-2 fw-medium">
                                                    <?php echo $rental['status']; ?>
                                                </span>
                                            </div>

                                            <div class="row mt-3 g-3">
                                                <div class="col-6">
                                                    <div class="text-muted small">Monthly Rent</div>
                                                    <div class="fw-bold">
                                                        €
                                                        <?php echo number_format($rental['total_cost'] / max($rental['months'], 1), 2); ?>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="text-muted small">Next Payment</div>
                                                    <div class="fw-bold">
                                                        <?php echo $rental['status'] === 'Active' ? date('Y-m-d', strtotime('+1 month')) : '-'; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mt-auto pt-3 d-flex gap-2">
                                                <button class="btn btn-outline-secondary btn-sm px-3">View Details</button>
                                                <?php if ($rental['status'] === 'Active'): ?>
                                                    <a href="wallet.php" class="btn btn-primary btn-sm px-3">Pay Rent</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>