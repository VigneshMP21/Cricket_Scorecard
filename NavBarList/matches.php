<?php
require_once '../includes/db.php';
// Public access - no login required

// Handle success/error messages
$message = '';
$message_type = '';

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case '1':
            $message = 'Match scheduled successfully!';
            $message_type = 'success';
            break;
        case '2':
            $message = 'Match deleted successfully!';
            $message_type = 'success';
            break;
        case '3':
            $message = 'Match updated successfully!';
            $message_type = 'success';
            break;
        case '4':
            $message = 'Match stopped successfully! Moved to upcoming matches.';
            $message_type = 'success';
            break;
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case '1':
            $message = 'Database error occurred. Please try again.';
            $message_type = 'error';
            break;
        case '3':
            $message = 'Match not found.';
            $message_type = 'error';
            break;
        case '4':
            $message = 'Failed to stop match. Please try again.';
            $message_type = 'error';
            break;
    }
}

// Fetch matches by status
$upcomingMatches = [];
$liveMatches = [];
$completedMatches = [];

try {
    // Upcoming matches
    $stmt = $pdo->query("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               tr.tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.status = 'upcoming'
        ORDER BY m.match_date ASC, m.match_time ASC
    ");
    $upcomingMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Live matches
    $stmt = $pdo->query("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               tr.tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.status = 'ongoing'
        ORDER BY m.match_date DESC, m.match_time DESC
    ");
    $liveMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Completed matches
    $stmt = $pdo->query("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               tr.tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.status = 'completed'
        ORDER BY m.match_date DESC, m.match_time DESC
    ");
    $completedMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

$page_title = "Matches";
require_once '../includes/header.php';

$default_tab = !empty($liveMatches) ? 'live' : 'upcoming';
?>

<div class="container-fluid py-4"
    style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%); min-height: calc(100vh - 76px);">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-baseball-ball me-2 text-primary"></i>Matches</h2>
        <div class="d-flex gap-2">
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'player'): ?>
                <a href="../view/view_player_profile.php?player_id=<?= (int) $_SESSION['user_id'] ?>"
                    class="btn btn-white shadow-sm fw-bold">
                    <i class="fas fa-user me-1 text-primary"></i>My Profile
                </a>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                <a href="../admin/create_match.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="fas fa-plus me-1"></i>Schedule Match
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show rounded-4 shadow-sm border-0"
            role="alert">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Mobile Tabs Navigation -->
    <div class="mobile-tabs-nav mb-4 bg-white rounded-pill shadow-sm p-1 d-flex">
        <button
            class="btn btn-tab flex-grow-1 rounded-pill fw-bold py-2 <?= $default_tab === 'upcoming' ? 'active' : '' ?>"
            onclick="switchTab('upcoming', this)">Upcoming</button>
        <button class="btn btn-tab flex-grow-1 rounded-pill fw-bold py-2 <?= $default_tab === 'live' ? 'active' : '' ?>"
            onclick="switchTab('live', this)">Live</button>
        <button
            class="btn btn-tab flex-grow-1 rounded-pill fw-bold py-2 <?= $default_tab === 'Completed' ? 'active' : '' ?>"
            onclick="switchTab('Completed', this)">Completed</button>
    </div>

    <div class="row g-4">
        <div class="col-lg-4 col-md-6 match-section-col <?= $default_tab === 'upcoming' ? 'active-tab' : '' ?>"
            id="upcoming-section">
            <div class="section-header mb-3 d-flex align-items-center">
                <div class="icon-box bg-warning-light text-warning rounded-circle me-2 p-2">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h5 class="fw-bold m-0 text-dark">Upcoming Matches</h5>
            </div>

            <div id="upcoming-list">
                <?php if (empty($upcomingMatches)): ?>
                    <div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white">
                        <div class="text-muted opacity-50 mb-3">
                            <i class="fas fa-calendar-times fa-3x"></i>
                        </div>
                        <p class="text-muted fw-bold">No upcoming matches scheduled</p>
                    </div>
                <?php else: ?>
                    <div class="matches-list">
                        <?php foreach ($upcomingMatches as $match): ?>
                            <div class="match-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
                                <div
                                    class="card-header bg-white border-bottom-0 pt-3 pb-0 px-3 d-flex justify-content-between align-items-start">
                                    <span class="badge bg-light text-primary border border-primary-subtle rounded-pill px-3">
                                        <?= htmlspecialchars($match['match_type'] ?? 'Match') ?>
                                    </span>
                                    <?php if ($match['tournament_name']): ?>
                                        <small class="text-muted fw-bold text-uppercase"
                                            style="font-size: 0.65rem; letter-spacing: 1px;">
                                            <?= htmlspecialchars($match['tournament_name']) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body p-3">
                                    <div class="row align-items-center text-center">
                                        <!-- Team 1 -->
                                        <div class="col-4">
                                            <div class="team-logo-container mb-2">
                                                <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                                                    alt="<?= htmlspecialchars($match['team1_name']) ?>"
                                                    class="img-fluid rounded-circle border shadow-sm"
                                                    style="width: 50px; height: 50px; object-fit: contain;">
                                            </div>
                                            <div class="team-name fw-bold text-dark text-truncate" style="font-size: 0.85rem;">
                                                <?= htmlspecialchars($match['team1_name']) ?>
                                            </div>
                                        </div>

                                        <!-- VS -->
                                        <div class="col-4">
                                            <div class="vs-badge-container position-relative">
                                                <div class="badge bg-warning text-dark rounded-circle shadow-sm d-flex align-items-center justify-content-center mx-auto"
                                                    style="width: 40px; height: 40px; font-weight: 800; font-size: 0.9rem;">
                                                    VS
                                                </div>
                                                <div class="small text-muted mt-2 fw-bold" style="font-size: 0.7rem;">
                                                    <?= date('H:i', strtotime($match['match_time'])) ?>
                                                </div>
                                                <div class="small text-muted" style="font-size: 0.7rem;">
                                                    <?= date('d M', strtotime($match['match_date'])) ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Team 2 -->
                                        <div class="col-4">
                                            <div class="team-logo-container mb-2">
                                                <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                                                    alt="<?= htmlspecialchars($match['team2_name']) ?>"
                                                    class="img-fluid rounded-circle border shadow-sm"
                                                    style="width: 50px; height: 50px; object-fit: contain;">
                                            </div>
                                            <div class="team-name fw-bold text-dark text-truncate" style="font-size: 0.85rem;">
                                                <?= htmlspecialchars($match['team2_name']) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="text-center mt-3 pt-3 border-top border-light">
                                        <small class="text-muted d-block mb-2">
                                            <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                                            <?= htmlspecialchars($match['venue']) ?>
                                        </small>

                                        <!-- Action Buttons -->
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <div class="d-grid gap-2">
                                                <a href="../admin/start_match/initialize_match.php?id=<?= $match['id'] ?>"
                                                    class="btn btn-sm btn-success rounded-pill fw-bold">
                                                    <i class="fas fa-play me-1"></i>Start Match
                                                </a>
                                                <div class="btn-group w-100">
                                                    <a href="../view/view_match.php?id=<?= $match['id'] ?>"
                                                        class="btn btn-sm btn-outline-primary" title="Details"><i
                                                            class="fas fa-eye"></i></a>
                                                    <a href="../edit/edit_match.php?id=<?= $match['id'] ?>"
                                                        class="btn btn-sm btn-outline-warning" title="Edit"><i
                                                            class="fas fa-edit"></i></a>
                                                    <button class="btn btn-sm btn-outline-danger"
                                                        onclick="deleteMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"
                                                        title="Delete"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="../view/view_match.php?id=<?= $match['id'] ?>"
                                                class="btn btn-sm btn-outline-primary w-100 rounded-pill fw-bold">
                                                View Details
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Live Matches -->
        <div class="col-lg-4 col-md-6 match-section-col <?= $default_tab === 'live' ? 'active-tab' : '' ?>"
            id="live-section">
            <div class="section-header mb-3 d-flex align-items-center">
                <div class="icon-box bg-danger-light text-danger rounded-circle me-2 p-2">
                    <i class="fas fa-broadcast-tower"></i>
                </div>
                <h5 class="fw-bold m-0 text-dark">Live Now</h5>
            </div>

            <div id="live-list">
                <?php if (empty($liveMatches)): ?>
                    <div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white">
                        <div class="text-muted opacity-50 mb-3">
                            <i class="fas fa-video-slash fa-3x"></i>
                        </div>
                        <p class="text-muted fw-bold">No live matches currently</p>
                    </div>
                <?php else: ?>
                    <div class="matches-list">
                        <?php foreach ($liveMatches as $match): ?>
                            <div class="match-card live-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
                                <div
                                    class="card-header bg-danger text-white border-bottom-0 pt-2 pb-2 px-3 d-flex justify-content-between align-items-center">
                                    <span class="badge bg-white text-danger fw-bold animate-pulse">
                                        <i class="fas fa-circle me-1 small"></i> LIVE
                                    </span>
                                    <small class="fw-bold opacity-75"><?= $match['overs'] ?> Overs</small>
                                </div>

                                <div class="card-body p-3">
                                    <div class="row align-items-center text-center">
                                        <!-- Team 1 -->
                                        <div class="col-4">
                                            <div class="team-logo-container mb-2">
                                                <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                                                    alt="<?= htmlspecialchars($match['team1_name']) ?>"
                                                    class="img-fluid rounded-circle border border-2 border-white shadow-sm"
                                                    style="width: 55px; height: 55px; object-fit: contain;">
                                            </div>
                                            <div class="team-name fw-bold text-dark text-truncate" style="font-size: 0.85rem;">
                                                <?= htmlspecialchars($match['team1_name']) ?>
                                            </div>
                                        </div>

                                        <!-- VS -->
                                        <div class="col-4">
                                            <div class="vs-badge-container position-relative">
                                                <div class="badge bg-danger text-white rounded-circle shadow-lg d-flex align-items-center justify-content-center mx-auto animate-pulse-shadow"
                                                    style="width: 45px; height: 45px; font-weight: 800; font-size: 1rem;">
                                                    VS
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Team 2 -->
                                        <div class="col-4">
                                            <div class="team-logo-container mb-2">
                                                <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                                                    alt="<?= htmlspecialchars($match['team2_name']) ?>"
                                                    class="img-fluid rounded-circle border border-2 border-white shadow-sm"
                                                    style="width: 55px; height: 55px; object-fit: contain;">
                                            </div>
                                            <div class="team-name fw-bold text-dark text-truncate" style="font-size: 0.85rem;">
                                                <?= htmlspecialchars($match['team2_name']) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="text-center mt-3 pt-3 border-top border-light">
                                        <p class="mb-2 text-dark small fw-bold">
                                            <?= htmlspecialchars($match['tournament_name'] ?? 'Friendly Match') ?>
                                        </p>

                                        <!-- Action Buttons -->
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <div class="d-grid gap-2">
                                                <a href="../admin/start_match/continue_match.php?id=<?= $match['id'] ?>"
                                                    class="btn btn-sm btn-danger rounded-pill fw-bold shadow-sm">
                                                    <i class="fas fa-gamepad me-1"></i>Score Control
                                                </a>
                                                <div class="btn-group w-100">
                                                    <a href="../view/view_match.php?id=<?= $match['id'] ?>"
                                                        class="btn btn-sm btn-outline-primary" title="View"><i
                                                            class="fas fa-eye"></i></a>
                                                    <button class="btn btn-sm btn-outline-secondary"
                                                        onclick="stopMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"
                                                        title="Stop"><i class="fas fa-stop"></i></button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="../live_stream/live_match.php?id=<?= $match['id'] ?>"
                                                class="btn btn-sm btn-danger w-100 rounded-pill fw-bold shadow-sm animate-pulse-btn">
                                                <i class="fas fa-play me-1"></i> Watch Live
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Completed Matches -->
        <div class="col-lg-4 col-md-12 match-section-col <?= $default_tab === 'Completed' ? 'active-tab' : '' ?>"
            id="Completed-section">
            <div class="section-header mb-3 d-flex align-items-center">
                <div class="icon-box bg-success-light text-success rounded-circle me-2 p-2">
                    <i class="fas fa-check-double"></i>
                </div>
                <h5 class="fw-bold m-0 text-dark">Completed</h5>
            </div>

            <div id="completed-list">
                <?php if (empty($completedMatches)): ?>
                    <div class="card border-0 shadow-sm rounded-4 text-center py-5 bg-white">
                        <div class="text-muted opacity-50 mb-3">
                            <i class="fas fa-clipboard-check fa-3x"></i>
                        </div>
                        <p class="text-muted fw-bold">No completed matches yet</p>
                    </div>
                <?php else: ?>
                    <div class="matches-list">
                        <?php foreach ($completedMatches as $match): ?>
                            <div class="match-card card border-0 shadow-sm mb-3 rounded-4 overflow-hidden">
                                <div
                                    class="card-header bg-light border-bottom-0 pt-3 pb-0 px-3 d-flex justify-content-between align-items-center">
                                    <small class="text-muted fw-bold"><i
                                            class="far fa-calendar me-1"></i><?= date('d M Y', strtotime($match['match_date'])) ?></small>
                                    <span
                                        class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2">
                                        Finished
                                    </span>
                                </div>

                                <div class="card-body p-3">
                                    <div class="row align-items-center text-center mb-3">
                                        <!-- Team 1 -->
                                        <div class="col-4">
                                            <img src="<?= $match['team1_logo'] ? '../uploads/teams/' . $match['team1_logo'] : '../images/default-team.png' ?>"
                                                alt="<?= htmlspecialchars($match['team1_name']) ?>"
                                                class="img-fluid rounded-circle border mb-1"
                                                style="width: 40px; height: 40px; object-fit: contain; <?= ($match['winner_id'] == $match['team1_id']) ? 'border: 2px solid #198754;' : '' ?>">
                                            <div
                                                class="small fw-bold text-truncate <?= ($match['winner_id'] == $match['team1_id']) ? 'text-success' : 'text-muted' ?>">
                                                <?= htmlspecialchars($match['team1_name']) ?>
                                                <?php if ($match['winner_id'] == $match['team1_id']): ?><i
                                                        class="fas fa-check-circle ms-1 small"></i><?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- VS -->
                                        <div class="col-4">
                                            <div class="small fw-bold text-muted">VS</div>
                                        </div>

                                        <!-- Team 2 -->
                                        <div class="col-4">
                                            <img src="<?= $match['team2_logo'] ? '../uploads/teams/' . $match['team2_logo'] : '../images/default-team.png' ?>"
                                                alt="<?= htmlspecialchars($match['team2_name']) ?>"
                                                class="img-fluid rounded-circle border mb-1"
                                                style="width: 40px; height: 40px; object-fit: contain; <?= ($match['winner_id'] == $match['team2_id']) ? 'border: 2px solid #198754;' : '' ?>">
                                            <div
                                                class="small fw-bold text-truncate <?= ($match['winner_id'] == $match['team2_id']) ? 'text-success' : 'text-muted' ?>">
                                                <?= htmlspecialchars($match['team2_name']) ?>
                                                <?php if ($match['winner_id'] == $match['team2_id']): ?><i
                                                        class="fas fa-check-circle ms-1 small"></i><?php endif; ?>
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

                                    <div class="d-grid">
                                        <a href="../view_match_summary.php?id=<?= $match['id'] ?>"
                                            class="btn btn-sm btn-outline-success rounded-pill">
                                            <i class="fas fa-chart-bar me-1"></i> Full Scorecard
                                        </a>
                                    </div>

                                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                        <div class="text-center mt-2">
                                            <div class="btn-group btn-group-sm">
                                                <a href="../edit/edit_match.php?id=<?= $match['id'] ?>"
                                                    class="btn btn-ghost-secondary text-muted" title="Edit"><i
                                                        class="fas fa-edit"></i></a>
                                                <button class="btn btn-ghost-danger text-danger"
                                                    onclick="deleteMatch(<?= $match['id'] ?>, '<?= htmlspecialchars($match['match_code']) ?>')"
                                                    title="Delete"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* Global Helpers */
    .icon-box {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .bg-warning-light {
        background-color: #fffbeb;
    }

    .bg-danger-light {
        background-color: #fef2f2;
    }

    .bg-success-light {
        background-color: #f0fdf4;
    }

    .matches-list {
        max-height: 650px;
        overflow-y: auto;
        padding-right: 5px;
    }

    /* Scrollbar styling */
    .matches-list::-webkit-scrollbar {
        width: 4px;
    }

    .matches-list::-webkit-scrollbar-track {
        background: transparent;
    }

    .matches-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    /* Card Styling */
    .match-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .match-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08) !important;
    }

    /* Live Card Specifics */
    .live-card {
        border: 1px solid #fee2e2 !important;
    }

    /* Animations */
    .animate-pulse {
        animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }

    .animate-pulse-shadow {
        animation: pulse-shadow 2s infinite;
    }

    .animate-pulse-btn {
        animation: pulse-transform 2s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    @keyframes pulse-shadow {
        0% {
            box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7);
        }

        70% {
            box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(220, 38, 38, 0);
        }
    }

    @keyframes pulse-transform {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.02);
        }

        100% {
            transform: scale(1);
        }
    }

    /* Buttons */
    .btn-ghost-secondary:hover {
        background: #f1f5f9;
    }

    .btn-ghost-danger:hover {
        background: #fef2f2;
    }

    /* Tab Navigation */
    .mobile-tabs-nav .btn-tab {
        border: none;
        background: transparent;
        color: #64748b;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .mobile-tabs-nav .btn-tab.active {
        background-color: #0d6efd;
        color: white;
        box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
    }

    .mobile-tabs-nav {
        display: none !important;
    }

    @media (max-width: 766px) {
        .mobile-tabs-nav {
            display: flex !important;
        }

        .match-section-col {
            display: none;
        }

        .match-section-col.active-tab {
            display: block;
        }

        .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .row {
            --bs-gutter-x: 0;
            margin-left: 0;
            margin-right: 0;
        }

        .match-section-col {
            padding-left: 0;
            padding-right: 0;
        }

        .section-header {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .match-card {
            border-radius: 0 !important;
            margin-bottom: 1rem !important;
        }

        .mobile-tabs-nav {
            margin-left: 1rem;
            margin-right: 1rem;
        }

        .alert {
            margin-left: 1rem;
            margin-right: 1rem;
        }

        .d-flex.justify-content-between.align-items-center.mb-3 {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }

    @media (min-width: 767px) {
        .match-section-col {
            display: block !important;
        }
    }

    /* Responsive */
    @media (max-width: 576px) {
        .matches-list {
            max-height: 500px;
        }

        .section-header h5 {
            font-size: 1.1rem;
        }
    }

    @media (max-width: 460px),
    (max-width: 400px) {
        .matches-list {
            max-height: none !important;
            height: auto !important;
            overflow-y: visible !important;
        }
    }
</style>

<script>
    function switchTab(tabName, btn) {
        // Update buttons
        document.querySelectorAll('.btn-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update sections
        document.querySelectorAll('.match-section-col').forEach(s => s.classList.remove('active-tab'));
        document.getElementById(tabName + '-section').classList.add('active-tab');
    }

    let lastResponse = {
        upcoming: document.getElementById('upcoming-list').innerHTML,
        live: document.getElementById('live-list').innerHTML,
        completed: document.getElementById('completed-list').innerHTML
    };

    function pollMatches() {
        console.log('Polling for match updates...');
        fetch('get_matches_ajax.php?source=all')
            .then(response => response.json())
            .then(data => {
                if (data.upcoming && data.upcoming !== lastResponse.upcoming) {
                    document.getElementById('upcoming-list').innerHTML = data.upcoming;
                    lastResponse.upcoming = data.upcoming;
                }
                if (data.live && data.live !== lastResponse.live) {
                    document.getElementById('live-list').innerHTML = data.live;
                    lastResponse.live = data.live;
                }
                if (data.completed && data.completed !== lastResponse.completed) {
                    document.getElementById('completed-list').innerHTML = data.completed;
                    lastResponse.completed = data.completed;
                }
            })
            .catch(err => console.error('Polling error:', err));
    }

    // Poll every 10 seconds
    setInterval(pollMatches, 2000);

    function deleteMatch(matchId, matchCode) {
        if (confirm(`Are you sure you want to delete match "${matchCode}"? This action cannot be undone.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../admin/delete_match.php';
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'match_id';
            idInput.value = matchId;
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function stopMatch(matchId, matchCode) {
        if (confirm(`Are you sure you want to stop match "${matchCode}"? It will be moved to upcoming matches.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../admin/stop_match.php';
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'match_id';
            idInput.value = matchId;
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>ent('input');
idInput.type = 'hidden';
idInput.name = 'match_id';
idInput.value = matchId;
form.appendChild(idInput);
document.body.appendChild(form);
form.submit();
}
}
</script>

<!-- Silent Handshake Iframe for InfinityFree Security (Verified Start) -->
<iframe src="get_matches_ajax.php?init=1" onload="if(typeof startMatchesSync === 'function') startMatchesSync()"
    style="display:none; width:0; height:0; border:0; visibility:hidden;"></iframe>

<?php require_once '../includes/footer.php'; ?>