<?php
require_once '../includes/db.php';
require_login();

// 1. Error Handling & Security (Prevent leaking paths/warnings in UI)
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}



use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

// Configure Cloudinary
Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? "",
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'] ?? "",
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? "",
    ],
    'url' => [
        'secure' => true
    ]
]);

function uploadToCloudinary($filePath) {
    if (!$filePath || !file_exists($filePath)) return null;
    try {
        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($filePath, [
            'folder' => 'tournaments',
            'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? ""
        ]);
        if (!empty($result['secure_url'])) {
            return ['url' => $result['secure_url'], 'public_id' => $result['public_id']];
        }
    } catch (Exception $e) {
        error_log("❌ Cloudinary Edit Tournament Upload Error: " . $e->getMessage());
    }
    return null;
}

function deleteFromCloudinary($public_id) {
    if (!$public_id) return;
    try {
        $uploadApi = new UploadApi();
        $uploadApi->destroy($public_id);
    } catch (Exception $e) {
        error_log("❌ Cloudinary Edit Tournament Delete Error: " . $e->getMessage());
    }
}

// 2. Get tournament ID from query parameter
$tournament_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$tournament_id) {
    header("Location: ../NavBarList/tournament_list.php");
    exit();
}

// 3. Fetch tournament details
$tournament = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        header("Location: ../NavBarList/tournament_list.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: ../NavBarList/tournament_list.php");
    exit();
}

