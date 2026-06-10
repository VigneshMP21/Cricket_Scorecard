<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        try {
            $dotenv->load();
        } catch (Exception $e) {
        }
    }
}

if (class_exists('Cloudinary\\Configuration\\Configuration')) {
    \Cloudinary\Configuration\Configuration::instance([
        'cloud' => [
            'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
            'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
            'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
        ],
        'url' => [
            'secure' => true
        ]
    ]);
}

require_login();

function buildHtmlToImageDocument(string $html, string $css): string
{
    return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>{$css}</style>
</head>
<body>{$html}</body>
</html>";
}

function generateNotificationBannerViaApi(string $html, string $css, int $width, int $height, int $delayMs = 2000): ?string
{
    $apiKey = $_ENV['HTML2IMAGE_API_KEY'] ?? '';
    if (empty($apiKey) || $apiKey === 'your_html2image_api_key_here') {
        return null;
    }

    $document = buildHtmlToImageDocument($html, $css);
    $url = 'https://www.html2image.net/api/api.php?key=' . urlencode($apiKey)
        . '&type=png&width=' . $width
        . '&height=' . $height
        . '&delay=' . $delayMs
        . '&transparent=false';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'source=' . urlencode($document),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('Notification banner HTML2Image cURL error: ' . $curlErr);
        return null;
    }

    $result = json_decode((string) $response, true);
    if (!is_array($result)) {
        error_log('Notification banner HTML2Image invalid response: ' . substr((string) $response, 0, 200));
        return null;
    }

    if (($result['Status'] ?? '') === 'OK' && !empty($result['Link'])) {
        return $result['Link'];
    }

    error_log('Notification banner HTML2Image error: ' . ($result['Message'] ?? json_encode($result)));
    return null;
}

function uploadNotificationBannerToCloudinary(?string $imageUrl, string $folder): ?string
{
    if (!$imageUrl) {
        return null;
    }

    if (class_exists('\Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $cloudRes = $uploadApi->upload($imageUrl, [
                'folder' => $folder,
                'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ""
            ]);
            return $cloudRes['secure_url'] ?? $imageUrl;
        } catch (Exception $e) {
            error_log('Notification banner Cloudinary upload error: ' . $e->getMessage());
        }
    }

    return $imageUrl;
}

function buildRankingMedalMarkup(int $rank): string
{
    $medalConfig = [
        1 => ['fill' => '#fbbf24', 'stroke' => '#b45309', 'label' => '1'],
        2 => ['fill' => '#d1d5db', 'stroke' => '#6b7280', 'label' => '2'],
        3 => ['fill' => '#d97706', 'stroke' => '#92400e', 'label' => '3'],
    ];

    $config = $medalConfig[$rank] ?? ['fill' => '#f59e0b', 'stroke' => '#78350f', 'label' => (string) $rank];
    $fill = $config['fill'];
    $stroke = $config['stroke'];
    $label = $config['label'];

    return "
    <svg viewBox='0 0 64 72' xmlns='http://www.w3.org/2000/svg' aria-hidden='true'>
        <path d='M18 2h12l4 18H22z' fill='#2563eb'/>
        <path d='M34 2h12l-4 18H30z' fill='#dc2626'/>
        <circle cx='32' cy='42' r='18' fill='{$fill}' stroke='{$stroke}' stroke-width='4'/>
        <circle cx='32' cy='42' r='11' fill='rgba(255,255,255,0.22)'/>
        <text x='32' y='48' text-anchor='middle' font-size='20' font-weight='800' font-family='Outfit, sans-serif' fill='#111827'>{$label}</text>
    </svg>";
}

function buildMatchInfoIcon(string $type): string
{
    if ($type === 'calendar') {
        return "
        <svg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' aria-hidden='true'>
            <rect x='3' y='5' width='18' height='16' rx='3' fill='rgba(255,255,255,0.16)' stroke='#ffffff' stroke-width='1.8'/>
            <path d='M7 3v4M17 3v4M3 9h18' stroke='#fbbf24' stroke-width='1.8' stroke-linecap='round'/>
            <rect x='7' y='12' width='4' height='4' rx='1' fill='#fbbf24'/>
        </svg>";
    }

    return "
    <svg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' aria-hidden='true'>
        <path d='M12 21s6-5.2 6-11a6 6 0 1 0-12 0c0 5.8 6 11 6 11Z' fill='rgba(255,255,255,0.16)' stroke='#ffffff' stroke-width='1.8'/>
        <circle cx='12' cy='10' r='2.5' fill='#fbbf24'/>
    </svg>";
}

/**
 * Generates a premium ranking banner from HTML/CSS
 */
