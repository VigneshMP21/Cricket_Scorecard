<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

function format_score($runs, $wickets, $overs)
{
    if ($runs === null)
        return '';
    return (int) $runs . '/' . (int) $wickets . ' (' . ($overs ?: '0.0') . ')';
}

try {
    $current_match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    $stmt = $pdo->prepare("
        SELECT m.id, m.match_date, m.match_time, m.venue, m.match_type, m.winner_id, m.result,
               t1.id as team1_id, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.id as team2_id, t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code
        FROM matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.status = 'completed'
          AND (? = 0 OR m.id <> ?)
        ORDER BY m.match_date DESC, m.match_time DESC, m.id DESC
        LIMIT 5
    ");
    $stmt->execute([$current_match_id, $current_match_id]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $innings_stmt = $pdo->prepare("
        SELECT inning_number, batting_team_id, total_runs, wickets, overs_bowled
        FROM innings
        WHERE match_id = ?
        ORDER BY inning_number ASC
    ");

    foreach ($matches as &$match) {
        $innings_stmt->execute([$match['id']]);
        $innings = $innings_stmt->fetchAll(PDO::FETCH_ASSOC);

        $team_scores = [
            (int) $match['team1_id'] => [],
            (int) $match['team2_id'] => []
        ];

        foreach ($innings as $inn) {
            $label = ((int) $inn['inning_number'] >= 3 ? 'SO ' : '') .
                format_score($inn['total_runs'], $inn['wickets'], $inn['overs_bowled']);
            $team_scores[(int) $inn['batting_team_id']][] = $label;
        }

        $match['team1_score'] = implode(' / ', $team_scores[(int) $match['team1_id']] ?? []);
        $match['team2_score'] = implode(' / ', $team_scores[(int) $match['team2_id']] ?? []);

        $winner_id = (int) ($match['winner_id'] ?? 0);
        $result = strtolower((string) ($match['result'] ?? ''));

        if ($winner_id > 0) {
            $winner_name = ($winner_id === (int) $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
            $match['result_text'] = $winner_name . ' won';
            $match['team1_status'] = $winner_id === (int) $match['team1_id'] ? 'WON' : 'LOST';
            $match['team2_status'] = $winner_id === (int) $match['team2_id'] ? 'WON' : 'LOST';
        } elseif ($result === 'tie' || $result === 'draw') {
            $match['result_text'] = $result === 'draw' ? 'Match drawn' : 'Match tied';
            $match['team1_status'] = 'TIE';
            $match['team2_status'] = 'TIE';
        } elseif ($result === 'no result') {
            $match['result_text'] = 'No result';
            $match['team1_status'] = 'N/R';
            $match['team2_status'] = 'N/R';
        } else {
            $match['result_text'] = $match['result'] ?: 'Match completed';
            $match['team1_status'] = 'DONE';
            $match['team2_status'] = 'DONE';
        }
    }
    unset($match);

    echo json_encode(['success' => true, 'matches' => $matches]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
