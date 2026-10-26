<?php
/**
 * Settings screen. Settings, then Skyphusion AI. The page slug stays cloudflare-ai-search.
 */

if (!defined('ABSPATH')) {
    exit;
}

function skyphusion_ai_defaults()
{
    return array(
        'endpoint' => '',
        'placement' => 'top',
        'search_bar' => true,
        'modal' => true,
        'chat' => false,
        'hide_checkout' => true,
        'placeholder' => 'Search',
        'color' => '#1d2327',
        'theme' => 'auto',
        'max_results' => 8,
        'hide_branding' => false,
        'account_id' => '',
        'gateway_id' => '',
        'lead_search' => false,
    );
}

function skyphusion_ai_settings()
{
    $stored = get_option('skyphusion_ai_settings', get_option( 'cloudflare_ai_search_settings', array() ));
    if (!is_array($stored)) {
        $stored = array();
    }

    $settings = array_merge(skyphusion_ai_defaults(), $stored);
    $settings['endpoint'] = skyphusion_ai_normalize_endpoint($settings['endpoint']);
    $settings['search_bar'] = (bool) $settings['search_bar'];
    $settings['modal'] = (bool) $settings['modal'];
    $settings['chat'] = (bool) $settings['chat'];
    $settings['hide_checkout'] = (bool) $settings['hide_checkout'];
    $settings['hide_branding'] = (bool) $settings['hide_branding'];
    $settings['lead_search'] = (bool) $settings['lead_search'];
    $settings['account_id'] = cloudflare_ai_provider_account_id($settings['account_id']);
    $settings['gateway_id'] = cloudflare_ai_provider_gateway_id($settings['gateway_id']);
    $settings['max_results'] = (int) $settings['max_results'];
    if ($settings['placement'] !== 'shortcode') {
        $settings['placement'] = 'top';
    }
    if (!in_array($settings['theme'], array('auto', 'light', 'dark'), true)) {
        $settings['theme'] = 'auto';
    }
    if ($settings['max_results'] < 1) {
        $settings['max_results'] = 1;
    }
    if ($settings['max_results'] > 20) {
        $settings['max_results'] = 20;
    }

    return $settings;
}

