/**
 * Offline test for assets/js/waiting-list.js with a tiny fake DOM (no network, no npm deps).
 * Run: node src/tests/waiting-list-js-test.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'waiting-list.js'), 'utf8');
let failures = 0;
function check(cond, msg) {
    console.log((cond ? 'PASS: ' : 'FAIL: ') + msg);
    if (!cond) failures++;
}

function makeEl(id, value) {
    const listeners = {};
    const attrs = {};
    return {
        id, value: value || '', textContent: '', className: '', hidden: true, children: [],
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        fire(type, ev) { (listeners[type] || []).forEach((fn) => fn(ev || {})); },
        setAttribute(k, v) { attrs[k] = String(v); },
        getAttribute(k) { return k in attrs ? attrs[k] : null; },
        appendChild(c) { this.children.push(c); },
    };
}

function run(mode, fetchImpl, extra) {
    const els = {
        osm_wl_child_postcode: makeEl('osm_wl_child_postcode'),
        osm_wl_child_town: makeEl('osm_wl_child_town'),
        osm_wl_child_address: makeEl('osm_wl_child_address'),
        osm_wl_postcode_status: makeEl('osm_wl_postcode_status'),
        osm_wl_address_search: makeEl('osm_wl_address_search'),
    };
    const fetchLog = [];
    const window = {
        osmWaitingListConfig: { mode, postcodesApi: 'https://api.postcodes.io/postcodes/', i18n: { checking: 'Checking', found: 'Found', notFound: 'Not found', invalid: 'Invalid' } },
        fetch: (url, opts) => { fetchLog.push({ url, opts }); return fetchImpl(url); },
        AbortController: function () { this.signal = {}; this.abort = () => {}; },
    };
    const document = { readyState: 'complete', getElementById: (id) => els[id] || null, querySelector: () => null, addEventListener() {} };
    const ctx = Object.assign({ window, document, setTimeout, clearTimeout, console, Promise }, extra || {});
    window.document = document;
    vm.createContext(ctx);
    vm.runInContext(src, ctx);
    return { els, fetchLog, window, ctx };
}
const tick = () => new Promise((r) => setTimeout(r, 10));
const json = (status, body) => Promise.resolve({ status, ok: status >= 200 && status < 300, json: () => Promise.resolve(body) });

(async () => {
    // postcodes.io: found, fills empty town with parish, formats postcode.
    let t = run('postcodes_io', () => json(200, { status: 200, result: { postcode: 'LE65 1AB', parish: 'Ashby-de-la-Zouch', admin_district: 'North West Leicestershire' } }));
    t.els.osm_wl_child_postcode.value = 'le651ab';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.fetchLog.length === 1 && t.fetchLog[0].url === 'https://api.postcodes.io/postcodes/LE651AB', 'calls postcodes.io for the postcode');
    check(t.fetchLog[0].opts.credentials === 'omit', 'no cookies sent to postcodes.io');
    check(t.els.osm_wl_child_postcode.value === 'LE65 1AB', 'postcode reformatted');
    check(t.els.osm_wl_child_town.value === 'Ashby-de-la-Zouch', 'town filled from parish');
    check(t.els.osm_wl_postcode_status.textContent === 'Found', 'status says found');

    // Unparished → admin_district; existing typed town is kept.
    t = run('postcodes_io', () => json(200, { status: 200, result: { postcode: 'LE1 1AA', parish: 'Leicester, unparished area', admin_district: 'Leicester' } }));
    t.els.osm_wl_child_postcode.value = 'LE1 1AA';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.els.osm_wl_child_town.value === 'Leicester', 'unparished → admin_district');
    t = run('postcodes_io', () => json(200, { status: 200, result: { postcode: 'LE1 1AA', admin_district: 'Leicester' } }));
    t.els.osm_wl_child_town.value = 'My Town';
    t.els.osm_wl_child_postcode.value = 'LE1 1AA';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.els.osm_wl_child_town.value === 'My Town', 'typed town not overwritten');

    // 404 → not found message.
    t = run('postcodes_io', () => json(404, { status: 404, error: 'Postcode not found' }));
    t.els.osm_wl_child_postcode.value = 'ZZ99 9ZZ';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.els.osm_wl_postcode_status.textContent === 'Not found', '404 → not found message');

    // Bad format → no network call.
    t = run('postcodes_io', () => json(200, {}));
    t.els.osm_wl_child_postcode.value = 'hello';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.fetchLog.length === 0 && t.els.osm_wl_postcode_status.textContent === 'Invalid', 'bad format → message, no call');

    // Network failure → silent.
    t = run('postcodes_io', () => Promise.reject(new Error('offline')));
    t.els.osm_wl_child_postcode.value = 'LE65 1AB';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.els.osm_wl_postcode_status.textContent === '', 'network error → no message (server decides)');

    // Google (new widget): fills address line 1, town, postcode.
    let created = null;
    const google = { maps: { places: {
        PlaceAutocompleteElement: function (opts) { created = makeEl('pac'); created.opts = opts; return created; },
    } } };
    t = run('google', () => json(200, {}), { google });
    t.window.google = google;
    t.ctx.window.osmWlGoogleReady();
    check(created && created.opts.includedRegionCodes[0] === 'gb', 'Places widget restricted to the UK');
    check(t.els.osm_wl_address_search.hidden === false && t.els.osm_wl_address_search.children.length === 1, 'search box shown');
    const place = {
        addressComponents: [
            { types: ['street_number'], longText: '12', shortText: '12' },
            { types: ['route'], longText: 'Market Street', shortText: 'Market St' },
            { types: ['postal_town'], longText: 'Ashby-de-la-Zouch', shortText: 'Ashby' },
            { types: ['postal_code'], longText: 'le65 1ap', shortText: 'LE65 1AP' },
        ],
        fetchFields: () => Promise.resolve(),
    };
    created.fire('gmp-select', { placePrediction: { toPlace: () => place } });
    await tick();
    check(t.els.osm_wl_child_address.value === '12 Market Street', 'address line 1 filled');
    check(t.els.osm_wl_child_town.value === 'Ashby-de-la-Zouch', 'town filled from postal_town');
    check(t.els.osm_wl_child_postcode.value === 'LE65 1AP', 'postcode filled');
    created.fire('gmp-error');
    check(t.els.osm_wl_address_search.hidden === true, 'gmp-error hides the search box (manual entry)');

    // Google (legacy widget fallback).
    let acOpts = null; let placeCb = null;
    const legacy = { maps: { places: { Autocomplete: function (input, opts) { acOpts = opts; this.addListener = (n, cb) => { placeCb = cb; }; this.getPlace = () => ({ address_components: [
        { types: ['premise'], long_name: 'Rose Cottage' },
        { types: ['route'], long_name: 'Mill Lane' },
        { types: ['locality'], long_name: 'Packington' },
        { types: ['postal_code'], long_name: 'LE65 1WG' },
    ] }); } } } };
    t = run('google', () => json(200, {}), { google: legacy });
    t.window.google = legacy;
    t.ctx.window.osmWlGoogleReady();
    check(acOpts && acOpts.componentRestrictions.country === 'gb', 'legacy widget restricted to the UK');
    placeCb();
    check(t.els.osm_wl_child_address.value === 'Rose Cottage, Mill Lane' && t.els.osm_wl_child_town.value === 'Packington', 'legacy widget fills fields');

    // Off / missing config → nothing happens.
    t = run('off', () => json(200, {}));
    t.els.osm_wl_child_postcode.value = 'LE65 1AB';
    t.els.osm_wl_child_postcode.fire('blur');
    await tick();
    check(t.fetchLog.length === 0, 'mode off → no lookups');

    if (failures) { console.log(`\n${failures} failure(s)`); process.exit(1); }
    console.log('\nAll JS tests passed.');
})();
