<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

/**
 * Esito di una valutazione di regola.
 *
 * - ALARM: la condizione di apertura è soddisfatta (già con le sue durate
 *   minime, calcolate dalla regola sui dati);
 * - OK: la condizione di chiusura è soddisfatta;
 * - HOLD: né l'una né l'altra (isteresi): l'avviso aperto resta aperto,
 *   senza avviso lo stato è "pending" se $pending;
 * - UNKNOWN: dati insufficienti, non apre e non chiude.
 */
final class Outcome
{
	public const ALARM = 'alarm';
	public const OK = 'ok';
	public const HOLD = 'hold';
	public const UNKNOWN = 'unknown';

	public ?string $severity = null;
	public ?string $action = null;
	public ?string $title = null;
	/** @var list<string> righe "Cosa è stato misurato" del ticket */
	public array $lines = [];
	/** @var array<string,mixed> valori misurati (rule_state.last_value, alerts.details.values) */
	public array $values = [];
	public ?string $check = null;
	public ?string $cause = null;
	/** Secondi in cui la chiusura deve restare vera prima di chiudere (stato recovering). */
	public int $clearFor = 0;
	public string $closeReason = 'auto';
	public ?string $closeNote = null;
	public bool $pending = false;
	public ?int $dataAt = null;

	public function __construct(public string $state)
	{
	}

	public static function unknown(string $why, array $values = []): self
	{
		$o = new self(self::UNKNOWN);
		$o->values = ['why' => $why] + $values;
		return $o;
	}

	public static function ok(array $values = []): self
	{
		$o = new self(self::OK);
		$o->values = $values;
		return $o;
	}

	public static function hold(array $values = [], bool $pending = false): self
	{
		$o = new self(self::HOLD);
		$o->values = $values;
		$o->pending = $pending;
		return $o;
	}

	public static function alarm(string $title, array $lines = [], array $values = []): self
	{
		$o = new self(self::ALARM);
		$o->title = $title;
		$o->lines = $lines;
		$o->values = $values;
		return $o;
	}
}
