<?php
require_once '../includes/db.php';

// Validate player_id
$player_id = isset($_GET['player_id']) ? (int) $_GET['player_id'] : 0;
$player = null;
$playerStats = null;
$error_message = '';

if ($player_id <= 0) {
    $error_message = 'Invalid player ID.';
} else {
    try {
        // Fetch User + Team Info
        $stmt = $pdo->prepare("
            SELECT u.*, t.team_name, t.team_logo, t.team_color
            FROM users u
            LEFT JOIN team_players tp ON u.id = tp.player_id
            LEFT JOIN teams t ON tp.team_id = t.id
            WHERE u.id = ? AND u.role = 'player'
        ");
        $stmt->execute([$player_id]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player) {
            // Fetch Stats (Calculate Dynamically for Accuracy)
            // 1. Correct Match Count
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ms.match_id) 
                FROM match_squads ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = ? AND ms.playing_11 = 1 AND m.status = 'completed'
            ");
            $stmt->execute([$player_id]);
            $real_matches = $stmt->fetchColumn();

            // 2. Fetch Aggregated Stats
            // (Note: Removed direct ball_by_ball wickets query to rely on match_statistics)

            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(ms.runs_scored), 0) as runs_scored,
                    COALESCE(SUM(ms.balls_faced), 0) as balls_faced,
                    COALESCE(SUM(ms.fours), 0) as fours,
                    COALESCE(SUM(ms.sixes), 0) as sixes,
                    COALESCE(SUM(ms.runs_conceded), 0) as runs_conceded,
                    COALESCE(SUM(ms.wickets_taken), 0) as wickets_taken,
                    MAX(ms.runs_scored) as highest_score,
                    COUNT(CASE WHEN ms.runs_scored >= 100 THEN 1 END) as centuries,
                    COUNT(CASE WHEN ms.runs_scored >= 50 AND ms.runs_scored < 100 THEN 1 END) as half_centuries
                FROM match_statistics ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = ? AND m.status = 'completed'
            ");
            $stmt->execute([$player_id]);
            $agg_stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Use the aggregated wickets as real_wickets
            $real_wickets = $agg_stats['wickets_taken'];

            // 2.1 Calculate Innings Batted
            // An inning is counted if the player has an entry in match_statistics with balls_faced > 0
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM match_statistics ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = ? AND ms.balls_faced > 0 AND m.status = 'completed'
            ");
            $stmt->execute([$player_id]);
            $innings_batted = $stmt->fetchColumn();

            // 2.2 Bowling Stats
            // Fetch all bowling performances from match_statistics
            $stmt = $pdo->prepare("
                SELECT ms.runs_conceded, ms.wickets_taken, ms.overs_bowled
                FROM match_statistics ms
                JOIN matches m ON ms.match_id = m.id
                WHERE ms.player_id = ? AND (ms.overs_bowled != '0.0' OR ms.wickets_taken > 0) AND m.status = 'completed'
            ");
            $stmt->execute([$player_id]);
            $bowling_perfs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $innings_bowled = count($bowling_perfs);
            $total_balls_bowled = 0;

            // Loop for accurate sums
            foreach ($bowling_perfs as $perf) {
                // Parse overs (e.g., "3.4")
                $ov_parts = explode('.', (string) $perf['overs_bowled']);
                $ov = isset($ov_parts[0]) ? (int) $ov_parts[0] : 0;
                $bl = isset($ov_parts[1]) ? (int) $ov_parts[1] : 0;
                $total_balls_bowled += ($ov * 6 + $bl);
            }

            $overs_bowled = floor($total_balls_bowled / 6) . '.' . ($total_balls_bowled % 6);
            $overs_decimal = $total_balls_bowled / 6;

            // 2.3 Best Bowling & 5 Wickets (Calculated from bowling_perfs)
            $five_wickets = 0;
            $best_bowling_wkts = 0;
            $best_bowling_runs = 0;

            foreach ($bowling_perfs as $perf) {
                if ($perf['wickets_taken'] >= 5)
                    $five_wickets++;

                // Best Bowling
                if ($perf['wickets_taken'] > $best_bowling_wkts) {
                    $best_bowling_wkts = $perf['wickets_taken'];
                    $best_bowling_runs = $perf['runs_conceded'];
                } elseif ($perf['wickets_taken'] == $best_bowling_wkts) {
                    if ($best_bowling_runs === 0 || $perf['runs_conceded'] < $best_bowling_runs) {
                        $best_bowling_runs = $perf['runs_conceded'];
                    }
                }
            }

            $best_bowling_display = "$best_bowling_wkts/$best_bowling_runs";
            if ($innings_bowled == 0)
                $best_bowling_display = "-";
            elseif ($best_bowling_wkts == 0) {
                $min_runs = null;
                foreach ($bowling_perfs as $perf) {
                    if ($min_runs === null || $perf['runs_conceded'] < $min_runs)
                        $min_runs = $perf['runs_conceded'];
                }
                $best_bowling_display = "0/" . ($min_runs ?? 0);
            }


            // 3. Keep some cumulative fields from player_stats table if easier
            $stmt = $pdo->prepare("SELECT * FROM player_stats WHERE player_id = ?");
            $stmt->execute([$player_id]);
            $stored_stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Merge: Calculated takes precedence
            $playerStats = $stored_stats ?: [];
            $playerStats['matches_played'] = $real_matches;
            $playerStats['innings_batted'] = $innings_batted;
            $playerStats['runs_scored'] = $agg_stats['runs_scored'];
            $playerStats['wickets_taken'] = $real_wickets; // Use real count
            $playerStats['centuries'] = $agg_stats['centuries'];
            $playerStats['half_centuries'] = $agg_stats['half_centuries'];
            $playerStats['highest_score'] = max($agg_stats['highest_score'] ?? 0, $stored_stats['highest_score'] ?? 0);
            $playerStats['fours'] = $agg_stats['fours'];
            $playerStats['sixes'] = $agg_stats['sixes'];

            // Bowling fields
            $playerStats['innings_bowled'] = $innings_bowled;
            $playerStats['runs_conceded'] = $agg_stats['runs_conceded'];
            $playerStats['overs_bowled'] = $overs_bowled;
            $playerStats['best_bowling'] = $best_bowling_display;
            $playerStats['five_wickets'] = $five_wickets;

            // Re-calculate averages/SR
            $playerStats['batting_average'] = ($playerStats['innings_batted'] > 0) ? ($playerStats['runs_scored'] / $playerStats['innings_batted']) : 0;
            $playerStats['strike_rate'] = ($agg_stats['balls_faced'] > 0) ? (($playerStats['runs_scored'] / $agg_stats['balls_faced']) * 100) : 0;

            // Bowling Averages
            $playerStats['bowling_average'] = ($real_wickets > 0) ? ($playerStats['runs_conceded'] / $real_wickets) : 0;
            $playerStats['economy_rate'] = ($overs_decimal > 0) ? ($playerStats['runs_conceded'] / $overs_decimal) : 0;

            // Ensure other keys exist
            $defaults = [
                'catches' => 0,
                'stumpings' => 0,
                'run_outs' => 0,
                'man_of_match' => 0
            ];
            foreach ($defaults as $key => $val) {
                if (!isset($playerStats[$key]))
                    $playerStats[$key] = $val;
            }

        } else {
            $error_message = 'Player not found.';
        }
    } catch (PDOException $e) {
        $error_message = 'Error loading profile: ' . $e->getMessage();
    }
}