function skyphusion_ai_register_settings()
{
    register_setting(
        'cloudflare_ai_search',
        'skyphusion_ai_settings',
        array(
            'type' => 'array',
            'sanitize_callback' => 'skyphusion_ai_sanitize',
            'default' => skyphusion_ai_defaults(),
        )
    );

    add_settings_section(
        'connection',
        __('Connection', 'skyphusion-ai'),
        'skyphusion_ai_section_connection',
        'cloudflare-ai-search'
    );
    add_settings_field(
        'endpoint',
        __('AI Search address', 'skyphusion-ai'),
        'skyphusion_ai_field_endpoint',
        'cloudflare-ai-search',
        'connection'
    );

    add_settings_section(
        'workers',
        __('Workers AI and AI Gateway', 'skyphusion-ai'),
        'skyphusion_ai_section_workers',
        'cloudflare-ai-search'
    );
    add_settings_field('account_id', __('Account ID', 'skyphusion-ai'), 'skyphusion_ai_field_account_id', 'cloudflare-ai-search', 'workers');
    add_settings_field('gateway_id', __('AI Gateway', 'skyphusion-ai'), 'skyphusion_ai_field_gateway_id', 'cloudflare-ai-search', 'workers');

    add_settings_section(
        'display',
        __('What visitors see', 'skyphusion-ai'),
        'skyphusion_ai_section_display',
        'cloudflare-ai-search'
    );
    add_settings_field('search_bar', __('Search box', 'skyphusion-ai'), 'skyphusion_ai_field_search_bar', 'cloudflare-ai-search', 'display');
    add_settings_field('modal', __('Keyboard search', 'skyphusion-ai'), 'skyphusion_ai_field_modal', 'cloudflare-ai-search', 'display');
    add_settings_field('chat', __('Chat button', 'skyphusion-ai'), 'skyphusion_ai_field_chat', 'cloudflare-ai-search', 'display');
    add_settings_field('placement', __('Where the search box goes', 'skyphusion-ai'), 'skyphusion_ai_field_placement', 'cloudflare-ai-search', 'display');
    add_settings_field('hide_checkout', __('Cart and checkout', 'skyphusion-ai'), 'skyphusion_ai_field_hide_checkout', 'cloudflare-ai-search', 'display');
    add_settings_field('lead_search', __('Search results', 'skyphusion-ai'), 'skyphusion_ai_field_lead_search', 'cloudflare-ai-search', 'display');

    add_settings_section(
        'appearance',
        __('Look', 'skyphusion-ai'),
        'skyphusion_ai_section_appearance',
        'cloudflare-ai-search'
    );
    add_settings_field('placeholder', __('Placeholder', 'skyphusion-ai'), 'skyphusion_ai_field_placeholder', 'cloudflare-ai-search', 'appearance');
    add_settings_field('color', __('Accent color', 'skyphusion-ai'), 'skyphusion_ai_field_color', 'cloudflare-ai-search', 'appearance');
    add_settings_field('theme', __('Light or dark', 'skyphusion-ai'), 'skyphusion_ai_field_theme', 'cloudflare-ai-search', 'appearance');
    add_settings_field('max_results', __('How many results', 'skyphusion-ai'), 'skyphusion_ai_field_max_results', 'cloudflare-ai-search', 'appearance');
    add_settings_field('hide_branding', __('Cloudflare name', 'skyphusion-ai'), 'skyphusion_ai_field_hide_branding', 'cloudflare-ai-search', 'appearance');
}
add_action('admin_init', 'skyphusion_ai_register_settings');

/**
 * Tell the admin that a submitted value was rejected and what was kept.
 *
 * WHICH FALLBACKS GET ONE, stated so it can be argued with rather than
 * discovered. A notice is owed when the stored value is UNRELATED to what was
 * submitted, and not owed when the stored value is the NEAREST PERMITTED one.
 *
 *   reported   endpoint, account id, gateway id, colour, theme, placement.
 *              Each discards the submission and restores something else.
 *   silent     max_results clamped to 1..20, and placeholder truncated to 80
 *              characters. Each keeps the submission and moves it to the
 *              closest allowed value, so the direction of the intent survives.
 *   silent     an EMPTY field. Clearing a box is not a rejected value.
 *
 * That line is the difference between "we could not use what you typed" and
 * "we used as much of it as we could", and only the first is a surprise.
 *
 * @param string $code    Field slug, matching the input key.
 * @param string $message Already translated.
 */
function skyphusion_ai_reject($code, $message)
{
    if (function_exists('add_settings_error')) {
        add_settings_error('cloudflare_ai_search', $code, $message);
    }
}

