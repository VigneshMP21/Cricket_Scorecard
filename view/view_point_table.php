<?php
require_once '../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: ../NavBarList/point_tables.php");
    exit();
}

$table_id = (int) $_GET['id'];

try {
    // Get point table info
    $stmt = $pdo->prepare("
        SELECT pt.*, t.tournament_name,
               (SELECT COUNT(*) FROM matches m 
                WHERE m.status = 'completed'
                AND m.team1_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id)
                AND m.team2_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id)
               ) as match_count
        FROM point_tables pt
        LEFT JOIN tournaments t ON pt.tournament_id = t.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$table_id]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        header("Location: ../NavBarList/point_tables.php");
        exit();
    }

    $page_title = "Point Table - " . htmlspecialchars($table['table_name']);
    require_once '../includes/header.php';

    // Get teams in this point table
    $stmt = $pdo->prepare("
        SELECT t.id, t.team_name, t.team_code, t.team_logo
        FROM point_table_entries pte
        JOIN teams t ON pte.team_id = t.id
        WHERE pte.point_table_id = ?
        ORDER BY t.team_name
    ");
    $stmt->execute([$table_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $table_data = [];
    foreach ($teams as $team) {
        // Fetch all matches for this team (completed OR scheduled)
        $stmt = $pdo->prepare("
            SELECT m.id, m.team1_id, m.team2_id, m.winner_id, m.result, m.match_date, m.match_time, m.match_type, m.status,
                   t1.team_name as t1_name, t1.team_logo as t1_logo,
                   t2.team_name as t2_name, t2.team_logo as t2_logo
            FROM matches m 
            JOIN teams t1 ON m.team1_id = t1.id
            JOIN teams t2 ON m.team2_id = t2.id
            WHERE (
                (m.tournament_id = ?) OR 
                (m.team1_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = ?) 
                 AND m.team2_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = ?))
            )
            AND (m.team1_id = ? OR m.team2_id = ?)
            ORDER BY m.match_date ASC, m.match_time ASC
        ");
        $stmt->execute([$table['tournament_id'], $table_id, $table_id, $team['id'], $team['id']]);
        $team_matches_all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matches_played = 0;
        $won = 0;
        $lost = 0;
        $draw = 0;
        $nr = 0;
        $detailed_history = [];

        foreach ($team_matches_all as $tm) {
            // Stats calculation (only for completed matches)
            if ($tm['status'] == 'completed') {
                $matches_played++;
                if ($tm['result'] == 'tie' || $tm['result'] == 'draw') {
                    $draw++;
                } elseif ($tm['winner_id'] == $team['id']) {
                    $won++;
                } elseif ($tm['winner_id'] !== null) {
                    $lost++;
                } else {
                    $nr++;
                }
            }

            // Prepare detailed history for dropdown
            $is_team1 = ($tm['team1_id'] == $team['id']);
            $opponent_name = $is_team1 ? $tm['t2_name'] : $tm['t1_name'];
            $opponent_logo = $is_team1 ? $tm['t2_logo'] : $tm['t1_logo'];

            // Format result text and color
            $result_text = "Match Not Started";
            $result_color = "text-muted";

            if ($tm['status'] == 'completed') {
                if ($tm['winner_id'] == $team['id'] || ($tm['winner_id'] !== null)) {
                    // Fetch innings to calculate margin
                    $stmt_margin = $pdo->prepare("SELECT batting_team_id, total_runs, wickets FROM innings WHERE match_id = ? ORDER BY inning_number ASC");
                    $stmt_margin->execute([$tm['id']]);
                    $margin_data = $stmt_margin->fetchAll(PDO::FETCH_ASSOC);

                    $margin_text = "";
                    if (count($margin_data) > 2) {
                        $margin_text = "by Super over";
                    } elseif (count($margin_data) == 2) {
                        $inn1 = $margin_data[0];
                        $inn2 = $margin_data[1];

                        if ($tm['winner_id'] == $inn1['batting_team_id']) {
                            $margin = $inn1['total_runs'] - $inn2['total_runs'];
                            $margin_text = "by $margin runs";
                        } else {
                            $wickets_left = 10 - $inn2['wickets'];
                            $margin_text = "by $wickets_left wickets";
                        }
                    }

                    if ($tm['winner_id'] == $team['id']) {
                        $result_text = "Won " . $margin_text;
                        $result_color = "text-success";
                    } else {
                        $result_text = "Lost";
                        $result_color = "text-danger";
                    }
                } elseif ($tm['result'] == 'tie' || $tm['result'] == 'draw' || $tm['result'] == 'no result') {
                    $result_text = ucfirst($tm['result'] ?: 'No Result');
                    $result_color = "text-info";
                }
            }

            $detailed_history[] = [
                'opponent_name' => $opponent_name,
                'opponent_logo' => $opponent_logo,
                'match_type' => $tm['match_type'] ?: 'League',
                'match_date' => $tm['match_date'],
                'match_time' => $tm['match_time'],
                'result_text' => $result_text,
                'result_color' => $result_color,
                'status' => $tm['status'],
                'match_id' => $tm['id']
            ];
        }

        $points = ($won * ($table['win_points'] ?? 2)) +
            ($draw * ($table['draw_points'] ?? 1)) +
            ($lost * ($table['loss_points'] ?? 0)) +
            ($nr * ($table['nr_points'] ?? 1));


        // NRR Calculation (only for completed matches)
        $total_runs_scored = 0;
        $total_overs_faced = 0;
        $total_runs_conceded = 0;
        $total_overs_bowled = 0;

        $completed_match_ids = array_filter(array_column($team_matches_all, 'id'), function ($id) use ($team_matches_all) {
            foreach ($team_matches_all as $m)
                if ($m['id'] == $id && $m['status'] == 'completed')
                    return true;
            return false;
        });

        if (!empty($completed_match_ids)) {
            $placeholders = str_repeat('?,', count($completed_match_ids) - 1) . '?';
            $stmt_innings = $pdo->prepare("
                SELECT i.batting_team_id, i.total_runs, i.overs_bowled, i.wickets, m.overs as max_overs
                FROM innings i
                JOIN matches m ON i.match_id = m.id
                WHERE m.id IN ($placeholders)
                AND (i.batting_team_id = ? OR i.bowling_team_id = ?)
            ");
            $params = array_merge(array_values($completed_match_ids), [$team['id'], $team['id']]);
            $stmt_innings->execute($params);
            $innings_data = $stmt_innings->fetchAll(PDO::FETCH_ASSOC);

            foreach ($innings_data as $inning) {
                if ($inning['batting_team_id'] == $team['id']) {
                    $total_runs_scored += $inning['total_runs'];
                    if ($inning['wickets'] >= 10) {
                        $total_overs_faced += $inning['max_overs'];
                    } else {
                        $overs = (float) $inning['overs_bowled'];
                        $balls = (floor($overs) * 6) + (($overs * 10) % 10);
                        $total_overs_faced += $balls / 6;
                    }
                } else {
                    $total_runs_conceded += $inning['total_runs'];
                    if ($inning['wickets'] >= 10) {
                        $total_overs_bowled += $inning['max_overs'];
                    } else {
                        $overs = (float) $inning['overs_bowled'];
                        $balls = (floor($overs) * 6) + (($overs * 10) % 10);
                        $total_overs_bowled += $balls / 6;
                    }
                }
            }
        }

        $nrr = 0.000;
        if ($total_overs_faced > 0 && $total_overs_bowled > 0) {
            $run_rate_for = $total_runs_scored / $total_overs_faced;
            $run_rate_against = $total_runs_conceded / $total_overs_bowled;
            $nrr = $run_rate_for - $run_rate_against;
        }

        $table_data[] = [
            'team_id' => $team['id'],
            'team_name' => $team['team_name'],
            'team_code' => $team['team_code'],
            'team_logo' => $team['team_logo'],
            'played' => $matches_played,
            'won' => $won,
            'lost' => $lost,
            'draw' => $draw,
            'nr' => $nr,
            'points' => $points,
            'nrr' => $nrr,
            'history' => $detailed_history
        ];

    }

    // Sorting: Points DESC, Wins DESC, NRR DESC, Name ASC
    usort($table_data, function ($a, $b) {
        if ($b['points'] != $a['points'])
            return $b['points'] - $a['points'];
        if ($b['won'] != $a['won'])
            return $b['won'] - $a['won'];
        if ($b['nrr'] != $a['nrr'])
            return $b['nrr'] > $a['nrr'] ? 1 : -1;
        return strcmp($a['team_name'], $b['team_name']);
    });

    $qualify_count = $table['qualify_count'] ?? 4;
    ?>

<style>
        .letter-spacing-1 {
            letter-spacing: 1px;
        }

        .pos-circle {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            position: relative;
            font-size: 0.9rem;
            margin: 0 auto;
        }

        .q-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #198754;
            color: white;
            font-size: 0.5rem;
            width: 14px;
            height: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 1px solid white;
        }

        .team-logo-container img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            background: #fff;
            padding: 2px;
            border: 1px solid #eee;
        }

        .custom-points-table thead th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            padding-top: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eef2f7;
        }

        .custom-points-table tbody tr {
            transition: all 0.2s ease;
        }

        .custom-points-table tbody tr.team-row:hover {
            background-color: #f8fafc;
        }

        .qualifying-row {
            background-color: rgba(25, 135, 84, 0.03);
        }

        .custom-points-table td {
            padding-top: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
        }

        .stats-scroll-container::-webkit-scrollbar {
            display: none;
        }

        .stats-scroll-container {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* History Dropdown Styling */
        .history-row {
            background-color: #f8fafc !important;
        }

        .history-content {
            border-left: 4px solid #0d6efd;
            overflow: hidden;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .history-inner-table {
            background: white;
        }

        .history-inner-table th {
            font-weight: 600;
            color: #64748b;
            border: none !important;
        }

        .history-inner-table td {
            border-bottom: 1px solid #f1f5f9 !important;
            vertical-align: middle;
        }

        .history-item-row:hover {
            background-color: #f1f5f9;
        }

        .dropdown-icon {
            transition: transform 0.3s ease;
        }

        .team-row.active .dropdown-icon {
            transform: rotate(180deg);
            color: #0d6efd !important;
        }

        @media (max-width: 768px) {

            .container,
            .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .card {
                margin-left: 0 !important;
                margin-right: 0 !important;
                border-radius: 0 !important;
            }

            .team-logo-container img {
                width: 35px;
                height: 35px;
            }
        }

        /* ── Mobile Points Table (≤460px) – card layout ── */
        @media (max-width: 460px) {

            /* Hide the full desktop table */
            .pt-table-desktop {
                display: none !important;
            }

            /* Show mobile card list */
            .pt-mobile-list {
                display: block !important;
            }

            /* Hide desktop history table, show mobile history */
            .hist-table-desktop {
                display: none !important;
            }
            .hist-mobile-list {
                display: block !important;
            }
        }

        /* Hidden by default (shown only on ≤460px) */
        .pt-mobile-list  { display: none; }
        .hist-mobile-list { display: none; }

        /* ── Mobile standings header ── */
        .pt-mob-header {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: #1e293b;
            color: #94a3b8;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .pt-mob-header .pt-team-col { flex: 1; min-width: 0; }
        .pt-mob-header .pt-stat     { width: 34px; text-align: center; flex-shrink: 0; }
        .pt-mob-header .pt-stat-wide { width: 46px; text-align: center; flex-shrink: 0; }

        /* ── Mobile standings row ── */
        .pt-mob-row {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
        }
        .pt-mob-row:hover { background: #f8fafc; }
        .pt-mob-row.qualifying { background: rgba(25,135,84,0.04); }

        .pt-mob-row .pt-team-col {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-right: 4px;
        }
        .pt-mob-row .pt-pos {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .pt-mob-row .pt-logo {
            width: 30px;
            height: 30px;
            object-fit: contain;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .pt-mob-row .pt-team-info { min-width: 0; }
        .pt-mob-row .pt-team-name {
            font-weight: 700;
            font-size: 0.82rem;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pt-mob-row .pt-team-code {
            font-size: 0.62rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pt-mob-row .pt-stat {
            width: 34px;
            text-align: center;
            flex-shrink: 0;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .pt-mob-row .pt-stat-wide {
            width: 46px;
            text-align: center;
            flex-shrink: 0;
            font-size: 0.78rem;
            font-weight: 700;
        }

        /* ── Mobile history card ── */
        .hist-mob-card {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            gap: 10px;
            background: white;
        }
        .hist-mob-card:hover { background: #f8fafc; }
        .hist-mob-card .hist-logo {
            width: 28px;
            height: 28px;
            object-fit: contain;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .hist-mob-card .hist-info { flex: 1; min-width: 0; }
        .hist-mob-card .hist-opp {
            font-weight: 700;
            font-size: 0.82rem;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .hist-mob-card .hist-meta {
            font-size: 0.68rem;
            color: #94a3b8;
        }
        .hist-mob-card .hist-result {
            font-size: 0.78rem;
            font-weight: 700;
            text-align: right;
            flex-shrink: 0;
            white-space: nowrap;
        }
    </style>

    <div class="container-fluid pb-4"
        style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); min-height: calc(100vh - 76px);">
        <div class="container px-0 px-md-3">

            <!-- Unified Points Table Section -->
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-5">
                <!-- Header Part -->
                <div class="card-body p-3 p-md-4 pb-0 pb-md-0 bg-white border-bottom">
                    <!-- Row 1: Back Button -->
                    <div class="mb-2">
                        <a href="../NavBarList/point_tables.php"
                            class="btn btn-light rounded-pill shadow-sm px-3 py-1 fw-bold" style="font-size: 0.8rem;">
                            <i class="fas fa-arrow-left me-1 text-primary"></i>Back to point table
                        </a>
                    </div>

                    <!-- Row 2: Table Name and Point System -->
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                        <div class="overflow-hidden">
                            <h2 class="fw-bold text-dark mb-0 text-truncate" style="font-size: clamp(1.1rem, 4vw, 1.8rem);">
                                <?= htmlspecialchars($table['table_name']) ?>
                            </h2>
                            <?php if ($table['tournament_name']): ?>
                                <p class="text-muted mb-0 text-truncate" style="font-size: clamp(0.7rem, 2.5vw, 0.85rem);">
                                    <i
                                        class="fas fa-trophy me-1 text-warning"></i><?= htmlspecialchars($table['tournament_name']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="flex-shrink-0">
                            <div
                                class="point-system-pill d-inline-flex flex-row align-items-center px-3 py-1 py-md-2 rounded-pill bg-primary text-white shadow-sm">
                                <div class="me-2 me-md-3 text-center">
                                    <span class="fw-bold fs-4 fs-md-2"><?= $table['win_points'] ?? 2 ?></span>
                                </div>
                                <div class="text-start border-start border-white border-opacity-25 ps-2 ps-md-3">
                                    <small class="text-uppercase d-block"
                                        style="font-size: 0.5rem; opacity: 0.8; line-height: 1;">Points</small>
                                    <small class="text-uppercase fw-bold"
                                        style="font-size: 0.6rem; letter-spacing: 0.5px;">Per Win</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 3: Stats Details -->
                    <div class="d-flex gap-2 gap-md-3 overflow-x-auto pb-1 stats-scroll-container">
                        <div class="stats-badge px-3 py-2 rounded-3 bg-light border flex-shrink-0">
                            <small class="text-muted d-block text-uppercase fw-bold letter-spacing-1"
                                style="font-size: 0.65rem;">Total Teams</small>
                            <span class="fw-bold text-dark"><?= count($teams) ?></span>
                        </div>
                        <div class="stats-badge px-3 py-2 rounded-3 bg-light border flex-shrink-0">
                            <small class="text-muted d-block text-uppercase fw-bold letter-spacing-1"
                                style="font-size: 0.65rem;">Last Updated</small>
                            <span class="fw-bold text-dark"><?= date('d M Y', strtotime($table['updated_at'])) ?></span>
                        </div>
                        <?php if ($table['match_count'] > 0): ?>
                            <div class="stats-badge px-3 py-2 rounded-3 bg-light border flex-shrink-0">
                                <small class="text-muted d-block text-uppercase fw-bold letter-spacing-1"
                                    style="font-size: 0.65rem;">Matches Played</small>
                                <span class="fw-bold text-dark"><?= $table['match_count'] ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Standings Part -->
                <div class="card-body p-0">
                    <div class="bg-dark text-white py-3 px-4 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold" style="font-size: 1rem;"><i
                                class="fas fa-list-ol me-2 text-warning"></i>League Standings</h5>
                        <div class="d-none d-md-block">
                            <small class="opacity-75">Sorted by: Points > Wins > NRR</small>
                        </div>
                    </div>
                    <!-- ═══ Desktop Standings Table (hidden on ≤460px) ═══ -->
                    <div class="table-responsive pt-table-desktop">
                        <table class="table table-hover align-middle mb-0 custom-points-table">
                            <thead>
                                <tr class="bg-light text-secondary">
                                    <th width="80" class="ps-4 text-center">POS</th>
                                    <th>TEAM</th>
                                    <th width="70" class="text-center">P</th>
                                    <th width="70" class="text-center">W</th>
                                    <th width="70" class="text-center">L</th>
                                    <th width="70" class="text-center">D</th>
                                    <th width="70" class="text-center">N/R</th>
                                    <th width="100" class="text-center">PTS</th>
                                    <th width="120" class="text-center pe-4">NRR</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($table_data)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5">
                                            <div class="text-muted">No teams or match data available for this table.</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($table_data as $index => $row):
                                        $is_qualifying = ($index < $qualify_count);
                                        $nrr_formatted = ($row['nrr'] >= 0 ? '+' : '') . number_format($row['nrr'], 3);
                                        ?>
                                        <tr class="<?= $is_qualifying ? 'qualifying-row' : '' ?> team-row"
                                            data-team-id="<?= $row['team_id'] ?>" style="cursor: pointer;">
                                            <td class="ps-4 text-center fw-bold">
                                                <div
                                                    class="pos-circle <?= $is_qualifying ? 'bg-success text-white' : 'bg-light text-dark border' ?>">
                                                    <?= $index + 1 ?>
                                                    <?php if ($is_qualifying): ?>
                                                        <span class="q-badge" title="Qualified">Q</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="team-logo-container me-3">
                                                        <img src="<?= $row['team_logo'] ? '../uploads/teams/' . $row['team_logo'] : '../assets/images/default-team.png' ?>"
                                                            alt="<?= htmlspecialchars($row['team_name']) ?>"
                                                            class="rounded-circle shadow-sm">
                                                    </div>
                                                    <div class="overflow-hidden">
                                                        <div class="fw-bold mb-0 text-dark text-truncate" style="max-width: 150px;">
                                                            <?= htmlspecialchars($row['team_name']) ?>
                                                        </div>
                                                        <small class="text-muted text-uppercase letter-spacing-1"
                                                            style="font-size: 0.65rem;"><?= htmlspecialchars($row['team_code']) ?></small>
                                                    </div>
                                                    <i class="fas fa-chevron-down ms-auto text-muted dropdown-icon"
                                                        style="font-size: 0.7rem;"></i>
                                                </div>
                                            </td>
                                            <td class="text-center fw-medium"><?= $row['played'] ?></td>
                                            <td class="text-center text-success fw-medium"><?= $row['won'] ?></td>
                                            <td class="text-center text-danger fw-medium"><?= $row['lost'] ?></td>
                                            <td class="text-center text-warning fw-medium"><?= $row['draw'] ?></td>
                                            <td class="text-center text-info fw-medium"><?= $row['nr'] ?></td>
                                            <td class="text-center fw-bold text-dark"><?= $row['points'] ?></td>
                                            <td
                                                class="text-center pe-4 fw-bold <?= $row['nrr'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= $nrr_formatted ?>
                                            </td>
                                        </tr>
                                        <!-- Match History Dropdown (Desktop) -->
                                        <tr class="history-row" id="history-<?= $row['team_id'] ?>" style="display: none;">
                                            <td colspan="9" class="p-0 border-0">
                                                <div class="history-content bg-light">
                                                    <?php if (empty($row['history'])): ?>
                                                        <div class="p-4 text-center text-muted small">No match history available.</div>
                                                    <?php else: ?>
                                                        <div class="table-responsive hist-table-desktop">
                                                            <table class="table table-sm mb-0 history-inner-table">
                                                                <thead class="bg-secondary bg-opacity-10">
                                                                    <tr>
                                                                        <th class="ps-4 py-2" style="font-size: 0.7rem;">OPPONENT</th>
                                                                        <th class="py-2 text-center" style="font-size: 0.7rem;">MATCH TYPE</th>
                                                                        <th class="py-2 text-center" style="font-size: 0.7rem;">DATE &amp; TIME</th>
                                                                        <th class="pe-4 py-2 text-end" style="font-size: 0.7rem;">RESULT</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($row['history'] as $match):
                                                                        $nav_url = ($match['status'] == 'completed') ? "../view_match_summary.php?id=" . $match['match_id'] : "view_match.php?id=" . $match['match_id'];
                                                                        ?>
                                                                        <tr onclick="window.location.href='<?= $nav_url ?>'"
                                                                            style="cursor: pointer;" class="history-item-row">
                                                                            <td class="ps-4 py-3">
                                                                                <div class="d-flex align-items-center">
                                                                                    <img src="<?= $match['opponent_logo'] ? '../uploads/teams/' . $match['opponent_logo'] : '../assets/images/default-team.png' ?>"
                                                                                        class="rounded-circle me-2"
                                                                                        style="width: 24px; height: 24px; object-fit: contain;">
                                                                                    <span class="fw-bold text-dark small"><?= htmlspecialchars($match['opponent_name']) ?></span>
                                                                                </div>
                                                                            </td>
                                                                            <td class="py-3 text-center">
                                                                                <span class="badge bg-white text-dark border fw-normal py-1 px-2" style="font-size: 0.65rem;">
                                                                                    <?= htmlspecialchars($match['match_type']) ?>
                                                                                </span>
                                                                            </td>
                                                                            <td class="py-3 text-center">
                                                                                <div class="small fw-bold text-dark" style="line-height: 1.2;">
                                                                                    <?= date('M d', strtotime($match['match_date'])) ?>
                                                                                </div>
                                                                                <div class="text-muted" style="font-size: 0.65rem;">
                                                                                    <?= date('H:i', strtotime($match['match_time'])) ?>
                                                                                </div>
                                                                            </td>
                                                                            <td class="pe-4 py-3 text-end">
                                                                                <span class="fw-bold small <?= $match['result_color'] ?>">
                                                                                    <?= htmlspecialchars($match['result_text']) ?>
                                                                                </span>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <!-- Match History Mobile Cards (≤460px) -->
                                                        <div class="hist-mobile-list">
                                                            <?php foreach ($row['history'] as $match):
                                                                $nav_url = ($match['status'] == 'completed') ? "../view_match_summary.php?id=" . $match['match_id'] : "view_match.php?id=" . $match['match_id'];
                                                            ?>
                                                            <div class="hist-mob-card" onclick="window.location.href='<?= $nav_url ?>'">
                                                                <img src="<?= $match['opponent_logo'] ? '../uploads/teams/' . $match['opponent_logo'] : '../assets/images/default-team.png' ?>" class="hist-logo">
                                                                <div class="hist-info">
                                                                    <div class="hist-opp"><?= htmlspecialchars($match['opponent_name']) ?></div>
                                                                    <div class="hist-meta"><?= date('M d', strtotime($match['match_date'])) ?> &bull; <?= htmlspecialchars($match['match_type']) ?></div>
                                                                </div>
                                                                <div class="hist-result <?= $match['result_color'] ?>"><?= htmlspecialchars($match['result_text']) ?></div>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ═══ Mobile Standings Cards (≤460px) ═══ -->
                    <div class="pt-mobile-list">
                        <!-- Header row -->
                        <div class="pt-mob-header">
                            <div class="pt-team-col">Team</div>
                            <div class="pt-stat">P</div>
                            <div class="pt-stat">W</div>
                            <div class="pt-stat">L</div>
                            <div class="pt-stat-wide">PTS</div>
                            <div class="pt-stat-wide">NRR</div>
                        </div>
                        <?php if (empty($table_data)): ?>
                            <div class="p-4 text-center text-muted small">No teams or match data available.</div>
                        <?php else: ?>
                            <?php foreach ($table_data as $index => $row):
                                $is_qualifying = ($index < $qualify_count);
                                $nrr_formatted = ($row['nrr'] >= 0 ? '+' : '') . number_format($row['nrr'], 3);
                            ?>
                            <!-- Team standing row (mobile) -->
                            <div class="pt-mob-row <?= $is_qualifying ? 'qualifying' : '' ?> mob-team-row"
                                data-team-id="<?= $row['team_id'] ?>">
                                <div class="pt-team-col">
                                    <div class="pt-pos <?= $is_qualifying ? 'bg-success text-white' : 'bg-light text-dark border' ?>">
                                        <?= $index + 1 ?>
                                    </div>
                                    <img src="<?= $row['team_logo'] ? '../uploads/teams/' . $row['team_logo'] : '../assets/images/default-team.png' ?>" class="pt-logo">
                                    <div class="pt-team-info">
                                        <div class="pt-team-name"><?= htmlspecialchars($row['team_name']) ?></div>
                                        <div class="pt-team-code"><?= htmlspecialchars($row['team_code']) ?></div>
                                    </div>
                                    <i class="fas fa-chevron-down ms-auto text-muted mob-dropdown-icon" style="font-size: 0.65rem;"></i>
                                </div>
                                <div class="pt-stat text-muted"><?= $row['played'] ?></div>
                                <div class="pt-stat text-success"><?= $row['won'] ?></div>
                                <div class="pt-stat text-danger"><?= $row['lost'] ?></div>
                                <div class="pt-stat-wide fw-bold text-dark" style="font-size:0.9rem;"><?= $row['points'] ?></div>
                                <div class="pt-stat-wide <?= $row['nrr'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:0.72rem;"><?= $nrr_formatted ?></div>
                            </div>
                            <!-- Match history dropdown (mobile) -->
                            <div class="mob-history-panel" id="mob-history-<?= $row['team_id'] ?>" style="display:none;">
                                <div class="history-content">
                                    <?php if (empty($row['history'])): ?>
                                        <div class="p-3 text-center text-muted small">No match history available.</div>
                                    <?php else: ?>
                                        <?php foreach ($row['history'] as $match):
                                            $nav_url = ($match['status'] == 'completed') ? "../view_match_summary.php?id=" . $match['match_id'] : "view_match.php?id=" . $match['match_id'];
                                        ?>
                                        <div class="hist-mob-card" onclick="window.location.href='<?= $nav_url ?>'">
                                            <img src="<?= $match['opponent_logo'] ? '../uploads/teams/' . $match['opponent_logo'] : '../assets/images/default-team.png' ?>" class="hist-logo">
                                            <div class="hist-info">
                                                <div class="hist-opp"><?= htmlspecialchars($match['opponent_name']) ?></div>
                                                <div class="hist-meta"><?= date('M d', strtotime($match['match_date'])) ?> &bull; <?= htmlspecialchars($match['match_type']) ?></div>
                                            </div>
                                            <div class="hist-result <?= $match['result_color'] ?>"><?= htmlspecialchars($match['result_text']) ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-light py-3 px-4 border-top">
                    <div class="d-flex align-items-center">
                        <div class="pos-circle bg-success text-white small me-2"
                            style="width: 20px; height: 20px; font-size: 0.6rem;">Q</div>
                        <small class="text-muted">Teams in highlighting rows are currently in qualification
                            positions.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Desktop table rows
            const teamRows = document.querySelectorAll('.team-row');
            teamRows.forEach(row => {
                row.addEventListener('click', function () {
                    const teamId = this.getAttribute('data-team-id');
                    const historyRow = document.getElementById('history-' + teamId);
                    if (historyRow.style.display === 'none') {
                        historyRow.style.display = 'table-row';
                        this.classList.add('active');
                    } else {
                        historyRow.style.display = 'none';
                        this.classList.remove('active');
                    }
                });
            });

            // Mobile card rows
            const mobRows = document.querySelectorAll('.mob-team-row');
            mobRows.forEach(row => {
                row.addEventListener('click', function () {
                    const teamId = this.getAttribute('data-team-id');
                    const panel = document.getElementById('mob-history-' + teamId);
                    const icon  = this.querySelector('.mob-dropdown-icon');
                    if (panel.style.display === 'none') {
                        panel.style.display = 'block';
                        if (icon) { icon.style.transform = 'rotate(180deg)'; icon.style.color = '#0d6efd'; }
                    } else {
                        panel.style.display = 'none';
                        if (icon) { icon.style.transform = 'rotate(0deg)'; icon.style.color = ''; }
                    }
                });
            });
        });
    </script>

    <?php
    require_once '../includes/footer.php';

} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require_once '../includes/footer.php';
}
?>