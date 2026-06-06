<?php
require_once '../includes/db.php';
// Public access - no login required

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $point_table_id = (int) $_GET['delete'];

        // Start transaction
        $pdo->beginTransaction();

        // Delete point table entries first
        $stmt = $pdo->prepare("DELETE FROM point_table_entries WHERE point_table_id = ?");
        $stmt->execute([$point_table_id]);

        // Delete point table
        $stmt = $pdo->prepare("DELETE FROM point_tables WHERE id = ?");
        $stmt->execute([$point_table_id]);

        $pdo->commit();

        // Redirect with success message
        header("Location: point_tables.php?deleted=1");
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to delete point table: " . $e->getMessage();
    }
}

// Handle success messages
$success_message = '';
if (isset($_GET['deleted'])) {
    $success_message = 'Point table deleted successfully!';
} elseif (isset($_GET['updated'])) {
    $success_message = 'Point table updated successfully!';
}

// Fetch point tables
$pointTables = [];
try {
    $stmt = $pdo->query("
        SELECT pt.*, t.tournament_name,
               COUNT(DISTINCT pte.team_id) as team_count,
               (SELECT GROUP_CONCAT(t2.team_logo) FROM point_table_entries pte2 JOIN teams t2 ON pte2.team_id = t2.id WHERE pte2.point_table_id = pt.id) as team_logos,
               (SELECT COUNT(*) FROM matches m 
                WHERE m.status = 'completed'
                AND m.team1_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id)
                AND m.team2_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id)
               ) as match_count,
               (SELECT SUM(bbb.runs_scored + bbb.extra_runs) 
                FROM ball_by_ball bbb 
                JOIN matches m ON bbb.match_id = m.id 
                WHERE (
                    (m.team1_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id))
                    OR 
                    (m.team2_id IN (SELECT team_id FROM point_table_entries WHERE point_table_id = pt.id))
                )
               ) as total_runs
        FROM point_tables pt
        LEFT JOIN tournaments t ON pt.tournament_id = t.id
        LEFT JOIN point_table_entries pte ON pt.id = pte.point_table_id
        GROUP BY pt.id
        ORDER BY pt.created_at DESC
    ");
    $pointTables = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

$page_title = "Point Tables";
require_once '../includes/header.php';
?>

<style>
    /* Global Styles */
    .letter-spacing-2 {
        letter-spacing: 2px;
    }

    /* Point Table Card */
    .point-table-card {
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
        background: #fff;
    }

    .point-table-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15) !important;
    }

    .team-avatar-wrapper {
        margin-left: -12px;
        transition: transform 0.2s ease;
    }

    .team-avatar-wrapper:first-child {
        margin-left: 0;
    }

    .team-avatar-wrapper:hover {
        transform: translateY(-3px) scale(1.1);
        z-index: 10 !important;
    }

    .team-avatar-wrapper img {
        width: 36px;
        height: 36px;
        object-fit: contain;
        background: #fff;
    }

    .point-system-grid {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
    }

    /* Dropdown */
    .dropdown-item:active {
        background-color: #e9ecef;
        color: #1e293b;
    }

    /* Responsive Adjustments */
    @media (max-width: 576px) {
        .team-avatar-wrapper img {
            width: 30px;
            height: 30px;
        }
    }
</style>

