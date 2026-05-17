<?php

if (!defined('WHMCS')) {
    die('No direct access');
}

if (!function_exists('peakrackCheckoutIsCheckoutPage')) {
    function peakrackCheckoutIsCheckoutPage(array $vars): bool
    {
        $filename = (string) ($vars['filename'] ?? '');
        $action = (string) ($_GET['a'] ?? '');

        return $filename === 'cart' && $action === 'checkout';
    }
}

if (!function_exists('peakrackCheckoutIsChinese')) {
    function peakrackCheckoutIsChinese(array $vars = []): bool
    {
        $language = strtolower((string) ($vars['language'] ?? ($_SESSION['Language'] ?? '')));
        return str_contains($language, 'chinese') || str_contains($language, 'zh');
    }
}

if (!function_exists('peakrackCheckoutClientId')) {
    function peakrackCheckoutClientId(array $vars = []): int
    {
        $clientDetails = $vars['clientsdetails'] ?? ($vars['clientdetails'] ?? []);
        if (is_array($clientDetails)) {
            foreach (['userid', 'id', 'client_id'] as $key) {
                if (!empty($clientDetails[$key])) {
                    return max(0, (int) $clientDetails[$key]);
                }
            }
        }

        return max(0, (int) ($_SESSION['uid'] ?? 0));
    }
}

if (!function_exists('peakrackCheckoutClientIp')) {
    function peakrackCheckoutClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $ip = trim((string) ($_SERVER[$key] ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return 'unknown';
    }
}

if (!function_exists('peakrackCheckoutScope')) {
    function peakrackCheckoutScope(array $vars = []): string
    {
        return hash('sha256', implode('|', [
            session_id(),
            peakrackCheckoutClientId($vars),
            peakrackCheckoutClientIp(),
        ]));
    }
}

if (!function_exists('peakrackCheckoutEnsureScope')) {
    function peakrackCheckoutEnsureScope(array $vars = []): string
    {
        $scope = peakrackCheckoutScope($vars);
        $storedScope = (string) ($_SESSION['peakrack_risk_checkout_scope'] ?? '');

        if (!hash_equals($scope, $storedScope)) {
            $_SESSION['peakrack_risk_checkout_scope'] = $scope;
            unset(
                $_SESSION['peakrack_risk_checkout_nonce'],
                $_SESSION['peakrack_risk_checkout_acknowledged'],
                $_SESSION['peakrack_risk_checkout_acknowledged_at']
            );
        }

        return $scope;
    }
}

if (!function_exists('peakrackCheckoutNonce')) {
    function peakrackCheckoutNonce(array $vars = []): string
    {
        peakrackCheckoutEnsureScope($vars);

        if (!empty($_SESSION['peakrack_risk_checkout_nonce']) && is_string($_SESSION['peakrack_risk_checkout_nonce'])) {
            return $_SESSION['peakrack_risk_checkout_nonce'];
        }

        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $nonce = hash('sha256', session_id() . '|' . microtime(true) . '|' . mt_rand());
        }

        $_SESSION['peakrack_risk_checkout_nonce'] = $nonce;
        return $nonce;
    }
}

if (!function_exists('peakrackCheckoutIsSessionAcknowledged')) {
    function peakrackCheckoutIsSessionAcknowledged(array $vars = []): bool
    {
        peakrackCheckoutEnsureScope($vars);
        return !empty($_SESSION['peakrack_risk_checkout_acknowledged']);
    }
}

if (!function_exists('peakrackCheckoutMarkSessionAcknowledged')) {
    function peakrackCheckoutMarkSessionAcknowledged(array $vars = []): void
    {
        peakrackCheckoutEnsureScope($vars);
        $_SESSION['peakrack_risk_checkout_acknowledged'] = true;
        $_SESSION['peakrack_risk_checkout_acknowledged_at'] = time();
    }
}

