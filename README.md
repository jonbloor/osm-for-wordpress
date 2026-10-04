# Online Scout Manager for WordPress

**This WordPress plugin is not affiliated with Online Scout Manager in any way.**

The Online Scout Manager (OSM) for WordPress plugin allows you to display programmes and events directly from OSM on your website. The plugin integrates with the OSM API to fetch and display data from your sections, ensuring an up-to-date representation of your scouting activities.

---

## Features

- Display section programmes and events using shortcodes.
- Public waiting-list form shortcode that writes a child straight into a configurable OSM waiting-list section.
- Manage sections, authentication, waiting-list section ID, and cache directly from the WordPress admin.
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

Use **one** OSM application. The recommended grant is **authorization code** with PKCE (`code_challenge_method=S256`). The WordPress site must be **https**. Scopes requested are `section:programme:read`, `section:event:read`, and `section:member:write` (the smallest set that can still write a waiting-list member). Do not request finance, administration, badge, attendance, quartermaster, or flexirecord.

The redirect URL is exactly `admin_url( 'admin-post.php?action=osm_oauth_callback' )`, which on a site is `https://{host}/wp-admin/admin-post.php?action=osm_oauth_callback`. Copy it from **OSM Settings → Authentication**. It has no extra parameters and must match the `redirect_uri` the plugin sends.

Do **not** tick **Client Credentials Grant** unless you are using the single-user client-credentials mode below.

### Step 1: Create the OSM application (authorization code)

1. Log in to [Online Scout Manager (OSM)](https://www.onlinescoutmanager.co.uk).
2. Expand the **Settings** menu at the bottom of the page.
3. Select **My Account Details**.
4. Click **Developer Tools** from the menu on the left-hand side.
5. Click **Create Application**.
6. Provide a name for your application and click **Save**.
7. Enter **I am a developer** into the Confirmation box and click **Reveal Credentials**.
8. The **Client ID** and **Client Secret** will be displayed **once only**. Copy them.
9. Register the plugin's **Redirect URL** (copied from the settings page) on the OSM application. It must be https.
10. Leave **Client Credentials Grant** unticked.

### Step 2: Connect from WordPress

1. Go to **OSM Settings** in the WordPress admin menu.
2. Open the **Authentication** tab.
3. Leave the grant on **Authorization code with PKCE**.
4. Enter the **Client ID** and **Client Secret** and click **Save settings**. Leaving the secret blank later does not wipe a stored secret.
5. Click **Connect with OSM**. The browser goes to OSM and returns to `wp-admin/admin-post.php?action=osm_oauth_callback`.
6. Access and refresh tokens are stored in WordPress options, not in the page HTML. The plugin refreshes the access token when it expires.

A failed authorisation does **not** delete the stored client ID or secret. The admin notice includes OSM's `error` and `error_description`.

### Client credentials (single user only)

Client credentials are only for the OSM user who created the application, and only if that application has the grant enabled.

1. Create the application as above and reveal the credentials.
2. Close the window, then on the application you just created, click **Edit**.
3. Tick the box labelled **Client Credentials Grant** and click **Save**.
4. In WordPress, select **Client credentials grant**, paste the Client ID and Client Secret, and click **Save & Authenticate**.

Same scopes. Do not use this mode for a site other people use.

### Blocks, invalid data, and rate limits

OSM can block an application that sends invalid data or keeps calling after a warning. This plugin validates waiting-list input before it calls OSM. If a response includes `X-Blocked`, it stores `osm_api_blocked` and refuses every further OSM request until an administrator clears the block. `X-Deprecated` is logged and shown with its date; an endpoint past that date is not called again. HTTP 429 honours `Retry-After` by stopping (it does not retry in a loop).

### Step 3: Enable Sections

1. Navigate to the **Sections Enabled** tab.
2. Select the sections you want to enable by ticking the checkboxes.
3. Click **Save Sections**. The plugin will automatically fetch and cache the current term for each enabled section.

### Step 4: Verify Configuration

1. Go to the **General** tab.
2. Verify that your enabled sections are listed along with their current term IDs.
3. If needed, use the **Purge Cache** or **Reset Configuration** options.

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

Shows a public form for joining an OSM waiting list. Set the target **waiting-list section ID** under **OSM Settings → Waiting List** before publishing the form. There is no hardcoded default section.

Example for testing only: the 4th Ashby waiting list section ID is `60830`. Other groups must enter their own section ID in the admin setting — do not treat `60830` as a default that submits anywhere.

**Required fields:** child first name, last name, date of birth (UK day/month/year), postcode; parent 1 first name, last name, email, phone; consent checkbox.

**Optional fields:** address line 1, town; parent 2 first name, last name, email, phone (if any parent 2 field is filled, first name, last name, and email become required).

On a successful OSM write, the submission is **not** stored in WordPress. The form uses a WordPress nonce, a honeypot field, server-side validation, and a simple per-IP rate limit. Those stay in place when spam protection is **off** (the default).

Optional spam protection (per site, checked on the server before any OSM call):

- **Off** — honeypot and rate limit only. No captcha token is required.
- **Google reCAPTCHA** (v2 checkbox) — site key and secret key from the [Google reCAPTCHA admin](https://www.google.com/recaptcha/admin). Verified at `https://www.google.com/recaptcha/api/siteverify`.
- **Cloudflare Turnstile** — site key and secret key from the [Cloudflare Turnstile dashboard](https://dash.cloudflare.com/?to=/:account/turnstile). Verified at `https://challenges.cloudflare.com/turnstile/v0/siteverify`.

Setting keys: `osm_waiting_list_captcha` (`off`, `recaptcha`, or `turnstile`), `osm_recaptcha_site_key`, `osm_recaptcha_secret_key`, `osm_turnstile_site_key`, `osm_turnstile_secret_key`. A blank secret field does not wipe a stored secret. Failed verification shows a form error and does not call OSM.

Your OSM OAuth application needs `section:member:write` as well as the programme and event read scopes. Reconnect under **OSM Settings → Authentication** after updating so a fresh token is issued.

---

## Admin Features

- **General Tab**:
  - View enabled sections and their current term IDs.
  - Purge cached data.
  - Reset all plugin configuration.

- **Sections Enabled Tab**:
  - List all available sections retrieved from OSM.
  - Enable or disable specific sections.

- **Authentication Tab**:
  - Recommended: authorization code + PKCE. Copy the redirect URL into OSM. Do not tick Client Credentials Grant.
  - Optional: client credentials after you Edit the OSM application and tick **Client Credentials Grant**, then Save.
  - Clear an `X-Blocked` stop once the cause is fixed.

- **Advanced Options**:
  - **Date Format**: Customize the date format used in the plugin. Default: `d/m/Y`.
  - **Time Format**: Customize the time format used in the plugin. Default: `H:i`.

- **Waiting List**:
  - Set the OSM section ID that `[osm_waiting_list]` submissions are written into.
  - Example for testing only: `60830` (4th Ashby waiting list). Leave blank or set your own group's section ID — never rely on a hardcoded default.
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
