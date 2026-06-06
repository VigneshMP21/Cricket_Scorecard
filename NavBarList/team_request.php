<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/onesignal_utils.php';
require_once __DIR__ . '/../includes/notification_banner_utils.php';



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

require_login();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Handle Approve/Reject
if (isset($_GET['action']) && isset($_GET['id'])) {
    $team_id = (int)$_GET['id'];
    $action = $_GET['action'];

    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE teams SET status = 'active' WHERE id = ?");
            $stmt->execute([$team_id]);

            // ─── Notification Feature ──────────────────────────────────────────
            try {
                // Fetch team details
                $stmtTeam = $pdo->prepare("
                    SELECT t.team_name, t.team_code, t.team_color, t.team_logo_url, t.captain_id, t.vice_captain_id,
                           tn.tournament_name
                    FROM teams t
                    LEFT JOIN tournaments tn ON t.tournament_id = tn.id
                    WHERE t.id = ?
                ");
                $stmtTeam->execute([$team_id]);
                $team_data = $stmtTeam->fetch(PDO::FETCH_ASSOC);

                if ($team_data) {
                    $team_name_notify = $team_data['team_name'];
                    $team_code_notify = $team_data['team_code'] ?? 'TM';
                    $team_color_notify = $team_data['team_color'] ?? '#0d6efd';
                    $team_logo_url = $team_data['team_logo_url'];
                    $captain_player_id = $team_data['captain_id'];
                    $vice_captain_player_id = $team_data['vice_captain_id'];
                    $tournament_name_notify = $team_data['tournament_name'] ?: 'Open Registration';

                    // Fetch Squad Player IDs
                    $stmtSquad = $pdo->prepare("SELECT player_id FROM team_players WHERE team_id = ?");
                    $stmtSquad->execute([$team_id]);
                    $squad_player_ids = $stmtSquad->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($squad_player_ids)) {
                        $placeholders = implode(',', array_fill(0, count($squad_player_ids), '?'));
                        
                        // 1. Team Members OneSignal IDs
                        $stmtTM = $pdo->prepare("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE user_id IN ($placeholders) AND onesignal_player_id IS NOT NULL");
                        $stmtTM->execute($squad_player_ids);
                        $team_member_onesignal_ids = $stmtTM->fetchAll(PDO::FETCH_COLUMN);

                        // 2. Others (registered not in team + guests)
                        $stmtOthers = $pdo->prepare("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE (user_id NOT IN ($placeholders) OR user_id IS NULL) AND onesignal_player_id IS NOT NULL");
                        $stmtOthers->execute($squad_player_ids);
                        $other_player_ids = $stmtOthers->fetchAll(PDO::FETCH_COLUMN);
                        $other_player_ids = array_diff($other_player_ids, $team_member_onesignal_ids);

                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $base_url = "$protocol://$host/CPT_LEAGUE/";

                        // Fetch Captain Profile Image for Notification Icons
                        $stmtCap = $pdo->prepare("SELECT name, profile_image_url FROM users WHERE id = ?");
                        $stmtCap->execute([$captain_player_id]);
                        $captain_info = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: [];
                        $captain_image_url = $captain_info['profile_image_url'] ?? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
                        $vice_captain_info = [];
                        $vice_captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
                        if ($vice_captain_player_id) {
                            $stmtViceCap = $pdo->prepare("SELECT name, profile_image_url FROM users WHERE id = ?");
                            $stmtViceCap->execute([$vice_captain_player_id]);
                            $vice_captain_info = $stmtViceCap->fetch(PDO::FETCH_ASSOC) ?: [];
                            if (!empty($vice_captain_info['profile_image_url'])) {
                                $vice_captain_image_url = $vice_captain_info['profile_image_url'];
                            }
                        }
                        $notification_banner_url = generate_team_status_notification_banner([
                            'eyebrow' => 'Team Approved',
                            'team_name' => $team_name_notify,
                            'subline' => 'Officially joined ' . $tournament_name_notify,
                            'team_code' => $team_code_notify,
                            'team_color' => $team_color_notify,
                            'captain_name' => $captain_info['name'] ?? 'Captain TBA',
                            'captain_label' => 'Captain',
                            'vice_captain_name' => $vice_captain_info['name'] ?? 'Vice Captain TBA',
                            'vice_captain_label' => 'Vice Captain',
                            'secondary_stat_label' => 'Players',
                            'secondary_stat_value' => (string) count($squad_player_ids),
                            'logo_url' => $team_logo_url,
                            'captain_image_url' => $captain_image_url,
                            'vice_captain_image_url' => $vice_captain_image_url,
                            'folder' => 'team_approval_notifications',
                        ]);
                        $notification_big_picture = $notification_banner_url ?: ($team_logo_url ?: ($base_url . "assets/images/logo.jpg"));

                        $metadata = [
                            'type' => 'team_approved',
                            'big_picture' => $notification_big_picture,
                            'image' => $notification_big_picture,
                            'large_icon' => $captain_image_url,
                            'small_icon' => 'ic_stat_notify',
                            'android_sound' => 'notification_sound'
                        ];
                        $click_url = $base_url . "view/view_team.php?team_id=$team_id";

                        // Send to Team Members
                        if (!empty($team_member_onesignal_ids)) {
                            sendOneSignalNotification(
                                $team_member_onesignal_ids,
                                "🏏 Team Approved: $team_name_notify!",
                                "Congratulations! Your team '$team_name_notify' has been officially approved. Get ready to play and stay tuned for upcoming matches!",
                                $metadata,
                                $click_url
                            );
                        }

                        // Send to Others
                        if (!empty($other_player_ids)) {
                            sendOneSignalNotification(
                                $other_player_ids,
                                "📢 New Team Joined!",
                                "A new team '$team_name_notify' has officially joined the tournament. Stay prepared for upcoming matches!",
                                $metadata,
                                $click_url
                            );
                        }
                    }
                }
            } catch (Exception $no_err) {
                error_log("Team Approval Notification Error: " . $no_err->getMessage());
            }
            // ───────────────────────────────────────────────────────────────────

            $msg = "Team approved successfully!";
        } elseif ($action === 'reject') {
            try {
                // Fetch details before deletion
                $stmtFetch = $pdo->prepare("
                    SELECT t.team_name, t.team_code, t.team_color, t.team_logo, t.team_logo_url, t.team_logo_public_id,
                           t.captain_id, t.vice_captain_id, tn.tournament_name
                    FROM teams t
                    LEFT JOIN tournaments tn ON t.tournament_id = tn.id
                    WHERE t.id = ?
                ");
                $stmtFetch->execute([$team_id]);
                $team_info = $stmtFetch->fetch(PDO::FETCH_ASSOC);

                if ($team_info) {
                    $captain_id = $team_info['captain_id'];
                    $vice_captain_id = $team_info['vice_captain_id'];
                    $team_name = $team_info['team_name'];
                    $team_code = $team_info['team_code'] ?? 'TM';
                    $team_color = $team_info['team_color'] ?? '#0d6efd';
                    $team_logo_url = $team_info['team_logo_url'] ?? '';
                    $tournament_name = $team_info['tournament_name'] ?: 'Open Registration';
                    $logo_public_id = $team_info['team_logo_public_id'];
                    $logo_file = $team_info['team_logo'];

                    // Fetch OneSignal IDs for C and VC
                    $leads = array_filter([$captain_id, $vice_captain_id]);
                    $lead_onesignal_ids = [];
                    if (!empty($leads)) {
                        $placeholders = implode(',', array_fill(0, count($leads), '?'));
                        $stmtLead = $pdo->prepare("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE user_id IN ($placeholders) AND onesignal_player_id IS NOT NULL");
                        $stmtLead->execute($leads);
                        $lead_onesignal_ids = $stmtLead->fetchAll(PDO::FETCH_COLUMN);
                    }

                    // Perform Deletion
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM team_players WHERE team_id = ?")->execute([$team_id]);
                    $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$team_id]);
                    $pdo->commit();

                    // Local file cleanup
                    if ($logo_file) {
                        $logo_path = '../uploads/teams/' . $logo_file;
                        if (file_exists($logo_path)) unlink($logo_path);
                    }

                    // Cloudinary cleanup
                    if ($logo_public_id) {
                        try {
                            $uploadApi = new UploadApi();
                            $uploadApi->destroy($logo_public_id);
                        } catch (Exception $e) {
                            error_log("Cloudinary Delete Error: " . $e->getMessage());
                        }
                    }

                    // Send Rejection Notification to C & VC
                    if (!empty($lead_onesignal_ids)) {
                        $stmtCap = $pdo->prepare("SELECT name, profile_image_url FROM users WHERE id = ?");
                        $stmtCap->execute([$captain_id]);
                        $captain_info = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: [];
                        $captain_image_url = $captain_info['profile_image_url'] ?? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
                        $vice_captain_info = [];
                        $vice_captain_image_url = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";
                        if ($vice_captain_id) {
                            $stmtViceCap = $pdo->prepare("SELECT name, profile_image_url FROM users WHERE id = ?");
                            $stmtViceCap->execute([$vice_captain_id]);
                            $vice_captain_info = $stmtViceCap->fetch(PDO::FETCH_ASSOC) ?: [];
                            if (!empty($vice_captain_info['profile_image_url'])) {
                                $vice_captain_image_url = $vice_captain_info['profile_image_url'];
                            }
                        }
                        $rejection_banner_url = generate_team_status_notification_banner([
                            'eyebrow' => 'Registration Rejected',
                            'team_name' => $team_name,
                            'subline' => 'Please contact admin regarding ' . $tournament_name,
                            'team_code' => $team_code,
                            'team_color' => $team_color,
                            'captain_name' => $captain_info['name'] ?? 'Captain TBA',
                            'captain_label' => 'Captain',
                            'vice_captain_name' => $vice_captain_info['name'] ?? 'Vice Captain TBA',
                            'vice_captain_label' => 'Vice Captain',
                            'secondary_stat_label' => 'Tournament',
                            'secondary_stat_value' => $tournament_name,
                            'logo_url' => $team_logo_url,
                            'captain_image_url' => $captain_image_url,
                            'vice_captain_image_url' => $vice_captain_image_url,
                            'folder' => 'team_rejection_notifications',
                        ]);
                        $notification_big_picture = $rejection_banner_url ?: ($team_logo_url ?: ("https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png"));

                        sendOneSignalNotification(
                            $lead_onesignal_ids,
                            "Your team registration has been rejected.",
                            "Due to some reason your team '$team_name' registration has been rejected. Please contact your admin for more details.",
                            [
                                'type' => 'team_rejected',
                                'big_picture' => $notification_big_picture,
                                'image' => $notification_big_picture,
                                'large_icon' => $captain_image_url,
                                'small_icon' => 'ic_stat_notify',
                                'android_sound' => 'notification_sound'
                            ]
                        );
                    }
                }
                $msg = "Team registration rejected and deleted.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        header("Location: team_request.php?msg=" . urlencode($msg));
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch pending teams
$pending_teams = [];
try {
    $stmt = $pdo->query("
        SELECT t.*, u.name as creator_name 
        FROM teams t 
        LEFT JOIN users u ON t.created_by = u.id 
        WHERE t.status = 'pending' 
        ORDER BY t.id DESC
    ");
    $pending_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$page_title = "Team Requests";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Outfit', sans-serif; background-color: #f8fafc; }
    .page-header { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; padding: 3rem 1rem; margin-bottom: 2rem; border-radius: 0 0 2rem 2rem; }
    .request-card { background: white; border-radius: 1.5rem; transition: transform 0.2s; border: none; overflow: hidden; }
    .request-card:hover { transform: translateY(-3px); }
    .btn-circle { width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .btn-approve { background: #ecfdf5; color: #10b981; }
    .btn-approve:hover { background: #10b981; color: white; }
    .btn-reject { background: #fef2f2; color: #ef4444; }
    .btn-reject:hover { background: #ef4444; color: white; }
    .btn-view { background: #eff6ff; color: #3b82f6; }
    .btn-view:hover { background: #3b82f6; color: white; }
    .team-logo { width: 50px; height: 50px; border-radius: 50%; object-fit: contain; background: #f1f5f9; padding: 5px; }
</style>

<div class="page-header px-4">
    <div class="row align-items-center">
        <div class="col-md-2 col-12 text-start mb-3 mb-md-0">
            <a href="teams.php" class="btn btn-link text-white text-decoration-none p-0">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
        <div class="col-md-8 col-12 text-center">
            <h1 class="fw-bold mb-0">Team Requests</h1>
        </div>
        <div class="col-md-2 d-none d-md-block"></div>
    </div>
    <p class="opacity-75 mb-0 mt-2 text-center">Review and manage pending team registrations</p>
</div>

<div class="container mb-5">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <?php if (empty($pending_teams)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3 opacity-25"></i>
                    <h4 class="text-muted">No pending requests</h4>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-3">Team Details</th>
                                    <th class="py-3">Creator</th>
                                    <th class="py-3 text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_teams as $team): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <img src="<?= $team['team_logo'] ? '../uploads/teams/'.$team['team_logo'] : '../images/default-team.jpg' ?>" class="team-logo me-3 shadow-sm">
                                                <div>
                                                    <h6 class="mb-0 fw-bold"><?= htmlspecialchars($team['team_name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($team['team_code']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-medium"><?= htmlspecialchars($team['creator_name']) ?></div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="?action=approve&id=<?= $team['id'] ?>" class="btn-circle btn-approve" title="Approve" onclick="return confirm('Approve this team?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="?action=reject&id=<?= $team['id'] ?>" class="btn-circle btn-reject" title="Reject" onclick="return confirm('Reject and delete this team registration?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                                <a href="../view/view_team.php?team_id=<?= $team['id'] ?>" class="btn-circle btn-view" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="text-center mt-4">
        <a href="teams.php" class="btn btn-link text-muted text-decoration-none">
            <i class="fas fa-arrow-left me-1"></i> Back to Teams
        </a>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
