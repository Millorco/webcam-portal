<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Http\Request;

/**
 * Corpo JSON di /telemetry e /event, con Content-Encoding: gzip facoltativo.
 * Il limite di dimensione vale dopo la decompressione.
 */
final class Body
{
	/** @return array<string,mixed> */
	public static function json(Request $req, int $maxBytes): array
	{
		$ct = strtolower(trim(explode(';', (string) $req->header('Content-Type'))[0]));
		if ($ct !== 'application/json' && $ct !== '') {
			throw new ApiError(415, 'unsupported_media_type', 'Serve Content-Type: application/json');
		}
		$enc = strtolower(trim((string) $req->header('Content-Encoding')));
		if ($enc !== '' && $enc !== 'identity' && $enc !== 'gzip') {
			throw new ApiError(415, 'unsupported_encoding', 'Content-Encoding accettato: gzip');
		}
		if ($enc !== 'gzip' && $req->contentLength() > $maxBytes) {
			throw new ApiError(413, 'too_large', "Corpo oltre $maxBytes byte");
		}
		$in = fopen('php://input', 'rb');
		if ($in === false) {
			throw new ApiError(400, 'invalid_body', 'Corpo non leggibile');
		}
		$raw = '';
		try {
			if ($enc === 'gzip') {
				$ctx = inflate_init(ZLIB_ENCODING_GZIP);
				while (!feof($in)) {
					$chunk = (string) fread($in, 65536);
					$out = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
					if ($out === false) {
						throw new ApiError(400, 'invalid_gzip', 'Corpo gzip non valido');
					}
					$raw .= $out;
					if (strlen($raw) > $maxBytes) {
						throw new ApiError(413, 'too_large', "Corpo decompresso oltre $maxBytes byte");
					}
				}
			} else {
				$raw = (string) stream_get_contents($in, $maxBytes + 1);
				if (strlen($raw) > $maxBytes) {
					throw new ApiError(413, 'too_large', "Corpo oltre $maxBytes byte");
				}
			}
		} finally {
			fclose($in);
		}
		$data = json_decode($raw, true, 64);
		if (!V::isObject($data)) {
			throw new ApiError(400, 'invalid_json', 'JSON non leggibile');
		}
		return $data;
	}
}
