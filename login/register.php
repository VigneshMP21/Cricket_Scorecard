<?php
// login/register.php
// Player registration page

require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';



$cloud_name = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "";
$upload_preset = $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? "";
// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// ─── OneSignal Credentials ───────────────────────────────────────────────────
$ONESIGNAL_APP_ID = $_ENV['ONESIGNAL_APP_ID'] ?? '';
$ONESIGNAL_API_KEY = $_ENV['ONESIGNAL_API_KEY'] ?? '';
// ─────────────────────────────────────────────────────────────────────────────

// Initialize variables
$name = $email = $password = $confirm_password = $phone = $address = '';
$onesignal_player_id = '';
$cloudinary_image_url = null;
$errors = [];
$success = '';

// Check announcement status
$show_announcement = false;
if (!isset($_SESSION['announcement_seen'])) {
    $show_announcement = true;
    // We mark it as seen globally after the form processing block 
    // unless registration is successful (handled inside the POST block)
}

// Process registration form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate inputs
    $name = sanitize_input($_POST['name']);
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = sanitize_input($_POST['phone']);
    $address = sanitize_input($_POST['address']);
    $onesignal_player_id = sanitize_input($_POST['onesignal_player_id'] ?? '');

    // Handle profile image upload
    $profile_image = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // File size validation (Max 2MB)
        if ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $errors[] = "Profile image must be less than 2MB";
        }

        if (empty($errors) && in_array($ext, $allowed)) {
            $newname = uniqid() . '.jpg'; // Force .jpg for consistency
            $destination = '../uploads/users/' . $newname;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                // --- IMAGE OPTIMIZATION FOR ONESIGNAL (GD) ---
                $source = $destination;
                $img = null;
                // Use original extension ($ext) to open the file, even if renamed to .jpg
                if ($ext == 'jpg' || $ext == 'jpeg') {
                    $img = imagecreatefromjpeg($source);
                } elseif ($ext == 'png') {
                    $img = imagecreatefrompng($source);
                }

                if ($img) {
                    $original_width = imagesx($img);
                    $original_height = imagesy($img);

                    // Maintain ratio (Target: 512x512 square max)
                    $size = 512;
                    $new_width = $size;
                    $new_height = $size;

                    $tmp = imagecreatetruecolor($size, $size);

                    // Maintain transparency if original input was PNG
                    if ($ext == 'png') {
                        imagealphablending($tmp, false);
                        imagesavealpha($tmp, true);
                    }

                    imagecopyresampled($tmp, $img, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);

                    // Save always as JPG for smaller file size (< 1MB)
                    imagejpeg($tmp, $source, 80);
                    imagedestroy($img);
                    imagedestroy($tmp);
                }
                $profile_image = $newname;

                // --- CLOUDINARY UPLOAD ---
                $cloudinary_image_url = null;

                if ($cloud_name && $upload_preset) {

                    $url = "https://api.cloudinary.com/v1_1/$cloud_name/image/upload";

                    $post_fields = [
                        'file' => new CURLFile($destination),
                        'upload_preset' => $upload_preset
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $response = curl_exec($ch);
                    error_log("Cloudinary Response: " . $response);

                    if (curl_errno($ch)) {
                        error_log("Cloudinary CURL Error: " . curl_error($ch));
                    }

                    curl_close($ch);

                    $result = json_decode($response, true);

                    // STRICT CHECK
                    if (!empty($result['secure_url'])) {
                        $cloudinary_image_url = $result['secure_url'];
                        error_log("Cloudinary SUCCESS: " . $cloudinary_image_url);
                    } else {
                        error_log("Cloudinary FAILED RESPONSE: " . $response);
                    }
                }
            } else {
                $errors[] = "Failed to upload profile image";
            }
        } else {
            $errors[] = "Invalid image format";
        }
    }

    // Validation
    if (empty($name)) {
        $errors[] = "Name is required";
    }

    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    if (empty($phone)) {
        $errors[] = "Phone number is required";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = "Phone number must be exactly 10 digits";
    }

    if (empty($address)) {
        $errors[] = "Address is required";
    }

    // Check if email already exists
    if (empty($errors)) {
        // Use PDO for consistency if $pdo is available from db.php, otherwise fallback to mysqli global $conn
        // Assuming $pdo is available as per login.php. If raw mysqli is used in db.php, we should use that.
        // Looking at login.php, $pdo is used. We should stay consistent.
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "This player email is already registered";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    // If no errors, register player
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'player'; // Default role

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone, address, profile_image, profile_image_url, onesignal_player_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $hashed_password, $role, $phone, $address, $profile_image, $cloudinary_image_url, $onesignal_player_id])) {
                $new_user_id = $pdo->lastInsertId();
                $name_for_modal = $name; // Capture name for success modal
                $success = "Player registration successful! You can now login.";
                $_SESSION['announcement_seen'] = true; // Mark as seen so it doesn't show with success modal
                $show_announcement = false; // Force hide announcement if registration was successful

                // 🔔 Link OneSignal Player ID to new user in user_devices
                if (!empty($onesignal_player_id)) {
                    try {
                        $dev_stmt = $pdo->prepare("
                            INSERT INTO user_devices (user_id, onesignal_player_id) 
                            VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE user_id = ?, updated_at = NOW()
                        ");
                        $dev_stmt->execute([$new_user_id, $onesignal_player_id, $new_user_id]);

                        // 🔔 Send Welcome Push Notification via centralized util
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $base_url = "$protocol://$host/CPT_LEAGUE/";

                        // Set image URL strictly to Cloudinary URL - Use full image without forced cropping
                        if (!$cloudinary_image_url) {
                            error_log("❌ Cloudinary upload failed - stopping notification");
                            $image_url = null;
                        } else {
                            // Use f_auto, q_auto for optimization but maintain original aspect ratio
                            $image_url = str_replace(
                                "/upload/",
                                "/upload/f_auto,q_auto/",
                                $cloudinary_image_url
                            );
                        }

                        if (!empty($onesignal_player_id)) {

                            if (!$image_url) {
                                error_log("❌ Image URL missing - notification skipped");
                            } else {

                                $payload = [
                                    'type' => 'welcome',
                                    'big_picture' => $image_url,
                                    'image' => $image_url,
                                    'large_icon' => "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1776484189/logo_jhar6d.jpg",
                                    'small_icon' => 'ic_stat_notify',
                                    'android_sound' => 'notification_sound',
                                    'target_url' => $base_url . "login/login.php"
                                ];

                                sendOneSignalNotification(
                                    [$onesignal_player_id],
                                    '🎉 Player Registration Successful!',
                                    "Welcome $name! Your player account has been created successfully.",
                                    $payload
                                );
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("OneSignal Register Link Error: " . $e->getMessage());
                    }
                }

                $name = $email = $password = $confirm_password = $phone = $address = '';
                $onesignal_player_id = '';
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';

// Mark announcement as seen for future visits in this session
if ($show_announcement && empty($success)) {
    $_SESSION['announcement_seen'] = true;
}
?>

<!-- Cropper.js CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<!-- Cropper.js JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<style>
    .register-container {
        min-height: calc(100vh - 76px);
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        padding: 3rem 1rem;
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        overflow-x: hidden;
    }

    .register-bg-accent {
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        background-image:
            radial-gradient(circle at 80% 10%, rgba(16, 185, 129, 0.05) 0%, transparent 40%),
            radial-gradient(circle at 20% 90%, rgba(59, 130, 246, 0.05) 0%, transparent 40%);
        pointer-events: none;
    }

    .glass-card-lg {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.8);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        border-radius: 24px;
        width: 100%;
        max-width: 800px;
        position: relative;
        overflow: hidden;
    }

    .register-header {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        padding: 2rem;
        text-align: center;
        border-bottom: 4px solid #10b981;
    }

    .register-icon-box {
        width: 60px;
        height: 60px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .form-floating>.form-control {
        border-radius: 12px;
        border: 1px solid #cbd5e1;
    }

    .form-floating>.form-control:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
    }

    .btn-register {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border: none;
        padding: 0.8rem;
        border-radius: 12px;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
    }

    .btn-register:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.3);
    }

    .back-home {
        position: absolute;
        top: 20px;
        left: 20px;
        color: #475569;
        text-decoration: none;
        font-size: 0.9rem;
        z-index: 20;
    }

    .back-home:hover {
        color: #0f172a;
    }

    /* Custom File Input Styling */
    .file-upload-wrapper {
        position: relative;
        overflow: hidden;
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        background: #f8fafc;
        transition: all 0.2s;
    }

    .file-upload-wrapper:hover {
        border-color: #10b981;
        background: #f0fdf4;
    }

    .file-upload-input {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }


    /* Announcement Overlay Styles */
    #announcementOverlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 100000;
        /* Ensure it is above everything including loader */
        display: none;
        /* Hidden by default, shown via JS */
        justify-content: center;
        align-items: center;
        padding: 1rem;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    #announcementOverlay.active {
        display: flex !important;
        opacity: 1;
    }

    .announcement-card {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        width: 100%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
        display: flex;
        flex-direction: column;
        animation: slideUpFade 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideUpFade {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .announcement-header {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        padding: 1.5rem;
        border-radius: 20px 20px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 10;
        border-bottom: 3px solid #10b981;
    }

    .announcement-title {
        color: white;
        margin: 0;
        font-weight: 700;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .btn-close-custom {
        background: rgba(255, 255, 255, 0.1);
        border: none;
        color: white;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        cursor: pointer;
    }

    .btn-close-custom:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: rotate(90deg);
    }

    .lang-toggle-container {
        display: flex;
        justify-content: center;
        gap: 1rem;
        padding: 1rem;
        background: #f1f5f9;
        border-bottom: 1px solid #e2e8f0;
    }

    .btn-lang {
        border: 2px solid #cbd5e1;
        background: white;
        color: #64748b;
        padding: 0.5rem 1.5rem;
        border-radius: 50px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        min-width: 120px;
    }

    .btn-lang.active {
        border-color: #10b981;
        background: #10b981;
        color: white;
        box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
    }

    .announcement-body {
        padding: 2rem;
        color: #334155;
        line-height: 1.7;
    }

    .info-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .info-list li {
        position: relative;
        padding-left: 2rem;
        margin-bottom: 1rem;
    }

    .info-list li::before {
        content: "•";
        color: #10b981;
        font-weight: bold;
        font-size: 1.5rem;
        position: absolute;
        left: 0.5rem;
        top: -0.2rem;
    }

    .announcement-footer {
        padding: 1.5rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        text-align: center;
        border-radius: 0 0 20px 20px;
    }

    .mobile-divider {
        display: none;
        height: 1px;
        background: #e2e8f0;
        margin: 2rem 0;
        position: relative;
    }

    .mobile-divider::after {
        content: "TAMIL / தமிழ்";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 0 1rem;
        color: #94a3b8;
        font-size: 0.8rem;
        font-weight: bold;
    }

    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
        .lang-toggle-container {
            display: none;
            /* Hide toggle on mobile */
        }

        .announcement-content {
            display: block !important;
            /* Show both contents */
        }

        #content-english {
            margin-bottom: 0.5rem;
        }

        .mobile-divider {
            display: block;
            /* Show divider */
            margin: 1rem 0;
            height: 1px;
            background: #cbd5e1;
        }

        .mobile-divider::after {
            content: "TAMIL / தமிழ்";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 0 0.5rem;
            color: #94a3b8;
            font-size: 0.7rem;
            font-weight: bold;
        }

        /* Reduce font size 60% of original (~approx 0.8rem from 1rem base is safer but close to 13px) */
        .announcement-title {
            font-size: 1rem;
            /* Reduced from 1.25rem */
        }

        .announcement-body {
            padding: 1rem;
            font-size: 0.8rem;
            /* Reduced significantly from base (usually 1rem/16px) */
            line-height: 1.4;
        }

        .info-list li {
            padding-left: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .info-list li::before {
            font-size: 1rem;
            left: 0;
            top: -0.1rem;
        }

        h5 {
            font-size: 0.9rem;
            /* Reduced heading size */
        }

        #btnUnderstand {
            font-size: 0.8rem;
            padding: 0.6rem 1.5rem;
            width: 100%;
        }
    }

    /* Success Modal Styles */
    .success-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 999999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.4s ease;
    }

    .success-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .success-card {
        background: white;
        width: 90%;
        max-width: 450px;
        padding: 3rem 2rem;
        border-radius: 32px;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        transform: scale(0.9) translateY(20px);
        transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .success-overlay.show .success-card {
        transform: scale(1) translateY(0);
    }

    .success-icon-wrapper {
        width: 80px;
        height: 80px;
        background: #10b981;
        color: white;
        font-size: 2.5rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 2rem;
        box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        animation: pulse-success 2s infinite;
    }

    @keyframes pulse-success {
        0% {
            transform: scale(1);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        50% {
            transform: scale(1.05);
            box-shadow: 0 15px 30px rgba(16, 185, 129, 0.4);
        }

        100% {
            transform: scale(1);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }
    }

    .btn-success-modal {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border: none;
        color: white;
        padding: 1rem 3rem;
        border-radius: 16px;
        font-weight: 700;
        font-size: 1.1rem;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
        margin-top: 1.5rem;
        width: 100%;
    }

    .btn-success-modal:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
    }

    /* Force remove browser eye (strong fix) */
    input[type="password"]::-webkit-textfield-decoration-container {
        display: none !important;
    }

    input[type="password"]::-webkit-credentials-auto-fill-button {
        visibility: hidden;
        display: none !important;
        pointer-events: none;
    }

    input[type="password"]::-webkit-password-reveal-button {
        visibility: hidden;
        display: none !important;
        pointer-events: none;
    }

    input::-ms-reveal,
    input::-ms-clear {
        display: none !important;
    }

    .password-toggle-wrapper {
        position: relative;
    }

    .password-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        font-size: 1.4rem;
        z-index: 20;
        user-select: none;
        transition: all 0.3s ease;
        line-height: 1;
        background: transparent;
        width: auto;
        height: auto;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    .password-toggle-wrapper:focus-within .password-toggle {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .password-toggle:hover {
        transform: translateY(-50%) scale(1.2);
    }

    .form-floating>.form-control {
        padding-right: 55px;
    }
</style>

<div class="register-container">
    <div class="register-bg-accent"></div>
    <a href="../../index.php" class="back-home">
        <i class="fas fa-arrow-left me-2"></i>Back to Home
    </a>

    <div class="glass-card-lg">
        <div class="register-header">
            <div class="register-icon-box">
                <i class="fas fa-user-plus fa-2x text-success"></i>
            </div>
            <h2 class="text-white fw-bold h4 mb-1">Player Registration</h2>
            <p class="text-white-50 mb-0 small">Register as a player to join CPT League</p>
        </div>

        <div class="p-4 p-md-5">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-4">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>



            <form id="registerForm" method="POST" action="" enctype="multipart/form-data">
                <!-- 🔔 Hidden field: OneSignal Player ID injected by Android WebView -->
                <input type="hidden" id="onesignal_player_id" name="onesignal_player_id" value="">
                <div class="row g-3">
                    <!-- Personal Info -->
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="name" name="name" placeholder="Full Name"
                                value="<?php echo htmlspecialchars($name); ?>" required>
                            <label for="name">Full Name</label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="email" name="email" placeholder="Email"
                                value="<?php echo htmlspecialchars($email); ?>" required>
                            <label for="email">Email Address</label>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="col-md-6">
                        <div class="form-floating password-toggle-wrapper">
                            <input type="password" class="form-control" id="password" name="password"
                                placeholder="Password" required>
                            <label for="password">Password (Min 6 chars)</label>
                            <span class="password-toggle" onclick="togglePassword('password', this)"
                                onmousedown="event.preventDefault()">🐵</span>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-floating password-toggle-wrapper">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                placeholder="Confirm Password" required>
                            <label for="confirm_password">Confirm Password</label>
                            <span class="password-toggle" onclick="togglePassword('confirm_password', this)"
                                onmousedown="event.preventDefault()">🐵</span>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="Phone"
                                value="<?php echo htmlspecialchars($phone); ?>" required pattern="[0-9]{10}"
                                maxlength="10" title="Please enter exactly 10 digits"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <label for="phone">Phone Number (10 digits)</label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="address" name="address" placeholder="Address"
                                value="<?php echo htmlspecialchars($address); ?>" required>
                            <label for="address">Address/City</label>
                        </div>
                    </div>

                    <!-- Profile Image -->
                    <div class="col-12">
                        <div class="file-upload-wrapper mt-2">
                            <div class="mb-2"><i class="fas fa-cloud-upload-alt fa-2x text-muted"></i></div>
                            <h6 class="text-dark fw-bold mb-1">Upload Player Photo</h6>
                            <small class="text-muted d-block mb-0">Select your profile photo</small>
                            <input type="file" id="profile_image" name="profile_image" class="file-upload-input"
                                accept="image/*" required>
                        </div>
                        <div class="mt-3 text-center">
                            <img id="imagePreview" src="" alt="Preview"
                                style="display: none; width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" id="btnRegister"
                        class="btn btn-register text-white btn-lg d-flex align-items-center justify-content-center">
                        <span class="btn-text">Register Now</span>
                        <i class="fas fa-arrow-right ms-2 btn-icon"></i>
                        <div class="spinner-border spinner-border-sm ms-2 d-none" id="btnSpinner" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </button>
                </div>

                <div class="text-center pt-3">
                    <p class="text-muted small mb-0">Already registered?
                        <a href="login.php" class="text-success fw-bold text-decoration-none">Login here</a>
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Crop Modal -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Adjust Your Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="img-container bg-dark rounded-3 overflow-hidden" style="max-height: 400px;">
                    <img id="imageToCrop" src="" style="max-width: 100%; display: block;" alt="Crop Image">
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold"
                    data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success rounded-pill px-4 fw-bold" id="cropBtn">
                    <i class="fas fa-check me-2"></i>Crop & Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cropper Logic -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const imageInput = document.getElementById('profile_image');
        const imageToCrop = document.getElementById('imageToCrop');
        const cropBtn = document.getElementById('cropBtn');
        let cropper;
        let cropModal;

        // Initialize Modal
        cropModal = new bootstrap.Modal(document.getElementById('cropModal'));

        imageInput.addEventListener('change', function (e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                const file = files[0];

                // Prevent recursive loop
                if (file.cropped) return;

                const reader = new FileReader();
                reader.onload = function (event) {
                    imageToCrop.src = event.target.result;
                    cropModal.show();

                    // Wait for modal to be somewhat visible or just destroy prev immediately
                    if (cropper) cropper.destroy();
                };
                reader.readAsDataURL(file);

                // Clear input so same file can be selected again if cancelled
                imageInput.value = '';
            }
        });

        // Initialize cropper when modal is shown
        document.getElementById('cropModal').addEventListener('shown.bs.modal', function () {
            cropper = new Cropper(imageToCrop, {
                aspectRatio: 1, // 1:1 Square Profile Image
                viewMode: 1,
                autoCropArea: 1,
            });
        });

        cropBtn.addEventListener('click', function () {
            if (!cropper) return;

            // Get Cropped Canvas - Adjust size to match 1:1 square
            const canvas = cropper.getCroppedCanvas({
                width: 512,
                height: 512,
            });

            // Convert to Blob
            canvas.toBlob(function (blob) {
                const croppedFile = new File([blob], "profile_cropped.png", { type: "image/png" });
                croppedFile.cropped = true; // Flag to prevent loop

                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(croppedFile);
                imageInput.files = dataTransfer.files;

                // Show Preview
                const preview = document.getElementById('imagePreview');
                preview.src = URL.createObjectURL(blob);
                preview.style.display = 'inline-block';

                cropModal.hide();
            }, 'image/png');
        });

        document.getElementById('cropModal').addEventListener('hidden.bs.modal', function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        });
    });
