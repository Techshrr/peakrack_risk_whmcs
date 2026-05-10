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
            'logOnly' => false,
            'autoFraud' => false,
            'adminLanguage' => 'en',
            'reviewThreshold' => 30.0,
            'fraudThreshold' => 80.0,
            'apiRetries' => 1,
            'ipBurstWindowMinutes' => 60,
            'ipBurstOrderCount' => 3,
            'highRiskCountries' => ['NG', 'PK', 'BD', 'ID', 'RU', 'UA', 'IR', 'VN'],
            'trustedEmailDomains' => ['qq.com', 'foxmail.com'],
            'whitelistClientIds' => [],
            'whitelistClientGroupIds' => [],
            'whitelistEmailDomains' => [],
            'whitelistIpCidrs' => [],
            'weights' => [
                'providerFraud' => 60,
                'shortEmail' => 10,
                'numericEmail' => 5,
                'highRiskCountry' => 15,
                'ipBurst' => 25,
                'historyFraudMax' => 30,
                'historyFraudPerOrder' => 10,
                'activeServiceTrust' => -10,
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

        foreach ([
            'highRiskCountries',
            'trustedEmailDomains',
            'whitelistClientIds',
            'whitelistClientGroupIds',
            'whitelistEmailDomains',
            'whitelistIpCidrs',
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

        return $settings;
    }
}

if (!function_exists('peakrackRiskLoadSettings')) {
    function peakrackRiskLoadSettings(): array
    {
        $defaults = peakrackRiskDefaults();

        try {
            $row = Capsule::table('mod_peakrack_risk_settings')
                ->where('setting', 'config')
                ->first();

            if (!$row) {
                return $defaults;
            }

            $stored = peakrackRiskJsonDecode((string) $row->value, []);
            return peakrackRiskMergeSettings($defaults, $stored);
        } catch (\Throwable) {
            return $defaults;
        }
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
                'level' => $level,
                'message' => $message,
                'context' => peakrackRiskJsonEncode($context),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Avoid breaking checkout/order flow if audit persistence is unavailable.
        }

        logActivity('PeakRack Risk ' . strtoupper($level) . ': ' . $message, $clientId);
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

if (!function_exists('peakrackRiskBool')) {
    function peakrackRiskBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }
}
