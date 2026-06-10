<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

function plural_text($count, $singular, $plural)
{
    $count = (int) $count;
    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

function result_innings_pair(array $innings)
{
    $super_over_innings = [];
    foreach ($innings as $inn) {
        if ((int) $inn['inning_number'] >= 3) {
            $super_over_innings[] = $inn;
        }
    }

    if (count($super_over_innings) >= 2) {
        return [$super_over_innings[0], $super_over_innings[1]];
    }

    if (count($innings) >= 2) {
        return [$innings[0], $innings[1]];
    }

    return [];
}

function winner_name_for_match(array $match, $winner_id)
{
    if ((int) $winner_id === (int) $match['team1_id']) {
        return $match['team1_name'];
    }
    if ((int) $winner_id === (int) $match['team2_id']) {
        return $match['team2_name'];
    }
    return '';
}

function winner_color_for_match(array $match, $winner_id)
{
    if ((int) $winner_id === (int) $match['team1_id']) {
        return $match['team1_color'];
    }
    if ((int) $winner_id === (int) $match['team2_id']) {
        return $match['team2_color'];
    }
    return '';
}

function build_result_text(array $match, array $innings, $winner_id)
{
    $winner_name = winner_name_for_match($match, $winner_id);
    if ($winner_name === '') {
        return $match['result'] ?: 'Match completed';
    }

    $pair = result_innings_pair($innings);
    if (count($pair) < 2) {
        return $winner_name . ' won';
    }

    $first = $pair[0];
    $second = $pair[1];
    $first_runs = (int) $first['total_runs'];
    $second_runs = (int) $second['total_runs'];
    $winner_id = (int) $winner_id;
    $is_super_over = ((int) $first['inning_number'] >= 3 || (int) $second['inning_number'] >= 3);

    if ($winner_id === (int) $first['batting_team_id']) {
        $margin = max(0, $first_runs - $second_runs);
        if ($margin > 0) {
            return $winner_name . ' won by ' . plural_text($margin, 'run', 'runs');
        }
    }

    if ($winner_id === (int) $second['batting_team_id']) {
        if ($second_runs > $first_runs) {
            $wicket_limit = $is_super_over ? 2 : 10;
            $wickets_left = max(1, $wicket_limit - (int) $second['wickets']);
            return $winner_name . ' won by ' . plural_text($wickets_left, 'wicket', 'wickets');
        }
    }

    if ($is_super_over) {
        return $winner_name . ' won by super over';
    }

    return $winner_name . ' won';
}

try {
    $current_match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    $stmt = $pdo->prepare("
        SELECT m.id, m.match_date, m.match_time, m.venue, m.match_type, m.winner_id, m.result,
               t1.id as team1_id, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               COALESCE(NULLIF(t1.team_color, ''), '#0d6efd') as team1_color,
               t2.id as team2_id, t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               COALESCE(NULLIF(t2.team_color, ''), '#dc3545') as team2_color
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

        $winner_id = (int) ($match['winner_id'] ?? 0);
        $result = strtolower(trim((string) ($match['result'] ?? '')));
        if ($winner_id <= 0 && $result === 'team1') {
            $winner_id = (int) $match['team1_id'];
        } elseif ($winner_id <= 0 && $result === 'team2') {
            $winner_id = (int) $match['team2_id'];
        }

        $match['winner_team_id'] = $winner_id > 0 ? $winner_id : null;
        $match['winner_team_color'] = $winner_id > 0 ? winner_color_for_match($match, $winner_id) : '';
        $match['team1_is_winner'] = $winner_id === (int) $match['team1_id'];
        $match['team2_is_winner'] = $winner_id === (int) $match['team2_id'];

        if ($winner_id > 0) {
            $match['result_text'] = build_result_text($match, $innings, $winner_id);
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