function skyphusion_ai_sanitize($input)
{
    $old = skyphusion_ai_settings();
    $clean = skyphusion_ai_defaults();
    if (!is_array($input)) {
        return $old;
    }

    $typed = isset($input['endpoint']) ? trim((string) $input['endpoint']) : '';
    $endpoint = skyphusion_ai_normalize_endpoint($typed);
    if ($typed !== '' && $endpoint === '') {
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- names the required host. It does not load a file.
        skyphusion_ai_reject('endpoint', __('That address has to be an https link ending in search.ai.cloudflare.com. The previous address was kept.', 'skyphusion-ai'));
        $endpoint = $old['endpoint'];
    }
    $clean['endpoint'] = $endpoint;

    $clean['search_bar'] = !empty($input['search_bar']);
    $clean['modal'] = !empty($input['modal']);
    $clean['chat'] = !empty($input['chat']);
    $clean['hide_checkout'] = !empty($input['hide_checkout']);
    $clean['hide_branding'] = !empty($input['hide_branding']);
    $placement = isset($input['placement']) ? trim((string) $input['placement']) : '';
    if (in_array($placement, array('top', 'shortcode'), true)) {
        $clean['placement'] = $placement;
    } else {
        if ($placement !== '') {
            skyphusion_ai_reject('placement', __('Where the search box goes has to be top or shortcode. It was set to top.', 'skyphusion-ai'));
        }
        $clean['placement'] = 'top';
    }

    $theme = isset($input['theme']) ? trim((string) $input['theme']) : '';
    if (in_array($theme, array('auto', 'light', 'dark'), true)) {
        $clean['theme'] = $theme;
    } else {
        if ($theme !== '') {
            skyphusion_ai_reject('theme', __('Light or dark has to be auto, light, or dark. It was set to auto.', 'skyphusion-ai'));
        }
        $clean['theme'] = 'auto';
    }

    $placeholder = isset($input['placeholder']) ? sanitize_text_field((string) $input['placeholder']) : '';
    if ($placeholder === '') {
        $placeholder = 'Search';
    }
    if (function_exists('mb_substr')) {
        $placeholder = mb_substr($placeholder, 0, 80);
    } else {
        $placeholder = substr($placeholder, 0, 80);
    }
    $clean['placeholder'] = $placeholder;

    $typed_color = isset($input['color']) ? trim((string) $input['color']) : '';
    $color = sanitize_hex_color($typed_color);
    if ($typed_color !== '' && !$color) {
        skyphusion_ai_reject('color', __('Accent color has to be a hex color such as #1B3A32. The previous color was kept.', 'skyphusion-ai'));
    }
    $clean['color'] = $color ? $color : $old['color'];

    $max = isset($input['max_results']) ? (int) $input['max_results'] : 8;
    if ($max < 1) {
        $max = 1;
    }
    if ($max > 20) {
        $max = 20;
    }
    $clean['max_results'] = $max;

    $clean['lead_search'] = !empty($input['lead_search']);

    $typed_account = isset($input['account_id']) ? trim((string) $input['account_id']) : '';
    if ($typed_account === '') {
        $clean['account_id'] = '';
    } else {
        $account = cloudflare_ai_provider_account_id($typed_account);
        if ($account === '') {
            skyphusion_ai_reject('account_id', __('Account ID has to be 32 hex characters. The previous account ID was kept.', 'skyphusion-ai'));
            $clean['account_id'] = $old['account_id'];
        } else {
            $clean['account_id'] = $account;
        }
    }

    $typed_gateway = isset($input['gateway_id']) ? trim((string) $input['gateway_id']) : '';
    if ($typed_gateway === '') {
        $clean['gateway_id'] = '';
    } else {
        $gateway = cloudflare_ai_provider_gateway_id($typed_gateway);
        if ($gateway === '') {
            skyphusion_ai_reject('gateway_id', __('Gateway ID has to be a slug of letters, numbers, hyphens, or underscores. The previous gateway ID was kept.', 'skyphusion-ai'));
            $clean['gateway_id'] = $old['gateway_id'];
        } else {
            $clean['gateway_id'] = $gateway;
        }
    }

    return $clean;
}

function skyphusion_ai_section_connection()
{
    echo '<p>' . esc_html__('Paste the public AI Search address. The search box does not send an API token.', 'skyphusion-ai') . '</p>';
    echo '<ol>';
    echo '<li>' . esc_html__('In the Cloudflare dashboard, open AI Search and select the instance for this site.', 'skyphusion-ai') . '</li>';
    echo '<li>' . esc_html__('Open Settings, then Public endpoint, and turn the public endpoint on.', 'skyphusion-ai') . '</li>';
    echo '<li>' . wp_kses(__('Copy the address. It looks like <code>https://something.search.ai.cloudflare.com/</code>.', 'skyphusion-ai'), array('code' => array())) . '</li>'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- names the required host. It does not load a file.
    echo '</ol>';
}

