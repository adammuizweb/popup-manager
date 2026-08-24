# Popup Manager Plugin

This repository is the authoritative source for the standalone Jyavani Popup
Manager plugin.

## Boundaries

- Keep product changes inside this repository. Do not patch Jyavani Core or a
  site theme to implement campaign behavior.
- Use the `jpm_` prefix for PHP functions, constants, settings, tables, browser
  storage keys, and audit events.
- Keep `plugin.json` at the package root and static destinations below
  `static/plugins/popup-manager/`.
- Never hardcode a customer domain, campaign image, destination, admin path,
  login path, registration path, or homepage template.
- Public requests must never create or migrate database tables.
- Restricted HTML must pass through the Core sanitizer. Site Owner-only trusted
  embed HTML may retain iframe, style, video, and audio, but must still remove
  script, event handlers, srcdoc, unsafe URL schemes, and executable CSS.
- Excludes override includes. Do not add arbitrary regex targeting without a
  bounded-execution and security review.
- Only public Core media may be used by image campaigns.
- Engagement statistics must remain aggregate. Never store visitor IPs, user
  agents, cookie IDs, account IDs, or route histories for popup events.
- Runtime queues are bounded to ten campaigns and must skip ineligible entries
  without blocking later campaigns.

## Verification

- Run PHP syntax checks for every PHP file.
- Run JavaScript syntax checks for every JavaScript file.
- Run `php tests/contract.php` and `php tests/security_contract.php`.
- Verify the plugin manifest against canonical Jyavani Core.
- Test image and HTML campaigns across desktop and mobile viewport widths.
- Test every frequency mode, exact/prefix targeting, schedules, keyboard focus,
  Escape, overlay close, semantic page types, queue order, event deduplication,
  bulk actions, and pagination.