function generateRankingBanner($data)
{
    $players_html = "";
    
    foreach ($data['players'] as $index => $player) {
        $rank = $index + 1;
        $medal = buildRankingMedalMarkup($rank);
        $players_html .= "
        <div class='player-card'>
            <div class='image-wrapper' style='border-color: {$player['team_color']}'>
                <div class='rank-badge'>$medal</div>
                <img src='{$player['player_image']}' class='player-img' />
            </div>
            <div class='player-name'>{$player['player_name']}</div>
            <div class='team-name' style='color: {$player['team_color']}'>{$player['team_name']}</div>
            <div class='score-badge'>{$player['score']} Pts</div>
        </div>";
    }

    $html = "
    <div class='banner-container'>
        <div class='title-tag'>{$data['title']}</div>
        <div class='players-row'>
            $players_html
        </div>
    </div>";

    $cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw';
    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 400px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 800px; height: 400px; 
        background: url('https://res.cloudinary.com/{$cloudName}/image/upload/v1777033632/icc-world-cup-cricket-stadium-background-with-flag-trophy-vector-wallpaper-design-illustration_837518-24365_ftswsn.jpg') center/cover no-repeat;
        display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
        padding: 25px 20px; position: relative;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.4); z-index: 0;
    }
    .title-tag, .players-row { position: relative; z-index: 1; }
    .title-tag { 
        background: #fbbf24; color: #000; padding: 8px 30px; border-radius: 50px; 
        font-weight: 800; font-size: 24px; text-transform: uppercase; letter-spacing: 2px;
        margin-bottom: 25px; box-shadow: 0 10px 30px rgba(251, 191, 36, 0.4);
    }
    .players-row { 
        display: flex; justify-content: center; align-items: flex-start; width: 100%; gap: 20px;
    }
    .player-card { display: flex; flex-direction: column; align-items: center; text-align: center; width: 230px; }
    .image-wrapper { 
        position: relative; width: 150px; height: 150px; 
        border: 4px solid #fff; border-radius: 12px; overflow: hidden;
        background: rgba(255,255,255,0.1); margin-bottom: 12px;
        box-shadow: 0 12px 25px rgba(0,0,0,0.5);
    }
    .rank-badge {
        position: absolute; top: 8px; left: 8px; width: 46px; height: 52px;
        display: flex; align-items: center; justify-content: center; z-index: 2;
        filter: drop-shadow(0 6px 10px rgba(0,0,0,0.45));
    }
    .rank-badge svg {
        width: 100%; height: 100%; display: block;
    }
    .player-img { width: 100%; height: 100%; object-fit: cover; }
    .player-name { 
        font-size: 28px; font-weight: 800; color: #ffffff; 
        text-shadow: 0 4px 8px rgba(0,0,0,0.9); 
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; 
        margin-top: 5px;
    }
    .team-name { 
        font-size: 16px; font-weight: 700; text-transform: uppercase; 
        margin-top: 2px; letter-spacing: 1.5px;
        text-shadow: 0 2px 4px rgba(0,0,0,0.8);
    }
    .score-badge { 
        margin-top: 10px; background: #fbbf24; color: #000;
        padding: 4px 15px; border-radius: 50px; font-size: 15px; font-weight: 800;
        box-shadow: 0 4px 10px rgba(251, 191, 36, 0.3);
    }";

    $imageUrl = generateNotificationBannerViaApi($html, $css, 800, 400);
    return uploadNotificationBannerToCloudinary($imageUrl, 'ranking_banners');
}

/**
 * Generates a premium match banner from HTML/CSS
 */
