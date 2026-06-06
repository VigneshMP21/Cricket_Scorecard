<?php
require_once '../includes/db.php';
require_login();
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: ../NavBarList/teams.php");
    exit();
}

$team_id = (int) $_GET['id'];
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';



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
    } catch (Exception $e) {
        error_log("❌ Cloudinary Edit Upload Error: " . $e->getMessage());
    }
    return null;
}

function deleteFromCloudinary($public_id) {
    if (!$public_id) return;
    try {
        $uploadApi = new UploadApi();
        $uploadApi->destroy($public_id);
    } catch (Exception $e) {
        error_log("❌ Cloudinary Edit Delete Error: " . $e->getMessage());
    }
}

// Fetch team details first for permission check
try {
    $stmt = $pdo->prepare("
        SELECT t.*,
               p1.name as captain_name,
               p2.name as vice_captain_name,
               t.team_logo_public_id
        FROM teams t
        LEFT JOIN users p1 ON t.captain_id = p1.id
        LEFT JOIN users p2 ON t.vice_captain_id = p2.id
        WHERE t.id = ?
    ");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        header("Location: ../NavBarList/teams.php");
        exit();
    }

    // Permission check: Admin OR Captain OR Vice-Captain
    $isAuthorized = ($user_role === 'admin' || $current_user_id == $team['captain_id'] || $current_user_id == $team['vice_captain_id']);
    
    if (!$isAuthorized) {
        header("Location: ../NavBarList/teams.php?error=unauthorized");
        exit();
    }

} catch (PDOException $e) {
    header("Location: ../NavBarList/teams.php");
    exit();
}

// Fetch current team players
$current_player_ids = [];
$current_players = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.profile_image, u.playing_role
        FROM team_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.team_id = ?
        ORDER BY u.name
    ");
    $stmt->execute([$team_id]);
    $current_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_player_ids = array_column($current_players, 'id');
} catch (PDOException $e) {
    // Handle error
}

