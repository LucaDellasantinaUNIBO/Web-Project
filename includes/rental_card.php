<div class="bg-white rounded-4 shadow-sm p-0 overflow-hidden">
    <div class="row g-0">
        <div class="col-md-4">
            <div style="height: 100%; min-height: 200px; background-color: 
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
                    <span class="badge <?php echo $rental['status_class']; ?> rounded-pill px-3 py-2 fw-medium">
                        <?php echo $rental['display_status']; ?>
                    </span>
                </div>

                <div class="row mt-3 g-3">
                    <div class="col-6">
                        <div class="text-muted small">Monthly Rent</div>
                        <div class="fw-bold">
                            €
                            <?php echo number_format($rental['total_cost'] / max($rental['months'], 1), 2, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Dates</div>
                        <div class="fw-bold">
                            <?php echo date('M Y', strtotime($rental['start_date'])); ?> -
                            <?php echo $rental['end_date'] ? date('M Y', strtotime($rental['end_date'])) : 'Indefinite'; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-auto pt-3 d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm px-3" data-bs-toggle="modal"
                        data-bs-target="
                        <i class="fas fa-comment-alt me-1"></i> Chat
                    </button>
                    <!-- Add Pay Rent or Details buttons here if needed -->
                </div>
            </div>
        </div>
    </div>
</div>