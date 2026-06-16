<?php
/**
 * Logout Handler
 */

session_start();
session_destroy();
header('Location: login.php');
exit();
