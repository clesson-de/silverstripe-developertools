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

## Quick usage

Open **Developer Tools** in the CMS menu (`ADMIN` permission required):

1. **Dashboard**
2. **Monitoring**
3. **Overlays**
4. **Offline mode**
5. **Analysis**

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

BSD 3-Clause - see [LICENSE](LICENSE).
