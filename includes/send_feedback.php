<?php
// includes/send_feedback.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; // Adjust path if necessary

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $name = isset($_POST['name']) ? strip_tags(trim($_POST['name'])) : '';
    $message = htmlspecialchars($_POST['message']);

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
        exit;
    }

    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => 'Name cannot be empty']);
        exit;
    }

    if (empty($message)) {
        echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty']);
        exit;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        // Use environment variables or a config file for credentials in production
        $mail->Username = 'caiofficial03@gmail.com'; // Sender email (from instructions)
        $mail->Password = 'fievznjmgpxksowc'; // Replace with actual app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom($email, 'CPT League Player Feedback'); // Send from user's email
        $mail->addAddress('mpvignesh2107@gmail.com');    // Send to vicky@gmail.com

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'CPT League Player Suggestion / Problem';

        $bodyContent = "I am {$name}<br><br>";
        $bodyContent .= "Email: {$email}<br><br>";
        $bodyContent .= "Message:<br>" . nl2br($message);

        $mail->Body = $bodyContent;
        $mail->AltBody = "I am {$name}\n\nEmail: {$email}\n\nMessage:\n{$message}";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'Message sent successfully']);
    } catch (Exception $e) {
        // Log the error internally, don't expose sensitive info to user
        error_log("Mailer Error: {$mail->ErrorInfo}");
        echo json_encode(['status' => 'error', 'message' => 'Message could not be sent. Please try again later.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>