/**
 * Which source is supplying the Workers AI token.
 *
 * Pure. It does not read the environment, a constant, or the database. The
 * caller passes the three values it already read. The WordPress-free harness
 * can decide the branch without a process, the same split includes/stock.php
 * uses for its builder.
 *
 * The return is a label key. It is never the value, and never anything
 * derived from the value. No prefix, no length, no mask. An input that is
 * not a non-empty string is absent, which is the same rule
 * cloudflare_ai_provider_connector_key() uses. Environment, then the
 * constant, then the Connectors option. This does not change that order.
 *
 *   env                     environment, option empty
 *   env_ignores_saved       environment, option also set
 *   constant                constant, option empty
 *   constant_ignores_saved  constant, option also set
 *   option                  the Connectors option
 *   none                    nothing is set
 *
 * @param mixed $env      getenv('CLOUDFLARE_API_KEY'), or false when unset.
 * @param mixed $constant The constant when it is defined, otherwise null.
 * @param mixed $stored   The Connectors option value.
 * @return string
 */
function skyphusion_ai_token_source( $env, $constant, $stored ) {
	$from_env      = is_string( $env ) && '' !== $env;
	$from_constant = is_string( $constant ) && '' !== $constant;
	$from_option   = is_string( $stored ) && '' !== $stored;
	if ( $from_env ) {
		return $from_option ? 'env_ignores_saved' : 'env';
	}
	if ( $from_constant ) {
		return $from_option ? 'constant_ignores_saved' : 'constant';
	}
	if ( $from_option ) {
		return 'option';
	}
	return 'none';
}

/**
 * Read the three candidates and return only the label key.
 *
 * The values do not leave this function. The screen prints a sentence for
 * the key, so the token never reaches an echo.
 *
 * @return string
 */
function skyphusion_ai_token_source_now() {
	$env      = getenv( 'CLOUDFLARE_API_KEY' );
	$constant = defined( 'CLOUDFLARE_API_KEY' ) ? constant( 'CLOUDFLARE_API_KEY' ) : null;
	$stored   = '';
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( 'connectors_ai_cloudflare_api_key', '' );
	}
	return skyphusion_ai_token_source( $env, $constant, $stored );
}

/**
 * The sentence for one label key.
 *
 * The argument is a key, not a token. An unknown key uses the same sentence
 * as no token, so the argument itself is never returned.
 *
 * @param string $key Label key from skyphusion_ai_token_source(). Never a token.
 * @return string Translated. The caller escapes it.
 */
