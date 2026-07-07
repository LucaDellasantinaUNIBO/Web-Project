(function () {
    function onlyDigits(value) {
        return value.replace(/\D/g, '');
    }

    document.querySelectorAll('input[name="card_number"]').forEach(function (el) {
        el.setAttribute('maxlength', '19');
        el.addEventListener('input', function () {
            var digits = onlyDigits(el.value).slice(0, 16);
            el.value = digits.replace(/(.{4})/g, '$1 ').trim();
        });
    });

    document.querySelectorAll('input[name="card_expiry"]').forEach(function (el) {
        el.setAttribute('maxlength', '5');
        el.addEventListener('input', function (e) {
            var digits = onlyDigits(el.value).slice(0, 4);
            var deleting = e.inputType === 'deleteContentBackward';
            if (digits.length > 2 || (digits.length === 2 && !deleting)) {
                el.value = digits.slice(0, 2) + '/' + digits.slice(2);
            } else {
                el.value = digits;
            }
        });
    });

    document.querySelectorAll('input[name="card_cvc"]').forEach(function (el) {
        el.setAttribute('maxlength', '4');
        el.addEventListener('input', function () {
            el.value = onlyDigits(el.value).slice(0, 4);
        });
    });
})();
