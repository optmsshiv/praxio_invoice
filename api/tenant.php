<?php
// ================================================================
//  api/tenant.php — Tenant Management (Super Admin only)
//
//  POST   ?action=create    → provision new tenant + DB
//  GET    ?action=list      → list all tenants
//  GET    ?action=get&id=N  → get single tenant
//  PATCH  ?action=suspend   → suspend tenant
//  PATCH  ?action=activate  → reactivate tenant
//  DELETE ?action=delete    → hard delete (careful!)
//  POST   ?action=add_user  → add user to tenant
//  GET    ?action=users&tenant_id=N → list tenant users
//  PATCH  ?action=update_user       → update user role/status
//  DELETE ?action=remove_user&id=N  → remove user from tenant
// ================================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Read body for POST/PATCH
$body = [];
if (in_array($method, ['POST','PATCH','PUT'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    if (empty($body)) $body = $_POST;
}

try {
    $master = getMasterDB();

    // ── LIST tenants ───────────────────────────────────────────────
    if ($method === 'GET' && $action === 'list') {
        $stmt = $master->query(
            'SELECT t.*, COUNT(u.id) AS user_count
             FROM tenants t
             LEFT JOIN users u ON u.tenant_id = t.id AND u.status != "inactive"
             GROUP BY t.id
             ORDER BY t.created_at DESC'
        );
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ── GET single tenant ──────────────────────────────────────────
    if ($method === 'GET' && $action === 'get') {
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $master->prepare('SELECT * FROM tenants WHERE id=?');
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();
        if (!$tenant) jsonResponse(['error' => 'Tenant not found'], 404);
        jsonResponse(['success' => true, 'data' => $tenant]);
    }

    // ── LIST tenant users ──────────────────────────────────────────
    if ($method === 'GET' && $action === 'users') {
        $tid  = (int)($_GET['tenant_id'] ?? 0);
        $stmt = $master->prepare(
            'SELECT id, name, email, role, status, last_login, created_at
             FROM users WHERE tenant_id = ? ORDER BY role, name'
        );
        $stmt->execute([$tid]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ── CREATE tenant ──────────────────────────────────────────────
    if ($method === 'POST' && $action === 'create') {
        $name       = trim($body['company_name'] ?? '');
        $slug       = _makeSlug($body['slug'] ?? $name);
        $ownerEmail = trim($body['owner_email'] ?? '');
        $ownerName  = trim($body['owner_name']  ?? '');
        $ownerPass  = $body['password'] ?? _randomPassword();
        $plan       = $body['plan'] ?? 'trial';
        $phone      = trim($body['phone'] ?? '');

        if (!$name || !$ownerEmail) {
            jsonResponse(['error' => 'company_name and owner_email are required'], 400);
        }

        // Validate slug uniqueness
        $slugCheck = $master->prepare('SELECT id FROM tenants WHERE slug=?');
        $slugCheck->execute([$slug]);
        if ($slugCheck->fetch()) {
            $slug = $slug . '_' . substr(uniqid(), -4);
        }

        // Build DB name
        $dbName = 'optms_' . preg_replace('/[^a-z0-9]/', '_', strtolower($slug));
        // Ensure DB name is unique
        $dbCheck = $master->prepare('SELECT id FROM tenants WHERE db_name=?');
        $dbCheck->execute([$dbName]);
        if ($dbCheck->fetch()) {
            $dbName .= '_' . substr(uniqid(), -4);
        }

        // ── Provision tenant DB ──────────────────────────────────
        _provisionTenantDB($dbName);

        // ── Insert tenant row ────────────────────────────────────
        $master->prepare(
            'INSERT INTO tenants (slug, company_name, db_name, plan, owner_email, owner_name, phone, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$slug, $name, $dbName, $plan, $ownerEmail, $ownerName, $phone,
                    $_SESSION['user_id']]);
        $tenantId = (int)$master->lastInsertId();

        // ── Create owner user in master users table ──────────────
        $hashedPass = password_hash($ownerPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $master->prepare(
            'INSERT INTO users (tenant_id, name, email, password, role, status, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$tenantId, $ownerName ?: $name, $ownerEmail,
                    $hashedPass, 'owner', 'active',
                    $_SESSION['user_id']]);
        $userId = (int)$master->lastInsertId();

        // ── Seed owner into tenant DB users table ────────────────
        $tenantDb = getDBByName($dbName);
        $tenantDb->prepare(
            'INSERT IGNORE INTO users (id, name, email, password, role, is_active)
             VALUES (?,?,?,?,?,1)'
        )->execute([$userId, $ownerName ?: $name, $ownerEmail, $hashedPass, 'owner']);

        // ── Seed default company name in tenant settings ─────────
        $tenantDb->prepare(
            'INSERT INTO settings (`key`, value) VALUES ("company_name", ?)
             ON DUPLICATE KEY UPDATE value=?'
        )->execute([$name, $name]);

        masterAuditLog($_SESSION['user_id'], $tenantId, 'tenant_created',
            "Created tenant: {$name} (DB: {$dbName})");

        jsonResponse([
            'success'    => true,
            'tenant_id'  => $tenantId,
            'db_name'    => $dbName,
            'slug'       => $slug,
            'owner_email'=> $ownerEmail,
            'temp_pass'  => $ownerPass, // show once — tell admin to copy it
            'message'    => "Tenant '{$name}' created. Share credentials with the client.",
        ]);
    }

    // ── SUSPEND tenant ─────────────────────────────────────────────
    if ($method === 'PATCH' && $action === 'suspend') {
        $id = (int)($body['id'] ?? 0);
        $master->prepare('UPDATE tenants SET status="suspended" WHERE id=?')->execute([$id]);
        masterAuditLog($_SESSION['user_id'], $id, 'tenant_suspended', '');
        jsonResponse(['success' => true]);
    }

    // ── ACTIVATE tenant ────────────────────────────────────────────
    if ($method === 'PATCH' && $action === 'activate') {
        $id = (int)($body['id'] ?? 0);
        $master->prepare('UPDATE tenants SET status="active" WHERE id=?')->execute([$id]);
        masterAuditLog($_SESSION['user_id'], $id, 'tenant_activated', '');
        jsonResponse(['success' => true]);
    }

    // ── ADD user to tenant ─────────────────────────────────────────
    if ($method === 'POST' && $action === 'add_user') {
        $tenantId  = (int)($body['tenant_id'] ?? 0);
        $email     = trim($body['email'] ?? '');
        $name      = trim($body['name']  ?? '');
        $role      = $body['role'] ?? 'sales';
        $password  = $body['password'] ?? _randomPassword();

        $allowedRoles = ['owner','admin','manager','accountant','sales','viewer'];
        if (!in_array($role, $allowedRoles)) {
            jsonResponse(['error' => 'Invalid role'], 400);
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Valid email required'], 400);
        }

        // Check tenant exists
        $tStmt = $master->prepare('SELECT db_name FROM tenants WHERE id=? AND status="active"');
        $tStmt->execute([$tenantId]);
        $tenant = $tStmt->fetch();
        if (!$tenant) jsonResponse(['error' => 'Tenant not found or suspended'], 404);

        $hashedPass = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Check email not already taken
        $emailCheck = $master->prepare('SELECT id FROM users WHERE email=?');
        $emailCheck->execute([$email]);
        if ($emailCheck->fetch()) jsonResponse(['error' => 'Email already in use'], 409);

        // Insert into master users
        $master->prepare(
            'INSERT INTO users (tenant_id, name, email, password, role, status, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$tenantId, $name, $email, $hashedPass, $role, 'active',
                    $_SESSION['user_id']]);
        $userId = (int)$master->lastInsertId();

        // Mirror into tenant DB users table
        $tenantDb = getDBByName($tenant['db_name']);
        $tenantDb->prepare(
            'INSERT IGNORE INTO users (id, name, email, password, role, is_active)
             VALUES (?,?,?,?,?,1)'
        )->execute([$userId, $name, $email, $hashedPass, $role]);

        masterAuditLog($_SESSION['user_id'], $tenantId, 'user_added',
            "Added user: {$email} ({$role})");

        jsonResponse([
            'success'   => true,
            'user_id'   => $userId,
            'email'     => $email,
            'role'      => $role,
            'temp_pass' => $password,
        ]);
    }

    // ── UPDATE user role/status ────────────────────────────────────
    if ($method === 'PATCH' && $action === 'update_user') {
        $userId = (int)($body['user_id'] ?? 0);
        $field  = $body['field'] ?? '';
        $value  = $body['value'] ?? '';
        if (!in_array($field, ['role','status'])) {
            jsonResponse(['error' => 'Only role and status can be updated'], 400);
        }
        $master->prepare("UPDATE users SET {$field}=? WHERE id=?")->execute([$value, $userId]);
        jsonResponse(['success' => true]);
    }

    // ── REMOVE user ────────────────────────────────────────────────
    if (($method === 'DELETE' || $method === 'PATCH') && $action === 'remove_user') {
        $userId = (int)($body['user_id'] ?? $_GET['id'] ?? 0);
        $master->prepare('UPDATE users SET status="inactive" WHERE id=?')->execute([$userId]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown action: ' . $action], 400);

} catch (Exception $e) {
    error_log('tenant.php error: ' . $e->getMessage());
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

// ── Helpers ────────────────────────────────────────────────────────

function _makeSlug(string $text): string {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug ?: 'tenant_' . substr(uniqid(), -6);
}

function _randomPassword(int $len = 12): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@#!';
    $pass  = '';
    for ($i = 0; $i < $len; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function _provisionTenantDB(string $dbName): void {
    // Connect without DB selected to create it
    $pdo = new PDO(
        'mysql:host=' . MASTER_DB_HOST . ';charset=utf8mb4',
        MASTER_DB_USER, MASTER_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create the database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Switch to the new DB and run the tenant schema
    $pdo->exec("USE `{$dbName}`");

    $schemaFile = __DIR__ . '/../config/tenant_schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("tenant_schema.sql not found at: {$schemaFile}");
    }

    // Execute schema SQL — split by semicolon, skip empty
    $sql        = file_get_contents($schemaFile);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--')
    );
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Skip "already exists" errors during provisioning
            if ($e->getCode() !== '42S01') throw $e;
        }
    }
}