function skyphusion_ai_token_source_notice( $key ) {
	switch ( $key ) {
		case 'env':
			return __( 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> environment variable.', 'skyphusion-ai' );
		case 'env_ignores_saved':
			return __( 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> environment variable. The token saved in Settings, then Connectors is not being used.', 'skyphusion-ai' );
		case 'constant':
			return __( 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> constant.', 'skyphusion-ai' );
		case 'constant_ignores_saved':
			return __( 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> constant. The token saved in Settings, then Connectors is not being used.', 'skyphusion-ai' );
		case 'option':
			return __( 'Workers AI is using the token from Settings, then Connectors.', 'skyphusion-ai' );
	}
	return __( 'No token is set.', 'skyphusion-ai' );
}

function skyphusion_ai_section_workers()
{
    $connectors = admin_url('options-connectors.php');
    echo '<p>' . sprintf(
        wp_kses(
            /* translators: %s is the URL of the WordPress Connectors settings screen. */
            __('WordPress and WooCommerce call Workers AI through the AI Client. The provider id is <code>cloudflare</code>. The plugin reads the API token from the <code>CLOUDFLARE_API_KEY</code> environment variable first. It then tries a PHP constant of the same name. It falls back to the token saved in <a href="%s">Settings, then Connectors</a>. A token in the environment or in a constant overrides the saved one. This screen does not store the token.', 'skyphusion-ai'),
            array('code' => array(), 'a' => array('href' => array()))
        ),
        esc_url($connectors)
    ) . '</p>';
    echo '<p>' . wp_kses(__('Leave the gateway blank to call Workers AI directly. Set a gateway id to send the same calls through AI Gateway. A model call does not replace another provider that is already configured. Call <code>using_provider(\'cloudflare\')</code> when the caller wants this one.', 'skyphusion-ai'), array('code' => array())) . '</p>';
	echo '<p>' . wp_kses(
		skyphusion_ai_token_source_notice( skyphusion_ai_token_source_now() ),
		array( 'code' => array() )
	) . '</p>';
}

function skyphusion_ai_section_display()
{
    echo '<p>' . esc_html__('Choose what shows up for visitors. Save, then look at the front of the site.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_section_appearance()
{
    echo '<p>' . esc_html__('These match the search box to the rest of the site.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_field_endpoint()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="url" class="regular-text code" name="skyphusion_ai_settings[endpoint]" value="%s" placeholder="https://example.search.ai.cloudflare.com/" />', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- names the required host. It does not load a file.
        esc_attr($settings['endpoint'])
    );
    echo '<p class="description">' . esc_html__('Leave this blank to hide search until you are ready.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_field_search_bar()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[search_bar]" value="1" ' . checked($settings['search_bar'], true, false) . ' /> Show a search box</label>';
    echo '<p class="description">' . esc_html__('Lists matching pages as someone types.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_field_modal()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[modal]" value="1" ' . checked($settings['modal'], true, false) . ' /> Open search when someone presses Ctrl+K or Command+K</label>';
}

function skyphusion_ai_field_chat()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[chat]" value="1" ' . checked($settings['chat'], true, false) . ' /> Show a chat button in the corner</label>';
    echo '<p class="description">' . esc_html__('The chat button answers in a sentence and uses more of the Cloudflare allowance. The search box only lists pages.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_field_placement()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="radio" name="skyphusion_ai_settings[placement]" value="top" ' . checked($settings['placement'], 'top', false) . ' /> Top of every page</label><br />';
    echo '<label><input type="radio" name="skyphusion_ai_settings[placement]" value="shortcode" ' . checked($settings['placement'], 'shortcode', false) . ' /> Only where I place the shortcode</label>';
    echo '<p class="description">' . wp_kses(__('Shortcode: <code>[cloudflare_ai_search]</code>', 'skyphusion-ai'), array('code' => array())) . '</p>';
}

function skyphusion_ai_field_hide_checkout()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[hide_checkout]" value="1" ' . checked($settings['hide_checkout'], true, false) . ' /> Hide the search box and the chat button on the cart and checkout</label>';
}

function skyphusion_ai_field_placeholder()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="text" class="regular-text" name="skyphusion_ai_settings[placeholder]" value="%s" maxlength="80" />',
        esc_attr($settings['placeholder'])
    );
}

function skyphusion_ai_field_color()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="text" class="cloudflare-ai-search-color" name="skyphusion_ai_settings[color]" value="%s" />',
        esc_attr($settings['color'])
    );
}

function skyphusion_ai_field_theme()
{
    $settings = skyphusion_ai_settings();
    echo '<select name="skyphusion_ai_settings[theme]">';
    $choices = array(
        'auto' => 'Match the visitor\'s device',
        'light' => 'Always light',
        'dark' => 'Always dark',
    );
    foreach ($choices as $value => $label) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($value),
            selected($settings['theme'], $value, false),
            esc_html($label)
        );
    }
    echo '</select>';
}

function skyphusion_ai_field_max_results()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="number" min="1" max="20" name="skyphusion_ai_settings[max_results]" value="%d" />',
        (int) $settings['max_results']
    );
}

function skyphusion_ai_field_hide_branding()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[hide_branding]" value="1" ' . checked($settings['hide_branding'], true, false) . ' /> Hide the Cloudflare name on the search box</label>';
}

