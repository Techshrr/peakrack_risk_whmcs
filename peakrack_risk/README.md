# PeakRack Risk for WHMCS

PeakRack Risk is a WHMCS addon module for order risk review and checkout acknowledgement.
It packages post-fraud-check scoring, configurable review rules, checkout security notices,
and audit logging into a managed WHMCS addon.

## Features

- Runs after the WHMCS fraud check and calculates an additional order risk score.
- Supports configurable review and fraud thresholds.
- Moves risky orders to `Pending` for manual review.
- Optionally marks high-risk orders as `Fraud` when automatic fraud action is enabled.
- Shows a checkout acknowledgement notice before order submission.
- Supports server-side checkout acknowledgement validation with nonce protection.
- Provides editable Chinese and English checkout notice copy.
- Includes English and Chinese admin UI labels.
- Stores decision history, audit logs, and rule version snapshots.
- Prunes old audit logs and rule snapshots during the WHMCS daily cron.
- Controls how much audit data is mirrored into WHMCS Activity Log.
- Displays a PeakRack Risk panel on WHMCS admin order detail pages.
- Provides manual tools to rescore an order or intentionally run controlled order actions.
- Shows recent 7-day and 30-day risk metrics.
- Provides diagnostics for schema, hook activity, rule version, and WHMCS helper availability.
- Supports JSON settings import/export.
- Skips repeated automatic order actions when the same rule version already processed the order.
- Keeps audit data when the addon is deactivated.

## Compatibility

- WHMCS 9.x
- PHP 8.3
- MySQL/MariaDB supported by WHMCS

The module uses WHMCS `Capsule`, `localAPI`, addon module lifecycle functions, and standard hook registration.

## Installation

1. Upload this `peakrack_risk` folder to your WHMCS installation:

   ```text
   modules/addons/peakrack_risk
   ```

2. In the WHMCS admin area, go to:

   ```text
   System Settings > Addon Modules
   ```

3. Activate **PeakRack Risk**.

4. Open:

   ```text
   Addons > PeakRack Risk
   ```

5. Review thresholds, weights, allowlists, checkout notice text, and admin language.

## Configuration

### General Controls

- **Enable risk engine**: Enables or disables post-fraud-check scoring.
- **Enable checkout notice**: Shows the checkout acknowledgement modal.
- **Require server acknowledgement**: Requires a valid acknowledgement field and nonce during checkout submission.
- **Log only**: Records decisions without changing order status.
- **Allow automatic FraudOrder**: Allows the addon to call `FraudOrder` for high-risk orders.
- **Admin language**: Switches addon admin page labels between English and Chinese.
- **WHMCS activity log**: Controls whether info, warning, error, or no addon audit entries are mirrored to WHMCS Activity Log.

### Thresholds

- **Review threshold**: Orders at or above this score are moved to `Pending`.
- **Fraud threshold**: Orders at or above this score are treated as high-risk. The review threshold is kept at or below this value.
- **API retries**: Number of retries for WHMCS `localAPI` order actions.
- **IP burst window**: Lookback window for repeated orders from the same IP.
- **IP burst count**: Number of recent orders from the same IP required to add burst risk.

### Retention

- **Audit retention days**: Deletes audit logs older than this many days during daily cleanup. Use `0` to disable age cleanup.
- **Max audit logs**: Keeps only the newest audit log rows by count. Use `0` to disable count cleanup.
- **Max rule versions**: Keeps only the newest rule snapshots. Use `0` to disable count cleanup.

### Lists

- High-risk countries
- Trusted email domains
- Whitelisted client IDs
- Whitelisted client group IDs
- Whitelisted email domains
- Whitelisted IP/CIDR entries
- Whitelisted product IDs
- Whitelisted payment methods, using WHMCS gateway module names

### Trust Strategy

- **Trusted client age days**: Applies the client-age trust weight after an account reaches the configured age. Use `0` to disable.
- **Trusted paid invoice count**: Applies the paid-invoice trust weight after a client has the configured number of paid invoices. Use `0` to disable.
- **Trust verified email**: Applies the verified-email trust weight when the WHMCS client record exposes an email verification flag.

### Risk Weights

Each signal has a configurable weight. Positive values increase risk. Negative values reduce risk.

Current signals include:

- Provider fraud result
- Short email username
- Numeric email username
- High-risk country
- Same-IP order burst
- Previous fraud history
- Active service trust reduction
- Client age trust reduction
- Paid invoice history trust reduction
- Verified email trust reduction

### Checkout Notice

The checkout notice can be edited directly from the addon admin page.

Editable fields:

- Title
- Introduction
- Bullet points
- Note
- Button text
- Validation message

Both Chinese and English notice text are supported.

### Manual Order Tools

The addon admin page can rescore an order by WHMCS order ID. It can also apply the configured rules or deliberately run `PendingOrder` or `FraudOrder` through WHMCS `localAPI`.

Manual actions can intentionally re-run order actions. Automatic hook processing skips a repeat run when the order already has a decision saved with the current rule version.

### Diagnostics and Config Tools

The addon admin page includes:

- Recent 7-day and 30-day decision metrics.
- Database and helper diagnostics for common upgrade or environment issues.
- Read-only JSON export of the normalized active configuration.
- JSON settings import with normalization before saving.

## Runtime Hooks

The addon registers these WHMCS hooks from `hooks.php`:

- `ClientAreaFooterOutput`: Injects the checkout acknowledgement modal on the checkout page.
- `ShoppingCartValidateCheckout`: Validates checkout acknowledgement server-side.
- `DailyCronJob`: Cleans up old audit logs and rule snapshots according to retention settings.
- `AdminAreaFooterOutput`: Injects the order details risk panel in the WHMCS admin area.
- `AfterFraudCheck`: Scores the order after WHMCS fraud checks complete.

## Database Tables

The addon creates these tables during activation:

- `mod_peakrack_risk_settings`
- `mod_peakrack_risk_rule_versions`
- `mod_peakrack_risk_audit_logs`
- `mod_peakrack_risk_decisions`

Tables are not removed on deactivation so audit history and decision records are preserved.

Upgrades run additive schema checks and add missing module columns, including the decision `rule_version` field used by repeat-execution protection.

## Project Structure

```text
peakrack_risk/
  peakrack_risk.php       Addon entrypoint, activation, admin page, table creation
  hooks.php               WHMCS hook registration
  README.md               Module documentation
  lib/
    AdminLang.php         Admin UI language strings
    Bootstrap.php         Defaults, settings load/save, normalization, audit logging
    Checkout.php          Checkout modal generation
    RiskEngine.php        Risk scoring and order action helpers
```

## Safety Notes

- Automatic fraud marking is disabled by default.
- New installations default to `Log only` mode; existing saved settings are preserved during upgrades.
- Use `Log only` mode first when testing in production.
- Manual force actions call WHMCS `localAPI`; test them on a non-critical order before production use.
- Do not keep older standalone versions of the original hook files enabled at the same time, or hooks may run twice.
- Always test checkout flow after changing notice text or server acknowledgement settings.

## Upgrade Notes

See [UPGRADE.md](UPGRADE.md) for release-by-release upgrade details.

## Development Checks

Run PHP syntax checks before packaging:

```powershell
Get-ChildItem -Path peakrack_risk -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected result: no syntax errors.

## License

This project uses a custom source-available license. See the repository `LICENSE` file for the full terms.
