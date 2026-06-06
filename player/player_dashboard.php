<?php
// player/player_dashboard.php
// Player Dashboard

require_once __DIR__ . '/../includes/db.php';
require_login();

// Check if user is player
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$profile_image = $_SESSION['profile_image'] ?? null;

// Fetch player stats
// Fetch player stats (Dynamic Calculation for Accuracy)
$playerStats = [];
try {
    // 1. Correct Match Count
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ms.match_id) 
        FROM match_squads ms
        JOIN matches m ON ms.match_id = m.id
        WHERE ms.player_id = ? AND ms.playing_11 = 1 AND m.status = 'completed'
    ");
    $stmt->execute([$user_id]);
    $real_matches = $stmt->fetchColumn();

    // 2. Fetch Aggregated Stats (match_statistics is source of truth)
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
    $stmt->execute([$user_id]);
    $agg_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $real_wickets = $agg_stats['wickets_taken'];

    // 2.1 Calculate Innings Batted
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM match_statistics ms
        JOIN matches m ON ms.match_id = m.id
        WHERE ms.player_id = ? AND ms.balls_faced > 0 AND m.status = 'completed'
    ");
    $stmt->execute([$user_id]);
    $innings_batted = $stmt->fetchColumn();

    // 2.2 Bowling Analytics (Overs, Innings)
    $stmt = $pdo->prepare("
        SELECT ms.runs_conceded, ms.wickets_taken, ms.overs_bowled 
        FROM match_statistics ms
        JOIN matches m ON ms.match_id = m.id
        WHERE ms.player_id = ? AND m.status = 'completed' AND (ms.overs_bowled != '0.0' OR ms.wickets_taken > 0)
    ");
    $stmt->execute([$user_id]);
    $bowling_perfs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $innings_bowled = count($bowling_perfs);
    $total_balls_bowled = 0;
    foreach ($bowling_perfs as $perf) {
        $ov_parts = explode('.', (string) $perf['overs_bowled']);
        $ov = isset($ov_parts[0]) ? (int) $ov_parts[0] : 0;
        $bl = isset($ov_parts[1]) ? (int) $ov_parts[1] : 0;
        $total_balls_bowled += ($ov * 6 + $bl);
    }
    $overs_decimal = $total_balls_bowled / 6;
    $overs_display = floor($total_balls_bowled / 6) . '.' . ($total_balls_bowled % 6);

    // 4. Fetch Stored Stats for cumulative fields (catches, etc.)
    $stmt = $pdo->prepare("SELECT * FROM player_stats WHERE player_id = ?");
    $stmt->execute([$user_id]);
    $stored_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Merge
    $playerStats = $stored_stats ?: [];
    $playerStats['matches_played'] = $real_matches;
    $playerStats['innings_batted'] = $innings_batted;
    $playerStats['innings_bowled'] = $innings_bowled;
    $playerStats['wickets_taken'] = $real_wickets;
    $playerStats['runs_scored'] = $agg_stats['runs_scored'] ?: 0;
    $playerStats['runs_conceded'] = $agg_stats['runs_conceded'] ?: 0;
    $playerStats['overs_bowled'] = $overs_display;
    $playerStats['highest_score'] = max($agg_stats['highest_score'] ?? 0, $stored_stats['highest_score'] ?? 0);
    $playerStats['centuries'] = $agg_stats['centuries'] ?: 0;
    $playerStats['half_centuries'] = $agg_stats['half_centuries'] ?: 0;
    $playerStats['fours'] = $agg_stats['fours'] ?: 0;
    $playerStats['sixes'] = $agg_stats['sixes'] ?: 0;

    // Recalculate Rates
    $playerStats['batting_average'] = ($playerStats['innings_batted'] > 0) ? ($playerStats['runs_scored'] / $playerStats['innings_batted']) : 0;
    $playerStats['strike_rate'] = ($agg_stats['balls_faced'] > 0) ? (($playerStats['runs_scored'] / $agg_stats['balls_faced']) * 100) : 0;
    $playerStats['bowling_average'] = ($real_wickets > 0) ? ($playerStats['runs_conceded'] / $real_wickets) : 0;
    $playerStats['economy_rate'] = ($overs_decimal > 0) ? ($playerStats['runs_conceded'] / $overs_decimal) : 0;

} catch (PDOException $e) {
    $playerStats = [];
}