function skyphusion_ai_field_account_id()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="text" class="regular-text code" name="skyphusion_ai_settings[account_id]" value="%s" maxlength="32" spellcheck="false" autocomplete="off" />',
        esc_attr($settings['account_id'])
    );
    echo '<p class="description">' . wp_kses(__('32 hex characters from the Cloudflare dashboard. You can also set <code>CLOUDFLARE_ACCOUNT_ID</code>, or put the account id in front of the token on Connectors as <code>account-id:api-token</code>. Only the token half is sent.', 'skyphusion-ai'), array('code' => array())) . '</p>';
}

function skyphusion_ai_field_gateway_id()
{
    $settings = skyphusion_ai_settings();
    printf(
        '<input type="text" class="regular-text code" name="skyphusion_ai_settings[gateway_id]" value="%s" maxlength="64" spellcheck="false" autocomplete="off" />',
        esc_attr($settings['gateway_id'])
    );
    echo '<p class="description">' . wp_kses(__('Optional. A slug such as <code>store</code>. Blank sends Workers AI calls to api.cloudflare.com.', 'skyphusion-ai'), array('code' => array())) . '</p>';
}

function skyphusion_ai_field_lead_search()
{
    $settings = skyphusion_ai_settings();
    echo '<label><input type="checkbox" name="skyphusion_ai_settings[lead_search]" value="1" ' . checked($settings['lead_search'], true, false) . ' /> Show an AI Search answer above WordPress and WooCommerce search results</label>';
    echo '<p class="description">' . esc_html__('Off until you turn it on. A failed request stays off the page. The cart and checkout stay quiet when that option is on.', 'skyphusion-ai') . '</p>';
}

function skyphusion_ai_add_menu()
{
    add_options_page(
        'Skyphusion AI for Cloudflare',
        'Skyphusion AI',
        'manage_options',
        'cloudflare-ai-search',
        'skyphusion_ai_render_page'
    );
}
add_action('admin_menu', 'skyphusion_ai_add_menu');

function skyphusion_ai_action_links($links)
{
    $url = admin_url('options-general.php?page=cloudflare-ai-search');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'skyphusion-ai') . '</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(SKYPHUSION_AI_FILE), 'skyphusion_ai_action_links');

function skyphusion_ai_admin_assets($hook)
{
    if ($hook !== 'settings_page_cloudflare-ai-search') {
        return;
    }
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_add_inline_script(
        'wp-color-picker',
        "jQuery(function($){ $('.cloudflare-ai-search-color').wpColorPicker(); });"
    );
}
add_action('admin_enqueue_scripts', 'skyphusion_ai_admin_assets');

function skyphusion_ai_admin_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = skyphusion_ai_settings();
    if ($settings['endpoint'] !== '') {
        return;
    }
    if (!$settings['search_bar'] && !$settings['modal'] && !$settings['chat']) {
        return;
    }
    $url = admin_url('options-general.php?page=cloudflare-ai-search');
    echo '<div class="notice notice-warning"><p>' . sprintf(
        wp_kses(
            /* translators: %s is the URL of this plugin's settings screen. */
            __('Skyphusion AI is not connected yet. <a href="%s">Add the AI Search address</a>.', 'skyphusion-ai'),
            array('a' => array('href' => array()))
        ),
        esc_url($url)
    ) . '</p></div>';
}
add_action('admin_notices', 'skyphusion_ai_admin_notice');

/**
 * One plain line of search counts for the settings screen.
 *
 * Each sentence is one count. The numbers are the open 24 hour window.
 *
 * @return string Plain text. The caller escapes it.
 */
