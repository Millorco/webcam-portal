<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Token delle webcam: wct_ + 32 byte casuali in base64url (47 caratteri).
 * Nel database c'è solo hex(sha256(token)).
 */
final class Tokens
{
	public const PATTERN = '/^wct_[A-Za-z0-9_-]{43}$/';

	public static function generate(): string
	{
		return 'wct_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/** Crea un token nuovo per la webcam e lo restituisce in chiaro (unica volta). */
	public static function create(int $webcamId, ?string $label = null, bool $revokeOthers = false): string
	{
		$token = self::generate();
		if ($revokeOthers) {
			Db::exec('UPDATE tokens SET revoked_at = UTC_TIMESTAMP() WHERE webcam_id = ? AND revoked_at IS NULL', [$webcamId]);
		}
		Db::exec(
			'INSERT INTO tokens (webcam_id, token_hash, token_prefix, label, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
			[$webcamId, self::hash($token), substr($token, 0, 12), $label]
		);
		return $token;
	}
}
