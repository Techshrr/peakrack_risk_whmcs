<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('No direct access');
}

if (!function_exists('peakrackRiskDefaults')) {
    function peakrackRiskDefaults(): array
    {
        return [
            'enabled' => true,
            'checkoutEnabled' => true,
            'checkoutServerValidation' => true,
            'logOnly' => true,
            'autoFraud' => false,
            'adminLanguage' => 'en',
            'activityLogMirrorLevel' => 'warning',
            'reviewThreshold' => 30.0,
            'fraudThreshold' => 80.0,
            'apiRetries' => 1,
            'ipBurstWindowMinutes' => 60,
            'ipBurstOrderCount' => 3,
            'auditRetentionDays' => 180,
            'maxAuditLogs' => 10000,
            'maxRuleVersions' => 200,
            'highRiskCountries' => ['NG', 'PK', 'BD', 'ID', 'RU', 'UA', 'IR', 'VN'],
            'trustedEmailDomains' => ['qq.com', 'foxmail.com'],
            'whitelistClientIds' => [],
            'whitelistClientGroupIds' => [],
            'whitelistEmailDomains' => [],
            'whitelistIpCidrs' => [],
            'whitelistProductIds' => [],
            'whitelistPaymentMethods' => [],
            'trustedClientAgeDays' => 0,
            'trustedPaidInvoiceCount' => 0,
            'emailVerifiedTrustEnabled' => false,
            'weights' => [
                'providerFraud' => 60,
                'shortEmail' => 10,
                'numericEmail' => 5,
                'highRiskCountry' => 15,
                'ipBurst' => 25,
                'historyFraudMax' => 30,
                'historyFraudPerOrder' => 10,
                'activeServiceTrust' => -10,
                'clientAgeTrust' => -8,
                'paidInvoiceTrust' => -8,
                'emailVerifiedTrust' => -5,
            ],
            'checkout' => [
                'fieldName' => '_prk_checkout_ack',
                'fieldValue' => '1',
                'nonceFieldName' => '_prk_checkout_ack_nonce',
                'storageKey' => 'prk_checkout_ack_v2',
                'zh' => [
                    'title' => '订单审核提示',
                    'line1' => '为保障服务开通及账户安全，提交订单前请确认以下事项：',
                    'items' => [
                        '请使用真实、稳定的网络环境提交订单，避免使用代理、VPN 或匿名网络。',
                        '账户资料、账单信息及付款信息应与实际使用人信息保持一致。',
                        '如系统检测到信息不一致或异常行为，订单可能需要人工审核后处理。',
                    ],
                    'footer' => '未按要求提交或存在异常风险的订单，可能被延迟处理、取消或拒绝。',
                    'button' => '确认并继续',
                    'validation' => '请先确认订单审核提示后再提交订单。',
                ],
                'en' => [
                    'title' => 'Order Review Notice',
                    'line1' => 'To protect account security and service delivery, please confirm the following before submitting your order:',
                    'items' => [
                        'Submit the order from a real and stable network environment; proxy, VPN, or anonymous network access may require review.',
                        'Account details, billing information, and payment information should be accurate and consistent.',
                        'Orders with inconsistent information or abnormal risk signals may be held for manual review.',
                    ],
                    'footer' => 'Orders submitted with inaccurate information or abnormal risk indicators may be delayed, cancelled, or declined.',
                    'button' => 'Confirm and Continue',
                    'validation' => 'Please confirm the order review notice before submitting your order.',
                ],
            ],
        ];
    }
}

