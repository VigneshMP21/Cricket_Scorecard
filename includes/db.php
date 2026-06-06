<?php
// includes/db.php - Simplified Database Connection
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/CPT_LEAGUE/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Load environment variables
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        try {
            $dotenv->load();
        } catch (Exception $e) {
            // Silently fail if .env is missing or invalid
        }
    }
}

// Database connection
$host = $_ENV['DB_HOST'] ?? "sql103.infinityfree.com"; 
$user = $_ENV['DB_USER'] ?? "if0_40705850"; 
$pass = $_ENV['DB_PASS'] ?? "oOqr1j4vNab"; 
$dbname = $_ENV['DB_NAME'] ?? "if0_40705850_gully_cricket_db"; 

$conn = mysqli_connect($host, $user, $pass, $dbname);
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Database connection error. Please try again later.");
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Auto-Login logic using "Remember Me" token
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
        $parts = explode(':', $_COOKIE['remember_token']);
        if (count($parts) === 2) {
            $selector = $parts[0];
            $validator = $parts[1];

            $auth_stmt = $pdo->prepare("SELECT rt.id, rt.user_id, rt.validator_hash, u.name, u.email, u.role, u.profile_image 
                                   FROM remember_tokens rt 
                                   JOIN users u ON rt.user_id = u.id 
                                   WHERE rt.selector = ? AND rt.expires_at > NOW()");
            $auth_stmt->execute([$selector]);
            $auth_row = $auth_stmt->fetch(PDO::FETCH_ASSOC);

            if ($auth_row && hash_equals($auth_row['validator_hash'], hash('sha256', $validator))) {
                // Restore session variables
                $_SESSION['user_id'] = $auth_row['user_id'];
                $_SESSION['user_name'] = $auth_row['name'];
                $_SESSION['user_email'] = $auth_row['email'];
                $_SESSION['role'] = $auth_row['role'];
                $_SESSION['profile_image'] = $auth_row['profile_image'];

                // Token Rotation (Security Best Practice)
                $new_selector = bin2hex(random_bytes(12));
                $new_validator = bin2hex(random_bytes(32));
                $new_val_hash = hash('sha256', $new_validator);
                $new_expiry = date("Y-m-d H:i:s", strtotime("+30 days"));

                $upd_stmt = $pdo->prepare("UPDATE remember_tokens SET selector = ?, validator_hash = ?, expires_at = ? WHERE id = ?");
                $upd_stmt->execute([$new_selector, $new_val_hash, $new_expiry, $auth_row['id']]);

                setcookie('remember_token', $new_selector . ':' . $new_validator, time() + (30 * 24 * 60 * 60), '/', '', false, true);
            } else {
                // Invalid or expired token -> clear cookie
                setcookie('remember_token', '', time() - 3600, '/');
            }
        }
    }
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Function to require login
function require_login()
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: /CPT_LEAGUE/login/login.php");
        exit();
    }
}

// Sanitize input function
function sanitize_input($data)
{
    return trim(htmlspecialchars($data));
}
?>