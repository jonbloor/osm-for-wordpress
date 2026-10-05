<div class="wrap">
    <h1>Online Scout Manager for WordPress</h1>

    <?php if ( ! OSM_Helper_Client::is_configured() ): ?>
        <div class="notice notice-warning"><p><strong>Waiting list not linked to OSM Helper.</strong> Open the <strong>Waiting List</strong> tab, set the OSM Helper base URL, and paste the site key from OSM Helper Settings.</p></div>
    <?php elseif ( empty( $enabled_sections ) ): ?>
        <div class="notice notice-info"><p>No programme/events sections enabled. Waiting-list forms do not need that — they go through OSM Helper.</p></div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=general' ); ?>" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=shortcodes' ); ?>" class="nav-tab <?php echo $active_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">Shortcodes</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=sections' ); ?>" class="nav-tab <?php echo $active_tab === 'sections' ? 'nav-tab-active' : ''; ?>">Sections Enabled</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=advanced_options' ); ?>" class="nav-tab <?php echo $active_tab === 'advanced_options' ? 'nav-tab-active' : ''; ?>">Advanced Options</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=waiting_list' ); ?>" class="nav-tab <?php echo $active_tab === 'waiting_list' ? 'nav-tab-active' : ''; ?>">Waiting List</a>
    </h2>

    <?php if ( $active_tab === 'general' ): ?>
        <h2>General Settings</h2>
        <p>The following functions allow administrators to perform certain actions when maintaining the website or diagnosing an issue. These should not be used unless you know what they do.</p>
        <ul>
            <li><strong>Purge Cache:</strong> This will remove all cached data from the database. This will not affect the configuration settings.</li>
            <li><strong>Reset Configuration:</strong> This will remove all configuration settings, including OSM Helper keys and enabled sections. This will not affect the cached data.</li>
        </ul>
        
        <div class="osm-actions">
            <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_purge_cache' ); ?>" style="margin-bottom: 10px;">
                <?php wp_nonce_field( 'osm_purge_nonce' ); ?>
                <button type="submit" class="button">Purge Cache</button>
            </form>

            <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_reset_configuration' ); ?>">
                <?php wp_nonce_field( 'osm_reset_nonce' ); ?>
                <button type="submit" class="button button-secondary">Reset Configuration</button>
            </form>
        </div>

        <h3>Enabled Sections</h3>
        <?php if ( OSM_API::blocked_flag_is_set( $api_blocked ) ): ?>
            <p><strong>Legacy OSM requests are stopped</strong> because the API returned X-Blocked. Waiting-list forms use OSM Helper separately.</p>
        <?php elseif ( empty( $enabled_sections ) ): ?>
            <p><strong>No sections enabled</strong> for programme/events shortcodes.</p>
        <?php else: ?>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th id="columnname" class="manage-column column-columnname" scope="col">Section Name</th>
                        <th id="columnname" class="manage-column column-columnname" scope="col">Section ID</th>
                        <th id="columnname" class="manage-column column-columnname" scope="col">Current Term ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row_count = 1;
                    foreach ( $enabled_sections as $sectionid => $enabled ):
                        $row_count++;
                        try {
                            $sectionDetails = OSM_API::get_sections()[$sectionid] ?? null;
                        } catch ( Exception $e ) {
                            $sectionDetails = null;
                        }
                        ?>
                        <tr class="<?php echo esc_attr( ($row_count % 2 === 0) ? '' : 'alternate' ); ?>">
                            <td class="column-columnname"><?php echo $sectionDetails ? esc_html( $sectionDetails['groupname'] . ': ' . $sectionDetails['sectionname'] ) : esc_html( (string) $sectionid ); ?></td>
                            <td class="column-columnname"><?php echo esc_html( $sectionid ); ?></td>
                            <td class="column-columnname"><?php
                                try {
                                    echo esc_html( OSM_API::get_current_term( $sectionid ) );
                                } catch ( Exception $e ) {
                                    echo '—';
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Bugs &amp; Feature Requests</h3>
        <p>If you encounter any bugs or have feature requests, please report them via the GitHub repository: <a href="https://github.com/jonbloor/osm-for-wordpress" target="_blank" rel="noopener noreferrer">jonbloor/osm-for-wordpress</a>.</p>
    <?php elseif ( $active_tab === 'shortcodes' ): ?>
        <h2>Shortcodes</h2>
        <p>Use the following shortcodes to display OSM data on your website:</p>

        <h3>Programme Shortcode</h3>
        <p>
            <code>[osm_programme sectionid="SECTION_ID" futureonly="true"]</code>
        </p>
        <ul>
            <li><strong>sectionid</strong> (required): The ID of the section to display.</li>
            <li><strong>futureonly</strong> (optional): Set to <code>true</code> to show only future events. Default: <code>false</code>.</li>
        </ul>

        <h3>Events Shortcode</h3>
        <p>
            <code>[osm_events sectionid="SECTION_ID" futureonly="true"]</code>
        </p>
        <ul>
            <li><strong>sectionid</strong> (required): The ID of the section to display.</li>
            <li><strong>futureonly</strong> (optional): Set to <code>true</code> to show only future events. Default: <code>false</code>.</li>
        </ul>

        <h3>Waiting List Shortcode</h3>
        <p>
            <code>[osm_waiting_list]</code>
        </p>
        <p>Shows a public form so a visitor can submit a child onto your OSM waiting list. The parent never logs in to OSM. Configure OSM Helper under the <strong>Waiting List</strong> tab. Successful submissions are written by OSM Helper into OSM and are not stored in WordPress.</p>
    <?php elseif ( $active_tab === 'sections' ): ?>
        <h2>Sections Enabled</h2>
        <p class="description">Programme and events shortcodes still use a legacy OSM token if one exists. Waiting-list forms do <strong>not</strong> use this tab — they go through OSM Helper.</p>
        <?php if ( OSM_API::blocked_flag_is_set( $api_blocked ) ): ?>
            <div class="notice notice-error"><p>OSM requests are stopped (X-Blocked). Waiting-list intake is separate (OSM Helper).</p></div>
        <?php else: ?>
        <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_save_sections' ); ?>">
            <?php wp_nonce_field( 'osm_sections_nonce' ); ?>
            <table class="form-table">
                <thead>
                    <tr>
                        <th>Section Name</th>
                        <th>Enable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    try {
                        $sections = OSM_API::get_sections();
                    } catch ( Exception $e ) {
                        $sections = [];
                        echo '<tr><td colspan="2">Could not load sections from OSM. Waiting-list forms still work via OSM Helper.</td></tr>';
                    }
                    foreach ( $sections as $sectionid => $section ): ?>
                        <tr>
                            <td><?php echo esc_html( $section['groupname'] . ': ' . $section['sectionname'] ); ?></td>
                            <td>
                                <input type="checkbox" name="osm_enabled_sections[<?php echo esc_attr( $sectionid ); ?>]" value="1" <?php checked( 1, $enabled_sections[ $sectionid ] ?? 0 ); ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( 'Save Sections' ); ?>
        </form>
        <?php endif; ?>
    <?php elseif ( $active_tab === 'advanced_options' ): ?>
        <h2>Advanced Options</h2>
        <p>These options allow you to further customise the way the plugin behaves and displays data.</p>
        <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_save_advanced_options' ); ?>">
            <?php wp_nonce_field( 'osm_advanced_options_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="osm_date_format">Date Format</label></th>
                    <td>
                        <input type="text" id="osm_date_format" name="osm_date_format" value="<?php echo esc_attr( $advanced_options['osm_date_format'] ); ?>" placeholder="d M Y" class="regular-text">
                        <p class="description">You can enter any valid PHP date format above, for more information see the <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank">PHP documentation</a>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_time_format">Time Format</label></th>
                    <td>
                        <input type="text" id="osm_time_format" name="osm_time_format" value="<?php echo esc_attr( $advanced_options['osm_time_format'] ); ?>" placeholder="H:i" class="regular-text">
                        <p class="description">You can enter any valid PHP time format above, for more information see the <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank">PHP documentation</a>.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Advanced Options' ); ?>
        </form>
    <?php elseif ( $active_tab === 'waiting_list' ): ?>
        <h2>Waiting List (via OSM Helper)</h2>
        <p>Parents fill this form on your website. They never log in to OSM or approve an app. OSM Helper already holds the one-time OSM approval for your group. Choose the waiting-list section and copy the site key in <a href="https://osmhelper.co.uk/settings/" target="_blank" rel="noopener noreferrer">OSM Helper → Settings</a> after you are signed in there.</p>
        <p>This plugin does <strong>not</strong> ask for an OSM client ID, client secret, or Connect with OSM. Other groups do not need to create an OSM application.</p>
        <p>Use the shortcode <code>[osm_waiting_list]</code> on any page. When OSM Helper accepts a submission, the plugin does not store the form data in WordPress.</p>
        <p>Validation, honeypot, per-IP rate limit, and captcha run here first. Captcha (if enabled) is verified on the server before calling OSM Helper.</p>
        <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_save_waiting_list' ); ?>" autocomplete="off">
            <?php wp_nonce_field( 'osm_waiting_list_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="osm_helper_base_url">OSM Helper base URL</label></th>
                    <td>
                        <input type="url" id="osm_helper_base_url" name="osm_helper_base_url" value="<?php echo esc_attr( $helper_base_url ); ?>" class="regular-text" placeholder="https://osmhelper.co.uk">
                        <p class="description">Default is <code>https://osmhelper.co.uk</code>. Must be https. The plugin posts to <code><?php echo esc_html( OSM_Helper_Client::SUBMIT_PATH ); ?></code> on that host.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_helper_site_key">OSM Helper site key</label></th>
                    <td>
                        <input type="password" id="osm_helper_site_key" name="osm_helper_site_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_helper_site_key ? esc_attr( 'A site key is saved. Leave blank to keep it.' ) : ''; ?>">
                        <p class="description">Copy from OSM Helper Settings → WordPress waiting-list form. Leave blank to keep the saved key. It is stored in WordPress options and is not shown again.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_waiting_list_captcha">Spam protection</label></th>
                    <td>
                        <select id="osm_waiting_list_captcha" name="osm_waiting_list_captcha">
                            <option value="off" <?php selected( $captcha_mode, 'off' ); ?>>Off (honeypot and per-IP rate limit only)</option>
                            <option value="recaptcha" <?php selected( $captcha_mode, 'recaptcha' ); ?>>Google reCAPTCHA (v2 checkbox)</option>
                            <option value="turnstile" <?php selected( $captcha_mode, 'turnstile' ); ?>>Cloudflare Turnstile</option>
                        </select>
                        <p class="description">Default is off. Keys are per site. The plugin checks the token on the server before calling OSM Helper. A failed check shows a form error and does not call OSM Helper.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_recaptcha_site_key">reCAPTCHA site key</label></th>
                    <td>
                        <input type="text" id="osm_recaptcha_site_key" name="osm_recaptcha_site_key" value="<?php echo esc_attr( $recaptcha_site_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">From the <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">Google reCAPTCHA admin</a> (v2 “I’m not a robot” checkbox). This key is public and is shown on the form.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_recaptcha_secret_key">reCAPTCHA secret key</label></th>
                    <td>
                        <input type="password" id="osm_recaptcha_secret_key" name="osm_recaptcha_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_recaptcha_secret ? esc_attr( 'A secret key is saved. Leave blank to keep it.' ) : ''; ?>">
                        <p class="description">Leave blank to keep the saved secret. It is stored in WordPress options and is not shown again.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_turnstile_site_key">Turnstile site key</label></th>
                    <td>
                        <input type="text" id="osm_turnstile_site_key" name="osm_turnstile_site_key" value="<?php echo esc_attr( $turnstile_site_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">From the <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">Cloudflare Turnstile dashboard</a>. Create a widget for this site and copy its site key.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_turnstile_secret_key">Turnstile secret key</label></th>
                    <td>
                        <input type="password" id="osm_turnstile_secret_key" name="osm_turnstile_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_turnstile_secret ? esc_attr( 'A secret key is saved. Leave blank to keep it.' ) : ''; ?>">
                        <p class="description">Leave blank to keep the saved secret. It is not written into the page.</p>
                    </td>
                </tr>
            </table>

            <h3>Parent note</h3>
            <p class="description">The form has an optional “Anything else we should know?” box (up to <?php echo esc_html( (string) OSM_Waiting_List::PARENT_NOTE_MAX ); ?> characters, plain text). OSM Helper saves it to the waiting list’s <strong>Notes</strong> field, the one chosen in <a href="https://osmhelper.co.uk/waiting-list/fields/" target="_blank" rel="noopener noreferrer">OSM Helper → Waiting list → Rank &amp; notes settings</a>, as a line like <code>05/10/26 10:30 - Parent (website form) - "…"</code>. If no Notes field is chosen there, or OSM refuses the note, the child is still added. The parent is asked to send the note another way, and the problem is written to the PHP error log.</p>

            <h3>Confirmation email</h3>
            <p class="description">Sent with WordPress’s own mail function (<code>wp_mail</code>) only after OSM Helper confirms the child is on the OSM waiting list. It is never sent if the OSM write fails. Nothing from the form is stored in WordPress. If emails do not arrive, set up an SMTP plugin for this site.</p>
            <table class="form-table">
                <tr>
                    <th>Send confirmation</th>
                    <td>
                        <label><input type="checkbox" name="osm_wl_email_enabled" value="1" <?php checked( $email_enabled ); ?>> Email parent 1 a receipt after a successful submission</label><br>
                        <label><input type="checkbox" name="osm_wl_email_parent2" value="1" <?php checked( $email_parent2 ); ?>> Also email parent 2 (if they gave an email address)</label>
                        <p class="description">On by default. Each parent gets a separate email, so they do not see each other’s address.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_wl_group_name">Group name</label></th>
                    <td>
                        <input type="text" id="osm_wl_group_name" name="osm_wl_group_name" value="<?php echo esc_attr( $group_name ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                        <p class="description">Used for <code>{group_name}</code>. Leave blank to use the site title.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_wl_email_from_name">From name</label></th>
                    <td>
                        <input type="text" id="osm_wl_email_from_name" name="osm_wl_email_from_name" value="<?php echo esc_attr( $email_from_name ); ?>" class="regular-text" placeholder="<?php echo esc_attr( OSM_Waiting_List_Email::group_name() ); ?>">
                        <p class="description">The sender name parents see. Leave blank to use the group name. The from address is still WordPress’s (or your SMTP plugin’s), which helps delivery.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_wl_email_reply_to">Reply-to email</label></th>
                    <td>
                        <input type="email" id="osm_wl_email_reply_to" name="osm_wl_email_reply_to" value="<?php echo esc_attr( $email_reply_to ); ?>" class="regular-text" placeholder="waitinglist@example.org.uk">
                        <p class="description">Optional. Where parents’ replies go, for example your waiting-list or group secretary address. Without it, replies go to the from address.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_wl_email_subject">Subject</label></th>
                    <td>
                        <input type="text" id="osm_wl_email_subject" name="osm_wl_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="large-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_wl_email_body">Message</label></th>
                    <td>
                        <textarea id="osm_wl_email_body" name="osm_wl_email_body" rows="14" class="large-text code"><?php echo esc_textarea( $email_body ); ?></textarea>
                        <p class="description">Plain text. Clear the subject or message and save to go back to the default text. Placeholders:</p>
                        <ul class="osm-placeholder-list"><?php foreach ( OSM_Waiting_List_Email::PLACEHOLDERS as $tag => $label ) : ?><li><code><?php echo esc_html( $tag ); ?></code> – <?php echo esc_html( $label ); ?></li><?php endforeach; ?></ul>
                    </td>
                </tr>
            </table>

            <h3>Address lookup</h3>
            <table class="form-table">
                <tr>
                    <th><label for="osm_wl_address_lookup">Address lookup</label></th>
                    <td>
                        <select id="osm_wl_address_lookup" name="osm_wl_address_lookup">
                            <option value="off" <?php selected( $address_lookup, 'off' ); ?>>Off: parents type the address (default)</option>
                            <option value="google" <?php selected( $address_lookup, 'google' ); ?>>Google Places address autocomplete (needs an API key)</option>
                            <option value="postcodes_io" <?php selected( $address_lookup, 'postcodes_io' ); ?>>Postcode check with postcodes.io (free, no key)</option>
                        </select>
                        <p class="description">Scripts load only on pages showing <code>[osm_waiting_list]</code>. Manual entry always still works, and this works with captcha off, reCAPTCHA or Turnstile.</p>
                        <ul class="osm-placeholder-list">
                            <li><strong>Google Places:</strong> parents start typing and pick their address, and address line 1, line 2, town, county and postcode are filled in. Results are limited to the UK. Google needs a billing account on the Cloud project, but there is a monthly free allowance, so a small group’s waiting list normally costs nothing. Set a budget alert to be sure.</li>
                            <li><strong>postcodes.io:</strong> free and open data, with no key or account. When the parent leaves the postcode box, the browser checks the postcode and fills in the town and county if they are empty (county only where postcodes.io has one). It <em>cannot</em> list house addresses, so parents still type address line 1.</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_google_maps_api_key">Google Maps API key</label></th>
                    <td>
                        <input type="text" id="osm_google_maps_api_key" name="osm_google_maps_api_key" value="<?php echo esc_attr( $google_maps_api_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Only for Google Places. In <a href="https://console.cloud.google.com/google/maps-apis/" target="_blank" rel="noopener noreferrer">Google Cloud console</a>, turn on billing, then enable the <strong>Maps JavaScript API</strong> and <strong>Places API (New)</strong>. (Older keys that only have the legacy Places API still work, using Google’s older widget.) Restrict the key to <em>Websites</em> with this site’s address (<code><?php echo esc_html( home_url( '/*' ) ); ?></code>), and to those two APIs. The key is public: it is included in the page.</p>
                    </td>
                </tr>
                <tr>
                    <th>Server-side postcode check</th>
                    <td>
                        <label><input type="checkbox" name="osm_wl_postcode_server_check" value="1" <?php checked( $postcode_server_check ); ?>> Also check the postcode with postcodes.io when the form is sent</label>
                        <p class="description">Only used with the postcodes.io option. A postcode that postcodes.io says does not exist is sent back to the parent to correct. If postcodes.io is slow (over <?php echo esc_html( (string) OSM_Waiting_List::POSTCODES_IO_TIMEOUT ); ?> seconds) or unavailable, the form carries on (fails open). Off by default.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Waiting List Settings' ); ?>
        </form>
        <?php if ( OSM_API::blocked_flag_is_set( $api_blocked ) ): ?>
            <h3>Legacy OSM block</h3>
            <p>A previous direct OSM connection stored an X-Blocked flag. Waiting-list intake uses OSM Helper; clear Helper’s WordPress block there if needed.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=osm_clear_api_block' ) ); ?>">
                <?php wp_nonce_field( 'osm_clear_api_block' ); ?>
                <?php submit_button( 'Clear legacy OSM API block', 'delete' ); ?>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
