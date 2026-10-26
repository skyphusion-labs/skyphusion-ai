<?php
/**
 * Read-only search ability. Issue #173.
 *
 * The ability asks the public AI Search. It sends no token.
 */

require_once dirname(__DIR__) . '/includes/search-ability.php';

$search_ability_root = dirname(__DIR__);
$search_ability_doc = (string) file_get_contents($search_ability_root . '/docs/search-ability.md');
$search_ability_readme = (string) file_get_contents($search_ability_root . '/README.md');
$search_ability_readme_txt = (string) file_get_contents($search_ability_root . '/readme.txt');
$search_ability_plugin = (string) file_get_contents($search_ability_root . '/skyphusion-ai.php');
$search_ability_php = (string) file_get_contents($search_ability_root . '/includes/search-ability.php');

expect('the ability id is skyphusion-ai/search', skyphusion_ai_search_ability_id(), 'skyphusion-ai/search');
expect('the category id is skyphusion-ai', skyphusion_ai_search_ability_category(), 'skyphusion-ai');

$search_ability_args = skyphusion_ai_search_ability_args();
expect('the ability is read-only', $search_ability_args['meta']['annotations']['readonly'], true);
expect('the ability is not destructive', $search_ability_args['meta']['annotations']['destructive'], false);
expect('the MCP adapter may list the ability', $search_ability_args['meta']['mcp']['public'], true);
expect('the MCP type is a tool', $search_ability_args['meta']['mcp']['type'], 'tool');
expect('the input requires query', $search_ability_args['input_schema']['required'], array('query'));
expect('the output requires answer and sources', $search_ability_args['output_schema']['required'], array('answer', 'sources'));
expect('the execute callback is the search runner', $search_ability_args['execute_callback'], 'skyphusion_ai_search_ability_run');
expect('the permission callback checks read', $search_ability_args['permission_callback'], 'skyphusion_ai_search_ability_allowed');

$search_ability_out = skyphusion_ai_search_ability_output(
	array(
		'answer' => 'A blue cap is in stock.',
		'sources' => array('https://ggc.sh/product/cap/'),
		'token' => 'hidden',
	)
);
expect('the output keeps the answer', $search_ability_out['answer'], 'A blue cap is in stock.');
expect('the output keeps the sources', $search_ability_out['sources'], array('https://ggc.sh/product/cap/'));
expect('the output drops other fields', isset($search_ability_out['token']), false);
expect('a missing result is an empty answer', skyphusion_ai_search_ability_output(null), array('answer' => '', 'sources' => array()));
expect('a user check is refused when WordPress is absent', skyphusion_ai_search_ability_allowed(), false);

expect('the plugin loads the ability file', strpos($search_ability_plugin, '/includes/search-ability.php') !== false, true);
expect('the ability hooks the WordPress abilities API', strpos($search_ability_php, 'wp_abilities_api_init') !== false, true);
expect('the ability file does not read the API token constant', strpos($search_ability_php, 'CLOUDFLARE_API_KEY') !== false, false);
expect('the doc names the ability id', strpos($search_ability_doc, 'skyphusion-ai/search') !== false, true);
expect('the doc names the query field', strpos($search_ability_doc, 'query') !== false, true);
expect('the doc says the call sends no token', strpos($search_ability_doc, 'The call sends no token.') !== false, true);
expect('the README points at the ability doc', strpos($search_ability_readme, 'docs/search-ability.md') !== false, true);
expect('the readme txt points at the ability doc', strpos($search_ability_readme_txt, 'docs/search-ability.md') !== false, true);
