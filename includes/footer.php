<?php
// includes/footer.php - Common footer
?>
</main>

<!-- Footer -->
<footer class="cricket-footer">
    <div class="container">
        <div class="row g-4 align-items-center">

            <!-- Left Side: Contact Info -->
            <div class="col-lg-6 mb-4 mb-lg-0">
                <div class="footer-content pe-lg-5">
                    <h3 class="footer-heading mb-4">Get In Touch</h3>

                    <!-- Contact Details -->
                    <div class="contact-info-list">
                        <div class="contact-info-item">
                            <div class="contact-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="contact-details">
                                <h6>Name</h6>
                                <p>M P VIGNESH</p>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="contact-details">
                                <h6>Address</h6>
                                <p>4-50, Bazar street, Chinthala Pattadai, Nagari</p>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="contact-details">
                                <h6>Email</h6>
                                <p>mpvignesh2107@gmail.com</p>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-icon">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div class="contact-details">
                                <h6>Phone</h6>
                                <p>+91-9393211095</p>
                            </div>
                        </div>
                    </div>

                    <!-- Social Media Icons -->
                    <div class="social-links">
                        <a href="https://www.instagram.com/vignesh_mp_06/" class="social-btn instagram" title="Instagram" target="_blank"><i class="fab fa-instagram"></i></a>
                        <a href="https://www.linkedin.com/in/vignesh-m-p-b7a60a373/" class="social-btn linkedin" title="LinkedIn" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                        <a href="https://github.com/VigneshMP21/" class="social-btn github" title="GitHub" target="_blank"><i class="fab fa-github"></i></a>
                        <a href="#" class="social-btn twitter" title="Twitter"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>

            <!-- Right Side: Contact Form -->
            <div class="col-lg-6">
                <div class="footer-form-card">
                    <h3 class="footer-heading mb-4">Suggest Your Problem</h3>
                    <form id="footerContactForm">
                        <div class="form-group">
                            <input type="email" class="footer-input" id="contactEmail" name="email"
                                placeholder="Your Email Address" required>
                        </div>
                        <div class="form-group">
                            <input type="text" class="footer-input" id="contactName" name="name" placeholder="Your Name"
                                required>
                        </div>
                        <div class="form-group">
                            <textarea class="footer-input" id="contactMessage" name="message" rows="4"
                                placeholder="Describe your problem or suggestion..." required></textarea>
                        </div>
                        <button type="submit" class="send-btn" id="sendBtn">
                            <span>Send Mail</span> <i class="fas fa-paper-plane"></i>
                        </button>
                        <div id="formMessage" class="form-message"></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> CPT LEAGUE. Made with <i class="fas fa-heart"></i> for
                cricket lovers. <a id="privacy-link" href="privacy-policy.php" aria-label="Privacy Policy">Privacy Policy</a></p>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="/CPT_LEAGUE/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- jQuery (optional, for additional features) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Cropper.js JS -->
<script src="/CPT_LEAGUE/assets/vendor/cropperjs/cropper.min.js"></script>

<!-- Custom JavaScript -->
<script src="/CPT_LEAGUE/assets/js/script.js"></script>

<!-- Additional Scripts -->
<script>
    // Enable Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Contact Form Handling
    $(document).ready(function () {
        $('#footerContactForm').on('submit', function (e) {
            e.preventDefault();

            const btn = $('#sendBtn');
            const originalText = btn.html();
            const messageBox = $('#formMessage');

            // Client-side validation
            const email = $('#contactEmail').val().trim();
            const name = $('#contactName').val().trim();
            const message = $('#contactMessage').val().trim();

            // Regex for name validation (letters and spaces only)
            const nameRegex = /^[a-zA-Z\s]+$/;

            if (!email || !name || !message) {
                messageBox.removeClass('success').addClass('error').text('Please fill in all fields.').slideDown();
                return;
            }

            if (!nameRegex.test(name)) {
                messageBox.removeClass('success').addClass('error').text('Name should only contain letters and spaces.').slideDown();
                return;
            }

            // Disable button and show loading state
            btn.prop('disabled', true).html('<span>Sending...</span> <i class="fas fa-spinner fa-spin"></i>');
            messageBox.slideUp();

            $.ajax({
                url: '/CPT_LEAGUE/includes/send_feedback.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    email: email,
                    name: name,
                    message: message
                },
                success: function (response) {
                    if (response.status === 'success') {
                        messageBox.removeClass('error').addClass('success').text(response.message).slideDown();
                        $('#footerContactForm')[0].reset();
                    } else {
                        messageBox.removeClass('success').addClass('error').text(response.message).slideDown();
                    }
                },
                error: function () {
                    messageBox.removeClass('success').addClass('error').text('An error occurred. Please try again.').slideDown();
                },
                complete: function () {
                    btn.prop('disabled', false).html(originalText);

                    // Auto hide success message after 5 seconds
                    if (messageBox.hasClass('success')) {
                        setTimeout(function () {
                            messageBox.slideUp();
                        }, 5000);
                    }
                }
            });
        });
    });

    // Global Team Request Polling for Admin
    function updateGlobalRequestBadge() {
        const badge = document.getElementById('global-request-badge');
        if (!badge) return;

        fetch('/CPT_LEAGUE/NavBarList/get_request_count.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    badge.textContent = data.count;
                    if (data.count > 0) {
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }
            })
            .catch(err => console.error('Error fetching request count:', err));
    }

    if (document.getElementById('global-request-badge')) {
        updateGlobalRequestBadge(); // Initial call
        setInterval(updateGlobalRequestBadge, 3000); // Poll every 30s
    }

    // 🔔 Global OneSignal Player ID Capture (Android App Only)
    $(document).ready(function() {
        if (typeof Android !== 'undefined' && typeof Android.getPlayerId === 'function') {
            const pid = Android.getPlayerId();
            if (pid && pid.length > 0) {
                // 1. Populate any hidden input with id='onesignal_player_id' (useful for login/register forms)
                const hiddenField = document.getElementById('onesignal_player_id');
                if (hiddenField) {
                    hiddenField.value = pid;
                    console.log('Populated hidden field onesignal_player_id with ' + pid);
                }

                // 2. Send to backend to track (handles guest and logged-in users)
                // Use a small delay to ensure session is active if just redirected
                setTimeout(function() {
                    console.log('Syncing Player ID: ' + pid);
                    fetch('//' + window.location.host + '/CPT_LEAGUE/save_player_id.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ player_id: pid })
                    })
                    .then(response => response.json())
                    .then(data => console.log('OneSignal Sync Success:', data))
                    .catch(err => console.error('OneSignal Sync Error:', err));
                }, 1000);
            }
        }
    });
</script>
<script>
    // Ensure footer privacy link works reliably on mobile
    document.addEventListener('DOMContentLoaded', function () {
        var pl = document.getElementById('privacy-link');
        if (!pl) return;

        // Make link more prominent on mobile with clear styling
        pl.style.display = 'inline-block';
        pl.style.padding = '8px 15px';
        pl.style.marginTop = '8px';
        pl.style.borderRadius = '4px';
        pl.style.textDecoration = 'underline';
        pl.style.cursor = 'pointer';
        pl.style.position = 'relative';
        pl.style.zIndex = '100';

        // Ensure the link is always clickable
        pl.style.pointerEvents = 'auto';

        // Direct navigation using JavaScript to be extra safe
        pl.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = 'privacy-policy.php';
        });

        pl.addEventListener('touchend', function (e) {
            e.preventDefault();
            window.location.href = 'privacy-policy.php';
        });
    });
</script>
</body>

</html>
<?php
ob_end_flush();
?>
