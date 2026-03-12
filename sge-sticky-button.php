<?php
/**
 * Plugin Name: SGE Sticky Button
 * Description: Adds a customizable sticky button to selected pages/posts with configurable text, URL, position, and style.
 * Version:     1.4.0
 * Author:      SGE
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGE_SB_VERSION', '1.4.0' );
define( 'SGE_SB_OPTION',  'sge_sticky_button_settings' );

// =============================================================================
// Hook registrations
// =============================================================================

add_action( 'init',           'sge_sb_register_meta' );
add_action( 'admin_menu',    'sge_sb_register_menu' );
add_action( 'admin_init',    'sge_sb_register_settings' );
add_action( 'add_meta_boxes', 'sge_sb_add_meta_box' );
add_action( 'save_post',     'sge_sb_save_meta_box' );
add_action( 'wp_footer',     'sge_sb_render_button' );

// =============================================================================
// 0. Meta field registration
// =============================================================================

/**
 * Registers the per-post meta fields for all public post types.
 *
 * Registering meta ensures:
 * - Consistent type coercion when reading/writing values.
 * - Correct behaviour in both the classic editor and the block editor (Gutenberg),
 *   where the primary save uses the REST API and $_POST is not available.
 */
function sge_sb_register_meta() {
	$post_types = get_post_types( array( 'public' => true ), 'names' );

	$meta_fields = array(
		'_sge_sb_enabled'     => array(
			'type'        => 'string',
			'description' => 'SGE Sticky Button enabled state for this post (1 = on, 0 = off, empty = follow global rules).',
			'default'     => '',
		),
		'_sge_sb_button_text' => array(
			'type'        => 'string',
			'description' => 'SGE Sticky Button text override for this post.',
			'default'     => '',
		),
		'_sge_sb_button_url'  => array(
			'type'        => 'string',
			'description' => 'SGE Sticky Button URL override for this post.',
			'default'     => '',
		),
	);

	$auth_callback = static function () {
		return current_user_can( 'edit_posts' );
	};

	foreach ( $post_types as $post_type ) {
		foreach ( $meta_fields as $meta_key => $args ) {
			register_post_meta(
				$post_type,
				$meta_key,
				array(
					'type'              => $args['type'],
					'description'       => $args['description'],
					'single'            => true,
					'default'           => $args['default'],
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth_callback,
				)
			);
		}
	}
}

// =============================================================================
// 1. Settings — defaults, retrieval, sanitization
// =============================================================================

/**
 * Returns the default values for all plugin settings.
 *
 * @return array
 */
function sge_sb_get_defaults() {
	return array(
		'button_text'   => 'Click Here',
		'button_url'    => '',
		'open_new_tab'  => 0,
		'position'      => 'bottom-right',
		'custom_bottom' => '30px',
		'custom_right'  => '',
		'custom_left'   => '',
		'bg_color'      => '#00CAF3',
		'text_color'    => '#00313E',
		'font_size'     => '16px',
		'line_height'   => '1.4',
		'display_on'    => 'all',
		'specific_ids'  => '',
	);
}

/**
 * Retrieves plugin settings merged with defaults.
 *
 * @return array
 */
function sge_sb_get_settings() {
	return wp_parse_args( get_option( SGE_SB_OPTION, array() ), sge_sb_get_defaults() );
}

/**
 * Sanitizes settings before saving.
 *
 * @param  array $input Raw POST values.
 * @return array        Sanitized values.
 */
