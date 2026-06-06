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

// Fetch match and team details
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code
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
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Retrieve existing selections from session if any
$saved_winner = isset($_SESSION['match_setup'][$match_id]['toss_winner']) ? $_SESSION['match_setup'][$match_id]['toss_winner'] : '';
$saved_choice = isset($_SESSION['match_setup'][$match_id]['toss_choice']) ? $_SESSION['match_setup'][$match_id]['toss_choice'] : '';
$saved_winner_name = '';
if ($saved_winner) {
    $saved_winner_name = ($saved_winner == $match['team1_id']) ? $match['team1_name'] : $match['team2_name'];
}

$page_title = "Match Toss - " . $match['match_code'];
require_once '../../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10 text-center">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="../../NavBarList/matches.php" class="btn btn-outline-secondary rounded-pill">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
                <h2 class="text-primary fw-bold mb-0"><i class="fas fa-coins me-2"></i>Match Toss</h2>
                <div style="width: 85px;"></div> <!-- Spacer for centering -->
            </div>
            <div class="mb-4">
                <span class="badge bg-dark px-3 py-2"><?= htmlspecialchars($match['match_code']) ?></span>
                <span
                    class="ms-2 text-muted"><?= htmlspecialchars($match['tournament_name'] ?? ($match['match_type'] ?? 'Match')) ?></span>
            </div>

            <hr class="mb-5">

            <!-- Question 1 -->
            <div id="question1" class="toss-card mb-5 animate__animated animate__fadeIn">
                <h4 class="fw-bold mb-4">Which team won the toss?</h4>
                <div class="row justify-content-center gap-4">
                    <div class="col-md-5">
                        <div class="team-option <?= $saved_winner == $match['team1_id'] ? 'selected' : '' ?>"
                            onclick="selectTossWinner('<?= $match['team1_id'] ?>', '<?= addslashes($match['team1_name']) ?>', event)">
                            <div class="logo-wrapper">
                                <img src="<?= $match['team1_logo'] ? '/CPT_LEAGUE/uploads/teams/' . $match['team1_logo'] : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                    class="team-logo-btn" alt="<?= htmlspecialchars($match['team1_name']) ?>">
                            </div>
                            <p class="mt-3 fs-5 fw-bold text-dark"><?= htmlspecialchars($match['team1_name']) ?></p>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="team-option <?= $saved_winner == $match['team2_id'] ? 'selected' : '' ?>"
                            onclick="selectTossWinner('<?= $match['team2_id'] ?>', '<?= addslashes($match['team2_name']) ?>', event)">
                            <div class="logo-wrapper">
                                <img src="<?= $match['team2_logo'] ? '/CPT_LEAGUE/uploads/teams/' . $match['team2_logo'] : '/CPT_LEAGUE/assets/images/default-team.png' ?>"
                                    class="team-logo-btn" alt="<?= htmlspecialchars($match['team2_name']) ?>">
                            </div>
                            <p class="mt-3 fs-5 fw-bold text-dark"><?= htmlspecialchars($match['team2_name']) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Question 2 (Initially Hidden unless saved) -->
            <div id="question2" class="toss-card mb-5 animate__animated animate__fadeInUp"
                style="display: <?= $saved_winner ? 'block' : 'none' ?>;">
                <h4 class="fw-bold mb-4"><span id="tossWinnerAnnouncement"
                        class="text-success"><?= $saved_winner_name ? htmlspecialchars($saved_winner_name) . " won the toss." : "" ?></span>
                    Choose to:</h4>
                <div class="row justify-content-center gap-4 mt-4">
                    <div class="col-md-5">
                        <div class="choice-option <?= $saved_choice == 'bat' ? 'selected' : '' ?>"
                            onclick="selectChoice('bat', event)">
                            <div class="logo-wrapper">
                                <img src="/CPT_LEAGUE/assets/images/batting.jpg" class="choice-img-btn" alt="Batting">
                            </div>
                            <p class="mt-3 fs-5 fw-bold text-dark">Batting</p>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="choice-option <?= $saved_choice == 'bowl' ? 'selected' : '' ?>"
                            onclick="selectChoice('bowl', event)">
                            <div class="logo-wrapper">
                                <img src="/CPT_LEAGUE/assets/images/bowling.jpg" class="choice-img-btn" alt="Bowling">
                            </div>
                            <p class="mt-3 fs-5 fw-bold text-dark">Bowling</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Next Button -->
            <div id="nextBtnContainer" class="mt-5 mb-5 animate__animated animate__fadeIn"
                style="display: <?= ($saved_winner && $saved_choice) ? 'block' : 'none' ?>;">
                <a href="<?= ($saved_winner && $saved_choice) ? "team_selection.php?id=$match_id&winner=$saved_winner&choice=$saved_choice" : "#" ?>"
                    class="btn btn-primary btn-lg px-5 py-3 rounded-pill shadow" id="nextBtn">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .toss-card {
        background: #ffffff;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .logo-wrapper {
        padding: 15px;
        background: #f8fafc;
        border-radius: 15px;
        display: inline-block;
        transition: all 0.3s;
    }

    .team-logo-btn,
    .choice-img-btn {
        width: 160px;
        height: 160px;
        object-fit: contain;
        cursor: pointer;
        border-radius: 10px;
    }

    .team-option,
    .choice-option {
        cursor: pointer;
        padding: 20px;
        border-radius: 15px;
        transition: all 0.3s;
        border: 2px solid transparent;
    }

    .team-option:hover .logo-wrapper,
    .choice-option:hover .logo-wrapper {
        transform: translateY(-5px);
        box-shadow: 0 15px 25px rgba(0, 0, 0, 0.1);
        background: #e2e8f0;
    }

    .team-option.selected,
    .choice-option.selected {
        border-color: #0d6efd;
        background: rgba(13, 110, 253, 0.05);
    }

    .team-option.selected .logo-wrapper,
    .choice-option.selected .logo-wrapper {
        background: #0d6efd;
    }

    .team-option.selected p,
    .choice-option.selected p {
        color: #0d6efd !important;
    }

    .animate__animated {
        animation-duration: 0.6s;
    }

    @media (max-width: 480px) {
        .toss-card {
            padding: 20px;
        }

        .team-logo-btn,
        .choice-img-btn {
            width: 100px;
            height: 100px;
        }

        .toss-card h4 {
            font-size: 1.2rem;
            margin-bottom: 1rem !important;
        }

        .team-option p,
        .choice-option p {
            font-size: 1rem !important;
            margin-top: 10px !important;
        }

        .logo-wrapper {
            padding: 10px;
            border-radius: 12px;
        }

        .team-option,
        .choice-option {
            padding: 10px;
        }

        #nextBtn {
            padding: 12px 30px !important;
            font-size: 1rem;
            width: 100%;
        }

        h2.text-primary {
            font-size: 1.4rem;
        }

        /* Adjust spacing for stacked columns */
        .col-md-5:not(:last-child) {
            margin-bottom: 20px;
        }

        .container.py-5 {
            padding-top: 20px !important;
            padding-bottom: 20px !important;
        }

        .mb-5 {
            margin-bottom: 20px !important;
        }
    }