// Fetch rankings
$bestBattingRankings = [];
$bestBowlingRankings = [];
$bestSeriesRankings = [];

try {
    // Best Batsman (Weighted Scoring)
    $stmt = $pdo->query("
        SELECT 
            u.id as player_id, 
            u.name, 
            u.profile_image, 
            t.team_name,
            SUM(ms.runs_scored) as total_runs,
            SUM(ms.balls_faced) as total_balls,
            COUNT(DISTINCT ms.match_id) as total_matches,
            SUM(ms.wickets_taken) as total_wickets,
            SUM(
                ms.runs_scored -- 1 point per run
                + CASE 
                    WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 160 THEN 15
                    WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 140 THEN 10
                    WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 120 THEN 5
                    ELSE 0
                  END -- SR Bonus
                + (ms.fours * 1 + ms.sixes * 2) -- Boundary Bonus
                + CASE 
                    WHEN ms.runs_scored >= 100 THEN 20
                    WHEN ms.runs_scored >= 50 THEN 12
                    WHEN ms.runs_scored >= 30 THEN 5
                    ELSE 0
                  END -- Milestone Bonus
                + CASE 
                    WHEN ms.runs_scored = 0 AND EXISTS (
                        SELECT 1 FROM ball_by_ball bbb 
                        WHERE bbb.match_id = ms.match_id 
                        AND bbb.wicket_player_id = ms.player_id
                    ) THEN -5
                    ELSE 0
                  END -- Duck Penalty
                + CASE 
                    WHEN m.winner_id = ms.team_id THEN 5
                    ELSE 0
                  END -- Win Bonus
            ) as performance_score,
            (SELECT SUM(ms_inner.runs_scored) 
             FROM match_statistics ms_inner 
             JOIN matches m_inner ON ms_inner.match_id = m_inner.id
             WHERE ms_inner.player_id = u.id AND m_inner.status = 'completed') as career_runs,
            (SELECT SUM(ms_inner.wickets_taken) 
             FROM match_statistics ms_inner 
             JOIN matches m_inner ON ms_inner.match_id = m_inner.id
             WHERE ms_inner.player_id = u.id AND m_inner.status = 'completed') as career_wickets,
            (SELECT COUNT(DISTINCT ms_match.match_id) 
                FROM match_squads ms_match
                JOIN matches m_match ON ms_match.match_id = m_match.id
                WHERE ms_match.player_id = u.id AND ms_match.playing_11 = 1 AND m_match.status = 'completed') as career_matches
        FROM match_statistics ms
        JOIN users u ON ms.player_id = u.id
        JOIN matches m ON ms.match_id = m.id
        LEFT JOIN teams t ON ms.team_id = t.id

        WHERE ms.balls_faced > 0 AND m.status = 'completed'
        GROUP BY u.id, u.name, u.profile_image, t.team_name
        ORDER BY performance_score DESC, 
                 (SUM(ms.runs_scored) / NULLIF(SUM(ms.balls_faced), 0) * 100) DESC, 
                 SUM(ms.runs_scored) DESC
    ");
    $bestBattingRankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            u.id as player_id, 
            u.name, 
            u.profile_image, 
            t.team_name,
            SUM(ms.wickets_taken) as total_wickets,
            SUM(ms.runs_conceded) as total_runs_conceded,
            SUM(ms.maidens) as total_maidens,
            SUM(match_balls) as total_balls,
            SUM(match_dot_balls) as total_dots,
            COUNT(DISTINCT ms.match_id) as total_matches,
            SUM(ms.runs_scored) as total_runs,
            SUM(
                (ms.wickets_taken * 25) -- Wicket points
                + CASE 
                    WHEN ms.overs_bowled >= 2.0 THEN
                        CASE 
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 5.00 THEN 15
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 6.50 THEN 10
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 7.50 THEN 5
                            ELSE 0
                        END
                    ELSE 0
                  END -- Econ Bonus
                + (ms.maidens * 10) -- Maiden Bonus
                + CASE 
                    WHEN ms.wickets_taken >= 5 THEN 25
                    WHEN ms.wickets_taken = 4 THEN 15
                    WHEN ms.wickets_taken = 3 THEN 10
                    ELSE 0
                  END -- Wicket Milestone Bonus
                + CASE 
                    WHEN match_dot_balls >= 20 THEN 15
                    WHEN match_dot_balls >= 15 THEN 10
                    WHEN match_dot_balls >= 10 THEN 5
                    ELSE 0
                  END -- Dot Ball Bonus
                + CASE WHEN m.winner_id = ms.team_id THEN 5 ELSE 0 END -- Win Bonus
            ) as performance_score,
            (SELECT SUM(ms_inner.runs_scored) 
             FROM match_statistics ms_inner 
             JOIN matches m_inner ON ms_inner.match_id = m_inner.id
             WHERE ms_inner.player_id = u.id AND m_inner.status = 'completed') as career_runs,
            (SELECT SUM(ms_inner.wickets_taken) 
             FROM match_statistics ms_inner 
             JOIN matches m_inner ON ms_inner.match_id = m_inner.id
             WHERE ms_inner.player_id = u.id AND m_inner.status = 'completed') as career_wickets,
            (SELECT COUNT(DISTINCT ms_match.match_id) 
                FROM match_squads ms_match
                JOIN matches m_match ON ms_match.match_id = m_match.id
                WHERE ms_match.player_id = u.id AND ms_match.playing_11 = 1 AND m_match.status = 'completed') as career_matches
        FROM (
            SELECT ms.*, 
                (FLOOR(ms.overs_bowled) * 6 + ROUND((ms.overs_bowled - FLOOR(ms.overs_bowled)) * 10)) as match_balls,
                (SELECT COUNT(*) FROM ball_by_ball bbb 
                    WHERE bbb.match_id = ms.match_id 
                    AND bbb.bowler_id = ms.player_id 
                    AND bbb.runs_scored = 0 
                    AND (bbb.extra_type IS NULL OR bbb.extra_type NOT IN ('wide', 'no ball'))
                ) as match_dot_balls
            FROM match_statistics ms
        ) ms
        JOIN users u ON ms.player_id = u.id
        JOIN matches m ON ms.match_id = m.id
        LEFT JOIN teams t ON ms.team_id = t.id
        WHERE ms.match_balls > 0 AND m.status = 'completed'
        GROUP BY u.id, u.name, u.profile_image, t.team_name
        HAVING performance_score > 0
        ORDER BY performance_score DESC, 
                 total_wickets DESC, 
                 (SUM(ms.runs_conceded) * 6 / NULLIF(SUM(match_balls), 0)) ASC, 
                 total_dots DESC
    ");
    $bestBowlingRankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Player of the Series (Advanced Scoring Logic - Synchronized with pos_ranking.php)
    $bestSeriesRankings = [];

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
        // Map: [match_id][player_id] = true
        $out_map = [];
        foreach ($dismissals as $d) {
            $out_map[$d['match_id']][$d['wicket_player_id']] = true;
        }

        // 5. Fetch All Players Info with Career Stats
        $players_stmt = $pdo->query("
            SELECT u.id, u.name, u.profile_image, tp.team_id, t.team_name,
                   COALESCE(cs.career_runs, 0) as career_runs,
                   COALESCE(cs.career_wickets, 0) as career_wickets,
                   COALESCE(cm.career_matches, 0) as career_matches
            FROM users u 
            LEFT JOIN team_players tp ON u.id = tp.player_id
            LEFT JOIN teams t ON tp.team_id = t.id 
            LEFT JOIN (
                SELECT ms_inner.player_id, 
                       SUM(ms_inner.runs_scored) as career_runs, 
                       SUM(ms_inner.wickets_taken) as career_wickets
                FROM match_statistics ms_inner
                JOIN matches m_inner ON ms_inner.match_id = m_inner.id
                WHERE m_inner.status = 'completed'
                GROUP BY ms_inner.player_id
            ) cs ON u.id = cs.player_id
            LEFT JOIN (
                SELECT ms_match.player_id, COUNT(DISTINCT ms_match.match_id) as career_matches
                FROM match_squads ms_match
                JOIN matches m_match ON ms_match.match_id = m_match.id
                WHERE ms_match.playing_11 = 1 AND m_match.status = 'completed'
                GROUP BY ms_match.player_id
            ) cm ON u.id = cm.player_id
            WHERE u.role='player'
        ");
        $all_players = $players_stmt->fetchAll(PDO::FETCH_ASSOC);
        $player_info = [];
        $player_data = [];
        foreach ($all_players as $p) {
            $player_info[$p['id']] = $p;
            $player_data[$p['id']] = ['matches' => []];
        }

        // Process Batting/Bowling (Accumulate stats across innings)
        foreach ($all_stats as $s) {
            $pid = $s['player_id'];
            $mid = $s['match_id'];
            if (!isset($player_data[$pid]))
                continue;

            if (!isset($player_data[$pid]['matches'][$mid])) {
                $player_data[$pid]['matches'][$mid] = [
                    'bat' => ['runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0],
                    'bowl' => ['wickets' => 0, 'conceded' => 0, 'legal_balls' => 0],
                    'field' => ['catches' => 0, 'run_outs' => 0, 'stumpings' => 0]
                ];
            }

            $player_data[$pid]['matches'][$mid]['bat']['runs'] += $s['runs_scored'];
            $player_data[$pid]['matches'][$mid]['bat']['balls'] += $s['balls_faced'];
            $player_data[$pid]['matches'][$mid]['bowl']['wickets'] += $s['wickets_taken'];
            $player_data[$pid]['matches'][$mid]['bowl']['conceded'] += $s['runs_conceded'];

            $overs_str = $s['overs_bowled'] ?? '0.0';
            $parts = explode('.', (string) $overs_str);
            $balls = ((int) $parts[0] * 6) + (int) ($parts[1] ?? 0);
            $player_data[$pid]['matches'][$mid]['bowl']['legal_balls'] += $balls;
        }

        // Process Fielding
        foreach ($all_fielding as $f) {
            $pid = $f['fielder_id'];
            $mid = $f['match_id'];
            if (!isset($player_data[$pid]))
                continue;
            if (!isset($player_data[$pid]['matches'][$mid])) {
                $player_data[$pid]['matches'][$mid] = [
                    'bat' => ['runs' => 0, 'balls' => 0],
                    'bowl' => ['wickets' => 0, 'conceded' => 0, 'legal_balls' => 0],
                    'field' => ['catches' => 0, 'run_outs' => 0, 'stumpings' => 0]
                ];
            }

            $player_data[$pid]['matches'][$mid]['field'] = [
                'catches' => $f['catches'],
                'run_outs' => $f['run_outs'],
                'stumpings' => $f['stumpings']
            ];
        }

        // 6. Calculate Scores
        $scores = [];
        $rankings_data = [];

        foreach ($player_data as $pid => $data) {
            $total_points = 0;
            $matches_played = count($data['matches']);
            if ($matches_played === 0)
                continue;

            $matches_30_runs = 0;
            $matches_with_wickets = 0;

            foreach ($data['matches'] as $mid => $m_stats) {
                $match_points = 0;

                // Batting
                $runs = $m_stats['bat']['runs'] ?? 0;
                $balls = $m_stats['bat']['balls'] ?? 0;
                $is_out = isset($out_map[$mid][$pid]);

                $match_points += $runs;
                if ($runs >= 100)
                    $match_points += 20;
                elseif ($runs >= 50)
                    $match_points += 10;

                if ($balls > 0) {
                    $sr = ($runs / $balls) * 100;
                    if ($sr >= 150)
                        $match_points += 10;
                    elseif ($sr >= 120)
                        $match_points += 5;
                }

                if ($runs == 0 && $is_out)
                    $match_points -= 5;

                // Bowling
                $wickets = $m_stats['bowl']['wickets'] ?? 0;
                $conceded = $m_stats['bowl']['conceded'] ?? 0;
                $legal_balls = $m_stats['bowl']['legal_balls'] ?? 0;

                $match_points += ($wickets * 25);
                if ($wickets >= 5)
                    $match_points += 20;
                elseif ($wickets >= 3)
                    $match_points += 10;

                if ($legal_balls >= 12) {
                    $econ = ($conceded / $legal_balls) * 6;
                    if ($econ <= 6.0)
                        $match_points += 10;
                    elseif ($econ <= 7.5)
                        $match_points += 5;
                }

                // Fielding
                $catches = $m_stats['field']['catches'] ?? 0;
                $runouts = $m_stats['field']['run_outs'] ?? 0;
                $stumps = $m_stats['field']['stumpings'] ?? 0;

                $match_points += ($catches * 8);
                $match_points += ($runouts * 12);
                $match_points += ($stumps * 10);

                // Win Bonus
                $p_team = $player_info[$pid]['team_id'];
                if (isset($completed_matches[$mid]) && $completed_matches[$mid] == $p_team) {
                    $match_points += 5;
                }

                $total_points += $match_points;

                if ($runs >= 30)
                    $matches_30_runs++;
                if ($wickets >= 1)
                    $matches_with_wickets++;
            }

            // Series Bonuses
            if ($matches_played >= 3)
                $total_points += 10;
            if ($matches_30_runs >= 3)
                $total_points += 10;
            if ($matches_with_wickets >= 3)
                $total_points += 10;

            if ($total_points > 0) {
                $scores[$pid] = $total_points;
                $rankings_data[$pid] = [
                    'player_id' => $pid,
                    'name' => $player_info[$pid]['name'],
                    'profile_image' => $player_info[$pid]['profile_image'],
                    'team_name' => $player_info[$pid]['team_name'],
                    'points' => $total_points,
                    'total_runs' => $player_info[$pid]['career_runs'],
                    'total_wickets' => $player_info[$pid]['career_wickets'],
                    'total_matches' => $player_info[$pid]['career_matches']
                ];
            }
        }

        // Sort by points
        arsort($scores);
        foreach ($scores as $pid => $pt) {
            $bestSeriesRankings[] = $rankings_data[$pid];
        }
    }
} catch (PDOException $e) {
    // Silent fail for empty
}

$page_title = "Player Dashboard";
require_once '../includes/header.php';
?>

<!-- Custom CSS -->
<style>
    :root {
        --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        --glass-bg: rgba(255, 255, 255, 0.85);
        --glass-border: rgba(255, 255, 255, 0.6);
        --glass-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --text-primary: #0f172a;
        --text-secondary: #64748b;
        --accent-color: #3b82f6;
        /* Subtle blue */
        --card-radius: 16px;
    }

    body {
        background: var(--bg-gradient);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        color: var(--text-primary);
        min-height: 100vh;
        overflow-x: hidden;
        /* Fix horizontal scroll */
    }

    .main-container {
        padding: 2rem 1.25rem 4rem;
        max-width: 1200px;
        margin: 0 auto;
    }

    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Stats Cards - New 4 Detail Section Style */
    .detail-card {
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        border-radius: var(--card-radius);
        padding: 1.5rem;
        height: 100%;
        transition: transform 0.2s;
        box-shadow: var(--glass-shadow);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .detail-card:hover {
        transform: translateY(-4px);
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
        background: rgba(59, 130, 246, 0.1);
        color: var(--accent-color);
    }

    .detail-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.5rem;
    }

    .detail-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1;
        margin-bottom: 0.5rem;
    }

    .detail-sub {
        font-size: 0.8rem;
        color: var(--text-secondary);
    }

    /* Small Overview Cards */
    .mini-stat-card {
        background: white;
        border-radius: 12px;
        padding: 1rem;
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .mini-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #f1f5f9;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .mini-stat-info h6 {
        margin: 0;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 600;
    }

    .mini-stat-info p {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
    }

    /* Ranking Lists */
    .ranking-card {
        background: white;
        border-radius: var(--card-radius);
        border: 1px solid #e2e8f0;
        overflow: hidden;
        height: 100%;
        box-shadow: var(--glass-shadow);
    }

    .ranking-header {
        padding: 1.25rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-weight: 700;
        color: #334155;
        font-size: 0.95rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ranking-body {
        max-height: 380px;
        overflow-y: auto;
        /* Scrollbar Styling */
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .ranking-body::-webkit-scrollbar {
        width: 6px;
    }

    .ranking-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .ranking-body::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 20px;
    }

    .ranking-list-item {
        display: flex;
        align-items: center;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        text-decoration: none;
        color: inherit;
        transition: background 0.2s;
    }

    .ranking-list-item:hover {
        background: #f8fafc;
    }

    .rank-num {
        font-weight: 600;
        color: #94a3b8;
        width: 24px;
        margin-right: 1rem;
    }

    .rank-1 {
        color: #d97706;
    }

    /* Gold-ish */
    .rank-2 {
        color: #64748b;
    }

    /* Silver-ish */
    .rank-3 {
        color: #b45309;
    }

    /* Bronze-ish */

    .player-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 0.75rem;
        background: #e2e8f0;
    }

    .player-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e293b;
    }

    .player-stat {
        margin-left: auto;
        font-weight: 700;
        color: #334155;
        font-size: 0.9rem;
    }
