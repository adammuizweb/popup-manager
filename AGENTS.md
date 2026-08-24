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
- HTML must pass through the Core restricted HTML sanitizer. Do not add raw
  script, style, iframe, or event-handler execution.
- Excludes override includes. Do not add arbitrary regex targeting without a
  bounded-execution and security review.
- Only public Core media may be used by image campaigns.

## Verification

- Run PHP syntax checks for every PHP file.
- Run JavaScript syntax checks for every JavaScript file.
- Run `php tests/contract.php` and `php tests/security_contract.php`.
- Verify the plugin manifest against canonical Jyavani Core.
- Test image and HTML campaigns across desktop and mobile viewport widths.
- Test every frequency mode, exact/prefix targeting, schedules, keyboard focus,
  Escape, overlay close, and campaign priority.
