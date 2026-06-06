<?php
require_once '../includes/db.php';
require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    header("Location: ../index.php");
    exit();
}

// Get user data
$user_id = $_SESSION['user_id'];
$user = null;
$errors = [];
$success = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: ../login/login.php");
        exit();
    }
} catch (PDOException $e) {
    $errors[] = "Database error occurred.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log that POST was received
    error_log("POST received in player_profile.php");
    error_log("POST data: " . json_encode($_POST));

    try {
        $update_data = [];

        // Cricket Profile Updates
        if (isset($_POST['playing_role']) && !empty($_POST['playing_role'])) {
            $update_data['playing_role'] = $_POST['playing_role'];
        }

        if (isset($_POST['batting_hand']) && !empty($_POST['batting_hand'])) {
            $update_data['batting_hand'] = $_POST['batting_hand'];
        }

        if (isset($_POST['batting_order']) && !empty($_POST['batting_order'])) {
            $update_data['batting_order'] = $_POST['batting_order'];
        }

        if (isset($_POST['bowling_type']) && !empty($_POST['bowling_type'])) {
            $update_data['bowling_type'] = $_POST['bowling_type'];
        }

        if (isset($_POST['bowling_arm']) && !empty($_POST['bowling_arm'])) {
            $update_data['bowling_arm'] = $_POST['bowling_arm'];
        }

        // Personal Details Updates
        if (isset($_POST['full_name'])) {
            $update_data['full_name'] = trim($_POST['full_name']);
        }

        if (isset($_POST['email'])) {
            $update_data['email'] = trim($_POST['email']);
        }

        if (isset($_POST['phone']) && !empty($_POST['phone'])) {
            $update_data['phone'] = trim($_POST['phone']);
        }

        if (isset($_POST['dob']) && !empty($_POST['dob'])) {
            $update_data['dob'] = $_POST['dob'];
        }

        if (isset($_POST['address'])) {
            $update_data['address'] = trim($_POST['address']);
        }

        if (isset($_POST['city']) && !empty($_POST['city'])) {
            $update_data['city'] = trim($_POST['city']);
        }

        if (isset($_POST['bio'])) {
            $update_data['bio'] = trim($_POST['bio']);
        }

        // Handle profile image upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_image']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $newname = uniqid() . '.' . $ext;
                $destination = '../uploads/users/' . $newname;

                if (!is_dir('../uploads/users/')) {
                    mkdir('../uploads/users/', 0755, true);
                }

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                    // Delete old image if exists
                    if ($user['profile_image'] && file_exists('../uploads/users/' . $user['profile_image'])) {
                        unlink('../uploads/users/' . $user['profile_image']);
                    }
                    $update_data['profile_image'] = $newname;
                    $_SESSION['profile_image'] = $newname;
                } else {
                    $errors[] = "Failed to upload profile image.";
                }
            } else {
                $errors[] = "Invalid image format. Only JPG, PNG, and GIF are allowed.";
            }
        }

        // Update database if no errors
        if (empty($errors) && !empty($update_data)) {
            error_log("Update data: " . json_encode($update_data));

            $set_parts = [];
            $params = [];

            foreach ($update_data as $column => $value) {
                $set_parts[] = "$column = ?";
                $params[] = $value;
            }

            $params[] = $user_id;

            $query = "UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = ?";
            error_log("SQL Query: " . $query);
            error_log("Params: " . json_encode($params));

            $stmt = $pdo->prepare($query);
            $result = $stmt->execute($params);

            error_log("Update result: " . ($result ? "success" : "failed"));

            $success = "Profile updated successfully!";

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $errors[] = "Database error occurred while updating profile: " . $e->getMessage();
    }
}

