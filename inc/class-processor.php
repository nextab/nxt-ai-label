<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Processor {
	private const MIN_WIDTH = 32;
	private const BACKUP_DIRNAME = 'nxt-ai-label-backup';

	public static function process(int $attachment_id): void {
		if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
			return;
		}

		if (!function_exists('imagecreatetruecolor')) {
			return;
		}

		$enabled = get_post_meta($attachment_id, '_nxt_ai_label_enabled', true) === '1';
		if (!$enabled) {
			self::restore($attachment_id);
			return;
		}

		$slug = (string) get_post_meta($attachment_id, '_nxt_ai_label_slug', true);
		$position = (string) get_post_meta($attachment_id, '_nxt_ai_label_position', true);
		$scale = (string) get_post_meta($attachment_id, '_nxt_ai_label_scale', true);

		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = NXT_AI_Label_Labels::DEFAULT_SLUG;
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = NXT_AI_Label_Labels::DEFAULT_POSITION;
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = NXT_AI_Label_Labels::DEFAULT_SCALE;
		}

		$label_path = NXT_AI_Label_Labels::path($slug);
		if ($label_path === null) {
			return;
		}

		self::ensure_backup_protection();

		$manifest = self::read_manifest($attachment_id);
		$targets = self::collect_targets($attachment_id);
		if ($targets === []) {
			return;
		}

		if ($manifest === null) {
			self::ensure_backups($attachment_id, $targets);
		} else {
			self::recreate_missing_size_backups($attachment_id);
			$targets = self::collect_targets($attachment_id);
			$safe = [];
			foreach ($targets as $relative) {
				if (self::is_ewww_webp_sibling($relative) && !self::has_backup($attachment_id, $relative)) {
					continue;
				}
				$safe[] = $relative;
			}
			self::ensure_backups($attachment_id, $safe);
		}

		$targets = self::collect_targets($attachment_id);
		$settings = [
			'slug' => $slug,
			'position' => $position,
			'scale' => $scale,
			'label_path' => $label_path,
			'height' => NXT_AI_Label_Labels::scale_height($scale),
		];

		$rasters = [];
		$webps = [];
		foreach ($targets as $relative) {
			if (self::is_ewww_webp_sibling($relative)) {
				$webps[] = $relative;
			} else {
				$rasters[] = $relative;
			}
		}

		foreach ($rasters as $relative) {
			self::apply_to_relative($attachment_id, $relative, $settings);
		}
		foreach ($webps as $relative) {
			self::apply_to_relative($attachment_id, $relative, $settings);
		}

		foreach ($rasters as $relative) {
			$webp_relative = $relative . '.webp';
			$webp_public = self::public_path($webp_relative);
			if ($webp_public === null) {
				continue;
			}
			if (self::has_backup($attachment_id, $webp_relative)) {
				continue;
			}
			if (!is_readable($webp_public)) {
				continue;
			}
			$raster_public = self::public_path($relative);
			if ($raster_public === null || !is_readable($raster_public)) {
				continue;
			}
			self::write_webp_from_raster($raster_public, $webp_public, $settings['slug']);
		}
	}

	public static function restore(int $attachment_id): void {
		$manifest = self::read_manifest($attachment_id);
		if ($manifest === null || empty($manifest['files']) || !is_array($manifest['files'])) {
			return;
		}

		foreach ($manifest['files'] as $relative) {
			$relative = self::normalize_relative((string) $relative);
			if ($relative === '') {
				continue;
			}

			$backup = self::backup_path($attachment_id, $relative);
			$public = self::public_path($relative);
			if ($backup === null || $public === null || !is_readable($backup)) {
				continue;
			}

			$dir = dirname($public);
			if (!is_dir($dir)) {
				wp_mkdir_p($dir);
			}

			copy($backup, $public);
		}
	}

	public static function delete_backup(int $attachment_id): void {
		$dir = self::backup_dir($attachment_id);
		if (!is_dir($dir)) {
			return;
		}

		self::rrmdir($dir);
	}

	/**
	 * @return list<int>
	 */
	public static function labeled_ids(): array {
		$ids = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_key' => '_nxt_ai_label_enabled',
			'meta_value' => '1',
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		if (!is_array($ids)) {
			return [];
		}

		return array_values(array_map('intval', $ids));
	}

	public static function mark(int $attachment_id, bool $enabled, string $slug, string $position, string $scale): bool {
		if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
			return false;
		}

		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = NXT_AI_Label_Labels::DEFAULT_SLUG;
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = NXT_AI_Label_Labels::DEFAULT_POSITION;
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = NXT_AI_Label_Labels::DEFAULT_SCALE;
		}

		update_post_meta($attachment_id, '_nxt_ai_label_enabled', $enabled ? '1' : '0');
		update_post_meta($attachment_id, '_nxt_ai_label_slug', $slug);
		update_post_meta($attachment_id, '_nxt_ai_label_position', $position);
		update_post_meta($attachment_id, '_nxt_ai_label_scale', $scale);
		self::process($attachment_id);

		return true;
	}

	/**
	 * @param list<int> $attachment_ids
	 * @return array{processed: int, skipped: int, ids: list<int>}
	 */
	public static function apply_settings(array $attachment_ids, bool $enabled, string $slug, string $position, string $scale): array {
		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = NXT_AI_Label_Labels::DEFAULT_SLUG;
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = NXT_AI_Label_Labels::DEFAULT_POSITION;
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = NXT_AI_Label_Labels::DEFAULT_SCALE;
		}

		$processed = 0;
		$skipped = 0;
		$done = [];

		foreach ($attachment_ids as $attachment_id) {
			$attachment_id = (int) $attachment_id;
			if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
				$skipped++;
				continue;
			}

			if (!current_user_can('edit_post', $attachment_id)) {
				$skipped++;
				continue;
			}

			if (!self::mark($attachment_id, $enabled, $slug, $position, $scale)) {
				$skipped++;
				continue;
			}

			$processed++;
			$done[] = $attachment_id;
		}

		return [
			'processed' => $processed,
			'skipped' => $skipped,
			'ids' => $done,
		];
	}

	/**
	 * @param list<int>|null $attachment_ids null = all labeled
	 * @return array{processed: int, skipped: int, ids: list<int>}
	 */
	public static function regenerate(?array $attachment_ids = null): array {
		$ids = $attachment_ids ?? self::labeled_ids();
		$processed = 0;
		$skipped = 0;
		$done = [];

		foreach ($ids as $attachment_id) {
			$attachment_id = (int) $attachment_id;
			if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
				$skipped++;
				continue;
			}

			if (get_post_meta($attachment_id, '_nxt_ai_label_enabled', true) !== '1') {
				$skipped++;
				continue;
			}

			self::process($attachment_id);
			$processed++;
			$done[] = $attachment_id;
		}

		return [
			'processed' => $processed,
			'skipped' => $skipped,
			'ids' => $done,
		];
	}

	public static function restore_all_and_cleanup(): void {
		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return;
		}

		$root = trailingslashit($upload['basedir']) . self::BACKUP_DIRNAME;
		if (!is_dir($root)) {
			return;
		}

		$entries = scandir($root);
		if ($entries === false) {
			return;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === 'index.php' || $entry === '.htaccess') {
				continue;
			}
			if (!ctype_digit($entry)) {
				continue;
			}

			$attachment_id = (int) $entry;
			self::restore($attachment_id);
			delete_post_meta($attachment_id, '_nxt_ai_label_enabled');
			delete_post_meta($attachment_id, '_nxt_ai_label_slug');
			delete_post_meta($attachment_id, '_nxt_ai_label_position');
			delete_post_meta($attachment_id, '_nxt_ai_label_scale');
			delete_post_meta($attachment_id, '_nxt_ai_label_detected');
			delete_post_meta($attachment_id, '_nxt_ai_label_auto');
			self::delete_backup($attachment_id);
		}

		self::rrmdir($root);
	}

	/**
	 * @return list<string>
	 */
	private static function collect_targets(int $attachment_id): array {
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!is_array($metadata)) {
			$metadata = [];
		}

		$attached = get_post_meta($attachment_id, '_wp_attached_file', true);
		$relatives = [];

		if (is_string($attached) && $attached !== '') {
			$relatives[] = self::normalize_relative($attached);
		}

		if (!empty($metadata['file']) && is_string($metadata['file'])) {
			$relatives[] = self::normalize_relative($metadata['file']);
		}

		$base_dir = self::metadata_base_dir($metadata);

		if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$relatives[] = self::normalize_relative($base_dir . $metadata['original_image']);
		}

		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size) {
				if (empty($size['file']) || !is_string($size['file'])) {
					continue;
				}
				$relatives[] = self::normalize_relative($base_dir . $size['file']);
			}
		}

		$relatives = array_values(array_unique(array_filter($relatives)));
		$targets = [];

		foreach ($relatives as $relative) {
			$public = self::public_path($relative);
			$has_backup = self::has_backup($attachment_id, $relative);
			if (($public === null || !is_readable($public)) && !$has_backup) {
				continue;
			}
			if ($public !== null && is_readable($public) && !self::is_supported_path($public)) {
				continue;
			}
			$targets[] = $relative;

			$webp_relative = $relative . '.webp';
			$webp_public = self::public_path($webp_relative);
			if (($webp_public !== null && is_readable($webp_public)) || self::has_backup($attachment_id, $webp_relative)) {
				$targets[] = $webp_relative;
			}
		}

		return array_values(array_unique($targets));
	}

	/**
	 * @param list<string> $targets
	 */
	private static function ensure_backups(int $attachment_id, array $targets): void {
		$manifest = self::read_manifest($attachment_id) ?? [
			'files' => [],
			'created' => gmdate('c'),
		];
		$files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
		$changed = false;

		foreach ($targets as $relative) {
			if (self::has_backup($attachment_id, $relative)) {
				if (!in_array($relative, $files, true)) {
					$files[] = $relative;
					$changed = true;
				}
				continue;
			}

			$backup = self::backup_path($attachment_id, $relative);
			$public = self::public_path($relative);
			if ($backup === null || $public === null || !is_readable($public)) {
				continue;
			}

			$backup_dir = dirname($backup);
			if (!is_dir($backup_dir)) {
				wp_mkdir_p($backup_dir);
			}
			if (!copy($public, $backup)) {
				continue;
			}

			$files[] = $relative;
			$changed = true;
		}

		if ($changed) {
			$manifest['files'] = array_values(array_unique($files));
			$manifest['updated'] = gmdate('c');
			self::write_manifest($attachment_id, $manifest);
		}
	}

	private static function recreate_missing_size_backups(int $attachment_id): void {
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!is_array($metadata) || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
			return;
		}

		$clean_full = self::clean_fullsize_path($attachment_id);
		if ($clean_full === null) {
			return;
		}

		$base_dir = self::metadata_base_dir($metadata);

		foreach ($metadata['sizes'] as $size) {
			if (empty($size['file']) || !is_string($size['file'])) {
				continue;
			}

			$relative = self::normalize_relative($base_dir . $size['file']);
			if (self::has_backup($attachment_id, $relative)) {
				continue;
			}

			$width = (int) ($size['width'] ?? 0);
			$height = (int) ($size['height'] ?? 0);
			if ($width < self::MIN_WIDTH || $height <= 0) {
				continue;
			}

			$public = self::public_path($relative);
			if ($public === null) {
				continue;
			}

			$editor = wp_get_image_editor($clean_full);
			if (is_wp_error($editor)) {
				continue;
			}

			$crop = !empty($size['crop']);
			$resized = $editor->resize($width, $height, $crop);
			if (is_wp_error($resized)) {
				continue;
			}

			$dir = dirname($public);
			if (!is_dir($dir)) {
				wp_mkdir_p($dir);
			}

			$saved = $editor->save($public);
			if (is_wp_error($saved)) {
				continue;
			}

			self::ensure_backups($attachment_id, [$relative]);
		}
	}

	/**
	 * @param array{slug: string, position: string, scale: string, label_path: string, height: int} $settings
	 */
	private static function apply_to_relative(int $attachment_id, string $relative, array $settings): void {
		$backup = self::backup_path($attachment_id, $relative);
		$public = self::public_path($relative);
		if ($public === null) {
			return;
		}

		if ($backup !== null && is_readable($backup)) {
			$dir = dirname($public);
			if (!is_dir($dir)) {
				wp_mkdir_p($dir);
			}
			copy($backup, $public);
			self::stamp_file($public, $settings);
			return;
		}

		if (self::is_ewww_webp_sibling($relative)) {
			$raster_relative = preg_replace('/\.webp$/i', '', $relative);
			$raster_public = is_string($raster_relative) ? self::public_path($raster_relative) : null;
			if ($raster_public !== null && is_readable($raster_public)) {
				self::write_webp_from_raster($raster_public, $public, $settings['slug']);
			}
			return;
		}

		if (is_readable($public)) {
			self::stamp_file($public, $settings);
		}
	}

	/**
	 * @param array{slug: string, position: string, scale: string, label_path: string, height: int} $settings
	 */
	private static function stamp_file(string $path, array $settings): bool {
		if (!is_readable($path) || !is_writable($path)) {
			return false;
		}

		$info = @getimagesize($path);
		if ($info === false) {
			return false;
		}

		$width = (int) $info[0];
		$height = (int) $info[1];
		$mime = (string) ($info['mime'] ?? '');

		if ($width < self::MIN_WIDTH || $height <= 0) {
			return false;
		}

		$image = self::load_image($path, $mime);
		if ($image === null) {
			return false;
		}

		$label = self::load_image($settings['label_path'], 'image/png');
		if ($label === null) {
			return false;
		}

		$label_w = imagesx($label);
		$label_h = imagesy($label);
		if ($label_w <= 0 || $label_h <= 0) {
			return false;
		}

		$pad = max(4, (int) round(min($width, $height) * 0.03));
		$max_w = max(1, $width - (2 * $pad));
		$max_h = max(1, $height - (2 * $pad));
		$target_h = max(1, (int) $settings['height']);
		$target_w = max(1, (int) round($target_h * ($label_w / $label_h)));

		if ($target_w > $max_w) {
			$target_w = $max_w;
			$target_h = max(1, (int) round($target_w * ($label_h / $label_w)));
		}
		if ($target_h > $max_h) {
			$target_h = $max_h;
			$target_w = max(1, (int) round($target_h * ($label_w / $label_h)));
		}

		$target_w = max(1, $target_w);
		$target_h = max(1, $target_h);

		$scaled = imagecreatetruecolor($target_w, $target_h);
		if ($scaled === false) {
			return false;
		}

		imagealphablending($scaled, false);
		imagesavealpha($scaled, true);
		$transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
		imagefilledrectangle($scaled, 0, 0, $target_w, $target_h, $transparent);
		imagecopyresampled($scaled, $label, 0, 0, 0, 0, $target_w, $target_h, $label_w, $label_h);

		[$x, $y] = self::coords($settings['position'], $width, $height, $target_w, $target_h, $pad);

		imagealphablending($image, true);
		imagesavealpha($image, true);
		imagecopy($image, $scaled, $x, $y, 0, 0, $target_w, $target_h);

		$saved = self::save_image($image, $path, $mime);
		if ($saved) {
			NXT_AI_Label_Meta::embed($path, (string) $settings['slug']);
		}

		return $saved;
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private static function coords(string $position, int $width, int $height, int $label_w, int $label_h, int $pad): array {
		return match ($position) {
			'top-left' => [$pad, $pad],
			'top-right' => [$width - $label_w - $pad, $pad],
			'bottom-left' => [$pad, $height - $label_h - $pad],
			default => [$width - $label_w - $pad, $height - $label_h - $pad],
		};
	}

	private static function load_image(string $path, string $mime): ?\GdImage {
		$image = match ($mime) {
			'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
			'image/png' => @imagecreatefrompng($path),
			'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
			default => false,
		};

		if ($image === false) {
			return null;
		}

		imagealphablending($image, false);
		imagesavealpha($image, true);

		return $image;
	}

	private static function save_image(\GdImage $image, string $path, string $mime): bool {
		$result = match ($mime) {
			'image/jpeg', 'image/jpg' => imagejpeg($image, $path, 90),
			'image/png' => imagepng($image, $path, 6),
			'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, self::webp_quality()) : false,
			default => false,
		};

		return (bool) $result;
	}

	private static function write_webp_from_raster(string $raster_path, string $webp_path, string $slug): bool {
		if (!function_exists('imagewebp') || !is_readable($raster_path)) {
			return false;
		}

		$info = @getimagesize($raster_path);
		if ($info === false) {
			return false;
		}

		$image = self::load_image($raster_path, (string) ($info['mime'] ?? ''));
		if ($image === null) {
			return false;
		}

		$dir = dirname($webp_path);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$saved = (bool) imagewebp($image, $webp_path, self::webp_quality());
		if ($saved) {
			NXT_AI_Label_Meta::embed($webp_path, $slug);
		}

		return $saved;
	}

	private static function webp_quality(): int {
		if (function_exists('ewww_image_optimizer_get_option')) {
			$quality = ewww_image_optimizer_get_option('webp_quality');
			if (is_numeric($quality)) {
				return max(1, min(100, (int) $quality));
			}
		}

		return 75;
	}

	private static function clean_fullsize_path(int $attachment_id): ?string {
		$attached = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (!is_string($attached) || $attached === '') {
			return null;
		}

		$relative = self::normalize_relative($attached);
		$backup = self::backup_path($attachment_id, $relative);
		if ($backup !== null && is_readable($backup)) {
			return $backup;
		}

		$public = self::public_path($relative);
		return ($public !== null && is_readable($public)) ? $public : null;
	}

	private static function is_ewww_webp_sibling(string $relative): bool {
		return (bool) preg_match('/\.(jpe?g|png)\.webp$/i', $relative);
	}

	private static function is_supported_path(string $path): bool {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($ext === 'webp') {
			return true;
		}

		return in_array($ext, ['jpg', 'jpeg', 'png'], true);
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private static function metadata_base_dir(array $metadata): string {
		if (empty($metadata['file']) || !is_string($metadata['file'])) {
			return '';
		}

		$base_dir = trailingslashit(dirname($metadata['file']));
		if ($base_dir === './') {
			return '';
		}

		return $base_dir;
	}

	private static function normalize_relative(string $relative): string {
		$relative = str_replace('\\', '/', $relative);
		$relative = ltrim($relative, '/');
		$relative = preg_replace('#^\./#', '', $relative) ?? $relative;
		return $relative;
	}

	private static function public_path(string $relative): ?string {
		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return null;
		}

		$relative = self::normalize_relative($relative);
		if ($relative === '' || str_contains($relative, '..')) {
			return null;
		}

		return trailingslashit($upload['basedir']) . $relative;
	}

	private static function backup_root(): ?string {
		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return null;
		}

		return trailingslashit($upload['basedir']) . self::BACKUP_DIRNAME;
	}

	private static function backup_dir(int $attachment_id): string {
		return trailingslashit((string) self::backup_root()) . (string) $attachment_id;
	}

	private static function backup_path(int $attachment_id, string $relative): ?string {
		$relative = self::normalize_relative($relative);
		if ($relative === '' || str_contains($relative, '..')) {
			return null;
		}

		return trailingslashit(self::backup_dir($attachment_id)) . $relative;
	}

	private static function has_backup(int $attachment_id, string $relative): bool {
		$path = self::backup_path($attachment_id, $relative);
		return $path !== null && is_readable($path);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function read_manifest(int $attachment_id): ?array {
		$path = trailingslashit(self::backup_dir($attachment_id)) . 'manifest.json';
		if (!is_readable($path)) {
			return null;
		}

		$json = file_get_contents($path);
		if ($json === false) {
			return null;
		}

		$data = json_decode($json, true);
		return is_array($data) ? $data : null;
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private static function write_manifest(int $attachment_id, array $manifest): void {
		$dir = self::backup_dir($attachment_id);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		file_put_contents(
			trailingslashit($dir) . 'manifest.json',
			wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);
	}

	private static function ensure_backup_protection(): void {
		$root = self::backup_root();
		if ($root === null) {
			return;
		}

		if (!is_dir($root)) {
			wp_mkdir_p($root);
		}

		$htaccess = trailingslashit($root) . '.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Require all denied\n");
		}

		$index = trailingslashit($root) . 'index.php';
		if (!file_exists($index)) {
			file_put_contents($index, "<?php\n// Silence is golden.\n");
		}
	}

	private static function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}

		$items = scandir($dir);
		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				self::rrmdir($path);
			} else {
				unlink($path);
			}
		}

		rmdir($dir);
	}
}
