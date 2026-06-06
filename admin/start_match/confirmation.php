<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../NavBarList/matches.php?error=3");
    exit();
}


$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$match_id || !isset($_SESSION['match_setup'][$match_id])) {
    header("Location: ../../NavBarList/matches.php");
    exit();
}

$setup = $_SESSION['match_setup'][$match_id];

try {
    // Fetch match and team names
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

    // Helper to get player names and roles
    function getPlayerDetails($pdo, $player_ids)
    {
        if (empty($player_ids))
            return [];
        $placeholders = str_repeat('?,', count($player_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, name, playing_role, profile_image FROM users WHERE id IN ($placeholders)");
        $stmt->execute($player_ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR + PDO::FETCH_ASSOC); // Not working as expected, use standard
    }

    // Better helper
    function getPlayers($pdo, $player_ids)
    {
        if (empty($player_ids))
            return [];
        $placeholders = str_repeat('?,', count($player_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, name, playing_role, profile_image FROM users WHERE id IN ($placeholders)");
        $stmt->execute($player_ids);
        $players = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $players[$row['id']] = $row;
        }
        return $players;
    }

    $team1_data = $setup['team1'];
    $team2_data = $setup['team2'];

    $team1_players = getPlayers($pdo, $team1_data['players']);
    $team2_players = getPlayers($pdo, $team2_data['players']);

    $toss_winner_name = ($setup['toss_winner'] == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

$page_title = "Confirm Match Start";
require_once '../../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-11">
            <!-- Progress Stepper -->
            <div class="mb-5">
                <div class="d-flex justify-content-between position-relative">
                    <div class="position-absolute top-50 start-0 translate-middle-y w-100 bg-light"
                        style="height: 2px; z-index: 0;"></div>
                    <div class="position-absolute top-50 start-0 translate-middle-y bg-primary"
                        style="height: 2px; z-index: 0; width: 100%;"></div>

                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow"
                            style="width: 40px; height: 40px;">1</div>
                        <small class="fw-bold mt-2 d-block">Toss</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">2</div>
                        <small class="fw-bold mt-2 d-block">Team 1 Squad</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">3</div>
                        <small class="fw-bold mt-2 d-block">Team 2 Squad</small>
                    </div>
                    <div class="text-center position-relative" style="z-index: 1;">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 40px; height: 40px;">4</div>
                        <small class="fw-bold mt-2 d-block">Confirm</small>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold mb-0">Confirm Match Details</h2>
                <a href="team_selection.php?id=<?= $match_id ?>&winner=<?= $setup['toss_winner'] ?>&choice=<?= $setup['toss_choice'] ?>&step=2"
                    class="btn btn-outline-secondary rounded-pill">
                    <i class="fas fa-edit me-2"></i>Edit Squads
                </a>
            </div>

            <!-- Toss Summary -->
            <div class="alert alert-info border-0 shadow-sm rounded-4 p-4 mb-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-white rounded-circle p-3 me-4 shadow-sm">
                        <i class="fas fa-coins text-info fs-3"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-1">Toss Result</h5>
                        <p class="mb-0 fs-5">
                            <strong><?= htmlspecialchars($toss_winner_name) ?></strong> won the toss and elected to
                            <strong><?= $setup['toss_choice'] ?></strong> first.
                        </p>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Team 1 Summary -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                        <div class="card-header bg-primary text-white p-4">
                            <?php
                            $t1_id = $team1_data['team_id'];
                            $t1_name = ($t1_id == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
                            $t1_logo = ($t1_id == $match['team1_id']) ? $match['team1_logo'] : $match['team2_logo'];
                            ?>
                            <div class="d-flex align-items-center">
                                <img src="<?= $t1_logo ? '/CPT_LEAGUE/uploads/teams/' . $t1_logo : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                    class="rounded-circle bg-white p-1 me-3"
                                    style="width: 60px; height: 60px; object-fit: contain;">
                                <div>
                                    <h4 class="fw-bold mb-0"><?= htmlspecialchars($t1_name) ?></h4>
                                    <span class="badge bg-white text-primary">Toss Winner</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <!-- Captain & VC -->
                                <div class="list-group-item bg-light p-3">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Captain</small>
                                            <span class="fw-bold"><i
                                                    class="fas fa-star text-warning me-1"></i><?= htmlspecialchars($team1_players[$team1_data['captain_id']]['name']) ?></span>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Vice-Captain</small>
                                            <span class="fw-bold"><i
                                                    class="fas fa-star-half-alt text-info me-1"></i><?= htmlspecialchars($team1_players[$team1_data['vice_captain_id']]['name']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Players -->
                                <?php foreach ($team1_data['players'] as $p_id): ?>
                                    <div class="list-group-item p-3 d-flex align-items-center">
                                        <img src="<?= $team1_players[$p_id]['profile_image'] ? '/CPT_LEAGUE/uploads/users/' . $team1_players[$p_id]['profile_image'] : '/CPT_LEAGUE/assets/images/default-player.png' ?>"
                                            class="rounded-circle me-3"
                                            style="width: 40px; height: 40px; object-fit: cover;">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($team1_players[$p_id]['name']) ?>
                                            </h6>
                                            <small
                                                class="text-muted"><?= htmlspecialchars($team1_players[$p_id]['playing_role']) ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team 2 Summary -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100 overflow-hidden">
                        <div class="card-header bg-dark text-white p-4">
                            <?php
                            $t2_id = $team2_data['team_id'];
                            $t2_name = ($t2_id == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
                            $t2_logo = ($t2_id == $match['team1_id']) ? $match['team1_logo'] : $match['team2_logo'];
                            ?>
                            <div class="d-flex align-items-center">
                                <img src="<?= $t2_logo ? '/CPT_LEAGUE/uploads/teams/' . $t2_logo : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                    class="rounded-circle bg-white p-1 me-3"
                                    style="width: 60px; height: 60px; object-fit: contain;">
                                <div>
                                    <h4 class="fw-bold mb-0"><?= htmlspecialchars($t2_name) ?></h4>
                                    <span class="badge bg-secondary">Opponent</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <!-- Captain & VC -->
                                <div class="list-group-item bg-light p-3">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Captain</small>
                                            <span class="fw-bold"><i
                                                    class="fas fa-star text-warning me-1"></i><?= htmlspecialchars($team2_players[$team2_data['captain_id']]['name']) ?></span>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Vice-Captain</small>
                                            <span class="fw-bold"><i
                                                    class="fas fa-star-half-alt text-info me-1"></i><?= htmlspecialchars($team2_players[$team2_data['vice_captain_id']]['name']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Players -->
                                <?php foreach ($team2_data['players'] as $p_id): ?>
                                    <div class="list-group-item p-3 d-flex align-items-center">
                                        <img src="<?= $team2_players[$p_id]['profile_image'] ? '/CPT_LEAGUE/uploads/users/' . $team2_players[$p_id]['profile_image'] : '/CPT_LEAGUE/assets/images/default-player.png' ?>"
                                            class="rounded-circle me-3"
                                            style="width: 40px; height: 40px; object-fit: cover;">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($team2_players[$p_id]['name']) ?>
                                            </h6>
                                            <small
                                                class="text-muted"><?= htmlspecialchars($team2_players[$p_id]['playing_role']) ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="text-center mt-5 mb-5">
                <form id="startMatchForm" action="finalize_match.php" method="POST">
                    <input type="hidden" name="match_id" value="<?= $match_id ?>">
                    <button id="startMatchButton" type="submit"
                        class="btn btn-success btn-lg px-5 py-3 rounded-pill shadow-lg start-match-btn">
                        <span class="btn-label">
                            <i class="fas fa-check-circle me-2"></i>Start Match Now
                        </span>
                        <span class="btn-loading d-none">
                            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            Starting Match...
                        </span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .start-match-btn {
        min-width: 240px;
        transition: opacity 0.2s ease, transform 0.2s ease;
    }

    .start-match-btn .btn-label,
    .start-match-btn .btn-loading {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .start-match-btn.is-loading {
        opacity: 0.9;
        cursor: wait;
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

        /* Toss Alert */
        .alert.p-4 {
            padding: 1rem !important;
        }

        .alert i.fs-3 {
            font-size: 1.5rem !important;
        }

        .alert .rounded-circle {
            padding: 0.5rem !important;
            margin-right: 1rem !important;
        }
        
        /* Headers */
        h2.fw-bold {
            font-size: 1.5rem;
            margin-bottom: 1rem !important;
        }
        
        .d-flex.justify-content-between.align-items-center.mb-4 {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 10px;
        }

        /* Team Cards */
        .card-header.p-4 {
            padding: 1rem !important;
        }

        .card-header img {
            width: 50px !important;
            height: 50px !important;
        }

        .card-header h4 {
            font-size: 1.2rem;
        }

        /* Player List */
        .list-group-item.p-3 {
            padding: 0.75rem !important;
        }

        .list-group-item img {
            width: 35px !important;
            height: 35px !important;
        }
        
        .list-group-item h6 {
            font-size: 0.95rem;
        }
        
        .list-group-item small {
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn-lg {
            width: 100%;
            padding: 12px !important;
            font-size: 1rem;
        }
        
        /* Layout */
        .col-lg-6.mb-4 {
            margin-bottom: 20px !important;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('startMatchForm');
        const button = document.getElementById('startMatchButton');

        if (!form || !button) {
            return;
        }

        form.addEventListener('submit', function (event) {
            if (button.dataset.loading === 'true') {
                event.preventDefault();
                return;
            }

            button.dataset.loading = 'true';
            button.disabled = true;
            button.classList.add('is-loading');

            const defaultLabel = button.querySelector('.btn-label');
            const loadingLabel = button.querySelector('.btn-loading');

            if (defaultLabel) {
                defaultLabel.classList.add('d-none');
            }

            if (loadingLabel) {
                loadingLabel.classList.remove('d-none');
            }
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
