<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'includes/db.php';

echo "<h1>OneSignal Debug Status</h1>";

try {
    // Check table existence
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_devices'");
    if (!$stmt->fetch()) {
        echo "<p style='color:red'>Table 'user_devices' DOES NOT EXIST!</p>";
        
        echo "<h2>Attempting to create table now...</h2>";
        $sql = "CREATE TABLE IF NOT EXISTS user_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            onesignal_player_id VARCHAR(255) UNIQUE,
            device_id VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )";
        $pdo->exec($sql);
        echo "<p style='color:green'>Table created successfully (hopefully).</p>";
    } else {
        echo "<p style='color:green'>Table 'user_devices' exists.</p>";
    }

    // Check row count
    $stmt = $pdo->query("SELECT COUNT(*) FROM user_devices");
    $count = $stmt->fetchColumn();
    echo "<p>Total devices registered: <b>$count</b></p>";

    if ($count > 0) {
        echo "<h2>Latest 10 Devices:</h2>";
        echo "<table border='1'><tr><th>ID</th><th>User ID</th><th>Player ID</th><th>Updated</th></tr>";
        $stmt = $pdo->query("SELECT * FROM user_devices ORDER BY updated_at DESC LIMIT 10");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>
                <td>{$row['id']}</td>
                <td>" . ($row['user_id'] ?? 'GUEST') . "</td>
                <td>{$row['onesignal_player_id']}</td>
                <td>{$row['updated_at']}</td>
            </tr>";
        }
        echo "</table>";
    }

    // Show Debug Log
    echo "<h2>Recent Debug Logs (onesignal_debug.log):</h2>";
    $log_file = 'onesignal_debug.log';
    if (file_exists($log_file)) {
        echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc; max-height:400px; overflow:auto;'>";
        echo htmlspecialchars(file_get_contents($log_file));
        echo "</pre>";
    } else {
        echo "<p>No log file found yet.</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "<h2>Instructions</h2>";
echo "<p>1. Open this page in your browser: <code>http://your-domain/CPT_LEAGUE/debug_onesignal.php</code></p>";
echo "<p>2. If 'Total devices' is 0, then the App is NOT sending IDs correctly.</p>";
echo "<p>3. If IDs exist but no notifications are received, check <code>onesignal_debug.log</code> in the root folder.</p>";
?>
