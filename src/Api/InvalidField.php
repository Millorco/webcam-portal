<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

/** Valore di un campo non valido (tipo, formato o intervallo). */
class InvalidField extends \RuntimeException
{
	public function __construct(public readonly string $field, string $reason)
	{
		parent::__construct("$field: $reason");
	}
}