// Calculate age from DOB
$age = null;
if ($user['dob']) {
    $birthDate = new DateTime($user['dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

$page_title = "My Profile";
require_once '../includes/header.php';
?>

<div class="container-fluid py-5 player-profile-page">
    <!-- Custom CSS and Google Fonts -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

        :root {
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.2);
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --body-bg: #f8fafc;
        }

        .player-profile-page {
            font-family: 'Outfit', sans-serif;
            background-color: var(--body-bg);
            min-height: 100vh;
        }

        /* Hero Section */
        .profile-hero {
            background: var(--primary-gradient);
            height: 240px;
            border-radius: 24px;
            position: relative;
            margin-bottom: -80px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.2);
            animation: heroReveal 1.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('https://www.transparenttextures.com/patterns/carbon-fibre.png');
            opacity: 0.1;
        }

        .hero-pattern {
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            filter: blur(80px);
        }

        /* Glass Cards */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 3px solid var(--glass-border);
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
            animation: cardSlideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
        }

        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border-color: rgba(79, 70, 229, 0.3);
        }

        /* Profile Image */
        .profile-pic-wrapper {
            position: relative;
            z-index: 10;
            margin-top: -80px;
            animation: profileZoom 1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .profile-pic-container {
            width: 180px;
            height: 180px;
            margin: 0 auto;
            border-radius: 50%;
            padding: 8px;
            background: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .profile-pic {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #f1f5f9;
        }

        /* Buttons and Badges */
        .btn-modern {
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-edit {
            background: white;
            color: #4f46e5;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-edit:hover {
            background: #f8fafc;
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .badge-modern {
            padding: 6px 16px;
            border-radius: 50px;
            background: var(--primary-gradient);
            color: white;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
        }

        /* Icons and Labels */
        .detail-item {
            padding: 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.5);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .detail-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f46e5;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        .detail-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 1rem;
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 0;
        }

        /* Animations */
        @keyframes heroReveal {
            from {
                height: 0;
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                height: 240px;
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes cardSlideUp {
            from {
                transform: translateY(40px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes profileZoom {
            from {
                transform: scale(0.5);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .delay-1 {
            animation-delay: 0.2s;
        }

        .delay-2 {
            animation-delay: 0.4s;
        }

        .delay-3 {
            animation-delay: 0.6s;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <!-- Hero Header -->
            <div class="profile-hero">
                <div class="hero-pattern"></div>
                <div class="p-4 d-flex justify-content-between align-items-start position-relative">
                    <div>
                        <h1 class="text-white fw-bold mb-0">Player Profile</h1>
                        <p class="text-white opacity-75">Welcome back,
                            <?= htmlspecialchars($user['full_name'] ?: ($user['email'] ?? 'Player')) ?>!
                        </p>
                    </div>
                    <button type="button" class="btn btn-modern btn-edit" id="editBtn">
                        <i class="fas fa-pen-nib"></i>Edit Profile
                    </button>
                </div>
            </div>

            <!-- Profile Overview -->
            <div class="profile-pic-wrapper text-center">
                <div class="profile-pic-container">
                    <img src="<?= $user['profile_image'] ? '../uploads/users/' . $user['profile_image'] . '?t=' . time() : '../images/default-player.png' ?>"
                        alt="Profile" class="profile-pic">
                </div>
                <h2 class="mt-3 fw-bold text-dark"><?= htmlspecialchars($user['full_name'] ?: 'Associate Player') ?>
                </h2>
                <div class="d-flex justify-content-center gap-2 mt-2">
                    <span class="badge-modern"><i class="fas fa-medal me-1"></i>Active Player</span>
                    <?php if ($user['playing_role']): ?>
                        <span class="badge-modern bg-info"><i
                                class="fas fa-briefcase me-1"></i><?= htmlspecialchars($user['playing_role']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content Section -->
            <div class="row mt-5">
                <!-- Cricket Profile Section -->
                <div class="col-lg-6 mb-4">
                    <div class="glass-card shadow-sm h-100 delay-1">
                        <div class="card-header border-0 bg-transparent pt-4 px-4">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-baseball-ball me-2 text-primary"></i>Cricket
                                Arsenal</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-user-tag"></i></div>
                                        <div>
                                            <p class="detail-label">Playing Role</p>
                                            <p class="detail-value"><?= $user['playing_role'] ?: 'Not defined' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-hand-fist"></i></div>
                                        <div>
                                            <p class="detail-label">Batting Hand</p>
                                            <p class="detail-value">
                                                <?= $user['batting_hand'] ? $user['batting_hand'] . '-handed' : 'Not set' ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-2">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-sort-numeric-up"></i></div>
                                        <div>
                                            <p class="detail-label">Batting Order</p>
                                            <p class="detail-value"><?= $user['batting_order'] ?: 'Standard' ?></p>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($user['bowling_type']): ?>
                                    <div class="col-sm-6 mt-2">
                                        <div class="detail-item">
                                            <div class="detail-icon"><i class="fas fa-wind"></i></div>
                                            <div>
                                                <p class="detail-label">Bowling Type</p>
                                                <p class="detail-value"><?= htmlspecialchars($user['bowling_type']) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 mt-2">
                                        <div class="detail-item">
                                            <div class="detail-icon"><i class="fas fa-arrows-left-right"></i></div>
                                            <div>
                                                <p class="detail-label">Bowling Arm</p>
                                                <p class="detail-value"><?= htmlspecialchars($user['bowling_arm']) ?>-arm
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!$user['playing_role'] || !$user['batting_hand']): ?>
                                <div class="alert alert-soft-warning mt-3 mb-0"
                                    style="background-color: #fffbeb; border: 1px dashed #f59e0b; border-radius: 12px; color: #b45309;">
                                    <i class="fas fa-bolt me-2"></i>Improve your profile by filling in cricket details.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Personal Details Section -->
                <div class="col-lg-6 mb-4">
                    <div class="glass-card shadow-sm h-100 delay-2">
                        <div class="card-header border-0 bg-transparent pt-4 px-4">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-id-card me-2 text-success"></i>Identity & Bio</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-envelope"></i></div>
                                        <div style="overflow: hidden;">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value text-truncate">
                                                <?= htmlspecialchars($user['email']) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-phone-alt"></i></div>
                                        <div>
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value"><?= $user['phone'] ?: 'N/A' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-cake-candles"></i></div>
                                        <div>
                                            <p class="detail-label">Age</p>
                                            <p class="detail-value"><?= $age ? $age . ' years' : 'N/A' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-item">
                                        <div class="detail-icon"><i class="fas fa-location-dot"></i></div>
                                        <div>
                                            <p class="detail-label">Location</p>
                                            <p class="detail-value"><?= $user['city'] ?: 'Earth' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-item mt-2">
                                <div class="detail-icon"><i class="fas fa-quote-left"></i></div>
                                <div class="w-100">
                                    <p class="detail-label">Professional Bio</p>
                                    <p class="detail-value mt-1"
                                        style="font-weight: 500; font-style: italic; color: #475569;">
                                        <?= $user['bio'] ? nl2br(htmlspecialchars($user['bio'])) : 'No bio added yet...' ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="profileForm" action="">
                    <div class="modal-body">
                        <!-- Tab Navigation -->
                        <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="cricket-tab" data-bs-toggle="tab"
                                    data-bs-target="#cricket" type="button" role="tab">Cricket Profile</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="personal-tab" data-bs-toggle="tab"
                                    data-bs-target="#personal" type="button" role="tab">Personal Details</button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content mt-3" id="profileTabsContent">
                            <!-- Cricket Profile Tab -->
                            <div class="tab-pane fade show active" id="cricket" role="tabpanel">
                                <div class="text-center mb-3">
                                    <div class="profile-image-container"
                                        onclick="document.getElementById('profile_image').click()"
                                        style="cursor: pointer;">
                                        <img id="currentImage"
                                            src="<?= $user['profile_image'] ? '../uploads/users/' . $user['profile_image'] . '?t=' . time() : '../images/default-player.png' ?>"
                                            alt="Current Profile" class="img-fluid responsive-profile-img"
                                            style="max-width: 200px; height: auto; border: 2px solid #007bff;">
                                    </div>
                                    <div class="mt-2">
                                        <label for="profile_image" class="form-label text-muted small">Click to change
                                            profile picture</label>
                                        <input type="file" class="form-control d-none" id="profile_image"
                                            name="profile_image" accept="image/*">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="playing_role" class="form-label">Player Role *</label>
                                        <select class="form-select" id="playing_role" name="playing_role">
                                            <option value="">Select Role</option>
                                            <option value="Batsman" <?= $user['playing_role'] === 'Batsman' ? 'selected' : '' ?>>Batsman</option>
                                            <option value="Bowler" <?= $user['playing_role'] === 'Bowler' ? 'selected' : '' ?>>Bowler</option>
                                            <option value="All-rounder" <?= $user['playing_role'] === 'All-rounder' ? 'selected' : '' ?>>All-rounder</option>
                                            <option value="Wicket-keeper" <?= $user['playing_role'] === 'Wicket-keeper' ? 'selected' : '' ?>>Wicket-keeper</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="batting_hand" class="form-label">Batting Hand *</label>
                                        <select class="form-select" id="batting_hand" name="batting_hand">
                                            <option value="">Select Hand</option>
                                            <option value="Right" <?= $user['batting_hand'] === 'Right' ? 'selected' : '' ?>>Right-handed</option>
                                            <option value="Left" <?= $user['batting_hand'] === 'Left' ? 'selected' : '' ?>>
                                                Left-handed</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Dynamic fields based on role -->
                                <div id="battingFields" style="display: none;">
                                    <div class="mb-3">
                                        <label for="batting_order" class="form-label">Batting Order</label>
                                        <select class="form-select" id="batting_order" name="batting_order">
                                            <option value="">Select Order</option>
                                            <option value="Opener" <?= $user['batting_order'] === 'Opener' ? 'selected' : '' ?>>Opener</option>
                                            <option value="Middle Order" <?= $user['batting_order'] === 'Middle Order' ? 'selected' : '' ?>>Middle Order</option>
                                            <option value="Finisher" <?= $user['batting_order'] === 'Finisher' ? 'selected' : '' ?>>Finisher</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="bowlingFields" style="display: none;">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="bowling_type" class="form-label">Bowling Type</label>
                                            <select class="form-select" id="bowling_type" name="bowling_type">
                                                <option value="">Select Type</option>
                                                <option value="Fast" <?= $user['bowling_type'] === 'Fast' ? 'selected' : '' ?>>Fast</option>
                                                <option value="Medium" <?= $user['bowling_type'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                                <option value="Spin" <?= $user['bowling_type'] === 'Spin' ? 'selected' : '' ?>>Spin</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="bowling_arm" class="form-label">Bowling Arm</label>
                                            <select class="form-select" id="bowling_arm" name="bowling_arm">
                                                <option value="">Select Arm</option>
                                                <option value="Right" <?= $user['bowling_arm'] === 'Right' ? 'selected' : '' ?>>Right-arm</option>
                                                <option value="Left" <?= $user['bowling_arm'] === 'Left' ? 'selected' : '' ?>>Left-arm</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Personal Details Tab -->
                            <div class="tab-pane fade" id="personal" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name"
                                            value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Mail ID *</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Mobile Number *</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="dob" class="form-label">Date of Birth *</label>
                                        <input type="date" class="form-control" id="dob" name="dob"
                                            value="<?= $user['dob'] ?? '' ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">City *</label>
                                    <input type="text" class="form-control" id="city" name="city"
                                        value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="address" class="form-label">Address *</label>
                                    <textarea class="form-control" id="address" name="address"
                                        rows="3"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="bio" class="form-label">Bio / About Player</label>
                                    <textarea class="form-control" id="bio" name="bio" rows="4"
                                        placeholder="Tell us about yourself..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Crop Modal Structure -->
    <div class="modal fade" id="cropModal" tabindex="-1" aria-labelledby="cropModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cropModalLabel">Perfect Your Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="cropperContainer" style="max-height: 400px; overflow: hidden; border-radius: 12px;">
                        <img id="imageToCrop" src="" style="max-width: 100%;">
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" id="cropBtn">Apply Changes</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Glass Modal Styles */
    .modal-content {
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(16px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: var(--primary-gradient);
        border-radius: 24px 24px 0 0;
        padding: 1.5rem;
        color: white;
    }

    .modal-title {
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .nav-tabs .nav-link {
        border: none;
        border-bottom: 2px solid transparent;
        padding: 12px 24px;
        font-weight: 600;
        color: #64748b !important;
        transition: all 0.3s;
    }

    .nav-tabs .nav-link.active {
        border-bottom: 2px solid #4f46e5;
        background: transparent !important;
        color: #4f46e5 !important;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        padding: 10px 16px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        transition: all 0.3s;
    }

    .form-control:focus,
    .form-select:focus {
        background: white;
        border-color: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
    }

    .responsive-profile-img {
        transition: transform 0.3s ease;
        border-radius: 12px;
        max-width: 100%;
        height: auto;
    }

    .responsive-profile-img:hover {
        transform: scale(1.02);
    }

    #currentImage {
        cursor: pointer;
        transition: opacity 0.3s ease;
    }

    #currentImage:hover {
        opacity: 0.8;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Initialize dynamic fields based on player role
        const roleSelect = document.getElementById('playing_role');
        if (roleSelect) {
            roleSelect.addEventListener('change', function () {
                const role = this.value;
                const battingFields = document.getElementById('battingFields');
                const bowlingFields = document.getElementById('bowlingFields');

                if (battingFields) {
                    battingFields.style.display = (role === 'Batsman' || role === 'All-rounder') ? 'block' : 'none';
                }
                if (bowlingFields) {
                    bowlingFields.style.display = (role === 'Bowler' || role === 'All-rounder') ? 'block' : 'none';
                }
            });

            // Trigger change on load if value exists
            if (roleSelect.value) {
                roleSelect.dispatchEvent(new Event('change'));
            }
        }

        // 2. Auto-hide alerts
        setTimeout(function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // 3. Profile Image Cropping Logic
        const imageInput = document.getElementById('profile_image');
        const currentImage = document.getElementById('currentImage');
        const imageToCrop = document.getElementById('imageToCrop');
        const cropBtn = document.getElementById('cropBtn');
        const cropModalElement = document.getElementById('cropModal');
        let cropper;
        let cropModal;

        // Initialize Modal only if element exists
        if (cropModalElement) {
            cropModal = new bootstrap.Modal(cropModalElement);

            // Open file selector when clicking current image (Delegated to handle potential DOM issues)
            // Note: We also have an inline onclick as a backup
            const profileImageContainer = document.querySelector('.profile-image-container');
            if (profileImageContainer) {
                profileImageContainer.addEventListener('click', function (e) {
                    // The inline onclick handles the click, but we can keep this for completeness or remove if redundant
                    // Keeping it doesn't hurt, but inline is primary now.
                });
            }

            if (imageInput) {
                imageInput.addEventListener('change', function (e) {
                    const files = e.target.files;
                    if (files && files.length > 0) {
                        const file = files[0];

                        // Prevent recursive loop
                        if (file.cropped) return;

                        const reader = new FileReader();
                        reader.onload = function (event) {
                            if (imageToCrop) {
                                imageToCrop.src = event.target.result;
                                cropModal.show();
                            }
                            // Wait for modal to be somewhat visible or just destroy prev immediately
                            if (cropper) cropper.destroy();
                        };
                        reader.readAsDataURL(file);

                        // Clear input so same file can be selected again if cancelled
                        imageInput.value = '';
                    }
                });
            }

            // Initialize cropper when modal is shown
            cropModalElement.addEventListener('shown.bs.modal', function () {
                if (imageToCrop) {
                    cropper = new Cropper(imageToCrop, {
                        aspectRatio: 1,
                        viewMode: 1,
                        autoCropArea: 1,
                    });
                }
            });

            cropModalElement.addEventListener('hidden.bs.modal', function () {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });

            if (cropBtn) {
                cropBtn.addEventListener('click', function () {
                    if (!cropper) return;

                    // Get Cropped Canvas
                    const canvas = cropper.getCroppedCanvas({
                        width: 500,
                        height: 500,
                    });

                    // Convert to Blob
                    canvas.toBlob(function (blob) {
                        const croppedFile = new File([blob], "profile_cropped.png", { type: "image/png" });
                        croppedFile.cropped = true; // Flag to prevent loop

                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(croppedFile);
                        if (imageInput) {
                            imageInput.files = dataTransfer.files;
                        }

                        // Show Preview
                        if (currentImage) {
                            currentImage.src = URL.createObjectURL(blob);
                        }

                        cropModal.hide();
                    }, 'image/png');
                });
            }
        } else {
            console.warn('Crop modal element not found');
        }

        // 4. Edit button functionality
        const editBtn = document.getElementById('editBtn');
        if (editBtn) {
            editBtn.addEventListener('click', function () {
                const editModalEl = document.getElementById('editModal');
                if (editModalEl) {
                    const modal = new bootstrap.Modal(editModalEl);
                    modal.show();
                }
            });
        }

        // 5. Enhanced Save Button Feedback
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function (e) {
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Saving...`;
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>