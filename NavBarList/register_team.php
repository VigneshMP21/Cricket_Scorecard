<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_once '../includes/notification_banner_utils.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        try {
            $dotenv->load();
        } catch (Exception $e) {
        }
    }
}

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

function uploadToCloudinary($filePath, $folder = 'teams')
{
    if (!$filePath || !file_exists($filePath)) {
        return null;
    }

    try {
        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($filePath, [
            'folder' => $folder,
            'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ""
        ]);

        if (!empty($result['secure_url'])) {
            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id']
            ];
        }
        return null;
    } catch (Exception $e) {
        error_log("Cloudinary Upload Error: " . $e->getMessage());
        return null;
    }
}

// Fetch available players
$available_players = [];
try {
    // Only fetch players who are not already in a team
    $stmt = $pdo->query("
        SELECT id, name, profile_image, playing_role 
        FROM users 
        WHERE role = 'player' 
        AND id NOT IN (SELECT player_id FROM team_players) 
        ORDER BY name
    ");
    $available_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch tournament if provided
$tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;
$tournament_name = "Not Selected";
if ($tournament_id) {
    try {
        $stmt = $pdo->prepare("SELECT tournament_name FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tournament) {
            $tournament_name = $tournament['tournament_name'];
        }
    } catch (PDOException $e) {
        // Handle error
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    try {
        // Validation
        if (empty($_POST['team_name']) || empty($_POST['team_code'])) {
            throw new Exception("Team Name and Short Code are required.");
        }

        // Duplicate Check
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE team_name = ? OR team_code = ?");
        $stmt->execute([trim($_POST['team_name']), strtoupper(trim($_POST['team_code']))]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Team Name or Short Code already exists.");
        }

        $squad = isset($_POST['squad']) ? $_POST['squad'] : [];
        $squad = array_filter($squad); // Remove empty values
        if (count($squad) < 11) {
            throw new Exception("Minimum 11 players required in squad.");
        }

        if (empty($_POST['captain'])) {
            throw new Exception("Captain is required.");
        }

        // Handle logo upload
        $logo_filename = '';
        if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['team_logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $newname = uniqid() . '.' . $ext;
                $destination = '../uploads/teams/' . $newname;
                if (!is_dir('../uploads/teams/')) mkdir('../uploads/teams/', 0777, true);
                if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $destination)) {
                    $logo_filename = $newname;
                }
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

        // Insert team with 'pending' status
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO teams (
                team_name, team_code, team_color, team_logo, team_logo_url, team_logo_public_id, captain_id, vice_captain_id, tournament_id, created_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([
            trim($_POST['team_name']),
            strtoupper(trim($_POST['team_code'])),
            $_POST['team_color'] ?? '#0d6efd',
            $logo_filename,
            $team_logo_url,
            $team_logo_public_id,
            $_POST['captain'],
            $_POST['vice_captain'] ?: null,
            $tournament_id,
            $_SESSION['user_id']
        ]);

        $team_id = $pdo->lastInsertId();

        // Insert squad
        $stmt = $pdo->prepare("INSERT INTO team_players (team_id, player_id) VALUES (?, ?)");
        foreach ($squad as $player_id) {
            $stmt->execute([$team_id, $player_id]);
        }

        $pdo->commit();

        // ─── Notification Feature ──────────────────────────────────────────
        // 1. Get All Admin Player IDs
        $admin_ids = getAdminPlayerIds($pdo);
        if (!empty($admin_ids)) {
            $team_name_raw = trim($_POST['team_name']);
            $tournament_label = $tournament_name !== 'Not Selected' ? $tournament_name : 'Open Registration';
            // 2. Send Notification
            $title = "Team Registration Request – " . $tournament_name;

            $content = "The team \"" . $team_name_raw . "\" has successfully requested to join the tournament \"" . $tournament_name . "\". Please review and approve the registration.";
            $title = "Team Registration Request - " . $tournament_label;
            $content = "The team \"" . $team_name_raw . "\" has successfully requested to join the tournament \"" . $tournament_label . "\". Please review and approve the registration.";
            
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $base_url = "$protocol://$host/CPT_LEAGUE/";

            // Fetch Captain Profile Image for Notification Icons
            $stmtCap = $pdo->prepare("SELECT name, profile_image, profile_image_url FROM users WHERE id = ?");
            $stmtCap->execute([$_POST['captain']]);
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
                        $updStmt->execute([$captain_image_url, $_POST['captain']]);
                    } catch (PDOException $e) { /* ignore */ }
                } else {
                    $captain_image_url = $base_url . 'uploads/users/' . $captain['profile_image'];
                }
            }
            if (!$captain_image_url) {
                $captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
            }

            $vice_captain = null;
            $vice_captain_image_url = null;
            if (!empty($_POST['vice_captain'])) {
                $stmtViceCap = $pdo->prepare("SELECT name, profile_image, profile_image_url FROM users WHERE id = ?");
                $stmtViceCap->execute([$_POST['vice_captain']]);
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
                            $updStmt->execute([$vice_captain_image_url, $_POST['vice_captain']]);
                        } catch (PDOException $e) { /* ignore */ }
                    } else {
                        $vice_captain_image_url = $base_url . 'uploads/users/' . $vice_captain['profile_image'];
                    }
                }
            }
            if (!$vice_captain_image_url) {
                $vice_captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
            }

            $notification_banner_url = generate_team_status_notification_banner([
                'eyebrow' => 'Registration Request',
                'team_name' => $team_name_raw,
                'subline' => 'Awaiting approval for ' . $tournament_label,
                'team_code' => strtoupper(trim($_POST['team_code'])),
                'team_color' => $_POST['team_color'] ?? '#0d6efd',
                'captain_name' => $captain['name'] ?? 'Captain TBA',
                'captain_label' => 'Captain',
                'vice_captain_name' => $vice_captain['name'] ?? 'Vice Captain TBA',
                'vice_captain_label' => 'Vice Captain',
                'secondary_stat_label' => 'Players',
                'secondary_stat_value' => (string) count($squad),
                'logo_url' => $team_logo_url,
                'captain_image_url' => $captain_image_url,
                'vice_captain_image_url' => $vice_captain_image_url,
                'folder' => 'team_registration_notifications',
            ]);
            $notification_big_picture = $notification_banner_url ?: ($team_logo_url ?: ($base_url . "assets/images/logo.jpg"));

            sendOneSignalNotification(
                $admin_ids, 
                $title, 
                $content, 
                [
                    'type' => 'team_registration_request',
                    'big_picture' => $notification_big_picture,
                    'image' => $notification_big_picture,
                    'large_icon' => $captain_image_url,
                    'small_icon' => 'ic_stat_notify',
                    'android_sound' => 'notification_sound'
                ], 
                $base_url . "NavBarList/team_request.php"
            );
        }
        // ───────────────────────────────────────────────────────────────────

        if ($is_ajax) {
            echo json_encode(['success' => true]);
            exit();
        }
        $success = true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($is_ajax) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
        $error = $e->getMessage();
    }
}