function generateMatchBanner($data)
{
    $calendarIcon = buildMatchInfoIcon('calendar');
    $locationIcon = buildMatchInfoIcon('location');

    $html = "
    <div class='banner-container'>
        <div class='tournament-tag'>{$data['tournament_name']}</div>
        <div class='match-main'>
            <div class='team-box'>
                <div class='logo-wrapper teamA-border'><img src='{$data['teamA_logo']}' /></div>
                <div class='team-name teamA-color'>{$data['teamA_name']}</div>
            </div>
            <div class='vs-box'>
                <div class='vs-text'>VS</div>
                <div class='match-type'>{$data['match_type']}</div>
            </div>
            <div class='team-box'>
                <div class='logo-wrapper teamB-border'><img src='{$data['teamB_logo']}' /></div>
                <div class='team-name teamB-color'>{$data['teamB_name']}</div>
            </div>
        </div>
        <div class='match-footer'>
            <div class='footer-item'>
                <span class='footer-icon'>{$calendarIcon}</span>
                <span>{$data['date_time']}</span>
            </div>
            <div class='footer-item'>
                <span class='footer-icon'>{$locationIcon}</span>
                <span>{$data['venue']}</span>
            </div>
        </div>
    </div>";

    $cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw';
    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 400px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 800px; height: 400px; 
        background: url('https://res.cloudinary.com/{$cloudName}/image/upload/v1777031427/Night-Lights-at-Narendra-Modi-Stadium_cbq1o1.webp') center/cover no-repeat;
        padding: 30px; color: white; display: flex; flex-direction: column; justify-content: space-between;
        position: relative;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.4); z-index: 0;
    }
    .tournament-tag, .match-main, .match-footer { position: relative; z-index: 1; }
    .tournament-tag { 
        align-self: center; background: rgba(0,0,0,0.6); 
        padding: 6px 20px; border-radius: 50px; font-weight: 600;
        font-size: 16px; color: #fbbf24; text-transform: uppercase; letter-spacing: 2px;
        border: 1px solid rgba(251, 191, 36, 0.3);
    }
    .match-main { display: flex; align-items: center; justify-content: space-around; flex-grow: 1; }
    .team-box { text-align: center; width: 280px; }
    .logo-wrapper { 
        width: 140px; height: 140px; background: rgba(255,255,255,0.1); 
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        margin: 0 auto 15px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    }
    .teamA-border { border: 3px solid {$data['teamA_color']}; }
    .teamB-border { border: 3px solid {$data['teamB_color']}; }
    .logo-wrapper img { width: 100px; height: 100px; object-fit: contain; }
    .team-name { font-size: 32px; font-weight: 800; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); }
    .teamA-color { color: {$data['teamA_color']}; }
    .teamB-color { color: {$data['teamB_color']}; }
    .vs-box { text-align: center; }
    .vs-text { font-size: 80px; font-weight: 900; color: #ef4444; line-height: 1; text-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
    .match-type { font-size: 16px; color: white; font-weight: 600; text-transform: uppercase; margin-top: 5px; }
    .match-footer { 
        display: flex; justify-content: center; gap: 40px; 
        padding-top: 15px; background: rgba(0,0,0,0.6); border-radius: 10px;
    }
    .footer-item { font-size: 20px; color: white; font-weight: 600; display: flex; align-items: center; gap: 10px; padding: 10px; }
    .footer-icon { width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .footer-icon svg { width: 24px; height: 24px; display: block; }";

    $imageUrl = generateNotificationBannerViaApi($html, $css, 800, 400);
    return uploadNotificationBannerToCloudinary($imageUrl, 'match_announcements');
}

/**
 * Generates a premium point table banner from HTML/CSS
 */
function generatePointTableBanner($data)
{
    $rows_html = "";
    foreach ($data['teams'] as $team) {
        $row_class = $team['is_qualified'] ? 'qualified-row' : '';
        $rows_html .= "
        <div class='team-item $row_class'>
            <div class='pos'>{$team['pos']}</div>
            <div class='name'>{$team['team_name']}</div>
            <div class='stat'>{$team['won']}</div>
            <div class='stat'>{$team['lost']}</div>
            <div class='stat pts'>{$team['points']}</div>
            <div class='stat nrr'>{$team['nrr']}</div>
        </div>";
    }

    $html = "
    <div class='banner-container'>
        <div class='table-name'>{$data['table_name']}</div>
        <div class='subtitle'>Qualified Teams</div>
        <div class='header-row'>
            <div class='pos'>#</div>
            <div class='name'>Team Name</div>
            <div class='stat'>W</div>
            <div class='stat'>L</div>
            <div class='stat'>Pts</div>
            <div class='stat'>NRR</div>
        </div>
        <div class='teams-list'>
            $rows_html
        </div>
    </div>";

    $cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw';
    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 600px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 800px; height: 600px; 
        background: url('https://res.cloudinary.com/{$cloudName}/image/upload/v1778068401/cricket-46_1024422-11592_z50wdm.jpg') center/cover no-repeat;
        padding: 40px; color: white; display: flex; flex-direction: column; align-items: center;
        position: relative;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.65); z-index: 0;
    }
    .table-name, .subtitle, .header-row, .teams-list { position: relative; z-index: 1; }
    .table-name { font-size: 42px; font-weight: 800; color: #fbbf24; text-transform: uppercase; margin-bottom: 5px; text-align: center; text-shadow: 0 4px 10px rgba(0,0,0,0.5); }
    .subtitle { font-size: 24px; font-weight: 600; color: #cbd5e1; margin-bottom: 30px; letter-spacing: 2px; }
    .header-row { 
        display: flex; width: 100%; background: rgba(255,255,255,0.15); 
        padding: 12px 20px; border-radius: 12px; margin-bottom: 10px;
        font-weight: 800; font-size: 18px; text-transform: uppercase; color: #94a3b8;
    }
    .teams-list { width: 100%; display: flex; flex-direction: column; gap: 8px; }
    .team-item { 
        display: flex; width: 100%; background: rgba(255,255,255,0.08); 
        padding: 15px 20px; border-radius: 12px; align-items: center;
        font-size: 20px; font-weight: 600; border: 1px solid rgba(255,255,255,0.1);
    }
    .qualified-row { 
        background: rgba(16, 185, 129, 0.25);
        border: 1px solid rgba(16, 185, 129, 0.5);
        color: #dcfce7;
    }
    .pos { width: 50px; color: #94a3b8; }
    .name { flex: 1; }
    .stat { width: 70px; text-align: center; }
    .pts { color: #fbbf24; font-weight: 800; }
    .nrr { width: 100px; font-family: monospace; color: #cbd5e1; }";

    $imageUrl = generateNotificationBannerViaApi($html, $css, 800, 600);
    return uploadNotificationBannerToCloudinary($imageUrl, 'point_table_announcements');
}


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$success_msg = '';
$error_msg = '';

// Fetch upcoming matches for dropdown
$matches_stmt = $pdo->query("
    SELECT m.id, m.match_date, m.match_time, t1.team_name as t1_name, t2.team_name as t2_name 
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.status = 'upcoming'
    ORDER BY m.match_date ASC, m.match_time ASC
");
$upcoming_matches = $matches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch points tables for dropdown
$tables_stmt = $pdo->query("SELECT id, table_name FROM point_tables ORDER BY created_at DESC");
$points_tables = $tables_stmt->fetchAll(PDO::FETCH_ASSOC);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $type = $_POST['notification_type'];
    $rankings = [];
    $title_msg = '';

    try {
        if ($type === 'batsman') {
            $title_msg = "🔥 The Elite Batting Leaderboard! 🔥";
            $stmt = $pdo->query("
                SELECT 
                    u.name as player_name, 
                    u.profile_image,
                    u.profile_image_url,
                    t.team_name,
                    t.team_color,
                    SUM(
                        ms.runs_scored 
                        + CASE 
                            WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 160 THEN 15
                            WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 140 THEN 10
                            WHEN ms.balls_faced > 0 AND (ms.runs_scored / ms.balls_faced * 100) >= 120 THEN 5
                            ELSE 0
                          END 
                        + (ms.fours * 1 + ms.sixes * 2) 
                        + CASE 
                            WHEN ms.runs_scored >= 100 THEN 20
                            WHEN ms.runs_scored >= 50 THEN 12
                            WHEN ms.runs_scored >= 30 THEN 5
                            ELSE 0
                          END 
                        + CASE 
                            WHEN ms.runs_scored = 0 AND EXISTS (
                                SELECT 1 FROM ball_by_ball bbb 
                                WHERE bbb.match_id = ms.match_id 
                                AND bbb.wicket_player_id = ms.player_id
                            ) THEN -5
                            ELSE 0
                          END 
                        + CASE 
                            WHEN m.winner_id = ms.team_id THEN 5
                            ELSE 0
                          END 
                    ) as best_batter_score
                FROM match_statistics ms
                JOIN users u ON ms.player_id = u.id
                JOIN matches m ON ms.match_id = m.id
                LEFT JOIN teams t ON ms.team_id = t.id
                WHERE ms.balls_faced > 0 AND m.status = 'completed'
                GROUP BY u.id, u.name, u.profile_image, u.profile_image_url, t.team_name, t.team_color
                ORDER BY best_batter_score DESC, 
                         (SUM(ms.runs_scored) / NULLIF(SUM(ms.balls_faced), 0) * 100) DESC, 
                         SUM(ms.runs_scored) DESC
                LIMIT 3
            ");
            $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($type === 'bowler') {
            $title_msg = "🎯 Bowling Masters Revealed! 🎯";
            $stmt = $pdo->query("
                SELECT 
                    u.name as player_name, 
                    u.profile_image,
                    u.profile_image_url,
                    t.team_name,
                    t.team_color,
                    SUM(
                        (ms.wickets_taken * 25) 
                        + CASE 
                            WHEN ms.overs_bowled >= 2.0 THEN
                                CASE 
                                    WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 5.00 THEN 15
                                    WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 6.50 THEN 10
                                    WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 7.50 THEN 5
                                    ELSE 0
                                END
                            ELSE 0
                          END 
                        + (ms.maidens * 10) 
                        + CASE 
                            WHEN ms.wickets_taken >= 5 THEN 25
                            WHEN ms.wickets_taken = 4 THEN 15
                            WHEN ms.wickets_taken = 3 THEN 10
                            ELSE 0
                          END 
                        + CASE 
                            WHEN match_dot_balls >= 20 THEN 15
                            WHEN match_dot_balls >= 15 THEN 10
                            WHEN match_dot_balls >= 10 THEN 5
                            ELSE 0
                          END 
                        + CASE WHEN m.winner_id = ms.team_id THEN 5 ELSE 0 END 
                    ) as best_bowler_score
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
                GROUP BY u.id, u.name, u.profile_image, u.profile_image_url, t.team_name, t.team_color
                HAVING best_bowler_score > 0
                ORDER BY best_bowler_score DESC, 
                         SUM(ms.wickets_taken) DESC
                LIMIT 3
            ");
            $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($type === 'pos') {
            $title_msg = "🏆 Ultimate MVP Race: Player of the Series! 🏆";

            // Replicate POS logic briefly
            $matches_stmt = $pdo->query("SELECT id, winner_id FROM matches WHERE status = 'completed'");
            $completed_matches = $matches_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (!empty($completed_matches)) {
                $completed_ids_str = implode(',', array_keys($completed_matches));
                $stats_stmt = $pdo->query("SELECT * FROM match_statistics WHERE match_id IN ($completed_ids_str)");
                $all_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

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

                $wicket_stmt = $pdo->query("SELECT match_id, wicket_player_id FROM ball_by_ball WHERE match_id IN ($completed_ids_str) AND wicket_player_id IS NOT NULL");
                $dismissals = $wicket_stmt->fetchAll(PDO::FETCH_ASSOC);
                $out_map = [];
                foreach ($dismissals as $d) {
                    $out_map[$d['match_id']][$d['wicket_player_id']] = true;
                }

                $players_stmt = $pdo->query("
                    SELECT u.id, u.name, u.profile_image, u.profile_image_url, tp.team_id, t.team_name, t.team_color
                    FROM users u 
                    LEFT JOIN team_players tp ON u.id = tp.player_id
                    LEFT JOIN teams t ON tp.team_id = t.id 
                    WHERE u.role='player'
                ");
                $all_players = $players_stmt->fetchAll(PDO::FETCH_ASSOC);
                $player_info = [];
                $player_data = [];
                foreach ($all_players as $p) {
                    $player_info[$p['id']] = $p;
                    $player_data[$p['id']] = ['matches' => []];
                }

                foreach ($all_stats as $s) {
                    $pid = $s['player_id'];
                    $mid = $s['match_id'];
                    if (!isset($player_data[$pid]))
                        continue;
                    if (!isset($player_data[$pid]['matches'][$mid])) {
                        $player_data[$pid]['matches'][$mid] = ['bat' => ['runs' => 0, 'balls' => 0], 'bowl' => ['wickets' => 0, 'conceded' => 0, 'legal_balls' => 0], 'field' => ['catches' => 0, 'run_outs' => 0, 'stumpings' => 0]];
                    }
                    $player_data[$pid]['matches'][$mid]['bat']['runs'] += $s['runs_scored'];
                    $player_data[$pid]['matches'][$mid]['bat']['balls'] += $s['balls_faced'];
                    $player_data[$pid]['matches'][$mid]['bowl']['wickets'] += $s['wickets_taken'];
                    $player_data[$pid]['matches'][$mid]['bowl']['conceded'] += $s['runs_conceded'];
                    $parts = explode('.', (string) ($s['overs_bowled'] ?? '0.0'));
                    $balls = ((int) $parts[0] * 6) + (int) ($parts[1] ?? 0);
                    $player_data[$pid]['matches'][$mid]['bowl']['legal_balls'] += $balls;
                }

                foreach ($all_fielding as $f) {
                    $pid = $f['fielder_id'];
                    $mid = $f['match_id'];
                    if (!isset($player_data[$pid]))
                        continue;
                    if (!isset($player_data[$pid]['matches'][$mid])) {
                        $player_data[$pid]['matches'][$mid] = ['bat' => ['runs' => 0, 'balls' => 0], 'bowl' => ['wickets' => 0, 'conceded' => 0, 'legal_balls' => 0], 'field' => ['catches' => 0, 'run_outs' => 0, 'stumpings' => 0]];
                    }
                    $player_data[$pid]['matches'][$mid]['field'] = ['catches' => $f['catches'], 'run_outs' => $f['run_outs'], 'stumpings' => $f['stumpings']];
                }

                $scores = [];
                foreach ($player_data as $pid => $data) {
                    $total_points = 0;
                    $matches_played = count($data['matches']);
                    if ($matches_played === 0)
                        continue;

                    $matches_30_runs = 0;
                    $matches_with_wickets = 0;

                    foreach ($data['matches'] as $mid => $m_stats) {
                        $match_points = 0;
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

                        $catches = $m_stats['field']['catches'] ?? 0;
                        $runouts = $m_stats['field']['run_outs'] ?? 0;
                        $stumps = $m_stats['field']['stumpings'] ?? 0;
                        $match_points += ($catches * 8) + ($runouts * 12) + ($stumps * 10);

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

                    if ($matches_played >= 3)
                        $total_points += 10;
                    if ($matches_30_runs >= 3)
                        $total_points += 10;
                    if ($matches_with_wickets >= 3)
                        $total_points += 10;

                    if ($total_points > 0) {
                        $scores[$pid] = $total_points;
                        $rankings_data[$pid] = [
                            'player_name' => $player_info[$pid]['name'],
                            'team_name' => $player_info[$pid]['team_name'] ?: 'No Team',
                            'team_color' => $player_info[$pid]['team_color'] ?: '#fbbf24',
                            'profile_image' => $player_info[$pid]['profile_image'],
                            'profile_image_url' => $player_info[$pid]['profile_image_url'],
                            'score' => $total_points
                        ];
                    }
                }

                arsort($scores);
                $rankings = [];
                $count = 0;
                foreach ($scores as $pid => $pt) {
                    $rankings[] = $rankings_data[$pid];
                    $count++;
                    if ($count >= 3)
                        break;
                }
            }
        } elseif ($type === 'match_announcement') {
            if (empty($_POST['match_id'])) {
                $error_msg = "Please select a match first.";
            } else {
                $match_id = (int) $_POST['match_id'];
                $stmt = $pdo->prepare("
                    SELECT m.*, 
                           t1.team_name as t1n, t1.team_code as t1c, t1.team_logo_public_id as t1l, t1.team_color as t1col,
                           t2.team_name as t2n, t2.team_code as t2c, t2.team_logo_public_id as t2l, t2.team_color as t2col,
                           tn.tournament_name, tn.tournament_logo_public_id
                    FROM matches m 
                    JOIN teams t1 ON m.team1_id = t1.id 
                    JOIN teams t2 ON m.team2_id = t2.id 
                    LEFT JOIN tournaments tn ON m.tournament_id = tn.id
                    WHERE m.id = ?");
                $stmt->execute([$match_id]);
                $match = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($match) {
                    $match_date_fmt = date("F j, Y", strtotime($match['match_date']));
                    $match_time_fmt = date("h:i A", strtotime($match['match_time']));
                    
                    $title_msg = "Next Match: {$match['t1c']} VS {$match['t2c']}";
                    $content_msg = "{$match['t1n']} VS {$match['t2n']}, be ready guys!";

                    $target_player_ids = getPlayerIdsForTeams($pdo, [$match['team1_id'], $match['team2_id']]);

                    if (!empty($target_player_ids)) {
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $base_url = "$protocol://$host/CPT_LEAGUE/";

                        // Fetch Tournament Logo for large_icon
                        $tournament_logo_url = $match['tournament_logo_public_id'] ? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . $match['tournament_logo_public_id'] : ($base_url . "assets/images/logo.jpg");

                        // Prepare data for the banner
                        $banner_data = [
                            'tournament_name' => $match['tournament_name'] ?: 'Cricket Tournament',
                            'teamA_name' => $match['t1n'],
                            'teamB_name' => $match['t2n'],
                            'teamA_logo' => "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . ($match['t1l'] ?: 'default_team_logo'),
                            'teamB_logo' => "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . ($match['t2l'] ?: 'default_team_logo'),
                            'teamA_color' => $match['t1col'] ?: '#fbbf24',
                            'teamB_color' => $match['t2col'] ?: '#fbbf24',
                            'date_time' => date('d M, Y', strtotime($match['match_date'])) . " | " . $match_time_fmt,
                            'venue' => $match['venue'],
                            'match_type' => $match['match_type']
                        ];

                        $match_banner = generateMatchBanner($banner_data);

                        sendOneSignalNotification(
                            $target_player_ids,
                            $title_msg,
                            $content_msg,
                            [
                                'type' => 'match_announcement',
                                'match_id' => $match_id,
                                'big_picture' => $match_banner ?: ($base_url . "assets/images/cricket-bg.jpg"),
                                'large_icon' => $tournament_logo_url,
                                'small_icon' => 'ic_stat_notify',
                                'android_sound' => 'notification_sound'
                            ],
                            $base_url . "view/view_match.php?id=$match_id"
                        );
                        $success_msg = "Match announcement sent successfully to the playing teams!";
                    } else {
                        $error_msg = "No devices found for the players in this match.";
                    }
                }
            }
        } elseif ($type === 'qualify_teams') {
            if (empty($_POST['table_id'])) {
                $error_msg = "Please select a points table first.";
            } else {
                $table_id = (int) $_POST['table_id'];
                $stmt = $pdo->prepare("
                    SELECT pt.*, t.tournament_name, t.tournament_logo_public_id 
                    FROM point_tables pt
                    LEFT JOIN tournaments t ON pt.tournament_id = t.id
                    WHERE pt.id = ?
                ");
                $stmt->execute([$table_id]);
                $table = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($table) {
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
                        $stmt_matches = $pdo->prepare("
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
                        ");
                        $stmt_matches->execute([$table['tournament_id'], $table_id, $table_id, $team['id'], $team['id']]);
                        $team_matches_all = $stmt_matches->fetchAll(PDO::FETCH_ASSOC);

                        $matches_played = 0;
                        $won = 0;
                        $lost = 0;
                        $draw = 0;
                        $nr = 0;
                        foreach ($team_matches_all as $tm) {
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
                        }

                        $points = ($won * ($table['win_points'] ?? 2)) +
                            ($draw * ($table['draw_points'] ?? 1)) +
                            ($lost * ($table['loss_points'] ?? 0)) +
                            ($nr * ($table['nr_points'] ?? 1));

                        $total_overs_faced = 0;
                        $total_overs_bowled = 0;
                        $total_runs_scored = 0;
                        $total_runs_conceded = 0;

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
                            'team_name' => $team['team_name'],
                            'won' => $won,
                            'lost' => $lost + $draw + $nr, 
                            'points' => $points,
                            'nrr' => number_format($nrr, 3),
                        ];
                    }

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
                    
                    // Prepare data for Point Table Banner
                    $banner_teams = [];
                    foreach($table_data as $index => $row) {
                        $banner_teams[] = [
                            'pos' => $index + 1,
                            'team_name' => $row['team_name'],
                            'won' => $row['won'],
                            'lost' => $row['lost'],
                            'points' => $row['points'],
                            'nrr' => $row['nrr'],
                            'is_qualified' => ($index < $qualify_count)
                        ];
                    }

                    $table_name = $table['table_name'];
                    $title_msg = $table_name;
                    $content_msg = "Check out the latest standings for {$table_name}! Top teams are moving closer to the finals. 🏆🏏";

                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $base_url = "$protocol://$host/CPT_LEAGUE/";

                    $pt_banner = generatePointTableBanner([
                        'table_name' => $table_name,
                        'teams' => $banner_teams
                    ]);

                    $tournament_logo_url = $table['tournament_logo_public_id'] ? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . $table['tournament_logo_public_id'] : ($base_url . "assets/images/logo.jpg");

                    $registered_player_ids = $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE user_id IS NOT NULL AND onesignal_player_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                    $guest_player_ids = getGuestPlayerIds($pdo);
                    $all_player_ids = array_unique(array_merge($registered_player_ids, $guest_player_ids));

                    if (!empty($all_player_ids)) {
                        sendOneSignalNotification(
                            $all_player_ids,
                            $title_msg,
                            $content_msg,
                            [
                                'type' => 'qualified_teams',
                                'table_id' => $table_id,
                                'big_picture' => $pt_banner ?: ($base_url . "assets/images/home_bg.jpg"),
                                'large_icon' => $tournament_logo_url,
                                'small_icon' => 'ic_stat_notify',
                                'android_sound' => 'notification_sound'
                            ],
                            $base_url . "view/view_point_table.php?id=$table_id"
                        );
                        $success_msg = "Point table announcement sent successfully to all members!";
                    } else {
                        $error_msg = "No devices found to send notifications.";
                    }
                }
            }
        }

        // Build the content msg
        if ($type === 'batsman' || $type === 'bowler' || $type === 'pos') {
            // Build the content msg
            $content_msg = "";
            if ($type === 'batsman') {
                $content_msg = "The runs are flowing! Here are the top performers dominating the crease. Who will claim the Orange Cap? 🏏✨\n\n";
            } elseif ($type === 'bowler') {
                $content_msg = "Precision, pace, and spin! These bowling titans are tearing through line-ups. Check out the current wicket-takers. ⚡️🔥\n\n";
            } elseif ($type === 'pos') {
                $content_msg = "The battle for glory is heating up! These all-round superstars are leading the charge for the Ultimate Trophy. 🌟🥇\n\n";
            }

            $count = 1;
            $top_score = 0;
            $medals = [1 => "\u{1F947}", 2 => "\u{1F948}", 3 => "\u{1F949}"];

            foreach ($rankings as $index => $row) {
                $p_name = $row['player_name'];
                $t_name = $row['team_name'] ?: 'No Team';
                $score = 0;

                if ($type === 'batsman')
                    $score = $row['best_batter_score'];
                elseif ($type === 'bowler')
                    $score = $row['best_bowler_score'];
                elseif ($type === 'pos')
                    $score = $row['score'];

                if ($index === 0)
                    $top_score = $score;

                $medal_emoji = $medals[$count] ?? '';
                $content_msg .= "Rank- $count $medal_emoji $p_name ($t_name) - " . number_format($score, 0) . " points\n";
                $count++;
            }

            if ($count > 1) { // We got at least 1 ranking
                $registered_player_ids = $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE user_id IS NOT NULL AND onesignal_player_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                $guest_player_ids = getGuestPlayerIds($pdo);
                $all_player_ids = array_unique(array_merge($registered_player_ids, $guest_player_ids));

                if (!empty($all_player_ids)) {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $base_url = "$protocol://$host/CPT_LEAGUE/";

                    $target_url = "";
                    if ($type === 'batsman')
                        $target_url = $base_url . "NavBarList/best_batter_ranking.php";
                    elseif ($type === 'bowler')
                        $target_url = $base_url . "NavBarList/best_bowler_ranking.php";
                    elseif ($type === 'pos')
                        $target_url = $base_url . "NavBarList/pos_ranking.php";

                    $ranking_icon = $base_url . "assets/images/logo.jpg";
                    $ranking_banner = $base_url . "assets/images/cricket-bg.jpg";

                    if ($type === 'batsman') {
                        $ranking_icon = $base_url . "assets/images/batting.jpg";
                        $banner_title = "Best Batting Ranking";
                    } elseif ($type === 'bowler') {
                        $ranking_icon = $base_url . "assets/images/bowling.jpg";
                        $banner_title = "Best Bowling Ranking";
                    } elseif ($type === 'pos') {
                        $banner_title = "Player of Series Ranking";
                    }

                    // Fetch Tournament Logo for large_icon
                    $t_logo_stmt = $pdo->query("SELECT tournament_logo_public_id FROM tournaments WHERE tournament_logo_public_id IS NOT NULL AND tournament_logo_public_id != '' ORDER BY created_at DESC LIMIT 1");
                    $t_logo_id = $t_logo_stmt->fetchColumn();
                    $tournament_logo_url = $t_logo_id ? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . $t_logo_id : ($base_url . "assets/images/logo.jpg");

                    // Prepare Top 3 Players Data for the Banner
                    $banner_players = [];
                    foreach (array_slice($rankings, 0, 3) as $row) {
                        $p_score = 0;
                        if ($type === 'batsman') $p_score = $row['best_batter_score'];
                        elseif ($type === 'bowler') $p_score = $row['best_bowler_score'];
                        elseif ($type === 'pos') $p_score = $row['score'];

                        $p_img = $row['profile_image_url'] ?? null;
                        if (!$p_img && !empty($row['profile_image'])) {
                            $p_img = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/" . $row['profile_image'];
                        }
                        if (!$p_img) $p_img = "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? "dffnuolqw") . "/image/upload/v1745678901/default_user_ovz6zt.png";

                        $banner_players[] = [
                            'player_name' => $row['player_name'],
                            'team_name' => $row['team_name'] ?: 'No Team',
                            'team_color' => $row['team_color'] ?? '#fbbf24',
                            'player_image' => $p_img,
                            'score' => number_format($p_score, 0)
                        ];
                    }

                    $ranking_banner = generateRankingBanner([
                        'title' => $banner_title,
                        'players' => $banner_players
                    ]);

                    if (!$ranking_banner) {
                        $ranking_banner = $base_url . "assets/images/cricket-bg.jpg";
                    }

                    sendOneSignalNotification(
                        $all_player_ids,
                        $title_msg,
                        trim($content_msg),
                        [
                            'type' => 'ranking_announcement',
                            'large_icon' => $tournament_logo_url,
                            'small_icon' => 'ic_stat_notify',
                            'android_sound' => 'notification_sound',
                            'big_picture' => $ranking_banner
                        ],
                        $target_url
                    );
                    $success_msg = "Notification sent to all members successfully!";
                } else {
                    $error_msg = "No devices found to send notifications.";
                }
            } else {
                $error_msg = "No ranking data available to send.";
            }
        }

    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

$page_title = "Notifications Manager";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f8fafc;
    }

    .page-header {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: white;
        padding: 3rem 1rem;
        margin-bottom: 2rem;
        border-radius: 0 0 2rem 2rem;
        box-shadow: 0 10px 30px rgba(99, 102, 241, 0.2);
    }

    .table-card {
        background: white;
        border-radius: 1.5rem;
        border: none;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .table thead th {
        background-color: #f1f5f9;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        color: #475569;
        padding: 1rem;
        border-bottom: 2px solid #e2e8f0;
    }

    .table tbody td {
        padding: 1.2rem 1rem;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-weight: 500;
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    .table tbody tr {
        transition: all 0.2s ease;
    }

    .table tbody tr:hover {
        background-color: #f8fafc;
    }

    .btn-send {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        border: none;
        border-radius: 50px;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
    }

    .btn-send:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        color: white;
    }

    .btn-send i {
        margin-right: 0.5rem;
    }

    .icon-wrapper {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        margin-right: 1rem;
    }

    .icon-bat {
        background: #e0e7ff;
        color: #4f46e5;
    }

    .icon-bowl {
        background: #dcfce7;
        color: #16a34a;
    }

    .icon-pos {
        background: #fef3c7;
        color: #d97706;
    }

    /* Mobile Responsive Styles (<460px, <400px) */
    @media (max-width: 460px) {
        .page-header {
            padding: 1.5rem 1rem;
            border-radius: 0 0 1rem 1rem;
        }

        .page-header h1 {
            font-size: 1.4rem;
        }

        .table thead {
            display: none;
        }

        .table tbody td {
            padding: 0.8rem 0.5rem;
            vertical-align: middle;
        }

        .table tbody td:first-child {
            display: none;
            /* Hide S.No to save horizontal space */
        }

        .icon-wrapper {
            width: 35px;
            height: 35px;
            font-size: 1rem;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        .table tbody td h6 {
            font-size: 0.85rem;
            line-height: 1.2;
            margin-bottom: 0.2rem !important;
        }

        .table tbody td small {
            font-size: 0.7rem;
            line-height: 1.2;
        }

        .btn-send {
            padding: 0.5rem 0.8rem;
            font-size: 0.8rem;
            white-space: nowrap;
        }
    }

    @media (max-width: 400px) {
        .page-header h1 {
            font-size: 1.25rem;
        }

        .icon-wrapper {
            width: 30px;
            height: 30px;
            font-size: 0.9rem;
            margin-right: 0.4rem;
        }

        .table tbody td h6 {
            font-size: 0.8rem;
        }

        .table tbody td small {
            font-size: 0.65rem;
        }

        .btn-send {
            padding: 0.4rem 0.6rem;
            font-size: 0.75rem;
        }

        .btn-send i {
            margin-right: 0.2rem;
        }
    }

    .btn-send:disabled {
        opacity: 0.85;
        cursor: wait;
        transform: none;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
    }
</style>

<div class="page-header px-4">
    <div class="row align-items-center">
        <div class="col-md-2 col-12 text-start mb-3 mb-md-0">
            <a href="../admin/admin_dashboard.php" class="btn btn-link text-white text-decoration-none p-0">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
        <div class="col-md-8 col-12 text-center">
            <h1 class="fw-bold mb-0">Notifications Manager</h1>
        </div>
        <div class="col-md-2 d-none d-md-block"></div>
    </div>
    <p class="opacity-75 mb-0 mt-2 text-center">Broadcast latest ranking updates to all users</p>
</div>

<div class="container mb-5">
    <?php if ($success_msg): ?>
        <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4 d-flex align-items-center" id="autoDismissAlert">
            <i class="fas fa-check-circle fs-4 me-3"></i>
            <div><?= htmlspecialchars($success_msg) ?></div>
        </div>
        <script>
            setTimeout(function () {
                var alertBox = document.getElementById('autoDismissAlert');
                if (alertBox) {
                    alertBox.style.transition = 'opacity 0.5s ease';
                    alertBox.style.opacity = '0';
                    setTimeout(() => alertBox.remove(), 500);
                }
            }, 3000);
        </script>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4 d-flex align-items-center">
            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
            <div><?= htmlspecialchars($error_msg) ?></div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 80px;">S.No</th>
                                <th>Notification Name</th>
                                <th class="text-end" style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Best Batsman -->
                            <tr>
                                <td class="text-center fw-bold text-muted">1</td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-wrapper icon-bat">
                                            <i class="fas fa-baseball-bat-ball"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Best Batsman Ranking Notification</h6>
                                            <small class="text-muted">Sends top 3 batters to all members</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="">
                                        <input type="hidden" name="notification_type" value="batsman">
                                        <button type="submit" name="send_notification" class="btn btn-send"
                                            onclick="return confirm('Send Best Batsman ranking to all members?');">
                                            <i class="fas fa-paper-plane"></i> Send
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <!-- Best Bowler -->
                            <tr>
                                <td class="text-center fw-bold text-muted">2</td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-wrapper icon-bowl">
                                            <i class="fas fa-bowling-ball"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Best Bowler Ranking Notification</h6>
                                            <small class="text-muted">Sends top 3 bowlers to all members</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="">
                                        <input type="hidden" name="notification_type" value="bowler">
                                        <button type="submit" name="send_notification" class="btn btn-send"
                                            onclick="return confirm('Send Best Bowler ranking to all members?');">
                                            <i class="fas fa-paper-plane"></i> Send
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <!-- Player of the Series -->
                            <tr>
                                <td class="text-center fw-bold text-muted">3</td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-wrapper icon-pos">
                                            <i class="fas fa-trophy"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Player of the Series Notification</h6>
                                            <small class="text-muted">Sends top 3 POS players to all members</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="">
                                        <input type="hidden" name="notification_type" value="pos">
                                        <button type="submit" name="send_notification" class="btn btn-send"
                                            onclick="return confirm('Send Player of the Series ranking to all members?');">
                                            <i class="fas fa-paper-plane"></i> Send
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <!-- Match Announcement -->
                            <tr>
                                <td class="text-center fw-bold text-muted">4</td>
                                <td>
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="icon-wrapper icon-bat" style="background:#e0f2fe; color:#0284c7;">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Match Announcement Notification</h6>
                                            <small class="text-muted">Sends a reminder to teams of an upcoming
                                                match</small>
                                        </div>
                                    </div>
                                    <form method="POST" action=""
                                        class="d-flex mt-2 align-items-center gap-2 flex-wrap">
                                        <input type="hidden" name="notification_type" value="match_announcement">
                                        <select name="match_id" class="form-select form-select-sm border shadow-sm"
                                            style="max-width: 350px;" required>
                                            <option value="">Select a match</option>
                                            <?php foreach ($upcoming_matches as $match): ?>
                                                <option value="<?= $match['id'] ?>">
                                                    <?= htmlspecialchars($match['t1_name'] . " vs " . $match['t2_name'] . ", " . date('M d H:i', strtotime($match['match_date'] . ' ' . $match['match_time']))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="send_notification" class="btn btn-send py-1 px-3"
                                            onclick="return confirm('Send match announcement to playing teams?');"
                                            style="font-size:0.8rem;">
                                            <i class="fas fa-paper-plane mb-0"></i> Send
                                        </button>
                                    </form>
                                </td>
                                <td></td>
                            </tr>

                            <!-- Point Table Qualify Teams -->
                            <tr>
                                <td class="text-center fw-bold text-muted">5</td>
                                <td>
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="icon-wrapper icon-pos" style="background:#dcfce7; color:#15803d;">
                                            <i class="fas fa-list-ol"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Point Table Qualified Teams Announcement
                                            </h6>
                                            <small class="text-muted">Sends the list of qualified teams to all
                                                members</small>
                                        </div>
                                    </div>
                                    <form method="POST" action=""
                                        class="d-flex mt-2 align-items-center gap-2 flex-wrap">
                                        <input type="hidden" name="notification_type" value="qualify_teams">
                                        <select name="table_id" class="form-select form-select-sm border shadow-sm"
                                            style="max-width: 350px;" required>
                                            <option value="">Select a Points Table</option>
                                            <?php foreach ($points_tables as $pt): ?>
                                                <option value="<?= $pt['id'] ?>">
                                                    <?= htmlspecialchars($pt['table_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="send_notification" class="btn btn-send py-1 px-3"
                                            onclick="return confirm('Send qualified teams announcement to all members?');"
                                            style="font-size:0.8rem;">
                                            <i class="fas fa-paper-plane mb-0"></i> Send
                                        </button>
                                    </form>
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('form button[name="send_notification"]').forEach(function (button) {
        const form = button.closest('form');
        if (!form) return;

        form.addEventListener('submit', function () {
            if (!form.querySelector('input[type="hidden"][name="send_notification"]')) {
                const sendInput = document.createElement('input');
                sendInput.type = 'hidden';
                sendInput.name = 'send_notification';
                sendInput.value = button.value || '1';
                form.appendChild(sendInput);
            }

            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending...';
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
