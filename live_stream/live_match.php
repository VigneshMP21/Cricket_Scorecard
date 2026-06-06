<?php
require_once __DIR__ . '/../includes/live_commentary.php';

$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$commentary_token = $match_id > 0 ? cpt_live_commentary_token($match_id) : '';
// Set Viewer Cookie if not exists
if (!isset($_COOKIE['cpt_viewer_id'])) {
    $viewer_id = bin2hex(random_bytes(16));
    setcookie('cpt_viewer_id', $viewer_id, time() + (86400 * 30), "/"); // 30 days
    $_COOKIE['cpt_viewer_id'] = $viewer_id; // Make available for immediate use if needed
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Match - CPT League</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="live_match.css?v=<?php echo time(); ?>">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/peerjs@1.5.4/dist/peerjs.min.js"></script>

</head>

<body>
    <div class="header" style="position:relative;">
        <a href="../NavBarList/matches.php" class="back-btn"><i class="fas fa-arrow-left me-1"></i> Back</a>
        <button id="shareBtn" class="share-btn" onclick="shareMatch()"><i class="fas fa-share-alt"></i></button>
        <div class="container">
            <div id="matchInfoTop" class="match-info-top">
                <!-- Populated by JS -->
            </div>
            <div class="match-banner" id="headerContent">
                <!-- Loading -->
                <h2 style="text-align:center; width:100%;">Loading Match...</h2>
            </div>

        </div>
    </div>

    <div class="container">
        <div class="tabs">
            <div class="tab active" onclick="switchTab('live')">Live Match</div>
            <div class="tab" onclick="switchTab('scorecard')">Scorecard</div>
            <div class="tab" onclick="switchTab('teams')">Teams</div>
            <div class="tab" onclick="switchTab('commentary')">Commentary</div>
        </div>

        <!-- Live Tab -->
        <div id="live" class="tab-content active">
            <!-- Score Summary -->

            <audio id="liveCommentaryAudio" autoplay playsinline></audio>


            <!-- Current Play Status -->
            <div class="row" style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                <!-- Current Batters -->
                <div style="flex:1; min-width:300px;" class="table-container">
                    <h4 id="liveBattingHeader"
                        style="padding:15px; border-bottom:1px solid #eee; color:var(--primary);"><i
                            class="fas fa-bat-ball"></i> Batting</h4>
                    <table id="liveBattingTable">
                        <thead>
                            <tr>
                                <th>Batter</th>
                                <th>R</th>
                                <th>B</th>
                                <th>4s</th>
                                <th>6s</th>
                                <th>SR</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <!-- Current Bowler -->
                <div style="flex:1; min-width:300px;" class="table-container">
                    <h4 id="liveBowlingHeader" style="padding:15px; border-bottom:1px solid #eee; color:var(--danger);">
                        <i class="fas fa-baseball-ball"></i> Bowling
                    </h4>
                    <table id="liveBowlingTable">
                        <thead>
                            <tr>
                                <th>Bowler</th>
                                <th>O</th>
                                <th>M</th>
                                <th>R</th>
                                <th>W</th>
                                <th>Econ</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div style="padding:15px;">
                        <small>Current Over:</small>
                        <div id="liveBubbles" style="display:flex; gap:5px; margin-top:5px; flex-wrap:wrap;"></div>
                    </div>
                </div>
            </div>

            <div id="liveScoreSummary" class="score-info-container">
                <!-- Populated by JS -->
            </div>

            <!-- Target Section -->
            <div id="targetSection" style="display:none; margin-top:10px; margin-bottom:15px;">
                <div class="match-target-bar"
                    style="background:#fff3e0; border-left:4px solid var(--accent); padding:10px 15px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; box-shadow:var(--shadow);">
                    <div style="font-weight:700; color:var(--text-primary);">TARGET: <span id="targetVal"
                            style="font-size:1.2rem; color:var(--accent);">0</span></div>
                    <div id="targetDesc" style="font-size:0.9rem; font-weight:600; color:var(--text-secondary);">Needs 0
                        runs in 0 balls</div>
                </div>
            </div>

            <div class="commentary-panel">
                <div class="commentary-header">
                    <span>Live Commentary</span>
                    <button class="commentary-mute-btn" id="muteVoiceCommentaryBtn" type="button"
                        onclick="liveCommentaryReceiver.toggleMute()" aria-pressed="false"
                        title="Mute live voice commentary">
                        <i class="fas fa-volume-up" id="muteVoiceCommentaryIcon"></i>
                        <span id="muteVoiceCommentaryText">Mute</span>
                    </button>
                </div>
                <div class="commentary-body" id="commentaryFeed">
                    <!-- Feed -->
                </div>
            </div>
        </div>

        <!-- Commentary Tab -->
        <div id="commentary" class="tab-content">
            <div class="full-commentary-container" id="fullCommentaryFeed">
                <!-- Populated by JS -->
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted mb-3"></i>
                    <p>Loading full commentary history...</p>
                </div>
            </div>
        </div>
        <div id="scorecard" class="tab-content">
            <div class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-2x text-muted mb-3"></i>
                <p>Loading Scorecard...</p>
            </div>
        </div>

        <!-- Teams Tab -->
        <div id="teams" class="tab-content">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
                <div class="table-container" id="team1Container">
                    <!-- Populated by JS -->
                    <h3 style="padding:15px;" id="team1Name">Team 1</h3>
                    <div id="team1List"></div>
                </div>
                <div class="table-container" id="team2Container">
                    <!-- Populated by JS -->
                    <h3 style="padding:15px;" id="team2Name">Team 2</h3>
                    <div id="team2List"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- Popup -->
    <div id="playerPopup" class="overlay">
        <div class="player-popup">
            <img id="ppImg" src="" class="popup-img">
            <h2 id="ppName" style="margin-bottom:5px;"></h2>
            <div id="ppRole" style="color:var(--text-secondary); margin-bottom:15px; font-weight:600;"></div>
            <div style="display:flex; justify-content:center; gap:10px;">
                <div style="background:#333; color:#fff; padding:5px 10px; border-radius:5px;">
                    <div style="font-size:0.7rem;">MATCHES</div>
                    <div style="font-size:1.1rem; font-weight:bold;" id="ppMat">0</div>
                </div>
                <div style="background:var(--primary); color:#fff; padding:5px 10px; border-radius:5px;">
                    <div style="font-size:0.7rem;">RUNS</div>
                    <div style="font-size:1.1rem; font-weight:bold;" id="ppRun">0</div>
                </div>
                <div style="background:var(--danger); color:#fff; padding:5px 10px; border-radius:5px;">
                    <div style="font-size:0.7rem;">WICKETS</div>
                    <div style="font-size:1.1rem; font-weight:bold;" id="ppWkt">0</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ball Run Popup -->
    <div id="runPopup" class="run-popup-overlay">
        <div class="run-ring" id="runRing"></div>
        <div id="runNumber" class="run-number"></div>
    </div>

    <!-- Super Over Intro Overlay -->
    <div id="superOverIntroOverlay" class="super-over-overlay">
        <div class="so-intro-content">
            <h1 class="so-title">SUPER OVER</h1>
            <div class="so-teams-row">
                <div class="so-team left">
                    <div class="so-logo-wrapper">
                        <img id="soTeam1Logo" src="" alt="">
                    </div>
                    <h3 id="soTeam1Name">TEAM 1</h3>
                </div>
                <div class="so-vs">VS</div>
                <div class="so-team right">
                    <div class="so-logo-wrapper">
                        <img id="soTeam2Logo" src="" alt="">
                    </div>
                    <h3 id="soTeam2Name">TEAM 2</h3>
                </div>
            </div>
            <div class="so-subtitle">Tie-Breaker Over</div>
        </div>
    </div>
    <!-- Innings Break Overlay -->
    <div id="inningsBreakOverlay" class="innings-break-overlay">
        <div class="ib-card">
            <div class="ib-close" onclick="hideInningsHeader()">&times;</div>
            <div class="ib-header">
                <div id="ibTeamLogo" class="team-logo mb-2"
                    style="margin: 0 auto; width: 100px; height: 100px; background: #fff; border-radius: 50%; padding: 2px; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <img src="" style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;"
                        id="ibLogoImg">
                </div>
                <h2 id="ibTeamName" style="margin: 10px 0 5px 0;">Team Name</h2>
                <div class="innings-label" style="font-size: 0.9rem; opacity: 0.8;">Innings Finished</div>
                <div id="ibFinalScore" style="font-size: 3.5rem; font-weight: 800; margin: 10px 0;">100/8</div>
                <div class="ib-innings-title">Innings Break</div>
                <div style="font-size: 1.1rem;">2nd Innings will start in a few minutes</div>
            </div>

            <div class="ib-body">
                <h4 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">Batting Summary
                </h4>
                <div class="table-container" style="box-shadow: none; border: 1px solid #eee;">
                    <table>
                        <thead>
                            <tr>
                                <th>Batter</th>
                                <th>R</th>
                                <th>B</th>
                                <th>4s</th>
                                <th>6s</th>
                                <th>SR</th>
                            </tr>
                        </thead>
                        <tbody id="ibBattingBody"></tbody>
                    </table>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                    <div style="background: #f5f5f5; padding: 15px; border-radius: 8px;">
                        <div style="font-size: 0.8rem; color: #666; text-transform: uppercase;">Innings Summary</div>
                        <div style="margin-top: 5px; font-weight: 600;">Overs: <span id="ibOvers">0.0</span></div>
                        <div style="font-weight: 600;">Extras: <span id="ibExtras">0</span></div>
                        <div style="font-size: 0.85rem; color: #888;" id="ibExtrasBreakdown">(b 0, lb 0, w 0, nb 0)
                        </div>
                    </div>
                    <div class="ib-target-box text-center d-flex flex-column align-items-center justify-content-center">
                        <div style="font-size: 1rem; text-transform: uppercase; margin-bottom: -5px;">Target</div>
                        <div id="ibTarget">101</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Target Overlay -->
    <div id="targetOverlay" class="target-overlay">
        <div class="target-card">
            <div class="target-team" id="targetBattingTeam">Batting Team</div>
            <div class="target-content">
                Needs <span class="target-number" id="targetRuns">0</span> runs
                in <span class="target-number" id="targetBalls">0</span> balls
            </div>
        </div>
    </div>

    <!-- Duck Out Popup -->
    <div id="duckOutPopup" class="duck-out-overlay">
        <img src="../assets/images/duck_out.png" class="duck-img">
        <div class="duck-text">
            <div id="duckPlayerName" class="duck-player-name">Player Name</div>
            <div id="duckScore" class="duck-score">0 (0)</div>
        </div>
    </div>

    <!-- Generic Wicket Popup -->
    <div id="wicketPopup" class="duck-out-overlay" style="background: rgba(187, 0, 0, 0.95);">
        <div class="duck-text">
            <div
                style="font-size: 5rem; font-weight: 900; color: #fff; text-transform: uppercase; margin-bottom: 20px; text-shadow: 0 0 20px rgba(255,255,255,0.4);">
                OUT!</div>
            <div id="wicketPlayerImgContainer" style="margin-bottom: 20px;">
                <img id="wicketPlayerImg" src="../assets/images/default-player.png"
                    style="width: 150px; height: 150px; border-radius: 50%; border: 5px solid #fff; object-fit: cover; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            </div>
            <div id="wicketPlayerName" class="duck-player-name"
                style="color: #fff; font-size: 2.5rem; margin-bottom: 10px;">Player Name</div>
            <div id="wicketStats" style="font-size: 1.8rem; font-weight: 700; color: #ffeb3b;">0 (0)</div>
        </div>
    </div>

    <!-- Pause Match Overlay -->
    <div id="pauseOverlay" class="pause-overlay">
        <div class="pause-content">
            <div class="pause-header">
                <i class="fas fa-pause-circle me-3"></i>TIME BREAK
            </div>
            <div class="pause-message">
                Match is temporarily paused by the official. <br>
                Please wait, it will resume shortly.
            </div>
        </div>
    </div>

    <!-- Milestone Overlay -->
    <div id="milestoneOverlay" class="milestone-overlay">
        <div class="milestone-card">
            <div class="milestone-left">
                <img id="msPlayerImg" src="../assets/images/default-player.png" class="milestone-player-img">
                <h3 id="msPlayerName" style="font-weight: 700; color: #333;">Player Name</h3>
            </div>
            <div class="milestone-right">
                <div id="msNumber" class="milestone-number">50</div>
                <div class="milestone-text">Runs reached!</div>
            </div>
        </div>
    </div>

    <!-- Score Comparison Overlay -->
    <div id="comparisonGraphOverlay" class="overlay">
        <div class="comparison-graph-card">
            <div class="comp-header-wrapper">
                <div class="comp-team-logo-circle left" style="padding: 2px !important;">
                    <img id="compBatTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'"
                        style="border-radius: 50%; padding: 2px; object-fit: contain; width: 100%; height: 100%;">
                </div>
                <h2 class="comparison-title">Score Comparison</h2>
                <div class="comp-team-logo-circle right" style="padding: 2px !important;">
                    <img id="compBowlTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'"
                        style="border-radius: 50%; padding: 2px; object-fit: contain; width: 100%; height: 100%;">
                </div>
            </div>
            <div class="graph-container">
                <canvas id="comparisonChart"></canvas>
            </div>
            <div id="comparisonStatsFooter" class="comparison-stats-footer">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Runs & Wickets Graph Overlay -->
    <div id="graphRunsWicketsOverlay" class="overlay">
        <div class="graph-runs-wickets-card">
            <div class="grw-header">
                <div class="grw-team-logo left" style="padding: 2px !important; overflow: hidden;">
                    <img id="grwBatTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'"
                        style="border-radius: 50%; object-fit: cover; width: 100%; height: 100%;">
                </div>
                <h2 class="grw-title" id="grwTitle">Run Rate & Wickets</h2>
                <div class="grw-team-logo right" style="padding: 2px !important; overflow: hidden;">
                    <img id="grwBowlTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'"
                        style="border-radius: 50%; object-fit: cover; width: 100%; height: 100%;">
                </div>
            </div>
            <div class="grw-body">
                <div class="grw-graph-container">
                    <canvas id="runsWicketsChart"></canvas>
                </div>
            </div>
            <div class="grw-footer">
                <div class="grw-footer-item">
                    <small>EXTRAS</small>
                    <div id="grwExtras" class="fw-bold">0</div>
                </div>
                <div class="grw-footer-item center">
                    <small>OVERS</small>
                    <div id="grwOvers" class="fw-bold">0.0</div>
                </div>
                <div class="grw-footer-item">
                    <small>SCORE</small>
                    <div id="grwScore" class="fw-bold">0/0</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hat-Trick Overlay -->
    <div id="hattrickOverlay" class="hattrick-overlay">
        <div class="hattrick-card">
            <div id="htContent">
                <!-- Generic Hat-trick info -->
                <img id="htPlayerImg" src="../assets/images/default-player.png"
                    style="width: 200px; height: 200px; border-radius: 50%; border: 5px solid #e74c3c;">
                <h2 id="htPlayerName" style="margin-top: 15px;">Player Name</h2>
                <div id="htTitle" class="hattrick-title">HAT-TRICK WICKET</div>
            </div>
        </div>
    </div>

    <!-- Player Card Overlay -->
    <div id="playerCardOverlay" class="player-card-overlay">
        <div class="pc-card">
            <div class="pc-close" onclick="closePlayerCard()">&times;</div>
            <div class="pc-header">
                <img id="pcImg" src="../assets/images/default-player.png" class="pc-img">
                <div id="pcName" class="pc-name">Player Name</div>
                <div id="pcTeam" class="pc-team">Team Name</div>
            </div>
            <div class="pc-stats-row">
                <div class="pc-stat-item">
                    <div class="pc-stat-label">Matches</div>
                    <div id="pcMat" class="pc-stat-value">0</div>
                </div>
                <div class="pc-stat-item">
                    <div class="pc-stat-label">Runs</div>
                    <div id="pcRun" class="pc-stat-value">0</div>
                </div>
                <div class="pc-stat-item">
                    <div class="pc-stat-label">Wickets</div>
                    <div id="pcWkt" class="pc-stat-value">0</div>
                </div>
            </div>
            <div class="pc-footer">
                <a id="pcViewStats" href="#" class="pc-btn">View Stats</a>
            </div>
        </div>
    </div>

    <!-- Partnership Display Overlay -->
    <div id="partnershipOverlay" class="partnership-overlay">
        <div class="partnership-card">
            <div class="p-title">PARTNERSHIP</div>
            <div class="p-main">
                <div class="p-player p-left">
                    <img id="p1Img" src="../assets/images/default-player.png" class="p-player-img"
                        onerror="this.src='../assets/images/default-player.png'">
                    <div class="p-info">
                        <div id="p1Name" class="p-name">Batter 1</div>
                        <div id="p1Runs" class="p-runs">0 (0)</div>
                    </div>
                </div>
                <div class="p-center">
                    <div id="pTotalRuns" class="p-total-runs">0</div>
                    <div id="pTotalBalls" class="p-total-balls">0 balls</div>
                </div>
                <div class="p-player p-right">
                    <img id="p2Img" src="../assets/images/default-player.png" class="p-player-img"
                        onerror="this.src='../assets/images/default-player.png'">
                    <div class="p-info">
                        <div id="p2Name" class="p-name">Batter 2</div>
                        <div id="p2Runs" class="p-runs">0 (0)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Batting Team Display Overlay -->
    <div id="battingTeamOverlay" class="batting-team-overlay">
        <div class="bt-overlay-card">
            <div class="bt-header">
                <div class="bt-team-logo-circle">
                    <img id="btTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'" alt="">
                </div>
                <h1 class="bt-team-name" id="btTeamName">BATTING TEAM</h1>
            </div>
            <div class="bt-container" id="btCardsContainer">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Batting Scorecard Overlay -->
    <div id="battingScorecardOverlay" class="batting-scorecard-overlay">
        <div class="bsc-card">
            <div class="bsc-header">
                <img id="bscTeamLogo" src="../assets/images/default-team.png" class="bsc-team-logo"
                    onerror="this.src='../assets/images/default-team.png'">
                <h2 id="bscTeamName" class="bsc-team-name">BATTING TEAM</h2>
            </div>
            <div class="bsc-table-wrapper">
                <table class="bsc-table">
                    <thead>
                        <tr>
                            <th class="bsc-name-col">Name</th>
                            <th>R</th>
                            <th>B</th>
                            <th>4s</th>
                            <th>6s</th>
                            <th>SR</th>
                        </tr>
                    </thead>
                    <tbody id="bscTableBody"></tbody>
                </table>
            </div>
            <div class="bsc-footer">
                <div class="bsc-footer-left">Extras: <span id="bscExtras">0</span></div>
                <div class="bsc-footer-center">Overs: <span id="bscOvers">0.0 / 20</span></div>
                <div class="bsc-footer-right">Score: <span id="bscScore">0/0</span></div>
            </div>
        </div>
    </div>

    <!-- Bowler Scorecard Overlay -->
    <div id="bowlerScorecardOverlay" class="bowler-scorecard-overlay">
        <div class="bwsc-card">
            <div class="bwsc-header">
                <img id="bwscTeamLogo" src="../assets/images/default-team.png" class="bwsc-team-logo"
                    onerror="this.src='../assets/images/default-team.png'">
                <h2 id="bwscTeamName" class="bwsc-team-name">BOWLING TEAM</h2>
            </div>
            <div class="bwsc-table-wrapper">
                <table class="bwsc-table">
                    <thead>
                        <tr>
                            <th class="bwsc-name-col">Name</th>
                            <th>O</th>
                            <th>R</th>
                            <th>W</th>
                            <th>ECON</th>
                        </tr>
                    </thead>
                    <tbody id="bwscTableBody"></tbody>
                </table>
            </div>
            <div class="bwsc-footer">
                <div class="bwsc-footer-left">Extras: <span id="bwscExtras">0</span></div>
                <div class="bwsc-footer-center">Overs: <span id="bwscOvers">0.0 / 20</span></div>
                <div class="bwsc-footer-right">Score: <span id="bwscScore">0/0</span></div>
            </div>
        </div>
    </div>

    <!-- Projected Score Display Overlay -->
    <div id="projectedScoreOverlay" class="projected-score-overlay">
        <div class="ps-header">
            <h1 class="ps-title" id="psTitle">PROJECTED SCORE</h1>
        </div>
        <div class="ps-container" id="psContainer">
            <!-- Populated by JS -->
        </div>
    </div>

    <!-- Bowling Team Display Overlay -->
    <div id="bowlingTeamOverlay" class="bowling-team-overlay">
        <div class="bwl-overlay-card">
            <div class="bwl-header">
                <div class="bwl-team-logo-circle">
                    <img id="bwlTeamLogo" src="../assets/images/default-team.png"
                        onerror="this.src='../assets/images/default-team.png'" alt="">
                </div>
                <h1 class="bwl-team-name" id="bwlTeamName">BOWLING TEAM</h1>
            </div>
            <div class="bwl-container" id="bwlCardsContainer">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Upcoming Matches Overlay -->
    <div id="upcomingMatchesOverlay" class="upcoming-matches-overlay">
        <div class="um-card">
            <div class="um-header">
                <h2>Upcoming Matches</h2>
            </div>
            <div id="umList" class="um-list">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Next Match Overlay -->
    <div id="nextMatchOverlay" class="upcoming-matches-overlay">
        <div class="um-card">
            <div class="um-header">
                <h2>Next Match</h2>
            </div>
            <div id="nmList" class="um-list">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    </div>

    <!-- Share Modal -->
    <div id="shareModal" class="overlay">
        <div class="share-modal-card">
            <h3>Share Match</h3>
            <div class="share-options">
                <button onclick="shareTo('whatsapp')" class="share-opt-btn share-whatsapp">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </button>
                <button onclick="shareTo('telegram')" class="share-opt-btn share-telegram">
                    <i class="fab fa-telegram-plane"></i> Telegram
                </button>
                <button onclick="shareTo('facebook')" class="share-opt-btn share-facebook">
                    <i class="fab fa-facebook-f"></i> Facebook
                </button>
                <button onclick="shareTo('instagram')" class="share-opt-btn share-instagram">
                    <i class="fab fa-instagram"></i> Instagram
                </button>
                <button onclick="shareTo('email')" class="share-opt-btn share-email">
                    <i class="fas fa-envelope"></i> Email
                </button>
                <button onclick="shareTo('copy')" class="share-opt-btn share-copy">
                    <i class="fas fa-link"></i> Copy Link
                </button>
            </div>
            <button class="share-close-btn" onclick="closeShareModal()">Close</button>
        </div>
    </div>

    <!-- Share Modal (Optional Fallback) -->
    <div id="shareToast"
        style="position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#333; color:#fff; padding:10px 20px; border-radius:5px; display:none; z-index:9999;">
        Link Copied!</div>

    <script>
        // Before Refresh Warning Dialog
        window.addEventListener("beforeunload", function (e) {
            e.preventDefault();
            e.returnValue = "";
        });

        // Optional custom message (limited support across modern browsers)
        window.onbeforeunload = function () {
            return "Click 'Cancel' button, Don't refresh the page. If refreshed, go back and click watch live.";
        };

        let breakShowingUntil = 0;
        function openShareModal() {
            document.getElementById('shareModal').classList.add('show');
        }

        function closeShareModal() {
            document.getElementById('shareModal').classList.remove('show');
        }

        function shareMatch() {
            const url = window.location.href;
            const title = document.title;
            const text = "Check out this live match on CPT League!";

            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: text,
                    url: url
                }).catch((error) => {
                    console.log('Error sharing:', error);
                    // If user cancelled, don't show modal
                    if (error.name !== 'AbortError') {
                        openShareModal();
                    }
                });
            } else {
                openShareModal();
            }
        }

        function shareTo(platform) {
            const url = window.location.href;
            const text = "Check out this live match on CPT League!\n" + url;
            const encodedText = encodeURIComponent(text);
            const encodedUrl = encodeURIComponent(url);

            let shareUrl = "";
            switch (platform) {
                case 'whatsapp':
                    shareUrl = `https://api.whatsapp.com/send?text=${encodedText}`;
                    break;
                case 'telegram':
                    shareUrl = `https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent("Check out this live match on CPT League!")}`;
                    break;
                case 'facebook':
                    shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
                    break;
                case 'instagram':
                    copyToClipboard(url, "Link Copied! Open Instagram to paste.");
                    setTimeout(() => {
                        window.open('https://www.instagram.com/', '_blank');
                        closeShareModal();
                    }, 2000);
                    break;
                case 'email':
                    shareUrl = `mailto:?subject=${encodeURIComponent(title)}&body=${encodedText}`;
                    break;
                case 'copy':
                    copyToClipboard(url);
                    closeShareModal();
                    return;
            }

            if (shareUrl) {
                window.open(shareUrl, '_blank');
                closeShareModal();
            }
        }

        function copyToClipboard(text, customMessage = "Link Copied!") {
            const tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);

            const toast = document.getElementById('shareToast');
            toast.innerText = customMessage;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 2000);
        }

        const MATCH_ID = <?= $match_id ?>;
        const COMMENTARY_API_URL = 'live_commentary_api.php';
        const COMMENTARY_TOKEN = <?= json_encode($commentary_token) ?>;
        let lastStrikerId = null;
        let lastNonStrikerId = null;
        let lastBowlerId = null;
        let lastOverNum = -1;
        let firstLoad = true;
        let lastInningNum = -1;
        let lastSquadsStr = '';

        let lastOverlayId = 0;

        class LiveCommentaryReceiver {
            constructor() {
                this.peer = null;
                this.peerId = null;
                this.enabled = false;
                this.muted = false;
                this.activeCall = null;
                this.statusTimer = null;
                this.heartbeatTimer = null;
                this.recoveryTimer = null;
                this.playRetryTimer = null;
                this.disconnectNoticeTimer = null;
                this.isRecovering = false;
                this.suppressPeerEvents = false;
                this.audioUnlockEventsBound = false;
                this.roomId = null;
                this.audioEl = null;
                this.audioContext = null;
                this.gainNode = null;
                this.streamSource = null;
                this.currentStream = null;

                window.addEventListener('online', () => {
                    this.log('Network online event received.');
                    if (this.enabled) this.recoverPeer('network-online');
                });
                window.addEventListener('offline', () => {
                    this.warn('Network offline event received.');
                    this.setStatus('Commentary disconnected. Waiting for network...', 'error');
                });
                window.addEventListener('pagehide', () => this.leave(true));
                window.addEventListener('beforeunload', () => this.leave(true));
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden && this.enabled) {
                        this.unlockAudioOutput().catch(err => this.warn('Audio resume after visibility change failed.', err));
                    }
                });
            }

            log(...args) {
                console.log('[LiveCommentary][viewer]', ...args);
            }

            warn(...args) {
                console.warn('[LiveCommentary][viewer]', ...args);
            }

            error(...args) {
                console.error('[LiveCommentary][viewer]', ...args);
            }

            initElements() {
                if (!this.audioEl) {
                    this.audioEl = document.getElementById('liveCommentaryAudio');
                    if (this.audioEl) {
                        this.audioEl.autoplay = true;
                        this.audioEl.playsInline = true;
                        this.audioEl.preload = 'none';
                        this.audioEl.addEventListener('playing', () => this.log('HTML audio element playing.'));
                        this.audioEl.addEventListener('pause', () => this.log('HTML audio element paused.'));
                        this.audioEl.addEventListener('error', () => this.warn('HTML audio element error.', this.audioEl.error));
                    }
                }
            }

            isSecureAllowed() {
                return window.isSecureContext
                    || location.protocol === 'https:'
                    || ['localhost', '127.0.0.1'].includes(location.hostname);
            }

            buildPeerId() {
                const bytes = new Uint8Array(8);
                if (window.crypto && window.crypto.getRandomValues) {
                    window.crypto.getRandomValues(bytes);
                } else {
                    for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
                }
                const random = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
                return `cpt-viewer-${MATCH_ID}-${random}`;
            }

            peerOptions() {
                return {
                    debug: 2,
                    config: {
                        iceServers: [
                            { urls: 'stun:stun.l.google.com:19302' },
                            { urls: 'stun:stun1.l.google.com:19302' },
                            { urls: 'stun:stun2.l.google.com:19302' }
                        ],
                        iceCandidatePoolSize: 2
                    }
                };
            }

            async enable() {
                this.initElements();

                if (this.enabled) {
                    this.log('Receiver already enabled; retrying playback unlock.');
                    await this.unlockAudioOutput();
                    await this.playAudioElementWithRetry(1);
                    return;
                }

                if (!this.isSecureAllowed()) {
                    this.setStatus('Open this page with HTTPS to use live commentary.', 'error');
                    return;
                }
                if (typeof Peer === 'undefined') {
                    this.setStatus('PeerJS failed to load. Check network.', 'error');
                    return;
                }

                this.enabled = true;
                this.bindAudioUnlockEvents();
                this.setControls();
                this.setStatus('Connecting commentary audio...', 'connecting');

                try {
                    await this.unlockAudioOutput();
                    this.setStatus('Checking live commentary room...', 'connecting');
                    await this.refreshStatus();
                    this.setStatus('Opening commentary receiver...', 'connecting');
                    await this.createPeer();
                    this.setStatus('Joining commentary room...', 'connecting');
                    await this.join('join');

                    this.statusTimer = setInterval(() => {
                        this.refreshStatus().catch(err => this.warn('Status refresh failed.', err));
                    }, 5000);
                    this.heartbeatTimer = setInterval(() => {
                        this.join('heartbeat').catch(err => {
                            this.warn('Heartbeat failed.', err);
                            if (!this.peer || this.peer.destroyed || !this.peer.open) {
                                this.recoverPeer('heartbeat-peer-closed');
                            } else if (!this.currentStream) {
                                this.setStatus('Commentary sync retrying...', 'connecting');
                            }
                        });
                    }, 12000);

                    if (this.roomId) {
                        this.setStatus('Waiting for commentator stream...', 'connecting');
                    } else {
                        this.setStatus('Waiting for commentator...', 'idle');
                    }
                } catch (err) {
                    this.error('Live commentary enable failed.', err);
                    this.setStatus(err.message || 'Could not enable commentary.', 'error');
                    this.enabled = false;
                    this.setControls();
                    this.cleanupPeer();
                }
            }

            async unlockAudioOutput() {
                this.initElements();
                const AudioContextCtor = window.AudioContext || window.webkitAudioContext;

                if (AudioContextCtor) {
                    if (!this.audioContext) {
                        try {
                            this.audioContext = new AudioContextCtor({ latencyHint: 'interactive' });
                        } catch (err) {
                            this.warn('AudioContext latencyHint option failed; retrying basic constructor.', err);
                            this.audioContext = new AudioContextCtor();
                        }
                        this.gainNode = this.audioContext.createGain();
                        this.gainNode.gain.value = this.muted ? 0 : 1;
                        this.gainNode.connect(this.audioContext.destination);
                        this.log('AudioContext created for autoplay-safe playback.');
                    }

                    if (this.audioContext.state !== 'running') {
                        try {
                            await this.withTimeout(this.audioContext.resume(), 1200, 'AudioContext resume timed out.');
                            this.log('AudioContext resumed from user gesture.', this.audioContext.state);
                        } catch (err) {
                            this.warn('AudioContext resume did not complete during enable; will retry when stream arrives.', err);
                        }
                    }

                    try {
                        const silent = this.audioContext.createBufferSource();
                        silent.buffer = this.audioContext.createBuffer(1, 1, 22050);
                        silent.connect(this.gainNode);
                        silent.start(0);
                    } catch (err) {
                        this.warn('Silent unlock buffer failed.', err);
                    }
                }

                if (this.audioEl) {
                    this.audioEl.muted = !!this.streamSource || this.muted;
                    if (this.audioEl.srcObject || this.audioEl.currentSrc || this.audioEl.src) {
                        try {
                            await this.withTimeout(this.audioEl.play(), 1200, 'HTML audio unlock timed out.');
                            this.log('HTML audio element play unlocked.');
                        } catch (err) {
                            this.log('Initial audio play was blocked or empty; will retry when stream arrives.', err.name || err);
                        }
                    } else {
                        this.log('HTML audio unlock skipped until commentary stream arrives.');
                    }
                }
            }

            bindAudioUnlockEvents() {
                if (this.audioUnlockEventsBound) return;
                this.audioUnlockEventsBound = true;

                const unlock = () => {
                    if (!this.enabled) return;
                    this.unlockAudioOutput()
                        .then(() => this.playAudioElementWithRetry(1))
                        .catch(err => this.warn('Audio unlock from page interaction failed.', err));
                };

                document.addEventListener('pointerdown', unlock, { passive: true });
                document.addEventListener('keydown', unlock);
            }

            withTimeout(promise, timeoutMs, message) {
                return Promise.race([
                    promise,
                    new Promise((_, reject) => setTimeout(() => reject(new Error(message)), timeoutMs))
                ]);
            }

            async createPeer() {
                return new Promise((resolve, reject) => {
                    this.cleanupPeer(false);
                    this.peerId = this.buildPeerId();
                    this.log('Creating viewer peer.', this.peerId);
                    this.peer = new Peer(this.peerId, this.peerOptions());

                    let settled = false;
                    const timeout = setTimeout(() => {
                        if (settled) return;
                        settled = true;
                        reject(new Error('PeerJS viewer connection timed out.'));
                    }, 15000);

                    this.peer.on('open', id => {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timeout);
                        this.peerId = id;
                        this.log('Viewer peer open.', id);
                        resolve(id);
                    });

                    this.peer.on('call', call => this.answerCall(call));

                    this.peer.on('disconnected', () => {
                        this.warn('Viewer peer disconnected from PeerJS signaling.');
                        if (!this.enabled || this.suppressPeerEvents || this.isRecovering) return;
                        this.setStatus('Reconnecting commentary...', 'connecting');
                        try {
                            if (this.peer && !this.peer.destroyed) this.peer.reconnect();
                        } catch (err) {
                            this.warn('PeerJS reconnect threw.', err);
                        }
                        this.recoverPeer('peer-disconnected');
                    });

                    this.peer.on('close', () => {
                        this.warn('Viewer peer closed.');
                        if (this.enabled && !this.suppressPeerEvents && !this.isRecovering) this.recoverPeer('peer-closed');
                    });

                    this.peer.on('error', err => {
                        this.error('Viewer PeerJS error.', err);
                        if (!settled) {
                            settled = true;
                            clearTimeout(timeout);
                            reject(new Error(this.describePeerError(err)));
                            return;
                        }
                        if (this.enabled && !this.suppressPeerEvents && !this.isRecovering) {
                            this.setStatus(this.describePeerError(err), 'error');
                            this.recoverPeer('peer-error');
                        }
                    });
                });
            }

            answerCall(call) {
                this.log('Incoming commentary call.', call && call.metadata);
                if (!this.enabled) {
                    this.warn('Rejected call because commentary is not enabled.');
                    try { call.close(); } catch (e) { }
                    return;
                }

                const meta = call.metadata || {};
                if (meta.matchId && parseInt(meta.matchId) !== parseInt(MATCH_ID)) {
                    this.warn('Rejected call for different match.', meta);
                    try { call.close(); } catch (e) { }
                    return;
                }
                if (this.roomId && meta.roomId && meta.roomId !== this.roomId) {
                    this.warn('Rejected call for stale room.', { expected: this.roomId, received: meta.roomId });
                    try { call.close(); } catch (e) { }
                    return;
                }
                if (!this.roomId && meta.roomId) {
                    this.roomId = meta.roomId;
                    this.log('Room id synced from incoming call metadata.', this.roomId);
                }

                if (this.activeCall && this.currentStream && this.isStreamLive(this.currentStream)) {
                    this.log('Closing duplicate commentary call while current stream is live.');
                    try { call.close(); } catch (e) { }
                    return;
                }

                if (this.activeCall) {
                    this.handleCallClosed(this.activeCall, 'replaced by new call');
                }

                this.activeCall = call;
                this.setStatus('Receiving live commentary stream...', 'connecting');

                call.on('stream', stream => this.attachStream(call, stream));
                call.on('close', () => this.handleCallClosed(call, 'call closed'));
                call.on('error', err => {
                    this.warn('Live commentary call error.', err);
                    this.handleCallClosed(call, 'call error');
                });

                try {
                    const emptyAnswerStream = typeof MediaStream !== 'undefined' ? new MediaStream() : undefined;
                    call.answer(emptyAnswerStream);
                    this.log('Incoming call answered.');
                    setTimeout(() => this.attachPeerConnectionDebug(call), 0);
                } catch (err) {
                    this.error('Failed to answer incoming commentary call.', err);
                    this.handleCallClosed(call, 'answer failed');
                }
            }

            async attachStream(call, stream) {
                if (call && this.activeCall !== call) {
                    this.warn('Ignoring stream from stale commentary call.');
                    try { call.close(); } catch (e) { }
                    return;
                }

                this.initElements();
                this.log('Remote stream received.', {
                    streamId: stream && stream.id,
                    audioTracks: stream && stream.getAudioTracks ? stream.getAudioTracks().length : 0
                });

                if (!stream || typeof stream.getAudioTracks !== 'function') {
                    this.setStatus('Invalid commentary stream received.', 'error');
                    return;
                }

                const audioTracks = stream.getAudioTracks();
                if (!audioTracks.length) {
                    this.setStatus('Commentary stream has no audio track.', 'error');
                    return;
                }

                audioTracks.forEach(track => {
                    this.log('Remote audio track ready.', {
                        label: track.label,
                        enabled: track.enabled,
                        muted: track.muted,
                        readyState: track.readyState,
                        settings: typeof track.getSettings === 'function' ? track.getSettings() : {}
                    });
                    track.onmute = () => {
                        this.warn('Remote commentary track muted.');
                        this.setStatus('Commentary audio muted by network/device.', 'error');
                    };
                    track.onunmute = () => {
                        this.log('Remote commentary track unmuted.');
                        this.setStatus('Live commentary connected', 'live');
                    };
                    track.onended = () => {
                        if (call && this.activeCall !== call) return;
                        this.warn('Remote commentary track ended.');
                        this.handleCallClosed(call, 'remote track ended');
                    };
                });

                this.currentStream = stream;
                this.cleanupStreamOutput();

                if (this.audioContext && this.gainNode) {
                    try {
                        if (this.audioContext.state !== 'running') {
                            await this.withTimeout(this.audioContext.resume(), 1200, 'AudioContext stream resume timed out.');
                        }
                        this.streamSource = this.audioContext.createMediaStreamSource(stream);
                        this.streamSource.connect(this.gainNode);
                        this.setOutputMuted(this.muted);
                        if (this.audioEl) {
                            this.audioEl.srcObject = stream;
                            this.audioEl.muted = true;
                            this.audioEl.play().catch(err => this.log('Muted audio element play fallback ignored.', err.name || err));
                        }
                        this.setStatus('Live commentary connected', 'live');
                        this.log('Remote stream connected through Web Audio output.');
                        return;
                    } catch (err) {
                        this.warn('Web Audio stream output failed; falling back to HTML audio.', err);
                        this.cleanupStreamOutput();
                    }
                }

                this.audioEl.srcObject = stream;
                this.audioEl.muted = this.muted;
                await this.playAudioElementWithRetry(5);
            }

            async playAudioElementWithRetry(maxAttempts = 5) {
                this.initElements();
                if (!this.audioEl || !this.audioEl.srcObject) return;

                for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                    try {
                        await this.withTimeout(this.audioEl.play(), 2500, 'Audio playback timed out.');
                        this.setStatus('Live commentary connected', 'live');
                        this.log('Remote stream playing through HTML audio.', { attempt });
                        return;
                    } catch (err) {
                        this.warn('Audio playback attempt failed.', { attempt, name: err.name, message: err.message });
                        if (err.name === 'NotAllowedError') {
                            this.muted = true;
                            this.setOutputMuted(true);
                            this.setControls();
                            this.setStatus('Use Unmute once to allow audio.', 'error');
                            return;
                        }
                        await new Promise(resolve => setTimeout(resolve, 600 * attempt));
                    }
                }

                this.setStatus('Audio playback failed. Use the mute button once.', 'error');
            }

            attachPeerConnectionDebug(call) {
                const pc = call && call.peerConnection;
                if (!pc) {
                    this.warn('Incoming MediaConnection has no RTCPeerConnection yet.');
                    return;
                }

                const updateState = () => {
                    if (this.activeCall !== call) return;

                    const iceState = pc.iceConnectionState || 'unknown';
                    const connectionState = pc.connectionState || 'unknown';
                    this.log('Viewer WebRTC state.', { iceState, connectionState });

                    if (iceState === 'connected' || iceState === 'completed' || connectionState === 'connected') {
                        this.clearDisconnectNoticeTimer();
                        this.setStatus('Live commentary connected', 'live');
                    }
                    if (iceState === 'failed' || iceState === 'closed' || connectionState === 'failed' || connectionState === 'closed') {
                        this.handleCallClosed(call, `WebRTC ${connectionState}/${iceState}`);
                    }
                    if (iceState === 'disconnected' || connectionState === 'disconnected') {
                        this.scheduleDisconnectNotice(call, pc);
                    }
                };

                if (typeof pc.addEventListener === 'function') {
                    pc.addEventListener('iceconnectionstatechange', updateState);
                    pc.addEventListener('connectionstatechange', updateState);
                    pc.addEventListener('icecandidateerror', event => {
                        this.warn('ICE candidate error.', {
                            errorCode: event.errorCode,
                            errorText: event.errorText,
                            url: event.url
                        });
                    });
                } else {
                    pc.oniceconnectionstatechange = updateState;
                    pc.onconnectionstatechange = updateState;
                }
                updateState();
            }

            scheduleDisconnectNotice(call, pc) {
                if (this.disconnectNoticeTimer || this.activeCall !== call) return;

                this.disconnectNoticeTimer = setTimeout(() => {
                    this.disconnectNoticeTimer = null;
                    if (!this.enabled || this.activeCall !== call) return;

                    const iceState = pc.iceConnectionState || 'unknown';
                    const connectionState = pc.connectionState || 'unknown';
                    if (iceState === 'connected' || iceState === 'completed' || connectionState === 'connected') {
                        this.setStatus('Live commentary connected', 'live');
                        return;
                    }

                    if (this.currentStream && this.isStreamLive(this.currentStream)) {
                        this.warn('WebRTC state is disconnected, but audio track is still live.', { iceState, connectionState });
                        return;
                    }

                    this.setStatus('Commentary connection retrying...', 'connecting');
                }, 5000);
            }

            clearDisconnectNoticeTimer() {
                if (this.disconnectNoticeTimer) {
                    clearTimeout(this.disconnectNoticeTimer);
                    this.disconnectNoticeTimer = null;
                }
            }

            isStreamLive(stream) {
                return !!(
                    stream &&
                    typeof stream.getAudioTracks === 'function' &&
                    stream.getAudioTracks().some(track => track.readyState === 'live')
                );
            }

            handleCallClosed(callOrReason = null, reason = 'call closed') {
                let call = null;
                if (callOrReason && typeof callOrReason === 'object' && typeof callOrReason.close === 'function') {
                    call = callOrReason;
                } else if (typeof callOrReason === 'string') {
                    reason = callOrReason;
                }

                if (call && this.activeCall !== call) {
                    this.warn('Ignoring close from stale commentary call.', reason);
                    try { call.close(); } catch (e) { }
                    return;
                }

                this.warn('Commentary stream closed.', reason);
                this.clearDisconnectNoticeTimer();
                if (this.activeCall) {
                    const call = this.activeCall;
                    this.activeCall = null;
                    try { call.close(); } catch (e) { }
                }
                this.cleanupStreamOutput();
                this.currentStream = null;
                if (this.audioEl) this.audioEl.srcObject = null;
                if (this.enabled) {
                    this.setStatus(this.roomId ? 'Waiting for commentator stream...' : 'Waiting for commentator...', this.roomId ? 'connecting' : 'idle');
                }
            }

            async refreshStatus() {
                try {
                    const data = await this.getAction('status');
                    if (!data.success) throw new Error(data.message || 'Commentary status failed.');

                    const previousRoomId = this.roomId;
                    this.roomId = data.active ? data.room_id : null;
                    this.log('Status API response.', {
                        active: data.active,
                        roomId: this.roomId,
                        viewerCount: data.viewer_count,
                        revision: data.revision
                    });

                    if (previousRoomId && this.roomId && previousRoomId !== this.roomId) {
                        this.warn('Room id changed; closing stale stream.', { previousRoomId, roomId: this.roomId });
                        this.handleCallClosed('room changed');
                    }

                    if (!this.enabled) {
                        this.setStatus(data.active ? 'Live commentary available' : 'No live commentary right now', data.active ? 'available' : 'idle');
                        return data;
                    }

                    if (!data.active) {
                        this.handleStoppedState();
                    } else if (!this.activeCall) {
                        this.setStatus('Waiting for commentator stream...', 'connecting');
                    }

                    return data;
                } catch (err) {
                    this.warn('Live commentary status error.', err);
                    if (this.enabled) this.setStatus('Commentary status retrying...', 'error');
                    throw err;
                }
            }

            handleStoppedState() {
                if (this.activeCall || this.currentStream) {
                    this.handleCallClosed('commentary stopped by host');
                }
                this.setStatus(this.enabled ? 'Waiting for commentator...' : 'No live commentary right now', 'idle');
            }

            async join(action = 'join') {
                if (!this.peerId || !this.peer || this.peer.destroyed || !this.peer.open) {
                    throw new Error('Viewer PeerJS connection is not open.');
                }

                const data = await this.postAction(action, { peer_id: this.peerId });
                if (!data.success) throw new Error(data.message || 'Unable to join commentary room.');
                this.roomId = data.active ? data.room_id : null;
                this.log(`${action} API success.`, {
                    peerId: this.peerId,
                    roomId: this.roomId,
                    active: data.active,
                    viewerCount: data.viewer_count
                });
                return data;
            }

            async recoverPeer(reason = 'unknown') {
                if (this.recoveryTimer || this.isRecovering || !this.enabled) return;

                this.warn('Reconnect triggered.', reason);
                this.setStatus('Reconnecting commentary...', 'connecting');
                this.recoveryTimer = setTimeout(async () => {
                    this.recoveryTimer = null;
                    const oldPeerId = this.peerId;
                    this.isRecovering = true;
                    let shouldRetry = false;
                    this.cleanupPeer(false);
                    if (oldPeerId) this.sendLeave(oldPeerId);

                    try {
                        await this.createPeer();
                        await this.join('join');
                        this.setStatus('Reconnected. Waiting for commentator stream...', 'connecting');
                    } catch (err) {
                        this.error('Commentary receiver recovery failed.', err);
                        shouldRetry = this.enabled;
                    } finally {
                        this.isRecovering = false;
                        if (shouldRetry) this.recoverPeer('recovery-failed');
                    }
                }, 2500);
            }

            async toggleMute() {
                this.muted = !this.muted;
                this.setOutputMuted(this.muted);
                this.setControls();
                this.log('Mute toggled.', this.muted);

                try {
                    await this.unlockAudioOutput();
                    if (!this.muted) await this.playAudioElementWithRetry(1);
                } catch (err) {
                    this.warn('Audio resume after mute toggle failed.', err);
                }
            }

            setOutputMuted(isMuted) {
                if (this.gainNode) {
                    this.gainNode.gain.value = isMuted ? 0 : 1;
                }
                if (this.audioEl) {
                    this.audioEl.muted = this.streamSource ? true : isMuted;
                }
            }

            cleanupStreamOutput() {
                this.clearDisconnectNoticeTimer();
                if (this.streamSource) {
                    try { this.streamSource.disconnect(); } catch (e) { }
                    this.streamSource = null;
                }
                if (this.playRetryTimer) {
                    clearTimeout(this.playRetryTimer);
                    this.playRetryTimer = null;
                }
            }

            cleanupPeer(clearPeerId = true) {
                this.suppressPeerEvents = true;
                if (this.activeCall) {
                    const call = this.activeCall;
                    this.activeCall = null;
                    try { call.close(); } catch (e) { }
                }
                this.cleanupStreamOutput();
                this.currentStream = null;
                if (this.peer && !this.peer.destroyed) {
                    try { this.peer.destroy(); } catch (err) { this.warn('Failed to destroy viewer peer.', err); }
                }
                this.peer = null;
                if (clearPeerId) this.peerId = null;
                if (this.audioEl) this.audioEl.srcObject = null;
                setTimeout(() => {
                    this.suppressPeerEvents = false;
                }, 1000);
            }

            leave(useBeacon = false) {
                if (this.statusTimer) clearInterval(this.statusTimer);
                if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
                if (this.recoveryTimer) clearTimeout(this.recoveryTimer);
                this.statusTimer = null;
                this.heartbeatTimer = null;
                this.recoveryTimer = null;

                const peerId = this.peerId;
                this.enabled = false;
                this.cleanupPeer();
                this.setControls();
                this.setStatus('Commentary disconnected', 'idle');

                if (!peerId) return;
                this.sendLeave(peerId, useBeacon);
            }

            sendLeave(peerId, useBeacon = false) {
                const body = new URLSearchParams({
                    action: 'leave',
                    match_id: MATCH_ID,
                    token: COMMENTARY_TOKEN,
                    peer_id: peerId
                });

                if (useBeacon && navigator.sendBeacon) {
                    navigator.sendBeacon(COMMENTARY_API_URL, new Blob([body.toString()], {
                        type: 'application/x-www-form-urlencoded'
                    }));
                    this.log('Leave sent with sendBeacon.', peerId);
                    return;
                }

                fetch(COMMENTARY_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body
                }).catch(err => this.warn('Leave API failed.', err));
            }

            async getAction(action, extra = {}) {
                const params = new URLSearchParams({
                    action,
                    match_id: MATCH_ID,
                    token: COMMENTARY_TOKEN,
                    ...extra
                });
                const response = await this.fetchWithTimeout(`${COMMENTARY_API_URL}?${params.toString()}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                }, 8000);
                return this.parseApiResponse(response);
            }

            async postAction(action, extra = {}) {
                const body = new URLSearchParams({
                    action,
                    match_id: MATCH_ID,
                    token: COMMENTARY_TOKEN,
                    ...extra
                });
                const response = await this.fetchWithTimeout(COMMENTARY_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body
                }, 8000);
                return this.parseApiResponse(response);
            }

            async fetchWithTimeout(url, options = {}, timeoutMs = 8000) {
                if (typeof AbortController === 'undefined') {
                    return fetch(url, options);
                }

                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), timeoutMs);
                try {
                    return await fetch(url, { ...options, signal: controller.signal });
                } catch (err) {
                    if (err && err.name === 'AbortError') {
                        throw new Error('Commentary API request timed out.');
                    }
                    throw err;
                } finally {
                    clearTimeout(timeout);
                }
            }

            async parseApiResponse(response) {
                const text = await response.text();
                let data = null;
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (err) {
                    throw new Error(`Commentary API returned invalid JSON: ${text.slice(0, 160)}`);
                }
                if (!response.ok) {
                    throw new Error(data.message || `Commentary API HTTP ${response.status}`);
                }
                return data;
            }

            describePeerError(err) {
                const type = err && err.type ? err.type : 'peer-error';
                const message = err && err.message ? err.message : 'PeerJS connection error.';
                return `PeerJS ${type}: ${message}`;
            }

            setStatus(text, state = 'idle') {
                this.log('Status:', state, text);
            }

            setControls() {
                const muteBtn = document.getElementById('muteVoiceCommentaryBtn');
                const muteIcon = document.getElementById('muteVoiceCommentaryIcon');
                const muteText = document.getElementById('muteVoiceCommentaryText');

                if (muteBtn) {
                    muteBtn.disabled = false;
                    muteBtn.setAttribute('aria-pressed', this.muted ? 'true' : 'false');
                    muteBtn.title = this.muted ? 'Unmute live voice commentary' : 'Mute live voice commentary';
                }
                if (muteIcon) {
                    muteIcon.className = this.muted ? 'fas fa-volume-mute' : 'fas fa-volume-up';
                }
                if (muteText) muteText.innerText = this.muted ? 'Unmute' : 'Mute';
            }
        }

        const liveCommentaryReceiver = new LiveCommentaryReceiver();
        window.liveCommentaryReceiver = liveCommentaryReceiver;
        document.addEventListener('DOMContentLoaded', () => {
            liveCommentaryReceiver.enable().catch(err => {
                liveCommentaryReceiver.warn('Automatic commentary connection failed.', err);
            });
        });

        // Overlay Priority Queue
        class OverlayManager {
            constructor() {
                this.queue = [];
                this.isShowing = false;
                this.shownBallIds = new Set();
            }

            add(type, ballId, priority, displayFn) {
                // Prevent duplicate overlays for same ball+type
                const key = `${type}_${ballId}`;
                if (this.shownBallIds.has(key)) return;

                this.queue.push({ type, ballId, priority, displayFn });
                this.queue.sort((a, b) => a.priority - b.priority); // Lower number = Higher priority
                this.process();
            }

            process() {
                if (this.isShowing || this.queue.length === 0) return;

                this.isShowing = true;
                const item = this.queue.shift();
                const key = `${item.type}_${item.ballId}`;
                this.shownBallIds.add(key);

                // Execute display function
                item.displayFn(() => {
                    this.isShowing = false;
                    setTimeout(() => this.process(), 500); // Small gap between overlays
                });
            }

            removeByBallId(ballId) {
                // For undo support
                this.queue = this.queue.filter(item => item.ballId != ballId);
                // We don't remove from shownBallIds because you probably don't want to re-show it if it's undone?
                // Actually if it's undone and re-scored, it will have a NEW ball ID anyway.
            }
        }

        const overlayManager = new OverlayManager();
        let introducedPlayers = new Set();
        let summarizedOvers = new Set();
        let lastBallId = 0;
        let matchStartPlayed = false;
        let lastMatchStatus = ''; // Global status tracker
        let milestoneReached = new Set(); // Track milestones per player
        let bowlerWicketsLastBalls = {}; // Track consecutive wickets for hat-trick

        // Image caching to prevent auto-refresh
        let cachedPlayerImages = {};

        // Audio Manager
        class AudioManager {
            constructor() {
                this.enabled = true;
                this.unlocked = false;
                this.audios = {};
                this.sources = {
                    wide: '../assets/audio/wide.mp3',
                    duck: '../assets/audio/duck_out.mp3',
                    match_start: '../assets/audio/match_start.mp3',
                    batHit: '../assets/audio/hitting_score.mp3',
                    danger: '../assets/audio/danger.mp3',
                    wicket: '../assets/audio/wicket.mp3',
                    scorecard: '../assets/audio/batting_scorecard.mp3',
                    graph: '../assets/audio/score_comparison_graph.mp3',
                    batting_team: '../assets/audio/batting_team_overlay.mp3',
                    target: '../assets/audio/target_overlay.mp3',
                    bar_graph: '../assets/audio/bar_graph_overlay.mp3',
                    projected_score: '../assets/audio/project_score_overlay.mp3',
                    bowling_team_display: '../assets/audio/bowling_team_display.mp3',
                    partnership: '../assets/audio/partnership_overlay.mp3',
                    upcoming_match: '../assets/audio/upcoming_match.mp3',
                    next_match: '../assets/audio/upcoming_match.mp3'
                };
                this.unlockingPromise = null;
                this.init();
            }

            init() {
                console.log("Initializing Audio Manager...");
                for (const [key, src] of Object.entries(this.sources)) {
                    const a = new Audio(src);
                    a.preload = 'auto';
                    const loopingKeys = ['scorecard', 'graph', 'batting_team', 'target', 'bar_graph', 'projected_score', 'bowling_team_display', 'partnership', 'upcoming_match', 'next_match'];
                    if (loopingKeys.includes(key)) a.loop = true;
                    this.audios[key] = a;
                }

                // Multi-event unlock to be safe
                const unlocker = () => {
                    this.unlock();
                };

                ['click', 'keydown', 'touchstart', 'mousedown'].forEach(evt => {
                    window.addEventListener(evt, unlocker, { once: true });
                });

                // Guarantee unlock on first body click even if window listeners fail
                document.body.addEventListener('click', () => {
                    this.unlock();
                }, { once: true });
            }

            async unlock() {
                if (this.unlocked) return;

                // Concurrency Protection: If already unlocking, return existing promise
                if (this.unlockingPromise) return this.unlockingPromise;

                this.unlockingPromise = (async () => {
                    console.log("Attempting to unlock audio context...");
                    try {
                        const promises = Object.values(this.audios).map(async (a) => {
                            try {
                                const p = a.play();
                                if (p !== undefined) {
                                    await p;
                                    a.pause();
                                    a.currentTime = 0;
                                }
                            } catch (e) {
                                // Expected before interaction
                            }
                        });

                        await Promise.all(promises);
                        this.unlocked = true;
                        console.log("Audio engine unlocked successfully.");

                        // Resume active overlay audio if any
                        if (typeof currentData !== 'undefined') {
                            this.checkPersistentOverlayAudio(currentData);
                        }

                        // Proactively check if we should play match start audio now that we are unlocked
                        if (typeof lastMatchStatus !== 'undefined' && lastMatchStatus) {
                            checkMatchStartAudio(lastMatchStatus);
                        }
                    } catch (err) {
                        console.warn("Audio unlock process failed:", err);
                    } finally {
                        this.unlockingPromise = null;
                    }
                })();

                return this.unlockingPromise;
            }

            async play(key) {
                if (!this.enabled) return;

                const a = this.audios[key];
                if (a) {
                    a.currentTime = 0;
                    return a.play().catch(e => {
                        console.warn(`Audio play failed for '${key}':`, e);
                        if (e.name === 'NotAllowedError') {
                            this.unlocked = false;
                        }
                        throw e; // Rethrow to allow caller to handle failure
                    });
                } else {
                    console.error(`Audio key not found: ${key}`);
                    return Promise.reject(`Key not found: ${key}`);
                }
            }

            stop(key) {
                const a = this.audios[key];
                if (a) {
                    a.pause();
                    a.currentTime = 0;
                }
            }

            stopAll() {
                Object.values(this.audios).forEach(a => {
                    a.pause();
                    a.currentTime = 0;
                });
            }

            toggle() {
                this.enabled = !this.enabled;
                console.log(`Audio toggled: ${this.enabled ? 'On' : 'Off'}`);
                if (this.enabled && !this.unlocked) this.unlock();
            }

            checkPersistentOverlayAudio(data) {
                if (!this.unlocked || !this.enabled) return;
                const oType = data.match_info.overlay_type;
                if (!oType) return;

                // Map of overlay_type to its audio source key
                const map = {
                    'partnership': 'partnership',
                    'batting_team': 'batting_team',
                    'bowling_team': 'bowling_team_display',
                    'target': 'target',
                    'projected_score': 'projected_score',
                    'runs_wickets_graph': 'bar_graph',
                    'batting_scorecard': 'scorecard',
                    'bowler_scorecard': 'scorecard', // bowlers also use scorecard audio
                    'comparison_graph': 'graph',
                    'upcoming_matches': 'upcoming_match',
                    'next_match': 'next_match'
                };

                const audioKey = map[oType];
                if (audioKey && this.audios[audioKey]) {
                    // Only play if not already playing
                    if (this.audios[audioKey].paused) {
                        console.log(`Resuming audio for ${oType}: ${audioKey}`);
                        this.play(audioKey).catch(e => console.warn("Deferred play failed", e));
                    }
                }
            }
        }

        const audioManager = new AudioManager();
        // Expose globally for button
        window.toggleAudio = () => audioManager.toggle();

        window.addEventListener('load', () => {
            // fetchData will handle the initial check once status is known
            fetchData();
        });

        function checkMatchStartAudio(status) {
            if (status) lastMatchStatus = status.toLowerCase();

            console.log(`Checking match start audio. Status: ${lastMatchStatus}, AlreadyPlayed: ${matchStartPlayed}`);

            // Allow both 'live' and 'ongoing' as active statuses
            const isActive = (lastMatchStatus === 'live' || lastMatchStatus === 'ongoing');

            if (typeof MATCH_ID === 'undefined' || !isActive) return;

            const flag = `match_start_${MATCH_ID}_played_v4`;
            if (sessionStorage.getItem(flag)) return;
            if (matchStartPlayed) return;

            // Attempt to play immediately
            audioManager.play('match_start').then(() => {
                console.log("Match Start audio played successfully!");
                matchStartPlayed = true;
                sessionStorage.setItem(flag, 'true');
            }).catch(e => {
                console.warn("Match Start audio playback blocked. Waiting for interaction...");
                // It will retry automatically when the user interacts via AudioManager.unlock()
            });
        }

        function openPlayerCard(playerData, teamName) {
            if (!playerData) return;

            const profileImg = playerData.profile_image ? `../uploads/users/${playerData.profile_image}` : '../assets/images/default-player.png';
            document.getElementById('pcImg').src = profileImg;
            document.getElementById('pcImg').onerror = function () { this.src = '../assets/images/default-player.png'; };
            document.getElementById('pcName').innerText = playerData.name || 'Unknown';
            document.getElementById('pcTeam').innerText = teamName || '';

            // Prioritize "career" stats aliases, fallback to standard keys
            document.getElementById('pcMat').innerText = (playerData.matches_played !== undefined ? playerData.matches_played : (playerData.matches || '0'));
            document.getElementById('pcRun').innerText = (playerData.career_runs !== undefined ? playerData.career_runs : (playerData.runs || '0'));
            document.getElementById('pcWkt').innerText = (playerData.career_wickets !== undefined ? playerData.career_wickets : (playerData.wickets || '0'));

            document.getElementById('pcViewStats').href = `../view/view_player_profile.php?player_id=${playerData.id || playerData.player_id}`;

            document.getElementById('playerCardOverlay').style.display = 'flex';
        }

        function closePlayerCard() {
            document.getElementById('playerCardOverlay').style.display = 'none';
        }

        // Close overlay on background click
        window.onclick = function (event) {
            const pcOverlay = document.getElementById('playerCardOverlay');
            if (event.target == pcOverlay) {
                closePlayerCard();
            }
            const ppOverlay = document.getElementById('playerPopup');
            if (event.target == ppOverlay) {
                ppOverlay.classList.remove('show');
            }
        }

        if (typeof MATCH_ID === 'undefined' || !MATCH_ID) {
            console.error("Critical Error: MATCH_ID is not defined.");
            document.getElementById('headerContent').innerHTML = "<h3 class='text-danger'>Error: Match ID Missing</h3>";
            throw new Error("Execution stopped due to missing Match ID");
        }

        let isFetching = false;
        const FETCH_TIMEOUT_MS = 10000;

        function toggleInnings(inningNum) {
            const header = document.getElementById(`sc-header-${inningNum}`);
            const content = document.getElementById(`sc-content-${inningNum}`);
            if (header && content) {
                header.classList.toggle('active');
                content.classList.toggle('show');
            }
        }

        let refreshInterval = setInterval(fetchData, 2000);
        fetchData();
        let currentTab = 'live';

        function switchTab(t) {
            currentTab = t;
            document.querySelectorAll('.tab').forEach(e => e.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));

            // Find the tab element by text content or use a more robust selector if id was on the div
            // Actually, event.target might be cleaner but let's use a selector
            const tabElements = document.querySelectorAll('.tab');
            tabElements.forEach(el => {
                if (el.innerText.toLowerCase().includes(t.toLowerCase())) {
                    el.classList.add('active');
                }
            });
            document.getElementById(t).classList.add('active');

            // Just trigger an immediate fetch for responsiveness
            fetchData();
        }



        function fetchData() {
            if (isFetching) return;
            isFetching = true;

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

            // Add cache-buster to prevent stale data
            fetch(`get_live_data.php?id=${MATCH_ID}&t=${new Date().getTime()}`, { signal: controller.signal })
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP error! status: ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    if (data && data.success) {
                        // Check for match end (completed)
                        if (data.match_info.status === 'completed') {
                            window.location.href = `../view_match_summary.php?id=${MATCH_ID}`;
                            return;
                        }

                        // Check for match stop (upcoming)
                        if (data.match_info.status === 'upcoming') {
                            clearLiveUI();
                            renderStoppedState();
                            return;
                        }

                        // If match was previously stopped but now started, clear and fetch fresh
                        if (document.getElementById('headerContent').querySelector('.fa-pause-circle')) {
                            clearLiveUI();
                        }

                        // Auto-Redirect upon Match Completion (2nd Innings finished)


                        if (lastInningNum !== -1 && lastInningNum !== data.score.inning_number) {
                            introducedPlayers.clear();
                            summarizedOvers.clear();
                            document.getElementById('commentaryFeed').innerHTML = '';
                            lastBallId = 0; // Reset for new innings
                        }

                        // Detection of Undo (Rollback)
                        if (!firstLoad && data.recent_commentary && data.recent_commentary.length > 0) {
                            const latestBallInSync = parseInt(data.recent_commentary[0].id);
                            if (latestBallInSync < lastBallId) {
                                // Undo detected!
                                console.log("Undo detected, syncing state...");
                                syncStateFromHistory(data, true);
                            }
                        }

                        // Prevent replaying old popups on first load
                        const isInitialLoad = firstLoad;
                        if (firstLoad) {
                            if (data.recent_commentary && data.recent_commentary.length > 0) {
                                syncStateFromHistory(data, false);
                                // For the very first load, we populate the live commentary feed from full history
                                if (data.full_commentary && data.full_commentary.length > 0) {
                                    // Use full commentary but MUST filter for current inning 
                                    // to match the live feed's behavior (which resets per inning)
                                    const currentInningBalls = data.full_commentary.filter(b => b.inning_number == data.score.inning_number);
                                    renderCommentary(data, currentInningBalls);
                                }
                            }
                            // firstLoad = false; // Moved to the end of processing
                        }

                        lastInningNum = data.score.inning_number;

                        renderHeader(data);
                        renderLiveTab(data);
                        renderFullCommentary(data);
                        renderScorecardTabNew(data);
                        renderTeamsTab(data);
                        checkPopups(data);
                        handleInningsBreak(data);
                        checkMatchStartAudio(data.match_info.status);

                        // Pause Overlay Control
                        const pauseOverlay = document.getElementById('pauseOverlay');
                        if (pauseOverlay) {
                            if (data.match_info.is_paused == 1) {
                                pauseOverlay.classList.add('show');
                            } else {
                                pauseOverlay.classList.remove('show');
                            }
                        }

                        // Overlay Triggers (Remote)
                        if (data.match_info.overlay_id && data.match_info.overlay_id > lastOverlayId) {
                            const oType = data.match_info.overlay_type;
                            if (oType === 'partnership') {
                                overlayManager.add('remote_partnership', data.match_info.overlay_id, 2, (done) => {
                                    showPartnershipOverlay(data, done);
                                });
                            } else if (oType === 'batting_team') {
                                overlayManager.add('remote_batting_team', data.match_info.overlay_id, 2, (done) => {
                                    showBattingTeamOverlay(data, done);
                                });
                            } else if (oType === 'comparison_graph') {
                                overlayManager.add('remote_comparison_graph', data.match_info.overlay_id, 2, (done) => {
                                    showComparisonGraphOverlay(data, done);
                                });
                            } else if (oType === 'target') {
                                overlayManager.add('remote_target', data.match_info.overlay_id, 2, (done) => {
                                    showTargetOverlay(data, done);
                                });
                            } else if (oType === 'projected_score') {
                                overlayManager.add('remote_projected_score', data.match_info.overlay_id, 2, (done) => {
                                    showProjectedScoreOverlay(data, done);
                                });
                            } else if (oType === 'runs_wickets_graph') {
                                overlayManager.add('remote_runs_wickets_graph', data.match_info.overlay_id, 2, (done) => {
                                    showRunsWicketsGraphOverlay(data, done);
                                });
                            } else if (oType === 'batting_scorecard') {
                                overlayManager.add('remote_batting_scorecard', data.match_info.overlay_id, 2, (done) => {
                                    showBattingScorecardOverlay(data, done);
                                });
                            } else if (oType === 'bowler_scorecard') {
                                overlayManager.add('remote_bowler_scorecard', data.match_info.overlay_id, 2, (done) => {
                                    showBowlerScorecardOverlay(data, done);
                                });
                            } else if (oType === 'bowling_team') {
                                overlayManager.add('remote_bowling_team', data.match_info.overlay_id, 2, (done) => {
                                    showBowlingTeamOverlay(data, done);
                                });
                            } else if (oType === 'super_over_intro') {
                                if (!isInitialLoad) {
                                    overlayManager.add('super_over_intro', data.match_info.overlay_id, 2, (done) => {
                                        showSuperOverIntro(data, done);
                                    });
                                }
                            } else if (oType === 'upcoming_matches') {
                                overlayManager.add('remote_upcoming_matches', data.match_info.overlay_id, 2, (done) => {
                                    showUpcomingMatchesOverlay(done);
                                });
                            } else if (oType === 'next_match') {
                                overlayManager.add('remote_next_match', data.match_info.overlay_id, 2, (done) => {
                                    showNextMatchOverlay(done);
                                });
                            } else if (oType === 'clear' || oType === '') {
                                hideAllPersistentOverlays();
                            }
                            lastOverlayId = data.match_info.overlay_id;
                        } else if (firstLoad && data.match_info.overlay_id) {
                            // Persistent Overlays: Show even on first load if active
                            const oType = data.match_info.overlay_type;
                            if (oType === 'partnership' || oType === 'batting_team' || oType === 'bowling_team' || oType === 'comparison_graph' || oType === 'target' || oType === 'projected_score' || oType === 'runs_wickets_graph' || oType === 'batting_scorecard' || oType === 'bowler_scorecard' || oType === 'upcoming_matches' || oType === 'next_match') {
                                // Re-trigger persistent overlay immediately on first load
                                setTimeout(() => {
                                    if (oType === 'partnership') showPartnershipOverlay(data);
                                    else if (oType === 'batting_team') showBattingTeamOverlay(data);
                                    else if (oType === 'bowling_team') showBowlingTeamOverlay(data);
                                    else if (oType === 'comparison_graph') showComparisonGraphOverlay(data);
                                    else if (oType === 'target') showTargetOverlay(data);
                                    else if (oType === 'projected_score') showProjectedScoreOverlay(data);
                                    else if (oType === 'runs_wickets_graph') showRunsWicketsGraphOverlay(data);
                                    else if (oType === 'batting_scorecard') showBattingScorecardOverlay(data);
                                    else if (oType === 'bowler_scorecard') showBowlerScorecardOverlay(data);
                                    else if (oType === 'upcoming_matches') showUpcomingMatchesOverlay();
                                    else if (oType === 'next_match') showNextMatchOverlay();
                                }, 1000); // Small delay to ensure UI is ready
                            }
                            lastOverlayId = data.match_info.overlay_id;
                        }

                        // Refresh active persistent overlays (Live update)
                        if (document.getElementById('partnershipOverlay').style.display === 'flex') {
                            showPartnershipOverlay(data, null, true);
                        }
                        if (document.getElementById('battingTeamOverlay').style.display === 'flex') {
                            showBattingTeamOverlay(data, null, true);
                        }
                        if (document.getElementById('bowlingTeamOverlay').style.display === 'flex') {
                            showBowlingTeamOverlay(data, null, true);
                        }
                        if (document.getElementById('targetOverlay').style.display === 'flex') {
                            showTargetOverlay(data, null, true);
                        }
                        if (document.getElementById('projectedScoreOverlay').style.display === 'flex') {
                            showProjectedScoreOverlay(data, null, true);
                        }
                        if (document.getElementById('graphRunsWicketsOverlay').style.display === 'flex') {
                            showRunsWicketsGraphOverlay(data, null, true);
                        }
                        if (document.getElementById('battingScorecardOverlay').style.display === 'flex') {
                            showBattingScorecardOverlay(data, null, true);
                        }
                        if (document.getElementById('bowlerScorecardOverlay').style.display === 'flex') {
                            showBowlerScorecardOverlay(data, null, true);
                        }

                        // Always check for persistent audio (handles deferred unlock)
                        audioManager.checkPersistentOverlayAudio(data);

                        if (firstLoad) firstLoad = false;
                    } else {
                        document.getElementById('headerContent').innerHTML = `
                            <div style="text-align:center; width:100%; padding:20px;">
                                <h3 class="text-danger"><i class="fas fa-exclamation-triangle"></i> Error Loading Match</h3>
                                <p>${data.message || 'Unknown error occurred'}</p>
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    console.error("Fetch Error:", err);
                    // Only show connection error if it's NOT an abort error (timeout)
                    if (err.name === 'AbortError') {
                        console.warn("Fetch timed out");
                    } else {
                        document.getElementById('headerContent').innerHTML = `
                            <div style="text-align:center; width:100%; padding:20px;">
                                <h3 class="text-danger"><i class="fas fa-exclamation-triangle"></i> Connection Error</h3>
                                <p>Retrying...</p>
                            </div>
                        `;
                    }
                })
                .finally(() => {
                    clearTimeout(timeoutId);
                    isFetching = false;
                });
        }

        // Add this new function for full commentary
        function renderFullCommentary(data) {
            const feed = document.getElementById('fullCommentaryFeed');
            const items = data.full_commentary;
            if (!items || items.length === 0) {
                feed.innerHTML = '<div class="text-center py-5">Full commentary will be available once the match starts.</div>';
                return;
            }

            let html = '';
            let currentInn = -1;

            items.forEach(ball => {
                if (ball.inning_number != currentInn) {
                    currentInn = ball.inning_number;
                    let innLabel = `${currentInn}${currentInn == 1 ? 'st' : 'nd'} Innings`;
                    if (currentInn == 3) innLabel = "Super Over 1st Innings";
                    if (currentInn == 4) innLabel = "Super Over 2nd Innings";
                    html += `<div class="inning-divider">${innLabel}</div>`;
                }

                let ballClass = 'bg-0';
                let ballText = ball.runs_scored;
                if (ball.wicket_type) { ballClass = 'bg-W'; ballText = 'W'; }
                else if (ball.runs_scored == 4) { ballClass = 'bg-4'; ballText = '4'; }
                else if (ball.runs_scored == 6) { ballClass = 'bg-6'; ballText = '6'; }
                else if (ball.extra_type == 'wide' || (ball.extra_type && ball.extra_type.toLowerCase() == 'wide')) { ballClass = 'bg-WD'; ballText = 'WD'; }
                else if (ball.extra_type == 'no ball' || (ball.extra_type && ball.extra_type.toLowerCase() == 'no ball')) { ballClass = 'bg-NB'; ballText = 'NB'; }

                html += `
                    <div class="commentary-item">
                        <div class="ball-event ${ballClass}">${ballText}</div>
                        <div style="flex:1;">
                            <span style="font-weight:bold; color:var(--text-secondary); margin-right:10px;">${ball.over_number}.${ball.ball_number}</span>
                            <strong>${ball.bowler_name} to ${ball.batter_name},</strong> 
                            ${ball.wicket_type ? `<span style="color:var(--danger); font-weight:bold;">OUT! (${ball.wicket_type})</span>` : ball.commentary}
                        </div>
                    </div>
                `;
            });

            feed.innerHTML = html;
        }



        let persistentDoneCallback = null;
        let comparisonChartInstance = null;

        function showUpcomingMatchesOverlay(done = null, skipAudio = false) {
            const overlay = document.getElementById('upcomingMatchesOverlay');
            const listContainer = document.getElementById('umList');

            listContainer.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-3x text-muted"></i><p class="mt-3">Fetching upcoming matches...</p></div>';
            overlay.style.display = 'flex';

            fetch('get_upcoming_matches.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.matches.length > 0) {
                        listContainer.innerHTML = '';
                        data.matches.forEach(m => {
                            const row = document.createElement('div');
                            row.className = 'um-match-row';
                            row.innerHTML = `
                                <div class="um-team-side">
                                    <img src="../uploads/teams/${m.team1_logo}" class="um-team-logo" onerror="this.src='../assets/images/default-team.png'">
                                    <div class="um-team-name">${m.team1_name}</div>
                                    <div class="um-team-code">${m.team1_code}</div>
                                </div>
                                <div class="um-center">
                                    <div class="um-vs-box">VS</div>
                                    <div class="um-match-type">${m.match_type}</div>
                                    <div class="um-date-time">${m.match_date} | ${m.match_time}</div>
                                    <div class="um-venue">${m.venue}</div>
                                </div>
                                <div class="um-team-side">
                                    <img src="../uploads/teams/${m.team2_logo}" class="um-team-logo" onerror="this.src='../assets/images/default-team.png'">
                                    <div class="um-team-name">${m.team2_name}</div>
                                    <div class="um-team-code">${m.team2_code}</div>
                                </div>
                            `;
                            listContainer.appendChild(row);
                        });
                    } else {
                        listContainer.innerHTML = '<div class="text-center py-5"><h3>No upcoming matches found</h3></div>';
                    }
                })
                .catch(err => {
                    console.error("Error fetching upcoming matches:", err);
                    listContainer.innerHTML = '<div class="text-center py-5"><h3 class="text-danger">Error loading data</h3></div>';
                });

            if (!skipAudio) audioManager.play('upcoming_match');
            if (done) persistentDoneCallback = done;
        }

        function showNextMatchOverlay(done = null, skipAudio = false) {
            const overlay = document.getElementById('nextMatchOverlay');
            const listContainer = document.getElementById('nmList');

            listContainer.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-3x text-muted"></i><p class="mt-3">Fetching next match...</p></div>';
            overlay.style.display = 'flex';

            fetch('get_next_match.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.match) {
                        const m = data.match;
                        listContainer.innerHTML = '';
                        const row = document.createElement('div');
                        row.className = 'um-match-row';
                        row.innerHTML = `
                            <div class="um-team-side">
                                <img src="../uploads/teams/${m.team1_logo}" class="um-team-logo" onerror="this.src='../assets/images/default-team.png'">
                                <div class="um-team-name">${m.team1_name}</div>
                                <div class="um-team-code">${m.team1_code}</div>
                            </div>
                            <div class="um-center">
                                <div class="um-vs-box">VS</div>
                                <div class="um-match-type">${m.match_type}</div>
                                <div class="um-date-time">${m.match_date} | ${m.match_time}</div>
                                <div class="um-venue">${m.venue}</div>
                            </div>
                            <div class="um-team-side">
                                <img src="../uploads/teams/${m.team2_logo}" class="um-team-logo" onerror="this.src='../assets/images/default-team.png'">
                                <div class="um-team-name">${m.team2_name}</div>
                                <div class="um-team-code">${m.team2_code}</div>
                            </div>
                        `;
                        listContainer.appendChild(row);
                    } else {
                        listContainer.innerHTML = '<div class="text-center py-5"><h3>No next match found</h3></div>';
                    }
                })
                .catch(err => {
                    console.error("Error fetching next match:", err);
                    listContainer.innerHTML = '<div class="text-center py-5"><h3 class="text-danger">Error loading data</h3></div>';
                });

            if (!skipAudio) audioManager.play('next_match');
            if (done) persistentDoneCallback = done;
        }

        function hideAllPersistentOverlays() {
            const overlayIds = [
                'battingTeamOverlay',
                'bowlingTeamOverlay',
                'partnershipOverlay',
                'comparisonGraphOverlay',
                'targetOverlay',
                'projectedScoreOverlay',
                'graphRunsWicketsOverlay',
                'battingScorecardOverlay',
                'bowlerScorecardOverlay',
                'upcomingMatchesOverlay',
                'nextMatchOverlay'
            ];

            overlayIds.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.style.display = 'none';
                    el.classList.remove('show', 'active');
                    el.style.pointerEvents = 'none';
                }
            });

            // Unlock body scroll if it was locked
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';

            // Stop All Audios
            audioManager.stopAll();

            // Trigger callback to unblock queue
            if (persistentDoneCallback) {
                console.log("Closing persistent overlay and triggering callback");
                const callback = persistentDoneCallback;
                persistentDoneCallback = null;
                try { callback(); } catch (e) { console.error("Error in persistent callback:", e); }
            } else {
                console.log("No persistent callback to trigger, ensuring manager is unblocked");
                overlayManager.isShowing = false;
            }
        }
        function showBattingTeamOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('battingTeamOverlay');
            const m = data.match_info;
            const s = data.score;
            const sc = data.scorecard;

            document.getElementById('btTeamName').innerText = m.batting_team_name;

            // Set team logo
            const isTeam1Batting = (m.batting_team_name === m.team1_name);
            const battingLogoPath = (isTeam1Batting ? m.team1_logo : m.team2_logo) || '';
            const btLogoEl = document.getElementById('btTeamLogo');
            if (btLogoEl) {
                btLogoEl.src = battingLogoPath ? `../uploads/teams/${battingLogoPath}` : '../assets/images/default-team.png';
            }

            // Find current batting team squad
            const originalSquad = (isTeam1Batting ? data.squads.team1 : data.squads.team2) || [];

            // Map original squad to include their batting position
            const squadWithPos = originalSquad.map((p, idx) => ({ ...p, pos: idx + 1 }));

            // Find scorecard for current inning
            const currentSc = sc.find(i => i.inning_number == s.inning_number);
            const battingStats = currentSc ? currentSc.batting : [];

            // Group players for custom sorting
            const dismissed = [];
            let striker = null;
            let nonStriker = null;
            const remaining = [];

            const sId = data.current_players.striker ? data.current_players.striker.id : null;
            const nsId = data.current_players.non_striker ? data.current_players.non_striker.id : null;

            // 1. Identify Dismissed in Batting Order
            battingStats.forEach(stat => {
                const p = squadWithPos.find(sqp => sqp.id == stat.player_id);
                if (p && p.is_out > 0) {
                    dismissed.push(p);
                }
            });
            const dismissedIds = new Set(dismissed.map(d => d.id));

            // 2. Identify remaining groups
            squadWithPos.forEach(p => {
                if (dismissedIds.has(p.id)) return; // Already in dismissed

                if (p.id == sId) {
                    striker = p;
                } else if (p.id == nsId) {
                    nonStriker = p;
                } else {
                    remaining.push(p);
                }
            });

            // Combine in requested order: Dismissed -> Striker -> Non-Striker -> Remaining
            const sortedSquad = [
                ...dismissed,
                ...(striker ? [striker] : []),
                ...(nonStriker ? [nonStriker] : []),
                ...remaining
            ];

            const container = document.getElementById('btCardsContainer');
            container.innerHTML = '';

            // Using flexible rows
            const row1 = document.createElement('div');
            row1.className = 'bt-row';
            const row2 = document.createElement('div');
            row2.className = 'bt-row';

            const numOut = dismissed.length;
            let remainingCounter = 0;

            sortedSquad.forEach((p, index) => {
                // Find stats if they exist
                const pStat = battingStats.find(st => st.player_id == p.id);
                let statText = '';

                if (p.id == sId || p.id == nsId) {
                    // Current batters: show runs even if zero/missing
                    const stats = pStat || { runs_scored: 0, balls_faced: 0 };
                    statText = `${stats.runs_scored} (${stats.balls_faced})`;
                } else if (p.is_out > 0 || pStat) {
                    // Dismissed players or those who finished batting
                    const stats = pStat || { runs_scored: 0, balls_faced: 0 };
                    statText = `${stats.runs_scored} (${stats.balls_faced})`;
                } else {
                    // Remaining players: Start from numOut + 2
                    statText = `@${numOut + 2 + remainingCounter}`;
                    remainingCounter++;
                }

                const cardChild = rowBatOverlay(p, statText, p.id == sId || p.id == nsId, p.is_out > 0);

                if (index < 6) {
                    row1.appendChild(cardChild);
                } else {
                    row2.appendChild(cardChild);
                }
            });

            container.appendChild(row1);
            if (sortedSquad.length > 6) container.appendChild(row2);

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('batting_team');
            // Auto-hide removed as per user request. Requires manual Stop click.
        }

        function showBowlingTeamOverlay(data, done = null, skipAudio = false) {
            if (!skipAudio) audioManager.play('bowling_team_display');
            const overlay = document.getElementById('bowlingTeamOverlay');
            const m = data.match_info;
            const sc = data.scorecard;
            const s = data.score;

            // Set team logo and determine bowling team
            const isTeam1Bowling = (m.bowling_team_name === m.team1_name);
            const teamColor = (isTeam1Bowling ? m.team1_color : m.team2_color) || '#ffffff';
            const nameEl = document.getElementById('bwlTeamName');
            nameEl.innerText = m.bowling_team_name;
            nameEl.style.color = teamColor;

            const bowlingLogoPath = (isTeam1Bowling ? m.team1_logo : m.team2_logo) || '';
            const bwlLogoEl = document.getElementById('bwlTeamLogo');
            if (bwlLogoEl) {
                bwlLogoEl.src = bowlingLogoPath ? `../uploads/teams/${bowlingLogoPath}` : '../assets/images/default-team.png';
            }

            // Find bowling squad
            const originalSquad = (isTeam1Bowling ? data.squads.team1 : data.squads.team2) || [];

            // Find scorecard for current inning
            const currentSc = sc.find(i => i.inning_number == s.inning_number);
            const bowlingStats = currentSc ? currentSc.bowling : [];

            // Filter players who have bowled at least one ball
            const activeBowlers = originalSquad.filter(p => {
                const stat = bowlingStats.find(st => st.player_id == p.id);
                return stat && parseFloat(stat.overs_bowled) > 0;
            });

            const container = document.getElementById('bwlCardsContainer');
            container.innerHTML = '';

            if (activeBowlers.length === 0) {
                container.innerHTML = '<div style="color:#666; font-size:1.5rem; margin-top:20px;">No bowlers active yet.</div>';
            } else {
                const row = document.createElement('div');
                row.className = 'bwl-row';

                activeBowlers.forEach(p => {
                    const stat = bowlingStats.find(st => st.player_id == p.id);
                    const W = stat.wickets_taken || 0;
                    const R = stat.runs_conceded || 0;
                    const O = stat.overs_bowled || '0.0';
                    const statText = `${W} / ${R} (${O})`;

                    const card = document.createElement('div');
                    card.className = 'bwl-player-card shadow-lg';
                    card.innerHTML = `
                        <div class="bwl-img-box">
                            <img src="../uploads/users/${p.profile_image || ''}" onerror="this.src='../assets/images/default-player.png'">
                        </div>
                        <div class="bwl-info-box">
                            <h4 class="bwl-player-name">${p.name}</h4>
                        </div>
                        <div class="bwl-stats-box">${statText}</div>
                    `;
                    row.appendChild(card);
                });
                container.appendChild(row);
            }

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('batting_team'); // Reusing batting_team audio if bowling one doesn't exist
        }

        function showTargetOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('targetOverlay');
            const m = data.match_info;
            const s = data.score;

            // Guard: Only show in 2nd innings
            if (s.inning_number != 2) {
                console.warn("Target overlay triggered but not in 2nd innings.");
                if (done) done();
                return;
            }

            document.getElementById('targetBattingTeam').innerText = m.batting_team_name;
            document.getElementById('targetRuns').innerText = s.required_runs || 0;
            document.getElementById('targetBalls').innerText = s.balls_remaining || 0;

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('target');
        }

        function showProjectedScoreOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('projectedScoreOverlay');
            const s = data.score;
            const totalOvers = s.total_overs || 20;

            // Calculate CRR
            const crr = (s.runs / (Math.max(0.1, (parseFloat(Math.floor(s.overs)) + ((parseFloat(s.overs) % 1) * 10 / 6))))).toFixed(2);
            const crrFloat = parseFloat(crr);
            const floorCrr = Math.floor(crrFloat);

            // Variations: Current, -1, -2, +1, +2, +3
            // We'll filter out duplicates if current RPO is exactly an integer
            const variations = [
                { label: 'CURRENT RPO', rpo: crrFloat, highlight: true },
                { label: `AT ${floorCrr - 2} RPO`, rpo: floorCrr - 2 },
                { label: `AT ${floorCrr - 1} RPO`, rpo: floorCrr - 1 },
                { label: `AT ${floorCrr + 1} RPO`, rpo: floorCrr + 1 },
                { label: `AT ${floorCrr + 2} RPO`, rpo: floorCrr + 2 },
                { label: `AT ${floorCrr + 3} RPO`, rpo: floorCrr + 3 }
            ];

            const container = document.getElementById('psContainer');
            container.innerHTML = '';

            variations.forEach(v => {
                if (v.rpo <= 0) return;

                const projected = Math.round(v.rpo * totalOvers);
                const div = document.createElement('div');
                div.className = 'ps-item' + (v.highlight ? ' highlight' : '');
                div.innerHTML = `
                    <div class="ps-label">${v.label}</div>
                    <div class="ps-value">${projected}</div>
                `;
                container.appendChild(div);
            });

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('projected_score');
        }


        function rowBatOverlay(p, statText, isActive, isDismissed) {
            const div = document.createElement('div');
            div.className = 'bt-player-card shadow-lg' + (isDismissed ? ' bt-dismissed' : '');

            let displayName = p.name;
            if (isActive) displayName = '★ ' + displayName;

            div.innerHTML = `
                <div class="bt-img-box">
                    <img src="../uploads/users/${p.profile_image || ''}" onerror="this.src='../assets/images/default-player.png'">
                </div>
                <div class="bt-info-box">
                    <h4 class="bt-player-name">${displayName}</h4>
                </div>
                <div class="bt-stats-box">${statText}</div>
            `;
            return div;
        }

        function syncStateFromHistory(data, isUndo = false) {
            if ((!data.recent_commentary || data.recent_commentary.length === 0) && (!data.full_commentary || data.full_commentary.length === 0)) return;

            if (isUndo) {
                // Clear feed and stop overlays
                document.getElementById('commentaryFeed').innerHTML = '';
                overlayManager.queue = [];
                overlayManager.isShowing = false;

                // Hide active overlays
                const overlays = ['runPopup', 'duckOutPopup', 'milestoneOverlay', 'hattrickOverlay', 'playerPopup'];
                overlays.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.style.display = 'none';
                        el.classList.remove('show', 'run-popup-active');
                    }
                });

                // Reset trackers except shownBallIds (we want to KEEP memory of what was shown)
                introducedPlayers.clear();
                summarizedOvers.clear();
                milestoneReached.clear();
                bowlerWicketsLastBalls = {};
            } else {
                // First load case: fresh start for everything
                overlayManager.shownBallIds.clear();
                introducedPlayers.clear();
                summarizedOvers.clear();
                milestoneReached.clear();
                bowlerWicketsLastBalls = {};
            }


            // Warm up from history
            const historyToSync = (data.full_commentary && data.full_commentary.length > 0) ? data.full_commentary : (data.recent_commentary || []);
            const historical = [...historyToSync].reverse();
            historical.forEach(ball => {
                // Silently populate trackers
                checkMilestonesAndHatTricks(ball, data, true);

                // DO NOT add players or overs here 
                // We want renderCommentary to encounter them for the first time
                // to correctly draw the intro/summary boxes in the feed.

                introducedPlayers.add(`pop_${ball.batsman_id}`); // Prevent reentry popup

                // Mark as shown so popups don't trigger in next loop (esp. on first load)
                overlayManager.shownBallIds.add(`run_${ball.id}`);
                overlayManager.shownBallIds.add(`duck_${ball.id}`);
                overlayManager.shownBallIds.add(`wicket_${ball.id}`);
                overlayManager.shownBallIds.add(`milestone_${ball.id}`);
                overlayManager.shownBallIds.add(`hattrick_${ball.id}`);
                overlayManager.shownBallIds.add(`on_hattrick_${ball.id}`);
            });

            // Re-sync basic state vars to avoid triggering "new object" detection popups
            const p = data.current_players;
            if (p && p.striker) lastStrikerId = p.striker.id;
            if (p && p.non_striker) lastNonStrikerId = p.non_striker.id;
            if (p && p.bowler) {
                lastBowlerId = p.bowler.id;
                lastOverNum = Math.floor(parseFloat(data.score.overs));
            }

            // Reset lastBallId to 0 regardless of load type 
            // This allows the next call to renderCommentary to populate the feed with existing balls.
            // Popups are still blocked because we populated shownBallIds above.
            lastBallId = 0;

            // On first load, clear feed and trigger re-render from history for the current inning
            if (!isUndo) {
                document.getElementById('commentaryFeed').innerHTML = '';
            }
        }
        function renderHeader(data) {
            const m = data.match_info;
            const s = data.score;
            const inningNum = s.inning_number;
            const top = document.getElementById('matchInfoTop');

            let soBadge = '';
            const headerEl = document.querySelector('.header');
            if (s.inning_number >= 3) {
                if (headerEl) headerEl.classList.add('super-over-header');
                soBadge = `<div style="background:linear-gradient(to right, #f1c40f, #e67e22); color:white; padding:4px 15px; border-radius:15px; display:inline-block; font-weight:bold; font-size:0.8rem; margin-bottom:2px; text-transform:uppercase; letter-spacing:1px; box-shadow: 0 0 10px rgba(241,196,15,0.4);">
                    Super Over
                </div><br>`;
            } else {
                if (headerEl) headerEl.classList.remove('super-over-header');
            }

            // Render Match Info Top
            top.innerHTML = `
                ${soBadge}
                <h2 style="font-family:'Montserrat'; font-weight:700; margin-bottom:5px;">${m.tournament_name} – Match ${m.match_number || ''}</h2>
                <div style="font-size:0.9rem; opacity:0.9;">
                    <i class="fas fa-map-marker-alt me-1"></i> Venue: ${m.venue} <br> 
                    <i class="fas fa-coins me-1"></i> Toss: ${m.toss_winner_name} won the toss and elected to ${m.toss_decision}
                </div>
            `;

            document.getElementById('headerContent').innerHTML = `
            <div class="team batting-side">
                <div class="team-logo circle-logo">
                    <img src="../uploads/teams/${m.batting_team_logo}" onerror="this.src='../assets/images/default-team.png'">
                </div>
                <div class="innings-label batting-label">
                    ${inningNum <= 2 ? (inningNum == 1 ? '1st' : '2nd') : 'Super Over'}<span class="hide-mobile"> Innings</span>
                    ${inningNum >= 3 ? '<span class="super-over-badge">SO</span>' : ''}
                </div>
                <div class="team-name">${m.batting_team_name}</div>
            </div>
            
            <div class="vs-container">
                 <div class="match-status">${m.status}</div>
                 <div class="vs-text">
                    ${s.runs}<span class="wickets-slash">/${s.wickets}</span>
                 </div>
                 <div class="overs-badge">${s.overs} / ${s.total_overs} Overs</div>
                 ${(s.inning_number == 2 || s.inning_number == 4) ? `<div class="match-target">${s.inning_number == 4 ? 'SO ' : ''}Target: ${s.target || '-'}</div>` : ''}
            </div>
            
            <div class="team bowling-side">
                <div class="team-logo circle-logo">
                    <img src="../uploads/teams/${m.bowling_team_logo}" onerror="this.src='../assets/images/default-team.png'">
                </div>
                <div class="innings-label bowling-label">Bowling</div>
                <div class="team-name">${m.bowling_team_name}</div>
            </div>
        `;

            // Update dynamic meta (Title & Favicon)
            updatePageMeta(data);
        }

        function renderLiveTab(data) {
            const s = data.score;
            const m = data.match_info;

            // Target Section visibility
            const targetSec = document.getElementById('targetSection');
            if (s.inning_number == 2 || s.inning_number == 4) {
                targetSec.style.display = 'block';
                document.getElementById('targetVal').innerText = s.target;

                // Calculate required
                const req = s.target - s.runs;
                const ballsTotal = (s.total_overs * 6);
                const currentBalls = (Math.floor(s.overs) * 6) + Math.round((s.overs % 1) * 10);
                const left = ballsTotal - currentBalls;

                if (req <= 0) {
                    document.getElementById('targetDesc').innerText = "Target Reached!";
                } else {
                    document.getElementById('targetDesc').innerText = `Needs ${req} runs in ${left} balls`;
                }
            } else {
                targetSec.style.display = 'none';
            }

            const liveScoreSummary = document.getElementById('liveScoreSummary');

            let infoLine = '';
            const crr = (s.runs / (Math.max(0.1, (parseFloat(Math.floor(s.overs)) + ((parseFloat(s.overs) % 1) * 10 / 6))))).toFixed(2);

            if (s.inning_number == 1 || s.inning_number == 3) {
                // 1st half of match (Normal or Super Over)
                infoLine = `
                    <div class="info-row">
                        <div>CRR: <strong>${crr}</strong></div>
                        <div>Projected Score: <strong>${s.projected_score || '-'}</strong></div>
                    </div>
                `;
            } else {
                // 2nd half of match (Target chasing)
                // Calculate RRR
                const reqRuns = s.required_runs || 0;
                const ballsLeft = s.balls_remaining || 0;
                let rrr = '0.00';
                if (ballsLeft > 0) {
                    rrr = ((reqRuns * 6) / ballsLeft).toFixed(2);
                } else if (reqRuns > 0) {
                    rrr = '∞';
                }

                infoLine = `
                    <div class="info-row highlight-needed">
                        <div>CRR: <strong>${crr}</strong></div>
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--accent);">RRR: <strong>${rrr}</strong></div>
                    </div>
                `;
            }

            liveScoreSummary.innerHTML = `
                <div class="info-row" style="margin-bottom: 0px; border-left: 4px solid #673ab7; background: #ede7f6;">
                    <div>Total Views: <strong>${data.views ? data.views.total : 0}</strong> <i class="fas fa-eye" style="color:#673ab7"></i></div>
                    <div>Current Views: <strong>${data.views ? data.views.current : 0}</strong> <i class="fas fa-users" style="color:#673ab7"></i></div>
                </div>
                ${infoLine}
            `;

            // Update Section Headers with Team Names
            const batHeader = document.getElementById('liveBattingHeader');
            if (batHeader) {
                batHeader.innerHTML = `<i class="fas fa-bat-ball"></i> Batting - ${m.batting_team_name}`;
            }
            const bwlHeader = document.getElementById('liveBowlingHeader');
            if (bwlHeader) {
                bwlHeader.innerHTML = `<i class="fas fa-baseball-ball"></i> Bowling - ${m.bowling_team_name}`;
            }

            // Live Batting Table
            const p = data.current_players;
            const tbodyBat = document.querySelector('#liveBattingTable tbody');
            let batH = '';
            if (p.striker) batH += rowBat(p.striker, true, m.batting_team_name);
            if (p.non_striker) batH += rowBat(p.non_striker, false, m.batting_team_name);
            tbodyBat.innerHTML = batH;

            // Live Bowling
            const tbodyBowl = document.querySelector('#liveBowlingTable tbody');
            if (p.bowler) {
                const bStats = p.bowler.match_stats || { overs_bowled: '0.0', runs_conceded: 0, wickets_taken: 0, maidens: 0, economy_rate: 0 };
                const bowlerJson = JSON.stringify(p.bowler).replace(/"/g, '&quot;');
                tbodyBowl.innerHTML = `
                <tr>
                    <td class="player-cell" style="display:flex; align-items:center; cursor:pointer;" onclick="openPlayerCard(${bowlerJson}, '${m.bowling_team_name}')">
                        <img src="../uploads/users/${(p.bowler && p.bowler.profile_image) || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                        <div>
                            <div style="font-weight:600">${(p.bowler && p.bowler.name && p.bowler.name.trim() !== '') ? p.bowler.name : 'Bowler Name'}</div>
                            <small class="text-danger fw-bold">Current Bowler</small>
                        </div>
                    </td>
                    <td>${bStats.overs_bowled || '0.0'}</td>
                    <td>${bStats.maidens || 0}</td> 
                    <td>${bStats.runs_conceded || 0}</td>
                    <td>${bStats.wickets_taken || 0}</td>
                    <td class="highlight">${parseFloat(bStats.economy_rate || 0).toFixed(2)}</td>
                </tr>
            `;
            }

            // Bubbles - Always clear and redraw to prevent duplicates
            const bubbles = document.getElementById('liveBubbles');
            bubbles.innerHTML = '';

            // Track bowler change for other purposes
            if (p.bowler && p.bowler.id !== lastBowlerId) {
                lastBowlerId = p.bowler.id;
            }

            if (data.current_over) {
                // Ensure we only show balls for the CURRENT bowler
                const currentBowlerId = p.bowler ? p.bowler.id : null;

                data.current_over.forEach(b => {
                    // Skip if ball belongs to a previous bowler (and it's not a legal ball ending an over that happened concurrently)
                    // The safest bet is: strictly current bowler ID
                    if (currentBowlerId && b.bowler_id != currentBowlerId) return;

                    let cls = 'bg-0';
                    let txt = b.runs_scored;
                    if (b.wicket_type) { cls = 'bg-W'; txt = 'W'; }
                    else if (b.runs_scored == 4) { cls = 'bg-4'; txt = '4'; }
                    else if (b.runs_scored == 6) { cls = 'bg-6'; txt = '6'; }
                    else if (b.extra_type == 'wide' || (b.extra_type && b.extra_type.toLowerCase() == 'wide')) { cls = 'bg-WD'; txt = 'WD'; }
                    else if (b.extra_type == 'no ball' || (b.extra_type && b.extra_type.toLowerCase() == 'no ball')) { cls = 'bg-NB'; txt = 'NB'; }

                    bubbles.innerHTML += `<div class="ball-event ${cls}">${txt}</div>`;
                });
            }

            // Commentary
            // On normal cycles, we use recent_commentary from the data object
            renderCommentary(data);
        }

        function renderCommentary(data, overrideBalls = null) {
            const feed = document.getElementById('commentaryFeed');
            const recent = overrideBalls || data.recent_commentary;
            if (!recent || recent.length === 0) return;

            // Reverse to process chronologically
            const chronological = [...recent].reverse();

            chronological.forEach(ball => {
                if (parseInt(ball.id) <= lastBallId) return;

                const m = data.match_info;

                // 1. Player Introduction (Once per inning lifecycle)
                if (!introducedPlayers.has(ball.batsman_id)) {
                    // Find batter in squads to get more info if needed, or just use ball data
                    const introHtml = `
                        <div class="commentary-intro d-flex align-items-center" style="background:#f8f9fa; border-left:4px solid var(--primary); padding:15px; margin:10px 0; border-radius:8px; display:flex; gap:15px;">
                            <img src="../uploads/users/${ball.batter_image || ''}" onerror="this.src='../assets/images/default-player.png'" style="width:50px; height:50px; border-radius:5px; object-fit:cover;">
                            <div>
                                <div style="font-weight:700; font-size:1.1rem;">${ball.batter_name}</div>
                                <div style="font-size:0.85rem; color:var(--text-secondary);">${m.batting_team_name} | <span class="badge bg-primary">Batter</span></div>
                            </div>
                        </div>
                    `;
                    feed.innerHTML = introHtml + feed.innerHTML;
                    introducedPlayers.add(ball.batsman_id);
                }

                // Bowler intro (Once per over)
                const overKey = `bowler_${ball.over_number}_${ball.bowler_id}`;
                if (!introducedPlayers.has(overKey)) {
                    const introBowlHtml = `
                        <div class="commentary-intro d-flex align-items-center" style="background:#fff5f5; border-left:4px solid var(--danger); padding:15px; margin:10px 0; border-radius:8px; display:flex; gap:15px;">
                            <img src="../uploads/users/${ball.bowler_image || ''}" onerror="this.src='../assets/images/default-player.png'" style="width:50px; height:50px; border-radius:5px; object-fit:cover;">
                            <div>
                                <div style="font-weight:700; font-size:1.1rem;">${ball.bowler_name}</div>
                                <div style="font-size:0.85rem; color:var(--text-secondary);">${m.bowling_team_name} | <span class="badge bg-danger">Current Bowler</span></div>
                            </div>
                        </div>
                    `;
                    feed.innerHTML = introBowlHtml + feed.innerHTML;
                    introducedPlayers.add(overKey);
                }

                // 2. Ball Item
                let ballClass = 'bg-0';
                let ballText = ball.runs_scored;
                if (ball.wicket_type) { ballClass = 'bg-W'; ballText = 'W'; }
                else if (ball.runs_scored == 4) { ballClass = 'bg-4'; ballText = '4'; }
                else if (ball.runs_scored == 6) { ballClass = 'bg-6'; ballText = '6'; }
                else if (ball.extra_type == 'wide' || (ball.extra_type && ball.extra_type.toLowerCase() == 'wide')) { ballClass = 'bg-WD'; ballText = 'WD'; }
                else if (ball.extra_type == 'no ball' || (ball.extra_type && ball.extra_type.toLowerCase() == 'no ball')) { ballClass = 'bg-NB'; ballText = 'NB'; }

                const ballHtml = `
                    <div class="commentary-item">
                        <div class="ball-event ${ballClass}">${ballText}</div>
                        <div style="flex:1;">
                            <span style="font-weight:bold; color:var(--text-secondary); margin-right:10px;">${ball.over_number}.${ball.ball_number}</span>
                            <strong>${ball.bowler_name} to ${ball.batter_name},</strong> 
                            ${ball.wicket_type ? `<span style="color:var(--danger); font-weight:bold;">OUT! (${ball.wicket_type})</span>` : ball.commentary}
                        </div>
                    </div>
                `;
                feed.innerHTML = ballHtml + feed.innerHTML;

                // Audio handled in showRunPopup to avoid overlap with Duck Out

                // Trigger Run Popup for the new ball
                let popupVal = ball.runs_scored;
                // Special handling: Wicket popup triggers separately
                if (ball.wicket_type) {
                    // Determine if it's a duck or normal wicket
                    let isDuck = false;
                    const sc = data.scorecard.find(i => i.inning_number == data.score.inning_number);

                    // Use wicket_player_id if available (essential for run-outs), else fallback to batsman_id
                    const outPlayerId = ball.wicket_player_id || ball.batsman_id;
                    let batterName = ball.wicket_player_name || ball.batter_name;
                    let batterImg = ball.wicket_player_image || ball.batter_image || '';

                    if (sc) {
                        const outBatter = sc.batting.find(p => p.player_id == outPlayerId);
                        if (outBatter) {
                            if (parseInt(outBatter.runs_scored) === 0) isDuck = true;
                            batterName = outBatter.name;
                            batterImg = outBatter.profile_image || batterImg;
                        }
                    }

                    if (isDuck) {
                        overlayManager.add('wicket', ball.id, 1, (done) => {
                            showWicketPopup(batterName, batterImg, '0', '0', done, 2000, false);
                        });
                        overlayManager.add('duck', ball.id, 1, (done) => {
                            showDuckOutPopup({ name: batterName, balls_faced: 0 }, done, 2000);
                        });
                    } else {
                        // Normal Wicket: 4s, Wicket Audio
                        // Fetch stats from scorecard
                        let batterRuns = '0';
                        let batterBalls = '0';

                        if (sc) {
                            const outBatter = sc.batting.find(p => p.player_id == outPlayerId);
                            if (outBatter) {
                                batterRuns = outBatter.runs_scored;
                                batterBalls = outBatter.balls_faced;
                                batterImg = outBatter.profile_image || batterImg;
                            }
                        }

                        overlayManager.add('wicket', ball.id, 1, (done) => {
                            showWicketPopup(batterName, batterImg, batterRuns, batterBalls, done, 4000, true);
                        });
                    }

                } else {
                    if (ball.extra_type && ball.extra_type.toLowerCase() == 'wide') popupVal = 'WD';
                    else if (ball.extra_type && ball.extra_type.toLowerCase() == 'no ball') popupVal = 'NB';

                    // Add to Overlay Queue: Run Popup (Priority 1)
                    overlayManager.add('run', ball.id, 1, (done) => {
                        showRunPopup(popupVal, ball, data, done);
                    });
                }

                // 2.5 Milestone and Hat-Trick Check
                checkMilestonesAndHatTricks(ball, data);

                // 3. Over Summary
                // Check if this ball ends an over (6th legal ball)
                if (ball.ball_number == 6 && ball.extra_type === null && !summarizedOvers.has(ball.over_number)) {
                    // We need to calculate runs/wickets for THIS over
                    // Since we are processing chronologically, we can look back at the bubbles for current over 
                    // or just calculate from the 'recent' array balls that match this over number
                    const overBalls = recent.filter(b => b.over_number == ball.over_number);
                    let overRuns = 0;
                    let overWickets = 0;
                    let ballDisplay = [];

                    overBalls.reverse().forEach(b => {
                        let r = parseInt(b.runs_scored || 0) + parseInt(b.extra_runs || 0);
                        overRuns += r;
                        if (b.wicket_type) overWickets++;

                        let txt = b.runs_scored;
                        if (b.wicket_type) txt = 'W';
                        else if (b.extra_type == 'wide') txt = 'WD';
                        else if (b.extra_type == 'no ball') txt = 'NB';
                        ballDisplay.push(txt);
                    });

                    // Current Batters info
                    const p = data.current_players;
                    let battersInfo = '';
                    if (p.striker || p.non_striker) {
                        const sArr = [];
                        if (p.striker) {
                            const st = p.striker.match_stats || { runs_scored: 0, balls_faced: 0 };
                            sArr.push(`${p.striker.name} ${st.runs_scored}(${st.balls_faced})`);
                        }
                        if (p.non_striker) {
                            const nst = p.non_striker.match_stats || { runs_scored: 0, balls_faced: 0 };
                            sArr.push(`${p.non_striker.name} ${nst.runs_scored}(${nst.balls_faced})`);
                        }
                        battersInfo = `<div style="margin-top:10px; font-size:0.95rem; border-top:1px solid #333; padding-top:10px;">
                            <strong>Batters:</strong> ${sArr.join(', ')}
                        </div>`;
                    }

                    // Partnership info (Cumulative)
                    const partnershipInfo = data.score.partnership && data.score.partnership.runs > 0
                        ? `<div style="margin-top:5px; font-size:0.9rem;"><strong>Partnership:</strong> ${data.score.partnership.runs} runs (${data.score.partnership.balls} balls)</div>`
                        : '';

                    const overSummaryHtml = `
                        <div class="commentary-over-end" style="background:#1a1a1a; color:#fff; padding:20px; border-radius:12px; margin:25px 0; border:1px solid #333; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
                            <div style="font-weight:700; font-size:1.2rem; margin-bottom:12px; border-bottom:1px solid #333; padding-bottom:10px; display:flex; justify-content:space-between;">
                                <span>End of Over ${parseInt(ball.over_number) + 1}</span>
                                <span style="color:var(--accent);">Total: ${data.score.runs}/${data.score.wickets}</span>
                            </div>
                            <div style="font-weight:600; margin-bottom:12px; font-size:1rem;">
                                This Over: <span style="color:var(--accent);">${overRuns} runs, ${overWickets} wicket${overWickets !== 1 ? 's' : ''}</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                                <span style="font-size:0.9rem; font-weight:700;">Balls:</span>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    ${ballDisplay.map(t => `<div style="width:28px; height:28px; border-radius:50%; background:#333; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:bold; border:1px solid #444;">${t}</div>`).join('')}
                                </div>
                            </div>
                            ${battersInfo}
                            ${partnershipInfo}
                        </div>
                    `;
                    feed.innerHTML = overSummaryHtml + feed.innerHTML;
                    summarizedOvers.add(ball.over_number);
                }

                lastBallId = parseInt(ball.id);
            });
        }

        function checkMilestonesAndHatTricks(ball, data, skipOverlay = false) {
            // Milestone Check
            const currentInning = data.score.inning_number;
            const scorecard = data.scorecard.find(i => i.inning_number == currentInning);
            if (scorecard) {
                const batter = scorecard.batting.find(b => b.player_id === ball.batsman_id);
                if (batter) {
                    const runs = parseInt(batter.runs_scored);
                    [50, 100].forEach(ms => {
                        const key = `ms_${ball.batsman_id}_${ms}`;
                        if (runs >= ms && !milestoneReached.has(key)) {
                            if (!skipOverlay) {
                                // Add to Overlay Queue: Milestone (Priority 4)
                                overlayManager.add('milestone', ball.id, 4, (done) => {
                                    showMilestoneOverlay(batter, ms, done);
                                });
                            }
                            milestoneReached.add(key);
                        }
                    });
                }
            }

            // Hat-Trick Check
            const bowlerId = ball.bowler_id;
            if (!bowlerWicketsLastBalls[bowlerId]) bowlerWicketsLastBalls[bowlerId] = [];

            if (ball.wicket_type && ball.wicket_type !== 'run out') {
                bowlerWicketsLastBalls[bowlerId].push(ball.id);

                // Check if last two or three balls were wickets
                if (bowlerWicketsLastBalls[bowlerId].length >= 3) {
                    if (!skipOverlay) {
                        overlayManager.add('hattrick', ball.id, 3, (done) => {
                            showHatTrickOverlay(ball, data, true, done);
                        });
                    }
                    bowlerWicketsLastBalls[bowlerId] = []; // Reset after hat-trick
                } else if (bowlerWicketsLastBalls[bowlerId].length === 2) {
                    if (!skipOverlay) {
                        overlayManager.add('on_hattrick', ball.id, 3, (done) => {
                            showHatTrickOverlay(ball, data, false, done);
                        });
                    }
                }
            } else if (ball.extra_type !== 'wide' && ball.extra_type !== 'no ball') {
                // If legal delivery and no wicket, break consecutive streak
                bowlerWicketsLastBalls[bowlerId] = [];
            }
        }

        function showMilestoneOverlay(batter, runs, done = null) {
            const overlay = document.getElementById('milestoneOverlay');
            document.getElementById('msPlayerName').innerText = batter.name;
            document.getElementById('msNumber').innerText = runs;
            const profileImg = batter.profile_image ? `../uploads/users/${batter.profile_image}` : '../assets/images/default-player.png';
            document.getElementById('msPlayerImg').src = profileImg;
            document.getElementById('msPlayerImg').onerror = function () { this.src = '../assets/images/default-player.png'; };

            overlay.style.display = 'flex';
            setTimeout(() => {
                overlay.style.display = 'none';
                if (done) done();
            }, 3000);
        }

        function showHatTrickOverlay(ball, data, isTriple, done = null) {
            const overlay = document.getElementById('hattrickOverlay');
            const playerName = ball.bowler_name;
            const playerImg = ball.bowler_image;
            const imgSrc = playerImg ? `../uploads/users/${playerImg}` : `../assets/images/default-player.png`;

            document.getElementById('htPlayerImg').src = imgSrc;
            document.getElementById('htPlayerImg').onerror = function () { this.src = '../assets/images/default-player.png'; };
            document.getElementById('htPlayerName').innerText = playerName;

            if (isTriple) {
                document.getElementById('htTitle').innerText = 'HAT-TRICK WICKET!';
                document.getElementById('htTitle').className = 'hattrick-title';
            } else {
                document.getElementById('htTitle').innerText = 'On Hat-Trick!';
                document.getElementById('htTitle').className = 'hattrick-title on-hattrick-title';
            }

            overlay.style.display = 'flex';
            setTimeout(() => {
                overlay.style.display = 'none';
                if (done) done();
            }, isTriple ? 4000 : 2000);
        }

        function showPartnershipOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('partnershipOverlay');
            const p = data.current_players;
            const score = data.score;

            if (p.striker) {
                const s = p.striker;
                // Use individual partnership stats from backend
                const pRuns = score.partnership.striker_p_runs || 0;
                const pBalls = score.partnership.striker_p_balls || 0;
                document.getElementById('p1Name').innerText = s.name;
                document.getElementById('p1Runs').innerText = `${pRuns} (${pBalls})`;
                document.getElementById('p1Img').src = s.profile_image ? `../uploads/users/${s.profile_image}` : '../assets/images/default-player.png';
            }

            if (p.non_striker) {
                const ns = p.non_striker;
                // Use individual partnership stats from backend
                const pRuns = score.partnership.non_striker_p_runs || 0;
                const pBalls = score.partnership.non_striker_p_balls || 0;
                document.getElementById('p2Name').innerText = ns.name;
                document.getElementById('p2Runs').innerText = `${pRuns} (${pBalls})`;
                document.getElementById('p2Img').src = ns.profile_image ? `../uploads/users/${ns.profile_image}` : '../assets/images/default-player.png';
            }

            if (score.partnership) {
                document.getElementById('pTotalRuns').innerText = score.partnership.runs || 0;
                document.getElementById('pTotalBalls').innerText = `${score.partnership.balls || 0} balls`;
            }

            overlay.style.display = 'flex';
            if (!skipAudio) audioManager.play('partnership');

            if (done) persistentDoneCallback = done; // Allow manual Stop to clear it

            // Auto-hide removed to make it persistent until manual Stop is clicked.
        }

        function showComparisonGraphOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('comparisonGraphOverlay');
            overlay.style.display = 'flex';
            setTimeout(() => overlay.classList.add('show'), 10); // Trigger transition

            const stats = data.overwise_stats;
            const match = data.match_info;

            // Update Logos in Header
            document.getElementById('compBatTeamLogo').src = match.batting_team_logo ? `../uploads/teams/${match.batting_team_logo}` : '../assets/images/default-team.png';
            document.getElementById('compBowlTeamLogo').src = match.bowling_team_logo ? `../uploads/teams/${match.bowling_team_logo}` : '../assets/images/default-team.png';

            if (!stats) {
                if (done) done();
                return;
            }

            const ctx = document.getElementById('comparisonChart').getContext('2d');

            // Prepare labels: Overs 1 to Max
            const totalOvers = data.score.total_overs || 20;
            const labels = Array.from({ length: totalOvers }, (_, i) => i + 1);

            // Prepare data: mapping labels to stats to ensure all overs are represented
            const inn1Data = labels.map(l => {
                const found = stats.inning1.find(ov => parseInt(ov.over_number) == (l - 1));
                return found ? parseFloat(found.runs) : null;
            });
            const inn2Data = labels.map(l => {
                const found = stats.inning2.find(ov => parseInt(ov.over_number) == (l - 1));
                return found ? parseFloat(found.runs) : null;
            });

            if (comparisonChartInstance) comparisonChartInstance.destroy();

            // Populate Stats Footer
            const sc = data.scorecard || [];
            const inn1 = sc.find(idx => idx.inning_number == 1);
            const inn2 = sc.find(idx => idx.inning_number == 2);

            const statsFooter = document.getElementById('comparisonStatsFooter');
            statsFooter.innerHTML = '';

            const createStatBox = (inning, title) => {
                if (!inning) return '';
                return `
                    <div class="comp-stat-box">
                        <div class="comp-stat-title">${title}</div>
                        <div class="comp-stat-main">${inning.runs}/${inning.wickets}</div>
                        <div class="comp-stat-sub">Overs: ${inning.overs} | Extras: ${inning.extras}</div>
                    </div>
                `;
            };

            // Determine Team Names & Colors for each Inning
            const getTeamInfo = (inn) => {
                if (!inn) return { name: '', color: '#ccc' };
                if (inn.batting_team === match.team1_name) {
                    return { name: match.team1_name, color: match.team1_color || '#0d6efd' };
                } else {
                    return { name: match.team2_name, color: match.team2_color || '#dc3545' };
                }
            };

            const t1 = getTeamInfo(inn1);
            const t2 = getTeamInfo(inn2);

            statsFooter.innerHTML = createStatBox(inn1, t1.name + ' (1st Inn)') + createStatBox(inn2, t2.name + ' (2nd Inn)');

            comparisonChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: t1.name + ' (1st Inn)',
                            data: inn1Data,
                            borderColor: t1.color,
                            backgroundColor: t1.color + '33',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 5,
                            pointBackgroundColor: t1.color,
                            pointHoverRadius: 8
                        },
                        ...(data.score.inning_number >= 2 ? [{
                            label: t2.name + ' (2nd Inn)',
                            data: inn2Data,
                            borderColor: t2.color,
                            backgroundColor: t2.color + '33',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 5,
                            pointBackgroundColor: t2.color,
                            pointHoverRadius: 8
                        }] : [])
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: 10
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Runs per Over', color: '#fff', font: { weight: 'bold' } },
                            ticks: { color: '#fff' },
                            grid: { color: 'rgba(255,255,255,0.1)' }
                        },
                        x: {
                            title: { display: true, text: 'Overs', color: '#fff', font: { weight: 'bold' } },
                            ticks: { color: '#fff' },
                            grid: { color: 'rgba(255,255,255,0.1)' }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#fff',
                                font: { size: 14 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return ` Over ${context.label}: ${context.raw} runs`;
                                }
                            }
                        }
                    }
                }
            });

            if (!skipAudio) audioManager.play('graph');
            if (done) persistentDoneCallback = done;
        }

        let runsWicketsChartInstance = null;

        function showRunsWicketsGraphOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('graphRunsWicketsOverlay');
            overlay.style.display = 'flex';
            setTimeout(() => overlay.classList.add('show'), 10);

            const match = data.match_info;
            const score = data.score;
            const stats = data.overwise_stats;

            // Header Setup
            // Simplified logic: strict check on inning number
            let batLogo = '../assets/images/default-team.png';
            let bowlLogo = '../assets/images/default-team.png';

            if (score.inning_number == 1) {
                batLogo = match.team1_logo ? `../uploads/teams/${match.team1_logo}` : batLogo;
                bowlLogo = match.team2_logo ? `../uploads/teams/${match.team2_logo}` : bowlLogo;
            } else {
                batLogo = match.team2_logo ? `../uploads/teams/${match.team2_logo}` : batLogo;
                bowlLogo = match.team1_logo ? `../uploads/teams/${match.team1_logo}` : bowlLogo;
            }

            document.getElementById('grwBatTeamLogo').src = batLogo;
            document.getElementById('grwBowlTeamLogo').src = bowlLogo;

            // Footer Data
            document.getElementById('grwExtras').innerText = score.extras || 0;
            document.getElementById('grwOvers').innerText = `${score.overs} / ${score.total_overs}`;
            document.getElementById('grwScore').innerText = `${score.runs}/${score.wickets}`;

            // Graph Data Processing
            const ctx = document.getElementById('runsWicketsChart').getContext('2d');
            const totalOvers = score.total_overs || 20;
            const labels = Array.from({ length: totalOvers }, (_, i) => i + 1);

            // Filter stats for CURRENT innings only
            const currentInnStats = (stats && stats['inning' + score.inning_number]) ? stats['inning' + score.inning_number] : [];

            // Map data to overs
            const runsData = labels.map(l => {
                const found = currentInnStats.find(ov => parseInt(ov.over_number) == (l - 1));
                return found ? parseFloat(found.runs) : null;
            });

            const wicketsData = labels.map(l => {
                const found = currentInnStats.find(ov => parseInt(ov.over_number) == (l - 1));
                return found ? parseInt(found.wickets) : 0;
            });

            // Determine color based on inning (Team 1 color for Inn 1, Team 2 for Inn 2)
            const teamColor = (score.inning_number == 1) ? (match.team1_color || '#0d6efd') : (match.team2_color || '#dc3545');

            if (runsWicketsChartInstance) {
                // Update existing chart
                runsWicketsChartInstance.data.labels = labels;
                runsWicketsChartInstance.data.datasets[0].data = runsData;
                runsWicketsChartInstance.data.datasets[0].backgroundColor = teamColor;
                // We also need to update the wicketsData for the plugin, but there is no direct way to pass it to the plugin
                // except attaching it to the chart instance or dataset.
                runsWicketsChartInstance.data.datasets[0].wicketsData = wicketsData;
                runsWicketsChartInstance.update();
            } else {
                // Create new chart
                runsWicketsChartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Runs',
                            data: runsData,
                            backgroundColor: teamColor,
                            borderRadius: 4,
                            barPercentage: 0.6,
                            wicketsData: wicketsData // Custom property for plugin
                        }]
                    },
                    options: {
                        animation: false, // Disable animation for live updates
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: { padding: { top: 20 } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: { display: true, text: 'Runs', color: '#333', font: { weight: 'bold' } },
                                grid: { color: 'rgba(0,0,0,0.05)' }
                            },
                            x: {
                                title: { display: true, text: 'Overs', color: '#333', font: { weight: 'bold' } },
                                grid: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        // Access wicketsData from dataset
                                        const wkts = context.dataset.wicketsData[context.dataIndex];
                                        return `${context.raw} runs${wkts > 0 ? `, ${wkts} wkt(s)` : ''}`;
                                    }
                                }
                            },
                            datalabels: { display: false }
                        }
                    },
                    plugins: [{
                        id: 'wicketLabels',
                        afterDatasetsDraw: (chart) => {
                            const { ctx } = chart;
                            chart.data.datasets.forEach((dataset, i) => {
                                const meta = chart.getDatasetMeta(i);
                                // Access wicketsData from dataset
                                const wData = dataset.wicketsData || [];
                                meta.data.forEach((bar, index) => {
                                    const wkts = wData[index];
                                    // Make sure we have a valid x/y and run count
                                    // Draw if wickets > 0 OR if the user wants it for every over (user said "Bar top you can give wicket count")
                                    // User image shows a box on top. Let's just draw the number.
                                    if (wkts > 0 && bar && bar.x && bar.y) {
                                        ctx.fillStyle = '#dc3545'; // Red color for wicket count
                                        ctx.font = 'bold 12px sans-serif';
                                        ctx.textAlign = 'center';
                                        // Draw Text slightly above the bar
                                        // If bar.y is the top of the bar, we draw above it.
                                        ctx.fillText(wkts + 'W', bar.x, bar.y - 5);
                                    }
                                });
                            });
                        }
                    }]
                });
            }

            if (!skipAudio) audioManager.play('bar_graph');
            if (done) persistentDoneCallback = done;
        }

        function rowBat(pl, isStr, teamName) {
            const bStats = pl.match_stats || { runs_scored: 0, balls_faced: 0, fours: 0, sixes: 0, strike_rate: 0 };
            const playerJson = JSON.stringify(pl).replace(/"/g, '&quot;');
            return `
            <tr>
                <td class="player-cell" style="display:flex; align-items:center; cursor:pointer;" onclick="openPlayerCard(${playerJson}, '${teamName}')">
                    <img src="../uploads/users/${(pl && pl.profile_image) || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                    <div>
                        <div style="font-weight:600">${(pl && pl.name && pl.name.trim() !== '') ? pl.name : ('Player ' + (pl.id || ''))} ${isStr ? '<span class="text-primary">★</span>' : ''}</div>
                        ${isStr ? '<small class="text-primary fw-bold">Striker</small>' : '<small class="text-muted">Non-Striker</small>'}
                    </div>
                </td>
                <td class="highlight">${bStats.runs_scored || 0}</td>
                <td>${bStats.balls_faced || 0}</td>
                <td>${bStats.fours || 0}</td>
                <td>${bStats.sixes || 0}</td>
                <td>${parseFloat(bStats.strike_rate || 0).toFixed(1)}</td>
            </tr>
        `;
        }

        function renderScorecardTab(data) {
            const scorecard = data.scorecard;
            const container = document.getElementById('scorecard');

            if (!scorecard || scorecard.length === 0) {
                container.innerHTML = '<div class="text-center py-5">Scorecard will be available once the match starts.</div>';
                return;
            }

            // Keep track of which sections are open
            const openSections = new Set();
            document.querySelectorAll('.sc-innings-header.active').forEach(h => {
                openSections.add(h.id);
            });

            container.innerHTML = '';

            scorecard.forEach(inn => {
                const innNum = inn.inning_number;
                const headerId = `sc-header-${innNum}`;
                const contentId = `sc-content-${innNum}`;
                const isActive = openSections.has(headerId) || (innNum == data.score.inning_number);
                const innDiv = document.createElement('div');
                innDiv.className = 'innings-wrapper mb-3';

                // Correct team logo logic
                const mInfo = data.match_info;
                const currentBattingLogo = (inn.batting_team === mInfo.team1_name) ? mInfo.team1_logo : mInfo.team2_logo;

                innDiv.innerHTML = `
                        <div id="${headerId}" class="sc-innings-header ${isActive ? 'active' : ''} ${innNum >= 3 ? 'super-over' : ''}" onclick="toggleInnings(${innNum})">
                            <div class="sc-innings-info">
                                <span style="font-weight:600; color:var(--text-secondary);">
                                    ${innNum == 1 ? '1st Innings' : (innNum == 2 ? '2nd Innings' : (innNum == 3 ? 'Super Over 1st Innings' : 'Super Over 2nd Innings'))} –
                                </span>
                            <img src="../uploads/teams/${currentBattingLogo}" class="sc-team-logo" style="width: 40px; height: 40px; object-fit: contain; margin: 0 10px;" onerror="this.src='../assets/images/default-team.png'">
                            <span class="sc-team-name">${inn.batting_team}</span>
                            <span class="sc-score">${inn.runs}/${inn.wickets}</span>
                        </div>
                        <i class="fas fa-chevron-down sc-arrow"></i>
                    </div>
                    
                    <div id="${contentId}" class="sc-innings-content ${isActive ? 'show' : ''}">
                        <div class="table-container mb-4">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Batter</th>
                                        <th>R</th>
                                        <th>B</th>
                                        <th>4s</th>
                                        <th>6s</th>
                                        <th>SR</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${inn.batting.map(r => {
                    const playerJson = JSON.stringify(r).replace(/"/g, '&quot;');
                    return `
                                        <tr>
                                            <td class="player-cell" onclick="openPlayerCard(${playerJson}, '${inn.batting_team}')" style="cursor:pointer;">
                                                <div style="display:flex; align-items:center;">
                                                    <img src="../uploads/users/${r.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                                    <div>
                                                        <div style="font-weight:600">${r.name || ('Player ' + (r.player_id || ''))}</div>
                                                        <div style="font-size:0.75rem; color:var(--text-secondary);">${r.dismissal || ''}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="highlight">${r.runs_scored !== undefined ? r.runs_scored : 0}</td>
                                            <td>${r.balls_faced !== undefined ? r.balls_faced : 0}</td>
                                            <td>${r.fours !== undefined ? r.fours : 0}</td>
                                            <td>${r.sixes !== undefined ? r.sixes : 0}</td>
                                            <td>${(r.strike_rate !== undefined && !isNaN(parseFloat(r.strike_rate))) ? parseFloat(r.strike_rate).toFixed(1) : '0.0'}</td>
                                        </tr>
                                    `}).join('')}
                                </tbody>
                            </table>
                            <div style="padding:15px; font-weight:bold; background:#f9f9f9; border-top:1px solid #ddd; display:flex; justify-content:space-between;">
                                <span>Extras: ${inn.extras}</span>
                                <span>Total: ${inn.runs}/${inn.wickets} (${inn.overs} ov)</span>
                            </div>
                        </div>

                        <div class="table-container">
                            <h4 style="padding:15px; color:var(--danger); background:#f8f9fa; border-bottom:1px solid #eee;">
                                <i class="fas fa-baseball-ball me-2"></i> Bowling
                            </h4>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Bowler</th>
                                        <th>O</th>
                                        <th>R</th>
                                        <th>W</th>
                                        <th>Econ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${inn.bowling.map(r => {
                        const playerJson = JSON.stringify(r).replace(/"/g, '&quot;');
                        return `
                                        <tr>
                                            <td class="player-cell" style="display:flex; align-items:center; cursor:pointer;" onclick="openPlayerCard(${playerJson}, '${inn.bowling_team}')">
                                                 <img src="../uploads/users/${r.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                                 <span style="font-weight:600">${r.name}</span>
                                            </td>
                                            <td>${r.overs_bowled}</td>
                                            <td>${r.runs_conceded}</td>
                                            <td class="highlight">${r.wickets_taken}</td>
                                            <td>${parseFloat(r.economy_rate).toFixed(2)}</td>
                                        </tr>
                                    `}).join('')}
                                </tbody>
                            </table>
                        </div>

                        <!-- Fall of Wickets Section -->
                        <div class="fow-section">
                            <div class="fow-title">Fall of Wickets</div>
                            <div class="fow-grid">
                                ${(inn.fall_of_wickets || []).map((w, index) => `
                                    <div class="fow-item">
                                        <div class="fow-num">${index + 1}</div>
                                        <img src="../uploads/users/${w.profile_image || ''}" class="fow-player-img" onerror="this.src='../assets/images/default-player.png'">
                                        <div>
                                            <div style="font-weight:600;">${w.player_name}</div>
                                            <div style="font-size:0.75rem; color:#888;">${w.score} (${w.over} ov)</div>
                                        </div>
                                    </div>
                                `).join('')}
                                ${(!inn.fall_of_wickets || inn.fall_of_wickets.length === 0) ? '<div style="color:#888; font-style:italic;">No wickets fallen yet.</div>' : ''}
                            </div>
                        </div>
                    </div >

                `;
                container.appendChild(innDiv);
            });
        }

        function renderTeamsTab(data) {
            const currentSquadsStr = JSON.stringify(data.squads);
            if (currentSquadsStr === lastSquadsStr) return;
            lastSquadsStr = currentSquadsStr;

            // 1. Teams & Toss
            const mInfo = data.match_info;

            const updateTeamHeader = (id, name, code, logo) => {
                const el = document.getElementById(id);
                if (el) {
                    el.innerHTML = `
                        <div style="display:flex; align-items:center; padding:15px; border-bottom:1px solid #eee;">
                            <img src="../uploads/teams/${logo}" style="width:50px; height:50px; object-fit:contain; margin-right:15px; border-radius: 50%;" onerror="this.src='../assets/images/default-team.png'">
                            <div>
                                <div style="font-size:1.2rem; font-weight:700; color:var(--text-primary); line-height:1.2;">${name}</div>
                                <div style="font-size:0.9rem; color:var(--text-secondary); font-weight:600;">${code}</div>
                            </div>
                        </div>
                    `;
                }
            };

            updateTeamHeader('team1Name', mInfo.team1_name, mInfo.team1_code, mInfo.team1_logo);
            updateTeamHeader('team2Name', mInfo.team2_name, mInfo.team2_code, mInfo.team2_logo);

            const rendSq = (sq, teamName) => {
                // Simplified Role Priority
                const getRoleVal = (r) => {
                    if (!r) return 99;
                    const role = r.toLowerCase().trim();
                    if (role.includes('bat')) return 1; // Batsman, Batter
                    if (role.includes('wicket') || role.includes('wk') || role.includes('keeper')) return 2; // Wicket Keeper
                    if (role.includes('all') || role.includes('rounder')) return 3; // All Rounder
                    if (role.includes('bowl')) return 4; // Bowler
                    return 99;
                };

                const sortedSq = [...sq].sort((a, b) => {
                    // 1. Captain first
                    if (a.is_captain && !b.is_captain) return -1;
                    if (!a.is_captain && b.is_captain) return 1;

                    // 2. Vice-Captain second
                    if (a.is_vc && !b.is_vc) return -1;
                    if (!a.is_vc && b.is_vc) return 1;

                    // 3. Role Priority
                    const rA = getRoleVal(a.playing_role);
                    const rB = getRoleVal(b.playing_role);
                    if (rA !== rB) return rA - rB;

                    // 4. Alphabetical Name (Stability)
                    return a.name.localeCompare(b.name);
                });

                return sortedSq.map(p => {
                    const playerJson = JSON.stringify(p).replace(/"/g, '&quot;');
                    let badge = '';
                    if (p.is_captain) badge = '<span class="badge bg-primary ms-2" style="font-size:0.7em;">(C)</span>';
                    else if (p.is_vc) badge = '<span class="badge bg-secondary ms-2" style="font-size:0.7em;">(VC)</span>';

                    return `
                <div style="padding:10px; border-bottom:1px solid #eee; display:flex; align-items:center; cursor:pointer;" onclick="openPlayerCard(${playerJson}, '${teamName}')">
                    <img src="../uploads/users/${p.profile_image}" onerror="this.src='../assets/images/default-player.png'" style="width:40px; height:40px; border-radius:50%; margin-right:15px; object-fit: cover;">
                    <div>
                        <div style="font-weight:600;">${p.name} ${badge}</div>
                        <div style="font-size:0.8rem; color:#888;">${p.playing_role}</div>
                    </div>
                </div>
            `;
                }).join('');
            };

            document.getElementById('team1List').innerHTML = rendSq(data.squads.team1, data.match_info.team1_name);
            document.getElementById('team2List').innerHTML = rendSq(data.squads.team2, data.match_info.team2_name);
        }

        function checkPopups(data) {
            const p = data.current_players;
            const m = data.match_info;

            // 1. Striker Change (Batter Enters)
            if (p.striker && p.striker.id && p.striker.id != lastStrikerId) {
                if (!introducedPlayers.has(`pop_${p.striker.id}`)) {
                    overlayManager.add('entry', `p_${p.striker.id}`, 5, (done) => {
                        showPopup(p.striker, m.batting_team_name, true, done);
                    });
                    introducedPlayers.add(`pop_${p.striker.id}`);
                }
                lastStrikerId = p.striker.id;
            }

            // 2. Bowler Change (Over Starts)
            const currentOver = Math.floor(parseFloat(data.score.overs));
            if (p.bowler && p.bowler.id && currentOver != lastOverNum) {
                overlayManager.add('bowler_change', `b_${p.bowler.id}_${currentOver}`, 5, (done) => {
                    showPopup(p.bowler, m.bowling_team_name, false, done);
                });
                lastOverNum = currentOver;
                lastBowlerId = p.bowler.id;
            }
        }

        function showPopup(player, teamName, isBatter, done = null) {
            const pop = document.getElementById('playerPopup');

            // Ensure display is flex (might have been 'none' from undo sync)
            pop.style.display = 'flex';

            const profileImg = player.profile_image ? `../uploads/users/${player.profile_image}` : '../assets/images/default-player.png';
            document.getElementById('ppImg').src = profileImg;
            document.getElementById('ppImg').onerror = function () { this.src = '../assets/images/default-player.png'; };
            document.getElementById('ppName').innerHTML = player.name + (isBatter ? ' <span class="text-primary">★</span>' : '');
            document.getElementById('ppRole').innerText = isBatter ? `${teamName} (Striker)` : `${teamName} (Current Bowler)`;

            document.getElementById('ppMat').innerText = player.matches;
            document.getElementById('ppRun').innerText = player.runs;
            document.getElementById('ppWkt').innerText = player.wickets;

            pop.classList.add('show');
            setTimeout(() => {
                pop.classList.remove('show');
                // Small delay before marking done to let it fully fade out
                setTimeout(() => {
                    if (done) done();
                }, 300);
            }, 3000);
        }

        function showRunPopup(val, ballObj = null, data = null, done = null) {
            const popup = document.getElementById('runPopup');
            const numberEl = document.getElementById('runNumber');
            const ringEl = document.getElementById('runRing');

            // Ensure display is flex (might have been 'none' from undo sync)
            popup.style.display = 'flex';

            // Safety: Hide player detail card if it's open, to focus on the ball event
            closePlayerCard();

            // Reset classes for animation
            popup.classList.remove('show', 'run-popup-active');
            numberEl.className = 'run-number';
            ringEl.className = 'run-ring';

            // Force reflow to allow re-triggering animation
            void popup.offsetWidth;

            numberEl.textContent = val;
            let colorCls = '0';
            let audioKey = null;

            let doConfetti = false;

            // Normalize val for comparison
            const nVal = (typeof val === 'string') ? val.toUpperCase() : val;

            if (nVal === 'WD' || nVal === 'NB') {
                colorCls = 'W'; // Red style
                if (nVal === 'WD') {
                    audioKey = 'wide';
                } else {
                    audioKey = 'danger';
                }
            } else if (nVal == 4) {
                colorCls = '4'; // Green
                audioKey = 'batHit';
                doConfetti = true;
            } else if (nVal == 6) {
                colorCls = '6'; // Purple
                audioKey = 'batHit';
                doConfetti = true;
            } else if (nVal === 'W') {
                colorCls = 'W';
                audioKey = 'wicket';
                // Check for duck out
                if (ballObj && data) {
                    const currentInning = data.score.inning_number;
                    const scorecard = data.scorecard.find(i => i.inning_number == currentInning);
                    if (scorecard) {
                        const dismissedBatter = scorecard.batting.find(b => b.name === ballObj.batter_name || b.player_id === ballObj.batsman_id);
                        if (dismissedBatter && dismissedBatter.runs_scored == 0) {
                            // Duck Out is Priority 2, but Run Popup (W) is shown first
                            // We can push Duck Out to queue here
                            overlayManager.add('duck', ballObj.id, 2, (duckDone) => {
                                showDuckOutPopup(dismissedBatter, duckDone);
                            });
                            audioKey = 'duck';
                        }
                    }
                }
            } else {
                colorCls = '0';
            }

            numberEl.classList.add('run-' + colorCls);
            ringEl.classList.add('run-' + colorCls);

            // Handle run-6 purple explicitly
            if (nVal == 6) {
                numberEl.style.color = '#9B59B6';
                ringEl.style.borderColor = 'rgba(155, 89, 182, 0.5)';
            } else {
                numberEl.style.color = '';
                ringEl.style.borderColor = '';
            }

            popup.classList.add('show', 'run-popup-active');

            if (audioKey) {
                audioManager.play(audioKey);
            }

            if (doConfetti) {
                createConfettiBlast();
            }

            setTimeout(() => {
                popup.classList.remove('show', 'run-popup-active');
                if (done) done();
            }, (nVal === 'W' ? 4000 : 1200));
        }

        function showDuckOutPopup(batter, done = null, duration = 2000) {
            const duckPopup = document.getElementById('duckOutPopup');
            document.getElementById('duckPlayerName').innerText = batter.name;
            document.getElementById('duckScore').innerText = `0(0)`;

            // Audio
            audioManager.play('duck');
            createConfettiBlast();

            duckPopup.style.display = 'flex';
            setTimeout(() => {
                duckPopup.style.display = 'none';
                if (done) done();
            }, duration);
        }

        function showWicketPopup(batterName, batterImg, runs, balls, done = null, duration = 4000, playSound = true) {
            const popup = document.getElementById('wicketPopup');
            document.getElementById('wicketPlayerName').innerText = batterName;
            document.getElementById('wicketStats').innerText = `${runs} (${balls})`;

            const imgEl = document.getElementById('wicketPlayerImg');
            imgEl.src = batterImg ? `../uploads/users/${batterImg}` : '../assets/images/default-player.png';
            imgEl.onerror = function () { this.src = '../assets/images/default-player.png'; };

            if (playSound) {
                audioManager.play('wicket');
            }
            createConfettiBlast();

            popup.style.display = 'flex';
            setTimeout(() => {
                popup.style.display = 'none';
                if (done) done();
            }, duration);
        }

        function createConfettiBlast() {
            const container = document.createElement('div');
            container.className = 'paper-blast';
            document.body.appendChild(container);

            const colors = ['#f1c40f', '#e67e22', '#e74c3c', '#3498db', '#2ecc71', '#9b59b6'];
            for (let i = 0; i < 50; i++) {
                const conf = document.createElement('div');
                conf.className = 'confetti';
                conf.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];

                const tx = (Math.random() - 0.5) * 400 + 'px';
                const ty = (Math.random() - 0.5) * 400 + 'px';
                const tr = Math.random() * 360 + 'deg';

                conf.style.setProperty('--tx', tx);
                conf.style.setProperty('--ty', ty);
                conf.style.setProperty('--tr', tr);
                conf.style.animation = `confettiBlast 1s ease - out forwards`;

                container.appendChild(conf);
            }

            setTimeout(() => container.remove(), 1200);
        }

        function clearLiveUI() {
            document.getElementById('liveScoreSummary').innerHTML = '';
            document.getElementById('commentaryFeed').innerHTML = '';
            document.querySelector('#liveBattingTable tbody').innerHTML = '';
            document.querySelector('#liveBowlingTable tbody').innerHTML = '';
            document.getElementById('liveBubbles').innerHTML = '';
            introducedPlayers.clear();
            summarizedOvers.clear();
            lastBallId = 0;
            lastOverNum = -1;
            lastBowlerId = null;
        }

        function renderStoppedState() {
            document.getElementById('headerContent').innerHTML = `
                <div style="text-align:center; width:100%; padding:40px;">
                    <i class="fas fa-pause-circle fa-4x text-muted mb-3"></i>
                    <h3>No matches are live now</h3>
                    <p class="text-muted">This match has been stopped</p>
                    <a href="../NavBarList/matches.php" class="btn btn-primary mt-3">Back to Matches</a>
                </div>
            `;
        }

        function handleInningsBreak(data) {
            const overlay = document.getElementById('inningsBreakOverlay');
            const isBreak = data.score.is_break;
            const currentInning = data.score.inning_number;
            const isFinished = data.score.is_finished;

            // Auto-open if it's a break and we are in 1st or 3rd innings
            if (isBreak && (currentInning == 1 || currentInning == 3)) {
                if (overlay.style.display !== 'flex' && overlay.style.display !== 'block') {
                    // Use OverlayManager to queue it after any wickets/runs (Priority 10 = Lowest)
                    overlayManager.add('innings_break', `break_${currentInning}`, 10, (done) => {
                        showInningsBreak(data, done);
                    });
                }
            } else if (isFinished && (currentInning == 2 || currentInning == 4)) {
                if (overlay.style.display === 'flex' || overlay.style.display === 'block') {
                    hideInningsHeader();
                }
            } else {
                // Auto-close if next innings started OR if no break
                // BUT: Wait until breakShowingUntil time has passed
                if (Date.now() >= breakShowingUntil) {
                    if (overlay.style.display === 'flex' || overlay.style.display === 'block') {
                        hideInningsHeader();
                    }
                }
            }
        }

        let inningsBreakCallback = null;
        function showInningsBreak(data, done = null) {
            const m = data.match_info;
            const s = data.score;
            const sc = data.scorecard;

            if (done) inningsBreakCallback = done;

            // Set minimum display time (8 seconds)
            breakShowingUntil = Date.now() + 8000;

            // Find the current innings data in the scorecard array instead of hardcoding 1
            const currentInnData = (sc && sc.length > 0) ? (sc.find(i => i.inning_number == s.inning_number) || sc[sc.length - 1]) : null;
            if (!currentInnData) return;

            document.getElementById('ibLogoImg').src = `../uploads/teams/${m.batting_team_logo}`;
            document.getElementById('ibTeamName').innerText = m.batting_team_name;
            document.getElementById('ibFinalScore').innerHTML = `${s.runs}<span style="color:#ffcccc; font-size: 3.5rem;">/${s.wickets}</span>`;
            document.getElementById('ibOvers').innerText = s.overs;
            document.getElementById('ibExtras').innerText = (currentInnData.extras !== undefined) ? currentInnData.extras : 0;
            document.getElementById('ibExtrasBreakdown').innerText = currentInnData.extras_breakdown || '';
            document.getElementById('ibTarget').innerText = s.target || (s.runs + 1);

            // Hide target box if 0 (e.g. Inning 1 or Super Over Inning 3) - we assume 0 or undefined means no target yet
            // If it's SO Inning 3, we specifically want to wait for Inning 4 to show the target.
            const targetBox = document.querySelector('.ib-target-box');
            if (s.inning_number == 1 || s.inning_number == 3) {
                if (targetBox) targetBox.style.display = 'none';
            } else {
                if (targetBox) targetBox.style.display = 'flex';
            }

            // Adjust labels for Super Over
            const overlay = document.getElementById('inningsBreakOverlay');
            const subLabel = overlay.querySelector('.innings-label');
            const mainLabel = overlay.querySelector('.ib-innings-title');
            const nextInnDesc = overlay.querySelector('div[style*="font-size: 1.1rem"]');

            if (s.inning_number >= 3) {
                if (subLabel) subLabel.innerHTML = '<span class="badge bg-warning text-dark me-2">Super Over</span> Finished';
                if (mainLabel) mainLabel.innerText = 'Super Over Break';
                if (nextInnDesc) nextInnDesc.innerHTML = (s.inning_number == 3)
                    ? '<strong style="color:var(--accent);">Super Over:</strong> 2nd Innings will start soon'
                    : 'Match Finished';
                overlay.querySelector('.ib-header').style.background = 'linear-gradient(135deg, #4b0082 0%, #673ab7 100%)';
            } else {
                const chasingTeam = (m.batting_team_name === m.team1_name) ? m.team2_name : m.team1_name;
                const totalBalls = (s.total_overs || 20) * 6;
                const targetScore = s.target || (s.runs + 1);

                if (subLabel) subLabel.innerText = '1st Innings Completed';
                if (mainLabel) mainLabel.innerText = `${m.team1_name} vs ${m.team2_name}`;
                if (nextInnDesc) nextInnDesc.innerHTML = `<strong style="color:var(--accent);">${chasingTeam}</strong> needs <strong>${targetScore}</strong> runs in <strong>${totalBalls}</strong> balls`;

                overlay.querySelector('.ib-header').style.background = ''; // Reset to default
            }

            // Batting Summary
            const tbody = document.getElementById('ibBattingBody');
            tbody.innerHTML = '';
            if (currentInnData.batting) {
                currentInnData.batting.forEach(p => {
                    tbody.innerHTML += `
                        <tr>
                            <td style="display:flex; align-items:center;">
                                <img src="../uploads/users/${p.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                <div>
                                    <div style="font-weight:600;">${p.name || 'Unknown'}</div>
                                    <div style="font-size:0.75rem; color:#888;">${p.dismissal || ''}</div>
                                </div>
                            </td>
                            <td class="fw-bold">${p.runs_scored || 0}</td>
                            <td>${p.balls_faced || 0}</td>
                            <td>${p.fours || 0}</td>
                            <td>${p.sixes || 0}</td>
                            <td>${parseFloat(p.strike_rate || 0).toFixed(1)}</td>
                        </tr>
                    `;
                });
            }

            // Bowling Summary
            let bowlHtml = `
                <h4 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin: 25px 0 15px 0;">Bowling Summary</h4>
                <div class="table-container" style="box-shadow: none; border: 1px solid #eee;">
                    <table>
                        <thead>
                            <tr>
                                <th>Bowler</th>
                                <th>O</th>
                                <th>R</th>
                                <th>W</th>
                                <th>Econ</th>
                            </tr>
                        </thead>
                        <tbody>
                            `;

            if (currentInnData.bowling) {
                currentInnData.bowling.forEach(b => {
                    bowlHtml += `
                        <tr>
                            <td style="display:flex; align-items:center;">
                                <img src="../uploads/users/${b.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                <span>${b.name || 'Unknown'}</span>
                            </td>
                            <td>${b.overs_bowled}</td>
                            <td>${b.runs_conceded}</td>
                            <td class="fw-bold text-danger">${b.wickets_taken}</td>
                            <td>${parseFloat(b.economy_rate).toFixed(2)}</td>
                        </tr>
                    `;
                });
            }
            bowlHtml += '</tbody></table></div>';

            // Append bowling summary if not already there
            const ibBody = document.querySelector('.ib-body');
            const existingBowl = ibBody.querySelector('.bowling-summary-section');
            if (existingBowl) existingBowl.remove();

            const bowlDiv = document.createElement('div');
            bowlDiv.className = 'bowling-summary-section';
            bowlDiv.innerHTML = bowlHtml;
            ibBody.appendChild(bowlDiv);

            document.getElementById('inningsBreakOverlay').style.display = 'block';
        }

        function hideInningsHeader() {
            document.getElementById('inningsBreakOverlay').style.display = 'none';
            if (inningsBreakCallback) {
                const callback = inningsBreakCallback;
                inningsBreakCallback = null;
                try { callback(); } catch (e) { console.error("Error in innings break callback:", e); }
            }
        }
        let lastFaviconUrl = '';
        function setCircularFavicon(url) {
            if (lastFaviconUrl === url) return;
            lastFaviconUrl = url;

            const canvas = document.createElement('canvas');
            canvas.width = 64;
            canvas.height = 64;
            const ctx = canvas.getContext('2d');
            const img = new Image();
            img.onload = function () {
                ctx.clearRect(0, 0, 64, 64);
                ctx.beginPath();
                ctx.arc(32, 32, 32, 0, Math.PI * 2);
                ctx.clip();
                ctx.drawImage(img, 0, 0, 64, 64);

                let link = document.querySelector("link[rel~='icon']");
                if (!link) {
                    link = document.createElement('link');
                    link.rel = 'icon';
                    document.head.appendChild(link);
                }
                link.href = canvas.toDataURL('image/png');
            };
            img.src = url;
        }

        function updatePageMeta(data) {
            const m = data.match_info;
            const s = data.score;
            const isFirstInning = s.inning == 1;

            // Determine Batting Team
            let battingTeamCode = '';
            let battingTeamLogo = '';

            // Simplifed Logic using Name matching (more reliable if IDs are tricky)
            if (m.batting_team_name === m.team1_name) {
                battingTeamCode = m.team1_code || m.team1_name.substring(0, 3).toUpperCase();
                battingTeamLogo = m.team1_logo;
            } else {
                battingTeamCode = m.team2_code || m.team2_name.substring(0, 3).toUpperCase();
                battingTeamLogo = m.team2_logo;
            }

            // Construct Title: CODE - Runs/Wickets (Overs/Total)
            // Example: MI - 120/3 (14.2/20)
            const title = `${battingTeamCode} – ${s.runs}/${s.wickets} (${s.overs}/${s.total_overs})`;

            if (document.title !== title) {
                document.title = title;
            }

            // Update Favicon (Circular)
            const newIconPath = `../uploads/teams/${battingTeamLogo || 'default-team.png'}`;
            setCircularFavicon(newIconPath);
        }

        function renderScorecardTabNew(data) {
            const scorecard = data.scorecard;
            const container = document.getElementById('scorecard');

            if (!scorecard || scorecard.length === 0) {
                if (container.innerHTML.trim() === '' || container.querySelector('.text-center')) {
                    container.innerHTML = '<div class="text-center py-5">Scorecard will be available once the match starts.</div>';
                }
                return;
            }

            // Remove placeholder
            if (container.querySelector('.text-center')) {
                container.innerHTML = '';
            }

            scorecard.forEach(inn => {
                const innNum = inn.inning_number;
                const wrapperId = `sc-wrapper-${innNum}`;
                const headerId = `sc-header-${innNum}`;
                const contentId = `sc-content-${innNum}`;

                let wrapper = document.getElementById(wrapperId);
                const mInfo = data.match_info;
                const currentBattingLogo = (inn.batting_team === mInfo.team1_name) ? mInfo.team1_logo : mInfo.team2_logo;

                if (!wrapper) {
                    // Create Structure
                    wrapper = document.createElement('div');
                    wrapper.id = wrapperId;
                    wrapper.className = 'innings-wrapper mb-3';

                    const isActive = (innNum == data.score.inning_number);

                    wrapper.innerHTML = `
                        <div id="${headerId}" class="sc-innings-header ${isActive ? 'active' : ''} ${innNum >= 3 ? 'super-over' : ''}" onclick="toggleInnings(${innNum})">
                            <div class="sc-header-left">
                                <img id="sc-logo-${innNum}" src="../uploads/teams/${currentBattingLogo}" class="sc-team-logo" onerror="this.src='../assets/images/default-team.png'">
                                <div class="sc-innings-label">
                                    ${innNum == 1 ? '1st Innings' : (innNum == 2 ? '2nd Innings' : (innNum == 3 ? 'Super Over 1st Innings' : 'Super Over 2nd Innings'))}
                                </div>
                            </div>
                            <div class="sc-header-middle">
                                <span id="sc-team-${innNum}" class="sc-team-name">${inn.batting_team}</span>
                            </div>
                            <div class="sc-header-right">
                                <div id="sc-score-${innNum}" class="sc-score">${inn.runs}/${inn.wickets}</div>
                                <div id="sc-overs-${innNum}" class="sc-overs">(${inn.overs} ov)</div>
                            </div>
                            <i class="fas fa-chevron-down sc-arrow"></i>
                        </div>
                        
                        <div id="${contentId}" class="sc-innings-content ${isActive ? 'show' : ''}">
                            <div class="table-container mb-4">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Batter</th>
                                            <th>R</th>
                                            <th>B</th>
                                            <th>4s</th>
                                            <th>6s</th>
                                            <th>SR</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sc-bat-tbody-${innNum}"></tbody>
                                </table>
                                <div id="sc-footer-${innNum}" style="padding:15px; font-weight:bold; background:#f9f9f9; border-top:1px solid #ddd; display:flex; justify-content:space-between;">
                                    <span>Extras: ${inn.extras}</span>
                                    <span>Total: ${inn.runs}/${inn.wickets} (${inn.overs} ov)</span>
                                </div>
                            </div>

                            <div class="table-container">

                                <table>
                                    <thead>
                                        <tr>
                                            <th>Bowler</th>
                                            <th>O</th>
                                            <th>R</th>
                                            <th>W</th>
                                            <th>Econ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sc-bowl-tbody-${innNum}"></tbody>
                                </table>
                            </div>

                            <!-- Fall of Wickets -->
                            <div class="fow-section">
                                <div class="fow-title">Fall of Wickets</div>
                                <div id="sc-fow-grid-${innNum}" class="fow-grid"></div>
                            </div>
                        </div>
                    `;
                    container.appendChild(wrapper);
                } else {
                    // Update Header Score & Overs
                    const scoreEl = document.getElementById(`sc-score-${innNum}`);
                    if (scoreEl) scoreEl.textContent = `${inn.runs}/${inn.wickets}`;
                    const oversEl = document.getElementById(`sc-overs-${innNum}`);
                    if (oversEl) oversEl.textContent = `(${inn.overs} ov)`;

                    // Update Footer
                    const footerEl = document.getElementById(`sc-footer-${innNum}`);
                    if (footerEl) footerEl.innerHTML = `
                        <span>Extras: ${inn.extras}</span>
                        <span>Total: ${inn.runs}/${inn.wickets} (${inn.overs} ov)</span>
                    `;
                }

                // Update Batting Table
                const batTbody = document.getElementById(`sc-bat-tbody-${innNum}`);
                if (batTbody) {
                    inn.batting.forEach(r => {
                        const rowId = `sc-bat-row-${innNum}-${r.player_id}`;
                        let row = document.getElementById(rowId);
                        const playerJson = JSON.stringify(r).replace(/"/g, '&quot;');

                        const cellsHTML = `
                            <td class="player-cell" onclick="openPlayerCard(${playerJson}, '${inn.batting_team}')" style="cursor:pointer;">
                                <div style="display:flex; align-items:center;">
                                    <img src="../uploads/users/${r.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                    <div>
                                        <div style="font-weight:600">${r.name || ('Player ' + (r.player_id || ''))}</div>
                                        <div style="font-size:0.75rem; color:var(--text-secondary);">${r.dismissal || ''}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="highlight">${r.runs_scored !== undefined ? r.runs_scored : 0}</td>
                            <td>${r.balls_faced !== undefined ? r.balls_faced : 0}</td>
                            <td>${r.fours !== undefined ? r.fours : 0}</td>
                            <td>${r.sixes !== undefined ? r.sixes : 0}</td>
                            <td>${(r.strike_rate !== undefined && !isNaN(parseFloat(r.strike_rate))) ? parseFloat(r.strike_rate).toFixed(1) : '0.0'}</td>
                        `;

                        if (row) {
                            if (row.innerHTML !== cellsHTML) row.innerHTML = cellsHTML;
                        } else {
                            row = document.createElement('tr');
                            row.id = rowId;
                            row.innerHTML = cellsHTML;
                            batTbody.appendChild(row);
                        }
                    });
                }

                // Update Bowling Table
                const bowlTbody = document.getElementById(`sc-bowl-tbody-${innNum}`);
                if (bowlTbody) {
                    const bowlingTeamName = (inn.batting_team === mInfo.team1_name) ? mInfo.team2_name : mInfo.team1_name;
                    inn.bowling.forEach(r => {
                        const rowId = `sc-bowl-row-${innNum}-${r.player_id}`;
                        let row = document.getElementById(rowId);
                        const playerJson = JSON.stringify(r).replace(/"/g, '&quot;');

                        const cellsHTML = `
                            <td class="player-cell" onclick="openPlayerCard(${playerJson}, '${bowlingTeamName}')" style="cursor:pointer;">
                                <div style="display:flex; align-items:center;">
                                    <img src="../uploads/users/${r.profile_image || ''}" class="player-img" onerror="this.src='../assets/images/default-player.png'">
                                    <div>
                                        <div style="font-weight:600">${r.name || 'Unknown'}</div>
                                    </div>
                                </div>
                            </td>
                            <td>${r.overs_bowled}</td>
                            <td>${r.runs_conceded}</td>
                            <td class="highlight">${r.wickets_taken}</td>
                            <td>${parseFloat(r.economy_rate || 0).toFixed(2)}</td>
                        `;

                        if (row) {
                            if (row.innerHTML !== cellsHTML) row.innerHTML = cellsHTML;
                        } else {
                            row = document.createElement('tr');
                            row.id = rowId;
                            row.innerHTML = cellsHTML;
                            bowlTbody.appendChild(row);
                        }
                    });
                }

                // Update Fall of Wickets
                const fowGrid = document.getElementById(`sc-fow-grid-${innNum}`);
                if (fowGrid) {
                    const fowHtml = (inn.fall_of_wickets || []).map((w, index) => `
                        <div class="fow-item">
                            <div class="fow-num">${index + 1}</div>
                            <img src="../uploads/users/${w.profile_image || ''}" class="fow-player-img" onerror="this.src='../assets/images/default-player.png'">
                            <div>
                            <div style="font-weight:600">${w.player_name}</div>
                                <div style="font-size:0.75rem; color:#888;">${w.score} (${w.over} ov)</div>
                            </div>
                        </div>
                    `).join('');

                    const noWicketsMsg = (!inn.fall_of_wickets || inn.fall_of_wickets.length === 0) ? '<div style="color:#888; font-style:italic;">No wickets fallen yet.</div>' : '';
                    const finalHTML = fowHtml + noWicketsMsg;

                    if (fowGrid.innerHTML !== finalHTML) fowGrid.innerHTML = finalHTML;
                }
            });
        }
    </script>
    <style>
        /* Robust Overlay Safeguard */
        .overlay:not(.show),
        .player-card-overlay:not([style*="display: flex"]),
        .partnership-overlay:not([style*="display: flex"]),
        .batting-team-overlay:not([style*="display: flex"]),
        .projected-score-overlay:not([style*="display: flex"]),
        .batting-scorecard-overlay:not([style*="display: flex"]),
        .bowler-scorecard-overlay:not([style*="display: flex"]),
        #graphRunsWicketsOverlay:not([style*="display: flex"]) {
            pointer-events: none !important;
            opacity: 0 !important;
            z-index: -1 !important;
        }

        /* Ensure backdrop doesn't block */
        .overlay-backdrop:not(.active) {
            pointer-events: none !important;
            display: none !important;
        }
    </style>
    <script>
        function showSuperOverIntro(data, done = null) {
            const overlay = document.getElementById('superOverIntroOverlay');
            const match = data.match_info;

            document.getElementById('soTeam1Logo').src = match.team1_logo ? `../uploads/teams/${match.team1_logo}` : '../assets/images/default-team.png';
            document.getElementById('soTeam2Logo').src = match.team2_logo ? `../uploads/teams/${match.team2_logo}` : '../assets/images/default-team.png';
            document.getElementById('soTeam1Name').innerText = match.team1_code || match.team1_name;
            document.getElementById('soTeam2Name').innerText = match.team2_code || match.team2_name;

            overlay.classList.add('show');
            audioManager.play('match_start'); // Or a special Super Over sound if available

            setTimeout(() => {
                overlay.classList.remove('show');
                if (done) done();
            }, 5000);
        }

        function showBattingScorecardOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('battingScorecardOverlay');
            const m = data.match_info;
            const s = data.score;
            const sc = data.scorecard;

            // Set team header
            const isTeam1Batting = (m.batting_team_name === m.team1_name);
            const battingLogoPath = (isTeam1Batting ? m.team1_logo : m.team2_logo) || '';
            document.getElementById('bscTeamLogo').src = battingLogoPath ? `../uploads/teams/${battingLogoPath}` : '../assets/images/default-team.png';
            document.getElementById('bscTeamName').innerText = m.batting_team_name;

            // Find the current inning scorecard
            const currentSc = sc.find(i => i.inning_number == s.inning_number);
            const battingStats = currentSc ? currentSc.batting : [];

            // Get full squad for the batting team
            const fullSquad = (isTeam1Batting ? data.squads.team1 : data.squads.team2) || [];

            // Current batter IDs
            const sId = data.current_players.striker ? data.current_players.striker.id : null;
            const nsId = data.current_players.non_striker ? data.current_players.non_striker.id : null;

            // Create lookup of stats by player_id
            const statsMap = {};
            battingStats.forEach(bs => { statsMap[bs.player_id] = bs; });

            // Categorize players
            let striker = null, nonStriker = null;
            const dismissed = [];
            const remaining = [];

            // Role priority for sorting remaining players
            const rolePriority = { 'Batter': 1, 'Batting Allrounder': 2, 'Allrounder': 3, 'Bowling Allrounder': 4, 'Bowler': 5, 'WK-Batter': 1 };

            fullSquad.forEach(p => {
                if (p.id == sId) {
                    striker = p;
                } else if (p.id == nsId) {
                    nonStriker = p;
                } else if (p.is_out > 0) {
                    dismissed.push(p);
                } else {
                    remaining.push(p);
                }
            });

            // Sort remaining by cricket role
            remaining.sort((a, b) => {
                const pa = rolePriority[a.playing_role] || 99;
                const pb = rolePriority[b.playing_role] || 99;
                return pa - pb;
            });

            // Final order: Striker -> Non-Striker -> Dismissed -> Remaining
            const sortedPlayers = [
                ...(striker ? [striker] : []),
                ...(nonStriker ? [nonStriker] : []),
                ...dismissed,
                ...remaining
            ];

            // Build table body
            const tbody = document.getElementById('bscTableBody');
            tbody.innerHTML = '';

            sortedPlayers.forEach(p => {
                const stat = statsMap[p.player_id || p.id];
                const pid = p.player_id || p.id;
                const isStriker = (pid == sId);
                const isNonStriker = (pid == nsId);
                const isActive = isStriker || isNonStriker;
                const isOut = p.is_out > 0;

                // Determine dismissal text
                let dismissalText = '';
                if (isActive) {
                    dismissalText = 'not out';
                } else if (isOut && stat && stat.dismissal) {
                    dismissalText = stat.dismissal;
                } else if (stat && (stat.runs_scored > 0 || stat.balls_faced > 0)) {
                    dismissalText = stat.dismissal || 'not out';
                } else {
                    dismissalText = 'Yet to bat';
                }

                const hasStats = isActive || isOut || (stat && (stat.runs_scored > 0 || stat.balls_faced > 0));
                const runs = hasStats ? (stat ? stat.runs_scored : 0) : '-';
                const balls = hasStats ? (stat ? stat.balls_faced : 0) : '-';
                const fours = hasStats ? (stat ? stat.fours : 0) : '-';
                const sixes = hasStats ? (stat ? stat.sixes : 0) : '-';
                let sr = '-';
                if (hasStats && stat && stat.balls_faced > 0) {
                    sr = ((stat.runs_scored / stat.balls_faced) * 100).toFixed(1);
                } else if (hasStats) {
                    sr = '0.0';
                }

                const profileImg = p.profile_image ? `../uploads/users/${p.profile_image}` : '../assets/images/default-player.png';
                const playerName = p.name || 'Unknown';
                const activeIndicator = isStriker ? ' 🏏' : '';

                const rowClass = isOut ? 'bsc-row-out' : (isActive ? 'bsc-row-active' : '');
                const yetToBatClass = dismissalText === 'Yet to bat' ? 'bsc-yet-to-bat' : '';

                const tr = document.createElement('tr');
                tr.className = `${rowClass} ${yetToBatClass}`;
                tr.innerHTML = `
                    <td class="bsc-name-cell">
                        <div class="bsc-player-info">
                            <img src="${profileImg}" class="bsc-player-img" onerror="this.src='../assets/images/default-player.png'">
                            <div class="bsc-player-details">
                                <div class="bsc-player-name">${playerName}${activeIndicator}</div>
                                <div class="bsc-dismissal">${dismissalText}</div>
                            </div>
                        </div>
                    </td>
                    <td class="bsc-stat">${runs}</td>
                    <td class="bsc-stat">${balls}</td>
                    <td class="bsc-stat">${fours}</td>
                    <td class="bsc-stat">${sixes}</td>
                    <td class="bsc-stat">${sr}</td>
                `;
                tbody.appendChild(tr);
            });

            // Footer
            document.getElementById('bscExtras').innerText = currentSc ? currentSc.extras : 0;
            document.getElementById('bscOvers').innerText = `${s.overs} / ${s.total_overs}`;
            document.getElementById('bscScore').innerText = `${s.runs}/${s.wickets}`;

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('scorecard');
        }

        function showBowlerScorecardOverlay(data, done = null, skipAudio = false) {
            const overlay = document.getElementById('bowlerScorecardOverlay');
            const m = data.match_info;
            const s = data.score;
            const sc = data.scorecard;

            // Set team header (bowling team)
            const isTeam1Batting = (m.batting_team_name === m.team1_name);
            const bowlingLogoPath = (isTeam1Batting ? m.team2_logo : m.team1_logo) || '';
            const bowlingTeamName = isTeam1Batting ? m.team2_name : m.team1_name;
            document.getElementById('bwscTeamLogo').src = bowlingLogoPath ? `../uploads/teams/${bowlingLogoPath}` : '../assets/images/default-team.png';
            document.getElementById('bwscTeamName').innerText = bowlingTeamName;

            // Find the current inning scorecard
            const currentSc = sc.find(i => i.inning_number == s.inning_number);
            const bowlingStats = currentSc ? currentSc.bowling : [];

            // Filter: Only bowlers who have bowled at least 1 ball
            const activeBowlers = bowlingStats.filter(b => {
                const overs = parseFloat(b.overs_bowled) || 0;
                return overs > 0;
            });

            // Sort: By overs desc, then wickets desc
            activeBowlers.sort((a, b) => {
                const oversA = parseFloat(a.overs_bowled) || 0;
                const oversB = parseFloat(b.overs_bowled) || 0;
                if (oversB !== oversA) return oversB - oversA;
                const wktsA = parseInt(a.wickets_taken) || 0;
                const wktsB = parseInt(b.wickets_taken) || 0;
                return wktsB - wktsA;
            });

            // Current bowler ID
            const currentBowlerId = data.current_players.bowler ? data.current_players.bowler.id : null;

            // Build table body
            const tbody = document.getElementById('bwscTableBody');
            tbody.innerHTML = '';

            activeBowlers.forEach(b => {
                const isCurrent = (b.player_id == currentBowlerId);
                const overs = b.overs_bowled || '0.0';
                const runs = b.runs_conceded || 0;
                const wickets = b.wickets_taken || 0;
                const economy = parseFloat(b.economy_rate) || 0;
                const econDisplay = economy > 0 ? economy.toFixed(2) : '0.00';

                const profileImg = b.profile_image ? `../uploads/users/${b.profile_image}` : '../assets/images/default-player.png';
                const playerName = b.name || 'Unknown';
                const currentIndicator = isCurrent ? ' \u{1F3CF}' : '';

                const rowClass = isCurrent ? 'bwsc-row-active' : '';

                const tr = document.createElement('tr');
                tr.className = rowClass;
                tr.innerHTML = `
                    <td class="bwsc-name-cell">
                        <div class="bwsc-player-info">
                            <img src="${profileImg}" class="bwsc-player-img" onerror="this.src='../assets/images/default-player.png'">
                            <div class="bwsc-player-details">
                                <div class="bwsc-player-name">${playerName}${currentIndicator}</div>
                            </div>
                        </div>
                    </td>
                    <td class="bwsc-stat">${overs}</td>
                    <td class="bwsc-stat">${runs}</td>
                    <td class="bwsc-stat bwsc-wickets">${wickets}</td>
                    <td class="bwsc-stat">${econDisplay}</td>
                `;
                tbody.appendChild(tr);
            });

            // Footer
            document.getElementById('bwscExtras').innerText = currentSc ? currentSc.extras : 0;
            document.getElementById('bwscOvers').innerText = `${s.overs} / ${s.total_overs}`;
            document.getElementById('bwscScore').innerText = `${s.runs}/${s.wickets}`;

            overlay.style.display = 'flex';
            if (done) persistentDoneCallback = done;
            if (!skipAudio) audioManager.play('scorecard');
        }
    </script>
    <!-- Pause Match Overlay -->
    <div id="pauseOverlay" class="pause-overlay">
        <div class="rw-card text-center" style="display: flex; flex-direction: column; align-items: center;">
            <div class="rw-icon mb-3" style="color: #f1c40f;">
                <i class="fas fa-pause-circle fa-4x"></i>
            </div>
            <h2 class="rw-title"
                style="color: white; margin-bottom: 5px; font-weight: bold; text-transform: uppercase;">Time Break</h2>
            <p class="rw-text" style="color: #ccc; font-size: 1.1rem; margin-bottom: 0;">Match scoring is paused.</p>
        </div>
    </div>

    <!-- Refresh Warning Overlay -->
    <div id="refreshWarningOverlay" class="refresh-warning-overlay" style="display:none;">
        <div class="rw-card">
            <div class="rw-icon pulse-rw">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2 class="rw-title">Wait a Minute!</h2>
            <p class="rw-text">Click the Cancel button. Do not refresh the page because some audio functionalities may
                not work if the page is refreshed.</p>
            <div class="rw-actions">
                <button class="rw-btn rw-btn-cancel" onclick="hideRefreshWarning()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Refresh Warning Logic
        function showRefreshWarning() {
            document.getElementById('refreshWarningOverlay').style.display = 'flex';
        }

        function hideRefreshWarning() {
            document.getElementById('refreshWarningOverlay').style.display = 'none';
        }

        // Intercept F5 and Ctrl+R
        window.addEventListener('keydown', function (e) {
            if ((e.which || e.keyCode) == 116 || (e.ctrlKey && (e.which || e.keyCode) == 82)) {
                e.preventDefault();
                showRefreshWarning();
            }
        });
    </script>
</body>

</html>