if (!function_exists('peakrackCheckoutStorageKey')) {
    function peakrackCheckoutStorageKey(array $config, array $vars = []): string
    {
        $baseKey = (string) ($config['checkout']['storageKey'] ?? 'prk_checkout_ack_v2');
        return $baseKey . ':' . substr(peakrackCheckoutScope($vars), 0, 16);
    }
}

if (!function_exists('peakrackCheckoutJson')) {
    function peakrackCheckoutJson(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('peakrackCheckoutMessages')) {
    function peakrackCheckoutMessages(array $config, bool $isChinese): array
    {
        return $isChinese ? $config['checkout']['zh'] : $config['checkout']['en'];
    }
}

if (!function_exists('peakrackCheckoutScript')) {
    function peakrackCheckoutScript(array $config, array $vars): string
    {
        $checkout = $config['checkout'];
        $nonce = peakrackCheckoutNonce($vars);
        $payload = [
            'fieldName' => $checkout['fieldName'],
            'fieldValue' => $checkout['fieldValue'],
            'nonceFieldName' => $checkout['nonceFieldName'],
            'nonceValue' => $nonce,
            'storageKey' => peakrackCheckoutStorageKey($config, $vars),
            'acknowledged' => peakrackCheckoutIsSessionAcknowledged($vars),
            'messages' => peakrackCheckoutMessages($config, peakrackCheckoutIsChinese($vars)),
        ];

        $json = peakrackCheckoutJson($payload);

        return <<<HTML
<script>
(function () {
    'use strict';

    var config = {$json};
    if (!config || !config.messages) {
        return;
    }

    if (window.__peakrackRiskCheckoutBooted) {
        return;
    }
    window.__peakrackRiskCheckoutBooted = true;

    function isCheckoutForm(form) {
        if (!form || String(form.tagName).toLowerCase() !== 'form') {
            return false;
        }

        var action = (form.getAttribute('action') || '').toLowerCase();
        var targetsCart = action === '' || action.indexOf('cart.php') !== -1 || action.indexOf('a=checkout') !== -1;
        var hasCheckoutAction = Boolean(form.querySelector('input[name="a"][value="checkout"]'));
        var hasCheckoutFields = Boolean(form.querySelector('[name="paymentmethod"], [name="ccinfo"], [name="accepttos"], [name="custtype"]'));

        return (targetsCart || hasCheckoutAction) && hasCheckoutFields;
    }

    function findCheckoutForms() {
        return Array.prototype.slice.call(document.querySelectorAll('form')).filter(isCheckoutForm);
    }

    function fieldSelector(name) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return 'input[name="' + window.CSS.escape(name) + '"]';
        }
        return 'input[name="' + String(name).replace(/"/g, '\\\\"') + '"]';
    }

    function ensureHiddenField(form, name, value) {
        var existing = form.querySelector(fieldSelector(name));
        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'hidden';
            existing.name = name;
            form.appendChild(existing);
        }
        existing.value = value;
    }

    function ensureAckFields(targetForm) {
        var forms = targetForm ? [targetForm] : findCheckoutForms();
        forms.forEach(function (form) {
            ensureHiddenField(form, config.fieldName, config.fieldValue);
            ensureHiddenField(form, config.nonceFieldName, config.nonceValue);
        });
    }

    function hasAcknowledged() {
        if (config.acknowledged === true) {
            return true;
        }

        try {
            return window.sessionStorage.getItem(config.storageKey) === '1';
        } catch (e) {
            return false;
        }
    }

    function setAcknowledged() {
        config.acknowledged = true;
        try {
            window.sessionStorage.setItem(config.storageKey, '1');
        } catch (e) {}
        ensureAckFields();
    }

    function createElement(tag, attrs, text) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'className') {
                el.className = attrs[key];
            } else {
                el.setAttribute(key, attrs[key]);
            }
        });
        if (typeof text === 'string') {
            el.textContent = text;
        }
        return el;
    }

    function injectStyles() {
        if (document.getElementById('prk-checkout-style')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'prk-checkout-style';
        style.textContent = [
            '.prk-checkout-overlay{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.58);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);opacity:0;transition:opacity .2s ease;}',
            '.prk-checkout-overlay.is-visible{opacity:1;}',
            '.prk-checkout-dialog{width:min(100%,460px);box-sizing:border-box;background:#fff;color:#111827;border-radius:10px;box-shadow:0 24px 60px rgba(15,23,42,.28);padding:28px;transform:translateY(12px);transition:transform .2s ease;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}',
            '.prk-checkout-overlay.is-visible .prk-checkout-dialog{transform:translateY(0);}',
            '.prk-checkout-title{margin:0 0 14px;font-size:20px;line-height:1.3;font-weight:650;}',
            '.prk-checkout-text{margin:0 0 14px;color:#374151;font-size:14px;line-height:1.7;}',
            '.prk-checkout-list{margin:0 0 18px;padding-left:20px;color:#4b5563;font-size:14px;line-height:1.8;}',
            '.prk-checkout-note{margin:0 0 22px;padding:12px 14px;border-left:4px solid #111827;border-radius:6px;background:#f9fafb;color:#4b5563;font-size:13px;line-height:1.6;}',
            '.prk-checkout-button{width:100%;min-height:44px;border:0;border-radius:8px;background:#111827;color:#fff;font-size:15px;font-weight:650;cursor:pointer;}',
            '.prk-checkout-button:focus{outline:3px solid rgba(37,99,235,.35);outline-offset:2px;}'
        ].join('');
        document.head.appendChild(style);
    }

    function showDialog() {
        if (hasAcknowledged()) {
            ensureAckFields();
            return;
        }

        if (document.getElementById('prk-checkout-overlay')) {
            return;
        }

        injectStyles();

        var previousOverflow = document.body.style.overflow;
        var previousActive = document.activeElement;
        var overlay = createElement('div', { className: 'prk-checkout-overlay', id: 'prk-checkout-overlay', role: 'presentation' });
        var dialog = createElement('div', {
            className: 'prk-checkout-dialog',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'prk-checkout-title',
            'aria-describedby': 'prk-checkout-description'
        });

        dialog.appendChild(createElement('h3', { className: 'prk-checkout-title', id: 'prk-checkout-title' }, config.messages.title));
        dialog.appendChild(createElement('p', { className: 'prk-checkout-text', id: 'prk-checkout-description' }, config.messages.line1));

        if (Array.isArray(config.messages.items) && config.messages.items.length > 0) {
            var list = createElement('ul', { className: 'prk-checkout-list' });
            config.messages.items.forEach(function (item) {
                list.appendChild(createElement('li', {}, item));
            });
            dialog.appendChild(list);
        }
        dialog.appendChild(createElement('p', { className: 'prk-checkout-note' }, config.messages.footer));

        var button = createElement('button', { className: 'prk-checkout-button', type: 'button' }, config.messages.button);
        dialog.appendChild(button);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        button.addEventListener('click', function () {
            setAcknowledged();
            overlay.classList.remove('is-visible');
            window.setTimeout(function () {
                overlay.remove();
                document.body.style.overflow = previousOverflow;
                if (previousActive && typeof previousActive.focus === 'function') {
                    previousActive.focus();
                }
            }, 200);
        });

        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Tab') {
                event.preventDefault();
                button.focus();
            }
        });

        window.requestAnimationFrame(function () {
            overlay.classList.add('is-visible');
            button.focus();
        });
    }

    function boot() {
        if (hasAcknowledged()) {
            ensureAckFields();
        }

        document.addEventListener('submit', function (event) {
            if (isCheckoutForm(event.target) && hasAcknowledged()) {
                ensureAckFields(event.target);
            }
        }, true);

        if (window.MutationObserver && document.body) {
            var observer = new MutationObserver(function () {
                if (hasAcknowledged()) {
                    ensureAckFields();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        showDialog();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
HTML;
    }
}
