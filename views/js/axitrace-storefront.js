/**
 * AxiTrace for PrestaShop - storefront script.
 *
 * Reads the shop configuration (#axitrace-prestashop-config) and the page context
 * (#axitrace-prestashop-context) rendered by the module, loads the AxiTrace SDK from the
 * tracking domain and sends the commerce events through the SDK directly (never through the
 * dataLayer, which would fire the merchant's own Google Tag Manager tags):
 *   - product.view       product page (page view itself is sent by the SDK on init)
 *   - product.addToCart  PrestaShop 'updateCart' event with reason.linkAction 'add-to-cart'
 *                        (product page, quick view and listing buttons; core.js, both themes)
 *   - begin_checkout     once per cart content per tab: the PrestaShop checkout reloads the page at
 *                        every step (personal information, addresses, shipping, payment), so the
 *                        cart signature is kept in sessionStorage; a changed cart starts again
 *   - add_payment_info   once per cart content per tab, when a payment option is first selected
 *   - transaction.charge order confirmation page, only when the module rendered a purchase (the
 *                        order is paid or cash on delivery), with the server purchase's eventSalt
 *                        so AxiTrace keeps one; once per order id (sessionStorage guard)
 *
 * Consent (port of the Shopware plugin bootstrap): only the POLICY is rendered into the page
 * (consentMode, consentCookie); the DECISION is made here, in the visitor's own browser, because a
 * page cache shares the HTML across visitors. Mode 'off' loads the SDK at once and leaves the
 * decision to the AxiTrace workspace policy. Modes 'browser' and 'all' load nothing (no cookie,
 * no storage, no request) until a grant signal: the consent cookie (500 ms poll for 60 s) or
 * window.axitraceConsent.grant(). window.axitraceConsent.revoke() forwards a withdrawal.
 *
 * Customer groups excluded from tracking: when config.statusUrl is set, the uncached status
 * controller is asked before the SDK loads; {"tracked": false} keeps the SDK off.
 *
 * Product data never comes from the 'updateCart' response payload (its shape differs per theme):
 * it comes from the context block, or from the listing miniature for an add to cart outside the
 * product page.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (root && root.document) {
        api.boot(root, root.document);
    }
    // Unit tests (node --test) load the file as a CommonJS module, where there is no document.
    if (typeof module === 'object' && module && module.exports) {
        module.exports = api;
    }
}(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    var CONFIG_ID = 'axitrace-prestashop-config';
    var CONTEXT_ID = 'axitrace-prestashop-context';
    var SOURCE = 'prestashop';

    // Cookie values that are PRESENT but do not mean consent. Must stay character-for-character
    // identical to CONSENT_DENY_VALUES in web-sdk/velitrack-sdk.js and ConsentGate::DENY_VALUES
    // (src/Consent/ConsentGate.php); tests/fixtures/sdk_deny_values.txt pins all three.
    var DENY_VALUES = ["", "0", "false", "no", "deny", "denied", "opt-out"];

    var SDK_POLL_INTERVAL_MS = 250;
    var SDK_POLL_MAX_ATTEMPTS = 40; // 10 s
    var CONSENT_POLL_INTERVAL_MS = 500;
    var CONSENT_POLL_MAX_TICKS = 120; // 60 s
    var MAX_PENDING_EVENTS = 50;
    var PURCHASE_GUARD_PREFIX = 'axitrace_ps_purchase_';
    var CHECKOUT_GUARD_PREFIX = 'axitrace_ps_checkout_';
    var PAYMENT_INFO_GUARD_PREFIX = 'axitrace_ps_payment_info_';
    var UUID_SHAPE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    var ADD_FORM_MAX_AGE_MS = 30000;

    function isGrantSignal(raw) {
        if (raw === null || raw === undefined) {
            return false;
        }
        return DENY_VALUES.indexOf(String(raw).trim().toLowerCase()) === -1;
    }

    function readCookie(doc, name) {
        if (!name) {
            return null;
        }
        try {
            var parts = ('; ' + (doc.cookie || '')).split('; ' + name + '=');
            if (parts.length < 2) {
                return null;
            }
            return parts.pop().split(';').shift() || '';
        } catch (e) {
            return null;
        }
    }

    function readJson(doc, id) {
        var el = doc.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            var parsed = JSON.parse(el.textContent || '');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function toId(value) {
        var n = parseInt(value, 10);
        return isFinite(n) && n > 0 ? n : 0;
    }

    function toNumber(value) {
        var n = typeof value === 'number' ? value : parseFloat(value);
        return isFinite(n) ? n : 0;
    }

    /**
     * A displayed price ("€19.12", "19,12 kr", "1 234,50 €", "$1,234.50") as a number, or null.
     * The last separator followed by one or two digits is the decimal separator.
     */
    function parsePrice(text) {
        if (typeof text !== 'string') {
            return null;
        }
        var cleaned = text.replace(/[^0-9.,]/g, '');
        if (cleaned === '' || !/[0-9]/.test(cleaned)) {
            return null;
        }
        var match = cleaned.match(/^(.*)[.,]([0-9]{1,2})$/);
        var integer = match ? match[1] : cleaned;
        var fraction = match ? match[2] : '';
        integer = integer.replace(/[.,]/g, '');
        var value = parseFloat(integer + (fraction !== '' ? '.' + fraction : ''));
        return isFinite(value) ? value : null;
    }

    function round2(value) {
        return Math.round(toNumber(value) * 100) / 100;
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (matches(el, selector)) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    function matches(el, selector) {
        var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
        return !!(fn && fn.call(el, selector));
    }

    function textOf(el) {
        return el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }

    /**
     * One product line in the shape every AxiTrace destination reads (Shopware/Magento parity).
     */
    function productLine(product, quantity, currency) {
        var line = {
            id: product.productId,
            productId: product.productId,
            variantId: product.variantId,
            name: product.name || '',
            price: round2(product.price),
            currency: currency,
            quantity: quantity
        };
        if (product.sku) {
            line.sku = product.sku;
        }
        if (product.category) {
            line.category = product.category;
        }
        if (product.brand) {
            line.brand = product.brand;
        }
        return line;
    }

    function productEventData(product, quantity, currency) {
        var line = productLine(product, quantity, currency);
        var data = {
            id: line.id,
            productId: line.productId,
            variantId: line.variantId,
            name: line.name,
            price: line.price,
            currency: currency,
            quantity: quantity,
            value: round2(line.price * quantity),
            products: [line],
            source: SOURCE
        };
        if (line.sku) {
            data.sku = line.sku;
        }
        if (line.category) {
            data.category = line.category;
        }
        if (line.brand) {
            data.brand = line.brand;
        }
        return data;
    }

    function cartEventData(context, extra) {
        var currency = context.currency || '';
        var items = context.items || [];
        var products = [];
        for (var i = 0; i < items.length; i++) {
            products.push(productLine(items[i], toId(items[i].quantity) || 1, currency));
        }
        var data = {
            value: round2(context.value),
            currency: currency,
            itemCount: toId(context.itemCount),
            products: products,
            source: SOURCE
        };
        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    data[key] = extra[key];
                }
            }
        }
        return data;
    }

    /**
     * Identifies the cart content of a checkout page (currency, value, lines and quantities), so a
     * step reload of the same checkout is recognised while a changed cart counts as a new checkout.
     */
    function cartSignature(context) {
        var items = context.items || [];
        var parts = [String(context.currency || ''), String(round2(context.value))];
        for (var i = 0; i < items.length; i++) {
            parts.push(String(toId(items[i].productId)) + ':' + String(toId(items[i].variantId)) + 'x' + String(toId(items[i].quantity)));
        }
        return parts.join('|');
    }

    /** Hand-off guard: true when the event may leave now; marks the tab so a reload does not resend. */
    function onceForTab(win, key) {
        if (sessionFlag(win, key)) {
            return false;
        }
        setSessionFlag(win, key);
        return true;
    }

    function sessionFlag(win, key) {
        try {
            return !!(win.sessionStorage && win.sessionStorage.getItem(key) === '1');
        } catch (e) {
            return false;
        }
    }

    function setSessionFlag(win, key) {
        try {
            if (win.sessionStorage) {
                win.sessionStorage.setItem(key, '1');
            }
        } catch (e) {
            // Storage unavailable: a reload may send the purchase again, which the shared
            // eventSalt deduplicates in AxiTrace.
        }
    }

    /**
     * Creates the storefront tracker for one page. Returns null when the page has no usable
     * configuration or the script already ran (double init guard).
     */
    function boot(win, doc) {
        if (win.__axitracePrestaShopInit) {
            return null;
        }
        var config = readJson(doc, CONFIG_ID);
        if (!config || !config.publicKey) {
            return null;
        }
        win.__axitracePrestaShopInit = true;

        var context = readJson(doc, CONTEXT_ID) || {};
        var gated = config.consentMode === 'browser' || config.consentMode === 'all';

        var tracker = null;
        var pending = [];
        var booted = false;
        var revoked = false;
        var excluded = false;
        var consentTimer = null;
        var lastAddForm = null;
        var lastAddFormAt = 0;
        var checkoutSent = false;
        var paymentInfoSent = false;
        var handledReasons = [];

        function debug(message, detail) {
            if (config.debug && win.console && win.console.debug) {
                win.console.debug('[AxiTrace] ' + message, detail === undefined ? '' : detail);
            }
        }

        // ---------------------------------------------------------------- sending

        // beforeTrack (optional) runs when the event is handed to the SDK, not when it is queued:
        // returning false drops it (the purchase guard must not be set for an event that never
        // left, for example while consent is still pending).
        function send(eventName, data, beforeTrack) {
            if (tracker) {
                if (beforeTrack && beforeTrack() === false) {
                    return;
                }
                try {
                    tracker.track(eventName, data);
                    debug('track ' + eventName, data);
                } catch (e) {
                    debug('track ' + eventName + ' failed', e);
                }
                return;
            }
            if (pending.length < MAX_PENDING_EVENTS) {
                pending.push([eventName, data, beforeTrack]);
            }
        }

        function flush() {
            var queued = pending;
            pending = [];
            for (var i = 0; i < queued.length; i++) {
                send(queued[i][0], queued[i][1], queued[i][2]);
            }
        }

        // ---------------------------------------------------------------- SDK loading

        function initSdk() {
            var options = { publicKey: config.publicKey, apiUrl: config.apiUrl, debug: !!config.debug };
            if (gated) {
                // This page gates consent on top of the workspace policy; the grant was already
                // resolved here (the SDK loads only after one), so it is handed over at once.
                options.requireConsent = true;
                if (config.consentCookie) {
                    options.consentCookie = config.consentCookie;
                }
            }
            try {
                var instance = win.Axitrace.init(options);
                if (gated && typeof win.Axitrace.grant === 'function') {
                    win.Axitrace.grant();
                }
                tracker = instance && typeof instance.track === 'function' ? instance
                    : (typeof win.Axitrace.track === 'function' ? win.Axitrace : null);
            } catch (e) {
                debug('SDK init failed', e);
                return;
            }
            if (tracker) {
                flush();
            }
        }

        function waitForSdk(attempt) {
            if (win.Axitrace && typeof win.Axitrace.init === 'function') {
                initSdk();
                return;
            }
            if (attempt >= SDK_POLL_MAX_ATTEMPTS) {
                debug('SDK not loaded after ' + attempt + ' polls');
                return;
            }
            win.setTimeout(function () { waitForSdk(attempt + 1); }, SDK_POLL_INTERVAL_MS);
        }

        function injectAndInit() {
            try {
                if (!doc.querySelector('script[data-axitrace-sdk]') && config.sdkUrl) {
                    var script = doc.createElement('script');
                    script.src = config.sdkUrl;
                    script.async = true;
                    script.setAttribute('data-axitrace-sdk', '1');
                    script.setAttribute('data-axitrace-prestashop', '1');
                    (doc.head || doc.documentElement).appendChild(script);
                }
            } catch (e) {
                debug('SDK script injection failed', e);
            }
            waitForSdk(0);
        }

        function stopWaiting() {
            if (consentTimer !== null) {
                win.clearInterval(consentTimer);
                consentTimer = null;
            }
        }

        // Idempotent: concurrent grant signals produce one script tag and one init chain.
        function loadAndInit() {
            if (booted) {
                return;
            }
            booted = true;
            stopWaiting();

            if (!config.statusUrl || typeof win.fetch !== 'function') {
                injectAndInit();
                return;
            }

            // Fail-open: when the question cannot be answered the SDK starts; the purchase of
            // an excluded customer is still held back server-side, where the group is known.
            try {
                win.fetch(config.statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                }).then(function (response) {
                    return response && response.ok ? response.json() : null;
                }).then(function (status) {
                    if (status && status.tracked === false) {
                        excluded = true;
                        pending = [];
                        debug('customer group excluded from tracking');
                        return;
                    }
                    injectAndInit();
                }, function () {
                    injectAndInit();
                });
            } catch (e) {
                injectAndInit();
            }
        }

        // ---------------------------------------------------------------- consent

        function grantConsent() {
            revoked = false;
            if (!booted) {
                loadAndInit();
                return;
            }
            try {
                if (win.Axitrace && typeof win.Axitrace.grant === 'function') {
                    win.Axitrace.grant();
                }
            } catch (e) {
                debug('consent grant failed', e);
            }
        }

        function revokeConsent() {
            if (!booted) {
                return;
            }
            revoked = true;
            try {
                if (win.Axitrace && typeof win.Axitrace.revoke === 'function') {
                    win.Axitrace.revoke();
                }
            } catch (e) {
                debug('consent revoke failed', e);
            }
        }

        function consentCookieGranted() {
            return isGrantSignal(readCookie(doc, config.consentCookie));
        }

        function installConsentApi() {
            var stub = win.axitraceConsent;
            win.axitraceConsent = {
                grant: function () { grantConsent(); },
                revoke: function () { revokeConsent(); },
                isGranted: function () { return booted && !revoked && !excluded; }
            };
            // Calls a consent tool made before this script ran (header stub queue).
            var queued = stub && stub._queue && stub._queue.length ? stub._queue.slice() : [];
            for (var i = 0; i < queued.length; i++) {
                if (queued[i] === 'grant') {
                    grantConsent();
                } else if (queued[i] === 'revoke') {
                    revokeConsent();
                }
            }
        }

        // ---------------------------------------------------------------- commerce events

        function contextProduct(productId) {
            var product = context.type === 'product' ? context.product : null;
            return product && toId(product.productId) === productId ? product : null;
        }

        function miniatureProduct(productId, variantId) {
            var nodes = doc.querySelectorAll('[data-id-product="' + productId + '"]');
            for (var i = 0; i < nodes.length; i++) {
                var tile = closest(nodes[i], '.js-product-miniature, .product-miniature, article');
                if (!tile) {
                    continue;
                }
                var title = tile.querySelector('.product-miniature__title, .product-title');
                var price = tile.querySelector('.product-miniature__price, .price, [itemprop="price"]');
                var priceValue = price ? (price.getAttribute('content') || textOf(price)) : '';
                return {
                    productId: String(productId),
                    variantId: String(variantId),
                    name: textOf(title),
                    price: parsePrice(priceValue) || 0
                };
            }
            return null;
        }

        function resolveProduct(productId, variantId) {
            var fromContext = contextProduct(productId);
            if (fromContext) {
                var variants = fromContext.variants || {};
                var price = variantId > 0 && Object.prototype.hasOwnProperty.call(variants, String(variantId))
                    ? variants[String(variantId)] : fromContext.price;
                return {
                    productId: String(productId),
                    variantId: String(variantId || toId(fromContext.variantId)),
                    name: fromContext.name,
                    sku: fromContext.sku,
                    category: fromContext.category,
                    brand: fromContext.brand,
                    price: toNumber(price)
                };
            }
            return miniatureProduct(productId, variantId) || {
                productId: String(productId),
                variantId: String(variantId),
                name: '',
                price: 0
            };
        }

        function formQuantity(form, productId) {
            if (!form || !form.querySelector) {
                return 0;
            }
            var idInput = form.querySelector('[name="id_product"]');
            if (!idInput || toId(idInput.value) !== productId) {
                return 0;
            }
            var qty = form.querySelector('[name="qty"]');
            return qty ? toId(qty.value) : 1;
        }

        function resolveQuantity(productId) {
            var quantity = 0;
            if (lastAddForm && (Date.now() - lastAddFormAt) < ADD_FORM_MAX_AGE_MS) {
                quantity = formQuantity(lastAddForm, productId);
            }
            if (!quantity && contextProduct(productId)) {
                var wanted = doc.getElementById('quantity_wanted');
                quantity = wanted ? toId(wanted.value) : 0;
            }
            return quantity || 1;
        }

        function currencyFor() {
            if (context.currency) {
                return context.currency;
            }
            var ps = win.prestashop;
            return ps && ps.currency && ps.currency.iso_code ? String(ps.currency.iso_code) : '';
        }

        function onUpdateCart(event) {
            var reason = event && event.reason;
            if (!reason || reason.linkAction !== 'add-to-cart') {
                return;
            }
            if (handledReasons.indexOf(reason) !== -1) {
                return;
            }
            handledReasons.push(reason);

            var productId = toId(reason.idProduct);
            if (!productId) {
                return;
            }
            var variantId = toId(reason.idProductAttribute);
            var product = resolveProduct(productId, variantId);
            var quantity = resolveQuantity(productId);
            lastAddForm = null;
            send('product.addToCart', productEventData(product, quantity, currencyFor()));
        }

        function onClick(event) {
            var button = closest(event && event.target, '[data-button-action="add-to-cart"]');
            if (!button) {
                return;
            }
            lastAddForm = button.form || closest(button, 'form');
            lastAddFormAt = Date.now();
        }

        function onChange(event) {
            var target = event && event.target;
            if (paymentInfoSent || !target || target.name !== 'payment-option' || !target.checked) {
                return;
            }
            paymentInfoSent = true;
            var paymentGuard = PAYMENT_INFO_GUARD_PREFIX + cartSignature(context);
            if (sessionFlag(win, paymentGuard)) {
                debug('add_payment_info already sent for this cart');
                return;
            }
            send('add_payment_info', cartEventData(context, {
                paymentType: target.getAttribute('data-module-name') || ''
            }), function () {
                return onceForTab(win, paymentGuard);
            });
        }

        function subscribePrestaShop() {
            var ps = win.prestashop;
            if (ps && typeof ps.on === 'function') {
                ps.on('updateCart', onUpdateCart);
                return true;
            }
            return false;
        }

        function trackPage() {
            if (context.type === 'product' && context.product) {
                var product = resolveProduct(toId(context.product.productId), toId(context.product.variantId));
                send('product.view', productEventData(product, 1, currencyFor()));
            }
            if (context.type === 'checkout' && !checkoutSent) {
                checkoutSent = true;
                var checkoutGuard = CHECKOUT_GUARD_PREFIX + cartSignature(context);
                if (sessionFlag(win, checkoutGuard)) {
                    debug('begin_checkout already sent for this cart (checkout step reload)');
                } else {
                    send('begin_checkout', cartEventData(context), function () {
                        return onceForTab(win, checkoutGuard);
                    });
                }
            }
            if (context.type === 'confirmation') {
                trackPurchase();
            }
        }

        function trackPurchase() {
            var purchase = context.purchase;
            var orderId = String(context.orderId || '');
            if (!purchase || typeof purchase !== 'object' || !orderId
                || typeof context.eventSalt !== 'string' || !UUID_SHAPE.test(context.eventSalt)) {
                debug('order confirmation without a purchase - left to the server');
                return;
            }
            var guard = PURCHASE_GUARD_PREFIX + orderId;
            if (sessionFlag(win, guard)) {
                debug('purchase already sent from this tab', orderId);
                return;
            }
            var data = {};
            for (var key in purchase) {
                if (Object.prototype.hasOwnProperty.call(purchase, key)) {
                    data[key] = purchase[key];
                }
            }
            data.eventSalt = context.eventSalt;
            data.transactionId = orderId;
            data.orderId = orderId;
            data.source = SOURCE;
            send('transaction.charge', data, function () {
                if (sessionFlag(win, guard)) {
                    return false;
                }
                setSessionFlag(win, guard);
                return true;
            });
        }

        // ---------------------------------------------------------------- start

        installConsentApi();
        trackPage();

        try {
            doc.addEventListener('click', onClick, true);
            if (context.type === 'checkout') {
                doc.addEventListener('change', onChange, true);
            }
        } catch (e) {
            debug('listener registration failed', e);
        }

        if (!subscribePrestaShop()) {
            doc.addEventListener('DOMContentLoaded', subscribePrestaShop);
        }

        if (!gated || consentCookieGranted()) {
            loadAndInit();
        } else {
            var ticks = 0;
            consentTimer = win.setInterval(function () {
                ticks += 1;
                if (consentCookieGranted()) {
                    loadAndInit();
                    return;
                }
                if (ticks >= CONSENT_POLL_MAX_TICKS) {
                    stopWaiting();
                }
            }, CONSENT_POLL_INTERVAL_MS);
        }

        return {
            config: config,
            context: context,
            onUpdateCart: onUpdateCart,
            isBooted: function () { return booted; },
            isExcluded: function () { return excluded; },
            pendingCount: function () { return pending.length; }
        };
    }

    return {
        boot: boot,
        isGrantSignal: isGrantSignal,
        parsePrice: parsePrice,
        DENY_VALUES: DENY_VALUES
    };
}));
