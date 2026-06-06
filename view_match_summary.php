<?php
require_once 'includes/db.php'; // Path might need adjustment depending on where this file is
require_once 'includes/header.php';

$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$match_id)
    die("Match ID not provided");

// Fetch Match Data
$stmt = $pdo->prepare("
    SELECT m.*, 
           t1.team_name as team1_name, t1.team_logo as team1_logo, t1.captain_id as team1_captain_id, t1.vice_captain_id as team1_vice_captain_id,
           t2.team_name as team2_name, t2.team_logo as team2_logo, t2.captain_id as team2_captain_id, t2.vice_captain_id as team2_vice_captain_id,
           trn.tournament_name,
           tw.team_name as toss_winner_name, tw.team_logo as toss_winner_logo
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN tournaments trn ON m.tournament_id = trn.id
    LEFT JOIN teams tw ON m.toss_winner = tw.id
    WHERE m.id = ?
");
$stmt->execute([$match_id]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Completed Innings
$stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number ASC");
$stmt->execute([$match_id]);
$innings_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine Winner calculation
$winner_text = "Match Completed";
if (!empty($match['result'])) {
    if ($match['result'] == 'team1') {
        $winner_text = $match['team1_name'] . " Won";
    } elseif ($match['result'] == 'team2') {
        $winner_text = $match['team2_name'] . " Won";
    } elseif ($match['result'] == 'draw') {
        $winner_text = "Match Drawn";
    } elseif ($match['result'] == 'tie') {
        $winner_text = "Match Tied";
    } elseif ($match['result'] == 'no result') {
        $winner_text = "No Result";
    } else {
        $winner_text = $match['result'];
    }
}
$winning_team_id = $match['winner_id'] ?? null;

// Fallback logic if winner_id is not set but we have innings data
if (!$winning_team_id && count($innings_data) >= 2 && $winner_text == "Match Completed") {
    $inn1 = $innings_data[0];
    $inn2 = $innings_data[1];
    if ($inn1['total_runs'] > $inn2['total_runs']) {
        $winning_team_id = $inn1['batting_team_id'];
        $winner_text = ($winning_team_id == $match['team1_id'] ? $match['team1_name'] : $match['team2_name']) . " Won";
    } else if ($inn2['total_runs'] > $inn1['total_runs']) {
        $winning_team_id = $inn2['batting_team_id'];
        $winner_text = ($winning_team_id == $match['team1_id'] ? $match['team1_name'] : $match['team2_name']) . " Won";
    } else {
        $winner_text = "Match Tied";
    }
}

// Correctly map scores to Team 1 and Team 2
$team1_runs = 0;
$team1_wickets = 0;
$team1_so_runs = 0;
$team1_so_wickets = 0;

$team2_runs = 0;
$team2_wickets = 0;
$team2_so_runs = 0;
$team2_so_wickets = 0;

$has_super_over = false;

foreach ($innings_data as $inn) {
    if ($inn['inning_number'] <= 2) {
        if ($inn['batting_team_id'] == $match['team1_id']) {
            $team1_runs = $inn['total_runs'];
            $team1_wickets = $inn['wickets'];
        } else if ($inn['batting_team_id'] == $match['team2_id']) {
            $team2_runs = $inn['total_runs'];
            $team2_wickets = $inn['wickets'];
        }
    } else {
        $has_super_over = true;
        if ($inn['batting_team_id'] == $match['team1_id']) {
            $team1_so_runs = $inn['total_runs'];
            $team1_so_wickets = $inn['wickets'];
        } else if ($inn['batting_team_id'] == $match['team2_id']) {
            $team2_so_runs = $inn['total_runs'];
            $team2_so_wickets = $inn['wickets'];
        }
    }
}

// Calculate POTM of the Match (Data-Driven)
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.profile_image, t.team_name, ms.team_id,
           SUM(ms.runs_scored) as total_runs, 
           SUM(ms.balls_faced) as total_balls,
           SUM(ms.wickets_taken) as total_wickets,
           SUM(ms.runs_conceded) as total_runs_conceded,
           SUM(ms.overs_bowled) as total_overs,
           SUM(ms.maidens) as total_maidens,
           SUM(ms.catches) as total_catches,
           SUM(ms.run_outs) as total_run_outs,
           SUM(ms.stumpings) as total_stumpings,
           AVG(ms.strike_rate) as avg_sr,
           AVG(ms.economy_rate) as avg_econ
    FROM match_statistics ms
    JOIN users u ON ms.player_id = u.id
    JOIN teams t ON ms.team_id = t.id
    WHERE ms.match_id = ?
    GROUP BY ms.player_id, t.team_name, ms.team_id
");

// If a winning team is known, prefer only that team's players for POTM
if ($winning_team_id) {
    $stmt->execute([$match_id]);
    $all_players_stats = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function ($r) use ($winning_team_id) {
        return $r['team_id'] == $winning_team_id;
    });

    // If no stats were found for the winning team (edge case), fall back to all players
    if (empty($all_players_stats)) {
        $stmt->execute([$match_id]);
        $all_players_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // No winning team available - consider all players
    $stmt->execute([$match_id]);
    $all_players_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($all_players_stats as &$p) {
    $bat_score = $p['total_runs'];
    if ($p['total_runs'] >= 50)
        $bat_score += 10;
    else if ($p['total_runs'] >= 30)
        $bat_score += 5;

    if ($p['total_balls'] > 0) {
        $sr = ($p['total_runs'] / $p['total_balls']) * 100;
        if ($sr >= 150)
            $bat_score += 10;
        else if ($sr >= 120)
            $bat_score += 5;
    }
    // Duck penalty - checking across all innings for this player
    $is_duck = false;
    foreach ($innings_data as $idat) {
        if ($p['total_runs'] == 0 && isset($dismissal_map[$idat['inning_number']][$p['id']])) {
            $is_duck = true;
            break;
        }
    }
    if ($is_duck)
        $bat_score -= 5;

    $bowl_score = ($p['total_wickets'] * 25);

    // Econ Bonus (min 2 overs)
    $whole_ov = floor($p['total_overs']);
    $rem_balls = ($p['total_overs'] - $whole_ov) * 10;
    $act_ov = $whole_ov + ($rem_balls / 6);
    if ($act_ov >= 2) {
        $econ = $p['total_runs_conceded'] / $act_ov;
        if ($econ <= 6)
            $bowl_score += 10;
        else if ($econ <= 7.5)
            $bowl_score += 5;
    }
    $bowl_score += ($p['total_maidens'] * 8);
    if ($p['total_wickets'] >= 3)
        $bowl_score += 10;

    $field_score = ($p['total_catches'] * 8) + ($p['total_run_outs'] * 12) + ($p['total_stumpings'] * 10);

    $p['total_potm_score'] = $bat_score + $bowl_score + $field_score;
}

usort($all_players_stats, function ($a, $b) use ($winning_team_id) {
    if ($b['total_potm_score'] != $a['total_potm_score']) {
        return $b['total_potm_score'] <=> $a['total_potm_score'];
    }
    // Tie-breaker 1: Winning team
    $a_win = ($a['team_id'] == $winning_team_id) ? 1 : 0;
    $b_win = ($b['team_id'] == $winning_team_id) ? 1 : 0;
    if ($b_win != $a_win)
        return $b_win <=> $a_win;

    // Tie-breaker 2: All-round performance
    $a_ar = $a['total_runs'] + ($a['total_wickets'] * 25);
    $b_ar = $b['total_runs'] + ($b['total_wickets'] * 25);
    if ($b_ar != $a_ar)
        return $b_ar <=> $a_ar;

    return $b['avg_sr'] <=> $a['avg_sr'];
});

$potm = $all_players_stats[0] ?? null;

// Finalize MOM logic: Automatically award MOM once the match is completed and viewed
if ($match['status'] === 'completed' && empty($match['man_of_match']) && $potm) {
    try {
        $pdo->beginTransaction();

        // 1. Update matches table
        $stmt_mom = $pdo->prepare("UPDATE matches SET man_of_match = ? WHERE id = ?");
        $stmt_mom->execute([$potm['id'], $match_id]);

        // 2. Update player_stats table (increment MOM count)
        $stmt_ps = $pdo->prepare("UPDATE player_stats SET man_of_match = man_of_match + 1 WHERE player_id = ?");
        $stmt_ps->execute([$potm['id']]);

        // 3. Update match_statistics table (mark as MOM)
        $stmt_ms = $pdo->prepare("UPDATE match_statistics SET man_of_match = 1 WHERE match_id = ? AND player_id = ?");
        $stmt_ms->execute([$match_id, $potm['id']]);

        $pdo->commit();
        $match['man_of_match'] = $potm['id']; // Update local variable for UI consistency
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("Failed to award MOM for match $match_id: " . $e->getMessage());
    }
}

// Best Batter logic deferred after dismissal map calculation
// Best Bowler logic deferred after dot ball calculation

// Fetch all balls once to avoid multiple database hits
$stmt = $pdo->prepare("
    SELECT b.*, bow.name as bowler_name, f.name as fielder_name, u.name as player_name, u.profile_image as player_image
    FROM ball_by_ball b
    LEFT JOIN users bow ON b.bowler_id = bow.id
    LEFT JOIN users f ON b.fielder_id = f.id
    LEFT JOIN users u ON b.wicket_player_id = u.id
    WHERE b.match_id = ? 
    ORDER BY b.inning_number, b.over_number, b.ball_number
");
$stmt->execute([$match_id]);
$all_balls = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dismissals_raw = array_filter($all_balls, function ($b) {
    return $b['wicket_type'] !== null;
});
$dismissal_map = [];
foreach ($dismissals_raw as $d) {
    $dismissal_map[$d['inning_number']][$d['wicket_player_id']] = $d;
}

$fow_data = [];
$temp_runs = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$temp_wickets = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$bowler_dots = [];

foreach ($all_balls as $ball) {
    $inn = $ball['inning_number'];
    $temp_runs[$inn] += $ball['runs_scored'] + $ball['extra_runs'];

    // Dot ball calculation: 0 runs off bat and not a wide or no-ball
    $bowler_id = $ball['bowler_id'];
    if (!isset($bowler_dots[$bowler_id]))
        $bowler_dots[$bowler_id] = 0;
    if ($ball['runs_scored'] == 0 && !in_array($ball['extra_type'], ['wide', 'no ball'])) {
        $bowler_dots[$bowler_id]++;
    }

    if ($ball['wicket_type']) {
        $temp_wickets[$inn]++;

        // Find player info for this wicket
        $p_name = "Unknown";
        $p_img = "assets/images/default-player.png";
        foreach ($dismissals_raw as $dr) {
            if ($dr['inning_number'] == $inn && $dr['wicket_player_id'] == $ball['wicket_player_id']) {
                $p_name = $dr['player_name'];
                $p_img = $dr['player_image'] ? 'uploads/users/' . $dr['player_image'] : $p_img;
                break;
            }
        }

        $fow_data[$inn][] = [
            'wicket' => $temp_wickets[$inn],
            'player' => $p_name,
            'image' => $p_img,
            'score' => $temp_runs[$inn],
            'over' => $ball['over_number'] . '.' . $ball['ball_number']
        ];
    }
}

// Calculate Key Partnerships
$partnerships_data = [];
$current_partnerships = [1 => [], 2 => [], 3 => [], 4 => []];
$current_pair = [1 => null, 2 => null, 3 => null, 4 => null];
$partnership_start_score = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

foreach ($all_balls as $ball) {
    $inn = $ball['inning_number'];
    $batter1 = $ball['batsman_id'];
    $batter2 = $ball['non_striker_id'];

    // Normalize pair order to identify the same partnership
    $pair = [$batter1, $batter2];
    sort($pair);
    $pair_key = implode('-', $pair);

    if (!isset($current_partnerships[$inn][$pair_key])) {
        $current_partnerships[$inn][$pair_key] = [
            'p1_id' => $pair[0],
            'p2_id' => $pair[1],
            'runs' => 0,
            'balls' => 0,
            'p1_runs' => 0,
            'p2_runs' => 0
        ];
    }

    $runs = $ball['runs_scored'];
    $extras = $ball['extra_runs'];

    $current_partnerships[$inn][$pair_key]['runs'] += ($runs + $extras);
    $current_partnerships[$inn][$pair_key]['balls'] += in_array($ball['extra_type'], ['wide', 'no ball']) ? 0 : 1;

    if ($ball['batsman_id'] == $pair[0]) {
        $current_partnerships[$inn][$pair_key]['p1_runs'] += $runs;
    } else {
        $current_partnerships[$inn][$pair_key]['p2_runs'] += $runs;
    }
}

// Fetch player names and images map for later use
$player_names_map = [];
$stmt_names = $pdo->prepare("SELECT id, name, profile_image FROM users");
$stmt_names->execute();
while ($row = $stmt_names->fetch(PDO::FETCH_ASSOC)) {
    $player_names_map[$row['id']] = $row;
}

// Post-process partnerships to get names and filter top ones
foreach ($current_partnerships as $inn => $pairs) {
    $processed = [];
    foreach ($pairs as $p) {
        $p['p1_name'] = $player_names_map[$p['p1_id']]['name'] ?? 'Unknown';
        $p['p2_name'] = $player_names_map[$p['p2_id']]['name'] ?? 'Unknown';
        $p['p1_img'] = !empty($player_names_map[$p['p1_id']]['profile_image']) ? 'uploads/users/' . $player_names_map[$p['p1_id']]['profile_image'] : 'assets/images/default-player.png';
        $p['p2_img'] = !empty($player_names_map[$p['p2_id']]['profile_image']) ? 'uploads/users/' . $player_names_map[$p['p2_id']]['profile_image'] : 'assets/images/default-player.png';

        $processed[] = $p;
    }

    usort($processed, function ($a, $b) {
        return $b['runs'] <=> $a['runs'];
    });

    $partnerships_data[$inn] = array_slice($processed, 0, 3); // Top 3
}

// Clear unused variables
unset($pairs);

// Calculate Best Batter of the Match
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.profile_image, ms.runs_scored, ms.balls_faced, ms.strike_rate, ms.fours, ms.sixes, ms.team_id, t.team_name, ms.inning_number
    FROM match_statistics ms
    JOIN users u ON ms.player_id = u.id
    JOIN teams t ON ms.team_id = t.id
    WHERE ms.match_id = ? AND ms.balls_faced > 0
");
$stmt->execute([$match_id]);
$all_batters = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_batters as &$b) {
    $score = $b['runs_scored'];

    // Strike Rate Bonus
    if ($b['balls_faced'] > 0) {
        $sr = $b['strike_rate'];
        if ($sr >= 160)
            $score += 15;
        else if ($sr >= 140)
            $score += 10;
        else if ($sr >= 120)
            $score += 5;
    }

    // Boundary Bonus
    $score += ($b['fours'] * 1);
    $score += ($b['sixes'] * 2);

    // Milestone Bonus
    if ($b['runs_scored'] >= 100)
        $score += 20;
    else if ($b['runs_scored'] >= 50)
        $score += 12;
    else if ($b['runs_scored'] >= 30)
        $score += 5;

    // Dismissal Penalty (Duck)
    $is_out = isset($dismissal_map[$b['inning_number']][$b['id']]);
    if ($b['runs_scored'] == 0 && $is_out) {
        $score -= 5;
    }

    // Match Impact Tie-Break Bonus
    if ($b['team_id'] == $winning_team_id) {
        $score += 5;
    }

    $b['best_batter_score'] = $score;
}

usort($all_batters, function ($a, $b) use ($winning_team_id) {
    if ($b['best_batter_score'] != $a['best_batter_score']) {
        return $b['best_batter_score'] <=> $a['best_batter_score'];
    }
    // Tie-Breakers
    if ($b['strike_rate'] != $a['strike_rate']) {
        return $b['strike_rate'] <=> $a['strike_rate'];
    }
    if ($b['runs_scored'] != $a['runs_scored']) {
        return $b['runs_scored'] <=> $a['runs_scored'];
    }
    $a_won = ($a['team_id'] == $winning_team_id) ? 1 : 0;
    $b_won = ($b['team_id'] == $winning_team_id) ? 1 : 0;
    return $b_won <=> $a_won;
});

$best_batter = $all_batters[0] ?? null;

// Calculate Best Bowler of the Match
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.profile_image, ms.wickets_taken, ms.runs_conceded, ms.economy_rate, ms.overs_bowled, ms.maidens, ms.team_id, t.team_name
    FROM match_statistics ms
    JOIN users u ON ms.player_id = u.id
    JOIN teams t ON ms.team_id = t.id
    WHERE ms.match_id = ? AND ms.overs_bowled > 0
");
$stmt->execute([$match_id]);
$all_bowlers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_bowlers as &$b) {
    $dots = $bowler_dots[$b['id']] ?? 0;
    $b['dot_balls'] = $dots;

    $score = ($b['wickets_taken'] * 25);

    // Economy Rate Bonus (min 2 overs)
    $whole_overs = floor($b['overs_bowled']);
    $balls = ($b['overs_bowled'] - $whole_overs) * 10;
    $actual_overs = $whole_overs + ($balls / 6);

    if ($actual_overs >= 2) {
        $econ = $b['economy_rate'];
        if ($econ <= 5.00)
            $score += 15;
        else if ($econ <= 6.50)
            $score += 10;
        else if ($econ <= 7.50)
            $score += 5;
    }

    // Maiden Over Bonus
    $score += ($b['maidens'] * 10);

    // Wicket Milestone Bonus
    if ($b['wickets_taken'] >= 5)
        $score += 25;
    else if ($b['wickets_taken'] == 4)
        $score += 15;
    else if ($b['wickets_taken'] == 3)
        $score += 10;

    // Dot Ball Pressure Bonus
    if ($dots >= 20)
        $score += 15;
    else if ($dots >= 15)
        $score += 10;
    else if ($dots >= 10)
        $score += 5;

    // Match Impact Tie-Break Bonus
    if ($b['team_id'] == $winning_team_id) {
        $score += 5;
    }

    $b['best_bowler_score'] = $score;
}

usort($all_bowlers, function ($a, $b) use ($winning_team_id) {
    if ($b['best_bowler_score'] != $a['best_bowler_score']) {
        return $b['best_bowler_score'] <=> $a['best_bowler_score'];
    }
    // Tie-Breakers
    if ($b['wickets_taken'] != $a['wickets_taken']) {
        return $b['wickets_taken'] <=> $a['wickets_taken'];
    }
    if ($a['economy_rate'] != $b['economy_rate']) {
        return $a['economy_rate'] <=> $b['economy_rate']; // Lower better
    }
    if ($b['dot_balls'] != $a['dot_balls']) {
        return $b['dot_balls'] <=> $a['dot_balls'];
    }
    $a_won = ($a['team_id'] == $winning_team_id) ? 1 : 0;
    $b_won = ($b['team_id'] == $winning_team_id) ? 1 : 0;
    return $b_won <=> $a_won;
});

$best_bowler = $all_bowlers[0] ?? null;

function getDismissalText($d)
{
    if (!$d)
        return '<span class="text-success fw-bold">not out</span>';
    $text = "";
    switch ($d['wicket_type']) {
        case 'bowled':
            $text = "b " . $d['bowler_name'];
            break;
        case 'caught':
            if ($d['fielder_id'] == $d['bowler_id']) {
                $text = "c & b " . $d['bowler_name'];
            } else {
                $text = "c " . $d['fielder_name'] . " b " . $d['bowler_name'];
            }
            break;
        case 'lbw':
            $text = "lbw b " . $d['bowler_name'];
            break;
        case 'run out':
            $text = "run out (" . $d['fielder_name'] . ")";
            break;
        case 'stumped':
            $text = "st " . $d['fielder_name'] . " b " . $d['bowler_name'];
            break;
        default:
            $text = $d['wicket_type'] . " b " . $d['bowler_name'];
    }
    return $text;
}

// Fetch Full Commentary
$stmt = $pdo->prepare("
    SELECT b.*, u1.name as batter_name, bow.name as bowler_name
    FROM ball_by_ball b
    JOIN users u1 ON b.batsman_id = u1.id
    JOIN users bow ON b.bowler_id = bow.id
    WHERE b.match_id = ?
    ORDER BY b.inning_number ASC, b.over_number ASC, b.ball_number ASC
");
$stmt->execute([$match_id]);
$full_commentary = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
        --accent-gradient: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
        --glass-bg: rgba(255, 255, 255, 0.9);
        --glass-border: rgba(255, 255, 255, 0.2);
        --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --premium-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        --border-radius: 20px;
    }

    #match-summary-wrapper {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        background-color: #f8fafc;
        color: #1e293b;
    }

    #match-summary-wrapper .summary-card {
        background: white;
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        padding: 24px;
        margin-bottom: 24px;
        transition: var(--transition);
        border: 1px solid #e2e8f0;
    }

    #match-summary-wrapper .summary-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--premium-shadow);
    }

    #match-summary-wrapper .award-player-img {
        width: 160px;
        height: 160px;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transition: var(--transition);
    }

    #match-summary-wrapper .summary-card:hover .award-player-img {
        transform: scale(1.05) rotate(2deg);
    }

    #match-summary-wrapper .nav-pills {
        background: white;
        padding: 8px;
        border-radius: 100px;
        box-shadow: var(--card-shadow);
        border: 1px solid #e2e8f0;
    }

    #match-summary-wrapper .nav-pills .nav-link {
        color: #64748b;
        font-weight: 600;
        padding: 12px 24px;
        border-radius: 100px;
        transition: var(--transition);
        border: none;
    }

    #match-summary-wrapper .nav-pills .nav-link.active {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
    }

    #match-summary-wrapper .inning-header {
        cursor: pointer;
        padding: 20px 24px;
        background: white;
        border-radius: var(--border-radius);
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        border: 1px solid #e2e8f0;
        transition: var(--transition);
    }

    #match-summary-wrapper .inning-header:hover {
        border-color: #3b82f6;
        background: #f0f9ff;
    }

    #match-summary-wrapper .inning-header h5 {
        font-size: 1.125rem;
        font-weight: 700;
        margin: 0;
    }

    /* Super Over Inning Header */
    #match-summary-wrapper .inning-header-so {
        background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 100%) !important;
        border-color: #ffd700 !important;
        color: white !important;
    }

    #match-summary-wrapper .inning-header-so h5,
    #match-summary-wrapper .inning-header-so .text-muted,
    #match-summary-wrapper .inning-header-so .h4,
    #match-summary-wrapper .inning-header-so .header-inn-label,
    #match-summary-wrapper .inning-header-so .header-team-name,
    #match-summary-wrapper .inning-header-so .header-score,
    #match-summary-wrapper .inning-header-so .header-overs,
    #match-summary-wrapper .inning-header-so i {
        color: white !important;
    }

    #match-summary-wrapper .inning-header-so:hover {
        background: linear-gradient(135deg, #5b21b6 0%, #8b5cf6 100%) !important;
    }

    #match-summary-wrapper .player-image-sm {
        width: 44px;
        height: 44px;
        object-fit: cover;
        border-radius: 12px;
        margin-right: 12px;
        background: #f1f5f9;
        padding: 2px;
        border: 1px solid #e2e8f0;
    }

    #match-summary-wrapper .player-row {
        transition: var(--transition);
        border-radius: 12px;
        cursor: pointer;
        touch-action: manipulation;
    }

    #match-summary-wrapper .player-row:hover {
        background-color: #f1f5f9;
    }

    /* Player Overlay Refinement */
    .player-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(8px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 0;
        transition: var(--transition);
    }

    .player-overlay.show {
        display: flex;
        opacity: 1;
    }

    .player-overlay-card {
        background: white;
        border-radius: 24px;
        width: 90%;
        max-width: 440px;
        padding: 32px;
        position: relative;
        box-shadow: var(--premium-shadow);
        transform: translateY(20px);
        transition: var(--transition);
    }

    .player-overlay.show .player-overlay-card {
        transform: translateY(0);
    }

    #match-summary-wrapper .stat-box,
    .player-overlay .stat-box {
        background: #f8fafc;
        padding: 16px;
        border-radius: 16px;
        flex: 1;
        border: 1px solid #e2e8f0;
        text-align: center;
    }

    #match-summary-wrapper .stat-box .value,
    .player-overlay .stat-box .value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e3a8a;
    }

    #match-summary-wrapper .commentary-item {
        display: flex;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid #f1f5f9;
        align-items: flex-start;
        transition: var(--transition);
    }

    #match-summary-wrapper .commentary-item:hover {
        background: #f8fafc;
    }

    #match-summary-wrapper .ball-num {
        font-weight: 700;
        color: #64748b;
        font-size: 0.875rem;
        min-width: 40px;
        padding-top: 4px;
    }

    #match-summary-wrapper .ball-event-badge {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.75rem;
        color: white;
        flex-shrink: 0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    #match-summary-wrapper .bg-W {
        background: #ef4444;
    }

    #match-summary-wrapper .bg-4 {
        background: #10b981;
    }

    #match-summary-wrapper .bg-6 {
        background: #8b5cf6;
    }

    #match-summary-wrapper .bg-0 {
        background: #94a3b8;
    }

    #match-summary-wrapper .bg-WD,
    #match-summary-wrapper .bg-NB {
        background: #f59e0b;
    }

    #match-summary-wrapper .inning-divider {
        background: #f1f5f9;
        padding: 12px 24px;
        font-weight: 800;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #475569;
        border-left: 4px solid #1e3a8a;
    }

    #match-summary-wrapper .match-result-banner {
        overflow: hidden;
        border-radius: 32px;
        border: none;
        background: var(--primary-gradient);
        position: relative;
        box-shadow: var(--premium-shadow);
    }

    #match-summary-wrapper .match-result-banner::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        opacity: 0.5;
    }

    #match-summary-wrapper .match-result-banner::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 600px;
        height: 600px;
        background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    #match-summary-wrapper .confetti {
        position: absolute;
        width: 10px;
        height: 10px;
        background: #ffd700;
        border-radius: 2px;
        opacity: 0.6;
        animation: confetti-fall 3s ease-in-out infinite;
    }

    @keyframes confetti-fall {
        0% {
            transform: translateY(-100px) rotate(0deg);
            opacity: 0.6;
        }

        100% {
            transform: translateY(300px) rotate(720deg);
            opacity: 0;
        }
    }

    #match-summary-wrapper .match-result-logo {
        width: 100px;
        height: 100px;
        background: white;
        border-radius: 16px;
        padding: 2px;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        object-fit: contain;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        #match-summary-wrapper .container {
            padding: 0 !important;
            margin-top: 0 !important;
            max-width: 100% !important;
        }

        .admin-header,
        .admin-nav {
            margin-top: 0 !important;
            padding-top: 0 !important;
            border-radius: 0 !important;
        }

        #match-summary-wrapper .match-result-banner {
            border-radius: 0 !important;
            margin-top: 0 !important;
        }

        .admin-header .container-fluid,
        .admin-nav .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        #match-summary-wrapper .nav-pills {
            border-radius: 12px;
            padding: 4px;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        #match-summary-wrapper .nav-pills .nav-link {
            padding: 8px 16px;
            font-size: 0.875rem;
            white-space: nowrap;
        }

        #match-summary-wrapper .display-3 {
            font-size: 1.75rem !important;
        }

        #match-summary-wrapper .match-result-logo {
            width: 75px;
            height: 75px;
        }

        #match-summary-wrapper .inning-header {
            padding: 12px 16px;
        }

        #match-summary-wrapper .inning-header .header-col-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            width: 70px;
            flex-shrink: 0;
        }

        #match-summary-wrapper .inning-header .header-logo-container {
            width: 44px !important;
            height: 44px !important;
            margin: 0 !important;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #match-summary-wrapper .inning-header .header-logo-container img {
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: contain !important;
            border-radius: 5px;
        }

        #match-summary-wrapper .inning-header .header-inn-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            white-space: nowrap;
        }

        #match-summary-wrapper .inning-header .header-col-center {
            flex-grow: 1;
            padding: 0 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            border-left: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
        }

        #match-summary-wrapper .inning-header .header-team-name {
            font-size: 0.9rem;
            font-weight: 800;
            margin: 0;
            line-height: 1.2;
        }

        #match-summary-wrapper .inning-header .header-col-right {
            width: 85px;
            flex-shrink: 0;
            text-align: right;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        #match-summary-wrapper .inning-header .header-score {
            font-size: 1.1rem !important;
            font-weight: 800;
            margin-bottom: 2px !important;
        }

        #match-summary-wrapper .inning-header .header-overs {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 600;
        }

        #match-summary-wrapper .inning-header .chevron-icon {
            margin-left: 8px;
            font-size: 0.8rem;
        }
    }

    #match-summary-wrapper .badge-premium {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        padding: 6px 16px;
        border-radius: 100px;
        font-weight: 600;
    }

    #match-summary-wrapper .award-card {
        border: none;
        background: white;
        text-align: center;
        cursor: pointer;
        touch-action: manipulation;
    }

    #match-summary-wrapper .award-card.potm {
        background: linear-gradient(to bottom, #fff7ed, #ffffff);
        border: 1px solid #ffedd5;
    }

    #match-summary-wrapper .award-card.potm .award-player-img {
        border-color: #fb923c;
    }

    #match-summary-wrapper .table thead th {
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 16px;
    }

    #match-summary-wrapper .table tbody td {
        padding: 18px 16px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }

    #match-summary-wrapper .table tbody tr:last-child td {
        border-bottom: none;
    }

    #match-summary-wrapper .transition-hover {
        transition: var(--transition);
    }

    #match-summary-wrapper .transition-hover:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    #match-summary-wrapper .fow-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        transition: var(--transition);
    }

    #match-summary-wrapper .fow-card:hover {
        border-color: #cbd5e1;
        background: white;
        box-shadow: 4px 4px 12px rgba(0, 0, 0, 0.05);
    }

    /* ── Mobile Scorecard Card Layout (≤460px) ── */
    @media (max-width: 460px) {
        /* Hide the desktop table on small screens */
        #match-summary-wrapper .sc-table-wrap {
            display: none !important;
        }
        /* Show the mobile card list */
        #match-summary-wrapper .sc-mobile-list {
            display: block !important;
        }
    }

    /* Hide mobile list on desktop by default */
    #match-summary-wrapper .sc-mobile-list {
        display: none;
    }

    /* Mobile scorecard header row */
    #match-summary-wrapper .sc-mobile-header {
        display: flex;
        align-items: center;
        padding: 8px 12px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
    }

    #match-summary-wrapper .sc-mobile-header .sc-player-col {
        flex: 1;
        min-width: 0;
    }

    #match-summary-wrapper .sc-mobile-header .sc-stat {
        width: 36px;
        text-align: center;
        flex-shrink: 0;
    }

    /* Mobile scorecard player row */
    #match-summary-wrapper .sc-mobile-row {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        touch-action: manipulation;
        transition: background 0.2s;
    }

    #match-summary-wrapper .sc-mobile-row:hover {
        background: #f8fafc;
    }

    #match-summary-wrapper .sc-mobile-row .sc-player-col {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        padding-right: 6px;
    }

    #match-summary-wrapper .sc-mobile-row .sc-player-img {
        width: 36px;
        height: 36px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        flex-shrink: 0;
        background: #f1f5f9;
    }

    #match-summary-wrapper .sc-mobile-row .sc-player-info {
        min-width: 0;
    }

    #match-summary-wrapper .sc-mobile-row .sc-player-name {
        font-weight: 700;
        font-size: 0.82rem;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    #match-summary-wrapper .sc-mobile-row .sc-dismissal {
        font-size: 0.68rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    #match-summary-wrapper .sc-mobile-row .sc-stat {
        width: 36px;
        text-align: center;
        flex-shrink: 0;
        font-size: 0.8rem;
    }

    #match-summary-wrapper .sc-mobile-row .sc-stat.sc-runs {
        font-weight: 800;
        font-size: 0.95rem;
        color: #1e3a8a;
    }

    #match-summary-wrapper .sc-mobile-row .sc-stat.sc-wkts {
        font-weight: 800;
        font-size: 0.95rem;
        color: #ef4444;
    }

    /* Mobile extras/total footer */
    #match-summary-wrapper .sc-mobile-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
        font-size: 0.8rem;
        font-weight: 700;
        color: #1e293b;
    }
