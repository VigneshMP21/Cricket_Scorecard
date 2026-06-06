<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

$source = isset($_GET['source']) ? $_GET['source'] : 'all'; // 'all' or 'my'
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$upcomingMatches = [];
$liveMatches = [];
$completedMatches = [];

try {
    if ($source === 'my' && $user_id > 0) {
        // Personal matches logic from my_matches.php
        $stmt = $pdo->prepare("
            SELECT t.id as team_id FROM team_players tp
            JOIN teams t ON tp.team_id = t.id
            WHERE tp.player_id = ?
        ");
        $stmt->execute([$user_id]);
        $teamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Also check legacy
        $stmt = $pdo->prepare("SELECT team_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $legacyId = $stmt->fetchColumn();
        if ($legacyId && !in_array($legacyId, $teamIds))
            $teamIds[] = $legacyId;

        if (!empty($teamIds)) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql = "
                SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo,
                       t2.team_name as team2_name, t2.team_logo as team2_logo,
                       tr.tournament_name
                FROM matches m
                LEFT JOIN teams t1 ON m.team1_id = t1.id
                LEFT JOIN teams t2 ON m.team2_id = t2.id
                LEFT JOIN tournaments tr ON m.tournament_id = tr.id
                WHERE m.team1_id IN ($placeholders) OR m.team2_id IN ($placeholders)
                ORDER BY m.match_date DESC, m.match_time DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($teamIds, $teamIds));
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($all as $match) {
                if ($match['status'] === 'upcoming')
                    $upcomingMatches[] = $match;
                elseif ($match['status'] === 'ongoing' || $match['status'] === 'live')
                    $liveMatches[] = $match;
                elseif ($match['status'] === 'completed')
                    $completedMatches[] = $match;
            }
        }
    } else {
        // General matches logic from matches.php
        $upcomingMatches = $pdo->query("
            SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo,
                   t2.team_name as team2_name, t2.team_logo as team2_logo, tr.tournament_name
            FROM matches m
            LEFT JOIN teams t1 ON m.team1_id = t1.id
            LEFT JOIN teams t2 ON m.team2_id = t2.id
            LEFT JOIN tournaments tr ON m.tournament_id = tr.id
            WHERE m.status = 'upcoming' ORDER BY m.match_date ASC, m.match_time ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $liveMatches = $pdo->query("
            SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo,
                   t2.team_name as team2_name, t2.team_logo as team2_logo, tr.tournament_name
            FROM matches m
            LEFT JOIN teams t1 ON m.team1_id = t1.id
            LEFT JOIN teams t2 ON m.team2_id = t2.id
            LEFT JOIN tournaments tr ON m.tournament_id = tr.id
            WHERE m.status = 'ongoing' ORDER BY m.match_date DESC, m.match_time DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $completedMatches = $pdo->query("
            SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo,
                   t2.team_name as team2_name, t2.team_logo as team2_logo, tr.tournament_name
            FROM matches m
            LEFT JOIN teams t1 ON m.team1_id = t1.id
            LEFT JOIN teams t2 ON m.team2_id = t2.id
            LEFT JOIN tournaments tr ON m.tournament_id = tr.id
            WHERE m.status = 'completed' ORDER BY m.match_date DESC, m.match_time DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    die(json_encode(['error' => $e->getMessage()]));
}

// Function to render Upcoming match card
function renderUpcoming($match, $is_admin)
{
    ob_start(); ?>
    <div class="match-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom-0 pt-3 pb-0 px-3 d-flex justify-content-between align-items-start">
            <span class="badge bg-light text-primary border border-primary-subtle rounded-pill px-3">
                <?= htmlspecialchars($match['match_type'] ?? 'Match') ?>
            </span>
            <?php if ($match['tournament_name']): ?>
                <small class="text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">
                    <?= htmlspecialchars($match['tournament_name']) ?>
                </small>
            <?php endif; ?>
        </div>
        <div class="card-body p-3">
            <div class="row align-items-center text-center">
                <div class="col-4">
                    <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border shadow-sm"
                        style="width: 50px; height: 50px; object-fit: contain;">
                    <div class="team-name fw-bold text-dark text-truncate mt-2" style="font-size: 0.85rem;">
                        <?= htmlspecialchars($match['team1_name']) ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="badge bg-warning text-dark rounded-circle shadow-sm d-flex align-items-center justify-content-center mx-auto"
                        style="width: 40px; height: 40px; font-weight: 800;">VS</div>
                    <div class="small text-muted mt-2 fw-bold" style="font-size: 0.7rem;">
                        <?= date('H:i', strtotime($match['match_time'])) ?>
                    </div>
                    <div class="small text-muted" style="font-size: 0.7rem;">
                        <?= date('d M', strtotime($match['match_date'])) ?>
                    </div>
                </div>
                <div class="col-4">
                    <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border shadow-sm"
                        style="width: 50px; height: 50px; object-fit: contain;">
                    <div class="team-name fw-bold text-dark text-truncate mt-2" style="font-size: 0.85rem;">
                        <?= htmlspecialchars($match['team2_name']) ?>
                    </div>
                </div>
            </div>
            <div class="text-center mt-3 pt-3 border-top border-light">
                <small class="text-muted d-block mb-2"><i class="fas fa-map-marker-alt me-1 text-danger"></i>
                    <?= htmlspecialchars($match['venue']) ?>
                </small>
                <?php if ($is_admin): ?>
                    <div class="d-grid gap-2">
                        <a href="../admin/start_match/initialize_match.php?id=<?= $match['id'] ?>"
                            class="btn btn-sm btn-success rounded-pill fw-bold"><i class="fas fa-play me-1"></i>Start Match</a>
                        <div class="btn-group w-100">
                            <a href="../view/view_match.php?id=<?= $match['id'] ?>" class="btn btn-sm btn-outline-primary"><i
                                    class="fas fa-eye"></i></a>
                            <a href="../edit/edit_match.php?id=<?= $match['id'] ?>" class="btn btn-sm btn-outline-warning"><i
                                    class="fas fa-edit"></i></a>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="deleteMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"><i
                                    class="fas fa-trash"></i></button>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="../view/view_match.php?id=<?= $match['id'] ?>"
                        class="btn btn-sm btn-outline-primary w-100 rounded-pill fw-bold">View Details</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

// Function to render Live match card
function renderLive($match, $is_admin, $source)
{
    ob_start(); ?>
    <div class="match-card live-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
        <div
            class="card-header bg-danger text-white border-bottom-0 pt-2 pb-2 px-3 d-flex justify-content-between align-items-center">
            <span class="badge bg-white text-danger fw-bold animate-pulse"><i class="fas fa-circle me-1 small"></i>
                LIVE</span>
            <small class="fw-bold opacity-75">
                <?= $match['overs'] ?> Overs
            </small>
        </div>
        <div class="card-body p-3">
            <div class="row align-items-center text-center">
                <div class="col-4">
                    <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border border-2 border-white shadow-sm"
                        style="width: 55px; height: 55px; object-fit: contain;">
                    <div class="team-name fw-bold text-dark text-truncate mt-2" style="font-size: 0.85rem;">
                        <?= htmlspecialchars($match['team1_name']) ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="badge bg-danger text-white rounded-circle shadow-lg d-flex align-items-center justify-content-center mx-auto animate-pulse-shadow"
                        style="width: 45px; height: 45px; font-weight: 800;">VS</div>
                </div>
                <div class="col-4">
                    <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border border-2 border-white shadow-sm"
                        style="width: 55px; height: 55px; object-fit: contain;">
                    <div class="team-name fw-bold text-dark text-truncate mt-2" style="font-size: 0.85rem;">
                        <?= htmlspecialchars($match['team2_name']) ?>
                    </div>
                </div>
            </div>
            <div class="text-center mt-3 pt-3 border-top border-light">
                <p class="mb-2 text-dark small fw-bold">
                    <?= htmlspecialchars($match['tournament_name'] ?? 'Friendly Match') ?>
                </p>
                <?php if ($is_admin && $source !== 'my'): ?>
                    <div class="d-grid gap-2">
                        <a href="../admin/start_match/continue_match.php?id=<?= $match['id'] ?>"
                            class="btn btn-sm btn-danger rounded-pill fw-bold shadow-sm"><i
                                class="fas fa-gamepad me-1"></i>Score Control</a>
                        <div class="btn-group w-100">
                            <a href="../view/view_match.php?id=<?= $match['id'] ?>" class="btn btn-sm btn-outline-primary"><i
                                    class="fas fa-eye"></i></a>
                            <button class="btn btn-sm btn-outline-secondary"
                                onclick="stopMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"><i
                                    class="fas fa-stop"></i></button>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="../live_stream/live_match.php?id=<?= $match['id'] ?>"
                        class="btn btn-sm btn-danger w-100 rounded-pill fw-bold shadow-sm animate-pulse-btn"><i
                            class="fas fa-play me-1"></i> Watch Live</a>
                    <div class="mt-2"><a href="../view/view_match.php?id=<?= $match['id'] ?>"
                            class="btn btn-sm btn-outline-secondary w-100 rounded-pill fw-bold">Details</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

// Function to render Completed match card
function renderCompleted($match, $is_admin)
{
    ob_start(); ?>
    <div class="match-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
        <div class="card-header bg-light border-bottom-0 pt-3 pb-0 px-3 d-flex justify-content-between align-items-center">
            <small class="text-muted fw-bold"><i class="far fa-calendar me-1"></i>
                <?= date('d M Y', strtotime($match['match_date'])) ?>
            </small>
            <span
                class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2">Finished</span>
        </div>
        <div class="card-body p-3">
            <div class="row align-items-center text-center mb-3">
                <div class="col-4">
                    <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border mb-1"
                        style="width: 40px; height: 40px; object-fit: contain; <?= ($match['winner_id'] == $match['team1_id']) ? 'border: 2px solid #198754;' : '' ?>">
                    <div
                        class="small fw-bold text-truncate <?= ($match['winner_id'] == $match['team1_id']) ? 'text-success' : 'text-muted' ?>">
                        <?= htmlspecialchars($match['team1_name']) ?>
                        <?php if ($match['winner_id'] == $match['team1_id']): ?><i
                                class="fas fa-check-circle ms-1 small"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="small fw-bold text-muted">VS</div>
                </div>
                <div class="col-4">
                    <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                        class="img-fluid rounded-circle border mb-1"
                        style="width: 40px; height: 40px; object-fit: contain; <?= ($match['winner_id'] == $match['team2_id']) ? 'border: 2px solid #198754;' : '' ?>">
                    <div
                        class="small fw-bold text-truncate <?= ($match['winner_id'] == $match['team2_id']) ? 'text-success' : 'text-muted' ?>">
                        <?= htmlspecialchars($match['team2_name']) ?>
                        <?php if ($match['winner_id'] == $match['team2_id']): ?><i
                                class="fas fa-check-circle ms-1 small"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="winner-banner bg-success-subtle text-success text-center py-2 rounded-3 mb-3">
                <small class="fw-bold">
                    <?php if ($match['winner_id']): ?>
                        <i class="fas fa-trophy me-1"></i>
                        <?= ($match['winner_id'] == $match['team1_id']) ? htmlspecialchars($match['team1_name']) : htmlspecialchars($match['team2_name']) ?>
                        Won
                    <?php elseif ($match['result'] == 'tie'): ?>
                        <i class="fas fa-handshake me-1"></i> Match Tied
                    <?php else: ?>
                        Match Draw/NR
                    <?php endif; ?>
                </small>
            </div>
            <div class="d-grid"><a href="../view_match_summary.php?id=<?= $match['id'] ?>"
                    class="btn btn-sm btn-outline-success rounded-pill"><i class="fas fa-chart-bar me-1"></i> Full
                    Scorecard</a></div>
            <?php if ($is_admin): ?>
                <div class="text-center mt-2">
                    <div class="btn-group btn-group-sm">
                        <a href="../edit/edit_match.php?id=<?= $match['id'] ?>" class="btn btn-ghost-secondary text-muted"><i
                                class="fas fa-edit"></i></a>
                        <button class="btn btn-ghost-danger text-danger"
                            onclick="deleteMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"><i
                                class="fas fa-trash"></i></button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

$upcomingHtml = '';
if (empty($upcomingMatches)) {
    $upcomingHtml = '<div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white"><div class="text-muted opacity-50 mb-3"><i class="fas fa-calendar-times fa-3x"></i></div><p class="text-muted fw-bold">No upcoming matches scheduled</p></div>';
} else {
    $upcomingHtml .= '<div class="matches-list">';
    foreach ($upcomingMatches as $m)
        $upcomingHtml .= renderUpcoming($m, $is_admin);
    $upcomingHtml .= '</div>';
}

$liveHtml = '';
if (empty($liveMatches)) {
    $liveHtml = '<div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white"><div class="text-muted opacity-50 mb-3"><i class="fas fa-video-slash fa-3x"></i></div><p class="text-muted fw-bold">No live matches currently</p></div>';
} else {
    $liveHtml .= '<div class="matches-list">';
    foreach ($liveMatches as $m)
        $liveHtml .= renderLive($m, $is_admin, $source);
    $liveHtml .= '</div>';
}

$completedHtml = '';
if (empty($completedMatches)) {
    $completedHtml = '<div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white"><div class="text-muted opacity-50 mb-3"><i class="fas fa-clipboard-check fa-3x"></i></div><p class="text-muted fw-bold">No completed matches yet</p></div>';
} else {
    $completedHtml .= '<div class="matches-list">';
    foreach ($completedMatches as $m)
        $completedHtml .= renderCompleted($m, $is_admin);
    $completedHtml .= '</div>';
}

header('Content-Type: application/json');
echo json_encode([
    'upcoming' => $upcomingHtml,
    'live' => $liveHtml,
    'completed' => $completedHtml
]);
