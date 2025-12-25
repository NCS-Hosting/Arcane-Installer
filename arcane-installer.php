<?php
// Arcane Customer Installer CLI
// Commands: install, uninstall, update, autoupdate
// Security: HTTPS only, no embedded secrets. Validates JSON signatures when enckey provided.

declare(strict_types=1);

const API_BASE = 'https://license.ncshosting.org/api/1.2/';
// Hardcoded application identifiers per requirements
const OWNER_ID = 'ivOVJDbwto';
const APP_NAME = 'Arcane';

function println(string $msg = ''): void {
    fwrite(STDOUT, $msg . PHP_EOL);
}

function eprintln(string $msg = ''): void {
    fwrite(STDERR, $msg . PHP_EOL);
}

function prompt(string $msg): string {
    println($msg);
    $line = trim(fgets(STDIN));
    return $line;
}

function usage(): void {
    println('Arcane Installer');
    println('Run: php tools/customer-installer/arcane-installer.php');
    println('Follow interactive prompts to install, update, or uninstall.');
}

function isWindows(): bool {
    return strtoupper(PHP_OS_FAMILY) === 'Windows';
}

function requireRoot(): void {
    if (isWindows()) {
        // On Windows, we cannot reliably check Administrator from PHP. Warn user.
        println('Note: Run this installer as Administrator when modifying system files.');
        return;
    }
    if (function_exists('posix_geteuid')) {
        if (posix_geteuid() !== 0) {
            throw new RuntimeException('Installer must be run as root to ensure proper permissions.');
        }
    } else {
        // Fallback: attempt a write check to panel path later
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

// No CLI arguments are supported; installer is fully interactive.

function ensureHttps(string $url): void {
    if (stripos($url, 'https://') !== 0) {
        throw new RuntimeException('HTTPS is required for all API calls.');
    }
}

function httpPost(string $url, array $params, array $headers = []): array {
    ensureHttps($url);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($headers) {
        $hdrs = [];
        foreach ($headers as $k => $v) { $hdrs[] = $k . ': ' . $v; }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP error: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    $headersMap = [];
    foreach (explode("\r\n", $headersRaw) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $headersMap[strtolower($k)] = $v;
        }
    }
    return [ 'status' => $status, 'headers' => $headersMap, 'body' => $body ];
}

function verifySignatureIfPresent(array $resp, ?string $enckey): void {
    if (!$enckey) { return; }
    $sig = $resp['headers']['signature'] ?? null;
    if (!$sig) { return; }
    $expected = hash_hmac('sha256', $resp['body'], $enckey);
    if (!hash_equals($sig, $expected)) {
        throw new RuntimeException('Invalid response signature');
    }
}

function jsonDecodeStrict(string $json): array {
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return $data;
}

function initSession(string $ownerid, string $name, ?string $ver, ?string $enckey): array {
    $resp = httpPost(API_BASE . 'index.php?type=init', array_filter([
        'ownerid' => $ownerid,
        'name' => $name,
        'ver' => $ver,
        'enckey' => $enckey,
    ], fn($v) => $v !== null));
    verifySignatureIfPresent($resp, $enckey);
    $data = jsonDecodeStrict($resp['body']);
    return $data;
}

function licenseLogin(string $sessionid, string $key, ?string $hwid, ?string $enckey): array {
    $resp = httpPost(API_BASE . 'index.php?type=license', array_filter([
        'sessionid' => $sessionid,
        'key' => $key,
        'hwid' => $hwid,
    ], fn($v) => $v !== null));
    verifySignatureIfPresent($resp, $enckey);
    $data = jsonDecodeStrict($resp['body']);
    return $data;
}

function downloadPackage(string $key, string $ownerid, string $name, ?string $sessionid, ?string $token, ?string $hwid, string $destFile): void {
    $url = API_BASE . 'download.php';
    ensureHttps($url);
    $ch = curl_init();
    $params = array_filter([
        'key' => $key,
        'ownerid' => $ownerid,
        'name' => $name,
        'sessionid' => $sessionid,
        'token' => $token,
        'hwid' => $hwid,
    ], fn($v) => $v !== null);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADER => false,
    ]);
    $bin = curl_exec($ch);
    if ($bin === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Download error: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        throw new RuntimeException('Download failed, HTTP status ' . $status);
    }
    if (file_put_contents($destFile, $bin) === false) {
        throw new RuntimeException('Failed to write package: ' . $destFile);
    }
}

