<?php

/**
 * Runtime hooks for the PeakRack Risk addon.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('No direct access');
}

require_once __DIR__ . '/lib/Bootstrap.php';
require_once __DIR__ . '/lib/RiskEngine.php';
require_once __DIR__ . '/lib/Checkout.php';

add_hook('ClientAreaFooterOutput', 1, static function (array $vars): string {
    $config = peakrackRiskLoadSettings();

    if (!$config['enabled'] || !$config['checkoutEnabled'] || !peakrackCheckoutIsCheckoutPage($vars)) {
        return '';
    }

    return peakrackCheckoutScript($config, $vars);
});

add_hook('ShoppingCartValidateCheckout', 1, static function (array $vars): array {
    $config = peakrackRiskLoadSettings();

    if (!$config['enabled'] || !$config['checkoutEnabled'] || !$config['checkoutServerValidation']) {
        return [];
    }

    $checkout = $config['checkout'];
    $fieldName = (string) $checkout['fieldName'];
    $nonceFieldName = (string) $checkout['nonceFieldName'];
    $expectedValue = (string) $checkout['fieldValue'];
    $postedValue = (string) ($_POST[$fieldName] ?? ($vars[$fieldName] ?? ''));
    $postedNonce = (string) ($_POST[$nonceFieldName] ?? ($vars[$nonceFieldName] ?? ''));
    $sessionNonce = (string) ($_SESSION['peakrack_risk_checkout_nonce'] ?? '');

    if (
        hash_equals($expectedValue, $postedValue)
        && $sessionNonce !== ''
        && hash_equals($sessionNonce, $postedNonce)
    ) {
        unset($_SESSION['peakrack_risk_checkout_nonce']);
        return [];
    }

    $messages = peakrackCheckoutMessages($config, peakrackCheckoutIsChinese($vars));
    return [$messages['validation']];
});

add_hook('AfterFraudCheck', 1, static function (array $vars): void {
    $config = peakrackRiskLoadSettings();
    if (!$config['enabled']) {
        return;
    }

    try {
        $orderId = (int) ($vars['orderid'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = Capsule::table('tblorders')->where('id', $orderId)->first();
        if (!$order) {
            return;
        }

        $clientId = (int) ($order->userid ?? 0);
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) {
            return;
        }

        $currentStatus = (string) ($order->status ?? '');
        if (in_array($currentStatus, ['Fraud', 'Cancelled'], true)) {
            $skipScore = $currentStatus === 'Fraud' ? 100.0 : 0.0;
            peakrackRiskPersistDecision($orderId, $clientId, $skipScore, 'skip_' . strtolower($currentStatus), ['Order already ' . $currentStatus]);
            peakrackRiskAudit('info', "Order #{$orderId} skipped because it is already {$currentStatus}", [], $orderId, $clientId);
            return;
        }

        $decision = peakrackRiskScoreOrder($order, $client, $vars, $config);
        $score = (float) $decision['score'];
        $reasons = (array) $decision['reasons'];
        $apiResult = [];
        $action = 'pass';

        if ($decision['whitelisted'] ?? false) {
            peakrackRiskPersistDecision($orderId, $clientId, $score, 'whitelist', $reasons);
            peakrackRiskAudit('info', "Order #{$orderId} whitelisted", ['score' => $score, 'reasons' => $reasons], $orderId, $clientId);
            return;
        }

        if ($config['logOnly']) {
            peakrackRiskPersistDecision($orderId, $clientId, $score, 'log_only', $reasons);
            peakrackRiskAudit('info', "Order #{$orderId} log only score {$score}", ['reasons' => $reasons], $orderId, $clientId);
            return;
        }

        if ($score >= (float) $config['fraudThreshold']) {
            $action = 'review_high';

            if ($config['autoFraud']) {
                $apiResult = peakrackRiskRunOrderAction('FraudOrder', $orderId, ['cancelsub' => true], (int) $config['apiRetries']);
                if (($apiResult['result'] ?? '') === 'success') {
                    $action = 'fraud';
                    peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
                    peakrackRiskAudit('warning', "Order #{$orderId} marked fraud with score {$score}", ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
                    return;
                }

                peakrackRiskAudit('error', "Order #{$orderId} FraudOrder failed", ['score' => $score, 'reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
            }

            if ($currentStatus !== 'Pending') {
                $apiResult = peakrackRiskRunOrderAction('PendingOrder', $orderId, [], (int) $config['apiRetries']);
            }

            peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
            peakrackRiskAudit('warning', "Order #{$orderId} sent to high review with score {$score}", ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
            return;
        }

        if ($score >= (float) $config['reviewThreshold']) {
            $action = 'review';
            if ($currentStatus !== 'Pending') {
                $apiResult = peakrackRiskRunOrderAction('PendingOrder', $orderId, [], (int) $config['apiRetries']);
            }

            peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons, $apiResult);
            peakrackRiskAudit('info', "Order #{$orderId} sent to review with score {$score}", ['reasons' => $reasons, 'api' => $apiResult], $orderId, $clientId);
            return;
        }

        peakrackRiskPersistDecision($orderId, $clientId, $score, $action, $reasons);
        peakrackRiskAudit('info', "Order #{$orderId} passed with score {$score}", ['reasons' => $reasons], $orderId, $clientId);
    } catch (\Throwable $e) {
        peakrackRiskAudit('error', 'Risk hook error: ' . $e->getMessage());
    }
});
