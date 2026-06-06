<?php
// admin/admin_dashboard.php
// Admin Dashboard

require_once '../includes/db.php';
require_login();

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Fetch dashboard statistics
$stats = [
    'total_tournaments' => 0,
    'total_teams' => 0,
    'total_matches' => 0,
    'total_players' => 0,
    'ongoing_matches' => 0,
    'completed_matches' => 0,
    'total_runs' => 0,
    'total_wickets' => 0
];

// Fetch actual data from database (placeholder queries)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tournaments");
    $stats['total_tournaments'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM teams");
    $stats['total_teams'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM matches");
    $stats['total_matches'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'player'");
    $stats['total_players'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM matches WHERE status = 'ongoing'");
    $stats['ongoing_matches'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM matches WHERE status = 'completed'");
    $stats['completed_matches'] = $stmt->fetchColumn();

    // Total Runs and Wickets from Completed Matches
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(ms.runs_scored), 0) 
        FROM match_statistics ms
        JOIN matches m ON ms.match_id = m.id
        WHERE m.status = 'completed'
    ");
    $stats['total_runs'] = $stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT COALESCE(SUM(ms.wickets_taken), 0) 
        FROM match_statistics ms
        JOIN matches m ON ms.match_id = m.id
        WHERE m.status = 'completed'
    ");
    $stats['total_wickets'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Log the error but don't show to user
    error_log("Database error in admin dashboard: " . $e->getMessage());
    // Continue with default values (0)
}

// ---------------------------------------------------------
// Player of the Series Logic (Point System)
// ---------------------------------------------------------
$bestSeriesRankings = [];
try {
    // 1. Fetch Completed Matches (For Win Bonus)
    $matches_stmt = $pdo->query("SELECT id, winner_id FROM matches WHERE status = 'completed'");
    $completed_matches = $matches_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => winner_id]

    if (!empty($completed_matches)) {
        $completed_ids_str = implode(',', array_keys($completed_matches));

        // 2. Fetch All Stats for Completed Matches
        $stats_stmt = $pdo->query("SELECT * FROM match_statistics WHERE match_id IN ($completed_ids_str)");
        $all_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch Fielding Stats (Grouped)
        $fielding_stmt = $pdo->query("
            SELECT match_id, fielder_id, 
                   COUNT(CASE WHEN wicket_type='caught' THEN 1 END) as catches,
                   COUNT(CASE WHEN wicket_type='run out' THEN 1 END) as run_outs,
                   COUNT(CASE WHEN wicket_type='stumped' THEN 1 END) as stumpings
            FROM ball_by_ball 
            WHERE match_id IN ($completed_ids_str) AND fielder_id IS NOT NULL 
            GROUP BY match_id, fielder_id
        ");
        $all_fielding = $fielding_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Fetch Dismissals (For Duck Penalty)
        $wicket_stmt = $pdo->query("SELECT match_id, wicket_player_id FROM ball_by_ball WHERE match_id IN ($completed_ids_str) AND wicket_player_id IS NOT NULL");
        $dismissals = $wicket_stmt->fetchAll(PDO::FETCH_ASSOC);
        $out_map = [];
        foreach ($dismissals as $d) {
            $out_map[$d['match_id']][$d['wicket_player_id']] = true;
        }

        // 5. Organize Data Per Player
        $player_data = [];
        $players_stmt = $pdo->query("SELECT u.id, u.name, u.profile_image, tp.team_id, t.team_name 
                                    FROM users u 
                                    LEFT JOIN team_players tp ON u.id = tp.player_id
                                    LEFT JOIN teams t ON tp.team_id = t.id 
                                    WHERE u.role='player'");
        $all_players = $players_stmt->fetchAll(PDO::FETCH_ASSOC);
        $player_info = [];
        foreach ($all_players as $p) {
            $player_info[$p['id']] = $p;
            $player_data[$p['id']] = ['matches' => []];
        }

        // Process Batting/Bowling
        foreach ($all_stats as $s) {
            $pid = $s['player_id'];
            $mid = $s['match_id'];
            if (!isset($player_data[$pid]))
                continue;

            if (!isset($player_data[$pid]['matches'][$mid])) {
                $player_data[$pid]['matches'][$mid] = [
                    'bat' => ['runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0],
                    'bowl' => ['wickets' => 0, 'conceded' => 0, 'overs' => 0, 'legal_balls' => 0],
                    'field' => []
                ];
            }
            $player_data[$pid]['matches'][$mid]['bat']['runs'] += $s['runs_scored'];
            $player_data[$pid]['matches'][$mid]['bat']['balls'] += $s['balls_faced'];
            $player_data[$pid]['matches'][$mid]['bat']['fours'] += $s['fours'];
            $player_data[$pid]['matches'][$mid]['bat']['sixes'] += $s['sixes'];
            $player_data[$pid]['matches'][$mid]['bowl']['wickets'] += $s['wickets_taken'];
            $player_data[$pid]['matches'][$mid]['bowl']['conceded'] += $s['runs_conceded'];

            $overs_str = $s['overs_bowled'] ?? '0.0';
            $parts = explode('.', $overs_str);
            $balls = ($parts[0] * 6) + ($parts[1] ?? 0);
            $player_data[$pid]['matches'][$mid]['bowl']['legal_balls'] += $balls;
        }

        // Process Fielding
        foreach ($all_fielding as $f) {
            $pid = $f['fielder_id'];
            $mid = $f['match_id'];
            if (!isset($player_data[$pid]))
                continue;
            if (!isset($player_data[$pid]['matches'][$mid]))
                $player_data[$pid]['matches'][$mid] = ['bat' => [], 'bowl' => [], 'field' => []];

            $player_data[$pid]['matches'][$mid]['field'] = [
                'catches' => $f['catches'],
                'run_outs' => $f['run_outs'],
                'stumpings' => $f['stumpings']
            ];
        }

        // 6. Calculate Scores
        $scores = [];
        foreach ($player_data as $pid => $data) {
            $total_score = 0;
            $matches_played = count($data['matches']);
            $matches_30_runs = 0;
            $matches_with_wickets = 0;

            foreach ($data['matches'] as $mid => $m_stats) {
                $match_score = 0;
                // Batting
                $runs = $m_stats['bat']['runs'] ?? 0;
                $balls = $m_stats['bat']['balls'] ?? 0;
                $is_out = isset($out_map[$mid][$pid]);

                $match_score += $runs;
                if ($runs >= 100)
                    $match_score += 20;
                elseif ($runs >= 50)
                    $match_score += 10;

                if ($balls > 0) {
                    $sr = ($runs / $balls) * 100;
                    if ($sr >= 150)
                        $match_score += 10;
                    elseif ($sr >= 120)
                        $match_score += 5;
                }
                if ($runs == 0 && $is_out)
                    $match_score -= 5;

                // Bowling
                $wickets = $m_stats['bowl']['wickets'] ?? 0;
                $match_score += ($wickets * 25);
                if ($wickets >= 5)
                    $match_score += 20;
                elseif ($wickets >= 3)
                    $match_score += 10;

                $total_balls_bowled = $m_stats['bowl']['legal_balls'] ?? 0;
                if ($total_balls_bowled >= 12) {
                    $conceded = $m_stats['bowl']['conceded'] ?? 0;
                    $econ = ($conceded / $total_balls_bowled) * 6;
                    if ($econ <= 6.0)
                        $match_score += 10;
                    elseif ($econ <= 7.5)
                        $match_score += 5;
                }

                // Fielding
                $catches = $m_stats['field']['catches'] ?? 0;
                $runouts = $m_stats['field']['run_outs'] ?? 0;
                $stumps = $m_stats['field']['stumpings'] ?? 0;
                $match_score += ($catches * 8);
                $match_score += ($runouts * 12);
                $match_score += ($stumps * 10);

                // Win Bonus
                $p_team = $player_info[$pid]['team_id'];
                if (isset($completed_matches[$mid]) && $completed_matches[$mid] == $p_team) {
                    $match_score += 5;
                }

                $total_score += $match_score;

                if ($runs >= 30)
                    $matches_30_runs++;
                if ($wickets >= 1)
                    $matches_with_wickets++;
            }

            // Series Bonus
            if ($matches_played >= 3)
                $total_score += 10;
            if ($matches_30_runs >= 3)
                $total_score += 10;
            if ($matches_with_wickets >= 3)
                $total_score += 10;

            if ($total_score > 0) {
                $scores[$pid] = $total_score;
            }
        }

        // Sort Top 5
        arsort($scores);
        $top_ids = array_slice(array_keys($scores), 0, 5);

        foreach ($top_ids as $tid) {
            $p = $player_info[$tid];
            $p['points'] = $scores[$tid];

            // Aggregate totals for Player Card
            $data = $player_data[$tid];
            $p['total_matches'] = count($data['matches']);
            $p['total_runs'] = 0;
            $p['total_wickets'] = 0;
            foreach ($data['matches'] as $m) {
                $p['total_runs'] += ($m['bat']['runs'] ?? 0);
                $p['total_wickets'] += ($m['bowl']['wickets'] ?? 0);
            }

            $bestSeriesRankings[] = $p;
        }
    }
} catch (PDOException $e) {
    // Silent fail
}

$page_title = "Admin Dashboard";
require_once '../includes/header.php';
?>

<style>
    :root {
        --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        --glass-bg: rgba(255, 255, 255, 0.9);
        --glass-border: 1px solid rgba(255, 255, 255, 0.5);
        --glass-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        --card-radius: 16px;
        --nav-height: 76px;
    }

    body {
        background: var(--bg-gradient);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        color: #1e293b;
        min-height: 100vh;
    }

    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem 1.5rem;
    }

    .section-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #334155;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Detail Cards */
    .detail-card {
        background: var(--glass-bg);
        border: var(--glass-border);
        border-radius: var(--card-radius);
        padding: 1.5rem;
        height: 100%;
        box-shadow: var(--glass-shadow);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .detail-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .detail-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-bottom: 1rem;
    }

    .icon-primary {
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
    }

    .icon-success {
        background: rgba(16, 185, 129, 0.1);
        color: #059669;
    }

    .icon-warning {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
    }

    .icon-info {
        background: rgba(6, 182, 212, 0.1);
        color: #0891b2;
    }

    .icon-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .icon-secondary {
        background: rgba(100, 116, 139, 0.1);
        color: #475569;
    }

    .icon-success-soft {
        background: #ecfdf5;
        color: #10b981;
    }

    .icon-warning-soft {
        background: #fffbeb;
        color: #f59e0b;
    }

    .detail-title {
        font-size: 0.8rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.25rem;
    }

    .detail-value {
        font-size: 1.75rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
    }

    /* Action Cards */
    .action-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: white;
        padding: 1.5rem;
        border-radius: var(--card-radius);
        border: 1px solid #e2e8f0;
        text-decoration: none;
        transition: all 0.2s;
        height: 100%;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
    }

    .action-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.06);
        border-color: #cbd5e1;
    }

    .action-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }

    .action-title {
        margin: 0;
        font-weight: 600;
        color: #334155;
        font-size: 0.95rem;
    }

    /* Ranking Cards */
    .ranking-card {
        background: white;
        border-radius: var(--card-radius);
        border: 1px solid #e2e8f0;
        overflow: hidden;
        box-shadow: var(--glass-shadow);
    }

    .ranking-header {
        padding: 1rem 1.25rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-weight: 700;
        color: #334155;
        font-size: 0.95rem;
    }

    .ranking-item {
        display: flex;
        align-items: center;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.15s;
    }

    .ranking-item:last-child {
        border-bottom: none;
    }

    .ranking-item:hover {
        background: #f8fafc;
    }

    .rank-badge {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 0.75rem;
        font-weight: 700;
        margin-right: 1rem;
        color: #64748b;
        background: #f1f5f9;
    }

    .rank-1 {
        color: #d97706;
        background: #fef3c7;
    }

    .rank-2 {
        color: #475569;
        background: #e2e8f0;
    }

    .rank-3 {
        color: #b45309;
        background: #ffedd5;
    }

    .player-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 0.75rem;
        border: 1px solid #e2e8f0;
    }

    .player-info {
        flex-grow: 1;
        min-width: 0;
    }

    .player-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .team-name {
        font-size: 0.75rem;
        color: #64748b;
    }

    .stat-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 99px;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .btn-glass {
        background: white;
        border: 1px solid #cbd5e1;
        color: #334155;
        font-weight: 600;
        padding: 0.5rem 1rem;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .btn-glass:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        transform: translateY(-1px);
    }

    @media (max-width: 768px) {
        .admin-dashboard {
            padding: 1rem;
        }
    }
