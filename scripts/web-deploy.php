<?php
/**
 * Web-based Deployment Script
 * Access this file via browser: http://your-domain.com/services/scripts/web-deploy.php
 */

// Security check - only allow from specific IPs or with password
$allowed_ips = ['127.0.0.1', '::1']; // Add your IP here
$deploy_password = 'deploy123'; // Change this password

if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips) &&
    (!isset($_GET['password']) || $_GET['password'] !== $deploy_password)) {
    die("❌ Access denied. Please contact administrator.");
}

echo "<!DOCTYPE html>";
echo "<html><head><title>Laravel Deployment</title></head><body>";
echo "<h1>🚀 Laravel WhatsApp Bulk - Web Deployment</h1>";

// Include the auto-deploy script
include 'auto-deploy.php';

echo "</body></html>";
?>
