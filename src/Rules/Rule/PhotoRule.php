<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Rule;

/** Regole sulle metriche delle foto (photo_metrics, bin/photo-metrics.php). */
abstract class PhotoRule extends Rule
{
	public function group(): string
	{
		return 'photo';
	}

	/**
	 * Foto analizzate (non scartate) dal più vecchio, in (now - $sec, now].
	 *
	 * @return list<array<string,mixed>>
	 */
	protected function metrics(Context $c, int $sec, bool $needRatio = true): array
	{
		return Db::all(
			'SELECT pm.*, UNIX_TIMESTAMP(pm.taken_at) AS t FROM photo_metrics pm
			  WHERE pm.webcam_id = ? AND pm.taken_at > ? AND pm.taken_at <= ? AND pm.classification <> \'skipped\''
				. ($needRatio ? ' AND pm.blur_ratio IS NOT NULL' : '') . ' ORDER BY pm.taken_at',
			[$c->id(), Db::dt($c->now - $sec), Db::dt($c->now)]
		);
	}

	protected static function r(mixed $v): ?float
	{
		return $v === null ? null : (float) $v;
	}
}
