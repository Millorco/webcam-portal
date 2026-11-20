<?php

declare(strict_types=1);

namespace WebcamPortal\Web;

/**
 * Template PHP in templates/ con escape HTML.
 */
final class View
{
	/** @param array<string,mixed> $vars */
	public static function render(string $template, array $vars = []): string
	{
		$file = WEBCAM_PORTAL_ROOT . '/templates/' . $template . '.php';
		extract($vars, EXTR_SKIP);
		ob_start();
		try {
			include $file;
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
		return (string) ob_get_clean();
	}

	public static function e(mixed $v): string
	{
		return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** DATETIME UTC -> testo nel fuso della webcam. */
	public static function local(?string $utc, string $tz = 'Europe/Rome', string $fmt = 'd/m/Y H:i'): string
	{
		if ($utc === null || $utc === '') {
			return '—';
		}
		try {
			$d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
			return $d->setTimezone(new \DateTimeZone($tz))->format($fmt);
		} catch (\Throwable $e) {
			return $utc;
		}
	}

	/** "3 min fa", "2 h fa", "5 giorni fa". */
	public static function ago(?string $utc): string
	{
		if ($utc === null || $utc === '') {
			return 'mai';
		}
		$s = time() - (int) strtotime($utc . ' UTC');
		if ($s < 0) {
			return 'nel futuro';
		}
		if ($s < 90) {
			return 'adesso';
		}
		if ($s < 5400) {
			return round($s / 60) . ' min fa';
		}
		if ($s < 172800) {
			return round($s / 3600) . ' h fa';
		}
		return round($s / 86400) . ' giorni fa';
	}

	public static function num(mixed $v, int $dec = 1, string $unit = ''): string
	{
		if ($v === null || $v === '') {
			return '—';
		}
		return number_format((float) $v, $dec, ',', '.') . ($unit !== '' ? ' ' . $unit : '');
	}
}
