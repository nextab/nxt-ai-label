<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Media_Fields {
	public static function register(): void {
		add_filter('attachment_fields_to_edit', [self::class, 'fields'], 10, 2);
		add_filter('wp_prepare_attachment_for_js', [self::class, 'prepare_js'], 10, 2);
		add_filter('manage_media_columns', [self::class, 'column']);
		add_action('manage_media_custom_column', [self::class, 'column_content'], 10, 2);
		add_action('wp_enqueue_media', [self::class, 'enqueue']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_attachment_edit']);
		add_action('wp_ajax_nxt_ai_label_apply', [self::class, 'ajax_apply']);
		add_action('wp_ajax_nxt_ai_label_detect', [self::class, 'ajax_detect']);
	}

	public static function enqueue_attachment_edit(string $hook): void {
		if ($hook !== 'post.php') {
			return;
		}

		$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
		if ($post_id <= 0 || get_post_type($post_id) !== 'attachment') {
			return;
		}

		self::enqueue();
	}

	public static function enqueue(): void {
		wp_enqueue_style(
			'nxt-ai-label-admin',
			NXT_AI_LABEL_URL . 'assets/admin.css',
			[],
			NXT_AI_LABEL_VERSION
		);
		wp_enqueue_script(
			'nxt-ai-label-admin',
			NXT_AI_LABEL_URL . 'assets/admin.js',
			[],
			NXT_AI_LABEL_VERSION,
			true
		);

		$labels = [];
		foreach (NXT_AI_Label_Labels::all() as $slug => $item) {
			[$width, $height] = NXT_AI_Label_Labels::pixel_size($slug);
			$labels[$slug] = [
				'url' => NXT_AI_LABEL_URL . 'assets/labels/' . $item['file'],
				'width' => $width,
				'height' => $height,
			];
		}

		$heights = [];
		foreach (NXT_AI_Label_Labels::scales() as $key => $item) {
			$heights[$key] = $item['height'];
		}

		wp_localize_script('nxt-ai-label-admin', 'nxtAiLabel', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('nxt_ai_label_apply'),
			'labels' => $labels,
			'heights' => $heights,
			'i18n' => [
				'set' => __('Set label', 'nxt-ai-label'),
				'update' => __('Update label', 'nxt-ai-label'),
				'removing' => __('Removing label', 'nxt-ai-label'),
				'writing' => __('Writing label', 'nxt-ai-label'),
				'failed' => __('Failed.', 'nxt-ai-label'),
				'requestFailed' => __('Request failed.', 'nxt-ai-label'),
				'flagSet' => __('Set', 'nxt-ai-label'),
				'flagAi' => __('AI?', 'nxt-ai-label'),
			],
		]);
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<string, mixed>
	 */
	public static function prepare_js(array $response, WP_Post $attachment): array {
		if (!wp_attachment_is_image($attachment)) {
			return $response;
		}

		$decision = (string) get_post_meta($attachment->ID, '_nxt_ai_label_enabled', true);
		$detected = (string) get_post_meta($attachment->ID, NXT_AI_Label_Auto::DETECTED, true);
		if ($decision === '0' || !in_array($detected, ['generated', 'modified'], true)) {
			$detected = '';
		}

		$response['nxtAiLabel'] = [
			'enabled' => $decision === '1',
			'detected' => $detected,
		];

		return $response;
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function column(array $columns): array {
		$columns['nxt_ai_label'] = __('AI label', 'nxt-ai-label');
		return $columns;
	}

	public static function column_content(string $column, int $post_id): void {
		if ($column !== 'nxt_ai_label' || !wp_attachment_is_image($post_id)) {
			return;
		}

		$view = self::presentation($post_id);
		if ($view['state'] === 'set') {
			echo '<span class="nxt-ai-label-col nxt-ai-label-col--set">' . esc_html__('Set', 'nxt-ai-label') . '</span>';
			return;
		}
		if ($view['state'] === 'suggest') {
			$label = $view['detected'] === 'modified'
				? __('Edited with AI', 'nxt-ai-label')
				: __('AI-generated', 'nxt-ai-label');
			echo '<span class="nxt-ai-label-col nxt-ai-label-col--suggest">' . esc_html($label) . '</span>';
			return;
		}

		echo '<span class="screen-reader-text">' . esc_html__('Not set', 'nxt-ai-label') . '</span>';
	}

	/**
	 * @return array{state: string, detected: string, text: string}
	 */
	public static function presentation(int $attachment_id, bool $confirm_file = false): array {
		$decision = (string) get_post_meta($attachment_id, '_nxt_ai_label_enabled', true);
		$detected_raw = (string) get_post_meta($attachment_id, NXT_AI_Label_Auto::DETECTED, true);
		$detected = in_array($detected_raw, ['generated', 'modified'], true) ? $detected_raw : '';
		$choice = self::choice($attachment_id);

		if ($decision === '1') {
			$name = NXT_AI_Label_Labels::name($choice['slug']);
			if ($confirm_file && !self::file_has_source($attachment_id)) {
				$text = sprintf(
					/* translators: %s: label name */
					__('Label is set: %s. You do not need to set it again. Regenerate it once so the file metadata is written.', 'nxt-ai-label'),
					$name
				);
			} else {
				$text = sprintf(
					/* translators: 1: label name, 2: metadata sentence written into the file */
					__('Label is set: %1$s. You do not need to set it again. File metadata: %2$s', 'nxt-ai-label'),
					$name,
					NXT_AI_Label_Meta::hint($choice['slug'])
				);
			}

			return [
				'state' => 'set',
				'detected' => $detected,
				'text' => $text,
			];
		}
		if ($decision === '0') {
			return [
				'state' => 'off',
				'detected' => '',
				'text' => __('No label. You removed it, so it stays off.', 'nxt-ai-label'),
			];
		}
		if ($detected === 'generated') {
			return [
				'state' => 'suggest',
				'detected' => 'generated',
				'text' => __('This image is AI-generated. Set the label now?', 'nxt-ai-label'),
			];
		}
		if ($detected === 'modified') {
			return [
				'state' => 'suggest',
				'detected' => 'modified',
				'text' => __('This image was edited with AI. Set the label now?', 'nxt-ai-label'),
			];
		}

		return [
			'state' => 'none',
			'detected' => '',
			'text' => __('No label is set.', 'nxt-ai-label'),
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $form_fields
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(array $form_fields, WP_Post $post): array {
		if (!wp_attachment_is_image($post)) {
			return $form_fields;
		}

		$view = self::presentation($post->ID, true);
		$choice = self::choice($post->ID);
		$raw_detected = (string) get_post_meta($post->ID, NXT_AI_Label_Auto::DETECTED, true);
		$detected_attr = in_array($raw_detected, ['0', 'generated', 'modified'], true) ? $raw_detected : '';
		$meta = wp_get_attachment_metadata($post->ID);
		$full_w = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
		$full_h = is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;

		$label_options = self::grouped_options($choice['slug']);
		$position_options = '';
		foreach (NXT_AI_Label_Labels::positions() as $key => $label) {
			$position_options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr($key),
				selected($choice['position'], $key, false),
				esc_html($label)
			);
		}

		$scale_options = '';
		foreach (NXT_AI_Label_Labels::scales() as $key => $item) {
			$scale_options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr($key),
				selected($choice['scale'], $key, false),
				esc_html(sprintf(
					/* translators: 1: size name, 2: height in pixels */
					__('%1$s (%2$d px)', 'nxt-ai-label'),
					$item['label'],
					(int) $item['height']
				))
			);
		}

		$apply_label = $view['state'] === 'set'
			? __('Update label', 'nxt-ai-label')
			: __('Set label', 'nxt-ai-label');

		$html = '<div class="nxt-ai-label"'
			. ' data-id="' . esc_attr((string) $post->ID) . '"'
			. ' data-state="' . esc_attr($view['state']) . '"'
			. ' data-enabled="' . ($view['state'] === 'set' ? '1' : '0') . '"'
			. ' data-detected="' . esc_attr($detected_attr) . '"'
			. ' data-preview="0"'
			. ' data-full-width="' . esc_attr((string) $full_w) . '"'
			. ' data-full-height="' . esc_attr((string) $full_h) . '"'
			. '>';
		$html .= '<p class="nxt-ai-label__status nxt-ai-label__status--' . esc_attr($view['state']) . '">' . esc_html($view['text']) . '</p>';
		if ($view['state'] === 'none' || $view['state'] === 'suggest') {
			$html .= '<p class="nxt-ai-label__hint">' . esc_html__('The dropdowns are your default. Nothing is written until you set the label.', 'nxt-ai-label') . '</p>';
		}
		$html .= '<p class="nxt-ai-label__preview" hidden>' . esc_html__('Preview only. Not saved on the image yet.', 'nxt-ai-label') . '</p>';
		$html .= '<label class="nxt-ai-label__field">' . esc_html__('Label', 'nxt-ai-label') . '<select data-field="slug">' . $label_options . '</select></label>';
		$html .= '<label class="nxt-ai-label__field">' . esc_html__('Position', 'nxt-ai-label') . '<select data-field="position">' . $position_options . '</select></label>';
		$html .= '<label class="nxt-ai-label__field">' . esc_html__('Size', 'nxt-ai-label') . '<select data-field="scale">' . $scale_options . '</select></label>';
		$html .= '<p class="nxt-ai-label__actions">'
			. '<button type="button" class="button button-primary" data-action="apply">' . esc_html($apply_label) . '</button>'
			. '<button type="button" class="button" data-action="remove">' . esc_html__('Remove label', 'nxt-ai-label') . '</button>'
			. '</p>';
		$html .= '<p class="nxt-ai-label__msg" aria-live="polite"></p>';
		$html .= '</div>';

		$form_fields['nxt_ai_label'] = [
			'label' => __('AI label', 'nxt-ai-label'),
			'input' => 'html',
			'html' => $html,
		];

		return $form_fields;
	}

	public static function ajax_apply(): void {
		check_ajax_referer('nxt_ai_label_apply', 'nonce');

		$attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
		if ($attachment_id <= 0 || !current_user_can('edit_post', $attachment_id) || !wp_attachment_is_image($attachment_id)) {
			wp_send_json_error(['message' => __('You cannot edit this image.', 'nxt-ai-label')], 403);
		}

		$defaults = NXT_AI_Label_Auto::settings();
		$mode = isset($_POST['mode']) ? sanitize_key((string) $_POST['mode']) : 'apply';
		$slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash((string) $_POST['slug'])) : $defaults['slug'];
		$position = isset($_POST['position']) ? sanitize_key((string) $_POST['position']) : $defaults['position'];
		$scale = isset($_POST['scale']) ? sanitize_key((string) $_POST['scale']) : $defaults['scale'];
		$enabled = $mode !== 'remove';

		if (!NXT_AI_Label_Processor::mark($attachment_id, $enabled, $slug, $position, $scale)) {
			wp_send_json_error(['message' => __('Could not write the label.', 'nxt-ai-label')], 500);
		}

		$image = wp_get_attachment_image_src($attachment_id, 'large');
		$url = is_array($image) ? (string) $image[0] : '';
		$view = self::presentation($attachment_id, true);

		wp_send_json_success([
			'enabled' => $view['state'] === 'set',
			'state' => $view['state'],
			'status' => $view['text'],
			'url' => $url,
		]);
	}

	public static function ajax_detect(): void {
		check_ajax_referer('nxt_ai_label_apply', 'nonce');

		$attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
		if ($attachment_id <= 0 || !current_user_can('edit_post', $attachment_id) || !wp_attachment_is_image($attachment_id)) {
			wp_send_json_error(['message' => __('You cannot edit this image.', 'nxt-ai-label')], 403);
		}

		$decision = (string) get_post_meta($attachment_id, '_nxt_ai_label_enabled', true);
		if ($decision !== '1' && $decision !== '0') {
			$kind = NXT_AI_Label_Auto::attachment_kind($attachment_id);
			update_post_meta($attachment_id, NXT_AI_Label_Auto::DETECTED, $kind === '' ? '0' : $kind);
		}

		$view = self::presentation($attachment_id, true);
		$detected = $view['state'] === 'suggest' ? $view['detected'] : '';

		wp_send_json_success([
			'state' => $view['state'],
			'status' => $view['text'],
			'detected' => $detected,
		]);
	}

	private static function file_has_source(int $attachment_id): bool {
		$path = get_attached_file($attachment_id);
		if (!is_string($path) || $path === '') {
			return false;
		}

		return NXT_AI_Label_Detector::file_kind($path) !== '';
	}

	/**
	 * @return array{slug: string, position: string, scale: string}
	 */
	private static function choice(int $attachment_id): array {
		$settings = NXT_AI_Label_Auto::settings();
		$slug = (string) get_post_meta($attachment_id, '_nxt_ai_label_slug', true);
		$position = (string) get_post_meta($attachment_id, '_nxt_ai_label_position', true);
		$scale = (string) get_post_meta($attachment_id, '_nxt_ai_label_scale', true);

		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = $settings['slug'];
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = $settings['position'];
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = $settings['scale'];
		}

		return [
			'slug' => $slug,
			'position' => $position,
			'scale' => $scale,
		];
	}

	private static function grouped_options(string $selected): string {
		$html = '';
		$current_group = '';
		foreach (NXT_AI_Label_Labels::all() as $key => $item) {
			if ($item['group'] !== $current_group) {
				if ($current_group !== '') {
					$html .= '</optgroup>';
				}
				$current_group = $item['group'];
				$html .= '<optgroup label="' . esc_attr($current_group) . '">';
			}
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr($key),
				selected($selected, $key, false),
				esc_html($item['label'])
			);
		}
		if ($current_group !== '') {
			$html .= '</optgroup>';
		}

		return $html;
	}
}
