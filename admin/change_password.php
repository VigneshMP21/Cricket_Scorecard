<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($current_password)) {
        $errors[] = "Current password is required.";
    }

    if (empty($new_password)) {
        $errors[] = "New password is required.";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "New password must be at least 6 characters long.";
    }

    if ($new_password !== $confirm_password) {
        $errors[] = "New password and confirmation do not match.";
    }

    if ($current_password === $new_password) {
        $errors[] = "You must enter a different password because the new password matches the current password.";
    }

    if (empty($errors)) {
        try {
            // Verify current password and fetch name
            $stmt = $pdo->prepare("SELECT name, password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($current_password, $user['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);

                $success = "Password changed successfully!";

                // 🔔 Send Notification
                try {
                    $admin_name = $user['name'] ?? 'Admin';
                    $admin_device_ids = getPlayerIdsForUser($pdo, $user_id);
                    
                    if (!empty($admin_device_ids)) {
                        sendOneSignalNotification(
                            $admin_device_ids,
                            "Dear $admin_name, Your password was changed successfully! 🔐",
                            "Your account password has been updated. If you did not perform this action, please contact support immediately.",
                            ['type' => 'password_changed']
                        );
                    }
                } catch (Exception $no_err) {
                    error_log("Admin Password Change Notification Error: " . $no_err->getMessage());
                }
            } else {
                $errors[] = "Current password is incorrect.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred.";
        }
    }
}

$page_title = "Change Password";
require_once '../includes/header.php';
?>


<style>
/* Reset & Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

:root {
    --primary-color: #dc3545; /* Admin Danger Color */
    --primary-hover: #bb2d3b;
    --text-dark: #1e293b;
    --text-muted: #64748b;
    --glass-bg: rgba(255, 255, 255, 0.95);
    --glass-border: rgba(255, 255, 255, 0.4);
    --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
}

body {
    font-family: 'Inter', sans-serif;
    background-color: #f1f5f9;
}