// Fetch all available players (players not in any team)
$available_players = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, profile_image, playing_role
        FROM users
        WHERE role = 'player' AND id NOT IN (
            SELECT player_id FROM team_players WHERE team_id != $team_id
        )
        ORDER BY name
    ");
    $available_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        // Handle logo upload
        $logo_filename = $team['team_logo']; 
        $team_logo_url = $team['team_logo_url'];
        $team_logo_public_id = $team['team_logo_public_id'];

        if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'avif', 'heif', 'webp', 'svg'];
            $filename = $_FILES['team_logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $newname = uniqid() . '.' . $ext;
                $destination = '../uploads/teams/' . $newname;
                if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $destination)) {
                    
                    // Delete old logo from Cloudinary before updating
                    if (!empty($team['team_logo_public_id'])) {
                        deleteFromCloudinary($team['team_logo_public_id']);
                    }

                    // Upload new logo to Cloudinary
                    $logo_res = uploadToCloudinary($destination);
                    if ($logo_res) {
                        $team_logo_url = $logo_res['url'];
                        $team_logo_public_id = $logo_res['public_id'];
                    }

                    $logo_filename = $newname;
                    // Delete old local logo if it exists
                    if ($team['team_logo'] && $team['team_logo'] !== $logo_filename && file_exists('../uploads/teams/' . $team['team_logo'])) {
                        unlink('../uploads/teams/' . $team['team_logo']);
                    }
                } else {
                    throw new Exception("Failed to upload team logo.");
                }
            } else {
                throw new Exception("Invalid logo format. Supported formats: JPEG, JPG, PNG, GIF, AVIF, HEIF, WEBP, SVG.");
            }
        }

        // Start transaction
        $pdo->beginTransaction();

        // Update team
        $stmt = $pdo->prepare("
            UPDATE teams SET
                team_name = ?,
                team_code = ?,
                team_color = ?,
                team_logo = ?,
                team_logo_url = ?,
                team_logo_public_id = ?,
                captain_id = ?,
                vice_captain_id = ?,
                tournament_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            trim($_POST['team_name']),
            strtoupper(trim($_POST['team_code'])),
            $_POST['team_color'] ?? '#0d6efd',
            $logo_filename,
            $team_logo_url,
            $team_logo_public_id,
            $_POST['captain'],
            !empty($_POST['vice_captain']) ? $_POST['vice_captain'] : null,
            !empty($_POST['tournament_id']) ? $_POST['tournament_id'] : null,
            $team_id
        ]);

        // Delete existing team players
        $stmt = $pdo->prepare("DELETE FROM team_players WHERE team_id = ?");
        $stmt->execute([$team_id]);

        // Insert updated team players
        $stmt = $pdo->prepare("INSERT INTO team_players (team_id, player_id) VALUES (?, ?)");
        foreach ($selected_user_ids as $player_id) {
            $stmt->execute([$team_id, $player_id]);
        }

        $pdo->commit();

        // Redirect with success message
        header("Location: ../NavBarList/teams.php?updated=1");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$page_title = "Edit Team";
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
                        <i class="fas fa-edit"></i>
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

                    <form id="editTeamForm" method="POST" enctype="multipart/form-data" class="needs-validation"
                        novalidate>
                        <input type="hidden" name="selected_players" id="selectedPlayersInput"
                            value="<?= implode(',', $current_player_ids) ?>">

                        <div class="row g-4 mb-5">
                            <!-- Team Logo Section -->
                            <div class="col-lg-4">
                                <label class="form-label">Team Logo</label>
                                <div class="upload-area h-100 d-flex flex-column justify-content-center align-items-center"
                                    onclick="document.getElementById('teamLogo').click()">
                                    <input type="file" class="form-control d-none" id="teamLogo" name="team_logo"
                                        accept="image/*">
                                    <div class="preview mb-3" id="logoPreview">
                                        <div class="rounded-circle bg-white shadow-sm p-4 d-inline-block">
                                            <img src="<?= $team['team_logo'] ? '../uploads/teams/' . $team['team_logo'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><circle cx="40" cy="40" r="38" fill="#e5e7eb" stroke="#9ca3af" stroke-width="2"/><text x="40" y="48" text-anchor="middle" font-family="Arial, sans-serif" font-size="32" fill="#6b7280">T</text></svg>') ?>"
                                                alt="Current Logo" class="preview-img">
                                        </div>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Click to Change Logo</h6>
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
                                                name="team_name" value="<?= htmlspecialchars($team['team_name']) ?>"
                                                required placeholder="e.g. Mumbai Indians">
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
                                                name="team_code" maxlength="4"
                                                value="<?= htmlspecialchars($team['team_code']) ?>" required
                                                placeholder="e.g. MI">
                                        </div>
                                        <div class="invalid-feedback">Please provide a team code (max 4 chars).</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Team Color</label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color w-100 rounded-4"
                                                name="team_color" value="<?= htmlspecialchars($team['team_color']) ?>"
                                                title="Choose team color">
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
                                                    <option value="<?= $t['id'] ?>" <?= $team['tournament_id'] == $t['id'] ? 'selected' : '' ?>>
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

                        <div class="row g-4 mb-4">
                            <!-- Available Players -->
                            <div class="col-lg-6">
                                <div class="player-list-card">
                                    <div class="player-list-header">
                                        <span>Available Players (<span
                                                id="availableCount"><?= count($available_players) ?></span>)</span>
                                    </div>
                                    <div class="p-3 bg-white border-bottom">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0 ps-3">
                                                <i class="fas fa-search text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control bg-light border-start-0"
                                                placeholder="Search players..." id="searchPlayers"
                                                oninput="debounceSearch()">
                                        </div>
                                    </div>
                                    <div class="player-list-body" id="availablePlayers">
                                        <?php $all_available = $available_players; // Sort by name already done in SQL ?>
                                        <?php foreach ($all_available as $player): ?>
                                            <div class="player-item d-flex align-items-center"
                                                data-player-id="<?= $player['id'] ?>" onclick="selectPlayer(this)">
                                                <div class="position-relative">
                                                    <img src="<?= $player['profile_image'] ? '../uploads/users/' . $player['profile_image'] : 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><circle cx="20" cy="20" r="18" fill="#e5e7eb" stroke="#9ca3af" stroke-width="1"/><circle cx="20" cy="15" r="5" fill="#6b7280"/><path d="M8 32c0-6.5 5-11.5 11.5-11.5s11.5 5 11.5 11.5" fill="#6b7280"/></svg>') ?>"
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
                                        <span>Selected Squad (<span
                                                id="selectedCount"><?= count($current_players) ?></span>/15)</span>
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="player-list-body bg-success bg-opacity-10" id="selectedPlayers">
                                        <!-- Selected players will appear here -->
                                        <div class="text-center text-muted py-5" id="emptyState" style="display: none;">
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
                                        id="captainSelect" required>
                                        <option value="">Select Captain</option>
                                        <?php foreach ($current_players as $player): ?>
                                            <option value="<?= $player['id'] ?>" <?= $player['id'] == $team['captain_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($player['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
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
                                        id="viceCaptainSelect">
                                        <option value="">Select Vice Captain</option>
                                        <?php foreach ($current_players as $player): ?>
                                            <option value="<?= $player['id'] ?>" <?= $player['id'] == $team['vice_captain_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($player['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between align-items-center pt-4 border-top">
                            <a href="../NavBarList/teams.php" class="btn btn-action btn-back">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-action btn-create">
                                <i class="fas fa-save"></i> Update Team
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
    // Initialize selected players from PHP, ensuring IDs are strings
    const selectedPlayers = new Set(<?= json_encode(array_map('strval', $current_player_ids)) ?>);
    // Merge current and available for the JS source of truth
    const availablePlayers = <?= json_encode($available_players) ?>;

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

    function initSelectedState() {
        selectedPlayers.forEach(playerId => {
            const element = document.querySelector(`.player-item[data-player-id="${playerId}"]`);
            if (element) {
                element.classList.add('selected');
                const button = element.querySelector('button');
                const icon = button.querySelector('i');

                button.className = 'btn btn-sm btn-success rounded-circle shadow-sm';
                icon.className = 'fas fa-check text-white';
            }
        });
        // Render the right-hand column
        updateSelectedList();
    }

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
        filterPlayers();
    }

    function updateSelectedList() {
        const selectedDiv = document.getElementById('selectedPlayers');
        const emptyState = document.getElementById('emptyState');

        // Clear list but keep empty state element reference (it's hidden/shown)
        // Actually simpler to just rebuild innerHTML but re-append emptyState if needed or just toggle it.
        // Let's just clear and rebuild.
        selectedDiv.innerHTML = '';

        if (selectedPlayers.size === 0) {
            if (emptyState) {
                emptyState.style.display = 'block';
                selectedDiv.appendChild(emptyState);
            } else {
                // Re-create empty state if it was lost
                selectedDiv.innerHTML = `
                <div class="text-center text-muted py-5" id="emptyState">
                    <i class="fas fa-arrow-left mb-2 opacity-50"></i>
                    <p class="small">Select players from the list</p>
                </div>`;
            }
            return;
        }

        // Append empty state but hide it, to keep it in DOM if we want
        if (emptyState) {
            emptyState.style.display = 'none';
            selectedDiv.appendChild(emptyState);
        }

        selectedPlayers.forEach(playerId => {
            const player = availablePlayers.find(p => p.id == playerId);
            if (player) {
                const div = document.createElement('div');
                div.className = 'player-item d-flex align-items-center bg-white border-0 shadow-sm mb-2';
                div.style.cursor = 'default';
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
                    ${getCaptainBadge(player.id)}
                </div>
                <button type="button" class="btn btn-sm btn-danger rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0;" 
                        onclick="removePlayer('${player.id}')">
                    <i class="fas fa-times text-white"></i>
                </button>
            `;
                selectedDiv.appendChild(div);
            }
        });
    }

    function getCaptainBadge(playerId) {
        const captainId = document.getElementById('captainSelect').value;
        const viceCaptainId = document.getElementById('viceCaptainSelect').value;

        if (playerId == captainId) return '<span class="badge bg-warning text-dark ms-1">C</span>';
        if (playerId == viceCaptainId) return '<span class="badge bg-info text-white ms-1">VC</span>';
        return '';
    }

    function removePlayer(playerId) {
        const availableItem = document.querySelector(`.player-item[data-player-id="${playerId}"]`);
        if (availableItem) {
            selectPlayer(availableItem);
        } else {
            // Fallback
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
        // Initialize Bootstrap modal
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

        // Initialize State
        initSelectedState();
        filterPlayers();
        updateSelectedCount();
        updateCaptainOptions();
    });

    // Update captain options when selections change
    document.getElementById('captainSelect').addEventListener('change', updateCaptainOptions);
    document.getElementById('viceCaptainSelect').addEventListener('change', updateCaptainOptions);

    // Form submission
    document.getElementById('editTeamForm').addEventListener('submit', function (e) {
        const form = this;
        const teamName = form.querySelector('input[name="team_name"]').value.trim();
        const teamCode = form.querySelector('input[name="team_code"]').value.trim();
        const captain = document.getElementById('captainSelect').value;
        const viceCaptain = document.getElementById('viceCaptainSelect').value;

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
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
        submitBtn.disabled = true;

        // Re-enable after 10 seconds as fallback
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);

        // Allow form to submit normally
    });
</script>

<?php require_once '../includes/footer.php'; ?>