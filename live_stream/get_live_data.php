<?php
require_once '../includes/db.php';
header('Content-Type: application/json');
error_reporting(0);

$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$match_id) {
    echo json_encode(['success' => false, 'message' => 'Match ID required']);
    exit();
}

try {
    // 1. Fetch Basic Match Info
    $stmt = $pdo->prepare("
        SELECT m.*, 
               t1.team_name as team1_name, t1.team_logo as team1_logo, t1.captain_id as team1_captain_id, t1.vice_captain_id as team1_vice_captain_id, t1.team_code as team1_code, t1.team_color as team1_color,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.captain_id as team2_captain_id, t2.vice_captain_id as team2_vice_captain_id, t2.team_code as team2_code, t2.team_color as team2_color,
               tw.team_name as toss_winner_name,
               tr.tournament_name, tr.venue as tournament_venue
        FROM matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN teams tw ON m.toss_winner = tw.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception("Match not found");
    }

    // 2. Fetch Current Innings
    $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number DESC LIMIT 1");
    $stmt->execute([$match_id]);
    $innings = $stmt->fetch(PDO::FETCH_ASSOC);

    // Determine Batting/Bowling Teams
    $batting_team_name = '';
    $batting_team_logo = '';
    $bowling_team_name = '';
    $bowling_team_logo = '';

    if ($innings) {
        if ($innings['batting_team_id'] == $match['team1_id']) {
            $batting_team_name = $match['team1_name'];
            $batting_team_logo = $match['team1_logo'];
            $bowling_team_name = $match['team2_name'];
            $bowling_team_logo = $match['team2_logo'];
        } else {
            $batting_team_name = $match['team2_name'];
            $batting_team_logo = $match['team2_logo'];
            $bowling_team_name = $match['team1_name'];
            $bowling_team_logo = $match['team1_logo'];
        }
    } else {
        // Default if no innings started yet
        $batting_team_name = $match['team1_name'];
        $batting_team_logo = $match['team1_logo'];
        $bowling_team_name = $match['team2_name'];
        $bowling_team_logo = $match['team2_logo'];
    }

    // 3. Helper to get player stats
    function getPlayerStats($pdo, $playerId)
    {
        if (!$playerId || $playerId == 0)
            return ['id' => 0, 'name' => 'Select Player', 'profile_image' => '', 'playing_role' => '', 'matches' => 0, 'runs' => 0, 'wickets' => 0];

        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.profile_image, u.playing_role,
                   (SELECT COUNT(DISTINCT ms_sq.match_id) 
                    FROM match_squads ms_sq 
                    JOIN matches m ON ms_sq.match_id = m.id 
                    WHERE ms_sq.player_id = u.id AND ms_sq.playing_11 = 1 AND m.status = 'completed') as matches,
                   (SELECT COALESCE(SUM(ms.runs_scored), 0) FROM match_statistics ms JOIN matches m ON ms.match_id = m.id WHERE ms.player_id = u.id AND m.status = 'completed') as runs,
                   (SELECT COALESCE(SUM(ms.wickets_taken), 0) FROM match_statistics ms JOIN matches m ON ms.match_id = m.id WHERE ms.player_id = u.id AND m.status = 'completed') as wickets
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->execute([$playerId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            if (!$res['name'] || trim($res['name']) === '') {
                $res['name'] = 'Player ' . $res['id'];
            }
            return $res;
        }
        return ['id' => $playerId, 'name' => 'Player (' . $playerId . ')', 'profile_image' => '', 'playing_role' => '', 'matches' => 0, 'runs' => 0, 'wickets' => 0];
    }

    // 4. Helper to get Match Stats
    function getMatchBattingStats($pdo, $matchId, $playerId, $inningNum)
    {
        $default = ['runs_scored' => 0, 'balls_faced' => 0, 'fours' => 0, 'sixes' => 0, 'strike_rate' => 0.0];
        if (!$playerId)
            return $default;
        $stmt = $pdo->prepare("SELECT runs_scored, balls_faced, fours, sixes, strike_rate FROM match_statistics WHERE match_id = ? AND player_id = ? AND inning_number = ?");
        $stmt->execute([$matchId, $playerId, $inningNum]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res : $default;
    }

    function getMatchBowlingStats($pdo, $matchId, $playerId, $inningNum)
    {
        $default = ['overs_bowled' => '0.0', 'runs_conceded' => 0, 'wickets_taken' => 0, 'economy_rate' => 0.0];
        if (!$playerId)
            return $default;
        $stmt = $pdo->prepare("
            SELECT overs_bowled, runs_conceded, GREATEST(0, wickets_taken) as wickets_taken, maidens,
                   COALESCE(CASE WHEN ((FLOOR(overs_bowled) * 6) + (overs_bowled * 10 % 10)) > 0 
                                 THEN (runs_conceded * 6) / ((FLOOR(overs_bowled) * 6) + (overs_bowled * 10 % 10)) 
                                 ELSE 0 END, 0) as economy_rate
            FROM match_statistics 
            WHERE match_id = ? AND player_id = ? AND inning_number = ?
        ");
        $stmt->execute([$matchId, $playerId, $inningNum]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res : $default;
    }

    // --- VIEW TRACKING LOGIC ---
    $viewer_id = '';
    if (isset($_COOKIE['cpt_viewer_id'])) {
        $viewer_id = $_COOKIE['cpt_viewer_id'];
    } elseif (isset($_REQUEST['viewer_id'])) {
        // Allow passing via query param if cookie fails
        $viewer_id = $_REQUEST['viewer_id'];
    } else {
        // If no cookie/param, we can't track UNIQUE session reliably for "Current" without inflating "Total".
        // Use IP + UserAgent as fallback? No, simpler to just generate one.
        // But if we generate here, we MUST output it or set cookie.
        // Since this is JSON, we can't set cookie easily if headers sent (though we haven't sent body yet).
        // Let's set cookie just in case.
        $viewer_id = bin2hex(random_bytes(16));
        setcookie('cpt_viewer_id', $viewer_id, time() + (86400 * 30), "/");
    }

    // Update/Insert Viewer
    if ($viewer_id) {
        $stmt = $pdo->prepare("
            INSERT INTO match_viewers (match_id, viewer_id, last_active) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE last_active = NOW()
        ");
        $stmt->execute([$match_id, $viewer_id]);
    }

    // Get Total Views (Unique Viewers)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT viewer_id) FROM match_viewers WHERE match_id = ?");
    $stmt->execute([$match_id]);
    $total_views = $stmt->fetchColumn();

    // Get Current Views (Active in last 60 seconds)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT viewer_id) FROM match_viewers WHERE match_id = ? AND last_active >= (NOW() - INTERVAL 60 SECOND)");
    $stmt->execute([$match_id]);
    $current_views = $stmt->fetchColumn();
    // ---------------------------

    $striker = getPlayerStats($pdo, $match['current_striker_id']);
    $non_striker = getPlayerStats($pdo, $match['current_non_striker_id']);
    $bowler = getPlayerStats($pdo, $match['current_bowler_id']) ?: null;

    $inning_num = $innings ? $innings['inning_number'] : 1;

    // Enrich with current match stats
    if ($striker)
        $striker['match_stats'] = getMatchBattingStats($pdo, $match_id, $striker['id'], $inning_num);
    if ($non_striker)
        $non_striker['match_stats'] = getMatchBattingStats($pdo, $match_id, $non_striker['id'], $inning_num);
    if ($bowler)
        $bowler['match_stats'] = getMatchBowlingStats($pdo, $match_id, $bowler['id'], $inning_num);

    // 5. Get Full Scorecard Data (Both Innings)
    $scorecard_data = [];

    // Fetch all innings for this match
    $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number ASC");
    $stmt->execute([$match_id]);
    $all_innings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_innings as $inn) {
        $inn_id = $inn['id'];
        $inn_num = $inn['inning_number'];
        $inn_bat_team = $inn['batting_team_id'];
        $inn_bowl_team = $inn['bowling_team_id'];

        $stmt = $pdo->prepare("
            SELECT u.name, u.profile_image, ms.player_id, ms.runs_scored, ms.balls_faced, ms.fours, ms.sixes, ms.strike_rate, ms.id as stat_id,
                   (SELECT COUNT(DISTINCT ms_sq.match_id) 
                    FROM match_squads ms_sq 
                    JOIN matches m ON ms_sq.match_id = m.id 
                    WHERE ms_sq.player_id = ms.player_id AND ms_sq.playing_11 = 1 AND m.status = 'completed') as matches_played,
                   (SELECT COALESCE(SUM(ms_inner.runs_scored), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = ms.player_id AND m.status = 'completed') as career_runs,
                   (SELECT COALESCE(SUM(ms_inner.wickets_taken), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = ms.player_id AND m.status = 'completed') as career_wickets
            FROM match_statistics ms
            LEFT JOIN users u ON ms.player_id = u.id
            WHERE ms.match_id = ? AND ms.inning_number = ? AND ms.team_id = ?
            ORDER BY ms.id ASC
        ");
        $stmt->execute([$match_id, $inn_num, $inn_bat_team]);
        $batting = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map for easy lookup
        $bat_map = [];
        foreach ($batting as $b) {
            $bat_map[$b['player_id']] = $b;
        }

        // INJECT: Current Striker/Non-Striker if missing from match_statistics
        if ($innings && $inn_num == $innings['inning_number']) {
            $cur_pids = array_filter([$match['current_striker_id'], $match['current_non_striker_id']]);
            foreach ($cur_pids as $pid) {
                if (!isset($bat_map[$pid])) {
                    $p_info = getPlayerStats($pdo, $pid);
                    $new_b = [
                        'name' => $p_info['name'],
                        'profile_image' => $p_info['profile_image'],
                        'player_id' => $pid,
                        'runs_scored' => 0,
                        'balls_faced' => 0,
                        'fours' => 0,
                        'sixes' => 0,
                        'strike_rate' => 0,
                        'stat_id' => 0
                    ];
                    $batting[] = $new_b;
                    $bat_map[$pid] = $new_b;
                }
            }
        }

        // Final Processing & Filtering
        $final_batting = [];
        foreach ($batting as $b) {
            $pid = $b['player_id'];
            if (!$pid)
                continue; // Skip generic entries

            // Fetch dismissal info
            $stmt = $pdo->prepare("
                SELECT bbb.wicket_type, u_bowler.name as bowler_name, u_fielder.name as fielder_name
                FROM ball_by_ball bbb
                LEFT JOIN users u_bowler ON bbb.bowler_id = u_bowler.id
                LEFT JOIN users u_fielder ON bbb.fielder_id = u_fielder.id
                WHERE bbb.match_id = ? AND bbb.inning_number = ? AND bbb.wicket_player_id = ?
                LIMIT 1
            ");
            $stmt->execute([$match_id, $inn_num, $pid]);
            $wicket = $stmt->fetch(PDO::FETCH_ASSOC);

            $dismissal = '';
            $is_out = false;
            if ($wicket) {
                $is_out = true;
                $type = $wicket['wicket_type'];
                $bw = $wicket['bowler_name'];
                $fd = $wicket['fielder_name'];
                if ($type == 'bowled')
                    $dismissal = "b $bw";
                elseif ($type == 'caught')
                    $dismissal = "c $fd b $bw";
                elseif ($type == 'lbw')
                    $dismissal = "lbw b $bw";
                elseif ($type == 'run out')
                    $dismissal = "run out ($fd)";
                elseif ($type == 'stumped')
                    $dismissal = "stumped ($fd) b $bw";
                elseif ($type == 'hit wicket')
                    $dismissal = "hit wicket b $bw";
                else
                    $dismissal = "out";
            } else {
                // If not out, check if currently batting or had some contribution
                $is_active = ($innings && $inn_num == $innings['inning_number'] && ($pid == $match['current_striker_id'] || $pid == $match['current_non_striker_id']));
                if ($is_active) {
                    $dismissal = "not out";
                } elseif ($b['runs_scored'] > 0 || $b['balls_faced'] > 0) {
                    $dismissal = "not out";
                } else {
                    // Skip players who haven't batted and aren't at the crease (solves persistent old non-striker issue)
                    continue;
                }
            }

            // Ensure name is clean
            if (empty($b['name']) || strpos($b['name'], 'Player') === 0) {
                $p_info = getPlayerStats($pdo, $pid);
                $b['name'] = $p_info['name'];
            }

            $b['dismissal'] = $dismissal;
            $final_batting[] = $b;
        }
        $batting = $final_batting;
        $stmt = $pdo->prepare("
            SELECT u.name, u.profile_image, ms.player_id, ms.overs_bowled, ms.runs_conceded, GREATEST(0, ms.wickets_taken) as wickets_taken, ms.maidens,
                   COALESCE(CASE WHEN ((FLOOR(ms.overs_bowled) * 6) + (ms.overs_bowled * 10 % 10)) > 0 
                                 THEN (ms.runs_conceded * 6) / ((FLOOR(ms.overs_bowled) * 6) + (ms.overs_bowled * 10 % 10)) 
                                 ELSE 0 END, 0) as economy_rate,
                   (SELECT COUNT(DISTINCT ms_sq.match_id) 
                    FROM match_squads ms_sq 
                    JOIN matches m ON ms_sq.match_id = m.id 
                    WHERE ms_sq.player_id = ms.player_id AND ms_sq.playing_11 = 1 AND m.status = 'completed') as matches_played,
                   (SELECT COALESCE(SUM(ms_inner.runs_scored), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = ms.player_id AND m.status = 'completed') as career_runs,
                   (SELECT COALESCE(SUM(ms_inner.wickets_taken), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = ms.player_id AND m.status = 'completed') as career_wickets
            FROM match_statistics ms
            JOIN users u ON ms.player_id = u.id
            WHERE ms.match_id = ? AND ms.inning_number = ? AND ms.team_id = ?
        ");
        $stmt->execute([$match_id, $inn_num, $inn_bowl_team]);
        $bowling = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // INJECT: Current Bowler if missing from match_statistics
        if ($innings && $inn_num == $innings['inning_number']) {
            $bid = $match['current_bowler_id'];
            if ($bid) {
                $found = false;
                foreach ($bowling as $b) {
                    if ($b['player_id'] == $bid) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $p_info = getPlayerStats($pdo, $bid);
                    $bowling[] = [
                        'name' => $p_info['name'],
                        'profile_image' => $p_info['profile_image'],
                        'player_id' => $bid,
                        'overs_bowled' => '0.0',
                        'runs_conceded' => 0,
                        'wickets_taken' => 0,
                        'economy_rate' => 0.0
                    ];
                }
            }
        }

        // Extras for this inning
        $stmt = $pdo->prepare("
            SELECT extra_type, SUM(extra_runs) as total 
            FROM ball_by_ball 
            WHERE match_id = ? AND inning_number = ? AND extra_type IS NOT NULL
            GROUP BY extra_type
        ");
        $stmt->execute([$match_id, $inn_num]);
        $ex_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $w = $ex_data['wide'] ?? 0;
        $nb = $ex_data['no ball'] ?? 0;
        $by = $ex_data['bye'] ?? 0;
        $lb = $ex_data['leg bye'] ?? 0;
        $t_ex = $w + $nb + $by + $lb;

        // Fall of Wickets for this inning
        $stmt = $pdo->prepare("
            SELECT bbb.runs_scored, bbb.extra_runs, bbb.over_number, bbb.ball_number, 
                   u.name as player_name, u.profile_image,
                   (SELECT SUM(runs_scored + extra_runs) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND id <= bbb.id) as team_runs,
                   (SELECT COUNT(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND id <= bbb.id AND wicket_type IS NOT NULL) as team_wickets
            FROM ball_by_ball bbb
            JOIN users u ON bbb.wicket_player_id = u.id
            WHERE bbb.match_id = ? AND bbb.inning_number = ? AND bbb.wicket_type IS NOT NULL
            ORDER BY bbb.id ASC
        ");
        $stmt->execute([$match_id, $inn_num, $match_id, $inn_num, $match_id, $inn_num]);
        $fow_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fow = [];
        foreach ($fow_raw as $f) {
            $fow[] = [
                'player_name' => $f['player_name'],
                'profile_image' => $f['profile_image'],
                'score' => $f['team_runs'] . "/" . $f['team_wickets'],
                'over' => $f['over_number'] . "." . $f['ball_number']
            ];
        }

        $scorecard_data[] = [
            'inning_number' => $inn_num,
            'batting_team' => $inn['batting_team_id'] == $match['team1_id'] ? $match['team1_name'] : $match['team2_name'],
            'bowling_team' => $inn['bowling_team_id'] == $match['team1_id'] ? $match['team1_name'] : $match['team2_name'],
            'batting' => $batting,
            'bowling' => $bowling,
            'runs' => $inn['total_runs'],
            'wickets' => $inn['wickets'],
            'overs' => $inn['overs_bowled'],
            'extras' => $t_ex,
            'extras_breakdown' => "($by b, $lb lb, $w w, $nb nb)",
            'fall_of_wickets' => $fow
        ];
    }

    // Full Match Commentary (All Innings)
    $stmt = $pdo->prepare("
        SELECT b.*, u1.name as batter_name, u1.profile_image as batter_image, 
               u2.name as bowler_name, u2.profile_image as bowler_image,
               u3.name as wicket_player_name, u3.profile_image as wicket_player_image
        FROM ball_by_ball b
        LEFT JOIN users u1 ON b.batsman_id = u1.id
        LEFT JOIN users u2 ON b.bowler_id = u2.id
        LEFT JOIN users u3 ON b.wicket_player_id = u3.id
        WHERE b.match_id = ?
        ORDER BY b.inning_number DESC, b.id DESC
    ");
    $stmt->execute([$match_id]);
    $full_commentary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recent_commentary = [];
    $current_over_balls = [];
    $last_over_bowler_id = 0;
    $extras_str = "0 (b 0, lb 0, w 0, nb 0)";
    $p_runs = 0;
    $p_balls = 0;

    if ($innings) {

        // 7. Recent Ball Commentary
        $stmt = $pdo->prepare("
            SELECT b.*, u1.name as batter_name, u1.profile_image as batter_image, 
                   u2.name as bowler_name, u2.profile_image as bowler_image,
                   u3.name as wicket_player_name, u3.profile_image as wicket_player_image
            FROM ball_by_ball b
            LEFT JOIN users u1 ON b.batsman_id = u1.id
            LEFT JOIN users u2 ON b.bowler_id = u2.id
            LEFT JOIN users u3 ON b.wicket_player_id = u3.id
            WHERE b.match_id = ? AND b.inning_number = ? 
            ORDER BY b.id DESC LIMIT 20
        ");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $recent_commentary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 8. Current Over Balls
        $last_ball = $recent_commentary[0] ?? null;
        $current_over_num = 0;
        if ($last_ball) {
            $current_over_num = $last_ball['over_number'];
        }

        $stmt = $pdo->prepare("SELECT * FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND over_number = ? ORDER BY id ASC");
        $stmt->execute([$match_id, $innings['inning_number'], $current_over_num]);
        $current_over_balls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get last finished over's bowler to prevent consecutive overs
        $stmt = $pdo->prepare("SELECT count(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND extra_type IS NULL");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $total_legal_balls = (int) $stmt->fetchColumn();

        if ($total_legal_balls > 0 && $total_legal_balls % 6 === 0) {
            $stmt = $pdo->prepare("SELECT bowler_id FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND extra_type IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$match_id, $innings['inning_number']]);
            $last_over_bowler_id = $stmt->fetchColumn();
        }

        // Calculate Extras
        $stmt = $pdo->prepare("
            SELECT extra_type, SUM(extra_runs) as total 
            FROM ball_by_ball 
            WHERE match_id = ? AND inning_number = ? AND extra_type IS NOT NULL
            GROUP BY extra_type
        ");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $extras_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $w = $extras_data['wide'] ?? 0;
        $nb = $extras_data['no ball'] ?? 0;
        $b = $extras_data['bye'] ?? 0;
        $lb = $extras_data['leg bye'] ?? 0;
        $total_extras = $w + $nb + $b + $lb;
        $extras_str = "$total_extras (b $b, lb $lb, w $w, nb $nb)";

        // 9. Partnership Calculation
        // Find the last wicket's ball ID
        $stmt = $pdo->prepare("SELECT MAX(id) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND wicket_type IS NOT NULL");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $last_wicket_id = (int) $stmt->fetchColumn() ?: 0;

        // Sum runs and count balls since that wicket
        $stmt = $pdo->prepare("
            SELECT SUM(runs_scored + extra_runs) as p_runs, 
                   COUNT(*) as p_balls 
            FROM ball_by_ball 
            WHERE match_id = ? AND inning_number = ? AND id > ? AND (extra_type != 'wide' OR extra_type IS NULL)
        ");
        $stmt->execute([$match_id, $innings['inning_number'], $last_wicket_id]);
        $partnership_data = $stmt->fetch(PDO::FETCH_ASSOC);

        $p_runs = (int) ($partnership_data['p_runs'] ?? 0);
        $p_balls = (int) ($partnership_data['p_balls'] ?? 0);

        // Individual partnership contributions
        $striker_p_runs = 0;
        $striker_p_balls = 0;
        $non_striker_p_runs = 0;
        $non_striker_p_balls = 0;

        $s_id = $match['current_striker_id'] ?? 0;
        $ns_id = $match['current_non_striker_id'] ?? 0;

        if ($s_id && $ns_id) {
            $stmt = $pdo->prepare("
                SELECT batsman_id, SUM(runs_scored) as runs, COUNT(*) as balls 
                FROM ball_by_ball 
                WHERE match_id = ? AND inning_number = ? AND id > ? AND (extra_type != 'wide' OR extra_type IS NULL)
                GROUP BY batsman_id
            ");
            $stmt->execute([$match_id, $innings['inning_number'], $last_wicket_id]);
            $indiv_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($indiv_stats as $is) {
                if ($is['batsman_id'] == $s_id) {
                    $striker_p_runs = (int) $is['runs'];
                    $striker_p_balls = (int) $is['balls'];
                } elseif ($is['batsman_id'] == $ns_id) {
                    $non_striker_p_runs = (int) $is['runs'];
                    $non_striker_p_balls = (int) $is['balls'];
                }
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.profile_image, u.playing_role, ms.team_id, ms.is_captain, ms.is_vice_captain as is_vc,
               COALESCE((SELECT COUNT(*) FROM ball_by_ball b 
                WHERE b.match_id = ms.match_id AND b.inning_number = ? AND b.wicket_player_id = u.id AND b.wicket_type IS NOT NULL), 0) as is_out,
               (SELECT COUNT(DISTINCT ms_sq.match_id) 
                FROM match_squads ms_sq 
                JOIN matches m ON ms_sq.match_id = m.id                    WHERE ms_sq.player_id = u.id AND ms_sq.playing_11 = 1 AND m.status = 'completed') as matches_played,
               (SELECT COALESCE(SUM(ms_inner.runs_scored), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = u.id AND m.status = 'completed') as career_runs,
               (SELECT COALESCE(SUM(ms_inner.wickets_taken), 0) FROM match_statistics ms_inner JOIN matches m ON ms_inner.match_id = m.id WHERE ms_inner.player_id = u.id AND m.status = 'completed') as career_wickets
        FROM match_squads ms
        JOIN users u ON ms.player_id = u.id
        WHERE ms.match_id = ?
    ");
    // Ensure $inn_num is defined properly using the innings array directly
    $inn_num = ($innings && isset($innings['inning_number'])) ? $innings['inning_number'] : 1;
    $stmt->execute([$inn_num, $match_id]);
    $all_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $team1_squad = array_values(array_filter($all_players, fn($p) => $p['team_id'] == $match['team1_id']));
    $team2_squad = array_values(array_filter($all_players, fn($p) => $p['team_id'] == $match['team2_id']));

    // Sort Squads: Captain -> VC -> Alphabetical
    $sortSquad = function ($a, $b) {
        if ($a['is_captain'] && !$b['is_captain'])
            return -1;
        if (!$a['is_captain'] && $b['is_captain'])
            return 1;
        if ($a['is_vc'] && !$b['is_vc'])
            return -1;
        if (!$a['is_vc'] && $b['is_vc'])
            return 1;
        return strcasecmp($a['name'], $b['name']);
    };

    usort($team1_squad, $sortSquad);
    usort($team2_squad, $sortSquad);

    // 7. Calculate status-related values
    $target = 0;
    if ($innings && $innings['inning_number'] == 2) {
        $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 1");
        $stmt->execute([$match_id]);
        $first_inn_runs = $stmt->fetchColumn();
        $target = ($first_inn_runs !== false) ? (int) $first_inn_runs + 1 : 0;
    } elseif ($innings && $innings['inning_number'] == 4) {
        $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 3");
        $stmt->execute([$match_id]);
        $third_inn_runs = $stmt->fetchColumn();
        $target = ($third_inn_runs !== false) ? (int) $third_inn_runs + 1 : 0;
    }

    $is_break = false;
    $is_finished = false;
    if ($innings && $innings['inning_number'] == 1) {
        // Break condition: 
        // 1. 1st innings over (wickets=10 or overs=max)
        // 2. OR current strikers are NULL (manual end)
        // AND we haven't started 2nd innings yet (which we know because $innings['inning_number'] is 1)

        $is_over = ($innings['wickets'] >= 10 || floatval($innings['overs_bowled']) >= ($match['total_overs'] ?? 20));
        $no_strikers = (!$match['current_striker_id'] && !$match['current_non_striker_id']);

        // Ensure some action has happened (runs > 0 or overs > 0 or wickets > 0) to avoid break at match start
        $has_started = ($innings['total_runs'] > 0 || floatval($innings['overs_bowled']) > 0 || $innings['wickets'] > 0);

        if ($has_started && ($is_over || $no_strikers)) {
            $is_break = true;
        }
    } elseif ($innings && $innings['inning_number'] == 2) {
        $is_over = ($innings['wickets'] >= 10 || floatval($innings['overs_bowled']) >= ($match['total_overs'] ?? 20));
        $target_reached = ($target > 0 && $innings['total_runs'] >= $target);
        if ($is_over || $target_reached) {
            $is_finished = true;
        }
    } elseif ($innings && $innings['inning_number'] == 3) {
        $is_over = ($innings['wickets'] >= 2 || floatval($innings['overs_bowled']) >= 1.0);
        $no_strikers = (!$match['current_striker_id'] && !$match['current_non_striker_id']);

        // Ensure some action has happened to avoid break at super over start
        $has_started = ($innings['total_runs'] > 0 || floatval($innings['overs_bowled']) > 0 || $innings['wickets'] > 0);

        if ($has_started && ($is_over || $no_strikers)) {
            $is_break = true;
            $target = 0; // No target to show yet during SO break
        }
    } elseif ($innings && $innings['inning_number'] == 4) {
        $is_over = ($innings['wickets'] >= 2 || floatval($innings['overs_bowled']) >= 1.0);
        $target_reached = ($target > 0 && $innings['total_runs'] >= $target);
        $no_strikers = (!$match['current_striker_id'] && !$match['current_non_striker_id']);

        // Ensure some action has happened to avoid finish at super over start
        $has_started = ($innings['total_runs'] > 0 || floatval($innings['overs_bowled']) > 0 || $innings['wickets'] > 0);

        if ($has_started && ($is_over || $target_reached || $no_strikers)) {
            $is_finished = true;
        }
    }

    echo json_encode([
        'success' => true,
        'last_over_bowler_id' => $last_over_bowler_id,
        'match_info' => [
            'team1_name' => $match['team1_name'] ?? '',
            'team1_logo' => $match['team1_logo'] ?? '',
            'team1_code' => $match['team1_code'] ?? '',
            'team1_color' => $match['team1_color'] ?? '#0d6efd',
            'team2_name' => $match['team2_name'] ?? '',
            'team2_logo' => $match['team2_logo'] ?? '',
            'team2_code' => $match['team2_code'] ?? '',
            'team2_color' => $match['team2_color'] ?? '#dc3545',
            'batting_team_name' => $batting_team_name,
            'batting_team_logo' => $batting_team_logo,
            'bowling_team_name' => $bowling_team_name,
            'bowling_team_logo' => $bowling_team_logo,
            'status' => $match['status'] ?? '',
            'updated_at' => $match['updated_at'] ?? '',
            'tournament_name' => $match['tournament_name'] ?? '',
            'venue' => $match['venue'] ?? '',
            'match_number' => $match['match_number'] ?? '',
            'toss_winner_name' => $match['toss_winner_name'] ?? '',
            'toss_decision' => $match['toss_decision'] ?? '',
            'overlay_id' => $match['overlay_id'] ?? 0,
            'overlay_type' => $match['overlay_type'] ?? '',
            'is_paused' => (int) ($match['is_paused'] ?? 0)
        ],
        'overwise_stats' => [
            'inning1' => $pdo->query("SELECT over_number, SUM(runs_scored + extra_runs) as runs FROM ball_by_ball WHERE match_id = $match_id AND inning_number = 1 GROUP BY over_number ORDER BY over_number ASC")->fetchAll(PDO::FETCH_ASSOC),
            'inning2' => $pdo->query("SELECT over_number, SUM(runs_scored + extra_runs) as runs FROM ball_by_ball WHERE match_id = $match_id AND inning_number = 2 GROUP BY over_number ORDER BY over_number ASC")->fetchAll(PDO::FETCH_ASSOC),
            'inning3' => $pdo->query("SELECT over_number, SUM(runs_scored + extra_runs) as runs FROM ball_by_ball WHERE match_id = $match_id AND inning_number = 3 GROUP BY over_number ORDER BY over_number ASC")->fetchAll(PDO::FETCH_ASSOC),
            'inning4' => $pdo->query("SELECT over_number, SUM(runs_scored + extra_runs) as runs FROM ball_by_ball WHERE match_id = $match_id AND inning_number = 4 GROUP BY over_number ORDER BY over_number ASC")->fetchAll(PDO::FETCH_ASSOC)
        ],
        'views' => [
            'total' => $total_views,
            'current' => $current_views
        ],
        'score' => [
            'runs' => (int) ($innings['total_runs'] ?? 0),
            'wickets' => (int) ($innings['wickets'] ?? 0),
            'overs' => $innings['overs_bowled'] ?? '0.0',
            'inning_number' => (int) ($innings['inning_number'] ?? 1),
            'total_overs' => ($innings && $innings['inning_number'] >= 3) ? 1 : (int) ($match['overs'] ?? 20),
            'batting_team_id' => (int) ($innings['batting_team_id'] ?? 0),
            'bowling_team_id' => (int) ($innings['bowling_team_id'] ?? 0),
            'is_finished' => $is_finished,
            'is_break' => $is_break,
            'target' => $target,
            'partnership' => [
                'runs' => $p_runs ?? 0,
                'balls' => $p_balls ?? 0,
                'striker_p_runs' => $striker_p_runs ?? 0,
                'striker_p_balls' => $striker_p_balls ?? 0,
                'non_striker_p_runs' => $non_striker_p_runs ?? 0,
                'non_striker_p_balls' => $non_striker_p_balls ?? 0
            ],
            'projected_score' => $innings && floatval($innings['overs_bowled']) > 0
                ? round(($innings['total_runs'] / (floor($innings['overs_bowled']) + (fmod($innings['overs_bowled'], 1) * 10 / 6))) * ($match['overs'] ?? 20))
                : 0,
            'required_runs' => ($innings && ($innings['inning_number'] == 2 || $innings['inning_number'] == 4)) ? max(0, $target - $innings['total_runs']) : 0,
            'balls_remaining' => ($innings && ($innings['inning_number'] == 2 || $innings['inning_number'] == 4)) ? (int) round(max(0, (($innings['inning_number'] == 4 ? 1 : $match['overs']) * 6) - (floor($innings['overs_bowled']) * 6 + round(fmod($innings['overs_bowled'], 1) * 10)))) : 0
        ],
        'current_players' => [
            'striker' => $striker,
            'non_striker' => $non_striker,
            'bowler' => $bowler
        ],
        'scorecard' => $scorecard_data,

        'squads' => [
            'team1' => $team1_squad,
            'team2' => $team2_squad
        ],
        'recent_commentary' => $recent_commentary,
        'full_commentary' => $full_commentary,
        'current_over' => $current_over_balls
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
