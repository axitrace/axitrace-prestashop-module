// Unit tests of views/js/axitrace-storefront.js. Run: node --test tests/js/*.test.mjs
// No DOM library: a minimal fake document/window covers what the script touches.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const storefront = require('../../views/js/axitrace-storefront.js');

const PK = 'pk_live_0123456789abcdef0123456789abcdef';

class FakeElement {
    constructor(tag, attrs = {}, children = []) {
        this.nodeType = 1;
        this.tagName = tag.toUpperCase();
        this.attrs = { ...attrs };
        this.children = [];
        this.parentNode = null;
        this.textContent = attrs.text || '';
        this.value = attrs.value;
        this.name = attrs.name;
        this.checked = !!attrs.checked;
        children.forEach((c) => this.appendChild(c));
    }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attrs, name) ? String(this.attrs[name]) : null;
    }

    setAttribute(name, value) {
        this.attrs[name] = value;
    }

    get form() {
        let el = this.parentNode;
        while (el && el.tagName !== 'FORM') {
            el = el.parentNode;
        }
        return el || null;
    }

    // Supports the selectors the script uses: comma lists of `tag`, `.class`, `[attr]`,
    // `[attr="value"]`, `tag[attr="value"]`.
    matches(selector) {
        return selector.split(',').some((part) => matchesOne(this, part.trim()));
    }

    descendants() {
        const out = [];
        for (const child of this.children) {
            out.push(child, ...child.descendants());
        }
        return out;
    }

    querySelectorAll(selector) {
        return this.descendants().filter((el) => el.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
}

function matchesOne(el, selector) {
    const re = /^([a-z]*)((?:\.[\w-]+)*)((?:\[[^\]]+\])*)$/i;
    const m = selector.match(re);
    if (!m) {
        throw new Error('unsupported selector in fake DOM: ' + selector);
    }
    const [, tag, classes, attrs] = m;
    if (tag && el.tagName !== tag.toUpperCase()) {
        return false;
    }
    const elClasses = String(el.attrs.class || '').split(/\s+/);
    for (const cls of classes.split('.').filter(Boolean)) {
        if (!elClasses.includes(cls)) {
            return false;
        }
    }
    for (const a of attrs.match(/\[[^\]]+\]/g) || []) {
        const am = a.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
        const value = am[1] === 'name' ? el.name : el.getAttribute(am[1]);
        if (value === null || value === undefined) {
            return false;
        }
        if (am[2] !== undefined && String(value) !== am[2]) {
            return false;
        }
    }
    return true;
}

function json(id, data) {
    return new FakeElement('script', { id, type: 'application/json', text: JSON.stringify(data) });
}

