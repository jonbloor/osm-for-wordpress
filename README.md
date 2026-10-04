# Online Scout Manager for WordPress

**This WordPress plugin is not affiliated with Online Scout Manager in any way.**

The Online Scout Manager (OSM) for WordPress plugin allows you to display programmes and events directly from OSM on your website. The plugin integrates with the OSM API to fetch and display data from your sections, ensuring an up-to-date representation of your scouting activities.

---

## Features

- Display section programmes and events using shortcodes.
- Public waiting-list form shortcode that passes validated submissions to [OSM Helper](https://osmhelper.co.uk), which writes into your OSM waiting-list section using the leader’s existing OSM Helper login.
- Manage sections, OSM Helper site key, captcha, and cache from the WordPress admin. No OSM client ID or Connect with OSM in WordPress.
- Dynamically retrieve and cache data from OSM for optimal performance.

---

## Installation

1. Download the latest release of the plugin from [here](https://github.com/alantiller/osm-for-wordpress/releases)
2. Log in to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Select the zip file and click **Install Now**.
5. Activate the plugin.

---

## Setup

### Waiting list (OSM Helper — recommended path)

Parents never log in to OSM. The one-time OSM approval lives in OSM Helper (`https://osmhelper.co.uk/callback`). WordPress only needs:

1. **OSM Helper base URL** (default `https://osmhelper.co.uk`)
2. A **site key** the leader copies from OSM Helper after signing in

In OSM Helper:

1. Sign in at [osmhelper.co.uk](https://osmhelper.co.uk).
2. Open **Settings**.
3. Under **WordPress waiting-list form**, choose your OSM waiting-list section (do not hardcode another group’s section id).
4. Save to create a site key, then copy it.

In WordPress:

1. Go to **OSM Settings → Waiting List**.
2. Confirm the base URL (https).
3. Paste the site key (leave blank later to keep it).
4. Optionally enable Google reCAPTCHA or Cloudflare Turnstile.
5. Publish `[osm_waiting_list]` on a page.

The plugin validates, applies honeypot and per-IP rate limit, verifies captcha (if enabled), then `POST`s JSON to `OSM Helper /api/waiting-list/submit`. It does **not** store a successful submission in WordPress. Groups do **not** create an OSM application for this plugin.

### Programme / events (legacy)

Programme and events shortcodes may still use a previously stored OSM token if one exists. This plugin no longer offers Connect with OSM, client ID, or client secret fields. Waiting-list forms do not use that path.

### Blocks, invalid data, and rate limits

Waiting-list writes go through OSM Helper, which honours `X-Blocked` (stop), surfaces `X-Deprecated`, and does not retry HTTP 429. Clear a Helper intake block under OSM Helper Settings after fixing the cause.

---

## Usage

### Shortcodes

Use the following shortcodes to display OSM data on your website, you'll be able to find the Section ID in the general tab of the settings menu:

#### Programme Shortcode

```plaintext
[osm_programme sectionid="SECTION_ID" futureonly="true"]
```

- **sectionid** (required): The ID of the section to display.
- **futureonly** (optional): Set to `true` to show only future events. Default: `false`.

Example:

```plaintext
[osm_programme sectionid="12345" futureonly="true"]
```

#### Events Shortcode

```plaintext
[osm_events sectionid="SECTION_ID" futureonly="true"]
```

- **sectionid** (required): The ID of the section to display.
- **futureonly** (optional): Set to `true` to show only future events. Default: `false`.

Example:

```plaintext
[osm_events sectionid="67890" futureonly="false"]
```

---


#### Waiting List Shortcode

```plaintext
[osm_waiting_list]
```

Shows a public form for joining an OSM waiting list via OSM Helper. Configure **OSM Helper base URL** and **site key** under **OSM Settings → Waiting List**. The waiting-list **section ID** is chosen in OSM Helper Settings (not in WordPress).

**Required fields:** child first name, last name, date of birth (UK day/month/year), postcode; parent 1 first name, last name, email, phone; consent checkbox.

**Optional fields:** address line 1, town; parent 2 first name, last name, email, phone (if any parent 2 field is filled, first name, last name, and email become required).

On a successful write, the submission is **not** stored in WordPress. The form uses a WordPress nonce, a honeypot field, server-side validation, and a simple per-IP rate limit. Those stay in place when spam protection is **off** (the default).

Optional spam protection (per site, checked on the server before calling OSM Helper):

- **Off** — honeypot and rate limit only. No captcha token is required.
- **Google reCAPTCHA** (v2 checkbox) — site key and secret key from the [Google reCAPTCHA admin](https://www.google.com/recaptcha/admin).
- **Cloudflare Turnstile** — site key and secret key from the [Cloudflare Turnstile dashboard](https://dash.cloudflare.com/?to=/:account/turnstile).

Setting keys: `osm_helper_base_url`, `osm_helper_site_key`, `osm_waiting_list_captcha` (`off`, `recaptcha`, or `turnstile`), plus captcha site/secret keys. A blank secret field does not wipe a stored secret. Failed verification shows a form error and does not call OSM Helper.

---

## Admin Features

- **General Tab**:
  - View enabled sections and their current term IDs.
  - Purge cached data.
  - Reset all plugin configuration.

- **Sections Enabled Tab**:
  - List all available sections retrieved from OSM.
  - Enable or disable specific sections.

- **Advanced Options**:
  - **Date Format**: Customize the date format used in the plugin. Default: `d/m/Y`.
  - **Time Format**: Customize the time format used in the plugin. Default: `H:i`.

- **Waiting List**:
  - OSM Helper base URL (default `https://osmhelper.co.uk`) and site key from OSM Helper Settings.
  - Waiting-list section ID is configured in OSM Helper, not here.
  - Choose spam protection: off, Google reCAPTCHA, or Cloudflare Turnstile. Keys are per site.

---

## Caching

The plugin caches the following data to reduce API calls:
- **Sections**: Cached for 24 hours.
- **Current Term IDs**: Cached for 24 hours per section.
- **Programmes and Events**: Cached for 24 hours per section and term.

Cached data is automatically refreshed when it expires.

---

## Reset Configuration

If you need to reset the plugin:
1. Navigate to the **General** tab.
2. Click the **Reset Configuration** button.
3. All authentication details, enabled sections, and cached data will be deleted.

---

## Frequently Asked Questions

### 1. How often is the data refreshed?
Data is cached for 24 hours and is refreshed automatically when the cache expires.

---

## License

This plugin is licensed under the MIT License. See the LICENSE file for details.

---

## Disclaimer

**This plugin is not affiliated with Online Scout Manager. Use it at your own risk.**
