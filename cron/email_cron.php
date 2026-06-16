<?php
/**
 * Email Cron Job (symlink or copy)
 * Runs via cPanel cron
 */

require_once '../../config/db.php';

// Verify cron security
// if ($_GET['token'] !== CRON_TOKEN) exit('Unauthorized');

// TODO: Implement cron job logic
echo "Cron executed: " . date('Y-m-d H:i:s');