$page_title = "Team Registration";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f3f4f6;
    }

    .main-container {
        min-height: 100vh;
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        background-image: radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0px, transparent 50%),
                          radial-gradient(at 100% 0%, rgba(168, 85, 247, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        border-radius: 24px;
        overflow: hidden;
    }

    .card-header-custom {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        padding: 2rem;
        color: white;
    }

    .section-title {
        color: #6b7280;
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

    .player-select-wrapper {
        position: relative;
    }

    .player-select-wrapper .player-img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 5;
    }

    .player-select-wrapper select {
        padding-left: 50px;
    }

    .upload-area {
        border: 2px dashed #e5e7eb;
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        background: #f9fafb;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .upload-area:hover {
        border-color: #6366f1;
        background: #eef2ff;
    }

    #successOverlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        text-align: center;
        padding: 2rem;
    }

    .success-box {
        background: white;
        color: #1f2937;
        padding: 3rem;
        border-radius: 24px;
        max-width: 500px;
        animation: scaleIn 0.3s ease-out;
    }

    @keyframes scaleIn {
        from { transform: scale(0.9); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    /* Custom Searchable Dropdown */
    .custom-dropdown {
        position: relative;
        width: 100%;
        user-select: none;
    }

    .dropdown-selected {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 0.65rem 1rem;
        padding-left: 50px;
        display: flex;
        align-items: center;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
    }

    .dropdown-selected:hover {
        border-color: #6366f1;
    }

    .dropdown-selected .player-img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
    }

    .dropdown-selected i.arrow {
        position: absolute;
        right: 15px;
        transition: transform 0.3s;
    }

    .custom-dropdown.active .dropdown-selected i.arrow {
        transform: rotate(180deg);
    }

    .dropdown-menu-custom {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        margin-top: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        z-index: 1000;
        display: none;
        max-height: 300px;
        overflow-y: auto;
    }

    .custom-dropdown.active .dropdown-menu-custom {
        display: block;
        animation: slideInDown 0.2s ease-out;
    }

    @keyframes slideInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Important Note Overlay */
    #noteOverlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        z-index: 10000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .note-box {
        background: white;
        width: 100%;
        max-width: 500px;
        border-radius: 24px;
        overflow: hidden;
        animation: scaleIn 0.3s ease-out;
        box-shadow: 0 20px 50px rgba(0,0,0,0.3);
    }

    .note-header {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        padding: 1.5rem;
        color: white;
        text-align: center;
    }

    .note-body {
        padding: 2.5rem;
        text-align: center;
    }

    .note-footer {
        padding: 1.5rem;
        text-align: center;
        border-top: 1px solid #f3f4f6;
    }

    .remove-player-btn {
        position: absolute;
        right: -10px;
        top: -10px;
        width: 24px;
        height: 24px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 10;
        border: 2px solid white;
        transition: all 0.2s;
    }

    .remove-player-btn:hover {
        background: #dc2626;
        transform: scale(1.1);
    }

    .dropdown-search-wrapper {
        padding: 10px;
        position: sticky;
        top: 0;
        background: #fff;
        border-bottom: 1px solid #f3f4f6;
        z-index: 10;
    }

    .dropdown-search {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 5px 10px;
        font-size: 0.9rem;
    }

    .dropdown-search:focus {
        border-color: #6366f1;
        outline: none;
    }

    .dropdown-item-custom {
        padding: 10px 15px;
        display: flex;
        align-items: center;
        cursor: pointer;
        transition: background 0.2s;
    }

    .dropdown-item-custom:hover {
        background: #f5f3ff;
    }

    .dropdown-item-custom.hidden {
        display: none !important;
    }

    .dropdown-item-custom.selected {
        background: #ecfdf5;
        color: #059669;
    }

    .dropdown-item-custom img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        margin-right: 12px;
        object-fit: cover;
    }