</style>

<!-- Admin Dashboard Container -->
<div class="main-container container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">Admin Dashboard</h1>
            <p class="text-secondary mb-0">Overview of league performance and management</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_profile.php" class="btn btn-glass">
                <i class="fas fa-user-circle me-2 text-primary"></i>Profile
            </a>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="section-title">
        <i class="fas fa-chart-pie text-primary"></i> Quick Statistics
    </div>

    <div class="row g-4 mb-5">
        <!-- Row 1 -->
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-primary">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="detail-title">Tournaments</div>
                    <div class="detail-value"><?= number_format($stats['total_tournaments']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="detail-title">Teams</div>
                    <div class="detail-value"><?= number_format($stats['total_teams']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-warning">
                        <i class="fas fa-baseball-ball"></i>
                    </div>
                    <div class="detail-title">Matches</div>
                    <div class="detail-value"><?= number_format($stats['total_matches']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-info">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="detail-title">Players</div>
                    <div class="detail-value"><?= number_format($stats['total_players']) ?></div>
                </div>
            </div>
        </div>

        <!-- Row 2 -->
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-danger">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="detail-title">Ongoing</div>
                    <div class="detail-value"><?= number_format($stats['ongoing_matches']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-secondary">
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                    <div class="detail-title">Completed</div>
                    <div class="detail-value"><?= number_format($stats['completed_matches']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-success-soft">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="detail-title">Total Runs</div>
                    <div class="detail-value"><?= number_format($stats['total_runs']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon icon-warning-soft">
                        <i class="fas fa-bowling-ball"></i>
                    </div>
                    <div class="detail-title">Total Wickets</div>
                    <div class="detail-value"><?= number_format($stats['total_wickets']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="section-title">
        <i class="fas fa-bolt text-warning"></i> Quick Actions
    </div>
    <div class="row g-4 mb-5">
        <div class="col-6 col-md-3">
            <a href="create_tournament.php" class="action-card">
                <div class="action-icon bg-primary-subtle text-primary">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <h6 class="action-title">Create Tournament</h6>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="create_team.php" class="action-card">
                <div class="action-icon bg-success-subtle text-success">
                    <i class="fas fa-users"></i>
                </div>
                <h6 class="action-title">Create Team</h6>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="create_match.php" class="action-card">
                <div class="action-icon bg-warning-subtle text-warning">
                    <i class="fas fa-baseball-ball"></i>
                </div>
                <h6 class="action-title">Schedule Match</h6>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="create_point_table.php" class="action-card">
                <div class="action-icon bg-info-subtle text-info">
                    <i class="fas fa-table"></i>
                </div>
                <h6 class="action-title">Create Point Table</h6>
            </a>
        </div>
    </div>

    <!-- Performance Section -->
    <div class="section-title">
        <i class="fas fa-medal text-success"></i> Performance Rankings
    </div>

    <?php
    $hasCompletedMatches = false;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM matches WHERE status = 'completed'");
        $hasCompletedMatches = $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
    }
    ?>

    <?php if (!$hasCompletedMatches): ?>
        <div class="card border-0 shadow-sm glass-card mb-5">
            <div class="card-body text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-baseball-ball fa-3x text-muted opacity-50"></i>
                </div>
                <h5 class="text-muted fw-normal">No matches completed yet</h5>
                <p class="text-secondary small">Rankings will appear here once match data is available.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4 mb-5">
            <!-- Best Batting Ranking -->
            <div class="col-lg-4">
                <div class="ranking-card h-100">
                    <div class="ranking-header">
                        <span><i class="fas fa-baseball-bat-ball me-2 text-primary"></i>Top Batters</span>
                    </div>
                    <div class="ranking-body">
                        <?php
                        // Fetch batting rankings
                        try {
                            $stmt = $pdo->query("
                                SELECT p.id as player_id, p.name, p.profile_image, t.team_name, 
                                       COALESCE(SUM(ms.runs_scored), 0) as total_runs,
                                       COALESCE(SUM(ms.wickets_taken), 0) as total_wickets,
                                       COUNT(DISTINCT ms.match_id) as total_matches
                                FROM users p
                                LEFT JOIN team_players tp ON p.id = tp.player_id
                                LEFT JOIN teams t ON tp.team_id = t.id
                                LEFT JOIN match_statistics ms ON p.id = ms.player_id
                                LEFT JOIN matches m ON ms.match_id = m.id
                                WHERE p.role = 'player' AND m.status = 'completed'
                                GROUP BY p.id
                                ORDER BY total_runs DESC
                                LIMIT 5
                            ");
                            $battingRankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($battingRankings as $index => $player):
                                ?>
                                <div class="ranking-item" style="cursor: pointer;"
                                    onclick="openPlayerCard(<?= htmlspecialchars(json_encode($player)) ?>)">
                                    <div class="rank-badge rank-<?= $index + 1 ?>"><?= $index + 1 ?></div>
                                    <img src="../uploads/users/<?= $player['profile_image'] ?>"
                                        onerror="this.src='../assets/images/default-player.png'" class="player-avatar">
                                    <div class="player-info">
                                        <div class="player-name"><?= htmlspecialchars($player['name']) ?></div>
                                        <div class="team-name"><?= htmlspecialchars($player['team_name'] ?? 'No Team') ?></div>
                                    </div>
                                    <div class="stat-badge bg-primary-subtle text-primary">
                                        <?= $player['total_runs'] ?> runs
                                    </div>
                                </div>
                            <?php endforeach;
                        } catch (PDOException $e) {
                            echo '<div class="p-4 text-center text-muted">Data unavailable</div>';
                        } ?>
                    </div>
                </div>
            </div>

            <!-- Best Bowling Ranking -->
            <div class="col-lg-4">
                <div class="ranking-card h-100">
                    <div class="ranking-header">
                        <span><i class="fas fa-bowling-ball me-2 text-success"></i>Top Bowlers</span>
                    </div>
                    <div class="ranking-body">
                        <?php
                        try {
                            $stmt = $pdo->query("
                                SELECT p.id as player_id, p.name, p.profile_image, t.team_name,
                                       COALESCE(SUM(ms.runs_scored), 0) as total_runs,
                                       COALESCE(SUM(ms.wickets_taken), 0) as total_wickets,
                                       COUNT(DISTINCT ms.match_id) as total_matches
                                FROM users p
                                LEFT JOIN team_players tp ON p.id = tp.player_id
                                LEFT JOIN teams t ON tp.team_id = t.id
                                LEFT JOIN match_statistics ms ON p.id = ms.player_id
                                LEFT JOIN matches m ON ms.match_id = m.id
                                WHERE p.role = 'player' AND m.status = 'completed'
                                GROUP BY p.id
                                ORDER BY total_wickets DESC
                                LIMIT 5
                            ");
                            $bowlingRankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($bowlingRankings as $index => $player):
                                ?>
                                <div class="ranking-item" style="cursor: pointer;"
                                    onclick="openPlayerCard(<?= htmlspecialchars(json_encode($player)) ?>)">
                                    <div class="rank-badge rank-<?= $index + 1 ?>"><?= $index + 1 ?></div>
                                    <img src="../uploads/users/<?= $player['profile_image'] ?>"
                                        onerror="this.src='../assets/images/default-player.png'" class="player-avatar">
                                    <div class="player-info">
                                        <div class="player-name"><?= htmlspecialchars($player['name']) ?></div>
                                        <div class="team-name"><?= htmlspecialchars($player['team_name'] ?? 'No Team') ?></div>
                                    </div>
                                    <div class="stat-badge bg-success-subtle text-success">
                                        <?= $player['total_wickets'] ?> wkts
                                    </div>
                                </div>
                            <?php endforeach;
                        } catch (PDOException $e) {
                        } ?>
                    </div>
                </div>
            </div>

            <!-- Player of the Series Ranking -->
            <div class="col-lg-4">
                <div class="ranking-card h-100">
                    <div class="ranking-header">
                        <span><i class="fas fa-crown me-2 text-warning"></i>Player of the Series</span>
                    </div>
                    <div class="ranking-body">
                        <?php if (empty($bestSeriesRankings)): ?>
                            <div class="p-4 text-center text-muted small">No data available</div>
                        <?php else: ?>
                            <?php foreach ($bestSeriesRankings as $index => $p): ?>
                                <div class="ranking-item" style="cursor: pointer;"
                                    onclick="openPlayerCard(<?= htmlspecialchars(json_encode($p)) ?>)">
                                    <div class="rank-badge rank-<?= $index + 1 ?>"><?= $index + 1 ?></div>
                                    <img src="../uploads/users/<?= $p['profile_image'] ?>"
                                        onerror="this.src='../assets/images/default-player.png'" class="player-avatar">
                                    <div class="player-info">
                                        <div class="player-name"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="team-name"><?= htmlspecialchars($p['team_name'] ?? 'No Team') ?></div>
                                    </div>
                                    <div class="stat-badge bg-warning-subtle text-warning">
                                        <?= number_format($p['points']) ?> Pts
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Player Card Modal -->
<div class="modal fade" id="playerCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-body p-0 text-center relative">
                <button type="button"
                    class="btn-close position-absolute top-0 end-0 m-3 z-3 bg-white p-2 rounded-circle shadow-sm"
                    data-bs-dismiss="modal" aria-label="Close"></button>

                <!-- Header BG -->
                <div style="height: 100px; background: linear-gradient(135deg, #0f172a 0%, #334155 100%);"></div>

                <!-- Profile Image -->
                <div class="position-relative" style="margin-top: -60px;">
                    <img id="modalPlayerImg" src="" alt="Player"
                        class="rounded-3 shadow-lg border border-4 border-white"
                        style="width: 140px; height: 140px; object-fit: cover;">
                </div>

                <div class="p-4 pt-3">
                    <h4 class="fw-bold mb-1 text-dark" id="modalPlayerName">Player Name</h4>
                    <p class="text-muted small mb-4" id="modalTeamName">Team Name</p>

                    <!-- Stats Row -->
                    <div class="d-flex justify-content-center gap-4 mb-4">
                        <div class="text-center">
                            <h5 class="fw-bold mb-0 text-primary" id="modalMatches">0</h5>
                            <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Matches</small>
                        </div>
                        <div class="vr bg-light"></div>
                        <div class="text-center">
                            <h5 class="fw-bold mb-0 text-success" id="modalRuns">0</h5>
                            <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Runs</small>
                        </div>
                        <div class="vr bg-light"></div>
                        <div class="text-center">
                            <h5 class="fw-bold mb-0 text-danger" id="modalWickets">0</h5>
                            <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Wickets</small>
                        </div>
                    </div>

                    <a id="modalProfileLink" href="#" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm">
                        View Full Stats
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openPlayerCard(player) {
        // Set Image
        const imgPath = player.profile_image ? '../uploads/users/' + player.profile_image : '../assets/images/default-player.png';
        document.getElementById('modalPlayerImg').src = imgPath;

        // Set Text
        document.getElementById('modalPlayerName').innerText = player.name;
        document.getElementById('modalTeamName').innerText = player.team_name || 'No Team';

        // Set Stats
        // Note: Data keys might differ between admin/player dashboard data sources depending on how we constructed $p
        // In admin dashboard $p has 'total_matches', 'total_runs', 'total_wickets' from the calculation logic I added.
        // Let's ensure fallback to 0.
        document.getElementById('modalMatches').innerText = player.total_matches || 0;
        document.getElementById('modalRuns').innerText = player.total_runs || 0;
        document.getElementById('modalWickets').innerText = player.total_wickets || 0;

        // Set Link
        // In admin dashboard, view_player_profile might need to be accessed differently or same path
        // Admin usually views via ../view/view_player_profile.php?player_id=...
        // $p['id'] or $p['player_id']
        const pid = player.player_id || player.id;
        document.getElementById('modalProfileLink').href = '../view/view_player_profile.php?player_id=' + pid;

        // Show Modal
        const modal = new bootstrap.Modal(document.getElementById('playerCardModal'));
        modal.show();
    }
</script>

<?php require_once '../includes/footer.php'; ?>