</style>

<script>
    let tossWinnerId = '<?= $saved_winner ?>';
    let tossChoice = '<?= $saved_choice ?>';

    function selectTossWinner(id, name, event) {
        tossWinnerId = id;

        // Remove selection from others
        document.querySelectorAll('.team-option').forEach(el => el.classList.remove('selected'));
        // Add selection to clicked
        event.currentTarget.classList.add('selected');

        // Show question 2
        document.getElementById('tossWinnerAnnouncement').textContent = name + " won the toss.";
        document.getElementById('question2').style.display = 'block';

        // Scroll to question 2
        setTimeout(() => {
            document.getElementById('question2').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);

        // Update Next button link if choice already exists
        updateNextLink();
    }

    function selectChoice(choice, event) {
        tossChoice = choice;

        // Remove selection from others
        document.querySelectorAll('.choice-option').forEach(el => el.classList.remove('selected'));
        // Add selection to clicked
        event.currentTarget.classList.add('selected');

        updateNextLink();

        // Show next button
        document.getElementById('nextBtnContainer').style.display = 'block';

        // Scroll to next button
        setTimeout(() => {
            document.getElementById('nextBtnContainer').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    }

    function updateNextLink() {
        if (tossWinnerId && tossChoice) {
            const matchId = '<?= $match_id ?>';
            document.getElementById('nextBtn').href = `team_selection.php?id=${matchId}&winner=${tossWinnerId}&choice=${tossChoice}`;
        }
    }
</script>

<?php require_once '../../includes/footer.php'; ?>