function skyphusion_ai_search_outcome_summary() {
	$counts  = skyphusion_ai_search_outcomes();
	$ai      = (int) $counts['ai'];
	$errors  = (int) $counts['provider_error'];
	$missing = (int) $counts['no_endpoint'];
	$limited = (int) $counts['rate_limited'];

	$sentences   = array();
	$sentences[] = __( 'This count lasts 24 hours.', 'skyphusion-ai' );

	/* translators: %d is the number of searches AI Search answered. */
	$ai_text     = _n(
		'AI Search answered %d search.',
		'AI Search answered %d searches.',
		$ai,
		'skyphusion-ai'
	);
	$sentences[] = sprintf( $ai_text, $ai );

	/* translators: %d is the number of searches a provider error sent to site results. */
	$error_text  = _n(
		'A provider error served %d search from the site results.',
		'A provider error served %d searches from the site results.',
		$errors,
		'skyphusion-ai'
	);
	$sentences[] = sprintf( $error_text, $errors );

	/* translators: %d is the number of searches served because the AI Search address is missing. */
	$missing_text = _n(
		'A missing address served %d search from the site results.',
		'A missing address served %d searches from the site results.',
		$missing,
		'skyphusion-ai'
	);
	$sentences[]  = sprintf( $missing_text, $missing );

	/* translators: %d is the number of searches the plugin call ceiling sent to site results. */
	$limit_text  = _n(
		'The safety limit served %d search from the site results.',
		'The safety limit served %d searches from the site results.',
		$limited,
		'skyphusion-ai'
	);
	$sentences[] = sprintf( $limit_text, $limited );

	return implode( ' ', $sentences );
}

/**
 * Print the search counts under the settings intro.
 *
 * @return void
 */
function skyphusion_ai_render_search_outcomes() {
	echo '<p>' . esc_html( skyphusion_ai_search_outcome_summary() ) . '</p>';
}

/**
 * Warn when fallback is at least half of a busy window.
 *
 * Separate from the "not connected yet" notice. rate_limited is not part
 * of the half. The notice does not name the token.
 *
 * @return void
 */
function skyphusion_ai_search_outcome_notice() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = skyphusion_ai_settings();
	if ( empty( $settings['lead_search'] ) ) {
		return;
	}
	if ( ! skyphusion_ai_search_outcome_sustained( skyphusion_ai_search_outcomes() ) ) {
		return;
	}
	if ( ! function_exists( 'admin_url' ) ) {
		return;
	}
	$url = admin_url( 'options-general.php?page=cloudflare-ai-search' );
	/* translators: %s is the URL of this plugin's settings screen. */
	$message = __( 'Most searches fell back from AI Search to the site results. <a href="%s">Check the AI Search settings</a>.', 'skyphusion-ai' );
	echo '<div class="notice notice-warning"><p>' . wp_kses(
		sprintf( $message, esc_url( $url ) ),
		array(
			'a' => array(
				'href' => array(),
			),
		)
	) . '</p></div>';
}
add_action( 'admin_notices', 'skyphusion_ai_search_outcome_notice' );

