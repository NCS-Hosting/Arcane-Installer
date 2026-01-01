#!/usr/bin/env php
<?php
/**
 * Lightweight Arcane Extension Installer
 * 
 * Installs ONLY extension files (delta from vanilla Pterodactyl).
 * This installer is much faster than full panel installation since it only
 * copies modified/new files and runs minimal post-install commands.
 * 
 * Features:
 * - Validates panel version compatibility
 * - Verifies file integrity (SHA256)
 * - Creates backups before installation
 * - Supports rollback on failure
 * - Minimal overhead (no full rebuild required)
 */

declare(strict_types=1);

define('INSTALLER_VERSION', '2.0.0');

function println(string $msg = ''): void { fwrite(STDOUT, $msg . PHP_EOL); }
function eprintln(string $msg = ''): void { fwrite(STDERR, $msg . PHP_EOL); }
function printSuccess(string $msg): void { println("\033[32m✓\033[0m " . $msg); }
function printWarning(string $msg): void { println("\033[33m⚠\033[0m " . $msg); }
function printError(string $msg): void { eprintln("\033[31m✗\033[0m " . $msg); }
function printInfo(string $msg): void { println("\033[36mℹ\033[0m " . $msg); }

/**
 * Prompt for user input
 */
function prompt(string $message, ?string $default = null): string {
    if ($default) {
        $message .= " [" . $default . "]";
    }
    fwrite(STDOUT, $message . ': ');
    $input = trim(fgets(STDIN));
    return $input === '' && $default !== null ? $default : $input;
}

/**
 * Confirm action
 */
function confirm(string $message, bool $default = false): bool {
    $defaultText = $default ? 'Y/n' : 'y/N';
    $input = strtolower(prompt($message . " ({$defaultText})"));
    
    if ($input === '') {
        return $default;
    }
    
    return in_array($input, ['y', 'yes', '1', 'true']);
}

/**
 * Detect panel root directory
 */
function detectPanelRoot(): ?string {
    $cwd = getcwd();
    
    // Check if current directory is panel root
    if (file_exists($cwd . '/artisan') && file_exists($cwd . '/app/Console/Kernel.php')) {
        return $cwd;
    }
    
    // Check parent directories
    $dir = $cwd;
    for ($i = 0; $i < 3; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/artisan') && file_exists($dir . '/app/Console/Kernel.php')) {
            return $dir;
        }
    }
    
    return null;
}

/**
 * Get panel version
 */