if (!function_exists('peakrackRiskJsonEncode')) {
    function peakrackRiskJsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('peakrackRiskJsonDecode')) {
    function peakrackRiskJsonDecode(?string $json, array $fallback = []): array
    {
        if (!is_string($json) || trim($json) === '') {
            return $fallback;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}

if (!function_exists('peakrackRiskMigrateCheckoutDefaults')) {
    function peakrackRiskMigrateCheckoutDefaults(array $defaults, array $settings): array
    {
        $oldDefaults = [
            'zh' => [
                'title' => '下单安全提醒',
                'line1' => '为确保您的订单顺利审核并及时交付，请在继续前确认以下事项：',
                'items' => [
                    '请勿使用 VPN、代理或匿名网络环境下单。',
                    '账户国家/地区应与您的实际所在地及账单信息一致。',
                    '支付环境、账单信息与账户资料不一致时，订单可能进入人工审核。',
                ],
                'footer' => '异常或不一致的信息可能导致订单延迟处理，严重时可能被拒绝。',
                'button' => '我已了解并继续',
                'validation' => '请先确认下单安全提醒后再提交订单。',
            ],
            'en' => [
                'title' => 'Checkout Security Notice',
                'line1' => 'To help us review and deliver your order promptly, please confirm the following before continuing:',
                'items' => [
                    'Do not place the order through a VPN, proxy, or anonymous network.',
                    'Your account country must match your real location and billing details.',
                    'Mismatched payment, billing, or account information may trigger manual review.',
                ],
                'footer' => 'Unusual or inconsistent information may delay processing or cause the order to be declined.',
                'button' => 'I Understand and Continue',
                'validation' => 'Please acknowledge the checkout security notice before submitting your order.',
            ],
        ];

        foreach (['zh', 'en'] as $language) {
            foreach (['title', 'line1', 'footer', 'button', 'validation'] as $field) {
                if (($settings['checkout'][$language][$field] ?? null) === $oldDefaults[$language][$field]) {
                    $settings['checkout'][$language][$field] = $defaults['checkout'][$language][$field];
                }
            }

            if (($settings['checkout'][$language]['items'] ?? null) === $oldDefaults[$language]['items']) {
                $settings['checkout'][$language]['items'] = $defaults['checkout'][$language]['items'];
            }
        }

        return $settings;
    }
}

if (!function_exists('peakrackRiskMergeSettings')) {
    function peakrackRiskMergeSettings(array $defaults, array $stored): array
    {
        $settings = array_replace_recursive($defaults, $stored);

        if (!isset($settings['weights']) || !is_array($settings['weights'])) {
            $settings['weights'] = $defaults['weights'];
        }

        if (!isset($settings['checkout']) || !is_array($settings['checkout'])) {
            $settings['checkout'] = $defaults['checkout'];
        }

        foreach (['zh', 'en'] as $language) {
            if (!isset($settings['checkout'][$language]) || !is_array($settings['checkout'][$language])) {
                $settings['checkout'][$language] = $defaults['checkout'][$language];
            }

            foreach (['title', 'line1', 'footer', 'button', 'validation'] as $field) {
                if (!isset($settings['checkout'][$language][$field]) || !is_scalar($settings['checkout'][$language][$field])) {
                    $settings['checkout'][$language][$field] = $defaults['checkout'][$language][$field];
                }
            }

            if (!isset($settings['checkout'][$language]['items']) || !is_array($settings['checkout'][$language]['items'])) {
                $settings['checkout'][$language]['items'] = $defaults['checkout'][$language]['items'];
            }
        }

        foreach ([
            'highRiskCountries',
            'trustedEmailDomains',
            'whitelistClientIds',
            'whitelistClientGroupIds',
            'whitelistEmailDomains',
            'whitelistIpCidrs',
            'whitelistProductIds',
            'whitelistPaymentMethods',
        ] as $listKey) {
            if (array_key_exists($listKey, $stored) && is_array($stored[$listKey])) {
                $settings[$listKey] = $stored[$listKey];
            }
        }

        foreach (['zh', 'en'] as $language) {
            if (isset($stored['checkout'][$language]['items']) && is_array($stored['checkout'][$language]['items'])) {
                $settings['checkout'][$language]['items'] = $stored['checkout'][$language]['items'];
            }
        }

        $settings = peakrackRiskMigrateCheckoutDefaults($defaults, $settings);
        $settings['adminLanguage'] = in_array((string) ($settings['adminLanguage'] ?? 'en'), ['en', 'zh'], true)
            ? (string) $settings['adminLanguage']
            : 'en';
        $settings['enabled'] = peakrackRiskBool($settings['enabled'] ?? $defaults['enabled']);
        $settings['checkoutEnabled'] = peakrackRiskBool($settings['checkoutEnabled'] ?? $defaults['checkoutEnabled']);
        $settings['checkoutServerValidation'] = peakrackRiskBool($settings['checkoutServerValidation'] ?? $defaults['checkoutServerValidation']);
        $settings['logOnly'] = peakrackRiskBool($settings['logOnly'] ?? $defaults['logOnly']);
        $settings['autoFraud'] = peakrackRiskBool($settings['autoFraud'] ?? $defaults['autoFraud']);
        $settings['activityLogMirrorLevel'] = peakrackRiskNormalizeActivityLogLevel($settings['activityLogMirrorLevel'] ?? $defaults['activityLogMirrorLevel']);
        $settings['reviewThreshold'] = peakrackRiskClampFloat($settings['reviewThreshold'] ?? $defaults['reviewThreshold'], 0.0, 100.0, (float) $defaults['reviewThreshold']);
        $settings['fraudThreshold'] = peakrackRiskClampFloat($settings['fraudThreshold'] ?? $defaults['fraudThreshold'], 0.0, 100.0, (float) $defaults['fraudThreshold']);
        if ($settings['reviewThreshold'] > $settings['fraudThreshold']) {
            $settings['reviewThreshold'] = $settings['fraudThreshold'];
        }
        $settings['apiRetries'] = peakrackRiskClampInt($settings['apiRetries'] ?? $defaults['apiRetries'], 1, 5, (int) $defaults['apiRetries']);
        $settings['ipBurstWindowMinutes'] = peakrackRiskClampInt($settings['ipBurstWindowMinutes'] ?? $defaults['ipBurstWindowMinutes'], 1, 1440, (int) $defaults['ipBurstWindowMinutes']);
        $settings['ipBurstOrderCount'] = peakrackRiskClampInt($settings['ipBurstOrderCount'] ?? $defaults['ipBurstOrderCount'], 1, 50, (int) $defaults['ipBurstOrderCount']);
        $settings['auditRetentionDays'] = peakrackRiskClampInt($settings['auditRetentionDays'] ?? $defaults['auditRetentionDays'], 0, 3650, (int) $defaults['auditRetentionDays']);
        $settings['maxAuditLogs'] = peakrackRiskClampInt($settings['maxAuditLogs'] ?? $defaults['maxAuditLogs'], 0, 1000000, (int) $defaults['maxAuditLogs']);
        $settings['maxRuleVersions'] = peakrackRiskClampInt($settings['maxRuleVersions'] ?? $defaults['maxRuleVersions'], 0, 5000, (int) $defaults['maxRuleVersions']);
        $settings['trustedClientAgeDays'] = peakrackRiskClampInt($settings['trustedClientAgeDays'] ?? $defaults['trustedClientAgeDays'], 0, 3650, (int) $defaults['trustedClientAgeDays']);
        $settings['trustedPaidInvoiceCount'] = peakrackRiskClampInt($settings['trustedPaidInvoiceCount'] ?? $defaults['trustedPaidInvoiceCount'], 0, 1000, (int) $defaults['trustedPaidInvoiceCount']);
        $settings['emailVerifiedTrustEnabled'] = peakrackRiskBool($settings['emailVerifiedTrustEnabled'] ?? $defaults['emailVerifiedTrustEnabled']);
        $settings['highRiskCountries'] = peakrackRiskNormalizeList($settings['highRiskCountries'] ?? [], true);
        $settings['trustedEmailDomains'] = peakrackRiskNormalizeList($settings['trustedEmailDomains'] ?? []);
        $settings['whitelistClientIds'] = peakrackRiskNormalizeIntList($settings['whitelistClientIds'] ?? []);
        $settings['whitelistClientGroupIds'] = peakrackRiskNormalizeIntList($settings['whitelistClientGroupIds'] ?? []);
        $settings['whitelistEmailDomains'] = peakrackRiskNormalizeList($settings['whitelistEmailDomains'] ?? []);
        $settings['whitelistIpCidrs'] = peakrackRiskNormalizeList($settings['whitelistIpCidrs'] ?? []);
        $settings['whitelistProductIds'] = peakrackRiskNormalizeIntList($settings['whitelistProductIds'] ?? []);
        $settings['whitelistPaymentMethods'] = peakrackRiskNormalizeList($settings['whitelistPaymentMethods'] ?? []);

        foreach (array_keys($defaults['weights']) as $key) {
            $settings['weights'][$key] = peakrackRiskClampFloat($settings['weights'][$key] ?? $defaults['weights'][$key], -100.0, 100.0, (float) $defaults['weights'][$key]);
        }

        return $settings;
    }
}

if (!function_exists('peakrackRiskLoadSettings')) {
    function peakrackRiskLoadSettings(): array
    {
        if (isset($GLOBALS['peakrackRiskSettingsCache']) && is_array($GLOBALS['peakrackRiskSettingsCache'])) {
            return $GLOBALS['peakrackRiskSettingsCache'];
        }

        $defaults = peakrackRiskDefaults();

        try {
            $row = Capsule::table('mod_peakrack_risk_settings')
                ->where('setting', 'config')
                ->first();

            if ($row) {
                $stored = peakrackRiskJsonDecode((string) $row->value, []);
                $settings = peakrackRiskMergeSettings($defaults, $stored);
            } else {
                $settings = $defaults;
            }
        } catch (\Throwable) {
            $settings = $defaults;
        }

        $GLOBALS['peakrackRiskSettingsCache'] = $settings;
        return $settings;
    }
}

if (!function_exists('peakrackRiskSaveSettings')) {
    function peakrackRiskSaveSettings(array $settings, int $adminId = 0): void
    {
        $settings = peakrackRiskMergeSettings(peakrackRiskDefaults(), $settings);
        $now = date('Y-m-d H:i:s');
        $json = peakrackRiskJsonEncode($settings);

        Capsule::table('mod_peakrack_risk_settings')->updateOrInsert(
            ['setting' => 'config'],
            ['value' => $json, 'updated_at' => $now]
        );

        $lastVersion = (int) Capsule::table('mod_peakrack_risk_rule_versions')->max('version');
        Capsule::table('mod_peakrack_risk_rule_versions')->insert([
            'version' => $lastVersion + 1,
            'settings_snapshot' => $json,
            'admin_id' => $adminId > 0 ? $adminId : null,
            'created_at' => $now,
        ]);

        $GLOBALS['peakrackRiskSettingsCache'] = $settings;
        $GLOBALS['peakrackRiskCurrentRuleVersionCache'] = $lastVersion + 1;
    }
}

if (!function_exists('peakrackRiskCurrentRuleVersion')) {
    function peakrackRiskCurrentRuleVersion(): int
    {
        if (isset($GLOBALS['peakrackRiskCurrentRuleVersionCache'])) {
            return (int) $GLOBALS['peakrackRiskCurrentRuleVersionCache'];
        }

        try {
            $version = (int) Capsule::table('mod_peakrack_risk_rule_versions')->max('version');
        } catch (\Throwable) {
            $version = 0;
        }

        $GLOBALS['peakrackRiskCurrentRuleVersionCache'] = $version;
        return $version;
    }
}

if (!function_exists('peakrackRiskAudit')) {
    function peakrackRiskAudit(string $level, string $message, array $context = [], ?int $orderId = null, ?int $clientId = null): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            Capsule::table('mod_peakrack_risk_audit_logs')->insert([
                'order_id' => $orderId,
                'client_id' => $clientId,
                'level' => peakrackRiskLimitText($level, 20),
                'message' => peakrackRiskLimitText($message, 255),
                'context' => peakrackRiskJsonEncode($context),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Avoid breaking checkout/order flow if audit persistence is unavailable.
        }

        if (function_exists('logActivity') && peakrackRiskShouldMirrorActivityLog($level)) {
            logActivity('PeakRack Risk ' . strtoupper($level) . ': ' . $message, $clientId);
        }
    }
}

if (!function_exists('peakrackRiskNormalizeActivityLogLevel')) {
    function peakrackRiskNormalizeActivityLogLevel(mixed $level): string
    {
        $level = strtolower((string) $level);
        return in_array($level, ['info', 'warning', 'error', 'disabled'], true) ? $level : 'warning';
    }
}

if (!function_exists('peakrackRiskShouldMirrorActivityLog')) {
    function peakrackRiskShouldMirrorActivityLog(string $level): bool
    {
        $threshold = 'warning';

        try {
            $settings = peakrackRiskLoadSettings();
            $threshold = peakrackRiskNormalizeActivityLogLevel($settings['activityLogMirrorLevel'] ?? 'warning');
        } catch (\Throwable) {
            $threshold = 'warning';
        }

        if ($threshold === 'disabled') {
            return false;
        }

        $rank = [
            'info' => 1,
            'warning' => 2,
            'error' => 3,
        ];

        $level = strtolower($level);
        return ($rank[$level] ?? 1) >= ($rank[$threshold] ?? 2);
    }
}

if (!function_exists('peakrackRiskCleanupRetention')) {
    function peakrackRiskCleanupRetention(array $config): array
    {
        $summary = [
            'audit_age_deleted' => 0,
            'audit_count_deleted' => 0,
            'rule_versions_deleted' => 0,
        ];

        $auditRetentionDays = (int) ($config['auditRetentionDays'] ?? 0);
        if ($auditRetentionDays > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - ($auditRetentionDays * 86400));
            $summary['audit_age_deleted'] = (int) Capsule::table('mod_peakrack_risk_audit_logs')
                ->where('created_at', '<', $cutoff)
                ->delete();
        }

        $maxAuditLogs = (int) ($config['maxAuditLogs'] ?? 0);
        if ($maxAuditLogs > 0) {
            $boundary = Capsule::table('mod_peakrack_risk_audit_logs')
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->skip($maxAuditLogs)
                ->first(['id', 'created_at']);

            if ($boundary) {
                $summary['audit_count_deleted'] = (int) Capsule::table('mod_peakrack_risk_audit_logs')
                    ->where(static function ($query) use ($boundary): void {
                        $query->where('created_at', '<', $boundary->created_at)
                            ->orWhere(static function ($query) use ($boundary): void {
                                $query->where('created_at', '=', $boundary->created_at)
                                    ->where('id', '<=', (int) $boundary->id);
                            });
                    })
                    ->delete();
            }
        }

        $maxRuleVersions = (int) ($config['maxRuleVersions'] ?? 0);
        if ($maxRuleVersions > 0) {
            $boundary = Capsule::table('mod_peakrack_risk_rule_versions')
                ->orderBy('version', 'desc')
                ->orderBy('id', 'desc')
                ->skip($maxRuleVersions)
                ->first(['id', 'version']);

            if ($boundary) {
                $summary['rule_versions_deleted'] = (int) Capsule::table('mod_peakrack_risk_rule_versions')
                    ->where(static function ($query) use ($boundary): void {
                        $query->where('version', '<', (int) $boundary->version)
                            ->orWhere(static function ($query) use ($boundary): void {
                                $query->where('version', '=', (int) $boundary->version)
                                    ->where('id', '<=', (int) $boundary->id);
                            });
                    })
                    ->delete();
            }
        }

        return $summary;
    }
}

if (!function_exists('peakrackRiskNormalizeList')) {
    function peakrackRiskNormalizeList(string|array|null $value, bool $upper = false): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string) $value) ?: [];
        }

        $items = array_map(static function ($item) use ($upper) {
            $item = trim((string) $item);
            return $upper ? strtoupper($item) : strtolower($item);
        }, $items);

        return array_values(array_unique(array_filter($items, static fn($item) => $item !== '')));
    }
}

