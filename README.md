# Arcane Customer Installer

A single-file PHP CLI installer for Arcane extension packages. Supports install, uninstall, update/autoupdate. Uses HTTPS-only API calls and validates JSON signatures when encryption key is provided.

## Usage

```
php tools/customer-installer/arcane-installer.php install --path="/var/www/panel" --ownerid="ivOVJDbwto" --name="Arcane" --key="YOUR-LICENSE-KEY"
php tools/customer-installer/arcane-installer.php uninstall --path="/var/www/panel"
php tools/customer-installer/arcane-installer.php update --path="/var/www/panel" --ownerid="ivOVJDbwto" --name="Arcane" --key="YOUR-LICENSE-KEY"
php tools/customer-installer/arcane-installer.php autoupdate --path="/var/www/panel" --ownerid="ivOVJDbwto" --name="Arcane" --key="YOUR-LICENSE-KEY" --non-interactive
```

- `--enckey` is optional; when provided, response signatures are verified for JSON endpoints.
- Installer expects the downloaded package to contain `package.manifest.json` with a file mapping and checksums.
- Post-install artisan commands are printed rather than executed, for safety.

## Manifest Format (package.manifest.json)

```json
{
  "version": "1.0.0",
  "compatibility": { "panel_min": "1.11.0", "panel_max": "2.x" },
  "files": [
    { "source": "src/app/Providers/ArcaneLicenseServiceProvider.php", "target": "app/Providers/ArcaneLicenseServiceProvider.php", "sha256": "..." },
    { "source": "src/app/Http/Middleware/ArcaneLicenseMiddleware.php", "target": "app/Http/Middleware/ArcaneLicenseMiddleware.php", "sha256": "..." }
  ],
  "post_install": [
    { "type": "artisan", "command": "cache:clear" },
    { "type": "artisan", "command": "config:cache" }
  ]
}
```

The installer will:
- Validate file checksums.
- Backup existing targets before overwrite.
- Copy files to targets.
- Save a local manifest to enable safe uninstall/update.