function makePage({ config = {}, context = null, body = [], cookie = '', fetchStatus = null, stub = null } = {}) {
    const head = new FakeElement('head');
    const bodyEl = new FakeElement('body', {}, body);
    const root = new FakeElement('html', {}, [head, bodyEl]);
    head.appendChild(json('axitrace-prestashop-config', {
        publicKey: PK,
        sdkUrl: 'http://localhost:8080/axitrack.js',
        apiUrl: 'http://localhost:8080',
        consentMode: 'off',
        consentCookie: '',
        pageType: 'index',
        moduleVersion: '0.0.1',
        statusUrl: '',
        debug: false,
        ...config,
    }));
    if (context) {
        bodyEl.appendChild(json('axitrace-prestashop-context', context));
    }

    const listeners = {};
    const events = [];
    const inits = [];
    const sdk = {
        init(options) {
            inits.push(options);
            return { track: (name, data) => events.push([name, data]) };
        },
        grants: 0,
        revokes: 0,
        grant() { this.grants += 1; },
        revoke() { this.revokes += 1; },
    };
    const psHandlers = {};
    const win = {
        prestashop: {
            currency: { iso_code: 'EUR' },
            on(name, fn) { (psHandlers[name] = psHandlers[name] || []).push(fn); },
            emit(name, payload) { (psHandlers[name] || []).forEach((fn) => fn(payload)); },
        },
        timers: [],
        setTimeout(fn) { this.timers.push(fn); return this.timers.length; },
        setInterval(fn) { this.timers.push(fn); return this.timers.length; },
        clearInterval() {},
        console,
    };
    if (stub) {
        win.axitraceConsent = stub;
    }
    if (fetchStatus !== null) {
        win.fetchCalls = [];
        win.fetch = (url) => {
            win.fetchCalls.push(url);
            return {
                then(onOk) {
                    const parsed = onOk({ ok: true, json: () => fetchStatus });
                    return { then(next) { next(parsed); } };
                },
            };
        };
    }
    const doc = {
        cookie,
        head,
        documentElement: root,
        getElementById: (id) => root.descendants().find((el) => el.attrs.id === id) || null,
        querySelector: (s) => root.querySelector(s),
        querySelectorAll: (s) => root.querySelectorAll(s),
        createElement: (tag) => {
            const el = new FakeElement(tag);
            return el;
        },
        addEventListener(name, fn) { (listeners[name] = listeners[name] || []).push(fn); },
        dispatch(name, event) { (listeners[name] || []).forEach((fn) => fn(event)); },
    };
    // The SDK "loads" as soon as its script tag is appended.
    const append = head.appendChild.bind(head);
    head.appendChild = (el) => {
        append(el);
        if (el.getAttribute('data-axitrace-sdk') === '1') {
            win.Axitrace = sdk;
        }
        return el;
    };
    return { win, doc, events, inits, sdk, root };
}

function sdkScripts(page) {
    return page.root.querySelectorAll('script[data-axitrace-sdk]');
}

// Shapes captured from core.js on 8.1.7 (Classic) and 9.2.0 (Hummingbird) - identical emit:
// emit("updateCart",{reason:{idProduct:e.id_product,idProductAttribute:e.id_product_attribute,
// idCustomization:e.id_customization,linkAction:"add-to-cart",cart:e.cart},resp:e})
function addToCartEvent(idProduct, idProductAttribute, respShape) {
    const resp = respShape === 'classic'
        ? { success: true, id_product: idProduct, id_product_attribute: idProductAttribute, id_customization: 0, quantity: 1, errors: '', cart: { products_count: 1 } }
        : { success: true, id_product: idProduct, id_product_attribute: idProductAttribute, id_customization: 0, quantity: 1, errors: '', cart: { totals: {} }, modal: '<div></div>' };
    return {
        reason: { idProduct, idProductAttribute, idCustomization: 0, linkAction: 'add-to-cart', cart: resp.cart },
        resp,
    };
}

const productContext = {
    type: 'product',
    currency: 'EUR',
    product: {
        productId: '1', variantId: '1', name: 'Hummingbird printed t-shirt', sku: 'demo_1', price: 23.9,
        category: 'Men', brand: 'Studio Design', variants: { 1: 23.9, 2: 23.9, 9: 29.9 },
    },
};

function classicProductForm(productId, qty) {
    const button = new FakeElement('button', { class: 'btn btn-primary add-to-cart', 'data-button-action': 'add-to-cart' });
    const form = new FakeElement('form', { id: 'add-to-cart-or-refresh' }, [
        new FakeElement('input', { name: 'id_product', value: String(productId), id: 'product_page_product_id' }),
        new FakeElement('input', { name: 'qty', value: String(qty), id: 'quantity_wanted' }),
        button,
    ]);
    return { form, button };
}

test('the deny-list is byte-identical to the SDK fixture', () => {
    const fixture = JSON.parse(readFileSync(path.join(here, '../fixtures/sdk_deny_values.txt'), 'utf8'));
    assert.deepEqual(storefront.DENY_VALUES, fixture);
    const source = readFileSync(path.join(here, '../../views/js/axitrace-storefront.js'), 'utf8');
    assert.ok(source.includes('var DENY_VALUES = ' + readFileSync(path.join(here, '../fixtures/sdk_deny_values.txt'), 'utf8').trim() + ';'));
});