</style>

<div id="noteOverlay">
    <div class="note-box">
        <div class="note-header">
            <h3 class="fw-bold mb-0">Important Note</h3>
        </div>
        <div class="note-body">
            <p class="mb-4 text-muted">Before creating a team, please note the following:</p>
            <p class="fw-bold mb-3">Only players who are already registered in the system will appear in the player selection list.</p>
            <p class="text-muted">If your team members are not registered yet, please register all players first. After completing player registrations, you can proceed to create your team.</p>
        </div>
        <div class="note-footer">
            <button onclick="closeNoteOverlay()" class="btn btn-warning rounded-pill px-5 fw-bold text-white">I Understand</button>
        </div>
    </div>
</div>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">
            <div class="glass-card">
                <div class="card-header-custom p-4">
                    <div class="row align-items-center">
                        <div class="col-md-2 col-12 text-start mb-3 mb-md-0">
                            <a href="tournament_list.php" class="btn btn-link text-white text-decoration-none p-0">
                                <i class="fas fa-arrow-left me-1"></i> Back
                            </a>
                        </div>
                        <div class="col-md-8 col-12 text-center">
                            <h2 class="fw-bold mb-0">Team Registration</h2>
                        </div>
                        <div class="col-md-2 d-none d-md-block"></div>
                    </div>
                    <p class="opacity-75 mb-0 mt-2 text-center">Create your squad and join the tournament</p>
                </div>

                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form id="regForm" method="POST" enctype="multipart/form-data">
                        <!-- Team Information -->
                        <div class="section-title">Team Information</div>
                        <div class="row g-4 mb-5">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Team Logo</label>
                                <div class="upload-area" onclick="document.getElementById('teamLogo').click()">
                                    <input type="file" id="teamLogo" name="team_logo" class="d-none" accept="image/*">
                                    <div id="logoPreview">
                                        <i class="fas fa-camera fa-2x text-muted mb-2"></i>
                                        <p class="small text-muted mb-0">Click to upload</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Team Name</label>
                                        <input type="text" name="team_name" class="form-control rounded-3" required placeholder="Full name of your team">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Short Code</label>
                                        <input type="text" name="team_code" class="form-control rounded-3" maxlength="4" required placeholder="e.g. MI, RCB">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Team Color</label>
                                        <input type="color" name="team_color" class="form-control form-control-color w-100 rounded-3" value="#6366f1">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Tournament</label>
                                        <input type="text" class="form-control rounded-3 bg-light" value="<?= htmlspecialchars($tournament_name) ?>" disabled>
                                        <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Squad Selection -->
                        <div class="section-title">Squad Selection (11-15 Players)</div>
                        
                        <div id="squadContainer" class="row g-3">
                            <?php for($i=1; $i<=11; $i++): ?>
                                <div class="col-md-6 squad-field">
                                    <div class="custom-dropdown" id="dropdown-<?= $i ?>">
                                        <div class="dropdown-selected" onclick="toggleDropdown(this)">
                                            <img src="../images/default-player.jpg" class="player-img me-2">
                                            <span class="selected-text">Select Player <?= $i ?></span>
                                            <i class="fas fa-chevron-down arrow"></i>
                                        </div>
                                        <div class="dropdown-menu-custom">
                                            <div class="dropdown-search-wrapper">
                                                <input type="text" class="dropdown-search" placeholder="Search player..." onclick="event.stopPropagation()" oninput="filterLocalList(this)">
                                            </div>
                                            <div class="dropdown-list">
                                                <div class="dropdown-item-custom" data-id="" data-img="../images/default-player.jpg" onclick="selectFromCustom(this)">
                                                    <img src="../images/default-player.jpg">
                                                    <div>
                                                        <div class="fw-bold">None / Remove</div>
                                                    </div>
                                                </div>
                                                <?php foreach($available_players as $p): ?>
                                                    <div class="dropdown-item-custom" 
                                                         data-id="<?= $p['id'] ?>" 
                                                         data-name="<?= htmlspecialchars($p['name']) ?>"
                                                         data-role="<?= htmlspecialchars($p['playing_role']) ?>"
                                                         data-img="<?= $p['profile_image'] ? '../uploads/users/'.$p['profile_image'] : '../images/default-player.jpg' ?>" 
                                                         onclick="selectFromCustom(this)">
                                                        <img src="<?= $p['profile_image'] ? '../uploads/users/'.$p['profile_image'] : '../images/default-player.jpg' ?>">
                                                        <div>
                                                            <div class="fw-bold"><?= htmlspecialchars($p['name']) ?></div>
                                                            <div class="small text-muted"><?= htmlspecialchars($p['playing_role']) ?></div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <input type="hidden" name="squad[]" class="player-hidden-input" required>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <div class="text-end mt-3">
                            <button type="button" id="addPlayerBtn" class="btn btn-outline-primary rounded-pill btn-sm fw-bold">
                                <i class="fas fa-plus me-1"></i> Add Player
                            </button>
                        </div>

                        <!-- Leadership -->
                        <div class="section-title">Leadership</div>
                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Captain</label>
                                <select name="captain" id="captainSelect" class="form-select rounded-3" required>
                                    <option value="">Select Captain</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Vice-Captain</label>
                                <select name="vice_captain" id="viceCaptainSelect" class="form-select rounded-3">
                                    <option value="">Select Vice-Captain</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-grid gap-3 pt-4 border-top">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm">
                                <i class="fas fa-check-circle me-2"></i> Register Team
                            </button>
                            <button type="button" onclick="window.history.back()" class="btn btn-link text-muted text-decoration-none">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>



