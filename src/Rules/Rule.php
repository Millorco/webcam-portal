<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

/**
 * Una regola di diagnosi-v1.md. Le soglie predefinite (defaults) hanno gli
 * stessi nomi del contratto e si cambiano in config.php (rules.params) o per
 * webcam (rule_config.params). Le durate in ore o minuti hanno il suffisso
 * nel nome solo dove il contratto non lo dà (clear_for è sempre in ore).
 */
abstract class Rule
{
	/** id stabile, es. enclosure.seal */
	abstract public function id(): string;

	/** Nome breve italiano per il pannello. */
	abstract public function label(): string;

	/** Gravità e azione all'apertura. */
	abstract public function severity(): string;

	abstract public function action(): string;

	/** @return array<string,mixed> */
	public function defaults(): array
	{
		return [];
	}

	abstract public function evaluate(Context $c): Outcome;

	/** Si valuta e apre avvisi anche in manutenzione (solo telemetry.stalled). */
	public function evaluateInMaintenance(): bool
	{
		return false;
	}

	/** Gruppo: "data" (custodia, camera), "photo" (vetro), "logs". */
	public function group(): string
	{
		return 'data';
	}

	/** Dopo l'apertura (o la riapertura): comandi al router. */
	public function afterOpen(Context $c, int $alertId): void
	{
	}

	/** A ogni valutazione con l'avviso aperto (dopo l'esito). */
	public function whileOpen(Context $c, Outcome $o): void
	{
	}

	/** Gli eventi alert.* vanno alla sincronizzazione (agenda/ticket)? */
	public function forward(): bool
	{
		return true;
	}
}
