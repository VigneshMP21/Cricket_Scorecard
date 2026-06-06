<?php
require_once '../../includes/db.php';
require_once '../../includes/onesignal_utils.php';
require_once '../../includes/notification_banner_utils.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();


if (class_exists('Cloudinary\Configuration\Configuration')) {
    try {
        \Cloudinary\Configuration\Configuration::instance([
            'cloud' => [
                'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
                'api_key' => $_ENV['CLOUDINARY_API_KEY'] ?? "",
                'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
            ],
            'url' => [
                'secure' => true
            ]
        ]);
    } catch (Throwable $t) {
        error_log("Cloudinary Config Error: " . $t->getMessage());
    }
}


/**
 * Generates a premium match started banner from HTML/CSS
 */
function generateMatchStartedBanner($data)
{
    return generate_match_started_notification_banner((array) $data);

    $api_user = $_ENV['HCTI_USER_ID_MATCH_NOTI'] ?? '';
    $api_key = $_ENV['HCTI_API_KEY_MATCH_NOTI'] ?? '';

    if (empty($api_user) || empty($api_key)) {
        return null;
    }

    $t_name = htmlspecialchars($data['tournament_name']);
    $t_details = htmlspecialchars($data['toss_details']);
    $teamA_name = htmlspecialchars($data['teamA_name']);
    $teamB_name = htmlspecialchars($data['teamB_name']);
    $venue = htmlspecialchars($data['venue']);

    $html = "
    <div class='banner-container'>
        <div class='tournament-tag'>$t_name</div>
        <div class='toss-tag'>$t_details</div>
        <div class='match-main'>
            <div class='team-box'>
                <div class='logo-wrapper teamA-border'><img src='{$data['teamA_logo']}' /></div>
                <div class='team-name teamA-color'>$teamA_name</div>
            </div>
            <div class='vs-box'>
                <div class='vs-text'>VS</div>
                <div class='match-type'>{$data['match_type']}</div>
            </div>
            <div class='team-box'>
                <div class='logo-wrapper teamB-border'><img src='{$data['teamB_logo']}' /></div>
                <div class='team-name teamB-color'>$teamB_name</div>
            </div>
        </div>
        <div class='match-footer'>
            <div class='footer-item'>📍 $venue</div>
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 450px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 800px; height: 450px; 
        background: url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1777031427/Night-Lights-at-Narendra-Modi-Stadium_cbq1o1.webp') center/cover no-repeat;
        padding: 30px; color: white; display: flex; flex-direction: column; align-items: center;
        position: relative;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.45); z-index: 0;
    }
    .tournament-tag, .toss-tag, .match-main, .match-footer { position: relative; z-index: 1; }
    .tournament-tag { 
        background: rgba(0,0,0,0.7); 
        padding: 6px 20px; border-radius: 50px; font-weight: 600;
        font-size: 14px; color: #fbbf24; text-transform: uppercase; letter-spacing: 2px;
        border: 1px solid rgba(251, 191, 36, 0.3); margin-bottom: 8px;
    }
    .toss-tag {
        background: rgba(16, 185, 129, 0.2);
        padding: 4px 15px; border-radius: 4px; font-weight: 700;
        font-size: 16px; color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);
        margin-bottom: 15px; text-transform: capitalize;
    }
    .match-main { display: flex; align-items: center; justify-content: space-around; width: 100%; flex-grow: 1; }
    .team-box { text-align: center; width: 250px; }
    .logo-wrapper { 
        width: 120px; height: 120px; background: rgba(255,255,255,0.1); 
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        margin: 0 auto 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    }
    .teamA-border { border: 3px solid {$data['teamA_color']}; }
    .teamB-border { border: 3px solid {$data['teamB_color']}; }
    .logo-wrapper img { width: 90px; height: 90px; object-fit: contain; }
    .team-name { font-size: 28px; font-weight: 800; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); }
    .teamA-color { color: {$data['teamA_color']}; }
    .teamB-color { color: {$data['teamB_color']}; }
    .vs-box { text-align: center; }
    .vs-text { font-size: 60px; font-weight: 900; color: #ef4444; line-height: 1; text-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
    .match-type { font-size: 14px; color: white; font-weight: 600; text-transform: uppercase; margin-top: 5px; }
    .match-footer { 
        display: flex; justify-content: center; width: 100%;
        padding-top: 15px; background: rgba(0,0,0,0.6); border-radius: 10px;
    }
    .footer-item { font-size: 18px; color: white; font-weight: 600; display: flex; align-items: center; gap: 8px; padding: 10px; }";

    $ch = curl_init('https://hcti.io/v1/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['html' => $html, 'css' => $css]));
    curl_setopt($ch, CURLOPT_USERPWD, $api_user . ':' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    $image_url = $res['url'] ?? null;

    if ($image_url && class_exists('\Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $cloud_res = $uploadApi->upload($image_url, [
                'folder' => 'match_started_banners',
                'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ''
            ]);
            return $cloud_res['secure_url'];
        } catch (Exception $e) {
            return $image_url;
        }
    }
    return $image_url;
}

