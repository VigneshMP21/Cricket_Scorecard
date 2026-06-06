<?php
require_once '../includes/db.php';
// Public access - no login required

$page_title = "Live Matches";
require_once '../includes/header.php';

// Fetch live matches
$liveMatches = [];
try {
    $stmt = $pdo->query("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo,
               t2.team_name as team2_name, t2.team_logo as team2_logo,
               tr.tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.status = 'ongoing'
        ORDER BY m.match_date DESC, m.match_time DESC
    ");
    $liveMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}
?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        --accent-color: #10b981;
        --danger-color: #ef4444;
        --card-bg: rgba(255, 255, 255, 0.95);
        --glass-bg: rgba(255, 255, 255, 0.7);
    }

    .dashboard-container {
        background: radial-gradient(circle at top right, #f8fafc, #e2e8f0);
        min-height: calc(100vh - 76px);
        padding: 2rem 1rem;
    }

    .page-header-section {
        margin-bottom: 2.5rem;
    }

    .page-title {
        font-weight: 800;
        color: #1e293b;
        letter-spacing: -0.025em;
    }

    .live-match-card {
        border: none;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        background: var(--card-bg);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
    }

    .live-match-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    .card-live-header {
        background: #1e293b;
        color: white;
        padding: 0.75rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .live-indicator {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .live-dot {
        width: 10px;
        height: 10px;
        background-color: var(--danger-color);
        border-radius: 50%;
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        animation: pulse-red 2s infinite;
    }

    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .team-section {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 2rem 1.5rem;
        background: linear-gradient(to bottom, #ffffff, #f8fafc);
    }

    .team-box {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 12px;
    }

    .team-logo-container {
        width: 80px;
        height: 80px;
        padding: 5px;
        background: white;
        border-radius: 50%;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.3s ease;
    }

    .live-match-card:hover .team-logo-container {
        transform: scale(1.1);
    }

    .team-logo-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .team-name {
        font-weight: 700;
        font-size: 0.95rem;
        color: #334155;
        margin: 0;
    }

    .vs-separator {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 60px;
    }

    .vs-text {
        font-weight: 900;
        font-size: 1.2rem;
        color: #94a3b8;
        font-style: italic;
    }

    .match-details-bar {
        background: #f1f5f9;
        padding: 1rem 1.5rem;
        border-top: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .detail-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85rem;
        color: #64748b;
    }

    .detail-item i {
        color: #94a3b8;
        width: 16px;
    }

    .watch-btn {
        background: #1e293b;
        color: white;
        border: none;
        padding: 1rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        text-decoration: none;
    }

    .watch-btn:hover {
        background: #0f172a;
        color: #10b981;
    }

    .empty-state {
        background: white;
        border-radius: 24px;
        padding: 4rem 2rem;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    }

    .empty-icon {
        font-size: 4rem;
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 1.5rem;
    }
</style>

<div class="dashboard-container">
    <div class="container">
        <div class="page-header-section d-flex justify-content-between align-items-end">
            <div>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill mb-2">
                    <i class="fas fa-satellite-dish me-2"></i>Live Hub
                </span>
                <h1 class="page-title mb-0">Current Action</h1>
                <p class="text-muted mt-1 mb-0">Experience the world-class CPT League matches as they happen.</p>
            </div>
            <div class="d-none d-md-block">
                <div class="text-end">
                    <div class="fw-bold text-dark h4 mb-0"><?= count($liveMatches) ?></div>
                    <small class="text-muted text-uppercase fw-semibold tracking-wider">Live Matches</small>
                </div>
            </div>
        </div>

        <?php if (empty($liveMatches)): ?>
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="empty-state border border-light">
                        <div class="empty-icon"><i class="fas fa-ghost"></i></div>
                        <h3 class="fw-bold text-dark">Stadium's Quiet Right Now</h3>
                        <p class="text-muted mb-4 px-lg-5">No ongoing matches at the moment. Explore our upcoming tournaments and teams while you wait.</p>
                        <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
                            <a href="/CPT_LEAGUE/NavBarList/tournament_list.php" class="btn btn-dark btn-lg px-4 rounded-3">
                                <i class="fas fa-trophy me-2"></i>Tournaments
                            </a>
                            <a href="/CPT_LEAGUE/NavBarList/teams.php" class="btn btn-outline-secondary btn-lg px-4 rounded-3">
                                <i class="fas fa-users me-2"></i>Browse Teams
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($liveMatches as $match): ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="live-match-card">
                            <div class="card-live-header">
                                <div class="live-indicator">
                                    <div class="live-dot"></div>
                                    LIVE NOW
                                </div>
                                <div class="match-tag small opacity-75">
                                    MATCH #<?= htmlspecialchars($match['id']) ?>
                                </div>
                            </div>
                    
                            <div class="team-section">
                                <div class="team-box">
                                    <div class="team-logo-container">
                                        <?php if ($match['team1_logo']): ?>
                                                <img src="/CPT_LEAGUE/uploads/teams/<?= htmlspecialchars($match['team1_logo']) ?>" 
                                                     class="team-logo-img">
                                        <?php else: ?>
                                                <i class="fas fa-users fa-2x text-light"></i>
                                        <?php endif; ?>
                                    </div>
                                    <h6 class="team-name"><?= htmlspecialchars($match['team1_name']) ?></h6>
                                </div>

                                <div class="vs-separator">
                                    <span class="vs-text">VS</span>
                                </div>

                                <div class="team-box">
                                    <div class="team-logo-container">
                                        <?php if ($match['team2_logo']): ?>
                                                <img src="/CPT_LEAGUE/uploads/teams/<?= htmlspecialchars($match['team2_logo']) ?>" 
                                                     class="team-logo-img">
                                        <?php else: ?>
                                                <i class="fas fa-users fa-2x text-light"></i>
                                        <?php endif; ?>
                                    </div>
                                    <h6 class="team-name"><?= htmlspecialchars($match['team2_name']) ?></h6>
                                </div>
                            </div>

                            <div class="match-details-bar">
                                <div class="detail-item">
                                    <i class="fas fa-trophy"></i>
                                    <span><?= htmlspecialchars($match['tournament_name']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>Central Cricket Ground</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-clock"></i>
                                    <span><?= date('M d, Y', strtotime($match['match_date'])) ?> &bull; <?= date('h:i A', strtotime($match['match_time'])) ?></span>
                                </div>
                            </div>

                            <a href="/CPT_LEAGUE/live_stream/live_match.php?id=<?= $match['id'] ?>" class="watch-btn">
                                <i class="fas fa-play-circle"></i>
                                Experience Live
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