</script>

<!-- 🔔 OneSignal Player ID — capture on form submit with Retry Mechanism -->
<script>
    document.getElementById('registerForm').addEventListener('submit', function (e) {
        const form = this;
        const playerIdField = document.getElementById('onesignal_player_id');
        const btn = document.getElementById('btnRegister');
        const btnText = btn.querySelector('.btn-text');
        const btnIcon = btn.querySelector('.btn-icon');
        const btnSpinner = document.getElementById('btnSpinner');

        // Prevent recursive submit loop
        if (form.dataset.ready === 'true') return;

        // Pre-submission validation check
        const pass = document.getElementById('password').value;
        const confirmPass = document.getElementById('confirm_password').value;
        const name = document.getElementById('name').value.trim();
        const email = document.getElementById('email').value.trim();

        if (!name || !email || pass.length < 6 || pass !== confirmPass) {
            // Let script.js handle the error display, we just stop the loading state here
            return;
        }

        // Show loading state
        btn.disabled = true;
        btnText.textContent = 'Registering...';
        btnIcon.classList.add('d-none');
        btnSpinner.classList.remove('d-none');

        // Check if running inside Android WebView with the Android interface
        if (typeof Android !== 'undefined' && typeof Android.getPlayerId === 'function') {
            e.preventDefault(); // Stop initial submission

            let attempts = 0;
            const maxAttempts = 10;
            const interval = 300; // ms

            function attemptCapture() {
                const pid = Android.getPlayerId();
                if (pid && pid.length > 0) {
                    playerIdField.value = pid;
                    console.log('OneSignal Player ID captured successfully: ' + pid);
                    submitForm();
                } else {
                    attempts++;
                    if (attempts < maxAttempts) {
                        console.log('Waiting for Player ID... attempt ' + attempts);
                        setTimeout(attemptCapture, interval);
                    } else {
                        console.warn('Failed to obtain Player ID after ' + maxAttempts + ' attempts. Submitting anyway.');
                        submitForm();
                    }
                }
            }

            function submitForm() {
                form.dataset.ready = 'true';
                form.submit();
            }

            attemptCapture();
        }
    });
