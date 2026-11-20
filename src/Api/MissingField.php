<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

/** Campo obbligatorio mancante. */
final class MissingField extends InvalidField
{
	public function __construct(string $field)
	{
		parent::__construct($field, 'campo obbligatorio mancante');
	}
}
