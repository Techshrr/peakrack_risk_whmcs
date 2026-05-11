<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('No direct access');
}

if (!function_exists('peakrackRiskNormalizeScore')) {
    function peakrackRiskNormalizeScore(float $score): float
    {
        if ($score > 0 && $score <= 1) {
            $score *= 100;
        }

        return max(0.0, min(100.0, $score));
    }
}

if (!function_exists('peakrackRiskFindScore')) {
    function peakrackRiskFindScore(mixed $value): ?float
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return null;
        }

        $scoreKeys = [
            'score',
            'risk_score',
            'riskscore',
            'riskScore',
            'fraud_score',
            'fraudscore',
            'fraudScore',
            'maxmind_score',
            'risk',
        ];

        foreach ($value as $key => $item) {
            if (in_array((string) $key, $scoreKeys, true) && is_numeric($item)) {
                return peakrackRiskNormalizeScore((float) $item);
            }

            if (is_array($item) || is_object($item)) {
                $found = peakrackRiskFindScore($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}

if (!function_exists('peakrackRiskIpMatchesCidr')) {
    function peakrackRiskIpMatchesCidr(string $ip, string $cidr): bool
    {
        if ($ip === '' || $cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return hash_equals($ip, $cidr);
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        $ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 0
        );
        $networkVersion = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : (
            filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 0
        );

        if ($ipVersion === 0 || $networkVersion === 0 || $ipVersion !== $networkVersion) {
            return false;
        }

        $maxPrefix = $ipVersion === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $networkBin = inet_pton($network);

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask);
    }
}

if (!function_exists('peakrackRiskIsWhitelisted')) {
    function peakrackRiskIsWhitelisted(object $order, object $client, string $emailDomain, string $ip, array $config): bool
    {
        $clientId = (int) ($client->id ?? 0);
        $groupId = (int) ($client->groupid ?? 0);
        $paymentMethod = strtolower(trim((string) ($order->paymentmethod ?? '')));

        if (in_array($clientId, $config['whitelistClientIds'], true)) {
            return true;
        }

        if ($groupId > 0 && in_array($groupId, $config['whitelistClientGroupIds'], true)) {
            return true;
        }

        if ($emailDomain !== '' && in_array($emailDomain, $config['whitelistEmailDomains'], true)) {
            return true;
        }

        foreach ($config['whitelistIpCidrs'] as $cidr) {
            if (peakrackRiskIpMatchesCidr($ip, (string) $cidr)) {
                return true;
            }
        }

        if ($paymentMethod !== '' && in_array($paymentMethod, $config['whitelistPaymentMethods'], true)) {
            return true;
        }

        $productWhitelist = (array) ($config['whitelistProductIds'] ?? []);
        if ($productWhitelist !== []) {
            foreach (peakrackRiskOrderProductIds((int) ($order->id ?? 0)) as $productId) {
                if (in_array($productId, $productWhitelist, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('peakrackRiskOrderProductIds')) {
    function peakrackRiskOrderProductIds(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        try {
            $rows = Capsule::table('tblhosting')
                ->where('orderid', $orderId)
                ->pluck('packageid')
                ->all();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $rows), static fn(int $item): bool => $item > 0)));
    }
}

if (!function_exists('peakrackRiskClientAgeDays')) {
    function peakrackRiskClientAgeDays(object $client): int
    {
        $date = trim((string) ($client->datecreated ?? ''));
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return 0;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false || $timestamp <= 0) {
            return 0;
        }

        return max(0, (int) floor((time() - $timestamp) / 86400));
    }
}

if (!function_exists('peakrackRiskClientPaidInvoiceCount')) {
    function peakrackRiskClientPaidInvoiceCount(int $clientId, int $limit): int
    {
        if ($clientId <= 0 || $limit <= 0) {
            return 0;
        }

        try {
            return peakrackRiskCountUpTo(
                Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Paid'),
                $limit
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}

if (!function_exists('peakrackRiskClientEmailVerified')) {
    function peakrackRiskClientEmailVerified(object $client): bool
    {
        foreach (['email_verified', 'emailverified', 'emailVerified'] as $field) {
            if (isset($client->{$field}) && peakrackRiskBool($client->{$field})) {
                return true;
            }
        }

        foreach (['email_verified_at', 'emailVerifiedAt'] as $field) {
            if (isset($client->{$field}) && trim((string) $client->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('peakrackRiskRunOrderAction')) {
    function peakrackRiskRunOrderAction(string $command, int $orderId, array $params = [], int $retries = 1): array
    {
        $attempts = max(1, $retries);
        $lastResult = [
            'result' => 'error',
            'message' => 'localAPI was not executed',
        ];

        if (!function_exists('localAPI')) {
            return [
                'result' => 'error',
                'message' => 'localAPI is unavailable',
                'attempts' => 0,
            ];
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = localAPI($command, array_merge(['orderid' => $orderId], $params));
                $lastResult = is_array($result) ? $result : [
                    'result' => 'error',
                    'message' => 'localAPI returned a non-array response',
                ];
            } catch (\Throwable $e) {
                $lastResult = [
                    'result' => 'error',
                    'message' => $e->getMessage(),
                ];
            }

            if (($lastResult['result'] ?? '') === 'success') {
                return $lastResult + ['attempts' => $attempt];
            }
        }

        return $lastResult + ['attempts' => $attempts];
    }
}

if (!function_exists('peakrackRiskCountUpTo')) {
    function peakrackRiskCountUpTo(mixed $query, int $limit): int
    {
        if ($limit <= 0) {
            return (int) $query->count();
        }

        return (int) $query->limit($limit)->get(['id'])->count();
    }
}

if (!function_exists('peakrackRiskScoreOrder')) {
    function peakrackRiskScoreOrder(object $order, object $client, array $vars, array $config): array
    {
        $clientId = (int) ($client->id ?? 0);
        $email = strtolower(trim((string) ($client->email ?? '')));
        $country = strtoupper(trim((string) ($client->country ?? '')));
        $ip = trim((string) ($order->ipaddress ?? ($vars['clientdetails']['ip'] ?? '')));

        [$emailUser, $emailDomain] = array_pad(explode('@', $email, 2), 2, '');

        if (peakrackRiskIsWhitelisted($order, $client, $emailDomain, $ip, $config)) {
            return [
                'score' => 0.0,
                'reasons' => ['Whitelisted'],
                'ip' => $ip,
                'whitelisted' => true,
            ];
        }

        $weights = $config['weights'];
        $providerScore = peakrackRiskFindScore($vars['fraudresults'] ?? []);
        $risk = $providerScore ?? 0.0;
        $reasons = ['ProviderScore:' . number_format($risk, 2, '.', '')];

        if (!empty($vars['isfraud'])) {
            $add = (float) $weights['providerFraud'];
            $risk += $add;
            $reasons[] = "ProviderFraud+{$add}";
        }

        if ($emailUser !== '' && strlen($emailUser) <= 4) {
            $add = (float) $weights['shortEmail'];
            $risk += $add;
            $reasons[] = "ShortEmail+{$add}";
        }

        if ($emailUser !== '' && preg_match('/\d{3,}/', $emailUser) && !in_array($emailDomain, $config['trustedEmailDomains'], true)) {
            $add = (float) $weights['numericEmail'];
            $risk += $add;
            $reasons[] = "NumericEmail+{$add}";
        }

        if (in_array($country, $config['highRiskCountries'], true)) {
            $add = (float) $weights['highRiskCountry'];
            $risk += $add;
            $reasons[] = "HighRiskCountry+{$add}";
        }

        if ($ip !== '') {
            $windowStart = date('Y-m-d H:i:s', time() - ((int) $config['ipBurstWindowMinutes'] * 60));
            $burstThreshold = max(1, (int) $config['ipBurstOrderCount']);
            $burstCount = Capsule::table('tblorders')
                ->where('ipaddress', $ip)
                ->where('date', '>', $windowStart)
                ->limit($burstThreshold)
                ->get(['id'])
                ->count();

            if ($burstCount >= $burstThreshold) {
                $add = (float) $weights['ipBurst'];
                $risk += $add;
                $reasons[] = "IPBurst({$burstCount})+{$add}";
            }
        }

        $historyFraudPerOrder = (float) $weights['historyFraudPerOrder'];
        $historyFraudMax = (float) $weights['historyFraudMax'];
        $historyLimit = $historyFraudPerOrder > 0.0 && $historyFraudMax > 0.0
            ? max(1, (int) ceil($historyFraudMax / $historyFraudPerOrder))
            : 0;
        $fraudHistory = peakrackRiskCountUpTo(Capsule::table('tblorders')
            ->where('userid', $clientId)
            ->where('id', '!=', (int) ($order->id ?? 0))
            ->where('status', 'Fraud'), $historyLimit);

        if ($fraudHistory > 0) {
            $add = min(((int) $fraudHistory) * $historyFraudPerOrder, $historyFraudMax);
            $risk += $add;
            $reasons[] = "HistoryFraud({$fraudHistory})+{$add}";
        }

        $hasActiveService = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->where('domainstatus', 'Active')
            ->exists();

        if ($hasActiveService) {
            $add = (float) $weights['activeServiceTrust'];
            $risk += $add;
            $reasons[] = "ActiveService{$add}";
        }

        $trustedClientAgeDays = (int) ($config['trustedClientAgeDays'] ?? 0);
        if ($trustedClientAgeDays > 0) {
            $clientAgeDays = peakrackRiskClientAgeDays($client);
            if ($clientAgeDays >= $trustedClientAgeDays) {
                $add = (float) $weights['clientAgeTrust'];
                $risk += $add;
                $reasons[] = "ClientAge({$clientAgeDays}){$add}";
            }
        }

        $trustedPaidInvoiceCount = (int) ($config['trustedPaidInvoiceCount'] ?? 0);
        if ($trustedPaidInvoiceCount > 0) {
            $paidInvoiceCount = peakrackRiskClientPaidInvoiceCount($clientId, $trustedPaidInvoiceCount);
            if ($paidInvoiceCount >= $trustedPaidInvoiceCount) {
                $add = (float) $weights['paidInvoiceTrust'];
                $risk += $add;
                $reasons[] = "PaidInvoices({$paidInvoiceCount}){$add}";
            }
        }

        if (!empty($config['emailVerifiedTrustEnabled']) && peakrackRiskClientEmailVerified($client)) {
            $add = (float) $weights['emailVerifiedTrust'];
            $risk += $add;
            $reasons[] = "EmailVerified{$add}";
        }

        return [
            'score' => peakrackRiskNormalizeScore($risk),
            'reasons' => $reasons,
            'ip' => $ip,
            'whitelisted' => false,
        ];
    }
}

if (!function_exists('peakrackRiskProcessOrder')) {
    function peakrackRiskProcessOrder(int $orderId, array $vars, array $config, string $mode = 'apply_rules', bool $allowRepeat = false): array
    {
        $mode = in_array($mode, ['score_only', 'apply_rules', 'force_pending', 'force_fraud'], true) ? $mode : 'apply_rules';

        try {
            if ($orderId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid order ID.',
                ];
            }

            $order = Capsule::table('tblorders')->where('id', $orderId)->first();
            if (!$order) {
                return [
                    'success' => false,
                    'message' => "Order #{$orderId} was not found.",
                ];
            }

            $clientId = (int) ($order->userid ?? 0);
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$client) {
                return [
                    'success' => false,
                    'order_id' => $orderId,
                    'client_id' => $clientId,
                    'message' => "Client #{$clientId} was not found.",
                ];
            }

            $currentRuleVersion = peakrackRiskCurrentRuleVersion();
            if (!$allowRepeat && $mode === 'apply_rules' && $currentRuleVersion > 0) {
                $existingDecision = Capsule::table('mod_peakrack_risk_decisions')->where('order_id', $orderId)->first();
                if ($existingDecision && (int) ($existingDecision->rule_version ?? 0) === $currentRuleVersion) {
                    $reasons = ["AlreadyProcessed({$currentRuleVersion})"];
                    peakrackRiskAudit('info', "Order #{$orderId} skipped because rule version {$currentRuleVersion} was already processed", [], $orderId, $clientId);

                    return [
                        'success' => true,
                        'order_id' => $orderId,
                        'client_id' => $clientId,
                        'score' => (float) ($existingDecision->score ?? 0),
                        'action' => 'skip_processed',
                        'reasons' => $reasons,
                        'api_result' => [],
                        'message' => "Order #{$orderId} skipped because rule version {$currentRuleVersion} was already processed.",
                    ];
                }
            }

            $currentStatus = (string) ($order->status ?? '');
            if ($mode === 'apply_rules' && in_array($currentStatus, ['Fraud', 'Cancelled'], true)) {
                $skipScore = $currentStatus === 'Fraud' ? 100.0 : 0.0;
                $action = 'skip_' . strtolower($currentStatus);
                $reasons = ['Order already ' . $currentStatus];
                peakrackRiskPersistDecision($orderId, $clientId, $skipScore, $action, $reasons);
                peakrackRiskAudit('info', "Order #{$orderId} skipped because it is already {$currentStatus}", [], $orderId, $clientId);

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'client_id' => $clientId,
                    'score' => $skipScore,
                    'action' => $action,
                    'reasons' => $reasons,
                    'api_result' => [],
                    'message' => "Order #{$orderId} skipped because it is already {$currentStatus}.",
                ];
            }

            $decision = peakrackRiskScoreOrder($order, $client, $vars, $config);
            $score = (float) $decision['score'];
            $reasons = (array) $decision['reasons'];
            $apiResult = [];
            $action = 'pass';
            $auditLevel = 'info';
            $message = "Order #{$orderId} passed with score {$score}";

            if ($mode === 'score_only') {
                $action = 'manual_score';
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons);
                peakrackRiskAudit('info', "Order #{$orderId} manually rescored {$score}", ['reasons' => $reasons], $orderId, $clientId);

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'client_id' => $clientId,
                    'score' => $score,
                    'action' => $action,
                    'reasons' => $reasons,
                    'api_result' => [],
                    'message' => "Order #{$orderId} manually rescored {$score}.",
                ];
            }

            if ($mode === 'force_pending') {
                $action = 'manual_pending';
                if ($currentStatus !== 'Pending') {
                    $apiResult = peakrackRiskRunOrderAction('PendingOrder', $orderId, [], (int) $config['apiRetries']);
                }

                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                $auditLevel = (($apiResult['result'] ?? 'success') === 'error') ? 'error' : 'warning';
                peakrackRiskAudit($auditLevel, "Order #{$orderId} manually sent to Pending with score {$score}", ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);

                return [
                    'success' => (($apiResult['result'] ?? 'success') !== 'error'),
                    'order_id' => $orderId,
                    'client_id' => $clientId,
                    'score' => $score,
                    'action' => $action,
                    'reasons' => $reasons,
                    'api_result' => $apiResult,
                    'message' => "Order #{$orderId} manual Pending action completed with score {$score}.",
                ];
            }

            if ($mode === 'force_fraud') {
                $action = 'manual_fraud';
                if ($currentStatus !== 'Fraud') {
                    $apiResult = peakrackRiskRunOrderAction('FraudOrder', $orderId, ['cancelsub' => true], (int) $config['apiRetries']);
                }

                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                $auditLevel = (($apiResult['result'] ?? 'success') === 'error') ? 'error' : 'warning';
                peakrackRiskAudit($auditLevel, "Order #{$orderId} manually marked Fraud with score {$score}", ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);

                return [
                    'success' => (($apiResult['result'] ?? 'success') !== 'error'),
                    'order_id' => $orderId,
                    'client_id' => $clientId,
                    'score' => $score,
                    'action' => $action,
                    'reasons' => $reasons,
                    'api_result' => $apiResult,
                    'message' => "Order #{$orderId} manual Fraud action completed with score {$score}.",
                ];
            }

            if ($decision['whitelisted'] ?? false) {
                $action = 'whitelist';
                $message = "Order #{$orderId} whitelisted";
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons);
                peakrackRiskAudit('info', $message, ['score' => $score, 'reasons' => $reasons], $orderId, $clientId);
            } elseif ($config['logOnly']) {
                $action = 'log_only';
                $message = "Order #{$orderId} log only score {$score}";
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons);
                peakrackRiskAudit('info', $message, ['reasons' => $reasons], $orderId, $clientId);
            } elseif ($score >= (float) $config['fraudThreshold']) {
                $action = 'review_high';
                $auditLevel = 'warning';

                if ($config['autoFraud']) {
                    $apiResult = peakrackRiskRunOrderAction('FraudOrder', $orderId, ['cancelsub' => true], (int) $config['apiRetries']);
                    if (($apiResult['result'] ?? '') === 'success') {
                        $action = 'fraud';
                        $message = "Order #{$orderId} marked fraud with score {$score}";
                        peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                        peakrackRiskAudit('warning', $message, ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);

                        return [
                            'success' => true,
                            'order_id' => $orderId,
                            'client_id' => $clientId,
                            'score' => $score,
                            'action' => $action,
                            'reasons' => $reasons,
                            'api_result' => $apiResult,
                            'message' => $message,
                        ];
                    }

                    peakrackRiskAudit('error', "Order #{$orderId} FraudOrder failed", ['score' => $score, 'reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
                }

                if ($currentStatus !== 'Pending') {
                    $apiResult = peakrackRiskRunOrderAction('PendingOrder', $orderId, [], (int) $config['apiRetries']);
                }

                $message = "Order #{$orderId} sent to high review with score {$score}";
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                peakrackRiskAudit($auditLevel, $message, ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
            } elseif ($score >= (float) $config['reviewThreshold']) {
                $action = 'review';
                if ($currentStatus !== 'Pending') {
                    $apiResult = peakrackRiskRunOrderAction('PendingOrder', $orderId, [], (int) $config['apiRetries']);
                }

                $message = "Order #{$orderId} sent to review with score {$score}";
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                peakrackRiskAudit('info', $message, ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
            } else {
                peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons);
                peakrackRiskAudit('info', $message, ['reasons' => $reasons], $orderId, $clientId);
            }

            return [
                'success' => (($apiResult['result'] ?? 'success') !== 'error'),
                'order_id' => $orderId,
                'client_id' => $clientId,
                'score' => $score,
                'action' => $action,
                'reasons' => $reasons,
                'api_result' => $apiResult,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            peakrackRiskAudit('error', 'Risk order process error: ' . $e->getMessage());
            return [
                'success' => false,
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('peakrackRiskDecisionHasRuleVersion')) {
    function peakrackRiskDecisionHasRuleVersion(): bool
    {
        if (isset($GLOBALS['peakrackRiskDecisionRuleVersionColumn'])) {
            return (bool) $GLOBALS['peakrackRiskDecisionRuleVersionColumn'];
        }

        try {
            $hasColumn = Capsule::schema()->hasTable('mod_peakrack_risk_decisions')
                && Capsule::schema()->hasColumn('mod_peakrack_risk_decisions', 'rule_version');
        } catch (\Throwable) {
            $hasColumn = false;
        }

        $GLOBALS['peakrackRiskDecisionRuleVersionColumn'] = $hasColumn;
        return $hasColumn;
    }
}

if (!function_exists('peakrackRiskPersistDecision')) {
    function peakrackRiskPersistDecision(int $orderId, int $clientId, float $score, string $action, array $reasons, array $apiResult = []): void
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'client_id' => $clientId,
            'score' => $score,
            'action' => $action,
            'reasons' => peakrackRiskJsonEncode($reasons),
            'api_result' => peakrackRiskJsonEncode($apiResult),
            'processed_at' => $now,
            'updated_at' => $now,
        ];

        if (peakrackRiskDecisionHasRuleVersion()) {
            $data['rule_version'] = peakrackRiskCurrentRuleVersion() ?: null;
        }

        Capsule::table('mod_peakrack_risk_decisions')->updateOrInsert(
            ['order_id' => $orderId],
            $data
        );
    }
}
