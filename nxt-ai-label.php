<?php
/**
 * Plugin Name: NXT AI Label
 * Plugin URI: https://nextab.de
 * Description: Burns official EU AI labels into selected media images, thumbnails and EWWW WebP files.
 * Version: 1.1.1
 * Author: nexTab
 * Author URI: https://nextab.de
 * Requires at least: 6.1
 * Requires PHP: 8.0
 * Text Domain: nxt-ai-label
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NXT_AI_LABEL_VERSION', '1.1.1');
define('NXT_AI_LABEL_DIR', plugin_dir_path(__FILE__));
define('NXT_AI_LABEL_URL', plugin_dir_url(__FILE__));
define('NXT_AI_LABEL_FILE', __FILE__);

require_once NXT_AI_LABEL_DIR . 'inc/class-labels.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-detector.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-meta.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-processor.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-auto.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-media-fields.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-admin.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-cover.php';
require_once NXT_AI_LABEL_DIR . 'inc/class-plugin.php';

NXT_AI_Label_Plugin::register();

/**
 * Apply the AI label to an attachment known to be AI-generated.
 *
 * @param array{slug?: string, position?: string, scale?: string} $args
 */
function nxt_ai_label_mark(int $attachment_id, array $args = []): bool {
	$settings = NXT_AI_Label_Auto::settings();
	$slug = isset($args['slug']) ? (string) $args['slug'] : $settings['slug'];
	$position = isset($args['position']) ? (string) $args['position'] : $settings['position'];
	$scale = isset($args['scale']) ? (string) $args['scale'] : $settings['scale'];
	$ok = NXT_AI_Label_Processor::mark($attachment_id, true, $slug, $position, $scale);
	if ($ok) {
		update_post_meta($attachment_id, '_nxt_ai_label_auto', '1');
	}

	return $ok;
}
