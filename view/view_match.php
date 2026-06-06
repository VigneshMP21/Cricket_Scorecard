<?php
require_once '../includes/db.php';
// Public access - no login required

// Get match ID from URL
$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$match_id) {
    header("Location: ../NavBarList/matches.php?error=3");
    exit();
}

// Fetch match details with team information
$match = null;
try {
    $stmt = $pdo->prepare("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t1.captain_id as team1_captain_id, t1.vice_captain_id as team1_vice_captain_id,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               t2.captain_id as team2_captain_id, t2.vice_captain_id as team2_vice_captain_id,
               tr.tournament_name, tr.tournament_code
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        header("Location: ../NavBarList/matches.php?error=3");
        exit();
    }
} catch (PDOException $e) {
    header("Location: ../NavBarList/matches.php?error=1");
    exit();
}

// Function to get team players with stats
function getTeamPlayers($pdo, $match_id, $team_id, $default_captain_id, $default_vice_captain_id, $team_name)
{
    try {
        // Try to get from match_squads first
        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.full_name, u.profile_image, u.playing_role, 
                   ms.is_captain, ms.is_vice_captain,
                   COALESCE(ps.matches_played, 0) as matches_played,
                   COALESCE(ps.runs_scored, 0) as runs_scored,
                   COALESCE(ps.wickets_taken, 0) as wickets_taken
            FROM match_squads ms
            JOIN users u ON ms.player_id = u.id
            LEFT JOIN player_stats ps ON u.id = ps.player_id
            WHERE ms.match_id = ? AND ms.team_id = ?
            ORDER BY ms.is_captain DESC, ms.is_vice_captain DESC, u.name ASC
        ");
        $stmt->execute([$match_id, $team_id]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($players)) {
            // Fallback to default team players
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.full_name, u.profile_image, u.playing_role,
                       (u.id = ?) as is_captain, (u.id = ?) as is_vice_captain,
                       COALESCE(ps.matches_played, 0) as matches_played,
                       COALESCE(ps.runs_scored, 0) as runs_scored,
                       COALESCE(ps.wickets_taken, 0) as wickets_taken
                FROM team_players tp
                JOIN users u ON tp.player_id = u.id
                LEFT JOIN player_stats ps ON u.id = ps.player_id
                WHERE tp.team_id = ?
                ORDER BY is_captain DESC, is_vice_captain DESC, u.name ASC
            ");
            $stmt->execute([$default_captain_id, $default_vice_captain_id, $team_id]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Add team name to each player for the modal
        foreach ($players as &$p) {
            $p['team_name'] = $team_name;
        }

        // Extract Captain, VC and Others
        $captain = null;
        $vice_captain = null;
        $other_players = [];

        foreach ($players as $p) {
            if ($p['is_captain']) {
                $captain = $p;
            } elseif ($p['is_vice_captain']) {
                $vice_captain = $p;
            } else {
                $other_players[] = $p;
            }
        }

        // Merge and Sort
        $final_players = [];
        if ($captain) {
            $final_players[] = $captain;
        }
        if ($vice_captain) {
            $final_players[] = $vice_captain;
        }

        usort($other_players, function ($a, $b) {
            // Role Sorting: Batsman > Wicket-keeper > All-rounder > Bowler
            $role_order = [
                'batsman' => 1,
                'wicket-keeper' => 2,
                'wicketkeeper' => 2,
                'all-rounder' => 3,
                'allrounder' => 3,
                'bowler' => 4
            ];
            $role_a = strtolower($a['playing_role'] ?? 'player');
            $role_b = strtolower($b['playing_role'] ?? 'player');

            $order_a = $role_order[$role_a] ?? 5;
            $order_b = $role_order[$role_b] ?? 5;

            if ($order_a !== $order_b) {
                return $order_a - $order_b;
            }

            // Alphabetical by Name
            return strcasecmp($a['name'], $b['name']);
        });

        $final_players = array_merge($final_players, $other_players);

        return [
            'captain' => $captain,
            'vice_captain' => $vice_captain,
            'players' => $other_players, // Keeping this for backward compatibility if needed elsewhere
            'all' => $final_players // New flattened and sorted list
        ];
    } catch (PDOException $e) {
        return ['captain' => null, 'vice_captain' => null, 'players' => [], 'all' => []];
    }
}

// Get players for both teams
$team1_players = getTeamPlayers($pdo, $match_id, $match['team1_id'], $match['team1_captain_id'], $match['team1_vice_captain_id'], $match['team1_name']);
$team2_players = getTeamPlayers($pdo, $match_id, $match['team2_id'], $match['team2_captain_id'], $match['team2_vice_captain_id'], $match['team2_name']);

$page_title = htmlspecialchars($match['match_code']) . " - Match Details";
require_once '../includes/header.php';
?>

<style>
    /* ... Previous styles ... */
    .match-hero-section {
        background: linear-gradient(135deg, #0d2438 0%, #1a365d 100%);
        color: white;
        padding-top: 2rem;
        padding-bottom: 5rem;
        position: relative;
        overflow: hidden;
    }

    .match-hero-bg-accent {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: radial-gradient(circle at top right, rgba(255, 255, 255, 0.05), transparent 40%),
            radial-gradient(circle at bottom left, rgba(255, 255, 255, 0.05), transparent 40%);
        pointer-events: none;
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        border-radius: 20px;
    }

    .match-info-card {
        margin-top: -4rem;
        z-index: 10;
        position: relative;
    }

    .team-logo-lg {
        width: 100px;
        height: 100px;
        object-fit: contain;
        background: white;
        border-radius: 50%;
        padding: 5px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.8);
    }

    .vs-badge-lg {
        width: 60px;
        height: 60px;
        background: #dc3545;
        color: white;
        font-weight: 800;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        border: 3px solid white;
        position: relative;
        z-index: 2;
    }

    .player-list-item {
        transition: transform 0.2s ease, background-color 0.2s ease;
        border-bottom: 1px solid #f0f0f0;
        cursor: pointer;
    }

    .player-list-item:hover {
        background-color: #f8fafc;
        transform: translateX(5px);
    }

    .player-list-item:last-child {
        border-bottom: none;
    }

    .role-text-badge {
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 500;
        text-transform: capitalize;
    }

    .role-badge {
        font-size: 0.7rem;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-captain {
        background-color: #ffc107;
        color: #000;
    }

    .badge-vc {
        background-color: #17a2b8;
        color: #fff;
    }

    .player-avatar-sm {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e2e8f0;
    }

    /* Player Card Modal Styles */
    .player-card-modal-content {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(15px);
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.5);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .player-card-img-container {
        width: 140px;
        height: 140px;
        margin: 0 auto;
        position: relative;
    }

    .player-card-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid white;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .stat-box {
        background: #f8fafc;
        border-radius: 12px;
        padding: 10px 5px;
        text-align: center;
        border: 1px solid #e2e8f0;
    }

    .stat-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1a365d;
    }

    .stat-label {
        font-size: 0.7rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .team-logo-lg {
            width: 70px;
            height: 70px;
        }

        .vs-badge-lg {
            width: 40px;
            height: 40px;
            font-size: 0.9rem;
        }
    }
</style>

<div style="background-color: #f8fafc; min-height: 100vh;">
    <!-- Hero Section -->
    <div class="match-hero-section text-center">
        <div class="match-hero-bg-accent"></div>
        <div class="container position-relative z-1">
            <!-- Mobile Back Button -->
            <div class="d-md-none text-start mb-3">
                <a href="../NavBarList/matches.php" class="btn btn-outline-light btn-sm rounded-pill">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>

            <!-- Desktop Back Button -->
            <a href="../NavBarList/matches.php"
                class="btn btn-outline-light btn-sm rounded-pill position-absolute top-0 start-0 m-3 d-none d-md-inline-flex"
                style="z-index: 10;">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center mb-4">
                    <li class="breadcrumb-item"><a href="../NavBarList/matches.php"
                            class="text-white-50 text-decoration-none">Matches</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">
                        <?= htmlspecialchars($match['match_code']) ?>
                    </li>
                </ol>
            </nav>

            <span class="badge rounded-pill bg-white text-primary px-3 py-2 mb-3 fw-bold shadow-sm">
                <?= htmlspecialchars($match['match_type']) ?> • <?= htmlspecialchars($match['overs']) ?> Overs
            </span>

            <h1 class="h3 fw-bold mb-1 d-none d-md-block text-white">
                <?= htmlspecialchars($match['tournament_name'] ?: 'Friendly Match') ?>
            </h1>
            <p class="text-white-50 mb-4 small">
                <i class="fas fa-map-marker-alt me-1"></i> <?= htmlspecialchars($match['venue']) ?>
            </p>
        </div>
    </div>

    <div class="container pb-5">
        <!-- Main Match Card -->
        <div class="card glass-card match-info-card border-0 mb-5">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <!-- Team 1 -->
                    <div class="col-4 text-center">
                        <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                            alt="<?= htmlspecialchars($match['team1_name']) ?>" class="team-logo-lg mb-3">
                        <h4 class="fw-bold mb-0 d-none d-md-block"><?= htmlspecialchars($match['team1_name']) ?></h4>
                        <h5 class="fw-bold mb-0 d-md-none"><?= htmlspecialchars($match['team1_code']) ?></h5>
                    </div>
                    <!-- VS -->
                    <div class="col-4">
                        <div class="d-flex flex-column align-items-center">
                            <div class="vs-badge-lg mb-3">VS</div>
                            <div class="text-center">
                                <?php if ($match['status'] == 'upcoming'): ?>
                                    <h5 class="fw-bold text-dark mb-1">
                                        <?= date('h:i A', strtotime($match['match_time'])) ?>
                                    </h5>
                                    <p class="text-muted small mb-0 fw-bold">
                                        <?= date('d M, Y', strtotime($match['match_date'])) ?>
                                    </p>
                                <?php elseif ($match['status'] == 'ongoing'): ?>
                                    <div class="mb-2">
                                        <span class="badge bg-danger animate-pulse px-3 py-2">LIVE NOW</span>
                                    </div>
                                    <a href="../live_stream/live_match.php?id=<?= $match['id'] ?>"
                                        class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                        Watch Live
                                    </a>
                                <?php else: ?>
                                    <div class="mb-2">
                                        <span class="badge bg-success px-3 py-2">COMPLETED</span>
                                    </div>
                                    <a href="../view_match_summary.php?id=<?= $match['id'] ?>"
                                        class="btn btn-sm btn-outline-success rounded-pill fw-bold">
                                        Scorecard
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Team 2 -->
                    <div class="col-4 text-center">
                        <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                            alt="<?= htmlspecialchars($match['team2_name']) ?>" class="team-logo-lg mb-3">
                        <h4 class="fw-bold mb-0 d-none d-md-block"><?= htmlspecialchars($match['team2_name']) ?></h4>
                        <h5 class="fw-bold mb-0 d-md-none"><?= htmlspecialchars($match['team2_code']) ?></h5>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                <div class="card-footer bg-light border-top p-3">
                    <div class="d-flex justify-content-center gap-2">
                        <a href="../edit/edit_match.php?id=<?= $match['id'] ?>"
                            class="btn btn-secondary btn-sm rounded-pill px-3">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <?php if ($match['status'] == 'upcoming'): ?>
                            <a href="../admin/start_match/initialize_match.php?id=<?= $match['id'] ?>"
                                class="btn btn-success btn-sm rounded-pill px-3 fw-bold">
                                <i class="fas fa-play me-1"></i> Start Match
                            </a>
                        <?php elseif ($match['status'] == 'ongoing'): ?>
                            <a href="../admin/scoring.php?id=<?= $match['id'] ?>"
                                class="btn btn-danger btn-sm rounded-pill px-3 fw-bold">
                                <i class="fas fa-gamepad me-1"></i> Scoring
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Squads Section -->
        <h4 class="fw-bold mb-4 ps-2 border-start border-4 border-primary">Team Squads</h4>
        <div class="row g-4">
            <!-- Team 1 Squad -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-bottom p-3">
                        <div class="d-flex align-items-center">
                            <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                                class="rounded-circle me-2" width="30" height="30">
                            <h5 class="mb-0 fw-bold"><?= htmlspecialchars($match['team1_name']) ?></h5>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($team1_players['all'])): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-users-slash fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">No players assigned</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($team1_players['all'] as $p): ?>
                                    <div class="list-group-item player-list-item px-3 py-2"
                                        onclick='showPlayerCard(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'>
                                        <div class="d-flex align-items-center">
                                            <img src="<?= $p['profile_image'] ? '../uploads/users/' . $p['profile_image'] : '../images/default-player.png' ?>"
                                                class="player-avatar-sm me-3">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center">
                                                    <span
                                                        class="text-dark fw-bold me-2"><?= htmlspecialchars($p['name']) ?></span>
                                                    <?php if ($p['is_captain']): ?>
                                                        <span class="role-badge badge-captain">C</span>
                                                    <?php endif; ?>
                                                    <?php if ($p['is_vice_captain']): ?>
                                                        <span class="role-badge badge-vc">VC</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="role-text-badge text-muted small">
                                                    <?= htmlspecialchars($p['playing_role'] ?: 'Player') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Team 2 Squad -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-bottom p-3">
                        <div class="d-flex align-items-center">
                            <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                                class="rounded-circle me-2" width="30" height="30">
                            <h5 class="mb-0 fw-bold"><?= htmlspecialchars($match['team2_name']) ?></h5>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($team2_players['all'])): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-users-slash fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">No players assigned</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($team2_players['all'] as $p): ?>
                                    <div class="list-group-item player-list-item px-3 py-2"
                                        onclick='showPlayerCard(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'>
                                        <div class="d-flex align-items-center">
                                            <img src="<?= $p['profile_image'] ? '../uploads/users/' . $p['profile_image'] : '../images/default-player.png' ?>"
                                                class="player-avatar-sm me-3">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center">
                                                    <span
                                                        class="text-dark fw-bold me-2"><?= htmlspecialchars($p['name']) ?></span>
                                                    <?php if ($p['is_captain']): ?>
                                                        <span class="role-badge badge-captain">C</span>
                                                    <?php endif; ?>
                                                    <?php if ($p['is_vice_captain']): ?>
                                                        <span class="role-badge badge-vc">VC</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="role-text-badge text-muted small">
                                                    <?= htmlspecialchars($p['playing_role'] ?: 'Player') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Player Card Modal -->
