<?php

namespace App\Application;

final readonly class ExternalIdentityData
{
    public function __construct(
        public string $providerUserId,
        public string $displayName,
    ) {}
}
