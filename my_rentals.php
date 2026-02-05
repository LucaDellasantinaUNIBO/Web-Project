<?php

include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$userId = $_SESSION['user_id'];
$pendingRentals = [];
$activeRentals = [];
$now = date('Y-m-d');

$stmt = mysqli_prepare($conn, "SELECT r.id, r.start_date, r.end_date, r.months, r.total_cost, r.status, p.name AS property_name, p.location, p.image_url, p.type AS property_type, p.status AS property_status FROM rentals r JOIN properties p ON r.property_id = p.id WHERE r.user_id = ? ORDER BY r.start_date DESC");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {

            // If admin removed 'rented' status from property, hide the rental from user
            if ($row['status'] === 'approved' && $row['property_status'] !== 'rented') {
                continue;
            }

            $status = ucfirst($row['status']);

            $statusClass = 'bg-secondary text-white';
            if ($row['status'] === 'approved') {
                if ($row['end_date'] >= $now || is_null($row['end_date'])) {
                    $status = 'Active';
                    $statusClass = 'bg-success text-white';
                } else {
                    $status = 'Completed';
                    $statusClass = 'bg-light text-secondary border';
                }
            } elseif ($row['status'] === 'pending') {
                $status = 'Pending Approval';
                $statusClass = 'bg-warning text-dark';
            } elseif ($row['status'] === 'rejected') {
                $statusClass = 'bg-danger text-white';
            }

            $row['display_status'] = $status;
            $row['status_class'] = $statusClass;

            if ($row['status'] === 'pending') {
                $pendingRentals[] = $row;
            } else {
                $activeRentals[] = $row;
            }
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
            <?php render_user_sidebar(); ?>

            <div class="col-lg-9">
                <?php if (empty($pendingRentals) && empty($activeRentals)): ?>
                    <div class="bg-white rounded-4 shadow-sm p-5 text-center">
                        <div class="mb-3">
                            <i class="fas fa-home fa-3x text-muted opacity-25"></i>
                        </div>
                        <h3 class="h5 fw-bold">No rentals found</h3>
                        <p class="text-muted">You haven't rented any properties yet.</p>
                        <a href="index.php" class="btn btn-primary mt-2">Browse Properties</a>
                    </div>
                <?php else: ?>

                    <!-- Pending Requests Group -->
                    <?php if (!empty($pendingRentals)): ?>
                        <h2 class="h4 mb-3 text-warning"><i class="fas fa-clock me-2"></i>Pending Requests</h2>
                        <div class="d-flex flex-column gap-3 mb-5">
                            <?php foreach ($pendingRentals as $rental): ?>
                                <?php render_rental_card($rental); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Active/Past Rentals Group -->
                    <?php if (!empty($activeRentals)): ?>
                        <h2 class="h4 mb-3 text-success"><i class="fas fa-check-circle me-2"></i>My Rentals</h2>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($activeRentals as $rental): ?>
                                <?php render_rental_card($rental); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Chat Modal -->
<div class="modal fade" id="chatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-sm border-0">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">Contact Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="chat-messages" class="p-3 bg-light" style="height: 300px; overflow-y: auto;">
                    <!-- Messages loaded via AJAX -->
                    <div class="text-center text-muted mt-5">Loading messages...</div>
                </div>
                <form id="chat-form" class="p-3 border-top">
                    <input type="hidden" name="rental_id" id="chat-rental-id">
                    <div class="input-group">
                        <input type="text" name="message" class="form-control border-0 bg-light rounded-start-pill ps-3"
                            placeholder="Type a message..." aria-label="Message" title="Message" required>
                        <button class="btn btn-primary rounded-end-pill px-4" type="submit">
                            <span class="fas fa-paper-plane" aria-hidden="true"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Chat Script -->
<script>
    const chatModal = document.getElementById('chatModal');
    chatModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const rentalId = button.getAttribute('data-rental-id');
        document.getElementById('chat-rental-id').value = rentalId;
        loadMessages(rentalId);
    });

    document.getElementById('chat-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const rentalId = document.getElementById('chat-rental-id').value;
        const msg = this.message.value;


        fetch('api/send_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'rental_id=' + rentalId + '&message=' + encodeURIComponent(msg)
        }).then(res => res.json()).then(data => {
            if (data.success) {
                this.message.value = '';
                loadMessages(rentalId);
            }
        });
    });

    function loadMessages(rentalId) {
        fetch('api/get_messages.php?rental_id=' + rentalId)
            .then(res => res.text())
            .then(html => {
                document.getElementById('chat-messages').innerHTML = html;
                const container = document.getElementById('chat-messages');
                container.scrollTop = container.scrollHeight;
            });
    }
</script>
</div>
</div>
</div>
</main>
<?php include 'includes/footer.php'; ?>