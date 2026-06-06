<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: ../NavBarList/point_tables.php");
    exit();
}

$table_id = (int) $_GET['id'];

// Fetch point table details
try {
    $stmt = $pdo->prepare("
        SELECT pt.*, t.tournament_name
        FROM point_tables pt
        LEFT JOIN tournaments t ON pt.tournament_id = t.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$table_id]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        header("Location: ../NavBarList/point_tables.php");
        exit();
    }
} catch (PDOException $e) {
    header("Location: ../NavBarList/point_tables.php");
    exit();
}

// Fetch all tournaments
$tournaments = [];
try {
    $stmt = $pdo->query("SELECT id, tournament_name FROM tournaments ORDER BY tournament_name");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Fetch teams in this point table
$current_team_ids = [];
$current_teams = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.team_name, t.team_code, t.team_logo
        FROM point_table_entries pte
        JOIN teams t ON pte.team_id = t.id
        WHERE pte.point_table_id = ?
        ORDER BY t.team_name
    ");
    $stmt->execute([$table_id]);
    $current_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_team_ids = array_column($current_teams, 'id');
} catch (PDOException $e) {
    // Handle error
}

// Fetch all teams for selection (excluding teams already in other point tables, but including current teams)
$all_teams = [];
try {
    if (!empty($current_team_ids)) {
        $placeholders = str_repeat('?,', count($current_team_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, team_name, team_code, team_logo 
            FROM teams 
            WHERE id IN ($placeholders) OR id NOT IN (SELECT DISTINCT team_id FROM point_table_entries WHERE point_table_id != ?)
            ORDER BY team_name
        ");
        $params = array_merge($current_team_ids, [$table_id]);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, team_name, team_code, team_logo 
            FROM teams 
            WHERE id NOT IN (SELECT DISTINCT team_id FROM point_table_entries WHERE point_table_id != ?)
            ORDER BY team_name
        ");
        $stmt->execute([$table_id]);
    }
    $all_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['table_name'])) {
            throw new Exception("Point table name is required.");
        }

        $selected_teams = $_POST['teams'] ?? [];
        if (count($selected_teams) < 2) {
            throw new Exception("Please select at least 2 teams.");
        }

        // Start transaction
        $pdo->beginTransaction();

        // Update point table
        $stmt = $pdo->prepare("
            UPDATE point_tables SET
                table_name = ?, 
                tournament_id = ?, 
                win_points = ?, 
                loss_points = ?, 
                draw_points = ?, 
                nr_points = ?, 
                qualify_count = ?, 
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            trim($_POST['table_name']),
            $_POST['tournament_id'] ? (int) $_POST['tournament_id'] : null,
            (int) ($_POST['win_points'] ?? 2),
            (int) ($_POST['loss_points'] ?? 0),
            (int) ($_POST['draw_points'] ?? 1),
            (int) ($_POST['nr_points'] ?? 1),
            (int) ($_POST['qualify_count'] ?? 4),
            $table_id
        ]);

        // Delete existing team entries
        $stmt = $pdo->prepare("DELETE FROM point_table_entries WHERE point_table_id = ?");
        $stmt->execute([$table_id]);

        // Insert updated team entries
        $stmt = $pdo->prepare("INSERT INTO point_table_entries (point_table_id, team_id) VALUES (?, ?)");
        foreach ($selected_teams as $team_id) {
            $stmt->execute([$table_id, (int) $team_id]);
        }

        $pdo->commit();

        // Redirect with success message
        header("Location: ../NavBarList/point_tables.php?updated=1");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$page_title = "Edit Point Table";
require_once '../includes/header.php';
?>

