<?php
require_once '../includes/db.php';
// Public access - no login required

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="alert alert-danger">Invalid table ID.</div>';
    exit();
}

$table_id = (int) $_GET['id'];

try {
    // Get point table info
    $stmt = $pdo->prepare("
        SELECT pt.*, t.tournament_name
        FROM point_tables pt
        LEFT JOIN tournaments t ON pt.tournament_id = t.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$table_id]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        echo '<div class="alert alert-danger">Point table not found.</div>';
        exit();
    }

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

    ?>
    <div class="table-info mb-4">
        <div class="row">
            <div class="col-md-6">
                <h6>Table Information</h6>
                <p><strong>Name:</strong> <?= htmlspecialchars($table['table_name']) ?></p>
                <?php if ($table['tournament_name']): ?>
                    <p><strong>Tournament:</strong> <?= htmlspecialchars($table['tournament_name']) ?></p>
                <?php endif; ?>
                <p><strong>Created:</strong> <?= date('d M Y H:i', strtotime($table['created_at'])) ?></p>
            </div>
            <div class="col-md-6">
                <h6>Point System</h6>
                <div class="row text-center">
                    <div class="col-3">
                        <div class="point-value fw-bold text-success"><?= $table['win_points'] ?? 2 ?></div>
                        <small class="text-muted">Win</small>
                    </div>
                    <div class="col-3">
                        <div class="point-value fw-bold text-warning"><?= $table['draw_points'] ?? 1 ?></div>
                        <small class="text-muted">Draw</small>
                    </div>
                    <div class="col-3">
                        <div class="point-value fw-bold text-danger"><?= $table['loss_points'] ?? 0 ?></div>
                        <small class="text-muted">Loss</small>
                    </div>
                    <div class="col-3">
                        <div class="point-value fw-bold text-info"><?= $table['nr_points'] ?? 1 ?></div>
                        <small class="text-muted">N/R</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h6>Teams (<?= count($teams) ?>)</h6>
    <?php if (empty($teams)): ?>
        <div class="alert alert-info">No teams assigned to this point table.</div>
    <?php else: ?>
        <?php
        $table_data = [];
        foreach ($teams as $team) {
            // Fetch all completed matches for this team
            $stmt = $pdo->prepare("
                SELECT m.id, m.team1_id, m.team2_id, m.winner_id, m.result 
                FROM matches m 
                WHERE m.status = 'completed' 
                AND (
                    (m.tournament_id = ?) OR 
                    (m.team1_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = ?) 
                     AND m.team2_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = ?))
                )
                AND (m.team1_id = ? OR m.team2_id = ?)
            ");
            $stmt->execute([$table['tournament_id'], $table_id, $table_id, $team['id'], $team['id']]);
            $team_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $matches_played = count($team_matches);
            $won = 0;
            $lost = 0;
            $draw = 0;
            $nr = 0;

            foreach ($team_matches as $tm) {
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

            $points = ($won * ($table['win_points'] ?? 2)) +
                ($draw * ($table['draw_points'] ?? 1)) +
                ($lost * ($table['loss_points'] ?? 0)) +
                ($nr * ($table['nr_points'] ?? 1));

            // NRR Calculation
            $total_runs_scored = 0;
            $total_overs_faced = 0;
            $total_runs_conceded = 0;
            $total_overs_bowled = 0;

            if (!empty($team_matches)) {
                $match_ids = array_column($team_matches, 'id');
                $placeholders = str_repeat('?,', count($match_ids) - 1) . '?';
                $stmt_innings = $pdo->prepare("
                    SELECT i.batting_team_id, i.total_runs, i.overs_bowled, i.wickets, m.overs as max_overs
                    FROM innings i
                    JOIN matches m ON i.match_id = m.id
                    WHERE m.id IN ($placeholders)
                    AND (i.batting_team_id = ? OR i.bowling_team_id = ?)
                ");
                $params = array_merge($match_ids, [$team['id'], $team['id']]);
                $stmt_innings->execute($params);
                $innings_data = $stmt_innings->fetchAll(PDO::FETCH_ASSOC);

                foreach ($innings_data as $inning) {
                    if ($inning['batting_team_id'] == $team['id']) {
                        $total_runs_scored += $inning['total_runs'];
                        if ($inning['wickets'] >= 10) {
                            $total_overs_faced += $inning['max_overs'];
                        } else {
                            // Convert cricket overs (1.2) to decimal (1.33) for calculation
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
                'nrr' => $nrr
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

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th width="40">Pos</th>
                        <th>Team</th>
                        <th width="60" class="text-center">P</th>
                        <th width="60" class="text-center">W</th>
                        <th width="60" class="text-center">L</th>
                        <th width="60" class="text-center">D</th>
                        <th width="60" class="text-center">N/R</th>
                        <th width="60" class="text-center">Pts</th>
                        <th width="100" class="text-center">NRR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_data as $index => $row):
                        $is_qualifying = ($index < $qualify_count);
                        $nrr_formatted = ($row['nrr'] >= 0 ? '+' : '') . number_format($row['nrr'], 3);
                        ?>
                        <tr class="<?= $is_qualifying ? 'qualify-row' : '' ?>">
                            <td class="text-center fw-bold">
                                <?= $index + 1 ?>
                                <?php if ($is_qualifying): ?>
                                    <span class="qualify-indicator" title="Qualifies for Playoffs">Q</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="<?= $row['team_logo'] ? '../uploads/teams/' . $row['team_logo'] : '../assets/images/default-team.png' ?>"
                                        alt="<?= htmlspecialchars($row['team_name']) ?>" class="me-2 rounded-circle" width="30"
                                        height="30">
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($row['team_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['team_code']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center"><?= $row['played'] ?></td>
                            <td class="text-center"><?= $row['won'] ?></td>
                            <td class="text-center"><?= $row['lost'] ?></td>
                            <td class="text-center"><?= $row['draw'] ?></td>
                            <td class="text-center"><?= $row['nr'] ?></td>
                            <td class="text-center fw-bold text-primary"><?= $row['points'] ?></td>
                            <td class="text-center fw-semibold <?= $row['nrr'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $nrr_formatted ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-2 small text-muted d-flex align-items-center">
            <div class="qualify-indicator me-2" style="position: static; font-size: 0.7rem; width: 15px; height: 15px;">Q</div>
            <span>Qualified for Playoffs / Next Round</span>
        </div>
    <?php endif; ?>

    <style>
        .qualify-row {
            background-color: rgba(25, 135, 84, 0.05);
            border-left: 4px solid #198754 !important;
        }

        .qualify-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background-color: #198754;
            color: white;
            font-size: 0.75rem;
            border-radius: 50%;
            margin-left: 2px;
            vertical-align: middle;
        }

        .point-value {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }
    </style>

    <?php

} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>