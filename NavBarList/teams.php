<?php
require_once '../includes/db.php';
// Public access - no login required

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        try { $dotenv->load(); } catch (Exception $e) {}
    }
}

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

// Configure Cloudinary
Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw",
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
    ],
    'url' => [
        'secure' => true
    ]
]);

function deleteFromCloudinary($public_id) {
    if (!$public_id) return;

    try {
        error_log("🗑️ Attempting to delete team logo from Cloudinary. public_id: " . $public_id);
        $uploadApi = new UploadApi();
        $response = $uploadApi->destroy($public_id);
        
        if (isset($response['result']) && $response['result'] === 'ok') {
            error_log("✅ Cloudinary Team Logo Delete SUCCESS: " . $public_id);
        } else {
            error_log("⚠️ Cloudinary Team Logo Delete WARNING: " . json_encode($response));
        }
    } catch (Exception $e) {
        error_log("❌ Cloudinary SDK Error: " . $e->getMessage());
    }
}

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $team_id = (int) $_GET['delete'];
        $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        $role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

        // Fetch team details for permission check and cleanup
        $stmt = $pdo->prepare("SELECT team_logo, team_logo_public_id, captain_id, vice_captain_id FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$team) {
            throw new Exception("Team not found.");
        }

        // Permission check: Admin OR Captain OR Vice-Captain
        $isAuthorized = ($role === 'admin' || $current_user_id == $team['captain_id'] || $current_user_id == $team['vice_captain_id']);
        
        if (!$isAuthorized) {
            header("Location: teams.php?error=unauthorized");
            exit();
        }

        // Start transaction
        $pdo->beginTransaction();

        // Delete team players first
        $stmt = $pdo->prepare("DELETE FROM team_players WHERE team_id = ?");
        $stmt->execute([$team_id]);

        // Delete from legacy players table to fix foreign key constraint
        $stmt = $pdo->prepare("DELETE FROM players WHERE team_id = ?");
        $stmt->execute([$team_id]);

        // Update users table to remove team association
        $stmt = $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id = ?");
        $stmt->execute([$team_id]);

        // Delete team
        $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);

        $pdo->commit();

        // --- CLOUDINARY CLEANUP ---
        if (!empty($team['team_logo_public_id'])) {
            deleteFromCloudinary($team['team_logo_public_id']);
        }

        // Delete team logo file if it exists locally
        if ($team && $team['team_logo']) {
            $logo_path = '../uploads/teams/' . $team['team_logo'];
            if (file_exists($logo_path)) {
                unlink($logo_path);
            }
        }

        // Redirect with success message
        $redirect_url = "teams.php?deleted=1";
        if (isset($_GET['tournament_id']) && is_numeric($_GET['tournament_id'])) {
            $redirect_url .= "&tournament_id=" . (int) $_GET['tournament_id'];
        }
        header("Location: " . $redirect_url);
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to delete team: " . $e->getMessage();
    }
}

// Handle success messages
$success_message = '';
if (isset($_GET['deleted'])) {
    $success_message = 'Team deleted successfully!';
} elseif (isset($_GET['updated'])) {
    $success_message = 'Team updated successfully!';
}

// Fetch tournaments for filter
$tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name ASC");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle tournament filter
$tournament_id = isset($_GET['tournament_id']) && is_numeric($_GET['tournament_id']) ? (int) $_GET['tournament_id'] : null;

