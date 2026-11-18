<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Service\Tokens;

/**
 * Autenticazione Bearer dei router: il token identifica la webcam.
 */
final class TokenAuth
{
	/**
	 * @return array{webcam: array<string,mixed>, token: array<string,mixed>, scopes: list<string>}
	 */
	public static function authenticate(Request $req, string $scope): array
	{
		$h = trim((string) $req->header('Authorization'));
		if ($h === '' || !preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
			throw new ApiError(401, 'token_missing', 'Token mancante: serve Authorization: Bearer <token>');
		}
		$token = $m[1];
		if (!preg_match(Tokens::PATTERN, $token)) {
			throw new ApiError(401, 'token_unknown', 'Token sconosciuto');
		}
		$row = Db::one(
			'SELECT t.id AS token_id, t.scopes, t.expires_at, t.revoked_at, w.*
			   FROM tokens t JOIN webcams w ON w.id = t.webcam_id
			  WHERE t.token_hash = ?',
			[Tokens::hash($token)]
		);
		if ($row === null) {
			throw new ApiError(401, 'token_unknown', 'Token sconosciuto');
		}
		if ($row['revoked_at'] !== null) {
			throw new ApiError(403, 'token_revoked', 'Token revocato');
		}
		if ($row['expires_at'] !== null && Db::unix($row['expires_at']) <= time()) {
			throw new ApiError(403, 'token_expired', 'Token scaduto');
		}
		if ((int) $row['status'] === 9) {
			throw new ApiError(403, 'webcam_retired', 'Webcam ritirata');
		}
		$scopes = array_values(array_filter(explode(',', (string) $row['scopes'])));
		if ($scope !== '' && !in_array($scope, $scopes, true)) {
			throw new ApiError(403, 'scope_denied', "Il token non permette \"$scope\"");
		}

		RateLimiter::hit((int) $row['token_id']);

		Db::exec(
			'UPDATE tokens SET last_used_at = UTC_TIMESTAMP(), last_used_ip = ? WHERE id = ?',
			[substr($req->clientIp(), 0, 45), $row['token_id']]
		);
		Db::exec('UPDATE webcams SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

		$token = ['id' => (int) $row['token_id'], 'scopes' => $scopes];
		unset($row['token_id'], $row['scopes'], $row['expires_at'], $row['revoked_at']);
		return ['webcam' => $row, 'token' => $token, 'scopes' => $scopes];
	}

	/** Il mac del corpo deve essere quello della webcam del token (409). */
	public static function checkMac(array $webcam, mixed $mac): void
	{
		if (!is_string($mac) || !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
			throw new ApiError(400, 'invalid_mac', 'Campo mac mancante o non valido (aa:bb:cc:dd:ee:ff, minuscolo)');
		}
		if ($mac !== $webcam['mac']) {
			throw new ApiError(409, 'mac_mismatch', 'Il mac non corrisponde alla webcam del token');
		}
	}
}