function sge_sb_sanitize_settings( $input ) {
	$clean = array();

	// Content.
	$clean['button_text']  = sanitize_text_field( $input['button_text'] ?? '' );
	$clean['button_url']   = esc_url_raw( $input['button_url'] ?? '' );
	$clean['open_new_tab'] = ! empty( $input['open_new_tab'] ) ? 1 : 0;

	// Position.
	$allowed_positions   = array( 'bottom-left', 'bottom-right', 'custom' );
	$clean['position']   = in_array( $input['position'] ?? '', $allowed_positions, true )
		? $input['position']
		: 'bottom-right';
	$clean['custom_bottom'] = sanitize_text_field( $input['custom_bottom'] ?? '' );
	$clean['custom_right']  = sanitize_text_field( $input['custom_right'] ?? '' );
	$clean['custom_left']   = sanitize_text_field( $input['custom_left'] ?? '' );

	// Style.
	$clean['bg_color']    = sge_sb_sanitize_hex_color( $input['bg_color'] ?? '', '#00CAF3' );
	$clean['text_color']  = sge_sb_sanitize_hex_color( $input['text_color'] ?? '', '#00313E' );
	$clean['font_size']   = sanitize_text_field( $input['font_size'] ?? '' );
	$clean['line_height'] = sanitize_text_field( $input['line_height'] ?? '' );

	// Display rules.
	$clean['display_on']  = in_array( $input['display_on'] ?? '', array( 'all', 'specific' ), true )
		? $input['display_on']
		: 'all';

	$raw_ids             = $input['specific_ids'] ?? '';
	$ids                 = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
	$clean['specific_ids'] = implode( ',', $ids );

	return $clean;
}

/**
 * Sanitizes a hex color value.
 *
 * @param  string $value    The value to check.
 * @param  string $fallback Returned when $value is invalid.
 * @return string
 */
function sge_sb_sanitize_hex_color( $value, $fallback ) {
	$value = trim( $value );

	return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : $fallback;
}

/**
 * Validates a CSS value — length units (px, em, rem…) or unitless numbers.
 *
 * @param  string $value    The value to check.
 * @param  string $fallback Returned when $value is invalid.
 * @return string
 */
function sge_sb_sanitize_css_value( $value, $fallback ) {
	$value = trim( $value );

	if ( '' !== $value && preg_match( '/^-?[\d.]+(%|px|em|rem|vw|vh|pt|cm|mm)?$/', $value ) ) {
		return $value;
	}

	return $fallback;
}

// =============================================================================
// 2. Admin menu & settings page
// =============================================================================

/**
 * Registers the plugin settings page under Settings.
 */
function sge_sb_register_menu() {
	add_options_page(
		__( 'SGE Sticky Button', 'sge-sticky-button' ),
		__( 'SGE Sticky Button', 'sge-sticky-button' ),
		'manage_options',
		'sge-sticky-button',
		'sge_sb_render_settings_page'
	);
}

/**
 * Registers the settings group and sanitization callback.
 */
function sge_sb_register_settings() {
	register_setting( 'sge_sb_group', SGE_SB_OPTION, 'sge_sb_sanitize_settings' );
}

/**
 * Renders the plugin settings page.
 */