// Fetch teams with captain and vice captain info
$teams = [];
try {
    // Check if user is logged in for the is_member check
    $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

    $query = "
        SELECT t.*,
               p1.name as captain_name, p1.profile_image as captain_image,
               p2.name as vice_captain_name, p2.profile_image as vice_captain_image,
               COUNT(tp.player_id) as player_count,
               (SELECT COUNT(*) FROM matches m WHERE (m.team1_id = t.id OR m.team2_id = t.id) AND m.status = 'completed') as completed_matches,
               (SELECT COUNT(*) FROM matches m WHERE (m.team1_id = t.id OR m.team2_id = t.id) AND m.status = 'upcoming') as upcoming_matches,
               (SELECT COUNT(*) FROM team_players tp2 WHERE tp2.team_id = t.id AND tp2.player_id = ?) as is_member
        FROM teams t
        LEFT JOIN users p1 ON t.captain_id = p1.id
        LEFT JOIN users p2 ON t.vice_captain_id = p2.id
        LEFT JOIN team_players tp ON t.id = tp.team_id
    ";

    $params = [$current_user_id];
    $query .= " WHERE t.status = 'active'";
    
    if ($tournament_id) {
        $query .= " AND t.tournament_id = ?";
        $params[] = $tournament_id;
    }

    $query .= " GROUP BY t.id ORDER BY t.team_name ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch pending request count for badge (admin only)
$pending_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM teams WHERE status = 'pending'");
        $pending_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {}
}

$page_title = "Teams";
require_once '../includes/header.php';
?>