if (!function_exists('peakrackRiskNormalizeIntList')) {
    function peakrackRiskNormalizeIntList(string|array|null $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', peakrackRiskNormalizeList($value)), static fn($item) => $item > 0)));
    }
}

if (!function_exists('peakrackRiskNormalizeTextLines')) {
    function peakrackRiskNormalizeTextLines(string|array|null $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n]+/', (string) $value) ?: [];
        }

        $items = array_map(static fn($item): string => trim((string) $item), $items);
        return array_values(array_filter($items, static fn($item): bool => $item !== ''));
    }
}

if (!function_exists('peakrackRiskClampFloat')) {
    function peakrackRiskClampFloat(mixed $value, float $min, float $max, ?float $default = null): float
    {
        if (!is_numeric($value)) {
            return $default ?? $min;
        }

        return max($min, min($max, (float) $value));
    }
}

if (!function_exists('peakrackRiskClampInt')) {
    function peakrackRiskClampInt(mixed $value, int $min, int $max, ?int $default = null): int
    {
        if (!is_numeric($value)) {
            return $default ?? $min;
        }

        return max($min, min($max, (int) $value));
    }
}

if (!function_exists('peakrackRiskLimitText')) {
    function peakrackRiskLimitText(string $value, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $maxLength
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : $value;
        }

        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }
}