function sge_sb_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s   = sge_sb_get_settings();
	$opt = esc_attr( SGE_SB_OPTION );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SGE Sticky Button', 'sge-sticky-button' ); ?></h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'sge_sb_group' ); ?>

			<?php /* ── Content ─────────────────────────────────────────── */ ?>
			<h2 class="title"><?php esc_html_e( 'Content', 'sge-sticky-button' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row">
						<label for="sge_button_text"><?php esc_html_e( 'Button Text', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="text" id="sge_button_text"
							name="<?php echo $opt; ?>[button_text]"
							value="<?php echo esc_attr( $s['button_text'] ); ?>"
							class="regular-text">
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sge_button_url"><?php esc_html_e( 'Button URL', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="url" id="sge_button_url"
							name="<?php echo $opt; ?>[button_url]"
							value="<?php echo esc_attr( $s['button_url'] ); ?>"
							class="regular-text" placeholder="https://">
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Open in New Tab', 'sge-sticky-button' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo $opt; ?>[open_new_tab]"
								value="1"
								<?php checked( 1, $s['open_new_tab'] ); ?>>
							<?php esc_html_e( 'Yes', 'sge-sticky-button' ); ?>
						</label>
					</td>
				</tr>

			</table>

			<?php /* ── Style ────────────────────────────────────────────── */ ?>
			<h2 class="title"><?php esc_html_e( 'Style', 'sge-sticky-button' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row">
						<label for="sge_bg_color"><?php esc_html_e( 'Background Color', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="color" id="sge_bg_color"
							name="<?php echo $opt; ?>[bg_color]"
							value="<?php echo esc_attr( $s['bg_color'] ); ?>">
						<input type="text" id="sge_bg_color_hex"
							class="small-text sge-hex-input"
							value="<?php echo esc_attr( $s['bg_color'] ); ?>"
							data-picker="sge_bg_color" maxlength="7" placeholder="#00CAF3">
						<p class="description">
							<?php
							printf(
								/* translators: %s: hex color code */
								esc_html__( 'Default: %s', 'sge-sticky-button' ),
								'<code>#00CAF3</code>'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sge_text_color"><?php esc_html_e( 'Text Color', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="color" id="sge_text_color"
							name="<?php echo $opt; ?>[text_color]"
							value="<?php echo esc_attr( $s['text_color'] ); ?>">
						<input type="text" id="sge_text_color_hex"
							class="small-text sge-hex-input"
							value="<?php echo esc_attr( $s['text_color'] ); ?>"
							data-picker="sge_text_color" maxlength="7" placeholder="#00313E">
						<p class="description">
							<?php
							printf(
								/* translators: %s: hex color code */
								esc_html__( 'Default: %s', 'sge-sticky-button' ),
								'<code>#00313E</code>'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sge_font_size"><?php esc_html_e( 'Font Size', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="text" id="sge_font_size"
							name="<?php echo $opt; ?>[font_size]"
							value="<?php echo esc_attr( $s['font_size'] ); ?>"
							class="small-text" placeholder="16px">
						<p class="description"><?php esc_html_e( 'Accepts any CSS unit — px, em, rem, vw, etc.', 'sge-sticky-button' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sge_line_height"><?php esc_html_e( 'Line Height', 'sge-sticky-button' ); ?></label>
					</th>
					<td>
						<input type="text" id="sge_line_height"
							name="<?php echo $opt; ?>[line_height]"
							value="<?php echo esc_attr( $s['line_height'] ); ?>"
							class="small-text" placeholder="1.4">
						<p class="description">
							<?php
							printf(
								/* translators: 1: unitless example, 2: unit example */
								esc_html__( 'Unitless (e.g. %1$s) or with unit (e.g. %2$s).', 'sge-sticky-button' ),
								'<code>1.4</code>',
								'<code>24px</code>'
							);
							?>
						</p>
					</td>
				</tr>

			</table>

			<?php /* ── Position ─────────────────────────────────────────── */ ?>
			<h2 class="title"><?php esc_html_e( 'Position', 'sge-sticky-button' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Button Position', 'sge-sticky-button' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<?php esc_html_e( 'Button Position', 'sge-sticky-button' ); ?>
							</legend>

							<label>
								<input type="radio"
									name="<?php echo $opt; ?>[position]"
									value="bottom-right"
									class="sge-position-radio"
									<?php checked( 'bottom-right', $s['position'] ); ?>>
								<?php esc_html_e( 'Bottom Right', 'sge-sticky-button' ); ?>
							</label><br>

							<label>
								<input type="radio"
									name="<?php echo $opt; ?>[position]"
									value="bottom-left"
									class="sge-position-radio"
									<?php checked( 'bottom-left', $s['position'] ); ?>>
								<?php esc_html_e( 'Bottom Left', 'sge-sticky-button' ); ?>
							</label><br>

							<label>
								<input type="radio"
									name="<?php echo $opt; ?>[position]"
									value="custom"
									class="sge-position-radio"
									<?php checked( 'custom', $s['position'] ); ?>>
								<?php esc_html_e( 'Custom', 'sge-sticky-button' ); ?>
							</label>

							<div id="sge-custom-position"
								<?php echo 'custom' !== $s['position'] ? 'style="display:none;"' : ''; ?>>

								<br>
								<label>
									<?php esc_html_e( 'Bottom:', 'sge-sticky-button' ); ?>
									<input type="text"
										name="<?php echo $opt; ?>[custom_bottom]"
										value="<?php echo esc_attr( $s['custom_bottom'] ); ?>"
										class="small-text" placeholder="30px">
								</label>
								&nbsp;
								<label>
									<?php esc_html_e( 'Right:', 'sge-sticky-button' ); ?>
									<input type="text"
										name="<?php echo $opt; ?>[custom_right]"
										value="<?php echo esc_attr( $s['custom_right'] ); ?>"
										class="small-text" placeholder="30px">
								</label>
								&nbsp;
								<label>
									<?php esc_html_e( 'Left:', 'sge-sticky-button' ); ?>
									<input type="text"
										name="<?php echo $opt; ?>[custom_left]"
										value="<?php echo esc_attr( $s['custom_left'] ); ?>"
										class="small-text" placeholder="auto">
								</label>
								<p class="description">
									<?php
									printf(
										/* translators: %s: code example */
										esc_html__( 'Use CSS units (px, %%, em, etc). Leave Right or Left blank to use %s.', 'sge-sticky-button' ),
										'<code>auto</code>'
									);
									?>
								</p>
							</div>

						</fieldset>
					</td>
				</tr>

			</table>

			<?php /* ── Display Rules ─────────────────────────────────────── */ ?>
			<h2 class="title"><?php esc_html_e( 'Display Rules', 'sge-sticky-button' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Display On', 'sge-sticky-button' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<?php esc_html_e( 'Display On', 'sge-sticky-button' ); ?>
							</legend>

							<label>
								<input type="radio"
									name="<?php echo $opt; ?>[display_on]"
									value="all"
									class="sge-display-radio"
									<?php checked( 'all', $s['display_on'] ); ?>>
								<?php esc_html_e( 'All pages &amp; posts', 'sge-sticky-button' ); ?>
							</label><br>

							<label>
								<input type="radio"
									name="<?php echo $opt; ?>[display_on]"
									value="specific"
									class="sge-display-radio"
									<?php checked( 'specific', $s['display_on'] ); ?>>
								<?php esc_html_e( 'Specific pages / posts', 'sge-sticky-button' ); ?>
							</label>

							<div id="sge-specific-ids"
								<?php echo 'specific' !== $s['display_on'] ? 'style="display:none;"' : ''; ?>>

								<br>
								<input type="text"
									name="<?php echo $opt; ?>[specific_ids]"
									value="<?php echo esc_attr( $s['specific_ids'] ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'e.g. 12, 45, 100', 'sge-sticky-button' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Enter comma-separated page or post IDs.', 'sge-sticky-button' ); ?>
								</p>

								<?php sge_sb_render_id_lookup(); ?>

							</div>
						</fieldset>
					</td>
				</tr>

			</table>

			<?php submit_button(); ?>
		</form>
	</div>

	<script>
	( function () {
		// Sync color picker <-> hex text input.
		document.querySelectorAll( '.sge-hex-input' ).forEach( function ( hex ) {
			var picker = document.getElementById( hex.dataset.picker );
			if ( ! picker ) {
				return;
			}
			picker.addEventListener( 'input', function () {
				hex.value = this.value;
			} );
			hex.addEventListener( 'input', function () {
				if ( /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test( this.value ) ) {
					picker.value = this.value;
				}
			} );
		} );

		// Toggle custom position fields.
		document.querySelectorAll( '.sge-position-radio' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				document.getElementById( 'sge-custom-position' ).style.display =
					'custom' === this.value ? '' : 'none';
			} );
		} );

		// Toggle specific IDs field.
		document.querySelectorAll( '.sge-display-radio' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				document.getElementById( 'sge-specific-ids' ).style.display =
					'specific' === this.value ? '' : 'none';
			} );
		} );
	} )();
	</script>
	<?php
}

/**
 * Renders a collapsible reference table of all published pages and posts with their IDs.
 */
function sge_sb_render_id_lookup() {
	$items = get_posts(
		array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'post_type title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	if ( empty( $items ) ) {
		return;
	}
	?>
	<details style="margin-top:10px;">
		<summary style="cursor:pointer; color:#2271b1;">
			<?php esc_html_e( 'Browse pages &amp; posts to find IDs', 'sge-sticky-button' ); ?>
		</summary>
		<table class="widefat striped" style="margin-top:8px; max-width:500px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'sge-sticky-button' ); ?></th>
					<th><?php esc_html_e( 'Title', 'sge-sticky-button' ); ?></th>
					<th><?php esc_html_e( 'Type', 'sge-sticky-button' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><code><?php echo absint( $item->ID ); ?></code></td>
						<td><?php echo esc_html( $item->post_title ); ?></td>
						<td><?php echo esc_html( $item->post_type ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</details>
	<?php
}

// =============================================================================
// 3. Meta box — per-post enable & overrides
// =============================================================================

/**
 * Registers the "Sticky Button" meta box on all public post types.
 */
function sge_sb_add_meta_box() {
	$post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );

	add_meta_box(
		'sge_sticky_button',
		__( 'Sticky Button', 'sge-sticky-button' ),
		'sge_sb_render_meta_box',
		$post_types,
		'side',
		'default',
		array( '__block_editor_compatible_meta_box' => true )
	);
}

/**
 * Renders the meta box HTML.
 *
 * @param WP_Post $post The current post object.
 */
function sge_sb_render_meta_box( $post ) {
	wp_nonce_field( 'sge_sb_meta_save', 'sge_sb_meta_nonce' );

	$enabled       = get_post_meta( $post->ID, '_sge_sb_enabled',     true );
	$override_text = get_post_meta( $post->ID, '_sge_sb_button_text', true );
	$override_url  = get_post_meta( $post->ID, '_sge_sb_button_url',  true );

	$is_enabled = ( '1' === $enabled );
	$s          = sge_sb_get_settings();
	?>
	<p style="margin: 0 0 6px;">
		<label style="font-weight: 600; font-size: 13px;">
			<input type="checkbox"
				id="sge_sb_enabled"
				name="sge_sb_enabled"
				value="1"
				<?php checked( $is_enabled ); ?>>
			<?php esc_html_e( 'Enable Sticky Button', 'sge-sticky-button' ); ?>
		</label>
	</p>

	<p style="margin: 0 0 10px; color: #646970; font-size: 12px;">
		<?php esc_html_e( 'Checked: always show on this page, ignoring global display rules. Unchecked: always hide, ignoring global display rules. Remember to Update/Publish to save.', 'sge-sticky-button' ); ?>
	</p>

	<div id="sge_sb_override_fields"<?php echo ! $is_enabled ? ' style="display:none;"' : ''; ?>>

		<p style="margin-top: 0; color: #646970; font-style: italic; font-size: 12px;">
			<?php esc_html_e( 'Leave blank to use the global settings.', 'sge-sticky-button' ); ?>
		</p>

		<p style="margin-bottom: 8px;">
			<label for="sge_sb_override_text" style="font-weight: 600; display: block; margin-bottom: 4px;">
				<?php esc_html_e( 'Button Text', 'sge-sticky-button' ); ?>
			</label>
			<input type="text"
				id="sge_sb_override_text"
				name="sge_sb_override_text"
				value="<?php echo esc_attr( $override_text ); ?>"
				placeholder="<?php echo esc_attr( $s['button_text'] ); ?>"
				style="width: 100%;">
		</p>

		<p style="margin-bottom: 0;">
			<label for="sge_sb_override_url" style="font-weight: 600; display: block; margin-bottom: 4px;">
				<?php esc_html_e( 'Button URL', 'sge-sticky-button' ); ?>
			</label>
			<input type="url"
				id="sge_sb_override_url"
				name="sge_sb_override_url"
				value="<?php echo esc_attr( $override_url ); ?>"
				placeholder="<?php echo esc_attr( $s['button_url'] ? $s['button_url'] : 'https://' ); ?>"
				style="width: 100%;">
		</p>

	</div>

	<script>
	( function () {
		var checkbox = document.getElementById( 'sge_sb_enabled' );
		var fields   = document.getElementById( 'sge_sb_override_fields' );
		if ( checkbox && fields ) {
			checkbox.addEventListener( 'change', function () {
				fields.style.display = this.checked ? '' : 'none';
			} );
		}
	} )();
	</script>
	<?php
}

/**
 * Saves meta box values on post save.
 *
 * @param int $post_id The ID of the post being saved.
 */
function sge_sb_save_meta_box( $post_id ) {
	// Verify nonce.
	if ( ! isset( $_POST['sge_sb_meta_nonce'] ) ||
		! wp_verify_nonce( $_POST['sge_sb_meta_nonce'], 'sge_sb_meta_save' ) ) {
		return;
	}

	// Skip autosaves and revisions.
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$enabled = ! empty( $_POST['sge_sb_enabled'] ) ? '1' : '0';
	$text    = sanitize_text_field( $_POST['sge_sb_override_text'] ?? '' );
	$url     = esc_url_raw( $_POST['sge_sb_override_url'] ?? '' );

	update_post_meta( $post_id, '_sge_sb_enabled',     $enabled );
	update_post_meta( $post_id, '_sge_sb_button_text', $text );
	update_post_meta( $post_id, '_sge_sb_button_url',  $url );
}

// =============================================================================
// 4. Frontend — output the sticky button
// =============================================================================

/**
 * Outputs the sticky button in the footer if display conditions are met.
 */
function sge_sb_render_button() {
	if ( is_admin() ) {
		return;
	}

	$s       = sge_sb_get_settings();
	$post_id = get_queried_object_id();

	// Check whether the button is explicitly enabled on this post.
	$post_enabled = get_post_meta( $post_id, '_sge_sb_enabled', true );

	/*
	 * Display logic — three states:
	 *
	 * '1'  → Post-level force-ON.  Always render; bypass global display rules entirely.
	 * '0'  → Post-level force-OFF. Never render; overrides global display rules entirely.
	 * ''   → Not set (post never saved with meta box). Obey global display rules.
	 *
	 * This means the meta box checkbox is a true three-way override:
	 *   checked   → show regardless of admin settings
	 *   unchecked → hide regardless of admin settings (once the post is saved)
	 *   never saved → follow admin settings
	 */
	if ( '1' === $post_enabled ) {
		// Force-ON: bypass all global display rules — fall through to render.
	} elseif ( '0' === $post_enabled ) {
		// Force-OFF: explicitly disabled for this post — never render.
		return;
	} else {
		// Not set: apply global display rules.
		if ( 'specific' === $s['display_on'] ) {
			$allowed_ids = array_filter( array_map( 'absint', explode( ',', $s['specific_ids'] ) ) );
			if ( empty( $allowed_ids ) || ! in_array( $post_id, $allowed_ids, true ) ) {
				return;
			}
		}
	}

	// Resolve per-post overrides, falling back to global values.
	$override_text = get_post_meta( $post_id, '_sge_sb_button_text', true );
	$override_url  = get_post_meta( $post_id, '_sge_sb_button_url',  true );

	$button_text = ( '' !== $override_text ) ? $override_text : $s['button_text'];
	$button_url  = ( '' !== $override_url )  ? $override_url  : $s['button_url'];

	// Nothing to render without both values.
	if ( empty( $button_text ) || empty( $button_url ) ) {
		return;
	}

	$position        = $s['position'];
	$position_css    = sge_sb_get_position_css( $s );
	$border_radius   = sge_sb_get_border_radius( $position, $s );
	$hover_transform = sge_sb_get_hover_transform( $position, $s );

	$bg_color    = esc_attr( $s['bg_color'] );
	$text_color  = esc_attr( $s['text_color'] );
	$font_size   = esc_attr( sge_sb_sanitize_css_value( $s['font_size'],   '16px' ) );
	$line_height = esc_attr( sge_sb_sanitize_css_value( $s['line_height'], '1.4' ) );

	$target_attr = $s['open_new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '';
	?>
	<style>
	#sge-sticky-btn {
		position: fixed;
		z-index: 99999;
		<?php echo $position_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		display: inline-block;
		padding: 12px 28px;
		background-color: <?php echo $bg_color; ?>;
		color: <?php echo $text_color; ?>;
		font-family: inherit;
		font-size: <?php echo $font_size; ?>;
		font-weight: 600;
		line-height: <?php echo $line_height; ?>;
		text-decoration: none;
		white-space: nowrap;
		border-radius: <?php echo $border_radius; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> !important;
		transition: filter .2s ease, transform .2s ease;
	}
	#sge-sticky-btn:hover {
		filter: brightness( 0.9 );
		color: <?php echo $text_color; ?>;
		transform: <?php echo $hover_transform; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	}
	</style>
	<a id="sge-sticky-btn"
		href="<?php echo esc_url( $button_url ); ?>"
		<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php echo esc_html( $button_text ); ?>
	</a>

	<script>
	( function () {
		var btn = document.getElementById( 'sge-sticky-btn' );
		if ( ! btn ) {
			return;
		}

		var href = btn.getAttribute( 'href' );

		// Only intercept pure hash links (e.g. "#section-id").
		if ( ! href || '#' !== href.charAt( 0 ) || href.length < 2 ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			var target = document.getElementById( href.slice( 1 ) );
			if ( ! target ) {
				return;
			}

			e.preventDefault();

			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );

			// Update the URL hash without triggering a jump.
			if ( window.history && window.history.pushState ) {
				window.history.pushState( null, '', href );
			}
		} );
	} )();
	</script>
	<?php
}

