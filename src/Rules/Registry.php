<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

use WebcamPortal\Rules\Rule as R;

/**
 * Elenco delle regole di diagnosi-v1.md, nell'ordine di valutazione
 * (telemetry.stalled prima di camera.stalled, che ne dipende).
 */
final class Registry
{
	/** @var array<string,Rule>|null */
	private static ?array $rules = null;

	/** @return array<string,Rule> */
	public static function all(): array
	{
		if (self::$rules === null) {
			self::$rules = [];
			foreach ([
				new R\TelemetryStalled(), new R\CameraStalled(),
				new R\EnclosureSeal(), new R\EnclosureHeater(), new R\EnclosureOverheat(), new R\EnclosureFan(),
				new R\GlassFog(), new R\GlassDirty(), new R\CameraMoved(),
				new R\ShutterLife(), new R\LogsReboots(), new R\LogsCameraErrors(),
			] as $r) {
				self::$rules[$r->id()] = $r;
			}
		}
		return self::$rules;
	}

	public static function get(string $id): ?Rule
	{
		return self::all()[$id] ?? null;
	}
}
