<?php
include 'includes/auth.php';
require_login();

if (isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin') {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5 text-center" id="main-content">
        <h1 class="fw-bold mb-4">Rent Discount Wheel</h1>
        <p class="text-muted mb-5">Spin daily to win credit towards your rent!</p>

        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="position-relative mx-auto mb-4" style="width: 300px; height: 300px;">
                    <div class="position-absolute start-50 top-0 translate-middle-x z-2"
                        style="width: 0; height: 0; border-left: 15px solid transparent; border-right: 15px solid transparent; border-top: 30px solid #2E8B57;">
                    </div>

                    <div id="wheel"
                        class="w-100 h-100 rounded-circle border border-5 border-light shadow overflow-hidden position-relative"
                        style="transition: transform 4s cubic-bezier(0.25, 0.1, 0.25, 1); background: conic-gradient(
                    #f8f9fa 0deg 60deg,
                    #e9ecef 60deg 120deg,
                    #dee2e6 120deg 180deg,
                    #ced4da 180deg 240deg,
                    #adb5bd 240deg 300deg,
                    #6c757d 300deg 360deg
                );">
                        <div class="position-absolute w-100 h-100 d-flex justify-content-center align-items-center">
                            <span class="h1 fw-bold text-muted opacity-25 fas fa-home"></span>
                        </div>
                    </div>
                </div>

                <button id="spinBtn" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm">SPIN!</button>

                <div id="result" class="mt-4" style="display: none;">
                    <div class="alert alert-success fs-5 fw-bold animate-pop fas fa-trophy me-2">
                        <span id="resultText"></span>
                    </div>
                </div>
                <div id="error" class="mt-4 alert alert-danger" style="display: none;"></div>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const wheel = document.getElementById('wheel');
        const spinBtn = document.getElementById('spinBtn');
        const resultDiv = document.getElementById('result');
        const resultText = document.getElementById('resultText');
        const errorDiv = document.getElementById('error');

        let isSpinning = false;
        let currentRotation = 0;

        spinBtn.addEventListener('click', function () {
            if (isSpinning) return;

            isSpinning = true;
            spinBtn.disabled = true;
            resultDiv.style.display = 'none';
            errorDiv.style.display = 'none';

            fetch('api/spin.php', {
                method: 'POST'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const spins = 5;
                        const degrees = 360 * spins + Math.floor(Math.random() * 360);
                        currentRotation += degrees;

                        wheel.style.transform = `rotate(${currentRotation}deg)`;

                        setTimeout(() => {
                            resultText.textContent = data.message;
                            resultDiv.style.display = 'block';
                            isSpinning = false;
                            spinBtn.disabled = false;
                        }, 4000);
                    } else {
                        showError(data.message || 'Error occurred');
                    }
                })
                .catch(err => {
                    showError('Connection error');
                });
        });

        function showError(msg) {
            errorDiv.textContent = msg;
            errorDiv.style.display = 'block';
            isSpinning = false;
            spinBtn.disabled = false;
        }
    });
</script>

<?php include 'includes/footer.php'; ?>