<div id="successOverlay" style="display: none;">
    <div class="success-box">
        <i class="fas fa-check-circle fa-4x text-success mb-4"></i>
        <h2 class="fw-bold mb-3">Registration Successful!</h2>
        <p class="text-muted mb-4">
            Your Team is registered successfully.<br>
            It may take a few minutes to appear because the management will verify your team before adding it.
        </p>
        <button onclick="window.location.href='tournament_list.php'" class="btn btn-primary rounded-pill px-5 fw-bold">OK</button>
    </div>
</div>

<?php if (isset($success)): ?>
    <script>document.getElementById('successOverlay').style.display = 'flex';</script>
<?php endif; ?>

<!-- Cropper Modal -->
<div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Crop Logo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center">
                <img id="cropImage" src="" style="max-width: 100%;">
            </div>
            <div class="modal-footer">
                <button id="saveCropBtn" class="btn btn-primary rounded-pill px-4">Crop & Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Load scripts at the end -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

<script>

    let cropper;
    window.addEventListener('load', function() {
        // Overlay logic: show if show_note=1 in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('show_note') === '1') {
            document.getElementById('noteOverlay').style.display = 'flex';
            
            // Remove show_note from URL to prevent showing on refresh
            urlParams.delete('show_note');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }

        const teamLogoInput = document.getElementById('teamLogo');
        const cropModalEl = document.getElementById('cropModal');
        const cropImage = document.getElementById('cropImage');
        let cropModal;

        if (typeof bootstrap !== 'undefined' && cropModalEl) {
            cropModal = new bootstrap.Modal(cropModalEl);
        }

        if (teamLogoInput) {
            teamLogoInput.addEventListener('change', function(e) {
                if (this.files && this.files[0] && cropModal) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        cropImage.src = event.target.result;
                        cropModal.show();
                    };
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

        if (cropModalEl) {
            cropModalEl.addEventListener('shown.bs.modal', function () {
                cropper = new Cropper(cropImage, {
                    aspectRatio: 1,
                    viewMode: 1
                });
            });

            cropModalEl.addEventListener('hidden.bs.modal', function () {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });
        }

        const saveCropBtn = document.getElementById('saveCropBtn');
        if (saveCropBtn) {
            saveCropBtn.addEventListener('click', function() {
                if (!cropper) return;
                const canvas = cropper.getCroppedCanvas({ width: 200, height: 200 });
                const preview = document.getElementById('logoPreview');
                if (preview) {
                    preview.innerHTML = '';
                    const img = document.createElement('img');
                    img.src = canvas.toDataURL();
                    img.style.width = '80px';
                    img.style.borderRadius = '50%';
                    preview.appendChild(img);
                }

                canvas.toBlob(blob => {
                    const file = new File([blob], 'logo.png', { type: 'image/png' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    if (teamLogoInput) teamLogoInput.files = dt.files;
                });
                if (cropModal) cropModal.hide();
            });
        }
    });

    // Player Selection Logic
    const availablePlayers = <?= json_encode($available_players) ?>;
    let playerCount = 11;

    // Custom Dropdown Logic
    window.toggleDropdown = function(el) {
        const dropdown = el.closest('.custom-dropdown');
        const isActive = dropdown.classList.contains('active');
        
        document.querySelectorAll('.custom-dropdown.active').forEach(d => {
            if (d !== dropdown) d.classList.remove('active');
        });
        
        dropdown.classList.toggle('active');
        if (!isActive) {
            const searchInput = dropdown.querySelector('.dropdown-search');
            if (searchInput) {
                searchInput.value = '';
                filterLocalList(searchInput);
                setTimeout(() => searchInput.focus(), 10);
            }
        }
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-dropdown')) {
            document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        }
    });

    window.filterLocalList = function(input) {
        const dropdown = input.closest('.custom-dropdown');
        const term = input.value.toLowerCase().trim();
        const items = dropdown.querySelectorAll('.dropdown-item-custom');
        const selectedIds = Array.from(document.querySelectorAll('.player-hidden-input'))
            .map(i => i.value)
            .filter(id => id !== "");
        const currentInput = dropdown.querySelector('.player-hidden-input');
        const currentVal = currentInput ? currentInput.value : "";

        items.forEach(item => {
            const id = item.getAttribute('data-id');
            if (id === "") { // None option
                item.style.display = searchMatch("", term) ? 'flex' : 'none';
                return;
            }
            
            const name = (item.getAttribute('data-name') || "").toLowerCase();
            const role = (item.getAttribute('data-role') || "").toLowerCase();
            const matchesSearch = name.includes(term) || role.includes(term);
            const isSelectedElsewhere = selectedIds.includes(id) && id !== currentVal;
            
            if (matchesSearch && !isSelectedElsewhere) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    };

    function searchMatch(haystack, needle) {
        return haystack.toLowerCase().includes(needle.toLowerCase());
    }

    window.selectFromCustom = function(el) {
        const item = el;
        const dropdown = item.closest('.custom-dropdown');
        const id = item.getAttribute('data-id');
        const name = item.getAttribute('data-name') || "None / Remove";
        const role = item.getAttribute('data-role') || "";
        const img = item.getAttribute('data-img');
        
        const selectedContainer = dropdown.querySelector('.dropdown-selected');
        if (selectedContainer) {
            const imgEl = selectedContainer.querySelector('img');
            if (imgEl) imgEl.src = img;
            const textEl = selectedContainer.querySelector('.selected-text');
            if (textEl) textEl.textContent = id ? `${name} (${role})` : name;
        }
        
        const hiddenInput = dropdown.querySelector('.player-hidden-input');
        if (hiddenInput) hiddenInput.value = id;
        
        dropdown.classList.remove('active');
        updateAllDropdowns();
        updateLeadership();
    };

    window.updateAllDropdowns = function() {
        document.querySelectorAll('.custom-dropdown').forEach(dropdown => {
            const searchInput = dropdown.querySelector('.dropdown-search');
            if (searchInput) filterLocalList(searchInput);
        });
    };

    window.closeNoteOverlay = function() {
        document.getElementById('noteOverlay').style.display = 'none';
    };

    document.addEventListener('DOMContentLoaded', function() {
        const addBtn = document.getElementById('addPlayerBtn');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                if (playerCount >= 15) return;
                playerCount++;
                
                const col = document.createElement('div');
                col.className = 'col-md-6 squad-field position-relative';
                col.innerHTML = `
                    <div class="remove-player-btn" onclick="removePlayerField(this)">
                        <i class="fas fa-times"></i>
                    </div>
                    <div class="custom-dropdown" id="dropdown-${playerCount}">
                        <div class="dropdown-selected" onclick="toggleDropdown(this)">
                            <img src="../images/default-player.jpg" class="player-img me-2">
                            <span class="selected-text">Select Player ${playerCount}</span>
                            <i class="fas fa-chevron-down arrow"></i>
                        </div>
                        <div class="dropdown-menu-custom">
                            <div class="dropdown-search-wrapper">
                                <input type="text" class="dropdown-search" placeholder="Search player..." onclick="event.stopPropagation()" oninput="filterLocalList(this)">
                            </div>
                            <div class="dropdown-list">
                                <div class="dropdown-item-custom" data-id="" data-img="../images/default-player.jpg" onclick="selectFromCustom(this)">
                                    <img src="../images/default-player.jpg">
                                    <div>
                                        <div class="fw-bold">None / Remove</div>
                                    </div>
                                </div>
                                ${availablePlayers.map(p => `
                                    <div class="dropdown-item-custom" 
                                         data-id="${p.id}" 
                                         data-name="${p.name.replace(/'/g, "\\'")}"
                                         data-role="${p.playing_role}"
                                         data-img="${p.profile_image ? '../uploads/users/'+p.profile_image : '../images/default-player.jpg'}" 
                                         onclick="selectFromCustom(this)">
                                        <img src="${p.profile_image ? '../uploads/users/'+p.profile_image : '../images/default-player.jpg'}">
                                        <div>
                                            <div class="fw-bold">${p.name}</div>
                                            <div class="small text-muted">${p.playing_role}</div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        <input type="hidden" name="squad[]" class="player-hidden-input" required>
                    </div>
                `;
                document.getElementById('squadContainer').appendChild(col);
                if (playerCount === 15) addBtn.disabled = true;
                updateAllDropdowns();
            });
        }
    });

    window.removePlayerField = function(btn) {
        const field = btn.closest('.squad-field');
        field.remove();
        playerCount--;
        
        const addBtn = document.getElementById('addPlayerBtn');
        if (addBtn) addBtn.disabled = false;
        
        updateAllDropdowns();
        updateLeadership();
    };

    window.updateLeadership = function() {
        const selectedIds = Array.from(document.querySelectorAll('.player-hidden-input'))
            .map(i => i.value)
            .filter(id => id !== "");

        const captainSelect = document.getElementById('captainSelect');
        const viceSelect = document.getElementById('viceCaptainSelect');
        if (!captainSelect || !viceSelect) return;
        
        const currentCap = captainSelect.value;
        const currentVice = viceSelect.value;

        captainSelect.innerHTML = '<option value="">Select Captain</option>';
        viceSelect.innerHTML = '<option value="">Select Vice-Captain</option>';

        selectedIds.forEach(id => {
            const player = availablePlayers.find(p => p.id == id);
            if (player) {
                const opt1 = new Option(player.name, player.id);
                const opt2 = new Option(player.name, player.id);
                captainSelect.add(opt1);
                viceSelect.add(opt2);
            }
        });

        captainSelect.value = currentCap;
        viceSelect.value = currentVice;
        updateLeadershipDisabled();
    };

    window.updateLeadershipDisabled = function() {
        const captainSelect = document.getElementById('captainSelect');
        const viceSelect = document.getElementById('viceCaptainSelect');
        if (!captainSelect || !viceSelect) return;
        
        Array.from(captainSelect.options).forEach(opt => {
            if (opt.value && opt.value === viceSelect.value) opt.disabled = true;
            else opt.disabled = false;
        });
        Array.from(viceSelect.options).forEach(opt => {
            if (opt.value && opt.value === captainSelect.value) opt.disabled = true;
            else opt.disabled = false;
        });
    };

    const capS = document.getElementById('captainSelect');
    if (capS) capS.addEventListener('change', updateLeadershipDisabled);
    const viceS = document.getElementById('viceCaptainSelect');
    if (viceS) viceS.addEventListener('change', updateLeadershipDisabled);

    // AJAX Form Submission
    const regForm = document.getElementById('regForm');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            const formData = new FormData(this);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('successOverlay').style.display = 'flex';
                } else {
                    alert(data.message || 'An error occurred during registration.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            });
        });
    }

</script>

<?php require_once '../includes/footer.php'; ?>
