<?php
require_once '../includes/db.php';

if (!isset($_GET['team_id']) || !is_numeric($_GET['team_id'])) {
    header("Location: ../NavBarList/teams.php");
    exit();
}

$team_id = (int) $_GET['team_id'];
$team = null;
$players = [];

try {
    // Get team info
    $stmt = $pdo->prepare("
        SELECT t.*,
               p1.name as captain_name, p1.profile_image as captain_image,
               p2.name as vice_captain_name, p2.profile_image as vice_captain_image,
               (SELECT COUNT(*) FROM matches m WHERE (m.team1_id = t.id OR m.team2_id = t.id) AND m.status = 'completed') as matches_played,
               (SELECT COUNT(*) FROM matches m WHERE (m.team1_id = t.id OR m.team2_id = t.id) AND m.status = 'completed' AND m.winner_id = t.id) as matches_won
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

    // Get team players
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.profile_image, u.playing_role,
               (SELECT COUNT(DISTINCT msq.match_id)
                FROM match_squads msq
                JOIN matches m ON msq.match_id = m.id
                WHERE msq.player_id = u.id AND msq.playing_11 = 1 AND m.status = 'completed') as matches,
               (SELECT COALESCE(SUM(ms.runs_scored), 0)
                FROM match_statistics ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = u.id AND m.status = 'completed') as runs_scored,
               (SELECT COALESCE(SUM(ms.wickets_taken), 0)
                FROM match_statistics ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = u.id AND m.status = 'completed') as wickets_taken
        FROM team_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.team_id = ?
        ORDER BY 
            CASE 
                WHEN u.id = ? THEN 1 
                WHEN u.id = ? THEN 2 
                WHEN u.playing_role LIKE '%Batsman%' THEN 3
                WHEN u.playing_role LIKE '%Wicket%' OR u.playing_role LIKE '%WK%' THEN 4
                WHEN u.playing_role LIKE '%All%' THEN 5
                WHEN u.playing_role LIKE '%Bowler%' THEN 6
                ELSE 7 
            END,
            u.name
    ");
    $stmt->execute([$team_id, $team['captain_id'], $team['vice_captain_id']]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$page_title = htmlspecialchars($team['team_name']);
require_once '../includes/header.php';

// Default images
$teamLogo = $team['team_logo'] ? '../uploads/teams/' . $team['team_logo'] : '../assets/images/default_player.png';
?>

<style>
    :root {
        --team-color: #4f46e5;
        --team-color-rgb: 79, 70, 229;
        --standard-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    }

    body {
        background-color: #f8fafc;
    }

    /* Hero Section */
    .team-hero {
        background: var(--standard-gradient), url('../assets/images/pattern-bg.png');
        /* Fallback or pattern */
        background-size: cover;
        position: relative;
        padding-top: 80px;
        padding-bottom: 100px;
        margin-bottom: 40px;
        border-radius: 0 0 50px 50px;
        box-shadow: 0 10px 30px -10px rgba(79, 70, 229, 0.5);
        color: white;
        overflow: hidden;
    }

    .team-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.2), transparent 60%);
        pointer-events: none;
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 16px;
        padding: 20px;
        transition: transform 0.3s ease;
    }

    .glass-card:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
    }

    .team-logo-hero {
        width: 160px;
        height: 160px;
        object-fit: contain;
        background: white;
        border-radius: 50%;
        padding: 10px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        border: 4px solid rgba(255, 255, 255, 0.8);
    }

    .stat-box {
        text-align: center;
        padding: 15px;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
    }

    .stat-box:last-child {
        border-right: none;
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 5px;
    }

    .stat-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.8;
    }

    /* Player Cards */
    .squad-section {
        margin-top: -80px;
        position: relative;
        z-index: 2;
        padding-bottom: 60px;
    }

    .player-card {
        background: white;
        border-radius: 20px;
        border: none;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        height: 100%;
        position: relative;
    }

    .player-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(var(--team-color-rgb), 0.15);
    }

    .player-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 6px;
        background: var(--team-color);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.3s ease;
    }

    .player-card:hover::before {
        transform: scaleX(1);
    }

    .player-img-wrapper {
        width: 90px;
        height: 90px;
        margin: 0 auto 15px;
        position: relative;
    }

    .player-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        border: 3px solid #f1f5f9;
        transition: border-color 0.3s ease;
    }

    .player-card:hover .player-img {
        border-color: var(--team-color);
    }

    .role-badge {
        font-size: 0.7rem;
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 600;
        background: #f1f5f9;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .player-stats-row {
        display: flex;
        align-items: stretch;
        justify-content: space-between;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
    }

    .player-stat-item {
        flex: 1;
        text-align: center;
    }

    .player-stat-item+.player-stat-item {
        border-left: 1px solid rgba(148, 163, 184, 0.3);
    }

    .player-stat-number {
        font-size: 0.95rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
    }

    .player-stat-label {
        margin-top: 4px;
        color: #64748b;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }

    .profile-link-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 14px;
        padding: 10px 16px;
        border-radius: 999px;
        background: rgba(var(--team-color-rgb), 0.08);
        color: var(--team-color);
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .profile-link-btn:hover {
        background: var(--team-color);
        color: #fff;
        transform: translateY(-1px);
    }

    .captain-badge {
        position: absolute;
        bottom: -5px;
        left: 50%;
        transform: translateX(-50%);
        background: #fbbf24;
        color: #78350f;
        font-size: 0.65rem;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 800;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 2;
        white-space: nowrap;
    }

    .vc-badge {
        position: absolute;
        bottom: -5px;
        left: 50%;
        transform: translateX(-50%);
        background: #bae6fd;
        color: #0c4a6e;
        font-size: 0.65rem;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 800;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 2;
        white-space: nowrap;
    }

    /* Back Button */
    .back-nav {
        position: absolute;
        top: 20px;
        left: 20px;
        z-index: 10;
        display: inline-flex;
        align-items: center;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(5px);
        padding: 8px 16px;
        border-radius: 30px;
        color: white;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        border: 1px solid rgba(255, 255, 255, 0.3);
        transition: all 0.2s ease;
    }

    .back-nav:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
        transform: translateX(-3px);
    }

    @media (max-width: 768px) {
        .team-hero {
            min-height: 50vh;
            padding-top: 40px;
            padding-bottom: 40px;
            text-align: left;
            display: flex;
            align-items: center;
            border-radius: 0 0 30px 30px;
        }

        .team-hero h1.display-4 {
            font-size: 1.8rem;
        }

        .team-info-row {
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
        }

        .team-logo-hero {
            width: 130px;
            height: 130px;
            margin-bottom: 0;
            border-width: 3px;
        }

        /* Stats row on mobile */
        .glass-card.d-flex {
            margin-top: 15px;
            padding: 12px;
            width: 100%;
        }

        .stat-box {
            padding: 5px;
            flex-basis: 33.33%;
            border-bottom: none;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-box:last-child {
            border-right: none;
        }

        .stat-value {
            font-size: 1.4rem;
        }

        .stat-label {
            font-size: 0.7rem;
        }

        .squad-section {
            margin-top: -60px;
        }

        /* Compact Player Card on Mobile */
        .player-card {
            padding: 1.25rem !important;
        }

        .player-img-wrapper {
            width: 70px;
            height: 70px;
            margin-bottom: 10px;
        }

        .player-card h5 {
            font-size: 1rem;
        }

        .role-badge {
            font-size: 0.6rem;
            padding: 3px 8px;
        }

        .player-stats-row {
            margin-top: 0.85rem;
            padding-top: 0.85rem;
        }

        .player-stat-number {
            font-size: 0.85rem;
        }

        .player-stat-label {
            font-size: 0.62rem;
        }

        .profile-link-btn {
            width: 100%;
            margin-top: 12px;
            padding: 9px 12px;
            font-size: 0.75rem;
        }
    }
</style>

<!-- Hero Section -->
<div class="team-hero">
    <div class="container-fluid" style="max-width: 1400px;">
        <a href="../NavBarList/teams.php" class="back-nav">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>

        <div class="row team-info-row align-items-center mt-4">
            <!-- Logo Column -->
            <div class="col-5 col-md-3 text-center mb-0 mb-md-0">
                <img src="<?= $teamLogo ?>" alt="Team Logo" class="team-logo-hero animate__animated animate__zoomIn">
            </div>

            <!-- Info Column -->
            <div class="col-7 col-md-5 mb-0 mb-md-0 ps-0 ps-md-3">
                <span
                    class="badge bg-white bg-opacity-25 border border-white border-opacity-25 px-2 py-1 rounded-pill mb-2 animate__animated animate__fadeInDown d-inline-block"
                    style="font-size: 0.7rem;">
                    <i class="fas fa-hashtag me-1"></i> <?= htmlspecialchars($team['team_code']) ?>
                </span>
                <h1 class="display-4 fw-bold mb-1 animate__animated animate__fadeInUp">
                    <?= htmlspecialchars($team['team_name']) ?>
                </h1>

                <div
                    class="d-flex align-items-center justify-content-start gap-3 gap-md-4 mt-2 animate__animated animate__fadeInUp animate__delay-1s">
                    <?php if ($team['captain_name']): ?>
                        <div
                            class="d-flex align-items-center bg-white bg-opacity-10 rounded-pill pe-3 py-1 border border-white border-opacity-10">
                            <div class="position-relative me-2 ps-1">
                                <img src="<?= $team['captain_image'] ? '../uploads/users/' . $team['captain_image'] : '../assets/images/default_player.png' ?>"
                                    class="rounded-circle border border-1 border-warning"
                                    style="width: 35px; height: 35px; object-fit: cover;">
                                <div class="position-absolute bottom-0 end-0 bg-warning rounded-circle d-flex align-items-center justify-content-center"
                                    style="width: 14px; height: 14px; font-size: 8px; color: black; font-weight: bold; border: 1px solid white;">
                                    C
                                </div>
                            </div>
                            <span class="fw-bold"
                                style="font-size: 12px;"><?= htmlspecialchars(explode(' ', $team['captain_name'])[0]) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($team['vice_captain_name']): ?>
                        <div
                            class="d-flex align-items-center bg-white bg-opacity-10 rounded-pill pe-3 py-1 border border-white border-opacity-10">
                            <div class="position-relative me-2 ps-1">
                                <img src="<?= $team['vice_captain_image'] ? '../uploads/users/' . $team['vice_captain_image'] : '../assets/images/default_player.png' ?>"
                                    class="rounded-circle border border-1 border-info"
                                    style="width: 35px; height: 35px; object-fit: cover;">
                                <div class="position-absolute bottom-0 end-0 bg-info rounded-circle d-flex align-items-center justify-content-center"
                                    style="width: 14px; height: 14px; font-size: 8px; color: white; font-weight: bold; border: 1px solid white;">
                                    VC
                                </div>
                            </div>
                            <span class="fw-bold"
                                style="font-size: 12px;"><?= htmlspecialchars(explode(' ', $team['vice_captain_name'])[0]) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Column (2nd row on mobile) -->
            <div class="col-12 col-md-4 mt-3 mt-md-0">
                <div
                    class="glass-card d-flex justify-content-around align-items-center animate__animated animate__fadeInRight">
                    <div class="stat-box flex-grow-1">
                        <div class="stat-value"><?= count($players) ?></div>
                        <div class="stat-label">Players</div>
                    </div>
                    <div class="stat-box flex-grow-1">
                        <div class="stat-value"><?= $team['matches_played'] ?? 0 ?></div>
                        <div class="stat-label">Matches</div>
                    </div>
                    <div class="stat-box flex-grow-1">
                        <div class="stat-value"><?= $team['matches_won'] ?? 0 ?></div>
                        <div class="stat-label">Wins</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Squad Section -->
<div class="container-fluid squad-section" style="max-width: 1400px;">
    <?php if (empty($players)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center">
            <div class="mb-3">
                <i class="fas fa-users-slash fa-4x text-muted opacity-25"></i>
            </div>
            <h4 class="text-muted fw-bold">No Players Found</h4>
            <p class="text-muted">This team currently has no players assigned.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($players as $player): ?>
                <div class="col-xl-3 col-lg-4 col-md-4 col-6">
                    <div class="player-card p-4 text-center">
                        <div class="player-img-wrapper">
                            <img src="<?= $player['profile_image'] ? '../uploads/users/' . $player['profile_image'] : '../assets/images/default_player.png' ?>"
                                alt="<?= htmlspecialchars($player['name']) ?>" class="player-img">

                            <?php if ($player['id'] == $team['captain_id']): ?>
                                <div class="captain-badge">CAPTAIN</div>
                            <?php elseif ($player['id'] == $team['vice_captain_id']): ?>
                                <div class="vc-badge">VICE CAPTAIN</div>
                            <?php endif; ?>
                        </div>

                        <h5 class="fw-bold mb-1 text-truncate"><?= htmlspecialchars($player['name']) ?></h5>
                        <div class="mb-3">
                            <span class="role-badge"><?= htmlspecialchars($player['playing_role']) ?></span>
                        </div>

                        <div class="player-stats-row">
                            <div class="player-stat-item">
                                <div class="player-stat-number"><?= $player['matches'] ?? 0 ?></div>
                                <div class="player-stat-label">Matches</div>
                            </div>
                            <div class="player-stat-item">
                                <div class="player-stat-number"><?= $player['runs_scored'] ?? 0 ?></div>
                                <div class="player-stat-label">Runs</div>
                            </div>
                            <div class="player-stat-item">
                                <div class="player-stat-number"><?= $player['wickets_taken'] ?? 0 ?></div>
                                <div class="player-stat-label">Wickets</div>
                            </div>
                        </div>

                        <a href="view_player_profile.php?player_id=<?= $player['id'] ?>" class="profile-link-btn">
                            View Profile
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    // Optional: Add simple animation triggers or interactions here
    // Currently using CSS transitions and animate.css classes (assuming animate.css is loaded in header, if not, they just won't animate but still show)
</script>

<?php require_once '../includes/footer.php'; ?>
