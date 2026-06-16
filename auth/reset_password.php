<?php
/**
 * Reset Password Page
 * Phase 3: Password reset via token
 */

require_once '../config/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // TODO: Implement password reset logic
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - Invoice App</title>
</head>
<body>
    <!-- Reset password form -->
</body>
</html>
