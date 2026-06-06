<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}


$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$toss_winner_id = isset($_GET['winner']) ? (int) $_GET['winner'] : 0;
$toss_choice = isset($_GET['choice']) ? $_GET['choice'] : '';
$step = isset($_GET['step']) ? (int) $_GET['step'] : 1;

if (!$match_id || !$toss_winner_id) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

// Initialize session storage for match start if not exists
if (!isset($_SESSION['match_setup'])) {
    $_SESSION['match_setup'] = [];
}
if (!isset($_SESSION['match_setup'][$match_id])) {
    $_SESSION['match_setup'][$match_id] = [
        'toss_winner' => $toss_winner_id,
        'toss_choice' => $toss_choice,
        'team1' => [],
        'team2' => []
    ];
} else {
    // Update toss info in session if it changed in URL
    $_SESSION['match_setup'][$match_id]['toss_winner'] = $toss_winner_id;
    $_SESSION['match_setup'][$match_id]['toss_choice'] = $toss_choice;
}

// Fetch match and team details
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               t1.team_name as team1_name, t1.team_logo as team1_logo,
               t2.team_name as team2_name, t2.team_logo as team2_logo
        FROM matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        header("Location: ../../NavBarList/matches.php");
        exit();
    }

    // Determine current team to select squad for
    if ($step == 1) {
        $current_team_id = $toss_winner_id;
        $saved_squad = isset($_SESSION['match_setup'][$match_id]['team1']) ? $_SESSION['match_setup'][$match_id]['team1'] : [];
    } else {
        $current_team_id = ($toss_winner_id == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
        $saved_squad = isset($_SESSION['match_setup'][$match_id]['team2']) ? $_SESSION['match_setup'][$match_id]['team2'] : [];
    }

    $stmt = $pdo->prepare("SELECT team_name, team_logo FROM teams WHERE id = ?");
    $stmt->execute([$current_team_id]);
    $current_team = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch players for the current team
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.profile_image, u.playing_role
        FROM team_players tp
        JOIN users u ON tp.player_id = u.id
        WHERE tp.team_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$current_team_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Prepare pre-selected data for JS
$preselected_players = isset($saved_squad['players']) ? json_encode($saved_squad['players']) : '[]';
$preselected_captain = isset($saved_squad['captain_id']) ? $saved_squad['captain_id'] : '';
$preselected_vc = isset($saved_squad['vice_captain_id']) ? $saved_squad['vice_captain_id'] : '';

$page_title = "Team Selection - " . htmlspecialchars($current_team['team_name']);
require_once '../../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <!-- Progress Stepper -->
            <div class="mb-5">
                <div class="d-flex justify-content-between position-relative">
                    <div class="position-absolute top-50 start-0 translate-middle-y w-100 bg-light"
                        style="height: 2px; z-index: 0;"></div>
                    <div class="position-absolute top-50 start-0 translate-middle-y bg-primary transition-all"
                        style="height: 2px; z-index: 0; width: <?= ($step == 1) ? '50%' : '100%' ?>; transition: width 0.5s;">
                    </div>

                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow"
                            style="width: 40px; height: 40px;">1</div>
                        <small class="fw-bold mt-2 d-block">Toss</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle <?= ($step >= 1) ? 'bg-primary text-white' : 'bg-white text-muted border' ?> d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">2</div>
                        <small class="<?= ($step == 1) ? 'fw-bold' : '' ?> mt-2 d-block">Team 1 Squad</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle <?= ($step >= 2) ? 'bg-primary text-white' : 'bg-white text-muted border' ?> d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">3</div>
                        <small class="<?= ($step == 2) ? 'fw-bold' : '' ?> mt-2 d-block">Team 2 Squad</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-white text-muted border d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">4</div>
                        <small class="mt-2 d-block">Confirm</small>
                    </div>
                </div>
            </div>

            <!-- Back Button -->
            <div class="mb-4">
                <?php if ($step == 1): ?>
                    <a href="toss.php?id=<?= $match_id ?>" class="btn btn-outline-secondary rounded-pill">
                        <i class="fas fa-arrow-left me-2"></i>Back to Toss
                    </a>
                <?php else: ?>
                    <a href="team_selection.php?id=<?= $match_id ?>&winner=<?= $toss_winner_id ?>&choice=<?= $toss_choice ?>&step=1"
                        class="btn btn-outline-secondary rounded-pill">
                        <i class="fas fa-arrow-left me-2"></i>Back to Team 1
                    </a>
                <?php endif; ?>
            </div>

            <!-- Current Team Display -->
            <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
                <div class="card-body bg-primary text-white p-4 text-center">
                    <div class="mb-3">
                        <img src="<?= $current_team['team_logo'] ? '/CPT_LEAGUE/uploads/teams/' . $current_team['team_logo'] : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                            alt="<?= htmlspecialchars($current_team['team_name']) ?>"
                            class="rounded-circle bg-white p-2 shadow"
                            style="width: 100px; height: 100px; object-fit: contain;">
                    </div>
                    <h3 class="fw-bold mb-1"><?= htmlspecialchars($current_team['team_name']) ?></h3>
                    <p class="mb-0 opacity-75">
                        <?= ($step == 1) ? 'Toss Winners (Choosing to ' . ucfirst($toss_choice) . ')' : 'Opponents' ?>
                        </small>
                </div>
            </div>

            <form id="squadForm" action="process_squad.php" method="POST">
                <input type="hidden" name="match_id" value="<?= $match_id ?>">
                <input type="hidden" name="team_id" value="<?= $current_team_id ?>">
                <input type="hidden" name="step" value="<?= $step ?>">
                <input type="hidden" name="toss_winner_id" value="<?= $toss_winner_id ?>">
                <input type="hidden" name="toss_choice" value="<?= $toss_choice ?>">

                <!-- Captain and Vice-Captain Selection -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div class="card shadow-sm border-0 rounded-4 h-100">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-star text-warning me-2"></i>Select Captain
                                </h5>
                                <select id="captainSelect" name="captain_id"
                                    class="form-select form-select-lg rounded-3" required>
                                    <option value="">Choose Captain...</option>
                                    <?php foreach ($players as $player): ?>
                                        <option value="<?= $player['id'] ?>"
                                            data-role="<?= htmlspecialchars($player['playing_role']) ?>"
                                            <?= $preselected_captain == $player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['name']) ?>
                                            (<?= htmlspecialchars($player['playing_role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 rounded-4 h-100">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-star-half-alt text-info me-2"></i>Select
                                    Vice-Captain</h5>
                                <select id="viceCaptainSelect" name="vice_captain_id"
                                    class="form-select form-select-lg rounded-3" required>
                                    <option value="">Choose Vice-Captain...</option>
                                    <?php foreach ($players as $player): ?>
                                        <option value="<?= $player['id'] ?>"
                                            data-role="<?= htmlspecialchars($player['playing_role']) ?>"
                                            <?= $preselected_vc == $player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['name']) ?>
                                            (<?= htmlspecialchars($player['playing_role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Squad Selection Section -->
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-0"><i class="fas fa-users me-2"></i>Squad Selection</h5>
                            <small class="text-muted">Select players for the match squad (Min 11)</small>
                        </div>
                        <span class="badge bg-primary rounded-pill px-3 py-2" id="selectedCount">0 Selected</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush" id="playerList">
                            <?php
                            $saved_players_arr = isset($saved_squad['players']) ? $saved_squad['players'] : [];
                            foreach ($players as $player):
                                $is_selected = in_array($player['id'], $saved_players_arr);
                                ?>
                                <div class="list-group-item p-3 player-item <?= $is_selected ? 'selected' : '' ?>"
                                    data-player-id="<?= $player['id'] ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="form-check me-3">
                                            <input class="form-check-input player-checkbox" type="checkbox" name="players[]"
                                                value="<?= $player['id'] ?>" id="check_<?= $player['id'] ?>" <?= $is_selected ? 'checked' : '' ?>>
                                        </div>
                                        <img src="<?= $player['profile_image'] ? '/CPT_LEAGUE/uploads/users/' . $player['profile_image'] : '/CPT_LEAGUE/assets/images/default-player.png' ?>"
                                            alt="<?= htmlspecialchars($player['name']) ?>" class="rounded-circle me-3"
                                            style="width: 50px; height: 50px; object-fit: cover;">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($player['name']) ?></h6>
                                            <small
                                                class="text-muted"><?= htmlspecialchars($player['playing_role']) ?></small>
                                        </div>
                                        <div class="badges">
                                            <span class="badge bg-warning text-dark captain-badge d-none">C</span>
                                            <span class="badge bg-info vice-captain-badge d-none">VC</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Next Button -->
                <div class="text-center mb-5">
                    <button type="submit" class="btn btn-primary btn-lg px-5 py-3 rounded-pill shadow" id="nextBtn"
                        disabled>
                        Next Step <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .transition-all {
        transition: all 0.3s ease;
    }

    .player-item {
        transition: background-color 0.2s;
        cursor: pointer;
    }

    .player-item:hover {
        background-color: #f8fafc;
    }

    .player-item.selected {
        background-color: #f0f7ff;
    }

    .form-check-input {
        width: 1.5em;
        height: 1.5em;
        cursor: pointer;
    }

    .captain-badge,
    .vice-captain-badge {
        font-size: 0.8rem;
        padding: 5px 8px;
    }

    @media (max-width: 480px) {
        .container.py-5 {
            padding-top: 20px !important;
            padding-bottom: 20px !important;
        }

        /* Stepper Scaling */
        .rounded-circle[style*="width: 40px"] {
            width: 30px !important;
            height: 30px !important;
            font-size: 0.8rem;
        }

        .text-center small {
            font-size: 0.65rem;
        }

        /* Team Display */
        img[style*="width: 100px"] {
            width: 80px !important;
            height: 80px !important;
        }

        h3.fw-bold {
            font-size: 1.5rem;
        }

        /* Card Padding */
        .card-body.p-4 {
            padding: 1.5rem !important;
        }

        /* Squad List */
        .player-item {
            padding: 10px !important;
        }

        .player-item img {
            width: 40px !important;
            height: 40px !important;
            margin-right: 10px !important;
        }

        /* Buttons & Badges */
        #nextBtn {
            width: 100%;
            padding: 12px !important;
            font-size: 1rem;
        }

        /* Form Elements */
        .form-select-lg {
            font-size: 1rem;
            padding: 0.5rem 1rem;
        }

        /* Adjust stack spacing */
        .col-md-6.mb-3 {
            margin-bottom: 15px !important;
        }

        .d-flex.align-items-center h5 {
            font-size: 1.1rem;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const captainSelect = document.getElementById('captainSelect');
        const viceCaptainSelect = document.getElementById('viceCaptainSelect');
        const playerCheckboxes = document.querySelectorAll('.player-checkbox');
        const playerItems = document.querySelectorAll('.player-item');
        const selectedCountBadge = document.getElementById('selectedCount');
        const nextBtn = document.getElementById('nextBtn');
        const squadForm = document.getElementById('squadForm');

        function updateSelections() {
            const captainId = captainSelect.value;
            const viceCaptainId = viceCaptainSelect.value;
            let count = 0;

            // Update mutual exclusion in dropdowns
            Array.from(captainSelect.options).forEach(option => {
                if (option.value === "") return;
                option.disabled = (option.value === viceCaptainId);
            });

            Array.from(viceCaptainSelect.options).forEach(option => {
                if (option.value === "") return;
                option.disabled = (option.value === captainId);
            });

            // Update checkboxes and badges
            playerItems.forEach(item => {
                const playerId = item.dataset.playerId;
                const checkbox = item.querySelector('.player-checkbox');
                const captainBadge = item.querySelector('.captain-badge');
                const viceCaptainBadge = item.querySelector('.vice-captain-badge');

                // Reset badges
                captainBadge.classList.add('d-none');
                viceCaptainBadge.classList.add('d-none');

                if (playerId === captainId) {
                    checkbox.checked = true;
                    checkbox.disabled = true;
                    captainBadge.classList.remove('d-none');
                    item.classList.add('selected');
                } else if (playerId === viceCaptainId) {
                    checkbox.checked = true;
                    checkbox.disabled = true;
                    viceCaptainBadge.classList.remove('d-none');
                    item.classList.add('selected');
                } else {
                    checkbox.disabled = false;
                    if (!checkbox.checked) {
                        item.classList.remove('selected');
                    } else {
                        item.classList.add('selected');
                    }
                }

                if (checkbox.checked) count++;
            });

            selectedCountBadge.textContent = `${count} Selected`;
            // Enable next button ONLY if exactly 11 players are selected
            // and C and VC are chosen
            nextBtn.disabled = !(count === 11 && captainId && viceCaptainId);
        }

        captainSelect.addEventListener('change', updateSelections);
        viceCaptainSelect.addEventListener('change', updateSelections);

        // Handle item click to toggle checkbox
        playerItems.forEach(item => {
            item.addEventListener('click', function (e) {
                // If clicked on select, do nothing
                if (e.target.closest('select')) return;

                const checkbox = item.querySelector('.player-checkbox');
                if (!checkbox.disabled) {
                    const currentSelectedCount = Array.from(playerCheckboxes).filter(cb => cb.checked).length;

                    if (e.target !== checkbox) {
                        if (!checkbox.checked && currentSelectedCount >= 11) {
                            alert("Only 11 players can be selected.");
                            return;
                        }
                        checkbox.checked = !checkbox.checked;
                    } else {
                        // User clicked checkbox directly
                        if (checkbox.checked && currentSelectedCount > 11) {
                            checkbox.checked = false; // Revert
                            alert("Only 11 players can be selected.");
                            return;
                        }
                    }
                    updateSelections();
                }
            });
        });

        // Ensure disabled checkboxes (C/VC) are included in form submission
        // and perform final validation
        squadForm.addEventListener('submit', function (e) {
            const selectedCount = Array.from(playerCheckboxes).filter(cb => cb.checked).length;

            if (selectedCount < 11) {
                e.preventDefault();
                alert("A minimum of 11 players is required.");
                return;
            }
            if (selectedCount > 11) {
                e.preventDefault();
                alert("Only 11 players can be selected.");
                return;
            }

            playerCheckboxes.forEach(cb => {
                if (cb.disabled) cb.disabled = false;
            });
        });

        // Initialize
        updateSelections();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>