function generateMatchStartedBannerHtml2Image(array $data): ?string
{
    return generate_match_started_notification_banner($data);
}


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

$match_id = (int) $_POST['match_id'];

if (!$match_id || !isset($_SESSION['match_setup'][$match_id])) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

$setup = $_SESSION['match_setup'][$match_id];
$toss_winner_id = $setup['toss_winner'];
$toss_choice = $setup['toss_choice'];

try {
    $pdo->beginTransaction();

    // 1. Update Match status and toss details
    $stmt = $pdo->prepare("
        UPDATE matches 
        SET toss_winner = ?, 
            toss_decision = ?, 
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$toss_winner_id, $toss_choice, $match_id]);


    // 2. Fetch match teams to determine opponent
    $stmt = $pdo->prepare("SELECT team1_id, team2_id FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    $opponent_id = ($toss_winner_id == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];

    // 3. Clear existing data for this match (if any, to allow re-starting)
    $stmt = $pdo->prepare("DELETE FROM ball_by_ball WHERE match_id = ?");
    $stmt->execute([$match_id]);

    $stmt = $pdo->prepare("DELETE FROM match_statistics WHERE match_id = ?");
    $stmt->execute([$match_id]);

    $stmt = $pdo->prepare("DELETE FROM innings WHERE match_id = ?");
    $stmt->execute([$match_id]);

    $stmt = $pdo->prepare("DELETE FROM match_squads WHERE match_id = ?");
    $stmt->execute([$match_id]);

    // 4. Insert Team 1 Squad (Toss Winner)
    $team1 = $setup['team1'];
    foreach ($team1['players'] as $player_id) {
        $is_captain = ($player_id == $team1['captain_id']) ? 1 : 0;
        $is_vc = ($player_id == $team1['vice_captain_id']) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO match_squads (match_id, team_id, player_id, playing_11, is_captain, is_vice_captain) 
            VALUES (?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$match_id, $team1['team_id'], $player_id, $is_captain, $is_vc]);

        // Ensure player has a record in player_stats
        $pdo->prepare("INSERT IGNORE INTO player_stats (player_id) VALUES (?)")->execute([$player_id]);
    }

    // 5. Insert Team 2 Squad (Opponent)
    $team2 = $setup['team2'];
    foreach ($team2['players'] as $player_id) {
        $is_captain = ($player_id == $team2['captain_id']) ? 1 : 0;
        $is_vc = ($player_id == $team2['vice_captain_id']) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO match_squads (match_id, team_id, player_id, playing_11, is_captain, is_vice_captain) 
            VALUES (?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$match_id, $team2['team_id'], $player_id, $is_captain, $is_vc]);

        // Ensure player has a record in player_stats
        $pdo->prepare("INSERT IGNORE INTO player_stats (player_id) VALUES (?)")->execute([$player_id]);
    }

    // 6. Initialize 1st Innings
    $batting_team_id = ($toss_choice == 'bat') ? $toss_winner_id : $opponent_id;
    $bowling_team_id = ($toss_choice == 'bat') ? $opponent_id : $toss_winner_id;

    $stmt = $pdo->prepare("
        INSERT INTO innings (match_id, inning_number, batting_team_id, bowling_team_id, total_runs, wickets, overs_bowled)
        VALUES (?, 1, ?, ?, 0, 0, '0.0')
    ");
    $stmt->execute([$match_id, $batting_team_id, $bowling_team_id]);

    $pdo->commit();

    // 🔔 Trigger Toss Notification
    try {
        // Fetch full match details for the banner
        $stmtDetails = $pdo->prepare("
            SELECT m.*, 
                   t1.team_name as t1n, t1.team_code as t1c, t1.team_logo_public_id as t1l, t1.team_color as t1col,
                   t2.team_name as t2n, t2.team_code as t2c, t2.team_logo_public_id as t2l, t2.team_color as t2col,
                   tn.tournament_name, tn.tournament_logo_public_id
            FROM matches m 
            JOIN teams t1 ON m.team1_id = t1.id 
            JOIN teams t2 ON m.team2_id = t2.id 
            LEFT JOIN tournaments tn ON m.tournament_id = tn.id
            WHERE m.id = ?");
        $stmtDetails->execute([$match_id]);
        $match_info = $stmtDetails->fetch(PDO::FETCH_ASSOC);

        if ($match_info) {
            $toss_winner_name = ($toss_winner_id == $match_info['team1_id']) ? $match_info['t1n'] : $match_info['t2n'];
            
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $base_url = "$protocol://$host/CPT_LEAGUE/";

            // Prepare banner data
            $toss_text = "{$toss_winner_name} won the toss and elected to {$toss_choice} first";
            $banner_data = [
                'tournament_name' => $match_info['tournament_name'] ?: 'Cricket Tournament',
                'toss_details' => $toss_text,
                'teamA_name' => $match_info['t1n'],
                'teamB_name' => $match_info['t2n'],
                'teamA_logo' => "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/" . ($match_info['t1l'] ?: 'default_team_logo'),
                'teamB_logo' => "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/" . ($match_info['t2l'] ?: 'default_team_logo'),
                'teamA_color' => $match_info['t1col'] ?: '#fbbf24',
                'teamB_color' => $match_info['t2col'] ?: '#fbbf24',
                'venue' => $match_info['venue'],
                'match_type' => $match_info['match_type']
            ];

            $match_started_banner = generateMatchStartedBannerHtml2Image($banner_data);

            // Fetch Tournament Logo for large_icon
            $tournament_logo_url = $match_info['tournament_logo_public_id'] ? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/" . $match_info['tournament_logo_public_id'] : ($base_url . "assets/images/logo.jpg");

            $all_ids = array_unique(array_merge(
                $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE onesignal_player_id IS NOT NULL AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN),
                getGuestPlayerIds($pdo)
            ));

            if (!empty($all_ids)) {
                sendOneSignalNotification(
                    $all_ids,
                    "Match Started",
                    "{$match_info['t1n']} Vs {$match_info['t2n']}",
                    [
                        'type' => 'toss_result', 
                        'match_id' => $match_id,
                        'big_picture' => $match_started_banner ?: ($base_url . "assets/images/cricket-bg.jpg"),
                        'image' => $match_started_banner ?: ($base_url . "assets/images/cricket-bg.jpg"),
                        'large_icon' => $tournament_logo_url,
                        'small_icon' => 'ic_stat_notify',
                        'android_sound' => 'notification_sound'
                    ],
                    $base_url . "live_stream/live_match.php?id=" . $match_id
                );
            }
        }
    } catch (Throwable $no_err) {
        error_log("Toss Notification Error: " . $no_err->getMessage());
    }

    // Clear session data for this match
    unset($_SESSION['match_setup'][$match_id]);

    // Redirect to score controller
    header("Location: score_controller.php?id=$match_id&started=1");
    exit();

} catch (PDOException $e) {
    $pdo->rollBack();
    die("Error finalizing match: " . $e->getMessage());
}