<div class="modal fade" id="playerCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content player-card-modal-content border-0">
            <div class="modal-body p-4 text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"
                    aria-label="Close"></button>

                <!-- Profile Image -->
                <div class="player-card-img-container mb-3">
                    <img id="modalPlayerInfoImg" src="../images/default-player.png" class="player-card-img"
                        alt="Player">
                </div>

                <!-- Name & Role -->
                <h4 class="fw-bold mb-1 text-dark" id="modalPlayerName">Player Name</h4>
                <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
                    <span class="badge bg-light text-dark border" id="modalPlayerRole">Batsman</span>
                    <span class="text-muted small">&bull;</span>
                    <span class="text-muted small fw-bold" id="modalTeamName">Team Name</span>
                </div>

                <!-- Stats Row -->
                <div class="row g-2 mb-4 px-2">
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="stat-value" id="modalMatches">0</div>
                            <div class="stat-label">Matches</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="stat-value" id="modalRuns">0</div>
                            <div class="stat-label">Runs</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="stat-value" id="modalWickets">0</div>
                            <div class="stat-label">Wickets</div>
                        </div>
                    </div>
                </div>

                <!-- View Profile Action -->
                <div class="d-grid">
                    <a href="#" id="modalProfileLink" class="btn btn-primary rounded-pill fw-bold shadow-sm">
                        View Stats
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        var playerCardModal = new bootstrap.Modal(document.getElementById('playerCardModal'));

        // Expose function globally so inline onclick works
        window.showPlayerCard = function (player) {
            // Populate Data
            document.getElementById('modalPlayerName').textContent = player.name;
            document.getElementById('modalTeamName').textContent = player.team_name;
            document.getElementById('modalPlayerRole').textContent = player.playing_role || 'Player';
            document.getElementById('modalPlayerInfoImg').src = player.profile_image ? '../uploads/users/' + player.profile_image : '../images/default-player.png';

            // Stats
            document.getElementById('modalMatches').textContent = player.matches_played;
            document.getElementById('modalRuns').textContent = player.runs_scored;
            document.getElementById('modalWickets').textContent = player.wickets_taken;

            // Link
            document.getElementById('modalProfileLink').href = 'view_player_profile.php?player_id=' + player.id;

            // Show
            playerCardModal.show();
        };
    });
</script>

<?php require_once '../includes/footer.php'; ?>