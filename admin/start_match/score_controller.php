<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}


$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$match_id) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

try {
    // 1. Fetch Match Details
    $stmt = $pdo->prepare("
        SELECT m.*, 
               t1.team_name as team1_name, t1.team_logo as team1_logo,
               t2.team_name as team2_name, t2.team_logo as team2_logo,
               m.overs as total_overs
        FROM matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match)
        die("Match not found");

    // 2. Fetch Current Innings
    $stmt = $pdo->prepare("SELECT * FROM innings WHERE match_id = ? ORDER BY inning_number DESC LIMIT 1");
    $stmt->execute([$match_id]);
    $current_inning = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_inning)
        die("Innings not initialized");

    // 2b. Calculate Target & Remaining (for initial render)
    $target = 0;
    $required_runs = 0;
    $balls_remaining = 0;
    $total_match_overs = ($current_inning['inning_number'] >= 3) ? 1 : (int) ($match['total_overs'] ?? 20);

    if ($current_inning['inning_number'] == 2) {
        $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 1");
        $stmt->execute([$match_id]);
        $first_inn_runs = $stmt->fetchColumn();
        $target = ($first_inn_runs !== false) ? (int) $first_inn_runs + 1 : 0;
    } elseif ($current_inning['inning_number'] == 4) {
        $stmt = $pdo->prepare("SELECT total_runs FROM innings WHERE match_id = ? AND inning_number = 3");
        $stmt->execute([$match_id]);
        $third_inn_runs = $stmt->fetchColumn();
        $target = ($third_inn_runs !== false) ? (int) $third_inn_runs + 1 : 0;
    }

    if ($target > 0) {
        $required_runs = max(0, $target - $current_inning['total_runs']);
        $total_balls = $total_match_overs * 6;
        $ov = floatval($current_inning['overs_bowled']);
        $balls_bowled = (floor($ov) * 6) + (($ov * 10) % 10);
        $balls_remaining = max(0, $total_balls - $balls_bowled);
    }

    $batting_team_id = $current_inning['batting_team_id'];
    $bowling_team_id = $current_inning['bowling_team_id'];

    $batting_team_name = ($batting_team_id == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
    $batting_team_logo = ($batting_team_id == $match['team1_id']) ? $match['team1_logo'] : $match['team2_logo'];

    $bowling_team_name = ($bowling_team_id == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
    $bowling_team_logo = ($bowling_team_id == $match['team1_id']) ? $match['team1_logo'] : $match['team2_logo'];

    // 3. Fetch Squads for Selection
    // Batting Squad
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.playing_role,
               (SELECT COUNT(*) FROM ball_by_ball b 
                WHERE b.match_id = ? AND b.inning_number = ? AND b.wicket_player_id = u.id AND b.wicket_type IS NOT NULL) as is_out
        FROM match_squads ms
        JOIN users u ON ms.player_id = u.id
        WHERE ms.match_id = ? AND ms.team_id = ?
    ");
    $stmt->execute([$match_id, $current_inning['inning_number'], $match_id, $batting_team_id]);
    $batting_squad = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bowling Squad
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.playing_role
        FROM match_squads ms
        JOIN users u ON ms.player_id = u.id
        WHERE ms.match_id = ? AND ms.team_id = ?
    ");
    $stmt->execute([$match_id, $bowling_team_id]);
    $bowling_squad = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Get Current Players
    $striker = null;
    $non_striker = null;
    $bowler = null;

    if ($match['current_striker_id']) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$match['current_striker_id']]);
        $striker = $stmt->fetchColumn();
    }
    if ($match['current_non_striker_id']) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$match['current_non_striker_id']]);
        $non_striker = $stmt->fetchColumn();
    }
    if ($match['current_bowler_id']) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$match['current_bowler_id']]);
        $bowler = $stmt->fetchColumn();
    }

    // 5. Fetch Match Statistics for current players
    $striker_stats = ['runs_scored' => 0, 'balls_faced' => 0, 'fours' => 0, 'sixes' => 0, 'strike_rate' => 0];
    $non_striker_stats = ['runs_scored' => 0, 'balls_faced' => 0, 'fours' => 0, 'sixes' => 0, 'strike_rate' => 0];
    $bowler_stats = ['overs_bowled' => '0.0', 'runs_conceded' => 0, 'wickets_taken' => 0];

    if ($match['current_striker_id']) {
        $stmt = $pdo->prepare("SELECT * FROM match_statistics WHERE match_id = ? AND player_id = ? AND inning_number = ?");
        $stmt->execute([$match_id, $match['current_striker_id'], $current_inning['inning_number']]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res)
            $striker_stats = $res;
    }
    if ($match['current_non_striker_id']) {
        $stmt = $pdo->prepare("SELECT * FROM match_statistics WHERE match_id = ? AND player_id = ? AND inning_number = ?");
        $stmt->execute([$match_id, $match['current_non_striker_id'], $current_inning['inning_number']]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res)
            $non_striker_stats = $res;
    }
    if ($match['current_bowler_id']) {
        $stmt = $pdo->prepare("SELECT * FROM match_statistics WHERE match_id = ? AND player_id = ? AND inning_number = ?");
        $stmt->execute([$match_id, $match['current_bowler_id'], $current_inning['inning_number']]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res)
            $bowler_stats = $res;
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

$page_title = "Score Controller";
require_once '../../includes/header.php';
?>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row justify-content-center">
        <div class="col-xl-10">

            <!-- 1. Header Section -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <!-- Team 1 -->
                        <div class="d-flex align-items-center">
                            <img src="<?= $batting_team_logo ? '/CPT_LEAGUE/uploads/teams/' . $batting_team_logo : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                class="rounded-circle border p-1"
                                style="width: 60px; height: 60px; object-fit: contain;">
                            <div class="ms-3">
                                <h4 class="fw-bold mb-0"><?= htmlspecialchars($batting_team_name) ?></h4>
                                <span class="badge bg-success">Batting</span>
                            </div>
                        </div>

                        <div class="text-center">
                            <h2 class="fw-bold text-muted">VS</h2>
                        </div>

                        <!-- Team 2 -->
                        <div class="d-flex align-items-center text-end">
                            <div class="me-3">
                                <h4 class="fw-bold mb-0"><?= htmlspecialchars($bowling_team_name) ?></h4>
                                <span class="badge bg-secondary">Bowling</span>
                            </div>
                            <img src="<?= $bowling_team_logo ? '/CPT_LEAGUE/uploads/teams/' . $bowling_team_logo : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                class="rounded-circle border p-1"
                                style="width: 60px; height: 60px; object-fit: contain;">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <!-- 2. Live Score Section -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-dark text-white">
                        <div class="card-body p-4 text-center">
                            <h6 class="text-uppercase text-white-50 letter-spacing-2">Current Score</h6>
                            <h1 class="display-1 fw-bold mb-0" id="displayScore">
                                <?= $current_inning['total_runs'] ?> <span class="text-danger">/
                                    <?= $current_inning['wickets'] ?></span>
                            </h1>
                            <p class="fs-5 text-white-50 mb-0">Overs: <span
                                    id="displayOvers"><?= $current_inning['overs_bowled'] ?> /
                                    <?= $match['total_overs'] ?></span></p>

                            <!-- Target Section -->
                            <div id="targetDisplay" style="<?= $target > 0 ? 'display:block;' : 'display:none;' ?>"
                                class="mt-3 pt-3 border-top border-secondary">
                                <h3 class="text-warning fw-bold mb-1">Target: <span
                                        id="displayTarget"><?= $target ?></span></h3>
                                <p class="text-white-50 mb-0 fs-5">
                                    Need <span id="reqRuns" class="text-white fw-bold"><?= $required_runs ?></span> runs
                                    in
                                    <span id="reqBalls" class="text-white fw-bold"><?= $balls_remaining ?></span> balls
                                </p>
                            </div>

                            <hr class="border-secondary my-4">

                            <div class="row">
                                <div class="col-6 border-end border-secondary">
                                    <small class="text-uppercase text-white-50 d-block mb-2">Batting</small>
                                    <div class="fw-bold fs-4 text-warning" id="battingTeamDisplay">
                                        <?= htmlspecialchars($batting_team_name) ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <small class="text-uppercase text-white-50 d-block mb-2">To Bat</small>
                                    <div class="fw-bold fs-4 text-white-50" id="bowlingTeamDisplay">
                                        <?= htmlspecialchars($bowling_team_name) ?>
                                    </div>
                                    <small class="text-white-50">Yet to bat</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Player Display Section -->
                    <div class="row g-4 mb-4">
                        <!-- Batting Display -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <h5 class="fw-bold mb-0"><i
                                                class="fas fa-bat-ball text-primary me-2"></i>Batting</h5>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-primary scoring-btn"
                                                data-bs-toggle="modal" data-bs-target="#strikerModal"
                                                title="Select Striker">
                                                <i class="fas fa-user-plus"></i> Str
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary scoring-btn"
                                                data-bs-toggle="modal" data-bs-target="#nonStrikerModal"
                                                title="Select Non-Striker">
                                                <i class="fas fa-user-plus"></i> Non
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning scoring-btn"
                                                onclick="swapBatters()" title="Change Striker">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-4">
                                    <div class="mb-3 pb-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-primary fw-bold text-uppercase">Striker *</small>
                                            <div class="badge bg-light text-dark border">
                                                <span id="strRuns"><?= $striker_stats['runs_scored'] ?></span>(<span
                                                    id="strBalls"><?= $striker_stats['balls_faced'] ?></span>)
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h4 class="fw-bold mb-0 text-dark" id="displayStriker">
                                                <?= $striker ? htmlspecialchars($striker) : 'Select Striker' ?>
                                            </h4>
                                            <div class="small text-muted">
                                                4s: <span id="str4s"
                                                    class="fw-bold text-dark"><?= $striker_stats['fours'] ?></span> |
                                                6s: <span id="str6s"
                                                    class="fw-bold text-dark"><?= $striker_stats['sixes'] ?></span> |
                                                SR: <span
                                                    id="strSR"><?= number_format($striker_stats['strike_rate'], 1) ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-muted fw-bold text-uppercase">Non-Striker</small>
                                            <div class="badge bg-light text-dark border">
                                                <span
                                                    id="nonStrRuns"><?= $non_striker_stats['runs_scored'] ?></span>(<span
                                                    id="nonStrBalls"><?= $non_striker_stats['balls_faced'] ?></span>)
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h4 class="fw-bold mb-0 text-secondary" id="displayNonStriker">
                                                <?= $non_striker ? htmlspecialchars($non_striker) : 'Select Non-Striker' ?>
                                            </h4>
                                            <div class="small text-muted">
                                                4s: <span id="nonStr4s"
                                                    class="fw-bold text-dark"><?= $non_striker_stats['fours'] ?></span>
                                                |
                                                6s: <span id="nonStr6s"
                                                    class="fw-bold text-dark"><?= $non_striker_stats['sixes'] ?></span>
                                                |
                                                SR: <span
                                                    id="nonStrSR"><?= number_format($non_striker_stats['strike_rate'], 1) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bowling Display -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="fw-bold mb-0"><i
                                                class="fas fa-baseball-ball text-danger me-2"></i>Bowling</h5>
                                        <button class="btn btn-sm btn-outline-danger rounded-pill scoring-btn"
                                            data-bs-toggle="modal" data-bs-target="#bowlerModal">
                                            <i class="fas fa-user-edit me-1"></i>Select Bowler
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-4">
                                    <div class="mb-3">
                                        <small class="text-danger d-block text-uppercase fw-bold"
                                            style="font-size: 0.75rem;">Current Bowler</small>
                                        <h4 class="fw-bold mb-1 text-dark" id="displayBowler">
                                            <?= $bowler ? htmlspecialchars($bowler) : 'Select Bowler' ?>
                                        </h4>
                                        <div class="text-muted fw-bold">
                                            <span id="bowlOvers"><?= $bowler_stats['overs_bowled'] ?></span>
                                            <small>Overs</small> •
                                            <span id="bowlRuns"><?= $bowler_stats['runs_conceded'] ?></span>
                                            <small>Runs</small> •
                                            <span id="bowlWickets"><?= $bowler_stats['wickets_taken'] ?></span>
                                            <small>Wickets</small>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem;">This
                                            Over</small>
                                        <div class="d-flex gap-2 mt-2 flex-wrap" id="currentOverBubbles">
                                            <!-- Bubbles -->
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted"
                                                style="width: 35px; height: 35px;">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- 4. Score Controller Panel -->
                    <div class="card border-0 shadow-lg rounded-4 h-100">
                        <div class="card-header bg-primary text-white p-4">
                            <h4 class="fw-bold mb-0"><i class="fas fa-gamepad me-2"></i>Controls</h4>
                        </div>
                        <div class="card-body p-4">

                            <!-- Player Selection Controls -->
                            <!-- Player Selection Controls -->
                            <!-- Moved to Batting Header -->

                            <hr class="my-4">

                            <!-- Run Buttons -->
                            <h6 class="fw-bold mb-3">Add Runs</h6>
                            <div class="row g-2 mb-4">
                                <div class="col-4"><button
                                        class="btn btn-light border fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(0)">0</button></div>
                                <div class="col-4"><button
                                        class="btn btn-light border fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(1)">1</button></div>
                                <div class="col-4"><button
                                        class="btn btn-light border fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(2)">2</button></div>
                                <div class="col-4"><button
                                        class="btn btn-light border fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(3)">3</button></div>
                                <div class="col-4"><button
                                        class="btn btn-info text-white fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(4)">4</button></div>
                                <div class="col-4"><button
                                        class="btn btn-success text-white fw-bold w-100 py-3 fs-4 shadow-sm run-btn scoring-btn"
                                        onclick="addRuns(6)">6</button></div>
                            </div>

                            <!-- Extras & Wicket -->
                            <h6 class="fw-bold mb-3">Extras & Dismissal</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <button class="btn btn-warning w-100 py-2 fw-bold text-dark scoring-btn"
                                        onclick="openExtraOverlay('wide')">WD</button>
                                </div>
                                <div class="col-6">
                                    <button class="btn btn-warning w-100 py-2 fw-bold text-dark scoring-btn"
                                        onclick="openExtraOverlay('no ball')">NB</button>
                                </div>
                            </div>

                            <button class="btn btn-danger w-100 py-3 fw-bold fs-5 shadow rounded-pill scoring-btn"
                                type="button" onclick="openWicketModal()">
                                <i class="fas fa-skull-crossbones me-2"></i>WICKET
                            </button>

                            <!-- Live Voice Commentary -->
                            <div class="commentary-control-card mt-4" id="commentaryControlCard">
                                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                    <div>
                                        <div class="commentary-kicker">Live Voice Commentary</div>
                                        <div class="commentary-status-line">
                                            <span class="commentary-dot" id="commentaryDot"></span>
                                            <span id="commentaryStatusText">Offline</span>
                                        </div>
                                    </div>
                                    <span class="commentary-live-pill" id="commentaryLivePill">STANDBY</span>
                                </div>
                                <div class="commentary-meta-grid">
                                    <div>
                                        <span>Viewers</span>
                                        <strong id="commentaryViewerCount">0</strong>
                                    </div>
                                    <div>
                                        <span>Mic</span>
                                        <strong id="commentaryMicState">Off</strong>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 mt-3">
                                    <button id="commentaryToggleBtn" class="btn btn-success fw-bold" type="button"
                                        onclick="liveCommentaryBroadcaster.toggle()" aria-pressed="false">
                                        <i class="fas fa-microphone me-1"></i>Start Live Commentary
                                    </button>
                                </div>
                                <div class="small text-muted mt-2" id="commentaryRoomText">Click start and allow
                                    microphone permission.</div>
                            </div>

                            <!-- Undo & End Match -->
                            <h6 class="fw-bold mb-2 mt-4 text-secondary">Match Actions</h6>
                            <div class="d-flex gap-2 mb-2 flex-wrap">
                                <button id="pauseMatchBtn" class="btn btn-warning flex-grow-1 fw-bold"
                                    onclick="togglePause()">
                                    <i class="fas fa-pause me-1"></i>Pause Match
                                </button>
                                <button class="btn btn-dark flex-grow-1" onclick="performUndo()"><i
                                        class="fas fa-undo me-1"></i> Undo</button>
                                <button class="btn btn-outline-dark flex-grow-1" onclick="stopMatch()">
                                    <i class="fas fa-stop me-1"></i>Stop Match
                                </button>
                                <button id="endMatchBtn" class="btn btn-outline-danger flex-grow-1"
                                    onclick="endMatch()">Match Completed
                                </button>
                                <button id="superOverBtn" class="btn btn-outline-dark flex-grow-1"
                                    onclick="startSuperOver()" style="display:none;">
                                    <i class="fas fa-history me-1"></i>Super Over
                                </button>
                                <button id="superOver2ndBtn" class="btn btn-outline-dark flex-grow-1"
                                    onclick="startSuperOver2nd()" style="display:none;" disabled>
                                    <i class="fas fa-forward me-1"></i>Start Super Over 2nd Innings
                                </button>
                                <button id="menuOptionsBtn" class="btn btn-outline-primary flex-grow-1"
                                    data-bs-toggle="modal" data-bs-target="#menuOptionsModal">
                                    <i class="fas fa-bars me-1"></i>Menu Options
                                </button>
                            </div>

                            <!-- Innings Control -->
                            <h6 class="fw-bold mb-2 mt-3 text-secondary">Innings Control</h6>
                            <div class="d-grid gap-2">
                                <button id="endInningsBtn" class="btn btn-warning fw-bold" onclick="endInnings()"
                                    disabled>
                                    <i class="fas fa-stop-circle me-1"></i> End Innings
                                </button>
                                <button id="startSecondInningsBtn" class="btn btn-success fw-bold"
                                    onclick="startSecondInnings()" disabled>
                                    <i class="fas fa-play-circle me-1"></i> Start 2nd Innings
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->

<!-- Striker Modal -->
<div class="modal fade" id="strikerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Select Striker</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($batting_squad as $player): ?>
                        <button type="button"
                            class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center player-option <?= $player['is_out'] ? 'bg-light text-muted opacity-50' : '' ?>"
                            data-player-id="<?= $player['id'] ?>" data-is-out="<?= $player['is_out'] ?>"
                            onclick="updatePlayer('striker', <?= $player['id'] ?>)" <?= $player['is_out'] ? 'disabled' : '' ?>>
                            <span class="fw-bold"><?= htmlspecialchars($player['name']) ?>
                                <?= $player['is_out'] ? '<small>(Out)</small>' : '' ?></span>
                            <span class="badge bg-light text-dark border"><?= $player['playing_role'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Non-Striker Modal -->
<div class="modal fade" id="nonStrikerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Select Non-Striker</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($batting_squad as $player): ?>
                        <button type="button"
                            class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center player-option <?= $player['is_out'] ? 'bg-light text-muted opacity-50' : '' ?>"
                            data-player-id="<?= $player['id'] ?>" data-is-out="<?= $player['is_out'] ?>"
                            onclick="updatePlayer('non_striker', <?= $player['id'] ?>)" <?= $player['is_out'] ? 'disabled' : '' ?>>
                            <span class="fw-bold"><?= htmlspecialchars($player['name']) ?>
                                <?= $player['is_out'] ? '<small>(Out)</small>' : '' ?></span>
                            <span class="badge bg-light text-dark border"><?= $player['playing_role'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bowler Modal -->
<div class="modal fade" id="bowlerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">Select Bowler</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($bowling_squad as $player): ?>
                        <button type="button"
                            class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center bowler-option"
                            data-player-id="<?= $player['id'] ?>" onclick="updatePlayer('bowler', <?= $player['id'] ?>)">
                            <span class="fw-bold"><?= htmlspecialchars($player['name']) ?></span>
                            <span class="badge bg-light text-dark border"><?= $player['playing_role'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Extra Detail Modal -->
<div class="modal fade" id="extraModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold" id="extraModalTitle">Extra Runs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="small text-muted fw-bold text-uppercase mb-2" id="extraRunsLabel">Runs</div>
                <div class="row g-2 mb-3">
                    <div class="col-4"><button class="btn btn-light border fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(0)">0</button></div>
                    <div class="col-4"><button class="btn btn-light border fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(1)">1</button></div>
                    <div class="col-4"><button class="btn btn-light border fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(2)">2</button></div>
                    <div class="col-4"><button class="btn btn-light border fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(3)">3</button></div>
                    <div class="col-4"><button class="btn btn-info text-white fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(4)">4</button></div>
                    <div class="col-4"><button class="btn btn-success text-white fw-bold w-100 py-3 fs-4 scoring-btn"
                            onclick="scoreExtraRuns(6)">6</button></div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <button class="btn btn-outline-warning text-dark fw-bold w-100 py-3 scoring-btn" type="button"
                            id="extraSwitchBtn" onclick="switchExtraType()">WD</button>
                    </div>
                    <div class="col-6">
                        <button class="btn btn-outline-danger fw-bold w-100 py-3 scoring-btn" type="button"
                            onclick="openExtraWicketModal()">
                            <i class="fas fa-skull-crossbones me-1"></i>Wicket
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Wicket Type Modal -->
<div class="modal fade" id="wicketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">Wicket Type</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="initWicket('caught')">Caught</button></div>
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="addWicket('bowled')">Bowled</button></div>
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="initWicket('run out')">Run Out</button></div>
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="addWicket('lbw')">LBW</button></div>
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="initWicket('stumped')">Stumped</button></div>
                    <div class="col-6"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="addWicket('hit wicket')">Hit Wicket</button></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Run Out Player Modal -->
<div class="modal fade" id="runOutPlayerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">Select Player to Dismiss</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <p>Which player was run out?</p>
                <div class="d-grid gap-3" id="runOutOptions">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Run Out Runs Modal -->
<div class="modal fade" id="runOutRunsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">Runs Before Run-Out</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <p>How many runs were completed before the run-out?</p>
                <div class="row g-2 justify-content-center">
                    <div class="col-3"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="selectRunOutRuns(0)">0</button></div>
                    <div class="col-3"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="selectRunOutRuns(1)">1</button></div>
                    <div class="col-3"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="selectRunOutRuns(2)">2</button></div>
                    <div class="col-3"><button class="btn btn-outline-danger w-100 py-3 fw-bold"
                            onclick="selectRunOutRuns(3)">3</button></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fielder Modal -->
<div class="modal fade" id="fielderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">Select Fielder</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($bowling_squad as $player): ?>
                        <button type="button"
                            class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center"
                            onclick="selectFielder(<?= $player['id'] ?>)">
                            <span class="fw-bold"><?= htmlspecialchars($player['name']) ?></span>
                            <span class="badge bg-light text-dark border"><?= $player['playing_role'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Menu Options Modal -->
<div class="modal fade" id="menuOptionsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Menu Options</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="overlay-options-grid">
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('partnership')">
                        <i class="fas fa-users me-2"></i> Partnership Display
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('batting_team')">
                        <i class="fas fa-address-card me-2"></i> Batting Team Display
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('bowling_team')">
                        <i class="fas fa-id-card me-2"></i> Bowling Team Display
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('comparison_graph')">
                        <i class="fas fa-chart-line me-2"></i> Comparison Graph
                    </button>
                    <button id="targetOverlayBtn" class="btn overlay-btn py-3 fw-bold scoring-btn"
                        onclick="triggerOverlay('target')">
                        <i class="fas fa-bullseye me-2"></i> Target Overlay
                    </button>
                    <button id="projectedScoreBtn" class="btn overlay-btn py-3 fw-bold scoring-btn"
                        onclick="triggerOverlay('projected_score')">
                        <i class="fas fa-calculator me-2"></i> Projected Score
                    </button>
                    <button id="graphRunsWicketsBtn" class="btn overlay-btn py-3 fw-bold scoring-btn"
                        onclick="triggerOverlay('runs_wickets_graph')">
                        <i class="fas fa-chart-bar me-2"></i> Graph (Runs/Wickets)
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold scoring-btn"
                        onclick="triggerOverlay('batting_scorecard')">
                        <i class="fas fa-table me-2"></i> Batting Scorecard
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold scoring-btn"
                        onclick="triggerOverlay('bowler_scorecard')">
                        <i class="fas fa-bowling-ball me-2"></i> Bowler Scorecard
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('upcoming_matches')">
                        <i class="fas fa-calendar-alt me-2"></i> Upcoming Matches
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold" onclick="triggerOverlay('previous_matches')">
                        <i class="fas fa-history me-2"></i> Previous Matches
                    </button>
                    <button class="btn overlay-btn py-3 fw-bold scoring-btn" onclick="triggerOverlay('next_match')">
                        <i class="fas fa-forward me-2"></i> Next Match
                    </button>

                </div>
            </div>
        </div>
    </div>
</div>
<!-- Overlay Control Alert -->
<div id="overlayControl" class="alert alert-warning position-fixed bottom-0 end-0 m-3 shadow-lg"
    style="display:none; z-index: 1060; min-width: 300px;">
    <div class="d-flex align-items-center justify-content-between">
        <div>
            <i class="fas fa-broadcast-tower me-2"></i>
            <strong id="activeOverlayName">Overlay</strong> is active
        </div>
        <button class="btn btn-danger btn-sm fw-bold" onclick="stopOverlay()">
            <i class="fas fa-stop me-1"></i> STOP
        </button>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/peerjs@1.5.4/dist/peerjs.min.js"></script>
<script>
    const MATCH_ID = <?= $match_id ?>;
    const TOTAL_OVERS = <?= $match['total_overs'] ?? 20 ?>;
    const COMMENTARY_API_URL = '../../live_stream/live_commentary_api.php';
    let currentData = null; // Store data for local checks
    let currentInningNum = <?= $current_inning['inning_number'] ?>;
    let pendingWicketType = null;
    let pendingWicketPlayerId = null;
    let pendingRunOutRuns = 0;
    let pendingExtraType = null;
    let isEndingMatch = false;

    class LiveCommentaryBroadcaster {
        constructor() {
            this.peer = null;
            this.peerId = null;
            this.localStream = null;
            this.calls = new Map();
            this.failedPeers = new Map();
            this.pollTimer = null;
            this.recoveryTimer = null;
            this.isLive = false;
            this.roomId = null;
            this.isBusy = false;
            this.busyAction = null;

            window.addEventListener('online', () => {
                this.log('Network online event received.');
                if (this.isLive) this.recoverPeer('network-online');
            });
            window.addEventListener('offline', () => {
                this.warn('Network offline event received.');
                this.setStatus('Commentary disconnected. Waiting for network...', 'error');
            });
            window.addEventListener('pagehide', () => this.stop(true));
            window.addEventListener('beforeunload', () => this.stop(true));
        }

        log(...args) {
            console.log('[LiveCommentary][host]', ...args);
        }

        warn(...args) {
            console.warn('[LiveCommentary][host]', ...args);
        }

        error(...args) {
            console.error('[LiveCommentary][host]', ...args);
        }

        isSecureAllowed() {
            return window.isSecureContext
                || location.protocol === 'https:'
                || ['localhost', '127.0.0.1'].includes(location.hostname);
        }

        buildPeerId(prefix) {
            const bytes = new Uint8Array(8);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
            } else {
                for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
            }
            const random = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
            return `cpt-${prefix}-${MATCH_ID}-${random}`;
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

        async toggle() {
            if (this.isBusy) {
                this.log('Toggle ignored because commentary is busy.', { isLive: this.isLive, busyAction: this.busyAction });
                return;
            }

            if (this.isLive) {
                this.isBusy = true;
                this.busyAction = 'stop';
                this.setButtons();

                try {
                    await this.stop(false);
                } finally {
                    this.isBusy = false;
                    this.busyAction = null;
                    this.setButtons();
                }
                return;
            }

            await this.start();
        }

        async start() {
            if (this.isLive || this.isBusy) {
                this.log('Start ignored because commentary is already live or busy.', { isLive: this.isLive, isBusy: this.isBusy });
                return;
            }

            this.isBusy = true;
            this.busyAction = 'start';
            this.setButtons();

            try {
                this.assertEnvironment();
                this.setStatus('Requesting microphone permission...', 'connecting');
                this.setMicState('Starting...');

                this.localStream = await this.captureMicrophone();
                this.assertUsableLocalStream();
                this.bindLocalTrackEvents();
                this.setMicState('On');

                this.setStatus('Creating commentator PeerJS connection...', 'connecting');
                await this.createPeer();

                this.setStatus('Opening private commentary room...', 'connecting');
                const startState = await this.postAction('start', { host_peer_id: this.peerId });
                if (!startState.success) throw new Error(startState.message || 'Unable to start commentary.');
                if (!startState.room_id) throw new Error('Commentary API did not return a room_id.');

                this.isLive = true;
                this.roomId = startState.room_id;
                this.log('Room connected.', {
                    roomId: this.roomId,
                    peerId: this.peerId,
                    viewerCount: startState.viewer_count || 0
                });
                this.setStatus('Live. Waiting for live match viewers...', 'live');
                this.updateRoomText();

                await this.pollViewers();
                this.pollTimer = setInterval(() => {
                    this.pollViewers().catch(err => {
                        this.warn('Viewer poll failed.', err);
                    });
                }, 3000);
            } catch (err) {
                this.error('Live commentary start failed.', err);
                this.setStatus(this.formatStartError(err), 'error');
                await this.stop(false, true);
            } finally {
                this.isBusy = false;
                this.busyAction = null;
                this.setButtons();
            }
        }

        assertEnvironment() {
            if (!this.isSecureAllowed()) {
                throw new Error('Open this page with HTTPS to use microphone and WebRTC commentary.');
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('This browser does not support microphone capture.');
            }
            if (typeof Peer === 'undefined') {
                throw new Error('PeerJS failed to load. Check the PeerJS CDN/network.');
            }
            this.log('Environment OK.', {
                secureContext: window.isSecureContext,
                host: location.host,
                userAgent: navigator.userAgent
            });
        }

        async captureMicrophone() {
            const advancedConstraints = {
                video: false,
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    channelCount: { ideal: 1 },
                    sampleRate: { ideal: 48000 }
                }
            };
            const fallbackConstraints = {
                video: false,
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            };

            if (navigator.permissions && navigator.permissions.query) {
                try {
                    const permission = await navigator.permissions.query({ name: 'microphone' });
                    this.log('Microphone permission state before prompt:', permission.state);
                } catch (err) {
                    this.warn('Microphone permission query is not available in this browser.', err);
                }
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia(advancedConstraints);
                this.log('Microphone access success with optimized constraints.');
                return stream;
            } catch (err) {
                this.warn('Optimized microphone constraints failed.', err);
                if (this.isTerminalMicrophoneError(err)) {
                    throw err;
                }
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia(fallbackConstraints);
                this.log('Microphone access success with fallback constraints.');
                return stream;
            } catch (err) {
                this.warn('Fallback microphone constraints failed.', err);
                if (this.isTerminalMicrophoneError(err)) {
                    throw err;
                }
            }

            const stream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
            this.log('Microphone access success with basic audio constraints.');
            return stream;
        }

        isTerminalMicrophoneError(err) {
            return err && ['NotAllowedError', 'PermissionDeniedError', 'NotFoundError', 'DevicesNotFoundError', 'SecurityError'].includes(err.name);
        }

        hasLiveMicrophone() {
            return !!(
                this.localStream &&
                this.localStream.getAudioTracks().some(track => track.readyState === 'live' && track.enabled)
            );
        }

        assertUsableLocalStream() {
            if (!this.localStream) {
                throw new Error('Microphone stream is undefined.');
            }

            const audioTracks = this.localStream.getAudioTracks();
            if (!audioTracks.length) {
                throw new Error('Microphone stream has no audio track.');
            }

            const liveTracks = audioTracks.filter(track => track.readyState === 'live');
            if (!liveTracks.length) {
                throw new Error('Microphone audio track is not live.');
            }

            liveTracks.forEach(track => {
                track.enabled = true;
                this.log('Microphone track ready.', {
                    label: track.label,
                    enabled: track.enabled,
                    muted: track.muted,
                    readyState: track.readyState,
                    settings: typeof track.getSettings === 'function' ? track.getSettings() : {}
                });
            });
        }

        bindLocalTrackEvents() {
            this.localStream.getAudioTracks().forEach(track => {
                track.onmute = () => {
                    this.warn('Microphone track muted by browser/device.', track.label);
                    this.setMicState('Muted');
                    this.setStatus('Mic muted by device. Check microphone permission.', 'error');
                };
                track.onunmute = () => {
                    this.log('Microphone track unmuted.', track.label);
                    this.setMicState('On');
                    if (this.isLive) this.setStatus('Live. Microphone active.', 'live');
                };
                track.onended = () => {
                    this.warn('Microphone track ended.', track.label);
                    this.setMicState('Stopped');
                    if (this.isLive) {
                        this.setStatus('Mic stopped. Restart commentary.', 'error');
                        this.stop(false);
                    }
                };
            });
        }

        async createPeer() {
            return new Promise((resolve, reject) => {
                this.destroyPeerOnly();
                this.peerId = this.buildPeerId('host');
                this.log('Creating commentator peer.', this.peerId);
                this.peer = new Peer(this.peerId, this.peerOptions());

                let settled = false;
                const timeout = setTimeout(() => {
                    if (settled) return;
                    settled = true;
                    reject(new Error('PeerJS commentator connection timed out.'));
                }, 15000);

                this.peer.on('open', id => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timeout);
                    this.peerId = id;
                    this.log('Commentator peer open.', id);
                    resolve(id);
                });

                this.peer.on('disconnected', () => {
                    this.warn('Commentator peer disconnected from PeerJS signaling.');
                    if (!this.isLive) return;
                    this.setStatus('Reconnecting commentator peer...', 'connecting');
                    try {
                        if (this.peer && !this.peer.destroyed) this.peer.reconnect();
                    } catch (err) {
                        this.warn('PeerJS reconnect threw.', err);
                    }
                    this.recoverPeer('peer-disconnected');
                });

                this.peer.on('close', () => {
                    this.warn('Commentator peer closed.');
                    if (this.isLive) this.recoverPeer('peer-closed');
                });

                this.peer.on('error', err => {
                    this.error('Commentator PeerJS error.', err);
                    if (!settled) {
                        settled = true;
                        clearTimeout(timeout);
                        reject(new Error(this.describePeerError(err)));
                        return;
                    }
                    if (this.isLive) {
                        this.setStatus(this.describePeerError(err), 'error');
                        this.recoverPeer('peer-error');
                    }
                });
            });
        }

        async recoverPeer(reason = 'unknown') {
            if (this.recoveryTimer || !this.isLive) return;
            if (!this.hasLiveMicrophone()) {
                this.warn('Recovery skipped because local microphone stream is not live.');
                this.setStatus('Mic stopped. Restart commentary.', 'error');
                return;
            }

            this.warn('Reconnect triggered.', reason);
            this.setStatus('Reconnecting commentary stream...', 'connecting');
            this.recoveryTimer = setTimeout(async () => {
                this.recoveryTimer = null;
                this.closeCalls('peer recovery');

                try {
                    await this.createPeer();
                    this.setStatus('Commentary reconnected. Calling viewers...', 'live');
                    await this.pollViewers();
                } catch (err) {
                    this.error('Peer recovery failed.', err);
                    if (this.isLive) this.recoverPeer('recovery-failed');
                }
            }, 2500);
        }

        async pollViewers() {
            if (!this.isLive) return;
            if (!this.peer || this.peer.destroyed || !this.peer.open) {
                this.warn('Viewer poll skipped because commentator peer is not open.');
                this.recoverPeer('poll-with-closed-peer');
                return;
            }
            if (!this.hasLiveMicrophone()) {
                this.setStatus('Mic stopped. Restart commentary.', 'error');
                await this.stop(false);
                return;
            }

            const data = await this.getAction('viewers');
            if (!data.success) throw new Error(data.message || 'Viewer polling failed.');

            if (!data.active) {
                this.warn('API says commentary is inactive; stopping local broadcaster.');
                await this.stop(false);
                return;
            }

            if (data.room_id && this.roomId && data.room_id !== this.roomId) {
                this.warn('Room id changed during broadcast.', { oldRoomId: this.roomId, newRoomId: data.room_id });
                this.roomId = data.room_id;
            }

            const viewers = Array.isArray(data.viewers) ? data.viewers : [];
            const viewerIds = viewers.map(viewer => viewer.peer_id).filter(Boolean);
            this.syncActiveViewerCalls(viewerIds);

            const viewerCount = Number(data.viewer_count || viewerIds.length || 0);
            this.setViewerCount(viewerCount);
            this.updateRoomText(viewerCount);
            this.log('Viewer heartbeat poll.', { viewerCount, viewerIds, roomId: data.room_id });

            if (viewerIds.length === 0) {
                this.setStatus('Live. Waiting for live match viewers...', 'live');
                return;
            }

            this.setStatus(`Live. Calling ${viewerIds.length} viewer${viewerIds.length === 1 ? '' : 's'}...`, 'live');
            viewerIds.forEach(peerId => this.callViewer(peerId));
        }

        syncActiveViewerCalls(activePeerIds) {
            const active = new Set(activePeerIds);
            Array.from(this.calls.keys()).forEach(peerId => {
                if (!active.has(peerId)) {
                    this.removeCall(peerId, 'viewer heartbeat expired', false);
                }
            });
        }

        callViewer(peerId) {
            if (!peerId) {
                this.warn('Skipping empty viewer peer id.');
                return;
            }
            if (!this.peer || this.peer.destroyed || !this.peer.open) {
                this.warn('Cannot call viewer because PeerJS is not open.', peerId);
                this.recoverPeer('call-with-closed-peer');
                return;
            }
            if (!this.hasLiveMicrophone()) {
                this.warn('Cannot call viewer because local MediaStream is missing or stopped.', peerId);
                this.setStatus('Mic stream missing. Restart commentary.', 'error');
                return;
            }

            const existing = this.calls.get(peerId);
            if (existing && !existing.closed) return;

            const lastFail = this.failedPeers.get(peerId) || 0;
            if (Date.now() - lastFail < 6000) {
                this.log('Skipping viewer call during retry backoff.', peerId);
                return;
            }

            try {
                this.log('Calling viewer peer.', { peerId, roomId: this.roomId, tracks: this.localStream.getAudioTracks().length });
                const call = this.peer.call(peerId, this.localStream, {
                    metadata: { matchId: MATCH_ID, roomId: this.roomId, hostPeerId: this.peerId },
                    sdpTransform: sdp => this.optimizeOpusSdp(sdp)
                });

                if (!call) {
                    throw new Error('peer.call() returned an empty call object.');
                }

                const entry = {
                    call,
                    connected: false,
                    closed: false,
                    startedAt: Date.now(),
                    timeoutId: null
                };
                this.calls.set(peerId, entry);

                entry.timeoutId = setTimeout(() => {
                    if (!entry.connected && !entry.closed) {
                        this.warn('Viewer call timed out before WebRTC connected.', peerId);
                        this.removeCall(peerId, 'call timeout', true, call);
                    }
                }, 22000);

                call.on('stream', stream => {
                    this.log('Unexpected remote stream received from viewer.', { peerId, streamId: stream && stream.id });
                });
                call.on('close', () => this.removeCall(peerId, 'call closed', false, call));
                call.on('error', err => {
                    this.warn('Viewer call failed.', peerId, err);
                    this.removeCall(peerId, 'call error', true, call);
                });

                setTimeout(() => this.attachPeerConnectionDebug(peerId, entry), 0);
            } catch (err) {
                this.warn('Unable to call viewer.', peerId, err);
                this.failedPeers.set(peerId, Date.now());
            }
        }

        attachPeerConnectionDebug(peerId, entry) {
            const pc = entry.call && entry.call.peerConnection;
            if (!pc) {
                this.warn('MediaConnection has no RTCPeerConnection yet.', peerId);
                return;
            }

            const updateState = () => {
                if (this.calls.get(peerId) !== entry) return;

                const iceState = pc.iceConnectionState || 'unknown';
                const connectionState = pc.connectionState || 'unknown';
                this.log('Viewer WebRTC state.', { peerId, iceState, connectionState });

                if (iceState === 'connected' || iceState === 'completed' || connectionState === 'connected') {
                    entry.connected = true;
                    if (entry.timeoutId) {
                        clearTimeout(entry.timeoutId);
                        entry.timeoutId = null;
                    }
                    this.setStatus('Live commentary connected to viewers.', 'live');
                }

                if (iceState === 'failed' || iceState === 'closed' || connectionState === 'failed' || connectionState === 'closed') {
                    this.removeCall(peerId, `WebRTC ${connectionState}/${iceState}`, true, entry.call);
                }

                if (iceState === 'disconnected' || connectionState === 'disconnected') {
                    setTimeout(() => {
                        if (entry.closed) return;
                        const currentIce = pc.iceConnectionState || 'unknown';
                        const currentConnection = pc.connectionState || 'unknown';
                        const isConnected = currentIce === 'connected' || currentIce === 'completed' || currentConnection === 'connected';
                        if (!isConnected && (currentIce === 'disconnected' || currentConnection === 'disconnected')) {
                            this.removeCall(peerId, 'WebRTC disconnected', true, entry.call);
                        }
                    }, 7000);
                }
            };

            if (typeof pc.addEventListener === 'function') {
                pc.addEventListener('iceconnectionstatechange', updateState);
                pc.addEventListener('connectionstatechange', updateState);
                pc.addEventListener('icecandidateerror', event => {
                    this.warn('ICE candidate error.', {
                        peerId,
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

        removeCall(peerId, reason, markFailed, expectedCall = null) {
            const entry = this.calls.get(peerId);
            if (!entry) return;
            if (expectedCall && entry.call !== expectedCall) {
                this.log('Ignoring stale viewer call cleanup.', { peerId, reason });
                try { expectedCall.close(); } catch (e) { }
                return;
            }

            entry.closed = true;
            if (entry.timeoutId) clearTimeout(entry.timeoutId);
            this.calls.delete(peerId);
            if (markFailed) this.failedPeers.set(peerId, Date.now());
            try { entry.call.close(); } catch (e) { }
            this.log('Viewer call removed.', { peerId, reason, markFailed });
        }

        optimizeOpusSdp(sdp) {
            try {
                if (!sdp || typeof sdp !== 'string') return sdp;
                const opusMatch = sdp.match(/a=rtpmap:(\d+) opus\/48000/i);
                if (!opusMatch) return sdp;

                const opusPayload = opusMatch[1];
                const lines = sdp.split('\r\n');
                const optimized = lines.map(line => {
                    if (!line.startsWith('m=audio')) return line;
                    const parts = line.split(' ');
                    const header = parts.slice(0, 3);
                    const payloads = parts.slice(3).filter(p => p !== opusPayload);
                    return header.concat([opusPayload], payloads).join(' ');
                });

                const fmtp = `a=fmtp:${opusPayload} minptime=10;useinbandfec=1;usedtx=1;stereo=0;sprop-stereo=0;maxaveragebitrate=24000;maxplaybackrate=48000`;
                const fmtpIndex = optimized.findIndex(line => line.startsWith(`a=fmtp:${opusPayload}`));
                if (fmtpIndex >= 0) {
                    optimized[fmtpIndex] = fmtp;
                } else {
                    const rtpIndex = optimized.findIndex(line => line.startsWith(`a=rtpmap:${opusPayload}`));
                    if (rtpIndex >= 0) optimized.splice(rtpIndex + 1, 0, fmtp);
                }

                this.log('Applied Opus voice optimization to SDP.');
                return optimized.join('\r\n');
            } catch (err) {
                this.warn('SDP optimization failed; using original SDP.', err);
                return sdp;
            }
        }

        closeCalls(reason = 'cleanup') {
            Array.from(this.calls.keys()).forEach(peerId => this.removeCall(peerId, reason, false));
        }

        async stop(useBeacon = false, fromFailedStart = false) {
            const wasLive = this.isLive;
            this.isLive = false;
            this.isBusy = fromFailedStart ? false : this.isBusy;

            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
            if (this.recoveryTimer) {
                clearTimeout(this.recoveryTimer);
                this.recoveryTimer = null;
            }

            this.closeCalls('commentary stop');
            this.destroyPeerOnly();

            if (this.localStream) {
                this.localStream.getTracks().forEach(track => {
                    try { track.stop(); } catch (err) { this.warn('Failed to stop local track.', err); }
                });
                this.localStream = null;
                this.log('Microphone tracks stopped.');
            }

            this.peerId = null;
            this.roomId = null;
            this.failedPeers.clear();
            this.setMicState('Off');
            this.setViewerCount(0);
            this.updateRoomText();
            if (!fromFailedStart) {
                this.setStatus('Commentary disconnected', 'idle');
            }
            this.setButtons();

            if (wasLive) {
                if (useBeacon && navigator.sendBeacon) {
                    const body = new URLSearchParams({ action: 'stop', match_id: MATCH_ID });
                    navigator.sendBeacon(COMMENTARY_API_URL, new Blob([body.toString()], {
                        type: 'application/x-www-form-urlencoded'
                    }));
                    this.log('Stop sent with sendBeacon.');
                } else {
                    try {
                        await this.postAction('stop');
                        this.log('Commentary room stopped.');
                    } catch (err) {
                        this.warn('Stop commentary API failed.', err);
                    }
                }
            }
        }

        destroyPeerOnly() {
            if (this.peer && !this.peer.destroyed) {
                try { this.peer.destroy(); } catch (err) { this.warn('Failed to destroy peer.', err); }
            }
            this.peer = null;
        }

        async getAction(action, extra = {}) {
            const params = new URLSearchParams({ action, match_id: MATCH_ID, ...extra });
            const response = await fetch(`${COMMENTARY_API_URL}?${params.toString()}`, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            return this.parseApiResponse(response);
        }

        async postAction(action, extra = {}) {
            const body = new URLSearchParams({ action, match_id: MATCH_ID, ...extra });
            const response = await fetch(COMMENTARY_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body
            });
            return this.parseApiResponse(response);
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

        formatStartError(err) {
            if (!err) return 'Could not start commentary.';
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                return 'Mic blocked. Allow microphone permission and try again.';
            }
            if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                return 'No microphone found on this device.';
            }
            if (err.name === 'NotReadableError') {
                return 'Mic is busy in another app. Close it and try again.';
            }
            if (err.name === 'SecurityError') {
                return 'Mic blocked because this page is not in a secure context.';
            }
            return err.message || 'Could not start commentary.';
        }

        setStatus(text, state = 'idle') {
            const status = document.getElementById('commentaryStatusText');
            const card = document.getElementById('commentaryControlCard');
            const pill = document.getElementById('commentaryLivePill');
            if (status) status.innerText = text;
            if (!card || !pill) return;

            card.classList.remove('is-live', 'is-connecting', 'is-error');
            if (state === 'live') {
                card.classList.add('is-live');
                pill.innerText = 'LIVE';
            } else if (state === 'connecting') {
                card.classList.add('is-connecting');
                pill.innerText = 'CONNECTING';
            } else if (state === 'error') {
                card.classList.add('is-error');
                pill.innerText = 'CHECK';
            } else {
                pill.innerText = 'STANDBY';
            }
            this.log('UI status:', text);
        }

        setButtons() {
            const toggleBtn = document.getElementById('commentaryToggleBtn');
            if (!toggleBtn) return;

            toggleBtn.disabled = this.isBusy;
            toggleBtn.setAttribute('aria-pressed', this.isLive ? 'true' : 'false');

            if (this.isBusy) {
                const label = this.busyAction === 'stop' ? 'Stopping Commentary' : 'Starting Commentary';
                toggleBtn.className = 'btn btn-warning fw-bold';
                toggleBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>${label}`;
                return;
            }

            if (this.isLive) {
                toggleBtn.className = 'btn btn-outline-danger fw-bold';
                toggleBtn.innerHTML = '<i class="fas fa-microphone-slash me-1"></i>Stop Commentary';
                return;
            }

            toggleBtn.className = 'btn btn-success fw-bold';
            toggleBtn.innerHTML = '<i class="fas fa-microphone me-1"></i>Start Live Commentary';
        }

        setMicState(text) {
            const el = document.getElementById('commentaryMicState');
            if (el) el.innerText = text;
        }

        setViewerCount(count) {
            const el = document.getElementById('commentaryViewerCount');
            if (el) el.innerText = String(count);
        }

        updateRoomText(viewerCount = null) {
            const text = document.getElementById('commentaryRoomText');
            if (!text) return;
            if (this.roomId) {
                const viewerText = viewerCount === null ? '' : ` | viewers: ${viewerCount}`;
                text.innerText = `Private room: ${this.roomId.slice(0, 12)}...${viewerText}`;
            } else {
                text.innerText = 'Click start and allow microphone permission.';
            }
        }
    }

    const liveCommentaryBroadcaster = new LiveCommentaryBroadcaster();
    window.liveCommentaryBroadcaster = liveCommentaryBroadcaster;

    // Initial Load
    fetchData();
    setInterval(fetchData, 3000); // Poll every 3s to keep sync

    function fetchData() {
        fetch(`../../live_stream/get_live_data.php?id=${MATCH_ID}&t=${new Date().getTime()}`)
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    // Check for innings change to refresh squads
                    if (data.score.inning_number != currentInningNum) {
                        location.reload();
                        return;
                    }
                    currentData = data;
                    updateUI(data);
                }
            })
            .catch(err => console.error("Sync Error:", err));
    }

    function getBallDisplay(ball) {
        const extraType = (ball.extra_type || '').toLowerCase();
        const runs = parseInt(ball.runs_scored || 0);
        const extraRuns = parseInt(ball.extra_runs || 0);
        const hasWicket = !!ball.wicket_type;
        let text = runs === 0 ? '•' : String(runs);
        let bg = 'bg-light text-muted';

        if (extraType === 'wide') {
            const completedRuns = Math.max(0, extraRuns - 1);
            text = completedRuns > 0 ? `WD+${completedRuns}` : 'WD';
            if (hasWicket) text += '+W';
            bg = 'bg-warning text-dark border-warning';
        } else if (extraType === 'no ball') {
            text = runs > 0 ? `NB+${runs}` : 'NB';
            if (hasWicket) text += '+W';
            bg = 'bg-warning text-dark border-warning';
        } else if (hasWicket) {
            text = 'W';
            bg = 'bg-danger text-white border-danger';
        } else if (runs === 4) {
            text = '4';
            bg = 'bg-primary text-white border-primary';
        } else if (runs === 6) {
            text = '6';
            bg = 'bg-success text-white border-success';
        }

        return {
            text,
            bg,
            compound: text.length > 2
        };
    }

    function updateUI(data) {
        const superOver2ndBtn = document.getElementById('superOver2ndBtn');
        const superOverBtn = document.getElementById('superOverBtn');
        const s = data.score;
        const sc = data.scorecard;

        // Reset display states
        superOverBtn.style.display = 'none';
        superOver2ndBtn.style.display = 'none';
        const start2ndBtn = document.getElementById('startSecondInningsBtn');
        start2ndBtn.style.display = 'none';

        // 1st Innings -> 2nd Innings transition (Persistent Visibility)
        if (s.inning_number <= 2) {
            start2ndBtn.style.display = 'block';
            // Only clickable during Inning 1 break
            start2ndBtn.disabled = !(s.inning_number == 1 && s.is_break);
        }

        // Super Over Button Visibility Logic (After 2nd Innings Tie)
        const is2ndInningOver = (s.inning_number == 2 && (s.is_finished || s.wickets >= 10 || parseFloat(s.overs) >= s.total_overs));

        if (is2ndInningOver) {
            const inn1 = sc.find(i => i.inning_number == 1);
            if (inn1 && parseInt(s.runs) > 0 && parseInt(s.runs) === parseInt(inn1.runs)) {
                superOverBtn.style.display = 'block';
            }
        } else if (s.inning_number == 3) {
            // During Super Over 1st Innings, show "Start SO 2nd Innings" button
            superOver2ndBtn.style.display = 'block';
            if (s.is_finished || s.is_break) {
                superOver2ndBtn.disabled = false;
                superOver2ndBtn.classList.remove('btn-outline-dark');
                superOver2ndBtn.classList.add('btn-dark');
            } else {
                superOver2ndBtn.disabled = true;
                superOver2ndBtn.classList.add('btn-outline-dark');
                superOver2ndBtn.classList.remove('btn-dark');
            }
        }

        // 1. Score Header
        const m = data.match_info;
        document.getElementById('displayScore').innerHTML = `${s.runs} <span class="text-danger">/${s.wickets}</span>`;
        // Overs Format: Current / Total
        document.getElementById('displayOvers').innerText = `${s.overs} / ${s.total_overs}`;

        // 1b. Target Display Update
        const targetDisplay = document.getElementById('targetDisplay');
        if (s.target > 0) {
            targetDisplay.style.display = 'block';
            document.getElementById('displayTarget').innerText = s.target;
            document.getElementById('reqRuns').innerText = s.required_runs;
            document.getElementById('reqBalls').innerText = s.balls_remaining;
        } else {
            targetDisplay.style.display = 'none';
        }

        // Update Team Names in Display
        document.getElementById('battingTeamDisplay').innerText = m.batting_team_name;
        document.getElementById('bowlingTeamDisplay').innerText = m.bowling_team_name;

        // 2. Batting Section
        const p = data.current_players;

        // Striker
        if (p.striker && p.striker.id > 0) {
            const sStats = p.striker.match_stats || { runs_scored: 0, balls_faced: 0, fours: 0, sixes: 0, strike_rate: 0 };
            document.getElementById('displayStriker').innerText = p.striker.name || ('Player ' + p.striker.id);
            document.getElementById('strRuns').innerText = sStats.runs_scored || 0;
            document.getElementById('strBalls').innerText = sStats.balls_faced || 0;
            document.getElementById('str4s').innerText = sStats.fours || 0;
            document.getElementById('str6s').innerText = sStats.sixes || 0;
            document.getElementById('strSR').innerText = parseFloat(sStats.strike_rate || 0).toFixed(1);
        } else {
            document.getElementById('displayStriker').innerHTML = '<span class="text-muted fst-italic">Select Striker</span>';
        }

        // Non-Striker
        if (p.non_striker && p.non_striker.id > 0) {
            const nsStats = p.non_striker.match_stats || { runs_scored: 0, balls_faced: 0, fours: 0, sixes: 0, strike_rate: 0 };
            document.getElementById('displayNonStriker').innerText = p.non_striker.name || ('Player ' + p.non_striker.id);
            document.getElementById('nonStrRuns').innerText = nsStats.runs_scored || 0;
            document.getElementById('nonStrBalls').innerText = nsStats.balls_faced || 0;
            document.getElementById('nonStr4s').innerText = nsStats.fours || 0;
            document.getElementById('nonStr6s').innerText = nsStats.sixes || 0;
            document.getElementById('nonStrSR').innerText = parseFloat(nsStats.strike_rate || 0).toFixed(1);
        } else {
            document.getElementById('displayNonStriker').innerHTML = '<span class="text-muted fst-italic">Select Non-Striker</span>';
        }

        // Filter Player Lists
        filterPlayerList('striker', p.non_striker ? p.non_striker.id : 0);
        filterPlayerList('non_striker', p.striker ? p.striker.id : 0);

        // 3. Bowling Section
        if (p.bowler && p.bowler.id > 0) {
            const bStats = p.bowler.match_stats || { overs_bowled: '0.0', runs_conceded: 0, wickets_taken: 0 };
            document.getElementById('displayBowler').innerText = p.bowler.name;
            document.getElementById('bowlOvers').innerText = bStats.overs_bowled || '0.0';
            document.getElementById('bowlRuns').innerText = bStats.runs_conceded || 0;
            document.getElementById('bowlWickets').innerText = bStats.wickets_taken || 0;
        } else {
            document.getElementById('displayBowler').innerHTML = '<span class="text-muted fst-italic">Select Bowler</span>';
        }

        // 3.5 Sync Squad Modals
        syncSquadsUI(data.squads);

        // Filter Player Lists
        filterPlayerList('striker', p.non_striker ? p.non_striker.id : 0);
        filterPlayerList('non_striker', p.striker ? p.striker.id : 0);

        // Filter Bowler List (Last over bowler)
        if (data.last_over_bowler_id) {
            filterBowlerList(data.last_over_bowler_id);
        }

        // 4. Current Over Bubbles
        const bubbles = document.getElementById('currentOverBubbles');
        bubbles.innerHTML = '';

        // Reset "This Over" display if over is complete and a new bowler has been selected
        let overBalls = data.current_over || [];
        const currentBowler = p.bowler ? p.bowler.id : null;
        if (overBalls.length >= 6 && currentBowler) {
            // Check if the balls in the current_over array belong to the current bowler
            // If they belong to someone else, it means the over just finished and we picked a new bowler
            const lastBallBowlerId = overBalls[overBalls.length - 1].bowler_id;
            if (lastBallBowlerId != currentBowler) {
                overBalls = [];
            }
        }

        let ballCount = 0;
        if (overBalls.length > 0) {
            overBalls.forEach(ball => {
                const display = getBallDisplay(ball);

                const el = document.createElement('div');
                el.className = `current-over-ball border d-flex align-items-center justify-content-center fw-bold ${display.bg} ${display.compound ? 'current-over-ball-compound' : ''}`;
                el.innerText = display.text;
                bubbles.appendChild(el);

                if (ball.extra_type !== 'wide' && ball.extra_type !== 'no ball') {
                    ballCount++;
                }
            });
        }

        // Fill remaining slots up to 6 legal balls
        for (let i = ballCount; i < 6; i++) {
            const el = document.createElement('div');
            el.className = "current-over-ball bg-light border d-flex align-items-center justify-content-center text-muted";
            el.innerText = '-';
            bubbles.appendChild(el);
        }

        // 5. Innings Control Buttons State (End Innings)
        const endBtn = document.getElementById('endInningsBtn');
        const isBreak = s.is_break;
        const inningNum = s.inning_number;

        // "End Inning" button should be enabled ONLY during active play of the 1st innings of each phase
        // Phase 1 (Normal Match 1st Innings) = Inning 1
        // Phase 2 (Super Over 1st Innings) = Inning 3
        const isInningPhase1 = (parseInt(inningNum) === 1 || parseInt(inningNum) === 3);
        const isLocked = (s.is_break == 1 || s.is_finished == 1 || data.match_info.status === 'completed');

        endBtn.disabled = (!isInningPhase1 || isLocked);

        // "Match Completed" button management - Always on until match is fully completed
        const matchBtn = document.getElementById('endMatchBtn');
        if (matchBtn) {
            matchBtn.disabled = (data.match_info.status === 'completed' || isEndingMatch);
        }

        // Disable all scoring controls if in break, match completed, overs completed, or PAUSED
        const isMatchCompleted = (data.match_info.status === 'completed');
        const isMatchFinished = data.score.is_finished;
        const isOversCompleted = (parseFloat(s.overs) >= parseFloat(s.total_overs));
        const isPaused = data.match_info.is_paused == 1;
        const controlsDisabled = isBreak || isMatchCompleted || isMatchFinished || isOversCompleted || isPaused;

        // Update Pause Button state
        const pauseBtn = document.getElementById('pauseMatchBtn');
        if (isPaused) {
            pauseBtn.innerHTML = '<i class="fas fa-play me-1"></i>Resume Match';
            pauseBtn.classList.remove('btn-warning');
            pauseBtn.classList.add('btn-success');
        } else {
            pauseBtn.innerHTML = '<i class="fas fa-pause me-1"></i>Pause Match';
            pauseBtn.classList.remove('btn-success');
            pauseBtn.classList.add('btn-warning');
        }
        // Pause button should be clickable unless match is fully over
        pauseBtn.disabled = isMatchCompleted;

        document.querySelectorAll('.scoring-btn').forEach(btn => {
            btn.disabled = controlsDisabled;
        });

        // Specific Inning-Based Button Restrictions
        if (!controlsDisabled) {
            const targetBtn = document.getElementById('targetOverlayBtn');
            const projectedBtn = document.getElementById('projectedScoreBtn');

            if (targetBtn) targetBtn.disabled = (inningNum != 2);
            if (projectedBtn) projectedBtn.disabled = (inningNum != 1);

            // Disable Menu Options in Super Over
            const menuBtn = document.getElementById('menuOptionsBtn');
            if (menuBtn) {
                if (inningNum >= 3) {
                    menuBtn.disabled = true;
                    menuBtn.title = "Menu options are disabled in Super Over";
                    menuBtn.style.pointerEvents = 'none'; // Visual "not clickable" feel
                    menuBtn.style.opacity = '0.6';
                } else {
                    menuBtn.disabled = controlsDisabled;
                    menuBtn.title = "";
                    menuBtn.style.pointerEvents = 'auto';
                    menuBtn.style.opacity = '1';
                }
            }
        }

        // Also disable player/bowler change buttons
        document.querySelectorAll('[data-bs-toggle="modal"]').forEach(btn => {
            // Except the ones that might be needed (though usually none are needed during break)
            btn.disabled = controlsDisabled;
        });

        // Overlay Restoration (STOP Button)
        const activeOType = data.match_info.overlay_type;
        const overlayCtrl = document.getElementById('overlayControl');
        if (activeOType && ['batting_team', 'bowling_team', 'partnership', 'comparison_graph', 'target', 'projected_score', 'runs_wickets_graph', 'batting_scorecard', 'bowler_scorecard', 'upcoming_matches', 'previous_matches', 'next_match'].includes(activeOType)) {
            let dName = 'Overlay';
            if (activeOType === 'batting_team') dName = 'Batting Team';
            else if (activeOType === 'bowling_team') dName = 'Bowling Team';
            else if (activeOType === 'partnership') dName = 'Partnership';
            else if (activeOType === 'comparison_graph') dName = 'Comparison Graph';
            else if (activeOType === 'target') dName = 'Target';
            else if (activeOType === 'projected_score') dName = 'Projected Score';
            else if (activeOType === 'runs_wickets_graph') dName = 'Runs & Wickets Graph';
            else if (activeOType === 'batting_scorecard') dName = 'Batting Scorecard';
            else if (activeOType === 'bowler_scorecard') dName = 'Bowler Scorecard';
            else if (activeOType === 'upcoming_matches') dName = 'Upcoming Matches';
            else if (activeOType === 'previous_matches') dName = 'Previous Matches';
            else if (activeOType === 'next_match') dName = 'Next Match';

            document.getElementById('activeOverlayName').innerText = dName;
            overlayCtrl.style.display = 'block';
        } else {
            overlayCtrl.style.display = 'none';
        }
    }

    function syncSquadsUI(squads) {
        if (!squads) return;

        // Determine which squad is batting and which is bowling
        const battingTeamId = currentData.score.batting_team_id; // Added later or check innings data
        // But get_live_data returns team1 and team2 squads. 
        // We can just iterate over all buttons in all 3 player modals and update them.

        const allSquadPlayers = [...squads.team1, ...squads.team2];
        const playerStatusMap = {};
        allSquadPlayers.forEach(p => {
            playerStatusMap[p.id] = { isOut: (p.is_out == 1), name: p.name };
        });

        // Update Batting Modals (Striker & Non-Striker)
        document.querySelectorAll('.player-option').forEach(btn => {
            const pid = btn.dataset.playerId;
            const status = playerStatusMap[pid];
            if (status) {
                btn.dataset.isOut = status.isOut ? '1' : '0';
                if (status.isOut) {
                    btn.classList.add('bg-light', 'text-muted', 'opacity-50');
                    btn.disabled = true;
                    if (!btn.querySelector('small')) {
                        btn.querySelector('.fw-bold').innerHTML += ' <small>(Out)</small>';
                    }
                } else {
                    btn.classList.remove('bg-light', 'text-muted', 'opacity-50');
                    btn.disabled = false;
                    const small = btn.querySelector('small');
                    if (small && small.innerText.includes('(Out)')) small.remove();
                }
            }
        });
    }

    function filterPlayerList(type, excludeId) {
        // Find modal for type
        let modalId = type === 'striker' ? '#strikerModal' : '#nonStrikerModal';
        let items = document.querySelectorAll(`${modalId} .player-option`);

        // Get current striker/non-striker from global data
        const strikerId = currentData && currentData.current_players.striker ? currentData.current_players.striker.id : null;
        const nonStrikerId = currentData && currentData.current_players.non_striker ? currentData.current_players.non_striker.id : null;

        items.forEach(item => {
            const pId = item.dataset.playerId;
            const isOut = item.dataset.isOut === '1';

            // Hide if player is OUT, or if player is already selected for the OTHER slot
            if (isOut) {
                item.classList.add('d-none');
            } else if (pId == excludeId) {
                item.classList.add('d-none');
            } else if (type === 'striker' && pId == nonStrikerId) {
                item.classList.add('d-none');
            } else if (type === 'non_striker' && pId == strikerId) {
                item.classList.add('d-none');
            } else {
                item.classList.remove('d-none');
            }
        });
    }

    function filterBowlerList(excludeId) {
        let items = document.querySelectorAll('#bowlerModal .bowler-option');
        items.forEach(item => {
            if (item.dataset.playerId == excludeId) {
                item.classList.add('d-none');
            } else {
                item.classList.remove('d-none');
            }
        });
    }

    function updatePlayer(type, playerId) {
        if (!currentData || !currentData.current_players) return;

        // Prevent selecting same player for both roles
        const strikerId = currentData.current_players.striker ? currentData.current_players.striker.id : null;
        const nonStrikerId = currentData.current_players.non_striker ? currentData.current_players.non_striker.id : null;

        if (type === 'striker' && playerId == nonStrikerId) {
            alert("Player is already Non-Striker!");
            return;
        }
        if (type === 'non_striker' && playerId == strikerId) {
            alert("Player is already Striker!");
            return;
        }

        if (!playerId || playerId == 0 || playerId == "undefined") {
            console.warn("Attempted to update player with invalid ID", { type, playerId });
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_player');
        formData.append('type', type);
        formData.append('player_id', playerId);

        sendRequest(formData, () => {
            closeModals();
        });
    }

    function swapBatters() {
        if (!confirm("Swap Striker and Non-Striker?")) return;
        const formData = new FormData();
        formData.append('action', 'swap_batters');
        sendRequest(formData);
    }

    function togglePause() {
        const isCurrentlyPaused = currentData && currentData.match_info && currentData.match_info.is_paused == 1;
        const action = isCurrentlyPaused ? 'resume_match' : 'pause_match';
        const msg = isCurrentlyPaused ? "Resume match scoring?" : "Pause match? This will display a 'Time Break' overlay.";

        if (!confirm(msg)) return;

        const formData = new FormData();
        formData.append('action', action);
        sendRequest(formData);
    }

    function endInnings() {
        if (!confirm("Are you sure you want to END the current innings?")) return;
        const formData = new FormData();
        formData.append('action', 'end_innings');
        sendRequest(formData, (data) => {
            alert("Innings Ended! Final Score: " + data.data.final_score + "/" + data.data.wickets);
            fetchData();
        });
    }

    function startSecondInnings() {
        if (!confirm("Start 2nd Innings now? Teams have been swapped.")) return;
        const formData = new FormData();
        formData.append('action', 'start_second_innings');
        sendRequest(formData, () => {
            alert("2nd Innings Started!");
            location.reload();
        });
    }

    function stopMatch() {
        if (!confirm("Stop match and move to Upcoming?")) return;
        const formData = new FormData();
        formData.append('action', 'stop_match');
        sendRequest(formData, () => {
            window.location.href = '../../NavBarList/matches.php';
        });
    }

    function validatePlayers() {
        if (!currentData || !currentData.current_players) return false;
        const p = currentData.current_players;
        if (!p.striker || !p.striker.id || p.striker.id == 0) {
            alert("Select Striker");
            return false;
        }
        if (!p.non_striker || !p.non_striker.id || p.non_striker.id == 0) {
            alert("Select Non-Striker");
            return false;
        }
        if (!p.bowler || !p.bowler.id || p.bowler.id == 0) {
            alert("Select Bowler");
            return false;
        }
        return true;
    }

    function addRuns(runs) {
        if (!validatePlayers()) return;
        const formData = new FormData();
        formData.append('action', 'score_update');
        formData.append('runs', runs);
        formData.append('is_wicket', 'false');
        sendRequest(formData);
    }

    function shortExtraLabel(type) {
        return type === 'wide' ? 'WD' : 'NB';
    }

    function normalizeExtraType(type) {
        return type === 'wide' ? 'wide' : 'no ball';
    }

    function resetPendingExtra() {
        pendingExtraType = null;
    }

    function openExtraOverlay(type) {
        if (!validatePlayers()) return;
        pendingExtraType = normalizeExtraType(type);
        pendingRunOutRuns = 0;

        const currentLabel = shortExtraLabel(pendingExtraType);
        const switchType = pendingExtraType === 'wide' ? 'no ball' : 'wide';
        const switchLabel = shortExtraLabel(switchType);

        document.getElementById('extraModalTitle').innerText = `${currentLabel} Details`;
        document.getElementById('extraRunsLabel').innerText = pendingExtraType === 'wide' ? 'Runs completed' : 'Batter runs';
        document.getElementById('extraSwitchBtn').innerText = switchLabel;
        document.getElementById('extraSwitchBtn').dataset.extraType = switchType;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('extraModal')).show();
    }

    function switchExtraType() {
        const switchBtn = document.getElementById('extraSwitchBtn');
        openExtraOverlay(switchBtn.dataset.extraType || 'wide');
    }

    function scoreExtraRuns(runs) {
        if (!pendingExtraType) return;
        addExtra(pendingExtraType, runs);
    }

    function addExtra(type, runs = 0) {
        if (!validatePlayers()) return;
        const formData = new FormData();
        formData.append('action', 'score_update');
        formData.append('runs', runs);
        formData.append('is_wicket', 'false');
        formData.append('extra_type', type);
        sendRequest(formData, () => {
            closeModals();
            resetPendingExtra();
        });
    }

    function openWicketModal() {
        if (!validatePlayers()) return;
        pendingWicketType = null;
        pendingWicketPlayerId = null;
        pendingRunOutRuns = 0;
        resetPendingExtra();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('wicketModal')).show();
    }

    function openExtraWicketModal() {
        if (!validatePlayers() || !pendingExtraType) return;
        pendingWicketType = null;
        pendingWicketPlayerId = null;
        pendingRunOutRuns = 0;

        const extraModal = bootstrap.Modal.getInstance(document.getElementById('extraModal'));
        if (extraModal) extraModal.hide();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('wicketModal')).show();
    }

    // Wicket Handling
    function initWicket(type) {
        if (!validatePlayers()) return;
        pendingWicketType = type;

        // 1. Hide Wicket Choice Modal
        const wModal = bootstrap.Modal.getInstance(document.getElementById('wicketModal'));
        if (wModal) wModal.hide();

        // 2. Specialized Flow
        if (type === 'run out') {
            // Need to know who is out: Striker or Non-Striker?
            const container = document.getElementById('runOutOptions');
            const p = currentData.current_players;
            container.innerHTML = '';

            if (p.striker) {
                container.innerHTML += `<button class="btn btn-outline-danger py-3 fw-bold" onclick="selectRunOutPlayer(${p.striker.id}, '${p.striker.name.replace(/'/g, "\\'")}')">${p.striker.name} (Striker)</button>`;
            }
            if (p.non_striker) {
                container.innerHTML += `<button class="btn btn-outline-danger py-3 fw-bold" onclick="selectRunOutPlayer(${p.non_striker.id}, '${p.non_striker.name.replace(/'/g, "\\'")}')">${p.non_striker.name} (Non-Striker)</button>`;
            }

            new bootstrap.Modal(document.getElementById('runOutPlayerModal')).show();
        } else if (['caught', 'stumped'].includes(type)) {
            // Need Fielder
            new bootstrap.Modal(document.getElementById('fielderModal')).show();
        } else {
            // Direct Wicket (Bowled, LBW, Hit Wicket)
            addWicket(type);
        }
    }

    // Capture who was run out
    function selectRunOutPlayer(playerId, playerName) {
        pendingWicketPlayerId = playerId;
        bootstrap.Modal.getInstance(document.getElementById('runOutPlayerModal')).hide();
        // Now ask for runs before run-out
        new bootstrap.Modal(document.getElementById('runOutRunsModal')).show();
    }

    function selectRunOutRuns(runs) {
        pendingRunOutRuns = runs;
        bootstrap.Modal.getInstance(document.getElementById('runOutRunsModal')).hide();
        // Finally ask for fielder
        new bootstrap.Modal(document.getElementById('fielderModal')).show();
    }

    function selectFielder(fielderId) {
        if (pendingWicketType) {
            addWicket(pendingWicketType, fielderId, pendingWicketPlayerId);
        }
    }

    function addWicket(type, fielderId = null, wicketPlayerId = null) {
        const formData = new FormData();
        formData.append('action', 'score_update');
        formData.append('runs', pendingRunOutRuns); // Allocation of runs for run-out
        formData.append('is_wicket', 'true');
        formData.append('wicket_type', type);
        if (pendingExtraType) formData.append('extra_type', pendingExtraType);
        if (fielderId) formData.append('fielder_id', fielderId);
        if (wicketPlayerId) formData.append('wicket_player_id', wicketPlayerId);

        sendRequest(formData, () => {
            closeModals();
            pendingWicketType = null;
            pendingWicketPlayerId = null;
            pendingRunOutRuns = 0;
            resetPendingExtra();
        });
    }

    function performUndo() {
        if (!confirm("Undo last action?")) return;
        const formData = new FormData();
        formData.append('action', 'undo');
        sendRequest(formData);
    }

    function endMatch() {
        if (isEndingMatch) return;
        if (!confirm("Are you sure you want to END the match? This cannot be undone.")) return;

        setEndMatchLoading(true);

        const formData = new FormData();
        formData.append('action', 'end_match');
        let shouldResetButton = true;

        sendRequest(formData, () => {
            shouldResetButton = false;
            alert('Match Ended!');
            window.location.href = '../../view_match_summary.php?id=' + MATCH_ID;
        }, () => {
            if (shouldResetButton) {
                setEndMatchLoading(false);
            }
        });
    }

    function setEndMatchLoading(isLoading) {
        isEndingMatch = isLoading;
        const button = document.getElementById('endMatchBtn');
        if (!button) return;

        button.disabled = isLoading;
        button.innerHTML = isLoading
            ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Completing...'
            : 'Match Completed';
    }

    function startSuperOver() {
        if (!confirm("Start Super Over? This will reset players for a 1-over tie-break.")) return;
        const formData = new FormData();
        formData.append('action', 'start_super_over');
        sendRequest(formData, () => {
            alert('Super Over Initiated!');
            location.reload();
        });
    }

    function startSuperOver2nd() {
        if (!confirm("Start Super Over 2nd Innings? Teams will be swapped.")) return;
        const formData = new FormData();
        formData.append('action', 'start_super_over_2nd');
        sendRequest(formData, () => {
            alert('Super Over 2nd Innings Started!');
            location.reload();
        });
    }

    function triggerOverlay(type) {
        const formData = new FormData();
        formData.append('action', 'trigger_overlay');
        formData.append('overlay_type', type);
        sendRequest(formData, () => {
            closeModals();
            // Show stop control for certain overlays
            if (['batting_team', 'bowling_team', 'partnership', 'comparison_graph', 'target', 'projected_score', 'runs_wickets_graph', 'batting_scorecard', 'bowler_scorecard', 'upcoming_matches', 'previous_matches', 'next_match'].includes(type)) {
                let displayName = 'Overlay';
                if (type === 'batting_team') displayName = 'Batting Team';
                else if (type === 'bowling_team') displayName = 'Bowling Team';
                else if (type === 'partnership') displayName = 'Partnership';
                else if (type === 'comparison_graph') displayName = 'Comparison Graph';
                else if (type === 'target') displayName = 'Target';
                else if (type === 'projected_score') displayName = 'Projected Score';
                else if (type === 'runs_wickets_graph') displayName = 'Runs & Wickets Graph';
                else if (type === 'batting_scorecard') displayName = 'Batting Scorecard';
                else if (type === 'bowler_scorecard') displayName = 'Bowler Scorecard';
                else if (type === 'upcoming_matches') displayName = 'Upcoming Matches';
                else if (type === 'previous_matches') displayName = 'Previous Matches';
                else if (type === 'next_match') displayName = 'Next Match';

                document.getElementById('activeOverlayName').innerText = displayName;
                document.getElementById('overlayControl').style.display = 'block';
            } else {
                alert(type.charAt(0).toUpperCase() + type.slice(1) + ' overlay triggered!');
            }
        });
    }

    function stopOverlay() {
        const formData = new FormData();
        formData.append('action', 'trigger_overlay');
        formData.append('overlay_type', 'clear');
        sendRequest(formData, () => {
            document.getElementById('overlayControl').style.display = 'none';
        });
    }

    function closeModals() {
        var modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            var bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) bsModal.hide();
        });
    }

    // Add listeners to filter lists when modals are shown
    document.addEventListener('DOMContentLoaded', function () {
        const sModal = document.getElementById('strikerModal');
        const nsModal = document.getElementById('nonStrikerModal');
        const bModal = document.getElementById('bowlerModal');

        if (sModal) {
            sModal.addEventListener('show.bs.modal', function () {
                const nsId = currentData && currentData.current_players.non_striker ? currentData.current_players.non_striker.id : null;
                filterPlayerList('striker', nsId);
            });
        }
        if (nsModal) {
            nsModal.addEventListener('show.bs.modal', function () {
                const sId = currentData && currentData.current_players.striker ? currentData.current_players.striker.id : null;
                filterPlayerList('non_striker', sId);
            });
        }
        if (bModal) {
            bModal.addEventListener('show.bs.modal', function () {
                filterBowlerList(currentData ? currentData.last_over_bowler_id : null);
            });
        }
    });

    function sendRequest(formData, callback, finalCallback) {
        fetch('update_score.php?id=' + MATCH_ID, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (callback) callback();
                    fetchData();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(console.error)
            .finally(() => {
                if (finalCallback) finalCallback();
            });
    }
</script>

<style>
    #menuOptionsModal .modal-dialog {
        max-width: 680px;
    }

    #menuOptionsModal .modal-content {
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.24);
    }

    .overlay-options-grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .overlay-btn {
        align-items: center;
        background: var(--overlay-gradient, linear-gradient(135deg, #2563eb 0%, #06b6d4 100%));
        border: none;
        border-radius: 14px;
        box-shadow: 0 10px 18px rgba(15, 23, 42, 0.16);
        color: white !important;
        display: flex;
        font-size: 0.82rem;
        gap: 8px;
        justify-content: center;
        letter-spacing: 0.5px;
        line-height: 1.15;
        min-height: 64px;
        overflow: hidden;
        padding: 12px 10px !important;
        position: relative;
        text-align: center;
        text-transform: uppercase;
        transition: all 0.3s ease;
        white-space: normal;
        width: 100%;
    }

    .overlay-btn::after {
        background: linear-gradient(120deg, transparent 0%, rgba(255, 255, 255, 0.22) 48%, transparent 100%);
        content: "";
        inset: 0;
        opacity: 0;
        pointer-events: none;
        position: absolute;
        transition: opacity 0.25s ease;
    }

    .overlay-btn:nth-child(1) {
        --overlay-gradient: linear-gradient(135deg, #2563eb 0%, #06b6d4 100%);
    }

    .overlay-btn:nth-child(2) {
        --overlay-gradient: linear-gradient(135deg, #7c3aed 0%, #ec4899 100%);
    }

    .overlay-btn:nth-child(3) {
        --overlay-gradient: linear-gradient(135deg, #059669 0%, #84cc16 100%);
    }

    .overlay-btn:nth-child(4) {
        --overlay-gradient: linear-gradient(135deg, #ea580c 0%, #f59e0b 100%);
    }

    .overlay-btn:nth-child(5) {
        --overlay-gradient: linear-gradient(135deg, #dc2626 0%, #f97316 100%);
    }

    .overlay-btn:nth-child(6) {
        --overlay-gradient: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
    }

    .overlay-btn:nth-child(7) {
        --overlay-gradient: linear-gradient(135deg, #4f46e5 0%, #8b5cf6 100%);
    }

    .overlay-btn:nth-child(8) {
        --overlay-gradient: linear-gradient(135deg, #0369a1 0%, #38bdf8 100%);
    }

    .overlay-btn:nth-child(9) {
        --overlay-gradient: linear-gradient(135deg, #be123c 0%, #fb7185 100%);
    }

    .overlay-btn:nth-child(10) {
        --overlay-gradient: linear-gradient(135deg, #15803d 0%, #22c55e 100%);
    }

    .overlay-btn:nth-child(11) {
        --overlay-gradient: linear-gradient(135deg, #9333ea 0%, #2563eb 100%);
    }

    .overlay-btn:nth-child(12) {
        --overlay-gradient: linear-gradient(135deg, #475569 0%, #111827 100%);
    }

    .overlay-btn:hover {
        background: var(--overlay-gradient, linear-gradient(135deg, #2563eb 0%, #06b6d4 100%));
        filter: brightness(1.05);
        transform: translateY(-2px);
        box-shadow: 0 14px 24px rgba(15, 23, 42, 0.22);
    }

    .overlay-btn:hover::after {
        opacity: 1;
    }

    .overlay-btn:active {
        transform: translateY(0);
    }

    .overlay-btn:disabled,
    .overlay-btn.disabled {
        cursor: not-allowed;
        filter: grayscale(0.2);
        opacity: 0.55;
        transform: none;
    }

    .overlay-btn i {
        flex-shrink: 0;
        font-size: 1rem;
        margin-right: 0 !important;
        position: relative;
        z-index: 1;
    }

    .commentary-control-card {
        border: 1px solid rgba(25, 135, 84, 0.2);
        border-radius: 16px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fffb 100%);
        padding: 16px;
        box-shadow: 0 10px 24px rgba(20, 83, 45, 0.08);
    }

    .commentary-kicker {
        color: #198754;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .commentary-status-line {
        align-items: center;
        color: #263238;
        display: flex;
        font-size: 0.92rem;
        font-weight: 700;
        gap: 8px;
        margin-top: 4px;
    }

    .commentary-dot {
        background: #adb5bd;
        border-radius: 50%;
        display: inline-block;
        height: 10px;
        width: 10px;
    }

    .commentary-live-pill {
        background: #eef2f7;
        border-radius: 999px;
        color: #475569;
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        padding: 6px 9px;
    }

    .commentary-meta-grid {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .commentary-meta-grid div {
        background: #f6f8fa;
        border-radius: 12px;
        padding: 10px 12px;
    }

    .commentary-meta-grid span {
        color: #64748b;
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .commentary-meta-grid strong {
        color: #111827;
        display: block;
        font-size: 1rem;
        line-height: 1.2;
    }

    .commentary-control-card.is-live {
        border-color: rgba(25, 135, 84, 0.45);
    }

    .commentary-control-card.is-live .commentary-dot {
        animation: commentaryPulse 1.4s infinite;
        background: #22c55e;
    }

    .commentary-control-card.is-live .commentary-live-pill {
        background: #dcfce7;
        color: #166534;
    }

    .commentary-control-card.is-connecting .commentary-dot {
        background: #f59e0b;
    }

    .commentary-control-card.is-error {
        border-color: rgba(220, 53, 69, 0.45);
    }

    .commentary-control-card.is-error .commentary-dot {
        background: #dc3545;
    }

    .commentary-control-card.is-error .commentary-live-pill {
        background: #fee2e2;
        color: #991b1b;
    }

    .current-over-ball {
        border-radius: 50%;
        font-size: 0.8rem;
        height: 35px;
        line-height: 1;
        min-width: 35px;
        width: 35px;
    }

    .current-over-ball-compound {
        border-radius: 999px;
        font-size: 0.68rem;
        min-width: 50px;
        padding: 0 7px;
        width: auto;
    }

    @keyframes commentaryPulse {
        0% {
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45);
        }

        70% {
            box-shadow: 0 0 0 8px rgba(34, 197, 94, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
        }
    }

    @media (max-width: 480px) {
        #menuOptionsModal .modal-dialog {
            margin: 0.75rem;
        }

        #menuOptionsModal .modal-body {
            padding: 1rem !important;
        }

        .overlay-options-grid {
            gap: 9px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .overlay-btn {
            border-radius: 12px;
            font-size: 0.72rem !important;
            min-height: 58px;
            padding: 10px 7px !important;
        }

        .overlay-btn i {
            font-size: 0.86rem !important;
        }

        /* Global Scale Reduction */
        .container-fluid.py-4 {
            padding-top: 10px !important;
            padding-bottom: 20px !important;
        }

        /* 1. Header Section - Compact */
        .card.mb-4 {
            margin-bottom: 15px !important;
        }

        .card-body.p-4 {
            padding: 15px !important;
        }

        /* Team Logos & Names */
        img[style*="width: 60px"] {
            width: 40px !important;
            height: 40px !important;
        }

        h4.fw-bold {
            font-size: 1rem !important;
        }

        .badge {
            font-size: 0.65rem !important;
            padding: 4px 6px !important;
        }

        h2.text-muted {
            font-size: 1.2rem !important;
            margin: 0 5px !important;
        }

        /* 2. Score Section - Compact */
        h1.display-1 {
            font-size: 2.5rem !important;
        }

        .fs-5 {
            font-size: 0.9rem !important;
        }

        .fs-4 {
            font-size: 1.1rem !important;
        }

        .letter-spacing-2 {
            font-size: 0.7rem !important;
            letter-spacing: 1px !important;
        }

        hr.my-4 {
            margin: 10px 0 !important;
        }

        /* 3. Player Cards - Compact */
        .card-header.pt-4.px-4 {
            padding: 10px 15px !important;
        }

        .card-header h5 {
            font-size: 0.9rem !important;
        }

        .btn-sm {
            font-size: 0.65rem !important;
            padding: 2px 8px !important;
        }

        /* Stats Display */
        #displayStriker,
        #displayNonStriker,
        #displayBowler {
            font-size: 1rem !important;
        }

        .small.text-muted {
            font-size: 0.7rem !important;
        }

        .mb-3.pb-3 {
            margin-bottom: 8px !important;
            padding-bottom: 8px !important;
        }

        /* Bubbles */
        .rounded-circle[style*="width: 35px"] {
            width: 25px !important;
            height: 25px !important;
            font-size: 0.65rem !important;
        }

        .current-over-ball {
            height: 25px;
            min-width: 25px;
            width: 25px;
            font-size: 0.65rem;
        }

        .current-over-ball-compound {
            min-width: 42px;
            padding: 0 5px;
            width: auto;
            font-size: 0.58rem;
        }

        /* 4. Controls - 60% Size Reduction Concept */
        .btn-group .btn {
            font-size: 0.75rem !important;
            padding: 6px 5px !important;
        }

        .run-btn {
            font-size: 1rem !important;
            padding: 10px 0 !important;
            height: auto !important;
        }

        h6.fw-bold {
            font-size: 0.85rem !important;
            margin-bottom: 8px !important;
        }

        .row.g-2 {
            --bs-gutter-y: 0.3rem !important;
            --bs-gutter-x: 0.3rem !important;
        }

        /* Stack buttons more tightly */
        .btn.py-3 {
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }

        .btn.fs-5 {
            font-size: 0.9rem !important;
        }

        /* Modals */
        .modal-body .p-3 {
            padding: 0.5rem !important;
        }

        .player-option .fw-bold,
        .bowler-option .fw-bold {
            font-size: 0.8rem !important;
        }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>
