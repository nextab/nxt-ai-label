<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Detector {
	private const MODIFIED = 'compositeWithTrainedAlgorithmicMedia';
	private const GENERATED = 'trainedAlgorithmicMedia';

	public static function file_declares_ai(string $path): bool {
		return self::file_kind($path) !== '';
	}

	public static function file_kind(string $path): string {
		if ($path === '' || !is_readable($path)) {
			return '';
		}

		$size = filesize($path);
		if ($size === false || $size <= 0) {
			return '';
		}

		$handle = fopen($path, 'rb');
		if ($handle === false) {
			return '';
		}

		$modified_le = self::utf16le(self::MODIFIED);
		$generated_le = self::utf16le(self::GENERATED);
		$found_generated = false;
		$carry = '';
		$left = $size;
		$max = 12 * 1024 * 1024;
		$read = 0;

		while ($left > 0 && $read < $max) {
			$chunk_len = (int) min(524288, $left, $max - $read);
			$chunk = (string) fread($handle, $chunk_len);
			if ($chunk === '') {
				break;
			}
			$blob = $carry . $chunk;
			if (self::has_modified($blob, $modified_le)) {
				fclose($handle);
				return 'modified';
			}
			if (self::has_generated($blob, $generated_le)) {
				$found_generated = true;
			}
			$carry = substr($blob, -80);
			$read += strlen($chunk);
			$left -= strlen($chunk);
		}

		if ($size > $max) {
			$tail_len = (int) min(1048576, $size);
			fseek($handle, -$tail_len, SEEK_END);
			$tail = (string) fread($handle, $tail_len);
			if (self::has_modified($tail, $modified_le)) {
				fclose($handle);
				return 'modified';
			}
			if (self::has_generated($tail, $generated_le)) {
				$found_generated = true;
			}
		}

		fclose($handle);

		return $found_generated ? 'generated' : '';
	}

	public static function attachment_kind(int $attachment_id): string {
		$paths = [];
		if (function_exists('wp_get_original_image_path')) {
			$original = wp_get_original_image_path($attachment_id);
			if (is_string($original) && $original !== '') {
				$paths[] = $original;
			}
		}

		$attached = get_attached_file($attachment_id);
		if (is_string($attached) && $attached !== '' && !in_array($attached, $paths, true)) {
			$paths[] = $attached;
		}

		$kind = '';
		foreach ($paths as $path) {
			$found = self::file_kind($path);
			if ($found === 'modified') {
				return 'modified';
			}
			if ($found === 'generated') {
				$kind = 'generated';
			}
		}

		return $kind;
	}

	private static function has_modified(string $blob, string $utf16le): bool {
		return stripos($blob, self::MODIFIED) !== false || str_contains($blob, $utf16le);
	}

	private static function has_generated(string $blob, string $utf16le): bool {
		return stripos($blob, self::GENERATED) !== false || str_contains($blob, $utf16le);
	}

	private static function utf16le(string $value): string {
		$out = '';
		$length = strlen($value);
		for ($i = 0; $i < $length; $i++) {
			$out .= $value[$i] . "\0";
		}

		return $out;
	}
}
