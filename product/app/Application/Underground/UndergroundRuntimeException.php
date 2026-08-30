<?php

namespace App\Application\Underground;

use DomainException;
use Throwable;

final class UndergroundRuntimeException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