</script>

<?php if ($success): ?>
    <!-- Success Modal Overlay -->
    <div class="success-overlay" id="successModal">
        <div class="success-card">
            <div class="success-icon-wrapper">
                <i class="fas fa-check"></i>
            </div>
            <h2 class="fw-bold text-dark mb-2">Welcome, <?php echo htmlspecialchars($name_for_modal ?? 'Player'); ?>!</h2>
            <p class="text-muted mb-4">Your account has been created successfully. Get ready to start your journey!</p>
            <button type="button" class="btn btn-success-modal shadow-sm" onclick="window.location.href='login.php'">
                OK
            </button>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('successModal');
            setTimeout(() => {
                modal.classList.add('show');
            }, 100);
        });
    </script>
<?php endif; ?>


<!-- Bilingual Announcement Overlay -->
<div id="announcementOverlay">
    <div class="announcement-card">
        <div class="announcement-header">
            <h3 class="announcement-title">
                <i class="fas fa-bullhorn text-warning"></i>
                IMPORTANT ANNOUNCEMENT
            </h3>
            <button type="button" class="btn-close-custom" id="btnCloseOverlay" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Language Toggle (Desktop Only) -->
        <div class="lang-toggle-container">
            <button class="btn-lang active" data-lang="english">English</button>
            <button class="btn-lang" data-lang="tamil">தமிழ் (Tamil)</button>
        </div>

        <div class="announcement-body">
            <!-- English Content -->
            <div id="content-english" class="announcement-content">
                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2 d-inline-block">PLAYER REGISTRATION GUIDELINES</h5>
                <p class="mb-3">Before proceeding with registration, please carefully read the following instructions:
                </p>
                <ul class="info-list">
                    <li>You must provide your <strong>original and accurate personal details</strong> while registering.
                    </li>
                    <li>Your <strong>Original Name, Email ID, and Mobile Number</strong> are mandatory.</li>
                    <li>You must upload a <strong>clear and recent photo of yourself</strong> as the Profile Picture.
                    </li>
                    <li>These details are required for <strong>secure player account recovery</strong>.</li>
                    <li>If you forget your password, you can use the <strong>Forgot Password</strong> option.</li>
                    <li>An <strong>OTP</strong> will be sent to your registered email ID for verification.</li>
                    <li>If false or misleading information is detected, the <span class="text-danger fw-bold">Admin has
                            the authority to remove the player account</span> from the portal without prior notice.</li>
                    <li>Please ensure all details are <strong>genuine</strong> before proceeding.
                    </li>
                </ul>
            </div>

            <!-- Mobile Divider -->
            <div class="mobile-divider"></div>

            <!-- Tamil Content -->
            <div id="content-tamil" class="announcement-content" style="display: none;">
                <h5 class="fw-bold mb-3 text-dark border-bottom pb-2 d-inline-block">வீரர் பதிவு விதிமுறைகள்</h5>
                <p class="mb-3">பதிவை தொடங்குவதற்கு முன் கீழ்கண்ட வழிமுறைகளை கவனமாக படிக்கவும்:</p>
                <ul class="info-list">
                    <li>பதிவு செய்யும் போது உங்கள் <strong>உண்மையான மற்றும் சரியான தகவல்களை</strong> வழங்க வேண்டும்.
                    </li>
                    <li>உங்கள் <strong>உண்மையான பெயர், மின்னஞ்சல் முகவரி மற்றும் கைபேசி எண்</strong> கட்டாயம்.</li>
                    <li>உங்கள் <strong>சொந்த புகைப்படத்தை (Profile Picture)</strong> மட்டுமே பதிவேற்ற வேண்டும்.</li>
                    <li>இத்தகவல்கள் <strong>கணக்கு பாதுகாப்பிற்கும் மீட்பிற்கும்</strong> அவசியமானவை.</li>
                    <li>கடவுச்சொல்லை மறந்தால் <strong>“Forgot Password”</strong> வசதியை பயன்படுத்தலாம்.</li>
                    <li>சரிபார்ப்பதற்காக <strong>OTP</strong> உங்கள் பதிவு செய்யப்பட்ட மின்னஞ்சலுக்கு அனுப்பப்படும்.
                    </li>
                    <li>தவறான அல்லது போலியான தகவல்கள் வழங்கப்பட்டால், <strong>நிர்வாகி முன் அறிவிப்பின்றி கணக்கை நீக்க
                            அதிகாரம் கொண்டவர்</strong>.</li>
                    <li>பதிவு செய்யும் முன் அனைத்து தகவல்களும் <strong>சரியாக உள்ளதா</strong> என்பதை உறுதிசெய்யவும்.
                    </li>
                </ul>
            </div>
        </div>

        <div class="announcement-footer">
            <button id="btnUnderstand" class="btn btn-success btn-lg px-5 rounded-pill fw-bold shadow-sm">
                <i class="fas fa-check-circle me-2"></i> I Understand / புரிந்து கொண்டேன்
            </button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('announcementOverlay');
        const btnClose = document.getElementById('btnCloseOverlay');
        const btnUnderstand = document.getElementById('btnUnderstand');
        const langBtns = document.querySelectorAll('.btn-lang');
        const contentEnglish = document.getElementById('content-english');
        const contentTamil = document.getElementById('content-tamil');
        const body = document.body;

        // Function to handle layout based on screen size
        function handleLayout() {
            if (window.innerWidth <= 768) {
                // Mobile: Show both
                contentEnglish.style.display = 'block';
                contentTamil.style.display = 'block';
            } else {
                // Desktop: Show active only
                const activeBtn = document.querySelector('.btn-lang.active');
                const lang = activeBtn ? activeBtn.getAttribute('data-lang') : 'english';

                if (lang === 'english') {
                    contentEnglish.style.display = 'block';
                    contentTamil.style.display = 'none';
                } else {
                    contentEnglish.style.display = 'none';
                    contentTamil.style.display = 'block';
                }
            }
        }

        // Always show overlay on load if session allows
        const showAnnouncement = <?= json_encode($show_announcement) ?>;

        // Ensure layout is correct before showing
        handleLayout();

        // Show overlay only if not seen in this session
        if (showAnnouncement) {
            overlay.classList.add('active');
            body.style.overflow = 'hidden'; // Prevent background scrolling
        }

        // Close Overlay Function
        function closeOverlay() {
            overlay.classList.remove('active');
            body.style.overflow = ''; // Restore scrolling
        }

        // Event Listeners
        btnClose.addEventListener('click', closeOverlay);
        btnUnderstand.addEventListener('click', closeOverlay);

        // Language Toggle Logic
        langBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                // Remove active class from all buttons
                langBtns.forEach(b => b.classList.remove('active'));
                // Add active class to clicked button
                btn.classList.add('active');

                // Toggle Content
                handleLayout();
            });
        });

        // Handle resize
        window.addEventListener('resize', handleLayout);
    });

    function togglePassword(inputId, el) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            el.textContent = '🙈';
        } else {
            input.type = 'password';
            el.textContent = '🐵';
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>