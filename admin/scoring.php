<?php
require_once '../includes/db.php';
$page_title = "Scoring";
require_once '../includes/header.php';

$match_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<div class="container py-5">
    <div class="card shadow-sm border-0 rounded-4 p-5 text-center">
        <i class="fas fa-tools fa-4x text-muted mb-4"></i>
        <h2 class="fw-bold">Scoring Interface Under Development</h2>
        <p class="text-muted">The scoring interface for Match ID: <?= $match_id ?> is being implemented.</p>
        <div class="mt-4">
            <a href="../NavBarList/matches.php" class="btn btn-primary rounded-pill px-4">Back to Matches</a>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
