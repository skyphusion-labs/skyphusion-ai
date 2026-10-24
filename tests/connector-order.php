<?php
/**
 * The CONSTANT beats the saved option for the Connectors key.
 * Run as a subprocess by tests/provider-guard-test.php.
 *
 * A SUBPROCESS BECAUSE A CONSTANT CANNOT BE UNDEFINED. The in-process test can
 * only reach the environment branch; ranking the constant against the option
 * needs a process where the constant is defined and the environment is not,
 * and nothing in the parent run may define it or every later assertion about
 * the environment branch changes meaning. tests/account-order.php is the same
 * shape for the account-id ordering and this file is modelled on it.
 *
 * ABSPATH is defined here so includes/provider.php can keep its direct-access
 * guard. This is not a WordPress bootstrap.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/*
 * provider.php calls add_action at the top level, so the stand-in has to exist
 * BEFORE the require rather than after it.
 */
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        // Only the key the two token functions read. Anything else gets the
        // default, so an unrelated read cannot make this case pass.
        return $name === 'connectors_ai_cloudflare_api_key' ? 'option-value-loses' : $default;
    }
}

require $argv[1];

// The environment is cleared rather than assumed empty: an ambient
// CLOUDFLARE_API_KEY on the machine would otherwise win and this case would
// report the environment value while claiming to test the constant.
putenv('CLOUDFLARE_API_KEY');
define('CLOUDFLARE_API_KEY', 'constant-value-wins');

echo cloudflare_ai_provider_connector_key();
