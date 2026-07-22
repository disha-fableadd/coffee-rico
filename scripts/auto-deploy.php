<?php
/**
 * Auto Deployment Script for Laravel WhatsApp Bulk
 * This script can be run via web browser or hosting panel
 */

// Set execution time limit
set_time_limit(300);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🚀 Laravel WhatsApp Bulk - Auto Deployment</h2>";
echo "<p>Starting deployment process...</p>";

// Check if we're in the right directory
if (!file_exists('artisan')) {
    die("❌ Error: This script must be run from the Laravel root directory (where artisan file is located)");
}

// Function to run command and return output
function runCommand($command) {
    echo "<p>Running: <code>$command</code></p>";
    $output = [];
    $return_var = 0;
    exec($command . ' 2>&1', $output, $return_var);

    if ($return_var !== 0) {
        echo "<p style='color: red;'>❌ Command failed with exit code: $return_var</p>";
        echo "<pre>" . implode("\n", $output) . "</pre>";
        return false;
    }

    echo "<p style='color: green;'>✅ Command completed successfully</p>";
    if (!empty($output)) {
        echo "<pre>" . implode("\n", $output) . "</pre>";
    }
    return true;
}

// Function to set file permissions
function setPermissions($path, $permissions = 0755) {
    if (file_exists($path)) {
        chmod($path, $permissions);
        echo "<p>✅ Set permissions $permissions for $path</p>";
        return true;
    }
    return false;
}

try {
    echo "<h3>1. Checking PHP Version</h3>";
    echo "<p>PHP Version: " . PHP_VERSION . "</p>";

    echo "<h3>2. Checking Composer</h3>";
    if (!runCommand('composer --version')) {
        die("❌ Composer is not available. Please install Composer first.");
    }

    echo "<h3>3. Creating Necessary Directories</h3>";
    $directories = [
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
        'resources/views'
    ];

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "<p>✅ Created directory: $dir</p>";
        } else {
            echo "<p>✅ Directory exists: $dir</p>";
        }
    }

    echo "<h3>4. Installing/Updating Dependencies</h3>";
    if (!runCommand('composer install --no-dev --optimize-autoloader --no-interaction')) {
        die("❌ Failed to install Composer dependencies");
    }

    echo "<h3>5. Generating Application Key</h3>";
    if (!runCommand('php artisan key:generate --force')) {
        echo "<p style='color: orange;'>⚠️ Key generation failed, but continuing...</p>";
    }

    echo "<h3>6. Running Database Migrations</h3>";
    if (!runCommand('php artisan migrate --force')) {
        echo "<p style='color: orange;'>⚠️ Migration failed, but continuing...</p>";
    }

    echo "<h3>7. Optimizing Application</h3>";
    runCommand('php artisan config:cache');
    runCommand('php artisan route:cache');

    // Only cache views if the views directory exists
    if (is_dir('resources/views')) {
        runCommand('php artisan view:cache');
    } else {
        echo "<p style='color: orange;'>⚠️ Views directory not found, skipping view:cache</p>";
    }

    echo "<h3>8. Setting File Permissions</h3>";
    setPermissions('storage', 0755);
    setPermissions('bootstrap/cache', 0755);

    // Set permissions for storage subdirectories
    $storageDirs = ['storage/app', 'storage/framework', 'storage/logs'];
    foreach ($storageDirs as $dir) {
        if (file_exists($dir)) {
            setPermissions($dir, 0755);
        }
    }

    echo "<h3>9. Clearing Application Cache</h3>";
    runCommand('php artisan cache:clear');

    echo "<h2 style='color: green;'>🎉 Deployment Completed Successfully!</h2>";
    echo "<p><strong>Your Laravel application is now ready to use!</strong></p>";
    echo "<p>Next steps:</p>";
    echo "<ul>";
    echo "<li>Configure your web server to point to the <code>public</code> directory</li>";
    echo "<li>Set up your database connection in the <code>.env</code> file</li>";
    echo "<li>Configure your WhatsApp API credentials</li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Deployment Failed</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