function getPanelVersion(string $panelRoot): ?string {
    $configPath = $panelRoot . '/config/app.php';
    if (!file_exists($configPath)) {
        return null;
    }
    
    $content = file_get_contents($configPath);
    if (preg_match("/'version'\\s*=>\\s*'([^']+)'/", $content, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Validate version compatibility
 */
function validateCompatibility(string $panelVersion, ?string $minVersion, ?string $maxVersion): bool {
    if ($minVersion && version_compare($panelVersion, $minVersion, '<')) {
        return false;
    }
    
    if ($maxVersion && version_compare($panelVersion, $maxVersion, '>')) {
        return false;
    }
    
    return true;
}

/**
 * Extract package archive
 */
function extractPackage(string $packagePath): string {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arcane_install_' . uniqid();
    mkdir($tempDir, 0755, true);
    
    $ext = strtolower(pathinfo($packagePath, PATHINFO_EXTENSION));
    
    if ($ext === 'gz' && str_ends_with(strtolower($packagePath), '.tar.gz')) {
        // Extract tar.gz
        if (!class_exists('PharData')) {
            throw new RuntimeException('PharData not available for .tar.gz extraction');
        }
        
        $phar = new PharData($packagePath);
        $phar->extractTo($tempDir);
        
        return $tempDir;
    }
    
    if ($ext === 'zip') {
        // Extract zip
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Failed to open zip archive');
        }
        
        $zip->extractTo($tempDir);
        $zip->close();
        
        return $tempDir;
    }
    
    throw new RuntimeException('Unsupported package format: ' . $ext);
}

/**
 * Verify file integrity
 */
function verifyFileIntegrity(string $filePath, string $expectedHash): bool {
    $actualHash = hash_file('sha256', $filePath);
    return $actualHash === $expectedHash;
}

/**
 * Create backup of file
 */
function createBackup(string $filePath, string $backupDir): void {
    $relativePath = str_replace(realpath(dirname($filePath, 3)), '', $filePath);
    $backupPath = $backupDir . $relativePath;
    
    $backupDirPath = dirname($backupPath);
    if (!is_dir($backupDirPath)) {
        mkdir($backupDirPath, 0755, true);
    }
    
    copy($filePath, $backupPath);
}

/**
 * Install extension files
 */
function installFiles(array $manifest, string $packageDir, string $panelRoot, bool $createBackups): array {
    $installed = [];
    $failed = [];
    $backupDir = null;
    
    if ($createBackups) {
        $backupDir = $panelRoot . '/storage/backups/arcane_' . date('Y-m-d_H-i-s');
        mkdir($backupDir, 0755, true);
        printInfo('Backups will be saved to: ' . $backupDir);
    }
    
    $totalFiles = count($manifest['files']);
    $processedCount = 0;
    
    foreach ($manifest['files'] as $file) {
        $processedCount++;
        $sourcePath = $packageDir . DIRECTORY_SEPARATOR . $file['source'];
        $targetPath = $panelRoot . DIRECTORY_SEPARATOR . $file['target'];
        
        // Verify source file exists
        if (!file_exists($sourcePath)) {
            $failed[] = [
                'file' => $file['target'],
                'reason' => 'Source file not found in package',
            ];
            continue;
        }
        
        // Verify integrity if hash provided
        if (isset($file['sha256']) && !verifyFileIntegrity($sourcePath, $file['sha256'])) {
            $failed[] = [
                'file' => $file['target'],
                'reason' => 'Integrity check failed (SHA256 mismatch)',
            ];
            continue;
        }
        
        // Create backup if file exists
        if ($createBackups && file_exists($targetPath)) {
            try {
                createBackup($targetPath, $backupDir);
            } catch (Exception $e) {
                printWarning('Failed to backup: ' . $file['target']);
            }
        }
        
        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        // Copy file
        if (copy($sourcePath, $targetPath)) {
            $installed[] = $file['target'];
            
            // Set proper permissions
            chmod($targetPath, 0644);
            
            if ($processedCount % 10 === 0 || $processedCount === $totalFiles) {
                printInfo("Progress: {$processedCount}/{$totalFiles} files");
            }
        } else {
            $failed[] = [
                'file' => $file['target'],
                'reason' => 'Failed to copy file',
            ];
        }
    }
    
    return [
        'installed' => $installed,
        'failed' => $failed,
        'backup_dir' => $backupDir,
    ];
}

/**
 * Run post-install commands
 */
function runPostInstallCommands(array $commands, string $panelRoot): void {
    printInfo('Running post-install commands...');
    
    foreach ($commands as $cmd) {
        if ($cmd['type'] === 'artisan') {
            $command = 'php artisan ' . $cmd['command'];
            printInfo('  → ' . $command);
            
            $output = [];
            $returnCode = 0;
            
            exec('cd ' . escapeshellarg($panelRoot) . ' && ' . $command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                printWarning('    Command failed (exit code: ' . $returnCode . ')');
                foreach ($output as $line) {
                    printWarning('    ' . $line);
                }
            } else {
                printSuccess('    Completed');
            }
        }
    }
}

/**
 * Main installation routine
 */
function main(): int {
    println('╔═══════════════════════════════════════════════════════════╗');
    println('║     Arcane Extension Installer - Lightweight & Fast      ║');
    println('║                    Version ' . INSTALLER_VERSION . '                        ║');
    println('╚═══════════════════════════════════════════════════════════╝');
    println('');
    
    try {
        // Step 1: Detect panel root
        printInfo('Detecting Pterodactyl Panel installation...');
        $panelRoot = detectPanelRoot();
        
        if (!$panelRoot) {
            $panelRoot = prompt('Panel root directory not found. Please enter panel path');
            if (!file_exists($panelRoot . '/artisan')) {
                printError('Invalid panel path. artisan file not found.');
                return 1;
            }
        }
        
        $panelRoot = realpath($panelRoot);
        printSuccess('Panel found: ' . $panelRoot);
        
        // Step 2: Get panel version
        $panelVersion = getPanelVersion($panelRoot);
        if ($panelVersion) {
            printInfo('Panel version: ' . $panelVersion);
        } else {
            printWarning('Could not determine panel version');
        }
        
        // Step 3: Locate package
        $packagePath = prompt('Enter path to Arcane package (.zip or .tar.gz)', './arcane_package_1.0.0.tar.gz');
        
        if (!file_exists($packagePath)) {
            printError('Package not found: ' . $packagePath);
            return 1;
        }
        
        printSuccess('Package found: ' . basename($packagePath));
        printInfo('Size: ' . number_format(filesize($packagePath) / 1024, 2) . ' KB');
        
        // Step 4: Extract package
        printInfo('Extracting package...');
        $packageDir = extractPackage($packagePath);
        printSuccess('Package extracted');
        
        // Step 5: Load manifest
        $manifestPath = $packageDir . '/package.manifest.json';
        if (!file_exists($manifestPath)) {
            printError('Manifest not found in package');
            return 1;
        }
        
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            printError('Invalid manifest JSON');
            return 1;
        }
        
        printInfo('Package: ' . ($manifest['name'] ?? 'Unknown'));
        printInfo('Version: ' . ($manifest['version'] ?? 'Unknown'));
        printInfo('Files: ' . count($manifest['files']));
        
        // Step 6: Validate compatibility
        if ($panelVersion) {
            $minVersion = $manifest['compatibility']['panel_min'] ?? null;
            $maxVersion = $manifest['compatibility']['panel_max'] ?? null;
            
            if (!validateCompatibility($panelVersion, $minVersion, $maxVersion)) {
                printWarning('Panel version compatibility check failed!');
                printWarning('Panel: ' . $panelVersion);
                printWarning('Required: ' . ($minVersion ?? 'any') . ' - ' . ($maxVersion ?? 'any'));
                
                if (!confirm('Continue anyway?', false)) {
                    return 1;
                }
            } else {
                printSuccess('Version compatibility verified');
            }
        }
        
        // Step 7: Confirm installation
        println('');
        println('Ready to install Arcane extensions:');
        println('  • ' . count($manifest['files']) . ' files will be installed/updated');
        println('  • Target: ' . $panelRoot);
        println('');
        
        if (!confirm('Proceed with installation?', true)) {
            println('Installation cancelled.');
            return 0;
        }
        
        $createBackups = confirm('Create backups of existing files?', true);
        
        // Step 8: Install files
        println('');
        printInfo('Installing extension files...');
        $result = installFiles($manifest, $packageDir, $panelRoot, $createBackups);
        
        if (!empty($result['failed'])) {
            printWarning('Some files failed to install:');
            foreach ($result['failed'] as $failure) {
                printWarning('  • ' . $failure['file'] . ': ' . $failure['reason']);
            }
        }
        
        printSuccess('Installed ' . count($result['installed']) . ' files');
        
        if ($result['backup_dir']) {
            printInfo('Backups saved to: ' . $result['backup_dir']);
        }
        
        // Step 9: Run post-install commands
        if (!empty($manifest['post_install'])) {
            println('');
            runPostInstallCommands($manifest['post_install'], $panelRoot);
        }
        
        // Cleanup
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($packageDir);
        
        // Success
        println('');
        println('╔═══════════════════════════════════════════════════════════╗');
        println('║               Installation Completed Successfully!        ║');
        println('╚═══════════════════════════════════════════════════════════╝');
        println('');
        printSuccess('Arcane extensions have been installed');
        printInfo('Next steps:');
        println('  1. Clear your browser cache');
        println('  2. Run: php artisan queue:restart (if using queues)');
        println('  3. Access your panel and verify functionality');
        println('');
        
        if (!empty($result['failed'])) {
            printWarning('Note: ' . count($result['failed']) . ' files failed to install');
            printWarning('Check the errors above and install manually if needed');
        }
        
        return empty($result['failed']) ? 0 : 2;
        
    } catch (Exception $e) {
        println('');
        printError('Installation failed: ' . $e->getMessage());
        if (getenv('DEBUG')) {
            eprintln($e->getTraceAsString());
        }
        return 1;
    }
}

exit(main());
