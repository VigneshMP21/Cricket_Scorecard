<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_once '../includes/html2image_utils.php';


if (class_exists('Cloudinary\Configuration\Configuration')) {
    \Cloudinary\Configuration\Configuration::instance([
        'cloud' => [
            'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
            'api_key' => $_ENV['CLOUDINARY_API_KEY'] ?? "",
            'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
        ],
        'url' => [
            'secure' => true
        ]
    ]);
}


/**
 * Generates a premium point table creation banner
 */
function generateNewTableBanner($table_name, $team_names)
{
    $api_user = $_ENV['HCTI_USER_ID'] ?? '';
    $api_key = $_ENV['HCTI_API_KEY'] ?? '';

    if (empty($api_user) || empty($api_key)) {
        return null;
    }

    $teams_html = "";
    foreach ($team_names as $index => $name) {
        $teams_html .= "
        <div class='team-row'>
            <div class='pos'>" . ($index + 1) . "</div>
            <div class='name'>$name</div>
            <div class='stat'>0</div>
            <div class='stat'>0</div>
            <div class='stat pts'>0</div>
            <div class='stat nrr'>0.000</div>
        </div>";
    }

    $html = "
    <div class='banner-container'>
        <div class='header'>$table_name</div>
        <div class='sub-header'>Group Participants</div>
        <div class='teams-container'>
            <div class='teams-header'>
                <div class='pos'>#</div>
                <div class='name'>Team Name</div>
                <div class='stat'>W</div>
                <div class='stat'>L</div>
                <div class='stat'>Pts</div>
                <div class='stat'>NRR</div>
            </div>
            $teams_html
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 1024px; height: 512px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 1024px; height: 512px; 
        background: url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1778068401/cricket-46_1024422-11592_z50wdm.jpg') center/cover no-repeat;
        padding: 40px; color: white; display: flex; flex-direction: column; align-items: center; position: relative;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.65); z-index: 0;
    }
    .header, .sub-header, .teams-container { position: relative; z-index: 1; }
    .header { font-size: 52px; font-weight: 800; color: #fbbf24; text-transform: uppercase; margin-bottom: 5px; text-shadow: 0 4px 10px rgba(0,0,0,0.5); }
    .sub-header { font-size: 20px; color: #cbd5e1; font-weight: 600; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 3px; }
    .teams-container { width: 100%; max-width: 900px; display: flex; flex-direction: column; gap: 8px; }
    .teams-header { 
        display: flex; padding: 10px 20px; background: rgba(255,255,255,0.15); 
        border-radius: 12px; font-weight: 800; color: #94a3b8; font-size: 14px; text-transform: uppercase;
    }
    .team-row { 
        display: flex; padding: 14px 20px; background: rgba(255,255,255,0.08); 
        border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);
        font-size: 20px; font-weight: 600; align-items: center;
    }
    .pos { width: 50px; color: #94a3b8; }
    .name { flex: 1; color: #f8fafc; }
    .stat { width: 70px; text-align: center; color: #cbd5e1; }
    .pts { color: #fbbf24; font-weight: 800; }
    .nrr { width: 100px; font-family: monospace; color: #cbd5e1; }
    ";

    $ch = curl_init('https://hcti.io/v1/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['html' => $html, 'css' => $css]));
    curl_setopt($ch, CURLOPT_USERPWD, $api_user . ':' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    $image_url = $res['url'] ?? null;

    if ($image_url && class_exists('\Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $cloud_res = $uploadApi->upload($image_url, [
                'folder' => 'point_table_creations',
                'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ''
            ]);
            return $cloud_res['secure_url'];
        } catch (Exception $e) {
            return $image_url;
        }
    }
    return $image_url;
}

function generateNewTableBannerHtml2Image(string $table_name, array $team_names): ?string
{
    $safe_table_name = htmlspecialchars($table_name, ENT_QUOTES, 'UTF-8');
    $teams_html = '';

    foreach ($team_names as $index => $name) {
        $safe_name = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $teams_html .= "
        <div class='team-row'>
            <div class='pos'>" . ($index + 1) . "</div>
            <div class='name'>{$safe_name}</div>
            <div class='stat'>0</div>
            <div class='stat'>0</div>
            <div class='stat pts'>0</div>
            <div class='stat nrr'>0.000</div>
        </div>";
    }

    $html = "
    <div class='banner-container'>
        <div class='header'>{$safe_table_name}</div>
        <div class='sub-header'>Group Participants</div>
        <div class='teams-container'>
            <div class='teams-header'>
                <div class='pos'>#</div>
                <div class='name'>Team Name</div>
                <div class='stat'>W</div>
                <div class='stat'>L</div>
                <div class='stat'>Pts</div>
                <div class='stat'>NRR</div>
            </div>
            {$teams_html}
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 1024px; height: 512px; font-family: 'Outfit', sans-serif; }
    .banner-container {
        width: 1024px; height: 512px;
        background:
            radial-gradient(circle at top right, rgba(251, 191, 36, 0.24), transparent 30%),
            radial-gradient(circle at bottom left, rgba(14, 165, 233, 0.18), transparent 36%),
            linear-gradient(145deg, #08111f 0%, #112b42 55%, #0f172a 100%);
        padding: 40px; color: white; display: flex; flex-direction: column; align-items: center; position: relative; overflow: hidden;
    }
    .banner-container::before {
        content: ''; position: absolute; inset: 0;
        background:
            linear-gradient(135deg, rgba(2, 6, 23, 0.78), rgba(15, 23, 42, 0.45)),
            url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1778068401/cricket-46_1024422-11592_z50wdm.jpg') center/cover no-repeat;
        opacity: 0.4; z-index: 0;
    }
    .header, .sub-header, .teams-container { position: relative; z-index: 1; }
    .header { font-size: 52px; font-weight: 800; color: #fbbf24; text-transform: uppercase; margin-bottom: 5px; text-shadow: 0 4px 10px rgba(0,0,0,0.5); }
    .sub-header { font-size: 20px; color: #cbd5e1; font-weight: 600; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 3px; }
    .teams-container { width: 100%; max-width: 900px; display: flex; flex-direction: column; gap: 8px; }
    .teams-header {
        display: flex; padding: 10px 20px; background: rgba(255,255,255,0.15);
        border-radius: 12px; font-weight: 800; color: #94a3b8; font-size: 14px; text-transform: uppercase;
    }
    .team-row {
        display: flex; padding: 14px 20px; background: rgba(255,255,255,0.08);
        border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);
        font-size: 20px; font-weight: 600; align-items: center;
    }
    .pos { width: 50px; color: #94a3b8; }
    .name { flex: 1; color: #f8fafc; }
    .stat { width: 70px; text-align: center; color: #cbd5e1; }
    .pts { color: #fbbf24; font-weight: 800; }
    .nrr { width: 100px; font-family: monospace; color: #cbd5e1; }";

    $image_url = generate_html2image_link($html, $css, 1024, 512);
    return upload_generated_image_to_cloudinary($image_url, 'point_table_creations');
}

require_login();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Fetch teams (excluding those already in point tables)
$teams = [];
try {
    $stmt = $pdo->query("
        SELECT id, team_name, team_logo, team_code 
        FROM teams 
        WHERE id NOT IN (SELECT DISTINCT team_id FROM point_table_entries)
        ORDER BY team_name
    ");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch tournaments
$tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['table_name'])) {
            throw new Exception("Point table name is required.");
        }

        $selected_teams = $_POST['teams'] ?? [];
        if (count($selected_teams) < 2) {
            throw new Exception("Please select at least 2 teams.");
        }

        // Insert point table
        $stmt = $pdo->prepare("
            INSERT INTO point_tables (
                table_name, tournament_id, win_points, loss_points, draw_points, nr_points, qualify_count, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            trim($_POST['table_name']),
            $_POST['tournament_id'] ? (int) $_POST['tournament_id'] : null,
            (int) ($_POST['win_points'] ?? 2),
            (int) ($_POST['loss_points'] ?? 0),
            (int) ($_POST['draw_points'] ?? 1),
            (int) ($_POST['nr_points'] ?? 1),
            (int) ($_POST['qualify_count'] ?? 4),
            $_SESSION['user_id']
        ]);

        $point_table_id = $pdo->lastInsertId();

        // Insert selected teams and build names list for notification
        $stmt = $pdo->prepare("INSERT INTO point_table_entries (point_table_id, team_id) VALUES (?, ?)");
        $team_names_list = [];
        foreach ($selected_teams as $team_id) {
            $stmt->execute([$point_table_id, (int) $team_id]);
            
            // Find team name from $teams array
            foreach ($teams as $t) {
                if ($t['id'] == $team_id) {
                    $team_names_list[] = $t['team_name'];
                    break;
                }
            }
        }

        // 🔔 Send Notification
        try {
            $table_name = trim($_POST['table_name']);
            $team_count = count($team_names_list);
            
            $title_msg = "{$table_name} was created.";
            $content_msg = "A new battleground is ready! {$team_count} teams are competing for the top spots. Check out the standings now! 🏆🏏";

            $registered_player_ids = $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE user_id IS NOT NULL AND onesignal_player_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $guest_player_ids = getGuestPlayerIds($pdo);
            $all_player_ids = array_unique(array_merge($registered_player_ids, $guest_player_ids));

            if (!empty($all_player_ids)) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $base_url = "$protocol://$host/CPT_LEAGUE/";

                // Generate Banner
                $table_banner = generateNewTableBannerHtml2Image($table_name, $team_names_list);

                // Fetch Tournament Logo for large_icon
                $tournament_logo_url = "";
                $tournament_id = $_POST['tournament_id'] ? (int) $_POST['tournament_id'] : null;
                if ($tournament_id) {
                    $stmtTour = $pdo->prepare("SELECT tournament_logo_public_id FROM tournaments WHERE id = ?");
                    $stmtTour->execute([$tournament_id]);
                    $tour = $stmtTour->fetch(PDO::FETCH_ASSOC);
                    if ($tour && $tour['tournament_logo_public_id']) {
                        $tournament_logo_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/" . $tour['tournament_logo_public_id'];
                    }
                }
                if (empty($tournament_logo_url)) {
                    $tournament_logo_url = $base_url . "assets/images/logo.jpg";
                }

                sendOneSignalNotification(
                    $all_player_ids,
                    $title_msg,
                    $content_msg,
                    [
                        'type' => 'point_table_created',
                        'table_id' => $point_table_id,
                        'big_picture' => $table_banner ?: ($base_url . "assets/images/home_bg.jpg"),
                        'large_icon' => $tournament_logo_url,
                        'small_icon' => 'ic_stat_notify',
                        'android_sound' => 'notification_sound'
                    ],
                    $base_url . "view/view_point_table.php?id=$point_table_id"
                );
            }
        } catch (Exception $no_err) {
            error_log("Point Table Notification Error: " . $no_err->getMessage());
        }

        // Redirect to point tables list with success message
        header("Location: ../NavBarList/point_tables.php?success=1");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Create Point Table";
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
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
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

    /* Team Selection Styles */
    .team-checkbox-card {
        position: relative;
    }

    .team-checkbox-card .form-check-input {
        position: absolute;
        top: 15px;
        right: 15px;
        z-index: 2;
        width: 1.25rem;
        height: 1.25rem;
        cursor: pointer;
    }

    .team-checkbox-card .team-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        border: 2px solid transparent;
        border-radius: 16px;
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        height: 100%;
    }

    .team-checkbox-card .team-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .team-checkbox-card input:checked + label .team-card {
        border-color: #6366f1;
        background-color: #eef2ff;
        box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);
    }

    /* Table Styles */
    .preview-table-container {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        border: 1px solid #e5e7eb;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: #f8fafc;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
        background: white;
        border-bottom: 1px solid #f3f4f6;
    }

    .preview-team {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .preview-team img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .points-input-card {
        background: white;
        padding: 1.5rem;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        text-align: center;
        height: 100%;
        transition: transform 0.2s;
    }
    
    .points-input-card:hover {
        transform: translateY(-2px);
        border-color: #6366f1;
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
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-table"></i>
                        Create Point Table
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

                    <?php if (empty($teams)): ?>
                            <div class="text-center py-5">
                                <div class="mb-3 text-warning">
                                    <i class="fas fa-users-slash fa-4x opacity-50"></i>
                                </div>
                                <h4 class="fw-bold text-dark">No Teams Available</h4>
                                <p class="text-muted mb-4">All teams are already assigned to existing point tables.</p>
                                <div class="d-flex justify-content-center gap-3">
                                    <a href="../NavBarList/point_tables.php" class="btn btn-action btn-back">
                                        <i class="fas fa-list"></i> View Tables
                                    </a>
                                    <a href="create_team.php" class="btn btn-action btn-create">
                                        <i class="fas fa-plus"></i> Create Team
                                    </a>
                                </div>
                            </div>
                    <?php else: ?>
                            <form id="createPointTableForm" method="POST" action="" class="needs-validation" novalidate>
                            
                                <div class="row g-4 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label">Point Table Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                                <i class="fas fa-signature text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 rounded-end-4" name="table_name" required placeholder="e.g., Group A, Group B, Super 8">
                                        </div>
                                        <div class="invalid-feedback">Please provide a point table name.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Tournament (Optional)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                                <i class="fas fa-trophy text-muted"></i>
                                            </span>
                                            <select class="form-select border-start-0 rounded-end-4" name="tournament_id">
                                                <option value="">Select Tournament</option>
                                                <?php foreach ($tournaments as $tournament): ?>
                                                        <option value="<?= $tournament['id'] ?>">
                                                            <?= htmlspecialchars($tournament['tournament_name']) ?>
                                                        </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Teams Selection -->
                                <div class="section-title">
                                    <i class="fas fa-users"></i> Select Teams
                                </div>
                            
                                <div class="alert alert-info border-0 bg-opacity-10 bg-info rounded-3 mb-4">
                                    <div class="d-flex">
                                        <i class="fas fa-info-circle text-info mt-1 me-3"></i>
                                        <div>
                                            <strong>Note:</strong> Only teams that are not already assigned to any point table are shown here. 
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <?php foreach ($teams as $team): ?>
                                            <div class="col-md-4 col-sm-6">
                                                <div class="team-checkbox-card h-100">
                                                    <input type="checkbox" class="form-check-input" name="teams[]" value="<?= $team['id'] ?>" id="team<?= $team['id'] ?>">
                                                    <label class="form-check-label w-100 h-100" for="team<?= $team['id'] ?>">
                                                        <div class="card team-card p-3">
                                                            <div class="d-flex align-items-center gap-3">
                                                                <img src="<?= $team['team_logo'] ? '../uploads/teams/' . $team['team_logo'] : '../images/default-team.png' ?>" 
                                                                     alt="<?= htmlspecialchars($team['team_name']) ?>"
                                                                     style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #f3f4f6;">
                                                                <div>
                                                                    <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($team['team_name']) ?></h6>
                                                                    <small class="text-muted fw-medium"><?= $team['team_code'] ?></small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Preview Table -->
                                <div class="section-title">
                                    <i class="fas fa-eye"></i> Live Preview
                                </div>
                            
                                <div class="preview-table-container mb-5">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle" id="pointTablePreview">
                                            <thead>
                                                <tr>
                                                    <th width="50" class="text-center">#</th>
                                                    <th>Team</th>
                                                    <th width="80" class="text-center">P</th>
                                                    <th width="80" class="text-center">W</th>
                                                    <th width="80" class="text-center">L</th>
                                                    <th width="80" class="text-center">D</th>
                                                    <th width="80" class="text-center">NR</th>
                                                    <th width="80" class="text-center">Pts</th>
                                                    <th width="80" class="text-center">NRR</th>
                                                </tr>
                                            </thead>
                                            <tbody id="previewBody">
                                                <!-- JS populated -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Point System -->
                                <div class="section-title">
                                    <i class="fas fa-calculator"></i> Point System Configuration
                                </div>

                                <div class="row g-3 mb-5">
                                    <div class="col-md-3 col-6">
                                        <div class="points-input-card">
                                            <label class="form-label text-success">Win Points</label>
                                            <input type="number" class="form-control text-center fw-bold fs-5" name="win_points" value="2" min="1">
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="points-input-card">
                                            <label class="form-label text-danger">Loss Points</label>
                                            <input type="number" class="form-control text-center fw-bold fs-5" name="loss_points" value="0" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="points-input-card">
                                            <label class="form-label text-warning">Draw Points</label>
                                            <input type="number" class="form-control text-center fw-bold fs-5" name="draw_points" value="1" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="points-input-card">
                                            <label class="form-label text-secondary">No Result</label>
                                            <input type="number" class="form-control text-center fw-bold fs-5" name="nr_points" value="1" min="0">
                                        </div>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <div class="p-3 bg-white border rounded-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                                            <label class="form-label mb-0 text-primary">
                                                <i class="fas fa-medal me-2"></i>Qualifying Teams (Top N)
                                            </label>
                                            <input type="number" class="form-control w-auto" name="qualify_count" value="4" min="1" style="max-width: 100px;">
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="d-flex justify-content-between align-items-center pt-4 border-top">
                                    <button type="button" class="btn btn-action btn-back" onclick="window.history.back()">
                                        <i class="fas fa-arrow-left"></i> Cancel
                                    </button>
                                    <button type="submit" class="btn btn-action btn-create">
                                        <i class="fas fa-check-circle"></i> Create Point Table
                                    </button>
                                </div>
                            </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const teams = <?= json_encode($teams) ?>;
    const previewBody = document.getElementById('previewBody');

    // Update preview when teams are selected
    document.querySelectorAll('input[name="teams[]"]').forEach(checkbox => {
        checkbox.addEventListener('change', updatePreview);
    });

    function updatePreview() {
        previewBody.innerHTML = '';
        let selectedCount = 0;

        document.querySelectorAll('input[name="teams[]"]:checked').forEach((checkbox, index) => {
            const teamId = checkbox.value;
            const team = teams.find(t => t.id == teamId);

            if (team) {
                const row = document.createElement('tr');
                row.innerHTML = `
                <td class="text-center fw-bold text-muted">${index + 1}</td>
                <td>
                    <div class="preview-team">
                        <img src="${team.team_logo ? '../uploads/teams/' + team.team_logo : '../images/default-team.png'}" 
                             alt="${team.team_name}">
                        <div>
                            <span class="d-block fw-bold text-dark">${team.team_name}</span>
                            <span class="small text-muted">${team.team_code}</span>
                        </div>
                    </div>
                </td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center fw-bold text-primary">0</td>
                <td class="text-center font-monospace">0.000</td>
            `;
                previewBody.appendChild(row);
                selectedCount++;
            }
        });
        
        // Show empty state if no teams selected
        if (selectedCount === 0) {
            previewBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="fas fa-arrow-up me-2"></i>Select teams above to preview the table
                    </td>
                </tr>
            `;
        }

        // Update table header count
        const tableHeader = document.querySelector('#pointTablePreview thead tr');
        if (tableHeader) {
            tableHeader.cells[1].innerHTML = `Team (${selectedCount})`;
        }
    }

    // Initial preview update
    updatePreview();

    document.getElementById('createPointTableForm').addEventListener('submit', function (e) {
        const form = this;

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const selectedTeams = document.querySelectorAll('input[name="teams[]"]:checked').length;
        if (selectedTeams < 2) {
            e.preventDefault();
            alert('Please select at least 2 teams');
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
</script>

<?php require_once '../includes/footer.php'; ?>
