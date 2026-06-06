<?php
require_once '../../includes/db.php';
require_once '../../includes/onesignal_utils.php';
require_once '../../includes/notification_banner_utils.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json');
error_reporting(0);


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

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

/**
 * Generates a premium Match Completed banner from HTML/CSS
 */
function generateMatchCompletedBanner($data)
{
    return generate_match_completed_notification_banner((array) $data);

    $api_user = $_ENV['HCTI_USER_ID_MATCH_COMPLETED_NOTI'] ?? '';
    $api_key = $_ENV['HCTI_API_KEY_MATCH_COMPLETED_NOTI'] ?? '';

    if (empty($api_user) || empty($api_key)) {
        return null;
    }

    $team_name = htmlspecialchars($data['team_name']);
    
    // Split players into two rows: 6 and 5
    $row1_players = array_slice($data['players'], 0, 6);
    $row2_players = array_slice($data['players'], 6);

    $row1_html = "";
    foreach ($row1_players as $p) {
        $p_img = $p['image'] ?: "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1743608770/cricket_p_qfscy6.jpg";
        $row1_html .= "
        <div class='player-item'>
            <div class='img-container'><img src='{$p_img}' /></div>
        </div>";
    }

    $row2_html = "";
    foreach ($row2_players as $p) {
        $p_img = $p['image'] ?: "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1743608770/cricket_p_qfscy6.jpg";
        $row2_html .= "
        <div class='player-item'>
            <div class='img-container'><img src='{$p_img}' /></div>
        </div>";
    }

    $html = "
    <div class='banner-container'>
        <div class='overlay'></div>
        <div class='content-wrapper'>
            <div class='team-header'>$team_name</div>
            <div class='players-grid'>
                <div class='player-row'>$row1_html</div>
                <div class='player-row row-2'>$row2_html</div>
            </div>
        </div>
    </div>";

    $css = "
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 1024px; height: 512px; font-family: 'Outfit', sans-serif; overflow: hidden; }
    .banner-container { 
        width: 1024px; height: 512px; 
        background: url('https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/v1778073510/OIP_vxotsb.webp') center/cover no-repeat;
        position: relative;
        display: flex; flex-direction: column; align-items: center;
    }
    .overlay {
        position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.4), rgba(0,0,0,0.7));
        z-index: 1;
    }
    .content-wrapper { position: relative; z-index: 2; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; padding: 40px 20px; }
    .team-header { 
        font-size: 52px; font-weight: 800; color: #fbbf24; text-transform: uppercase; 
        text-shadow: 0 4px 10px rgba(0,0,0,0.8); margin-bottom: 40px; text-align: center;
        letter-spacing: 2px;
    }
    .players-grid { display: flex; flex-direction: column; gap: 40px; width: 100%; }
    .player-row { display: flex; justify-content: center; gap: 30px; width: 100%; }
    .row-2 { gap: 40px; }
    .player-item { text-align: center; width: 130px; position: relative; }
    .img-container { 
        width: 130px; height: 130px; border-radius: 8px; overflow: hidden;
        border: 3px solid rgba(251, 191, 36, 0.8); background: rgba(0,0,0,0.3);
        box-shadow: 0 10px 25px rgba(0,0,0,0.6);
    }
    .img-container img { width: 100%; height: 100%; object-fit: cover; }
    ";

    $ch = curl_init('https://hcti.io/v1/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['html' => $html, 'css' => $css]));
    curl_setopt($ch, CURLOPT_USERPWD, $api_user . ':' . $api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    $image_url = $res['url'] ?? null;

    if ($image_url && class_exists('\Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $cloud_res = $uploadApi->upload($image_url, [
                'folder' => 'match_completion_banners',
                'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ''
            ]);
            return $cloud_res['secure_url'];
        } catch (Exception $e) {
            return $image_url;
        }
    }
    return $image_url;
}

function generateMatchCompletedBannerHtml2Image(array $data): ?string
{
    return generate_match_completed_notification_banner($data);
}

function sendResponse($success, $message, $data = [])
{
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit();
}

$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$match_id)
    sendResponse(false, 'Match ID required');

