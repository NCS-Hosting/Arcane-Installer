# Arcane Customer Installer

A single-file PHP CLI installer for Arcane extension packages. Fully interactive: prompts for all required inputs. Uses HTTPS-only API calls and validates JSON signatures when an encryption key is provided. The installer does not verify file checksums.

## Usage

Run the installer and follow prompts:

```
php tools/customer-installer/arcane-installer.php
```

You'll be asked to:
- Panel path: auto-detected from current directory when possible, otherwise prompted.
- License key: required to validate and download.
- Optional values: encryption key (`enckey`) for signature verification, `HWID` binding, and a download token if provided by support.

Notes:
- Owner ID and application name are hardcoded inside the installer.
- Post-install artisan commands are printed for you to run manually.
- On Linux, run as `root` to ensure proper permissions; on Windows, run as Administrator.
 - Checksums in the package manifest, if present, are ignored by the installer.

## Manifest Format (package.manifest.json)

```json
{
  "version": "1.0.0",
  "compatibility": { "panel_min": "1.11.0", "panel_max": "2.x" },
  "files": [
    { "source": "src/app/Providers/ArcaneLicenseServiceProvider.php", "target": "app/Providers/ArcaneLicenseServiceProvider.php" },
    { "source": "src/app/Http/Middleware/ArcaneLicenseMiddleware.php", "target": "app/Http/Middleware/ArcaneLicenseMiddleware.php" }
  ],
  "post_install": [
    { "type": "artisan", "command": "cache:clear" },
    { "type": "artisan", "command": "config:cache" }
  ]
}
```

The installer will:
- Verify required package files exist.
- Backup existing targets before overwrite.
- Copy files to targets.
- Save a local manifest to enable safe uninstall/update.