</style>

<div id="match-summary-wrapper">
    <div class="container py-4">
        <!-- Match Result Banner -->
        <div class="match-result-banner text-white text-center mb-4 shadow-lg border-0">
            <a href="NavBarList/matches.php"
                class="position-absolute top-0 start-0 m-3 text-white text-decoration-none fw-600 d-flex align-items-center"
                style="z-index: 10; font-size: 0.9rem; opacity: 0.9;">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
            <!-- Confetti Elements -->
            <?php if ($winning_team_id): ?>
                <?php for ($i = 0; $i < 20; $i++): ?>
                    <div class="confetti"
                        style="left: <?= rand(0, 100) ?>%; animation-delay: <?= rand(0, 3000) / 1000 ?>s; background: <?= ['#ffd700', '#ffffff', '#3b82f6'][rand(0, 2)] ?>;">
                    </div>
                <?php endfor; ?>
            <?php endif; ?>

            <div class="card-body py-5 px-4 position-relative" style="z-index: 2;">
                <div class="mb-2">
                    <span class="badge badge-premium text-uppercase letter-spacing-2"
                        style="font-size: 0.75rem; background: rgba(255,255,255,0.15);">Match Conclusion</span>
                </div>
                <h1 class="display-3 fw-800 mb-4"
                    style="text-shadow: 0 4px 12px rgba(0,0,0,0.3); letter-spacing: -1px;">
                    <?= $winner_text ?>
                </h1>

                <div class="row align-items-center justify-content-center g-0">
                    <!-- Team 1 -->
                    <div class="col-5 text-end">
                        <div class="d-inline-block text-center me-3">
                            <div class="position-relative d-inline-block mb-3">
                                <img src="uploads/teams/<?= $match['team1_logo'] ?>" class="match-result-logo"
                                    onerror="this.src='assets/images/default-team.png'">
                                <?php if ($winning_team_id == $match['team1_id']): ?>
                                    <div class="position-absolute top-0 start-0 translate-middle badge bg-warning rounded-circle p-2 shadow"
                                        style="width: 32px; height: 32px; border: 2px solid white;">
                                        <i class="fas fa-crown text-dark" style="font-size: 0.75rem;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="h2 fw-800 mb-0"><?= $team1_runs ?>/<?= $team1_wickets ?></div>
                            <?php if ($has_super_over): ?>
                                <div class="badge rounded-pill px-3 py-1 mt-2"
                                    style="background: rgba(255,215,0,0.2); color: #ffd700; border: 1px solid rgba(255,215,0,0.3); font-size: 0.75rem; font-weight: 800; backdrop-filter: blur(4px);">
                                    S/O: <?= $team1_so_runs ?>/<?= $team1_so_wickets ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-white-50 small text-uppercase fw-bold letter-spacing-1 mt-2">
                                <?= $match['team1_name'] ?>
                            </div>
                        </div>
                    </div>

                    <!-- VS Divider -->
                    <div class="col-2 text-center d-flex flex-column align-items-center justify-content-center">
                        <div class="bg-white opacity-25" style="width: 2px; height: 35px;"></div>
                        <div class="fw-900 opacity-50 py-2" style="font-size: 1.25rem; line-height: 1;">VS</div>
                        <div class="bg-white opacity-25" style="width: 2px; height: 35px;"></div>
                    </div>

                    <!-- Team 2 -->
                    <div class="col-5 text-start">
                        <div class="d-inline-block text-center ms-3">
                            <div class="position-relative d-inline-block mb-3">
                                <img src="uploads/teams/<?= $match['team2_logo'] ?>" class="match-result-logo"
                                    onerror="this.src='assets/images/default-team.png'">
                                <?php if ($winning_team_id == $match['team2_id']): ?>
                                    <div class="position-absolute top-0 start-0 translate-middle badge bg-warning rounded-circle p-2 shadow"
                                        style="width: 32px; height: 32px; border: 2px solid white;">
                                        <i class="fas fa-crown text-dark" style="font-size: 0.75rem;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="h2 fw-800 mb-0"><?= $team2_runs ?>/<?= $team2_wickets ?></div>
                            <?php if ($has_super_over): ?>
                                <div class="badge rounded-pill px-3 py-1 mt-2"
                                    style="background: rgba(255,215,0,0.2); color: #ffd700; border: 1px solid rgba(255,215,0,0.3); font-size: 0.75rem; font-weight: 800; backdrop-filter: blur(4px);">
                                    S/O: <?= $team2_so_runs ?>/<?= $team2_so_wickets ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-white-50 small text-uppercase fw-bold letter-spacing-1 mt-2">
                                <?= $match['team2_name'] ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-pills mb-4 flex-nowrap overflow-auto pb-2 nav-justified w-100" id="pills-tab" role="tablist"
            style="scrollbar-width: none;">
            <li class="nav-item flex-fill"><button class="nav-link w-100 active text-nowrap" id="pills-summary-tab"
                    data-bs-toggle="pill" data-bs-target="#pills-summary">Summary</button></li>
            <li class="nav-item flex-fill"><button class="nav-link w-100 text-nowrap" id="pills-scorecard-tab"
                    data-bs-toggle="pill" data-bs-target="#pills-scorecard">Scorecard</button></li>
            <li class="nav-item flex-fill"><button class="nav-link w-100 text-nowrap" id="pills-teams-tab"
                    data-bs-toggle="pill" data-bs-target="#pills-teams">Teams</button></li>
            <li class="nav-item flex-fill"><button class="nav-link w-100 text-nowrap" id="pills-commentary-tab"
                    data-bs-toggle="pill" data-bs-target="#pills-commentary">Commentary</button></li>
        </ul>

        <div class="tab-content" id="pills-tabContent">

            <!-- Summary Tab -->
            <div class="tab-pane fade show active" id="pills-summary">
                <div class="row g-4">
                    <!-- Match Info Section -->
                    <div class="col-12">
                        <div class="summary-card border-0 shadow-sm overflow-hidden p-0">
                            <div
                                class="bg-light border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-3 text-primary">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <h6 class="text-uppercase text-dark fw-800 mb-0 letter-spacing-1"
                                        style="font-size: 0.9rem;">Match Intelligence</h6>
                                </div>
                            </div>

                            <div class="row g-0">
                                <!-- Column 1: Tournament & Format -->
                                <div class="col-lg-4 border-end">
                                    <div class="p-4">
                                        <div class="mb-4">
                                            <small
                                                class="text-muted text-uppercase fw-bold letter-spacing-1 d-block mb-3">Event
                                                Details</small>
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="bg-warning bg-opacity-10 rounded-3 p-3 text-warning me-3">
                                                    <i class="fas fa-trophy fa-lg"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-800 text-dark">
                                                        <?= htmlspecialchars($match['tournament_name'] ?? 'N/A') ?>
                                                    </div>
                                                    <small class="text-muted"><?= $match['match_type'] ?> •
                                                        <?= $match['overs'] ?> Overs</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <small
                                                class="text-muted text-uppercase fw-bold letter-spacing-1 d-block mb-3">Venue</small>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-success bg-opacity-10 rounded-3 p-3 text-success me-3">
                                                    <i class="fas fa-map-marker-alt fa-lg"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-800 text-dark"><?= $match['venue'] ?></div>
                                                    <small class="text-muted">International Grade</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Column 2: Toss & Decision -->
                                <div class="col-lg-4 border-end">
                                    <div class="p-4">
                                        <small
                                            class="text-muted text-uppercase fw-bold letter-spacing-1 d-block mb-3">Toss
                                            Factor</small>
                                        <div class="bg-slate-50 rounded-4 p-4 border border-dashed text-center">
                                            <div class="bg-white shadow-sm d-inline-block rounded-circle p-2 mb-3"
                                                style="width: 70px; height: 70px;">
                                                <img src="uploads/teams/<?= $match['toss_winner_logo'] ?>"
                                                    style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;"
                                                    onerror="this.src='assets/images/default-team.png'">
                                            </div>
                                            <div class="fw-800 text-dark mb-1">
                                                <?= htmlspecialchars($match['toss_winner_name'] ?? 'N/A') ?>
                                            </div>
                                            <p class="small text-muted mb-0">Won the toss & elected to <span
                                                    class="badge bg-primary px-3 py-2 rounded-pill"><?= $match['toss_decision'] ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Column 3: Timing -->
                                <div class="col-lg-4">
                                    <div class="p-4 h-100 d-flex flex-column justify-content-center">
                                        <small
                                            class="text-muted text-uppercase fw-bold letter-spacing-1 d-block mb-3 text-center">Timestamp</small>
                                        <div class="d-flex flex-column gap-3">
                                            <div
                                                class="bg-white border rounded-4 p-3 shadow-xs d-flex align-items-center transition-hover">
                                                <i class="far fa-calendar-check text-primary me-3 fa-lg"></i>
                                                <span
                                                    class="fw-700"><?= date('D, j F Y', strtotime($match['match_date'])) ?></span>
                                            </div>
                                            <div
                                                class="bg-white border rounded-4 p-3 shadow-xs d-flex align-items-center transition-hover">
                                                <i class="far fa-clock text-primary me-3 fa-lg"></i>
                                                <span
                                                    class="fw-700"><?= date('h:i A', strtotime($match['match_time'])) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Awards Section -->
                    <?php if ($potm): ?>
                        <div class="col-md-4">
                            <div class="summary-card award-card potm h-100" onclick="showPlayerOverlay(<?= $potm['id'] ?>)">
                                <div class="badge bg-warning text-dark mb-4 py-2 px-3 rounded-pill fw-800">
                                    <i class="fas fa-crown me-2"></i>PLAYER OF THE MATCH
                                </div>
                                <div class="position-relative d-inline-block mb-4">
                                    <img src="uploads/users/<?= $potm['profile_image'] ?>" class="award-player-img"
                                        onerror="this.src='assets/images/default-player.png'">
                                    <div class="position-absolute bottom-0 end-0 bg-white shadow rounded-circle"
                                        style="width: 45px; height: 45px; padding: 2px; display: flex; align-items: center; justify-content: center;">
                                        <img src="uploads/teams/<?= $match[$potm['team_id'] == $match['team1_id'] ? 'team1_logo' : 'team2_logo'] ?>"
                                            style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;">
                                    </div>
                                </div>
                                <h4 class="fw-800 mb-1"><?= $potm['name'] ?></h4>
                                <p class="text-primary fw-600 small mb-4"><?= $potm['team_name'] ?></p>

                                <div class="row g-2 mt-auto">
                                    <div class="col-4">
                                        <div class="stat-box py-2 px-1">
                                            <div class="small text-muted mb-1">Runs</div>
                                            <div class="fw-800"><?= $potm['total_runs'] ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-box py-2 px-1">
                                            <div class="small text-muted mb-1">Wkts</div>
                                            <div class="fw-800"><?= $potm['total_wickets'] ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-box py-2 px-1">
                                            <div class="small text-muted mb-1">Impact</div>
                                            <div class="fw-800 text-danger">
                                                <?= number_format($potm['total_potm_score'], 0) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($best_batter): ?>
                        <div class="col-md-4">
                            <div class="summary-card award-card h-100"
                                onclick="showPlayerOverlay(<?= $best_batter['id'] ?>)">
                                <div class="badge bg-primary text-white mb-4 py-2 px-3 rounded-pill fw-800">
                                    <i class="fas fa-baseball-ball me-2"></i>BEST BATTER
                                </div>
                                <div class="mb-4">
                                    <img src="uploads/users/<?= $best_batter['profile_image'] ?>" class="award-player-img"
                                        onerror="this.src='assets/images/default-player.png'">
                                </div>
                                <h4 class="fw-800 mb-1"><?= $best_batter['name'] ?></h4>
                                <p class="text-primary fw-600 small mb-4"><?= $best_batter['team_name'] ?></p>

                                <div class="bg-light rounded-3 p-3 mt-auto">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted small">Runs Scored</span>
                                        <span class="fw-800"><?= $best_batter['runs_scored'] ?>
                                            (<?= $best_batter['balls_faced'] ?>)</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted small">Strike Rate</span>
                                        <span
                                            class="fw-800 text-success"><?= number_format($best_batter['strike_rate'], 1) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted small">Boundaries</span>
                                        <span class="fw-800"><?= $best_batter['fours'] ?>x4,
                                            <?= $best_batter['sixes'] ?>x6</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($best_bowler): ?>
                        <div class="col-md-4">
                            <div class="summary-card award-card h-100"
                                onclick="showPlayerOverlay(<?= $best_bowler['id'] ?>)">
                                <div class="badge bg-success text-white mb-4 py-2 px-3 rounded-pill fw-800">
                                    <i class="fas fa-bowling-ball me-2"></i>BEST BOWLER
                                </div>
                                <div class="mb-4">
                                    <img src="uploads/users/<?= $best_bowler['profile_image'] ?>" class="award-player-img"
                                        onerror="this.src='assets/images/default-player.png'">
                                </div>
                                <h4 class="fw-800 mb-1"><?= $best_bowler['name'] ?></h4>
                                <p class="text-primary fw-600 small mb-4"><?= $best_bowler['team_name'] ?></p>

                                <div class="bg-light rounded-3 p-3 mt-auto">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted small">Bowling Figures</span>
                                        <span class="fw-800 text-danger"><?= $best_bowler['wickets_taken'] ?> /
                                            <?= $best_bowler['runs_conceded'] ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted small">Overs Bowled</span>
                                        <span class="fw-800"><?= $best_bowler['overs_bowled'] ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted small">Economy</span>
                                        <span
                                            class="fw-800 text-primary"><?= number_format($best_bowler['economy_rate'], 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>


                    <!-- Key Partnerships Section -->
                    <div class="col-12">
                        <div class="summary-card border-0 shadow-sm p-0 overflow-hidden">
                            <div class="bg-light border-bottom py-3 px-4 d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 rounded-circle p-2 me-3 text-danger">
                                    <i class="fas fa-handshake"></i>
                                </div>
                                <h6 class="text-uppercase text-dark fw-800 mb-0 letter-spacing-1"
                                    style="font-size: 0.9rem;">Major Partnerships</h6>
                            </div>
                            <div class="p-4">
                                <div class="row g-4">
                                    <?php foreach ($innings_data as $inn): ?>
                                        <div class="col-lg-6">
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="badge bg-secondary bg-opacity-10 text-secondary me-2">
                                                    <?= $inn['inning_number'] <= 2 ? $inn['inning_number'] . ($inn['inning_number'] == 1 ? 'st' : 'nd') . ' Innings' : 'Super Over' ?>
                                                </div>
                                                <div class="fw-800 text-dark">
                                                    <?= ($inn['batting_team_id'] == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'] ?>
                                                </div>
                                            </div>

                                            <?php if (empty($partnerships_data[$inn['inning_number']])): ?>
                                                <div class="text-muted small italic py-2">No significant partnerships recorded.
                                                </div>
                                            <?php else: ?>
                                                <div class="d-flex flex-column gap-3">
                                                    <?php foreach ($partnerships_data[$inn['inning_number']] as $p): ?>
                                                        <div
                                                            class="position-relative p-3 rounded-4 bg-slate-50 border border-light">
                                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                                <div class="d-flex align-items-center gap-2">
                                                                    <div class="d-flex -space-x-2">
                                                                        <img src="<?= $p['p1_img'] ?>"
                                                                            class="rounded-circle border-2 border-white shadow-sm"
                                                                            style="width: 32px; height: 32px; object-fit: cover;">
                                                                        <img src="<?= $p['p2_img'] ?>"
                                                                            class="rounded-circle border-2 border-white shadow-sm"
                                                                            style="width: 32px; height: 32px; object-fit: cover; margin-left: -12px;">
                                                                    </div>
                                                                    <div class="small fw-700"><?= $p['p1_name'] ?> &
                                                                        <?= $p['p2_name'] ?>
                                                                    </div>
                                                                </div>
                                                                <div class="text-end">
                                                                    <div class="fw-800 text-primary h5 mb-0"><?= $p['runs'] ?>
                                                                        <small class="text-muted fw-normal"
                                                                            style="font-size: 0.7rem;">(<?= $p['balls'] ?>)</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="progress" style="height: 6px; background: #e2e8f0;">
                                                                <?php
                                                                $p1_pct = $p['runs'] > 0 ? ($p['p1_runs'] / $p['runs']) * 100 : 50;
                                                                ?>
                                                                <div class="progress-bar bg-primary" role="progressbar"
                                                                    style="width: <?= $p1_pct ?>%"></div>
                                                                <div class="progress-bar bg-info" role="progressbar"
                                                                    style="width: <?= 100 - $p1_pct ?>%"></div>
                                                            </div>
                                                            <div class="d-flex justify-content-between mt-1"
                                                                style="font-size: 0.6rem; font-weight: 700; color: #64748b;">
                                                                <span><?= $p['p1_runs'] ?> RUNS</span>
                                                                <span><?= $p['p2_runs'] ?> RUNS</span>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scorecard Tab -->
            <div class="tab-pane fade" id="pills-scorecard">
                <?php foreach ($innings_data as $inn):
                    $inn_num = $inn['inning_number'];
                    $bat_team_name = ($inn['batting_team_id'] == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
                    $bat_team_logo = ($inn['batting_team_id'] == $match['team1_id']) ? $match['team1_logo'] : $match['team2_logo'];

                    // Fetch Batting Stats
                    $stmt = $pdo->prepare("SELECT u.name, u.profile_image, u.id as player_id, ms.* FROM match_statistics ms JOIN users u ON ms.player_id = u.id WHERE ms.match_id = ? AND ms.inning_number = ? AND ms.team_id = ?");
                    $stmt->execute([$match_id, $inn_num, $inn['batting_team_id']]);
                    $batting = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Fetch Bowling Stats
                    $stmt->execute([$match_id, $inn_num, $inn['bowling_team_id']]);
                    $bowling = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <div class="inning-header <?= $inn_num >= 3 ? 'inning-header-so' : '' ?> mb-3" data-bs-toggle="collapse"
                        data-bs-target="#inning<?= $inn_num ?>">
                        <!-- Desktop Layout (Hidden on Mobile) -->
                        <div class="d-none d-md-flex align-items-center justify-content-between w-100">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-white rounded-3 shadow-sm" style="padding: 2px;">
                                    <img src="uploads/teams/<?= $bat_team_logo ?>"
                                        style="width: 32px; height: 32px; object-fit: contain;">
                                </div>
                                <h5 class="mb-0">
                                    <?php
                                    if ($inn_num == 1)
                                        echo '1st Innings';
                                    elseif ($inn_num == 2)
                                        echo '2nd Innings';
                                    elseif ($inn_num == 3)
                                        echo 'Super Over 1st';
                                    elseif ($inn_num == 4)
                                        echo 'Super Over 2nd';
                                    ?>
                                    <span class="text-muted fw-normal mx-2">|</span> <?= $bat_team_name ?>
                                </h5>
                            </div>
                            <div class="d-flex align-items-center gap-4">
                                <div class="text-end">
                                    <span class="h4 fw-800 mb-0"><?= $inn['total_runs'] ?>-<?= $inn['wickets'] ?></span>
                                    <div class="small text-muted font-monospace">(<?= $inn['overs_bowled'] ?> Ov)</div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </div>
                        </div>

                        <!-- Mobile Layout (Hidden on Desktop) -->
                        <div class="d-flex d-md-none align-items-center w-100">
                            <!-- Left Section: Logo & Inning -->
                            <div class="header-col-left">
                                <div class="bg-white rounded-3 shadow-sm header-logo-container" style="padding: 2px;">
                                    <img src="uploads/teams/<?= $bat_team_logo ?>"
                                        style="width: 100%; height: 100%; object-fit: contain;">
                                </div>
                                <div class="header-inn-label">
                                    <?php
                                    if ($inn_num == 1)
                                        echo '1st Innings';
                                    elseif ($inn_num == 2)
                                        echo '2nd Innings';
                                    elseif ($inn_num == 3)
                                        echo 'Super Over 1st';
                                    elseif ($inn_num == 4)
                                        echo 'Super Over 2nd';
                                    ?>
                                </div>
                            </div>

                            <!-- Center Section: Team Name -->
                            <div class="header-col-center">
                                <div class="header-team-name"><?= $bat_team_name ?></div>
                            </div>

                            <!-- Right Section: Score & Overs -->
                            <div class="header-col-right">
                                <div class="header-score"><?= $inn['total_runs'] ?>-<?= $inn['wickets'] ?></div>
                                <div class="header-overs">(<?= $inn['overs_bowled'] ?> /
                                    <?= ($inn_num >= 3) ? '1.0' : ($match['overs'] ?? '8.0') ?>
                                    Ov)
                                </div>
                            </div>

                            <i class="fas fa-chevron-down text-muted chevron-icon"></i>
                        </div>
                    </div>

                    <div class="collapse <?= $inn_num == 1 ? 'show' : '' ?> mb-5" id="inning<?= $inn_num ?>">
                        <div class="summary-card shadow-sm p-0 overflow-hidden border-0">
                            <!-- Batting Table (Desktop) -->
                            <div class="table-responsive sc-table-wrap">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Batter</th>
                                            <th class="text-center" style="width: 55px;">R</th>
                                            <th class="text-center" style="width: 55px;">B</th>
                                            <th class="text-center" style="width: 45px;">4s</th>
                                            <th class="text-center" style="width: 45px;">6s</th>
                                            <th class="text-center" style="width: 65px;">SR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batting as $b): ?>
                                            <tr class="player-row" onclick="showPlayerOverlay(<?= $b['player_id'] ?>)">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <img src="uploads/users/<?= $b['profile_image'] ?>"
                                                            class="player-image-sm"
                                                            onerror="this.src='assets/images/default-player.png'">
                                                        <div>
                                                            <div class="fw-700 text-dark"><?= $b['name'] ?></div>
                                                            <small class="text-muted font-monospace"
                                                                style="font-size: 0.75rem;">
                                                                <?= getDismissalText($dismissal_map[$inn_num][$b['player_id']] ?? null) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center fw-800 fs-5"><?= $b['runs_scored'] ?></td>
                                                <td class="text-center text-muted"><?= $b['balls_faced'] ?></td>
                                                <td class="text-center"><?= $b['fours'] ?></td>
                                                <td class="text-center"><?= $b['sixes'] ?></td>
                                                <td class="text-center fw-600"><?= number_format($b['strike_rate'], 1) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Batting Card List (Mobile ≤460px) -->
                            <div class="sc-mobile-list">
                                <div class="sc-mobile-header">
                                    <div class="sc-player-col">Batter</div>
                                    <div class="sc-stat">R</div>
                                    <div class="sc-stat">B</div>
                                    <div class="sc-stat">4s</div>
                                    <div class="sc-stat">6s</div>
                                    <div class="sc-stat">SR</div>
                                </div>
                                <?php foreach ($batting as $b): ?>
                                    <div class="sc-mobile-row" onclick="showPlayerOverlay(<?= $b['player_id'] ?>)">
                                        <div class="sc-player-col">
                                            <img src="uploads/users/<?= $b['profile_image'] ?>" class="sc-player-img"
                                                onerror="this.src='assets/images/default-player.png'">
                                            <div class="sc-player-info">
                                                <div class="sc-player-name"><?= $b['name'] ?></div>
                                                <div class="sc-dismissal"><?= strip_tags(getDismissalText($dismissal_map[$inn_num][$b['player_id']] ?? null), '<span>') ?></div>
                                            </div>
                                        </div>
                                        <div class="sc-stat sc-runs"><?= $b['runs_scored'] ?></div>
                                        <div class="sc-stat text-muted"><?= $b['balls_faced'] ?></div>
                                        <div class="sc-stat"><?= $b['fours'] ?></div>
                                        <div class="sc-stat"><?= $b['sixes'] ?></div>
                                        <div class="sc-stat fw-600"><?= number_format($b['strike_rate'], 1) ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php
                                    // Extras & Total footer for mobile batting
                                    $inn_extras = $inn['extras'] ?? 0;
                                    $inn_total  = $inn['total_runs'] . '/' . $inn['wickets'];
                                    $inn_ov     = $inn['overs_bowled'];
                                ?>
                                <div class="sc-mobile-footer">
                                    <span>Extras: <?= $inn_extras ?></span>
                                    <span>Total: <?= $inn_total ?> (<?= $inn_ov ?>ov)</span>
                                </div>
                            </div>

                            <!-- Bowling Section -->
                            <div class="bg-light border-top border-bottom py-2 px-4">
                                <h6 class="mb-0 text-uppercase fw-800 text-primary letter-spacing-1"
                                    style="font-size: 0.75rem;">Bowling Attack</h6>
                            </div>

                            <!-- Bowling Table (Desktop) -->
                            <div class="table-responsive sc-table-wrap">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Bowler</th>
                                            <th class="text-center" style="width: 45px;">O</th>
                                            <th class="text-center" style="width: 40px;">M</th>
                                            <th class="text-center" style="width: 45px;">R</th>
                                            <th class="text-center" style="width: 45px;">W</th>
                                            <th class="text-center" style="width: 60px;">Econ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bowling as $b): ?>
                                            <?php if ($b['overs_bowled'] > 0): ?>
                                                <tr class="player-row" onclick="showPlayerOverlay(<?= $b['player_id'] ?>)">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <img src="uploads/users/<?= $b['profile_image'] ?>"
                                                                class="player-image-sm"
                                                                onerror="this.src='assets/images/default-player.png'">
                                                            <span class="fw-700 text-dark"><?= $b['name'] ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="text-center fw-600"><?= $b['overs_bowled'] ?></td>
                                                    <td class="text-center"><?= $b['maidens'] ?></td>
                                                    <td class="text-center"><?= $b['runs_conceded'] ?></td>
                                                    <td class="text-center fw-800 text-danger fs-5"><?= $b['wickets_taken'] ?></td>
                                                    <?php
                                                    $econ = $b['economy_rate'];
                                                    if (($econ == 0 || empty($econ)) && $b['overs_bowled'] > 0) {
                                                        $ov_parts = explode('.', (string) $b['overs_bowled']);
                                                        $ov = isset($ov_parts[0]) ? (int) $ov_parts[0] : 0;
                                                        $bl = isset($ov_parts[1]) ? (int) $ov_parts[1] : 0;
                                                        $total_balls = ($ov * 6) + $bl;
                                                        if ($total_balls > 0) {
                                                            $econ = ($b['runs_conceded'] / $total_balls) * 6;
                                                        }
                                                    }
                                                    ?>
                                                    <td class="text-center fw-600"><?= number_format($econ, 2) ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Bowling Card List (Mobile ≤460px) -->
                            <div class="sc-mobile-list">
                                <div class="sc-mobile-header">
                                    <div class="sc-player-col">Bowler</div>
                                    <div class="sc-stat">O</div>
                                    <div class="sc-stat">M</div>
                                    <div class="sc-stat">R</div>
                                    <div class="sc-stat">W</div>
                                    <div class="sc-stat">Econ</div>
                                </div>
                                <?php foreach ($bowling as $b): ?>
                                    <?php if ($b['overs_bowled'] > 0):
                                        $econ = $b['economy_rate'];
                                        if (($econ == 0 || empty($econ)) && $b['overs_bowled'] > 0) {
                                            $ov_parts = explode('.', (string) $b['overs_bowled']);
                                            $bov = isset($ov_parts[0]) ? (int) $ov_parts[0] : 0;
                                            $bbl = isset($ov_parts[1]) ? (int) $ov_parts[1] : 0;
                                            $total_balls = ($bov * 6) + $bbl;
                                            if ($total_balls > 0) $econ = ($b['runs_conceded'] / $total_balls) * 6;
                                        }
                                    ?>
                                    <div class="sc-mobile-row" onclick="showPlayerOverlay(<?= $b['player_id'] ?>)">
                                        <div class="sc-player-col">
                                            <img src="uploads/users/<?= $b['profile_image'] ?>" class="sc-player-img"
                                                onerror="this.src='assets/images/default-player.png'">
                                            <div class="sc-player-info">
                                                <div class="sc-player-name"><?= $b['name'] ?></div>
                                            </div>
                                        </div>
                                        <div class="sc-stat fw-600"><?= $b['overs_bowled'] ?></div>
                                        <div class="sc-stat"><?= $b['maidens'] ?></div>
                                        <div class="sc-stat"><?= $b['runs_conceded'] ?></div>
                                        <div class="sc-stat sc-wkts"><?= $b['wickets_taken'] ?></div>
                                        <div class="sc-stat fw-600"><?= number_format($econ, 2) ?></div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Fall of Wickets -->
                            <?php if (!empty($fow_data[$inn_num])): ?>
                                <div class="bg-light border-top border-bottom py-2 px-4">
                                    <h6 class="mb-0 text-uppercase fw-800 text-muted letter-spacing-1"
                                        style="font-size: 0.75rem;">
                                        Fall of Wickets</h6>
                                </div>
                                <div class="p-4 bg-white">
                                    <div class="row row-cols-1 row-cols-md-3 g-3">
                                        <?php foreach ($fow_data[$inn_num] as $fow): ?>
                                            <div class="col">
                                                <div class="fow-card h-100">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3"
                                                        style="width: 28px; height: 28px; font-size: 0.8rem; font-weight: 900; flex-shrink: 0;">
                                                        <?= $fow['wicket'] ?>
                                                    </div>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="fw-800 text-dark text-truncate small"><?= $fow['player'] ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.7rem; font-weight: 600;">
                                                            <span class="text-primary"><?= $fow['score'] ?></span>
                                                            <span class="mx-1 opacity-50">•</span>
                                                            <?= $fow['over'] ?> Ov
                                                        </div>
                                                    </div>
                                                    <img src="<?= $fow['image'] ?>" class="rounded-circle ms-2 shadow-sm"
                                                        style="width: 32px; height: 32px; object-fit: cover; border: 2px solid white;"
                                                        onerror="this.src='assets/images/default-player.png'">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Teams Tab -->
            <div class="tab-pane fade" id="pills-teams">
                <div class="row g-4">
                    <?php
                    $teams_list = [
                        ['id' => $match['team1_id'], 'name' => $match['team1_name'], 'logo' => $match['team1_logo']],
                        ['id' => $match['team2_id'], 'name' => $match['team2_name'], 'logo' => $match['team2_logo']]
                    ];

                    foreach ($teams_list as $t):
                        ?>
                        <div class="col-md-6">
                            <div class="summary-card border-0 shadow-sm p-0 overflow-hidden">
                                <div class="bg-light border-bottom py-3 px-4 d-flex align-items-center gap-3">
                                    <img src="uploads/teams/<?= $t['logo'] ?>"
                                        style="width: 50px; height: 50px; object-fit: contain; border-radius: 5px;">
                                    <h6 class="mb-0 fw-800 text-dark" style="font-size: 1.5rem;"><?= $t['name'] ?></h6>
                                </div>

                                <div class="p-2">
                                    <?php
                                    $stmt = $pdo->prepare("SELECT u.id, u.name, u.profile_image, u.playing_role, ms.is_captain, ms.is_vice_captain as is_vc FROM match_squads ms JOIN users u ON ms.player_id = u.id WHERE ms.match_id = ? AND ms.team_id = ?");
                                    $stmt->execute([$match_id, $t['id']]);
                                    $squad = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                    usort($squad, function ($a, $b) {
                                        if ($a['is_captain'] && !$b['is_captain'])
                                            return -1;
                                        if (!$a['is_captain'] && $b['is_captain'])
                                            return 1;
                                        if ($a['is_vc'] && !$b['is_vc'])
                                            return -1;
                                        if (!$a['is_vc'] && $b['is_vc'])
                                            return 1;

                                        $role_order = [
                                            'batsman' => 1,
                                            'wicket-keeper' => 2,
                                            'wicketkeeper' => 2,
                                            'all-rounder' => 3,
                                            'allrounder' => 3,
                                            'bowler' => 4
                                        ];
                                        $order_a = $role_order[strtolower($a['playing_role'] ?? 'player')] ?? 4;
                                        $order_b = $role_order[strtolower($b['playing_role'] ?? 'player')] ?? 4;

                                        return ($order_a !== $order_b) ? ($order_a - $order_b) : strcasecmp($a['name'], $b['name']);
                                    });

                                    foreach ($squad as $row):
                                        $badge = '';
                                        if ($row['is_captain'])
                                            $badge = '<span class="badge bg-primary px-3 py-1 rounded-pill shadow-sm" style="font-size:0.6rem; font-weight: 800; letter-spacing: 0.5px;">CAPTAIN</span>';
                                        else if ($row['is_vc'])
                                            $badge = '<span class="badge bg-secondary px-3 py-1 rounded-pill shadow-sm" style="font-size:0.6rem; font-weight: 800; letter-spacing: 0.5px; background: #64748b !important;">V-CAPTAIN</span>';
                                        ?>
                                        <div class="d-flex align-items-center p-3 player-row border-bottom border-light transition-hover"
                                            onclick="showPlayerOverlay(<?= $row['id'] ?>)">
                                            <div class="position-relative">
                                                <img src="uploads/users/<?= $row['profile_image'] ?>"
                                                    class="player-image-sm rounded-4" style="width: 52px; height: 52px;"
                                                    onerror="this.src='assets/images/default-player.png'">
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="fw-800 text-dark"><?= $row['name'] ?></span>
                                                    <?= $badge ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="text-muted text-uppercase fw-700"
                                                        style="font-size: 0.65rem; letter-spacing: 0.05em;">
                                                        <?= htmlspecialchars($row['playing_role'] ?: 'Player') ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-muted opacity-25">
                                                <i class="fas fa-chevron-right"></i>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Commentary Tab -->
            <div class="tab-pane fade" id="pills-commentary">
                <div class="summary-card p-0 overflow-hidden border-0 shadow-sm">
                    <?php
                    $current_inn = -1;
                    if (empty($full_commentary)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-comment-slash fa-3x mb-3 opacity-25"></i>
                            <p>Detailed commentary is not available for this match.</p>
                        </div>
                    <?php else:
                        foreach (array_reverse($full_commentary) as $ball): // Show latest first? Actually traditional is chronological. Let's stick to chronological or user preference. Let's keep it chronological but styled well.
                            if ($ball['inning_number'] != $current_inn):
                                $current_inn = $ball['inning_number'];
                                if ($current_inn == 1)
                                    $inn_name = "First Innings";
                                elseif ($current_inn == 2)
                                    $inn_name = "Second Innings";
                                elseif ($current_inn == 3)
                                    $inn_name = "Super Over 1st Innings";
                                elseif ($current_inn == 4)
                                    $inn_name = "Super Over 2nd Innings";
                                else
                                    $inn_name = "Innings " . $current_inn;
                                ?>
                                <div class="inning-divider"><?= $inn_name ?></div>
                            <?php endif; ?>

                            <?php
                            $ballClass = 'bg-0';
                            $ballText = $ball['runs_scored'];
                            if ($ball['wicket_type']) {
                                $ballClass = 'bg-W';
                                $ballText = 'W';
                            } else if ($ball['runs_scored'] == 4) {
                                $ballClass = 'bg-4';
                                $ballText = '4';
                            } else if ($ball['runs_scored'] == 6) {
                                $ballClass = 'bg-6';
                                $ballText = '6';
                            } else if ($ball['extra_type'] == 'wide') {
                                $ballClass = 'bg-WD';
                                $ballText = 'WD';
                            } else if ($ball['extra_type'] == 'no ball') {
                                $ballClass = 'bg-NB';
                                $ballText = 'NB';
                            }
                            ?>

                            <div class="commentary-item">
                                <div class="ball-num"><?= $ball['over_number'] ?>.<?= $ball['ball_number'] ?></div>
                                <div class="ball-event-badge <?= $ballClass ?>"><?= $ballText ?></div>
                                <div class="flex-grow-1">
                                    <div class="mb-1">
                                        <span class="fw-800 text-dark"><?= $ball['bowler_name'] ?></span>
                                        <span class="text-muted mx-1">to</span>
                                        <span class="fw-800 text-dark"><?= $ball['batter_name'] ?></span>
                                    </div>
                                    <div class="text-secondary small line-height-1.5">
                                        <?= htmlspecialchars($ball['commentary']) ?>
                                        <?php if ($ball['wicket_type']): ?>
                                            <div class="mt-2">
                                                <span
                                                    class="badge bg-danger bg-opacity-10 text-danger fw-800 px-3 py-2 border border-danger border-opacity-25 rounded-pill">
                                                    OUT! (<?= strtoupper($ball['wicket_type']) ?>)
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach;
                    endif; ?>
                </div>
            </div>
        </div>
    </div>
</div><!-- End match-summary-wrapper -->

<!-- Player Profile Overlay -->
<div id="playerOverlay" class="player-overlay" onclick="closePlayerOverlay(event)">
    <div class="player-overlay-card" onclick="event.stopPropagation()">
        <button class="btn-close position-absolute top-0 end-0 m-4 shadow-none"
            onclick="closePlayerOverlay(event)"></button>

        <div class="text-center">
            <div class="position-relative d-inline-block mb-4">
                <img id="overlayImage" src="assets/images/default-player.png" class="overlay-img border shadow"
                    style="width: 120px; height: 120px; border-radius: 20px;">
            </div>
            <h3 id="overlayName" class="fw-800 mb-1">Player Name</h3>
            <p id="overlayTeam" class="text-primary fw-600 mb-4">Team Name</p>

            <div class="d-flex justify-content-center gap-2 mb-4 w-100">
                <div class="stat-box">
                    <div class="small text-muted mb-1">Matches</div>
                    <div id="statMatches" class="value">0</div>
                </div>
                <div class="stat-box">
                    <div class="small text-muted mb-1">Runs</div>
                    <div id="statRuns" class="value">0</div>
                </div>
                <div class="stat-box">
                    <div class="small text-muted mb-1">Wickets</div>
                    <div id="statWickets" class="value">0</div>
                </div>
            </div>

            <a id="viewProfileBtn" href="#" class="btn btn-primary w-100 py-3 rounded-pill fw-800 shadow-lg">
                View Detailed Stats
            </a>
        </div>
    </div>
</div>

<script>
    function showPlayerOverlay(playerId) {
        const overlay = document.getElementById('playerOverlay');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Clear old data and display loading state
        document.getElementById('overlayName').innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
        document.getElementById('overlayTeam').innerText = 'Fetching stats...';
        document.getElementById('overlayImage').src = 'assets/images/default-player.png';
        document.getElementById('statMatches').innerText = '-';
        document.getElementById('statRuns').innerText = '-';
        document.getElementById('statWickets').innerText = '-';
        document.getElementById('viewProfileBtn').style.display = 'none';

        fetch('view/get_player_modal_data.php?player_id=' + playerId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('overlayName').innerText = data.name;
                    document.getElementById('overlayTeam').innerText = data.team_name || 'No Team';
                    document.getElementById('overlayImage').src = data.profile_image;
                    
                    let profileBtn = document.getElementById('viewProfileBtn');
                    profileBtn.href = 'view/view_player_profile.php?player_id=' + playerId;
                    profileBtn.style.display = 'inline-block';
                    
                    document.getElementById('statMatches').innerText = data.matches || 0;
                    document.getElementById('statRuns').innerText = data.runs || 0;
                    document.getElementById('statWickets').innerText = data.wickets || 0;
                } else {
                    document.getElementById('overlayName').innerText = 'Failed to Load';
                    document.getElementById('overlayTeam').innerText = data.error || 'Server error';
                }
            })
            .catch(error => {
                document.getElementById('overlayName').innerText = 'Error';
                document.getElementById('overlayTeam').innerText = 'Network error check console';
            });
    }

    function closePlayerOverlay(e) {
        const overlay = document.getElementById('playerOverlay');
        overlay.classList.remove('show');
        setTimeout(() => {
            document.body.style.overflow = 'auto';
        }, 300);
    }
</script>

<?php require_once 'includes/footer.php'; ?>