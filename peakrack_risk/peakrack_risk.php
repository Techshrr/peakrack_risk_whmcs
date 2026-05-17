<?php

/**
 * PeakRack Risk addon module for WHMCS.
 *
 * Target runtime: WHMCS 9.x / PHP 8.3.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('No direct access');
}

require_once __DIR__ . '/lib/Bootstrap.php';
require_once __DIR__ . '/lib/AdminLang.php';
require_once __DIR__ . '/lib/RiskEngine.php';
require_once __DIR__ . '/lib/Checkout.php';

function peakrack_risk_config(): array
{
    return [
        'name' => 'PeakRack Risk',
        'description' => 'Professional order risk review and checkout acknowledgement module for PeakRack.',
        'version' => '1.2.1',
        'author' => 'PeakRack',
        'language' => 'english',
        'fields' => [],
    ];
}

function peakrack_risk_activate(): array
{
    try {
        peakrack_risk_create_tables();

        if (!Capsule::table('mod_peakrack_risk_settings')->where('setting', 'config')->exists()) {
            peakrackRiskSaveSettings(peakrackRiskDefaults(), 0);
        }

        return [
            'status' => 'success',
            'description' => 'PeakRack Risk has been activated.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function peakrack_risk_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'PeakRack Risk has been deactivated. Data tables were kept for audit history.',
    ];
}

function peakrack_risk_upgrade($vars): void
{
    peakrack_risk_create_tables();
}

function peakrack_risk_output(array $vars): void
{
    $message = '';
    $messageType = 'success';

    try {
        peakrack_risk_create_tables();
    } catch (\Throwable $e) {
        $message = 'Schema check failed: ' . $e->getMessage();
        $messageType = 'danger';
    }

    $settings = peakrackRiskLoadSettings();
    if (in_array((string) ($_GET['prk_admin_lang'] ?? ''), ['en', 'zh'], true)) {
        $settings['adminLanguage'] = (string) $_GET['prk_admin_lang'];
    }
    $language = peakrackRiskAdminLanguage($settings);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array((string) ($_POST['prk_action'] ?? ''), ['save_settings', 'manual_order_action', 'import_settings'], true)) {
        $language = in_array((string) ($_POST['adminLanguage'] ?? $language), ['en', 'zh'], true)
            ? (string) ($_POST['adminLanguage'] ?? $language)
            : $language;

        if (!peakrack_risk_verify_admin_token()) {
            $message = peakrackRiskAdminText($language, 'token_failed');
            $messageType = 'danger';
        } elseif (($_POST['prk_action'] ?? '') === 'save_settings') {
            $settings = peakrack_risk_settings_from_post($settings);
            peakrackRiskSaveSettings($settings, (int) ($_SESSION['adminid'] ?? 0));
            $settings = peakrackRiskLoadSettings();
            $language = peakrackRiskAdminLanguage($settings);
            $message = peakrackRiskAdminText($language, 'saved');
        } elseif (($_POST['prk_action'] ?? '') === 'import_settings') {
            $importResult = peakrack_risk_import_settings_from_post($settings);
            $message = $importResult['message'];
            $messageType = $importResult['success'] ? 'success' : 'danger';
            $settings = $importResult['settings'];
            $language = peakrackRiskAdminLanguage($settings);
        } else {
            $manualResult = peakrack_risk_handle_manual_order_action($settings);
            $message = $manualResult['message'];
            $messageType = $manualResult['success'] ? 'success' : 'danger';
        }
    }

    echo peakrack_risk_render_admin($settings, $message, $messageType);
}

function peakrack_risk_handle_manual_order_action(array $settings): array
{
    $orderId = (int) ($_POST['manualOrderId'] ?? 0);
    $mode = (string) ($_POST['manualAction'] ?? 'score_only');
    $mode = in_array($mode, array_keys(peakrack_risk_manual_action_options('en')), true) ? $mode : 'score_only';
    $result = peakrackRiskProcessOrder($orderId, [], $settings, $mode, true);
    $language = peakrackRiskAdminLanguage($settings);

    if (!$result['success']) {
        return [
            'success' => false,
            'message' => peakrackRiskAdminText($language, 'manual_failed') . ' ' . (string) ($result['message'] ?? ''),
        ];
    }

    $score = number_format((float) ($result['score'] ?? 0), 2);
    $action = (string) ($result['action'] ?? 'unknown');

    return [
        'success' => true,
        'message' => str_replace(
            ['{order}', '{score}', '{action}'],
            [(string) $orderId, $score, $action],
            peakrackRiskAdminText($language, 'manual_success')
        ),
    ];
}

function peakrack_risk_import_settings_from_post(array $current): array
{
    $language = in_array((string) ($_POST['adminLanguage'] ?? peakrackRiskAdminLanguage($current)), ['en', 'zh'], true)
        ? (string) ($_POST['adminLanguage'] ?? peakrackRiskAdminLanguage($current))
        : peakrackRiskAdminLanguage($current);
    $json = trim((string) ($_POST['settingsImportJson'] ?? ''));

    if ($json === '') {
        return [
            'success' => false,
            'message' => peakrackRiskAdminText($language, 'import_empty'),
            'settings' => $current,
        ];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'message' => peakrackRiskAdminText($language, 'import_invalid'),
            'settings' => $current,
        ];
    }

    if (!array_key_exists('adminLanguage', $decoded)) {
        $decoded['adminLanguage'] = $language;
    }

    $settings = peakrackRiskMergeSettings(peakrackRiskDefaults(), $decoded);
    peakrackRiskSaveSettings($settings, (int) ($_SESSION['adminid'] ?? 0));
    $settings = peakrackRiskLoadSettings();

    return [
        'success' => true,
        'message' => peakrackRiskAdminText(peakrackRiskAdminLanguage($settings), 'imported'),
        'settings' => $settings,
    ];
}

function peakrack_risk_create_tables(): void
{
    $schema = Capsule::schema();

    if (!$schema->hasTable('mod_peakrack_risk_settings')) {
        $schema->create('mod_peakrack_risk_settings', static function ($table): void {
            $table->increments('id');
            $table->string('setting', 100)->unique();
            $table->longText('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    if (!$schema->hasTable('mod_peakrack_risk_rule_versions')) {
        $schema->create('mod_peakrack_risk_rule_versions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('version')->index();
            $table->longText('settings_snapshot');
            $table->unsignedInteger('admin_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    if (!$schema->hasTable('mod_peakrack_risk_audit_logs')) {
        $schema->create('mod_peakrack_risk_audit_logs', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->unsignedInteger('client_id')->nullable()->index();
            $table->string('level', 20)->index();
            $table->string('message', 255);
            $table->longText('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    if (!$schema->hasTable('mod_peakrack_risk_decisions')) {
        $schema->create('mod_peakrack_risk_decisions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('order_id')->unique();
            $table->unsignedInteger('client_id')->index();
            $table->decimal('score', 5, 2);
            $table->string('action', 40)->index();
            $table->unsignedInteger('rule_version')->nullable()->index();
            $table->longText('reasons');
            $table->longText('api_result')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    peakrack_risk_ensure_schema();
}

function peakrack_risk_ensure_schema(): void
{
    $schema = Capsule::schema();

    if ($schema->hasTable('mod_peakrack_risk_settings')) {
        peakrack_risk_ensure_column('mod_peakrack_risk_settings', 'setting', static function ($table): void {
            $table->string('setting', 100)->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_settings', 'value', static function ($table): void {
            $table->longText('value')->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_settings', 'updated_at', static function ($table): void {
            $table->timestamp('updated_at')->nullable();
        });
    }

    if ($schema->hasTable('mod_peakrack_risk_rule_versions')) {
        peakrack_risk_ensure_column('mod_peakrack_risk_rule_versions', 'version', static function ($table): void {
            $table->unsignedInteger('version')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_rule_versions', 'settings_snapshot', static function ($table): void {
            $table->longText('settings_snapshot')->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_rule_versions', 'admin_id', static function ($table): void {
            $table->unsignedInteger('admin_id')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_rule_versions', 'created_at', static function ($table): void {
            $table->timestamp('created_at')->nullable();
        });
    }

    if ($schema->hasTable('mod_peakrack_risk_audit_logs')) {
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'order_id', static function ($table): void {
            $table->unsignedInteger('order_id')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'client_id', static function ($table): void {
            $table->unsignedInteger('client_id')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'level', static function ($table): void {
            $table->string('level', 20)->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'message', static function ($table): void {
            $table->string('message', 255)->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'context', static function ($table): void {
            $table->longText('context')->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_audit_logs', 'created_at', static function ($table): void {
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    if ($schema->hasTable('mod_peakrack_risk_decisions')) {
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'order_id', static function ($table): void {
            $table->unsignedInteger('order_id')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'client_id', static function ($table): void {
            $table->unsignedInteger('client_id')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'score', static function ($table): void {
            $table->decimal('score', 5, 2)->default(0);
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'action', static function ($table): void {
            $table->string('action', 40)->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'rule_version', static function ($table): void {
            $table->unsignedInteger('rule_version')->nullable()->index();
        });
        $GLOBALS['peakrackRiskDecisionRuleVersionColumn'] = true;
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'reasons', static function ($table): void {
            $table->longText('reasons')->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'api_result', static function ($table): void {
            $table->longText('api_result')->nullable();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'processed_at', static function ($table): void {
            $table->timestamp('processed_at')->nullable()->index();
        });
        peakrack_risk_ensure_column('mod_peakrack_risk_decisions', 'updated_at', static function ($table): void {
            $table->timestamp('updated_at')->nullable();
        });
    }
}

function peakrack_risk_ensure_column(string $table, string $column, callable $definition): void
{
    $schema = Capsule::schema();
    if (!$schema->hasColumn($table, $column)) {
        $schema->table($table, $definition);
    }
}

function peakrack_risk_verify_admin_token(): bool
{
    if (function_exists('check_token')) {
        return (bool) check_token('WHMCS.admin.default');
    }

    return true;
}

function peakrack_risk_admin_token_field(): string
{
    if (function_exists('generate_token')) {
        $token = (string) generate_token('plain');
        return '<input type="hidden" name="token" value="' . peakrack_risk_e($token) . '">';
    }

    return '';
}

function peakrack_risk_settings_from_post(array $current): array
{
    $settings = $current;
    $settings['enabled'] = peakrackRiskBool($_POST['enabled'] ?? false);
    $settings['checkoutEnabled'] = peakrackRiskBool($_POST['checkoutEnabled'] ?? false);
    $settings['checkoutServerValidation'] = peakrackRiskBool($_POST['checkoutServerValidation'] ?? false);
    $settings['logOnly'] = peakrackRiskBool($_POST['logOnly'] ?? false);
    $settings['autoFraud'] = peakrackRiskBool($_POST['autoFraud'] ?? false);
    $settings['emailVerifiedTrustEnabled'] = peakrackRiskBool($_POST['emailVerifiedTrustEnabled'] ?? false);
    $settings['adminLanguage'] = in_array((string) ($_POST['adminLanguage'] ?? 'en'), ['en', 'zh'], true)
        ? (string) $_POST['adminLanguage']
        : 'en';
    $settings['activityLogMirrorLevel'] = peakrackRiskNormalizeActivityLogLevel($_POST['activityLogMirrorLevel'] ?? 'warning');
    $settings['reviewThreshold'] = peakrack_risk_float_post('reviewThreshold', 30.0, 0.0, 100.0);
    $settings['fraudThreshold'] = peakrack_risk_float_post('fraudThreshold', 80.0, 0.0, 100.0);
    if ($settings['reviewThreshold'] > $settings['fraudThreshold']) {
        $settings['reviewThreshold'] = $settings['fraudThreshold'];
    }
    $settings['apiRetries'] = (int) peakrack_risk_float_post('apiRetries', 1, 1, 5);
    $settings['ipBurstWindowMinutes'] = (int) peakrack_risk_float_post('ipBurstWindowMinutes', 60, 1, 1440);
    $settings['ipBurstOrderCount'] = (int) peakrack_risk_float_post('ipBurstOrderCount', 3, 1, 50);
    $settings['trustedClientAgeDays'] = (int) peakrack_risk_float_post('trustedClientAgeDays', 0, 0, 3650);
    $settings['trustedPaidInvoiceCount'] = (int) peakrack_risk_float_post('trustedPaidInvoiceCount', 0, 0, 1000);
    $settings['auditRetentionDays'] = (int) peakrack_risk_float_post('auditRetentionDays', 180, 0, 3650);
    $settings['maxAuditLogs'] = (int) peakrack_risk_float_post('maxAuditLogs', 10000, 0, 1000000);
    $settings['maxRuleVersions'] = (int) peakrack_risk_float_post('maxRuleVersions', 200, 0, 5000);
    $settings['highRiskCountries'] = peakrackRiskNormalizeList($_POST['highRiskCountries'] ?? '', true);
    $settings['trustedEmailDomains'] = peakrackRiskNormalizeList($_POST['trustedEmailDomains'] ?? '');
    $settings['whitelistClientIds'] = peakrackRiskNormalizeIntList($_POST['whitelistClientIds'] ?? '');
    $settings['whitelistClientGroupIds'] = peakrackRiskNormalizeIntList($_POST['whitelistClientGroupIds'] ?? '');
    $settings['whitelistEmailDomains'] = peakrackRiskNormalizeList($_POST['whitelistEmailDomains'] ?? '');
    $settings['whitelistIpCidrs'] = peakrackRiskNormalizeList($_POST['whitelistIpCidrs'] ?? '');
    $settings['whitelistProductIds'] = peakrackRiskNormalizeIntList($_POST['whitelistProductIds'] ?? '');
    $settings['whitelistPaymentMethods'] = peakrackRiskNormalizeList($_POST['whitelistPaymentMethods'] ?? '');

    foreach (array_keys($settings['weights']) as $key) {
        $settings['weights'][$key] = peakrack_risk_float_post('weight_' . $key, (float) $settings['weights'][$key], -100.0, 100.0);
    }

    foreach (['zh', 'en'] as $language) {
        foreach (['title', 'line1', 'footer', 'button', 'validation'] as $field) {
            $posted = trim((string) ($_POST['checkout_' . $language . '_' . $field] ?? ''));
            if ($posted !== '') {
                $settings['checkout'][$language][$field] = $posted;
            }
        }

        $itemsKey = 'checkout_' . $language . '_items';
        if (array_key_exists($itemsKey, $_POST)) {
            $settings['checkout'][$language]['items'] = peakrackRiskNormalizeTextLines($_POST[$itemsKey]);
        }
    }

    return $settings;
}

function peakrack_risk_float_post(string $key, float $default, float $min, float $max): float
{
    $value = $_POST[$key] ?? $default;
    if (!is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (float) $value));
}

function peakrack_risk_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function peakrack_risk_lines(array $items): string
{
    return implode("\n", array_map(static fn($item): string => (string) $item, $items));
}

function peakrack_risk_render_admin(array $settings, string $message, string $messageType): string
{
    $recentDecisions = peakrack_risk_recent_rows('mod_peakrack_risk_decisions', 'processed_at', 100);
    $recentLogs = peakrack_risk_recent_rows('mod_peakrack_risk_audit_logs', 'created_at', 100);
    $metrics = peakrack_risk_decision_metrics();
    $diagnostics = peakrack_risk_diagnostics($settings);
    $exportJson = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $exportJson = is_string($exportJson) ? $exportJson : '{}';
    $token = peakrack_risk_admin_token_field();
    $language = peakrackRiskAdminLanguage($settings);
    $t = static fn(string $key): string => peakrackRiskAdminText($language, $key);
    $modeText = $settings['logOnly'] ? $t('log_only') : ($settings['autoFraud'] ? $t('auto_fraud_enabled') : $t('manual_review'));
    $checkoutText = $settings['checkoutEnabled'] ? $t('notice_enabled') : $t('notice_disabled');
    $manualOrderId = (int) ($_POST['manualOrderId'] ?? ($_GET['prk_order_id'] ?? 0));

    ob_start();
    ?>
    <style>
        .prk-wrap{max-width:1220px;color:#1f2937}
        .prk-topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin:0 0 18px}
        .prk-title{margin:0 0 4px;font-size:24px;font-weight:700;color:#111827}
        .prk-subtitle{margin:0;color:#6b7280;font-size:13px;line-height:1.5}
        .prk-save{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;justify-content:flex-end;flex-shrink:0}
        .prk-lang{display:inline-flex;border:1px solid #cfd8e3;border-radius:6px;background:#fff;overflow:hidden}
        .prk-lang a{display:inline-flex;align-items:center;padding:8px 10px;color:#475569;text-decoration:none;font-size:12px;font-weight:700}
        .prk-lang a.active{background:#2563eb;color:#fff}
        .prk-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}
        .prk-stat{border:1px solid #d8e0ea;border-radius:6px;background:#fff;padding:14px 16px}
        .prk-stat-label{display:block;color:#6b7280;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
        .prk-stat-value{display:block;margin-top:5px;font-size:18px;font-weight:700;color:#111827}
        .prk-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:18px;align-items:start}
        .prk-grid-even{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .prk-card{border:1px solid #d8e0ea;border-radius:6px;background:#fff;margin:0 0 18px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .prk-card-head{display:flex;justify-content:space-between;gap:16px;padding:14px 16px;border-bottom:1px solid #e7edf3;background:#fbfcfe}
        .prk-card-title{margin:0;font-size:15px;font-weight:700;color:#111827}
        .prk-card-desc{margin:4px 0 0;color:#6b7280;font-size:12px;line-height:1.45}
        .prk-card-body{padding:16px}
        .prk-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
        .prk-form-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 16px}
        .prk-language-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .prk-language-panel{border:1px solid #e7edf3;border-radius:6px;background:#fff}
        .prk-language-title{margin:0;padding:10px 12px;border-bottom:1px solid #e7edf3;background:#f8fafc;font-size:13px;font-weight:700;color:#344054}
        .prk-language-body{padding:14px;display:grid;grid-template-columns:1fr;gap:14px}
        .prk-field label{display:block;font-weight:600;margin-bottom:6px;color:#374151}
        .prk-field input[type=text],.prk-field input[type=number],.prk-field select,.prk-field textarea{width:100%;max-width:100%;box-sizing:border-box;border:1px solid #cfd8e3;border-radius:4px;padding:7px 9px;background:#fff;color:#111827}
        .prk-field textarea{min-height:92px;font-family:Consolas,Menlo,monospace;resize:vertical}
        .prk-help{margin:6px 0 0;color:#6b7280;font-size:12px;line-height:1.45}
        .prk-toggles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .prk-toggle{display:flex;align-items:center;gap:10px;border:1px solid #d8e0ea;border-radius:6px;padding:10px 12px;background:#fff;margin:0}
        .prk-toggle input{margin:0}
        .prk-toggle span{font-weight:600;color:#374151}
        .prk-muted{color:#6b7280;font-size:12px}
        .prk-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700;border:1px solid transparent;white-space:nowrap}
        .prk-badge-green{background:#ecfdf3;color:#027a48;border-color:#abefc6}
        .prk-badge-blue{background:#eff8ff;color:#175cd3;border-color:#b2ddff}
        .prk-badge-amber{background:#fffaeb;color:#b54708;border-color:#fedf89}
        .prk-badge-red{background:#fef3f2;color:#b42318;border-color:#fecdca}
        .prk-badge-gray{background:#f2f4f7;color:#344054;border-color:#d0d5dd}
        .prk-table-wrap{overflow:auto;border:1px solid #e7edf3;border-radius:6px}
        .prk-table-wrap-scroll{max-height:340px}
        .prk-table{width:100%;border-collapse:collapse;margin:0;background:#fff}
        .prk-table th{background:#f8fafc;color:#475467;font-size:12px;font-weight:700}
        .prk-table th,.prk-table td{border-top:1px solid #edf2f7;padding:9px 10px;text-align:left;vertical-align:top}
        .prk-table tr:first-child th{border-top:0}
        .prk-empty{margin:0;padding:14px;border:1px dashed #cfd8e3;border-radius:6px;background:#fbfcfe;color:#6b7280}
        .prk-mini-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .prk-mini{border:1px solid #e7edf3;border-radius:6px;background:#fff;padding:10px 12px}
        .prk-mini-label{display:block;color:#6b7280;font-size:12px;font-weight:700}
        .prk-mini-value{display:block;margin-top:4px;color:#111827;font-size:16px;font-weight:700}
        .prk-diagnostics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .prk-diagnostic{display:flex;justify-content:space-between;gap:10px;border:1px solid #e7edf3;border-radius:6px;padding:10px 12px}
        .prk-export{min-height:180px;font-size:12px}
        .prk-actions{margin:0 0 24px;padding-top:2px}
        @media (max-width:1050px){.prk-summary,.prk-mini-grid{grid-template-columns:repeat(2,1fr)}.prk-grid,.prk-grid-even,.prk-form-grid-3,.prk-language-grid,.prk-diagnostics{grid-template-columns:1fr}}
        @media (max-width:700px){.prk-topbar{display:block}.prk-save{justify-content:flex-start;margin-top:12px}.prk-summary,.prk-mini-grid,.prk-form-grid,.prk-toggles{grid-template-columns:1fr}}
    </style>
    <div class="prk-wrap">
        <div class="prk-topbar">
            <div>
                <h2 class="prk-title"><?php echo peakrack_risk_e($t('page_title')); ?></h2>
                <p class="prk-subtitle"><?php echo peakrack_risk_e($t('subtitle')); ?></p>
            </div>
            <div class="prk-save">
                <div class="prk-lang" aria-label="<?php echo peakrack_risk_e($t('admin_language')); ?>">
                    <a class="<?php echo $language === 'zh' ? 'active' : ''; ?>" href="<?php echo peakrack_risk_e(peakrack_risk_admin_url('zh')); ?>">中文</a>
                    <a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="<?php echo peakrack_risk_e(peakrack_risk_admin_url('en')); ?>">English</a>
                </div>
                <button form="prk-settings-form" type="submit" class="btn btn-primary"><?php echo peakrack_risk_e($t('save')); ?></button>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo peakrack_risk_e($messageType); ?>"><?php echo peakrack_risk_e($message); ?></div>
        <?php endif; ?>

        <div class="prk-summary">
            <div class="prk-stat"><span class="prk-stat-label"><?php echo peakrack_risk_e($t('mode')); ?></span><span class="prk-stat-value"><?php echo peakrack_risk_e($modeText); ?></span></div>
            <div class="prk-stat"><span class="prk-stat-label"><?php echo peakrack_risk_e($t('review_threshold')); ?></span><span class="prk-stat-value"><?php echo peakrack_risk_e($settings['reviewThreshold']); ?></span></div>
            <div class="prk-stat"><span class="prk-stat-label"><?php echo peakrack_risk_e($t('fraud_threshold')); ?></span><span class="prk-stat-value"><?php echo peakrack_risk_e($settings['fraudThreshold']); ?></span></div>
            <div class="prk-stat"><span class="prk-stat-label"><?php echo peakrack_risk_e($t('checkout')); ?></span><span class="prk-stat-value"><?php echo peakrack_risk_e($checkoutText); ?></span></div>
        </div>

        <div class="prk-card">
            <div class="prk-card-head">
                <div>
                    <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('metrics')); ?></h3>
                    <p class="prk-card-desc"><?php echo peakrack_risk_e($t('metrics_desc')); ?></p>
                </div>
            </div>
            <div class="prk-card-body"><?php echo peakrack_risk_render_metrics($metrics, $language); ?></div>
        </div>

        <div class="prk-card">
            <div class="prk-card-head">
                <div>
                    <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('manual_tools')); ?></h3>
                    <p class="prk-card-desc"><?php echo peakrack_risk_e($t('manual_tools_desc')); ?></p>
                </div>
            </div>
            <form method="post" class="prk-card-body prk-form-grid-3">
                <?php echo $token; ?>
                <input type="hidden" name="prk_action" value="manual_order_action">
                <input type="hidden" name="adminLanguage" value="<?php echo peakrack_risk_e($language); ?>">
                <?php echo peakrack_risk_input('manualOrderId', $t('manual_order_id'), $manualOrderId, 1, $t('manual_order_id_help')); ?>
                <?php echo peakrack_risk_select('manualAction', $t('manual_action'), (string) ($_POST['manualAction'] ?? 'score_only'), peakrack_risk_manual_action_options($language)); ?>
                <div class="prk-field">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-default"><?php echo peakrack_risk_e($t('manual_run')); ?></button>
                    <p class="prk-help"><?php echo peakrack_risk_e($t('manual_run_help')); ?></p>
                </div>
            </form>
        </div>

        <form id="prk-settings-form" method="post">
            <?php echo $token; ?>
            <input type="hidden" name="prk_action" value="save_settings">
            <input type="hidden" name="adminLanguage" value="<?php echo peakrack_risk_e($language); ?>">

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('general_controls')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('general_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body">
                    <div class="prk-toggles">
                        <?php echo peakrack_risk_checkbox('enabled', $t('enable_risk_engine'), $settings['enabled']); ?>
                        <?php echo peakrack_risk_checkbox('checkoutEnabled', $t('enable_checkout_notice'), $settings['checkoutEnabled']); ?>
                        <?php echo peakrack_risk_checkbox('checkoutServerValidation', $t('require_server_ack'), $settings['checkoutServerValidation']); ?>
                        <?php echo peakrack_risk_checkbox('logOnly', $t('log_only'), $settings['logOnly']); ?>
                        <?php echo peakrack_risk_checkbox('autoFraud', $t('allow_auto_fraud'), $settings['autoFraud']); ?>
                        <?php echo peakrack_risk_select('activityLogMirrorLevel', $t('activity_log_level'), (string) $settings['activityLogMirrorLevel'], ['warning' => $t('activity_log_warning'), 'error' => $t('activity_log_error'), 'info' => $t('activity_log_info'), 'disabled' => $t('activity_log_disabled')]); ?>
                    </div>
                </div>
            </div>

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('thresholds')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('thresholds_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body prk-form-grid-3">
                    <?php echo peakrack_risk_input('reviewThreshold', $t('review_threshold'), $settings['reviewThreshold'], 0.01, $t('review_help')); ?>
                    <?php echo peakrack_risk_input('fraudThreshold', $t('fraud_threshold'), $settings['fraudThreshold'], 0.01, $t('fraud_help')); ?>
                    <?php echo peakrack_risk_input('apiRetries', $t('api_retries'), $settings['apiRetries'], 1, $t('api_help')); ?>
                    <?php echo peakrack_risk_input('ipBurstWindowMinutes', $t('ip_burst_window'), $settings['ipBurstWindowMinutes'], 1, $t('ip_burst_window_help')); ?>
                    <?php echo peakrack_risk_input('ipBurstOrderCount', $t('ip_burst_count'), $settings['ipBurstOrderCount'], 1, $t('ip_burst_count_help')); ?>
                </div>
            </div>

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('trust_strategy')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('trust_strategy_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body prk-form-grid-3">
                    <?php echo peakrack_risk_input('trustedClientAgeDays', $t('trusted_client_age_days'), $settings['trustedClientAgeDays'], 1, $t('trusted_client_age_days_help')); ?>
                    <?php echo peakrack_risk_input('trustedPaidInvoiceCount', $t('trusted_paid_invoice_count'), $settings['trustedPaidInvoiceCount'], 1, $t('trusted_paid_invoice_count_help')); ?>
                    <?php echo peakrack_risk_checkbox('emailVerifiedTrustEnabled', $t('email_verified_trust'), $settings['emailVerifiedTrustEnabled']); ?>
                </div>
            </div>

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('retention')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('retention_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body prk-form-grid-3">
                    <?php echo peakrack_risk_input('auditRetentionDays', $t('audit_retention_days'), $settings['auditRetentionDays'], 1, $t('audit_retention_days_help')); ?>
                    <?php echo peakrack_risk_input('maxAuditLogs', $t('max_audit_logs'), $settings['maxAuditLogs'], 1, $t('max_audit_logs_help')); ?>
                    <?php echo peakrack_risk_input('maxRuleVersions', $t('max_rule_versions'), $settings['maxRuleVersions'], 1, $t('max_rule_versions_help')); ?>
                </div>
            </div>

            <div class="prk-grid">
                <div class="prk-card">
                    <div class="prk-card-head">
                        <div>
                            <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('lists')); ?></h3>
                            <p class="prk-card-desc"><?php echo peakrack_risk_e($t('lists_desc')); ?></p>
                        </div>
                    </div>
                    <div class="prk-card-body prk-form-grid">
                        <?php echo peakrack_risk_textarea('highRiskCountries', $t('high_risk_countries'), peakrack_risk_lines($settings['highRiskCountries']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('trustedEmailDomains', $t('trusted_email_domains'), peakrack_risk_lines($settings['trustedEmailDomains']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistClientIds', $t('whitelist_client_ids'), peakrack_risk_lines($settings['whitelistClientIds']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistClientGroupIds', $t('whitelist_group_ids'), peakrack_risk_lines($settings['whitelistClientGroupIds']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistEmailDomains', $t('whitelist_email_domains'), peakrack_risk_lines($settings['whitelistEmailDomains']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistIpCidrs', $t('whitelist_ip_cidrs'), peakrack_risk_lines($settings['whitelistIpCidrs']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistProductIds', $t('whitelist_product_ids'), peakrack_risk_lines($settings['whitelistProductIds']), $t('one_per_line')); ?>
                        <?php echo peakrack_risk_textarea('whitelistPaymentMethods', $t('whitelist_payment_methods'), peakrack_risk_lines($settings['whitelistPaymentMethods']), $t('payment_methods_help')); ?>
                    </div>
                </div>

                <div class="prk-card">
                    <div class="prk-card-head">
                        <div>
                            <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('risk_weights')); ?></h3>
                            <p class="prk-card-desc"><?php echo peakrack_risk_e($t('weights_desc')); ?></p>
                        </div>
                    </div>
                    <div class="prk-card-body prk-form-grid">
                        <?php foreach ($settings['weights'] as $key => $value): ?>
                            <?php echo peakrack_risk_input('weight_' . $key, peakrack_risk_weight_label($key, $language), $value); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('checkout_text')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('checkout_text_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body">
                    <div class="prk-language-grid">
                        <?php foreach (['zh' => 'Chinese', 'en' => 'English'] as $checkoutLanguage => $label): ?>
                            <div class="prk-language-panel">
                                <h4 class="prk-language-title"><?php echo peakrack_risk_e(peakrack_risk_checkout_language_label($checkoutLanguage, $language)); ?></h4>
                                <div class="prk-language-body">
                                    <?php foreach (['title', 'line1', 'items', 'footer', 'button', 'validation'] as $field): ?>
                                        <?php $fieldLabel = peakrack_risk_checkout_field_label($checkoutLanguage, $field, $language); ?>
                                        <?php if (in_array($field, ['title', 'button'], true)): ?>
                                            <?php echo peakrack_risk_text('checkout_' . $checkoutLanguage . '_' . $field, $fieldLabel, $settings['checkout'][$checkoutLanguage][$field]); ?>
                                        <?php elseif ($field === 'items'): ?>
                                            <?php echo peakrack_risk_textarea('checkout_' . $checkoutLanguage . '_items', $fieldLabel, peakrack_risk_lines($settings['checkout'][$checkoutLanguage]['items']), $t('checkout_items_help')); ?>
                                        <?php else: ?>
                                            <?php echo peakrack_risk_textarea('checkout_' . $checkoutLanguage . '_' . $field, $fieldLabel, $settings['checkout'][$checkoutLanguage][$field], ''); ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <p class="prk-actions"><button type="submit" class="btn btn-primary"><?php echo peakrack_risk_e($t('save')); ?></button></p>
        </form>

        <div class="prk-grid-even">
            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('diagnostics')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('diagnostics_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body"><?php echo peakrack_risk_render_diagnostics($diagnostics, $language); ?></div>
            </div>

            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('config_tools')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('config_tools_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body">
                    <?php echo peakrack_risk_textarea('settingsExportJson', $t('export_settings'), $exportJson, $t('export_settings_help'), true); ?>
                    <form method="post" style="margin-top:14px">
                        <?php echo $token; ?>
                        <input type="hidden" name="prk_action" value="import_settings">
                        <input type="hidden" name="adminLanguage" value="<?php echo peakrack_risk_e($language); ?>">
                        <?php echo peakrack_risk_textarea('settingsImportJson', $t('import_settings'), '', $t('import_settings_help')); ?>
                        <p class="prk-actions" style="margin-bottom:0"><button type="submit" class="btn btn-default"><?php echo peakrack_risk_e($t('import_run')); ?></button></p>
                    </form>
                </div>
            </div>
        </div>

        <div class="prk-grid-even">
            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('recent_decisions')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('recent_decisions_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body"><?php echo peakrack_risk_render_decisions($recentDecisions, $language); ?></div>
            </div>
            <div class="prk-card">
                <div class="prk-card-head">
                    <div>
                        <h3 class="prk-card-title"><?php echo peakrack_risk_e($t('recent_logs')); ?></h3>
                        <p class="prk-card-desc"><?php echo peakrack_risk_e($t('recent_logs_desc')); ?></p>
                    </div>
                </div>
                <div class="prk-card-body"><?php echo peakrack_risk_render_logs($recentLogs, $language); ?></div>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function peakrack_risk_manual_action_options(string $language): array
{
    $language = in_array($language, ['en', 'zh'], true) ? $language : 'en';
    $options = [
        'en' => [
            'score_only' => 'Score only',
            'apply_rules' => 'Apply configured rules',
            'force_pending' => 'Force PendingOrder',
            'force_fraud' => 'Force FraudOrder',
        ],
        'zh' => [
            'score_only' => '仅重新评分',
            'apply_rules' => '按当前规则处理',
            'force_pending' => '强制执行 PendingOrder',
            'force_fraud' => '强制执行 FraudOrder',
        ],
    ];

    return $options[$language];
}

function peakrack_risk_checkbox(string $name, string $label, mixed $checked): string
{
    return '<label class="prk-toggle"><input type="checkbox" name="' . peakrack_risk_e($name) . '" value="1" ' . (peakrackRiskBool($checked) ? 'checked' : '') . '><span>' . peakrack_risk_e($label) . '</span></label>';
}

function peakrack_risk_input(string $name, string $label, mixed $value, float $step = 0.01, string $help = ''): string
{
    $helpHtml = $help !== '' ? '<p class="prk-help">' . peakrack_risk_e($help) . '</p>' : '';
    return '<div class="prk-field"><label for="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($label) . '</label><input type="number" step="' . peakrack_risk_e($step) . '" id="' . peakrack_risk_e($name) . '" name="' . peakrack_risk_e($name) . '" value="' . peakrack_risk_e($value) . '">' . $helpHtml . '</div>';
}

function peakrack_risk_text(string $name, string $label, mixed $value): string
{
    return '<div class="prk-field"><label for="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($label) . '</label><input type="text" id="' . peakrack_risk_e($name) . '" name="' . peakrack_risk_e($name) . '" value="' . peakrack_risk_e($value) . '"></div>';
}

function peakrack_risk_select(string $name, string $label, string $value, array $options): string
{
    $html = '<div class="prk-field"><label for="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($label) . '</label><select id="' . peakrack_risk_e($name) . '" name="' . peakrack_risk_e($name) . '">';
    foreach ($options as $optionValue => $optionLabel) {
        $selected = hash_equals((string) $optionValue, $value) ? ' selected' : '';
        $html .= '<option value="' . peakrack_risk_e($optionValue) . '"' . $selected . '>' . peakrack_risk_e($optionLabel) . '</option>';
    }

    return $html . '</select></div>';
}

function peakrack_risk_textarea(string $name, string $label, mixed $value, string $help, bool $readonly = false): string
{
    $helpHtml = $help !== '' ? '<p class="prk-help">' . peakrack_risk_e($help) . '</p>' : '';
    $readonlyAttr = $readonly ? ' readonly class="prk-export"' : '';
    return '<div class="prk-field"><label for="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($label) . '</label><textarea id="' . peakrack_risk_e($name) . '" name="' . peakrack_risk_e($name) . '"' . $readonlyAttr . '>' . peakrack_risk_e($value) . '</textarea>' . $helpHtml . '</div>';
}

function peakrack_risk_admin_url(string $language): string
{
    $params = ['module' => 'peakrack_risk', 'prk_admin_lang' => in_array($language, ['en', 'zh'], true) ? $language : 'en'];
    $orderId = (int) ($_GET['prk_order_id'] ?? $_POST['manualOrderId'] ?? 0);
    if ($orderId > 0) {
        $params['prk_order_id'] = $orderId;
    }

    return 'addonmodules.php?' . http_build_query($params);
}

function peakrack_risk_recent_rows(string $table, string $dateColumn, int $limit = 10): array
{
    try {
        if (!Capsule::schema()->hasTable($table)) {
            return [];
        }

        return Capsule::table($table)->orderBy($dateColumn, 'desc')->limit(max(1, $limit))->get()->all();
    } catch (\Throwable) {
        return [];
    }
}

function peakrack_risk_decision_metrics(): array
{
    $empty = static fn(): array => [
        'total' => 0,
        'pass' => 0,
        'review' => 0,
        'fraud' => 0,
        'whitelist' => 0,
        'avg_score' => 0.0,
        '_score_sum' => 0.0,
    ];
    $metrics = [
        7 => $empty(),
        30 => $empty(),
    ];

    try {
        if (!Capsule::schema()->hasTable('mod_peakrack_risk_decisions')) {
            return $metrics;
        }

        $since30 = date('Y-m-d H:i:s', time() - (30 * 86400));
        $rows = Capsule::table('mod_peakrack_risk_decisions')
            ->where('processed_at', '>=', $since30)
            ->get(['action', 'score', 'processed_at'])
            ->all();
    } catch (\Throwable) {
        return $metrics;
    }

    $since = [
        7 => time() - (7 * 86400),
        30 => time() - (30 * 86400),
    ];

    foreach ($rows as $row) {
        $processedAt = strtotime((string) ($row->processed_at ?? ''));
        if ($processedAt === false) {
            continue;
        }

        foreach ($since as $days => $timestamp) {
            if ($processedAt < $timestamp) {
                continue;
            }

            $action = strtolower((string) ($row->action ?? ''));
            $metrics[$days]['total']++;
            $metrics[$days]['_score_sum'] += (float) ($row->score ?? 0);

            if (in_array($action, ['pass', 'log_only', 'manual_score'], true)) {
                $metrics[$days]['pass']++;
            } elseif (in_array($action, ['review', 'review_high', 'manual_pending'], true)) {
                $metrics[$days]['review']++;
            } elseif (in_array($action, ['fraud', 'manual_fraud', 'skip_fraud'], true)) {
                $metrics[$days]['fraud']++;
            } elseif ($action === 'whitelist') {
                $metrics[$days]['whitelist']++;
            }
        }
    }

    foreach ([7, 30] as $days) {
        if ($metrics[$days]['total'] > 0) {
            $metrics[$days]['avg_score'] = $metrics[$days]['_score_sum'] / $metrics[$days]['total'];
        }
        unset($metrics[$days]['_score_sum']);
    }

    return $metrics;
}

function peakrack_risk_diagnostics(array $settings): array
{
    $tables = [
        'diag_settings_table' => 'mod_peakrack_risk_settings',
        'diag_versions_table' => 'mod_peakrack_risk_rule_versions',
        'diag_audit_table' => 'mod_peakrack_risk_audit_logs',
        'diag_decisions_table' => 'mod_peakrack_risk_decisions',
    ];
    $items = [];

    foreach ($tables as $label => $table) {
        try {
            $ok = Capsule::schema()->hasTable($table);
        } catch (\Throwable) {
            $ok = false;
        }
        $items[] = ['label' => $label, 'ok' => $ok, 'value' => $table];
    }

    try {
        $hasRuleVersion = Capsule::schema()->hasTable('mod_peakrack_risk_decisions')
            && Capsule::schema()->hasColumn('mod_peakrack_risk_decisions', 'rule_version');
    } catch (\Throwable) {
        $hasRuleVersion = false;
    }

    $items[] = ['label' => 'diag_rule_version_column', 'ok' => $hasRuleVersion, 'value' => 'rule_version'];
    $items[] = ['label' => 'diag_localapi', 'ok' => function_exists('localAPI'), 'value' => 'localAPI'];
    $items[] = ['label' => 'diag_logactivity', 'ok' => function_exists('logActivity'), 'value' => 'logActivity'];
    $items[] = ['label' => 'diag_token', 'ok' => function_exists('generate_token') && function_exists('check_token'), 'value' => 'WHMCS admin token'];
    $items[] = ['label' => 'diag_rule_version', 'ok' => peakrackRiskCurrentRuleVersion() > 0, 'value' => (string) peakrackRiskCurrentRuleVersion()];
    $items[] = ['label' => 'diag_runtime_mode', 'ok' => !empty($settings['enabled']), 'value' => !empty($settings['logOnly']) ? 'log_only' : (!empty($settings['autoFraud']) ? 'auto_fraud' : 'manual_review')];

    foreach ([
        'diag_latest_decision' => ['mod_peakrack_risk_decisions', 'processed_at'],
        'diag_latest_audit' => ['mod_peakrack_risk_audit_logs', 'created_at'],
    ] as $label => $source) {
        try {
            [$table, $column] = $source;
            $value = Capsule::schema()->hasTable($table)
                ? (string) (Capsule::table($table)->max($column) ?? '')
                : '';
        } catch (\Throwable) {
            $value = '';
        }
        $items[] = ['label' => $label, 'ok' => $value !== '', 'value' => $value !== '' ? $value : '-'];
    }

    return $items;
}

function peakrack_risk_render_metrics(array $metrics, string $language): string
{
    $cards = [
        ['metric_7_total', (string) ($metrics[7]['total'] ?? 0)],
        ['metric_7_review', (string) ($metrics[7]['review'] ?? 0)],
        ['metric_7_fraud', (string) ($metrics[7]['fraud'] ?? 0)],
        ['metric_7_avg', number_format((float) ($metrics[7]['avg_score'] ?? 0), 2)],
        ['metric_30_total', (string) ($metrics[30]['total'] ?? 0)],
        ['metric_30_pass', (string) ($metrics[30]['pass'] ?? 0)],
        ['metric_30_whitelist', (string) ($metrics[30]['whitelist'] ?? 0)],
        ['metric_30_avg', number_format((float) ($metrics[30]['avg_score'] ?? 0), 2)],
    ];

    $html = '<div class="prk-mini-grid">';
    foreach ($cards as [$labelKey, $value]) {
        $html .= '<div class="prk-mini"><span class="prk-mini-label">' . peakrack_risk_e(peakrackRiskAdminText($language, $labelKey)) . '</span><span class="prk-mini-value">' . peakrack_risk_e($value) . '</span></div>';
    }

    return $html . '</div>';
}

function peakrack_risk_render_diagnostics(array $items, string $language): string
{
    $html = '<div class="prk-diagnostics">';
    foreach ($items as $item) {
        $ok = !empty($item['ok']);
        $html .= '<div class="prk-diagnostic"><div><strong>' . peakrack_risk_e(peakrackRiskAdminText($language, (string) $item['label'])) . '</strong><br><span class="prk-muted">' . peakrack_risk_e($item['value'] ?? '') . '</span></div><div>' . peakrack_risk_status_badge($ok ? peakrackRiskAdminText($language, 'ok') : peakrackRiskAdminText($language, 'missing'), $ok ? 'green' : 'amber') . '</div></div>';
    }

    return $html . '</div>';
}

function peakrack_risk_render_decisions(array $rows, string $language): string
{
    if ($rows === []) {
        return '<p class="prk-empty">' . peakrack_risk_e(peakrackRiskAdminText($language, 'no_decisions')) . '</p>';
    }

    $html = '<div class="prk-table-wrap prk-table-wrap-scroll"><table class="prk-table"><thead><tr><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'order')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'client')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'score')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'action')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'reason')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'processed')) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $reasons = peakrackRiskDescribeReasons(peakrackRiskDecodeList($row->reasons ?? ''), $language);
        $reasonText = $reasons === [] ? '-' : peakrackRiskLimitText(implode('; ', $reasons), 180);
        $html .= '<tr><td>#' . peakrack_risk_e($row->order_id ?? '') . '</td><td>' . peakrack_risk_e($row->client_id ?? '') . '</td><td>' . peakrack_risk_score_badge((float) ($row->score ?? 0)) . '</td><td>' . peakrack_risk_action_badge((string) ($row->action ?? '')) . '</td><td>' . peakrack_risk_e($reasonText) . '</td><td>' . peakrack_risk_e($row->processed_at ?? '') . '</td></tr>';
    }

    return $html . '</tbody></table></div>';
}

function peakrack_risk_render_logs(array $rows, string $language): string
{
    if ($rows === []) {
        return '<p class="prk-empty">' . peakrack_risk_e(peakrackRiskAdminText($language, 'no_logs')) . '</p>';
    }

    $html = '<div class="prk-table-wrap prk-table-wrap-scroll"><table class="prk-table"><thead><tr><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'time')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'level')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'message')) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . peakrack_risk_e($row->created_at ?? '') . '</td><td>' . peakrack_risk_level_badge((string) ($row->level ?? '')) . '</td><td>' . peakrack_risk_e($row->message ?? '') . '</td></tr>';
    }

    return $html . '</tbody></table></div>';
}

function peakrack_risk_status_badge(string $label, string $color): string
{
    $allowed = ['green', 'blue', 'amber', 'red', 'gray'];
    $color = in_array($color, $allowed, true) ? $color : 'gray';
    return '<span class="prk-badge prk-badge-' . peakrack_risk_e($color) . '">' . peakrack_risk_e($label) . '</span>';
}

function peakrack_risk_score_badge(float $score): string
{
    if ($score >= 80) {
        return peakrack_risk_status_badge(number_format($score, 2), 'red');
    }

    if ($score >= 30) {
        return peakrack_risk_status_badge(number_format($score, 2), 'amber');
    }

    return peakrack_risk_status_badge(number_format($score, 2), 'green');
}

function peakrack_risk_action_badge(string $action): string
{
    $color = match ($action) {
        'fraud' => 'red',
        'review_high', 'review' => 'amber',
        'pass', 'whitelist' => 'green',
        'log_only' => 'blue',
        default => 'gray',
    };

    return peakrack_risk_status_badge($action !== '' ? $action : 'unknown', $color);
}

function peakrack_risk_level_badge(string $level): string
{
    $color = match (strtolower($level)) {
        'error' => 'red',
        'warning' => 'amber',
        'info' => 'blue',
        default => 'gray',
    };

    return peakrack_risk_status_badge($level !== '' ? strtoupper($level) : 'LOG', $color);
}

function peakrack_risk_weight_label(string $key, string $language): string
{
    $labels = [
        'en' => [
            'providerFraud' => 'Provider fraud',
            'shortEmail' => 'Short email',
            'numericEmail' => 'Numeric email',
            'highRiskCountry' => 'High-risk country',
            'ipBurst' => 'IP burst',
            'historyFraudMax' => 'History fraud max',
            'historyFraudPerOrder' => 'History fraud per order',
            'activeServiceTrust' => 'Active service trust',
            'clientAgeTrust' => 'Client age trust',
            'paidInvoiceTrust' => 'Paid invoice trust',
            'emailVerifiedTrust' => 'Verified email trust',
        ],
        'zh' => [
            'providerFraud' => '风控服务商判定欺诈',
            'shortEmail' => '短邮箱用户名',
            'numericEmail' => '数字型邮箱',
            'highRiskCountry' => '高风险国家',
            'ipBurst' => '同 IP 爆发下单',
            'historyFraudMax' => '历史欺诈最高加分',
            'historyFraudPerOrder' => '每个历史欺诈订单加分',
            'activeServiceTrust' => '活跃服务信任减分',
            'clientAgeTrust' => '客户年龄信任减分',
            'paidInvoiceTrust' => '已付发票信任减分',
            'emailVerifiedTrust' => '邮箱验证信任减分',
        ],
    ];

    $language = in_array($language, ['en', 'zh'], true) ? $language : 'en';
    return $labels[$language][$key] ?? $labels['en'][$key] ?? $key;
}

function peakrack_risk_checkout_language_label(string $checkoutLanguage, string $adminLanguage): string
{
    $labels = [
        'en' => ['zh' => 'Chinese Notice', 'en' => 'English Notice'],
        'zh' => ['zh' => '中文提醒文案', 'en' => '英文提醒文案'],
    ];

    $adminLanguage = in_array($adminLanguage, ['en', 'zh'], true) ? $adminLanguage : 'en';
    $checkoutLanguage = in_array($checkoutLanguage, ['en', 'zh'], true) ? $checkoutLanguage : 'en';
    return $labels[$adminLanguage][$checkoutLanguage];
}

function peakrack_risk_checkout_field_label(string $checkoutLanguage, string $field, string $adminLanguage): string
{
    $labels = [
        'en' => [
            'zh' => [
                'title' => 'Chinese title',
                'line1' => 'Chinese introduction',
                'items' => 'Chinese bullet points',
                'footer' => 'Chinese note',
                'button' => 'Chinese button',
                'validation' => 'Chinese validation message',
            ],
            'en' => [
                'title' => 'English title',
                'line1' => 'English introduction',
                'items' => 'English bullet points',
                'footer' => 'English note',
                'button' => 'English button',
                'validation' => 'English validation message',
            ],
        ],
        'zh' => [
            'zh' => [
                'title' => '中文标题',
                'line1' => '中文说明',
                'items' => '中文列表项',
                'footer' => '中文提示',
                'button' => '中文按钮',
                'validation' => '中文校验提示',
            ],
            'en' => [
                'title' => '英文标题',
                'line1' => '英文说明',
                'items' => '英文列表项',
                'footer' => '英文提示',
                'button' => '英文按钮',
                'validation' => '英文校验提示',
            ],
        ],
    ];

    $adminLanguage = in_array($adminLanguage, ['en', 'zh'], true) ? $adminLanguage : 'en';
    $checkoutLanguage = in_array($checkoutLanguage, ['en', 'zh'], true) ? $checkoutLanguage : 'en';
    return $labels[$adminLanguage][$checkoutLanguage][$field] ?? $field;
}