/**
 * Builds the CSS position declarations for the button.
 *
 * @param  array $s Plugin settings.
 * @return string   CSS property declarations (no selector or braces).
 */
function sge_sb_get_position_css( $s ) {
	switch ( $s['position'] ) {
		case 'bottom-left':
			return "bottom: 30px;\nleft: 0;\nright: auto;";

		case 'custom':
			$bottom = sge_sb_sanitize_css_value( $s['custom_bottom'], '30px' );
			$right  = sge_sb_sanitize_css_value( $s['custom_right'],  '' );
			$left   = sge_sb_sanitize_css_value( $s['custom_left'],   '' );

			return implode(
				"\n",
				array(
					"bottom: {$bottom};",
					'right: ' . ( '' !== $right ? $right : 'auto' ) . ';',
					'left: '  . ( '' !== $left  ? $left  : 'auto' ) . ';',
				)
			);

		default: // bottom-right.
			return "bottom: 30px;\nright: 0;\nleft: auto;";
	}
}

/**
 * Returns the border-radius value based on button position.
 *
 * Preset positions produce an edge-anchored tab — pill-shaped on the inner
 * side and flat against the viewport edge. For custom positions, the edge
 * is inferred from the right/left values: if right is 0 the button is
 * anchored to the right edge; if left is 0 it is anchored to the left edge.
 *
 * @param  string $position One of 'bottom-right', 'bottom-left', or 'custom'.
 * @param  array  $s        Plugin settings (used to read custom_right / custom_left).
 * @return string           A CSS border-radius value.
 */
