<?php

namespace App\Http\Controllers\Api;

use App\Application\SalePolicyService;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Economy\SalePolicy;
use App\Domain\Economy\SalePolicyRules;
use App\Domain\Ruleset\ResetRequiredException;
use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SalePolicyController extends Controller
{
    public function index(Request $request, Nation $nation): JsonResponse
    {
        if (! NationMembership::query()->where('user_id', $request->user()->id)->where('nation_id', $nation->id)->exists()) {
            return response()->json(['message' => '自国の売却方針だけを取得できます。'], 403);
        }
        $resources = ResourceDefinition::query()
            ->where('tradable', true)
            ->with(['nationBalances' => fn ($query) => $query->where('nation_id', $nation->id)])
            ->orderBy('sort_order')
            ->get();
        $policies = NationResourceSalePolicy::query()->where('nation_id', $nation->id)->get()->keyBy('resource_definition_id');
        $rules = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
        try {
            $policyRules = SalePolicyRules::fromSettings($rules);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json(['data' => $resources->map(function (ResourceDefinition $resource) use ($policies, $policyRules): array {
            $policy = $policies->get($resource->id);
            $allowedPolicies = SalePolicy::values();
            if (in_array($resource->key, $policyRules->sellAllForbiddenResourceKeys, true)) {
                $allowedPolicies = array_values(array_diff($allowedPolicies, [SalePolicy::SellAll->value]));
            }

            return [
                'resource_id' => $resource->id,
                'resource_key' => $resource->key,
                'resource_name' => $resource->name,
                'amount' => (int) ($resource->nationBalances->first()->amount ?? 0),
                'policy' => $policy->policy ?? $policyRules->defaultPolicy,
                'keep_amount' => $policy?->keep_amount,
                'version' => $policy->version ?? 1,
                'allowed_policies' => $allowedPolicies,
            ];
        })->values()]);
    }

    public function update(Request $request, Nation $nation, ResourceDefinition $resourceDefinition, SalePolicyService $service): JsonResponse
    {
        $validated = $request->validate([
            'policy' => ['required', Rule::in(SalePolicy::values())],
            'keep_amount' => ['nullable', 'integer', 'min:0'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $policy = $service->update(
                $request->user(), $nation, $resourceDefinition, $validated['policy'],
                $validated['keep_amount'] ?? null, $validated['expected_version'],
            );

            return response()->json(['data' => [
                'resource_id' => $resourceDefinition->id,
                'resource_key' => $resourceDefinition->key,
                'resource_name' => $resourceDefinition->name,
                'policy' => $policy->policy,
                'keep_amount' => $policy->keep_amount,
                'version' => $policy->version,
            ]]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $payload = ['message' => $exception->getMessage()];
        if ($exception instanceof ResetRequiredException) {
            $payload['code'] = ResetRequiredException::ERROR_CODE;
        }

        return response()->json(
            $payload,
            $exception instanceof OptimisticLockException || $exception instanceof ResetRequiredException ? 409 : 422,
        );
    }
}
