<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Auto {
	public const OPTION = 'nxt_ai_label_auto';
	public const DETECTED = '_nxt_ai_label_detected';

	/**
	 * @return array{enabled: bool, slug: string, position: string, scale: string}
	 */
	public static function settings(): array {
		$stored = get_option(self::OPTION, []);
		if (!is_array($stored)) {
			$stored = [];
		}

		$slug = isset($stored['slug']) ? (string) $stored['slug'] : NXT_AI_Label_Labels::DEFAULT_SLUG;
		$position = isset($stored['position']) ? (string) $stored['position'] : NXT_AI_Label_Labels::DEFAULT_POSITION;
		$scale = isset($stored['scale']) ? (string) $stored['scale'] : NXT_AI_Label_Labels::DEFAULT_SCALE;

		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = NXT_AI_Label_Labels::DEFAULT_SLUG;
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = NXT_AI_Label_Labels::DEFAULT_POSITION;
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = NXT_AI_Label_Labels::DEFAULT_SCALE;
		}

		return [
			'enabled' => ($stored['enabled'] ?? '0') === '1',
			'slug' => $slug,
			'position' => $position,
			'scale' => $scale,
		];
	}

	/**
	 * @param array{enabled?: string, slug?: string, position?: string, scale?: string} $input
	 */
	public static function save(array $input): void {
		$current = self::settings();
		$slug = isset($input['slug']) ? (string) $input['slug'] : $current['slug'];
		$position = isset($input['position']) ? (string) $input['position'] : $current['position'];
		$scale = isset($input['scale']) ? (string) $input['scale'] : $current['scale'];

		if (!NXT_AI_Label_Labels::is_valid_slug($slug)) {
			$slug = NXT_AI_Label_Labels::DEFAULT_SLUG;
		}
		if (!NXT_AI_Label_Labels::is_valid_position($position)) {
			$position = NXT_AI_Label_Labels::DEFAULT_POSITION;
		}
		if (!NXT_AI_Label_Labels::is_valid_scale($scale)) {
			$scale = NXT_AI_Label_Labels::DEFAULT_SCALE;
		}

		update_option(self::OPTION, [
			'enabled' => !empty($input['enabled']) ? '1' : '0',
			'slug' => $slug,
			'position' => $position,
			'scale' => $scale,
		], false);
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public static function maybe_flag(array $metadata, int $attachment_id): array {
		if (!wp_attachment_is_image($attachment_id)) {
			return $metadata;
		}

		$known = (string) get_post_meta($attachment_id, self::DETECTED, true);
		if ($known !== 'generated' && $known !== 'modified' && $known !== '0') {
			$kind = self::attachment_kind($attachment_id, $metadata);
			update_post_meta($attachment_id, self::DETECTED, $kind === '' ? '0' : $kind);
		} else {
			$kind = $known === '0' ? '' : $known;
		}

		$settings = self::settings();
		if (!$settings['enabled']) {
			return $metadata;
		}

		$current = (string) get_post_meta($attachment_id, '_nxt_ai_label_enabled', true);
		if ($current === '0' || $current === '1' || $kind === '') {
			return $metadata;
		}

		update_post_meta($attachment_id, '_nxt_ai_label_enabled', '1');
		update_post_meta($attachment_id, '_nxt_ai_label_slug', $settings['slug']);
		update_post_meta($attachment_id, '_nxt_ai_label_position', $settings['position']);
		update_post_meta($attachment_id, '_nxt_ai_label_scale', $settings['scale']);
		update_post_meta($attachment_id, '_nxt_ai_label_auto', '1');

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public static function attachment_kind(int $attachment_id, array $metadata = []): string {
		$kind = NXT_AI_Label_Detector::attachment_kind($attachment_id);
		$declared = $kind !== '';
		$passed = (bool) apply_filters('nxt_ai_label_attachment_is_generated', $declared, $attachment_id, $metadata);
		if (!$passed) {
			return '';
		}
		if ($kind === '') {
			return 'generated';
		}

		return $kind;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public static function attachment_is_generated(int $attachment_id, array $metadata = []): bool {
		return self::attachment_kind($attachment_id, $metadata) !== '';
	}

	/**
	 * @return array{checked: int, marked: int, found: int, last_id: int, done: bool, ids: list<int>}
	 */
	public static function scan_batch(int $after_id, int $limit = 25, bool $apply = true, bool $rescan = false): array {
		global $wpdb;

		$limit = max(1, min(50, $limit));
		$after_id = max(0, $after_id);
		$like = $wpdb->esc_like('image/') . '%';

		if ($apply) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND p.ID > %d
					AND m.meta_id IS NULL
				ORDER BY p.ID ASC
				LIMIT %d",
				'_nxt_ai_label_enabled',
				$like,
				$after_id,
				$limit
			));
		} elseif ($rescan) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND p.ID > %d
					AND (e.meta_id IS NULL OR e.meta_value <> '1')
				ORDER BY p.ID ASC
				LIMIT %d",
				'_nxt_ai_label_enabled',
				$like,
				$after_id,
				$limit
			));
		} else {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND p.ID > %d
					AND d.meta_id IS NULL
					AND (e.meta_id IS NULL OR e.meta_value <> '1')
				ORDER BY p.ID ASC
				LIMIT %d",
				self::DETECTED,
				'_nxt_ai_label_enabled',
				$like,
				$after_id,
				$limit
			));
		}

		if (!is_array($ids)) {
			$ids = [];
		}

		$settings = self::settings();
		$marked = 0;
		$found_ids = [];
		$last_id = $after_id;

		foreach ($ids as $attachment_id) {
			$attachment_id = (int) $attachment_id;
			$last_id = $attachment_id;
			$kind = self::attachment_kind($attachment_id);
			update_post_meta($attachment_id, self::DETECTED, $kind === '' ? '0' : $kind);
			if ($kind === '') {
				continue;
			}

			$found_ids[] = $attachment_id;
			if (!$apply) {
				continue;
			}

			$decision = (string) get_post_meta($attachment_id, '_nxt_ai_label_enabled', true);
			if ($decision === '0' || $decision === '1') {
				continue;
			}

			if (!NXT_AI_Label_Processor::mark($attachment_id, true, $settings['slug'], $settings['position'], $settings['scale'])) {
				continue;
			}

			update_post_meta($attachment_id, '_nxt_ai_label_auto', '1');
			$marked++;
		}

		return [
			'checked' => count($ids),
			'marked' => $marked,
			'found' => count($found_ids),
			'last_id' => $last_id,
			'done' => count($ids) < $limit,
			'ids' => $found_ids,
		];
	}

	public static function scan_remaining(bool $rescan): int {
		global $wpdb;

		$like = $wpdb->esc_like('image/') . '%';
		if ($rescan) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND (e.meta_id IS NULL OR e.meta_value <> '1')",
				'_nxt_ai_label_enabled',
				$like
			));
		} else {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND d.meta_id IS NULL
					AND (e.meta_id IS NULL OR e.meta_value <> '1')",
				self::DETECTED,
				'_nxt_ai_label_enabled',
				$like
			));
		}

		return (int) $count;
	}

	/**
	 * @return array{ids: list<int>, total: int}
	 */
	public static function pending_page(int $paged, int $per_page): array {
		global $wpdb;

		$per_page = max(1, min(50, $per_page));
		$paged = max(1, $paged);
		$offset = ($paged - 1) * $per_page;
		$like = $wpdb->esc_like('image/') . '%';
		$from = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
			WHERE p.post_type = 'attachment'
				AND p.post_status = 'inherit'
				AND p.post_mime_type LIKE %s
				AND d.meta_value IN ('generated', 'modified')
				AND (e.meta_id IS NULL OR e.meta_value = '')";

		$total = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) {$from}",
			self::DETECTED,
			'_nxt_ai_label_enabled',
			$like
		));

		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID {$from} ORDER BY p.ID DESC LIMIT %d OFFSET %d",
			self::DETECTED,
			'_nxt_ai_label_enabled',
			$like,
			$per_page,
			$offset
		));

		if (!is_array($ids)) {
			$ids = [];
		}

		return [
			'ids' => array_values(array_map('intval', $ids)),
			'total' => $total,
		];
	}

	/**
	 * @return list<int>
	 */
	public static function pending_ids_after(int $after_id, int $limit): array {
		global $wpdb;

		$limit = max(1, min(20, $limit));
		$after_id = max(0, $after_id);
		$like = $wpdb->esc_like('image/') . '%';
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} d ON p.ID = d.post_id AND d.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} e ON p.ID = e.post_id AND e.meta_key = %s
			WHERE p.post_type = 'attachment'
				AND p.post_status = 'inherit'
				AND p.post_mime_type LIKE %s
				AND d.meta_value IN ('generated', 'modified')
				AND (e.meta_id IS NULL OR e.meta_value = '')
				AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d",
			self::DETECTED,
			'_nxt_ai_label_enabled',
			$like,
			$after_id,
			$limit
		));

		if (!is_array($ids)) {
			return [];
		}

		return array_values(array_map('intval', $ids));
	}
}
