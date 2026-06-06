<?php
// test_onesignal.php
require_once 'includes/db.php';
require_once 'includes/onesignal_utils.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>OneSignal Rich Media Test</h1>";

// 1. Find ALL valid player IDs to test with
try {
    $stmt = $pdo->query("SELECT DISTINCT onesignal_player_id FROM user_devices WHERE onesignal_player_id IS NOT NULL");
    $player_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($player_ids)) {
        die("<p style='color:red'>Error: No registered OneSignal Player IDs found in 'user_devices' table. Register your app first!</p>");
    }

    $count = count($player_ids);
    echo "<p>Testing with <b>$count</b> registered device(s).</p>";

    // 2. Prepare Payload with a PUBLIC image (Google)
    $title = "Global Rich Media Test " . date('H:i:s');
    $message = "If you can see the Google logo, it means OneSignal and your App are working correctly across all your devices!";
    $test_image = "https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png";
    
    $additional_data = [
        'type' => 'mega_test',
        'big_picture' => $test_image,
        'image' => $test_image,
        'large_icon' => $test_image
    ];

    echo "<p>Sending payload with image: <a href='$test_image' target='_blank'>$test_image</a></p>";

    // 3. Send Notification to ALL
    $response = sendOneSignalNotification($player_ids, $title, $message, $additional_data);

    echo "<h3>API Response:</h3>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";

    echo "<h3>Check Log:</h3>";
    if (file_exists('onesignal_payload.log')) {
        echo "<p>Log file found. Last few lines:</p>";
        echo "<pre style='background:#eee; padding:10px;'>" . htmlspecialchars(substr(file_get_contents('onesignal_payload.log'), -500)) . "</pre>";
    } else {
        echo "<p style='color:red'>Log file 'onesignal_payload.log' was NOT created. Check if the folder is writable.</p>";
    }

    echo "<h2>Instructions:</h2>";
    echo "<p>1. Check your phone. If you see the Google logo, then the problem with user images is likely <b>Public Accessibility</b> (OneSignal cannot reach your local server).</p>";
    echo "<p>2. If you see NO image even with Google logo, then the problem is in the <b>Android Native App</b> (NotificationServiceExtension).</p>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
