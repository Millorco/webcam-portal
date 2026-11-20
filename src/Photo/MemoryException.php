<?php

declare(strict_types=1);

namespace WebcamPortal\Photo;

/** La foto non entra in memory_limit: si salta (skip_reason = memory). */
final class MemoryException extends \RuntimeException
{
}