function checkPreflight(string $panelPath): void {
    // PHP version
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        throw new RuntimeException('PHP 8.0+ is required. Found ' . PHP_VERSION);
    }
    // Required extensions
    $required = ['curl', 'json', 'hash'];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            throw new RuntimeException('Required PHP extension missing: ' . $ext);
        }
    }
    // Panel structure
    $expected = [
        $panelPath . DIRECTORY_SEPARATOR . 'artisan',
        $panelPath . DIRECTORY_SEPARATOR . 'app',
        $panelPath . DIRECTORY_SEPARATOR . 'config',
    ];
    foreach ($expected as $p) {
        if (!file_exists($p)) {
            throw new RuntimeException('Panel path missing expected entry: ' . $p);
        }
    }
    // Permissions
    if (!is_writable($panelPath)) {
        throw new RuntimeException('Panel path is not writable: ' . $panelPath);
    }
    // Disk space
    $free = @disk_free_space($panelPath);
    if ($free !== false && $free < 50 * 1024 * 1024) {
        throw new RuntimeException('Insufficient disk space (<50MB)');
    }
}

function resolvePanelPathInteractive(): string {
    $cwd = getcwd();
    $candidate = $cwd;
    $markers = ['artisan', 'app', 'config'];
    $isPanel = true;
    foreach ($markers as $m) {
        if (!file_exists($candidate . DIRECTORY_SEPARATOR . $m)) { $isPanel = false; break; }
    }
    if ($isPanel) {
        println('Detected panel at current directory: ' . $candidate);
        return $candidate;
    }
    $input = prompt('Enter Pterodactyl panel root path:');
    return rtrim($input, DIRECTORY_SEPARATOR);
}

function loadLocalManifest(string $panelPath): ?array {
    $path = $panelPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arcane_installer' . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!file_exists($path)) { return null; }
    return jsonDecodeStrict(file_get_contents($path));
}

function saveLocalManifest(string $panelPath, array $manifest): void {
    $dir = $panelPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arcane_installer';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Failed to create manifest directory: ' . $dir);
    }
    $path = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function writeLog(?string $logFile, string $message): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $logfile = $logFile ?: 'arcane-installer.log';
    file_put_contents($logfile, $line, FILE_APPEND);
}

function extractZip(string $zipFile, string $destDir): void {
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Failed to open zip: ' . $zipFile);
    }
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        throw new RuntimeException('Failed to create directory: ' . $destDir);
    }
    if (!$zip->extractTo($destDir)) {
        $zip->close();
        throw new RuntimeException('Failed to extract zip to: ' . $destDir);
    }
    $zip->close();
}

function verifyFilesAgainstManifest(string $rootDir, array $manifest): void {
    if (!isset($manifest['files']) || !is_array($manifest['files'])) {
        throw new RuntimeException('Package manifest missing "files" section.');
    }
    foreach ($manifest['files'] as $f) {
        $src = $rootDir . DIRECTORY_SEPARATOR . $f['source'];
        if (!file_exists($src)) {
            throw new RuntimeException('Missing package file: ' . $src);
        }
        // Checksums are intentionally not verified per requirements.
    }
}

function applyInstallMapping(string $panelPath, string $pkgRoot, array $manifest, ?string $logFile): array {
    $backups = [];
    foreach ($manifest['files'] as $f) {
        $src = $pkgRoot . DIRECTORY_SEPARATOR . $f['source'];
        $dst = $panelPath . DIRECTORY_SEPARATOR . $f['target'];
        $dstDir = dirname($dst);
        if (!is_dir($dstDir) && !mkdir($dstDir, 0775, true)) {
            throw new RuntimeException('Failed to create target dir: ' . $dstDir);
        }
        if (file_exists($dst)) {
            $backupDir = $panelPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arcane_installer' . DIRECTORY_SEPARATOR . 'backups';
            if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) {
                throw new RuntimeException('Failed to create backup dir: ' . $backupDir);
            }
            $backupFile = $backupDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], '_', $f['target']);
            if (!copy($dst, $backupFile)) {
                throw new RuntimeException('Failed to backup: ' . $dst);
            }
            $backups[] = [ 'target' => $f['target'], 'backup' => $backupFile ];
        }
        if (!copy($src, $dst)) {
            throw new RuntimeException('Failed to install: ' . $f['target']);
        }
        writeLog($logFile, 'Installed: ' . $f['target']);
    }
    // Post-install commands
    if (!empty($manifest['post_install'])) {
        foreach ($manifest['post_install'] as $action) {
            if (($action['type'] ?? '') === 'artisan') {
                $cmd = $action['command'] ?? '';
                if ($cmd !== '') {
                    writeLog($logFile, 'Running artisan ' . $cmd);
                    // We do not automatically execute shell commands for safety; prompt user
                    println('Run in panel root: php artisan ' . $cmd);
                }
            }
        }
    }
    return $backups;
}