test('a second init on the same page is ignored', () => {
    const page = makePage({ context: productContext });
    assert.ok(storefront.boot(page.win, page.doc));
    assert.equal(storefront.boot(page.win, page.doc), null);
    assert.equal(page.inits.length, 1);
    assert.equal(sdkScripts(page).length, 1);
    assert.equal(page.events.filter(([name]) => name === 'product.view').length, 1);
});

test('nothing starts without a public key', () => {
    const page = makePage({ config: { publicKey: '' } });
    assert.equal(storefront.boot(page.win, page.doc), null);
    assert.equal(sdkScripts(page).length, 0);
});

test('product page: product.view with the context product', () => {
    const page = makePage({ context: productContext });
    storefront.boot(page.win, page.doc);
    assert.equal(page.inits.length, 1);
    assert.deepEqual(page.inits[0], { publicKey: PK, apiUrl: 'http://localhost:8080', debug: false });
    const [name, data] = page.events[0];
    assert.equal(name, 'product.view');
    assert.equal(data.productId, '1');
    assert.equal(data.variantId, '1');
    assert.equal(data.value, 23.9);
    assert.equal(data.currency, 'EUR');
    assert.equal(data.source, 'prestashop');
    assert.equal(data.products.length, 1);
});

for (const shape of ['classic', 'hummingbird']) {
    test(`${shape}-shaped add to cart on the product page: one product.addToCart with id, combination and quantity`, () => {
        const { form, button } = classicProductForm(1, 3);
        const page = makePage({ context: productContext, body: [form] });
        storefront.boot(page.win, page.doc);
        page.doc.dispatch('click', { target: button });
        page.win.prestashop.emit('updateCart', addToCartEvent(1, 9, shape));

        const adds = page.events.filter(([name]) => name === 'product.addToCart');
        assert.equal(adds.length, 1);
        const data = adds[0][1];
        assert.equal(data.productId, '1');
        assert.equal(data.variantId, '9');
        assert.equal(data.quantity, 3);
        assert.equal(data.price, 29.9);
        assert.equal(data.value, 89.7);
        assert.equal(data.name, 'Hummingbird printed t-shirt');
        assert.equal(data.products[0].quantity, 3);
    });

    test(`${shape}-shaped add to cart from a listing (no product context): data from the miniature`, () => {
        const button = new FakeElement('button', { class: 'product-miniature__add btn', 'data-button-action': 'add-to-cart' });
        const tile = new FakeElement('article', { class: 'product-miniature js-product-miniature', 'data-id-product': '6', 'data-id-product-attribute': '0' }, [
            new FakeElement('a', { class: 'product-miniature__title', text: '  Mug The best is yet to come ' }),
            new FakeElement('div', { class: 'product-miniature__price', text: '€11.90' }),
            new FakeElement('form', {}, [new FakeElement('input', { name: 'id_product', value: '6' }), button]),
        ]);
        const page = makePage({ context: null, body: [tile] });
        storefront.boot(page.win, page.doc);
        page.doc.dispatch('click', { target: button });
        page.win.prestashop.emit('updateCart', addToCartEvent(6, 0, shape));

        const adds = page.events.filter(([name]) => name === 'product.addToCart');
        assert.equal(adds.length, 1);
        const data = adds[0][1];
        assert.equal(data.productId, '6');
        assert.equal(data.variantId, '0');
        assert.equal(data.quantity, 1);
        assert.equal(data.name, 'Mug The best is yet to come');
        assert.equal(data.price, 11.9);
        assert.equal(data.currency, 'EUR');
    });
}

test('the same updateCart event delivered twice is counted once', () => {
    const page = makePage({ context: productContext });
    storefront.boot(page.win, page.doc);
    const event = addToCartEvent(1, 1, 'classic');
    page.win.prestashop.emit('updateCart', event);
    page.win.prestashop.emit('updateCart', event);
    assert.equal(page.events.filter(([name]) => name === 'product.addToCart').length, 1);
});

