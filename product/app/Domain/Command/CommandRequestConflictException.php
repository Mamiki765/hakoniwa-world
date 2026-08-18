<?php

namespace App\Domain\Command;

use DomainException;

final class CommandRequestConflictException extends DomainException
{
    public const ERROR_CODE = 'command_request_conflict';

    public function __construct()
    {
        parent::__construct('同じrequest keyが異なる開発計画で使用されています。');
    }
}
