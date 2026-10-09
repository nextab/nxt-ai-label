<?php

if (!defined('ABSPATH')) {
	exit;
}

final class NXT_AI_Label_Meta {
	public static function hint(string $slug): string {
		if (self::kind($slug) === 'generated') {
			return __('Created with AI.', 'nxt-ai-label');
		}

		return __('Edited with AI.', 'nxt-ai-label');
	}

	public static function source_uri(string $slug): string {
		$code = self::kind($slug) === 'generated'
			? 'trainedAlgorithmicMedia'
			: 'compositeWithTrainedAlgorithmicMedia';

		return 'http://cv.iptc.org/newscodes/digitalsourcetype/' . $code;
	}

	public static function embed(string $path, string $slug): void {
		if ($path === '' || !is_readable($path) || !is_writable($path)) {
			return;
		}

		$info = @getimagesize($path);
		if ($info === false) {
			return;
		}

		$mime = (string) ($info['mime'] ?? '');
		$xmp = self::packet($slug);
		if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
			self::embed_jpeg($path, $xmp, self::hint($slug));
			return;
		}
		if ($mime === 'image/png') {
			self::embed_png($path, $xmp);
			return;
		}
		if ($mime === 'image/webp') {
			self::embed_webp($path, $xmp);
		}
	}

	public static function kind(string $slug): string {
		if (str_contains($slug, 'generated')) {
			return 'generated';
		}

		return 'modified';
	}

	private static function packet(string $slug): string {
		$uri = htmlspecialchars(self::source_uri($slug), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$hint = htmlspecialchars(self::hint($slug), ENT_XML1 | ENT_QUOTES, 'UTF-8');

		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:iptcExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/">'
			. '<iptcExt:DigitalSourceType>' . $uri . '</iptcExt:DigitalSourceType>'
			. '<photoshop:Instructions>' . $hint . '</photoshop:Instructions>'
			. '</rdf:Description>'
			. '</rdf:RDF>'
			. '</x:xmpmeta>'
			. '<?xpacket end="w"?>';
	}

	private static function embed_jpeg(string $path, string $xmp, string $hint): void {
		$bytes = file_get_contents($path);
		if (!is_string($bytes) || !str_starts_with($bytes, "\xFF\xD8")) {
			return;
		}
		if (str_contains($bytes, 'iptcExt:DigitalSourceType')) {
			return;
		}

		if (function_exists('iptcembed')) {
			$binary = iptcembed(self::iptc_block($hint), $path, 0);
			if (is_string($binary) && str_starts_with($binary, "\xFF\xD8")) {
				file_put_contents($path, $binary);
				$bytes = $binary;
			}
		}

		if (str_contains($bytes, 'iptcExt:DigitalSourceType')) {
			return;
		}

		$payload = "http://ns.adobe.com/xap/1.0/\0" . $xmp;
		if (strlen($payload) + 2 > 65535) {
			return;
		}

		$segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
		file_put_contents($path, "\xFF\xD8" . $segment . substr($bytes, 2));
	}

	private static function embed_png(string $path, string $xmp): void {
		$bytes = file_get_contents($path);
		if (!is_string($bytes) || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
			return;
		}
		if (str_contains($bytes, 'iptcExt:DigitalSourceType')) {
			return;
		}

		$iend = strpos($bytes, 'IEND');
		if ($iend === false || $iend < 4) {
			return;
		}

		$chunk = self::png_itxt('XML:com.adobe.xmp', $xmp);
		file_put_contents($path, substr($bytes, 0, $iend - 4) . $chunk . substr($bytes, $iend - 4));
	}

	private static function embed_webp(string $path, string $xmp): void {
		$bytes = file_get_contents($path);
		if (!is_string($bytes) || strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
			return;
		}
		if (str_contains($bytes, 'iptcExt:DigitalSourceType')) {
			return;
		}

		$bytes = self::flag_webp_xmp($bytes);
		$chunk = 'XMP ' . pack('V', strlen($xmp)) . $xmp;
		if ((strlen($xmp) % 2) === 1) {
			$chunk .= "\0";
		}
		$bytes .= $chunk;
		$bytes = substr($bytes, 0, 4) . pack('V', strlen($bytes) - 8) . substr($bytes, 8);
		file_put_contents($path, $bytes);
	}

	private static function flag_webp_xmp(string $bytes): string {
		$offset = 12;
		$length = strlen($bytes);
		while ($offset + 8 <= $length) {
			$fourcc = substr($bytes, $offset, 4);
			$unpacked = unpack('V', substr($bytes, $offset + 4, 4));
			$chunk_size = (int) ($unpacked[1] ?? 0);
			if ($fourcc === 'VP8X' && $chunk_size >= 1 && ($offset + 8) < $length) {
				$bytes[$offset + 8] = chr(ord($bytes[$offset + 8]) | 0x10);
				return $bytes;
			}
			if ($chunk_size < 0) {
				break;
			}
			$offset += 8 + $chunk_size + ($chunk_size % 2);
		}

		return $bytes;
	}

	private static function png_itxt(string $keyword, string $text): string {
		$data = $keyword . "\0\0\0\0\0" . $text;
		$crc = crc32('iTXt' . $data);
		if ($crc < 0) {
			$crc += 4294967296;
		}

		return pack('N', strlen($data)) . 'iTXt' . $data . pack('N', $crc);
	}

	private static function iptc_block(string $hint): string {
		$length = strlen($hint);

		return chr(0x1C) . chr(2) . chr(40) . chr($length >> 8) . chr($length & 0xFF) . $hint;
	}
}
