<?php
/**
 * Settings screen layout. Issue #184.
 */

$admin_screen_root = dirname(__DIR__);
$admin_screen_php = (string) file_get_contents($admin_screen_root . '/includes/admin-screen.php');
$admin_screen_css = (string) file_get_contents($admin_screen_root . '/assets/admin.css');
$admin_screen_menu = (string) file_get_contents($admin_screen_root . '/includes/settings.php');
$admin_screen_boot = (string) file_get_contents($admin_screen_root . '/skyphusion-ai.php');

expect('the menu uses the grouped screen', strpos($admin_screen_menu, "'skyphusion_ai_admin_screen'") !== false, true);
expect('the plugin loads the screen file', strpos($admin_screen_boot, '/includes/admin-screen.php') !== false, true);
expect('the screen groups the connection form', strpos($admin_screen_php, 'skyphusion-ai-connection') !== false, true);
expect('the screen groups the assistant', strpos($admin_screen_php, 'skyphusion_ai_assistant_render') !== false, true);
expect('the screen still loads embeddings', strpos($admin_screen_php, "/embed.php") !== false, true);
expect('the stylesheet names the screen', strpos($admin_screen_css, '.skyphusion-ai-screen') !== false, true);
expect('the stylesheet names a panel', strpos($admin_screen_css, '.skyphusion-ai-panel') !== false, true);
