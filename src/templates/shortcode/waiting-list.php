<?php
/**
 * Public waiting-list form template.
 *
 * Variables from OSM_Shortcodes::render_waiting_list():
 * @var array|null $result
 * @var array      $values
 * @var array      $errors
 * @var bool       $section_configured
 * @var string     $captcha_mode
 * @var string     $captcha_site_key
 * @var string     $address_lookup   off, google, or postcodes_io (effective mode)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$address_lookup = isset( $address_lookup ) ? (string) $address_lookup : 'off';

$val = static function ( $key ) use ( $values ) {
    return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
};

$err = static function ( $key ) use ( $errors ) {
    return isset( $errors[ $key ] ) ? (string) $errors[ $key ] : '';
};

$field_class = static function ( $key ) use ( $errors ) {
    return isset( $errors[ $key ] ) ? 'osm-wl-field osm-wl-field--error' : 'osm-wl-field';
};
?>
<div class="osm-waiting-list" aria-live="polite">
    <?php if ( is_array( $result ) && ! empty( $result['message'] ) ) : ?>
        <div class="osm-wl-notice <?php echo ! empty( $result['success'] ) ? 'osm-wl-notice--success' : 'osm-wl-notice--error'; ?>" role="status">
            <p><?php echo esc_html( $result['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! $section_configured ) : ?>
        <div class="osm-wl-notice osm-wl-notice--error" role="status">
            <p><?php echo esc_html( 'The waiting list form is not available yet. Please contact the group.' ); ?></p>
        </div>
    <?php elseif ( empty( $result['success'] ) ) : ?>
        <form class="osm-wl-form" method="post" action="" novalidate>
            <?php wp_nonce_field( 'osm_waiting_list_submit', 'osm_waiting_list_nonce' ); ?>

            <?php /* Honeypot — leave blank. Hidden from assistive tech. */ ?>
            <div class="osm-wl-hp" aria-hidden="true">
                <label for="osm_wl_website">Website</label>
                <input type="text" id="osm_wl_website" name="osm_wl_website" value="" tabindex="-1" autocomplete="off">
            </div>

            <fieldset class="osm-wl-fieldset">
                <legend>Child</legend>

                <div class="<?php echo esc_attr( $field_class( 'child_first_name' ) ); ?>">
                    <label for="osm_wl_child_first_name">First name <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_child_first_name" name="child_first_name" required autocomplete="off" value="<?php echo esc_attr( $val( 'child_first_name' ) ); ?>">
                    <?php if ( $err( 'child_first_name' ) ) : ?><p class="osm-wl-error" id="err_child_first_name"><?php echo esc_html( $err( 'child_first_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_last_name' ) ); ?>">
                    <label for="osm_wl_child_last_name">Last name <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_child_last_name" name="child_last_name" required autocomplete="off" value="<?php echo esc_attr( $val( 'child_last_name' ) ); ?>">
                    <?php if ( $err( 'child_last_name' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'child_last_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_dob' ) ); ?>">
                    <label for="osm_wl_child_dob">Date of birth <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_child_dob" name="child_dob" required inputmode="numeric" placeholder="DD/MM/YYYY" autocomplete="off" value="<?php echo esc_attr( $val( 'child_dob' ) ); ?>" aria-describedby="osm_wl_child_dob_hint">
                    <p class="osm-wl-hint" id="osm_wl_child_dob_hint">Use day/month/year, for example 15/03/2018.</p>
                    <?php if ( $err( 'child_dob' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'child_dob' ) ); ?></p><?php endif; ?>
                </div>

            </fieldset>

            <fieldset class="osm-wl-fieldset">
                <legend>Home address</legend>

                <?php if ( $address_lookup === 'google' ) : ?>
                <div class="osm-wl-field osm-wl-address-search" id="osm_wl_address_search" hidden>
                    <p class="osm-wl-label" id="osm_wl_address_search_label">Find the address <span class="osm-wl-optional">(optional)</span></p>
                    <p class="osm-wl-hint">Start typing, then choose the address to fill in the boxes below. You can still type it yourself.</p>
                </div>
                <?php endif; ?>

                <div class="<?php echo esc_attr( $field_class( 'child_address' ) ); ?>">
                    <label for="osm_wl_child_address">Address line 1 <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_child_address" name="child_address" required autocomplete="address-line1" value="<?php echo esc_attr( $val( 'child_address' ) ); ?>">
                    <?php if ( $err( 'child_address' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'child_address' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_address2' ) ); ?>">
                    <label for="osm_wl_child_address2">Address line 2 <span class="osm-wl-optional">(optional)</span></label>
                    <input type="text" id="osm_wl_child_address2" name="child_address2" autocomplete="address-line2" value="<?php echo esc_attr( $val( 'child_address2' ) ); ?>">
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_town' ) ); ?>">
                    <label for="osm_wl_child_town">Town <span class="osm-wl-optional">(optional)</span></label>
                    <input type="text" id="osm_wl_child_town" name="child_town" autocomplete="address-level2" value="<?php echo esc_attr( $val( 'child_town' ) ); ?>">
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_county' ) ); ?>">
                    <label for="osm_wl_child_county">County <span class="osm-wl-optional">(optional)</span></label>
                    <input type="text" id="osm_wl_child_county" name="child_county" autocomplete="address-level1" value="<?php echo esc_attr( $val( 'child_county' ) ); ?>">
                </div>

                <div class="<?php echo esc_attr( $field_class( 'child_postcode' ) ); ?>">
                    <label for="osm_wl_child_postcode">Postcode <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_child_postcode" name="child_postcode" required autocomplete="postal-code" value="<?php echo esc_attr( $val( 'child_postcode' ) ); ?>"<?php echo $address_lookup === 'postcodes_io' ? ' aria-describedby="osm_wl_postcode_status"' : ''; ?>>
                    <?php if ( $address_lookup === 'postcodes_io' ) : ?><p class="osm-wl-lookup-status" id="osm_wl_postcode_status" aria-live="polite"></p><?php endif; ?>
                    <?php if ( $err( 'child_postcode' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'child_postcode' ) ); ?></p><?php endif; ?>
                </div>
            </fieldset>

            <fieldset class="osm-wl-fieldset">
                <legend>Parent 1</legend>

                <div class="<?php echo esc_attr( $field_class( 'parent1_first_name' ) ); ?>">
                    <label for="osm_wl_parent1_first_name">First name <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_parent1_first_name" name="parent1_first_name" required autocomplete="given-name" value="<?php echo esc_attr( $val( 'parent1_first_name' ) ); ?>">
                    <?php if ( $err( 'parent1_first_name' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent1_first_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent1_last_name' ) ); ?>">
                    <label for="osm_wl_parent1_last_name">Last name <span class="osm-wl-required">(required)</span></label>
                    <input type="text" id="osm_wl_parent1_last_name" name="parent1_last_name" required autocomplete="family-name" value="<?php echo esc_attr( $val( 'parent1_last_name' ) ); ?>">
                    <?php if ( $err( 'parent1_last_name' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent1_last_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent1_email' ) ); ?>">
                    <label for="osm_wl_parent1_email">Email <span class="osm-wl-required">(required)</span></label>
                    <input type="email" id="osm_wl_parent1_email" name="parent1_email" required autocomplete="email" value="<?php echo esc_attr( $val( 'parent1_email' ) ); ?>">
                    <?php if ( $err( 'parent1_email' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent1_email' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent1_phone' ) ); ?>">
                    <label for="osm_wl_parent1_phone">Phone <span class="osm-wl-required">(required)</span></label>
                    <input type="tel" id="osm_wl_parent1_phone" name="parent1_phone" required autocomplete="tel" value="<?php echo esc_attr( $val( 'parent1_phone' ) ); ?>">
                    <?php if ( $err( 'parent1_phone' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent1_phone' ) ); ?></p><?php endif; ?>
                </div>
                <div class="<?php echo esc_attr( $field_class( 'parent1_sms' ) ); ?>">
                    <label class="osm-wl-consent" for="osm_wl_parent1_sms">
                        <input type="checkbox" id="osm_wl_parent1_sms" name="parent1_sms" value="1" <?php checked( $val( 'parent1_sms' ), '1' ); ?>>
                        <span>Receive text messages from Leaders? <span class="osm-wl-optional">(optional)</span></span>
                    </label>
                    <p class="osm-wl-hint">Tick if leaders may send texts to this phone number.</p>
                </div>
            </fieldset>

            <fieldset class="osm-wl-fieldset">
                <legend>Parent 2 <span class="osm-wl-optional">(optional)</span></legend>
                <p class="osm-wl-hint">If you fill in any parent 2 field, first name, last name, and email are required.</p>

                <div class="<?php echo esc_attr( $field_class( 'parent2_first_name' ) ); ?>">
                    <label for="osm_wl_parent2_first_name">First name</label>
                    <input type="text" id="osm_wl_parent2_first_name" name="parent2_first_name" autocomplete="off" value="<?php echo esc_attr( $val( 'parent2_first_name' ) ); ?>">
                    <?php if ( $err( 'parent2_first_name' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent2_first_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent2_last_name' ) ); ?>">
                    <label for="osm_wl_parent2_last_name">Last name</label>
                    <input type="text" id="osm_wl_parent2_last_name" name="parent2_last_name" autocomplete="off" value="<?php echo esc_attr( $val( 'parent2_last_name' ) ); ?>">
                    <?php if ( $err( 'parent2_last_name' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent2_last_name' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent2_email' ) ); ?>">
                    <label for="osm_wl_parent2_email">Email</label>
                    <input type="email" id="osm_wl_parent2_email" name="parent2_email" autocomplete="off" value="<?php echo esc_attr( $val( 'parent2_email' ) ); ?>">
                    <?php if ( $err( 'parent2_email' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent2_email' ) ); ?></p><?php endif; ?>
                </div>

                <div class="<?php echo esc_attr( $field_class( 'parent2_phone' ) ); ?>">
                    <label for="osm_wl_parent2_phone">Phone</label>
                    <input type="tel" id="osm_wl_parent2_phone" name="parent2_phone" autocomplete="off" value="<?php echo esc_attr( $val( 'parent2_phone' ) ); ?>">
                    <?php if ( $err( 'parent2_phone' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent2_phone' ) ); ?></p><?php endif; ?>
                </div>
                <div class="<?php echo esc_attr( $field_class( 'parent2_sms' ) ); ?>">
                    <label class="osm-wl-consent" for="osm_wl_parent2_sms">
                        <input type="checkbox" id="osm_wl_parent2_sms" name="parent2_sms" value="1" <?php checked( $val( 'parent2_sms' ), '1' ); ?>>
                        <span>Receive text messages from Leaders? <span class="osm-wl-optional">(optional)</span></span>
                    </label>
                    <p class="osm-wl-hint">Tick if leaders may send texts to parent 2’s phone number (needs a phone number above).</p>
                </div>
            </fieldset>

            <div class="<?php echo esc_attr( $field_class( 'parent_note' ) ); ?>">
                <label for="osm_wl_parent_note">Anything else we should know? <span class="osm-wl-optional">(optional)</span></label>
                <textarea id="osm_wl_parent_note" name="parent_note" rows="4" maxlength="<?php echo esc_attr( (string) OSM_Waiting_List::PARENT_NOTE_MAX ); ?>" aria-describedby="osm_wl_parent_note_hint"><?php echo esc_textarea( $val( 'parent_note' ) ); ?></textarea>
                <p class="osm-wl-hint" id="osm_wl_parent_note_hint">For example, a brother or sister already in the group, or a preferred meeting night. Up to <?php echo esc_html( (string) OSM_Waiting_List::PARENT_NOTE_MAX ); ?> characters. Please do not include medical details here.</p>
                <?php if ( $err( 'parent_note' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'parent_note' ) ); ?></p><?php endif; ?>
            </div>

            <div class="<?php echo esc_attr( $field_class( 'consent' ) ); ?>">
                <label class="osm-wl-consent" for="osm_wl_consent">
                    <input type="checkbox" id="osm_wl_consent" name="consent" value="1" required <?php checked( $val( 'consent' ), '1' ); ?>>
                    <span><?php echo esc_html( OSM_Waiting_List::consent_label() ); ?> <span class="osm-wl-required">(required)</span></span>
                </label>
                <?php if ( $err( 'consent' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'consent' ) ); ?></p><?php endif; ?>
            </div>

            <?php if ( OSM_Waiting_List::captcha_requires_token( $captcha_mode ) ) : ?>
                <div class="<?php echo esc_attr( $field_class( 'captcha' ) ); ?> osm-wl-captcha">
                    <?php if ( $captcha_site_key === '' ) : ?>
                        <p class="osm-wl-error">This form is not available yet. Please contact the group.</p>
                    <?php elseif ( $captcha_mode === 'recaptcha' ) : ?>
                        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $captcha_site_key ); ?>"></div>
                    <?php elseif ( $captcha_mode === 'turnstile' ) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $captcha_site_key ); ?>"></div>
                    <?php endif; ?>
                    <?php if ( $err( 'captcha' ) ) : ?><p class="osm-wl-error"><?php echo esc_html( $err( 'captcha' ) ); ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! OSM_Waiting_List::captcha_requires_token( $captcha_mode ) || $captcha_site_key !== '' ) : ?>
            <p class="osm-wl-actions">
                <button type="submit" name="osm_waiting_list_submit" value="1" class="osm-wl-submit">Join the waiting list</button>
            </p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