test('cart page quantity changes and deletions are not add to cart', () => {
    const page = makePage({ context: { type: 'cart', currency: 'EUR', value: 10, itemCount: 1, items: [] } });
    storefront.boot(page.win, page.doc);
    page.win.prestashop.emit('updateCart', { reason: { linkAction: 'delete-from-cart', idProduct: '1' }, resp: {} });
    page.win.prestashop.emit('updateCart', { reason: { upUrl: '/cart?up=1', downUrl: '/cart?down=1' }, resp: {} });
    page.win.prestashop.emit('updateCart', {});
    assert.equal(page.events.length, 0);
});

test('checkout: begin_checkout once, add_payment_info once on the first payment option', () => {
    const context = {
        type: 'checkout', currency: 'EUR', value: 57.14, itemCount: 2,
        items: [{ productId: '1', variantId: '1', name: 'T-shirt', sku: 'demo_1', price: 19.12, quantity: 2, category: 'men', brand: '' }],
    };
    const page = makePage({ context, config: { pageType: 'order' } });
    storefront.boot(page.win, page.doc);
    const option = new FakeElement('input', { name: 'payment-option', 'data-module-name': 'ps_wirepayment', checked: true });
    page.doc.dispatch('change', { target: option });
    page.doc.dispatch('change', { target: option });
    const names = page.events.map(([name]) => name);
    assert.deepEqual(names, ['begin_checkout', 'add_payment_info']);
    assert.equal(page.events[0][1].value, 57.14);
    assert.equal(page.events[0][1].products[0].quantity, 2);
    assert.equal(page.events[1][1].payment_type, 'ps_wirepayment');
    assert.equal('paymentType' in page.events[1][1], false);
});

test('checkout step reloads: begin_checkout and add_payment_info once per cart, again for a changed cart', () => {
    // PrestaShop reloads the checkout page at every step (personal information, addresses, shipping,
    // payment); the E2E on 8.1.7 stored four begin_checkout rows for one checkout before this guard.
    const storage = fakeSessionStorage();
    const context = (quantity) => ({
        type: 'checkout', currency: 'EUR', value: 11.9 * quantity, itemCount: quantity,
        items: [{ productId: '6', variantId: '0', name: 'Mug', sku: 'demo_13', price: 11.9, quantity, category: 'home', brand: '' }],
    });
    const option = new FakeElement('input', { name: 'payment-option', 'data-module-name': 'ps_cashondelivery', checked: true });
    const step = (quantity, choosePayment) => {
        const page = makePage({ context: context(quantity), config: { pageType: 'order' } });
        page.win.sessionStorage = storage;
        storefront.boot(page.win, page.doc);
        if (choosePayment) {
            page.doc.dispatch('change', { target: option });
        }
        return page.events.map(([name]) => name);
    };

    assert.deepEqual(step(1, false), ['begin_checkout']);   // personal information
    assert.deepEqual(step(1, false), []);                   // addresses
    assert.deepEqual(step(1, false), []);                   // shipping
    assert.deepEqual(step(1, true), ['add_payment_info']);  // payment
    assert.deepEqual(step(1, true), []);                    // payment step reloaded
    assert.deepEqual(step(2, true), ['begin_checkout', 'add_payment_info']); // the cart changed
});

test('checkout in consent mode browser: the checkout guard is set only once the event really leaves', () => {
    const storage = fakeSessionStorage();
    const context = {
        type: 'checkout', currency: 'EUR', value: 11.9, itemCount: 1,
        items: [{ productId: '6', variantId: '0', name: 'Mug', sku: 'demo_13', price: 11.9, quantity: 1, category: '', brand: '' }],
    };
    const gated = makePage({ context, config: { pageType: 'order', consentMode: 'browser', consentCookie: 'consent' } });
    gated.win.sessionStorage = storage;
    storefront.boot(gated.win, gated.doc);
    assert.equal(gated.events.length, 0);
    assert.deepEqual(Object.keys(storage.data), []);

    const granted = makePage({ context, config: { pageType: 'order', consentMode: 'browser', consentCookie: 'consent' }, cookie: 'consent=yes' });
    granted.win.sessionStorage = storage;
    storefront.boot(granted.win, granted.doc);
    assert.deepEqual(granted.events.map(([name]) => name), ['begin_checkout']);
});

