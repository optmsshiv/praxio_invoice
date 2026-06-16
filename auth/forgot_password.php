<?php
/**
 * Forgot Password Page
 * Send reset link to email
 */

require_once '../config/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    // TODO: Implement forgot password logic
    // $message = 'Reset link sent to your email';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - Invoice App</title>
</head>
<body>
    <!-- Forgot password form -->
</body>
</html>
