<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Get match ID from URL
$match_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$match_id) {
    header("Location: ../NavBarList/matches.php?error=3");
    exit();
}

// Fetch match details
$match = null;
try {
    $stmt = $pdo->prepare("
        SELECT m.*, t1.team_name as team1_name, t1.team_logo as team1_logo, t1.team_code as team1_code,
               t2.team_name as team2_name, t2.team_logo as team2_logo, t2.team_code as team2_code,
               tr.tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        header("Location: ../NavBarList/matches.php?error=3");
        exit();
    }
} catch (PDOException $e) {
    header("Location: ../NavBarList/matches.php?error=1");
    exit();
}

// Fetch teams for dropdown
$teams = [];
try {
    $stmt = $pdo->query("SELECT id, team_name, team_logo, team_code FROM teams ORDER BY team_name");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch tournaments for dropdown
$tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['team1_id', 'team2_id', 'match_date', 'match_time', 'venue'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        // Validate teams are different
        if ($_POST['team1_id'] === $_POST['team2_id']) {
            throw new Exception("Please select two different teams.");
        }

        // Validate date is not in the past (only for upcoming matches)
        if ($match['status'] === 'upcoming') {
            $match_datetime = strtotime($_POST['match_date'] . ' ' . $_POST['match_time']);
            if ($match_datetime < time()) {
                throw new Exception("Match date and time cannot be in the past.");
            }
        }

        // Update match
        $stmt = $pdo->prepare("
            UPDATE matches SET
                team1_id = ?, team2_id = ?, match_date = ?, match_time = ?, venue = ?,
                match_type = ?, overs = ?, tournament_id = ?, updated_at = CURRENT_TIMESTAMP()
            WHERE id = ?
        ");

        $stmt->execute([
            (int) $_POST['team1_id'],
            (int) $_POST['team2_id'],
            $_POST['match_date'],
            $_POST['match_time'],
            trim($_POST['venue']),
            $_POST['match_type'] ?? 'League',
            (int) ($_POST['overs'] ?? 20),
            $_POST['tournament_id'] ? (int) $_POST['tournament_id'] : null,
            $match_id
        ]);

        // Log the update
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value, new_value)
            VALUES (?, 'UPDATE', 'matches', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $match_id,
            json_encode($match),
            json_encode(array_merge($match, $_POST))
        ]);

        // Redirect with success message
        header("Location: ../NavBarList/matches.php?success=3");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Edit Match";
require_once '../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    :root {
        --primary: #4f46e5;
        --secondary: #7c3aed;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --dark: #1e293b;
        --light: #f8fafc;
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --input-bg: #f8fafc;
        --input-border: #e2e8f0;
    }

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f1f5f9;
        background-image:
            radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 0%, rgba(124, 58, 237, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .main-container {
        padding-top: 2rem;
        padding-bottom: 4rem;
        min-height: calc(100vh - 76px);
    }

    /* Glass Card */
    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .card-header-custom {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        padding: 1.5rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .card-header-title {
        color: white;
        font-weight: 700;
        font-size: 1.25rem;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    /* Form Elements */
    .form-label {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
    }

    .form-control,
    .form-select {
        background-color: var(--input-bg);
        border: 2px solid var(--input-border);
        border-radius: 12px;
        padding: 0.75rem 1rem;
        font-size: 1rem;
        font-weight: 500;
        color: var(--dark);
        transition: all 0.2s ease;
    }

    .form-control:focus,
    .form-select:focus {
        background-color: #fff;
        border-color: var(--warning);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        outline: none;
    }

    /* Team Select & Preview */
    .team-selector {
        position: relative;
    }

    .team-preview {
        margin-top: 1rem;
        background: white;
        border: 2px dashed var(--input-border);
        border-radius: 16px;
        padding: 1rem;
        min-height: 100px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .team-preview.active {
        background: #fffbeb;
        border-color: #fcd34d;
        border-style: solid;
    }

    .team-logo-display {
        width: 64px;
        height: 64px;
        object-fit: contain;
        filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .team-preview:hover .team-logo-display {
        transform: scale(1.1) rotate(5deg);
    }

    .team-name-display {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--dark);
        margin-left: 1rem;
    }

    /* VS Badge */
    .vs-container {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem 0;
    }

    .vs-badge {
        width: 60px;
        height: 60px;
        background: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.2rem;
        color: var(--danger);
        box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.3);
        position: relative;
        z-index: 2;
        border: 4px solid #f8fafc;
    }

    /* Buttons */
    .btn-action {
        padding: 0.8rem 2rem;
        border-radius: 50px;
        font-weight: 600;
        letter-spacing: 0.02em;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        border: none;
    }

    .btn-save {
        background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        color: white;
        box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.4);
    }

    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 25px -5px rgba(245, 158, 11, 0.5);
        color: white;
    }

    .btn-back {
        background: white;
        color: var(--dark);
        border: 1px solid var(--input-border);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .btn-back:hover {
        background: #f8fafc;
        transform: translateY(-2px);
        color: var(--dark);
    }

    /* Section Divider */
    .section-title {
        color: var(--secondary);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 700;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .section-title::after {
        content: '';
        height: 1px;
        flex-grow: 1;
        background: linear-gradient(to right, var(--input-border), transparent);
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .vs-container {
            padding: 0;
            margin: 1rem 0;
        }

        .vs-badge {
            width: 40px;
            height: 40px;
            font-size: 0.9rem;
        }

        .card-body {
            padding: 1.5rem;
        }
    }
</style>

<div class="container-fluid main-container">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-edit"></i>
                        Edit Match - <?= htmlspecialchars($match['match_code']) ?>
                    </h4>
                </div>

                <!-- Body -->
                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-3 border-0 shadow-sm d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($teams)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-warning">
                                <i class="fas fa-users-slash fa-4x opacity-50"></i>
                            </div>
                            <h4 class="fw-bold text-dark">No Teams Found</h4>
                            <p class="text-muted mb-4">You need to create at least two teams to edit a match.</p>
                            <a href="create_team.php" class="btn btn-save btn-action">
                                <i class="fas fa-plus me-2"></i>Create Team
                            </a>
                        </div>
                    <?php else: ?>
                        <form id="editMatchForm" method="POST" action="" class="needs-validation" novalidate>

                            <!-- Teams Section -->
                            <div class="section-title">
                                <i class="fas fa-shield-alt"></i> Match Contenders
                            </div>

                            <div class="row align-items-stretch mb-5">
                                <!-- Team 1 -->
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label class="form-label">Home Team <span class="text-danger">*</span></label>
                                        <select class="form-select" name="team1_id" id="team1Select" required>
                                            <option value="">Select Team...</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?= $team['id'] ?>" data-logo="<?= $team['team_logo'] ?>"
                                                    <?= $team['id'] == $match['team1_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($team['team_name']) ?> (<?= $team['team_code'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select the first team.</div>
                                        <div class="team-preview" id="team1Preview">
                                            <span class="text-muted small">Select team to preview</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- VS -->
                                <div class="col-md-2">
                                    <div class="vs-container">
                                        <div class="vs-badge">VS</div>
                                    </div>
                                </div>

                                <!-- Team 2 -->
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label class="form-label">Away Team <span class="text-danger">*</span></label>
                                        <select class="form-select" name="team2_id" id="team2Select" required>
                                            <option value="">Select Team...</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?= $team['id'] ?>" data-logo="<?= $team['team_logo'] ?>"
                                                    <?= $team['id'] == $match['team2_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($team['team_name']) ?> (<?= $team['team_code'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select the second team.</div>
                                        <div class="team-preview" id="team2Preview">
                                            <span class="text-muted small">Select team to preview</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Match Details Section -->
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i> Match Logistics
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="match_date"
                                        value="<?= $match['match_date'] ?>" required>
                                    <div class="invalid-feedback">Required</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="match_time"
                                        value="<?= $match['match_time'] ?>" required>
                                    <div class="invalid-feedback">Required</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Venue <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"
                                            style="border-radius: 12px 0 0 12px; border: 2px solid var(--input-border);">
                                            <i class="fas fa-map-marker-alt text-muted"></i>
                                        </span>
                                        <input type="text" class="form-control border-start-0 ps-0" name="venue"
                                            value="<?= htmlspecialchars($match['venue']) ?>" required
                                            placeholder="Stadium / Ground Name" style="border-radius: 0 12px 12px 0;">
                                    </div>
                                    <div class="invalid-feedback">Please provide a venue.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Format / Type</label>
                                    <select class="form-select" name="match_type">
                                        <option value="League" <?= $match['match_type'] == 'League' ? 'selected' : '' ?>>League
                                        </option>
                                        <option value="Quarter Final" <?= $match['match_type'] == 'Quarter Final' ? 'selected' : '' ?>>Quarter Final</option>
                                        <option value="Semi Final" <?= $match['match_type'] == 'Semi Final' ? 'selected' : '' ?>>Semi Final</option>
                                        <option value="Final" <?= $match['match_type'] == 'Final' ? 'selected' : '' ?>>Final
                                        </option>
                                        <option value="Friendly" <?= $match['match_type'] == 'Friendly' ? 'selected' : '' ?>>
                                            Friendly</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Configuration Section -->
                            <div class="section-title">
                                <i class="fas fa-cogs"></i> Configuration
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-lg-6">
                                    <label class="form-label">Overs per Innings</label>
                                    <div class="p-3 bg-white border rounded-4">
                                        <?php
                                        // Determined selected overs state
                                        $overs = (int) $match['overs'];
                                        $is_custom = !in_array($overs, [6, 8, 10, 20, 50]);
                                        ?>
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <!-- Hidden input to store the actual value sent to DB -->
                                            <input type="hidden" name="overs" id="finalOvers" value="<?= $overs ?>">

                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over6" value="6" <?= $overs == 6 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="over6">6 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over8" value="8" <?= $overs == 8 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="over8">8 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over10" value="10" <?= $overs == 10 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="over10">10 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over20" value="20" <?= $overs == 20 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="over20">20 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="over50" value="50" <?= $overs == 50 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="over50">50 Overs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input overs-preset" type="radio"
                                                    name="overs_preset" id="overCustom" value="custom" <?= $is_custom ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="overCustom">Custom</label>
                                            </div>
                                        </div>

                                        <!-- Custom Input Container -->
                                        <div id="customOversContainer" class="mt-3"
                                            style="<?= $is_custom ? 'display: block;' : 'display: none;' ?>">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-hashtag text-muted"></i>
                                                </span>
                                                <input type="number" class="form-control border-start-0"
                                                    id="customOversInput" value="<?= $is_custom ? $overs : '' ?>"
                                                    placeholder="Enter number of overs" min="1" max="500" <?= $is_custom ? 'required' : '' ?>>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label">Tournament Series</label>
                                    <select class="form-select" name="tournament_id">
                                        <option value="">Independent Match (Friendly)</option>
                                        <?php foreach ($tournaments as $tournament): ?>
                                            <option value="<?= $tournament['id'] ?>"
                                                <?= $tournament['id'] == $match['tournament_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tournament['tournament_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Match Status Info -->
                            <div class="row mb-5">
                                <div class="col-12">
                                    <div
                                        class="p-3 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3 d-flex align-items-center">
                                        <i class="fas fa-info-circle text-info me-3 fs-4"></i>
                                        <div>
                                            <h6 class="mb-1 text-info fw-bold">Match Status:
                                                <?= ucfirst($match['status']) ?>
                                            </h6>
                                            <p class="mb-0 small text-muted">Match Code: <span
                                                    class="font-monospace text-dark"><?= htmlspecialchars($match['match_code']) ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="d-flex justify-content-between align-items-center pt-3 mt-4 border-top">
                                <a href="../NavBarList/matches.php" class="btn btn-action btn-back">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Matches
                                </a>
                                <button type="submit" class="btn btn-action btn-save">
                                    <i class="fas fa-save me-2"></i>Update Match
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional Custom Styles for Radio Inputs -->
<style>
    .form-check-input:checked {
        background-color: var(--primary);
        border-color: var(--primary);
    }
</style>

<script>
    const teams = <?= json_encode($teams) ?>;

    // Logic for Custom Overs Selection
    document.addEventListener('DOMContentLoaded', function() {
        const presets = document.querySelectorAll('.overs-preset');
        const finalInput = document.getElementById('finalOvers');
        const customContainer = document.getElementById('customOversContainer');
        const customInput = document.getElementById('customOversInput');

        if(presets.length > 0) {
            presets.forEach(preset => {
                preset.addEventListener('change', function() {
                    if (this.value === 'custom') {
                        customContainer.style.display = 'block';
                        customInput.required = true;
                        customInput.focus();
                        finalInput.value = customInput.value; // Sync if value exists
                    } else {
                        customContainer.style.display = 'none';
                        customInput.required = false;
                        finalInput.value = this.value;
                    }
                });
            });

            customInput.addEventListener('input', function() {
                finalInput.value = this.value;
            });
        }
    });

    // Initialize team previews on page load
    document.addEventListener('DOMContentLoaded', function () {
        updateTeamPreview('team1Preview', document.getElementById('team1Select').value);
        updateTeamPreview('team2Preview', document.getElementById('team2Select').value);
    });

    document.getElementById('team1Select').addEventListener('change', function () {
        updateTeamPreview('team1Preview', this.value);
        updateTeam2Options();
    });

    document.getElementById('team2Select').addEventListener('change', function () {
        updateTeamPreview('team2Preview', this.value);
        updateTeam1Options();
    });

    function updateTeamPreview(previewId, teamId) {
        const preview = document.getElementById(previewId);
        const team = teams.find(t => t.id == teamId);

        if (team) {
            preview.innerHTML = `
            <div class="d-flex align-items-center">
                <img src="${team.team_logo ? '../uploads/teams/' + team.team_logo : '../images/default-team.png'}"
                     alt="${team.team_name}"
                     class="team-logo-display">
                <div class="team-name-display">
                    ${team.team_name}
                </div>
            </div>
        `;
        } else {
            preview.innerHTML = '<div class="text-muted">Select a team</div>';
        }
    }

    function updateTeam2Options() {
        const team1Value = document.getElementById('team1Select').value;
        const team2Select = document.getElementById('team2Select');
        const currentTeam2Value = team2Select.value;

        // Reset team2 options
        team2Select.innerHTML = '<option value="">Select Team 2</option>';

        teams.forEach(team => {
            if (team.id != team1Value) {
                const option = document.createElement('option');
                option.value = team.id;
                option.textContent = team.team_name + ' (' + team.team_code + ')';
                option.setAttribute('data-logo', team.team_logo);
                if (team.id == currentTeam2Value && team.id != team1Value) {
                    option.selected = true;
                }
                team2Select.appendChild(option);
            }
        });
    }

    function updateTeam1Options() {
        const team2Value = document.getElementById('team2Select').value;
        const team1Select = document.getElementById('team1Select');
        const currentTeam1Value = team1Select.value;

        // Reset team1 options
        team1Select.innerHTML = '<option value="">Select Team 1</option>';

        teams.forEach(team => {
            if (team.id != team2Value) {
                const option = document.createElement('option');
                option.value = team.id;
                option.textContent = team.team_name + ' (' + team.team_code + ')';
                option.setAttribute('data-logo', team.team_logo);
                if (team.id == currentTeam1Value && team.id != team2Value) {
                    option.selected = true;
                }
                team1Select.appendChild(option);
            }
        });
    }

    document.getElementById('editMatchForm').addEventListener('submit', function (e) {
        const form = this;

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const team1 = document.getElementById('team1Select').value;
        const team2 = document.getElementById('team2Select').value;

        if (team1 === team2) {
            e.preventDefault();
            alert('Please select two different teams');
            return;
        }

        // Add loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
        submitBtn.disabled = true;

        // Re-enable after 10 seconds as fallback
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);

        // Allow form to submit normally
    });
</script>

<?php require_once '../includes/footer.php'; ?>