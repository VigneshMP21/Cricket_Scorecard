<?php
// NavBarList/best_bowler_ranking.php
require_once '../includes/db.php';

$page_title = "Best Bowler Ranking";
require_once '../includes/header.php';

// Fetch Best Bowlers with Scoring Rules
try {
    $stmt = $pdo->query("
        SELECT 
            u.id as player_id, 
            u.name as player_name, 
            u.profile_image, 
            t.team_name,
            SUM(ms.wickets_taken) as total_wickets,
            SUM(ms.runs_conceded) as total_runs_conceded,
            SUM(ms.maidens) as total_maidens,
            SUM(match_balls) as total_balls,
            SUM(match_dot_balls) as total_dots,
            COUNT(DISTINCT ms.match_id) as total_matches,
            SUM(ms.runs_scored) as total_runs_scored,
            SUM(
                (ms.wickets_taken * 25) -- Wicket points
                + CASE 
                    WHEN ms.overs_bowled >= 2.0 THEN
                        CASE 
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 5.00 THEN 15
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 6.50 THEN 10
                            WHEN (ms.runs_conceded * 6 / NULLIF(match_balls, 0)) <= 7.50 THEN 5
                            ELSE 0
                        END
                    ELSE 0
                  END -- Econ Bonus
                + (ms.maidens * 10) -- Maiden Bonus
                + CASE 
                    WHEN ms.wickets_taken >= 5 THEN 25
                    WHEN ms.wickets_taken = 4 THEN 15
                    WHEN ms.wickets_taken = 3 THEN 10
                    ELSE 0
                  END -- Wicket Milestone Bonus
                + CASE 
                    WHEN match_dot_balls >= 20 THEN 15
                    WHEN match_dot_balls >= 15 THEN 10
                    WHEN match_dot_balls >= 10 THEN 5
                    ELSE 0
                  END -- Dot Ball Bonus
                + CASE WHEN m.winner_id = ms.team_id THEN 5 ELSE 0 END -- Win Bonus
            ) as best_bowler_score,
            cs.career_runs,
            cs.career_wickets,
            cm.career_matches
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
        -- Join with Career Stats Subquery
        LEFT JOIN (
            SELECT ms_inner.player_id, 
                   SUM(ms_inner.runs_scored) as career_runs, 
                   SUM(ms_inner.wickets_taken) as career_wickets
            FROM match_statistics ms_inner
            JOIN matches m_inner ON ms_inner.match_id = m_inner.id
            WHERE m_inner.status = 'completed'
            GROUP BY ms_inner.player_id
        ) cs ON u.id = cs.player_id
        -- Join with Career Matches Subquery (Playing 11 in completed matches)
        LEFT JOIN (
            SELECT ms_match.player_id, COUNT(DISTINCT ms_match.match_id) as career_matches
            FROM match_squads ms_match
            JOIN matches m_match ON ms_match.match_id = m_match.id
            WHERE ms_match.playing_11 = 1 AND m_match.status = 'completed'
            GROUP BY ms_match.player_id
        ) cm ON u.id = cm.player_id
        WHERE ms.match_balls > 0 AND m.status = 'completed'
        GROUP BY u.id, u.name, u.profile_image, t.team_name, cs.career_runs, cs.career_wickets, cm.career_matches
        HAVING best_bowler_score > 0
        ORDER BY best_bowler_score DESC, 
                 total_wickets DESC, 
                 (SUM(ms.runs_conceded) * 6 / NULLIF(SUM(match_balls), 0)) ASC, 
                 total_dots DESC
    ");
    $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rankings = [];
}
?>

<style>
    .bg-bronze {
        background-color: #cd7f32;
    }

    .ranking-row {
        transition: all 0.3s ease;
    }

    .ranking-row:hover {
        background-color: rgba(30, 58, 138, 0.05) !important;
        transform: scale(1.005);
        box-shadow: inset 4px 0 0 #1e3a8a;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .table thead th {
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    .table tbody td {
        border-bottom: 1px solid rgba(0, 0, 0, 0.03);
    }

    /* Player Card Overlay Styles */
    .player-card-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeIn 0.2s ease-out;
    }

    .player-card {
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 34%),
            linear-gradient(145deg, #ffffff 0%, #f8fafc 48%, #eff6ff 100%);
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 24px;
        padding: 2rem;
        width: 90%;
        max-width: 400px;
        position: relative;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
        animation: zoomIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .player-card::before {
        content: "";
        position: absolute;
        inset: 10px;
        border: 1px solid rgba(255, 255, 255, 0.85);
        border-radius: 18px;
        pointer-events: none;
    }

    .player-card img {
        background: #ffffff;
        border: 5px solid #ffffff !important;
        box-shadow: 0 14px 28px rgba(15, 23, 42, 0.16) !important;
    }

    .player-card h3 {
        color: #0f172a !important;
        letter-spacing: 0;
    }

    .player-card .badge {
        background: #dbeafe !important;
        color: #1e3a8a !important;
        border: 1px solid rgba(37, 99, 235, 0.18);
    }

    .player-card .row.g-3>div>div {
        background: #ffffff !important;
        border: 1px solid rgba(148, 163, 184, 0.22);
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.07);
    }

    .player-card .text-white-50 {
        color: #64748b !important;
        font-weight: 700;
        letter-spacing: 0.35px;
    }

    .player-card .text-white {
        color: #0f172a !important;
    }

    .player-card .text-warning {
        color: #ca8a04 !important;
    }

    .player-card .text-info {
        color: #0284c7 !important;
    }

    .player-card .btn {
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
        border: 0;
        color: #ffffff;
        box-shadow: 0 14px 26px rgba(37, 99, 235, 0.24);
    }

    .player-card .btn:hover {
        color: #ffffff;
        filter: brightness(0.96);
    }

    .player-card-overlay.d-none {
        display: none !important;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes zoomIn {
        from {
            transform: scale(0.9);
            opacity: 0;
        }

        to {
            transform: scale(1);
            opacity: 1;
        }
    }

    /* Mobile Responsive Styles */
    @media (max-width: 767px) {
        .container-fluid {
            padding-left: 10px;
            padding-right: 10px;
        }

        /* Mobile layout fixes: kept header in flex-row */

        .alert.alert-primary {
            padding: 1rem;
            font-size: 0.875rem;
        }

        .alert.alert-primary .d-flex.align-items-center>div {
            flex: 1;
        }

        .table-responsive {
            border-radius: 10px;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            font-size: 0.875rem;
        }

        .table thead th {
            font-size: 0.75rem;
            padding: 0.5rem;
            font-weight: 900 !important;
            color: #000;
        }

        .table tbody td {
            padding: 0.5rem;
        }



        .ranking-row:hover {
            transform: none;
            /* Disable hover transform on mobile for better touch */
        }

        .badge.rounded-circle {
            width: 30px !important;
            height: 30px !important;
            font-size: 0.75rem;
        }

        .rounded-circle.me-3 {
            width: 35px !important;
            height: 35px !important;
        }

        .fw-bold.text-dark {
            font-size: 0.875rem;
        }

        .fw-bold.text-primary.fs-5 {
            font-size: 1rem;
        }

        .badge.bg-success.rounded-pill {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        /* Player Card Mobile Adjustments */
        .player-card {
            width: 95% !important;
            max-width: none !important;
            padding: 1.5rem !important;
            margin: 1rem;
        }

        .player-card img {
            width: 200px !important;
            height: 200px !important;
        }

        .player-card h3 {
            font-size: 1.25rem;
        }

        .player-card .badge {
            font-size: 0.75rem;
        }

        .player-card .row.g-3>div>div {
            padding: 0.75rem !important;
        }

        .player-card .h4 {
            font-size: 1.125rem;
        }

        .player-card .btn {
            font-size: 0.875rem;
            padding: 0.75rem 1rem;
        }

        .btn-close {
            top: 0.5rem !important;
            right: 0.5rem !important;
        }
    }

    @media (max-width: 480px) {
        .container-fluid {
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        .table {
            font-size: 0.7rem;
        }

        .table thead th {
            padding: 0.25rem 0.1rem;
            font-size: 0.65rem;
            font-weight: 900 !important;
            color: #000;
        }

        .table tbody td {
            padding: 0.25rem 0.1rem;
        }

        th[style*="width: 80px"] {
            width: 40px !important;
        }

        .badge.rounded-circle {
            width: 22px !important;
            height: 22px !important;
            font-size: 0.65rem !important;
            padding: 0 !important;
        }

        .rounded-circle.me-3 {
            width: 28px !important;
            height: 28px !important;
            margin-right: 0.4rem !important;
        }

        .fw-bold.text-dark {
            font-size: 0.75rem !important;
            line-height: 1.2;
        }

        .text-muted.small {
            font-size: 0.6rem !important;
        }

        .fw-bold.text-primary.fs-5 {
            font-size: 0.85rem !important;
        }

        .badge.bg-success.rounded-pill {
            font-size: 0.65rem !important;
            padding: 0.2rem 0.4rem !important;
        }

        .ps-4 {
            padding-left: 0.2rem !important;
        }

        .pe-4 {
            padding-right: 0.2rem !important;
        }

        .player-card {
            padding: 1rem !important;
        }

        .player-card img {
            width: 200px !important;
            height: 200px !important;
        }

        .player-card h3 {
            font-size: 1.125rem;
        }

        .player-card .h4 {
            font-size: 1rem;
        }
    }
</style>

<div class="container-fluid py-4"
    style="background: linear-gradient(135deg, rgba(248, 250, 252, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%); min-height: calc(100vh - 120px);">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="pe-2">
                    <h2 class="fw-bold mb-1" style="color: #1e3a8a;">
                        <i class="fas fa-bowling-ball me-2"></i>Best Bowler Ranking
                    </h2>
                    <p class="text-muted mb-0">Ranked by Performance Score (Wickets + Efficiency)</p>
                </div>
                <div class="badge bg-primary px-3 py-2 rounded-pill shadow-sm flex-shrink-0">
                    <i class="fas fa-clock me-1"></i> Live Stats
                </div>
            </div>

            <!-- Scoring Rules Info -->
            <div class="alert alert-primary border-0 shadow-sm mb-4"
                style="border-radius: 15px; background: rgba(219, 234, 254, 0.5); backdrop-filter: blur(5px);">
                <div class="d-flex align-items-center">
                    <i class="fas fa-info-circle me-3 fs-4 text-primary"></i>
                    <div>
                        <small class="text-dark fw-bold">Scoring System:</small>
                        <small class="text-muted d-block">Wicket (+25) | Econ (min 2 ov) ≤5 (+15), ≤6.5 (+10), ≤7.5 (+5)
                            | Maiden (+10) | 3 wkts (+10), 4 wkts (+15), 5+ wkts (+25) | Dots 10+ (+5), 15+ (+10), 20+
                            (+15) | Win (+5)</small>
                    </div>
                </div>
            </div>

            <!-- Rankings Table -->
            <div class="card border-0 shadow-lg"
                style="border-radius: 20px; background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.5);">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr
                                    style="background-color: #ffffff; color: #000000; border-bottom: 2px solid #e2e8f0;">
                                    <th class="ps-4 py-3 text-center"
                                        style="width: 80px; border-top-left-radius: 20px;">Rank</th>
                                    <th class="py-3">Player</th>
                                    <th class="py-3 text-center">Wickets</th>
                                    <th class="py-3 text-center">Overs</th>
                                    <th class="py-3 text-center">Score</th>
                                    <th class="pe-4 py-3 text-center" style="border-top-right-radius: 20px;">Economy
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rankings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No bowling stats available</h5>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rankings as $index => $row):
                                        $rank = $index + 1;
                                        $display_overs = floor($row['total_balls'] / 6) . '.' . ($row['total_balls'] % 6);
                                        $economy = $row['total_balls'] > 0 ? ($row['total_runs_conceded'] * 6) / $row['total_balls'] : 0;

                                        // Rank badges colors
                                        $badge_class = 'bg-light text-dark';
                                        if ($rank == 1)
                                            $badge_class = 'bg-warning text-dark';
                                        elseif ($rank == 2)
                                            $badge_class = 'bg-secondary text-white';
                                        elseif ($rank == 3)
                                            $badge_class = 'bg-bronze text-white';

                                        // Prepare data for modal
                                        $playerData = htmlspecialchars(json_encode([
                                            'id' => $row['player_id'],
                                            'name' => $row['player_name'],
                                            'team' => $row['team_name'] ?: 'No Team',
                                            'image' => $row['profile_image'] ? '../uploads/users/' . $row['profile_image'] : '',
                                            'matches' => $row['career_matches'] ?: 0,
                                            'runs' => $row['career_runs'] ?: 0,
                                            'wickets' => $row['career_wickets'] ?: 0
                                        ]), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr class="ranking-row <?= (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $row['player_id']) ? 'highlighted-row' : '' ?>"
                                            onclick="openPlayerCard(<?= $playerData ?>)"
                                            style="cursor: pointer; animation: fadeInUp 0.5s ease both; animation-delay: <?= $index * 0.05 ?>s;">
                                            <td class="text-center ps-4">
                                                <span
                                                    class="badge rounded-circle d-inline-flex align-items-center justify-content-center <?= $badge_class ?>"
                                                    style="width: 35px; height: 35px; font-weight: 800; border: 1px solid rgba(0,0,0,0.05);">
                                                    <?= $rank ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <img src="<?= $row['profile_image'] ? '../uploads/users/' . htmlspecialchars($row['profile_image']) : '../assets/images/default_player.png' ?>"
                                                        alt="<?= htmlspecialchars($row['player_name']) ?>"
                                                        class="rounded-circle me-3"
                                                        style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                    <div>
                                                        <div class="fw-bold text-dark">
                                                            <?= htmlspecialchars($row['player_name']) ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?= htmlspecialchars($row['team_name'] ?: 'No Team') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-dark">
                                                    <?= $row['total_wickets'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="text-muted">
                                                    <?= $display_overs ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-primary fs-5">
                                                    <?= number_format($row['best_bowler_score'], 0) ?>
                                                </span>
                                            </td>
                                            <td class="text-center pe-4">
                                                <span class="badge bg-success rounded-pill px-3 py-2">
                                                    <?= number_format($economy, 2) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Player Card Overlay -->
<div id="playerCardOverlay" class="player-card-overlay d-none">
    <div class="player-card">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3"
            onclick="closePlayerCard()" aria-label="Close"></button>

        <div class="text-center mb-3">
            <img id="cardPlayerImage" src="" alt="Player" class="rounded-3 shadow-lg mb-3"
                style="width: 200px; height: 200px; object-fit: cover; border: 4px solid rgba(255,255,255,0.2);">
            <h3 id="cardPlayerName" class="fw-bold text-white mb-1"></h3>
            <span id="cardTeamName" class="badge bg-white text-dark px-3 py-2 rounded-pill mt-2"></span>
        </div>

        <div class="row g-3 text-center mb-4">
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase">Matches</div>
                    <div id="cardMatches" class="h4 text-white mb-0 fw-bold"></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase">Runs</div>
                    <div id="cardRuns" class="h4 text-warning mb-0 fw-bold"></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.1);">
                    <div class="text-white-50 small text-uppercase">Wickets</div>
                    <div id="cardWickets" class="h4 text-info mb-0 fw-bold"></div>
                </div>
            </div>
        </div>

        <a id="cardViewStatsBtn" href="#" class="btn btn-light w-100 py-3 fw-bold rounded-pill">
            <i class="fas fa-chart-bar me-2"></i>View Full Stats
        </a>
    </div>
</div>

<script>
    function openPlayerCard(data) {
        document.getElementById('cardPlayerName').textContent = data.name;
        document.getElementById('cardTeamName').textContent = data.team;
        document.getElementById('cardMatches').textContent = data.matches;
        document.getElementById('cardRuns').textContent = data.runs;
        document.getElementById('cardWickets').textContent = data.wickets;

        // Set Image
        const img = document.getElementById('cardPlayerImage');
        if (data.image) {
            img.src = data.image;
        } else {
            img.src = '../assets/images/default-player.png';
        }

        // Set Link
        document.getElementById('cardViewStatsBtn').href = '../view/view_player_profile.php?player_id=' + data.id;

        // Show Overlay
        document.getElementById('playerCardOverlay').classList.remove('d-none');
        document.body.style.overflow = 'hidden'; // Prevent scrolling
    }

    function closePlayerCard() {
        document.getElementById('playerCardOverlay').classList.add('d-none');
        document.body.style.overflow = 'auto'; // Restore scrolling
    }

    // Close on click outside
    document.getElementById('playerCardOverlay').addEventListener('click', function (e) {
        if (e.target === this) {
            closePlayerCard();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closePlayerCard();
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