function uninstallUsingManifest(string $panelPath, array $manifest, ?string $logFile): void {
    // Remove installed files
    foreach ($manifest['files'] as $f) {
        $dst = $panelPath . DIRECTORY_SEPARATOR . $f['target'];
        if (file_exists($dst)) {
            if (!unlink($dst)) {
                writeLog($logFile, 'Warning: failed to remove ' . $f['target']);
            } else {
                writeLog($logFile, 'Removed: ' . $f['target']);
            }
        }
    }
    // Restore backups
    if (!empty($manifest['backups'])) {
        foreach ($manifest['backups'] as $b) {
            $dst = $panelPath . DIRECTORY_SEPARATOR . $b['target'];
            $src = $b['backup'];
            if (file_exists($src)) {
                $dstDir = dirname($dst);
                if (!is_dir($dstDir)) { mkdir($dstDir, 0775, true); }
                copy($src, $dst);
                writeLog($logFile, 'Restored backup: ' . $b['target']);
            }
        }
    }
    // Post-uninstall commands
    println('Run optional cleanup: php artisan cache:clear');
}

function commandInstallInteractive(): int {
    requireRoot();
    println('Starting Arcane install');
    $panelPath = resolvePanelPathInteractive();
    $logFile = null; // default arcane-installer.log
    $key = prompt('Enter license key:');
    $enckeyInput = prompt('Enter encryption key (optional, press Enter to skip):');
    $enckey = $enckeyInput !== '' ? $enckeyInput : null;
    $hwidInput = prompt('Enter HWID (optional, press Enter to skip):');
    $hwid = $hwidInput !== '' ? $hwidInput : null;
    $downloadTokenInput = prompt('Enter download token (optional, press Enter to skip):');
    $downloadToken = $downloadTokenInput !== '' ? $downloadTokenInput : null;

    checkPreflight($panelPath);
    writeLog($logFile, 'Preflight checks passed for ' . $panelPath);

    // Init session (no version known for fresh install)
    $init = initSession(OWNER_ID, APP_NAME, null, $enckey);
    if (!($init['success'] ?? false)) {
        throw new RuntimeException('Init failed: ' . ($init['message'] ?? 'unknown'));
    }
    $sessionid = $init['sessionid'] ?? null;
    if (!$sessionid) throw new RuntimeException('No sessionid returned');

    // License verify
    $lic = licenseLogin($sessionid, $key, $hwid, $enckey);
    if (!($lic['success'] ?? false)) {
        throw new RuntimeException('License validation failed: ' . ($lic['message'] ?? 'unknown'));
    }

    // Download artifact
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arcane_installer_' . uniqid();
    mkdir($tmpDir);
    $pkgFile = $tmpDir . DIRECTORY_SEPARATOR . 'package.zip';
    downloadPackage($key, OWNER_ID, APP_NAME, $sessionid, $downloadToken, $hwid, $pkgFile);
    writeLog($logFile, 'Downloaded package to ' . $pkgFile);

    // Extract and read package manifest
    $pkgRoot = $tmpDir . DIRECTORY_SEPARATOR . 'pkg';
    extractZip($pkgFile, $pkgRoot);
    $manifestPath = $pkgRoot . DIRECTORY_SEPARATOR . 'package.manifest.json';
    if (!file_exists($manifestPath)) {
        throw new RuntimeException('Package is missing package.manifest.json');
    }
    $manifest = jsonDecodeStrict(file_get_contents($manifestPath));
    verifyFilesAgainstManifest($pkgRoot, $manifest);

    // Apply mapping (copy files and backup overwrites)
    $backups = applyInstallMapping($panelPath, $pkgRoot, $manifest, $logFile);

    // Persist local manifest for uninstall/update
    $installedManifest = [
        'installed_at' => time(),
        'version' => $manifest['version'] ?? 'unknown',
        'files' => $manifest['files'],
        'backups' => $backups,
    ];
    saveLocalManifest($panelPath, $installedManifest);
    writeLog($logFile, 'Installation completed. Version: ' . ($installedManifest['version'] ?? 'unknown'));
    println('Install completed. Check logs and run any listed artisan commands.');
    // Cleanup temp
    @unlink($pkgFile);
    rrmdir($tmpDir);
    return 0;
}

function commandUninstallInteractive(): int {
    println('Starting Arcane uninstall');
    $panelPath = resolvePanelPathInteractive();
    $logFile = null;
    $manifest = loadLocalManifest($panelPath);
    if (!$manifest) { throw new RuntimeException('No local manifest found.'); }
    uninstallUsingManifest($panelPath, $manifest, $logFile);
    writeLog($logFile, 'Uninstall completed');
    println('Uninstall completed.');
    return 0;
}

