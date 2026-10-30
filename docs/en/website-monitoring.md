# Website Monitoring

[Back to the module README](../../README.md)

Monitor customer website inventories, release versions and health from a central
installation. Open **Developer Tools > Monitoring** (requires `ADMIN` permission):

- **Info** shows versions, health, expandable details and manual checks.
- **Configuration** manages websites, credentials and history.
- **Notifications** configures automatic check summaries.

The central installation and customer installations use the same module.
Enable the protected endpoint on each customer website, then
[configure its URL and credentials centrally](#credentials-and-configuration).
Customer websites do not have to poll themselves. A shared introductory hint
above the monitoring tabs explains this workflow.

## Contents

- [Automated JSON access](#automated-json-access)
- [Local and project health checks](#local-and-project-healthchecks)
- [Customer website monitoring](#customer-website-monitoring)
- [HTTP-to-HTTPS redirect check](#http-to-https-redirect-check)
- [Credentials and configuration](#credentials-and-configuration)
- [History and change detection](#history-and-change-detection)
- [Version overview](#version-overview)
- [Manual and scheduled checks](#manual-and-scheduled-checks)
- [Local development targets](#local-development-targets)

## Automated JSON Access

The endpoint is `GET /developer-tools/project-information`. It uses a Bearer
token, not a CMS login, a password prompt, or an Origin/Referer allowlist.
Origin and Referer headers can be spoofed by automated clients and are not
authentication.

Generate a separate random token for each project, for example:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Set the generated value in the server environment or the project's uncommitted
`.env` file:

```dotenv
DEVELOPERTOOLS_API_TOKEN="<generated-token>"
```

Keep it out of source control, URLs and logs. Configure the same token in the
calling system's secret storage. Use HTTPS and ensure the web server forwards
the `Authorization` header to PHP. Rotate the token by replacing it on both
the server and the calling system. This installation's endpoint token is not
managed in the CMS. Credentials for monitored remote websites are stored
encrypted in the central installation as described below.

Example request (the client environment variable contains the configured token):

```bash
curl --fail-with-body \
  --header "Authorization: Bearer ${DEVELOPERTOOLS_API_TOKEN}" \
  https://example.com/developer-tools/project-information
```

Example response (version values vary by project):

```json
{
  "schema_version": 2,
  "inventory": {
    "php_version": "8.3.0",
    "silverstripe_version": "6.1.0",
    "database_version": "10.11.0-MariaDB",
    "database_product": "mariadb",
    "composer_packages": [
      {
        "name": "silverstripe/framework",
        "version": "6.1.0"
      }
    ]
  },
  "health_checks": [
    {
      "id": "runtime.live",
      "title": "Live mode",
      "status": "ok",
      "message": "Production installations should run in live mode.",
      "checked_at": "2026-10-26T12:00:00Z"
    }
  ]
}
```

`composer_packages` is read from Composer's `InstalledVersions` metadata, not
`composer.lock`. It includes installed direct and transitive dependencies,
including development dependencies only when installed. The root project and
purely virtual/provided/replaced packages without an installed version are
excluded. Versions retain Composer's readable format, including `v` prefixes
and development branch names. No installation paths or repository URLs are exposed.
The CMS project information tab displays the inventory and a separate system
health table.

`database_product` identifies `mariadb`, `mysql`, or `unknown`. Monitoring can read
older responses without this field, but only evaluates database releases when
the product can be identified unambiguously. `silverstripe_version` remains the
framework version; the CMS version is taken from `silverstripe/cms` in the
package inventory.

Responses use `application/json` and `Cache-Control: private, no-store`.
An unset, empty or whitespace-only token disables the endpoint (`503`).
Missing or invalid Bearer credentials return `401`, including when the caller
has a CMS session. Authenticated methods other than GET return `405`.
Version lookup failures are errors rather than empty or fabricated versions.

## Local and Project Healthchecks

The protected API uses **schema version 2**, separating `inventory` and
`health_checks`. **Upgrade the central monitoring installation before customer
endpoints**: older monitoring versions reject this new envelope. The updated
monitor accepts both version 2 and the original flat inventory (also with
`schema_version: 1`). Legacy endpoints show health as not assessable.
Other API clients must read versions/packages from `inventory` now.

Built-in checks:

- Live mode: warns for dev/test installations; this is expected locally.
- HTTPS: observes the current web request, not a full TLS audit. CLI execution
  reports not assessable. Reverse proxy forwarding must be configured correctly.
- Webroot separation: checks the configured local public directory and the
  presence of `.env`, `.git`, Composer files, `vendor` and `app/src` within it.
  It **does not prove that the actual HTTP server blocks sensitive paths**.
  External HTTP access-control checks are not included in this release.
- Node build requirements: discovers `.nvmrc` files throughout the project.
  Numeric versions are compared against the official Node release schedule:
  `https://raw.githubusercontent.com/nodejs/Release/main/schedule.json`.
  Support reaching its end date produces a warning. Aliases such as `lts/*`,
  invalid files, unknown versions, future releases and unavailable support data
  are not assessable. No Node executable is run; the requirement is **not proof
  of the version used by the build**.

There is no required or default `.nvmrc` location. The search includes nested
directories but skips directories named `assets` or `vendor` (at any level)
and their entire subtrees. Symlinks are not followed. Each discovered file
gets an independent result with its project-relative path and a stable
path-derived ID. If none are found, the Node check is omitted entirely and
does not make the overall health status unknown.

Search is bounded to two seconds, 20,000 visited entries and 20 requirement
files, within the shared healthcheck budget. Unreadable directories or exhausted
limits produce an explicit incomplete-search outcome rather than a false
all-clear. Discovered files that cannot be read or assessed remain unknown.
The previous `nvmrc_files` configuration is no longer needed or used.

File reads are confined to the project, including symlink resolution, and
limited to 128 bytes. Absolute paths and complete file contents are not
published. Node schedules are cached for six hours; failed lookups are
explicitly not assessable and retried after one hour, never shown as healthy
using stale data. Only the fixed public schedule is requested; no filenames,
project inventory or credentials are sent to the provider.

### Custom project checks

Implement `Clesson\Silverstripe\DeveloperTools\HealthChecks\HealthCheck` in
project PHP code. Its `run(float $deadline): array` method returns a list of
results created with `HealthReport::singleton()->result($id, $title, $status,
$message)`. Use globally unique stable IDs, translated titles/messages and
`HealthStatus::OK`, `WARNING`, `ERROR` or `UNKNOWN`. The helper adds a UTC
`checked_at` timestamp and validates the wire format.

Register the class in project YAML:

```yaml
Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService:
  checks:
    import: 'App\HealthChecks\ImportHealthCheck'
```

Entries extend the built-in checks and are resolved through Injector. Only
trusted PHP implementations are supported, not CMS shell commands or arbitrary
URLs. Each check must use explicit I/O timeouts and honour the absolute deadline
(`microtime(true)`). The collector has a **cooperative 12-second total budget**:
late results are replaced by an explicit unknown outcome, and subsequent checks
are skipped. This is **not a sandbox or hard interruption** of project code.
Unexpected implementation exceptions propagate; custom checks must report
expected service outages using safe error/unknown results themselves.

Reports allow at most 50 unique check IDs, 200-byte titles, 1000-byte plain-text
messages and valid UTC timestamps. Never return passwords, tokens, personal
data, absolute filesystem paths, raw exception messages or remote response
bodies. Collection runs only after API authentication or in the ADMIN-only CMS.

### Health history and monitoring signals

Health outcomes are stored on **each monitoring attempt**, including unchanged
inventory fetches. Changes to health status or `checked_at` do not create
inventory snapshots. The attempt viewer and expandable Info table display
the health report; failed fetches do not reuse old remote health results as
current. The independently observed HTTP redirect outcome is still recorded
and displayed when an API fetch fails.

The Info list and dashboard show two independent icons: **version threshold**
first, **health** second (each has a descriptive tooltip). A single health error
is red regardless of the 50% version rule; warnings are yellow. Missing or
partially unassessable results are gray unless a warning/error takes priority.
Fetch status and scheduled-task exit status continue to describe successful
retrieval, not whether the returned health report passed.

## Customer Website Monitoring

The following sections cover checks, configuration and history in the central
installation. While [offline mode](offline-mode.md) is active on a customer
website, monitoring receives HTTP 503 unless its source IP is excepted.

### HTTP-to-HTTPS redirect check

Each manual check and each automatic check of an enabled website performs an
independent, credential-free HTTP GET to the configured host and installation
path on **port 80**. A configured HTTPS port is not reused as the HTTP port.
This central observation does not require an API update on the monitored site.

| Result | Meaning |
|---|---|
| OK | The first HTTP response has status 301, 302, 303, 307 or 308 and exactly one valid, absolute HTTPS `Location` |
| Warning | HTTP responds without a direct HTTPS redirect, including HTTP-only intermediate redirects, missing/invalid targets and ordinary 2xx/4xx/5xx responses |
| Not assessable | Destination/DNS validation fails, port 80 is unreachable, the request times out or complete bounded response headers cannot be read |

Canonical host changes, such as a redirect to `www`, are allowed. Redirects are
**not followed**, and the HTTPS target is not fetched or DNS-resolved. The check
does not certify the target's availability, TLS certificate or all website paths.
Browser HSTS is not a substitute for a server-side redirect on port 80.
Only one probe is attempted, with no retries.

The probe reuses the monitoring DNS/IP policy and existing exact-host development
exceptions, pins the validated destination, disables ambient proxies and netrc,
and never sends the inventory Bearer token or cookies. Connection establishment
is limited to 3 seconds and the cURL transfer to 5 seconds; DNS prevalidation
uses the system resolver's limits. Header processing is bounded to 16 KiB,
and the transfer stops at the end of the final response headers without retaining
the response body. The additional probe runs under the existing website lock.
The monitoring server therefore needs outbound HTTP access to port 80.

Results are saved separately on each immutable attempt, not in inventory
snapshots. They contribute to the existing health indicator in Info and the
dashboard, appear in attempt details/history and are summarized in notification
emails, including when the API fetch fails. A redirect warning does not turn a
valid inventory into a fetch failure. Older attempts contain no retroactive
redirect observation. Remote reports retain their 50-result limit; the combined
view can include one extra central result. The ID
`monitoring.http_https_redirect` is reserved for that result and must not be
returned by remote health implementations.

### Credentials and configuration

Only CMS administrators can configure websites, view snapshots and run checks.
The central installation requires an `ENCRYPTION_KEY` for
`lekoala/silverstripe-encrypt`. Generate it using that module's
`LeKoala\Encrypt\EncryptHelper::generateKey()` and store it in the server
environment or uncommitted `.env`. Back up the key separately from the database;
losing or changing it without a proper key migration makes saved tokens unusable.
Never commit keys or customer tokens.

In **Monitoring / Configuration**, add a name, the HTTPS base URL and the
customer endpoint's API token. Base URLs are normalized and unique. A base URL
can include the installation subdirectory; the fixed
`developer-tools/project-information` endpoint is appended automatically.
Leave the token replacement field blank when editing to keep the saved token.
Existing credentials are never filled back into the form. Deactivate websites
instead of deleting them, so their history remains available.

The configuration list displays the first and last attempted checks and the last
successful inventory fetch. A failed check advances the attempt timestamps, not
the last success time.

### History and change detection

The first valid response creates a snapshot. Subsequent valid responses create
another snapshot only when a version, package membership or database product
changes relative to the most recent snapshot. Returning to an earlier version
therefore also creates a new record.

The stored inventory is validated and canonicalized; JSON whitespace, object key
order and package order do not create changes. Optional response timestamps are
not part of inventory equality. Unknown fields are rejected rather than silently
discarded. Failed requests or invalid responses never replace the last valid
inventory.

Open a website's detail form to view immutable snapshots and the separate record
of every check attempt. Snapshots contain a readable inventory and saved JSON.
There is no automatic history deletion. The snapshot timestamp records when the
changed state was **observed**, not the exact deployment time.

### Version overview

In **Monitoring / Info**, click a row or its keyboard-accessible Details button.
The expanded row contains a plain HTML table, not a nested GridField:

| Item | Current version | Latest stable version | Status |
|---|---|---|---|
| PHP, Silverstripe CMS, database, each Composer package | Last valid inventory | Latest known stable release | Icon and label |

Green means not behind the latest stable release; yellow means a newer stable
release is available. Red indicates a failed or expired release lookup. Gray
means not assessable, for example an unknown database product, a development
branch, a missing CMS package or a private package without public release data.
Website fetch errors remain visible alongside the last successful inventory.

The overview's **Action required** column shows a warning when **more than 50%**
of assessable components have an update available; exactly 50% does not trigger
it. Only current/update comparisons count, excluding unknown, failed and stale
lookups. Each component counts once (the CMS package is not counted twice).
A green check means the threshold is not exceeded, not that every component is
current. Without any assessable components, a gray question mark is shown.
The indicator uses cached metadata and refreshes after a manual check.

**Latest stable does not mean compatible.** It may require a major upgrade or
changes to hosting and Composer constraints. This feature does not perform
dependency solving, security advisory checks, support/EOL assessment, automated
updates or notifications.

Public release sources:

- PHP: `https://www.php.net/releases/index.php?json`.
- Composer: `https://repo.packagist.org/p2/{vendor}/{package}.json`.
- MariaDB: `https://downloads.mariadb.org/rest-api/mariadb/`, followed by releases
  from its newest branch explicitly marked Stable.
- MySQL: numeric server release tags in Oracle's official
  `mysql/mysql-server` repository via GitHub's matching-refs API; cluster and
  prerelease tags are excluded.

Other database products are explicitly not assessable. Private package
repositories are not queried. Public package lookups send package names to
Packagist, but never customer tokens or complete inventories.

Release metadata is cached across websites for six hours; failed lookups are
retried after one hour. Cached errors and stale data are never reported as
current. The table includes the source and last lookup time (UTC).
Refreshing release data has a bounded per-check work budget: inventories with
many uncached packages may require additional checks to populate all rows.
Opening an expanded row does not trigger remote requests.

### Manual and scheduled checks

In **Monitoring / Notifications**, select the members who should receive summaries,
as in the Scheduler module. An empty selection disables notifications. Recipients
use their current member email address at send time; selecting a recipient does
not grant CMS access or administrator permissions.

**Upgrading from email-address recipients:** run `vendor/bin/sake db:build --flush`
to create the member relation, then select and save the recipients again.
Previously entered email addresses are not migrated and are no longer used.

Each completed automatic task run sends
one concise email listing all configured projects and their current run status;
disabled projects are marked as not checked. Manual **Check now** actions never
send email. The subject counts projects needing action: failed fetches, the
existing version threshold, or an individual health warning/error. Without
actionable findings but with unavailable assessments, the subject explicitly
reports an incomplete assessment rather than an all-clear.

Member email addresses are validated at send time; duplicate addresses are removed
case-insensitively. A missing or invalid address fails the task explicitly instead
of silently skipping that member. Deleted members no longer receive notifications.
Delivery uses the
application's standard Silverstripe mail transport and default sender
(`SilverStripe\Control\Email\Email.admin_email`), which must be configured.
Transport errors fail the task visibly, without rolling back completed checks.
Emails use vertically stacked, color-coded project cards with inline styling
for email clients. The footer identifies the monitoring installation domain
and actual From-address domain, and states that the message is automated and
replies cannot be processed. This notice does not change the configured sender
or set up an inbound-mail rejection service.
An unexpected exception interrupting the checks does not send a completed-run
summary. No tokens, raw health messages or inventory payloads are emailed.

Use **Check now** in the Info list for an individual website. It performs an
administrator-only, CSRF-protected POST.

For regular checks configure an administrator user in project YAML:

```yaml
Clesson\Silverstripe\DeveloperTools\Tasks\MonitoringCheckTask:
  cli_user_email: 'monitoring-admin@example.com'
```

Then run:

```bash
vendor/bin/sake tasks:developertools-monitoring-check
```

Alternatively select this task in `silverstripe-scheduler` and assign its
schedule there. Disabled websites are skipped. One failed website does not
prevent the remaining websites from being checked; the task returns a failure
exit status if any website fails.

Checks use a 30-second customer HTTP timeout (5 seconds to connect), to allow
bounded endpoint health collection; payloads are limited to 1 MiB.
Checks require InnoDB tables and use MySQL/MariaDB database transactions and
per-website row locking, also across workers sharing the same writable primary
database. Invoke checks outside existing application transactions. Other database
backends are not supported for monitoring execution. HTTPS certificates are verified,
redirects are disabled, and timeouts/body limits are enforced. Only public
targets are permitted: private/reserved IPv4 and IPv6 addresses, including local
DDEV targets, are rejected. DNS validation is repeated and the actual connection
is pinned to an approved address. No token is forwarded through a redirect.

### Local development targets

Private targets are blocked by default. To test monitoring against a local DDEV
installation, explicitly allow its exact hostname in project YAML:

```yaml
---
Name: local-developertools-monitoring
Only:
  environment: dev
---
Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL:
  dev_allowed_hosts:
    - 'silverstripe-6-modules-development.ddev.site'
```

Flush configuration after changing this list (`vendor/bin/sake flush`). Entries
are DNS hostnames only, without a scheme, port, path or wildcard. The exception
only works with `SS_ENVIRONMENT_TYPE=dev`; it is ignored in test and live
environments even if configured there. Only the listed hosts may resolve to
RFC1918, loopback or IPv6 unique-local addresses. Link-local addresses (including
cloud metadata endpoints), mapped IPv6 and other reserved ranges remain blocked.
All DNS answers are still validated and the connection is pinned.

HTTPS certificate verification, token authentication and redirect protection
remain enabled. The container must trust the local development certificate
authority; this exception does not accept self-signed or invalid certificates.
Do not add customer or third-party hosts to this local-development list.
