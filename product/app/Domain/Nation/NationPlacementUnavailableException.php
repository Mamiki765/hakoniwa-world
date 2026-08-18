<?php

namespace App\Domain\Nation;

use DomainException;

final class NationPlacementUnavailableException extends DomainException
{
    public const ERROR_CODE = 'nation_creation_unavailable';
}
