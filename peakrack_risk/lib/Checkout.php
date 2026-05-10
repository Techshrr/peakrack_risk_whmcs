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

if (!function_exists('peakrackCheckoutNonce')) {
    function peakrackCheckoutNonce(): string
    {
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
        $payload = [
            'fieldName' => $checkout['fieldName'],
            'fieldValue' => $checkout['fieldValue'],
            'nonceFieldName' => $checkout['nonceFieldName'],
            'nonceValue' => peakrackCheckoutNonce(),
            'storageKey' => $checkout['storageKey'],
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

    function findCheckoutForms() {
        return Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (form) {
            var action = (form.getAttribute('action') || '').toLowerCase();
            return action.indexOf('cart.php') !== -1 && form.querySelector('[name="paymentmethod"], [name="ccinfo"], [name="accepttos"]');
        });
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

    function ensureAckFields() {
        findCheckoutForms().forEach(function (form) {
            ensureHiddenField(form, config.fieldName, config.fieldValue);
            ensureHiddenField(form, config.nonceFieldName, config.nonceValue);
        });
    }

    function hasAcknowledged() {
        try {
            return window.sessionStorage.getItem(config.storageKey) === config.fieldValue;
        } catch (e) {
            return false;
        }
    }

    function setAcknowledged() {
        try {
            window.sessionStorage.setItem(config.storageKey, config.fieldValue);
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

        injectStyles();

        var previousOverflow = document.body.style.overflow;
        var previousActive = document.activeElement;
        var overlay = createElement('div', { className: 'prk-checkout-overlay', role: 'presentation' });
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

    document.addEventListener('DOMContentLoaded', function () {
        if (hasAcknowledged()) {
            ensureAckFields();
        }

        findCheckoutForms().forEach(function (form) {
            form.addEventListener('submit', function () {
                if (hasAcknowledged()) {
                    ensureAckFields();
                }
            });
        });

        showDialog();
    });
})();
</script>
HTML;
    }
}
