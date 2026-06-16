<?php
/**
 * Super Admin Panel
 * Tenant Management Dashboard
 */

require_once '../config/db.php';
require_once '../includes/auth.php';

// Check if user is super admin
if (!isSuperAdmin()) {
    header('Location: ../index.php');
    exit();
}

// TODO: Implement tenant management dashboard
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Tenant Management</title>
</head>
<body>
    <h1>Super Admin Dashboard</h1>
    <!-- Tenant management interface -->
</body>
</html>
