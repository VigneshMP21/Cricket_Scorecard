<?php
// login/forgot_password.php
// OTP-based Forgot Password page

require_once '../includes/db.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$email = '';
$otp = '';
$new_password = '';
$confirm_password = '';
$error = '';
$success = '';
$otp_sent = '';
$show_otp_form = false;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['send_otp'])) {
            // Step 1: Send OTP
            $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));

            if (empty($email)) {
                $error = "Please enter your email address";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format";
            } else {
                // Check if email exists
                $sql = "SELECT id, name FROM users WHERE email = ?";
                $stmt = mysqli_prepare($conn, $sql);
                if (!$stmt) {
                    throw new Exception("Database prepare failed: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt, "s", $email);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Database execute failed: " . mysqli_stmt_error($stmt));
                }
                $result = mysqli_stmt_get_result($stmt);

                if ($row = mysqli_fetch_assoc($result)) {
                    // Generate 6-digit OTP
                    $otp_code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    // Save OTP to database
                    $update_sql = "UPDATE users SET otp_code = ?, otp_expiry = ? WHERE email = ?";
                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    if (!$update_stmt) {
                        throw new Exception("OTP update prepare failed: " . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($update_stmt, "sss", $otp_code, $otp_expiry, $email);

                    if (mysqli_stmt_execute($update_stmt)) {
                        // Send email
                        if (sendOTPEmail($email, $row['name'], $otp_code)) {
                            $otp_sent = "OTP has been sent to your email. Please check your inbox.";
                            $show_otp_form = true;
                        } else {
                            $error = "Failed to send OTP email. Please try again.";
                        }
                    } else {
                        throw new Exception("OTP update execute failed: " . mysqli_stmt_error($update_stmt));
                    }
                    mysqli_stmt_close($update_stmt);
                } else {
                    $error = "Email not found in our system";
                }
                mysqli_stmt_close($stmt);
            }
        } elseif (isset($_POST['verify_reset'])) {
            // Step 2: Verify OTP and reset password
            $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
            $otp = trim($_POST['otp'] ?? '');
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Validate inputs
            if (empty($email) || empty($otp) || empty($new_password) || empty($confirm_password)) {
                $error = "All fields are required";
            } elseif (strlen($new_password) < 6) {
                $error = "Password must be at least 6 characters";
            } elseif ($new_password !== $confirm_password) {
                $error = "Passwords do not match";
            } else {
                // Verify OTP - TRIM the stored OTP before comparison
                $sql = "SELECT id, otp_code, otp_expiry FROM users WHERE email = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $user_id, $stored_otp, $stored_expiry);
                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);

                // Debug logging
                error_log("Email: $email");
                error_log("Input OTP: '$otp'");
                error_log("Stored OTP: '$stored_otp'");
                error_log("Stored Expiry: '$stored_expiry'");
                error_log("Current Time: " . date('Y-m-d H:i:s'));

                // Check if OTP exists, matches, and is not expired
                if ($stored_otp && trim($stored_otp) === trim($otp) && $stored_expiry > date('Y-m-d H:i:s')) {
                    // OTP is valid, reset password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    $update_sql = "UPDATE users SET password = ?, otp_code = NULL, otp_expiry = NULL WHERE email = ?";
                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "ss", $hashed_password, $email);

                    if (mysqli_stmt_execute($update_stmt)) {
                        // Store email for confirmation before clearing it
                        $confirmation_email = $email;

                        // Send confirmation email BEFORE clearing variables
                        $email_sent = sendPasswordResetConfirmation($confirmation_email);

                        $success = "Password has been reset successfully! You can now login.";

                        // Add email notification info if email was sent
                        if ($email_sent) {
                            $success .= " A confirmation email has been sent to your address.";
                        } else {
                            $success .= " (Note: Confirmation email could not be sent, but your password has been reset. You can still login.)";
                        }

                        // Clear all fields AFTER sending email
                        $email = $otp = $new_password = $confirm_password = '';
                        $show_otp_form = false;
                    } else {
                        $error = "Failed to reset password. Please try again.";
                    }
                    mysqli_stmt_close($update_stmt);
                } else {
                    // Detailed error message
                    if (!$stored_otp) {
                        $error = "No OTP found. Please request a new OTP.";
                    } elseif (trim($stored_otp) !== trim($otp)) {
                        $error = "Invalid OTP. Please check and try again.";
                    } elseif ($stored_expiry <= date('Y-m-d H:i:s')) {
                        $error = "OTP has expired. Please request a new OTP.";
                    } else {
                        $error = "Invalid or expired OTP. Please request a new OTP.";
                    }
                    $show_otp_form = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Forgot Password Error: " . $e->getMessage());
        $error = "An internal error occurred. Please try again later.";
    }
}

