/**
 * OSM waiting-list form: optional address lookup.
 * Loaded only on pages that render [osm_waiting_list] with a lookup mode switched on.
 * Manual entry always keeps working; failures here never block the form or the captcha.
 *
 * Modes (window.osmWaitingListConfig.mode):
 *  - postcodes_io: on postcode blur, look the postcode up at api.postcodes.io (free, open data,
 *    no key) to check it and fill the town if it is empty. It cannot list house addresses.
 *  - google: Google Places autocomplete restricted to the UK; fills address line 1, town, postcode.
 */
(function () {
    'use strict';

    var cfg = window.osmWaitingListConfig || {};
    var i18n = cfg.i18n || {};
    var PC_RE = /^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/;

    function byId(id) {
        return document.getElementById(id);
    }

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function compact(pc) {
        return String(pc || '').toUpperCase().replace(/\s+/g, '');
    }

    function normalise(pc) {
        var c = compact(pc);
        return c.length > 3 ? c.slice(0, -3) + ' ' + c.slice(-3) : c;
    }

    // Town for the address: post town if ever provided, else a real parish, else the local authority.
    function townFrom(r) {
        if (!r) return '';
        if (r.post_town) return r.post_town;
        if (r.parish && !/unparished/i.test(r.parish)) return r.parish;
        return r.admin_district || '';
    }

    function setStatus(msg, kind) {
        var el = byId('osm_wl_postcode_status');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'osm-wl-lookup-status' + (kind ? ' osm-wl-lookup-status--' + kind : '');
    }

    function initPostcodesIo() {
        var pcInput = byId('osm_wl_child_postcode');
        var town = byId('osm_wl_child_town');
        if (!pcInput || typeof window.fetch !== 'function') return;
        var base = cfg.postcodesApi || 'https://api.postcodes.io/postcodes/';
        var lastLooked = '';

        if (town) {
            town.addEventListener('input', function () {
                town.setAttribute('data-osm-autofilled', '0');
            });
        }

        pcInput.addEventListener('blur', function () {
            var c = compact(pcInput.value);
            if (c === '') {
                setStatus('');
                lastLooked = '';
                return;
            }
            if (!PC_RE.test(c)) {
                setStatus(i18n.invalid || '', 'error');
                lastLooked = '';
                return;
            }
            if (c === lastLooked) return;
            lastLooked = c;
            setStatus(i18n.checking || '', 'info');

            var ctrl = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timer = setTimeout(function () {
                if (ctrl) ctrl.abort();
            }, 5000);

            window.fetch(base + encodeURIComponent(c), {
                method: 'GET',
                credentials: 'omit',
                headers: { Accept: 'application/json' },
                signal: ctrl ? ctrl.signal : undefined
            }).then(function (res) {
                clearTimeout(timer);
                if (res.status === 404) return { notFound: true };
                if (!res.ok) throw new Error('postcodes.io HTTP ' + res.status);
                return res.json();
            }).then(function (data) {
                if (compact(pcInput.value) !== c) return; // Changed while we waited.
                if (!data || data.notFound || !data.result) {
                    setStatus(i18n.notFound || '', 'error');
                    return;
                }
                var r = data.result;
                if (r.postcode) pcInput.value = r.postcode;
                var t = townFrom(r);
                if (town && t && (town.value.trim() === '' || town.getAttribute('data-osm-autofilled') === '1')) {
                    town.value = t;
                    town.setAttribute('data-osm-autofilled', '1');
                }
                setStatus(i18n.found || '', 'ok');
            }).catch(function () {
                // Network error, timeout or outage: say nothing and let the server decide.
                clearTimeout(timer);
                setStatus('');
                lastLooked = '';
            });
        });
    }

    function fillFromComponents(components, longKey, shortKey) {
        var a1 = byId('osm_wl_child_address');
        var town = byId('osm_wl_child_town');
        var pc = byId('osm_wl_child_postcode');
        components = components || [];

        function get(type) {
            for (var i = 0; i < components.length; i++) {
                var types = components[i].types || [];
                if (types.indexOf(type) !== -1) {
                    return components[i][longKey] || components[i][shortKey] || '';
                }
            }
            return '';
        }

        var building = [get('subpremise'), get('premise')].filter(Boolean).join(', ');
        var street = [get('street_number'), get('route')].filter(Boolean).join(' ');
        var line1 = [building, street].filter(Boolean).join(', ');
        var townVal = get('postal_town') || get('locality') || get('administrative_area_level_2');
        var pcVal = get('postal_code');

        if (a1 && line1) a1.value = line1;
        if (town && townVal) town.value = townVal;
        if (pc && pcVal) pc.value = normalise(pcVal);
    }

    function initGoogle() {
        var a1 = byId('osm_wl_child_address');
        var wrap = byId('osm_wl_address_search');
        if (!a1 || !window.google || !window.google.maps) return;
        var places = window.google.maps.places || {};

        // Current widget (Places API (New)).
        if (wrap && typeof places.PlaceAutocompleteElement === 'function') {
            try {
                var el = new places.PlaceAutocompleteElement({ includedRegionCodes: ['gb'] });
                el.setAttribute('aria-labelledby', 'osm_wl_address_search_label');
                var onPlace = function (place) {
                    if (!place || typeof place.fetchFields !== 'function') return;
                    place.fetchFields({ fields: ['addressComponents'] }).then(function () {
                        fillFromComponents(place.addressComponents, 'longText', 'shortText');
                    }).catch(function () {});
                };
                el.addEventListener('gmp-select', function (ev) {
                    if (ev && ev.placePrediction && typeof ev.placePrediction.toPlace === 'function') {
                        onPlace(ev.placePrediction.toPlace());
                    }
                });
                // Older beta releases fired gmp-placeselect with a Place.
                el.addEventListener('gmp-placeselect', function (ev) {
                    if (ev && ev.place) onPlace(ev.place);
                });
                el.addEventListener('gmp-error', function () {
                    wrap.hidden = true; // Bad key or API not enabled: fall back to manual entry.
                });
                wrap.appendChild(el);
                wrap.hidden = false;
                return;
            } catch (e) {
                // Fall through to the legacy widget.
            }
        }

        // Legacy widget (Places API) on address line 1.
        if (typeof places.Autocomplete === 'function') {
            var ac = new places.Autocomplete(a1, {
                componentRestrictions: { country: 'gb' },
                fields: ['address_components'],
                types: ['address']
            });
            ac.addListener('place_changed', function () {
                var p = ac.getPlace();
                fillFromComponents(p && p.address_components, 'long_name', 'short_name');
            });
            // Enter picks a suggestion instead of submitting the form.
            a1.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && document.querySelector('.pac-container .pac-item')) {
                    e.preventDefault();
                }
            });
        }
    }

    // Google Maps calls this when the script (loaded with callback=osmWlGoogleReady) is ready.
    window.osmWlGoogleReady = function () {
        onReady(initGoogle);
    };

    // Google signals an auth failure (bad or restricted key) through this global hook.
    if (cfg.mode === 'google' && typeof window.gm_authFailure !== 'function') {
        window.gm_authFailure = function () {
            var wrap = byId('osm_wl_address_search');
            if (wrap) wrap.hidden = true;
        };
    }

    if (cfg.mode === 'postcodes_io') {
        onReady(initPostcodesIo);
    }
})();
