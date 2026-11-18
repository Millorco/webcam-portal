<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

/**
 * Errore di una chiamata alle API di Dolibarr. status = 0 per errori di rete.
 */
final class DolibarrException extends \RuntimeException
{
	public function __construct(public readonly int $status, string $message, public readonly ?array $body = null)
	{
		parent::__construct($message);
	}

	/** Messaggio della RestException di Dolibarr, se c'è. */
	public function apiMessage(): string
	{
		$m = $this->body['error']['message'] ?? ($this->body['message'] ?? '');
		return is_string($m) ? $m : '';
	}

	/**
	 * true se l'endpoint sembra non esistere (modulo webcams 1.1 senza le API
	 * v2): 404 senza "Webcam not found", 405 o 501.
	 */
	public function endpointMissing(): bool
	{
		if (in_array($this->status, [405, 501], true)) {
			return true;
		}
		return $this->status === 404 && stripos($this->apiMessage(), 'webcam not found') === false;
	}
}
