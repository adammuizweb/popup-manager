# Popup Manager

Popup Manager is a standalone campaign plugin for Jyavani CMS. It renders a
theme-independent modal from dynamic database content instead of hardcoding a
banner, destination, or route into a site template.

## Features

- Multiple campaigns with deterministic priority selection.
- Responsive image campaigns with desktop, tablet, and mobile media variants.
- Restricted HTML campaigns sanitized by Jyavani Core before storage and again
  before rendering.
- All-page, homepage-only, or exact/prefix path targeting with explicit
  exclusions.
- Start and end scheduling, configurable delay, and modal width.
- Every-view, per-session, per-path session, per-visitor, and daily frequency
  policies implemented in browser storage without personal tracking.
- Keyboard, focus trap, Escape, overlay, and visible-close accessibility.
- Dynamic admin, login, registration, private, installer, API, and static paths
  are excluded from popup targeting.

Only the highest-priority eligible campaign is rendered on a request. Popup
assets are emitted only when a campaign is selected.

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

## HTML Security

HTML mode uses `cms_sanitize_restricted_html()`. Scripts, styles, forms,
iframes, embeds, event handlers, unsafe protocols, and unsupported attributes
are removed. HTML mode is content flexibility, not arbitrary script execution.

## Development

Run the contracts against a canonical Core checkout:

```bash
JPM_CORE_ROOT=/path/to/jyavani php tests/contract.php
php tests/security_contract.php
```

## License

MIT. See `LICENSE`.
