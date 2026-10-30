# Frontend Overlays

[Back to the module README](../../README.md)

Display CMS-managed notices over frontend content without changing project
templates. The CMS calls them **Overlays**; internal identifiers still use
`Toast`/`toasts` for compatibility.

## Managing overlays

An introductory hint above the list explains the purpose and all targeting options.
The overlay editor groups fields into **Content** (title, message, active),
**Appearance** (type, position, autohide, close button) and **Filters**
(environment, IP addresses, user groups, start/end date and time).
Open **Developer Tools > Overlays** to create, edit or delete notices. All four
CMS operations require `ADMIN`; matching visitors can see the rendered
overlays without gaining access to the underlying CMS records. Anonymous visitors
only see overlays without a user-group restriction. The active state
can also be toggled directly in the list.
The **Active** column appears immediately before **Created** and uses a clickable
checkmark (active) or cross (inactive), with an accessible status label.
`GridFieldRecordDates` from `clesson-de/silverstripe-gridfield-pro` combines creation
and modification into that **Created** column using the signed-in member's date
and time preferences, without seconds. A smaller **Modified** line appears only
when the timestamps differ at minute precision; separate Created/LastEdited
columns are not displayed.
For changes on the creation day, the second line shows **Modified at** and
only the time instead of repeating the date.
Full timestamps use a comma between date and time. The German same-day line
reads, for example, **Geändert um 15:06 Uhr**.
Clicking the combined column heading sorts overlays by `LastEdited`, ascending
or descending, regardless of which dates are displayed.
The **Filters** column shows a globe for configured IP rules, a group symbol
for linked user groups and a clock when either start or end date is set.
Only configured filters appear; icons have descriptive tooltips and accessible
labels, are not clickable and do not display addresses or group names.
The environment column displays Dev and/or Live badges; **All environments**
shows both badges.
The combined **Position** column uses a 3×3 grid icon with the selected anchor
highlighted in blue. A tooltip and accessible label also describe the position.
Title and message share a column without a heading: the optional title appears
in bold above the full plaintext message. A 5px vertical bar spans both lines,
coloured blue for info, yellow for warning and red for alert.
Type and autohide duration remain editable in the detail form but do not have
separate overview columns. Horizontal and vertical position selectors appear
side by side in the **Appearance** tab.

| Setting | Choices and default |
|---|---|
| Title | Optional plain text (up to 200 characters); no HTML or Markdown rendering |
| Type | Info (default), warning, alert |
| Environment | All (default), development only, live only |
| IP addresses and ranges | Empty by default: everyone; otherwise any matching entry |
| User groups | Optional multiple selection; empty means everyone, otherwise signed-in members of any selected group, including subgroups |
| Message | Required Markdown, using `clesson-de/silverstripe-markdown` |
| Autohide after | Whole seconds, default **5**; **0** disables automatic dismissal |
| Removable | Enabled by default; controls the manual close button |
| Horizontal position | Left, centre, right (default) |
| Vertical position | Top (default), centre, bottom |
| Active | Enabled by default |
| Start date and time | Optional; display from this time onward, or immediately if empty |
| End date and time | Optional; hide from this time onward, or continue indefinitely if empty |

## Visibility and scheduling

All configured restrictions must match together. Multiple IP entries are
alternatives, as are multiple selected user groups; matching any one entry
within each filter is sufficient. With no IP rules or selected groups, that
filter places no restriction on the audience.

No field needs to be unique. The `test` environment only displays notices set
to **All environments**. Disabling manual dismissal does not disable autohide;
set the duration to 0 as well if a notice must remain visible.
Scheduling uses the application's configured timezone and requires **Active** to
remain enabled. An end date before the start date is rejected. Time restrictions
are evaluated on each page request; an already-open page is not refreshed.
Active, environment-matching scheduled notices also disable shared caching
outside their display period, preventing cached pages from hiding future notices.
Group restrictions are combined with the environment, IP and scheduling rules.
Administrators do not bypass the audience filter: they also need membership in
a selected group. Group-restricted candidates disable shared caching even for
anonymous visitors or non-matching members; group names are not sent to the frontend.

The title is independent of the type. Leaving it empty displays no heading;
the severity name is never used as a fallback. Each matching active record is
displayed once in its configured type. Separate records with identical content
are independent notices; deactivate unwanted copies in the list.

IP restrictions accept IPv4 and IPv6 addresses, CIDR networks and inclusive
start-end ranges, separated by commas or new lines. For example:

```text
192.0.2.10
198.51.100.0/24, 2001:db8::/32
203.0.113.10 - 203.0.113.20
2001:db8:1::10 - 2001:db8:1::ff
```

Range endpoints must use the same address family and be in ascending order.
The middleware uses Silverstripe's normalized request IP. Behind a reverse
proxy, configure `SS_TRUSTED_PROXY_IPS` correctly; arbitrary visitor-supplied
forwarding headers are not trusted by this module. IP restrictions are delivery
rules, not authentication for confidential information.

## Rendering and interaction

`Clesson\Silverstripe\DeveloperTools\Middleware\ToastMiddleware` implements
`HTTPMiddleware` and is registered on `Director` outside the core cache-control
middleware. No PageController extension or project-template include is needed.
Only complete, successful frontend HTML documents served by GET are augmented.
CMS (including its configured base URL), Security/login, dev, API, GraphQL,
Developer Tools API, AJAX, downloads, compressed responses and error pages remain
unchanged. Fragments are not augmented.

The Markdown field's generated HTML is sanitized for frontend use. Formatting,
ordinary links, images and tables are supported; scripts, event handlers,
executable URLs, embedded frames and arbitrary styling are not retained.
Only matching overlays are serialized into the page; IP rules, group names and
excluded messages are not exposed as client data.

Notices are fixed over the page and grouped by their horizontal/vertical
position, with stable creation/ID order inside each group. Each group has a
bounded scroll area, so long or numerous messages cannot overlap other groups.
Below 56rem viewport width or 38rem height, positions give way to a single
scrollable stack. Uncovered page content remains clickable.

Autohide pauses while the notice is hovered or focused and while the browser
tab is hidden, then resumes with its remaining duration. Closing a notice
applies only to the current page; reloading displays it again. No dismissal
state is stored in cookies or browser storage. Without JavaScript, notices
remain readable, but automatic/manual dismissal is unavailable.

## Caching and deployment

When any active notice targets the current environment, eligible frontend
responses use **`Cache-Control: private, no-store`**, even when the visitor's IP
or group membership does not match, or the current time falls outside the
configured display period. Existing ETag/Last-Modified validators are removed to
prevent reuse across visitors. With no environment-matching active notices,
the existing page and cache policy are preserved.

**Purge existing full-page caches when enabling or changing notices and bypass
CDN/proxy/static page caches while notices are active.** Responses served before
PHP runs cannot be filtered by middleware. Shared caching is intentionally
sacrificed for correct audience-specific, scheduled delivery; assets can still
be cached normally.

The CMS calls this feature **Overlays**. Internal PHP classes, relations,
database tables and asset filenames retain their `Toast`/`toasts` names for
compatibility; the display-name change does not rename existing data.

For rebuilding the overlay JavaScript and styles, see
[Frontend Asset Development](../../README.md#frontend-asset-development).
