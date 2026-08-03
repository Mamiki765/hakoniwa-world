<?php

namespace App\Domain\Economy;

use App\Models\ResourceDefinition;
use DomainException;

final readonly class SalePolicyRules
{
    /** @param list<string> $sellAllForbiddenResourceKeys */
    private function __construct(
        public string $defaultPolicy,
        public array $sellAllForbiddenResourceKeys,
    ) {}

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        $defaultPolicy = $settings['default_sale_policy'] ?? null;
        if (! SalePolicy::isSupportedRulesetDefault($defaultPolicy)) {
            throw new DomainException('Worldのdefault sale policy設定が不正です。');
        }

        $salePolicy = $settings['turn_processing']['sale_policy'] ?? null;
        if (! is_array($salePolicy)
            || ! array_key_exists('sell_all_forbidden_resource_keys', $salePolicy)) {
            throw new DomainException('The active ruleset is missing sale policy settings.');
        }
        $forbidden = $salePolicy['sell_all_forbidden_resource_keys'];
        if (! is_array($forbidden) || ! array_is_list($forbidden)
            || collect($forbidden)->contains(static fn (mixed $key): bool => ! is_string($key) || $key === '')) {
            throw new DomainException('The active ruleset has invalid sale policy settings.');
        }

        return new self($defaultPolicy, $forbidden);
    }

    public function assertAllowed(ResourceDefinition $resource, string $policy): void
    {
        if ($policy === SalePolicy::SellAll->value
            && in_array($resource->key, $this->sellAllForbiddenResourceKeys, true)) {
            throw new DomainException("{$resource->name}ではsell_allを使用できません。");
        }
    }
}