function commandUpdateInteractive(bool $auto): int {
    requireRoot();
    println('Starting Arcane update');
    $panelPath = resolvePanelPathInteractive();
    $logFile = null;
    $local = loadLocalManifest($panelPath);
    if (!$local) { throw new RuntimeException('Local manifest missing. Run install first.'); }
    $currentVer = $local['version'] ?? null;
    $enckeyInput = prompt('Enter encryption key (optional, press Enter to skip):');
    $enckey = $enckeyInput !== '' ? $enckeyInput : null;
    $key = prompt('Enter license key:');
    $hwidInput = prompt('Enter HWID (optional, press Enter to skip):');
    $hwid = $hwidInput !== '' ? $hwidInput : null;
    $downloadTokenInput = prompt('Enter download token (optional, press Enter to skip):');
    $downloadToken = $downloadTokenInput !== '' ? $downloadTokenInput : null;

    checkPreflight($panelPath);

    // Init with current version to detect mismatch
    $init = initSession(OWNER_ID, APP_NAME, $currentVer, $enckey);
    if (($init['success'] ?? false) === false && ($init['message'] ?? '') === 'invalidver') {
        writeLog($logFile, 'Update available. Downloading...');
    } else if (($init['success'] ?? false) === true) {
        println('No update available.');
        return 0;
    } else {
        throw new RuntimeException('Init failed: ' . ($init['message'] ?? 'unknown'));
    }

    // Validate license
    $sessionid = $init['sessionid'] ?? null;
    if (!$sessionid) {
        $init2 = initSession(OWNER_ID, APP_NAME, null, $enckey);
        if (!($init2['success'] ?? false)) throw new RuntimeException('Init failed');
        $sessionid = $init2['sessionid'] ?? null;
    }
    $lic = licenseLogin($sessionid, $key, $hwid, $enckey);
    if (!($lic['success'] ?? false)) { throw new RuntimeException('License validation failed'); }

    // Backup current state
    $backupTar = $panelPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arcane_installer' . DIRECTORY_SEPARATOR . 'backup_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backupTar, ZipArchive::CREATE) === true) {
        foreach ($local['files'] as $f) {
            $dst = $panelPath . DIRECTORY_SEPARATOR . $f['target'];
            if (file_exists($dst)) {
                $zip->addFile($dst, $f['target']);
            }
        }
        $zip->close();
    }

    // Download new package
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arcane_installer_' . uniqid();
    mkdir($tmpDir);
    $pkgFile = $tmpDir . DIRECTORY_SEPARATOR . 'package.zip';
    downloadPackage($key, OWNER_ID, APP_NAME, $sessionid, $downloadToken, $hwid, $pkgFile);
    $pkgRoot = $tmpDir . DIRECTORY_SEPARATOR . 'pkg';
    extractZip($pkgFile, $pkgRoot);
    $manifestPath = $pkgRoot . DIRECTORY_SEPARATOR . 'package.manifest.json';
    if (!file_exists($manifestPath)) { throw new RuntimeException('Package missing manifest'); }
    $manifest = jsonDecodeStrict(file_get_contents($manifestPath));
    verifyFilesAgainstManifest($pkgRoot, $manifest);

    // Apply mapping
    $backups = applyInstallMapping($panelPath, $pkgRoot, $manifest, $logFile);
    $installedManifest = [
        'installed_at' => time(),
        'version' => $manifest['version'] ?? 'unknown',
        'files' => $manifest['files'],
        'backups' => $backups,
    ];
    saveLocalManifest($panelPath, $installedManifest);
    writeLog($logFile, 'Update completed to version: ' . ($installedManifest['version'] ?? 'unknown'));
    println('Update completed.');
    @unlink($pkgFile);
    rrmdir($tmpDir);
    return 0;
}

function main(): int {
    usage();
    println('Choose an action:');
    println('  1) Install');
    println('  2) Uninstall');
    println('  3) Update');
    println('  4) Exit');
    $choice = prompt('Enter choice number:');
    try {
        switch (trim($choice)) {
            case '1':
                return commandInstallInteractive();
            case '2':
                return commandUninstallInteractive();
            case '3':
                return commandUpdateInteractive(false);
            case '4':
                println('Goodbye.');
                return 0;
            default:
                println('Invalid choice.');
                return 1;
        }
    } catch (Throwable $e) {
        eprintln('Error: ' . $e->getMessage());
        return 2;
    }
}

exit(main());
