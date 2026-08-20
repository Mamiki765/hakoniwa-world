<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryNotFoundException extends DomainException
{
    public const ERROR_CODE = 'secretary_not_found';
}
