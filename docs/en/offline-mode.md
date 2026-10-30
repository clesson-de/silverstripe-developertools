# Offline Page and Maintenance Mode

[Back to the module README](../../README.md)

Serve a published, static maintenance page while keeping the CMS and login
accessible. Configure this feature in **Developer Tools > Offline mode**
(requires `ADMIN` permission).

## Setup and activation

1. `dev/build` creates and publishes an **Offline page** with an English default
   maintenance message and a static export if no offline page exists in draft or
   live. It does not enable offline mode or modify/publish any existing page.
   Only one instance is allowed, including drafts. It has no child pages and
   defaults to hidden in navigation and search.
2. Edit the title and rich-text content, then **publish**. Saving a draft does not
   update the exported document. Normal CMS page permissions control editing and
   publishing; the page must be viewable by anyone because its export is public.
3. In **Developer Tools > Offline mode**, enable the switch and save. Activation
   requires a published page with a successful static export.
4. Optionally enter a start/end timestamp and IP exceptions. Blank start means
   immediate; blank end means indefinite. Start is inclusive and end exclusive,
   using the same application datetime convention as overlays. The switch must
   remain enabled for scheduled maintenance to apply.
5. Disable the switch and save to reopen the website. IP exceptions are optional;
   an empty list means no visitors bypass the gate.

## Automatic default page

Both the browser `/dev/build` and CLI `vendor/bin/sake db:build --flush` run the
page's `requireDefaultRecords()` hook. On first creation, it uses the title
**Website maintenance**, requests the URL segment `offline` (subject to normal
CMS URL uniqueness handling), and supplies this English content:

> Our website is temporarily unavailable while we carry out scheduled maintenance.
>
> Please try again shortly. Thank you for your patience.

The starter content is always English, regardless of the build user's language,
and can subsequently be edited or translated manually in the page tree.
The newly created page is published immediately, generating its static export,
but **offline mode remains disabled** unless it was explicitly enabled separately.

Subsequent builds do not change the title, content, publication state or static
export of an existing offline page. An existing unpublished draft also prevents
automatic creation and is not published by the build. After editing an existing
page, publish it through the CMS to refresh the export. A build run with
`--no-populate` skips default-record creation.

## Editing and static export

The page uses a standalone, responsive template with inline styling, not the
website navigation or page layout. It deliberately retains the normal rich-text
`Content` editor even if Elemental is enabled globally on `Page`. Override
`templates/Clesson/Silverstripe/DeveloperTools/Models/OfflinePage.ss` in the active
theme for custom branding. Republish after template changes to regenerate the
static output. Export uses anonymous live-stage data and does not make a nested
HTTP request, so it also works while the maintenance gate is active.

HTML is stored through Silverstripe's `GeneratedAssetHandler` under
`developertools-offline/page-<ID>.html`, like generated ErrorPage files. Export
errors are surfaced; they must be fixed and publication retried. Unpublishing or
deleting the live document removes its export. If it disappears during active
maintenance, the middleware logs an error and returns a generic 503 rather than
reopening the website or exposing unpublished content.

## Request handling and exceptions

The middleware runs after trusted proxy/host handling and before frontend routing.
It preserves the original URL and returns **503 Service Unavailable** with
`Retry-After: 300`, `X-Robots-Tag: noindex, nofollow` and
`Cache-Control: private, no-store`. HEAD responses have an empty body.
GET, AJAX, POST, PUT, DELETE and other dynamic methods are gated; blocked form
submissions do not execute. APIs, GraphQL, monitoring and `/dev/*` are not exempt.
Use CLI for build tasks during maintenance, or an explicitly allowed IP.

The configured admin route, configured login URL, `/Security/*`, `/CMSSecurity/*`,
`/_resources/*` and `/assets/*` retain normal access checks and handling. Login,
logout, password recovery, CMS editing and protected asset authorization continue
to work. Logged-in administrators receive the offline document on the frontend
unless their IP is listed as an exception. Existing monitoring will see 503
unless its source IP is excepted. See the
[Website Monitoring guide](website-monitoring.md) for monitoring configuration.

IP exceptions accept individual IPv4/IPv6 addresses, CIDR networks and inclusive
address ranges, separated by commas/new lines, using the existing overlay parser.
Forwarded client IPs are trusted only through Silverstripe's configured trusted
proxy middleware; configure `SS_TRUSTED_PROXY_IPS` correctly. Exempt and
out-of-window frontend responses also use `private, no-store` while the switch
is enabled, to avoid shared-cache leaks and missed scheduled activation.

**Operational limits:** PHP, Silverstripe startup and the settings database must
remain operational. The static document avoids rendering the live website but
does not provide a webserver-level or database-outage maintenance mechanism.
Files served directly by the webserver are not blocked, and requests already
running are not cancelled. Purge/bypass existing CDN, reverse-proxy and static
page caches when enabling maintenance; responses that never reach PHP cannot
be intercepted. Keep generated assets available to all application instances.
This feature does not alter webserver configuration or activate itself on install.