<div class="teams-page-container">
    <div class="container-fluid py-4" style="max-width: 1600px;">

        <!-- Header Section -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <h2 class="page-title text-dark m-0">
                <i class="fas fa-shield-alt me-2 text-primary"></i>Teams Directory
            </h2>
            <div class="d-flex flex-column flex-lg-row gap-2 w-100 w-lg-auto align-items-center">
                <div class="tournament-filter position-relative" style="min-width: 250px;">
                    <i class="fas fa-filter text-muted position-absolute"
                        style="left: 15px; top: 50%; transform: translateY(-50%); z-index: 5;"></i>
                    <select name="tournament_id" id="tournament_id"
                        class="form-select ps-5 rounded-pill shadow-sm border-0"
                        onchange="window.location.href='teams.php?tournament_id=' + this.value">
                        <option value="">All Tournaments</option>
                        <?php foreach ($tournaments as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $tournament_id == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['tournament_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <div class="d-flex flex-row gap-2 w-100 w-lg-auto justify-content-center">
                        <a href="team_request.php"
                            class="btn btn-outline-primary rounded-pill px-3 flex-fill d-flex align-items-center justify-content-center shadow-sm position-relative small">
                            <i class="fas fa-list-check me-2"></i>Requests
                            <span id="request-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $pending_count > 0 ? '' : 'd-none' ?>">
                                <?= $pending_count ?>
                            </span>
                        </a>
                        <a href="../admin/create_team.php"
                            class="btn btn-primary rounded-pill px-3 flex-fill d-flex align-items-center justify-content-center shadow-sm small">
                            <i class="fas fa-plus me-2"></i>Create
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($error) || (isset($_GET['error']) && $_GET['error'] == 'unauthorized')): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm border-0" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= isset($error) ? htmlspecialchars($error) : "You do not have permission to perform this action." ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm border-0" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php
        $sections = [];
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'player') {
            $myTeams = [];
            $otherTeams = [];
            foreach ($teams as $team) {
                // Only list teams where the user is a member (player/captain/vc)
                if ($team['is_member'] > 0) {
                    $myTeams[] = $team;
                } else {
                    $otherTeams[] = $team;
                }
            }
            $sections[] = ['title' => 'My Team', 'teams' => $myTeams, 'is_my_section' => true];
            $sections[] = ['title' => 'Other Teams', 'teams' => $otherTeams, 'is_my_section' => false];
        } else {
            $sections[] = ['title' => '', 'teams' => $teams, 'is_my_section' => false];
        }
        ?>

        <?php if (empty($teams)): ?>
            <div class="empty-state text-center py-5">
                <div class="mb-3">
                    <div class="icon-circle bg-secondary-light mx-auto" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fas fa-shield-alt text-secondary"></i>
                    </div>
                </div>
                <h4 class="text-muted fw-bold">No teams found</h4>
                <p class="text-muted">There are no teams available for the selected tournament.</p>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <a href="../admin/create_team.php" class="btn btn-primary mt-2">
                        Create New Team
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($sections as $section): ?>
                <?php if (!empty($section['title']) && (!empty($section['teams']) || $section['is_my_section'])): ?>
                    <h4 class="mb-3 fw-bold text-dark border-bottom pb-2 mt-4">
                        <?= htmlspecialchars($section['title']) ?>
                        <?php if ($section['is_my_section']): ?>
                            <span class="badge bg-primary rounded-pill small ms-2 fs-6"><?= count($section['teams']) ?></span>
                        <?php endif; ?>
                    </h4>
                <?php endif; ?>

                <?php if (empty($section['teams']) && $section['is_my_section']): ?>
                    <div class="alert alert-light text-center border dashed p-4 mb-3 text-muted">
                        You haven't created any teams yet.
                    </div>
                <?php elseif (!empty($section['teams'])): ?>
                    <div class="row g-4 mb-4">
                        <?php foreach ($section['teams'] as $team):
                            $teamColor = !empty($team['team_color']) ? htmlspecialchars($team['team_color']) : '#3b82f6';
                            $defaultTeamLogo = '../assets/images/default-player.png';
                            $teamLogo = $team['team_logo'] ? '../uploads/teams/' . htmlspecialchars($team['team_logo']) : $defaultTeamLogo;

                            $defaultAvatar = '../assets/images/default-player.png';
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <div class="card team-card h-100 border-0 shadow-sm overflow-hidden">
                                    <!-- Team Color Stripe -->
                                    <div class="team-header-stripe" style="background-color: <?= $teamColor ?>;"></div>

                                    <div class="card-body pt-0 text-center position-relative">
                                        <!-- Team Logo -->
                                        <div
                                            class="team-logo-wrapper mx-auto mb-3 shadow-lg bg-white d-flex align-items-center justify-content-center">
                                            <img src="<?= $teamLogo ?>" alt="<?= htmlspecialchars($team['team_name']) ?>"
                                                class="team-logo">
                                        </div>

                                        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($team['team_name']) ?></h5>
                                        <div class="mb-3">
                                            <span class="badge bg-light text-dark border fw-medium px-3 py-2 rounded-pill">
                                                <?= htmlspecialchars($team['team_code']) ?>
                                            </span>
                                        </div>

                                        <!-- Key Players -->
                                        <div class="row g-2 mb-3 text-start">
                                            <div class="col-6">
                                                <div class="p-2 rounded bg-light border h-100">
                                                    <div class="small text-muted text-uppercase fw-bold mb-2"
                                                        style="font-size: 0.65rem;">Captain</div>
                                                    <?php if ($team['captain_name']):
                                                        $capImg = $team['captain_image'] ? '../uploads/users/' . htmlspecialchars($team['captain_image']) : $defaultAvatar;
                                                        ?>
                                                        <div class="d-flex align-items-center player-modal-trigger cursor-pointer"
                                                            data-player-id="<?= (int) $team['captain_id'] ?>">
                                                            <img src="<?= $capImg ?>" class="rounded-circle me-2" width="32" height="32"
                                                                style="object-fit: cover;">
                                                            <div class="text-truncate small fw-bold" style="max-width: 80px;">
                                                                <?= htmlspecialchars($team['captain_name']) ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted small fst-italic">--</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="p-2 rounded bg-light border h-100">
                                                    <div class="small text-muted text-uppercase fw-bold mb-2"
                                                        style="font-size: 0.65rem;">Vice Captain</div>
                                                    <?php if ($team['vice_captain_name']):
                                                        $vcImg = $team['vice_captain_image'] ? '../uploads/users/' . htmlspecialchars($team['vice_captain_image']) : $defaultAvatar;
                                                        ?>
                                                        <div class="d-flex align-items-center player-modal-trigger cursor-pointer"
                                                            data-player-id="<?= (int) $team['vice_captain_id'] ?>">
                                                            <img src="<?= $vcImg ?>" class="rounded-circle me-2" width="32" height="32"
                                                                style="object-fit: cover;">
                                                            <div class="text-truncate small fw-bold" style="max-width: 80px;">
                                                                <?= htmlspecialchars($team['vice_captain_name']) ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted small fst-italic">--</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Stats -->
                                        <div
                                            class="d-flex justify-content-between align-items-center px-3 py-2 bg-light rounded-3 mb-3">
                                            <div class="text-center">
                                                <div class="h5 mb-0 fw-bold text-success"><?= $team['completed_matches'] ?></div>
                                                <div class="small text-muted" style="font-size: 0.7rem;">Completed</div>
                                            </div>
                                            <div class="vr"></div>
                                            <div class="text-center">
                                                <div class="h5 mb-0 fw-bold text-warning"><?= $team['upcoming_matches'] ?></div>
                                                <div class="small text-muted" style="font-size: 0.7rem;">Upcoming</div>
                                            </div>
                                            <div class="vr"></div>
                                            <div class="text-center">
                                                <div class="h5 mb-0 fw-bold text-primary"><?= $team['player_count'] ?></div>
                                                <div class="small text-muted" style="font-size: 0.7rem;">Players</div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="d-grid gap-2">
                                            <a href="../view/view_team.php?team_id=<?= $team['id'] ?>"
                                                class="btn btn-outline-primary rounded-pill btn-sm fw-medium">
                                                View Details
                                            </a>

                                            <?php 
                                            $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');
                                            $currentUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
                                            $isC_or_VC = ($currentUserId == $team['captain_id'] || $currentUserId == $team['vice_captain_id']);
                                            
                                            if ($isAdmin || $isC_or_VC || $section['is_my_section']): ?>
                                                <div class="d-flex gap-2 justify-content-center mt-1">
                                                    <?php if ($section['is_my_section']): ?>
                                                        <a href="team_banner.php?team_id=<?= $team['id'] ?>"
                                                            class="btn btn-link text-primary p-0 text-decoration-none small">
                                                            <i class="fas fa-image me-1"></i>Team Banner
                                                        </a>
                                                        <?php if ($isAdmin || $isC_or_VC): ?>
                                                            <span class="text-muted">|</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php if ($isAdmin || $isC_or_VC): ?>
                                                        <a href="../edit/edit_team.php?id=<?= $team['id'] ?>"
                                                            class="btn btn-link text-warning p-0 text-decoration-none small">
                                                            <i class="fas fa-edit me-1"></i>Edit
                                                        </a>
                                                        <span class="text-muted">|</span>
                                                        <button class="btn btn-link text-danger p-0 text-decoration-none small delete-team-btn"
                                                            data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                                            data-team-id="<?= $team['id'] ?>"
                                                            data-team-name="<?= htmlspecialchars($team['team_name']) ?>">
                                                            <i class="fas fa-trash me-1"></i>Delete
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Player Profile Modal -->
<div class="modal fade" id="playerProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-0 text-center position-relative">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal"
                    aria-label="Close"></button>
                <div class="bg-light p-4 d-flex justify-content-center align-items-center">
                    <img id="modalProfileImage" src="" alt="Player"
                        class="rounded-circle shadow-sm border border-4 border-white"
                        style="width: 120px; height: 120px; object-fit: cover;">
                </div>
                <div class="p-4">
                    <h4 id="modalPlayerName" class="fw-bold text-dark mb-1"></h4>
                    <div class="mb-3">
                        <span id="modalPlayingRole" class="badge bg-secondary rounded-pill px-3"></span>
                    </div>
                    <div class="text-muted mb-4 small">
                        Playing for <strong id="modalTeamName" class="text-dark"></strong>
                    </div>
                    <a id="viewStatsBtn" href="#" class="btn btn-primary rounded-pill w-100 fw-bold">
                        View Full Stats
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-body p-4 text-center">
                <div class="icon-circle bg-danger-light text-danger mx-auto mb-3"
                    style="width: 60px; height: 60px; font-size: 1.5rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background:#fee2e2;">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h5 class="fw-bold mb-3">Delete Team?</h5>
                <p class="text-muted mb-4">
                    Are you sure you want to delete <strong id="deleteTeamName" class="text-dark"></strong>?<br>
                    This will remove all players from this team intact. This action cannot be undone.
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light rounded-pill px-4"
                        data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger rounded-pill px-4">Delete Permanently</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Variables */
    :root {
        --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        --card-hover-transform: translateY(-5px);
    }

    body {
        background: #f1f5f9;
    }

    .teams-page-container {
        min-height: calc(100vh - 76px);
        background: var(--bg-gradient);
    }

    /* Team Card */
    .team-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        background: white;
        border-radius: 16px;
    }

    .team-card:hover {
        transform: var(--card-hover-transform);
        box-shadow: 0 12px 25px -5px rgba(0, 0, 0, 0.1) !important;
    }

    .team-header-stripe {
        height: 100px;
        width: 100%;
    }

    .team-logo-wrapper {
        margin-top: -50px;
        width: 90px;
        height: 90px;
        border-radius: 50%;
        position: relative;
        z-index: 2;
    }

    .team-logo {
        width: 90%;
        height: 90%;
        object-fit: contain;
        border-radius: 50%;
    }

    .cursor-pointer {
        cursor: pointer;
    }

    @media (max-width: 576px) {
        .page-title {
            font-size: 1.5rem;
        }
    }

    /* Ensure player profile modal appears on top of other modals */
    #playerProfileModal {
        z-index: 1060;
    }
