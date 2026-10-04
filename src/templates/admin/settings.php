<div class="wrap">
    <h1>Online Scout Manager for WordPress</h1>

    <?php if ( empty( $has_client_id ) || empty( $has_client_secret ) ): ?>
        <div class="notice notice-error"><p><strong>Authentication not configured.</strong> Please configure authentication in the "Authentication" tab.</p></div>
    <?php elseif ( $auth_mode === 'authorization_code' && empty( $osm_connected ) ): ?>
        <div class="notice notice-warning"><p><strong>Not connected to OSM.</strong> Save the client ID and secret, then use <strong>Connect with OSM</strong> on the Authentication tab.</p></div>
    <?php elseif ( empty( $enabled_sections ) ): ?>
        <div class="notice notice-error"><p><strong>No sections enabled.</strong> Please enable sections in the "Sections Enabled" tab.</p></div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=general' ); ?>" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=shortcodes' ); ?>" class="nav-tab <?php echo $active_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">Shortcodes</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=sections' ); ?>" class="nav-tab <?php echo $active_tab === 'sections' ? 'nav-tab-active' : ''; ?>">Sections Enabled</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=advanced_options' ); ?>" class="nav-tab <?php echo $active_tab === 'advanced_options' ? 'nav-tab-active' : ''; ?>">Advanced Options</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=waiting_list' ); ?>" class="nav-tab <?php echo $active_tab === 'waiting_list' ? 'nav-tab-active' : ''; ?>">Waiting List</a>
        <a href="<?php echo admin_url( 'admin.php?page=osm-for-wordpress&tab=authentication' ); ?>" class="nav-tab <?php echo $active_tab === 'authentication' ? 'nav-tab-active' : ''; ?>">Authentication</a>
    </h2>

    <?php if ( $active_tab === 'general' ): ?>
        <h2>General Settings</h2>
        <p>The following functions allow administrators to perform certain actions when maintaining the website or diagnosing an issue. These should not be used unless you know what they do.</p>
        <ul>
            <li><strong>Purge Cache:</strong> This will remove all cached data from the database. This will not affect the configuration settings.</li>
            <li><strong>Reset Configuration:</strong> This will remove all configuration settings, including authentication and enabled sections. This will not affect the cached data.</li>
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
            <p><strong>OSM requests are stopped</strong> because the API returned X-Blocked. Clear the block on the Authentication tab before loading sections.</p>
        <?php elseif ( empty( $enabled_sections ) ): ?>
            <p><strong>No sections enabled.</strong></p>
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
                        $sectionDetails = OSM_API::get_sections()[$sectionid]; ?>
                        <tr class="<?php echo esc_attr( ($row_count % 2 === 0) ? '' : 'alternate' ); ?>">
                            <td class="column-columnname"><?php echo esc_html( $sectionDetails['groupname'] . ': ' . $sectionDetails['sectionname'] ); ?></td>
                            <td class="column-columnname"><?php echo esc_html( $sectionid ); ?></td>
                            <td class="column-columnname"><?php echo esc_html( OSM_API::get_current_term( $sectionid ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Bugs &amp; Feature Requests</h3>
        <p>If you encounter any bugs or have feature requests, please report them via the GitHub repository: <a href="https://github.com/alantiller/osm-for-wordpress" target="_blank">alantiller/osm-for-wordpress</a>.</p>
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
        <p>Example:</p>
        <p>
            <code>[osm_programme sectionid="12345" futureonly="true"]</code>
        </p>

        <h3>Events Shortcode</h3>
        <p>
            <code>[osm_events sectionid="SECTION_ID" futureonly="true"]</code>
        </p>
        <ul>
            <li><strong>sectionid</strong> (required): The ID of the section to display.</li>
            <li><strong>futureonly</strong> (optional): Set to <code>true</code> to show only future events. Default: <code>false</code>.</li>
        </ul>
        <p>Example:</p>
        <p>
            <code>[osm_events sectionid="67890" futureonly="false"]</code>
        </p>

        <h3>Waiting List Shortcode</h3>
        <p>
            <code>[osm_waiting_list]</code>
        </p>
        <p>Shows a public form so a visitor can submit a child onto the waiting-list section configured under the <strong>Waiting List</strong> tab. Successful submissions are written straight into Online Scout Manager and are not stored in WordPress.</p>
        <p>Set the waiting-list section ID in <strong>OSM Settings → Waiting List</strong> before publishing the shortcode. Example for testing only (4th Ashby waiting list): <code>60830</code> — do not treat this as a default; every group must set their own section ID.</p>
    <?php elseif ( $active_tab === 'sections' ): ?>
        <h2>Sections Enabled</h2>
        <?php if ( OSM_API::blocked_flag_is_set( $api_blocked ) ): ?>
            <div class="notice notice-error"><p>OSM requests are stopped (X-Blocked). Clear the block on the Authentication tab before loading sections. Do not keep calling OSM after a block.</p></div>
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
                    <?php foreach ( OSM_API::get_sections() as $sectionid => $section ): ?>
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
        <h2>Waiting List</h2>
        <p>Configure the Online Scout Manager <strong>section ID</strong> that public waiting-list form submissions should be written into. This is typically a dedicated waiting-list section in OSM, not a Beavers/Cubs/Scouts section.</p>
        <p><strong>Important:</strong> leave this blank until you are ready. There is no hardcoded default. As a test example only, the 4th Ashby waiting list section ID is <code>60830</code> — other groups must enter their own section ID.</p>
        <p>Use the shortcode <code>[osm_waiting_list]</code> on any page. When OSM accepts a submission, the plugin does not store the form data in WordPress.</p>
        <p>The OSM application needs <code>section:programme:read</code>, <code>section:event:read</code>, and <code>section:member:write</code>. Reconnect (or Save &amp; Authenticate, if you use client credentials) after changing scopes so OSM issues a new token.</p>
        <p>Invalid data sent to OSM can get the application blocked. The form validates before it calls OSM. If OSM sends <code>X-Blocked</code>, the plugin stops every further OSM call until you clear the block.</p>
        <form method="post" action="<?php echo admin_url( 'admin-post.php?action=osm_save_waiting_list' ); ?>">
            <?php wp_nonce_field( 'osm_waiting_list_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="osm_waiting_list_section_id">Waiting list section ID</label></th>
                    <td>
                        <input type="text" id="osm_waiting_list_section_id" name="osm_waiting_list_section_id" value="<?php echo esc_attr( $waiting_list_section_id ); ?>" class="regular-text" inputmode="numeric" pattern="[0-9]*" placeholder="e.g. your OSM waiting-list section ID">
                        <p class="description">Find the section ID in OSM (or listed under General once the section is available to your API credentials). Example for testing only: 60830 (4th Ashby waiting list).</p>
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
                        <p class="description">Default is off. Keys are per site. The plugin checks the token on the server before any OSM call. A failed check shows a form error and does not call OSM.</p>
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
            <?php submit_button( 'Save Waiting List Settings' ); ?>
        </form>
    <?php elseif ( $active_tab === 'authentication' ): ?>
        <h2>Authentication</h2>
        <p>Recommended: one OSM application, using the <strong>authorization code</strong> flow. Copy the redirect URL below into that application. Do <strong>not</strong> tick <strong>Client Credentials Grant</strong> unless you are using the single-user client-credentials mode.</p>
        <?php if ( stripos( $oauth_redirect_url, 'https://' ) !== 0 ): ?>
            <div class="notice notice-error"><p>The redirect URL is not https. OSM only accepts an https redirect URL, so this site must be served over https before you can connect.</p></div>
        <?php endif; ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="osm_oauth_redirect_url">Redirect URL</label></th>
                <td>
                    <input type="text" id="osm_oauth_redirect_url" class="large-text" readonly value="<?php echo esc_attr( $oauth_redirect_url ); ?>" onclick="this.select();">
                    <p class="description">Paste this into the OSM application exactly. It is <code>admin_url( 'admin-post.php?action=osm_oauth_callback' )</code>, which on this site is <code><?php echo esc_html( $oauth_redirect_url ); ?></code>. No extra parameters. The site must be https.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Connection</th>
                <td>
                    <?php if ( $osm_connected ): ?>
                        <p><strong>Connected.</strong>
                        <?php if ( $token_expires_at ): ?>
                            Access token expiry (site time): <?php echo esc_html( wp_date( 'Y-m-d H:i', $token_expires_at ) ); ?>.
                        <?php endif; ?>
                        The access token and refresh token are stored in WordPress options and are not shown here.</p>
                    <?php else: ?>
                        <p><strong>Not connected.</strong> Tokens are stored only after a successful grant, and they are not printed on this page.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php if ( OSM_API::blocked_flag_is_set( $api_blocked ) ): ?>
            <div class="notice notice-error inline"><p>OSM returned <strong>X-Blocked</strong>. Further OSM calls are refused until you clear this. Only clear it after the cause is fixed.</p></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=osm_clear_api_block' ) ); ?>">
                <?php wp_nonce_field( 'osm_clear_api_block' ); ?>
                <?php submit_button( 'Clear OSM API block', 'delete' ); ?>
            </form>
        <?php endif; ?>

        <?php if ( is_array( $api_removed ) && $api_removed ): ?>
            <div class="notice notice-warning inline"><p>These OSM paths are past their <code>X-Deprecated</code> removal date and will not be called: <?php echo esc_html( implode( ', ', array_keys( $api_removed ) ) ); ?>.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=osm_save_auth' ) ); ?>" autocomplete="off">
            <?php wp_nonce_field( 'osm_auth_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Grant</th>
                    <td>
                        <label>
                            <input type="radio" name="osm_auth_mode" value="authorization_code" <?php checked( $auth_mode, 'authorization_code' ); ?>>
                            Authorization code with PKCE (recommended)
                        </label><br>
                        <label>
                            <input type="radio" name="osm_auth_mode" value="client_credentials" <?php checked( $auth_mode, 'client_credentials' ); ?>>
                            Client credentials grant (single user who created the application)
                        </label>
                        <p class="description">Use authorization code when other people use the site. Client credentials works only for the OSM user who created the application, and only if that application has <strong>Client Credentials Grant</strong> ticked.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="osm_client_id" autocomplete="off" name="osm_client_id" value="" class="regular-text" placeholder="<?php echo $has_client_id ? esc_attr( 'A client ID is saved. Leave blank to keep it.' ) : ''; ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="osm_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="osm_client_secret" autocomplete="new-password" name="osm_client_secret" value="" class="regular-text" placeholder="<?php echo $has_client_secret ? esc_attr( 'A client secret is saved. Leave blank to keep it.' ) : ''; ?>">
                        <p class="description">Leave the password blank to keep the stored secret. A failed authorisation does not delete the saved client ID or secret.</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="osm_auth_action" value="save" class="button button-secondary">Save settings</button>
                <button type="submit" name="osm_auth_action" value="connect" class="button button-primary">Connect with OSM</button>
                <button type="submit" name="osm_auth_action" value="client_credentials" class="button">Save &amp; Authenticate</button>
            </p>
            <h3>Recommended: authorization code</h3>
            <ol>
                <li>Log in to <a href="https://www.onlinescoutmanager.co.uk" target="_blank" rel="noopener noreferrer">Online Scout Manager (OSM)</a>.</li>
                <li>Expand the <strong>Settings</strong> menu at the bottom of the page.</li>
                <li>Select <strong>My Account Details</strong>.</li>
                <li>Click <strong>Developer Tools</strong> from the menu on the left-hand side.</li>
                <li>Click <strong>Create Application</strong>.</li>
                <li>Provide a name for your application and click <strong>Save</strong>.</li>
                <li>Enter <strong>I am a developer</strong> into the Confirmation field and click <strong>Reveal Credentials</strong>.</li>
                <li>The <strong>Client ID</strong> and <strong>Client Secret</strong> are displayed <em>once only</em>. Copy them into the fields above and click <strong>Save settings</strong>.</li>
                <li>Copy the <strong>Redirect URL</strong> from this page into the OSM application. It must be https and must match exactly.</li>
                <li>Do <strong>not</strong> tick <strong>Client Credentials Grant</strong>.</li>
                <li>Scopes used: <code>section:programme:read</code> <code>section:event:read</code> <code>section:member:write</code>. Do not add finance, administration, badge, attendance, quartermaster, or flexirecord.</li>
                <li>Click <strong>Connect with OSM</strong>. You return to this site at the redirect URL above. PKCE uses <code>code_challenge_method=S256</code>.</li>
            </ol>
            <h3>Client credentials (single user only)</h3>
            <p>Only the OSM user who created the application can use this, and only after the grant is enabled. After creating the application, Edit it and tick <strong>Client Credentials Grant</strong>, then Save. Same scopes as above.</p>
            <ol>
                <li>Log in to <a href="https://www.onlinescoutmanager.co.uk" target="_blank" rel="noopener noreferrer">Online Scout Manager (OSM)</a>.</li>
                <li>Expand the <strong>Settings</strong> menu at the bottom of the page.</li>
                <li>Select <strong>My Account Details</strong>.</li>
                <li>Click <strong>Developer Tools</strong> from the menu on the left-hand side.</li>
                <li>Click <strong>Create Application</strong>.</li>
                <li>Provide a name for your application and click <strong>Save</strong>.</li>
                <li>Enter <strong>I am a developer</strong> into the Confirmation field and click <strong>Reveal Credentials</strong>.</li>
                <li>The <strong>Client ID</strong> and <strong>Client Secret</strong> will be displayed <em>once only</em>. Make sure you note them down as they will be required in the following steps.</li>
                <li>Close the window, then on the application you just created, click <strong>Edit</strong>.</li>
                <li>Tick the box labelled <strong>Client Credentials Grant</strong> and click <strong>Save</strong>.</li>
                <li>Back here, select <strong>Client credentials grant</strong>, paste the Client ID and Client Secret, and click <strong>Save &amp; Authenticate</strong>.</li>
            </ol>
            <p>If OSM rejects the token request, the notice includes OSM’s <code>error</code> and <code>error_description</code>, not only “API HTTP error 401”. Stored credentials are left in place.</p>
        </form>
    <?php endif; ?>
</div>