/* Animated Background */
.page-bg {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
    background: linear-gradient(45deg, #f1f5f9, #cbd5e1, #e2e8f0);
    background-size: 400% 400%;
    animation: gradientBG 15s ease infinite;
}

@keyframes gradientBG {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Main Container */
.auth-container {
    min-height: calc(100vh - 76px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}

/* Glass Card */
.glass-card {
    background: var(--glass-bg);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 24px;
    box-shadow: var(--glass-shadow);
    overflow: hidden;
    width: 100%;
    max-width: 500px;
    transform: translateY(20px);
    opacity: 0;
    animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes slideUpFade {
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Header */
.card-header-custom {
    background: transparent;
    padding: 2.5rem 2rem 1rem;
    text-align: center;
}

.card-header-custom .icon-circle {
    width: 70px;
    height: 70px;
    background: rgba(220, 53, 69, 0.1);
    color: var(--primary-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin: 0 auto 1.5rem;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.15);
}

.card-header-custom h4 {
    color: var(--text-dark);
    font-weight: 700;
    letter-spacing: -0.5px;
    margin-bottom: 0.5rem;
}

.card-header-custom p {
    color: var(--text-muted);
    font-size: 0.95rem;
}

/* Form Styles */
.form-group {
    margin-bottom: 1.5rem;
    position: relative;
}

.form-label {
    font-weight: 500;
    color: var(--text-dark);
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    display: block;
}

.input-group-custom {
    position: relative;
    transition: all 0.3s ease;
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    transition: color 0.3s ease;
    z-index: 10;
}

.form-control-custom {
    width: 100%;
    padding: 12px 16px 12px 48px;
    font-size: 1rem;
    color: var(--text-dark);
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.form-control-custom:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
    outline: none;
}

.form-control-custom:focus + .input-icon,
.form-control-custom:focus ~ .input-icon {
    color: var(--primary-color);
}

.toggle-password {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    font-size: 1rem;
    transition: color 0.3s;
}

.toggle-password:hover {
    color: var(--text-dark);
}

/* Strength Meter */
.strength-meter-container {
    margin-top: 10px;
    background: #e2e8f0;
    height: 4px;
    border-radius: 2px;
    overflow: hidden;
    display: none; /* Hidden by default */
}

.strength-meter-bar {
    height: 100%;
    width: 0;
    transition: width 0.4s ease, background-color 0.4s ease;
}

.strength-text {
    font-size: 0.75rem;
    margin-top: 5px;
    display: block;
    text-align: right;
    font-weight: 600;
}

/* Buttons */
.btn-primary-custom {
    background: var(--primary-color);
    color: white;
    border: none;
    padding: 14px;
    border-radius: 12px;
    font-weight: 600;
    width: 100%;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
}

.btn-primary-custom:hover {
    background: var(--primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(220, 53, 69, 0.35);
}

.btn-primary-custom:active {
    transform: translateY(0);
}

.btn-back {
    display: block;
    text-align: center;
    margin-top: 1.5rem;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 0.9rem;
    transition: color 0.2s;
}

.btn-back:hover {
    color: var(--text-dark);
}

/* Alerts */
.alert-custom {
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.9rem;
    animation: shake 0.5s ease-in-out;
}

.alert-success-custom {
    background: rgba(209, 250, 229, 0.8);
    color: #065f46;
    border: 1px solid rgba(167, 243, 208, 0.5);
}

.alert-danger-custom {
    background: rgba(254, 226, 226, 0.8);
    color: #991b1b;
    border: 1px solid rgba(254, 202, 202, 0.5);
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

/* Footer Guidelines */
.guidelines-box {
    margin-top: 2rem;
    background: rgba(241, 245, 249, 0.5);
    border-radius: 12px;
    padding: 1.25rem;
    border: 1px dashed #cbd5e1;
}

.guideline-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.guideline-item i {
    font-size: 6px; /* small dot */
    color: #94a3b8;
}

.guideline-item.valid {
    color: #10b981;
}

.guideline-item.valid i {
    color: #10b981;
    font-size: 10px;
}

/* Validation Status */
.validation-message {
    font-size: 0.8rem;
    margin-top: 0.25rem;
    min-height: 1.2em;
    opacity: 0;
    transition: opacity 0.2s;
}
.validation-message.show {
    opacity: 1;
}
.text-success-custom { color: #10b981; }
.text-danger-custom { color: #ef4444; }

/* Responsive */
@media (max-width: 576px) {
    .glass-card {
        border-radius: 16px;
    }
    .card-header-custom {
        padding: 2rem 1.5rem 0.5rem;
    }
    .card-body {
        padding: 1.5rem;
    }
}
</style>

<div class="page-bg"></div>

<div class="auth-container">
    <div class="glass-card">
        
        <div class="card-header-custom">
            <div class="icon-circle">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h4>Secure Your Account</h4>
            <p>Update your password to keep your account safe.</p>
        </div>

        <div class="card-body px-4 pb-4">
            
            <!-- Messages -->
            <?php if ($success): ?>
                <div class="alert-custom alert-success-custom">
                    <i class="fas fa-check-circle mt-1"></i>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert-custom alert-danger-custom">
                    <i class="fas fa-exclamation-circle mt-1"></i>
                    <div>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="changePasswordForm" novalidate>
                
                <!-- Current Password -->
                <div class="form-group">
                    <label for="current_password" class="form-label">Current Password</label>
                    <div class="input-group-custom">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" class="form-control-custom" id="current_password" name="current_password" placeholder="Enter current password" required>
                        <button type="button" class="toggle-password" onclick="toggleVisibility('current_password')">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- New Password -->
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group-custom">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-control-custom" id="new_password" name="new_password" placeholder="Create new password" required minlength="6">
                        <button type="button" class="toggle-password" onclick="toggleVisibility('new_password')">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    
                    <!-- Strength Meter -->
                    <div class="strength-meter-container" id="strengthContainer">
                        <div class="strength-meter-bar" id="strengthBar"></div>
                    </div>
                    <span class="strength-text" id="strengthText"></span>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <div class="input-group-custom">
                        <i class="fas fa-check-circle input-icon"></i>
                        <input type="password" class="form-control-custom" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                    </div>
                    <div class="validation-message" id="matchMessage"></div>
                </div>

                <!-- Guidelines -->
                <div class="guidelines-box">
                    <div class="guideline-item" id="rule-length"><i class="fas fa-circle"></i> At least 6 characters</div>
                    <div class="guideline-item" id="rule-upper"><i class="fas fa-circle"></i> One uppercase letter</div>
                    <div class="guideline-item" id="rule-number"><i class="fas fa-circle"></i> One number</div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn-primary-custom" id="submitBtn">
                        <i class="fas fa-check"></i> Update Password
                    </button>
                    
                    <a href="admin_dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
// Toggle Password Visibility
function toggleVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.parentElement.querySelector('.toggle-password i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Real-time Strength Check & Validation
const newPassInput = document.getElementById('new_password');
const confirmPassInput = document.getElementById('confirm_password');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');
const strengthContainer = document.getElementById('strengthContainer');
const matchMessage = document.getElementById('matchMessage');
const rules = {
    length: document.getElementById('rule-length'),
    upper: document.getElementById('rule-upper'),
    number: document.getElementById('rule-number')
};

newPassInput.addEventListener('input', function() {
    const val = this.value;
    strengthContainer.style.display = 'block';
    
    // Evaluate Strength
    let score = 0;
    if (val.length >= 6) score += 1;
    if (/[A-Z]/.test(val)) score += 1;
    if (/[0-9]/.test(val)) score += 1;
    if (/[^a-zA-Z0-9]/.test(val)) score += 1;

    // Update UI Rules
    updateRule(rules.length, val.length >= 6);
    updateRule(rules.upper, /[A-Z]/.test(val));
    updateRule(rules.number, /[0-9]/.test(val));

    // Update Bar
    let color = '#ccc';
    let width = '0%';
    let text = '';
    
    if (val.length === 0) {
        width = '0%';
        text = '';
    } else if (score < 2) {
        color = '#ef4444'; // Red
        width = '25%';
        text = 'Weak';
    } else if (score < 4) {
        color = '#f59e0b'; // Orange
        width = '60%';
        text = 'Medium';
    } else {
        color = '#10b981'; // Green
        width = '100%';
        text = 'Strong';
    }

    strengthBar.style.backgroundColor = color;
    strengthBar.style.width = width;
    
    strengthText.textContent = text;
    strengthText.style.color = color;

    checkMatch();
});

confirmPassInput.addEventListener('input', checkMatch);

function checkMatch() {
    const p1 = newPassInput.value;
    const p2 = confirmPassInput.value;
    
    if (p2.length === 0) {
        matchMessage.classList.remove('show');
        return;
    }
    
    matchMessage.classList.add('show');
    if (p1 === p2) {
        matchMessage.innerHTML = '<i class="fas fa-check-circle me-1"></i> Passwords match';
        matchMessage.className = 'validation-message show text-success-custom';
    } else {
        matchMessage.innerHTML = '<i class="fas fa-times-circle me-1"></i> Passwords do not match';
        matchMessage.className = 'validation-message show text-danger-custom';
    }
}

function updateRule(element, isValid) {
    if (isValid) {
        element.classList.add('valid');
        element.querySelector('i').className = 'fas fa-check-circle';
    } else {
        element.classList.remove('valid');
        element.querySelector('i').className = 'fas fa-circle';
    }
}

// Form Submission Prevention
document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    const current = document.getElementById('current_password').value;
    const newP = newPassInput.value;
    const confirmP = confirmPassInput.value;

    if (!current || !newP || !confirmP) {
        e.preventDefault();
        alert('Please fill in all fields');
        return;
    }
    
    if (newP !== confirmP) {
        e.preventDefault();
        alert('Passwords do not match');
        return;
    }

    if (current === newP) {
        e.preventDefault();
        alert('You must enter a different password because the new password matches the current password.');
        return;
    }

    if (newP.length < 6) {
        e.preventDefault();
        alert('Password is too short');
        return;
    }
});

// Auto-close alerts after 3 seconds
const alerts = document.querySelectorAll('.alert-custom');
alerts.forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            alert.style.display = 'none';
        }, 500);
    }, 3000);
});
</script>

<?php require_once '../includes/footer.php'; ?>