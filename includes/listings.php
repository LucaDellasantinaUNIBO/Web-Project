<?php

const LISTING_TYPES = [
    'monolocale'  => 'Monolocale',
    'bilocale'    => 'Bilocale',
    'trilocale'   => 'Trilocale',
    'posto_letto' => 'Posto letto',
];

function listing_type_label(string $type): string
{
    return LISTING_TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

// Every listing of a given type uses the same picture, chosen from its type
function listing_image(string $type): string
{
    $images = [
        'monolocale'  => 'assets/img/listings/monolocale.png',
        'bilocale'    => 'assets/img/listings/bilocale.png',
        'trilocale'   => 'assets/img/listings/trilocale.png',
        'posto_letto' => 'assets/img/listings/posto-letto.png',
    ];
    return $images[$type] ?? '';
}

function eur0($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function eur2($value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function get_user_favorite_ids(mysqli $conn, int $userId): array
{
    $ids = [];
    $stmt = mysqli_prepare($conn, "SELECT listing_id FROM favorites WHERE user_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($result && $row = mysqli_fetch_assoc($result)) {
            $ids[(int) $row['listing_id']] = true;
        }
        mysqli_stmt_close($stmt);
    }
    return $ids;
}

function render_listing_card(array $listing, bool $isFavorite, bool $isLoggedIn, bool $isAdmin = false): void
{
    $id = (int) $listing['id'];
    $typeLabel = listing_type_label($listing['type']);
    $image = listing_image($listing['type']);
    $hasImage = $image !== '';
    $detailUrl = 'listing.php?id=' . $id;
    $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
    ?>
    <div class="col">
        <div class="ac-card h-100 d-flex flex-column">
            <div class="position-relative">
                <a href="<?php echo $detailUrl; ?>" class="d-block"<?php echo $hasImage ? '' : ' aria-label="Apri annuncio: ' . htmlspecialchars($listing['title']) . '"'; ?>>
                    <?php if ($hasImage): ?>
                        <img src="<?php echo htmlspecialchars($image); ?>" class="w-100 ac-photo-cover" style="object-fit: cover;" alt="<?php echo htmlspecialchars($typeLabel . ': ' . $listing['title']); ?>">
                    <?php else: ?>
                        <div class="ac-photo ac-photo-cover" aria-hidden="true">FOTO</div>
                    <?php endif; ?>
                </a>
                <?php if ((int) $listing['verified'] === 1): ?>
                    <span class="badge badge-verified position-absolute top-0 start-0 m-3">&check; Verificato</span>
                <?php endif; ?>
                <?php if (!$isAdmin): ?>
                    <form method="post" action="api/favorite.php" class="ac-fav-form position-absolute top-0 end-0 m-3">
                        <input type="hidden" name="listing_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($currentUrl); ?>">
                        <?php if ($isLoggedIn): ?>
                            <button type="submit" class="ac-heart <?php echo $isFavorite ? 'is-active' : ''; ?>"
                                    aria-pressed="<?php echo $isFavorite ? 'true' : 'false'; ?>"
                                    aria-label="<?php echo $isFavorite ? 'Rimuovi dai salvati' : 'Salva annuncio'; ?>">
                                <span class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></span>
                            </button>
                        <?php else: ?>
                            <a href="login.php" class="ac-heart" aria-label="Accedi per salvare l'annuncio">
                                <span class="far fa-heart" aria-hidden="true"></span>
                            </a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
            <div class="p-3 d-flex flex-column flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="label-mono"><?php echo htmlspecialchars($typeLabel); ?></span>
                </div>
                <h3 class="h6 fw-bold mb-1">
                    <a href="<?php echo $detailUrl; ?>" class="text-decoration-none text-reset stretched-link-none"><?php echo htmlspecialchars($listing['title']); ?></a>
                </h3>
                <p class="text-muted small mb-3">
                    <?php echo htmlspecialchars($listing['zone']); ?> · <?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $listing['distance_km'], 1, '.', ''), '0'), '.')); ?> km dal campus
                </p>
                <div class="d-flex justify-content-between align-items-end mt-auto">
                    <div>
                        <span class="h5 fw-bold text-ac mb-0">&euro;<?php echo eur0($listing['price']); ?></span>
                        <span class="text-muted small">/mese</span>
                    </div>
                    <?php if ((int) $listing['size_sqm'] > 0): ?>
                        <span class="text-muted small"><?php echo (int) $listing['size_sqm']; ?> m&sup2;</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>
