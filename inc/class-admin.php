<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Admin {
	public static function register(): void {
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		add_action('admin_post_nxt_ai_label_regenerate', [self::class, 'handle_regenerate']);
		add_action('admin_post_nxt_ai_label_apply', [self::class, 'handle_apply']);
		add_action('admin_post_nxt_ai_label_auto_save', [self::class, 'handle_auto_save']);
		add_action('admin_post_nxt_ai_label_pending_apply', [self::class, 'handle_pending_apply']);
		add_action('wp_ajax_nxt_ai_label_scan_batch', [self::class, 'ajax_scan_batch']);
		add_action('wp_ajax_nxt_ai_label_apply_batch', [self::class, 'ajax_apply_batch']);
		add_filter('bulk_actions-upload', [self::class, 'bulk_actions']);
		add_filter('handle_bulk_actions-upload', [self::class, 'handle_bulk'], 10, 3);
		add_action('admin_notices', [self::class, 'notices']);
	}

	public static function enqueue(string $hook): void {
		if ($hook !== 'tools_page_nxt-ai-label') {
			return;
		}

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
		wp_localize_script('nxt-ai-label-admin', 'nxtAiLabelTools', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'pageUrl' => admin_url('tools.php?page=nxt-ai-label'),
			'scanNonce' => wp_create_nonce('nxt_ai_label_scan'),
			'applyNonce' => wp_create_nonce('nxt_ai_label_pending_apply'),
			'i18n' => [
				'scanStart' => __('Scanning the library.', 'nxt-ai-label'),
				'applyStart' => __('Labeling images.', 'nxt-ai-label'),
				'scanProgress' => __('Checked %1$d of %2$d. Found %3$d.', 'nxt-ai-label'),
				'applyProgress' => __('Labeled %1$d of %2$d. Skipped %3$d.', 'nxt-ai-label'),
				'failed' => __('The batch stopped. Start it again to continue with what is left.', 'nxt-ai-label'),
			],
		]);
	}

	public static function menu(): void {
		add_management_page(
			__('AI label', 'nxt-ai-label'),
			__('AI label', 'nxt-ai-label'),
			'upload_files',
			'nxt-ai-label',
			[self::class, 'render_page']
		);
	}

	public static function render_page(): void {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to view this page.', 'nxt-ai-label'));
		}

		$bulk_ids = self::bulk_ids_from_request();
		if ($bulk_ids !== []) {
			self::render_apply_form($bulk_ids);
			return;
		}

		$count = count(NXT_AI_Label_Processor::labeled_ids());
		$auto = NXT_AI_Label_Auto::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('AI label', 'nxt-ai-label'); ?></h1>
			<p><?php esc_html_e('Only images you mark are changed. The label is burned into the file, and the file metadata records whether it was created or edited with AI.', 'nxt-ai-label'); ?></p>
			<h2><?php esc_html_e('Default label', 'nxt-ai-label'); ?></h2>
			<p><?php esc_html_e('These values are preselected when you open an image, and they are used for the batch below. You do not have to set the dropdowns again for every file.', 'nxt-ai-label'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="nxt_ai_label_auto_save" />
				<?php wp_nonce_field('nxt_ai_label_auto_save'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nxt_ai_label_auto_slug"><?php esc_html_e('Label', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_slug" id="nxt_ai_label_auto_slug">
								<?php echo self::label_options($auto['slug']); ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxt_ai_label_auto_position"><?php esc_html_e('Position', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_position" id="nxt_ai_label_auto_position">
								<?php foreach (NXT_AI_Label_Labels::positions() as $key => $label) : ?>
									<option value="<?php echo esc_attr($key); ?>"<?php selected($auto['position'], $key); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxt_ai_label_auto_scale"><?php esc_html_e('Size', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_scale" id="nxt_ai_label_auto_scale">
								<?php foreach (NXT_AI_Label_Labels::scales() as $key => $item) : ?>
									<option value="<?php echo esc_attr($key); ?>"<?php selected($auto['scale'], $key); ?>><?php echo esc_html($item['label']); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('New uploads', 'nxt-ai-label'); ?></th>
						<td>
							<label><input type="checkbox" name="nxt_ai_label_auto_enabled" value="1" <?php checked($auto['enabled']); ?> /> <?php esc_html_e('Apply the default label when the file declares an AI origin', 'nxt-ai-label'); ?></label>
							<p class="description"><?php esc_html_e('Looks for trainedAlgorithmicMedia or compositeWithTrainedAlgorithmicMedia, including the original file before WordPress scaling. A label you remove stays removed. Generators without that metadata can call nxt_ai_label_mark( $attachment_id ). The filter nxt_ai_label_attachment_is_generated runs only while this box is checked.', 'nxt-ai-label'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Save default label', 'nxt-ai-label')); ?>
			</form>
			<h2><?php esc_html_e('Images that should have a label', 'nxt-ai-label'); ?></h2>
			<p><?php echo esc_html(sprintf(
				/* translators: 1: label name, 2: position, 3: size */
				__('Batch uses the saved default: %1$s, %2$s, %3$s. Each file also gets IPTC/XMP metadata: Created with AI, or Edited with AI.', 'nxt-ai-label'),
				NXT_AI_Label_Labels::name($auto['slug']),
				NXT_AI_Label_Labels::positions()[$auto['position']] ?? $auto['position'],
				NXT_AI_Label_Labels::scales()[$auto['scale']]['label'] ?? $auto['scale']
			)); ?></p>
			<div id="nxt-ai-label-job" class="nxt-ai-label-job" hidden>
				<p id="nxt-ai-label-job-text"></p>
				<div class="nxt-ai-label-job__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="nxt-ai-label-job__bar"></div>
				</div>
			</div>
			<form id="nxt-ai-label-scan" action="#">
				<label><input type="checkbox" id="nxt-ai-label-rescan" value="1" /> <?php esc_html_e('Check images again', 'nxt-ai-label'); ?></label>
				<p><button type="button" class="button" id="nxt-ai-label-scan-start"><?php esc_html_e('Scan library', 'nxt-ai-label'); ?></button></p>
			</form>
			<p class="description"><?php esc_html_e('Finds images with an AI origin and no label. It does not write the label. WP-CLI wp nxt-ai-label detect still writes it.', 'nxt-ai-label'); ?></p>
			<?php self::render_queue(); ?>
			<h2><?php esc_html_e('Labels already applied', 'nxt-ai-label'); ?></h2>
			<p><strong><?php echo esc_html((string) $count); ?></strong> <?php echo esc_html(_n('image with an active label.', 'images with an active label.', $count, 'nxt-ai-label')); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="nxt_ai_label_regenerate" />
				<?php wp_nonce_field('nxt_ai_label_regenerate'); ?>
				<?php submit_button(__('Regenerate applied labels', 'nxt-ai-label'), 'secondary', 'submit', false, $count === 0 ? ['disabled' => 'disabled'] : null); ?>
			</form>
			<p class="description"><?php esc_html_e('Rewrites labels that are already applied, using the unmarked backup, and writes the AI metadata into those files. It does not add new labels. WP-CLI: wp nxt-ai-label regenerate', 'nxt-ai-label'); ?></p>
		</div>
		<?php
	}

	/**
	 * @param list<int> $ids
	 */
	private static function render_apply_form(array $ids): void {
		$token = isset($_GET['bulk']) ? sanitize_key((string) $_GET['bulk']) : '';
		$defaults = NXT_AI_Label_Auto::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Apply AI label', 'nxt-ai-label'); ?></h1>
			<p><?php echo esc_html(sprintf(
				/* translators: %d: number of selected images */
				_n('Only this %d image. The rest of the library stays untouched.', 'Only these %d images. The rest of the library stays untouched.', count($ids), 'nxt-ai-label'),
				count($ids)
			)); ?></p>
			<ul>
				<?php foreach ($ids as $id) : ?>
					<li><?php echo esc_html(get_the_title($id) !== '' ? get_the_title($id) : ('#' . $id)); ?> <code>#<?php echo esc_html((string) $id); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="nxt_ai_label_apply" />
				<input type="hidden" name="bulk" value="<?php echo esc_attr($token); ?>" />
				<?php wp_nonce_field('nxt_ai_label_apply'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Action', 'nxt-ai-label'); ?></th>
						<td>
							<label><input type="radio" name="nxt_ai_label_mode" value="set" checked="checked" /> <?php esc_html_e('Apply label', 'nxt-ai-label'); ?></label><br />
							<label><input type="radio" name="nxt_ai_label_mode" value="remove" /> <?php esc_html_e('Remove label', 'nxt-ai-label'); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxt_ai_label_slug"><?php esc_html_e('Label', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_slug" id="nxt_ai_label_slug">
								<?php echo self::label_options($defaults['slug']); ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxt_ai_label_position"><?php esc_html_e('Position', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_position" id="nxt_ai_label_position">
								<?php foreach (NXT_AI_Label_Labels::positions() as $key => $label) : ?>
									<option value="<?php echo esc_attr($key); ?>"<?php selected($defaults['position'], $key); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxt_ai_label_scale"><?php esc_html_e('Size', 'nxt-ai-label'); ?></label></th>
						<td>
							<select name="nxt_ai_label_scale" id="nxt_ai_label_scale">
								<?php foreach (NXT_AI_Label_Labels::scales() as $key => $item) : ?>
									<option value="<?php echo esc_attr($key); ?>"<?php selected($defaults['scale'], $key); ?>><?php echo esc_html($item['label']); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Apply to selected images', 'nxt-ai-label')); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_regenerate(): void {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to do this.', 'nxt-ai-label'));
		}

		check_admin_referer('nxt_ai_label_regenerate');

		$result = NXT_AI_Label_Processor::regenerate();
		self::redirect_with_result($result, 'regenerated');
	}

	public static function handle_apply(): void {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to do this.', 'nxt-ai-label'));
		}

		check_admin_referer('nxt_ai_label_apply');

		$token = isset($_POST['bulk']) ? sanitize_key((string) $_POST['bulk']) : '';
		$ids = self::bulk_ids($token);
		if ($ids === []) {
			wp_die(esc_html__('That selection has expired. Select the images in the library again.', 'nxt-ai-label'));
		}

		$mode = isset($_POST['nxt_ai_label_mode']) ? sanitize_key((string) $_POST['nxt_ai_label_mode']) : 'set';
		$enabled = $mode !== 'remove';
		$slug = isset($_POST['nxt_ai_label_slug']) ? sanitize_text_field(wp_unslash((string) $_POST['nxt_ai_label_slug'])) : NXT_AI_Label_Labels::DEFAULT_SLUG;
		$position = isset($_POST['nxt_ai_label_position']) ? sanitize_key((string) $_POST['nxt_ai_label_position']) : NXT_AI_Label_Labels::DEFAULT_POSITION;
		$scale = isset($_POST['nxt_ai_label_scale']) ? sanitize_key((string) $_POST['nxt_ai_label_scale']) : NXT_AI_Label_Labels::DEFAULT_SCALE;

		$result = NXT_AI_Label_Processor::apply_settings($ids, $enabled, $slug, $position, $scale);
		delete_transient(self::transient_key($token));
		self::redirect_with_result($result, $enabled ? 'applied' : 'removed');
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function bulk_actions(array $actions): array {
		$actions['nxt_ai_label_apply'] = __('Apply AI label', 'nxt-ai-label');
		$actions['nxt_ai_label_regenerate'] = __('Regenerate AI label', 'nxt-ai-label');
		return $actions;
	}

	/**
	 * @param list<int> $post_ids
	 */
	public static function handle_bulk(string $redirect_to, string $doaction, array $post_ids): string {
		if (!current_user_can('upload_files')) {
			return $redirect_to;
		}

		$ids = [];
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id > 0 && current_user_can('edit_post', $post_id) && wp_attachment_is_image($post_id)) {
				$ids[] = $post_id;
			}
		}

		if ($doaction === 'nxt_ai_label_apply') {
			if ($ids === []) {
				return add_query_arg('nxt_ai_label_skipped', count($post_ids), $redirect_to);
			}

			$token = wp_generate_password(12, false, false);
			set_transient(self::transient_key($token), $ids, 15 * MINUTE_IN_SECONDS);

			return add_query_arg(
				[
					'page' => 'nxt-ai-label',
					'bulk' => $token,
				],
				admin_url('tools.php')
			);
		}

		if ($doaction === 'nxt_ai_label_regenerate') {
			$result = NXT_AI_Label_Processor::regenerate($ids);
			return add_query_arg(
				[
					'nxt_ai_label_notice' => 'regenerated',
					'nxt_ai_label_done' => (int) $result['processed'],
					'nxt_ai_label_skipped' => (int) $result['skipped'],
				],
				$redirect_to
			);
		}

		return $redirect_to;
	}

	public static function notices(): void {
		if (!isset($_GET['nxt_ai_label_done'])) {
			return;
		}

		if (!current_user_can('upload_files')) {
			return;
		}

		$done = (int) $_GET['nxt_ai_label_done'];
		$skipped = isset($_GET['nxt_ai_label_skipped']) ? (int) $_GET['nxt_ai_label_skipped'] : 0;
		$notice = isset($_GET['nxt_ai_label_notice']) ? sanitize_key((string) $_GET['nxt_ai_label_notice']) : 'regenerated';

		$checked = isset($_GET['nxt_ai_label_checked']) ? (int) $_GET['nxt_ai_label_checked'] : 0;

		$message = match ($notice) {
			'applied' => sprintf(
				/* translators: 1: images changed, 2: images skipped */
				__('AI label applied: %1$d. Skipped: %2$d.', 'nxt-ai-label'),
				$done,
				$skipped
			),
			'removed' => sprintf(
				/* translators: 1: images changed, 2: images skipped */
				__('AI label removed: %1$d. Skipped: %2$d.', 'nxt-ai-label'),
				$done,
				$skipped
			),
			'detected' => sprintf(
				/* translators: 1: images checked, 2: images that should be labeled */
				__('Checked %1$d images. %2$d should get a label. Nothing was written yet.', 'nxt-ai-label'),
				$checked,
				$done
			),
			'empty' => __('Select at least one image.', 'nxt-ai-label'),
			'saved' => __('Default label saved.', 'nxt-ai-label'),
			default => sprintf(
				/* translators: 1: images regenerated, 2: images skipped */
				__('AI label regenerated: %1$d. Skipped: %2$d. Images without a label stay unchanged.', 'nxt-ai-label'),
				$done,
				$skipped
			),
		};

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html($message);
		echo '</p></div>';
	}

	/**
	 * @param array{processed: int, skipped: int, ids: list<int>} $result
	 */
	private static function redirect_with_result(array $result, string $notice): void {
		$redirect = add_query_arg(
			[
				'page' => 'nxt-ai-label',
				'nxt_ai_label_notice' => $notice,
				'nxt_ai_label_done' => (int) $result['processed'],
				'nxt_ai_label_skipped' => (int) $result['skipped'],
			],
			admin_url('tools.php')
		);

		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * @return list<int>
	 */
	private static function bulk_ids_from_request(): array {
		if (!isset($_GET['bulk'])) {
			return [];
		}

		return self::bulk_ids(sanitize_key((string) $_GET['bulk']));
	}

	/**
	 * @return list<int>
	 */
	private static function bulk_ids(string $token): array {
		if ($token === '') {
			return [];
		}

		$stored = get_transient(self::transient_key($token));
		if (!is_array($stored)) {
			return [];
		}

		$ids = [];
		foreach ($stored as $id) {
			$id = (int) $id;
			if ($id > 0 && current_user_can('edit_post', $id) && wp_attachment_is_image($id)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	private static function transient_key(string $token): string {
		return 'nxt_ai_label_bulk_' . get_current_user_id() . '_' . $token;
	}

	private static function label_options(string $selected): string {
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

	public static function handle_auto_save(): void {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to do this.', 'nxt-ai-label'));
		}

		check_admin_referer('nxt_ai_label_auto_save');

		NXT_AI_Label_Auto::save([
			'enabled' => isset($_POST['nxt_ai_label_auto_enabled']) ? '1' : '0',
			'slug' => isset($_POST['nxt_ai_label_slug']) ? sanitize_text_field(wp_unslash((string) $_POST['nxt_ai_label_slug'])) : '',
			'position' => isset($_POST['nxt_ai_label_position']) ? sanitize_key((string) $_POST['nxt_ai_label_position']) : '',
			'scale' => isset($_POST['nxt_ai_label_scale']) ? sanitize_key((string) $_POST['nxt_ai_label_scale']) : '',
		]);

		self::redirect_with_result([
			'processed' => 0,
			'skipped' => 0,
			'ids' => [],
		], 'saved');
	}

	public static function ajax_scan_batch(): void {
		check_ajax_referer('nxt_ai_label_scan', 'nonce');
		if (!current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('You are not allowed to do this.', 'nxt-ai-label')], 403);
		}

		$after = isset($_POST['after']) ? (int) $_POST['after'] : 0;
		$rescan = isset($_POST['rescan']) && (string) wp_unslash($_POST['rescan']) === '1';
		$total = isset($_POST['total']) ? (int) $_POST['total'] : 0;
		if ($after <= 0 || $total <= 0) {
			$total = NXT_AI_Label_Auto::scan_remaining($rescan);
		}

		$batch = NXT_AI_Label_Auto::scan_batch(max(0, $after), 25, false, $rescan);
		$last = (int) $batch['last_id'];
		wp_send_json_success([
			'done' => (bool) $batch['done'] || $last <= max(0, $after),
			'after' => $last,
			'checked' => (int) $batch['checked'],
			'found' => (int) $batch['found'],
			'total' => $total,
		]);
	}

	public static function ajax_apply_batch(): void {
		check_ajax_referer('nxt_ai_label_pending_apply', 'nonce');
		if (!current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('You are not allowed to do this.', 'nxt-ai-label')], 403);
		}

		$after = isset($_POST['after']) ? (int) $_POST['after'] : 0;
		$total = isset($_POST['total']) ? (int) $_POST['total'] : 0;
		if ($after <= 0 || $total <= 0) {
			$total = NXT_AI_Label_Auto::pending_page(1, 1)['total'];
		}

		$settings = NXT_AI_Label_Auto::settings();
		$ids = NXT_AI_Label_Auto::pending_ids_after(max(0, $after), 8);
		if ($ids === []) {
			wp_send_json_success([
				'done' => true,
				'after' => max(0, $after),
				'processed' => 0,
				'skipped' => 0,
				'total' => $total,
			]);
		}

		$result = NXT_AI_Label_Processor::apply_settings($ids, true, $settings['slug'], $settings['position'], $settings['scale']);
		$last = (int) $ids[count($ids) - 1];
		wp_send_json_success([
			'done' => $last <= max(0, $after),
			'after' => $last,
			'processed' => (int) $result['processed'],
			'skipped' => (int) $result['skipped'],
			'total' => $total,
		]);
	}

	private static function render_queue(): void {
		$per_page = 20;
		$paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
		$page = NXT_AI_Label_Auto::pending_page($paged, $per_page);
		$total = (int) $page['total'];
		$ids = $page['ids'];

		if ($total === 0) {
			echo '<p>' . esc_html__('Nothing open. After a scan, images with an AI origin and no label show up here.', 'nxt-ai-label') . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html((string) $total) . '</strong> ' . esc_html(_n(
			'image should get a label.',
			'images should get a label.',
			$total,
			'nxt-ai-label'
		)) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="nxt_ai_label_pending_apply" />
			<?php wp_nonce_field('nxt_ai_label_pending_apply'); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="nxt-ai-label-select-all" /></td>
						<th><?php esc_html_e('Image', 'nxt-ai-label'); ?></th>
						<th><?php esc_html_e('Detection', 'nxt-ai-label'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($ids as $id) : ?>
						<?php
						if (!current_user_can('edit_post', $id)) {
							continue;
						}
						$title = get_the_title($id);
						if ($title === '') {
							$file = get_attached_file($id);
							$title = is_string($file) && $file !== '' ? basename($file) : ('#' . $id);
						}
						$kind = (string) get_post_meta($id, NXT_AI_Label_Auto::DETECTED, true);
						$kind_label = $kind === 'modified'
							? __('Edited with AI', 'nxt-ai-label')
							: __('AI-generated', 'nxt-ai-label');
						$open = add_query_arg('item', (string) $id, admin_url('upload.php'));
						?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" class="nxt-ai-label-pending" name="ids[]" value="<?php echo esc_attr((string) $id); ?>" /></th>
							<td>
								<?php echo wp_get_attachment_image($id, [48, 48]); ?>
								<a href="<?php echo esc_url($open); ?>"><?php echo esc_html($title); ?></a>
								<code>#<?php echo esc_html((string) $id); ?></code>
							</td>
							<td><?php echo esc_html($kind_label); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<?php submit_button(__('Label selected', 'nxt-ai-label'), 'primary', 'nxt_ai_label_apply_selected', false); ?>
				<button type="button" class="button" id="nxt-ai-label-apply-all"><?php esc_html_e('Label all detected images', 'nxt-ai-label'); ?></button>
			</p>
		</form>
		<?php
		$pages = (int) ceil($total / $per_page);
		if ($pages > 1) {
			echo wp_kses_post((string) paginate_links([
				'base' => add_query_arg('paged', '%#%', admin_url('tools.php?page=nxt-ai-label')),
				'format' => '',
				'current' => $paged,
				'total' => $pages,
				'prev_text' => __('Previous', 'nxt-ai-label'),
				'next_text' => __('Next', 'nxt-ai-label'),
			]));
		}
	}

	public static function handle_pending_apply(): void {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to do this.', 'nxt-ai-label'));
		}

		check_admin_referer('nxt_ai_label_pending_apply');

		$settings = NXT_AI_Label_Auto::settings();
		$raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : [];
		if (!is_array($raw)) {
			$raw = [];
		}

		$ids = [];
		foreach ($raw as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		if ($ids === []) {
			self::redirect_with_result([
				'processed' => 0,
				'skipped' => 0,
				'ids' => [],
			], 'empty');
		}

		$result = NXT_AI_Label_Processor::apply_settings($ids, true, $settings['slug'], $settings['position'], $settings['scale']);
		self::redirect_with_result($result, 'applied');
	}
}
