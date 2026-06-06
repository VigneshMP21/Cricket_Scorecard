<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_once '../includes/html2image_utils.php';


require_login();

// Auto-migrate schema
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_image_url VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE teams ADD COLUMN team_logo_url VARCHAR(255) DEFAULT NULL, ADD COLUMN team_logo_public_id VARCHAR(100) DEFAULT NULL");
} catch (PDOException $e) {}

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

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

function uploadToCloudinary($filePath, $folder = 'teams')
{
    if (!$filePath || !file_exists($filePath)) {
        error_log("❌ Invalid file path for Cloudinary: " . $filePath);
        return null;
    }

    try {
        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($filePath, [
            'folder' => $folder,
            'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ""
        ]);

        if (!empty($result['secure_url'])) {
            error_log("✅ Cloudinary SDK SUCCESS ($folder): " . $result['secure_url']);
            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id']
            ];
        } else {
            error_log("❌ Cloudinary SDK Upload Failed: No secure_url in response");
            return null;
        }
    } catch (Exception $e) {
        error_log("❌ Cloudinary SDK Exception ($folder): " . $e->getMessage());
        return null;
    }
}

function generateTeamAnnouncementBanner(array $data): ?string
{
    $team_name = htmlspecialchars((string) ($data['team_name'] ?? 'Team'), ENT_QUOTES, 'UTF-8');
    $team_code = htmlspecialchars((string) ($data['team_code'] ?? 'TM'), ENT_QUOTES, 'UTF-8');
    $team_color = htmlspecialchars((string) ($data['team_color'] ?? '#0d6efd'), ENT_QUOTES, 'UTF-8');
    $captain_name = htmlspecialchars((string) ($data['captain_name'] ?? 'Captain TBA'), ENT_QUOTES, 'UTF-8');
    $vice_captain_name = htmlspecialchars((string) ($data['vice_captain_name'] ?? 'Vice Captain TBA'), ENT_QUOTES, 'UTF-8');
    $tournament_name = htmlspecialchars((string) ($data['tournament_name'] ?? 'Open Registration'), ENT_QUOTES, 'UTF-8');
    $member_count = (int) ($data['member_count'] ?? 0);
    $logo_url = htmlspecialchars((string) ($data['logo_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $captain_image_url = htmlspecialchars((string) ($data['captain_image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $vice_captain_image_url = htmlspecialchars((string) ($data['vice_captain_image_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    $logo_markup = $logo_url !== ''
        ? "<img class='team-logo' src='{$logo_url}' alt='Team logo' />"
        : "<div class='team-logo-fallback'>{$team_code}</div>";
    $captain_image = $captain_image_url !== ''
        ? "<img class='captain-image' src='{$captain_image_url}' alt='Captain image' />"
        : '';
    $vice_captain_image = $vice_captain_image_url !== ''
        ? "<img class='captain-image' src='{$vice_captain_image_url}' alt='Vice captain image' />"
        : '';

    $html = "
    <div class='banner-shell'>
        <div class='glow glow-one'></div>
        <div class='glow glow-two'></div>
        <div class='content'>
            <div class='eyebrow'>New Team Created</div>
            <div class='headline'>{$team_name}</div>
            <div class='subline'>Competing in {$tournament_name}</div>
            <div class='pill-row'>
                <div class='pill'>Code: {$team_code}</div>
                <div class='pill'>Players: {$member_count}</div>
            </div>
            <div class='lead-row'>
                <div class='captain-card'>
                    {$captain_image}
                    <div class='captain-copy'>
                        <div class='captain-label'>Captain</div>
                        <div class='captain-name'>{$captain_name}</div>
                    </div>
                </div>
                <div class='captain-card'>
                    {$vice_captain_image}
                    <div class='captain-copy'>
                        <div class='captain-label'>Vice Captain</div>
                        <div class='captain-name'>{$vice_captain_name}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class='logo-panel'>{$logo_markup}</div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 1024px; height: 576px; font-family: 'Outfit', sans-serif; }
    .banner-shell {
        width: 1024px; height: 576px; position: relative; overflow: hidden; color: #f8fafc;
        background:
            radial-gradient(circle at 12% 18%, rgba(255, 255, 255, 0.09), transparent 24%),
            linear-gradient(145deg, " . $team_color . " 0%, #0f172a 58%, #020617 100%);
    }
    .glow { position: absolute; border-radius: 999px; filter: blur(6px); opacity: 0.85; }
    .glow-one { width: 340px; height: 340px; right: -80px; top: -100px; background: rgba(251, 191, 36, 0.22); }
    .glow-two { width: 260px; height: 260px; left: -50px; bottom: -70px; background: rgba(59, 130, 246, 0.2); }
    .content { position: relative; z-index: 2; padding: 58px; width: 64%; display: flex; flex-direction: column; gap: 18px; }
    .eyebrow {
        display: inline-flex; width: fit-content; padding: 10px 18px; border-radius: 999px;
        background: rgba(15, 23, 42, 0.42); border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.18em; color: #dbeafe;
    }
    .headline { font-size: 62px; line-height: 1.02; font-weight: 800; max-width: 560px; }
    .subline { font-size: 23px; color: #dbeafe; }
    .pill-row { display: flex; gap: 14px; margin-top: 8px; }
        .pill {
            padding: 12px 18px; border-radius: 999px; background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.14); font-size: 18px; font-weight: 600;
        }
        .lead-row {
            margin-top: 20px; width: 100%; max-width: 660px;
            display: flex; gap: 18px;
        }
        .captain-card {
            flex: 1; min-height: 112px; padding: 18px 20px; border-radius: 26px;
            background: rgba(15, 23, 42, 0.58); border: 1px solid rgba(255, 255, 255, 0.14);
            display: flex; align-items: center; gap: 16px;
        }
        .captain-image {
            width: 74px; height: 74px; border-radius: 22px; object-fit: cover; border: 2px solid rgba(255, 255, 255, 0.22);
        }
        .captain-copy { min-width: 0; }
        .captain-label { font-size: 13px; text-transform: uppercase; letter-spacing: 0.14em; color: #93c5fd; margin-bottom: 6px; }
        .captain-name { font-size: 24px; font-weight: 700; line-height: 1.1; }
    .logo-panel {
        position: absolute; right: 58px; top: 92px; width: 300px; height: 300px; z-index: 2;
        border-radius: 42px; background: rgba(248, 250, 252, 0.10); border: 1px solid rgba(248, 250, 252, 0.16);
        display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 24px 48px rgba(2, 6, 23, 0.28);
    }
    .team-logo { width: 100%; height: 100%; object-fit: contain; padding: 26px; }
    .team-logo-fallback { font-size: 74px; font-weight: 800; letter-spacing: 0.08em; color: #ffffff; }";

    $image_url = generate_html2image_link($html, $css, 1024, 576, 2200);
    return upload_generated_image_to_cloudinary($image_url, 'team_notifications');
}

// Fetch available players
$players = [];
try {
    $stmt = $pdo->query("SELECT id, name, profile_image, playing_role FROM users WHERE role = 'player' AND id NOT IN (SELECT player_id FROM team_players) ORDER BY name");
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch all tournaments for dropdown
$all_tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name ASC");
    $all_tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['team_name'])) {
            throw new Exception("Team Name is required.");
        }
        if (empty($_POST['team_code'])) {
            throw new Exception("Team Short Code is required.");
        }

        $selected_user_ids = array_filter(array_map('trim', explode(',', $_POST['selected_players'] ?? '')));
        if (count($selected_user_ids) < 2) {
            throw new Exception("Minimum 2 players are required for the squad.");
        }

        if (empty($_POST['captain'])) {
            throw new Exception("Please select a Team Captain.");
        }

        if (empty($_POST['vice_captain'])) {
            throw new Exception("Please select a Team Vice Captain.");
        }

        if ($_POST['captain'] === $_POST['vice_captain']) {
            throw new Exception("Captain and Vice Captain must be different players.");
        }

        // Player IDs are user IDs since we merged players into users
        $player_ids = $selected_user_ids;
        $captain_player_id = $_POST['captain'];
        $vice_captain_player_id = !empty($_POST['vice_captain']) ? $_POST['vice_captain'] : null;

        // Handle logo upload
        $logo_filename = '';
        if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'avif', 'heif', 'webp', 'svg'];
            $filename = $_FILES['team_logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $newname = uniqid() . '.' . $ext;
                $destination = '../uploads/teams/' . $newname;
                if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $destination)) {
                    $logo_filename = $newname;
                } else {
                    throw new Exception("Failed to upload team logo.");
                }
            } else {
                throw new Exception("Invalid logo format. Supported formats: JPEG, JPG, PNG, GIF, AVIF, HEIF, WEBP, SVG.");
            }
        }

        // ─── Cloudinary Upload ───────────────────────────────────────────
        $team_logo_url = null;
        $team_logo_public_id = null;
        if ($logo_filename) {
            $logo_full_path = '../uploads/teams/' . $logo_filename;
            $logo_res = uploadToCloudinary($logo_full_path);
            if ($logo_res) {
                $team_logo_url = $logo_res['url'];
                $team_logo_public_id = $logo_res['public_id'];
            }
        }

        // Insert team
        $stmt = $pdo->prepare("
            INSERT INTO teams (
                team_name, team_code, team_color, team_logo, team_logo_url, team_logo_public_id, captain_id, vice_captain_id, tournament_id, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            trim($_POST['team_name']),
            strtoupper(trim($_POST['team_code'])),
            $_POST['team_color'] ?? '#0d6efd',
            $logo_filename,
            $team_logo_url,
            $team_logo_public_id,
            $captain_player_id,
            $vice_captain_player_id,
            !empty($_POST['tournament_id']) ? $_POST['tournament_id'] : null,
            $_SESSION['user_id']
        ]);

        $team_id = $pdo->lastInsertId();

        // Insert team players
        $stmt = $pdo->prepare("INSERT INTO team_players (team_id, player_id) VALUES (?, ?)");
        foreach ($player_ids as $player_id) {
            $stmt->execute([$team_id, $player_id]);
        }

        // 🔔 Send Segmented Notifications
        try {
            $team_name_notify = trim($_POST['team_name']);
            
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $base_url = "$protocol://$host/CPT_LEAGUE/";

            // 1. Identify Target Groups
            $placeholders = implode(',', array_fill(0, count($player_ids), '?'));

            // Team Members OneSignal IDs
            $stmtTM = $pdo->prepare("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE user_id IN ($placeholders) AND onesignal_player_id IS NOT NULL");
            $stmtTM->execute($player_ids);
            $team_member_onesignal_ids = $stmtTM->fetchAll(PDO::FETCH_COLUMN);

            // Others (other players and guests)
            $stmtOthers = $pdo->prepare("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE (user_id NOT IN ($placeholders) OR user_id IS NULL) AND onesignal_player_id IS NOT NULL");
            $stmtOthers->execute($player_ids);
            $other_player_ids = $stmtOthers->fetchAll(PDO::FETCH_COLUMN);

            // Ensure mutual exclusivity (safety first)
            $other_player_ids = array_diff($other_player_ids, $team_member_onesignal_ids);

            // Fetch Captain Profile Image for Notification Icons
            $stmtCap = $pdo->prepare("SELECT name, profile_image, profile_image_url FROM users WHERE id = ?");
            $stmtCap->execute([$captain_player_id]);
            $captain = $stmtCap->fetch(PDO::FETCH_ASSOC);

            $captain_image_url = null;
            if (!empty($captain['profile_image_url'])) {
                $captain_image_url = $captain['profile_image_url'];
            } elseif (!empty($captain['profile_image'])) {
                $cap_full_path = '../uploads/users/' . $captain['profile_image'];
                $cap_res = uploadToCloudinary($cap_full_path, 'users');
                if ($cap_res) {
                    $captain_image_url = $cap_res['url'];
                    try {
                        $updStmt = $pdo->prepare("UPDATE users SET profile_image_url = ? WHERE id = ?");
                        $updStmt->execute([$captain_image_url, $captain_player_id]);
                    } catch (PDOException $e) { /* ignore */ }
                } else {
                    $captain_image_url = $base_url . 'uploads/users/' . $captain['profile_image'];
                }
            }
            if (!$captain_image_url) {
                $captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1745678901/default_user_ovz6zt.png";
            }

            $vice_captain = null;
            $vice_captain_image_url = null;
            if ($vice_captain_player_id) {
                $stmtViceCap = $pdo->prepare("SELECT name, profile_image, profile_image_url FROM users WHERE id = ?");
                $stmtViceCap->execute([$vice_captain_player_id]);
                $vice_captain = $stmtViceCap->fetch(PDO::FETCH_ASSOC);

                if (!empty($vice_captain['profile_image_url'])) {
                    $vice_captain_image_url = $vice_captain['profile_image_url'];
                } elseif (!empty($vice_captain['profile_image'])) {
                    $vc_full_path = '../uploads/users/' . $vice_captain['profile_image'];
                    $vc_res = uploadToCloudinary($vc_full_path, 'users');
                    if ($vc_res) {
                        $vice_captain_image_url = $vc_res['url'];
                        try {
                            $updStmt = $pdo->prepare("UPDATE users SET profile_image_url = ? WHERE id = ?");
                            $updStmt->execute([$vice_captain_image_url, $vice_captain_player_id]);
                        } catch (PDOException $e) { /* ignore */ }
                    } else {
                        $vice_captain_image_url = $base_url . 'uploads/users/' . $vice_captain['profile_image'];
                    }
                }
            }
            if (!$vice_captain_image_url) {
                $vice_captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1745678901/default_user_ovz6zt.png";
            }

            $tournament_name_notify = 'Open Registration';
            if (!empty($_POST['tournament_id'])) {
                $stmtTournament = $pdo->prepare("SELECT tournament_name FROM tournaments WHERE id = ?");
                $stmtTournament->execute([$_POST['tournament_id']]);
                $tournament = $stmtTournament->fetch(PDO::FETCH_ASSOC);
                if (!empty($tournament['tournament_name'])) {
                    $tournament_name_notify = $tournament['tournament_name'];
                }
            }

            $notification_banner_url = generateTeamAnnouncementBanner([
                'team_name' => $team_name_notify,
                'team_code' => strtoupper(trim($_POST['team_code'])),
                'team_color' => $_POST['team_color'] ?? '#0d6efd',
                'captain_name' => $captain['name'] ?? 'Captain TBA',
                'vice_captain_name' => $vice_captain['name'] ?? 'Vice Captain TBA',
                'tournament_name' => $tournament_name_notify,
                'member_count' => count($player_ids),
                'logo_url' => $team_logo_url,
                'captain_image_url' => $captain_image_url,
                'vice_captain_image_url' => $vice_captain_image_url,
            ]);
            $notification_big_picture = $notification_banner_url ?: ($team_logo_url ?: ($base_url . "assets/images/logo.jpg"));

            $notification_metadata = [
                'type' => 'team_created',
                'big_picture' => $notification_big_picture,
                'image' => $notification_big_picture,
                'large_icon' => $captain_image_url,
                'small_icon' => 'ic_stat_notify',
                'android_sound' => 'notification_sound'
            ];
            $click_url = $base_url . "view/view_team.php?team_id=$team_id";

            // 2. Send Notification to Team Members
            if (!empty($team_member_onesignal_ids)) {
                sendOneSignalNotification(
                    $team_member_onesignal_ids,
                    "🎉 You’ve Been Added to a Team!",
                    "You have been successfully added to the team '$team_name_notify'. Get ready to play and stay tuned for upcoming matches!",
                    $notification_metadata,
                    $click_url
                );
            }

            // 3. Send Notification to Everyone Else
            if (!empty($other_player_ids)) {
                sendOneSignalNotification(
                    $other_player_ids,
                    "📢 New Team Created!",
                    "A new team '$team_name_notify' has been created in the tournament. Stay prepared — matches and updates are coming soon!",
                    $notification_metadata,
                    $click_url
                );
            }

        } catch (Exception $no_err) {
            error_log("Team Creation Notification Error: " . $no_err->getMessage());
        }

        // Redirect to teams list with success message
        header("Location: ../NavBarList/teams.php?success=1");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Create Team";
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
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        border: 2px solid #e5e7eb;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        background-color: rgba(255, 255, 255, 0.8);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        background-color: #fff;
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

    /* Player Selection Styles */
    .player-list-card {
        background: white;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .player-list-header {
        padding: 1rem 1.25rem;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
        color: var(--text-primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .player-list-body {
        padding: 1rem;
        overflow-y: auto;
        max-height: 400px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 #f1f5f9;
        flex-grow: 1;
    }

    .player-list-body::-webkit-scrollbar {
        width: 6px;
    }

    .player-list-body::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 6px;
    }

    .player-item {
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        background: white;
        border: 1px solid #f3f4f6;
        border-radius: 12px;
        margin-bottom: 0.75rem;
        padding: 0.75rem;
    }

    .player-item:hover {
        transform: translateX(4px);
        border-color: #6366f1;
        background-color: #f5f3ff;
    }

    .player-item.selected {
        border-color: #10b981;
        background-color: #ecfdf5;
    }

    .player-item.selected:hover {
        border-color: #ef4444;
        background-color: #fef2f2;
    }

    .upload-area {
        border: 2px dashed #e5e7eb;
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        background: #f9fafb;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .upload-area:hover {
        border-color: #6366f1;
        background: #eef2ff;
        transform: translateY(-2px);
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

    .btn-create:disabled {
        background: #cbd5e1;
        transform: none;
        box-shadow: none;
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

    .preview-img {
        width: 120px;
        height: 120px;
        object-fit: contain;
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        background: white;
        padding: 0.5rem;
    }
</style>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-11 col-lg-12">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-users-cog"></i>
                        <?= $page_title ?>
                    </h4>
                </div>

                <!-- Body -->
                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-3 border-0 shadow-sm d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form id="createTeamForm" method="POST" enctype="multipart/form-data" class="needs-validation"
                        novalidate>
                        <input type="hidden" name="selected_players" id="selectedPlayersInput">

                        <div class="row g-4 mb-5">
                            <!-- Team Logo Section -->
                            <div class="col-lg-4">
                                <label class="form-label">Team Logo <span class="text-danger">*</span></label>
                                <div class="upload-area h-100 d-flex flex-column justify-content-center align-items-center"
                                    onclick="document.getElementById('teamLogo').click()">
                                    <input type="file" class="form-control d-none" id="teamLogo" name="team_logo"
                                        accept="image/*" required>
                                    <div class="preview mb-3" id="logoPreview">
                                        <div class="rounded-circle bg-white shadow-sm p-4 d-inline-block">
                                            <i class="fas fa-camera fa-3x text-primary opacity-50"></i>
                                        </div>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Click to Upload Logo</h6>
                                    <p class="text-muted small mb-0">JPEG, PNG, GIF, WEBP, AVIF or SVG (Max 2MB)</p>
                                </div>
                            </div>

                            <!-- Team Details Section -->
                            <div class="col-lg-8">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Team Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                                <i class="fas fa-signature text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 rounded-end-4"
                                                name="team_name" required placeholder="e.g. Mumbai Indians">
                                        </div>
                                        <div class="invalid-feedback">Please provide a team name.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Team Short Code <span
                                                class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                                <i class="fas fa-tag text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 rounded-end-4"
                                                name="team_code" maxlength="4" required placeholder="e.g. MI">
                                        </div>
                                        <div class="invalid-feedback">Please provide a team code (max 4 chars).</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Team Color</label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color w-100 rounded-4"
                                                name="team_color" value="#0d6efd" title="Choose team color">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Select Tournament</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                                <i class="fas fa-trophy text-muted"></i>
                                            </span>
                                            <select name="tournament_id"
                                                class="form-select border-start-0 rounded-end-4">
                                                <option value="">No Tournament</option>
                                                <?php foreach ($all_tournaments as $t): ?>
                                                    <option value="<?= $t['id'] ?>">
                                                        <?= htmlspecialchars($t['tournament_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Player Selection -->
                        <div class="section-title">
                            <i class="fas fa-user-friends"></i> Squad Selection
                        </div>

                        <?php if (empty($players)): ?>
                            <div class="alert alert-warning border-0 bg-opacity-10 bg-warning rounded-4 p-4 text-center">
                                <i class="fas fa-exclamation-triangle fa-2x mb-3 text-warning"></i>
                                <h5 class="alert-heading fw-bold">No Players Available</h5>
                                <p class="mb-0">There are no registered players available to add to this team. Please add
                                    players to the system first.</p>
                            </div>
                        <?php else: ?>

                            <div class="row g-4 mb-4">
                                <!-- Available Players -->
                                <div class="col-lg-6">
                                    <div class="player-list-card">
                                        <div class="player-list-header">
                                            <span>Available Players (<span
                                                    id="availableCount"><?= count($players) ?></span>)</span>
                                        </div>
                                        <div class="p-3 bg-white border-bottom">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0 ps-3">
                                                    <i class="fas fa-search text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control bg-light border-start-0"
                                                    placeholder="Search by name or role..." id="searchPlayers"
                                                    oninput="debounceSearch()">
                                            </div>
                                        </div>
                                        <div class="player-list-body" id="availablePlayers">
                                            <?php foreach ($players as $player): ?>
                                                <div class="player-item d-flex align-items-center"
                                                    data-player-id="<?= $player['id'] ?>" onclick="selectPlayer(this)">
                                                    <div class="position-relative">
                                                        <img src="<?= $player['profile_image'] ? '../uploads/users/' . $player['profile_image'] : '../images/default-player.jpg' ?>"
                                                            alt="<?= htmlspecialchars($player['name']) ?>"
                                                            class="rounded-circle" width="48" height="48"
                                                            style="object-fit: cover;">
                                                    </div>
                                                    <div class="ms-3 flex-grow-1">
                                                        <h6 class="mb-0 fw-bold text-dark">
                                                            <?= htmlspecialchars($player['name']) ?>
                                                        </h6>
                                                        <span class="badge bg-light text-secondary border mt-1">
                                                            <?= htmlspecialchars($player['playing_role']) ?>
                                                        </span>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-light rounded-circle shadow-sm"
                                                        style="width: 32px; height: 32px; padding: 0;">
                                                        <i class="fas fa-plus text-primary"></i>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Selected Players -->
                                <div class="col-lg-6">
                                    <div class="player-list-card">
                                        <div class="player-list-header bg-success bg-opacity-10 text-success">
                                            <span>Selected Squad (<span id="selectedCount">0</span>/15)</span>
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <div class="player-list-body bg-success bg-opacity-10" id="selectedPlayers">
                                            <!-- Selected players will appear here -->
                                            <div class="text-center text-muted py-5" id="emptyState">
                                                <i class="fas fa-arrow-left mb-2 opacity-50"></i>
                                                <p class="small">Select players from the list</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Leadership Selection -->
                            <div class="section-title">
                                <i class="fas fa-crown"></i> Team Leadership
                            </div>

                            <div class="row g-4 mb-5">
                                <div class="col-md-6">
                                    <label class="form-label">Captain <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span
                                            class="input-group-text bg-warning bg-opacity-10 border-warning border-opacity-25 border-end-0 rounded-start-4">
                                            <i class="fas fa-copyright text-warning"></i>
                                        </span>
                                        <select class="form-select border-start-0 rounded-end-4" name="captain"
                                            id="captainSelect" required disabled>
                                            <option value="">Select Captain</option>
                                        </select>
                                    </div>
                                    <div class="invalid-feedback">Please select a team captain.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Vice Captain</label>
                                    <div class="input-group">
                                        <span
                                            class="input-group-text bg-info bg-opacity-10 border-info border-opacity-25 border-end-0 rounded-start-4">
                                            <i class="fas fa-star-half-alt text-info"></i>
                                        </span>
                                        <select class="form-select border-start-0 rounded-end-4" name="vice_captain"
                                            id="viceCaptainSelect" disabled>
                                            <option value="">Select Vice Captain</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between align-items-center pt-4 border-top">
                            <button type="button" class="btn btn-action btn-back" onclick="window.history.back()">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-action btn-create" <?= empty($players) ? 'disabled' : '' ?>>
                                <i class="fas fa-check-circle"></i> Create Team
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cropper Modal -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-0">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">Crop Team Logo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="img-container" style="max-height: 500px; display: block; overflow: hidden;">
                    <img id="imageToCrop" src="" style="width: 100%; display: block; max-width: 100%;">
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" id="cropImageBtn">
                    <i class="fas fa-crop-alt me-2"></i>Crop & Save
                </button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

<script>
    const selectedPlayers = new Set();
    const availablePlayers = <?= json_encode($players) ?>;

    function selectPlayer(element) {
        const playerId = element.getAttribute('data-player-id');

        const button = element.querySelector('button');
        const icon = button.querySelector('i');

        if (selectedPlayers.has(playerId)) {
            // Remove from selected
            selectedPlayers.delete(playerId);
            element.classList.remove('selected');

            // Reset button/icon state
            button.className = 'btn btn-sm btn-light rounded-circle shadow-sm';
            icon.className = 'fas fa-plus text-primary';
        } else {
            // Check max players
            if (selectedPlayers.size >= 15) {
                alert('Maximum 15 players allowed per team');
                return;
            }

            // Add to selected
            selectedPlayers.add(playerId);
            element.classList.add('selected');

            // Update button/icon state to show selected
            button.className = 'btn btn-sm btn-success rounded-circle shadow-sm';
            icon.className = 'fas fa-check text-white';
        }

        updateSelectedCount();
        updateSelectedList();
        updateCaptainOptions();

        // Instead of completely hiding/showing in filter, we might want to just visually indicate selection.
        // But keeping existing logic: filterPlayers re-runs often, let's keep it simple.
        // The previous logic hid selected players from the available list.
        // Let's modify filterPlayers to toggle visibility based on selection status too if we want that behavior.
        // For now, let's stick to the previous behavior: Selected players stay in the list but marked selected.
        // Actually, previous behavior (lines 448-450) HID selected players from Available column.
        // Let's replicate that behavior.

        filterPlayers();
    }

    function updateSelectedList() {
        const selectedDiv = document.getElementById('selectedPlayers');
        const emptyState = document.getElementById('emptyState');

        // Clear list but keep empty state element potentially (or just re-add it if needed)
        selectedDiv.innerHTML = '';

        if (selectedPlayers.size === 0) {
            selectedDiv.appendChild(emptyState);
            return;
        }

        selectedPlayers.forEach(playerId => {
            const player = availablePlayers.find(p => p.id == playerId);
            if (player) {
                const div = document.createElement('div');
                div.className = 'player-item d-flex align-items-center bg-white border-0 shadow-sm mb-2';
                div.style.cursor = 'default';
                // We can add a remove button here
                div.innerHTML = `
                <div class="position-relative">
                    <img src="${player.profile_image ? '../uploads/users/' + player.profile_image : '../images/default-player.jpg'}"
                         alt="${player.name}"
                         class="rounded-circle" width="48" height="48" style="object-fit: cover;">
                </div>
                <div class="ms-3 flex-grow-1">
                    <h6 class="mb-0 fw-bold text-dark">${player.name}</h6>
                    <span class="badge bg-light text-secondary border mt-1">
                        ${player.playing_role}
                    </span>
                </div>
                <button type="button" class="btn btn-sm btn-danger rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0;" 
                        onclick="removePlayer('${player.id}')">
                    <i class="fas fa-times text-white"></i>
                </button>
            `;
                selectedDiv.appendChild(div);
            }
        });

        // We removed emptyState from DOM, so if we clear all, we need to create it again or just toggle display.
        // Simplified: always re-render.
    }

    function removePlayer(playerId) {
        // Find the original element in available list to toggle its state back
        const availableItem = document.querySelector(`.player-item[data-player-id="${playerId}"]`);
        if (availableItem) {
            selectPlayer(availableItem);
        } else {
            // Fallback if not visible
            selectedPlayers.delete(playerId);
            updateSelectedCount();
            updateSelectedList();
            updateCaptainOptions();
            filterPlayers();
        }
    }

    let searchTimeout;

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(filterPlayers, 200);
    }

    function filterPlayers() {
        const searchTerm = document.getElementById('searchPlayers').value.toLowerCase().trim();
        const players = document.querySelectorAll('#availablePlayers .player-item');

        if (searchTerm === '') {
            let availableCount = 0;
            players.forEach(player => {
                const playerId = player.getAttribute('data-player-id');
                const isSelected = selectedPlayers.has(playerId);
                if (isSelected) {
                    player.classList.add('d-none');
                } else {
                    player.classList.remove('d-none');
                    availableCount++;
                }
            });
            document.getElementById('availableCount').textContent = availableCount;
            return;
        }

        let availableCount = 0;
        players.forEach(player => {
            const playerId = player.getAttribute('data-player-id');
            const nameEl = player.querySelector('h6');
            const roleEl = player.querySelector('.badge');

            const playerName = nameEl ? nameEl.textContent.toLowerCase() : '';
            const playingRole = roleEl ? roleEl.textContent.toLowerCase() : '';

            const isSelected = selectedPlayers.has(playerId);
            const matchesSearch = playerName.includes(searchTerm) || playingRole.includes(searchTerm);

            // Hide if selected (move to right column) OR doesn't match search
            // Search filtering should work independently from player selection visibility
            const shouldShow = matchesSearch && !isSelected;

            if (shouldShow) {
                player.classList.remove('d-none');
                availableCount++;
            } else {
                player.classList.add('d-none');
            }
        });

        // Update available count in header
        document.getElementById('availableCount').textContent = availableCount;
    }

    function updateSelectedCount() {
        document.getElementById('selectedCount').textContent = selectedPlayers.size;
        document.getElementById('selectedPlayersInput').value = Array.from(selectedPlayers).join(',');
    }

    function updateCaptainOptions() {
        const captainSelect = document.getElementById('captainSelect');
        const viceCaptainSelect = document.getElementById('viceCaptainSelect');

        // Get current selections
        const currentCaptain = captainSelect.value;
        const currentVice = viceCaptainSelect.value;

        // Clear options
        captainSelect.innerHTML = '<option value="">Select Captain</option>';
        viceCaptainSelect.innerHTML = '<option value="">Select Vice Captain</option>';

        // Add selected players as options
        selectedPlayers.forEach(playerId => {
            const player = availablePlayers.find(p => p.id == playerId);
            if (player) {
                // Add to captain if not selected as vice
                if (playerId != currentVice) {
                    const option1 = new Option(player.name, playerId);
                    captainSelect.add(option1);
                }
                // Add to vice if not selected as captain
                if (playerId != currentCaptain) {
                    const option2 = new Option(player.name, playerId);
                    viceCaptainSelect.add(option2);
                }
            }
        });

        // Restore selections if still valid
        if (currentCaptain && captainSelect.querySelector(`option[value="${currentCaptain}"]`)) {
            captainSelect.value = currentCaptain;
        }
        if (currentVice && viceCaptainSelect.querySelector(`option[value="${currentVice}"]`)) {
            viceCaptainSelect.value = currentVice;
        }

        // Enable/disable selects
        captainSelect.disabled = selectedPlayers.size === 0;
        viceCaptainSelect.disabled = selectedPlayers.size === 0;
    }

    // Team logo preview
    // Team logo preview & Modal Cropper Logic
    let cropper;
    let cropSuccess = false;
    // Variables to be initialized on load
    let teamLogoInput;
    let imageToCrop;
    let cropModalEl;
    let cropModal;

    document.addEventListener('DOMContentLoaded', function () {
        teamLogoInput = document.getElementById('teamLogo');
        imageToCrop = document.getElementById('imageToCrop');
        cropModalEl = document.getElementById('cropModal');
        // Initialize Bootstrap modal (bootstrap is loaded in footer)
        cropModal = new bootstrap.Modal(cropModalEl);

        teamLogoInput.addEventListener('change', function (e) {
            if (this.files && this.files[0]) {
                cropSuccess = false;
                const file = this.files[0];
                const reader = new FileReader();
                reader.onload = function (e) {
                    imageToCrop.src = e.target.result;
                    cropModal.show();
                };
                reader.readAsDataURL(file);
            }
        });

        cropModalEl.addEventListener('shown.bs.modal', function () {
            cropper = new Cropper(imageToCrop, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.8,
                responsive: true,
            });
        });

        cropModalEl.addEventListener('hidden.bs.modal', function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            if (!cropSuccess) {
                teamLogoInput.value = ''; // Clear selection if cancelled
            }
        });

        document.getElementById('cropImageBtn').addEventListener('click', function () {
            if (!cropper) return;

            // Get cropped canvas
            const canvas = cropper.getCroppedCanvas({ width: 200, height: 200 });

            // Update Preview
            const preview = document.getElementById('logoPreview');
            preview.innerHTML = '';
            const croppedImg = document.createElement('img');
            croppedImg.className = 'preview-img';
            croppedImg.src = canvas.toDataURL('image/png');
            preview.appendChild(croppedImg);

            // Update Input File using DataTransfer
            canvas.toBlob(function (blob) {
                const file = new File([blob], "logo.png", { type: "image/png" });
                const dt = new DataTransfer();
                dt.items.add(file);
                teamLogoInput.files = dt.files;
            });

            cropSuccess = true;
            cropModal.hide();
        });
    });

    // Update captain options when selections change
    document.getElementById('captainSelect').addEventListener('change', updateCaptainOptions);
    document.getElementById('viceCaptainSelect').addEventListener('change', updateCaptainOptions);

    // Initialize
    filterPlayers();
    updateSelectedCount();

    // Form submission
    document.getElementById('createTeamForm').addEventListener('submit', function (e) {
        const form = this;
        const teamName = form.querySelector('input[name="team_name"]').value.trim();
        const teamCode = form.querySelector('input[name="team_code"]').value.trim();
        const teamLogo = document.getElementById('teamLogo').files.length;
        const captain = document.getElementById('captainSelect').value;
        const viceCaptain = document.getElementById('viceCaptainSelect').value;

        if (!teamLogo) {
            e.preventDefault();
            alert("Please upload a Team Logo.");
            return;
        }

        if (!teamName) {
            e.preventDefault();
            alert("Please provide a Team Name.");
            return;
        }

        if (!teamCode) {
            e.preventDefault();
            alert("Please provide a Team Short Code.");
            return;
        }

        if (selectedPlayers.size < 2) {
            e.preventDefault();
            alert('Minimum 2 players are required for the squad.');
            return;
        }

        if (!captain) {
            e.preventDefault();
            alert('Please select a Team Captain.');
            return;
        }

        if (!viceCaptain) {
            e.preventDefault();
            alert('Please select a Team Vice Captain.');
            return;
        }

        if (captain === viceCaptain) {
            e.preventDefault();
            alert('Captain and Vice Captain must be different players.');
            return;
        }

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            alert("Please fill in all required fields correctly.");
            return;
        }

        // Add loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalContent = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Creating...';
        submitBtn.disabled = true;

        // Re-enable after 10 seconds as fallback
        setTimeout(() => {
            submitBtn.innerHTML = originalContent;
            submitBtn.disabled = false;
        }, 10000);

        // Allow form to submit normally
    });
</script>

<?php require_once '../includes/footer.php'; ?>