<!-- Glassmorphism Styling -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    :root {
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        --secondary-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        --text-primary: #1f2937;
        --text-secondary: #6b7280;
    }

    body {
        font-family: 'Outfit', sans-serif;
        background-color: #f3f4f6;
    }

    .main-container {
        min-height: 100vh;
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        background-image:
            radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 0%, rgba(168, 85, 247, 0.1) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(59, 130, 246, 0.1) 0px, transparent 50%),
            radial-gradient(at 0% 100%, rgba(236, 72, 153, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        box-shadow: var(--glass-shadow);
        border-radius: 24px;
        overflow: hidden;
        animation: slideUp 0.6s ease-out;
    }

    .card-header-custom {
        background: var(--primary-gradient);
        padding: 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
        overflow: hidden;
    }

    .card-header-custom::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, transparent 48%, rgba(255, 255, 255, 0.1) 50%, transparent 52%);
        background-size: 200% 200%;
        animation: shine 10s infinite linear;
    }

    @keyframes shine {
        0% {
            background-position: 200% 0;
        }

        100% {
            background-position: -200% 0;
        }
    }

    .card-header-title {
        color: white;
        margin: 0;
        font-weight: 700;
        font-size: 1.75rem;
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .form-label {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        border: 2px solid #e5e7eb;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        background-color: rgba(255, 255, 255, 0.8);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        background-color: #fff;
    }

    .section-title {
        color: var(--text-secondary);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 700;
        margin: 2rem 0 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .section-title::after {
        content: '';
        flex-grow: 1;
        height: 1px;
        background: #e5e7eb;
    }

    /* Team Selection Styles */
    .team-checkbox-card {
        position: relative;
    }

    .team-checkbox-card .form-check-input {
        position: absolute;
        top: 15px;
        right: 15px;
        z-index: 2;
        width: 1.25rem;
        height: 1.25rem;
        cursor: pointer;
    }

    .team-checkbox-card .team-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        border: 2px solid transparent;
        border-radius: 16px;
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        height: 100%;
    }

    .team-checkbox-card .team-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .team-checkbox-card input:checked+label .team-card {
        border-color: #6366f1;
        background-color: #eef2ff;
        box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);
    }

    /* Table Styles */
    .preview-table-container {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        border: 1px solid #e5e7eb;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: #f8fafc;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
        background: white;
        border-bottom: 1px solid #f3f4f6;
    }

    .preview-team {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .preview-team img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .points-input-card {
        background: white;
        padding: 1.5rem;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        text-align: center;
        height: 100%;
        transition: transform 0.2s;
    }

    .points-input-card:hover {
        transform: translateY(-2px);
        border-color: #6366f1;
    }

    .btn-action {
        border-radius: 12px;
        padding: 0.75rem 1.5rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: none;
    }

    .btn-create {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3);
    }

    .btn-create:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px -3px rgba(99, 102, 241, 0.4);
        color: white;
    }

    .btn-back {
        background: white;
        color: var(--text-primary);
        border: 1px solid #e5e7eb;
    }

    .btn-back:hover {
        background: #f9fafb;
        border-color: #d1d5db;
        transform: translateY(-2px);
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
</style>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-edit"></i>
                        Edit Point Table
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

                    <?php if (empty($all_teams)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-warning">
                                <i class="fas fa-users-slash fa-4x opacity-50"></i>
                            </div>
                            <h4 class="fw-bold text-dark">No Teams Available</h4>
                            <p class="text-muted mb-4">You need to create teams first to edit point tables.</p>
                            <a href="create_team.php" class="btn btn-action btn-create">
                                <i class="fas fa-plus"></i> Create Team
                            </a>
                        </div>
                    <?php else: ?>
                        <form id="editPointTableForm" method="POST" action="" class="needs-validation" novalidate>

                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Point Table Name <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                            <i class="fas fa-signature text-muted"></i>
                                        </span>
                                        <input type="text" class="form-control border-start-0 rounded-end-4"
                                            name="table_name" value="<?= htmlspecialchars($table['table_name']) ?>" required
                                            placeholder="e.g., Group A, Group B, Super 8">
                                    </div>
                                    <div class="invalid-feedback">Please provide a point table name.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tournament (Optional)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 rounded-start-4">
                                            <i class="fas fa-trophy text-muted"></i>
                                        </span>
                                        <select class="form-select border-start-0 rounded-end-4" name="tournament_id">
                                            <option value="">Select Tournament</option>
                                            <?php foreach ($tournaments as $tournament): ?>
                                                <option value="<?= $tournament['id'] ?>"
                                                    <?= $table['tournament_id'] == $tournament['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($tournament['tournament_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Teams Selection -->
                            <div class="section-title">
                                <i class="fas fa-users"></i> Select Teams
                            </div>

                            <div class="alert alert-info border-0 bg-opacity-10 bg-info rounded-3 mb-4">
                                <div class="d-flex">
                                    <i class="fas fa-info-circle text-info mt-1 me-3"></i>
                                    <div>
                                        <strong>Note:</strong> Teams already assigned to other point tables are not shown
                                        here.
                                        <span class="fw-bold">(Currently selected: <?= count($current_teams) ?>
                                            teams)</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <?php foreach ($all_teams as $team): ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="team-checkbox-card h-100">
                                            <input type="checkbox" class="form-check-input" name="teams[]"
                                                value="<?= $team['id'] ?>" id="team<?= $team['id'] ?>" <?= in_array($team['id'], $current_team_ids) ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100 h-100" for="team<?= $team['id'] ?>">
                                                <div class="card team-card p-3">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <img src="<?= $team['team_logo'] ? '../uploads/teams/' . $team['team_logo'] : '../images/default-team.png' ?>"
                                                            alt="<?= htmlspecialchars($team['team_name']) ?>"
                                                            style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #f3f4f6;">
                                                        <div>
                                                            <h6 class="mb-0 fw-bold text-dark">
                                                                <?= htmlspecialchars($team['team_name']) ?>
                                                            </h6>
                                                            <small
                                                                class="text-muted fw-medium"><?= $team['team_code'] ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Preview Table -->
                            <div class="section-title">
                                <i class="fas fa-eye"></i> Live Preview
                            </div>

                            <div class="preview-table-container mb-5">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="pointTablePreview">
                                        <thead>
                                            <tr>
                                                <th width="50" class="text-center">#</th>
                                                <th>Team</th>
                                                <th width="80" class="text-center">P</th>
                                                <th width="80" class="text-center">W</th>
                                                <th width="80" class="text-center">L</th>
                                                <th width="80" class="text-center">D</th>
                                                <th width="80" class="text-center">NR</th>
                                                <th width="80" class="text-center">Pts</th>
                                                <th width="80" class="text-center">NRR</th>
                                            </tr>
                                        </thead>
                                        <tbody id="previewBody">
                                            <!-- JS populated -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Point System -->
                            <div class="section-title">
                                <i class="fas fa-calculator"></i> Point System Configuration
                            </div>

                            <div class="row g-3 mb-5">
                                <div class="col-md-3 col-6">
                                    <div class="points-input-card">
                                        <label class="form-label text-success">Win Points</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5" name="win_points"
                                            value="<?= $table['win_points'] ?? 2 ?>" min="1">
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="points-input-card">
                                        <label class="form-label text-danger">Loss Points</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5"
                                            name="loss_points" value="<?= $table['loss_points'] ?? 0 ?>" min="0">
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="points-input-card">
                                        <label class="form-label text-warning">Draw Points</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5"
                                            name="draw_points" value="<?= $table['draw_points'] ?? 1 ?>" min="0">
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="points-input-card">
                                        <label class="form-label text-secondary">No Result</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5" name="nr_points"
                                            value="<?= $table['nr_points'] ?? 1 ?>" min="0">
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <div
                                        class="p-3 bg-white border rounded-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                                        <label class="form-label mb-0 text-primary">
                                            <i class="fas fa-medal me-2"></i>Qualifying Teams (Top N)
                                        </label>
                                        <input type="number" class="form-control w-auto" name="qualify_count"
                                            value="<?= $table['qualify_count'] ?? 4 ?>" min="1" style="max-width: 100px;">
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="d-flex justify-content-between align-items-center pt-4 border-top">
                                <a href="../NavBarList/point_tables.php" class="btn btn-action btn-back">
                                    <i class="fas fa-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-action btn-create">
                                    <i class="fas fa-save"></i> Update Point Table
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const teams = <?= json_encode($all_teams) ?>;
    const previewBody = document.getElementById('previewBody');

    // Update preview when teams are selected
    document.querySelectorAll('input[name="teams[]"]').forEach(checkbox => {
        checkbox.addEventListener('change', updatePreview);
    });

    function updatePreview() {
        previewBody.innerHTML = '';
        let selectedCount = 0;

        document.querySelectorAll('input[name="teams[]"]:checked').forEach((checkbox, index) => {
            const teamId = checkbox.value;
            const team = teams.find(t => t.id == teamId);

            if (team) {
                const row = document.createElement('tr');
                row.innerHTML = `
                <td class="text-center fw-bold text-muted">${index + 1}</td>
                <td>
                    <div class="preview-team">
                        <img src="${team.team_logo ? '../uploads/teams/' + team.team_logo : '../images/default-team.png'}" 
                             alt="${team.team_name}">
                        <div>
                            <span class="d-block fw-bold text-dark">${team.team_name}</span>
                            <span class="small text-muted">${team.team_code}</span>
                        </div>
                    </div>
                </td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center">0</td>
                <td class="text-center fw-bold text-primary">0</td>
                <td class="text-center font-monospace">0.000</td>
            `;
                previewBody.appendChild(row);
                selectedCount++;
            }
        });

        // Show empty state if no teams selected
        if (selectedCount === 0) {
            previewBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="fas fa-arrow-up me-2"></i>Select teams above to preview the table
                    </td>
                </tr>
            `;
        }

        // Update table header count
        const tableHeader = document.querySelector('#pointTablePreview thead tr');
        if (tableHeader) {
            tableHeader.cells[1].innerHTML = `Team (${selectedCount})`;
        }
    }

    // Initial preview update
    updatePreview();

    document.getElementById('editPointTableForm').addEventListener('submit', function (e) {
        const form = this;

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const selectedTeams = document.querySelectorAll('input[name="teams[]"]:checked').length;
        if (selectedTeams < 2) {
            e.preventDefault();
            alert('Please select at least 2 teams');
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
    });
</script>

<?php require_once '../includes/footer.php'; ?>