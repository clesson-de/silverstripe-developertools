# silverstripe-developertools

A collection of developer tools for Silverstripe CMS, accessible directly from the CMS admin panel.

## Documentation

| Guide | Contents |
|---|---|
| [Website Monitoring](docs/en/website-monitoring.md) | Website configuration, protected JSON API, health checks, HTTP-to-HTTPS checks, version history, notifications and scheduled checks |
| [Offline Page and Maintenance Mode](docs/en/offline-mode.md) | Automatic default page, editing and static export, activation, scheduling, IP exceptions and operational limits |
| [Frontend Overlays](docs/en/overlays.md) | Notice settings, audience filters, scheduling, positioning, dismissal and caching |

## Features

### Dashboard

- Uses `SingleRecordAdmin` with one shared `DeveloperToolsConfig` record.
- Opens with a configurable dashboard using `clesson-de/silverstripe-dashboard`
  and a local project information panel. Panel content requires a CMS
  administrator session; it does not use or expose the JSON API token.
- Includes a Website Monitoring dashboard panel listing all configured websites
  (including disabled entries) with the same cached action-required icons as Info.
  Existing dashboard layouts can add it through the panel picker; it is included
  by default for new layouts. Loading the panel does not trigger remote checks.

### Website Monitoring

- Manages customer websites, encrypted credentials and inventory histories.
- Shows installed versions, latest stable releases and health indicators.
- Checks HTTP-to-HTTPS redirects independently of the protected inventory API.
- Supports manual checks, scheduled tasks and notification emails.

See the [Website Monitoring guide](docs/en/website-monitoring.md).

### Project Information

- Displays PHP, Silverstripe framework and database server versions in the CMS.
- Uses `ProjectInformationService::getInformation()` for both the CMS and JSON API.
- Reports the PHP runtime serving the request, the installed
  `silverstripe/framework` version (not the CMS recipe version), and the connected
  database server version.
- Does not expose database credentials, environment variables, or `phpinfo()`.
- The JSON API additionally includes installed Composer dependencies with their
  package names and versions, sorted by name.

### Composer Packages

- Lists all installed Composer packages (including dev dependencies)
- Shows name, version, type, license, and description
- Searchable by package name or description
- Sortable columns

### Routes

- Lists registered routes with their controllers, actions and owning packages.
- Searchable and sortable.

### Frontend Overlays

- Manages multiple Markdown notices in a dedicated **Overlays** tab.
- Supports optional plain-text titles and required Markdown content.
- Filters delivery server-side by active state, environment, visitor IP,
  user groups (including subgroups) and optional start/end date and time.
- Supports informational, warning and alert messages at nine fixed positions.
- Provides per-message autohide durations and optional manual dismissal.
- Keeps notices from overlapping, with scrollable stacks and a compact layout.

See the [Frontend Overlays guide](docs/en/overlays.md).

### Offline Mode

- One CMS-editable `OfflinePage`, exported as standalone static HTML when published.
- Database builds create and publish a missing offline page with English starter
  content, without enabling offline mode or overwriting existing pages.
- Administrator-only **Offline mode** tab with an active switch, optional start/end
  timestamps and IPv4/IPv6 address, CIDR and range exceptions.
- Returns HTTP 503 at the original URL before routing, including for APIs and POSTs.
- Keeps the CMS, authentication and resources accessible without an administrator
  automatically bypassing the frontend gate.

See the [Offline Page and Maintenance Mode guide](docs/en/offline-mode.md).

## Installation

```bash
composer require clesson-de/silverstripe-developertools
```

Then run:

```bash
vendor/bin/sake db:build --flush
composer vendor-expose
```

## Usage

After installation, open **Developer Tools** in the CMS left-hand menu
(requires `ADMIN` permission). The tabs are ordered as follows:

1. **Dashboard** with a project information panel.
2. **Monitoring** with **Info**, **Configuration** and **Notifications**.
3. **Overlays** with editable frontend notices.
4. **Offline mode** with the maintenance switch, schedule and IP exceptions.
5. **Analysis** with **Project Information**, **Composer Packages** and **Routes**.

The analysis tools retain their existing functionality. The project information
tab is read-only and works independently of the API token. The shared
configuration record can only be created, viewed and edited by administrators,
and cannot be deleted. Dashboard layout preferences remain per user.

After upgrading, run `vendor/bin/sake db:build --flush` and `composer vendor-expose`
to create the required tables and expose both admin and frontend assets.
The database build also prepares the default offline page and its static export
if none exists. The website remains online until an administrator explicitly
enables offline mode.

## Frontend Asset Development

The module ships compiled assets in `client/admin/dist/` and
`client/frontend/dist/`. The npm project builds the monitoring UI and the
independent frontend overlays. Use Node.js 22 from `.nvmrc` (`nvm use`), then:

```bash
npm install
npm run build
npm test
```

`build:js` uses Rollup for both JavaScript bundles; `build:css` compiles Sass:

| Source | Output |
|---|---|
| `client/admin/src/index.js` | `client/admin/dist/bundle.js` |
| `client/admin/src/scss/main.scss` | `client/admin/dist/bundle.css` |
| `client/frontend/src/toasts.js` | `client/frontend/dist/toasts.js` |
| `client/frontend/src/toasts.scss` | `client/frontend/dist/toasts.css` |

Use `npm run watch:js` and `npm run watch:css` during development. Admin assets
use Requirements; the overlay renderer inserts its separate static asset URLs
after page rendering. Expose rebuilt files with `composer vendor-expose`.
`npm test` covers monitoring interactions and overlay lifecycles.
`npm run test:layout` additionally measures overlap, overflow and responsive
resizing in installed Chrome/Chromium. Set `CHROME_BIN` when the browser is not
in the default macOS Chrome or Linux `/usr/bin/chromium` location.

## Requirements

- Silverstripe Framework ^6
- Silverstripe Admin ^3
- Silverstripe CMS ^6
- Silverstripe Assets ^3
- Silverstripe Versioned ^3
- clesson-de/silverstripe-dashboard ^1
- clesson-de/silverstripe-markdown ^1
- clesson-de/silverstripe-hints ^1
- clesson-de/silverstripe-gridfield-pro ^1
- PHP ^8.5
- PHP cURL extension
- MySQL or MariaDB for central monitoring execution
- lekoala/silverstripe-encrypt ^5 with a configured encryption key

## License

BSD 3-Clause — see [LICENSE](LICENSE).