test('consent mode browser: nothing loads before a grant, the stub queue replays it', () => {
    const page = makePage({ context: productContext, config: { consentMode: 'browser', consentCookie: 'my_consent' } });
    storefront.boot(page.win, page.doc);
    assert.equal(sdkScripts(page).length, 0);
    assert.equal(page.events.length, 0);
    assert.equal(page.win.axitraceConsent.isGranted(), false);

    page.win.axitraceConsent.grant();
    assert.equal(sdkScripts(page).length, 1);
    assert.equal(page.inits[0].requireConsent, true);
    assert.equal(page.inits[0].consentCookie, 'my_consent');
    assert.equal(page.sdk.grants, 1);
    assert.equal(page.events.filter(([name]) => name === 'product.view').length, 1, 'queued product.view flushed');
    assert.equal(page.win.axitraceConsent.isGranted(), true);

    page.win.axitraceConsent.revoke();
    assert.equal(page.sdk.revokes, 1);
    assert.equal(page.win.axitraceConsent.isGranted(), false);
});

test('consent mode all: a grant queued by the header stub before the script ran boots the SDK', () => {
    const stub = { _queue: ['grant'], grant() {}, revoke() {}, isGranted() { return false; } };
    const page = makePage({ config: { consentMode: 'all', consentCookie: 'c' }, stub });
    storefront.boot(page.win, page.doc);
    assert.equal(sdkScripts(page).length, 1);
});

test('consent cookie: a grant value boots at once, a deny value does not', () => {
    const granted = makePage({ config: { consentMode: 'browser', consentCookie: 'c' }, cookie: 'a=1; c=yes' });
    storefront.boot(granted.win, granted.doc);
    assert.equal(sdkScripts(granted).length, 1);

    const denied = makePage({ config: { consentMode: 'browser', consentCookie: 'c' }, cookie: 'c=Denied ' });
    storefront.boot(denied.win, denied.doc);
    assert.equal(sdkScripts(denied).length, 0);
});

test('excluded customer group: the status answer keeps the SDK off', () => {
    const page = makePage({ context: productContext, config: { statusUrl: '/module/axitrace/status' }, fetchStatus: { tracked: false } });
    const tracker = storefront.boot(page.win, page.doc);
    assert.deepEqual(page.win.fetchCalls, ['/module/axitrace/status']);
    assert.equal(sdkScripts(page).length, 0);
    assert.equal(tracker.isExcluded(), true);
    assert.equal(tracker.pendingCount(), 0);
});

test('tracked customer: the status answer lets the SDK start', () => {
    const page = makePage({ context: productContext, config: { statusUrl: '/module/axitrace/status' }, fetchStatus: { tracked: true } });
    storefront.boot(page.win, page.doc);
    assert.equal(sdkScripts(page).length, 1);
    assert.equal(page.events.length, 1);
});

test('displayed prices are parsed in both decimal conventions', () => {
    assert.equal(storefront.parsePrice('€11.90'), 11.9);
    assert.equal(storefront.parsePrice('11,90 zł'), 11.9);
    assert.equal(storefront.parsePrice('1 234,50 €'), 1234.5);
    assert.equal(storefront.parsePrice('$1,234.50'), 1234.5);
    assert.equal(storefront.parsePrice('1.234 €'), 1234);
    assert.equal(storefront.parsePrice('¥1500'), 1500);
    assert.equal(storefront.parsePrice('free'), null);
});

test('grant signal mirrors ConsentGate::isGrantSignal', () => {
    assert.equal(storefront.isGrantSignal(null), false);
    assert.equal(storefront.isGrantSignal(' OPT-OUT '), false);
    assert.equal(storefront.isGrantSignal('1'), true);
    assert.equal(storefront.isGrantSignal('{"marketing":true}'), true);
});

// ------------------------------------------------------------------ confirmation purchase (T8)

const SALT = '0c6c4f5e-5d0f-5e0b-9c07-3b8a9f6a1d11';

