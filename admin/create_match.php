<?php
require_once '../includes/db.php';
require_once '../includes/onesignal_utils.php';
require_once '../includes/html2image_utils.php';


if (class_exists('Cloudinary\Configuration\Configuration')) {
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
}

require_login();

/**
 * Generates a premium match banner from HTML/CSS using an external API
 * and caches it on Cloudinary for OneSignal.
 */
function generateMatchBanner($data)
{
    $api_user = $_ENV['HCTI_USER_ID'] ?? '';
    $api_key = $_ENV['HCTI_API_KEY'] ?? '';

    if (empty($api_user) || empty($api_key)) {
        return null; // Fallback to simple logic if API not configured
    }

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
            <div class='footer-item'>📅 {$data['date_time']}</div>
            <div class='footer-item'>📍 {$data['venue']}</div>
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 400px; font-family: 'Outfit', sans-serif; }
    .banner-container { 
        width: 800px; height: 400px; 
        background: url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1777031427/Night-Lights-at-Narendra-Modi-Stadium_cbq1o1.webp') center/cover no-repeat;
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
        width: 140px; height: 140px; background: rgba(255,255,255,0.96); 
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        padding: 16px; overflow: hidden;
        margin: 0 auto 15px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    }
    .teamA-border { border: 3px solid {$data['teamA_color']}; }
    .teamB-border { border: 3px solid {$data['teamB_color']}; }
    .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; background: white; }
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
    .footer-item { font-size: 20px; color: white; font-weight: 600; display: flex; align-items: center; gap: 8px; padding: 10px; }";

    // API Call (HCTI example)
    $ch = curl_init('https://hcti.io/v1/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['html' => $html, 'css' => $css]));
    curl_setopt($ch, CURLOPT_USERPWD, $api_user . ':' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout to prevent page hang
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error || $http_code !== 200) {
        error_log("HCTI API Error: " . ($curl_error ?: "HTTP Status $http_code"));
        return null;
    }

    $res = json_decode($response, true);
    $image_url = $res['url'] ?? null;

    if ($image_url) {
        try {
            if (class_exists('\Cloudinary\Api\Upload\UploadApi')) {
                $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
                $cloud_res = $uploadApi->upload($image_url, [
                    'folder' => 'match_banners',
                    'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ''
                ]);
                return $cloud_res['secure_url'];
            }
            return $image_url;
        } catch (Exception $e) {
            error_log("Cloudinary Upload Error: " . $e->getMessage());
            return $image_url;
        }
    }
    return null;
}

