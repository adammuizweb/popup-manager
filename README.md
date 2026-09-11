# Popup Manager

Popup Manager is a standalone campaign plugin for Jyavani CMS. It renders a
theme-independent modal from dynamic database content instead of hardcoding a
banner, destination, or route into a site template.

## Features

- Ordered campaign queues that skip paused, draft, out-of-schedule, unmatched,
  and frequency-capped campaigns.
- Image and HTML carousels with up to ten ordered slides per campaign.
- Responsive image slides with desktop, tablet, and mobile media variants.
- Restricted HTML campaigns sanitized by Jyavani Core before storage and again
  before rendering.
- Rich Text and HTML Code authoring modes backed by the dashboard's existing
  Quill and CodeMirror libraries.
- Site Owner-only Trusted Embed HTML for iframe, style, video, and audio while
  scripts, event handlers, srcdoc, unsafe schemes, and executable CSS remain
  blocked.
- All-page, homepage-only, semantic Jyavani page-type, or exact/prefix path
  targeting with explicit exclusions.
- Start and end scheduling, configurable delay, and modal width.
- Every-view, per-session, per-path session, per-visitor, and daily frequency
  policies implemented in browser storage without personal tracking.
- Keyboard, focus trap, Escape, overlay, and visible-close accessibility.
- Dynamic admin, login, registration, private, installer, API, and static paths
  are excluded from popup targeting.
- Aggregate impression, close, and click counters per campaign and across the
  campaign list.
- Search, filters, bulk status/delete actions, queue controls, configurable
  browser-persisted columns, accessible overflow actions, and pagination in the
  campaign manager.

Up to ten eligible campaigns are emitted in queue order. The browser opens one
at a time and rechecks each campaign's frequency policy before display. Popup
assets are emitted only when at least one campaign is eligible.

Each campaign uses one content type across all of its slides. A one-slide
campaign behaves like earlier releases. Existing campaign rows are read as one
slide, while new saves keep the first slide mirrored in the legacy content
columns for rollback compatibility. Carousel navigation supports buttons,
position dots, and the Left/Right arrow keys.

## Requirements

- Jyavani Core 2.3.74 or newer.
- PHP 8.1 or newer.
- PDO MySQL, JSON, mbstring, and DOM extensions.

## Installation

Install the package as `plugins/popup-manager`. Jyavani publishes the declared
assets to `public/static/plugins/popup-manager/`. Open **Tools > Popup Manager**
once as an authorized administrator to initialize the plugin-owned table.

The public runtime never performs schema DDL and fails closed until the schema
is ready.

## Target Rules

Rules are root-relative and ignore query strings:

- `/admissions` matches exactly that path.
- `/news/*` matches `/news` and every descendant.
- Excludes always override includes.

Arbitrary regular expressions are intentionally unsupported.

Page-type targeting observes semantic Core hooks for homepage, search, 404,
article/page lists and singles, categories, authors, date archives, and Theme
Template pages. Custom plugin routes remain available through exact/prefix path
rules or the generic custom-route page type.

## Statistics

The frontend records at most one impression, close, and click for each rendered
campaign token. Tokens are short-lived, signed, and deduplicated by a stored
SHA-256 token hash. Statistics contain no IP address, user agent, visitor ID,
cookie ID, URL history, or personal profile. Counters are aggregate operational
metrics rather than identity-level analytics.

## HTML Security

HTML mode uses `cms_sanitize_restricted_html()`. Scripts, styles, forms,
iframes, embeds, event handlers, unsafe protocols, and unsupported attributes
are removed. HTML mode is content flexibility, not arbitrary script execution.

Site Owners with `plugin.popup-manager.html.trusted` may select Trusted Embed
HTML. This policy retains iframe/style/video/audio content for cases such as
YouTube and map embeds. It still rejects script, event attributes, `srcdoc`,
credential-bearing URLs, protocol-relative URLs, and executable CSS patterns.
System administrators do not receive this permission by default.

## Development

Run the contracts against a canonical Core checkout:

```bash
JPM_CORE_ROOT=/path/to/jyavani php tests/contract.php
php tests/security_contract.php
```

## License

MIT. See `LICENSE`.
