<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_once '../includes/html2image_utils.php';


require_login();

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

// Configure Cloudinary
Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
    ],
    'url' => [
        'secure' => true
    ]
]);

// ─── OneSignal Credentials ───────────────────────────────────────────────────
$ONESIGNAL_APP_ID = $_ENV['ONESIGNAL_APP_ID'] ?? '';
$ONESIGNAL_API_KEY = $_ENV['ONESIGNAL_API_KEY'] ?? '';
// ─────────────────────────────────────────────────────────────────────────────

function uploadToCloudinary($filePath) {
    if (!$filePath || !file_exists($filePath)) {
        error_log("❌ Invalid file path for Cloudinary: " . $filePath);
        return null;
    }

    try {
        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($filePath, [
            'folder' => 'tournaments',
            'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ""
        ]);

        if (!empty($result['secure_url'])) {
            error_log("✅ Cloudinary SDK SUCCESS: " . $result['secure_url']);
            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id']
            ];
        } else {
            error_log("❌ Cloudinary SDK Upload Failed: No secure_url in response");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Cloudinary SDK Exception: " . $e->getMessage());
        return null;
    }
}

function generateTournamentAnnouncementBanner(array $data): ?string
{
    $title = htmlspecialchars((string) ($data['title'] ?? 'Tournament'), ENT_QUOTES, 'UTF-8');
    $venue = htmlspecialchars((string) ($data['venue'] ?? 'Venue TBA'), ENT_QUOTES, 'UTF-8');
    $period = htmlspecialchars((string) ($data['period'] ?? ''), ENT_QUOTES, 'UTF-8');
    $format = htmlspecialchars((string) ($data['format'] ?? 'League'), ENT_QUOTES, 'UTF-8');
    $overs = htmlspecialchars((string) ($data['overs'] ?? '20'), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars((string) ($data['description'] ?? 'Get ready for the next chapter of competition.'), ENT_QUOTES, 'UTF-8');
    $logo_url = htmlspecialchars((string) ($data['logo_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $banner_url = htmlspecialchars((string) ($data['banner_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $logo_fallback = htmlspecialchars(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($data['title'] ?? 'TR')), 0, 2)) ?: 'TR', ENT_QUOTES, 'UTF-8');

    $hero_image = $banner_url !== '' ? "<img class='hero-image' src='{$banner_url}' alt='Tournament banner' />" : '';
    $logo_markup = $logo_url !== ''
        ? "<img class='logo-image' src='{$logo_url}' alt='Tournament logo' />"
        : "<span class='logo-fallback'>{$logo_fallback}</span>";

    $html = "
    <div class='banner-shell'>
        {$hero_image}
        <div class='overlay'></div>
        <div class='content'>
            <div class='eyebrow'>Tournament Announcement</div>
            <div class='headline'>{$title}</div>
            <div class='description'>{$description}</div>
            <div class='meta-grid'>
                <div class='meta-card'>
                    <div class='meta-label'>Venue</div>
                    <div class='meta-value'>{$venue}</div>
                </div>
                <div class='meta-card'>
                    <div class='meta-label'>Schedule</div>
                    <div class='meta-value'>{$period}</div>
                </div>
                <div class='meta-card'>
                    <div class='meta-label'>Format</div>
                    <div class='meta-value'>{$format}</div>
                </div>
                <div class='meta-card'>
                    <div class='meta-label'>Overs</div>
                    <div class='meta-value'>{$overs} Overs</div>
                </div>
            </div>
        </div>
        <div class='logo-shell'>{$logo_markup}</div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 1024px; height: 576px; font-family: 'Outfit', sans-serif; }
    .banner-shell {
        width: 1024px; height: 576px; position: relative; overflow: hidden; color: #f8fafc;
        background:
            radial-gradient(circle at top left, rgba(251, 191, 36, 0.20), transparent 30%),
            radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.18), transparent 34%),
            linear-gradient(140deg, #081120 0%, #0f2840 50%, #07111b 100%);
    }
    .hero-image {
        position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.22;
        transform: scale(1.06);
    }
    .overlay {
        position: absolute; inset: 0;
        background: linear-gradient(115deg, rgba(2, 6, 23, 0.92) 12%, rgba(2, 6, 23, 0.64) 58%, rgba(2, 6, 23, 0.86) 100%);
    }
    .content { position: relative; z-index: 2; padding: 54px; width: 72%; display: flex; flex-direction: column; gap: 18px; }
    .eyebrow {
        display: inline-flex; width: fit-content; align-items: center; padding: 10px 18px; border-radius: 999px;
        background: rgba(15, 23, 42, 0.62); border: 1px solid rgba(251, 191, 36, 0.36);
        color: #fbbf24; font-size: 15px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase;
    }
    .headline { font-size: 58px; line-height: 1.02; font-weight: 800; max-width: 620px; }
    .description { font-size: 21px; line-height: 1.55; color: #dbeafe; max-width: 620px; }
    .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 8px; max-width: 620px; }
    .meta-card {
        padding: 18px 20px; border-radius: 20px; background: rgba(15, 23, 42, 0.58);
        border: 1px solid rgba(148, 163, 184, 0.22); backdrop-filter: blur(8px);
    }
    .meta-label { font-size: 13px; letter-spacing: 0.12em; text-transform: uppercase; color: #93c5fd; margin-bottom: 8px; }
    .meta-value { font-size: 24px; line-height: 1.25; font-weight: 700; color: #ffffff; }
    .logo-shell {
        position: absolute; right: 54px; top: 52px; z-index: 2; width: 180px; height: 180px; border-radius: 34px;
        background: rgba(248, 250, 252, 0.10); border: 1px solid rgba(248, 250, 252, 0.18);
        display: flex; align-items: center; justify-content: center; box-shadow: 0 18px 40px rgba(2, 6, 23, 0.32); overflow: hidden;
    }
    .logo-image { width: 100%; height: 100%; object-fit: contain; padding: 18px; }
    .logo-fallback { font-size: 54px; font-weight: 800; color: #fbbf24; letter-spacing: 0.08em; }";

    $image_url = generate_html2image_link($html, $css, 1024, 576, 2500);
    return upload_generated_image_to_cloudinary($image_url, 'tournament_notifications');
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['tournament_name', 'venue', 'start_date', 'end_date', 'overs', 'format'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        // Validate dates
        $start_date = strtotime($_POST['start_date']);
        $end_date = strtotime($_POST['end_date']);

        if ($start_date === false || $end_date === false) {
            throw new Exception("Invalid date format.");
        }

        if ($start_date > $end_date) {
            throw new Exception("End date must be after start date.");
        }

        if ($start_date < strtotime('today')) {
            throw new Exception("Start date cannot be in the past.");
        }

        // Check if tournament name already exists
        $tournament_name = trim($_POST['tournament_name']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE tournament_name = ?");
        $stmt->execute([$tournament_name]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("A tournament with this name already exists. Please choose a different name.");
        }

        // Generate tournament code
        $words = explode(' ', $tournament_name);
        $base_code = '';
        foreach ($words as $word) {
            $base_code .= strtoupper(substr($word, 0, 1));
        }
        $base_code = substr($base_code, 0, 5); // Limit to 5 characters

        $code = $base_code;
        $is_unique = false;
        $attempts = 0;

        while (!$is_unique) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE tournament_code = ?");
            $stmt->execute([$code]);
            $count = $stmt->fetchColumn();

            if ($count == 0) {
                $is_unique = true;
            } else {
                // If collision, append random number and try again
                $code = $base_code . rand(10, 99);
                $attempts++;
                // Safety break after 10 attempts
                if ($attempts > 10) {
                    $code = $base_code . time();
                    break;
                }
            }
        }

        // Handle tournament logo upload
        $logo_full_path = null;
        $logo_db_path = null;
        if (isset($_FILES['tournament_logo']) && $_FILES['tournament_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/jfif', 'image/bmp', 'image/x-ms-bmp'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($_FILES['tournament_logo']['type'], $allowed_types)) {
                throw new Exception("Invalid logo file type. Only JPG, PNG, GIF, WEBP, JFIF, and BMP are allowed.");
            }

            if ($_FILES['tournament_logo']['size'] > $max_size) {
                throw new Exception("Logo file size too large. Maximum 2MB allowed.");
            }

            $upload_dir = '../uploads/tournaments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = pathinfo($_FILES['tournament_logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'logo_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $logo_full_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['tournament_logo']['tmp_name'], $logo_full_path)) {
                $logo_db_path = 'uploads/tournaments/' . $file_name;
            } else {
                throw new Exception("Failed to upload tournament logo.");
            }
        }

        // Handle tournament banner upload
        $banner_full_path = null;
        $banner_db_path = null;
        if (isset($_FILES['tournament_banner']) && $_FILES['tournament_banner']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/jfif', 'image/bmp', 'image/x-ms-bmp'];
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($_FILES['tournament_banner']['type'], $allowed_types)) {
                throw new Exception("Invalid banner file type. Only JPG, PNG, GIF, WEBP, JFIF, and BMP are allowed.");
            }

            if ($_FILES['tournament_banner']['size'] > $max_size) {
                throw new Exception("Banner file size too large. Maximum 5MB allowed.");
            }

            $upload_dir = '../uploads/tournaments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = pathinfo($_FILES['tournament_banner']['name'], PATHINFO_EXTENSION);
            $file_name = 'banner_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $banner_full_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['tournament_banner']['tmp_name'], $banner_full_path)) {
                $banner_db_path = 'uploads/tournaments/' . $file_name;
            } else {
                throw new Exception("Failed to upload tournament banner.");
            }
        }

        // ─── Early Cloudinary Upload ───────────────────────────────────────────
        $tournament_logo_url = null;
        $tournament_logo_public_id = null;
        $tournament_banner_url = null;
        $tournament_banner_public_id = null;

        if ($logo_full_path) {
            $logo_res = uploadToCloudinary($logo_full_path);
            if ($logo_res) {
                $tournament_logo_url = $logo_res['url'];
                $tournament_logo_public_id = $logo_res['public_id'];
            }
        }
        if ($banner_full_path) {
            $banner_res = uploadToCloudinary($banner_full_path);
            if ($banner_res) {
                $tournament_banner_url = $banner_res['url'];
                $tournament_banner_public_id = $banner_res['public_id'];
            }
        }

        // Insert tournament
        $stmt = $pdo->prepare("
            INSERT INTO tournaments (
                tournament_name, tournament_code, description, start_date, end_date, 
                venue, overs, format, ground_type, prize_amount, entry_fee, 
                tournament_logo, tournament_logo_public_id,
                tournament_banner, tournament_banner_public_id,
                max_teams, created_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming')
        ");

        $stmt->execute([
            $tournament_name,
            $code,
            trim($_POST['description'] ?? ''),
            $_POST['start_date'],
            $_POST['end_date'],
            trim($_POST['venue']),
            (int) $_POST['overs'],
            $_POST['format'],
            $_POST['ground_type'] ?? 'Grass',
            $_POST['prize_amount'] ? (float) $_POST['prize_amount'] : null,
            $_POST['entry_fee'] ? (float) $_POST['entry_fee'] : null,
            $logo_db_path,
            $tournament_logo_public_id,
            $banner_db_path,
            $tournament_banner_public_id,
            (int) ($_POST['max_teams'] ?? 8),
            $_SESSION['user_id']
        ]);

        $tournament_id = (int) $pdo->lastInsertId();

        // 🔔 Send Push Notification to all players (Registered & Guests) via centralized util
        $registered_player_ids = $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE user_id IS NOT NULL AND onesignal_player_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $guest_player_ids = getGuestPlayerIds($pdo);
        $all_player_ids = array_unique(array_merge($registered_player_ids, $guest_player_ids));

        if (!empty($all_player_ids)) {
            $venue = trim($_POST['venue']);
            $overs = (int) $_POST['overs'];
            $start_date_formatted = date('d M Y', strtotime($_POST['start_date']));
            $end_date_formatted = date('d M Y', strtotime($_POST['end_date']));
            $schedule_label = $start_date_formatted === $end_date_formatted
                ? $start_date_formatted
                : $start_date_formatted . ' - ' . $end_date_formatted;

            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $base_url = "$protocol://$host/CPT_LEAGUE/";
            $notification_banner_url = generateTournamentAnnouncementBanner([
                'title' => $tournament_name,
                'venue' => $venue,
                'period' => $schedule_label,
                'format' => $_POST['format'],
                'overs' => $overs,
                'description' => trim($_POST['description'] ?? '') ?: 'Register your team and prepare for the opening fixtures.',
                'logo_url' => $tournament_logo_url,
                'banner_url' => $tournament_banner_url,
            ]);
            $notification_big_picture = $notification_banner_url ?: ($tournament_banner_url ?: ($base_url . "assets/images/home_bg.jpg"));
            $notification_large_icon = $tournament_logo_url ?: ($base_url . "assets/images/logo.jpg");

            if ($notification_big_picture) {
                $payload = [
                    'tournament_id' => $tournament_id,
                    'type' => 'tournament',
                    'big_picture' => $notification_big_picture,
                    'image' => $notification_big_picture,
                    'large_icon' => $notification_large_icon,
                    'small_icon' => 'ic_stat_notify',
                    'android_sound' => 'notification_sound',
                    'target_url' => $base_url . "NavBarList/tournament_list.php"
                ];

                sendOneSignalNotification(
                    $all_player_ids,
                    "🏆 $tournament_name is officially Live!",
                    "Exciting matches starting at $venue from $start_date_formatted. Register your team now!",
                    $payload
                );
            } else {
                error_log("❌ Notification skipped - image missing or Cloudinary upload failed.");
            }
        }

        // Redirect to tournament list with success message
        header("Location: ../NavBarList/tournament_list.php?success=1");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Create Tournament";
require_once '../includes/header.php';
?>

<!-- Glassmorphism Styling -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    :root {
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        --secondary-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        --text-primary: #1f2937;
        --text-secondary: #6b7280;
    }

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f3f4f6;
    }

    .main-container {
        min-height: 100vh;
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        background-image:
            radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 0%, rgba(168, 85, 247, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(59, 130, 246, 0.1) 0px, transparent 50%),
            radial-gradient(at 0% 100%, rgba(236, 72, 153, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        box-shadow: var(--glass-shadow);
        border-radius: 24px;
        overflow: hidden;
        animation: slideUp 0.6s ease-out;
    }

    .card-header-custom {
        background: var(--primary-gradient);
        padding: 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
        overflow: hidden;
    }

    .card-header-custom::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, transparent 48%, rgba(255, 255, 255, 0.1) 50%, transparent 52%);
        background-size: 200% 200%;
        animation: shine 10s infinite linear;
    }

    @keyframes shine {
        0% {
            background-position: 200% 0;
        }

        100% {
            background-position: -200% 0;
        }
    }

    .card-header-title {
        color: white;
        margin: 0;
        font-weight: 700;
        font-size: 1.75rem;
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .form-label {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .input-group-text {
        background-color: #f8fafc;
        border: 2px solid #e5e7eb;
        border-right: none;
        color: #64748b;
        transition: all 0.3s ease;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        border: 2px solid #e5e7eb;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        background-color: #fff;
    }

    .input-group .form-control,
    .input-group .form-select {
        border-top-right-radius: 12px;
        border-bottom-right-radius: 12px;
        border-left: none;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #6366f1;
        box-shadow: none;
    }

    .input-group:focus-within .input-group-text {
        border-color: #6366f1;
        color: #6366f1;
    }

    .form-control:focus::placeholder {
        color: #a5b4fc;
    }

    .section-title {
        color: var(--text-secondary);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 700;
        margin: 2rem 0 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .section-title::after {
        content: '';
        flex-grow: 1;
        height: 1px;
        background: #e5e7eb;
    }

    .btn-action {
        border-radius: 12px;
        padding: 0.75rem 1.5rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: none;
    }

    .btn-create {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3);
    }

    .btn-create:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px -3px rgba(99, 102, 241, 0.4);
        color: white;
    }

    .btn-back {
        background: white;
        color: var(--text-primary);
        border: 1px solid #e5e7eb;
    }

    .btn-back:hover {
        background: #f9fafb;
        border-color: #d1d5db;
        transform: translateY(-2px);
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Upload Box Style */
    .upload-box {
        border: 2px dashed #cbd5e1;
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        transition: all 0.3s ease;
        background: #f8fafc;
        cursor: pointer;
        position: relative;
    }

    .upload-box:hover {
        border-color: #6366f1;
        background: #eef2ff;
    }

    .upload-box input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
</style>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-trophy"></i>
                        Create New Tournament
                    </h4>
                </div>

                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-3 border-0 shadow-sm d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form id="createTournamentForm" method="POST" action="" enctype="multipart/form-data"
                        class="needs-validation" novalidate>

                        <!-- Basic Info Section -->
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <label class="form-label">Tournament Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-signature"></i>
                                    </span>
                                    <input type="text" class="form-control" name="tournament_name" required
                                        placeholder="e.g. Premier League 2024">
                                </div>
                                <div class="invalid-feedback">Please provide a tournament name.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Venue</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </span>
                                    <input type="text" class="form-control" name="venue"
                                        placeholder="e.g. City Stadium">
                                </div>
                            </div>
                        </div>

                        <!-- Schedule Section -->
                        <div class="section-title">
                            <i class="fas fa-calendar-alt"></i> Schedule
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-hourglass-start"></i>
                                    </span>
                                    <input type="date" class="form-control" name="start_date" required>
                                </div>
                                <div class="invalid-feedback">Please select a start date.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-hourglass-end"></i>
                                    </span>
                                    <input type="date" class="form-control" name="end_date" required>
                                </div>
                                <div class="invalid-feedback">Please select an end date.</div>
                            </div>
                        </div>

                        <!-- Format Configuration -->
                        <div class="section-title">
                            <i class="fas fa-cogs"></i> Configuration
                        </div>

                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label">Format <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-list-ul"></i>
                                    </span>
                                    <select class="form-select" name="format" required>
                                        <option value="" disabled selected>Select Format</option>
                                        <option value="T20">T20</option>
                                        <option value="ODI">ODI</option>
                                        <option value="Test">Test</option>
                                        <option value="T10">T10</option>
                                        <option value="The Hundred">The Hundred</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select the tournament format.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Overs <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-bowling-ball"></i>
                                    </span>
                                    <select class="form-select" name="overs" required>
                                        <option value="6">6 Overs</option>
                                        <option value="8">8 Overs</option>
                                        <option value="10">10 Overs</option>
                                        <option value="20">20 Overs</option>
                                        <option value="50">50 Overs</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select the number of overs.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ground Type</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-leaf"></i>
                                    </span>
                                    <select class="form-select" name="ground_type">
                                        <option value="Grass">Grass</option>
                                        <option value="Turf">Turf</option>
                                        <option value="Artificial">Artificial</option>
                                        <option value="Concrete">Concrete</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Financials & Logo -->
                        <div class="section-title">
                            <i class="fas fa-coins"></i> Details & Assets
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="row g-4">
                                    <div class="col-12">
                                        <label class="form-label">Prize Amount (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-award"></i>
                                            </span>
                                            <input type="number" class="form-control" name="prize_amount"
                                                placeholder="100000">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Entry Fee (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-ticket-alt"></i>
                                            </span>
                                            <input type="number" class="form-control" name="entry_fee" placeholder="500"
                                                min="0">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Max Teams <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-users"></i>
                                            </span>
                                            <input type="number" class="form-control" name="max_teams" min="2" max="16"
                                                value="8" required>
                                        </div>
                                        <div class="invalid-feedback">Max teams (2-16).</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tournament Logo</label>
                                <div class="upload-box mb-3 d-flex flex-column justify-content-center align-items-center" style="height: 160px;">
                                    <input type="file" name="tournament_logo" id="tournamentLogo" accept="image/*">
                                    <div class="text-center" id="uploadPlaceholder">
                                        <i class="fas fa-image text-muted fs-2 mb-2"></i>
                                        <h6 class="fw-bold mb-1">Upload Logo</h6>
                                        <small class="text-muted d-block small">PNG or JPG</small>
                                    </div>
                                    <div id="logoPreview" class="d-none w-100 h-100 text-center">
                                        <img src="" alt="Preview" class="h-100 rounded-3 shadow-sm object-fit-contain">
                                    </div>
                                </div>

                                <label class="form-label">Tournament Banner</label>
                                <div class="upload-box d-flex flex-column justify-content-center align-items-center" style="height: 160px;">
                                    <input type="file" name="tournament_banner" id="tournamentBanner" accept="image/*">
                                    <div class="text-center" id="bannerPlaceholder">
                                        <i class="fas fa-panorama text-muted fs-2 mb-2"></i>
                                        <h6 class="fw-bold mb-1">Upload Banner</h6>
                                        <small class="text-muted d-block small">Recommended: 1200x400</small>
                                    </div>
                                    <div id="bannerPreview" class="d-none w-100 h-100 text-center">
                                        <img src="" alt="Preview" class="h-100 rounded-3 shadow-sm object-fit-cover w-100">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"
                                    placeholder="Enter tournament details..."></textarea>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center pt-5 mt-4 border-top">
                            <button type="button" class="btn btn-action btn-back" onclick="window.history.back()">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="submit" class="btn btn-action btn-create">
                                <i class="fas fa-save"></i> Create Tournament
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('createTournamentForm').addEventListener('submit', function (e) {
        const form = this;

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const startDate = new Date(this.start_date.value);
        const endDate = new Date(this.end_date.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Validate dates
        if (startDate < today) {
            e.preventDefault();
            alert('Start date cannot be in the past.');
            return;
        }

        if (endDate < startDate) {
            e.preventDefault();
            alert('End date must be after start date.');
            return;
        }

        // Add loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';
        submitBtn.disabled = true;

        // Re-enable after 10 seconds as fallback
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);
    });

    // Logo preview logic
    document.getElementById('tournamentLogo').addEventListener('change', function (e) {
        const previewContainer = document.getElementById('logoPreview');
        const uploadPlaceholder = document.getElementById('uploadPlaceholder');
        const previewImg = previewContainer.querySelector('img');

        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
                uploadPlaceholder.classList.add('d-none');
            }
            reader.readAsDataURL(this.files[0]);
        } else {
            previewContainer.classList.add('d-none');
            uploadPlaceholder.classList.remove('d-none');
        }
    });

    // Banner preview logic
    document.getElementById('tournamentBanner').addEventListener('change', function (e) {
        const previewContainer = document.getElementById('bannerPreview');
        const bannerPlaceholder = document.getElementById('bannerPlaceholder');
        const previewImg = previewContainer.querySelector('img');

        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
                bannerPlaceholder.classList.add('d-none');
            }
            reader.readAsDataURL(this.files[0]);
        } else {
            previewContainer.classList.add('d-none');
            bannerPlaceholder.classList.remove('d-none');
        }
    });

    // Set minimum date for start date
    document.addEventListener('DOMContentLoaded', function () {
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');

        const today = new Date().toISOString().split('T')[0];
        startDateInput.min = today;

        startDateInput.addEventListener('change', function () {
            endDateInput.min = this.value;
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
