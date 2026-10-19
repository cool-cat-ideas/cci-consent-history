# Development checks

Runtime files stay readable in Git. The release build minifies CSS and JavaScript in a temporary copy, removes ordinary comments and preserves legal comments. It does not rewrite the sources. Developer dependencies and scripts are excluded from the installable ZIP.

## PHP formatting

From the product directory:

```bash
composer install --working-dir=scripts/quality
composer format:php
composer format:php:check
```

The checked-in configuration uses the official `prestashop/php-dev-tools` rules and PHP CS Fixer. The development lock file pins their versions. Keep a blank line between methods, separate imports from declarations and write one item per line in long arrays.

## CSS and JavaScript

```bash
npm ci
npm run format
npm run format:check
npm run build
```

Run Node through the CCI workspace toolchain when needed. The build writes `releases/prestashop/cci_consent_history-1.0.0.zip` and its SHA-256 file. Install that exact ZIP for runtime verification; do not substitute files from the readable source tree after testing the release.
