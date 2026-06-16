<?php
// DELETE THIS FILE IMMEDIATELY AFTER USE
require_once 'config/db.php';

$newPassword = 'Admin@1234';
$hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

$db = getMasterDB();
$db->prepare("UPDATE users SET password=? WHERE email='superadmin@optmstech.in'")
   ->execute([$hash]);

echo "Done! Hash set to: " . $hash;
echo "<br>Login with: Admin@1234";
echo "<br><strong>DELETE THIS FILE NOW!</strong>";
?>