</style>

<script>
    // Initialize tooltips/popovers if needed



    // Delete Team Modal
    document.querySelectorAll('.delete-team-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const teamId = this.getAttribute('data-team-id');
            const teamName = this.getAttribute('data-team-name');
            const urlParams = new URLSearchParams(window.location.search);
            const tournamentId = urlParams.get('tournament_id');

            document.getElementById('deleteTeamName').textContent = teamName;
            let deleteUrl = `teams.php?delete=${teamId}`;
            if (tournamentId) {
                deleteUrl += `&tournament_id=${tournamentId}`;
            }
            document.getElementById('confirmDeleteBtn').href = deleteUrl;
        });
    });

    // Player Modal Delegate
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.player-modal-trigger');
        if (trigger) {
            const playerId = trigger.getAttribute('data-player-id');

            fetch(`../view/get_player_modal_data.php?player_id=${playerId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Prepend ../ because we are in NavBarList directory
                        const imgPath = data.profile_image ? '../' + data.profile_image : '../assets/images/default_player.png';
                        document.getElementById('modalProfileImage').src = imgPath;

                        document.getElementById('modalPlayerName').textContent = data.name;
                        document.getElementById('modalPlayingRole').textContent = data.playing_role;
                        document.getElementById('modalTeamName').textContent = data.team_name || 'Not assigned';
                        document.getElementById('viewStatsBtn').href = `../view/view_player_profile.php?player_id=${playerId}`;

                        const modal = new bootstrap.Modal(document.getElementById('playerProfileModal'));
                        modal.show();
                    } else {
                        // Silent fail or toast could be better
                        console.error('Error loading player data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }
    });
    // Polling for team requests count
    function updateRequestBadge() {
        fetch('get_request_count.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('request-badge');
                    if (badge) {
                        badge.textContent = data.count;
                        if (data.count > 0) {
                            badge.classList.remove('d-none');
                        } else {
                            badge.classList.add('d-none');
                        }
                    }
                }
            })
            .catch(err => console.error('Error fetching request count:', err));
    }

    // Update every 30 seconds if admin
    if (document.getElementById('request-badge')) {
        setInterval(updateRequestBadge, 30000);
    }
</script>

<?php require_once '../includes/footer.php'; ?>