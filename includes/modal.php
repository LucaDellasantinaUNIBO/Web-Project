<?php
if (defined('AC_CONFIRM_MODAL_RENDERED')) {
    return;
}
define('AC_CONFIRM_MODAL_RENDERED', true);
?>
<div class="modal fade" id="ac-confirm-modal" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="ac-confirm-title">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold mb-0" id="ac-confirm-title">Conferma</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="ac-confirm-message"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-ac" id="ac-confirm-ok">Conferma</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('ac-confirm-modal');
    if (!modalEl || !window.bootstrap) { return; }
    var modal = new bootstrap.Modal(modalEl);
    var msgEl = document.getElementById('ac-confirm-message');
    var okBtn = document.getElementById('ac-confirm-ok');
    var pending = null;

    okBtn.addEventListener('click', function () {
        var run = pending;
        pending = null;
        modal.hide();
        if (run) { run(); }
    });
    modalEl.addEventListener('hidden.bs.modal', function () { pending = null; });

    function ask(message, danger, onConfirm) {
        msgEl.textContent = message || "Confermare l'operazione?";
        okBtn.classList.toggle('btn-danger', danger);
        okBtn.classList.toggle('btn-ac', !danger);
        pending = onConfirm;
        modal.show();
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                form.reportValidity();
                return;
            }
            ask(form.dataset.confirm, form.hasAttribute('data-confirm-danger'), function () {
                form.submit();
            });
        });
    });

    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            ask(link.dataset.confirm, link.hasAttribute('data-confirm-danger'), function () {
                window.location.href = link.href;
            });
        });
    });

    document.querySelectorAll('.skip-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var target = document.getElementById((link.getAttribute('href') || '').replace('#', ''));
            if (!target) { return; }
            e.preventDefault();
            target.setAttribute('tabindex', '-1');
            target.focus();
            target.scrollIntoView();
        });
    });
})();
</script>
