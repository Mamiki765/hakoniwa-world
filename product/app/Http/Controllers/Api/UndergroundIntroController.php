<?php

namespace App\Http\Controllers\Api;

use App\Application\SecretaryLendingService;
use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundPlaytestService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Application\Underground\UndergroundSurfaceMapProjection;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcquireUndergroundSkillRequest;
use App\Http\Requests\AdvanceUndergroundIntroRequest;
use App\Http\Requests\AllocateUndergroundStpRequest;
use App\Http\Requests\CompleteUndergroundRecollectionRequest;
use App\Http\Requests\NameUndergroundShopkeeperRequest;
use App\Http\Requests\RespecUndergroundProfileRequest;
use App\Http\Requests\SelectUndergroundGrowthPathRequest;
use App\Http\Requests\UndergroundBankTransferRequest;
use App\Http\Requests\UndergroundExploreRequest;
use App\Http\Requests\UndergroundHuntingGroundSkipRequest;
use App\Http\Requests\UndergroundIntroMutationRequest;
use App\Http\Requests\UndergroundPlaytestRequest;
use App\Http\Requests\UndergroundTrialFightRequest;
use App\Http\Requests\UndergroundTrialRunRequest;
use App\Http\Requests\UndergroundTrialSkipRequest;
use App\Http\Requests\UndergroundTrialStartRequest;
use App\Http\Requests\UpdateSecretaryLendingRequest;
use App\Http\Requests\UpdateUndergroundActiveLoadoutRequest;
use App\Http\Requests\UpdateUndergroundAiConfigurationRequest;
use App\Http\Requests\UpdateUndergroundAwakeningMessageRequest;
use App\Http\Requests\UpdateUndergroundAwakeningTechniqueRequest;
use App\Models\Secretary;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UndergroundIntroController extends Controller
{
    public function show(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->state($request->user()));
    }

    public function surfaceMap(Request $request, UndergroundSurfaceMapProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->forUser($request->user())]);
    }

    public function lendingCandidates(Request $request, SecretaryLendingService $service): JsonResponse
    {
        $request->validate(['after_id' => ['sometimes', 'integer', 'min:0']]);
        $secretaryId = Secretary::query()->where('user_id', $request->user()->id)->value('id');
        $page = $service->publicCandidatePage(
            $request->user(),
            $secretaryId === null ? null : (int) $secretaryId,
            $request->integer('after_id'),
        );

        return response()->json(['data' => $page]);
    }

    public function enter(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->enter(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function advance(
        AdvanceUndergroundIntroRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->advance(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('action')->value(),
        ));
    }

    public function tutorial(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->tutorial(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function nameShopkeeper(
        NameUndergroundShopkeeperRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        try {
            return $this->respond(fn (): array => $service->nameShopkeeper(
                $request->user(),
                $request->string('request_id')->value(),
                $request->string('name')->value(),
            ));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }

    public function scriptedLoss(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->scriptedLoss(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function completeRecollection(
        CompleteUndergroundRecollectionRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->completeRecollection(
            $request->user(),
            $request->string('request_id')->value(),
            $request->integer('chapter'),
        ));
    }

    public function contract(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->contract(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function growthPath(
        SelectUndergroundGrowthPathRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->selectGrowthPath(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('growth_path_key')->value(),
        ));
    }

    public function respec(
        RespecUndergroundProfileRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->respec(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('growth_path_key')->value(),
        ));
    }

    public function playtest(
        UndergroundPlaytestRequest $request,
        UndergroundPlaytestService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->fight(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('build_key')->value(),
            $request->string('enemy_key')->value(),
        ));
    }

    public function main(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->main($request->user()));
    }

    public function explore(
        UndergroundExploreRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        return $this->respond(function () use ($request, $service): array {
            $validated = $request->validated();
            $result = $service->explore(
                $request->user(),
                $request->string('request_id')->value(),
                array_key_exists('hunting_ground_key', $validated)
                    ? (string) $validated['hunting_ground_key']
                    : null,
                is_array($validated['borrowed_secretary_ids'] ?? null)
                    ? array_map('intval', $validated['borrowed_secretary_ids'])
                    : [],
            );

            return [
                ...$service->projectExplorationBattle($result['battle']),
                'daily_quest' => $result['daily_quest'],
            ];
        });
    }

    public function skipHuntingGround(
        UndergroundHuntingGroundSkipRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        return $this->respond(function () use ($request, $service): array {
            if ($request->has('execution_count')) {
                $result = $service->bulkSkipHuntingGround(
                    $request->user(),
                    $request->string('request_id')->value(),
                    $request->string('hunting_ground_key')->value(),
                    $request->integer('execution_count'),
                );

                return [
                    ...$service->projectSkipBatch($result['batch'], $result['duplicate']),
                    'daily_quest' => $result['daily_quest'],
                ];
            }
            $result = $service->skipHuntingGround(
                $request->user(),
                $request->string('request_id')->value(),
                $request->string('hunting_ground_key')->value(),
            );

            return [
                ...$service->projectSkipSettlement($result['settlement'], $result['duplicate']),
                'daily_quest' => $result['daily_quest'],
            ];
        });
    }

    public function skipTrial(
        UndergroundTrialSkipRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        return $this->respond(function () use ($request, $service): array {
            if ($request->has('execution_count')) {
                $result = $service->bulkSkipTrial(
                    $request->user(),
                    $request->string('request_id')->value(),
                    $request->string('trial_key')->value(),
                    $request->integer('execution_count'),
                );

                return [
                    ...$service->projectSkipBatch($result['batch'], $result['duplicate']),
                    'daily_quest' => $result['daily_quest'],
                ];
            }
            $result = $service->skipTrial(
                $request->user(),
                $request->string('request_id')->value(),
                $request->string('trial_key')->value(),
            );

            return [
                ...$service->projectSkipSettlement($result['settlement'], $result['duplicate']),
                'daily_quest' => $result['daily_quest'],
            ];
        });
    }

    public function updateLending(
        UpdateSecretaryLendingRequest $request,
        SecretaryLendingService $service,
        UndergroundIntroService $intro,
    ): JsonResponse {
        return $this->respond(function () use ($request, $service, $intro): array {
            $service->update(
                $request->user(),
                $request->boolean('is_lendable'),
            );

            return $intro->state($request->user());
        });
    }

    public function startTrial(
        UndergroundTrialStartRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        $validated = $request->validated();

        return $this->respond(fn (): array => $service->projectTrialRun(
            $service->startTrial(
                $request->user(),
                is_string($validated['trial_key'] ?? null) ? $validated['trial_key'] : 'trial_01',
            ),
        ));
    }

    public function fightTrial(
        UndergroundTrialFightRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        return $this->respond(function () use ($request, $service): array {
            $result = $service->fightTrial(
                $request->user(),
                $request->string('run_key')->value(),
                $request->string('request_id')->value(),
            );

            return [
                ...$service->projectTrialBattle($result['battle']),
                'daily_quest' => $result['daily_quest'],
            ];
        });
    }

    public function withdrawTrial(
        UndergroundTrialRunRequest $request,
        UndergroundRuntimeService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->projectTrialRun(
            $service->withdrawTrial($request->user(), $request->string('run_key')->value()),
        ));
    }

    public function restAtInn(
        UndergroundIntroMutationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->restAtInn(
            $request->user(),
            $request->string('request_id')->value(),
        ));
    }

    public function bankTransfer(
        UndergroundBankTransferRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        $validated = $request->validated();

        return $this->respond(fn (): array => $service->bankTransfer(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('action')->value(),
            array_key_exists('amount', $validated) && $validated['amount'] !== null
                ? (int) $validated['amount']
                : null,
        ));
    }

    public function allocateStp(
        AllocateUndergroundStpRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        /** @var array<string, int> $allocations */
        $allocations = $request->validated('allocations');

        return $this->respond(fn (): array => $service->allocateStp(
            $request->user(),
            $request->string('request_id')->value(),
            $allocations,
        ));
    }

    public function acquireSkill(
        AcquireUndergroundSkillRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->acquireSkillNode(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('node_key')->value(),
        ));
    }

    public function updateActiveLoadout(
        UpdateUndergroundActiveLoadoutRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        /** @var list<string|null> $slots */
        $slots = $request->validated('slots');

        return $this->respond(fn (): array => $service->updateActiveLoadout(
            $request->user(),
            $request->string('request_id')->value(),
            $slots,
        ));
    }

    public function updateAiConfiguration(
        UpdateUndergroundAiConfigurationRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        $rules = $request->input('rules');

        return $this->respond(fn (): array => $service->updateAiConfiguration(
            $request->user(),
            $request->string('request_id')->value(),
            is_array($rules) ? $rules : null,
        ));
    }

    public function updateAwakeningMessage(
        UpdateUndergroundAwakeningMessageRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        $message = $request->validated('message');

        return $this->respond(fn (): array => $service->updateAwakeningMessage(
            $request->user(),
            $request->string('request_id')->value(),
            is_string($message) ? $message : null,
        ));
    }

    public function updateAwakeningTechnique(
        UpdateUndergroundAwakeningTechniqueRequest $request,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->updateAwakeningTechnique(
            $request->user(),
            $request->string('request_id')->value(),
            $request->string('technique_key')->value(),
        ));
    }

    public function battles(Request $request, UndergroundIntroService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->battles($request->user()));
    }

    public function battle(
        Request $request,
        string $battleRequestId,
        UndergroundIntroService $service,
    ): JsonResponse {
        return $this->respond(fn (): array => $service->battle($request->user(), $battleRequestId));
    }

    public function playtestOptions(Request $request, UndergroundPlaytestService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->options($request->user()));
    }

    /** @param callable(): array<mixed> $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()]);
        } catch (UndergroundRuntimeException $exception) {
            $status = $exception->errorCode === 'underground_secretary_missing'
                || $exception->errorCode === 'underground_battle_not_found'
                ? 404
                : 409;

            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $status);
        }
    }
}