// --- STATS UPDATE FUNCTION ---
function updatePlayerCareerStats($match_id, $pdo)
{
    // 1. Check if match is already completed (prevents double update)
    $stmt = $pdo->prepare("SELECT status FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $status = $stmt->fetchColumn();

    if ($status === 'completed')
        return; // Prevent duplicate updates or updating already finalized stats matches

    // 2. Fetch all players in Playing 11 (Both Teams)
    $stmt = $pdo->prepare("
        SELECT ms.player_id, 
               COALESCE(mst.runs_scored, 0) as runs,
               COALESCE(mst.balls_faced, 0) as balls,
               COALESCE(mst.fours, 0) as fours,
               COALESCE(mst.sixes, 0) as sixes,
               COALESCE(mst.wickets_taken, 0) as wickets,
               COALESCE(mst.runs_conceded, 0) as conceded,
               COALESCE(mst.overs_bowled, 0) as overs

        FROM match_squads ms
        LEFT JOIN match_statistics mst ON ms.player_id = mst.player_id AND mst.match_id = ms.match_id
        WHERE ms.match_id = ? AND ms.playing_11 = 1
    ");
    $stmt->execute([$match_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($players as $p) {
        $pid = $p['player_id'];

        // 3. Fetch Fielding Stats Breakdown from Ball-by-Ball
        $stmt_f = $pdo->prepare("SELECT 
            COUNT(CASE WHEN wicket_type = 'caught' AND fielder_id = ? THEN 1 END) as catches,
            COUNT(CASE WHEN wicket_type = 'stumped' AND fielder_id = ? THEN 1 END) as stumpings,
            COUNT(CASE WHEN wicket_type = 'run out' AND fielder_id = ? THEN 1 END) as run_outs
            FROM ball_by_ball WHERE match_id = ?");
        $stmt_f->execute([$pid, $pid, $pid, $match_id]);
        $f = $stmt_f->fetch(PDO::FETCH_ASSOC);

        // 4. Fetch Existing Career Stats
        $stmt_c = $pdo->prepare("SELECT * FROM player_stats WHERE player_id = ?");
        $stmt_c->execute([$pid]);
        $curr = $stmt_c->fetch(PDO::FETCH_ASSOC);

        // Default if new player
        if (!$curr) {
            $curr = [
                'matches_played' => 0,
                'innings_batted' => 0,
                'runs_scored' => 0,
                'balls_faced' => 0,
                'highest_score' => 0,
                'batting_average' => 0,
                'strike_rate' => 0,
                'centuries' => 0,
                'half_centuries' => 0,
                'fours' => 0,
                'sixes' => 0,
                'innings_bowled' => 0,
                'wickets_taken' => 0,
                'runs_conceded' => 0,
                'overs_bowled' => 0,
                'best_bowling' => '0/0',
                'bowling_average' => 0,
                'economy_rate' => 0,
                'five_wickets' => 0,
                'catches' => 0,
                'stumpings' => 0,
                'run_outs' => 0
            ];
        }

        // 5. Calculate New Stats
        // Rules: 
        // Innings Batted: +1 if faced >= 1 ball
        // Innings Bowled: +1 if overs > 0 (meaning bowled at least 1 legal ball)

        $inn_bat_inc = ($p['balls'] > 0) ? 1 : 0;
        $inn_bowl_inc = ($p['overs'] > 0) ? 1 : 0;

        $new_matches = $curr['matches_played'] + 1;
        $new_inn_batted = $curr['innings_batted'] + $inn_bat_inc;
        $new_runs = $curr['runs_scored'] + $p['runs'];
        $new_balls = $curr['balls_faced'] + $p['balls'];
        $new_hs = max($curr['highest_score'], $p['runs']);

        $new_bat_avg = ($new_inn_batted > 0) ? ($new_runs / $new_inn_batted) : 0;
        $new_sr = ($new_balls > 0) ? (($new_runs / $new_balls) * 100) : 0;

        $new_100s = $curr['centuries'] + ($p['runs'] >= 100 ? 1 : 0);
        $new_50s = $curr['half_centuries'] + (($p['runs'] >= 50 && $p['runs'] < 100) ? 1 : 0);
        $new_fours = $curr['fours'] + $p['fours'];
        $new_sixes = $curr['sixes'] + $p['sixes'];

        $new_inn_bowled = $curr['innings_bowled'] + $inn_bowl_inc;
        $new_wickets = $curr['wickets_taken'] + $p['wickets'];
        $new_conceded = $curr['runs_conceded'] + $p['conceded'];

        // Sum overs accurately
        $c_ov = $curr['overs_bowled'];
        $m_ov = $p['overs'];
        $total_balls_bowled = (floor($c_ov) * 6 + ($c_ov * 10) % 10) + (floor($m_ov) * 6 + ($m_ov * 10) % 10);
        $new_overs = floor($total_balls_bowled / 6) . "." . ($total_balls_bowled % 6);

        $new_bowl_avg = ($new_wickets > 0) ? ($new_conceded / $new_wickets) : 0;
        $new_econ = ($total_balls_bowled > 0) ? ($new_conceded / ($total_balls_bowled / 6)) : 0;
        $new_5w = $curr['five_wickets'] + ($p['wickets'] >= 5 ? 1 : 0);

        // Best Bowling (Compare wickets, then runs)
        $bb = $curr['best_bowling'];
        if (!$bb)
            $bb = '0/0';
        list($bw, $br) = explode('/', $bb);

        if ($p['wickets'] > $bw) {
            $bb = $p['wickets'] . '/' . $p['conceded'];
        } elseif ($p['wickets'] == $bw && $p['conceded'] < $br) {
            $bb = $p['wickets'] . '/' . $p['conceded'];
        }

        $new_cat = $curr['catches'] + $f['catches'];
        $new_st = $curr['stumpings'] + $f['stumpings'];
        $new_ro = $curr['run_outs'] + $f['run_outs'];

        // 6. DB Update
        $sql = "INSERT INTO player_stats (
            player_id, matches_played, innings_batted, runs_scored, balls_faced, highest_score, batting_average, strike_rate,
            centuries, half_centuries, fours, sixes,
            innings_bowled, wickets_taken, runs_conceded, overs_bowled, best_bowling, bowling_average, economy_rate, five_wickets,
            catches, stumpings, run_outs
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        ) ON DUPLICATE KEY UPDATE 
            matches_played = VALUES(matches_played),
            innings_batted = VALUES(innings_batted),
            runs_scored = VALUES(runs_scored),
            balls_faced = VALUES(balls_faced),
            highest_score = VALUES(highest_score),
            batting_average = VALUES(batting_average),
            strike_rate = VALUES(strike_rate),
            centuries = VALUES(centuries),
            half_centuries = VALUES(half_centuries),
            fours = VALUES(fours),
            sixes = VALUES(sixes),
            innings_bowled = VALUES(innings_bowled),
            wickets_taken = VALUES(wickets_taken),
            runs_conceded = VALUES(runs_conceded),
            overs_bowled = VALUES(overs_bowled),
            best_bowling = VALUES(best_bowling),
            bowling_average = VALUES(bowling_average),
            economy_rate = VALUES(economy_rate),
            five_wickets = VALUES(five_wickets),
            catches = VALUES(catches),
            stumpings = VALUES(stumpings),
            run_outs = VALUES(run_outs)
        ";

        $stmt_u = $pdo->prepare($sql);
        $stmt_u->execute([
            $pid,
            $new_matches,
            $new_inn_batted,
            $new_runs,
            $new_balls,
            $new_hs,
            $new_bat_avg,
            $new_sr,
            $new_100s,
            $new_50s,
            $new_fours,
            $new_sixes,
            $new_inn_bowled,
            $new_wickets,
            $new_conceded,
            $new_overs,
            $bb,
            $new_bowl_avg,
            $new_econ,
            $new_5w,
            $new_cat,
            $new_st,
            $new_ro
        ]);
    }

    // 7. Mark Match as Stats Applied - Removed as column doesn't exist. Replaced by status check.
    // $pdo->prepare("UPDATE matches SET stats_applied = 1 WHERE id = ?")->execute([$match_id]);
}

$action = $_POST['action'] ?? '';

// Commentary Sentences
$commentary_templates = [
    '0' => [
        "Semma line and length da! 🏏\nBatsman paathu defend pannitu calm ahh nikraan.",
        
        "Dot ball again! 🔴\nBowler pressure cooker madhiri build pannitu irukaan.",
        
        "Outside off ahh nalla leave pannitaan 👀\nRisk edukkaama smart ahh aadraan.",
        
        "Straight ahh fielder kitta pochu 🛑\nRun ku chance eh illa da.",
        
        "Aiyo beaten! ⚡\nBall movement paathu batsman confuse aayitaan.",
        
        "Solid defense machi 🛡️\nTextbook batting nu idha dhaan solluvaanga.",
        
        "Quick fielding da! 🧤\nSingle ahh complete stop pannitaanga.",
        
        "Timing irundhalum gap illa 🎯\nField setup semma tight ahh iruku.",
        
        "Pressure build aagudhu 📈\nBowler ippo full control la irukaan.",
        
        "Watchful start from batsman 🧐\nFirst settle aaganum nu plan pannraan."
    ],

    '1' => [
        "Easy single eduthutaanga 🏃\nStrike rotate pannradhu semma important ippo.",
        
        "Smart cricket da 🔄\nBatsman calm ahh scoreboard move pannraan.",
        
        "Leg side la tuck pannitu one 💨\nFielders react panna munnaadi run mudinju pochu.",
        
        "Quick run taken ⚡\nCommunication rendu perukum semma iruku.",
        
        "Soft hands use pannitu single 😌\nRisk illa, smart batting.",
        
        "Misfield advantage use pannitaanga 🙈\nFree ahh oru run gift madhiri.",
        
        "Gap find pannitu easy one 👌\nBowler ku frustration increase aagudhu.",
        
        "Tap and run strategy 🏏\nOld school but effective da.",
        
        "Single ku semma running between wickets 🏃‍♂️\nFitness level vera maari iruku.",
        
        "Rotation continues ✅\nBowler settle aaga vidama disturb pannraanga."
    ],

    '4' => [
        "Adei semma shot da! 💎\nCover drive boundary ku rocket madhiri pochu.",
        
        "Loose ball ku punishment ready 🔥\nBoundary line cross panniduchu!",
        
        "Elegant drive machi ✨\nAudience ellarum clap pannitu irukaanga.",
        
        "Pull shot mass ahh pochu 💪\nFielder paakradha thavira onnum panna mudiyala.",
        
        "Edge aanaalum four 🍀\nLuck um batsman side dhaan iruku.",
        
        "Sweep shot semma class 🧹\nFine leg side la boundary confirm.",
        
        "Timing vera level 🎯\nBall grass touch panna chance eh illa.",
        
        "Straight drive ahh wow 🚀\nCricket poster la vechikalaam indha shot ahh.",
        
        "Cut shot blazing speed la ⚔️\nPoint fielder ku chance eh illa.",
        
        "Boundaryyyyy! 👏\nBowler face la stress clear ahh theriyudhu."
    ],

    '6' => [
        "Adei paavi! Ball stadium veliya poiduchu 🚀\nIdhu six illa satellite launch da!",
        
        "Into the stands! 🏟️\nAudience catch panna ready ahh irukaanga.",
        
        "Clean ahh middle pannitaan 💥\nBowler ku nightmares start aagiduchu.",
        
        "Mid wicket mela massive six ☄️\nPower hitting masterclass ongoing.",
        
        "Straight down the ground da 💣\nCamera kuda follow panna kashtam.",
        
        "Length pick pannitu adichitaan 🦅\nBall ku passport venum pola.",
        
        "Maximum incoming 🔟\nFielders ellarum sightseeing mode la.",
        
        "Power hitter activated ⚡\nBowler confidence low battery warning.",
        
        "Another huge six 😱\nCaptain ippo field enga podradhu nu yosikraan.",
        
        "High and handsome 😍\nCrowd full ahh enjoy pannitu iruku."
    ],

    'run out' => [
        "Aiyo run out da 😫\nCommunication total ahh collapse aayiduchu.",
        
        "Direct hit! 🎯\nFielder bullet throw potutaan machi.",
        
        "Confusion in middle 🤦\nRendu perum same side ku odi vandhutaanga.",
        
        "Crease reach panna mudiyala 📏\nJust konjam short ahh poitaan.",
        
        "Semma fielding effort 🧤\nMatch momentum change aagudhu.",
        
        "Needless wicket da 🚨\nCoach ippo tension la irupaaru.",
        
        "Run ku ponaanga, wicket ahh kuduthutaanga ❌\nPressure clearly visible.",
        
        "Sharp throw and boom 💨\nStumps flying scene vera level.",
        
        "Batsman disappointment full face la 😔\nEasy ahh avoid pannirukalaam.",
        
        "Fielding side semma celebration 🎉\nImportant breakthrough kidaichiduchu."
    ],

    'bowled' => [
        "Bowled him da! 🪵\nStumps dance aadudhu paah!",
        
        "Gate open pannitaan 🚪\nBall straight ahh stumps ahh hit panniduchu.",
        
        "Timberrrrr! 🎡\nBails air la flying mode.",
        
        "Clean bowled machi 💥\nBatsman still shock la dhaan irukaan.",
        
        "Yorker semma deadly 🎯\nPerfect execution from bowler.",
        
        "Adei enna ball da idhu 🔥\nReplay paakanum nu thonudhu.",
        
        "Stumps cartwheel mode 🌪️\nCrowd full ahh roar pannudhu.",
        
        "Bowler celebration mass ahh iruku ⚡\nImportant wicket secured.",
        
        "No clue for batsman 😵\nBall inside vandhadhu theriyave illa.",
        
        "Absolute beauty da 👌\nTest match quality delivery."
    ],

    'caught' => [
        "OUTTT! 🧤\nSafe ahh catch complete pannitaan.",
        
        "Edge and taken da ⚡\nKeeper reflex semma speed.",
        
        "Slip la sharp catch 👏\nFielding vera level today.",
        
        "Straight ahh fielder hand ku 🎁\nGift wrap wicket madhiri.",
        
        "Sky high shot ☁️\nFinally safe ahh hands la settle aayiduchu.",
        
        "Big wicket machi 📉\nBatting side ku shock moment.",
        
        "Boundary line la semma catch 🚧\nBalance and awareness top class.",
        
        "Mistime panna result idhu dhaan ❌\nBowler finally smile pannraan.",
        
        "Safe hands da 👐\nPressure la kuda miss panna illa.",
        
        "Fielders semma celebration 🎉\nMatch twist ippo start."
    ],

    'lbw' => [
        "LBW da! ☝️\nUmpire finger rocket speed la mela pochu.",
        
        "Front la trap pannitaan 🙊\nBatsman review yosichitu irukaan.",
        
        "Huge appeal and OUT 📣\nBowler confidence max level.",
        
        "Dead plumb da ⚓\nBall straight stumps ahh hit pannirukum.",
        
        "Smart bowling effort 🎯\nLine and length semma accurate.",
        
        "Pad first, bat illa 🚨\nEasy decision for umpire.",
        
        "Pressure creates wicket 🔥\nBatsman movement total confuse.",
        
        "Review pannaalum save aaga maataan 📺\nClear out scene.",
        
        "Bowler celebration semma energy ⚡\nTeam full hype la iruku.",
        
        "Important breakthrough kidaichiduchu 👌\nGame ippo interesting aagudhu."
    ],

    'stumped' => [
        "Stumped da! 🕊️\nKeeper lightning speed la work pannitaan.",
        
        "Crease veliya vandhu maatikitaan 🏃‍♂️\nSpinner semma trap set pannitaan.",
        
        "Quick gloves work 🧤\nBatsman return vara munnaadi bails gone.",
        
        "Flight ku mosama bait aayitaan 🎯\nSpinner mind game win pannitaan.",
        
        "Dance panna vandhu wicket kuduthutaan 💃\nRisky shot total fail.",
        
        "Keeper semma alert 🌪️\nChance kidaicha udane finish pannitaan.",
        
        "Classic spin wicket 🌀\nOld school dismissal vibes.",
        
        "Turn and bounce semma dangerous ⚡\nBatsman clue eh illa.",
        
        "Out of crease punishment 🚫\nFielding side full happy.",
        
        "Beautiful teamwork da 👏\nBowler and keeper combo semma."
    ],

    'W' => [
        "Big wicket down da 📉\nMatch momentum ippo change aagalam.",
        
        "What a breakthrough 🚶\nFielding side ku semma boost.",
        
        "Finally wicket kidaichiduchu 🔓\nBowler hard work pay off aayiduchu.",
        
        "Batsman walk back pannraan 🔚\nCrowd mixed reaction kudukudhu.",
        
        "Pressure worked perfectly ⚡\nField setup semma useful aayiduchu.",
        
        "Important partnership break 💥\nCaptain face la relief clear.",
        
        "Huge celebration from players 🎉\nGame ippo fire mode la.",
        
        "Bowler semma comeback 🔥\nTiming of wicket romba crucial.",
        
        "Another batter gone 😔\nBatting side little shaky ahh theriyudhu.",
        
        "Massive turning point da 👌\nIppo yaar dominate pannuvaanga?"
    ],

    'WD' => [
        "Wide ball da ⬅️\nKeeper kuda reach panna mudiyala.",
        
        "Too wide outside off ➡️\nFree ahh extra kuduthutaan.",
        
        "Umpire hands spread 🤚\nBowler control konjam miss aagudhu.",
        
        "Leg side la romba poiduchu 📏\nDiscipline important da ippo.",
        
        "Another extra 😬\nCaptain ku tension increase aagudhu.",
        
        "Pressure bowling effect 🌪️\nLine maintain panna kashtapadraan.",
        
        "Easy run gift pannitaan 🎁\nBatting side happy ahh accept pannitaanga.",
        
        "Loose delivery da 🚫\nBowler reset aaganum quickly.",
        
        "Wide and frustration combo 📈\nFielders silent ahh paakraanga.",
        
        "Bad line punished instantly 🏏\nExtras count mela pogudhu."
    ],

    'NB' => [
        "No ball da 👣\nFront foot line cross panniduchu.",
        
        "Illegal delivery 🚫\nFree run plus pressure bonus.",
        
        "Overstep pannitaan ⚠️\nBowler concentration konjam poiduchu.",
        
        "Free hit coming maybe 😱\nBatsman smile pannitu ready ahh irukaan.",
        
        "Costly mistake from bowler 📏\nCaptain romba happy ahh illa.",
        
        "Discipline issue clear ahh theriyudhu 😬\nImportant moment la mistake.",
        
        "Extra gift pannitaan 🎁\nBatting side chance use pannalama?",
        
        "No ball signal vandhuduchu 🚨\nCrowd loud reaction kudukudhu.",
        
        "Bowler frustration visible ❌\nSmall mistake big impact kudukkum.",
        
        "Pressure making bowlers nervous 📈\nMatch intensity semma high."
    ]
];

function generateCommentary($event)
{
    global $commentary_templates;
    $key = (string) $event;

    // Fallback logic
    if (!isset($commentary_templates[$key])) {
        // If it's a known wicket type but missing specific template, fallback to 'W' (Generic Wicket)
        if (in_array($key, ['run out', 'bowled', 'caught', 'lbw', 'stumped', 'hit wicket'])) {
            $key = 'W';
        } else {
            $key = '0'; // Default to dot ball if unknown
        }
    }

    // Final check
    if (!isset($commentary_templates[$key]))
        $key = '0';

    $sentences = $commentary_templates[$key];
    return $sentences[array_rand($sentences)];
}

try {
    $pdo->beginTransaction();

    // Fetch Match & Innings
    $stmt = $pdo->prepare("
        SELECT m.*, m.overs as total_overs, 
               t1.team_name as team1_name, t2.team_name as team2_name 
        FROM matches m 
        JOIN teams t1 ON m.team1_id = t1.id 
        JOIN teams t2 ON m.team2_id = t2.id 
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match)
        throw new Exception("Match not found");

    // Fetch Current Innings
    $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number DESC LIMIT 1");
    $stmt->execute([$match_id]);
    $innings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($innings && ($innings['inning_number'] == 3 || $innings['inning_number'] == 4)) {
        $total_overs_limit = 1; // Super Over is always 1 over
        $wicket_limit = 2;      // Standard Super Over wicket limit
    } else {
        $total_overs_limit = (int) $match['total_overs'];
        $wicket_limit = 10;
    }

    if ($action === 'undo') {
        // Undo Logic
        $stmt = $pdo->prepare("SELECT * FROM ball_by_ball WHERE match_id = ? AND inning_number = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $last_ball = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$last_ball)
            throw new Exception("Nothing to undo");

        // Reverse Runs in Innings
        $runs = $last_ball['runs_scored'] + $last_ball['extra_runs'];

        // Super-robust wicket detection for undo
        $raw_wicket_type = isset($last_ball['wicket_type']) ? trim((string) $last_ball['wicket_type']) : '';
        $wicket_pid = isset($last_ball['wicket_player_id']) ? (int) $last_ball['wicket_player_id'] : 0;

        // Is it fundamentally a wicket ball?
        // It's a wicket if wicket_type is set OR if a wicket_player_id is present
        $is_wicket = (!empty($raw_wicket_type) || $wicket_pid > 0) ? 1 : 0;

        // Normalize for bowler credit check
        $norm_wicket_type = strtolower($raw_wicket_type);
        $disqualified_types = ['run out', 'retired hurt', 'obstructing the field', 'handled the ball', 'retired out'];
        $is_bowler_wicket = ($is_wicket && !empty($norm_wicket_type) && !in_array($norm_wicket_type, $disqualified_types)) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE innings SET total_runs = GREATEST(0, total_runs - ?), wickets = GREATEST(0, wickets - ?) WHERE id = ?");
        $stmt->execute([$runs, $is_wicket, $innings['id']]);

        // Reverse Batter Stats
        $stmt = $pdo->prepare("
            UPDATE match_statistics 
            SET runs_scored = GREATEST(0, runs_scored - ?), 
                balls_faced = GREATEST(0, balls_faced - (CASE WHEN ? IS NULL THEN 1 ELSE 0 END)),
                fours = GREATEST(0, fours - (CASE WHEN ? = 4 THEN 1 ELSE 0 END)),
                sixes = GREATEST(0, sixes - (CASE WHEN ? = 6 THEN 1 ELSE 0 END))
            WHERE match_id = ? AND player_id = ? AND inning_number = ?
        ");
        $stmt->execute([$last_ball['runs_scored'], $last_ball['extra_type'], $last_ball['runs_scored'], $last_ball['runs_scored'], $match_id, $last_ball['batsman_id'], $last_ball['inning_number']]);

        // Reverse Bowler Stats (Runs and Wickets)
        // We calculate new overs after this, then update all at once or separately
        // For now, let's keep it robust. Find balls remaining for this bowler.
        $stmt_b = $pdo->prepare("SELECT count(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND bowler_id = ? AND id != ? AND extra_type IS NULL");
        $stmt_b->execute([$match_id, $innings['inning_number'], $last_ball['bowler_id'], $last_ball['id']]);
        $b_balls = $stmt_b->fetchColumn();
        $b_overs = floor($b_balls / 6) . '.' . ($b_balls % 6);

        $stmt = $pdo->prepare("
            UPDATE match_statistics 
            SET runs_conceded = GREATEST(0, runs_conceded - ?), 
                wickets_taken = GREATEST(0, wickets_taken - ?),
                overs_bowled = ?
            WHERE match_id = ? AND player_id = ? AND inning_number = ?
        ");
        $stmt->execute([$runs, $is_bowler_wicket, $b_overs, $match_id, $last_ball['bowler_id'], $last_ball['inning_number']]);

        // Delete Ball
        $stmt = $pdo->prepare("DELETE FROM ball_by_ball WHERE id = ?");
        $stmt->execute([$last_ball['id']]);

        // Recalculate Overs
        $stmt = $pdo->prepare("SELECT count(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND extra_type IS NULL");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $valid_balls = $stmt->fetchColumn();
        $overs = floor($valid_balls / 6) . '.' . ($valid_balls % 6);
        $stmt = $pdo->prepare("UPDATE innings SET overs_bowled = ? WHERE id = ?");
        $stmt->execute([$overs, $innings['id']]);

        // Done above


        // Restore Players
        $stmt = $pdo->prepare("UPDATE matches SET current_striker_id = ?, current_non_striker_id = ?, current_bowler_id = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$last_ball['batsman_id'], $last_ball['non_striker_id'], $last_ball['bowler_id'], $match_id]);

        // Revoke completion status if needed
        if ($match['status'] === 'completed') {
            $stmt = $pdo->prepare("UPDATE matches SET status = 'live', winner_id = NULL, result = NULL WHERE id = ?");
            $stmt->execute([$match_id]);
        }

        $pdo->commit();
        sendResponse(true, 'Undo successful');

    } elseif ($action === 'score_update') {
        $striker_id = $match['current_striker_id'];
        $non_striker_id = $match['current_non_striker_id'];
        $bowler_id = $match['current_bowler_id'];

        if (!$striker_id)
            throw new Exception("Select Striker");
        if (!$non_striker_id)
            throw new Exception("Select Non-Striker");
        if (!$bowler_id)
            throw new Exception("Select Bowler");

        $runs = (int) $_POST['runs'];
        $is_wicket = ($_POST['is_wicket'] === 'true');
        $wicket_type = $_POST['wicket_type'] ?? null;
        $extra_type = $_POST['extra_type'] ?? null;
        $fielder_id = isset($_POST['fielder_id']) ? (int) $_POST['fielder_id'] : null;
        $wicket_player_id = isset($_POST['wicket_player_id']) ? (int) $_POST['wicket_player_id'] : $striker_id;

        $runs_scored = ($extra_type === 'wide') ? 0 : $runs;
        $extra_runs = ($extra_type) ? (1 + ($extra_type === 'wide' ? $runs : 0)) : 0;
        if ($extra_type === 'no ball') {
            $runs_scored = $runs;
            $extra_runs = 1;
        }

        $total_delivery_runs = $runs_scored + $extra_runs;
        $is_legal_ball = ($extra_type !== 'wide' && $extra_type !== 'no ball');

        $stmt = $pdo->prepare("SELECT count(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND extra_type IS NULL");
        $stmt->execute([$match_id, $innings['inning_number']]);
        $valid_balls_prev = $stmt->fetchColumn();

        $over_number = floor($valid_balls_prev / 6);
        $ball_number = ($valid_balls_prev % 6) + 1;

        $comm_event = $is_wicket ? ($wicket_type ?: 'W') : ($extra_type === 'wide' ? 'WD' : ($extra_type === 'no ball' ? 'NB' : $runs_scored));
        $commentary = generateCommentary($comm_event);

        // Insert Ball
        $stmt = $pdo->prepare("INSERT INTO ball_by_ball (match_id, inning_number, over_number, ball_number, batsman_id, non_striker_id, bowler_id, runs_scored, extra_type, extra_runs, wicket_type, wicket_player_id, fielder_id, commentary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$match_id, $innings['inning_number'], $over_number, $ball_number, $striker_id, $non_striker_id, $bowler_id, $runs_scored, $extra_type, $extra_runs, $wicket_type, $is_wicket ? $wicket_player_id : null, $fielder_id, $commentary]);

        // Update Innings
        $valid_balls_new = $valid_balls_prev + ($is_legal_ball ? 1 : 0);
        $overs_display = floor($valid_balls_new / 6) . '.' . ($valid_balls_new % 6);
        $stmt = $pdo->prepare("UPDATE innings SET total_runs = total_runs + ?, wickets = wickets + ?, overs_bowled = ? WHERE id = ?");
        $stmt->execute([$total_delivery_runs, $is_wicket ? 1 : 0, $overs_display, $innings['id']]);

        // Update Batter Stats
        if ($is_legal_ball || $extra_type === 'no ball') {
            // Ensure entry
            foreach ([$striker_id, $non_striker_id] as $pid) {
                if ($pid) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO match_statistics (match_id, player_id, team_id, inning_number) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$match_id, $pid, $innings['batting_team_id'], $innings['inning_number']]);
                }
            }

            if ($is_legal_ball) {
                // Update striker
                $stmt = $pdo->prepare("UPDATE match_statistics SET runs_scored = runs_scored + ?, balls_faced = balls_faced + 1, fours = fours + (CASE WHEN ? = 4 THEN 1 ELSE 0 END), sixes = sixes + (CASE WHEN ? = 6 THEN 1 ELSE 0 END), strike_rate = ((runs_scored + ?) / (balls_faced + 1)) * 100 WHERE match_id = ? AND player_id = ? AND inning_number = ?");
                $stmt->execute([$runs_scored, $runs_scored, $runs_scored, $runs_scored, $match_id, $striker_id, $innings['inning_number']]);
            } else {
                // No Ball (runs count, balls don't)
                $stmt = $pdo->prepare("UPDATE match_statistics SET runs_scored = runs_scored + ? WHERE match_id = ? AND player_id = ? AND inning_number = ?");
                $stmt->execute([$runs_scored, $match_id, $striker_id, $innings['inning_number']]);
            }
        }

        // Ensure dismissed player has specific stats entry
        if ($is_wicket && $wicket_player_id) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO match_statistics (match_id, player_id, team_id, inning_number) VALUES (?, ?, ?, ?)");
            $stmt->execute([$match_id, $wicket_player_id, $innings['batting_team_id'], $innings['inning_number']]);
        }

        // Update Bowler Stats
        $stmt = $pdo->prepare("INSERT IGNORE INTO match_statistics (match_id, player_id, team_id, inning_number) VALUES (?, ?, ?, ?)");
        $stmt->execute([$match_id, $bowler_id, $innings['bowling_team_id'], $innings['inning_number']]);

        $stmt_b = $pdo->prepare("SELECT count(*) FROM ball_by_ball WHERE match_id = ? AND inning_number = ? AND bowler_id = ? AND extra_type IS NULL");
        $stmt_b->execute([$match_id, $innings['inning_number'], $bowler_id]);
        $b_balls = $stmt_b->fetchColumn();
        $b_overs = floor($b_balls / 6) . '.' . ($b_balls % 6);
        // Robust check for run out - bowler doesn't get credit
        $is_run_out = ($wicket_type && strtolower(trim($wicket_type)) === 'run out');
        $bowler_wicket = ($is_wicket && !$is_run_out) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE match_statistics SET runs_conceded = runs_conceded + ?, wickets_taken = wickets_taken + ?, overs_bowled = ? WHERE match_id = ? AND player_id = ? AND inning_number = ?");
        $stmt->execute([$total_delivery_runs, $bowler_wicket, $b_overs, $match_id, $bowler_id, $innings['inning_number']]);

        // Swap Logic
        $new_striker = $striker_id;
        $new_non_striker = $non_striker_id;
        if ($runs % 2 != 0) {
            $temp = $new_striker;
            $new_striker = $new_non_striker;
            $new_non_striker = $temp;
        }

        $new_bowler = $bowler_id;
        if ($is_legal_ball && ($valid_balls_new % 6 == 0)) {
            $temp = $new_striker;
            $new_striker = $new_non_striker;
            $new_non_striker = $temp;
            $new_bowler = null;
        }

        if ($is_wicket) {
            if ($wicket_player_id == $new_striker)
                $new_striker = null;
            else
                $new_non_striker = null;
        }

        $stmt = $pdo->prepare("UPDATE matches SET current_striker_id = ?, current_non_striker_id = ?, current_bowler_id = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_striker, $new_non_striker, $new_bowler, $match_id]);

        // Check for Auto-Completion
        $stmt = $pdo->prepare("SELECT total_runs, wickets, overs_bowled FROM innings WHERE id = ?");
        $stmt->execute([$innings['id']]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);

        $innings_ended = false;
        if ($check['wickets'] >= $wicket_limit || ($valid_balls_new >= $total_overs_limit * 6))
            $innings_ended = true;

        if ($innings['inning_number'] == 2) {
            $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 1");
            $stmt->execute([$match_id]);
            $first_runs = (int) $stmt->fetchColumn();
            if ($check['total_runs'] > $first_runs)
                $innings_ended = true;
        } elseif ($innings['inning_number'] == 4) {
            $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 3");
            $stmt->execute([$match_id]);
            $third_runs = (int) $stmt->fetchColumn();
            if ($check['total_runs'] > $third_runs)
                $innings_ended = true;
        }

        if ($innings_ended) {
            if ($innings['inning_number'] == 1 || $innings['inning_number'] == 3) {
                // End of first half of a match (Standard or Super Over)
                $stmt = $pdo->prepare("UPDATE matches SET current_striker_id = NULL, current_non_striker_id = NULL, current_bowler_id = NULL WHERE id = ?");
                $stmt->execute([$match_id]);

                // 1st Innings Completion Notification Removed as per request
            } elseif ($innings['inning_number'] == 2) {
                // Match Ended (Standard)
                $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 1");
                $stmt->execute([$match_id]);
                $score1 = (int) $stmt->fetchColumn();
                $score2 = (int) $check['total_runs'];

                $winner_id = null;
                $result = 'tie';
                if ($score2 > $score1) {
                    $winner_id = $innings['batting_team_id'];
                    $result = ($winner_id == $match['team1_id']) ? 'team1' : 'team2';
                } elseif ($score1 > $score2) {
                    $winner_id = $innings['bowling_team_id'];
                    $result = ($winner_id == $match['team1_id']) ? 'team1' : 'team2';
                }

                $stmt = $pdo->prepare("UPDATE matches SET winner_id = ?, result = ? WHERE id = ?");
                $stmt->execute([$winner_id, $result, $match_id]);
            } elseif ($innings['inning_number'] == 4) {
                // Match Ended (Super Over)
                $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 3");
                $stmt->execute([$match_id]);
                $runs3 = (int) $stmt->fetchColumn();
                $runs4 = (int) $check['total_runs'];

                $winner_id = null;
                $result = 'tie';

                if ($runs4 > $runs3) {
                    $winner_id = $innings['batting_team_id'];
                } elseif ($runs3 > $runs4) {
                    $winner_id = $innings['bowling_team_id'];
                } else {
                    // Tie in Super Over! Compare boundaries (Recommended)
                    $stmt = $pdo->prepare("SELECT SUM(fours + sixes) FROM match_statistics WHERE match_id = ? AND inning_number = 3");
                    $stmt->execute([$match_id]);
                    $bounds3 = (int) $stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT SUM(fours + sixes) FROM match_statistics WHERE match_id = ? AND inning_number = 4");
                    $stmt->execute([$match_id]);
                    $bounds4 = (int) $stmt->fetchColumn();

                    if ($bounds4 > $bounds3) {
                        $winner_id = $innings['batting_team_id'];
                    } elseif ($bounds3 > $bounds4) {
                        $winner_id = $innings['bowling_team_id'];
                    }
                }

                if ($winner_id) {
                    $result = ($winner_id == $match['team1_id']) ? 'team1' : 'team2';
                }

                $stmt = $pdo->prepare("UPDATE matches SET winner_id = ?, result = ? WHERE id = ?");
                $stmt->execute([$winner_id, $result, $match_id]);

                // Play stops
                $stmt = $pdo->prepare("UPDATE matches SET current_striker_id = NULL, current_non_striker_id = NULL, current_bowler_id = NULL WHERE id = ?");
                $stmt->execute([$match_id]);
            }
        }

        $pdo->commit();
        sendResponse(true, 'Score updated', ['innings_ended' => $innings_ended]);

    } elseif ($action === 'end_match') {
        // Manual End Match

        // Check if already completed
        $stmt = $pdo->prepare("SELECT status FROM matches WHERE id = ?");
        $stmt->execute([$match_id]);
        if ($stmt->fetchColumn() === 'completed') {
            sendResponse(true, 'Match already ended');
        }

        updatePlayerCareerStats($match_id, $pdo);

        $stmt = $pdo->prepare("UPDATE matches SET status = 'completed' WHERE id = ?");
        $stmt->execute([$match_id]);

        // 🔔 Type-3: Match Completed Notification
        try {
            $stmtRes = $pdo->prepare("
                SELECT m.*, t1.team_name as t1n, t2.team_name as t2n
                FROM matches m 
                JOIN teams t1 ON m.team1_id = t1.id 
                JOIN teams t2 ON m.team2_id = t2.id 
                WHERE m.id = ?
            ");
            $stmtRes->execute([$match_id]);
            $res = $stmtRes->fetch(PDO::FETCH_ASSOC);

            if ($res) {
                // Fetch final scores to determine margin from innings
                $stmtScore = $pdo->prepare("SELECT inning_number, total_runs, wickets, batting_team_id FROM innings WHERE match_id = ? ORDER BY inning_number ASC");
                $stmtScore->execute([$match_id]);
                $inningsData = $stmtScore->fetchAll(PDO::FETCH_ASSOC);

                $winner_name = "";
                $margin_text = "";

                if ($res['winner_id']) {
                    $winner_name = ($res['winner_id'] == $res['team1_id']) ? $res['t1n'] : $res['t2n'];

                    // Determine margin (Runs or Wickets) using the first two innings
                    $first_inning = $inningsData[0] ?? null;
                    $second_inning = $inningsData[1] ?? null;

                    if ($first_inning && $second_inning) {
                        $is_super_over = false;
                        foreach ($inningsData as $inn) {
                            if ($inn['inning_number'] == 4) {
                                $is_super_over = true;
                                break;
                            }
                        }

                        if ($is_super_over) {
                            $margin_text = "won by super over";
                        } else if ($res['winner_id'] == $first_inning['batting_team_id']) {
                            $diff = $first_inning['total_runs'] - $second_inning['total_runs'];
                            $margin_text = "won by $diff runs";
                        } else {
                            $wickets_left = 10 - $second_inning['wickets'];
                            $margin_text = "won by $wickets_left wickets";
                        }
                    }
                } else if ($res['result'] === 'tie') {
                    $winner_name = "Match Tied";
                }

                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $base_url = "$protocol://$host/CPT_LEAGUE/";

                // 🏆 Generate Match Completion Banner for Winning Team
                $match_completed_banner = null;
                if ($res['winner_id']) {
                    // Fetch Winning Team's Squad and Roles
                    $stmtSquad = $pdo->prepare("
                        SELECT u.name, u.profile_image, u.profile_image_url, u.playing_role, ms.is_captain, ms.is_vice_captain
                        FROM match_squads ms
                        JOIN users u ON ms.player_id = u.id
                        WHERE ms.match_id = ? AND ms.team_id = ? AND ms.playing_11 = 1
                    ");
                    $stmtSquad->execute([$match_id, $res['winner_id']]);
                    $winning_squad = $stmtSquad->fetchAll(PDO::FETCH_ASSOC);

                    // Sort players: C, VC, Batter, All rounder, Bowler
                    usort($winning_squad, function($a, $b) {
                        $getPriority = function($p) {
                            if ($p['is_captain']) return 0;
                            if ($p['is_vice_captain']) return 1;
                            $role = strtolower($p['playing_role']);
                            if (strpos($role, 'bat') !== false) return 2;
                            if (strpos($role, 'all') !== false) return 3;
                            if (strpos($role, 'bowl') !== false) return 4;
                            return 5;
                        };
                        return $getPriority($a) <=> $getPriority($b);
                    });

                    // Prepare Banner Data
                    $banner_players = [];
                    foreach ($winning_squad as $p) {
                        $p_img_url = $p['profile_image_url'];
                        if (!$p_img_url && $p['profile_image']) {
                            $p_img_url = $base_url . "uploads/users/" . $p['profile_image'];
                        }

                        $banner_players[] = [
                            'name' => $p['name'],
                            'image' => $p_img_url
                        ];
                    }

                    $match_completed_banner = generateMatchCompletedBannerHtml2Image([
                        'team_name' => $winner_name,
                        'subtitle' => 'Match Winners',
                        'players' => $banner_players
                    ]);
                }

                // Fetch Tournament Logo for large_icon
                $stmtTour = $pdo->prepare("SELECT tn.tournament_logo_public_id FROM tournaments tn JOIN matches m ON m.tournament_id = tn.id WHERE m.id = ?");
                $stmtTour->execute([$match_id]);
                $tour = $stmtTour->fetch(PDO::FETCH_ASSOC);
                $tournament_logo_url = ($tour && $tour['tournament_logo_public_id']) ? "https://res.cloudinary.com/" . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'dffnuolqw') . "/image/upload/" . $tour['tournament_logo_public_id'] : ($base_url . "assets/images/logo.jpg");

                $all_ids = array_unique(array_merge(
                    $pdo->query("SELECT onesignal_player_id FROM user_devices WHERE onesignal_player_id IS NOT NULL AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN),
                    getGuestPlayerIds($pdo)
                ));

                if (!empty($all_ids)) {
                    sendOneSignalNotification(
                        $all_ids,
                        "{$winner_name} Won the Match!",
                        "{$winner_name} " . ($margin_text ?: "won the match"),
                        [
                            'type' => 'match_completed', 
                            'match_id' => $match_id,
                            'big_picture' => $match_completed_banner ?: ($base_url . "assets/images/cricket-bg.jpg"),
                            'image' => $match_completed_banner ?: ($base_url . "assets/images/cricket-bg.jpg"),
                            'large_icon' => $tournament_logo_url,
                            'small_icon' => 'ic_stat_notify',
                            'android_sound' => 'notification_sound'
                        ],
                        $base_url . "view_match_summary.php?id=" . $match_id
                    );
                }
            }
        } catch (Throwable $e3) {
            error_log("Match Completion Notification Error: " . $e3->getMessage());
        }

        $pdo->commit();
        sendResponse(true, 'Match Ended');

    } elseif ($action === 'start_super_over') {
        // Super Over starts with Inning 3
        // 1. Swap teams: 2nd innings batting team bowls, 1st innings batting team bats
        // Actually, the request says: Batting Team = 2nd Innings Team, Bowling Team = 1st Innings Team
        // So they keep their roles from the 2nd innings? Or is it fresh?
        // "Batting Team = 2nd Innings Team, Bowling Team = 1st Innings Team"

        // Let's find the teams from the last inning
        $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number DESC LIMIT 1");
        $stmt->execute([$match_id]);
        $last_inning = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$last_inning)
            throw new Exception("No previous innings found");

        $batting_team_id = $last_inning['batting_team_id'];
        $bowling_team_id = $last_inning['bowling_team_id'];

        if (!$batting_team_id || !$bowling_team_id) {
            // Fallback to match teams if last_inning is corrupted
            $batting_team_id = $match['team2_id'];
            $bowling_team_id = $match['team1_id'];
        }

        // Insert Super Over Inning
        $stmt = $pdo->prepare("INSERT INTO innings (match_id, inning_number, batting_team_id, bowling_team_id, total_runs, wickets, overs_bowled) VALUES (?, 3, ?, ?, 0, 0, '0.0')");
        $stmt->execute([$match_id, $batting_team_id, $bowling_team_id]);

        // Reset match strikers/bowler
        $pdo->prepare("UPDATE matches SET current_striker_id = NULL, current_non_striker_id = NULL, current_bowler_id = NULL, overlay_id = overlay_id + 1, overlay_type = 'super_over_intro' WHERE id = ?")->execute([$match_id]);

        $pdo->commit();
        sendResponse(true, 'Super Over Started');

    } elseif ($action === 'start_super_over_2nd') {
        // Start Inning 4 (Super Over 2nd Innings)
        $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? AND inning_number = 3");
        $stmt->execute([$match_id]);
        $inn3 = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inn3)
            throw new Exception("Super Over 1st innings not found");

        // Swap teams from Inn 3
        $batting_team_id = $inn3['bowling_team_id'];
        $bowling_team_id = $inn3['batting_team_id'];

        $stmt = $pdo->prepare("INSERT INTO innings (match_id, inning_number, batting_team_id, bowling_team_id, total_runs, wickets, overs_bowled) VALUES (?, 4, ?, ?, 0, 0, '0.0')");
        $stmt->execute([$match_id, $batting_team_id, $bowling_team_id]);

        // Reset match strikers/bowler
        $pdo->prepare("UPDATE matches SET current_striker_id = NULL, current_non_striker_id = NULL, current_bowler_id = NULL, overlay_id = overlay_id + 1, overlay_type = 'super_over_intro' WHERE id = ?")->execute([$match_id]);

        $pdo->commit();
        sendResponse(true, 'Super Over 2nd Innings Started');

    } elseif ($action === 'start_second_innings') {
        // Create 2nd Innings
        $b_id = $innings ? $innings['bowling_team_id'] : $match['team2_id'];
        $w_id = $innings ? $innings['batting_team_id'] : $match['team1_id'];

        $stmt = $pdo->prepare("INSERT INTO innings (match_id, inning_number, batting_team_id, bowling_team_id, total_runs, wickets, overs_bowled) VALUES (?, 2, ?, ?, 0, 0, '0.0')");
        $stmt->execute([$match_id, $b_id, $w_id]);

        $pdo->prepare("UPDATE matches SET current_striker_id = NULL, current_non_striker_id = NULL, current_bowler_id = NULL WHERE id = ?")->execute([$match_id]);

        $pdo->commit();
        sendResponse(true, 'Second innings started');

    } elseif ($action === 'update_player') {
        // Simple update player logic
        $type = $_POST['type'];
        $pid = (int) $_POST['player_id'];
        $col = ($type == 'striker' ? 'current_striker_id' : ($type == 'non_striker' ? 'current_non_striker_id' : 'current_bowler_id'));
        $pdo->prepare("UPDATE matches SET $col = ? WHERE id = ?")->execute([$pid, $match_id]);

        $pdo->commit();
        sendResponse(true, 'Player updated');

    } elseif ($action === 'end_innings') {
        // Manual End Innings
        $pdo->prepare("UPDATE matches SET current_striker_id=NULL, current_non_striker_id=NULL, current_bowler_id=NULL WHERE id=?")->execute([$match_id]);
        $pdo->commit();
        sendResponse(true, 'Innings ended');

    } elseif ($action === 'swap_batters') {
        $s = $match['current_striker_id'];
        $ns = $match['current_non_striker_id'];
        $pdo->prepare("UPDATE matches SET current_striker_id = ?, current_non_striker_id = ? WHERE id = ?")->execute([$ns, $s, $match_id]);
        $pdo->commit();
        sendResponse(true, 'Batters swapped');

    } elseif ($action === 'stop_match') {
        $pdo->prepare("UPDATE matches SET status = 'upcoming' WHERE id = ?")->execute([$match_id]);

        $pdo->commit();
        sendResponse(true, 'Match stopped');

    } elseif ($action === 'trigger_overlay') {
        $type = $_POST['overlay_type'] ?? '';
        if (!$type)
            throw new Exception("Overlay type required");

        $stmt = $pdo->prepare("UPDATE matches SET overlay_id = overlay_id + 1, overlay_type = ? WHERE id = ?");
        $stmt->execute([$type, $match_id]);

        $pdo->commit();
        sendResponse(true, 'Overlay triggered successfully');

    } elseif ($action === 'pause_match') {
        $pdo->prepare("UPDATE matches SET is_paused = 1 WHERE id = ?")->execute([$match_id]);
        $pdo->commit();
        sendResponse(true, 'Match Paused');

    } elseif ($action === 'resume_match') {
        $pdo->prepare("UPDATE matches SET is_paused = 0 WHERE id = ?")->execute([$match_id]);
        $pdo->commit();
        sendResponse(true, 'Match Resumed');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    sendResponse(false, $e->getMessage());
}
