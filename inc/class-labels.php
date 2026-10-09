<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Labels {
	public const DEFAULT_SLUG = 'ai-generated-black-transparent';
	public const DEFAULT_POSITION = 'bottom-right';
	public const DEFAULT_SCALE = 'medium';

	/**
	 * @return array<string, array{file: string, label: string, group: string, shape: string}>
	 */
	public static function all(): array {
		return [
			'ai-generated-black-transparent' => [
				'file' => 'ai-generated-black-transparent.png',
				'label' => __('AI Generated · black · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI Generated', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-generated-black' => [
				'file' => 'ai-generated-black.png',
				'label' => __('AI Generated · black · solid', 'nxt-ai-label'),
				'group' => __('AI Generated', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-generated-white-transparent' => [
				'file' => 'ai-generated-white-transparent.png',
				'label' => __('AI Generated · white · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI Generated', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-generated-white' => [
				'file' => 'ai-generated-white.png',
				'label' => __('AI Generated · white · solid', 'nxt-ai-label'),
				'group' => __('AI Generated', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-modified-black-transparent' => [
				'file' => 'ai-modified-black-transparent.png',
				'label' => __('AI Modified · black · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI Modified', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-modified-black' => [
				'file' => 'ai-modified-black.png',
				'label' => __('AI Modified · black · solid', 'nxt-ai-label'),
				'group' => __('AI Modified', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-modified-white-transparent' => [
				'file' => 'ai-modified-white-transparent.png',
				'label' => __('AI Modified · white · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI Modified', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-modified-white' => [
				'file' => 'ai-modified-white.png',
				'label' => __('AI Modified · white · solid', 'nxt-ai-label'),
				'group' => __('AI Modified', 'nxt-ai-label'),
				'shape' => 'wide',
			],
			'ai-black-transparent' => [
				'file' => 'ai-black-transparent.png',
				'label' => __('AI · black · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI', 'nxt-ai-label'),
				'shape' => 'square',
			],
			'ai-black' => [
				'file' => 'ai-black.png',
				'label' => __('AI · black · solid', 'nxt-ai-label'),
				'group' => __('AI', 'nxt-ai-label'),
				'shape' => 'square',
			],
			'ai-white-transparent' => [
				'file' => 'ai-white-transparent.png',
				'label' => __('AI · white · semi-transparent', 'nxt-ai-label'),
				'group' => __('AI', 'nxt-ai-label'),
				'shape' => 'square',
			],
			'ai-white' => [
				'file' => 'ai-white.png',
				'label' => __('AI · white · solid', 'nxt-ai-label'),
				'group' => __('AI', 'nxt-ai-label'),
				'shape' => 'square',
			],
		];
	}

	public static function is_valid_slug(string $slug): bool {
		return isset(self::all()[$slug]);
	}

	public static function name(string $slug): string {
		$all = self::all();
		return $all[$slug]['label'] ?? $slug;
	}

	public static function path(string $slug): ?string {
		$all = self::all();
		if (!isset($all[$slug])) {
			return null;
		}

		$path = NXT_AI_LABEL_DIR . 'assets/labels/' . $all[$slug]['file'];
		return is_readable($path) ? $path : null;
	}

	/**
	 * @return array<string, string>
	 */
	public static function positions(): array {
		return [
			'top-left' => __('Top left', 'nxt-ai-label'),
			'top-right' => __('Top right', 'nxt-ai-label'),
			'bottom-left' => __('Bottom left', 'nxt-ai-label'),
			'bottom-right' => __('Bottom right', 'nxt-ai-label'),
		];
	}

	public static function is_valid_position(string $position): bool {
		return isset(self::positions()[$position]);
	}

	/**
	 * @return array<string, array{label: string, height: int}>
	 */
	public static function scales(): array {
		return [
			'small' => [
				'label' => __('Small', 'nxt-ai-label'),
				'height' => 30,
			],
			'medium' => [
				'label' => __('Medium', 'nxt-ai-label'),
				'height' => 40,
			],
			'large' => [
				'label' => __('Large', 'nxt-ai-label'),
				'height' => 50,
			],
		];
	}

	public static function is_valid_scale(string $scale): bool {
		return isset(self::scales()[$scale]);
	}

	public static function scale_height(string $scale): int {
		$scales = self::scales();
		return $scales[$scale]['height'] ?? 40;
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	public static function pixel_size(string $slug): array {
		$shape = self::shape($slug);
		if ($shape === 'square') {
			return [2363, 2363];
		}
		if (str_contains($slug, 'modified')) {
			return [7087, 2363];
		}

		return [7459, 2363];
	}

	public static function shape(string $slug): string {
		$all = self::all();
		return $all[$slug]['shape'] ?? 'wide';
	}
}
