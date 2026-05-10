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
- Keeps audit data when the addon is deactivated.

## Compatibility

- WHMCS 9.x
- PHP 8.3
- MySQL/MariaDB supported by WHMCS

The module uses WHMCS `Capsule`, `localAPI`, addon module lifecycle functions, and standard hook registration.

## Installation

1. Upload this folder to your WHMCS installation:

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

### Thresholds

- **Review threshold**: Orders at or above this score are moved to `Pending`.
- **Fraud threshold**: Orders at or above this score are treated as high-risk.
- **API retries**: Number of retries for WHMCS `localAPI` order actions.
- **IP burst window**: Lookback window for repeated orders from the same IP.
- **IP burst count**: Number of recent orders from the same IP required to add burst risk.

### Lists

- High-risk countries
- Trusted email domains
- Whitelisted client IDs
- Whitelisted client group IDs
- Whitelisted email domains
- Whitelisted IP/CIDR entries

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

## Runtime Hooks

The addon registers these WHMCS hooks from `hooks.php`:

- `ClientAreaFooterOutput`: Injects the checkout acknowledgement modal on the checkout page.
- `ShoppingCartValidateCheckout`: Validates checkout acknowledgement server-side.
- `AfterFraudCheck`: Scores the order after WHMCS fraud checks complete.

## Database Tables

The addon creates these tables during activation:

- `mod_peakrack_risk_settings`
- `mod_peakrack_risk_rule_versions`
- `mod_peakrack_risk_audit_logs`
- `mod_peakrack_risk_decisions`

Tables are not removed on deactivation so audit history and decision records are preserved.

## Project Structure

```text
modules/addons/peakrack_risk/
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
- Use `Log only` mode first when testing in production.
- Do not keep older standalone versions of the original hook files enabled at the same time, or hooks may run twice.
- Always test checkout flow after changing notice text or server acknowledgement settings.

## Development Checks

Run PHP syntax checks before packaging:

```powershell
Get-ChildItem -Path modules\addons\peakrack_risk -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected result: no syntax errors.

## License

No license has been specified yet. Add a license before publishing if this repository will be public.
