<?php

namespace App\Domain\Secretary;

use DomainException;
use Throwable;

final class SecretaryEquipmentConflictException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
