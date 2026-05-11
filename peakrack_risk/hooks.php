<?php

/**
 * Runtime hooks for the PeakRack Risk addon.
 */

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

add_hook('DailyCronJob', 1, static function (array $vars): void {
    $config = peakrackRiskLoadSettings();

    try {
        $summary = peakrackRiskCleanupRetention($config);
        if (array_sum($summary) > 0) {
            peakrackRiskAudit('info', 'Retention cleanup completed', $summary);
        }
    } catch (\Throwable $e) {
        peakrackRiskAudit('error', 'Retention cleanup error: ' . $e->getMessage());
    }
});

add_hook('AdminAreaFooterOutput', 1, static function (array $vars): string {
    $filename = strtolower((string) ($vars['filename'] ?? ''));
    $action = strtolower((string) ($_GET['action'] ?? ''));
    $orderId = (int) ($_GET['id'] ?? ($_GET['orderid'] ?? 0));

    if (!in_array($filename, ['orders', 'orders.php'], true) || $action !== 'view' || $orderId <= 0) {
        return '';
    }

    return peakrackRiskAdminOrderPanel($orderId, 'addonmodules.php?module=peakrack_risk');
});

add_hook('AfterFraudCheck', 1, static function (array $vars): void {
    $config = peakrackRiskLoadSettings();
    if (!$config['enabled']) {
        return;
    }

    peakrackRiskProcessOrder((int) ($vars['orderid'] ?? 0), $vars, $config, 'apply_rules');
});
