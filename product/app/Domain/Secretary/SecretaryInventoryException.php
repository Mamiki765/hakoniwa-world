<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryInventoryException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
