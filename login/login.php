<?php
// login/login.php
// User login page

require_once '../includes/db.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $base_url = "http://" . $_SERVER['HTTP_HOST'] . "/CPT_LEAGUE/";
    $role = $_SESSION['role'];
    if ($role == 'admin') {
        header("Location: " . $base_url . "admin/admin_dashboard.php");
    } elseif ($role == 'player') {
        header("Location: " . $base_url . "player/player_dashboard.php");
    } else {
        header("Location: " . $base_url . "audience/audience_dashboard.php");
    }
    exit();
}

// Initialize variables
$email = isset($_COOKIE['remember_email']) ? $_COOKIE['remember_email'] : '';
$password = '';
$error = '';
$success = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    // Validate inputs
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        // Check user in database using PDO for consistency
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, password, role, profile_image FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // Verify password
                if (password_verify($password, $row['password'])) {
                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['user_name'] = $row['name'];
                    $_SESSION['user_email'] = $row['email'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['profile_image'] = $row['profile_image'];

                    // Handle Persistent "Remember me" (Auto-Login with Selector:Validator)
                    if (isset($_POST['remember'])) {
                        $selector = bin2hex(random_bytes(12));
                        $validator = bin2hex(random_bytes(32));
                        $validator_hash = hash('sha256', $validator);
                        $expiry = date("Y-m-d H:i:s", strtotime("+30 days"));

                        // Store selector and validator hash in DB
                        try {
                            $token_stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)");
                            $token_stmt->execute([$row['id'], $selector, $validator_hash, $expiry]);

                            // Cookie: selector:validator (Secure, HttpOnly)
                            setcookie('remember_token', $selector . ':' . $validator, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                        } catch (PDOException $e) {
                            // Error logging token (non-critical for login)
                        }
                    }

                    // Force session write
                     session_write_close();

                    // 🔔 Link OneSignal Player ID if provided
                    $onesignal_player_id = sanitize_input($_POST['onesignal_player_id'] ?? '');
                    if (!empty($onesignal_player_id)) {
                        try {
                            $dev_stmt = $pdo->prepare("
                                INSERT INTO user_devices (user_id, onesignal_player_id) 
                                VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE user_id = ?, updated_at = NOW()
                            ");
                            $dev_stmt->execute([$row['id'], $onesignal_player_id, $row['id']]);

                            // Store in session for cleanup on logout
                            $_SESSION['player_id'] = $onesignal_player_id;
                        } catch (PDOException $e) {
                            // Non-blocking error
                            error_log("OneSignal Link Error: " . $e->getMessage());
                        }
                    }

                     // Use absolute URLs for redirect
                    $base_url = "http://" . $_SERVER['HTTP_HOST'] . "/CPT_LEAGUE/";
                    if ($row['role'] == 'admin') {
                        header("Location: " . $base_url . "admin/admin_dashboard.php");
                    } elseif ($row['role'] == 'player') {
                        header("Location: " . $base_url . "player/player_dashboard.php");
                    } else {
                        header("Location: " . $base_url . "audience/audience_dashboard.php");
                    }
                    exit();
                } else {
                    $error = "Password is invalid";
                }
            } else {
                $error = "Email ID is incorrect";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';
?>

<style>
    .login-container {
        min-height: calc(100vh - 76px);
        /* Adjust based on header height */
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        position: relative;
        overflow: hidden;
    }

    .login-bg-accent {
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        background-image:
            radial-gradient(circle at 10% 10%, rgba(59, 130, 246, 0.05) 0%, transparent 40%),
            radial-gradient(circle at 90% 90%, rgba(245, 158, 11, 0.05) 0%, transparent 40%);
        pointer-events: none;
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.8);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        border-radius: 24px;
        overflow: hidden;
        width: 100%;
        max-width: 450px;
        position: relative;
        z-index: 10;
        transition: transform 0.3s ease;
    }

    .glass-card:hover {
        transform: translateY(-5px);
    }

    .login-header {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        padding: 2.5rem 2rem;
        text-align: center;
        border-bottom: 4px solid #f59e0b;
    }

    .login-icon-box {
        width: 70px;
        height: 70px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .form-floating>.form-control {
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        padding-left: 1rem;
    }

    .form-floating>.form-control:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }

    .btn-login {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border: none;
        padding: 0.8rem;
        border-radius: 12px;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
    }

    .btn-login:hover {
        background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.3);
    }

    .action-link {
        color: #64748b;
        transition: color 0.2s;
        text-decoration: none;
    }

    .action-link:hover {
        color: #0f172a;
        text-decoration: underline;
    }

    .back-home {
        position: absolute;
        top: 20px;
        left: 20px;
        color: #475569;
        text-decoration: none;
        font-size: 0.9rem;
        z-index: 20;
        transition: color 0.2s;
    }

    .back-home:hover {
        color: #0f172a;
    }
</style>

<div class="login-container">
    <div class="login-bg-accent"></div>

    <a href="../../index.php" class="back-home">
        <i class="fas fa-arrow-left me-2"></i>Back to Home
    </a>

    <div class="glass-card">
        <div class="login-header">
            <div class="login-icon-box">
                <i class="fas fa-user-lock fa-2x text-warning"></i>
            </div>
            <h2 class="text-white fw-bold h4 mb-1">Welcome Back</h2>
            <p class="text-white-50 mb-0 small">Sign in to your account</p>
        </div>

        <div class="p-4 p-md-5">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center rounded-3 mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center rounded-3 mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com"
                        value="<?php echo htmlspecialchars($email); ?>" required>
                    <label for="email"><i class="fas fa-envelope me-2 text-muted"></i>Email Address</label>
                </div>

                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password"
                        required>
                    <label for="password"><i class="fas fa-lock me-2 text-muted"></i>Password</label>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" checked>
                        <label class="form-check-label text-muted small" for="remember">
                            Remember me
                        </label>
                    </div>
                    <a href="forgot_password.php" class="action-link small fw-semibold">Forgot Password?</a>
                </div>

                 <div class="d-grid mb-4">
                     <button type="submit" class="btn btn-primary btn-login text-white">
                         Sign In Now <i class="fas fa-arrow-right ms-2"></i>
                     </button>
                 </div>

                <!-- 🔔 Hidden field: OneSignal Player ID -->
                <input type="hidden" id="onesignal_player_id" name="onesignal_player_id" value="">

                 <div class="text-center pt-2">
                    <p class="text-muted small mb-0">Don't have an account?
                        <a href="register.php" class="text-primary fw-bold text-decoration-none">Create Account</a>
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>



<?php require_once '../includes/footer.php'; ?>