function skyphusion_ai_render_product_links()
{
    $settings = skyphusion_ai_settings();
    $links = cloudflare_ai_provider_links($settings['account_id']);
    $products = array(
        array('Workers AI', $links['workers_ai'], $links['workers_ai_docs']),
        array('AI Gateway', $links['gateway'], $links['gateway_docs']),
        array('AI Search', $links['search'], $links['search_docs']),
    );
    echo '<ul>';
    foreach ($products as $product) {
        echo '<li><a href="' . esc_url($product[1]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($product[0]) . '</a>';
        echo ' (<a href="' . esc_url($product[2]) . '" target="_blank" rel="noopener noreferrer">docs</a>)</li>';
    }
    echo '</ul>';
    echo '<p>Gateway calls to Workers AI are documented at <a href="' . esc_url($links['gateway_workers_docs']) . '" target="_blank" rel="noopener noreferrer">AI Gateway for Workers AI</a>.</p>';
}

function skyphusion_ai_render_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $user_id = get_current_user_id();
    $check_key = 'skyphusion_ai_check_' . $user_id;
    $check = get_transient($check_key);
    if (is_array($check)) {
        delete_transient($check_key);
    }

    echo '<div class="wrap">';
    echo '<h1>Skyphusion AI for Cloudflare</h1>';
    echo '<p>Workers AI, AI Gateway, and AI Search. Visitors can still search this site in their own words. WordPress and WooCommerce can call the same Cloudflare account for text, images, speech, and embeddings.</p>';
	skyphusion_ai_render_search_outcomes();
    skyphusion_ai_render_product_links();

    if (is_array($check)) {
        $class = !empty($check['ok']) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html((string) $check['message']) . '</p></div>';
    }
    settings_errors('cloudflare_ai_search');

    echo '<form method="post" action="options.php">';
    settings_fields('cloudflare_ai_search');
    do_settings_sections('cloudflare-ai-search');
    submit_button('Save changes');
    echo '</form>';

    echo '<hr />';
    echo '<h2>Check the connection</h2>';
    echo '<p>Save first. This asks Cloudflare one question and tells you whether the address answers. An empty answer still counts as connected while Cloudflare is still reading the site.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('skyphusion_ai_test');
    echo '<input type="hidden" name="action" value="skyphusion_ai_test" />';
    submit_button('Check connection', 'secondary', 'submit', false);
    echo '</form>';
	if ( function_exists( 'skyphusion_ai_defaults_render' ) ) {
		skyphusion_ai_defaults_render();
	}
	if ( ! function_exists( 'skyphusion_ai_embed_render' ) ) {
		require_once __DIR__ . '/embed.php';
	}
	skyphusion_ai_embed_render();
	if ( ! function_exists( 'skyphusion_ai_insight_render' ) ) {
		require_once __DIR__ . '/insights.php';
	}
	skyphusion_ai_insight_render();
	if ( ! function_exists( 'skyphusion_ai_assistant_render' ) ) {
		require_once __DIR__ . '/assistant.php';
	}
	skyphusion_ai_assistant_render();
	if ( ! function_exists( 'skyphusion_ai_member_render' ) ) {
		require_once __DIR__ . '/member.php';
	}
	skyphusion_ai_member_render();
	if ( ! function_exists( 'skyphusion_ai_worker_render' ) ) {
		require_once __DIR__ . '/worker.php';
	}
	skyphusion_ai_worker_render();
	if ( ! function_exists( 'skyphusion_ai_backfill_render' ) ) {
		require_once __DIR__ . '/backfill.php';
	}
	skyphusion_ai_backfill_render();
	if ( ! function_exists( 'skyphusion_ai_prompt_render' ) ) {
		require_once __DIR__ . '/prompts.php';
	}
	skyphusion_ai_prompt_render();
    echo '</div>';
}

function skyphusion_ai_handle_test()
{
    if (!current_user_can('manage_options')) {
        wp_die('You cannot check this connection.');
    }
    check_admin_referer('skyphusion_ai_test');

    $settings = skyphusion_ai_settings();
    $endpoint = $settings['endpoint'];
    $message = 'Add a Cloudflare address and save it before checking the connection.';
    $ok = false;

    if ($endpoint !== '') {
        $response = wp_remote_post(
            $endpoint . '/search',
            array(
                'timeout' => 20,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode(array(
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => 'What is this site?',
                        ),
                    ),
                )),
            )
        );
        if (is_wp_error($response)) {
            $message = 'The site could not reach Cloudflare. Try again in a minute.';
        } else {
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status === 200) {
                $ok = true;
                $message = 'Connected. The search service answered. If a search on the site is empty, Cloudflare may still be reading the pages.';
            } else {
                $message = 'Cloudflare answered HTTP ' . $status . '. Confirm the public endpoint is turned on, then paste the address again.';
            }
        }
    }

    set_transient(
        'skyphusion_ai_check_' . get_current_user_id(),
        array(
            'ok' => $ok,
            'message' => $message,
        ),
        60
    );

    wp_safe_redirect(admin_url('options-general.php?page=cloudflare-ai-search'));
    exit;
}
add_action('admin_post_skyphusion_ai_test', 'skyphusion_ai_handle_test');
