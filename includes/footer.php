<footer class="ac-footer py-4 mt-auto">
    <div class="container text-center">
        <p class="text-muted mb-1">Continuando accetti i Termini e l'Informativa privacy.</p>
        <p class="text-muted small mb-0">AlmaCasa non è affiliata ufficialmente con l'Ateneo. &copy; 2026 · Progetto di Tecnologie Web</p>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/confirm-modal.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ac-fav-form').forEach(function (form) {
        var button = form.querySelector('button.ac-heart');
        if (!button) {
            return;
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            fetch('api/favorite.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        if (data.message) { window.location.href = 'login.php'; }
                        return;
                    }
                    var icon = button.querySelector('span');
                    button.classList.toggle('is-active', data.saved);
                    button.setAttribute('aria-pressed', data.saved ? 'true' : 'false');
                    button.setAttribute('aria-label', data.saved ? 'Rimuovi dai salvati' : 'Salva annuncio');
                    if (icon) {
                        icon.className = (data.saved ? 'fas' : 'far') + ' fa-heart';
                    }
                })
                .catch(function () { form.submit(); });
        });
    });
});
</script>
</body>
</html>
