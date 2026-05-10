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
        'version' => '1.0.0',
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
    $settings = peakrackRiskLoadSettings();
    $language = peakrackRiskAdminLanguage($settings);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['prk_action'] ?? '') === 'save_settings') {
        $language = in_array((string) ($_POST['adminLanguage'] ?? $language), ['en', 'zh'], true)
            ? (string) ($_POST['adminLanguage'] ?? $language)
            : $language;

        if (!peakrack_risk_verify_admin_token()) {
            $message = peakrackRiskAdminText($language, 'token_failed');
            $messageType = 'danger';
        } else {
            $settings = peakrack_risk_settings_from_post($settings);
            peakrackRiskSaveSettings($settings, (int) ($_SESSION['adminid'] ?? 0));
            $language = peakrackRiskAdminLanguage($settings);
            $message = peakrackRiskAdminText($language, 'saved');
        }
    }

    echo peakrack_risk_render_admin($settings, $message, $messageType);
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
            $table->longText('reasons');
            $table->longText('api_result')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
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
    $settings['adminLanguage'] = in_array((string) ($_POST['adminLanguage'] ?? 'en'), ['en', 'zh'], true)
        ? (string) $_POST['adminLanguage']
        : 'en';
    $settings['reviewThreshold'] = peakrack_risk_float_post('reviewThreshold', 30.0, 0.0, 100.0);
    $settings['fraudThreshold'] = peakrack_risk_float_post('fraudThreshold', 80.0, 0.0, 100.0);
    $settings['apiRetries'] = (int) peakrack_risk_float_post('apiRetries', 1, 1, 5);
    $settings['ipBurstWindowMinutes'] = (int) peakrack_risk_float_post('ipBurstWindowMinutes', 60, 1, 1440);
    $settings['ipBurstOrderCount'] = (int) peakrack_risk_float_post('ipBurstOrderCount', 3, 1, 50);
    $settings['highRiskCountries'] = peakrackRiskNormalizeList($_POST['highRiskCountries'] ?? '', true);
    $settings['trustedEmailDomains'] = peakrackRiskNormalizeList($_POST['trustedEmailDomains'] ?? '');
    $settings['whitelistClientIds'] = peakrackRiskNormalizeIntList($_POST['whitelistClientIds'] ?? '');
    $settings['whitelistClientGroupIds'] = peakrackRiskNormalizeIntList($_POST['whitelistClientGroupIds'] ?? '');
    $settings['whitelistEmailDomains'] = peakrackRiskNormalizeList($_POST['whitelistEmailDomains'] ?? '');
    $settings['whitelistIpCidrs'] = peakrackRiskNormalizeList($_POST['whitelistIpCidrs'] ?? '');

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
    $recentDecisions = peakrack_risk_recent_rows('mod_peakrack_risk_decisions', 'processed_at');
    $recentLogs = peakrack_risk_recent_rows('mod_peakrack_risk_audit_logs', 'created_at');
    $token = peakrack_risk_admin_token_field();
    $language = peakrackRiskAdminLanguage($settings);
    $t = static fn(string $key): string => peakrackRiskAdminText($language, $key);
    $modeText = $settings['logOnly'] ? $t('log_only') : ($settings['autoFraud'] ? $t('auto_fraud_enabled') : $t('manual_review'));
    $checkoutText = $settings['checkoutEnabled'] ? $t('notice_enabled') : $t('notice_disabled');

    ob_start();
    ?>
    <style>
        .prk-wrap{max-width:1220px;color:#1f2937}
        .prk-topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin:0 0 18px}
        .prk-title{margin:0 0 4px;font-size:24px;font-weight:700;color:#111827}
        .prk-subtitle{margin:0;color:#6b7280;font-size:13px;line-height:1.5}
        .prk-save{display:flex;gap:8px;align-items:center;flex-shrink:0}
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
        .prk-table{width:100%;border-collapse:collapse;margin:0;background:#fff}
        .prk-table th{background:#f8fafc;color:#475467;font-size:12px;font-weight:700}
        .prk-table th,.prk-table td{border-top:1px solid #edf2f7;padding:9px 10px;text-align:left;vertical-align:top}
        .prk-table tr:first-child th{border-top:0}
        .prk-empty{margin:0;padding:14px;border:1px dashed #cfd8e3;border-radius:6px;background:#fbfcfe;color:#6b7280}
        .prk-actions{margin:0 0 24px;padding-top:2px}
        @media (max-width:1050px){.prk-summary{grid-template-columns:repeat(2,1fr)}.prk-grid,.prk-grid-even,.prk-form-grid-3,.prk-language-grid{grid-template-columns:1fr}}
        @media (max-width:700px){.prk-topbar{display:block}.prk-save{margin-top:12px}.prk-summary,.prk-form-grid,.prk-toggles{grid-template-columns:1fr}}
    </style>
    <div class="prk-wrap">
        <div class="prk-topbar">
            <div>
                <h2 class="prk-title"><?php echo peakrack_risk_e($t('page_title')); ?></h2>
                <p class="prk-subtitle"><?php echo peakrack_risk_e($t('subtitle')); ?></p>
            </div>
            <div class="prk-save">
                <?php echo peakrack_risk_status_badge($settings['enabled'] ? $t('enabled') : $t('disabled'), $settings['enabled'] ? 'green' : 'gray'); ?>
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

        <form id="prk-settings-form" method="post">
            <?php echo $token; ?>
            <input type="hidden" name="prk_action" value="save_settings">

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
                        <?php echo peakrack_risk_select('adminLanguage', $t('admin_language'), (string) $settings['adminLanguage'], ['en' => $t('english'), 'zh' => $t('chinese')]); ?>
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

function peakrack_risk_textarea(string $name, string $label, mixed $value, string $help): string
{
    $helpHtml = $help !== '' ? '<p class="prk-help">' . peakrack_risk_e($help) . '</p>' : '';
    return '<div class="prk-field"><label for="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($label) . '</label><textarea id="' . peakrack_risk_e($name) . '" name="' . peakrack_risk_e($name) . '">' . peakrack_risk_e($value) . '</textarea>' . $helpHtml . '</div>';
}

function peakrack_risk_recent_rows(string $table, string $dateColumn): array
{
    try {
        if (!Capsule::schema()->hasTable($table)) {
            return [];
        }

        return Capsule::table($table)->orderBy($dateColumn, 'desc')->limit(10)->get()->all();
    } catch (\Throwable) {
        return [];
    }
}

function peakrack_risk_render_decisions(array $rows, string $language): string
{
    if ($rows === []) {
        return '<p class="prk-empty">' . peakrack_risk_e(peakrackRiskAdminText($language, 'no_decisions')) . '</p>';
    }

    $html = '<div class="prk-table-wrap"><table class="prk-table"><thead><tr><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'order')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'client')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'score')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'action')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'processed')) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>#' . peakrack_risk_e($row->order_id ?? '') . '</td><td>' . peakrack_risk_e($row->client_id ?? '') . '</td><td>' . peakrack_risk_score_badge((float) ($row->score ?? 0)) . '</td><td>' . peakrack_risk_action_badge((string) ($row->action ?? '')) . '</td><td>' . peakrack_risk_e($row->processed_at ?? '') . '</td></tr>';
    }

    return $html . '</tbody></table></div>';
}

function peakrack_risk_render_logs(array $rows, string $language): string
{
    if ($rows === []) {
        return '<p class="prk-empty">' . peakrack_risk_e(peakrackRiskAdminText($language, 'no_logs')) . '</p>';
    }

    $html = '<div class="prk-table-wrap"><table class="prk-table"><thead><tr><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'time')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'level')) . '</th><th>' . peakrack_risk_e(peakrackRiskAdminText($language, 'message')) . '</th></tr></thead><tbody>';
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