</style>

<div class="main-container container">

    <!-- Section 1: 4 Key Performance Details -->
    <div class="section-title">
        <i class="fas fa-chart-pie text-primary"></i> Performance Insights
    </div>
    <div class="row g-4 mb-5">
        <!-- Card 1: Matches -->
        <div class="col-6 col-md-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon" style="color: #2563eb; background: #dbeafe;">
                        <i class="fas fa-baseball-ball"></i>
                    </div>
                    <div class="detail-title">Matches Played</div>
                    <div class="detail-value"><?= $playerStats['matches_played'] ?? 0 ?></div>
                </div>
                <div class="detail-sub">Total matches participated</div>
            </div>
        </div>
        <!-- Card 2: Batting Impact -->
        <div class="col-6 col-md-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon" style="color: #059669; background: #d1fae5;">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="detail-title">Runs Scored</div>
                    <div class="detail-value"><?= $playerStats['runs_scored'] ?? 0 ?></div>
                </div>
                <div class="detail-sub">Avg: <?= number_format($playerStats['batting_average'] ?? 0, 1) ?> | HS:
                    <?= $playerStats['highest_score'] ?? 0 ?>
                </div>
            </div>
        </div>
        <!-- Card 3: Bowling Impact -->
        <div class="col-6 col-md-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon" style="color: #d97706; background: #fef3c7;">
                        <i class="fas fa-bowling-ball"></i>
                    </div>
                    <div class="detail-title">Wickets Taken</div>
                    <div class="detail-value"><?= $playerStats['wickets_taken'] ?? 0 ?></div>
                </div>
                <div class="detail-sub">Econ: <?= number_format($playerStats['economy_rate'] ?? 0, 2) ?></div>
            </div>
        </div>
        <!-- Card 4: Contribution -->
        <div class="col-6 col-md-3">
            <div class="detail-card">
                <div>
                    <div class="detail-icon" style="color: #7c3aed; background: #ede9fe;">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="detail-title">M.O.M Awards</div>
                    <div class="detail-value"><?= $playerStats['man_of_match'] ?? 0 ?></div>
                </div>
                <div class="detail-sub">Match Winning Performances</div>
            </div>
        </div>
    </div>

    <!-- Section 2: Overview Grid (Smaller Stats) -->
    <div class="section-title">
        <i class="fas fa-list-alt text-secondary"></i> Detailed Statistics
    </div>
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-percentage"></i></div>
                <div class="mini-stat-info">
                    <h6>Strike Rate</h6>
                    <p><?= number_format($playerStats['strike_rate'] ?? 0, 1) ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-bullseye"></i></div>
                <div class="mini-stat-info">
                    <h6>Bowling Avg</h6>
                    <p><?= number_format($playerStats['bowling_average'] ?? 0, 1) ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-hand-paper"></i></div>
                <div class="mini-stat-info">
                    <h6>Catches</h6>
                    <p><?= $playerStats['catches'] ?? 0 ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-stopwatch"></i></div>
                <div class="mini-stat-info">
                    <h6>Stumpings</h6>
                    <p><?= $playerStats['stumpings'] ?? 0 ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-arrow-right"></i></div>
                <div class="mini-stat-info">
                    <h6>Fours</h6>
                    <p><?= $playerStats['fours'] ?? 0 ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="mini-stat-info">
                    <h6>Sixes</h6>
                    <p><?= $playerStats['sixes'] ?? 0 ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-medal"></i></div>
                <div class="mini-stat-info">
                    <h6>50s</h6>
                    <p><?= $playerStats['half_centuries'] ?? 0 ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon"><i class="fas fa-crown"></i></div>
                <div class="mini-stat-info">
                    <h6>100s</h6>
                    <p><?= $playerStats['centuries'] ?? 0 ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Leaderboards -->
    <div class="section-title">
        <i class="fas fa-trophy text-warning"></i> Tournament Leaders
    </div>
    <div class="row g-4">
        <!-- Batting Leaders -->
        <div class="col-lg-4">
            <div class="ranking-card">
                <div class="ranking-header">
                    <span>Best Batsman</span>
                    <i class="fas fa-running text-muted"></i>
                </div>
                <div class="ranking-body">
                    <?php if (empty($bestBattingRankings)): ?>
                        <div class="p-3 text-center text-muted small">No data</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 align-middle" style="font-size: 0.85rem;">
                                <thead class="bg-light">
                                    <tr class="text-muted" style="font-size: 0.75rem;">
                                        <th class="ps-3">Player</th>
                                        <th class="text-center">R</th>
                                        <th class="text-center">B</th>
                                        <th class="text-center">SR</th>
                                        <th class="text-center pe-3">Pts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bestBattingRankings as $index => $p):
                                        $sr = $p['total_balls'] > 0 ? ($p['total_runs'] / $p['total_balls']) * 100 : 0;
                                        // Prepare data for modal with career stats
                                        $p_modal = $p;
                                        $p_modal['total_runs'] = $p['career_runs'] ?: 0;
                                        $p_modal['total_wickets'] = $p['career_wickets'] ?: 0;
                                        $p_modal['total_matches'] = $p['career_matches'] ?: 0;
                                        ?>
                                        <tr style="cursor: pointer;"
                                            onclick="openPlayerCard(<?= htmlspecialchars(json_encode($p_modal)) ?>)">
                                            <td class="ps-3 py-2">
                                                <div class="d-flex align-items-center">
                                                    <span class="rank-num rank-<?= $index + 1 ?> me-2"
                                                        style="width: 15px; font-size: 0.8rem;"><?= $index + 1 ?></span>
                                                    <img src="<?= $p['profile_image'] ? '../uploads/users/' . $p['profile_image'] : '../assets/images/default-avatar.png' ?>"
                                                        class="player-avatar" style="width: 28px; height: 28px;">
                                                    <div class="d-flex flex-column" style="line-height: 1.1;">
                                                        <span class="player-name small fw-bold text-truncate"
                                                            style="max-width: 80px;"><?= htmlspecialchars($p['name']) ?></span>
                                                        <span class="text-muted"
                                                            style="font-size: 0.65rem;"><?= htmlspecialchars($p['team_name'] ?? 'No Team') ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center fw-bold"><?= $p['total_runs'] ?></td>
                                            <td class="text-center text-muted" style="font-size: 0.75rem;">
                                                <?= $p['total_balls'] ?>
                                            </td>
                                            <td class="text-center" style="font-size: 0.75rem;"><?= number_format($sr, 1) ?>
                                            </td>
                                            <td class="text-center pe-3 fw-bold text-success">
                                                <?= number_format($p['performance_score']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bowling Leaders -->
        <div class="col-lg-4">
            <div class="ranking-card">
                <div class="ranking-header">
                    <span>Best Bowler</span>
                    <i class="fas fa-bowling-ball text-muted"></i>
                </div>
                <div class="ranking-body">
                    <?php if (empty($bestBowlingRankings)): ?>
                        <div class="p-3 text-center text-muted small">No data</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 align-middle" style="font-size: 0.85rem;">
                                <thead class="bg-light">
                                    <tr class="text-muted" style="font-size: 0.75rem;">
                                        <th class="ps-3">Player</th>
                                        <th class="text-center">W</th>
                                        <th class="text-center">O</th>
                                        <th class="text-center">E</th>
                                        <th class="text-center pe-3">Pts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bestBowlingRankings as $index => $p):
                                        $display_overs = floor($p['total_balls'] / 6) . '.' . ($p['total_balls'] % 6);
                                        $economy = $p['total_balls'] > 0 ? ($p['total_runs_conceded'] * 6) / $p['total_balls'] : 0;
                                        // Prepare data for modal with career stats
                                        $p_modal = $p;
                                        $p_modal['total_runs'] = $p['career_runs'] ?: 0;
                                        $p_modal['total_wickets'] = $p['career_wickets'] ?: 0;
                                        $p_modal['total_matches'] = $p['career_matches'] ?: 0;
                                        ?>
                                        <tr style="cursor: pointer;"
                                            onclick="openPlayerCard(<?= htmlspecialchars(json_encode($p_modal)) ?>)">
                                            <td class="ps-3 py-2">
                                                <div class="d-flex align-items-center">
                                                    <span class="rank-num rank-<?= $index + 1 ?> me-2"
                                                        style="width: 15px; font-size: 0.8rem;"><?= $index + 1 ?></span>
                                                    <img src="<?= $p['profile_image'] ? '../uploads/users/' . $p['profile_image'] : '../assets/images/default-avatar.png' ?>"
                                                        class="player-avatar" style="width: 28px; height: 28px;">
                                                    <div class="d-flex flex-column" style="line-height: 1.1;">
                                                        <span class="player-name small fw-bold text-truncate"
                                                            style="max-width: 80px;"><?= htmlspecialchars($p['name']) ?></span>
                                                        <span class="text-muted"
                                                            style="font-size: 0.65rem;"><?= htmlspecialchars($p['team_name'] ?? 'No Team') ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center fw-bold"><?= $p['total_wickets'] ?></td>
                                            <td class="text-center text-muted" style="font-size: 0.75rem;"><?= $display_overs ?>
                                            </td>
                                            <td class="text-center" style="font-size: 0.75rem;">
                                                <?= number_format($economy, 2) ?>
                                            </td>
                                            <td class="text-center pe-3 fw-bold text-primary">
                                                <?= number_format($p['performance_score']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Player of Series -->
        <div class="col-lg-4">
            <div class="ranking-card">
                <div class="ranking-header">
                    <span>Player of the Series</span>
                    <i class="fas fa-crown text-warning"></i>
                </div>
                <div class="ranking-body">
                    <?php if (empty($bestSeriesRankings)): ?>
                        <div class="p-3 text-center text-muted small">No data</div>
                    <?php else: ?>
                        <?php foreach ($bestSeriesRankings as $index => $p):
                            // Prepare data for modal with career stats
                            $p_modal = $p;
                            $p_modal['total_runs'] = $p['total_runs'] ?: 0;
                            $p_modal['total_wickets'] = $p['total_wickets'] ?: 0;
                            $p_modal['total_matches'] = $p['total_matches'] ?: 0;
                            ?>
                            <div class="ranking-list-item" style="cursor: pointer;"
                                onclick="openPlayerCard(<?= htmlspecialchars(json_encode($p_modal)) ?>)">
                                <span class="rank-num rank-<?= $index + 1 ?>"><?= $index + 1 ?></span>
                                <img src="<?= $p['profile_image'] ? '../uploads/users/' . $p['profile_image'] : '../assets/images/default-avatar.png' ?>"
                                    class="player-avatar">
                                <div class="d-flex flex-column justify-content-center me-auto">
                                    <span class="player-name"><?= htmlspecialchars($p['name']) ?></span>
                                    <span class="small text-muted"
                                        style="font-size: 0.75rem;"><?= htmlspecialchars($p['team_name'] ?? 'No Team') ?></span>
                                </div>
                                <span class="player-stat"><?= number_format($p['points']) ?> <small
                                        class="fw-normal text-muted">Pts</small></span>
                            </div>
                        <?php endforeach; ?>
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
        const imgPath = player.profile_image ? '../uploads/users/' + player.profile_image : '../assets/images/default-avatar.png';
        document.getElementById('modalPlayerImg').src = imgPath;

        // Set Text
        document.getElementById('modalPlayerName').innerText = player.name;
        document.getElementById('modalTeamName').innerText = player.team_name || 'No Team';

        // Set Stats
        document.getElementById('modalMatches').innerText = player.total_matches || 0;
        document.getElementById('modalRuns').innerText = player.total_runs || 0;
        document.getElementById('modalWickets').innerText = player.total_wickets || 0;

        // Set Link
        document.getElementById('modalProfileLink').href = '../view/view_player_profile.php?player_id=' + player.player_id;

        // Show Modal
        const modal = new bootstrap.Modal(document.getElementById('playerCardModal'));
        modal.show();
    }
</script>

<?php require_once '../includes/footer.php'; ?>