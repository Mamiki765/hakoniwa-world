<?php

namespace App\Domain\MessageBoard;

use DomainException;

final class MessageBoardValidationException extends DomainException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
