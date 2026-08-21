<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryItemProbability
{
    public function passesBasisPointDraw(int $draw, int $chanceBasisPoints): bool
    {
        if ($draw < 0 || $draw > 9_999 || $chanceBasisPoints < 0 || $chanceBasisPoints > 10_000) {
            throw new DomainException('Secretary Item basis-point probability input is invalid.');
        }

        return $draw < $chanceBasisPoints;
    }
}