<div class="container-fluid py-4"
    style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%); min-height: calc(100vh - 76px);">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-table me-2 text-primary"></i>Point Tables</h2>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
            <a href="../admin/create_point_table.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-plus me-2"></i>Create Table
            </a>
        <?php endif; ?>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($pointTables)): ?>
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <span class="fa-stack fa-3x">
                        <i class="fas fa-circle fa-stack-2x text-light"></i>
                        <i class="fas fa-table fa-stack-1x text-secondary"></i>
                    </span>
                </div>
                <h4 class="text-muted fw-bold">No point tables available</h4>
                <p class="text-secondary">Check back later for updated point tables.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($pointTables as $table): ?>
                <div class="col-xl-4 col-lg-6">
                    <div class="card point-table-card border-0 shadow-lg h-100 position-relative">
                        <!-- Status Badge -->
                        <div class="position-absolute top-0 end-0 mt-3 me-3 z-2">
                            <span
                                class="badge rounded-pill bg-white text-<?= $table['status'] == 'active' ? 'success' : 'secondary' ?> shadow-sm border px-3 py-2">
                                <i class="fas fa-circle small me-1"></i><?= ucfirst($table['status']) ?>
                            </span>
                        </div>

                        <!-- Card Header -->
                        <div class="card-header border-0 pt-4 px-4 pb-0 bg-white">
                            <?php if ($table['tournament_name']): ?>
                                <small class="text-uppercase text-muted fw-bold letter-spacing-2" style="font-size: 0.7rem;">
                                    <?= htmlspecialchars($table['tournament_name']) ?>
                                </small>
                            <?php else: ?>
                                <small class="text-uppercase text-muted fw-bold letter-spacing-2" style="font-size: 0.7rem;">
                                    General League
                                </small>
                            <?php endif; ?>
                            <h4 class="mt-2 mb-3 fw-bold text-dark"><?= htmlspecialchars($table['table_name']) ?></h4>
                        </div>

                        <div class="card-body px-4 pt-2">
                            <!-- Team Avatars -->
                            <div class="d-flex align-items-center mb-4">
                                <div class="d-flex ps-2">
                                    <?php
                                    if (!empty($table['team_logos'])) {
                                        $logos = array_slice(explode(',', $table['team_logos']), 0, 5);
                                        foreach ($logos as $index => $logo):
                                            ?>
                                            <div class="team-avatar-wrapper shadow-sm" style="z-index: <?= 5 - $index ?>;">
                                                <img src="<?= $logo ? '../uploads/teams/' . $logo : '../images/default-team.png' ?>"
                                                    class="rounded-circle border border-2 border-white" alt="Team">
                                            </div>
                                        <?php endforeach;
                                    } else { ?>
                                        <span class="text-muted small fst-italic">No teams added yet</span>
                                    <?php } ?>
                                </div>
                                <div class="ms-3">
                                    <span class="badge bg-light text-dark border rounded-pill px-3">
                                        <?= $table['team_count'] ?> Teams
                                    </span>
                                </div>
                            </div>

                            <!-- Point System Grid -->
                            <div class="point-system-grid rounded-3 p-3 mb-4">
                                <div class="row text-center g-0">
                                    <div class="col-3 border-end border-light-subtle">
                                        <div class="px-1">
                                            <div class="h5 mb-0 fw-bold text-success"><?= $table['win_points'] ?? 2 ?></div>
                                            <small class="text-secondary"
                                                style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Win</small>
                                        </div>
                                    </div>
                                    <div class="col-3 border-end border-light-subtle">
                                        <div class="px-1">
                                            <div class="h5 mb-0 fw-bold text-warning"><?= $table['draw_points'] ?? 1 ?></div>
                                            <small class="text-secondary"
                                                style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Draw</small>
                                        </div>
                                    </div>
                                    <div class="col-3 border-end border-light-subtle">
                                        <div class="px-1">
                                            <div class="h5 mb-0 fw-bold text-danger"><?= $table['loss_points'] ?? 0 ?></div>
                                            <small class="text-secondary"
                                                style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Loss</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="px-1">
                                            <div class="h5 mb-0 fw-bold text-info"><?= $table['nr_points'] ?? 1 ?></div>
                                            <small class="text-secondary"
                                                style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">N/R</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer Info -->
                            <div class="d-flex justify-content-between align-items-center text-muted small">
                                <span><i class="fas fa-baseball-ball me-1"></i> <?= $table['match_count'] ?? 0 ?> Matches</span>
                                <span><i class="fas fa-clock me-1"></i>
                                    <?= date('d M', strtotime($table['updated_at'])) ?></span>
                            </div>
                        </div>

                        <div class="card-footer bg-light border-top-0 p-3">
                            <div class="row g-2">
                                <div class="col">
                                    <a href="../view/view_point_table.php?id=<?= $table['id'] ?>"
                                        class="btn btn-primary w-100 rounded-pill shadow-sm fw-bold">
                                        View Table
                                    </a>
                                </div>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                    <div class="col-auto">
                                        <div class="dropdown">
                                            <button class="btn btn-white border shadow-sm rounded-circle" type="button"
                                                data-bs-toggle="dropdown" aria-expanded="false"
                                                style="width: 38px; height: 38px; padding: 0;">
                                                <i class="fas fa-ellipsis-v text-muted"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                                <li><a class="dropdown-item py-2"
                                                        href="../edit/edit_point_table.php?id=<?= $table['id'] ?>"><i
                                                            class="fas fa-edit me-2 text-warning"></i>Edit</a></li>
                                                <li>
                                                    <hr class="dropdown-divider">
                                                </li>
                                                <li>
                                                    <a class="dropdown-item py-2 text-danger delete-table-btn" href="#"
                                                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                                        data-table-id="<?= $table['id'] ?>"
                                                        data-table-name="<?= htmlspecialchars($table['table_name']) ?>">
                                                        <i class="fas fa-trash me-2"></i>Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>


<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-body p-4 text-center">
                <div class="icon-circle bg-danger-light text-danger mx-auto mb-3"
                    style="width: 60px; height: 60px; font-size: 1.5rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background:#fee2e2;">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h5 class="fw-bold mb-3">Delete Point Table?</h5>
                <p class="text-muted mb-4">
                    Are you sure you want to delete "<strong id="deleteTableName" class="text-dark"></strong>"?<br>
                    This will remove the table and all its data permanently.
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light rounded-pill px-4"
                        data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger rounded-pill px-4">Delete Permanently</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Handle delete confirmation modal
    document.querySelectorAll('.delete-table-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault(); // Prevent default link behavior
            const tableId = this.getAttribute('data-table-id');
            const tableName = this.getAttribute('data-table-name');

            document.getElementById('deleteTableName').textContent = tableName;
            document.getElementById('confirmDeleteBtn').href = `point_tables.php?delete=${tableId}`;
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>