if (!function_exists('peakrackRiskEscape')) {
    function peakrackRiskEscape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('peakrackRiskDecodeList')) {
    function peakrackRiskDecodeList(mixed $value): array
    {
        $items = is_string($value) ? peakrackRiskJsonDecode($value, []) : (is_array($value) ? $value : []);
        return array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $items), static fn($item): bool => $item !== ''));
    }
}

if (!function_exists('peakrackRiskDescribeReason')) {
    function peakrackRiskDescribeReason(string $reason, string $language = 'en'): string
    {
        $language = in_array($language, ['en', 'zh'], true) ? $language : 'en';
        $reason = trim($reason);
        $texts = [
            'en' => [
                'ProviderScore' => 'Provider score: {score}',
                'ProviderFraud' => 'Fraud provider marked the order as fraud ({points} points).',
                'ShortEmail' => 'Short email username ({points} points).',
                'NumericEmail' => 'Numeric email username ({points} points).',
                'HighRiskCountry' => 'High-risk client country ({points} points).',
                'IPBurst' => 'Repeated orders from the same IP: {count} ({points} points).',
                'HistoryFraud' => 'Previous fraud orders: {count} ({points} points).',
                'ActiveService' => 'Client has an active service ({points} points).',
                'ClientAge' => 'Client account age trusted: {days} days ({points} points).',
                'PaidInvoices' => 'Client paid invoice history trusted: {count} invoices ({points} points).',
                'EmailVerified' => 'Client email is verified ({points} points).',
                'Whitelisted' => 'Whitelist matched.',
                'OrderAlready' => 'Order already {status}.',
                'AlreadyProcessed' => 'Skipped because rule version {version} was already processed.',
            ],
            'zh' => [
                'ProviderScore' => '风控服务商评分：{score}',
                'ProviderFraud' => '风控服务商已判定欺诈（{points} 分）。',
                'ShortEmail' => '邮箱用户名过短（{points} 分）。',
                'NumericEmail' => '邮箱用户名包含连续数字（{points} 分）。',
                'HighRiskCountry' => '客户国家命中高风险国家（{points} 分）。',
                'IPBurst' => '同一 IP 短时间重复下单：{count} 单（{points} 分）。',
                'HistoryFraud' => '客户历史欺诈订单：{count} 单（{points} 分）。',
                'ActiveService' => '客户已有活跃服务（{points} 分）。',
                'ClientAge' => '客户账号年龄可信：{days} 天（{points} 分）。',
                'PaidInvoices' => '客户历史已支付发票可信：{count} 张（{points} 分）。',
                'EmailVerified' => '客户邮箱已验证（{points} 分）。',
                'Whitelisted' => '命中白名单。',
                'OrderAlready' => '订单已是 {status} 状态。',
                'AlreadyProcessed' => '规则版本 {version} 已处理过，本次自动跳过。',
            ],
        ];

        $render = static function (string $key, array $replace = []) use ($texts, $language, $reason): string {
            $template = $texts[$language][$key] ?? $texts['en'][$key] ?? $reason;
            foreach ($replace as $name => $value) {
                $template = str_replace('{' . $name . '}', (string) $value, $template);
            }
            return $template;
        };

        if (preg_match('/^ProviderScore:(-?\d+(?:\.\d+)?)$/', $reason, $matches)) {
            return $render('ProviderScore', ['score' => $matches[1]]);
        }

        foreach (['ProviderFraud', 'ShortEmail', 'NumericEmail', 'HighRiskCountry', 'EmailVerified'] as $key) {
            if (preg_match('/^' . $key . '([+-]\d+(?:\.\d+)?)$/', $reason, $matches)) {
                return $render($key, ['points' => $matches[1]]);
            }
        }

        foreach (['IPBurst' => 'count', 'HistoryFraud' => 'count', 'ClientAge' => 'days', 'PaidInvoices' => 'count'] as $key => $countName) {
            if (preg_match('/^' . $key . '\((\d+)\)([+-]\d+(?:\.\d+)?)$/', $reason, $matches)) {
                return $render($key, [$countName => $matches[1], 'points' => $matches[2]]);
            }
        }

        if (preg_match('/^ActiveService([+-]\d+(?:\.\d+)?)$/', $reason, $matches)) {
            return $render('ActiveService', ['points' => $matches[1]]);
        }

        if ($reason === 'Whitelisted') {
            return $render('Whitelisted');
        }

        if (preg_match('/^Order already (.+)$/', $reason, $matches)) {
            return $render('OrderAlready', ['status' => $matches[1]]);
        }

        if (preg_match('/^AlreadyProcessed\((\d+)\)$/', $reason, $matches)) {
            return $render('AlreadyProcessed', ['version' => $matches[1]]);
        }

        return $reason;
    }
}

