<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    header("Location: ../index.php");
    exit();
}

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
    ],
    'url' => ['secure' => true]
]);

function deleteFromCloudinary($publicId) {
    if (!$publicId) return;
    try {
        $uploadApi = new UploadApi();
        $uploadApi->destroy($publicId);
    } catch (Exception $e) {
        error_log("Cloudinary Delete Error: " . $e->getMessage());
    }
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_delete'] ?? '';

    if (empty($password)) {
        $errors[] = "Password is required to delete your account.";
    }

    if ($confirm !== 'yes') {
        $errors[] = "Please confirm that you understand the consequences.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT name, password, profile_image, profile_image_url FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $errors[] = "Incorrect password.";
            } else {
                $img = $user['profile_image'];
                $cloudUrl = $user['profile_image_url'];

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("DELETE FROM players WHERE user_id = ?");
                $stmt->execute([$user_id]);

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);

                $pdo->commit();

                if ($img && file_exists('../uploads/users/' . $img)) {
                    unlink('../uploads/users/' . $img);
                }

                if ($cloudUrl) {
                    $parts = explode('/upload/', $cloudUrl);
                    if (isset($parts[1])) {
                        $publicId = preg_replace('/^v\d+\//', '', $parts[1]);
                        $publicId = preg_replace('/\.[^.]+$/', '', $publicId);
                        deleteFromCloudinary($publicId);
                    }
                }

                session_destroy();

                header("Location: ../login/login.php?account_deleted=1");
                exit();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error occurred. Please try again later.";
            error_log("Delete Account Error: " . $e->getMessage());
        }
    }
}

$page_title = "Delete Account";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

    :root {
        --danger-color: #dc2626;
        --danger-hover: #b91c1c;
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

    .page-bg {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: -1;
        background: linear-gradient(45deg, #fef2f2, #fef9ef, #fce7f3);
        background-size: 400% 400%;
        animation: gradientBG 15s ease infinite;
    }

    @keyframes gradientBG {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    .auth-container {
        min-height: calc(100vh - 76px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: var(--glass-shadow);
        overflow: hidden;
        width: 100%;
        max-width: 520px;
        transform: translateY(20px);
        opacity: 0;
        animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes slideUpFade {
        to { transform: translateY(0); opacity: 1; }
    }

    .card-header-custom {
        background: transparent;
        padding: 2.5rem 2rem 1rem;
        text-align: center;
    }

    .card-header-custom .icon-circle {
        width: 70px;
        height: 70px;
        background: rgba(220, 38, 38, 0.1);
        color: var(--danger-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        margin: 0 auto 1.5rem;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
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

    .warning-box {
        background: rgba(254, 226, 226, 0.6);
        border: 1px solid rgba(254, 202, 202, 0.5);
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .warning-box .warning-title {
        color: var(--danger-color);
        font-weight: 600;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 0.75rem;
    }

    .warning-box ul {
        margin-bottom: 0;
        padding-left: 1.25rem;
    }

    .warning-box ul li {
        color: #7f1d1d;
        font-size: 0.88rem;
        margin-bottom: 6px;
        line-height: 1.4;
    }

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
        border-color: var(--danger-color);
        box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
        outline: none;
    }

    .form-control-custom:focus + .input-icon,
    .form-control-custom:focus ~ .input-icon {
        color: var(--danger-color);
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

    .confirm-check {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 16px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .confirm-check:hover {
        background: #fee2e2;
    }

    .confirm-check input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-top: 2px;
        accent-color: var(--danger-color);
        cursor: pointer;
        flex-shrink: 0;
    }

    .confirm-check label {
        font-size: 0.9rem;
        color: #7f1d1d;
        font-weight: 500;
        cursor: pointer;
        line-height: 1.4;
    }

    .btn-danger-custom {
        background: var(--danger-color);
        color: white;
        border: none;
        padding: 14px;
        border-radius: 12px;
        font-weight: 600;
        width: 100%;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
    }

    .btn-danger-custom:hover {
        background: var(--danger-hover);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(220, 38, 38, 0.35);
    }

    .btn-danger-custom:active {
        transform: translateY(0);
    }

    .btn-danger-custom:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
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
                <i class="fas fa-trash-alt"></i>
            </div>
            <h4>Delete Account</h4>
            <p>This action is permanent and cannot be undone.</p>
        </div>

        <div class="card-body px-4 pb-4">

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

            <div class="warning-box">
                <div class="warning-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Before you proceed, please understand:</span>
                </div>
                <ul>
                    <li>Your profile and all personal data will be permanently removed.</li>
                    <li>Your match statistics, rankings, and records will be deleted.</li>
                    <li>You will be removed from all teams you are part of.</li>
                    <li>This action <strong>cannot</strong> be reversed.</li>
                </ul>
            </div>

            <form method="POST" id="deleteAccountForm" novalidate>

                <div class="form-group">
                    <label for="password" class="form-label">Enter your password to confirm</label>
                    <div class="input-group-custom">
                        <input type="password" class="form-control-custom" id="password" name="password"
                            placeholder="Enter your password" required style="padding-left: 16px;">
                        <button type="button" class="toggle-password" onclick="toggleVisibility('password')">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <div class="confirm-check">
                        <input type="checkbox" id="confirm_delete" name="confirm_delete" value="yes" required>
                        <label for="confirm_delete">I understand that deleting my account is permanent and all my data will be lost.</label>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn-danger-custom" id="submitBtn" disabled>
                        <i class="fas fa-trash-alt"></i> Delete My Account
                    </button>

                    <a href="player_dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
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

    const confirmCheck = document.getElementById('confirm_delete');
    const passwordInput = document.getElementById('password');
    const submitBtn = document.getElementById('submitBtn');

    function toggleSubmit() {
        submitBtn.disabled = !(confirmCheck.checked && passwordInput.value.length > 0);
    }

    confirmCheck.addEventListener('change', toggleSubmit);
    passwordInput.addEventListener('input', toggleSubmit);

    document.getElementById('deleteAccountForm').addEventListener('submit', function (e) {
        if (!confirmCheck.checked) {
            e.preventDefault();
            alert('Please confirm that you understand the consequences.');
            return;
        }
        if (!passwordInput.value) {
            e.preventDefault();
            alert('Please enter your password.');
            return;
        }
        if (!confirm('Are you absolutely sure? This will permanently delete your account and all associated data!')) {
            e.preventDefault();
        }
    });

    const alerts = document.querySelectorAll('.alert-custom');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 500);
        }, 5000);
    });
</script>

<?php require_once '../includes/footer.php'; ?>
