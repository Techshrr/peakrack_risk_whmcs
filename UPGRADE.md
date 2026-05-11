# PeakRack Risk Upgrade Notes

## 1.2.0

Operations and scaling release for WHMCS 9.x / PHP 8.3.

### Added

- Top-right admin language switch, matching the PeakRack Popup module layout.
- Top-right WHMCS Activity Log mirror-level control.
- Recent 7-day and 30-day risk metrics for pass, review, fraud, whitelist, and average score.
- Diagnostics panel for module tables, `rule_version` migration, WHMCS token helpers, `localAPI`, `logActivity`, latest decision, latest audit row, and current rule version.
- JSON configuration export and import tools.
- Product ID and payment method whitelists.
- Trust strategy settings for client account age, paid invoice history, and verified email.
- Localized, human-readable risk reason display in the admin decision table and order detail panel.
- Additive schema migration checks for existing module tables.

### Changed

- Bottom Recent Decisions and Recent Audit Logs now load up to 100 rows inside constrained scroll areas instead of growing the page indefinitely.
- Automatic `AfterFraudCheck` processing skips repeat order actions when the same rule version already processed the order. Manual tools can still intentionally re-run actions.
- Decision rows now store the active rule version when the `rule_version` column is available.
- Addon version bumped to `1.2.0`.

### Upgrade Notes

- Existing settings are preserved and normalized with the new defaults.
- Run the addon upgrade or activation flow so the `mod_peakrack_risk_decisions.rule_version` column is added.
- Review the new whitelist and trust strategy fields before enabling them in production.
- JSON import replaces the active settings snapshot after validation and normalization.

## 1.1.0

Operational tooling release for WHMCS 9.x / PHP 8.3.

### Added

- WHMCS Activity Log mirroring level: mirror all audit entries, warnings and errors, errors only, or disable mirroring.
- Admin order details risk panel injected on `orders.php?action=view&id=...`.
- Manual order tools on the addon page:
  - score only
  - apply configured rules
  - force `PendingOrder`
  - force `FraudOrder`

### Changed

- Automatic fraud-check hook now uses the same order processing helper as manual actions.
- Informational audit entries remain in the addon audit table by default, while only warnings and errors are mirrored to WHMCS Activity Log.
- Addon version bumped to `1.1.0`.

### Upgrade Notes

- Existing settings are preserved.
- Review the new **WHMCS activity log** setting after upgrading.
- Force actions intentionally call WHMCS `localAPI`; test them on a non-critical order before using them in production.

## 1.0.1

Stability and production-safety release.

### Added

- Request-level settings cache.
- Checkout acknowledgement compatibility for dynamic and custom order forms.
- Server-side threshold normalization.
- Structured `localAPI` error handling.
- Audit log and rule version retention settings.
- Daily cron cleanup for old audit logs and rule snapshots.

### Changed

- New installations default to `Log only` mode.
- Checkout acknowledgement is scoped to the current server nonce.
- Repeated-order and history checks reduce unnecessary large-table counting.

### Upgrade Notes

- Existing saved settings are preserved; the safer default only affects new installations.
- Run a full checkout test after upgrading, especially if the site uses a custom order form template.