// 4. Assign Safe Variables (Defensive Data Handling)
// Use null coalescing to provide defaults and avoid "Undefined array key" warnings
$name = isset($tournament['tournament_name']) ? $tournament['tournament_name'] : '';
$venue = isset($tournament['venue']) ? $tournament['venue'] : '';
$start_date = isset($tournament['start_date']) ? $tournament['start_date'] : '';
$end_date = isset($tournament['end_date']) ? $tournament['end_date'] : '';
$overs = isset($tournament['overs']) ? (int)$tournament['overs'] : 20;
$format = isset($tournament['format']) ? $tournament['format'] : 'T20';
$ground_type = isset($tournament['ground_type']) ? $tournament['ground_type'] : 'Grass';
$prize = isset($tournament['prize_amount']) ? $tournament['prize_amount'] : null;
$fee = isset($tournament['entry_fee']) ? $tournament['entry_fee'] : null;
$logo = isset($tournament['tournament_logo']) ? $tournament['tournament_logo'] : null;
$banner = isset($tournament['tournament_banner']) ? $tournament['tournament_banner'] : null;
$max_teams = isset($tournament['max_teams']) ? (int)$tournament['max_teams'] : 8;
$description = isset($tournament['description']) ? $tournament['description'] : '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['tournament_name', 'venue', 'start_date', 'end_date', 'overs', 'format'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        // Validate dates
        $start_date = strtotime($_POST['start_date']);
        $end_date = strtotime($_POST['end_date']);

        if ($start_date === false || $end_date === false) {
            throw new Exception("Invalid date format.");
        }

        if ($start_date > $end_date) {
            throw new Exception("End date must be after start date.");
        }

        // Handle tournament logo upload
        $logo_path = $tournament['tournament_logo'];
        $logo_public_id = $tournament['tournament_logo_public_id'];
        
        if (isset($_FILES['tournament_logo']) && $_FILES['tournament_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/jfif', 'image/bmp', 'image/x-ms-bmp'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($_FILES['tournament_logo']['type'], $allowed_types)) {
                throw new Exception("Invalid logo file type.");
            }

            $upload_dir = '../uploads/tournaments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $file_extension = pathinfo($_FILES['tournament_logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'logo_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $logo_dest = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['tournament_logo']['tmp_name'], $logo_dest)) {
                // Delete old from Cloudinary
                if (!empty($tournament['tournament_logo_public_id'])) {
                    deleteFromCloudinary($tournament['tournament_logo_public_id']);
                }
                
                // Upload new to Cloudinary
                $logo_res = uploadToCloudinary($logo_dest);
                if ($logo_res) {
                    $logo_public_id = $logo_res['public_id'];
                }

                $logo_path = 'uploads/tournaments/' . $file_name;
                // Delete old local if exists
                if ($tournament['tournament_logo'] && file_exists('../' . $tournament['tournament_logo'])) {
                    unlink('../' . $tournament['tournament_logo']);
                }
            } else {
                throw new Exception("Failed to upload tournament logo.");
            }
        }

        // Handle tournament banner upload
        $banner_path = $tournament['tournament_banner'];
        $banner_public_id = $tournament['tournament_banner_public_id'];

        if (isset($_FILES['tournament_banner']) && $_FILES['tournament_banner']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/jfif', 'image/bmp', 'image/x-ms-bmp'];
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($_FILES['tournament_banner']['type'], $allowed_types)) {
                throw new Exception("Invalid banner file type.");
            }

            $upload_dir = '../uploads/tournaments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $file_extension = pathinfo($_FILES['tournament_banner']['name'], PATHINFO_EXTENSION);
            $file_name = 'banner_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $banner_dest = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['tournament_banner']['tmp_name'], $banner_dest)) {
                // Delete old from Cloudinary
                if (!empty($tournament['tournament_banner_public_id'])) {
                    deleteFromCloudinary($tournament['tournament_banner_public_id']);
                }
                
                // Upload new to Cloudinary
                $banner_res = uploadToCloudinary($banner_dest);
                if ($banner_res) {
                    $banner_public_id = $banner_res['public_id'];
                }

                $banner_path = 'uploads/tournaments/' . $file_name;
                // Delete old local if exists
                if ($tournament['tournament_banner'] && file_exists('../' . $tournament['tournament_banner'])) {
                    unlink('../' . $tournament['tournament_banner']);
                }
            } else {
                throw new Exception("Failed to upload tournament banner.");
            }
        }

        // Update tournament
        $stmt = $pdo->prepare("
            UPDATE tournaments SET
                tournament_name = ?, description = ?, start_date = ?, end_date = ?, 
                venue = ?, overs = ?, format = ?, ground_type = ?, 
                prize_amount = ?, entry_fee = ?, tournament_logo = ?, 
                tournament_logo_public_id = ?, tournament_banner = ?, 
                tournament_banner_public_id = ?, max_teams = ?
            WHERE id = ?
        ");

        $stmt->execute([
            trim($_POST['tournament_name']),
            trim($_POST['description'] ?? ''),
            $_POST['start_date'],
            $_POST['end_date'],
            trim($_POST['venue']),
            (int) $_POST['overs'],
            $_POST['format'],
            $_POST['ground_type'] ?? 'Grass',
            $_POST['prize_amount'] ? (float) $_POST['prize_amount'] : null,
            $_POST['entry_fee'] ? (float) $_POST['entry_fee'] : null,
            $logo_path,
            $logo_public_id,
            $banner_path,
            $banner_public_id,
            (int) ($_POST['max_teams'] ?? 8),
            $tournament_id
        ]);

        // Redirect to tournament list with success message
        header("Location: ../NavBarList/tournament_list.php?success=2");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = "Edit Tournament - " . htmlspecialchars($name);
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
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .input-group-text {
        background-color: #f8fafc;
        border: 2px solid #e5e7eb;
        border-right: none;
        color: #64748b;
        transition: all 0.3s ease;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        border: 2px solid #e5e7eb;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        background-color: #fff;
    }

    .input-group .form-control,
    .input-group .form-select {
        border-top-right-radius: 12px;
        border-bottom-right-radius: 12px;
        border-left: none;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #6366f1;
        box-shadow: none;
    }

    .input-group:focus-within .input-group-text {
        border-color: #6366f1;
        color: #6366f1;
    }

    .form-control:focus::placeholder {
        color: #a5b4fc;
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

    /* Upload Box Style */
    .upload-box {
        border: 2px dashed #cbd5e1;
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        transition: all 0.3s ease;
        background: #f8fafc;
        cursor: pointer;
        position: relative;
    }

    .upload-box:hover {
        border-color: #6366f1;
        background: #eef2ff;
    }

    .upload-box input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
</style>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            <div class="glass-card">
                <!-- Header -->
                <div class="card-header-custom">
                    <h4 class="card-header-title">
                        <i class="fas fa-edit"></i>
                        Edit Tournament
                    </h4>
                </div>

                <div class="card-body p-4 p-md-5">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-3 border-0 shadow-sm d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form id="editTournamentForm" method="POST" action="" enctype="multipart/form-data"
                        class="needs-validation" novalidate>

                        <!-- Basic Info Section -->
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <label class="form-label">Tournament Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-signature"></i>
                                    </span>
                                    <input type="text" class="form-control" name="tournament_name"
                                        value="<?= htmlspecialchars($name) ?>" required>
                                </div>
                                <div class="invalid-feedback">Please provide a tournament name.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Venue</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </span>
                                    <input type="text" class="form-control" name="venue"
                                        value="<?= htmlspecialchars($venue) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Schedule Section -->
                        <div class="section-title">
                            <i class="fas fa-calendar-alt"></i> Schedule
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-hourglass-start"></i>
                                    </span>
                                    <input type="date" class="form-control" name="start_date"
                                        value="<?= htmlspecialchars($start_date) ?>" required>
                                </div>
                                <div class="invalid-feedback">Please select a start date.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-hourglass-end"></i>
                                    </span>
                                    <input type="date" class="form-control" name="end_date"
                                        value="<?= htmlspecialchars($end_date) ?>" required>
                                </div>
                                <div class="invalid-feedback">Please select an end date.</div>
                            </div>
                        </div>

                        <!-- Format Configuration -->
                        <div class="section-title">
                            <i class="fas fa-cogs"></i> Configuration
                        </div>

                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label">Format <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-list-ul"></i>
                                    </span>
                                    <select class="form-select" name="format" required>
                                        <option value="T20" <?= $format == 'T20' ? 'selected' : '' ?>>T20
                                        </option>
                                        <option value="ODI" <?= $format == 'ODI' ? 'selected' : '' ?>>ODI
                                        </option>
                                        <option value="Test" <?= $format == 'Test' ? 'selected' : '' ?>>Test
                                        </option>
                                        <option value="T10" <?= $format == 'T10' ? 'selected' : '' ?>>T10
                                        </option>
                                        <option value="The Hundred" <?= $format == 'The Hundred' ? 'selected' : '' ?>>The Hundred</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select the tournament format.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Overs <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-bowling-ball"></i>
                                    </span>
                                    <select class="form-select" name="overs" required>
                                        <option value="6" <?= $overs == 6 ? 'selected' : '' ?>>6 Overs
                                        </option>
                                        <option value="8" <?= $overs == 8 ? 'selected' : '' ?>>8 Overs
                                        </option>
                                        <option value="10" <?= $overs == 10 ? 'selected' : '' ?>>10 Overs
                                        </option>
                                        <option value="20" <?= $overs == 20 ? 'selected' : '' ?>>20 Overs
                                        </option>
                                        <option value="50" <?= $overs == 50 ? 'selected' : '' ?>>50 Overs
                                        </option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select the number of overs.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ground Type</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start-4">
                                        <i class="fas fa-leaf"></i>
                                    </span>
                                    <select class="form-select" name="ground_type">
                                        <option value="Grass" <?= $ground_type == 'Grass' ? 'selected' : '' ?>>Grass</option>
                                        <option value="Turf" <?= $ground_type == 'Turf' ? 'selected' : '' ?>>
                                            Turf</option>
                                        <option value="Artificial" <?= $ground_type == 'Artificial' ? 'selected' : '' ?>>Artificial</option>
                                        <option value="Concrete" <?= $ground_type == 'Concrete' ? 'selected' : '' ?>>Concrete</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Financials & Logo -->
                        <div class="section-title">
                            <i class="fas fa-coins"></i> Details & Assets
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="row g-4">
                                    <div class="col-12">
                                        <label class="form-label">Prize Amount (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-award"></i>
                                            </span>
                                            <input type="number" class="form-control" name="prize_amount"
                                                placeholder="100000" value="<?= htmlspecialchars($prize) ?>">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Entry Fee (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-ticket-alt"></i>
                                            </span>
                                            <input type="number" class="form-control" name="entry_fee" placeholder="500"
                                                min="0" value="<?= htmlspecialchars($fee) ?>">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Max Teams <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text rounded-start-4">
                                                <i class="fas fa-users"></i>
                                            </span>
                                            <input type="number" class="form-control" name="max_teams" min="2" max="16"
                                                value="<?= htmlspecialchars($max_teams) ?>" required>
                                        </div>
                                        <div class="invalid-feedback">Max teams (2-16).</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tournament Logo</label>
                                <div class="upload-box mb-3 d-flex flex-column justify-content-center align-items-center" style="height: 160px;">
                                    <input type="file" name="tournament_logo" id="tournamentLogo" accept="image/jpeg,image/png,image/gif,image/webp,image/jfif,image/bmp">
                                    <div class="text-center <?= $logo ? 'd-none' : '' ?>" id="uploadPlaceholder">
                                        <i class="fas fa-image text-muted fs-2 mb-2"></i>
                                        <h6 class="fw-bold mb-1">Replace Logo</h6>
                                        <small class="text-muted d-block small">JPG, PNG, WEBP, BMP</small>
                                    </div>
                                    <div id="logoPreview" class="<?= $logo ? '' : 'd-none' ?> w-100 h-100 text-center">
                                        <img src="<?= $logo ? '../' . htmlspecialchars($logo) : '' ?>" alt="Preview" class="h-100 rounded-3 shadow-sm object-fit-contain">
                                    </div>
                                </div>

                                <label class="form-label">Tournament Banner</label>
                                <div class="upload-box d-flex flex-column justify-content-center align-items-center" style="height: 160px;">
                                    <input type="file" name="tournament_banner" id="tournamentBanner" accept="image/jpeg,image/png,image/gif,image/webp,image/jfif,image/bmp">
                                    <div class="text-center <?= $banner ? 'd-none' : '' ?>" id="bannerPlaceholder">
                                        <i class="fas fa-panorama text-muted fs-2 mb-2"></i>
                                        <h6 class="fw-bold mb-1">Replace Banner</h6>
                                        <small class="text-muted d-block small">Recommended: 1200x400 (JPG, PNG, WEBP)</small>
                                    </div>
                                    <div id="bannerPreview" class="<?= $banner ? '' : 'd-none' ?> w-100 h-100 text-center">
                                        <img src="<?= $banner ? '../' . htmlspecialchars($banner) : '' ?>" alt="Preview" class="h-100 rounded-3 shadow-sm object-fit-cover w-100">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description"
                                    rows="3"><?= htmlspecialchars($description) ?></textarea>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center pt-5 mt-4 border-top">
                            <a href="../NavBarList/tournament_list.php" class="btn btn-action btn-back">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-action btn-create">
                                <i class="fas fa-save"></i> Update Tournament
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Logo preview logic
    document.getElementById('tournamentLogo').addEventListener('change', function (e) {
        const previewContainer = document.getElementById('logoPreview');
        const uploadPlaceholder = document.getElementById('uploadPlaceholder');
        const previewImg = previewContainer.querySelector('img');

        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
                uploadPlaceholder.classList.add('d-none');
            }
            reader.readAsDataURL(this.files[0]);
        }
    });

    // Banner preview logic
    document.getElementById('tournamentBanner').addEventListener('change', function (e) {
        const previewContainer = document.getElementById('bannerPreview');
        const bannerPlaceholder = document.getElementById('bannerPlaceholder');
        const previewImg = previewContainer.querySelector('img');

        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
                bannerPlaceholder.classList.add('d-none');
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>