if (!function_exists('peakrackRiskDescribeReasons')) {
    function peakrackRiskDescribeReasons(array $reasons, string $language = 'en'): array
    {
        return array_map(static fn($reason): string => peakrackRiskDescribeReason((string) $reason, $language), $reasons);
    }
}

if (!function_exists('peakrackRiskAdminOrderPanel')) {
    function peakrackRiskAdminOrderPanel(int $orderId, string $addonUrl): string
    {
        if ($orderId <= 0) {
            return '';
        }

        try {
            $row = Capsule::table('mod_peakrack_risk_decisions')->where('order_id', $orderId)->first();
        } catch (\Throwable) {
            $row = null;
        }

        $score = $row ? number_format((float) ($row->score ?? 0), 2) : '-';
        $action = $row ? (string) ($row->action ?? 'unknown') : 'none';
        $processedAt = $row ? (string) ($row->processed_at ?? '') : '-';
        $clientId = $row ? (string) ($row->client_id ?? '') : '-';
        $reasons = $row ? peakrackRiskDecodeList($row->reasons ?? '') : [];
        $settings = peakrackRiskLoadSettings();
        $language = in_array((string) ($settings['adminLanguage'] ?? 'en'), ['en', 'zh'], true)
            ? (string) $settings['adminLanguage']
            : 'en';
        $displayReasons = peakrackRiskDescribeReasons($reasons, $language);
        $apiResult = $row ? peakrackRiskJsonDecode((string) ($row->api_result ?? ''), []) : [];
        $apiText = $apiResult === [] ? '-' : peakrackRiskJsonEncode($apiResult);
        $openUrl = $addonUrl . (str_contains($addonUrl, '?') ? '&' : '?') . 'prk_order_id=' . $orderId;
        $reasonsHtml = $reasons === []
            ? '<span class="text-muted">No reasons recorded.</span>'
            : '<ul class="prk-order-risk-reasons"><li>' . implode('</li><li>', array_map('peakrackRiskEscape', $displayReasons)) . '</li></ul>';

        $panelHtml = '<div id="prk-order-risk-panel" class="panel panel-default prk-order-risk-panel" style="display:none">'
            . '<div class="panel-heading"><strong>PeakRack Risk</strong> <span class="label label-default">' . peakrackRiskEscape($action) . '</span></div>'
            . '<div class="panel-body">'
            . '<div class="row">'
            . '<div class="col-sm-3"><strong>Score</strong><br>' . peakrackRiskEscape($score) . '</div>'
            . '<div class="col-sm-3"><strong>Action</strong><br>' . peakrackRiskEscape($action) . '</div>'
            . '<div class="col-sm-3"><strong>Client</strong><br>' . peakrackRiskEscape($clientId) . '</div>'
            . '<div class="col-sm-3"><strong>Processed</strong><br>' . peakrackRiskEscape($processedAt) . '</div>'
            . '</div>'
            . '<hr style="margin:12px 0">'
            . '<div><strong>Reasons</strong>' . $reasonsHtml . '</div>'
            . '<details style="margin-top:10px"><summary>API result</summary><pre style="white-space:pre-wrap;margin-top:8px">' . peakrackRiskEscape($apiText) . '</pre></details>'
            . '<p style="margin:12px 0 0"><a class="btn btn-xs btn-default" href="' . peakrackRiskEscape($openUrl) . '">Open manual tools</a></p>'
            . '</div>'
            . '</div>';

        $json = json_encode($panelHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return '';
        }

        return <<<HTML
<style>
.prk-order-risk-panel{margin:0 0 15px}
.prk-order-risk-reasons{margin:6px 0 0;padding-left:18px}
.prk-order-risk-reasons li{margin:2px 0}
</style>
<script>
(function () {
    var html = {$json};
    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    var panel = wrapper.firstElementChild;
    if (!panel || document.getElementById('prk-order-risk-panel')) {
        return;
    }
    var target = document.querySelector('.contentarea h1, .contentarea h2, h1, h2');
    var container = document.querySelector('.contentarea, .content, body');
    panel.style.display = '';
    if (target && target.parentNode) {
        target.parentNode.insertBefore(panel, target.nextSibling);
    } else if (container) {
        container.insertBefore(panel, container.firstChild);
    }
})();
</script>
HTML;
    }
}

if (!function_exists('peakrackRiskBool')) {
    function peakrackRiskBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }
}