// Calculate Age
$age = ($player && !empty($player['dob'])) ? date_diff(date_create($player['dob']), date_create('today'))->y : null;

// Defaults
if (!$playerStats) {
    $playerStats = array_fill_keys([
        'matches_played',
        'innings_batted',
        'runs_scored',
        'highest_score',
        'batting_average',
        'strike_rate',
        'centuries',
        'half_centuries',
        'fours',
        'sixes',
        'innings_bowled',
        'wickets_taken',
        'runs_conceded',
        'overs_bowled',
        'best_bowling',
        'bowling_average',
        'economy_rate',
        'five_wickets',
        'catches',
        'stumpings',
        'run_outs',
        'man_of_match'
    ], 0);
    $playerStats['best_bowling'] = '-';
}

$page_title = "Player Profile | " . ($player['name'] ?? 'View');
require_once '../includes/header.php';
?>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        --glass-bg: rgba(255, 255, 255, 0.85);
        --glass-border: rgba(255, 255, 255, 0.5);
        --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        --backdrop-blur: blur(12px);
        --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
        --accent-color:
            <?= $player['team_color'] ?? '#3b82f6' ?>
        ;
    }

    /* STEP 1 & 2: Fix Footer Positioning via Flexbox on Body */
    html,
    body {
        height: 100%;
        margin: 0;
        font-family: 'Outfit', sans-serif;
    }

    body {
        display: flex;
        flex-direction: column;
        background: radial-gradient(circle at 10% 20%, rgb(239, 246, 255) 0%, rgb(219, 228, 255) 90%);
        background-attachment: fixed;
    }

    /* Ensure Header sits at top */
    header {
        flex-shrink: 0;
    }

    /* Main content expands to push footer down */
    main.container-fluid {
        flex: 1 0 auto;
        display: flex;
        flex-direction: column;
    }

    /* FIX 1: Add Top Padding for Mobile Header Overlap - REMOVED */
    @media (max-width: 768px) {

        /* main.container-fluid {
            padding-top: 70px !important;
        } */
        /* Gap removed as per user request */
        main.container-fluid {
            padding-top: 0 !important;
        }
    }

    /* Footer styling */
    .cricket-footer {
        flex-shrink: 0;
        background: #0d1b2a;
        color: white;
        padding: 20px 0;
        margin-top: 30px !important;
    }

    /* Page Wrapper */
    .page-content-wrapper {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding-bottom: 40px;
        flex: 1;
    }

    /* Glass Cards */
    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: var(--backdrop-blur);
        -webkit-backdrop-filter: var(--backdrop-blur);
        border: 1px solid var(--glass-border);
        box-shadow: var(--glass-shadow);
        border-radius: 20px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        padding: 20px;
        height: auto;
    }

    .glass-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px 0 rgba(31, 38, 135, 0.25);
    }

    /* Hero Section */
    .hero-banner {
        background: var(--primary-gradient);
        border-radius: 0 0 30px 30px;
        padding: 40px 0 80px 0;
        margin-bottom: -40px !important;
        position: relative;
        overflow: hidden;
        flex-shrink: 0;
    }

    .hero-pattern {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }

    /* Mobile: Remove hero top spacing completely */
    @media (max-width: 768px) {
        .hero-banner {
            padding: 0 0 50px 0 !important;
            margin-top: 0 !important;
            margin-bottom: -30px !important;
        }
    }

    /* Quick Stats Grid */
    .quick-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin-bottom: 30px;
    }

    @media (max-width: 768px) {
        .quick-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .quick-stat-card {
        padding: 15px;
        text-align: center;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.8);
    }

    .quick-stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        line-height: 1.2;
    }

    .quick-stat-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        font-weight: 600;
        color: #64748b;
    }

    /* Main Grid Layout for Sections */
    .profile-grid-container {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        align-items: start;
    }

    /* Desktop Grid Areas */
    .section-batting {
        grid-column: 1;
        grid-row: 1;
    }

    .section-bowling {
        grid-column: 1;
        grid-row: 2;
    }

    .section-personal {
        grid-column: 2;
        grid-row: 1;
    }

    .section-fielding {
        grid-column: 2;
        grid-row: 2;
    }

    /* Mobile: Stack vertically with consistent widths */
    @media (max-width: 992px) {
        .profile-grid-container {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        /* Ensure all cards are full width */
        .section-batting,
        .section-personal,
        .section-bowling,
        .section-fielding {
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Mobile Order: Batting -> Personal -> Bowling -> Fielding */
        .section-batting {
            order: 1;
        }

        .section-personal {
            order: 2;
        }

        .section-bowling {
            order: 3;
        }

        .section-fielding {
            order: 4;
        }
    }

    /* Table Styles */
    .custom-table {
        width: 100%;
        margin-bottom: 0;
    }

    .custom-table th {
        color: #64748b;
        font-weight: 600;
        padding: 8px;
        font-size: 0.9rem;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }

    .custom-table td {
        padding: 10px 8px;
        font-weight: 500;
        font-size: 0.95rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .custom-table .text-end {
        text-align: right;
    }

    .badge-modern {
        background: rgba(255, 255, 255, 0.25);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        border: 1px solid rgba(255, 255, 255, 0.4);
    }

    .profile-img-container {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        border: 5px solid rgba(255, 255, 255, 0.8);
        overflow: hidden;
        margin: 0 auto;
        background: white;
    }

    /* Chart Container */
    .chart-container-wrapper {
        width: 100%;
        height: 220px;
        max-height: 220px;
        position: relative;
        display: flex;
        justify-content: center;
    }

    /* Animation */
    .animate-up {
        animation: slideUp 0.6s ease-out forwards;
        opacity: 0;
    }

    .delay-1 {
        animation-delay: 0.1s;
    }

    .delay-2 {
        animation-delay: 0.2s;
    }

    .delay-3 {
        animation-delay: 0.3s;
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
</style>

<!-- Hero Section -->
<div class="hero-banner">
    <div class="hero-pattern"></div>
    <div class="container text-center pt-2 position-relative" style="z-index: 2;">
        <a href="javascript:history.back()"
            class="btn btn-sm btn-outline-light rounded-pill position-absolute start-0 top-0 mt-2 ms-2">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>

        <div class="mt-3 mt-md-4">
            <div class="profile-img-container animate-up">
                <img src="<?= $player['profile_image'] ? '../uploads/users/' . $player['profile_image'] : '../assets/images/default-user.png' ?>"
                    class="profile-img" alt="Player" style="width:100%; height:100%; object-fit:cover;">
            </div>

            <h1 class="mt-3 mb-1 fw-bold fs-2 text-white animate-up delay-1"><?= htmlspecialchars($player['name']) ?>
            </h1>

            <div class="d-flex flex-wrap justify-content-center gap-2 mt-2 animate-up delay-1 px-3 text-white">
                <span class="badge-modern">
                    <i class="fas fa-id-badge me-2"></i><?= htmlspecialchars($player['playing_role']) ?>
                </span>
                <?php if ($player['team_name']): ?>
                    <span class="badge-modern">
                        <?= $player['team_logo'] ? '<img src="../uploads/teams/' . $player['team_logo'] . '" width="16" class="me-2 rounded-circle">' : '<i class="fas fa-shield-alt me-2"></i>' ?>
                        <?= htmlspecialchars($player['team_name']) ?>
                    </span>
                <?php endif; ?>
                <?php if ($age): ?>
                    <span class="badge-modern">
                        <i class="fas fa-birthday-cake me-2"></i><?= $age ?> Years
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Page Content -->
<div class="page-content-wrapper">
    <?php if ($error_message): ?>
        <div class="container pt-5">
            <div class="alert alert-danger shadow-sm rounded-4 border-0">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                <a href="javascript:history.back()" class="btn btn-sm btn-danger ms-3">Go Back</a>
            </div>
        </div>
    <?php else: ?>

        <div class="container px-3 px-md-4" style="position: relative; z-index: 5;">

            <!-- Quick Stats Grid -->
            <div class="quick-stats-grid animate-up delay-2">
                <div class="quick-stat-card">
                    <div class="quick-stat-value"><?= $playerStats['matches_played'] ?></div>
                    <div class="quick-stat-label">Matches</div>
                </div>
                <div class="quick-stat-card">
                    <div class="quick-stat-value"><?= $playerStats['runs_scored'] ?></div>
                    <div class="quick-stat-label">Runs</div>
                </div>
                <div class="quick-stat-card">
                    <div class="quick-stat-value"><?= $playerStats['wickets_taken'] ?></div>
                    <div class="quick-stat-label">Wickets</div>
                </div>
                <div class="quick-stat-card">
                    <div class="quick-stat-value">
                        <?= $player['playing_role'] == 'Bowler' ? $playerStats['best_bowling'] : $playerStats['highest_score'] ?>
                    </div>
                    <div class="quick-stat-label"><?= $player['playing_role'] == 'Bowler' ? 'Best Bowl' : 'High Score' ?>
                    </div>
                </div>
            </div>

            <!-- Main Layout Grid -->
            <div class="profile-grid-container animate-up delay-3">

                <!-- SECTION: Batting -->
                <div class="section-batting">
                    <div class="glass-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-primary p-2 text-white me-3 d-flex align-items-center justify-content-center"
                                style="width:40px; height:40px;">
                                <i class="fas fa-baseball-bat"></i>
                            </div>
                            <h4 class="mb-0 fw-bold text-dark fs-5">Batting Career</h4>
                        </div>

                        <div class="row align-items-center">
                            <div class="col-md-7">
                                <div style="overflow-x: auto;">
                                    <table class="custom-table">
                                        <thead>
                                            <tr>
                                                <th>Inn</th>
                                                <th>Runs</th>
                                                <th>Avg</th>
                                                <th>SR</th>
                                                <th>HS</th>
                                                <th>4s</th>
                                                <th>6s</th>
                                                <th>100s</th>
                                                <th>50s</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="fw-bold"><?= $playerStats['innings_batted'] ?></td>
                                                <td class="fw-bold text-primary"><?= $playerStats['runs_scored'] ?></td>
                                                <td class="fw-bold"><?= number_format($playerStats['batting_average'], 2) ?>
                                                </td>
                                                <td class="fw-bold">
                                                    <?= number_format($playerStats['strike_rate'], 1) ?>
                                                </td>
                                                <td class="fw-bold"><?= $playerStats['highest_score'] ?></td>
                                                <td class="fw-bold"><?= $playerStats['fours'] ?></td>
                                                <td class="fw-bold"><?= $playerStats['sixes'] ?></td>
                                                <td class="fw-bold"><?= $playerStats['centuries'] ?></td>
                                                <td class="fw-bold"><?= $playerStats['half_centuries'] ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-5 d-flex justify-content-center mt-3 mt-md-0">
                                <div class="chart-container-wrapper">
                                    <canvas id="battingChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION: Bowling -->
                <div class="section-bowling">
                    <div class="glass-card">
                        <div class="row align-items-center">
                            <div class="col-md-7">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="rounded-circle bg-danger p-2 text-white me-3 d-flex align-items-center justify-content-center"
                                        style="width:40px; height:40px;">
                                        <i class="fas fa-bowling-ball"></i>
                                    </div>
                                    <h4 class="mb-0 fw-bold text-dark fs-5">Bowling Career</h4>
                                </div>
                                <div style="overflow-x: auto;">
                                    <table class="custom-table">
                                        <thead>
                                            <tr>
                                                <th>Inn</th>
                                                <th>Wkts</th>
                                                <th>Econ</th>
                                                <th>Best</th>
                                                <th>Avg</th>
                                                <th>5W</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="fw-bold"><?= $playerStats['innings_bowled'] ?></td>
                                                <td class="fw-bold text-danger"><?= $playerStats['wickets_taken'] ?></td>
                                                <td class="fw-bold"><?= number_format($playerStats['economy_rate'], 2) ?>
                                                </td>
                                                <td class="fw-bold"><?= $playerStats['best_bowling'] ?></td>
                                                <td class="fw-bold"><?= number_format($playerStats['bowling_average'], 2) ?>
                                                </td>
                                                <td class="fw-bold"><?= $playerStats['five_wickets'] ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-5 d-flex justify-content-center mt-3 mt-md-0">
                                <div class="chart-container-wrapper">
                                    <canvas id="bowlingChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION: Personal Info -->
                <div class="section-personal">
                    <div class="glass-card">
                        <h5 class="fw-bold mb-3 border-bottom pb-2 fs-6">Personal Info</h5>
                        <div class="mb-2">
                            <label class="d-block text-muted small fw-bold">NAME</label>
                            <span
                                class="fw-bold text-dark text-break"><?= htmlspecialchars($player['full_name'] ?: $player['name']) ?></span>
                        </div>
                        <div class="mb-2">
                            <label class="d-block text-muted small fw-bold">DOB</label>
                            <span
                                class="fw-bold text-dark"><?= $player['dob'] ? date('d M Y', strtotime($player['dob'])) : '-' ?></span>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-6">
                                <label class="d-block text-muted small fw-bold">BAT</label>
                                <span
                                    class="fw-bold text-dark small"><?= htmlspecialchars($player['batting_hand'] ?? '-') ?></span>
                            </div>
                            <div class="col-6">
                                <label class="d-block text-muted small fw-bold">BOWL</label>
                                <span
                                    class="fw-bold text-dark small"><?= htmlspecialchars($player['bowling_type'] ?? '-') ?></span>
                            </div>
                            <div class="col-6">
                                <label class="d-block text-muted small fw-bold">ORDER</label>
                                <span
                                    class="fw-bold text-dark small"><?= htmlspecialchars($player['batting_order'] ?? '-') ?></span>
                            </div>
                            <div class="col-6">
                                <label class="d-block text-muted small fw-bold">ARM</label>
                                <span
                                    class="fw-bold text-dark small"><?= htmlspecialchars($player['bowling_arm'] ?? '-') ?></span>
                            </div>
                        </div>

                        <div class="mt-3 p-2 bg-light rounded-3 border d-flex align-items-center">
                            <i class="fas fa-trophy text-warning me-3 fs-3"></i>
                            <div>
                                <h6 class="mb-0 fw-bold small text-muted">MOM AWARDS</h6>
                                <span class="fs-4 fw-bold text-dark"><?= $playerStats['man_of_match'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION: Fielding -->
                <div class="section-fielding">
                    <div class="glass-card">
                        <h5 class="fw-bold mb-3 border-bottom pb-2 fs-6">Fielding</h5>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1">
                            <span class="text-muted small">Catches</span>
                            <span class="fw-bold"><?= $playerStats['catches'] ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1">
                            <span class="text-muted small">Run Outs</span>
                            <span class="fw-bold"><?= $playerStats['run_outs'] ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Stumpings</span>
                            <span class="fw-bold"><?= $playerStats['stumpings'] ?></span>
                        </div>
                    </div>
                </div>

            </div> <!-- End Profile Grid -->
        </div>

    <?php endif; ?>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const ctx = document.getElementById('battingChart');
        if (ctx) {
            // Data Calculation
            const fours = <?= $playerStats['fours'] ?>;
            const sixes = <?= $playerStats['sixes'] ?>;
            const totalRuns = <?= $playerStats['runs_scored'] ?>;

            const runsFromFours = fours * 4;
            const runsFromSixes = sixes * 6;
            const runningRuns = Math.max(0, totalRuns - (runsFromFours + runsFromSixes));

            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Runs from 4s', 'Runs from 6s', 'Running'],
                    datasets: [{
                        data: [runsFromFours, runsFromSixes, runningRuns],
                        backgroundColor: ['#3b82f6', '#ef4444', '#e2e8f0'],
                        borderWidth: 0, hoverOffset: 4
                    }]
                },
                options: {
                    cutout: '70%',
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    animation: { animateScale: true }
                }
            });
        }

        const ctx2 = document.getElementById('bowlingChart');
        if (ctx2) {
            // Visualize Bowling Average vs Economy Rate
            const econ = <?= $playerStats['economy_rate'] ?>;
            const avg = <?= $playerStats['bowling_average'] ?>;

            // Simple Bar Chart for Impact Stats
            new Chart(ctx2.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Economy', 'Average'],
                    datasets: [{
                        label: 'Bowling Stats',
                        data: [econ, avg],
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>