require_once '../includes/header.php';
?>

<style>
    /* Premium Glassmorphism UI for Forgot Password */
    :root {
        --primary-blue: #2563EB;
        --secondary-indigo: #4F46E5;
        --glass-bg: rgba(255, 255, 255, 0.9);
        --glass-border: rgba(255, 255, 255, 0.8);
        --text-dark: #1e293b;
        --text-muted: #64748b;
    }

    .forgot-password-section {
        min-height: calc(100vh - 80px);
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 2rem 1rem;
        position: relative;
        overflow: hidden;
    }

    .bg-accent {
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        background-image:
            radial-gradient(circle at 80% 10%, rgba(37, 99, 235, 0.05) 0%, transparent 40%),
            radial-gradient(circle at 20% 90%, rgba(79, 70, 229, 0.05) 0%, transparent 40%);
        pointer-events: none;
        z-index: 0;
    }

    .forgot-password-container {
        width: 100%;
        max-width: 480px;
        position: relative;
        z-index: 1;
    }

    .forgot-password-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08);
        padding: 45px 40px;
        overflow: hidden;
    }

    .forgot-password-header {
        text-align: center;
        margin-bottom: 35px;
    }

    .forgot-password-title {
        font-size: 32px;
        font-weight: 800;
        color: var(--text-dark);
        margin-bottom: 12px;
        letter-spacing: -0.5px;
        background: linear-gradient(135deg, var(--primary-blue), var(--secondary-indigo));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .forgot-password-subtitle {
        color: var(--text-muted);
        font-size: 15px;
        line-height: 1.5;
    }

    .step-indicator {
        display: flex;
        justify-content: center;
        margin-bottom: 35px;
        gap: 60px;
        position: relative;
    }

    .step-indicator::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 20%;
        right: 20%;
        height: 2px;
        background: #e2e8f0;
        z-index: 0;
    }

    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        z-index: 1;
        position: relative;
    }

    .step-circle {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: white;
        border: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 10px;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .step.active .step-circle {
        background: linear-gradient(135deg, var(--primary-blue), var(--secondary-indigo));
        border-color: transparent;
        color: white;
        transform: scale(1.1);
        box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
    }

    .step-label {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .step.active .step-label {
        color: var(--primary-blue);
    }

    .form-group {
        margin-bottom: 24px;
        position: relative;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
        margin-left: 2px;
    }

    .form-control {
        width: 100%;
        padding: 14px 18px;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        font-size: 16px;
        transition: all 0.3s ease;
        background: #f8fafc;
        color: var(--text-dark);
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-blue);
        background: white;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }

    .password-field {
        position: relative;
    }

    .toggle-password {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.2s;
    }

    .toggle-password:hover {
        color: var(--primary-blue);
    }

    .btn-primary {
        width: 100%;
        padding: 15px;
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-indigo) 100%);
        border: none;
        border-radius: 14px;
        color: white;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 20px -5px rgba(37, 99, 235, 0.4);
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    .btn-secondary {
        width: 100%;
        padding: 13px;
        background: transparent;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        color: var(--text-muted);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-secondary:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: var(--text-dark);
    }

    .alert {
        padding: 14px 18px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideDown 0.4s ease;
    }

    @keyframes slideDown {
        from {
            transform: translateY(-10px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .alert-success {
        background-color: #f0fdf4;
        border: 1px solid #bbfcce;
        color: #166534;
    }

    .alert-danger {
        background-color: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    .resend-otp {
        text-align: center;
        margin-top: 20px;
        font-size: 14px;
        color: var(--text-muted);
    }

    .resend-otp a {
        color: var(--primary-blue);
        text-decoration: none;
        font-weight: 700;
        margin-left: 5px;
    }

    .resend-otp a:hover {
        text-decoration: underline;
    }

    .back-to-login {
        text-align: center;
        margin-top: 30px;
        padding-top: 25px;
        border-top: 1px solid #f1f5f9;
    }

    .back-to-login a {
        color: var(--text-muted);
        text-decoration: none;
        font-weight: 600;
        font-size: 15px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .back-to-login a:hover {
        color: var(--primary-blue);
    }

    /* Mobile adjustments */
    @media (max-width: 480px) {
        .forgot-password-card {
            padding: 35px 25px;
            border-radius: 20px;
        }

        .forgot-password-title {
            font-size: 26px;
        }
    }
</style>

<!-- Forgot Password Section -->
<section class="forgot-password-section">
    <div class="bg-accent"></div>
    <div class="forgot-password-container">
        <div class="forgot-password-card shadow-2xl">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo !$show_otp_form && empty($success) ? 'active' : ''; ?>">
                    <div class="step-circle">1</div>
                    <div class="step-label">Identify</div>
                </div>
                <div class="step <?php echo $show_otp_form ? 'active' : ''; ?>">
                    <div class="step-circle">2</div>
                    <div class="step-label">Reset</div>
                </div>
            </div>

            <div class="forgot-password-header">
                <h1 class="forgot-password-title">
                    <?php echo $show_otp_form ? 'Reset Password' : 'Forgot Password?'; ?>
                </h1>
                <p class="forgot-password-subtitle">
                    <?php echo $show_otp_form
                        ? 'We\'ve sent a verification code to your email. Please enter it below to set your new password.'
                        : 'No worries! Enter your registered email address and we\'ll send you instructions to reset your password.'; ?>
                </p>
            </div>

            <!-- Error/Success Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($otp_sent): ?>
                <div class="alert alert-success">
                    <i class="fas fa-paper-plane"></i>
                    <span><?php echo $otp_sent; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>

                <?php if (strpos($success, 'reset successfully') !== false): ?>
                    <div class="text-center mt-6">
                        <a href="login.php" class="btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Continue to Login
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Step 1: Enter Email Form -->
            <?php if (!$show_otp_form && empty($success)): ?>
                <form id="emailForm" method="POST" action="">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email"
                            value="<?php echo htmlspecialchars($email); ?>" required placeholder="e.g. name@example.com">
                    </div>

                    <button type="submit" name="send_otp" class="btn-primary">
                        <span>Send Instructions</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>
            <?php endif; ?>

            <!-- Step 2: OTP Verification and New Password Form -->
            <?php if ($show_otp_form): ?>
                <form id="resetForm" method="POST" action="">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                    <!-- OTP Input -->
                    <div class="form-group">
                        <label for="otp" class="form-label">Verification Code (6-Digits)</label>
                        <input type="text" class="form-control text-center" id="otp" name="otp" maxlength="6" required
                            placeholder="· · · · · ·" pattern="[0-9]{6}"
                            style="font-size: 24px; letter-spacing: 8px; font-weight: 700;">
                        <div class="resend-otp">
                            Didn't receive the code?
                            <a href="#" onclick="resendOTP(); return false;">Resend Code</a>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="password-field">
                            <input type="password" class="form-control" id="new_password" name="new_password" required
                                minlength="6" placeholder="Create a strong password">
                            <button class="toggle-password" type="button" id="toggleNewPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="password-field">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required minlength="6" placeholder="Repeat your new password">
                            <button class="toggle-password" type="button" id="toggleConfirmPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Match Indicator -->
                    <div id="passwordMatch" style="margin-bottom: 20px;"></div>

                    <button type="submit" name="verify_reset" class="btn-primary">
                        <i class="fas fa-shield-alt"></i> Reset Password
                    </button>

                    <button type="button" class="btn-secondary" onclick="window.location.href='forgot_password.php'">
                        <i class="fas fa-undo-alt"></i> Change Email
                    </button>
                </form>
            <?php endif; ?>

            <div class="back-to-login">
                <a href="login.php">
                    <i class="fas fa-long-arrow-alt-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>
</section>


<script>
    // Password visibility toggle
    document.getElementById('toggleNewPassword')?.addEventListener('click', function () {
        const passwordField = document.getElementById('new_password');
        const icon = this.querySelector('i');
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordField.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });

    document.getElementById('toggleConfirmPassword')?.addEventListener('click', function () {
        const passwordField = document.getElementById('confirm_password');
        const icon = this.querySelector('i');
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordField.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });

    // Password match checker
    document.getElementById('new_password')?.addEventListener('input', checkPasswordMatch);
    document.getElementById('confirm_password')?.addEventListener('input', checkPasswordMatch);

    function checkPasswordMatch() {
        const password = document.getElementById('new_password')?.value || '';
        const confirmPassword = document.getElementById('confirm_password')?.value || '';
        const matchDiv = document.getElementById('passwordMatch');

        if (!matchDiv) return;

        if (confirmPassword === '') {
            matchDiv.innerHTML = '';
            return;
        }

        if (password === confirmPassword) {
            matchDiv.innerHTML = '<div style="color: #059669; font-size: 14px; background: #d1fae5; padding: 8px 12px; border-radius: 6px; border: 1px solid #a7f3d0;"><i class="fas fa-check-circle me-2"></i>Passwords match</div>';
        } else {
            matchDiv.innerHTML = '<div style="color: #dc2626; font-size: 14px; background: #fee2e2; padding: 8px 12px; border-radius: 6px; border: 1px solid #fecaca;"><i class="fas fa-times-circle me-2"></i>Passwords do not match</div>';
        }
    }

    // OTP input validation
    document.getElementById('otp')?.addEventListener('input', function (e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Resend OTP function
    function resendOTP() {
        const email = document.querySelector('input[name="email"]').value;
        const resendLink = event.target.closest('a');
        const originalText = resendLink.innerHTML;

        // Disable link and show loading
        resendLink.style.pointerEvents = 'none';
        resendLink.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

        // Send AJAX request to resend OTP
        const formData = new FormData();
        formData.append('email', email);
        formData.append('resend_otp', '1');

        fetch('forgot_password.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.text())
            .then(data => {
                // Extract alert message from response
                const parser = new DOMParser();
                const doc = parser.parseFromString(data, 'text/html');
                const alert = doc.querySelector('.alert');

                if (alert) {
                    // Show message as toast
                    showToast(alert.textContent.trim(), alert.classList.contains('alert-success') ? 'success' : 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to resend OTP. Please try again.', 'error');
            })
            .finally(() => {
                // Re-enable link after 30 seconds (reduced from 60)
                setTimeout(() => {
                    resendLink.style.pointerEvents = 'auto';
                    resendLink.innerHTML = '<i class="fas fa-redo me-1"></i>Resend OTP';
                }, 30000);

                // Show countdown (reduced from 60 to 30 seconds)
                let seconds = 30;
                const interval = setInterval(() => {
                    resendLink.innerHTML = `<i class="fas fa-clock me-1"></i>Resend (${seconds}s)`;
                    seconds--;
                    if (seconds < 0) {
                        clearInterval(interval);
                        resendLink.style.pointerEvents = 'auto';
                        resendLink.innerHTML = '<i class="fas fa-redo me-1"></i>Resend OTP';
                    }
                }, 1000);
            });
    }

    // Toast notification function
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';

        toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        background: ${bgColor};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        animation: slideIn 0.3s ease;
    `;

        toast.innerHTML = `
        <i class="fas fa-${icon} me-3"></i>
        <span>${message}</span>
    `;

        document.body.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    // Add keyframes for animation
    const style = document.createElement('style');
    style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
    document.head.appendChild(style);

    // Form validation
    document.getElementById('resetForm')?.addEventListener('submit', function (e) {
        const otp = document.getElementById('otp').value;
        const password = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        // OTP validation
        if (!/^\d{6}$/.test(otp)) {
            e.preventDefault();
            showToast('Please enter a valid 6-digit OTP', 'error');
            return false;
        }

        // Password validation
        if (password.length < 6) {
            e.preventDefault();
            showToast('Password must be at least 6 characters', 'error');
            return false;
        }

        if (password !== confirmPassword) {
            e.preventDefault();
            showToast('Passwords do not match', 'error');
            return false;
        }

        return true;
    });
</script>

<?php require_once '../includes/footer.php'; ?>

<?php
/**
 * Send OTP email
 */
function sendOTPEmail($email, $name, $otp_code)
{
    try {
        // Check if PHPMailer is available
        if (file_exists('../vendor/autoload.php')) {
            require_once '../vendor/autoload.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'caiofficial03@gmail.com';
            $mail->Password = 'fievznjmgpxksowc';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('noreply@cptcricket.com', 'CPT Cricket Scorecard');
            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset OTP - CPT Cricket Scorecard';

            $current_year = date('Y');
            $expiry_time = date('h:i A', strtotime('+10 minutes'));

            $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #374151; margin: 0; padding: 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #2563EB, #4F46E5); padding: 40px 20px; text-align: center; color: white; }
        .logo { font-size: 32px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.5px; }
        .content { padding: 40px; }
        .content h2 { color: #111827; margin-top: 0; font-size: 20px; font-weight: 600; }
        .otp-container { background: #f9fafb; border: 2px dashed #e5e7eb; border-radius: 12px; padding: 30px; text-align: center; margin: 30px 0; }
        .otp-code { font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; color: #2563EB; letter-spacing: 8px; margin: 0; }
        .expiry-notice { font-size: 14px; color: #6B7280; margin-top: 10px; }
        .security-box { background: #FFFBEB; border-left: 4px solid #F59E0B; padding: 20px; border-radius: 4px; margin: 30px 0; }
        .security-box h3 { margin: 0 0 10px 0; font-size: 16px; color: #92400E; display: flex; align-items: center; }
        .security-box p { margin: 0; font-size: 14px; color: #B45309; }
        .footer { background: #F9FAFB; padding: 30px; text-align: center; color: #9CA3AF; font-size: 13px; border-top: 1px solid #F3F4F6; }
        .footer p { margin: 5px 0; }
        .social-links { margin-top: 20px; }
        .social-links a { color: #2563EB; text-decoration: none; margin: 0 10px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🏏</div>
            <h1>CPT Cricket Scorecard</h1>
            <p style="margin: 5px 0 0; opacity: 0.9;">Secure Access Verification</p>
        </div>
        
        <div class="content">
            <h2>Hello {$name},</h2>
            <p>We received a request to reset the password for your CPT Cricket Scorecard account. Use the code below to proceed:</p>
            
            <div class="otp-container">
                <div class="otp-code">{$otp_code}</div>
                <div class="expiry-notice">Valid for 10 minutes (until {$expiry_time})</div>
            </div>
            
            <div class="security-box">
                <h3>⚠️ Security Reminder</h3>
                <p>Never share this code with anyone. CPT Support will never ask for your OTP or password via email or phone.</p>
            </div>
            
            <p>If you didn't request a password reset, you can safely ignore this email. Your account remains secure.</p>
            
            <p style="margin-top: 30px;">Best regards,<br><strong>The CPT Team</strong></p>
        </div>
        
        <div class="footer">
            <p><strong>CPT Cricket Scorecard</strong></p>
            <p>Track. Play. Celebrate.</p>
            <p>&copy; {$current_year} CPT Cricket. All rights reserved.</p>
            <p style="margin-top: 15px; font-size: 11px; opacity: 0.7;">This is an automated security notification. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
HTML;

            $mail->AltBody = "Password Reset OTP: {$otp_code}\n\nThis OTP is valid for 10 minutes. Do not share it with anyone.\n\nIf you didn't request this, please ignore this email.";

            return $mail->send();
        } else {
            // PHPMailer not available, return false
            return false;
        }

    } catch (Exception $e) {
        error_log("OTP email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset confirmation email
 */
function sendPasswordResetConfirmation($email)
{
    try {
        if (file_exists('../vendor/autoload.php')) {
            require_once '../vendor/autoload.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'caiofficial03@gmail.com';
            $mail->Password = 'fievznjmgpxksowc';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('noreply@cptcricket.com', 'CPT Cricket Scorecard');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Successful - CPT Cricket Scorecard';

            $current_year = date('Y');
            $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #374151; margin: 0; padding: 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #059669, #10B981); padding: 40px 20px; text-align: center; color: white; }
        .logo { font-size: 32px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.5px; }
        .content { padding: 40px; text-align: center; }
        .success-icon { font-size: 48px; color: #10B981; margin-bottom: 20px; }
        .content h2 { color: #111827; margin-top: 0; font-size: 20px; font-weight: 600; }
        .info-card { background: #f9fafb; border-radius: 12px; padding: 25px; text-align: left; margin: 30px 0; }
        .info-card h3 { margin: 0 0 15px 0; font-size: 16px; color: #374151; border-bottom: 1px solid #e5e7eb; pb: 10px; }
        .info-card ul { margin: 0; padding-left: 20px; color: #4B5563; font-size: 14px; }
        .info-card li { margin-bottom: 8px; }
        .btn { display: inline-block; background: #2563EB; color: white; padding: 14px 35px; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 20px; transition: background 0.3s ease; }
        .footer { background: #F9FAFB; padding: 30px; text-align: center; color: #9CA3AF; font-size: 13px; border-top: 1px solid #F3F4F6; }
        .footer p { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🏏</div>
            <h1>CPT Cricket Scorecard</h1>
            <p style="margin: 5px 0 0; opacity: 0.9;">Password Successfully Reset</p>
        </div>
        
        <div class="content">
            <div class="success-icon">✅</div>
            <h2>Password Reset Successful!</h2>
            <p>Your account password has been updated. You can now log in using your new credentials.</p>
            
            <a href="http://{$_SERVER['HTTP_HOST']}/CPT_LEAGUE/login/login.php" class="btn">Login to Account</a>
            
            <div class="info-card">
                <h3>🔒 Security Tips</h3>
                <ul>
                    <li>Do not share your password with anyone.</li>
                    <li>Update your password if you suspect any unauthorized access.</li>
                    <li>Use a unique password for this account.</li>
                </ul>
            </div>
            
            <p style="font-size: 14px; color: #6B7280;">If you did not perform this action, please contact our support team immediately.</p>
        </div>
        
        <div class="footer">
            <p><strong>CPT Cricket Scorecard</strong></p>
            <p>&copy; {$current_year} CPT Cricket. All rights reserved.</p>
            <p style="margin-top: 15px; font-size: 11px; opacity: 0.7;">This is an automated confirmation. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
HTML;

            $mail->AltBody = "Your password has been successfully reset. If you did not make this change, please contact support immediately.";

            return $mail->send();
        } else {
            // PHPMailer not available
            return false;
        }

    } catch (Exception $e) {
        error_log("Confirmation email error: " . $e->getMessage());
        return false;
    }
}

// Handle AJAX resend OTP request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resend_otp'])) {
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));

    if (!empty($email)) {
        // Generate new OTP
        $otp_code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $update_sql = "UPDATE users SET otp_code = ?, otp_expiry = ? WHERE email = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "sss", $otp_code, $otp_expiry, $email);

        if (mysqli_stmt_execute($update_stmt)) {
            // Get user name for email
            $name_sql = "SELECT name FROM users WHERE email = ?";
            $name_stmt = mysqli_prepare($conn, $name_sql);
            mysqli_stmt_bind_param($name_stmt, "s", $email);
            mysqli_stmt_execute($name_stmt);
            mysqli_stmt_bind_result($name_stmt, $user_name);
            mysqli_stmt_fetch($name_stmt);
            mysqli_stmt_close($name_stmt);

            if (sendOTPEmail($email, $user_name, $otp_code)) {
                echo '<div class="alert alert-success">New OTP has been sent to your email.</div>';
            } else {
                echo '<div class="alert alert-danger">Failed to send OTP. Please try again.</div>';
            }
        } else {
            echo '<div class="alert alert-danger">Failed to generate new OTP. Please try again.</div>';
        }
        mysqli_stmt_close($update_stmt);
    }
    exit;
}
?>