function generateMatchBannerHtml2Image(array $data): ?string
{
    $tournament_name = htmlspecialchars((string) ($data['tournament_name'] ?? 'Tournament'), ENT_QUOTES, 'UTF-8');
    $teamA_name = htmlspecialchars((string) ($data['teamA_name'] ?? 'Team A'), ENT_QUOTES, 'UTF-8');
    $teamB_name = htmlspecialchars((string) ($data['teamB_name'] ?? 'Team B'), ENT_QUOTES, 'UTF-8');
    $match_type = htmlspecialchars((string) ($data['match_type'] ?? 'League'), ENT_QUOTES, 'UTF-8');
    $date_time = htmlspecialchars((string) ($data['date_time'] ?? ''), ENT_QUOTES, 'UTF-8');
    $venue = htmlspecialchars((string) ($data['venue'] ?? ''), ENT_QUOTES, 'UTF-8');
    $teamA_logo = htmlspecialchars((string) ($data['teamA_logo'] ?? ''), ENT_QUOTES, 'UTF-8');
    $teamB_logo = htmlspecialchars((string) ($data['teamB_logo'] ?? ''), ENT_QUOTES, 'UTF-8');
    $teamA_color = htmlspecialchars((string) ($data['teamA_color'] ?? '#f59e0b'), ENT_QUOTES, 'UTF-8');
    $teamB_color = htmlspecialchars((string) ($data['teamB_color'] ?? '#ef4444'), ENT_QUOTES, 'UTF-8');
    $calendar_icon = buildMatchBannerInfoIcon('calendar');
    $location_icon = buildMatchBannerInfoIcon('location');

    $html = "
    <div class='banner-container'>
        <div class='tournament-tag'>{$tournament_name}</div>
        <div class='match-main'>
            <div class='team-box'>
                <div class='logo-wrapper teamA-border'><img src='{$teamA_logo}' alt='Team A logo' /></div>
                <div class='team-name teamA-color'>{$teamA_name}</div>
            </div>
            <div class='vs-box'>
                <div class='vs-text'>VS</div>
                <div class='match-type'>{$match_type}</div>
            </div>
            <div class='team-box'>
                <div class='logo-wrapper teamB-border'><img src='{$teamB_logo}' alt='Team B logo' /></div>
                <div class='team-name teamB-color'>{$teamB_name}</div>
            </div>
        </div>
        <div class='match-footer'>
            <div class='footer-item'>{$calendar_icon}<span>{$date_time}</span></div>
            <div class='footer-item'>{$location_icon}<span>{$venue}</span></div>
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 800px; height: 400px; font-family: 'Outfit', sans-serif; }
    .banner-container {
        width: 800px; height: 400px;
        background:
            radial-gradient(circle at top right, rgba(251, 191, 36, 0.22), transparent 32%),
            radial-gradient(circle at bottom left, rgba(59, 130, 246, 0.22), transparent 34%),
            linear-gradient(135deg, #07111f 0%, #112c45 48%, #08131f 100%);
        padding: 30px; color: white; display: flex; flex-direction: column; justify-content: space-between;
        position: relative; overflow: hidden;
    }
    .banner-container::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background:
            linear-gradient(120deg, rgba(2, 6, 23, 0.25), rgba(2, 6, 23, 0.7)),
            url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1777031427/Night-Lights-at-Narendra-Modi-Stadium_cbq1o1.webp') center/cover no-repeat;
        opacity: 0.48; z-index: 0;
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
        width: 140px; height: 140px; background: rgba(255,255,255,0.96);
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        padding: 16px; overflow: hidden;
        margin: 0 auto 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    }
    .teamA-border { border: 3px solid {$teamA_color}; }
    .teamB-border { border: 3px solid {$teamB_color}; }
    .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; background: white; }
    .team-name { font-size: 32px; font-weight: 800; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); }
    .teamA-color { color: {$teamA_color}; }
    .teamB-color { color: {$teamB_color}; }
    .vs-box { text-align: center; }
    .vs-text { font-size: 80px; font-weight: 900; color: #ef4444; line-height: 1; text-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
    .match-type { font-size: 16px; color: white; font-weight: 600; text-transform: uppercase; margin-top: 5px; }
    .match-footer {
        display: flex; justify-content: center; gap: 24px;
        padding: 14px 18px; background: rgba(5,10,20,0.68); border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .footer-item { font-size: 20px; color: white; font-weight: 600; display: flex; align-items: center; gap: 10px; padding: 6px 10px; }
    .footer-item span { line-height: 1.2; }
    .footer-item svg { width: 22px; height: 22px; flex-shrink: 0; }";

    $image_url = generate_html2image_link($html, $css, 800, 400);
    return upload_generated_image_to_cloudinary($image_url, 'match_banners');
}

function buildMatchBannerInfoIcon(string $type): string
{
    if ($type === 'calendar') {
        return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="5.5" width="17" height="15" rx="2.5" stroke="#FBBF24" stroke-width="1.8"/><path d="M7.5 3.8V7.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.8V7.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><path d="M3.8 9.5H20.2" stroke="#FBBF24" stroke-width="1.8" stroke-linecap="round"/><rect x="7.4" y="12.2" width="3.2" height="3.2" rx="0.8" fill="#FBBF24"/></svg>';
    }

    return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21C15.8 16.8 18.5 13.8 18.5 10.2C18.5 6.5 15.6 3.5 12 3.5C8.4 3.5 5.5 6.5 5.5 10.2C5.5 13.8 8.2 16.8 12 21Z" stroke="#38BDF8" stroke-width="1.8"/><circle cx="12" cy="10" r="2.3" fill="#38BDF8"/></svg>';
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Fetch teams
$teams = [];
try {
    $stmt = $pdo->query("SELECT id, team_name, team_logo, team_code FROM teams ORDER BY team_name");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch tournaments
$tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['team1_id', 'team2_id', 'match_date', 'match_time', 'venue'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        // Validate teams are different
        if ($_POST['team1_id'] === $_POST['team2_id']) {
            throw new Exception("Please select two different teams.");
        }

        // Validate date is not in the past
        $match_datetime = strtotime($_POST['match_date'] . ' ' . $_POST['match_time']);
        if ($match_datetime < time()) {
            throw new Exception("Match date and time cannot be in the past.");
        }

        // Generate unique match code
        $match_date = $_POST['match_date'];
        $date_part = date('ymd', strtotime($match_date)); // YYMMDD format
        $match_type_short = strtoupper(substr($_POST['match_type'] ?? 'League', 0, 1)); // L for League, Q for Quarter, etc.

        // Generate unique code
        $match_code = '';
        $attempts = 0;
        do {
            $random_part = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $match_code = "M{$date_part}{$match_type_short}{$random_part}";
            $attempts++;

            // Check if code already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE match_code = ?");
            $stmt->execute([$match_code]);
            $exists = $stmt->fetchColumn();

            if ($attempts > 100) {
                throw new Exception("Unable to generate unique match code. Please try again.");
            }
        } while ($exists > 0);

        // Insert match
        $stmt = $pdo->prepare("
            INSERT INTO matches (
                match_code, team1_id, team2_id, match_date, match_time, venue, match_type, 
                overs, tournament_id, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming', ?)
        ");

        $stmt->execute([
            $match_code,
            (int) $_POST['team1_id'],
            (int) $_POST['team2_id'],
            $_POST['match_date'],
            $_POST['match_time'],
            trim($_POST['venue']),
            $_POST['match_type'] ?? 'League',
            (int) ($_POST['overs'] ?? 20),
            $_POST['tournament_id'] ? (int) $_POST['tournament_id'] : null,
            $_SESSION['user_id']
        ]);

        $match_id = $pdo->lastInsertId();

        // 🔔 Send Push Notifications
        try {
            error_log("Match created: ID $match_id. Starting notification flow.");
            // Fetch Dynamic Data for Teams
            $stmt = $pdo->prepare("
                SELECT t.team_name, t.team_logo_url, t.team_logo_public_id, t.team_color, u.name as captain_name 
                FROM teams t 
                LEFT JOIN users u ON t.captain_id = u.id 
                WHERE t.id = ?
            ");

            $stmt->execute([$_POST['team1_id']]);
            $team1 = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt->execute([$_POST['team2_id']]);
            $team2 = $stmt->fetch(PDO::FETCH_ASSOC);

            $team1_name = $team1['team_name'] ?? 'Team 1';
            $team2_name = $team2['team_name'] ?? 'Team 2';
            $team1_logo = $team1['team_logo_public_id'] ?? 'default_team_logo';
            $team2_logo = $team2['team_logo_public_id'] ?? 'default_team_logo';
            $team1_color = $team1['team_color'] ?? '#fbbf24';
            $team2_color = $team2['team_color'] ?? '#fbbf24';

            $match_date_fmt = date('d M, Y', strtotime($_POST['match_date']));
            $match_time_fmt = date('h:i A', strtotime($_POST['match_time']));
            $match_venue = trim($_POST['venue']);
            $match_type = $_POST['match_type'] ?? 'League';
            $match_overs = (int) ($_POST['overs'] ?? 20);

            // Fetch Tournament Data
            $cloud_name = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "";
            $tournament_id = $_POST['tournament_id'] ?? null;
            $tournament_name = "Tournament";
            $tournament_logo_url = "";
            if ($tournament_id) {
                $stmtTour = $pdo->prepare("SELECT tournament_name, tournament_logo_public_id FROM tournaments WHERE id = ?");
                $stmtTour->execute([$tournament_id]);
                $tour = $stmtTour->fetch(PDO::FETCH_ASSOC);
                if ($tour) {
                    $tournament_name = $tour['tournament_name'];
                    if ($tour['tournament_logo_public_id']) {
                        $tournament_logo_url = "https://res.cloudinary.com/$cloud_name/image/upload/" . $tour['tournament_logo_public_id'];
                    }
                }
            }

            // 🖼️ Generate Dynamic Match Banner via HTML-to-Image API
            $banner_data = [
                'tournament_name' => $tournament_name,
                'teamA_name' => $team1_name,
                'teamB_name' => $team2_name,
                'teamA_logo' => "https://res.cloudinary.com/$cloud_name/image/upload/" . $team1_logo,
                'teamB_logo' => "https://res.cloudinary.com/$cloud_name/image/upload/" . $team2_logo,
                'teamA_color' => $team1_color,
                'teamB_color' => $team2_color,
                'date_time' => "$match_date_fmt | $match_time_fmt",
                'venue' => $match_venue,
                'match_type' => $match_type
            ];

            $match_banner_url = generateMatchBannerHtml2Image($banner_data);

            // Fallback to simple Cloudinary Banner if API fails
            if (!$match_banner_url) {
                $team1_logo_overlay = str_replace('/', ':', $team1_logo);
                $team2_logo_overlay = str_replace('/', ':', $team2_logo);
                $match_banner_url = "https://res.cloudinary.com/$cloud_name/image/upload/" .
                    "w_800,h_400,c_fill/" .
                    "l_text:Arial_50:" . rawurlencode($tournament_name) . ",g_north,y_20/" .
                    "l_$team1_logo_overlay,w_200,g_west,x_50/" .
                    "l_text:Arial_60:VS,g_center/" .
                    "l_$team2_logo_overlay,w_200,g_east,x_50/" .
                    "l_text:Arial_40:" . rawurlencode($team1_name) . ",g_south_west,x_50,y_20/" .
                    "l_text:Arial_40:" . rawurlencode($team2_name) . ",g_south_east,x_50,y_20/" .
                    "match_bg";
            }

            // 🔔 Condition 1: Competing Team Players
            $stmt = $pdo->prepare("
                SELECT DISTINCT ud.onesignal_player_id 
                FROM user_devices ud
                JOIN team_players tp ON ud.user_id = tp.player_id
                WHERE tp.team_id IN (?, ?) AND ud.onesignal_player_id IS NOT NULL
            ");
            $stmt->execute([$_POST['team1_id'], $_POST['team2_id']]);
            $player_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($player_ids)) {
                sendOneSignalNotification(
                    $player_ids,
                    "Your Team Match is Scheduled",
                    "On $match_date_fmt at $match_time_fmt, $match_venue",
                    [
                        'match_id' => $match_id,
                        'type' => 'match_scheduled',
                        'big_picture' => $match_banner_url,
                        'large_icon' => $tournament_logo_url,
                        'small_icon' => 'ic_stat_notify',
                        'android_sound' => 'notification_sound'
                    ],
                    "https://cptleague.free.nf/CPT_LEAGUE/view/view_match.php?id=$match_id"
                );
            }

            // 🔔 Condition 2: Other Players & Guests (Broad Announcement)
            $stmt = $pdo->prepare("
                SELECT DISTINCT ud.onesignal_player_id 
                FROM user_devices ud
                JOIN users u ON ud.user_id = u.id
                WHERE u.role = 'player' 
                AND u.id NOT IN (
                    SELECT player_id FROM team_players WHERE team_id IN (?, ?)
                ) AND ud.onesignal_player_id IS NOT NULL
            ");
            $stmt->execute([$_POST['team1_id'], $_POST['team2_id']]);
            $other_player_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Fetch Guest Players
            $guest_player_ids = getGuestPlayerIds($pdo);

            // Combine Other Players and Guests for a broad announcement
            $announcement_player_ids = array_unique(array_merge($other_player_ids, $guest_player_ids));

            if (!empty($announcement_player_ids)) {
                sendOneSignalNotification(
                    $announcement_player_ids,
                    "New Match is Scheduled",
                    "On $match_date_fmt at $match_time_fmt, $match_venue",
                    [
                        'match_id' => $match_id,
                        'type' => 'match_announced',
                        'big_picture' => $match_banner_url,
                        'large_icon' => $tournament_logo_url,
                        'small_icon' => 'ic_stat_notify',
                        'android_sound' => 'notification_sound'
                    ],
                    "https://cptleague.free.nf/CPT_LEAGUE/view/view_match.php?id=$match_id"
                );
            }
        } catch (Exception $notif_err) {
            // Notification fail - logs but don't block success message
            error_log("Match Notification Error: " . $notif_err->getMessage());
        }

        // Redirect to matches list with success message
        header("Location: ../NavBarList/matches.php?success=1");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Create Match";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    :root {
        --primary: #4f46e5;
        --secondary: #7c3aed;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --dark: #1e293b;
        --light: #f8fafc;
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --input-bg: #f8fafc;
        --input-border: #e2e8f0;
    }

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f1f5f9;
        background-image:
            radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 0%, rgba(124, 58, 237, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .main-container {
        padding-top: 2rem;
        padding-bottom: 4rem;
        min-height: calc(100vh - 76px);
    }

    /* Glass Card */
    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .card-header-custom {
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        padding: 1.5rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .card-header-title {
        color: white;
        font-weight: 700;
        font-size: 1.25rem;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    /* Form Elements */
    .form-label {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
    }

    .form-control,
    .form-select {
        background-color: var(--input-bg);
        border: 2px solid var(--input-border);
        border-radius: 12px;
        padding: 0.75rem 1rem;
        font-size: 1rem;
        font-weight: 500;
        color: var(--dark);
        transition: all 0.2s ease;
    }

    .form-control:focus,
    .form-select:focus {
        background-color: #fff;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        outline: none;
    }

    /* Team Select & Preview */
    .team-selector {
        position: relative;
    }

    .team-preview {
        margin-top: 1rem;
        background: white;
        border: 2px dashed var(--input-border);
        border-radius: 16px;
        padding: 1rem;
        min-height: 100px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .team-preview.active {
        background: #f0fdf4;
        border-color: #86efac;
        border-style: solid;
    }

    .team-logo-display {
        width: 64px;
        height: 64px;
        object-fit: contain;
        filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .team-preview:hover .team-logo-display {
        transform: scale(1.1) rotate(5deg);
    }

    .team-name-display {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--dark);
        margin-left: 1rem;
    }

    /* VS Badge */
    .vs-container {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem 0;
    }

    .vs-badge {
        width: 60px;
        height: 60px;
        background: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.2rem;
        color: var(--danger);
        box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.3);
        position: relative;
        z-index: 2;
        border: 4px solid #f8fafc;
    }

    /* Buttons */
    .btn-action {
        padding: 0.8rem 2rem;
        border-radius: 50px;
        font-weight: 600;
        letter-spacing: 0.02em;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        border: none;
    }

    .btn-create {
        background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        color: white;
        box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.4);
    }

    .btn-create:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 25px -5px rgba(245, 158, 11, 0.5);
        color: white;
    }

    .btn-back {
        background: white;
        color: var(--dark);
        border: 1px solid var(--input-border);
    }

    .btn-back:hover {
        background: #f8fafc;
        transform: translateY(-2px);
    }

    /* Section Divider */
    .section-title {
        color: var(--secondary);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 700;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .section-title::after {
        content: '';
        height: 1px;
        flex-grow: 1;
        background: linear-gradient(to right, var(--input-border), transparent);
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .vs-container {
            padding: 0;
            margin: 1rem 0;
        }

        .vs-badge {
            width: 40px;
            height: 40px;
            font-size: 0.9rem;
        }

        .card-body {
            padding: 1.5rem;
        }
    }
</style>

<div class="container-fluid main-container">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-calendar-plus"></i>
                        Schedule New Match
                    </h4>
                </div>

                <!-- Body -->
                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-3 border-0 shadow-sm d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($teams)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-warning">
                                <i class="fas fa-users-slash fa-4x opacity-50"></i>
                            </div>
                            <h4 class="fw-bold text-dark">No Teams Found</h4>
                            <p class="text-muted mb-4">You need to create at least two teams to schedule a match.</p>
                            <a href="create_team.php" class="btn btn-create btn-action">
                                <i class="fas fa-plus me-2"></i>Create Team
                            </a>
                        </div>
                    <?php else: ?>
                        <form id="createMatchForm" method="POST" action="" class="needs-validation" novalidate>

                            <!-- Teams Section -->
                            <div class="section-title">
                                <i class="fas fa-shield-alt"></i> Match Contenders
                            </div>

                            <div class="row align-items-stretch mb-5">
                                <!-- Team 1 -->
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label class="form-label">Home Team <span class="text-danger">*</span></label>
                                        <select class="form-select" name="team1_id" id="team1Select" required>
                                            <option value="">Select Team...</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?= $team['id'] ?>" data-logo="<?= $team['team_logo'] ?>">
                                                    <?= htmlspecialchars($team['team_name']) ?> (<?= $team['team_code'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select the first team.</div>
                                        <div class="team-preview" id="team1Preview">
                                            <span class="text-muted small">Select team to preview</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- VS -->
                                <div class="col-md-2">
                                    <div class="vs-container">
                                        <div class="vs-badge">VS</div>
                                    </div>
                                </div>

                                <!-- Team 2 -->
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label class="form-label">Away Team <span class="text-danger">*</span></label>
                                        <select class="form-select" name="team2_id" id="team2Select" required>
                                            <option value="">Select Team...</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?= $team['id'] ?>" data-logo="<?= $team['team_logo'] ?>">
                                                    <?= htmlspecialchars($team['team_name']) ?> (<?= $team['team_code'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select the second team.</div>
                                        <div class="team-preview" id="team2Preview">
                                            <span class="text-muted small">Select team to preview</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Match Details Section -->
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i> Match Logistics
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="match_date" required>
                                    <div class="invalid-feedback">Required</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="match_time" required>
                                    <div class="invalid-feedback">Required</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Venue <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"
                                            style="border-radius: 12px 0 0 12px; border: 2px solid var(--input-border);">
                                            <i class="fas fa-map-marker-alt text-muted"></i>
                                        </span>
                                        <input type="text" class="form-control border-start-0 ps-0" name="venue" required
                                            placeholder="Stadium / Ground Name" style="border-radius: 0 12px 12px 0;">
                                    </div>
                                    <div class="invalid-feedback">Please provide a venue.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Format / Type</label>
                                    <select class="form-select" name="match_type">
                                        <option value="League">League Match</option>
                                        <option value="Quarter Final">Quarter Final</option>
                                        <option value="Semi Final">Semi Final</option>
                                        <option value="Final">Final</option>
                                        <option value="Friendly">Friendly</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Configuration Section -->
                            <div class="section-title">
                                <i class="fas fa-cogs"></i> Configuration
                            </div>

                            <div class="row g-4 mb-5">
                                <div class="col-lg-6">
                                    <label class="form-label">Overs per Innings</label>
                                    <div class="p-3 bg-white border rounded-4">
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <!-- Hidden input to store the actual value sent to DB -->
                                            <input type="hidden" name="overs" id="finalOvers" value="20">

                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over6" value="6">
                                                <label class="form-check-label" for="over6">6 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over8" value="8">
                                                <label class="form-check-label" for="over8">8 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over10" value="10">
                                                <label class="form-check-label" for="over10">10 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over20" value="20" checked>
                                                <label class="form-check-label" for="over20">20 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over50" value="50">
                                                <label class="form-check-label" for="over50">50 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="overCustom" value="custom">
                                                <label class="form-check-label" for="overCustom">Custom</label>
                                            </div>
                                        </div>

                                        <!-- Custom Input Container -->
                                        <div id="customOversContainer" class="mt-3" style="display: none;">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-hashtag text-muted"></i>
                                                </span>
                                                <input type="number" class="form-control border-start-0"
                                                    id="customOversInput" placeholder="Enter number of overs" min="1"
                                                    max="500">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label">Tournament Series</label>
                                    <select class="form-select" name="tournament_id">
                                        <option value="">Independent Match (Friendly)</option>
                                        <?php foreach ($tournaments as $tournament): ?>
                                            <option value="<?= $tournament['id'] ?>">
                                                <?= htmlspecialchars($tournament['tournament_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="d-flex justify-content-between align-items-center pt-3 mt-4 border-top">
                                <button type="button" class="btn btn-action btn-back" onclick="window.history.back()">
                                    <i class="fas fa-arrow-left me-2"></i>Cancel
                                </button>
                                <button type="submit" class="btn btn-action btn-create">
                                    <i class="fas fa-check-circle me-2"></i>Schedule Match
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional Custom Styles for Radio Inputs -->
<style>
    .form-check-input:checked {
        background-color: var(--primary);
        border-color: var(--primary);
    }
</style>

<script>
    const teams = <?= json_encode($teams) ?>;

    // Logic for Custom Overs Selection
    document.addEventListener('DOMContentLoaded', function () {
        const presets = document.querySelectorAll('.overs-preset');
        const finalInput = document.getElementById('finalOvers');
        const customContainer = document.getElementById('customOversContainer');
        const customInput = document.getElementById('customOversInput');

        if (presets.length > 0) {
            presets.forEach(preset => {
                preset.addEventListener('change', function () {
                    if (this.value === 'custom') {
                        customContainer.style.display = 'block';
                        customInput.required = true;
                        customInput.focus();
                        finalInput.value = customInput.value; // Sync if value exists
                    } else {
                        customContainer.style.display = 'none';
                        customInput.required = false;
                        finalInput.value = this.value;
                    }
                });
            });

            customInput.addEventListener('input', function () {
                finalInput.value = this.value;
            });
        }
    });

    document.getElementById('team1Select').addEventListener('change', function () {
        updateTeamPreview('team1Preview', this.value);
        updateTeam2Options();
    });

    document.getElementById('team2Select').addEventListener('change', function () {
        updateTeamPreview('team2Preview', this.value);
        updateTeam1Options();
    });

    function updateTeamPreview(previewId, teamId) {
        const preview = document.getElementById(previewId);
        const team = teams.find(t => t.id == teamId);

        if (team) {
            preview.innerHTML = `
            <div class="d-flex align-items-center">
                <img src="${team.team_logo ? '../uploads/teams/' + team.team_logo : '../images/default-team.png'}" 
                     alt="${team.team_name}" 
                     class="team-logo-display">
                <div class="team-name-display">
                    ${team.team_name}
                </div>
            </div>
        `;
        } else {
            preview.innerHTML = '<div class="text-muted">Select a team</div>';
        }
    }

    function updateTeam2Options() {
        const team1Value = document.getElementById('team1Select').value;
        const team2Select = document.getElementById('team2Select');
        const currentTeam2Value = team2Select.value;

        // Reset team2 options
        team2Select.innerHTML = '<option value="">Select Team 2</option>';

        teams.forEach(team => {
            if (team.id != team1Value) {
                const option = document.createElement('option');
                option.value = team.id;
                option.textContent = team.team_name + ' (' + team.team_code + ')';
                option.setAttribute('data-logo', team.team_logo);
                if (team.id == currentTeam2Value && team.id != team1Value) {
                    option.selected = true;
                }
                team2Select.appendChild(option);
            }
        });
    }

    function updateTeam1Options() {
        const team2Value = document.getElementById('team2Select').value;
        const team1Select = document.getElementById('team1Select');
        const currentTeam1Value = team1Select.value;

        // Reset team1 options
        team1Select.innerHTML = '<option value="">Select Team 1</option>';

        teams.forEach(team => {
            if (team.id != team2Value) {
                const option = document.createElement('option');
                option.value = team.id;
                option.textContent = team.team_name + ' (' + team.team_code + ')';
                option.setAttribute('data-logo', team.team_logo);
                if (team.id == currentTeam1Value && team.id != team2Value) {
                    option.selected = true;
                }
                team1Select.appendChild(option);
            }
        });
    }

    document.getElementById('createMatchForm').addEventListener('submit', function (e) {
        const form = this;

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const team1 = document.getElementById('team1Select').value;
        const team2 = document.getElementById('team2Select').value;

        if (team1 === team2) {
            e.preventDefault();
            alert('Please select two different teams');
            return;
        }

        // Add loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Scheduling...';
        submitBtn.disabled = true;

        // Re-enable after 10 seconds as fallback
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);

        // Allow form to submit normally
    });
</script>

<?php require_once '../includes/footer.php'; ?>
