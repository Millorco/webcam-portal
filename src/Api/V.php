<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

/**
 * Lettura con controllo dei campi dei JSON del contratto (tipi e intervalli
 * degli schemi in docs/contratto/*.schema.json). Un campo assente o null
 * restituisce null; un valore del tipo sbagliato o fuori intervallo lancia
 * InvalidField.
 */
final class V
{
	public static function isObject(mixed $v): bool
	{
		return is_array($v) && ($v === [] || !array_is_list($v));
	}

	/** @param array<string,mixed> $o */
	public static function obj(array $o, string $k, string $path): ?array
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (!self::isObject($v)) {
			throw new InvalidField("$path$k", 'oggetto atteso');
		}
		return $v;
	}

	/** @param array<string,mixed> $o */
	public static function int(array $o, string $k, string $path, ?int $min = null, ?int $max = null): ?int
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (is_float($v) && floor($v) === $v && abs($v) < 9.0E15) {
			$v = (int) $v;
		}
		if (!is_int($v)) {
			throw new InvalidField("$path$k", 'intero atteso');
		}
		if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
			throw new InvalidField("$path$k", 'fuori intervallo');
		}
		return $v;
	}

	/** @param array<string,mixed> $o */
	public static function num(array $o, string $k, string $path, ?float $min = null, ?float $max = null): ?float
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (!is_int($v) && !is_float($v)) {
			throw new InvalidField("$path$k", 'numero atteso');
		}
		$v = (float) $v;
		if (!is_finite($v) || ($min !== null && $v < $min) || ($max !== null && $v > $max)) {
			throw new InvalidField("$path$k", 'fuori intervallo');
		}
		return $v;
	}

	/** @param array<string,mixed> $o */
	public static function bool(array $o, string $k, string $path): ?bool
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (!is_bool($v)) {
			throw new InvalidField("$path$k", 'booleano atteso');
		}
		return $v;
	}

	/** @param array<string,mixed> $o */
	public static function str(array $o, string $k, string $path, int $maxLen = 255, ?string $pattern = null): ?string
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (!is_string($v)) {
			throw new InvalidField("$path$k", 'stringa attesa');
		}
		if (mb_strlen($v) > $maxLen) {
			throw new InvalidField("$path$k", 'troppo lunga');
		}
		if ($pattern !== null && !preg_match($pattern, $v)) {
			throw new InvalidField("$path$k", 'formato non valido');
		}
		return $v;
	}

	/** @param list<string> $allowed */
	public static function enum(array $o, string $k, string $path, array $allowed): ?string
	{
		$v = $o[$k] ?? null;
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || !in_array($v, $allowed, true)) {
			throw new InvalidField("$path$k", 'valore non ammesso');
		}
		return $v;
	}

	/** Campo obbligatorio: assente -> MissingField. */
	public static function req(array $o, string $k, string $path): void
	{
		if (!array_key_exists($k, $o)) {
			throw new MissingField("$path$k");
		}
	}

	/** Valore SQL di un booleano (1/0/null). */
	public static function b(?bool $v): ?int
	{
		return $v === null ? null : ($v ? 1 : 0);
	}
}
