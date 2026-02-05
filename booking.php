<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

if (is_admin()) {
    include 'includes/header.php';
    ?>
    <div class="container py-5" style="max-width: 720px;">
        <div class="alert alert-warning">
            Admin accounts cannot rent properties.
        </div>
        <a class="btn btn-outline-secondary" href="admin.php">Go to admin panel</a>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

$errors = [];
$success = null;
$property = null;

function normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['available', 'rented', 'maintenance', 'renovation'], true)) {
        return $status;
    }
    return 'available';
}

function type_label(string $type): string
{
    return ucfirst($type);
}

$propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $propertyId = (int) ($_POST['property_id'] ?? 0);
}

if ($propertyId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM properties WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $propertyId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $property = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

if (!$property) {
    $errors[] = 'Property not found.';
}

$userCredit = 0.00;
$userCardNumber = '';
$userCardCvc = '';
$userCardExpiry = '';
if (isset($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT credit, mock_card_number, mock_cvc, mock_expiry FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $userCredit = (float) $row['credit'];
        $userCardNumber = $row['mock_card_number'] ?? '';
        $userCardCvc = $row['mock_cvc'] ?? '';
        $userCardExpiry = $row['mock_expiry'] ?? '';
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $months = (int) ($_POST['months'] ?? 0);
    $startDateInput = $_POST['start_date'] ?? date('Y-m-d');
    $status = normalize_status($property['status']);
    $estimatedCost = 0.0;

    if ($status !== 'available') {
        $errors[] = 'Property not available.';
    }
    if ($months <= 0) {
        $errors[] = 'Enter lease duration in months.';
    }

    // Validate Start Date
    $startDate = DateTime::createFromFormat('Y-m-d', $startDateInput);
    if (!$startDate) {
        $errors[] = 'Invalid start date.';
    }

    // Check if user has card saved
    if (empty($userCardNumber)) {
        $_SESSION['profile_flash'] = ['type' => 'warning', 'message' => 'Please add your card details before making a rental.'];
        header('Location: profile.php');
        exit;
    }



    if ($property) {
        $estimatedCost = round((float) $property['monthly_price'] * $months, 2);
        if ($userCredit < $estimatedCost) {
            $errors[] = 'Insufficient credit. Please add funds to your wallet.';
        }
    }

    if (!$errors) {
        $start = $startDate->format('Y-m-d');
        $endDate = clone $startDate;
        $endDate->modify("+$months months");
        $end = $endDate->format('Y-m-d');

        $cost = $estimatedCost;
        $newBalance = $userCredit - $cost;

        mysqli_begin_transaction($conn);

        $stmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, property_id, start_date, end_date, months, total_cost, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        mysqli_stmt_bind_param($stmt, "iisssd", $_SESSION['user_id'], $propertyId, $start, $end, $months, $cost);
        $insertOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE users SET credit = credit - ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "di", $cost, $_SESSION['user_id']);
        $walletUpdateOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $transactionOk = true;
        $transactionStmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, 'rental', ?, ?, ?)");
        if ($transactionStmt) {
            $amount = 0 - $cost;
            $description = "Rental Request for {$property['name']} (Pending Approval).";
            mysqli_stmt_bind_param($transactionStmt, "idds", $_SESSION['user_id'], $amount, $newBalance, $description);
            $transactionOk = mysqli_stmt_execute($transactionStmt);
            mysqli_stmt_close($transactionStmt);
        }

        $updateOk = true; // We don't update property status yet
        // if ($insertOk) {
        //     $stmt = mysqli_prepare($conn, "UPDATE properties SET status = 'rented' WHERE id = ?");
        //     mysqli_stmt_bind_param($stmt, "i", $propertyId);
        //     $updateOk = mysqli_stmt_execute($stmt);
        //     mysqli_stmt_close($stmt);
        // }

        if ($insertOk && $updateOk && $walletUpdateOk && $transactionOk) {
            mysqli_commit($conn);
            $success = 'Rental request sent! Waiting for admin approval.';
            $property['status'] = 'available'; // Still available until approved
            $userCredit -= $cost;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Booking failed.';
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" id="main-content">
        <div class="row g-4">
            <div class="col-lg-7">
                <h1 class="fw-bold mb-3">Rent this Property</h1>
                <p class="text-muted">Lease this home instantly.</p>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($property): ?>
                    <div class="form-section p-4 shadow-sm mb-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h2 class="h5 fw-bold mb-1">
                                    <?php echo htmlspecialchars($property['name']); ?>
                                </h2>
                                <p class="text-muted mb-0">Type:
                                    <?php echo htmlspecialchars(type_label($property['type'])); ?>
                                </p>
                            </div>
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars(ucfirst(normalize_status($property['status']))); ?>
                            </span>
                        </div>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small text-muted">Location</div>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($property['location']); ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small text-muted">Rooms</div>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars((string) $property['rooms']); ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small text-muted">Monthly Rent</div>
                                <div class="fw-bold">€
                                    <?php echo htmlspecialchars((string) $property['monthly_price']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section p-4 shadow-sm">
                        <form method="post" class="booking-form">
                            <input type="hidden" name="property_id"
                                value="<?php echo htmlspecialchars((string) $propertyId); ?>">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold" for="start-date-input">Start Date</label>
                                    <input id="start-date-input" type="date" name="start_date" class="form-control"
                                        value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold" for="months-input">Duration (Months)</label>
                                    <input id="months-input" type="number" min="1" name="months" class="form-control"
                                        value="12" required>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted">Total Cost</div>
                                    <div class="display-6 fw-bold text-success" id="price-estimate"
                                        data-monthly-price="<?php echo htmlspecialchars((string) ((float) $property['monthly_price'])); ?>">
                                        €0.00</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted">Lease End</div>
                                    <div class="fw-bold" id="end-estimate">--</div>
                                </div>
                            </div>

                            <div class="mb-4 mt-3">
                                <div class="small text-muted">Wallet balance</div>
                                <div class="fw-bold">€
                                    <?php echo number_format($userCredit, 2, ',', '.'); ?>
                                </div>
                                <div class="small text-muted mt-1">Rent is charged upfront.</div>
                                <div id="credit-warning" class="alert alert-warning mt-3 d-none">
                                    Not enough credit to confirm this rental.
                                    <a class="alert-link" href="wallet.php">Open wallet</a>
                                </div>
                            </div>
                            <button id="confirm-booking" type="submit" class="btn btn-primary w-100 mt-2">Rent
                                Request</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <div class="form-section p-4 shadow-sm h-100 bg-light">
                    <h3 class="h5 fw-bold">Why rent with us?</h3>
                    <ul class="list-unstyled mt-3">
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Verified Properties</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Secure Payments</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> 24/7 Support</li>
                    </ul>

                    <!-- Embedded Chat -->
                    <div class="mt-4 border-top pt-3">
                        <h3 class="h6 fw-bold mb-3">Need Help? Chat with Us</h3>
                        <div class="bg-white rounded-3 shadow-sm">
                            <div id="booking-chat-messages" class="p-3 bg-light"
                                style="height: 250px; overflow-y: auto; border-radius: 0.5rem 0.5rem 0 0;">
                                <div class="text-center text-muted mt-5">Loading messages...</div>
                            </div>
                            <form id="booking-chat-form" class="p-3 border-top bg-white"
                                style="border-radius: 0 0 0.5rem 0.5rem;">
                                <div class="input-group">
                                    <input type="text" name="message"
                                        class="form-control border-0 bg-light rounded-start-pill ps-3"
                                        placeholder="Type a message..." required>
                                    <button class="btn btn-primary rounded-end-pill px-4" type="submit"
                                        aria-label="Send message">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="creditModal" tabindex="-1" role="dialog" aria-labelledby="creditModalLabel"
        aria-hidden="true" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="h5 modal-title" id="creditModalLabel">Insufficient credit</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Your wallet balance is not enough to cover this rental. Please add funds to continue.
                </div>
                <div class="modal-footer">
                    <a href="wallet.php" class="btn btn-primary">Open wallet</a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    (function () {
        var monthsInput = document.getElementById('months-input');
        var dateInput = document.getElementById('start-date-input');
        var estimate = document.getElementById('price-estimate');
        var endEstimate = document.getElementById('end-estimate');
        var form = document.querySelector('.booking-form');
        var creditWarning = document.getElementById('credit-warning');
        var creditModal = document.getElementById('creditModal');

        if (!monthsInput || !estimate || !endEstimate) {
            return;
        }

        var monthlyPrice = parseFloat(estimate.dataset.monthlyPrice || '0');
        var userCredit = <?php echo json_encode((float) $userCredit); ?>;

        var updateCreditState = function (total) {
            var insufficient = total > userCredit;
            if (creditWarning) {
                creditWarning.classList.toggle('d-none', !insufficient);
            }
            return insufficient;
        };

        var updateEstimate = function () {
            var months = parseInt(monthsInput.value, 10);
            if (isNaN(months) || months <= 0) {
                estimate.textContent = '€0.00';
                endEstimate.textContent = '--';
                updateCreditState(0);
                return;
            }
            var total = months * monthlyPrice;
            estimate.textContent = '€' + total.toFixed(2);

            var startDateStr = dateInput.value;
            if (startDateStr) {
                var startDate = new Date(startDateStr);
                var endDate = new Date(startDate.setMonth(startDate.getMonth() + months));
                endEstimate.textContent = endDate.toLocaleDateString();
            } else {
                endEstimate.textContent = '--';
            }

            updateCreditState(total);
        };

        monthsInput.addEventListener('input', updateEstimate);
        dateInput.addEventListener('change', updateEstimate);

        if (form) {
            form.addEventListener('submit', function (event) {
                var months = parseInt(monthsInput.value, 10);
                var total = 0;
                if (!isNaN(months) && months > 0) {
                    total = months * monthlyPrice;
                }
                if (total > userCredit) {
                    event.preventDefault();
                    if (creditModal && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(creditModal).show();
                    }
                }
            });
        }
        updateEstimate();

        // Card input formatting
        const cardNumberBooking = document.getElementById('card_number_booking');
        const cardExpiryBooking = document.getElementById('card_expiry_booking');
        const cardCvcBooking = document.getElementById('card_cvc_booking');

        if (cardNumberBooking) {
            cardNumberBooking.addEventListener('input', function (e) {
                let value = e.target.value.replace(/\s/g, '').replace(/\D/g, '');
                let formatted = value.match(/.{1,4}/g)?.join(' ') || value;
                e.target.value = formatted;
            });
        }

        if (cardExpiryBooking) {
            cardExpiryBooking.addEventListener('input', function (e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length >= 2) {
                    // Validate month is between 01-12
                    let month = parseInt(value.slice(0, 2));
                    if (month > 12) {
                        value = '12' + value.slice(2);
                    } else if (month === 0) {
                        value = '01' + value.slice(2);
                    }
                    value = value.slice(0, 2) + '/' + value.slice(2, 4);
                }
                e.target.value = value;
            });
        }

        if (cardCvcBooking) {
            cardCvcBooking.addEventListener('input', function (e) {
                e.target.value = e.target.value.replace(/\D/g, '');
            });
        }
    })();

    // Booking Chat Functionality
    (function () {
        const chatForm = document.getElementById('booking-chat-form');
        const chatMessages = document.getElementById('booking-chat-messages');

        if (!chatForm || !chatMessages) return;

        let adminId = null;

        // Load messages with admin user_id
        function loadBookingMessages() {
            if (!adminId) return;

            fetch('api/get_messages.php?user_id=' + adminId)
                .then(res => res.text())
                .then(html => {
                    chatMessages.innerHTML = html || '<div class="text-center text-muted">No messages yet. Start the conversation!</div>';
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                });
        }

        // Send message
        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const message = this.message.value.trim();

            if (!message || !adminId) return;

            // Send message to admin
            fetch('api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'message=' + encodeURIComponent(message) + '&receiver_id=' + adminId
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        chatForm.message.value = '';
                        loadBookingMessages();
                    }
                })
                .catch(err => console.error('Error sending message:', err));
        });

        // Get admin ID and start chat
        fetch('api/get_admin_id.php')
            .then(res => res.json())
            .then(data => {
                if (data.admin_id) {
                    adminId = data.admin_id;
                    loadBookingMessages();
                    setInterval(loadBookingMessages, 5000);
                }
            });
    })();
</script>

<?php include 'includes/footer.php'; ?>