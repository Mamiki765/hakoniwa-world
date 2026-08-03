<?php

namespace App\Domain\Ruleset;

use DomainException;

final class ResetRequiredException extends DomainException
{
    public const ERROR_CODE = 'reset_required';
}
