<?php
// NavBarList/pos_ranking.php
require_once '../includes/db.php';

$page_title = "Player of the Series Ranking";
require_once '../includes/header.php';

// Player of the Series (Advanced Scoring Logic - Synchronized with player_dashboard.php)
try {
    // 1. Fetch Completed Matches (For Win Bonus)
    $matches_stmt = $pdo->query("SELECT id, winner_id FROM matches WHERE status = 'completed'");
    $completed_matches = $matches_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => winner_id]

    $rankings = [];
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

            // Totals for table display
            $total_runs = 0;
            $total_balls = 0;
            $total_wickets = 0;
            $total_legal_balls = 0;
            $total_conceded = 0;

            foreach ($data['matches'] as $mid => $m_stats) {
                $match_points = 0;

                // Batting
                $runs = $m_stats['bat']['runs'] ?? 0;
                $balls = $m_stats['bat']['balls'] ?? 0;
                $is_out = isset($out_map[$mid][$pid]);

                $total_runs += $runs;
                $total_balls += $balls;

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

                $total_wickets += $wickets;
                $total_legal_balls += $legal_balls;
                $total_conceded += $conceded;

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
                    'image' => $player_info[$pid]['profile_image'],
                    'team' => $player_info[$pid]['team_name'],
                    'total_runs' => $total_runs,
                    'total_balls' => $total_balls,
                    'total_wickets' => $total_wickets,
                    'total_balls_bowled' => $total_legal_balls,
                    'total_runs_conceded' => $total_conceded,
                    'score' => $total_points,
                    'career_runs' => $player_info[$pid]['career_runs'],
                    'career_wickets' => $player_info[$pid]['career_wickets'],
                    'career_matches' => $player_info[$pid]['career_matches']
                ];
            }
        }

        // Sort exactly as player_dashboard (arsort on scores)
        arsort($scores);
        foreach ($scores as $pid => $pt) {
            $rankings[] = $rankings_data[$pid];
        }
    }
} catch (PDOException $e) {
    $rankings = [];
}
?>