function confirmationContext(extra = {}) {
    return {
        type: 'confirmation',
        orderId: '30',
        orderReference: 'QDVGHUQCV',
        currency: 'EUR',
        eventSalt: SALT,
        purchase: {
            client: { email: 'buyer@example.com' },
            products: [{ productId: '1', quantity: 2, price: 19.12, currency: 'EUR' }],
            value: { amount: 57.14, currency: 'EUR' },
            revenue: { amount: 57.14, currency: 'EUR' },
            orderNumber: '30',
            eventSalt: SALT,
            transactionId: '30',
            orderId: '30',
        },
        ...extra,
    };
}

function fakeSessionStorage() {
    const data = {};
    return {
        data,
        getItem: (k) => (Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null),
        setItem: (k, v) => { data[k] = String(v); },
    };
}

test('confirmation: transaction.charge with the server eventSalt and the order id', () => {
    const page = makePage({ context: confirmationContext() });
    page.win.sessionStorage = fakeSessionStorage();
    storefront.boot(page.win, page.doc);

    const charges = page.events.filter(([name]) => name === 'transaction.charge');
    assert.equal(charges.length, 1);
    const [, data] = charges[0];
    assert.equal(data.eventSalt, SALT);
    assert.equal(data.transactionId, '30');
    assert.equal(data.orderId, '30');
    assert.equal(data.source, 'prestashop');
    assert.deepEqual(data.value, { amount: 57.14, currency: 'EUR' });
    assert.equal(page.win.sessionStorage.data.axitrace_ps_purchase_30, '1');
});

test('confirmation: a reload in the same tab does not send the purchase again', () => {
    const storage = fakeSessionStorage();
    const first = makePage({ context: confirmationContext() });
    first.win.sessionStorage = storage;
    storefront.boot(first.win, first.doc);

    const reload = makePage({ context: confirmationContext() });
    reload.win.sessionStorage = storage;
    storefront.boot(reload.win, reload.doc);

    assert.equal(first.events.filter(([name]) => name === 'transaction.charge').length, 1);
    assert.equal(reload.events.filter(([name]) => name === 'transaction.charge').length, 0);
});

test('confirmation without an eligible purchase (bank wire awaiting): no browser purchase', () => {
    const context = confirmationContext();
    delete context.purchase;
    delete context.eventSalt;
    const page = makePage({ context });
    page.win.sessionStorage = fakeSessionStorage();
    storefront.boot(page.win, page.doc);

    assert.equal(page.events.filter(([name]) => name === 'transaction.charge').length, 0);
    assert.deepEqual(page.win.sessionStorage.data, {});
});

test('confirmation: a malformed eventSalt never sends (the SDK would mint a fresh id)', () => {
    const page = makePage({ context: confirmationContext({ eventSalt: 'not-a-uuid' }) });
    storefront.boot(page.win, page.doc);

    assert.equal(page.events.filter(([name]) => name === 'transaction.charge').length, 0);
});

test('confirmation in consent mode browser: the guard is set only once the purchase really leaves', () => {
    const page = makePage({ context: confirmationContext(), config: { consentMode: 'browser', consentCookie: 'cookie_consent' } });
    page.win.sessionStorage = fakeSessionStorage();
    const tracker = storefront.boot(page.win, page.doc);

    assert.equal(tracker.isBooted(), false);
    assert.equal(tracker.pendingCount(), 1);
    assert.deepEqual(page.win.sessionStorage.data, {}, 'no consent yet: nothing sent, nothing remembered');

    page.win.axitraceConsent.grant();
    assert.equal(page.events.filter(([name]) => name === 'transaction.charge').length, 1);
    assert.equal(page.win.sessionStorage.data.axitrace_ps_purchase_30, '1');
});

test('confirmation without sessionStorage still sends once per page', () => {
    const page = makePage({ context: confirmationContext() });
    storefront.boot(page.win, page.doc);

    assert.equal(page.events.filter(([name]) => name === 'transaction.charge').length, 1);
});
