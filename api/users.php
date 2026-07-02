<?php
// ================================================================
//  api/users.php — Tenant User Management
//  Called by the Team page inside index.php
//  Accessible to tenant owners only — scoped to their own tenant
//
//  GET    ?action=list              → list users in this tenant
//  POST   ?action=add               → add user to this tenant
//  PATCH  ?action=update&id=N       → update role/status
//  PATCH  ?action=remove&id=N       → deactivate user
//  PATCH  ?action=change_password   → change own password
// ================================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$method    = $_SERVER['REQUEST_METHOD'];
$action    = $_GET['action'] ?? '';
$tenantId  = (int)($_SESSION['tenant_id'] ?? 0);
$userId    = (int)($_SESSION['user_id']   ?? 0);
$userRole  = $_SESSION['user_role']       ?? 'viewer';

// Read body
$body = [];
if (in_array($method, ['POST','PATCH','PUT'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
}

try {
    $master = getMasterDB();

    // ── LIST users in this tenant ──────────────────────────────────
    if ($method === 'GET' && $action === 'list') {
        requireRole(['owner','admin','super_admin']);

        $stmt = $master->prepare(
            'SELECT id, name, email, role, status, phone,
                    last_login, login_count, created_at
             FROM users
             WHERE tenant_id = ?
             ORDER BY FIELD(role,"owner","admin","manager","accountant","sales","viewer"), name'
        );
        $stmt->execute([$tenantId]);
        $users = $stmt->fetchAll();

        // Never expose password hashes
        foreach ($users as &$u) unset($u['password'], $u['invite_token'], $u['reset_token']);

        jsonResponse(['success' => true, 'data' => $users]);
    }

    // ── ADD user to this tenant ────────────────────────────────────
    if ($method === 'POST' && $action === 'add') {
        requireRole(['owner','super_admin']);

        $email    = trim($body['email']    ?? '');
        $name     = trim($body['name']     ?? '');
        $role     = $body['role']          ?? 'sales';
        $password = $body['password']      ?? '';

        $allowedRoles = ['admin','manager','accountant','sales','viewer'];
        if (!in_array($role, $allowedRoles)) {
            jsonResponse(['error' => 'Invalid role. Allowed: ' . implode(', ', $allowedRoles)], 400);
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Valid email required'], 400);
        }
        if (!$name) {
            jsonResponse(['error' => 'Name required'], 400);
        }

        // Check email not already taken in master
        $emailCheck = $master->prepare('SELECT id, tenant_id FROM users WHERE email = ?');
        $emailCheck->execute([$email]);
        $existing = $emailCheck->fetch();
        if ($existing) {
            if ((int)$existing['tenant_id'] === $tenantId) {
                jsonResponse(['error' => 'This email is already a member of your team'], 409);
            }
            jsonResponse(['error' => 'Email already in use by another account'], 409);
        }

        // Generate password if not provided
        $tempPass   = $password ?: _randomPass();
        $hashedPass = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);

        // Insert into master users
        $master->prepare(
            'INSERT INTO users (tenant_id, name, email, password, role, status, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$tenantId, $name, $email, $hashedPass, $role, 'active', $userId]);
        $newUserId = (int)$master->lastInsertId();

        // Mirror into tenant DB users table (for activity_log FK etc.)
        try {
            $tenantDb = getDB(); // already connected to tenant DB via session
            $tenantDb->prepare(
                'INSERT IGNORE INTO users (id, name, email, password, role, is_active)
                 VALUES (?,?,?,?,?,1)'
            )->execute([$newUserId, $name, $email, $hashedPass, $role]);
        } catch (Exception $e) {
            error_log('users.php: tenant DB mirror failed: ' . $e->getMessage());
            // Non-fatal — master insert is source of truth
        }

        masterAuditLog($userId, $tenantId, 'user_added', "Added {$email} as {$role}");
        logActivity($userId, 'create', 'user', $newUserId, "Added team member: {$email} ({$role})");

        jsonResponse([
            'success'   => true,
            'user_id'   => $newUserId,
            'email'     => $email,
            'role'      => $role,
            'temp_pass' => $tempPass,
        ]);
    }

    // ── UPDATE role or status ──────────────────────────────────────
    if ($method === 'PATCH' && $action === 'update') {
        requireRole(['owner','super_admin']);

        $targetId = (int)($body['user_id'] ?? $_GET['id'] ?? 0);
        $field    = $body['field'] ?? '';
        $value    = $body['value'] ?? '';

        // Verify target belongs to this tenant
        $check = $master->prepare('SELECT id, role FROM users WHERE id=? AND tenant_id=?');
        $check->execute([$targetId, $tenantId]);
        $target = $check->fetch();
        if (!$target) jsonResponse(['error' => 'User not found in your team'], 404);

        // Can't change owner's role
        if ($target['role'] === 'owner' && $field === 'role') {
            jsonResponse(['error' => 'Cannot change the owner\'s role'], 403);
        }
        // Can't demote yourself
        if ($targetId === $userId) {
            jsonResponse(['error' => 'You cannot change your own role or status'], 403);
        }

        $allowed = ['role' => ['admin','manager','accountant','sales','viewer'],
                    'status' => ['active','inactive']];
        if (!isset($allowed[$field]) || !in_array($value, $allowed[$field])) {
            jsonResponse(['error' => "Invalid {$field}"], 400);
        }

        $master->prepare("UPDATE users SET {$field}=? WHERE id=?")->execute([$value, $targetId]);

        // Mirror to tenant DB
        try {
            if ($field === 'status') {
                getDB()->prepare('UPDATE users SET is_active=? WHERE id=?')
                       ->execute([$value === 'active' ? 1 : 0, $targetId]);
            } elseif ($field === 'role') {
                getDB()->prepare('UPDATE users SET role=? WHERE id=?')
                       ->execute([$value, $targetId]);
            }
        } catch (Exception $e) { /* non-fatal */ }

        masterAuditLog($userId, $tenantId, 'user_updated', "Updated user #{$targetId} {$field}={$value}");
        jsonResponse(['success' => true]);
    }

    // ── REMOVE (deactivate) user ───────────────────────────────────
    if ($method === 'PATCH' && $action === 'remove') {
        requireRole(['owner','super_admin']);

        $targetId = (int)($body['user_id'] ?? $_GET['id'] ?? 0);

        // Verify target belongs to this tenant
        $check = $master->prepare('SELECT id, role, email FROM users WHERE id=? AND tenant_id=?');
        $check->execute([$targetId, $tenantId]);
        $target = $check->fetch();
        if (!$target) jsonResponse(['error' => 'User not found in your team'], 404);

        // Can't remove yourself
        if ($targetId === $userId) {
            jsonResponse(['error' => 'You cannot remove yourself'], 403);
        }
        // Can't remove another owner
        if ($target['role'] === 'owner') {
            jsonResponse(['error' => 'Cannot remove the account owner'], 403);
        }

        $master->prepare('UPDATE users SET status="inactive" WHERE id=?')->execute([$targetId]);

        // Mirror to tenant DB
        try {
            getDB()->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$targetId]);
        } catch (Exception $e) { /* non-fatal */ }

        masterAuditLog($userId, $tenantId, 'user_removed', "Removed user: {$target['email']}");
        logActivity($userId, 'delete', 'user', $targetId, "Removed team member: {$target['email']}");
        jsonResponse(['success' => true]);
    }

    // ── CHANGE OWN PASSWORD ────────────────────────────────────────
    if ($method === 'PATCH' && $action === 'change_password') {
        $current = $body['current_password'] ?? '';
        $newPass = $body['new_password']     ?? '';

        if (strlen($newPass) < 8) {
            jsonResponse(['error' => 'New password must be at least 8 characters'], 400);
        }

        // Verify current password
        $stmt = $master->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password'])) {
            jsonResponse(['error' => 'Current password is incorrect'], 401);
        }

        $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $master->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hashed, $userId]);

        // Mirror to tenant DB
        try {
            getDB()->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hashed, $userId]);
        } catch (Exception $e) { /* non-fatal */ }

        masterAuditLog($userId, $tenantId, 'password_changed', 'User changed own password');
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown action: ' . $action], 400);

} catch (Exception $e) {
    error_log('users.php error: ' . $e->getMessage());
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

function _randomPass(int $len = 12): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@#!';
    $pass  = '';
    for ($i = 0; $i < $len; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}
