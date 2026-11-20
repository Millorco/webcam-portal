<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

/**
 * Errore delle API v1: codice HTTP, codice di errore e messaggio in italiano
 * (vedi la tabella "Risposte" di api-portal-v1.md).
 */
final class ApiError extends \RuntimeException
{
	/** @param array<string,string> $headers @param array<string,mixed> $extra */
	public function __construct(
		public readonly int $status,
		public readonly string $error,
		string $message,
		public readonly array $headers = [],
		public readonly array $extra = []
	) {
		parent::__construct($message);
	}
}