function sge_sb_get_border_radius( $position, $s = array() ) {
	switch ( $position ) {
		case 'bottom-right':
			return '50px 0 0 50px'; // Rounded inner (left), flat edge (right).

		case 'bottom-left':
			return '0 50px 50px 0'; // Flat edge (left), rounded inner (right).

		case 'custom':
			$right = sge_sb_sanitize_css_value( $s['custom_right'] ?? '', '' );
			$left  = sge_sb_sanitize_css_value( $s['custom_left']  ?? '', '' );

			// Anchored to the right edge (right is 0, left is unset).
			if ( '' === $left && in_array( $right, array( '0', '0px' ), true ) ) {
				return '50px 0 0 50px';
			}

			// Anchored to the left edge (left is 0, right is unset).
			if ( '' === $right && in_array( $left, array( '0', '0px' ), true ) ) {
				return '0 50px 50px 0';
			}

			return '50px'; // Floating — full pill.

		default:
			return '50px';
	}
}

/**
 * Returns the hover transform that pulls the button away from its anchored edge.
 *
 * For custom positions the edge is inferred the same way as border-radius.
 *
 * @param  string $position One of 'bottom-right', 'bottom-left', or 'custom'.
 * @param  array  $s        Plugin settings (used to read custom_right / custom_left).
 * @return string           A CSS transform value.
 */
function sge_sb_get_hover_transform( $position, $s = array() ) {
	switch ( $position ) {
		case 'bottom-right':
			return 'translateX(-4px)';

		case 'bottom-left':
			return 'translateX(4px)';

		case 'custom':
			$right = sge_sb_sanitize_css_value( $s['custom_right'] ?? '', '' );
			$left  = sge_sb_sanitize_css_value( $s['custom_left']  ?? '', '' );

			if ( '' === $left && in_array( $right, array( '0', '0px' ), true ) ) {
				return 'translateX(-4px)'; // Anchored right — slide left on hover.
			}

			if ( '' === $right && in_array( $left, array( '0', '0px' ), true ) ) {
				return 'translateX(4px)'; // Anchored left — slide right on hover.
			}

			return 'translateY(-3px)'; // Floating — lift on hover.

		default:
			return 'translateY(-3px)';
	}
}
