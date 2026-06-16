<?php
/**
 * Public Self-Registration Page
 * Phase 3: Allow new tenants to register
 */

require_once 'config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = $_POST['company_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // TODO: Implement tenant registration logic
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - Invoice App</title>
</head>
<body>
    <!-- Registration form -->
</body>
</html>
