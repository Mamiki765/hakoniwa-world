<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryEquipmentValidationException extends DomainException
{
    public const ERROR_CODE = 'secretary_equipment_invalid';
}
