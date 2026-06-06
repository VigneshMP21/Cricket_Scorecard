<?php
require_once '../includes/db.php';
// Public access - no login required

// Fetch players with team info
$players = [];
try {
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.email, u.profile_image, u.playing_role, u.created_at,
               t.id as team_id, t.team_name, t.team_code, t.team_logo, t.created_by as team_creator,
               cs.career_runs,
               cs.career_wickets,
               cm.career_matches
        FROM users u
        LEFT JOIN team_players tp ON u.id = tp.player_id
        LEFT JOIN teams t ON tp.team_id = t.id AND t.status = 'active'
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
        WHERE u.role = 'player'
        ORDER BY u.name ASC
    ");
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error gracefully
}

$selectedPlayers = array_filter($players, function ($p) {
    return !empty($p['team_name']);
});
$availablePlayers = array_filter($players, function ($p) {
    return empty($p['team_name']);
});
$selectedCount = count($selectedPlayers);
$availableCount = count($availablePlayers);


$page_title = "Players List";
require_once '../includes/header.php';
?>

<style>
    /* CSS Variables for Consistency */
    :root {
        --primary-color: #3b82f6;
        --success-light: #dcfce7;
        --warning-light: #fef9c3;
        --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        --card-hover-transform: translateY(-5px);
    }

    body {
        background: #f1f5f9;
    }

    .players-page-container {
        min-height: calc(100vh - 76px);
        background: var(--bg-gradient);
    }

    .search-wrapper {
        flex-grow: 1;
        min-width: 250px;
    }

    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 5;
    }

    /* Stat Cards */
    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        cursor: pointer;
    }

    .icon-circle {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .bg-success-light {
        background-color: #d1fae5;
    }

    .bg-warning-light {
        background-color: #fef08a;
    }

    .bg-secondary-light {
        background-color: #e2e8f0;
    }

    /* Custom Pills */
    .custom-pills .nav-link {
        color: #64748b;
        font-weight: 500;
        transition: all 0.3s;
    }

    .custom-pills .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4);
    }

    /* Player Card */
    .player-card {
        border-radius: 16px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        background: white;
        cursor: pointer;
        overflow: hidden;
    }

    .player-card:hover {
        transform: var(--card-hover-transform);
        box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.1) !important;
    }

    .player-thumb {
        width: 64px;
        height: 64px;
        object-fit: cover;
        border: 2px solid #f8fafc;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .team-badge-sm {
        bottom: -5px;
        right: -5px;
        width: 30px;
        height: 30px;
        background: white;
        border-radius: 50%;
        padding: 3px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .team-badge-sm img {
        width: 100% !important;
        height: 100% !important;
        object-fit: contain !important;
        border-radius: 50%;
    }

    /* Overlays */
    .overlay-backdrop,
    .lightbox-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.85);
        /* Slate-900 with opacity */
        backdrop-filter: blur(8px);
        z-index: 1050;
        display: none;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .overlay-backdrop.show,
    .lightbox-backdrop.show {
        display: flex;
        opacity: 1;
    }

    .overlay-modal {
        background: white;
        width: 90%;
        max-width: 400px;
        border-radius: 24px;
        padding: 30px;
        position: relative;
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .overlay-backdrop.show .overlay-modal {
        transform: scale(1);
    }

    .btn-close-overlay,
    .lightbox-close {
        position: absolute;
        top: 15px;
        right: 20px;
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #94a3b8;
        cursor: pointer;
        transition: color 0.2s;
        z-index: 10;
    }

    .btn-close-overlay:hover,
    .lightbox-close:hover {
        color: #ef4444;
    }

    .overlay-avatar {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid white;
    }

    .overlay-avatar-wrapper {
        position: relative;
        width: 140px;
        height: 140px;
    }

    .lightbox-img {
        max-width: 90%;
        max-height: 85vh;
        border-radius: 12px;
        transform: scale(0.95);
        transition: transform 0.3s;
    }

    .lightbox-backdrop.show .lightbox-img {
        transform: scale(1);
    }

    .lightbox-close {
        top: 20px;
        right: 20px;
        color: white;
        font-size: 2rem;
    }

    @media (max-width: 576px) {
        .stat-card h2 {
            font-size: 1.5rem;
        }
    }
</style>

<div class="players-page-container">
    <div class="container-fluid py-4" style="max-width: 1600px;">
        <!-- Header Section -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <h2 class="page-title text-dark m-0">
                <i class="fas fa-users me-2 text-primary"></i>Players Directory
            </h2>
            <div class="d-flex flex-column flex-sm-row gap-2 w-100 w-md-auto">
                <div class="search-wrapper position-relative">
                    <i class="fas fa-search search-icon text-muted"></i>
                    <input type="text" id="searchPlayers" class="form-control ps-5 rounded-pill"
                        placeholder="Search name, team, or role...">
                </div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <a href="../admin/create_team.php"
                        class="btn btn-primary rounded-pill px-4 align-self-stretch d-flex align-items-center justify-content-center">
                        <i class="fas fa-plus me-2"></i>Create Team
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Stats & Tabs -->
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="stat-card selected-card" role="button"
                        onclick="document.getElementById('selected-tab').click()">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted mb-1 small fw-bold">Selected Players</h6>
                                <h2 class="mb-0 text-success fw-bold"><?= $selectedCount ?></h2>
                            </div>
                            <div class="icon-circle bg-success-light text-success">
                                <i class="fas fa-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card available-card" role="button"
                        onclick="document.getElementById('available-tab').click()">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted mb-1 small fw-bold">Available Pool</h6>
                                <h2 class="mb-0 text-warning fw-bold"><?= $availableCount ?></h2>
                            </div>
                            <div class="icon-circle bg-warning-light text-warning">
                                <i class="fas fa-user-plus"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-pills custom-pills mb-4 justify-content-center" id="playerTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-pill px-4" id="selected-tab" data-status="selected">
                        Selected
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill px-4" id="available-tab" data-status="available">
                        Available
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill px-4" id="all-tab" data-status="all">
                        Show All
                    </button>
                </li>
            </ul>
        <?php endif; ?>

        <!-- Empty State -->
        <?php if (empty($players)): ?>
            <div class="empty-state text-center py-5">
                <div class="mb-3">
                    <div class="icon-circle bg-secondary-light mx-auto" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fas fa-user-friends text-secondary"></i>
                    </div>
                </div>
                <h4 class="text-muted fw-bold">No players found</h4>
                <p class="text-muted">The player roster is currently empty.</p>
            </div>
        <?php else: ?>

            <!-- Unified Players Grid -->
            <?php
            // Check if user is player to show sections
            $showSections = isset($_SESSION['role']) && $_SESSION['role'] == 'player';

            if ($showSections) {
                $myTeamPlayers = [];
                $otherTeamsPlayers = [];
                $notSelectedPlayers = [];
                $currentUserId = $_SESSION['user_id'];

                // Find current user's team ID
                $myTeamId = null;
                foreach ($players as $p) {
                    if ($p['id'] == $currentUserId) {
                        $myTeamId = $p['team_id'];
                        break;
                    }
                }

                foreach ($players as $player) {
                    if (empty($player['team_name'])) {
                        $notSelectedPlayers[] = $player;
                    } elseif ($player['team_creator'] == $currentUserId || ($myTeamId && $player['team_id'] == $myTeamId)) {
                        $myTeamPlayers[] = $player;
                    } else {
                        $otherTeamsPlayers[] = $player;
                    }
                }

                // Sort Other Teams Players by Team Name then Player Name
                usort($otherTeamsPlayers, function ($a, $b) {
                    if ($a['team_name'] === $b['team_name']) {
                        return strcmp($a['name'], $b['name']);
                    }
                    return strcmp($a['team_name'], $b['team_name']);
                });

                $sections = [
                    ['title' => 'My Team Players', 'players' => $myTeamPlayers, 'class' => 'text-primary'],
                    ['title' => 'Other Teams Players', 'players' => $otherTeamsPlayers, 'class' => 'text-dark'],
                    ['title' => 'Not Selected Players', 'players' => $notSelectedPlayers, 'class' => 'text-muted']
                ];
            } else {
                // Admin/Guest sees all in one list
                $sections = [
                    ['title' => '', 'players' => $players, 'class' => '']
                ];
            }
            ?>

            <?php foreach ($sections as $section): ?>
                <?php if ($showSections && !empty($section['players'])): ?>
                    <h4 class="mb-3 fw-bold border-bottom pb-2 mt-4 <?= $section['class'] ?>">
                        <?= htmlspecialchars($section['title']) ?>
                        <span class="badge bg-light text-dark border ms-2 small" style="font-size: 0.6em; vertical-align: middle;">
                            <?= count($section['players']) ?>
                        </span>
                    </h4>
                <?php endif; ?>

                <?php if (!empty($section['players'])): ?>
                    <div class="row g-4"
                        id="playersGrid<?= $showSections ? '-' . preg_replace('/[^a-zA-Z0-9]/', '', $section['title']) : '' ?>">
                        <?php foreach ($section['players'] as $player):
                            $status = !empty($player['team_name']) ? 'selected' : 'available';
                            $defaultAvatar = '../assets/images/default_player.png';
                            $playerImg = $player['profile_image'] ? '../uploads/users/' . htmlspecialchars($player['profile_image']) : $defaultAvatar;
                            $teamLogo = $player['team_logo'] ? '../uploads/teams/' . htmlspecialchars($player['team_logo']) : null;
                            ?>
                            <div class="col-12 col-md-6 col-lg-4 col-xl-3 player-item" data-selection-status="<?= $status ?>"
                                data-player-id="<?= $player['id'] ?>" data-name="<?= htmlspecialchars($player['name']) ?>"
                                data-role="<?= htmlspecialchars($player['playing_role']) ?>"
                                data-matches="<?= $player['career_matches'] ?: 0 ?>" data-runs="<?= $player['career_runs'] ?: 0 ?>"
                                data-wickets="<?= $player['career_wickets'] ?: 0 ?>"
                                data-team="<?= $player['team_name'] ?: 'Available' ?>" data-image="<?= $playerImg ?>">

                                <div class="card h-100 border-0 shadow-sm player-card">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="position-relative flex-shrink-0">
                                                <img src="<?= $playerImg ?>" alt="<?= htmlspecialchars($player['name']) ?>"
                                                    class="rounded-circle player-thumb cursor-pointer">
                                                <?php if ($teamLogo): ?>
                                                    <div class="team-badge-sm position-absolute"
                                                        title="<?= htmlspecialchars($player['team_name']) ?>">
                                                        <img src="<?= $teamLogo ?>" alt="Team">
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                                <div class="position-absolute top-0 end-0 m-2 text-danger delete-player-btn"
                                                     onclick="deletePlayer(<?= $player['id'] ?>, event)"
                                                     title="Delete Player">
                                                    <div class="icon-circle bg-danger-subtle"
                                                        style="width: 32px; height: 32px; font-size: 0.9rem;">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="ms-3 overflow-hidden">
                                                <h6 class="player-name fw-bold text-truncate mb-0">
                                                    <?= htmlspecialchars($player['name']) ?>
                                                </h6>
                                                <div class="player-badge-role text-muted small text-truncate">
                                                    <?= htmlspecialchars($player['playing_role']) ?>
                                                </div>
                                                <?php if ($player['team_name']): ?>
                                                    <span
                                                        class="badge bg-success-subtle text-success border border-success-subtle rounded-pill mt-1 fw-normal"
                                                        style="font-size: 0.7rem;">
                                                        <?= htmlspecialchars($player['team_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill mt-1 fw-normal"
                                                        style="font-size: 0.7rem;">
                                                        Available
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="player-stats-mini row g-0 text-center rounded-3 bg-light py-2">
                                            <div class="col-4 border-end">
                                                <div class="fw-bold text-dark"><?= $player['career_matches'] ?: 0 ?></div>
                                                <div class="text-muted" style="font-size: 0.65rem;">MAT</div>
                                            </div>
                                            <div class="col-4 border-end">
                                                <div class="fw-bold text-dark"><?= $player['career_runs'] ?: 0 ?></div>
                                                <div class="text-muted" style="font-size: 0.65rem;">RUNS</div>
                                            </div>
                                            <div class="col-4">
                                                <div class="fw-bold text-dark"><?= $player['career_wickets'] ?: 0 ?></div>
                                                <div class="text-muted" style="font-size: 0.65rem;">WKTS</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</div>

<!-- Player Details Overlay -->
<div id="playerOverlay" class="overlay-backdrop">
    <div class="overlay-modal">
        <button class="btn-close-overlay"><i class="fas fa-times"></i></button>

        <div class="text-center mb-4">
            <div class="overlay-avatar-wrapper mx-auto mb-3">
                <img src="" id="overlayImg" alt="Player" class="overlay-avatar shadow">
            </div>
            <h3 id="overlayName" class="fw-bold mb-1"></h3>
            <div id="overlayTeam" class="text-primary fw-bold mb-1"></div>
            <span id="overlayRole" class="badge bg-secondary rounded-pill px-3 py-2 mb-3"></span>

            <div class="player-stats-mini row g-0 text-center rounded-3 bg-light py-2 mb-4">
                <div class="col-4 border-end">
                    <div id="overlayMatches" class="fw-bold text-dark">-</div>
                    <div class="text-muted" style="font-size: 0.7rem;">MATCHES</div>
                </div>
                <div class="col-4 border-end">
                    <div id="overlayRuns" class="fw-bold text-dark">-</div>
                    <div class="text-muted" style="font-size: 0.7rem;">RUNS</div>
                </div>
                <div class="col-4">
                    <div id="overlayWickets" class="fw-bold text-dark">-</div>
                    <div class="text-muted" style="font-size: 0.7rem;">WICKETS</div>
                </div>
            </div>
        </div>

        <div class="d-grid">
            <a href="#" id="viewStatsBtn" class="btn btn-primary btn-lg rounded-pill shadow-sm">
                <i class="fas fa-chart-bar me-2"></i>View Full Profile
            </a>
        </div>
    </div>
</div>

<!-- Image Lightbox -->
<div id="imageLightbox" class="lightbox-backdrop">
    <button class="lightbox-close"><i class="fas fa-times"></i></button>
    <img src="" id="lightboxImg" class="lightbox-img shadow-lg" alt="Full View">
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('searchPlayers');
        const tabButtons = document.querySelectorAll('#playerTabs button');

        // Overlays
        const playerOverlay = document.getElementById('playerOverlay');
        const imageLightbox = document.getElementById('imageLightbox');

        // Elements
        const overlayImg = document.getElementById('overlayImg');
        const overlayName = document.getElementById('overlayName');
        const overlayTeam = document.getElementById('overlayTeam');
        const overlayRole = document.getElementById('overlayRole');
        const overlayMatches = document.getElementById('overlayMatches');
        const overlayRuns = document.getElementById('overlayRuns');
        const overlayWickets = document.getElementById('overlayWickets');
        const viewStatsBtn = document.getElementById('viewStatsBtn');
        const lightboxImg = document.getElementById('lightboxImg');

        let currentStatusFilter = 'selected'; // Default for admin
        if (!document.querySelector('#playerTabs')) currentStatusFilter = 'all'; // Default for public

        function filterPlayers() {
            const term = searchInput.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.player-item');

            cards.forEach(card => {
                const status = card.dataset.selectionStatus;
                const name = card.dataset.name.toLowerCase();
                const role = card.dataset.role.toLowerCase();
                const team = card.dataset.team.toLowerCase();

                const matchesSearch = name.includes(term) || role.includes(term) || team.includes(term);
                const matchesStatus = (currentStatusFilter === 'all') || (status === currentStatusFilter);

                if (matchesSearch && matchesStatus) {
                    card.classList.remove('d-none');
                } else {
                    card.classList.add('d-none');
                }
            });
        }

        // Event Listeners for Filter
        searchInput.addEventListener('input', filterPlayers);

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Update Active State
                tabButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                // Set Filter
                currentStatusFilter = btn.dataset.status;
                filterPlayers();
            });
        });

        // Run initial filter
        filterPlayers();

        // Interaction Delegate
        document.addEventListener('click', function (e) {
            // Find closest player card or thumb
            const card = e.target.closest('.player-card');
            const imgTrigger = e.target.closest('.player-thumb');

            // If not clicking a card, ignore
            if (!card) return;

            // Prevent default behavior if needed (though div doesn't have default)
            // e.preventDefault();

            const parentItem = card.closest('.player-item');
            if (!parentItem) return;

            // If clicked on image specifically -> Lightbox
            if (imgTrigger) {
                e.preventDefault(); // Stop any link behavior if wrapped
                e.stopPropagation();

                // Get image source from data attribute or fallback to img src
                const imgSrc = parentItem.dataset.image || imgTrigger.src;
                lightboxImg.src = imgSrc;

                openModal(imageLightbox);
                return;
            }

            // Else -> Player Details Overlay
            // Avoid Admin Delete Button triggering this
            if (e.target.closest('.delete-player-btn')) return;

            overlayImg.src = parentItem.dataset.image;
            overlayName.textContent = parentItem.dataset.name;

            const teamName = parentItem.dataset.team;
            overlayTeam.textContent = (teamName && teamName !== 'Available') ? teamName : 'Available Player';
            overlayTeam.className = (teamName && teamName !== 'Available') ? 'text-success fw-bold mb-1' : 'text-warning fw-bold mb-1';

            overlayRole.textContent = parentItem.dataset.role;
            overlayMatches.textContent = parentItem.dataset.matches;
            overlayRuns.textContent = parentItem.dataset.runs;
            overlayWickets.textContent = parentItem.dataset.wickets;
            viewStatsBtn.href = `../view/view_player_profile.php?player_id=${parentItem.dataset.playerId}`;

            openModal(playerOverlay);
        });

        // Modal Helpers
        function openModal(el) {
            el.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(el) {
            el.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Close buttons
        playerOverlay.querySelector('.btn-close-overlay').addEventListener('click', () => closeModal(playerOverlay));
        imageLightbox.querySelector('.lightbox-close').addEventListener('click', () => closeModal(imageLightbox));

        // Click outside to close
        [playerOverlay, imageLightbox].forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target === el) closeModal(el);
            });
        });

        // ESC key close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal(playerOverlay);
                closeModal(imageLightbox);
            }
        });

        // 🗑️ Delete Player Function
        window.deletePlayer = function(playerId, event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            if (!confirm('WARNING: Are you sure you want to delete this player? This action cannot be undone.')) {
                return;
            }

            // Find the player card element
            const playerCard = document.querySelector(`.player-item[data-player-id="${playerId}"]`);
            if (!playerCard) return;

            // Make AJAX Request
            const formData = new FormData();
            formData.append('player_id', playerId);

            fetch('../admin/delete_player_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stats
                    const isSelected = playerCard.dataset.selectionStatus === 'selected';
                    const selectedCountEl = document.querySelector('.selected-card h2');
                    const availableCountEl = document.querySelector('.available-card h2');

                    if (isSelected && selectedCountEl) {
                        selectedCountEl.textContent = Math.max(0, parseInt(selectedCountEl.textContent) - 1);
                    } else if (availableCountEl) {
                        availableCountEl.textContent = Math.max(0, parseInt(availableCountEl.textContent) - 1);
                    }

                    // Smoothly remove from UI
                    playerCard.style.transition = 'all 0.5s ease';
                    playerCard.style.opacity = '0';
                    playerCard.style.transform = 'scale(0.8)';
                    
                    setTimeout(() => {
                        playerCard.remove();
                        // Check if grid is empty, show empty state if needed
                        const remainingCards = document.querySelectorAll('.player-item');
                        if (remainingCards.length === 0) {
                            location.reload(); // Refresh to show empty state
                        }
                    }, 500);
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete player.'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the player.');
            });
        };
    });
</script>

<?php require_once '../includes/footer.php'; ?>