# silverstripe-developertools

Developer tooling for Silverstripe CMS in one admin area: monitoring, overlays,
offline mode and project analysis.

## Documentation

`README.md` is the entry point. Feature details live in `docs/en`.

| Guide | Contents |
|---|---|
| [Website Monitoring](docs/en/website-monitoring.md) | Protected JSON API, health checks, version history, notifications, scheduled checks |
| [Offline Page and Maintenance Mode](docs/en/offline-mode.md) | Setup, activation, static export, scheduling, IP exceptions |
| [Frontend Overlays](docs/en/overlays.md) | Targeting, scheduling, rendering, caching, asset build and release workflow |

## Core capabilities

- **Dashboard**: single shared `DeveloperToolsConfig` with dashboard panels.
- **Logs**: administrator-only view of `.log` files in the project's `logs` directory.
- **Monitoring**: central website inventory, health status and notifications.
- **Overlays**: CMS-managed frontend notices with audience and time filters.
- **Offline mode**: static maintenance page with scheduling and IP exceptions.
- **Analysis**: project info, Composer packages and registered routes.

## Installation

```bash
composer require clesson-de/silverstripe-developertools
vendor/bin/sake db:build --flush
composer vendor-expose
```

## Supported versions

This package supports:

- PHP 8.5
- SilverStripe 6
- SilverStripe CMS 6
- SilverStripe Admin 3
- SilverStripe Assets 3
- SilverStripe Versioned 3

## Configuration

The module is installed as a standard SilverStripe vendormodule and is available in the CMS admin menu after rebuilding the project.

After installation, run:

```bash
composer require clesson-de/silverstripe-developertools
vendor/bin/sake db:build --flush
composer vendor-expose
```

Then open the `Developer Tools` area in the CMS. No custom bootstrap code is required for the default setup.

## Quick usage

Open **Developer Tools** in the CMS menu (`ADMIN` permission required):

1. **Dashboard**
2. **Logs**
3. **Monitoring**
4. **Overlays**
5. **Offline mode**
6. **Analysis**

The Logs tab lists regular `.log` files directly inside `<project>/logs` (not
subdirectories or symlinks). A selected file is shown as plain text with at most
the last 1 MiB; a notice marks truncated content. Automatic refresh is opt-in and
accepts whole seconds from 5 to 360. The PHP process must be able to read the
directory and files. Restrict CMS administrator access: logs may contain secrets
or personal data. Files without a `.log` extension are not displayed.

After upgrades, rerun:

```bash
vendor/bin/sake db:build --flush
composer vendor-expose
```

## Release check (assets)

Compiled frontend/admin assets are intentionally versioned in this repository
and must not be ignored by Git.
Before handover or release, rebuild and commit all changed files in
`client/admin/dist` and `client/frontend/dist`.

```bash
npm install
npm run build
npm test
composer vendor-expose
```

Detailed asset workflow is documented in
[Frontend Overlays](docs/en/overlays.md#frontend-asset-build-and-versioning).

## Repository hygiene

- Team-wide recurring artifacts belong in `.gitignore`.
- Personal/local-only artifacts belong in `.git/info/exclude`.

Example (`.git/info/exclude`):

```text
# local editor/cache files (personal)
.vscode/
*.swp
```

## Requirements

- Silverstripe Framework ^6
- Silverstripe Admin ^3
- Silverstripe CMS ^6
- Silverstripe Assets ^3
- Silverstripe Versioned ^3
- clesson-de/silverstripe-dashboard ^1
- clesson-de/silverstripe-markdown ^1
- clesson-de/silverstripe-hints ^1
- clesson-de/silverstripe-gridfield-pro ^2
- lekoala/silverstripe-encrypt ^5
- PHP ^8.5 with cURL extension

## License

This project is licensed under the BSD-3-Clause license. See [LICENSE](LICENSE) for the full text.