<style>
    .bg-bronze {
        background-color: #cd7f32;
    }

    .ranking-row {
        transition: all 0.3s ease;
    }

    .ranking-row:hover {
        background-color: rgba(109, 40, 217, 0.05) !important;
        transform: scale(1.005);
        box-shadow: inset 4px 0 0 #6d28d9;
    }

    .fs-xs {
        font-size: 0.65rem;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Player Card Overlay Styles */
    .player-card-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeIn 0.2s ease-out;
    }

    .player-card {
        background:
            radial-gradient(circle at top left, rgba(124, 58, 237, 0.16), transparent 34%),
            linear-gradient(145deg, #ffffff 0%, #f8fafc 48%, #f5f3ff 100%);
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 24px;
        padding: 2rem;
        width: 90%;
        max-width: 400px;
        position: relative;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
        animation: zoomIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .player-card::before {
        content: "";
        position: absolute;
        inset: 10px;
        border: 1px solid rgba(255, 255, 255, 0.85);
        border-radius: 18px;
        pointer-events: none;
    }

    .player-card img {
        background: #ffffff;
        border: 5px solid #ffffff !important;
        box-shadow: 0 14px 28px rgba(15, 23, 42, 0.16) !important;
    }

    .player-card h3 {
        color: #0f172a !important;
        letter-spacing: 0;
    }

    .player-card .badge {
        background: #ede9fe !important;
        color: #5b21b6 !important;
        border: 1px solid rgba(124, 58, 237, 0.18);
    }

    .player-card .row.g-3>div>div {
        background: #ffffff !important;
        border: 1px solid rgba(148, 163, 184, 0.22);
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.07);
    }

    .player-card .text-white-50 {
        color: #64748b !important;
        font-weight: 700;
        letter-spacing: 0.35px;
    }

    .player-card .text-white {
        color: #0f172a !important;
    }

    .player-card .text-warning {
        color: #ca8a04 !important;
    }

    .player-card .text-info {
        color: #0284c7 !important;
    }

    .player-card .btn {
        background: linear-gradient(135deg, #6d28d9, #8b5cf6);
        border: 0;
        color: #ffffff;
        box-shadow: 0 14px 26px rgba(124, 58, 237, 0.24);
    }

    .player-card .btn:hover {
        color: #ffffff;
        filter: brightness(0.96);
    }

    .player-card-overlay.d-none {
        display: none !important;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes zoomIn {
        from {
            transform: scale(0.9);
            opacity: 0;
        }

        to {
            transform: scale(1);
            opacity: 1;
        }
    }

    @media (max-width: 767px) {
        .table thead th {
            font-weight: 900 !important;
            color: #000;
        }

        .table-responsive {
            overflow-x: hidden;
        }

        .table {
            width: 100%;
        }

        .col-xl-10 {
            padding-left: 10px;
            padding-right: 10px;
        }

        .alert-primary .row>div {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 10px 0;
        }

        .alert-primary .row>div:last-child {
            border-bottom: none;
        }

        .alert-primary .border-end {
            border-end: none !important;
        }

        /* Player Card Mobile Adjustments */
        .player-card {
            width: 95% !important;
            max-width: none !important;
            padding: 1.5rem !important;
            margin: 1rem;
        }

        .player-card img {
            width: 200px !important;
            height: 200px !important;
        }

        .player-card h3 {
            font-size: 1.25rem;
        }

        .player-card .badge {
            font-size: 0.75rem;
        }

        .player-card .row.g-3>div>div {
            padding: 0.75rem !important;
        }

        .player-card .h4 {
            font-size: 1.125rem;
        }

        .player-card .btn {
            font-size: 0.875rem;
            padding: 0.75rem 1rem;
        }

        .btn-close {
            top: 0.5rem !important;
            right: 0.5rem !important;
        }
    }

    /* Mobile Enhancements < 480px */
    @media (max-width: 480px) {
        .container-fluid {
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        h2 {
            font-size: 1.15rem !important;
        }

        .alert-primary {
            padding: 0.5rem !important;
            margin-bottom: 0.75rem !important;
        }

        .alert-primary .fw-bold {
            font-size: 0.75rem;
        }

        .alert-primary small {
            font-size: 0.6rem;
        }

        th[style*="width: 80px"] {
            width: 40px !important;
        }

        .ranking-row td {
            padding-top: 6px !important;
            padding-bottom: 6px !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        .player-name,
        .fw-bold.text-dark {
            font-size: 0.75rem !important;
        }

        .badge.fs-6 {
            font-size: 0.7rem !important;
            padding: 0.2rem 0.4rem !important;
        }

        .table {
            width: 100%;
            font-size: 0.7rem;
        }

        .table thead th {
            padding: 0.25rem 0.1rem;
            font-size: 0.65rem;
            font-weight: 900 !important;
            color: #000;
        }

        .badge.rounded-circle {
            width: 22px !important;
            height: 22px !important;
            font-size: 0.65rem !important;
            padding: 0 !important;
        }

        .rounded-circle.me-3 {
            width: 28px !important;
            height: 28px !important;
            margin-right: 0.4rem !important;
        }

        .ps-4 {
            padding-left: 0.2rem !important;
        }

        .pe-4 {
            padding-right: 0.2rem !important;
        }

        .player-card img {
            width: 200px !important;
            height: 200px !important;
        }

        .player-card h3 {
            font-size: 1.125rem;
        }

        .player-card .h4 {
            font-size: 1rem;
        }
    }

    /* Mobile Enhancements < 400px */
    @media (max-width: 400px) {
        h2 {
            font-size: 1.05rem !important;
        }

        .badge.bg-primary {
            padding: 4px 8px !important;
            font-size: 0.65rem;
        }

        .ranking-row img {
            width: 24px !important;
            height: 24px !important;
            margin-right: 6px !important;
        }

        .ranking-row .fw-bold.text-dark {
            font-size: 0.7rem !important;
        }

        .ranking-row .text-muted.small {
            font-size: 0.6rem !important;
        }

        .table {
            width: 100%;
            font-size: 0.65rem;
        }

        .alert-primary .row>div {
            padding: 4px 0 !important;
        }
    }
</style>

<div class="container-fluid py-4"
    style="background: linear-gradient(135deg, rgba(248, 250, 252, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%); min-height: calc(100vh - 120px);">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="pe-2">
                    <h2 class="fw-bold mb-1" style="color: #6d28d9;">
                        <i class="fas fa-trophy me-2"></i>Player of the Series Ranking
                    </h2>
                    <p class="text-muted mb-0">Combined Performance, Consistency & Impact Scoring</p>
                </div>
                <div class="badge bg-primary px-3 py-2 rounded-pill shadow-sm flex-shrink-0">
                    <i class="fas fa-clock me-1"></i> Live Ranking
                </div>
            </div>

            <!-- Scoring Overview -->
            <div class="alert alert-primary border-0 shadow-sm mb-4"
                style="border-radius: 15px; background: rgba(237, 233, 254, 0.5); backdrop-filter: blur(5px);">
                <div class="row text-center g-2 g-md-0">
                    <div class="col-6 col-md-3 border-end">
                        <small class="text-muted d-block uppercase fs-xs">Batting</small>
                        <span class="fw-bold">Runs, Bonuses & SR</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <small class="text-muted d-block uppercase fs-xs">Bowling</small>
                        <span class="fw-bold">Wkts & Econ</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <small class="text-muted d-block uppercase fs-xs">Fielding</small>
                        <span class="fw-bold">Catch, RO & Stumps</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block uppercase fs-xs">Bonus</small>
                        <span class="fw-bold">Wins & Consistency</span>
                    </div>
                </div>
            </div>

            <!-- Rankings Table -->
            <div class="card border-0 shadow-lg"
                style="border-radius: 20px; background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.5);">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr
                                    style="background-color: #ffffff; color: #000000; border-bottom: 2px solid #e2e8f0;">
                                    <th class="ps-4 py-3 text-center"
                                        style="width: 80px; border-top-left-radius: 20px;">Rank</th>
                                    <th class="py-3">Player</th>
                                    <th class="py-3 text-center">Batting (R/B)</th>
                                    <th class="py-3 text-center">Bowling (W/O)</th>
                                    <th class="py-3 text-center">SR/Econ</th>
                                    <th class="pe-4 py-3 text-center" style="border-top-right-radius: 20px;">POS Points
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rankings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <i class="fas fa-medal fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No series data available</h5>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rankings as $index => $row):
                                        $rank = $index + 1;
                                        $sr = $row['total_balls'] > 0 ? ($row['total_runs'] / $row['total_balls']) * 100 : 0;
                                        $display_overs = floor($row['total_balls_bowled'] / 6) . '.' . ($row['total_balls_bowled'] % 6);
                                        $econ = $row['total_balls_bowled'] > 0 ? ($row['total_runs_conceded'] * 6) / $row['total_balls_bowled'] : 0;

                                        $badge_class = 'bg-light text-dark';
                                        if ($rank == 1)
                                            $badge_class = 'bg-warning text-dark';
                                        elseif ($rank == 2)
                                            $badge_class = 'bg-secondary text-white';
                                        elseif ($rank == 3)
                                            $badge_class = 'bg-bronze text-white';

                                        // Prepare data for modal
                                        $playerModalData = htmlspecialchars(json_encode([
                                            'id' => $row['player_id'],
                                            'name' => $row['name'],
                                            'team' => $row['team'] ?: 'No Team',
                                            'image' => $row['image'] ? '../uploads/users/' . $row['image'] : '',
                                            'matches' => $row['career_matches'],
                                            'runs' => $row['career_runs'],
                                            'wickets' => $row['career_wickets']
                                        ]), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr class="ranking-row <?= (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $row['player_id']) ? 'highlighted-row' : '' ?>"
                                            style="cursor: pointer; animation: fadeInUp 0.5s ease both; animation-delay: <?= $index * 0.05 ?>s;"
                                            onclick="openPlayerCard(<?= $playerModalData ?>)">
                                            <td class="text-center ps-4">
                                                <span
                                                    class="badge rounded-circle d-inline-flex align-items-center justify-content-center <?= $badge_class ?>"
                                                    style="width: 35px; height: 35px; font-weight: 800; border: 1px solid rgba(0,0,0,0.05);">
                                                    <?= $rank ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <img src="<?= $row['image'] ? '../uploads/users/' . htmlspecialchars($row['image']) : '../assets/images/default_player.png' ?>"
                                                        alt="<?= htmlspecialchars($row['name']) ?>" class="rounded-circle me-3"
                                                        style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                    <div>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['name']) ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?= htmlspecialchars($row['team'] ?: 'No Team') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-dark"><?= $row['total_runs'] ?></span>
                                                <small class="text-muted">/<?= $row['total_balls'] ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-dark"><?= $row['total_wickets'] ?></span>
                                                <small class="text-muted">/<?= $display_overs ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="small fw-bold text-primary"><?= number_format($sr, 1) ?> SR</div>
                                                <div class="small text-muted"><?= number_format($econ, 2) ?> Econ</div>
                                            </td>
                                            <td class="text-center pe-4">
                                                <span class="badge bg-success rounded-pill px-3 py-2 fs-6">
                                                    <?= number_format($row['score'], 0) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Player Card Overlay -->
<div id="playerCardOverlay" class="player-card-overlay d-none">
    <div class="player-card">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3"
            onclick="closePlayerCard()" aria-label="Close"></button>

        <div class="text-center mb-3">
            <img id="cardPlayerImage" src="" alt="Player" class="rounded-3 shadow-lg mb-3"
                style="width: 200px; height: 200px; object-fit: cover; border: 4px solid rgba(255,255,255,0.2);">
            <h3 id="cardPlayerName" class="fw-bold text-white mb-1"></h3>
            <span id="cardTeamName" class="badge bg-white text-dark px-3 py-2 rounded-pill mt-2"></span>
        </div>

        <div class="row g-3 text-center mb-4">
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase" style="font-size: 0.75rem;">Matches</div>
                    <div id="cardMatches" class="h4 text-white mb-0 fw-bold"></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase" style="font-size: 0.75rem;">Runs</div>
                    <div id="cardRuns" class="h4 text-warning mb-0 fw-bold"></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase" style="font-size: 0.75rem;">Wickets</div>
                    <div id="cardWickets" class="h4 text-info mb-0 fw-bold"></div>
                </div>
            </div>
        </div>

        <a id="cardViewStatsBtn" href="#" class="btn btn-light w-100 py-3 fw-bold rounded-pill">
            <i class="fas fa-chart-bar me-2"></i>View Full Stats
        </a>
    </div>
</div>

<script>
    function openPlayerCard(data) {
        document.getElementById('cardPlayerName').textContent = data.name;
        document.getElementById('cardTeamName').textContent = data.team;
        document.getElementById('cardMatches').textContent = data.matches;
        document.getElementById('cardRuns').textContent = data.runs;
        document.getElementById('cardWickets').textContent = data.wickets;

        // Set Image
        const img = document.getElementById('cardPlayerImage');
        if (data.image) {
            img.src = data.image;
        } else {
            img.src = '../assets/images/default-player.png';
        }

        // Set Link
        document.getElementById('cardViewStatsBtn').href = '../view/view_player_profile.php?player_id=' + data.id;

        // Show Overlay
        document.getElementById('playerCardOverlay').classList.remove('d-none');
        document.body.style.overflow = 'hidden'; // Prevent scrolling
    }

    function closePlayerCard() {
        document.getElementById('playerCardOverlay').classList.add('d-none');
        document.body.style.overflow = 'auto'; // Restore scrolling
    }

    // Close on click outside
    document.getElementById('playerCardOverlay').addEventListener('click', function (e) {
        if (e.target === this) {
            closePlayerCard();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closePlayerCard();
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
