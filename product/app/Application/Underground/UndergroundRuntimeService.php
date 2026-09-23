<?php

namespace App\Application\Underground;

use App\Application\DailyQuestService;
use App\Application\SecretaryImageRetentionService;
use App\Application\SecretaryProfilePresenter;
use App\Application\VisitorCodeAllocator;
use App\Domain\Underground\Area\UndergroundAreaCapacity;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundRandom;
use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Domain\Underground\Progression\UndergroundCombatProgression;
use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\SecretaryLendingSetting;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleLog;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
use App\Models\UndergroundSkipBatch;
use App\Models\UndergroundSkipSettlement;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class UndergroundRuntimeService
{
    public const MAX_BULK_SKIP_EXECUTIONS = 1_000;

    private const TRIAL_ONE_FIRST_CLEAR_STORY_TITLE = '●封印の解放';

    private const TRIAL_TWO_FIRST_CLEAR_STORY_TITLE = 'デュラハンの撃破と案内人';

    private const TRIAL_ONE_FIRST_CHALLENGE_INTRO = <<<'STORY'
　崩れかけた石壁の向こうに広がっていた不思議な空間。
　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。
　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。
STORY;

    private const TRIAL_TWO_FIRST_CHALLENGE_INTRO = <<<'STORY'
●試練2　黒曜石の魔窟
　あなたも薄々察しているでしょう。この辺りの黒い結晶。あれもまた輝石の一つです——光は放ちませんけどね？
　黒い輝石は浅層に見られる、不純物の多い輝石です。地上の一説には、そういった輝石にこのような逸話があります
　輝石自身が、その美しい輝きに心奪われ欲を抱いた時……その光を自らに吸収してしまい、黒く濁ってしまうと。
　私は、そうは思いたくありません。
　だって欲望が、人の業が、黒い輝石が罪深いと吐き捨てるなんて余りにも冷酷だとは思いませんか？
　同じく黒い黒曜石は、あんなにも透き通るような美しさを秘めていると言うのに。
　……さぁ、この先が次の封印の地。黒晶洞の最奥です。挑戦は止めませんとも。倒れたらまた、担いで運んであげますからね
STORY;

    private const TRIAL_ONE_ROUND_TWENTY_WARNING = '洞窟が崩れそうだ……';

    private const TRIAL_ONE_FIRST_CLEAR_STORY_BODY = <<<'STORY'
　ワイバーンの肉体が自らの魔力に耐え切れず、内から光を放ちながら崩壊していくその瞬間。
　秘書の中で何かが強く脈打った。ドクン、ドクンと、全身の細胞が歓喜に震え、肉体の輪郭が歪んでいく幻覚が見える。
　膝をつき、堕としてしまった武器を拾い上げたのは、あの案内人であった。
「大丈夫ですか？」
　秘書をゆっくりと立たせ、武器を返した案内人はふぅと大きなため息をついた。
「いくら狭くて飛び辛い有利な環境とはいえワイバーンまで倒すとは……無茶苦茶というか素晴らしいというべきなのか——」
　次の瞬間、頭上から重く大きな地響きが鳴り、辺りの空気が一変する。
「やはりですね。封印の地と呼ばれる……この辺りの魔物を倒しきったから、バリアが下がりました。つまりはもう封印している必要がなくなったということです」
　どういうことかと問えば、彼女は金色の瞳を細めて答えた。
「あなたがたの島の設備を置ける空間ができたということですよ。大したスペースはありませんが元は封印された地です。あらゆる災害から守られた聖地となるでしょう。ところで……あなたがあのネズ公を倒したときに手に入れた輝石、持ってますか？」
　秘書は輝石を取り出して、案内人に見せる。
「よろしい。とてもよろしい」
「あなたはこれからもっと深くの封印の地を解放していくのでしょう。私がそれの使い方を手解きしてあげましょう♪」

　そういうと、案内人は自分の輝石を取り出してみせた。
　それは桃色の、妖しく輝く楕円形の宝石であった。

「ただし、あなたがその力に溺れないという決意を見せてくれたらの話ですけれど、ね？」
STORY;

    private const TRIAL_TWO_FIRST_CLEAR_STORY_BODY = <<<'STORY'
　(秘書名)が倒したはずのデュラハンは突然、恐ろしい音を鳴り響かせながら立ち上がった。
　暴風がその鎧から溢れ出し、武器が手から離れ彼方へと飛ばされる。
『貴様さえ　キサマさえ生まれていなければ！！！』
　まるで全てを呪うかのような頭の『声』は、この洞窟の輝石をより黒く、黒く染め上げるかのようであった。
　そしてデュラハンは巨大な大剣を振り上げ、体勢を崩したあなたにそのままトドメの一撃を完遂する、はずだった。
「まったく、同感ね」
　目が眩むほどの雷鳴が突然視界を焼き焦がしたかと思えば、デュラハンの鎧は粉々に砕かれていた。
　黒い洞窟よりも遥かに黒い、吸い込まれるような黒い剣に走る、ローズピンクの迅る魔力。
　その眩さは自分が敵う相手ではないと、一瞬で思い知らされるほどであった。
「……」
　鎧を踏み抜き、砕いたかと思えば。こちらの方へと彼女は張り付いた笑顔でにっこりと小首をかしげた。

「いやいや、危なかったですが。お見事、お見事。新しい自分の戦い方にも慣れてきたようで」

「お陰様でこの封印の地も用済みです。つまりは必要がなくなったバリアが下がって——あなたの大好きなご主人様の島が使える領域はますます広がることでしょう」

　誰か問おうと、彼女の答えはいつも通り。
「私はただの、案内人ですよ」

「輝かしいはずの秩序も混沌も、理性も欲望も、人間に寄生しないと生きられないはずの自分の存在意義や名前すらも、何もかも見てられなくなって嫌になって、自分の世界の全部を海に沈めて無かったことにした。愚かな愚かな、案内人です」

「さぁ、帰って傷を癒しましょう。せっかくの暇つぶし相手に死なれては私が困りますから」
STORY;

    public function __construct(
        private UndergroundRuntimeCatalog $catalog,
        private AtomicUndergroundExplorationCombat $explorationCombat,
        private AtomicUndergroundPartyCombat $partyCombat,
        private UndergroundCombatProgression $progression,
        private UndergroundBattleSeed $battleSeed,
        private UndergroundAlphaV1PlayerCatalog $alphaV1Catalog,
        private UndergroundAlphaV1BattleProjector $alphaV1Projector,
        private UndergroundStarterEquipmentService $starterEquipment,
        private UndergroundEquipmentLoadoutResolver $equipmentLoadout,
        private UndergroundEquipmentDropService $equipmentDrops,
        private UndergroundAwakening $awakening,
        private BorrowedSecretarySnapshotFactory $borrowedSnapshots,
        private UndergroundLendingRewardService $lendingRewards,
        private UndergroundPartyBattleProjector $partyProjector,
        private SecretaryProfilePresenter $secretaryPresenter,
        private VisitorCodeAllocator $visitorCodes,
        private SecretaryImageRetentionService $imageRetention,
        private DailyQuestService $dailyQuests,
        private UndergroundBattleStatisticsProjector $statisticsProjector,
        private UndergroundBattleStorage $battleStorage,
    ) {}

    /**
     * @param  list<int>  $borrowedSecretaryIds
     * @return array{battle: UndergroundBattle, duplicate: bool, daily_quest: array<string, int|string|bool>}
     */
    public function explore(
        User $user,
        string $requestId,
        ?string $huntingGroundKey = null,
        array $borrowedSecretaryIds = [],
    ): array {
        return $this->runExplorationRequest($user, $requestId, $huntingGroundKey, $borrowedSecretaryIds);
    }

    /** @param list<int> $borrowedSecretaryIds
     * @return array{battle: UndergroundBattle, duplicate: bool, daily_quest: array<string,int|string|bool>}
     */
    public function challengeGuide(User $user, string $requestId, array $borrowedSecretaryIds = []): array
    {
        return $this->runExplorationRequest($user, $requestId, $this->alphaV1Catalog->guideDuel()['required_ground'], $borrowedSecretaryIds, true);
    }

    /** @param list<int> $borrowedSecretaryIds
     * @return array{battle:UndergroundBattle, duplicate:bool, daily_quest:array<string,int|string|bool>}
     */
    public function challengeOtherworld(User $user, string $requestId, string $stageKey, array $borrowedSecretaryIds = []): array
    {
        return $this->runExplorationRequest($user, $requestId, $stageKey, $borrowedSecretaryIds, otherworld: true);
    }

    /** @param list<int> $borrowedSecretaryIds
     * @return array{battle: UndergroundBattle, duplicate: bool, daily_quest: array<string,int|string|bool>}
     */
    private function runExplorationRequest(User $user, string $requestId, ?string $huntingGroundKey, array $borrowedSecretaryIds, bool $guideDuel = false, bool $otherworld = false): array
    {
        $this->assertRequestId($requestId);
        if (count($borrowedSecretaryIds) > 3
            || count($borrowedSecretaryIds) !== count(array_unique($borrowedSecretaryIds))) {
            throw new UndergroundRuntimeException(
                'underground_party_invalid',
                '借りる秘書は重複なしで3人まで選んでください。',
            );
        }
        foreach ($borrowedSecretaryIds as $secretaryId) {
            if ($secretaryId < 1) {
                throw new UndergroundRuntimeException('underground_party_invalid', 'PT編成を確認してください。');
            }
        }
        $huntingGroundKey ??= $this->alphaV1Catalog->explorationHuntingGroundKey();
        $huntingGround = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        if (($huntingGround['kind'] === 'otherworld') !== $otherworld) {
            throw new UndergroundRuntimeException('underground_otherworld_entry_required', '異世界の戦いの画面から出発してください。');
        }
        $fingerprintPayload = [
            'activity_type' => $guideDuel ? UndergroundBattle::ACTIVITY_GUIDE_DUEL : 'exploration',
            'guide_duel_identity' => $guideDuel ? $this->alphaV1Catalog->guideDuel()['identity'] : null,
            'activity_key' => $huntingGroundKey,
            'exploration_identity' => $this->alphaV1Catalog->explorationIdentity(),
            'content_identity' => $huntingGround['content_identity'],
        ];
        if (! $guideDuel) {
            unset($fingerprintPayload['guide_duel_identity']);
        }
        if ($otherworld) {
            $fingerprintPayload['otherworld'] = true;
        }
        if ($borrowedSecretaryIds !== []) {
            $fingerprintPayload['borrowed_secretary_ids'] = $borrowedSecretaryIds;
        }
        $fingerprint = $this->fingerprint($fingerprintPayload);

        /*
         * Borrowed source rows are prepared before the leader transaction.  A
         * reciprocal party (A borrows B while B borrows A) must never hold A's
         * leader rows while waiting for B's source rows.  The preparation
         * transaction also freezes the source data used by the battle and
         * releases all source locks before combat starts.
         */
        $attempt = 0;
        do {
            /** @var array{secretary_id:int, profile_id:int, combat_level:int, equipment_item_levels:array<string,int>}|null $leaderSyncInputs */
            $leaderSyncInputs = null;
            $preparedBorrowed = [];
            $hasExistingRequest = $borrowedSecretaryIds !== []
                && $this->explorationRequestExists($user, $requestId);
            if ($borrowedSecretaryIds !== [] && ! $hasExistingRequest) {
                $leaderSyncInputs = $this->partyLeaderSyncInputs($user);
                $preparedBorrowed = $this->prepareBorrowedPartySnapshots(
                    $user,
                    $requestId,
                    $leaderSyncInputs,
                    $borrowedSecretaryIds,
                );
            }

            try {
                return DB::transaction(function () use (
                    $user,
                    $requestId,
                    $fingerprint,
                    $huntingGroundKey,
                    $huntingGround,
                    $borrowedSecretaryIds,
                    $leaderSyncInputs,
                    $preparedBorrowed,
                    $guideDuel,
                    $otherworld,
                ): array {
                    $profile = $this->lockedProfileForUser($user);
                    $this->assertExplorationUnlocked($profile);
                    $this->assertHuntingGroundUnlocked($profile, $huntingGround);
                    $this->assertRequestNotUsedByIntro($profile, $requestId);
                    $duplicate = $this->duplicateBattle(
                        $profile,
                        $requestId,
                        $fingerprint,
                        $huntingGroundKey,
                    );
                    if ($duplicate instanceof UndergroundBattle) {
                        return [
                            'battle' => $duplicate,
                            'duplicate' => true,
                            'daily_quest' => $this->dailyQuests->currentStatus(
                                $user->id,
                                DailyQuestService::UNDERGROUND_BATTLES,
                            ),
                        ];
                    }
                    $this->assertSkillRebuildCompleted($profile);
                    if ($borrowedSecretaryIds !== array_column($profile->rental_party, 'secretary_id')) {
                        throw new UndergroundRuntimeException('underground_rental_party_changed', 'レンタル編成を確定してから出発してください。');
                    }
                    if ($borrowedSecretaryIds !== []) {
                        if ($leaderSyncInputs === null
                            || ! $this->partyLeaderSyncInputsMatch($profile, $leaderSyncInputs)) {
                            throw new UndergroundRuntimeException(
                                'underground_party_leader_changed',
                                'Leaderの戦闘状態が変わったため、PTを再準備します。',
                            );
                        }
                    }
                    if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                        throw new UndergroundRuntimeException(
                            'underground_trial_active',
                            '封印の地を継続するか、明示的に帰還してから通常探索を行ってください。',
                        );
                    }
                    if ($guideDuel) {
                        return [
                            'battle' => $this->resolveAndSettleGuideDuel($profile, $requestId, $fingerprint, $preparedBorrowed),
                            'duplicate' => false,
                            'daily_quest' => $this->dailyQuests->currentStatus($user->id, DailyQuestService::UNDERGROUND_BATTLES),
                        ];
                    }
                    if ($otherworld) {
                        if ($profile->distorted_stone_balance < 1) {
                            throw new UndergroundRuntimeException('underground_otherworld_stone_required', '黒竜バハムルへの挑戦には歪んだ輝石が必要です。');
                        }
                        if ($this->equipmentDrops->remainingVaultCapacity($profile, 'resonance') < 1
                            || $this->equipmentDrops->remainingVaultCapacity($profile) < 1) {
                            throw new UndergroundRuntimeException('underground_vault_full', '報酬を受け取るため、装備と共鳴結晶の保管庫にそれぞれ空きを作ってください。');
                        }
                    } else {
                        $this->assertCooldownElapsed($profile);
                    }
                    $keyBalanceBefore = $profile->shining_kingdom_key_balance;
                    $this->consumeExplorationEntryKey($profile, $huntingGround);
                    $seed = $this->battleSeed->forRequest(
                        $profile->id,
                        $requestId,
                        $huntingGround['content_identity'],
                    );
                    $random = new UndergroundRandom($seed);
                    $encounterKeys = $this->drawExplorationEncounterKeys(
                        $huntingGroundKey,
                        1 + count($borrowedSecretaryIds),
                        $random,
                    );
                    $encounterKey = $encounterKeys[0];
                    if ($huntingGround['kind'] === 'vault' && count($encounterKeys) > 1) {
                        $encounterKey = $encounterKeys[$random->integer(
                            'runtime:vault-reward-source',
                            0,
                            count($encounterKeys) - 1,
                        )];
                    }

                    $battle = $borrowedSecretaryIds === [] && ! $otherworld
                            ? $this->resolveAndSettleExplorationBattle(
                                $profile,
                                $requestId,
                                $fingerprint,
                                $huntingGroundKey,
                                $encounterKey,
                                $seed,
                                $keyBalanceBefore,
                            )
                            : $this->resolveAndSettlePartyExplorationBattle(
                                $profile,
                                $requestId,
                                $fingerprint,
                                $huntingGroundKey,
                                $encounterKeys,
                                $encounterKey,
                                $seed,
                                $keyBalanceBefore,
                                $preparedBorrowed,
                            );

                    return [
                        'battle' => $battle,
                        'duplicate' => false,
                        'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                            $user->id,
                            1,
                            'battle:'.$battle->id,
                        ),
                    ];
                }, 3);
            } catch (UndergroundRuntimeException $exception) {
                if ($exception->errorCode !== 'underground_party_leader_changed' || ++$attempt >= 3) {
                    throw $exception;
                }
            }
        } while (true);
    }

    /** @return array{settlement: UndergroundSkipSettlement, duplicate: bool, daily_quest: array<string, int|string|bool>} */
    public function skipHuntingGround(User $user, string $requestId, string $huntingGroundKey): array
    {
        $this->assertRequestId($requestId);
        $huntingGround = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        $this->assertSkippableHuntingGround($huntingGround);
        $policy = $this->catalog->skipPolicy('hunting_ground');
        $fingerprint = $this->fingerprint([
            'operation' => 'skip',
            'skip_identity' => $policy['identity'],
            'content_type' => 'hunting_ground',
            'content_key' => $huntingGroundKey,
            'content_identity' => $huntingGround['content_identity'],
        ]);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $huntingGroundKey,
            $huntingGround,
            $policy,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->assertHuntingGroundUnlocked($profile, $huntingGround);
            $duplicate = $this->duplicateSkipSettlement($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundSkipSettlement) {
                return [
                    'settlement' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus(
                        $user->id,
                        DailyQuestService::UNDERGROUND_BATTLES,
                    ),
                ];
            }
            $this->assertSkipRequestIdentityAvailable($profile, $requestId);
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '封印の地から帰還してから狩場skipを使用してください。',
                );
            }
            $progress = $this->lockedContentProgress($profile, 'hunting_ground', $huntingGroundKey);
            $this->assertSkipUnlocked($progress, $policy['actual_clears_required']);
            $balance = $this->lockedSkipTicketBalance($user);
            $this->assertSkipTicketBalance($balance, $policy['ticket_cost']);
            $keyBalanceBefore = $profile->shining_kingdom_key_balance;
            $this->consumeExplorationEntryKey($profile, $huntingGround);
            $seed = $this->battleSeed->forRequest(
                $profile->id,
                $requestId,
                $policy['identity'].':'.$huntingGround['content_identity'],
            );
            $random = new UndergroundRandom($seed);
            $encounterKey = $this->drawExplorationEncounterKeys($huntingGroundKey, 1, $random)[0];
            $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey, $huntingGroundKey);
            $victoryReward = $this->explorationVictoryReward($huntingGround, $encounterKey, $encounter, $seed, true);
            $reward = $this->applyRepeatableReward($profile, $encounter['xp'], $victoryReward['shards']);
            $profile->shining_kingdom_key_balance += $victoryReward['keys'];
            $profile->distorted_stone_balance += $victoryReward['distorted_stones'];
            $profile->save();
            $settledAt = Carbon::now();
            $settlement = UndergroundSkipSettlement::query()->create([
                'underground_profile_id' => $profile->id,
                'user_id' => $user->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'skip_identity' => $policy['identity'],
                'content_type' => 'hunting_ground',
                'content_key' => $huntingGroundKey,
                'content_identity' => $huntingGround['content_identity'],
                'ticket_cost' => $policy['ticket_cost'],
                'xp_awarded' => $encounter['xp'],
                'shard_awarded' => $victoryReward['shards'],
                'combat_level_before' => $reward['combat_level_before'],
                'combat_level_after' => $reward['combat_level_after'],
                'combat_xp_before' => $reward['combat_xp_before'],
                'combat_xp_after' => $reward['combat_xp_after'],
                'shard_balance_before' => $reward['shard_balance_before'],
                'shard_balance_after' => $reward['shard_balance_after'],
                'private_seed' => $seed,
                'reward_snapshot' => [
                    'encounters' => [[
                        'index' => 1,
                        'key' => $encounterKey,
                        'xp' => $encounter['xp'],
                        'shards' => $victoryReward['shards'],
                    ]],
                    'stp_awarded' => $reward['stp_awarded'],
                    'shining_kingdom_key' => [
                        'balance_before' => $keyBalanceBefore,
                        'entry_cost' => $huntingGround['entry_key_cost'],
                        'awarded' => $victoryReward['keys'],
                        'balance_after' => $profile->shining_kingdom_key_balance,
                    ],
                    'treasure' => $victoryReward['treasure'],
                    'distorted_stones' => $victoryReward['distorted_stones'],
                    'drops' => [['status' => 'pending']],
                ],
                'settled_at' => $settledAt,
            ]);
            $this->consumeSkipTickets($balance, $settlement, $policy['ticket_cost']);
            $snapshot = $settlement->reward_snapshot;
            $dropEncounter = $encounter;
            if (is_string($huntingGround['forced_drop_profile'] ?? null)) {
                $dropEncounter['drop_profile'] = $huntingGround['forced_drop_profile'];
            }
            $snapshot['drops'] = [$this->equipmentDrops->settleSkippedVictory(
                $profile,
                $settlement,
                $huntingGround['drop_tier_key'],
                $dropEncounter,
                $seed,
                1,
            )];
            $settlement->reward_snapshot = $snapshot;
            $settlement->save();
            $progress->total_clear_count++;
            $progress->save();

            return [
                'settlement' => $settlement->refresh(),
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                    $user->id,
                    1,
                    'skip-settlement:'.$settlement->id,
                ),
            ];
        }, 3);
    }

    /** @return array{settlement: UndergroundSkipSettlement, duplicate: bool, daily_quest: array<string, int|string|bool>} */
    public function skipTrial(User $user, string $requestId, string $trialKey): array
    {
        $this->assertRequestId($requestId);
        $trial = $this->catalog->trial($trialKey);
        $policy = $this->catalog->skipPolicy('trial');
        $fingerprint = $this->fingerprint([
            'operation' => 'skip',
            'skip_identity' => $policy['identity'],
            'content_type' => 'trial',
            'content_key' => $trialKey,
            'content_identity' => $trial['content_identity'],
        ]);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $trialKey,
            $trial,
            $policy,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->reconcileTrialProgresses($profile);
            $trialProgress = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $trialKey)
                ->lockForUpdate()
                ->first();
            if (! $trialProgress instanceof UndergroundTrialProgress) {
                throw new UndergroundRuntimeException('underground_trial_locked', 'この封印の地はまだ解禁されていません。');
            }
            $duplicate = $this->duplicateSkipSettlement($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundSkipSettlement) {
                return [
                    'settlement' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus(
                        $user->id,
                        DailyQuestService::UNDERGROUND_BATTLES,
                    ),
                ];
            }
            $this->assertSkipRequestIdentityAvailable($profile, $requestId);
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '進行中の封印の地から帰還してから周回skipを使用してください。',
                );
            }
            $progress = $this->lockedContentProgress($profile, 'trial', $trialKey);
            $this->assertSkipUnlocked($progress, $policy['actual_clears_required']);
            $balance = $this->lockedSkipTicketBalance($user);
            $this->assertSkipTicketBalance($balance, $policy['ticket_cost']);
            $seed = $this->battleSeed->forRequest(
                $profile->id,
                $requestId,
                $policy['identity'].':'.$trial['content_identity'],
            );
            $xp = array_sum(array_column($trial['rewards'], 'xp'));
            $shards = array_sum(array_column($trial['rewards'], 'shards'));
            $reward = $this->applyRepeatableReward($profile, $xp, $shards);
            $profile->save();
            $settledAt = Carbon::now();
            $encounters = [];
            foreach ($trial['encounters'] as $index => $encounterKey) {
                $entry = $trial['rewards'][$index];
                $encounters[] = [
                    'index' => $index + 1,
                    'key' => $encounterKey,
                    'xp' => $entry['xp'],
                    'shards' => $entry['shards'],
                ];
            }
            $settlement = UndergroundSkipSettlement::query()->create([
                'underground_profile_id' => $profile->id,
                'user_id' => $user->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'skip_identity' => $policy['identity'],
                'content_type' => 'trial',
                'content_key' => $trialKey,
                'content_identity' => $trial['content_identity'],
                'ticket_cost' => $policy['ticket_cost'],
                'xp_awarded' => $xp,
                'shard_awarded' => $shards,
                'combat_level_before' => $reward['combat_level_before'],
                'combat_level_after' => $reward['combat_level_after'],
                'combat_xp_before' => $reward['combat_xp_before'],
                'combat_xp_after' => $reward['combat_xp_after'],
                'shard_balance_before' => $reward['shard_balance_before'],
                'shard_balance_after' => $reward['shard_balance_after'],
                'private_seed' => $seed,
                'reward_snapshot' => [
                    'encounters' => $encounters,
                    'stp_awarded' => $reward['stp_awarded'],
                    'drops' => [],
                ],
                'settled_at' => $settledAt,
            ]);
            $this->consumeSkipTickets($balance, $settlement, $policy['ticket_cost']);
            $dropTierKey = $trial['drop_tier_key'];
            $drops = [];
            if (is_string($dropTierKey)) {
                foreach ($trial['rewards'] as $index => $entry) {
                    $rewardIndex = $index + 1;
                    $rewardSeed = $this->battleSeed->forRequest(
                        $profile->id,
                        $requestId,
                        $policy['identity'].':'.$trial['content_identity'].':reward:'.$rewardIndex,
                    );
                    $drops[] = $this->equipmentDrops->settleSkippedVictory(
                        $profile,
                        $settlement,
                        $dropTierKey,
                        $entry,
                        $rewardSeed,
                        $rewardIndex,
                    );
                }
            }
            $snapshot = $settlement->reward_snapshot;
            $snapshot['drops'] = $drops;
            $settlement->reward_snapshot = $snapshot;
            $settlement->save();
            $progress->total_clear_count++;
            $progress->save();

            return [
                'settlement' => $settlement->refresh(),
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                    $user->id,
                    count($trial['encounters']),
                    'skip-settlement:'.$settlement->id,
                ),
            ];
        }, 3);
    }

    /** @return array{batch: UndergroundSkipBatch, duplicate: bool, daily_quest: array<string, int|string|bool>} */
    public function bulkSkipHuntingGround(
        User $user,
        string $requestId,
        string $huntingGroundKey,
        int $executionCount,
    ): array {
        $this->assertRequestId($requestId);
        $this->assertBulkSkipExecutionCount($executionCount);
        $huntingGround = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        $this->assertSkippableHuntingGround($huntingGround);
        $policy = $this->catalog->skipPolicy('hunting_ground');
        $fingerprint = $this->fingerprint([
            'operation' => 'bulk_skip',
            'skip_identity' => $policy['identity'],
            'content_type' => 'hunting_ground',
            'content_key' => $huntingGroundKey,
            'content_identity' => $huntingGround['content_identity'],
            'execution_count' => $executionCount,
        ]);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $huntingGroundKey,
            $huntingGround,
            $policy,
            $executionCount,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->assertHuntingGroundUnlocked($profile, $huntingGround);
            $duplicate = $this->duplicateSkipBatch($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundSkipBatch) {
                return [
                    'batch' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus(
                        $user->id,
                        DailyQuestService::UNDERGROUND_BATTLES,
                    ),
                ];
            }
            $this->assertSkipRequestIdentityAvailable($profile, $requestId);
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '封印の地から帰還してから狩場skipを使用してください。',
                );
            }
            $progress = $this->lockedContentProgress($profile, 'hunting_ground', $huntingGroundKey);
            $this->assertSkipUnlocked($progress, $policy['actual_clears_required']);
            $ticketCost = $this->bulkSkipTicketCost($policy['ticket_cost'], $executionCount);
            $balance = $this->lockedSkipTicketBalance($user);
            $this->assertSkipTicketBalance($balance, $ticketCost);
            $keyBalanceBefore = $profile->shining_kingdom_key_balance;
            $this->consumeExplorationEntryKey($profile, $huntingGround, $executionCount);

            $levelBefore = $profile->combat_level;
            $xpBefore = $profile->combat_xp;
            $shardsBefore = $profile->shard_balance;
            $xpAwarded = 0;
            $shardsAwarded = 0;
            $stpAwarded = 0;
            $encounterCounts = [];
            $encounterSnapshots = [];
            $executions = [];
            for ($execution = 1; $execution <= $executionCount; $execution++) {
                $seed = $this->battleSeed->forRequest(
                    $profile->id,
                    $requestId,
                    $policy['identity'].':'.$huntingGround['content_identity'].':bulk-execution:'.$execution,
                );
                $random = new UndergroundRandom($seed);
                $encounterKey = $this->drawExplorationEncounterKeys($huntingGroundKey, 1, $random)[0];
                $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey, $huntingGroundKey);
                $victoryReward = $this->explorationVictoryReward($huntingGround, $encounterKey, $encounter, $seed, true);
                $reward = $this->applyRepeatableReward($profile, $encounter['xp'], $victoryReward['shards']);
                $profile->shining_kingdom_key_balance += $victoryReward['keys'];
                $profile->distorted_stone_balance += $victoryReward['distorted_stones'];
                $xpAwarded += $encounter['xp'];
                $shardsAwarded += $victoryReward['shards'];
                $stpAwarded += $reward['stp_awarded'];
                $encounterCounts[$encounterKey] = ($encounterCounts[$encounterKey] ?? 0) + 1;
                $encounterSnapshots[] = [
                    'index' => $execution,
                    'key' => $encounterKey,
                    'xp' => $encounter['xp'],
                    'shards' => $victoryReward['shards'],
                    'keys' => $victoryReward['keys'],
                    'treasure' => $victoryReward['treasure'],
                    'distorted_stones' => $victoryReward['distorted_stones'],
                ];
                $dropEncounter = $encounter;
                if (is_string($huntingGround['forced_drop_profile'] ?? null)) {
                    $dropEncounter['drop_profile'] = $huntingGround['forced_drop_profile'];
                }
                $executions[] = [$dropEncounter, $seed];
            }
            $profile->save();
            $settledAt = Carbon::now();
            $batch = UndergroundSkipBatch::query()->create([
                'underground_profile_id' => $profile->id,
                'user_id' => $user->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'skip_identity' => $policy['identity'],
                'content_type' => 'hunting_ground',
                'content_key' => $huntingGroundKey,
                'content_identity' => $huntingGround['content_identity'],
                'execution_count' => $executionCount,
                'ticket_cost' => $ticketCost,
                'xp_awarded' => $xpAwarded,
                'shard_awarded' => $shardsAwarded,
                'combat_level_before' => $levelBefore,
                'combat_level_after' => $profile->combat_level,
                'combat_xp_before' => $xpBefore,
                'combat_xp_after' => $profile->combat_xp,
                'shard_balance_before' => $shardsBefore,
                'shard_balance_after' => $profile->shard_balance,
                'reward_snapshot' => ['drops' => [['status' => 'pending']]],
                'settled_at' => $settledAt,
            ]);
            $ticketBalanceAfter = $this->consumeBulkSkipTickets($balance, $batch, $ticketCost);
            $drops = [];
            $equipmentGranted = 0;
            $vaultFull = 0;
            $remainingVaultSlots = $this->equipmentDrops->remainingVaultCapacity($profile);
            foreach ($executions as $index => [$encounter, $seed]) {
                $drop = $this->equipmentDrops->settleBulkSkippedVictory(
                    $profile,
                    $batch,
                    $huntingGround['drop_tier_key'],
                    $encounter,
                    $seed,
                    $index + 1,
                    $remainingVaultSlots > 0,
                );
                if ($drop['status'] === 'granted') {
                    $equipmentGranted++;
                    $remainingVaultSlots--;
                    $drops[] = $drop;
                } elseif ($drop['status'] === 'vault_full') {
                    $vaultFull++;
                }
            }
            $batch->reward_snapshot = [
                'encounters' => $encounterSnapshots,
                'encounter_counts' => $encounterCounts,
                'stp_awarded' => $stpAwarded,
                'equipment_granted_count' => $equipmentGranted,
                'vault_full_count' => $vaultFull,
                'drops' => $drops,
                'ticket_balance_after' => $ticketBalanceAfter,
                'shining_kingdom_key' => [
                    'balance_before' => $keyBalanceBefore,
                    'entry_cost_total' => $huntingGround['entry_key_cost'] * $executionCount,
                    'balance_after' => $profile->shining_kingdom_key_balance,
                ],
            ];
            $batch->save();
            $progress->total_clear_count += $executionCount;
            $progress->save();

            return [
                'batch' => $batch->refresh(),
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                    $user->id,
                    $executionCount,
                    'skip-batch:'.$batch->id,
                ),
            ];
        }, 3);
    }

    /** @return array{batch: UndergroundSkipBatch, duplicate: bool, daily_quest: array<string, int|string|bool>} */
    public function bulkSkipTrial(
        User $user,
        string $requestId,
        string $trialKey,
        int $executionCount,
    ): array {
        $this->assertRequestId($requestId);
        $this->assertBulkSkipExecutionCount($executionCount);
        $trial = $this->catalog->trial($trialKey);
        $policy = $this->catalog->skipPolicy('trial');
        $fingerprint = $this->fingerprint([
            'operation' => 'bulk_skip',
            'skip_identity' => $policy['identity'],
            'content_type' => 'trial',
            'content_key' => $trialKey,
            'content_identity' => $trial['content_identity'],
            'execution_count' => $executionCount,
        ]);

        return DB::transaction(function () use (
            $user,
            $requestId,
            $trialKey,
            $trial,
            $policy,
            $executionCount,
            $fingerprint,
        ): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->reconcileTrialProgresses($profile);
            $trialProgress = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $trialKey)
                ->lockForUpdate()
                ->first();
            if (! $trialProgress instanceof UndergroundTrialProgress) {
                throw new UndergroundRuntimeException('underground_trial_locked', 'この封印の地はまだ解禁されていません。');
            }
            $duplicate = $this->duplicateSkipBatch($profile, $requestId, $fingerprint);
            if ($duplicate instanceof UndergroundSkipBatch) {
                return [
                    'batch' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus(
                        $user->id,
                        DailyQuestService::UNDERGROUND_BATTLES,
                    ),
                ];
            }
            $this->assertSkipRequestIdentityAvailable($profile, $requestId);
            if ($this->lockedActiveTrialRun($profile) instanceof UndergroundTrialRun) {
                throw new UndergroundRuntimeException(
                    'underground_trial_active',
                    '進行中の封印の地から帰還してから周回skipを使用してください。',
                );
            }
            $progress = $this->lockedContentProgress($profile, 'trial', $trialKey);
            $this->assertSkipUnlocked($progress, $policy['actual_clears_required']);
            $ticketCost = $this->bulkSkipTicketCost($policy['ticket_cost'], $executionCount);
            $balance = $this->lockedSkipTicketBalance($user);
            $this->assertSkipTicketBalance($balance, $ticketCost);

            $levelBefore = $profile->combat_level;
            $xpBefore = $profile->combat_xp;
            $shardsBefore = $profile->shard_balance;
            $xpPerRun = array_sum(array_column($trial['rewards'], 'xp'));
            $shardsPerRun = array_sum(array_column($trial['rewards'], 'shards'));
            $stpAwarded = 0;
            for ($execution = 1; $execution <= $executionCount; $execution++) {
                $reward = $this->applyRepeatableReward($profile, $xpPerRun, $shardsPerRun);
                $stpAwarded += $reward['stp_awarded'];
            }
            $profile->save();
            $settledAt = Carbon::now();
            $batch = UndergroundSkipBatch::query()->create([
                'underground_profile_id' => $profile->id,
                'user_id' => $user->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'skip_identity' => $policy['identity'],
                'content_type' => 'trial',
                'content_key' => $trialKey,
                'content_identity' => $trial['content_identity'],
                'execution_count' => $executionCount,
                'ticket_cost' => $ticketCost,
                'xp_awarded' => $xpPerRun * $executionCount,
                'shard_awarded' => $shardsPerRun * $executionCount,
                'combat_level_before' => $levelBefore,
                'combat_level_after' => $profile->combat_level,
                'combat_xp_before' => $xpBefore,
                'combat_xp_after' => $profile->combat_xp,
                'shard_balance_before' => $shardsBefore,
                'shard_balance_after' => $profile->shard_balance,
                'reward_snapshot' => ['drops' => [['status' => 'pending']]],
                'settled_at' => $settledAt,
            ]);
            $ticketBalanceAfter = $this->consumeBulkSkipTickets($balance, $batch, $ticketCost);
            $dropTierKey = $trial['drop_tier_key'];
            $drops = [];
            $equipmentGranted = 0;
            $vaultFull = 0;
            if (is_string($dropTierKey)) {
                $remainingVaultSlots = $this->equipmentDrops->remainingVaultCapacity($profile);
                $rewardsPerRun = count($trial['rewards']);
                for ($execution = 1; $execution <= $executionCount; $execution++) {
                    foreach ($trial['rewards'] as $index => $entry) {
                        $rewardIndex = (($execution - 1) * $rewardsPerRun) + $index + 1;
                        $rewardSeed = $this->battleSeed->forRequest(
                            $profile->id,
                            $requestId,
                            $policy['identity'].':'.$trial['content_identity'].':bulk-reward:'.$rewardIndex,
                        );
                        $drop = $this->equipmentDrops->settleBulkSkippedVictory(
                            $profile,
                            $batch,
                            $dropTierKey,
                            $entry,
                            $rewardSeed,
                            $rewardIndex,
                            $remainingVaultSlots > 0,
                        );
                        if ($drop['status'] === 'granted') {
                            $equipmentGranted++;
                            $remainingVaultSlots--;
                            $drops[] = $drop;
                        } elseif ($drop['status'] === 'vault_full') {
                            $vaultFull++;
                        }
                    }
                }
            }
            $batch->reward_snapshot = [
                'stp_awarded' => $stpAwarded,
                'equipment_granted_count' => $equipmentGranted,
                'vault_full_count' => $vaultFull,
                'drops' => $drops,
                'ticket_balance_after' => $ticketBalanceAfter,
            ];
            $batch->save();
            $progress->total_clear_count += $executionCount;
            $progress->save();

            return [
                'batch' => $batch->refresh(),
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                    $user->id,
                    count($trial['encounters']) * $executionCount,
                    'skip-batch:'.$batch->id,
                ),
            ];
        }, 3);
    }

    public function startTrial(User $user, string $trialKey): UndergroundTrialRun
    {
        $trial = $this->catalog->trial($trialKey);

        return DB::transaction(function () use ($user, $trialKey, $trial): UndergroundTrialRun {
            $profile = $this->lockedProfileForUser($user);
            $this->assertExplorationUnlocked($profile);
            $this->reconcileTrialProgresses($profile);
            $progress = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $trialKey)
                ->lockForUpdate()
                ->first();
            if (! $progress instanceof UndergroundTrialProgress) {
                throw new UndergroundRuntimeException(
                    'underground_trial_locked',
                    'この封印の地はまだ解禁されていません。',
                );
            }

            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($run instanceof UndergroundTrialRun && $run->status === UndergroundTrialRun::STATUS_ACTIVE) {
                if ($run->trial_key !== $trialKey) {
                    throw new UndergroundRuntimeException(
                        'underground_trial_active',
                        '別の封印の地が進行中です。継続するか明示的に帰還してください。',
                    );
                }

                return $this->reconcileActiveTrialContent($run, $trial['content_identity']);
            }

            $now = Carbon::now();
            if (! $run instanceof UndergroundTrialRun) {
                $run = new UndergroundTrialRun;
                $run->underground_profile_id = $profile->id;
            }
            $run->run_key = (string) Str::uuid();
            $run->trial_key = $trialKey;
            $run->trial_content_identity = $trial['content_identity'];
            $run->next_battle_index = 1;
            $run->status = UndergroundTrialRun::STATUS_ACTIVE;
            $run->started_at = $now;
            $run->ended_at = null;
            $run->save();

            return $run->refresh();
        }, 3);
    }

    /** @return array{battle: UndergroundBattle, duplicate: bool, daily_quest: array<string, int|string|bool>} */
    public function fightTrial(User $user, string $runKey, string $requestId): array
    {
        $this->assertRequestId($runKey);
        $this->assertRequestId($requestId);
        $fingerprint = $this->trialFingerprint([
            'activity_type' => 'trial',
            'run_key' => $runKey,
        ]);

        return DB::transaction(function () use ($user, $runKey, $requestId, $fingerprint): array {
            $profile = $this->lockedProfileForUser($user);
            $this->assertRequestNotUsedByIntro($profile, $requestId);
            $duplicate = $this->duplicateTrialBattle($profile, $requestId, $runKey);
            if ($duplicate instanceof UndergroundBattle) {
                return [
                    'battle' => $duplicate,
                    'duplicate' => true,
                    'daily_quest' => $this->dailyQuests->currentStatus(
                        $user->id,
                        DailyQuestService::UNDERGROUND_BATTLES,
                    ),
                ];
            }

            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if (! $run instanceof UndergroundTrialRun
                || $run->status !== UndergroundTrialRun::STATUS_ACTIVE
                || $run->run_key !== $runKey) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_stale',
                    '封印の地の進行状態が更新されています。',
                );
            }
            $trial = $this->catalog->trial($run->trial_key);
            $run = $this->reconcileActiveTrialContent($run, $trial['content_identity']);
            $this->assertSkillRebuildCompleted($profile);
            $this->assertCooldownElapsed($profile);
            $battleIndex = $run->next_battle_index;
            $encounterKey = $trial['encounters'][$battleIndex - 1] ?? null;
            if (! is_string($encounterKey)) {
                throw new UndergroundRuntimeException(
                    'underground_trial_progress_invalid',
                    '封印の地の進行状態を解決できません。',
                );
            }
            $seed = $this->battleSeed->forRequest($profile->id, $requestId, $trial['content_identity']);

            $battle = $this->resolveAndSettleTrialBattle(
                $profile,
                $requestId,
                $fingerprint,
                $trial,
                $encounterKey,
                $seed,
                $run,
                $battleIndex,
                $battleIndex === count($trial['encounters']),
            );

            return [
                'battle' => $battle,
                'duplicate' => false,
                'daily_quest' => $this->dailyQuests->recordUndergroundBattles(
                    $user->id,
                    1,
                    'battle:'.$battle->id,
                ),
            ];
        }, 3);
    }

    public function withdrawTrial(User $user, string $runKey): UndergroundTrialRun
    {
        $this->assertRequestId($runKey);

        return DB::transaction(function () use ($user, $runKey): UndergroundTrialRun {
            $profile = $this->lockedProfileForUser($user);
            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if (! $run instanceof UndergroundTrialRun || $run->run_key !== $runKey) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_stale',
                    '封印の地の進行状態が更新されています。',
                );
            }
            if ($run->status === UndergroundTrialRun::STATUS_WITHDRAWN) {
                return $run;
            }
            if ($run->status !== UndergroundTrialRun::STATUS_ACTIVE) {
                throw new UndergroundRuntimeException(
                    'underground_trial_run_finished',
                    'この封印の地の挑戦はすでに終了しています。',
                );
            }
            $trial = $this->catalog->trial($run->trial_key);
            $run = $this->reconcileActiveTrialContent($run, $trial['content_identity']);

            $run->status = UndergroundTrialRun::STATUS_WITHDRAWN;
            $run->next_battle_index = 1;
            $run->ended_at = Carbon::now();
            $run->save();

            return $run->refresh();
        }, 3);
    }

    public function activeTrial(User $user): ?UndergroundTrialRun
    {
        return DB::transaction(function () use ($user): ?UndergroundTrialRun {
            $secretary = Secretary::query()
                ->where('user_id', $user->id)
                ->first();
            if (! $secretary instanceof Secretary) {
                return null;
            }
            $profile = UndergroundProfile::query()
                ->where('secretary_id', $secretary->id)
                ->lockForUpdate()
                ->first();
            if (! $profile instanceof UndergroundProfile) {
                return null;
            }

            return $this->lockedActiveTrialRun($profile);
        }, 3);
    }

    /** @return Collection<int, UndergroundBattle> */
    public function recentBattles(User $user, int $limit = 20): Collection
    {
        if ($limit < 1 || $limit > 100) {
            throw new UndergroundRuntimeException(
                'underground_history_limit_invalid',
                '戦闘履歴の取得件数を確認してください。',
            );
        }
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        $profile = $secretary instanceof Secretary
            ? UndergroundProfile::query()->where('secretary_id', $secretary->id)->first()
            : null;
        if (! $profile instanceof UndergroundProfile) {
            return new Collection;
        }

        return UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function pruneExpiredBattleLogs(): int
    {
        return $this->pruneExpiredBattleData()['logs_deleted'];
    }

    /**
     * @return array{
     *   logs_deleted:int,image_references_deleted:int,batches:int,stopped_by:string,
     *   logs_before:int,logs_after:int,oldest_log_before:string|null,oldest_log_after:string|null,
     *   image_references_before:int,image_references_after:int,oldest_image_before:string|null,oldest_image_after:string|null
     * }
     */
    public function pruneExpiredBattleData(
        int $batchSize = 1000,
        int $maxBatches = 20,
        int $maxSeconds = 30,
    ): array {
        if ($batchSize < 1 || $batchSize > 10_000
            || $maxBatches < 1 || $maxBatches > 1_000
            || $maxSeconds < 1 || $maxSeconds > 3_600) {
            throw new \InvalidArgumentException('Underground cleanup boundary is invalid.');
        }
        $now = Carbon::now();
        $logBefore = $this->expiredBattleLogBacklog($now);
        $imageBefore = $this->imageRetention->expiredBacklog($now);
        $logsDeleted = 0;
        $imagesDeleted = 0;
        $batches = 0;
        $stoppedBy = 'complete';
        $started = microtime(true);

        while ($batches < $maxBatches) {
            if (microtime(true) - $started >= $maxSeconds) {
                $stoppedBy = 'time_limit';
                break;
            }
            $ids = UndergroundBattleLog::query()
                ->where('expires_at', '<=', $now)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');
            $deleted = $ids->isEmpty()
                ? 0
                : UndergroundBattleLog::query()
                    ->whereIn('id', $ids->all())
                    ->where('expires_at', '<=', $now)
                    ->delete();
            $imageDeleted = $this->imageRetention->pruneExpired($batchSize, $now);
            $logsDeleted += $deleted;
            $imagesDeleted += $imageDeleted;
            $batches++;
            if ($deleted === 0 && $imageDeleted === 0) {
                break;
            }
        }
        if ($batches >= $maxBatches
            && (UndergroundBattleLog::query()->where('expires_at', '<=', $now)->exists()
                || $this->imageRetention->expiredBacklog($now)['count'] > 0)) {
            $stoppedBy = 'batch_limit';
        }
        $logAfter = $this->expiredBattleLogBacklog($now);
        $imageAfter = $this->imageRetention->expiredBacklog($now);

        return [
            'logs_deleted' => $logsDeleted,
            'image_references_deleted' => $imagesDeleted,
            'batches' => $batches,
            'stopped_by' => $stoppedBy,
            'logs_before' => $logBefore['count'],
            'logs_after' => $logAfter['count'],
            'oldest_log_before' => $logBefore['oldest_expires_at'],
            'oldest_log_after' => $logAfter['oldest_expires_at'],
            'image_references_before' => $imageBefore['count'],
            'image_references_after' => $imageAfter['count'],
            'oldest_image_before' => $imageBefore['oldest_expires_at'],
            'oldest_image_after' => $imageAfter['oldest_expires_at'],
        ];
    }

    /** @return array{count:int,oldest_expires_at:string|null} */
    private function expiredBattleLogBacklog(Carbon $now): array
    {
        $query = UndergroundBattleLog::query()->where('expires_at', '<=', $now);
        $oldest = (clone $query)->min('expires_at');

        return [
            'count' => (clone $query)->count(),
            'oldest_expires_at' => is_string($oldest) ? $oldest : null,
        ];
    }

    /** @return array<string, mixed> */
    public function projectExplorationBattle(UndergroundBattle $battle, bool $withRounds = true): array
    {
        return $this->projectAlphaV1Battle($battle, UndergroundBattle::ACTIVITY_EXPLORATION, $withRounds);
    }

    /** @return array<string, mixed> */
    public function projectTrialBattle(UndergroundBattle $battle, bool $withRounds = true): array
    {
        return $this->projectAlphaV1Battle($battle, UndergroundBattle::ACTIVITY_TRIAL, $withRounds);
    }

    /** @return array<string, mixed> */
    public function projectGuideDuel(UndergroundBattle $battle, bool $withRounds = true): array
    {
        return $this->projectAlphaV1Battle($battle, UndergroundBattle::ACTIVITY_GUIDE_DUEL, $withRounds);
    }

    /** @return array<string, mixed> */
    public function projectGuideDuelState(UndergroundProfile $profile): array
    {
        $duel = $this->alphaV1Catalog->guideDuel();
        $ground = $this->alphaV1Catalog->explorationHuntingGround($duel['required_ground']);
        $unlocked = UndergroundTrialProgress::query()->where('underground_profile_id', $profile->id)
            ->where('trial_key', $ground['required_trial_key'])->whereNotNull('first_cleared_at')->exists();
        $won = UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)
            ->where('content_type', UndergroundBattle::ACTIVITY_GUIDE_DUEL)->where('content_key', $duel['key'])
            ->where('actual_clear_count', '>', 0)->exists();

        return ['unlocked' => $unlocked, 'won' => $won, 'challenge_lines' => $duel['challenge_lines'],
            'accept_lines' => $duel['accept_lines'], 'cancel_lines' => $duel['cancel_lines'],
            'rematch_lines' => $duel['rematch_lines'], 'solo_rematch_lines' => $duel['solo_rematch_lines']];
    }

    /** @return array<string, mixed> */
    public function projectTrialRun(UndergroundTrialRun $run): array
    {
        $trial = $this->catalog->trial($run->trial_key);

        return [
            'key' => $run->trial_key,
            'label' => $trial['label'],
            'run_key' => $run->run_key,
            'status' => $run->status,
            'next_battle_index' => $run->next_battle_index,
            'total_battles' => count($trial['encounters']),
        ];
    }

    /** @return array<string, mixed> */
    public function projectSkipSettlement(UndergroundSkipSettlement $settlement, bool $duplicate): array
    {
        return [
            'id' => $settlement->request_id,
            'duplicate' => $duplicate,
            'content_type' => $settlement->content_type,
            'content_key' => $settlement->content_key,
            'ticket_cost' => $settlement->ticket_cost,
            'xp_awarded' => $settlement->xp_awarded,
            'shards_awarded' => $settlement->shard_awarded,
            'combat_level_before' => $settlement->combat_level_before,
            'combat_level_after' => $settlement->combat_level_after,
            'rewards' => $settlement->reward_snapshot,
            'settled_at' => $settlement->settled_at->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    public function projectSkipBatch(UndergroundSkipBatch $batch, bool $duplicate): array
    {
        return [
            'id' => $batch->request_id,
            'duplicate' => $duplicate,
            'content_type' => $batch->content_type,
            'content_key' => $batch->content_key,
            'execution_count' => $batch->execution_count,
            'ticket_cost' => $batch->ticket_cost,
            'xp_awarded' => $batch->xp_awarded,
            'shards_awarded' => $batch->shard_awarded,
            'combat_level_before' => $batch->combat_level_before,
            'combat_level_after' => $batch->combat_level_after,
            'rewards' => $batch->reward_snapshot,
            'settled_at' => $batch->settled_at->toAtomString(),
        ];
    }

    public function canDiscoverOtherworld(UndergroundProfile $profile): bool
    {
        $entry = $this->alphaV1Catalog->otherworld();

        return $profile->combat_level >= $entry['minimum_level']
            && UndergroundTrialProgress::query()->where('underground_profile_id', $profile->id)
                ->where('trial_key', $entry['required_trial_key'])->whereNotNull('first_cleared_at')->exists();
    }

    /** @return array<string,mixed> */
    public function projectOtherworldState(UndergroundProfile $profile): array
    {
        $cleared = $this->otherworldClearedKeys($profile);
        $content = $this->alphaV1Catalog->otherworld();
        $stages = [];
        foreach ($content['stages'] as $key => $stage) {
            $reason = $this->otherworldUnavailableReason($profile, $key, $cleared);
            $stages[] = ['key' => $key, 'name' => $stage['name'], 'recommended_level' => $stage['level'],
                'item_level' => $stage['item_level'], 'locked' => $reason !== null,
                'unlock_condition' => $reason, 'cleared' => in_array($key, $cleared, true)];
        }

        return ['stages' => $stages, 'distorted_stone_balance' => $profile->distorted_stone_balance];
    }

    /** @return list<string> */
    private function otherworldClearedKeys(UndergroundProfile $profile): array
    {
        return UndergroundContentClearProgress::query()->where('underground_profile_id', $profile->id)
            ->where('content_type', 'hunting_ground')->where('actual_clear_count', '>', 0)->pluck('content_key')->all();
    }

    /** @param list<string> $cleared */
    private function otherworldUnavailableReason(UndergroundProfile $profile, string $key, array $cleared): ?string
    {
        if ($profile->otherworld_discovered_at === null) {
            return '試練2クリア・Lv100以上でショップを訪ねてください。';
        }
        $previous = $this->alphaV1Catalog->otherworld()['stages'][$key]['previous'];

        return is_string($previous) && ! in_array($previous, $cleared, true)
            ? '前の段階をクリアすると解禁されます。' : null;
    }

    /** @return array<string, mixed> */
    public function projectHuntingGroundState(UndergroundProfile $profile): array
    {
        $clearedTrials = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('first_cleared_at')
            ->pluck('trial_key')
            ->all();
        $progresses = UndergroundContentClearProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('content_type', 'hunting_ground')
            ->get()
            ->keyBy('content_key');
        $skipPolicy = $this->catalog->skipPolicy('hunting_ground');
        $grounds = array_map(function (array $ground) use ($clearedTrials, $progresses, $skipPolicy, $profile): array {
            $requiredTrial = $ground['required_trial_key'];
            $locked = is_string($requiredTrial) && ! in_array($requiredTrial, $clearedTrials, true);
            $entryKeyCost = $ground['entry_key_cost'];
            $keyUnavailable = ! $locked && $entryKeyCost > $profile->shining_kingdom_key_balance;
            $progress = $progresses->get($ground['key']);
            $actualClears = $progress instanceof UndergroundContentClearProgress
                ? $progress->actual_clear_count
                : 0;
            $totalClears = $progress instanceof UndergroundContentClearProgress
                ? $progress->total_clear_count
                : 0;

            return [
                'key' => $ground['key'],
                'name' => $ground['name'],
                'kind' => $ground['kind'],
                'locked' => $locked,
                'unlock_condition' => match ($requiredTrial) {
                    'trial_01' => '試練1を初回clear',
                    'trial_02' => '試練2を初回clear',
                    default => null,
                },
                'entry_key_cost' => $entryKeyCost,
                'key_balance' => $profile->shining_kingdom_key_balance,
                'disabled' => $keyUnavailable,
                'unavailable_reason' => $keyUnavailable ? '輝きの王国の鍵が必要' : null,
                'item_level_min' => $ground['item_level_min'],
                'item_level_max' => $ground['item_level_max'],
                'skip' => [
                    'actual_clear_count' => $actualClears,
                    'total_clear_count' => $totalClears,
                    'actual_clears_required' => $skipPolicy['actual_clears_required'],
                    'unlocked' => $actualClears >= $skipPolicy['actual_clears_required'],
                    'ticket_cost' => $skipPolicy['ticket_cost'],
                ],
            ];
        }, $this->alphaV1Catalog->explorationHuntingGrounds());

        return [
            'default_key' => $this->alphaV1Catalog->explorationHuntingGroundKey(),
            'grounds' => $grounds,
        ];
    }

    /** @return array<string, mixed> */
    public function projectTrialState(UndergroundProfile $profile): array
    {
        $firstTrialKey = $this->catalog->firstTrialKey();
        $firstTrial = $this->catalog->trial($firstTrialKey);

        return DB::transaction(function () use ($profile, $firstTrialKey, $firstTrial): array {
            $lockedProfile = UndergroundProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $progresses = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $lockedProfile->id)
                ->get()
                ->keyBy('trial_key');
            $clearProgresses = UndergroundContentClearProgress::query()
                ->where('underground_profile_id', $lockedProfile->id)
                ->where('content_type', 'trial')
                ->get()
                ->keyBy('content_key');
            $skipPolicy = $this->catalog->skipPolicy('trial');
            $run = UndergroundTrialRun::query()
                ->where('underground_profile_id', $lockedProfile->id)
                ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($run instanceof UndergroundTrialRun) {
                $activeTrial = $this->catalog->trial($run->trial_key);
                $run = $this->reconcileActiveTrialContent($run, $activeTrial['content_identity']);
            }
            $trials = [];
            foreach ($this->catalog->trialKeys() as $trialKey) {
                $trial = $this->catalog->trial($trialKey);
                $progress = $progresses->get($trialKey);
                $requiredTrialKey = $trial['required_trial_key'];
                $requiredProgress = is_string($requiredTrialKey)
                    ? $progresses->get($requiredTrialKey)
                    : null;
                $unlocked = $progress instanceof UndergroundTrialProgress
                    || $requiredTrialKey === null
                    || ($requiredProgress instanceof UndergroundTrialProgress
                        && $requiredProgress->first_cleared_at !== null);
                $clearProgress = $clearProgresses->get($trialKey);
                $actualClears = $clearProgress instanceof UndergroundContentClearProgress
                    ? $clearProgress->actual_clear_count
                    : 0;
                $totalClears = $clearProgress instanceof UndergroundContentClearProgress
                    ? $clearProgress->total_clear_count
                    : 0;
                $trials[] = [
                    'key' => $trialKey,
                    'label' => $trial['label'],
                    'total_battles' => count($trial['encounters']),
                    'locked' => ! $unlocked,
                    'unlock_condition' => $requiredTrialKey === 'trial_01'
                        ? '試練1を初回clear'
                        : null,
                    'first_cleared' => $progress?->first_cleared_at !== null,
                    'skip' => [
                        'actual_clear_count' => $actualClears,
                        'total_clear_count' => $totalClears,
                        'actual_clears_required' => $skipPolicy['actual_clears_required'],
                        'unlocked' => $actualClears >= $skipPolicy['actual_clears_required'],
                        'ticket_cost' => $skipPolicy['ticket_cost'],
                    ],
                ];
            }
            $firstProgress = $progresses->get($firstTrialKey);

            return [
                'key' => $firstTrialKey,
                'label' => $firstTrial['label'],
                'total_battles' => count($firstTrial['encounters']),
                'first_cleared' => $firstProgress?->first_cleared_at !== null,
                'active_run' => $run instanceof UndergroundTrialRun ? $this->projectTrialRun($run) : null,
                'trials' => $trials,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function projectAlphaV1Battle(
        UndergroundBattle $battle,
        string $context,
        bool $withRounds,
    ): array {
        $log = $battle->relationLoaded('log') && $battle->getRelation('log') instanceof UndergroundBattleLog
            ? $battle->getRelation('log')
            : null;
        $storedSnapshot = $battle->snapshot;
        if ($log instanceof UndergroundBattleLog && is_array($log->presentation)) {
            $storedSnapshot = array_replace($storedSnapshot, $log->presentation);
        }
        $snapshot = $this->secretaryPresenter->filterSavedBattleImages(
            $storedSnapshot,
            $battle->profile->secretary->user,
        );
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $presentationLogVersion = $snapshot['presentation_log_version'] ?? null;
        $hasPresentationLog = in_array($presentationLogVersion, [
            1,
            UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION,
            UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION,
        ], true)
            && $log instanceof UndergroundBattleLog;
        $partySnapshot = $presentationLogVersion === UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION
            && is_array($snapshot['party'] ?? null)
                ? $snapshot['party']
                : null;
        $party = $partySnapshot !== null
            ? $this->projectPartyPresentation($partySnapshot)
            : null;

        return [
            'id' => $battle->request_id,
            'context' => $context,
            'presentation_log_version' => $presentationLogVersion,
            'party' => $party,
            'portrait_events' => $party !== null && is_array($snapshot['portrait_events'] ?? null)
                ? $snapshot['portrait_events']
                : [],
            'player_display_name' => is_string($snapshot['player_display_name'] ?? null)
                ? $snapshot['player_display_name']
                : '秘書',
            'player_image_references' => is_array($snapshot['player_image_references'] ?? null)
                ? $snapshot['player_image_references'] : null,
            'encounter_name' => is_string($snapshot['encounter_display_name'] ?? null)
                ? $snapshot['encounter_display_name']
                : '地下の敵',
            'enemy_key' => $battle->encounter_key,
            'result' => $battle->result,
            'rounds_count' => $battle->rounds,
            'xp_awarded' => $battle->xp_awarded,
            'shard_delta' => $battle->shard_delta,
            'combat_level_before' => $battle->combat_level_before,
            'combat_level_after' => $battle->combat_level_after,
            'combat_xp_before' => $battle->combat_xp_before,
            'combat_xp_after' => $battle->combat_xp_after,
            'stp_awarded' => (int) ($snapshot['stp_awarded'] ?? 0),
            'unspent_stp_after' => (int) ($snapshot['unspent_stp_after'] ?? 0),
            'current_hp_before' => (int) ($snapshot['current_hp_before'] ?? 0),
            'current_hp_after' => (int) ($snapshot['current_hp_after'] ?? 0),
            'max_hp_after' => (int) ($snapshot['max_hp_after'] ?? 0),
            'interbattle_heal_amount' => (int) ($snapshot['interbattle_heal_amount'] ?? 0),
            'summary' => $summary,
            'initial_state' => $withRounds
                && $hasPresentationLog
                && in_array($presentationLogVersion, [
                    UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION,
                    UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION,
                ], true)
                && is_array($snapshot['initial_state'] ?? null)
                    ? $snapshot['initial_state']
                    : null,
            'rounds' => $withRounds && $hasPresentationLog ? $log->actions : null,
            'detail_available' => $withRounds
                ? $hasPresentationLog
                : (bool) ($battle->getAttribute('active_log_exists') ?? false),
            'detail_message' => $withRounds && ! $hasPresentationLog
                ? '詳細ログは保存期間を過ぎました。'
                : null,
            'finished_at' => $battle->finished_at->toAtomString(),
            'rewards' => ['xp' => $battle->xp_awarded, 'shards' => $battle->shard_delta],
            'duel_dialogue' => $context === UndergroundBattle::ACTIVITY_GUIDE_DUEL ? ($snapshot['duel_dialogue'] ?? []) : null,
            'hunting_ground' => $context === UndergroundBattle::ACTIVITY_EXPLORATION
                && is_array($snapshot['hunting_ground'] ?? null)
                    ? $snapshot['hunting_ground']
                    : null,
            'drop' => is_array($snapshot['drop'] ?? null) ? $snapshot['drop'] : null,
            'drops' => is_array($snapshot['drops'] ?? null) ? $snapshot['drops'] : null,
            'treasure' => is_array($snapshot['treasure'] ?? null) ? $snapshot['treasure'] : null,
            'distorted_stones' => (int) ($snapshot['distorted_stones'] ?? 0),
            'shining_kingdom_key' => is_array($snapshot['shining_kingdom_key'] ?? null)
                ? $snapshot['shining_kingdom_key']
                : null,
            'trial_key' => $context === UndergroundBattle::ACTIVITY_TRIAL ? $battle->activity_key : null,
            'trial_run_key' => $context === UndergroundBattle::ACTIVITY_TRIAL ? $battle->trial_run_key : null,
            'trial_battle_index' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? $battle->trial_battle_index
                : null,
            'trial_total_battles' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? (int) ($snapshot['trial_total_battles'] ?? 0)
                : null,
            'trial_status' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? ($snapshot['trial_status'] ?? null)
                : null,
            'trial_next_battle_index' => $context === UndergroundBattle::ACTIVITY_TRIAL
                ? ($snapshot['trial_next_battle_index'] ?? null)
                : null,
            'first_clear_story' => $context === UndergroundBattle::ACTIVITY_TRIAL
                && is_array($snapshot['first_clear_story'] ?? null)
                    ? $snapshot['first_clear_story']
                    : null,
            'awakening' => is_array($snapshot['awakening'] ?? null)
                ? $snapshot['awakening']
                : null,
            'challenge_intro' => in_array($context, [UndergroundBattle::ACTIVITY_TRIAL, UndergroundBattle::ACTIVITY_GUIDE_DUEL], true)
                && is_string($snapshot['challenge_intro'] ?? null)
                    ? $snapshot['challenge_intro']
                    : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, mixed>
     */
    private function projectPartyPresentation(array $party): array
    {
        $members = is_array($party['members'] ?? null) ? $party['members'] : [];
        $players = [];
        $enemies = [];
        foreach ($members as $combatantId => $member) {
            if (! is_string($combatantId) || ! is_array($member)) {
                continue;
            }
            $images = is_array($member['image_references'] ?? null) ? $member['image_references'] : [];
            $icon = is_array($images['compact'] ?? null) ? $images['compact'] : [];
            $portrait = is_array($images['normal'] ?? null) ? $images['normal'] : [];
            $team = ($member['team'] ?? null) === 'enemy' ? 'enemy' : 'player';
            $row = [
                'team' => $team,
                'combatant_id' => $combatantId,
                'display_name' => is_string($member['display_name'] ?? null)
                    ? $member['display_name']
                    : (is_string($member['label'] ?? null) ? $member['label'] : $combatantId),
                'icon_url' => is_string($icon['url'] ?? null) ? $icon['url'] : null,
                'portrait_url' => is_string($portrait['url'] ?? null) ? $portrait['url'] : null,
                'image_references' => $images,
                'state' => null,
                'awakening_state' => null,
            ];
            if ($team === 'player') {
                $players[] = $row;
            } else {
                $enemies[] = $row;
            }
        }

        return [
            'party_id' => $party['party_id'] ?? null,
            'party_size' => $party['party_size'] ?? count($players),
            'enemy_count' => $party['enemy_count'] ?? count($enemies),
            'members' => $players,
            'enemies' => $enemies,
        ];
    }

    /** @return array<string, mixed> */
    private function battleImageReferences(Secretary $secretary): array
    {
        $secretary = $this->imageRetention->lockSnapshotSource($secretary);
        $secretary->loadMissing(['user', 'images']);

        return [
            'compact' => $this->secretaryPresenter->resolveCompactImage($secretary, $secretary->user),
            'awakening_compact' => $this->secretaryPresenter->resolveCompactImage($secretary, $secretary->user, true),
            'normal' => $this->secretaryPresenter->resolveLargeImage($secretary, $secretary->user),
            'awakening' => $this->secretaryPresenter->resolveLargeImage($secretary, $secretary->user, true),
        ];
    }

    /** @return array<string, mixed> */
    public function projectAwakeningState(UndergroundProfile $profile, bool $unlocked): array
    {
        $technique = is_string($profile->growth_path_key)
            ? $this->awakening->technique($profile->growth_path_key, $profile->awakening_technique_key)
            : null;
        $techniques = is_string($profile->growth_path_key)
            ? $this->awakening->techniques($profile->growth_path_key)
            : [];

        return [
            'identity' => UndergroundAwakening::IDENTITY,
            'unlocked' => $unlocked,
            'current' => $unlocked ? $profile->awakening_gauge : 0,
            'maximum' => UndergroundAwakening::GAUGE_MAX,
            'custom_message' => $unlocked ? $profile->awakening_message : null,
            'default_message' => UndergroundAwakening::DEFAULT_MESSAGE,
            'technique' => $unlocked ? $technique : null,
            'techniques' => $unlocked ? $techniques : [],
            'selected_technique_key' => $unlocked ? ($technique['key'] ?? null) : null,
        ];
    }

    /** @return array{unlocked: bool, gauge: int, message: string, growth_path: string, technique_key: string} */
    private function awakeningSnapshot(
        UndergroundProfile $profile,
        bool $unlocked,
        string $secretaryName,
    ): array {
        if (! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_exploration_locked',
                '覚醒対象の成長方針を解決できません。',
            );
        }

        $technique = $this->awakening->technique(
            $profile->growth_path_key,
            $profile->awakening_technique_key,
        );

        return [
            'unlocked' => $unlocked,
            'gauge' => $unlocked ? $profile->awakening_gauge : 0,
            'message' => $this->awakening->renderMessage($profile->awakening_message, $secretaryName),
            'growth_path' => $profile->growth_path_key,
            'technique_key' => $technique['key'],
        ];
    }

    private function resolveAndSettleExplorationBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        string $huntingGroundKey,
        string $encounterKey,
        int $seed,
        int $keyBalanceBefore,
    ): UndergroundBattle {
        $huntingGround = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey, $huntingGroundKey);
        $secretary = $profile->secretary;
        if (! is_string($secretary->name) || $secretary->name === '') {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '名前のある秘書が必要です。',
            );
        }
        if (! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_exploration_locked',
                '周囲の探索はまだ解禁されていません。',
            );
        }
        $equipment = $this->equipmentLoadout->combatLoadout($profile);
        $maxHpBefore = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $currentHpBefore = min($profile->current_hp ?? $maxHpBefore, $maxHpBefore);
        $definition = $this->alphaV1Catalog->explorationCombatDefinition(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
            $secretary->name,
            $currentHpBefore,
            $profile->skillAllocationMap(),
            $profile->custom_ai_rules,
        );
        $awakeningUnlocked = $this->awakeningUnlocked($profile);
        $definition['player_snapshot']['awakening'] = $this->awakeningSnapshot(
            $profile,
            $awakeningUnlocked,
            $secretary->name,
        );
        $growthPath = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $maxRounds = $this->alphaV1Catalog->explorationMaxRounds();
        $startedAt = Carbon::now();
        $result = $this->explorationCombat->fight(
            $definition['catalog'],
            $definition['player_snapshot'],
            $encounterKey,
            $seed,
            $maxRounds,
            (int) $growthPath['natural_recovery'],
        );
        $this->assertExplorationCombatResult(
            $result,
            $encounterKey,
            $seed,
            $maxRounds,
            $awakeningUnlocked,
            $profile->awakening_gauge,
        );
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            default => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $unspentStpBefore = $profile->unspent_stp;
        $victoryReward = $this->explorationVictoryReward(
            $huntingGround,
            $encounterKey,
            $encounter,
            $seed,
            $resultType === UndergroundBattle::RESULT_VICTORY,
        );
        $xpAwarded = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $encounter['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($encounter['xp'], 4),
            default => 0,
        };
        $shardDelta = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $victoryReward['shards'],
            UndergroundBattle::RESULT_DEFEAT => intdiv($profile->shard_balance, 2) - $profile->shard_balance,
            default => 0,
        };
        $rewardSettlement = $this->applyRepeatableReward($profile, $xpAwarded, $shardDelta);
        $profile->shining_kingdom_key_balance += $victoryReward['keys'];
        $profile->distorted_stone_balance += $victoryReward['distorted_stones'];
        $curve = $rewardSettlement['xp_curve'];
        $stpAwarded = $rewardSettlement['stp_awarded'];
        $maxHpAfter = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $profile->current_hp = $resultType === UndergroundBattle::RESULT_DEFEAT
            ? $maxHpAfter
            : min($result->playerRemainingHp, $maxHpAfter);
        $profile->awakening_gauge = $result->awakening['gauge_after'];
        $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        $profile->save();

        $projection = $this->alphaV1Projector->project(
            $result,
            $definition['catalog'],
            $this->secretaryPresenter->battleDisplayName($secretary),
            $encounter['label'],
        );
        $projection['summary']['result'] = $resultType;
        $detailSnapshot = [
            'initial_state' => $projection['initial_state'],
            'player_image_references' => $this->battleImageReferences($secretary),
        ];
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $this->alphaV1Catalog->explorationIdentity(),
            'activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION,
            'activity_key' => $huntingGroundKey,
            'encounter_key' => $encounterKey,
            'trial_run_key' => null,
            'trial_battle_index' => null,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'healing_done' => $result->effectiveHealing,
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'statistics' => $this->statisticsProjector->fromSolo($result),
            'xp_awarded' => $xpAwarded,
            'shard_delta' => $shardDelta,
            'combat_level_before' => $levelBefore,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $xpBefore,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $shardsBefore,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => $seed,
            'snapshot' => $this->battleStorage->compactSnapshot([
                'exploration_identity' => $this->alphaV1Catalog->explorationIdentity(),
                'hunting_ground' => [
                    'key' => $huntingGround['key'],
                    'name' => $huntingGround['name'],
                    'content_identity' => $huntingGround['content_identity'],
                    'item_level_min' => $huntingGround['item_level_min'],
                    'item_level_max' => $huntingGround['item_level_max'],
                ],
                'combat_rules_identity' => $result->rulesIdentity,
                'ai' => $definition['ai'],
                'player_display_name' => $this->secretaryPresenter->battleDisplayName($secretary),
                'player_image_references' => $detailSnapshot['player_image_references'],
                'encounter_display_name' => $encounter['label'],
                'presentation_log_version' => UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION,
                'initial_state' => $projection['initial_state'],
                'summary' => $projection['summary'],
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'progression_stats' => $definition['progression_stats'],
                'combat_stats' => $definition['combat_stats'],
                'allocated_stp' => $profile->allocatedStp(),
                'equipment' => $definition['equipment'],
                'skill_tree_identity' => $profile->skill_tree_identity,
                'targeting_contract_identity' => $this->alphaV1Catalog->targetingIdentity(),
                'acquired_skill_nodes' => $definition['acquired_nodes'],
                'equipped_active_skills' => $definition['active_skills'],
                'effective_passive_modifiers' => $definition['passive_modifiers'],
                'encounter' => [
                    'key' => $encounterKey,
                    'weight_bps' => $encounter['weight'],
                    'xp_reward' => $encounter['xp'],
                    'shard_reward' => $encounter['shards'],
                ],
                'xp_curve' => $curve,
                'max_rounds' => $maxRounds,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
                'unspent_stp_before' => $unspentStpBefore,
                'unspent_stp_after' => $profile->unspent_stp,
                'stp_awarded' => $stpAwarded,
                'current_hp_before' => $currentHpBefore,
                'max_hp_before' => $maxHpBefore,
                'current_hp_after' => $profile->current_hp,
                'max_hp_after' => $maxHpAfter,
                'banked_shard_balance' => $profile->banked_shard_balance,
                'shining_kingdom_key' => [
                    'balance_before' => $keyBalanceBefore,
                    'entry_cost' => $huntingGround['entry_key_cost'],
                    'awarded' => $victoryReward['keys'],
                    'balance_after' => $profile->shining_kingdom_key_balance,
                ],
                'treasure' => $victoryReward['treasure'],
                'distorted_stones' => $victoryReward['distorted_stones'],
                'awakening' => $result->awakening,
                'drop' => [
                    'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                    'status' => 'pending',
                ],
            ]),
            'compaction_version' => UndergroundBattleStorage::COMPACTION_VERSION,
            'compacted_at' => $finishedAt,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        $snapshot = $battle->snapshot;
        $dropEncounter = $encounter;
        if (is_string($huntingGround['forced_drop_profile'] ?? null)) {
            $dropEncounter['drop_profile'] = $huntingGround['forced_drop_profile'];
        }
        $snapshot['drop'] = $resultType === UndergroundBattle::RESULT_VICTORY
            ? $this->equipmentDrops->settleVictory(
                $profile,
                $battle,
                $huntingGroundKey,
                $dropEncounter,
                $seed,
                $huntingGround['drop_tier_key'],
            )
            : [
                'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                'status' => 'ineligible',
            ];
        $battle->snapshot = $snapshot;
        $battle->save();
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $projection['rounds'],
            'presentation' => $this->battleStorage->detailPresentation($detailSnapshot),
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours()),
        ]);
        $this->imageRetention->retainSnapshotImages($battle, $detailSnapshot);
        if ($resultType === UndergroundBattle::RESULT_VICTORY) {
            $this->recordActualContentClear($profile, 'hunting_ground', $huntingGroundKey);
        }

        return $battle->load('log');
    }

    /**
     * Freeze the same member inputs for exploration and the guide duel.
     *
     * @param  list<array<string, mixed>>  $borrowed
     * @return array{Secretary, User, int, array<string,mixed>, int, int, string, array<string,mixed>, string, array<string,mixed>, array<string,array<string,mixed>>, list<array<string,mixed>>, list<array<string,mixed>>}
     */
    private function partyCombatInputs(UndergroundProfile $profile, array $borrowed): array
    {
        $secretary = $this->imageRetention->lockSnapshotSource($profile->secretary);
        if (! is_string($secretary->name) || $secretary->name === ''
            || ! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException('underground_party_invalid', 'PT戦闘の開始状態を解決できません。');
        }
        $secretary->loadMissing('user');
        $leader = $secretary->user;
        $leaderGameId = $this->visitorCodes->allocate($leader);

        $leaderLevel = $profile->combat_level;
        $leaderEquipment = $this->equipmentLoadout->combatLoadout($profile);
        $maxHpBefore = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $leaderLevel,
            $profile->allocatedStp(),
            $leaderEquipment,
        );
        $currentHpBefore = min($profile->current_hp ?? $maxHpBefore, $maxHpBefore);
        $leaderDisplayName = $this->secretaryPresenter->battleDisplayName($secretary);
        $leaderDefinition = $this->alphaV1Catalog->explorationCombatDefinition(
            $profile->growth_path_key,
            $leaderLevel,
            $profile->allocatedStp(),
            $leaderEquipment,
            $leaderDisplayName,
            $currentHpBefore,
            $profile->skillAllocationMap(),
            $profile->custom_ai_rules,
        );
        $leaderAwakeningUnlocked = $this->awakeningUnlocked($profile);
        $leaderAwakening = $this->awakeningSnapshot($profile, $leaderAwakeningUnlocked, $secretary->name);
        $leaderCombatantId = 'secretary:'.$secretary->id;
        $leaderDefinition['player_snapshot']['combatant_id'] = $leaderCombatantId;
        $leaderDefinition['player_snapshot']['natural_recovery'] = $this->alphaV1Catalog
            ->growthPath($profile->growth_path_key)['natural_recovery'];
        $leaderDefinition['player_snapshot']['awakening'] = $leaderAwakening;
        $leaderSnapshot = [
            'team' => 'player',
            'combatant_id' => $leaderCombatantId,
            'source_type' => 'self',
            'source' => [
                'secretary_id' => $secretary->id,
                'owner_game_id' => $leaderGameId,
            ],
            'formal_name' => $secretary->name,
            'display_name' => $leaderDisplayName,
            'original_combat_level' => $leaderLevel,
            'effective_combat_level' => $leaderLevel,
            'equipment_sync' => [
                'authority' => 'leader_equipped_slot_item_level',
                'leader_item_levels' => $this->equipmentItemLevelsBySlot($leaderEquipment),
            ],
            'growth_path_key' => $profile->growth_path_key,
            'growth_path_identity' => $profile->growth_path_identity,
            'original_allocated_stp' => $profile->allocatedStp(),
            'effective_allocated_stp' => $profile->allocatedStp(),
            'original_equipment' => $leaderDefinition['equipment'],
            'effective_equipment' => $leaderDefinition['equipment'],
            'active_skills' => $leaderDefinition['active_skills'],
            'ai' => $leaderDefinition['ai'],
            'awakening' => $leaderAwakening,
            'image_references' => [
                'compact' => $this->secretaryPresenter->resolveCompactImage($secretary, $leader),
                'awakening_compact' => $this->secretaryPresenter->resolveCompactImage($secretary, $leader, true),
                'normal' => $this->secretaryPresenter->resolveLargeImage($secretary, $leader),
                'awakening' => $this->secretaryPresenter->resolveLargeImage($secretary, $leader, true),
            ],
            'player_snapshot' => $leaderDefinition['player_snapshot'],
        ];

        $memberSnapshots = [$leaderCombatantId => $leaderSnapshot];
        $memberRows = [[
            'source_type' => 'self',
            'secretary_id' => $secretary->id,
            'source_owner_user_id' => $leader->id,
            'combatant_id' => $leaderCombatantId,
            'original_level' => $leaderLevel,
            'effective_level' => $leaderLevel,
            'snapshot' => $leaderSnapshot,
        ]];
        $playerSnapshots = [$leaderDefinition['player_snapshot']];
        foreach ($borrowed as $context) {
            $snapshot = $context['snapshot'];
            $borrowedSecretaryId = $context['secretary_id'];
            $combatantId = 'borrowed:'.$borrowedSecretaryId;
            $snapshot['team'] = 'player';
            $snapshot['combatant_id'] = $combatantId;
            $snapshot['source_type'] = 'borrowed_secretary';
            $snapshot['player_snapshot']['combatant_id'] = $combatantId;
            $rental = collect($profile->rental_party)->firstWhere('secretary_id', $borrowedSecretaryId);
            if (! is_array($rental)) {
                throw new UndergroundRuntimeException('underground_rental_party_changed', 'レンタル編成を確認してください。');
            }
            $maxHp = (int) $snapshot['resources']['effective_max_hp'];
            $hp = $this->rescaleRentalHp($rental['current_hp'], $rental['max_hp'], $maxHp);
            $snapshot['resources']['effective_current_hp'] = $hp;
            $snapshot['resources']['authority'] = 'borrower_rental_party';
            $snapshot['player_snapshot']['current_hp'] = $hp;
            $snapshot['awakening']['gauge'] = $rental['awakening_gauge'];
            $snapshot['player_snapshot']['awakening']['gauge'] = $rental['awakening_gauge'];
            $memberSnapshots[$combatantId] = $snapshot;
            $playerSnapshots[] = $snapshot['player_snapshot'];
            $memberRows[] = [
                'source_type' => 'borrowed_secretary',
                'secretary_id' => $borrowedSecretaryId,
                'source_owner_user_id' => $context['source_owner_user_id'],
                'combatant_id' => $combatantId,
                'original_level' => $snapshot['original_combat_level'],
                'effective_level' => $snapshot['effective_combat_level'],
                'snapshot' => $snapshot,
            ];
        }

        return [$secretary, $leader, $leaderLevel, $leaderEquipment, $maxHpBefore, $currentHpBefore, $leaderDisplayName, $leaderDefinition, $leaderCombatantId, $leaderSnapshot, $memberSnapshots, $memberRows, $playerSnapshots];
    }

    /** @param list<array<string, mixed>> $borrowed */
    private function resolveAndSettleGuideDuel(UndergroundProfile $profile, string $requestId, string $fingerprint, array $borrowed): UndergroundBattle
    {
        [$secretary, $leader, $leaderLevel, $leaderEquipment, $maxHpBefore, $currentHpBefore, $leaderDisplayName,
            $leaderDefinition, $leaderCombatantId, $leaderSnapshot, $memberSnapshots, $memberRows, $playerSnapshots]
            = $this->partyCombatInputs($profile, $borrowed);
        $duel = $this->alphaV1Catalog->guideDuel();
        $combatCatalog = $this->alphaV1Catalog->guideDuelCatalog();
        $memberSnapshots['enemy:1'] = ['team' => 'enemy', 'combatant_id' => 'enemy:1', 'display_name' => $duel['enemy']['label'],
            'image_references' => ['compact' => null, 'normal' => null, 'awakening' => null]];
        $partySnapshot = ['schema_version' => 1, 'content_identity' => $duel['identity'], 'party_size' => count($playerSnapshots),
            'enemy_count' => 1, 'enemy_count_authority' => 'fixed', 'leader_combat_level' => $leaderLevel,
            'leader_equipment_item_levels' => $this->equipmentItemLevelsBySlot($leaderEquipment),
            'reward_authority' => ['mode' => 'leader_first_victory_only']];
        $party = $this->lendingRewards->createSnapshot($leader, $secretary, UndergroundBattle::ACTIVITY_GUIDE_DUEL,
            $duel['key'], $duel['identity'], $leaderLevel, $partySnapshot, $memberRows);
        $seed = $this->battleSeed->forRequest($profile->id, $requestId, $duel['identity']);
        $startedAt = Carbon::now();
        $result = $this->partyCombat->fight($combatCatalog, $playerSnapshots, [$duel['key']], $seed,
            $this->alphaV1Catalog->explorationMaxRounds(), (int) $this->alphaV1Catalog->growthPath($profile->growth_path_key)['natural_recovery']);
        $finishedAt = Carbon::now();
        $resultType = $result->winner === 'player' ? UndergroundBattle::RESULT_VICTORY : UndergroundBattle::RESULT_DEFEAT;
        $projection = $this->partyProjector->project($result, $memberSnapshots, $combatCatalog);
        $projection['summary']['result'] = $resultType;
        $progress = $this->lockedContentProgress($profile, UndergroundBattle::ACTIVITY_GUIDE_DUEL, $duel['key']);
        $firstVictory = $resultType === UndergroundBattle::RESULT_VICTORY && $progress->actual_clear_count === 0;
        $dialogue = $resultType === UndergroundBattle::RESULT_VICTORY
            ? [...$duel['victory_lines'], ...$duel[$firstVictory ? 'first_victory_lines' : 'repeat_victory_lines']]
            : $duel['defeat_lines'];
        $detailSnapshot = [
            'initial_state' => $projection['initial_state'],
            'portrait_events' => $projection['portrait_events'],
            'party' => [...$partySnapshot, 'party_id' => $party->id, 'members' => $memberSnapshots],
        ];
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id, 'underground_party_id' => $party->id,
            'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'runtime_identity' => $duel['identity'],
            'activity_type' => UndergroundBattle::ACTIVITY_GUIDE_DUEL, 'activity_key' => $duel['key'], 'encounter_key' => $duel['key'],
            'result' => $resultType, 'rounds' => $result->rounds,
            'damage_dealt' => (int) ($result->metrics['damage_dealt'] ?? 0), 'damage_received' => (int) ($result->metrics['damage_received'] ?? 0),
            'healing_done' => (int) ($result->metrics['effective_healing'] ?? 0),
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'statistics' => $this->statisticsProjector->fromParty($result, $leaderCombatantId),
            'xp_awarded' => 0, 'shard_delta' => 0,
            'combat_level_before' => $leaderLevel, 'combat_level_after' => $leaderLevel,
            'combat_xp_before' => $profile->combat_xp, 'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $profile->shard_balance, 'shard_balance_after' => $profile->shard_balance, 'private_seed' => $seed,
            'snapshot' => $this->battleStorage->compactSnapshot(['content_identity' => $duel['identity'], 'combat_rules_identity' => AlphaV1CombatRules::IDENTITY,
                'player_display_name' => $leaderDisplayName, 'encounter_display_name' => $duel['enemy']['label'],
                'presentation_log_version' => UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION,
                'initial_state' => $detailSnapshot['initial_state'], 'summary' => $projection['summary'], 'portrait_events' => $detailSnapshot['portrait_events'],
                'party' => $detailSnapshot['party'],
                'encounter' => ['key' => $duel['key'], 'enemy_keys' => [$duel['key']], 'definition' => $duel['enemy']],
                'current_hp_before' => $currentHpBefore, 'current_hp_after' => $currentHpBefore, 'max_hp_after' => $maxHpBefore,
                'party_awakening' => $result->awakening, 'awakening' => $result->awakening[$leaderCombatantId] ?? null,
                'duel_dialogue' => $dialogue, 'challenge_intro' => implode("\n", $duel['accept_lines']),
                'first_victory' => $firstVictory, 'resources_restored' => true]),
            'compaction_version' => UndergroundBattleStorage::COMPACTION_VERSION,
            'compacted_at' => $finishedAt,
            'started_at' => $startedAt, 'finished_at' => $finishedAt,
        ]);
        if ($firstVictory) {
            $item = UndergroundOwnedEquipment::query()->firstOrCreate([
                'underground_profile_id' => $profile->id, 'definition_key' => $duel['reward_key'], 'instance_kind' => 'fixed',
            ], ['catalog_identity' => app(UndergroundEquipmentCatalog::class)->identity(), 'equipped_slot' => null, 'acquired_at' => $finishedAt]);
            $snapshot = $battle->snapshot;
            $snapshot['drop'] = ['status' => 'granted', 'item' => $this->equipmentLoadout->projectOwned($item)];
            $battle->snapshot = $snapshot;
            $battle->save();
        }
        if ($resultType === UndergroundBattle::RESULT_VICTORY) {
            $this->recordActualContentClear($profile, UndergroundBattle::ACTIVITY_GUIDE_DUEL, $duel['key']);
        }
        UndergroundBattleLog::query()->create(['underground_battle_id' => $battle->id, 'actions' => $projection['rounds'],
            'presentation' => $this->battleStorage->detailPresentation($detailSnapshot),
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours())]);
        $this->imageRetention->retainSnapshotImages($battle, $detailSnapshot);

        return $battle->load('log');
    }

    /**
     * @param  list<string>  $encounterKeys
     * @param  list<array{secretary_id:int, source_owner_user_id:int, snapshot:array<string,mixed>}>  $borrowed
     */
    private function resolveAndSettlePartyExplorationBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        string $huntingGroundKey,
        array $encounterKeys,
        string $encounterKey,
        int $seed,
        int $keyBalanceBefore,
        array $borrowed,
    ): UndergroundBattle {
        $huntingGround = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey, $huntingGroundKey);
        $averagedReward = $this->averagedExplorationEncounterReward($encounterKeys, $huntingGroundKey);
        $baseShardReward = $huntingGround['kind'] === 'vault'
            ? (int) $huntingGround['vault_base_g']
            : $averagedReward['shards'];
        [$secretary, $leader, $leaderLevel, $leaderEquipment, $maxHpBefore, $currentHpBefore, $leaderDisplayName, $leaderDefinition, $leaderCombatantId, $leaderSnapshot, $memberSnapshots, $memberRows, $playerSnapshots] = $this->partyCombatInputs($profile, $borrowed);

        $partySize = count($playerSnapshots);
        $enemyCount = $this->alphaV1Catalog->explorationEnemyCountForPartySize($huntingGroundKey, $partySize);
        if (count($encounterKeys) !== $enemyCount) {
            throw new UndergroundRuntimeException('underground_party_invalid', '敵編成を解決できません。');
        }
        $enemyKeys = $encounterKeys;
        $otherworld = $huntingGround['kind'] === 'otherworld';
        $combatCatalog = $otherworld ? $this->alphaV1Catalog->otherworldCatalog($huntingGroundKey) : $this->alphaV1Catalog->explorationCatalog();
        $enemyLabels = [];
        for ($index = 1; $index <= $enemyCount; $index++) {
            $enemyKey = $enemyKeys[$index - 1];
            $enemyDefinition = $combatCatalog->enemy($enemyKey);
            $enemyEncounter = $this->alphaV1Catalog->explorationEncounter($enemyKey, $huntingGroundKey);
            $encounterLabel = $enemyDefinition['label'] ?? $enemyEncounter['label'];
            $enemyLabels[] = $encounterLabel;
            $enemyId = 'enemy:'.$index;
            $memberSnapshots[$enemyId] = [
                'team' => 'enemy',
                'combatant_id' => $enemyId,
                'display_name' => $encounterLabel,
                'image_references' => ['compact' => null, 'normal' => null, 'awakening' => null],
            ];
        }
        $partySnapshot = [
            'schema_version' => 1,
            'content_identity' => $huntingGround['content_identity'],
            'leader_combat_level' => $leaderLevel,
            'leader_equipment_item_levels' => $this->equipmentItemLevelsBySlot($leaderEquipment),
            'party_size' => $partySize,
            'enemy_count' => $enemyCount,
            'enemy_count_authority' => 'content_party_size_table',
            'reward_authority' => [
                'mode' => 'enemy_average',
                'encounter_key' => $encounterKey,
                'enemy_keys' => $enemyKeys,
                'xp' => $averagedReward['xp'],
                'base_shards' => $baseShardReward,
                'multiplied_by_enemy_count' => false,
            ],
        ];
        $party = $this->lendingRewards->createSnapshot(
            $leader,
            $secretary,
            UndergroundBattle::ACTIVITY_EXPLORATION,
            $huntingGroundKey,
            $huntingGround['content_identity'],
            $leaderLevel,
            $partySnapshot,
            $memberRows,
        );

        $maxRounds = $otherworld ? $this->alphaV1Catalog->otherworld()['max_rounds'] : $this->alphaV1Catalog->explorationMaxRounds();
        $naturalRecovery = (int) $this->alphaV1Catalog->growthPath($profile->growth_path_key)['natural_recovery'];
        $startedAt = Carbon::now();
        $result = $this->partyCombat->fight(
            $combatCatalog,
            $playerSnapshots,
            $enemyKeys,
            $seed,
            $maxRounds,
            $naturalRecovery,
        );
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            default => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $leaderFinalState = $result->finalStates[$leaderCombatantId] ?? null;
        $leaderFinalAwakening = $result->awakening[$leaderCombatantId] ?? null;
        if (! is_array($leaderFinalState)
            || ! is_int($leaderFinalState['hp'] ?? null)
            || ! is_array($leaderFinalAwakening)
            || ! is_int($leaderFinalAwakening['gauge_after'] ?? null)) {
            throw new UndergroundRuntimeException('underground_party_result_invalid', 'PT戦闘結果を解決できません。');
        }

        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $unspentStpBefore = $profile->unspent_stp;
        $victoryReward = $this->explorationVictoryReward(
            $huntingGround,
            $encounterKey,
            $encounter,
            $seed,
            $resultType === UndergroundBattle::RESULT_VICTORY,
            $baseShardReward,
        );
        $xpAwarded = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $averagedReward['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => $otherworld ? 0 : intdiv($averagedReward['xp'], 4),
            default => 0,
        };
        $shardDelta = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $victoryReward['shards'],
            UndergroundBattle::RESULT_DEFEAT => intdiv($profile->shard_balance, 2) - $profile->shard_balance,
            default => 0,
        };
        $rewardSettlement = $this->applyRepeatableReward($profile, $xpAwarded, $shardDelta);
        $profile->shining_kingdom_key_balance += $victoryReward['keys'];
        $profile->distorted_stone_balance += $victoryReward['distorted_stones'];
        $curve = $rewardSettlement['xp_curve'];
        $stpAwarded = $rewardSettlement['stp_awarded'];
        $maxHpAfter = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $leaderEquipment,
        );
        $profile->current_hp = $resultType === UndergroundBattle::RESULT_DEFEAT
            ? $maxHpAfter
            : min(max(1, $leaderFinalState['hp']), $maxHpAfter);
        $profile->awakening_gauge = $leaderFinalAwakening['gauge_after'];
        $rentalMembers = $profile->rental_party;
        foreach ($rentalMembers as &$rental) {
            $id = 'borrowed:'.$rental['secretary_id'];
            $final = $result->finalStates[$id];
            $normalMaxHp = (int) $memberSnapshots[$id]['resources']['effective_max_hp'];
            $rental['current_hp'] = $this->rescaleRentalHp($final['hp'], $final['max_hp'], $normalMaxHp);
            $rental['max_hp'] = $normalMaxHp;
            $rental['awakening_gauge'] = $result->awakening[$id]['gauge_after'];
            $rental['display_name'] = $memberSnapshots[$id]['display_name'];
        }
        unset($rental);
        $profile->rental_party = $rentalMembers;
        if ($otherworld && $resultType === UndergroundBattle::RESULT_VICTORY) {
            $profile->distorted_stone_balance--;
        } elseif (! $otherworld) {
            $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        }
        $profile->save();

        $projection = $this->partyProjector->project($result, $memberSnapshots, $combatCatalog);
        $projection['summary']['result'] = $resultType;
        $detailSnapshot = [
            'initial_state' => $projection['initial_state'],
            'portrait_events' => $projection['portrait_events'],
            'party' => [...$partySnapshot, 'party_id' => $party->id, 'members' => $memberSnapshots],
        ];
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'underground_party_id' => $party->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $this->alphaV1Catalog->explorationIdentity(),
            'activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION,
            'activity_key' => $huntingGroundKey,
            'encounter_key' => $encounterKey,
            'trial_run_key' => null,
            'trial_battle_index' => null,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => (int) ($result->metrics['damage_dealt'] ?? 0),
            'damage_received' => (int) ($result->metrics['damage_received'] ?? 0),
            'healing_done' => (int) ($result->metrics['effective_healing'] ?? 0),
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'statistics' => $this->statisticsProjector->fromParty($result, $leaderCombatantId),
            'xp_awarded' => $xpAwarded,
            'shard_delta' => $shardDelta,
            'combat_level_before' => $levelBefore,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $xpBefore,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $shardsBefore,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => $seed,
            'snapshot' => $this->battleStorage->compactSnapshot([
                'exploration_identity' => $this->alphaV1Catalog->explorationIdentity(),
                'hunting_ground' => [
                    'key' => $huntingGround['key'],
                    'name' => $huntingGround['name'],
                    'content_identity' => $huntingGround['content_identity'],
                    'item_level_min' => $huntingGround['item_level_min'],
                    'item_level_max' => $huntingGround['item_level_max'],
                ],
                'combat_rules_identity' => AlphaV1CombatRules::IDENTITY,
                'player_display_name' => $leaderDisplayName,
                'encounter_display_name' => count(array_unique($enemyLabels)) === 1
                    ? $enemyLabels[0].($enemyCount === 1 ? '' : ' ×'.$enemyCount)
                    : implode('・', $enemyLabels),
                'presentation_log_version' => UndergroundPartyBattleProjector::PRESENTATION_LOG_VERSION,
                'initial_state' => $detailSnapshot['initial_state'],
                'summary' => $projection['summary'],
                'portrait_events' => $detailSnapshot['portrait_events'],
                'party' => $detailSnapshot['party'],
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'progression_stats' => $leaderDefinition['progression_stats'],
                'combat_stats' => $leaderDefinition['combat_stats'],
                'allocated_stp' => $leaderSnapshot['effective_allocated_stp'],
                'equipment' => $leaderDefinition['equipment'],
                'skill_tree_identity' => $profile->skill_tree_identity,
                'targeting_contract_identity' => $this->alphaV1Catalog->targetingIdentity(),
                'encounter' => [
                    'key' => $encounterKey,
                    'enemy_keys' => $enemyKeys,
                    'weight_bps' => $encounter['weight'],
                    'xp_reward' => $averagedReward['xp'],
                    'shard_reward' => $baseShardReward,
                    'reward_multiplied_by_enemy_count' => false,
                ],
                'xp_curve' => $curve,
                'max_rounds' => $maxRounds,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
                'unspent_stp_before' => $unspentStpBefore,
                'unspent_stp_after' => $profile->unspent_stp,
                'stp_awarded' => $stpAwarded,
                'current_hp_before' => $currentHpBefore,
                'max_hp_before' => $maxHpBefore,
                'current_hp_after' => $profile->current_hp,
                'max_hp_after' => $maxHpAfter,
                'banked_shard_balance' => $profile->banked_shard_balance,
                'shining_kingdom_key' => [
                    'balance_before' => $keyBalanceBefore,
                    'entry_cost' => $huntingGround['entry_key_cost'],
                    'awarded' => $victoryReward['keys'],
                    'balance_after' => $profile->shining_kingdom_key_balance,
                ],
                'treasure' => $victoryReward['treasure'],
                'distorted_stones' => $victoryReward['distorted_stones'],
                'awakening' => $leaderFinalAwakening,
                'party_awakening' => $result->awakening,
                'drop' => [
                    'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                    'status' => 'pending',
                ],
            ]),
            'compaction_version' => UndergroundBattleStorage::COMPACTION_VERSION,
            'compacted_at' => $finishedAt,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        $snapshot = $battle->snapshot;
        $dropEncounter = $encounter;
        if (is_string($huntingGround['forced_drop_profile'] ?? null)) {
            $dropEncounter['drop_profile'] = $huntingGround['forced_drop_profile'];
        }
        if ($otherworld) {
            unset($snapshot['drop']);
            $snapshot['drops'] = $resultType === UndergroundBattle::RESULT_VICTORY
                ? $this->equipmentDrops->settleOtherworldVictory($profile, $battle, $huntingGroundKey, $seed) : [];
        } else {
            $snapshot['drop'] = $resultType === UndergroundBattle::RESULT_VICTORY
                ? $this->equipmentDrops->settleVictory(
                    $profile,
                    $battle,
                    $huntingGroundKey,
                    $dropEncounter,
                    $seed,
                    $huntingGround['drop_tier_key'],
                )
                : [
                    'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                    'status' => 'ineligible',
                ];
        }
        $battle->snapshot = $snapshot;
        $battle->save();
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $projection['rounds'],
            'presentation' => $this->battleStorage->detailPresentation($detailSnapshot),
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours()),
        ]);
        $this->imageRetention->retainSnapshotImages($battle, $detailSnapshot);
        $this->lendingRewards->settle($battle, $party);
        if ($resultType === UndergroundBattle::RESULT_VICTORY) {
            $this->recordActualContentClear($profile, 'hunting_ground', $huntingGroundKey);
        }

        return $battle->load('log');
    }

    /**
     * @param  array<string, mixed>  $equipment
     * @return array<string, int>
     */
    private function equipmentItemLevelsBySlot(array $equipment): array
    {
        $items = $equipment['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('Underground equipment item-level snapshot is invalid.');
        }
        $levels = [];
        foreach ($items as $item) {
            $slot = is_array($item) ? ($item['equipped_slot'] ?? null) : null;
            $itemLevel = is_array($item) ? ($item['item_level'] ?? null) : null;
            if (! is_string($slot)
                || ! in_array($slot, UndergroundEquipmentCatalog::EQUIPPED_SLOTS, true)
                || ! is_int($itemLevel)
                || $itemLevel < 1
                || isset($levels[$slot])) {
                throw new RuntimeException('Underground equipment item-level snapshot is invalid.');
            }
            $levels[$slot] = $itemLevel;
        }
        if (! isset($levels['weapon'])) {
            throw new RuntimeException('Underground equipment item-level snapshot requires a weapon.');
        }

        return $levels;
    }

    /**
     * @param array{
     *   label: string,
     *   content_identity: string,
     *   balance_manifest: string,
     *   required_trial_key: string|null,
     *   drop_tier_key: string|null,
     *   interbattle_heal_bps: int,
     *   first_clear_skill_points: int,
     *   encounters: list<string>,
     *   rewards: list<array{xp: int, shards: int}>
     * } $trial
     */
    private function resolveAndSettleTrialBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        array $trial,
        string $encounterKey,
        int $seed,
        UndergroundTrialRun $trialRun,
        int $trialBattleIndex,
        bool $isTrialBoss,
    ): UndergroundBattle {
        $secretary = $profile->secretary;
        if (! is_string($secretary->name) || $secretary->name === '') {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '名前のある秘書が必要です。',
            );
        }
        if (! is_string($profile->growth_path_key)) {
            throw new UndergroundRuntimeException(
                'underground_trial_locked',
                '地下に眠る古代遺跡はまだ解禁されていません。',
            );
        }
        $reward = $trial['rewards'][$trialBattleIndex - 1] ?? null;
        if (! is_array($reward)) {
            throw new UndergroundRuntimeException(
                'underground_trial_progress_invalid',
                '封印の地の報酬を解決できません。',
            );
        }
        $equipment = $this->equipmentLoadout->combatLoadout($profile);
        $maxHpBefore = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $currentHpBefore = min($profile->current_hp ?? $maxHpBefore, $maxHpBefore);
        $definition = $this->alphaV1Catalog->trialCombatDefinition(
            $trialRun->trial_key,
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
            $secretary->name,
            $currentHpBefore,
            $profile->skillAllocationMap(),
            $profile->custom_ai_rules,
        );
        $awakeningUnlocked = $this->awakeningUnlocked($profile);
        $definition['player_snapshot']['awakening'] = $this->awakeningSnapshot(
            $profile,
            $awakeningUnlocked,
            $secretary->name,
        );
        $enemy = $definition['catalog']->enemy($encounterKey);
        $encounterLabel = $enemy['label'] ?? null;
        if (! is_string($encounterLabel) || $encounterLabel === '') {
            throw new UndergroundRuntimeException(
                'underground_trial_progress_invalid',
                '封印の地の対戦相手を解決できません。',
            );
        }
        $growthPath = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $maxRounds = $this->catalog->maxRounds();
        $startedAt = Carbon::now();
        $result = $this->explorationCombat->fight(
            $definition['catalog'],
            $definition['player_snapshot'],
            $encounterKey,
            $seed,
            $maxRounds,
            (int) $growthPath['natural_recovery'],
        );
        $this->assertExplorationCombatResult(
            $result,
            $encounterKey,
            $seed,
            $maxRounds,
            $awakeningUnlocked,
            $profile->awakening_gauge,
        );
        $finishedAt = Carbon::now();
        $resultType = match ($result->winner) {
            'player' => UndergroundBattle::RESULT_VICTORY,
            'enemy' => UndergroundBattle::RESULT_DEFEAT,
            default => UndergroundBattle::RESULT_WITHDRAWAL,
        };
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $unspentStpBefore = $profile->unspent_stp;
        $xpAwarded = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $reward['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($reward['xp'], 4),
            default => 0,
        };
        $shardDelta = match ($resultType) {
            UndergroundBattle::RESULT_VICTORY => $reward['shards'],
            UndergroundBattle::RESULT_DEFEAT => intdiv($profile->shard_balance, 2) - $profile->shard_balance,
            default => 0,
        };
        $rewardSettlement = $this->applyRepeatableReward($profile, $xpAwarded, $shardDelta);
        $curve = $rewardSettlement['xp_curve'];
        $stpAwarded = $rewardSettlement['stp_awarded'];
        $maxHpAfter = $this->alphaV1Catalog->currentMaxHp(
            $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $equipment,
        );
        $remainingHpAfterBattle = min($result->playerRemainingHp, $maxHpAfter);
        $profile->current_hp = match ($resultType) {
            UndergroundBattle::RESULT_DEFEAT => $maxHpAfter,
            UndergroundBattle::RESULT_VICTORY => $isTrialBoss
                ? $remainingHpAfterBattle
                : min(
                    $maxHpAfter,
                    $result->playerRemainingHp + intdiv(
                        $maxHpAfter * $trial['interbattle_heal_bps'],
                        10_000,
                    ),
                ),
            default => $remainingHpAfterBattle,
        };
        $interbattleHealAmount = $resultType === UndergroundBattle::RESULT_VICTORY && ! $isTrialBoss
            ? $profile->current_hp - $remainingHpAfterBattle
            : 0;
        $profile->awakening_gauge = $result->awakening['gauge_after'];
        $firstClear = $this->settleTrial($profile, $trialRun, $resultType, $isTrialBoss, $finishedAt);
        $profile->next_battle_at = $finishedAt->copy()->addSeconds($this->catalog->cooldownSeconds());
        $profile->save();

        $projection = $this->alphaV1Projector->project(
            $result,
            $definition['catalog'],
            $this->secretaryPresenter->battleDisplayName($secretary),
            $encounterLabel,
        );
        if ($trialRun->trial_key === 'trial_01' && $isTrialBoss && $result->rounds >= 20) {
            $projection = $this->withTrialOneRoundTwentyWarning($projection);
        }
        $projection['summary']['result'] = $resultType;
        $trialProgress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)->where('trial_key', $trialRun->trial_key)->firstOrFail();
        $firstChallenge = $trialBattleIndex === 1 && $trialProgress->first_challenged_at === null;
        $challengeIntro = $firstChallenge ? match ($trialRun->trial_key) {
            'trial_01' => self::TRIAL_ONE_FIRST_CHALLENGE_INTRO,
            'trial_02' => self::TRIAL_TWO_FIRST_CHALLENGE_INTRO,
            default => null,
        } : null;
        $firstClearStory = $firstClear ? match ($trialRun->trial_key) {
            'trial_01' => [
                'title' => self::TRIAL_ONE_FIRST_CLEAR_STORY_TITLE,
                'body' => self::TRIAL_ONE_FIRST_CLEAR_STORY_BODY,
                'system_messages' => [
                    "{$secretary->name}は一つ目の封印の地を制覇した。",
                    'SPを40入手した。',
                    '地底マップが'.UndergroundAreaCapacity::forUnlockedLayers(1).'マス解禁された。',
                    '覚醒を習得した。',
                    '覚醒ゲージが解禁された。',
                ],
            ],
            'trial_02' => [
                'title' => self::TRIAL_TWO_FIRST_CLEAR_STORY_TITLE,
                'body' => str_replace('(秘書名)', $secretary->name, self::TRIAL_TWO_FIRST_CLEAR_STORY_BODY),
                'system_messages' => [
                    "{$secretary->name}は二つ目の封印の地を制覇した。",
                    'SPを40入手した。',
                    '地底マップが'.UndergroundAreaCapacity::forUnlockedLayers(2).'マスまで拡張された。',
                ],
            ],
            default => null,
        } : null;
        if ($trialProgress->first_challenged_at === null) {
            $trialProgress->first_challenged_at = $startedAt;
            $trialProgress->first_challenge_intro = $challengeIntro;
        }
        if ($firstClearStory !== null) {
            $trialProgress->first_clear_story = $firstClearStory;
        }
        $trialProgress->save();
        $detailSnapshot = [
            'initial_state' => $projection['initial_state'],
            'player_image_references' => $this->battleImageReferences($secretary),
        ];
        $battle = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'request_fingerprint' => $fingerprint,
            'runtime_identity' => $trial['content_identity'],
            'activity_type' => UndergroundBattle::ACTIVITY_TRIAL,
            'activity_key' => $trialRun->trial_key,
            'encounter_key' => $encounterKey,
            'trial_run_key' => $trialRun->run_key,
            'trial_battle_index' => $trialBattleIndex,
            'result' => $resultType,
            'rounds' => $result->rounds,
            'damage_dealt' => $result->damageDealt,
            'damage_received' => $result->damageReceived,
            'healing_done' => $result->effectiveHealing,
            'statistics_version' => UndergroundBattleStatisticsProjector::VERSION,
            'statistics' => $this->statisticsProjector->fromSolo($result),
            'xp_awarded' => $xpAwarded,
            'shard_delta' => $shardDelta,
            'combat_level_before' => $levelBefore,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $xpBefore,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $shardsBefore,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => $seed,
            'snapshot' => $this->battleStorage->compactSnapshot([
                'trial_content_identity' => $trial['content_identity'],
                'combat_rules_identity' => $result->rulesIdentity,
                'ai' => $definition['ai'],
                'player_display_name' => $this->secretaryPresenter->battleDisplayName($secretary),
                'player_image_references' => $detailSnapshot['player_image_references'],
                'encounter_display_name' => $encounterLabel,
                'presentation_log_version' => UndergroundAlphaV1BattleProjector::PRESENTATION_LOG_VERSION,
                'initial_state' => $projection['initial_state'],
                'summary' => $projection['summary'],
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'progression_stats' => $definition['progression_stats'],
                'combat_stats' => $definition['combat_stats'],
                'allocated_stp' => $profile->allocatedStp(),
                'equipment' => $definition['equipment'],
                'skill_tree_identity' => $profile->skill_tree_identity,
                'targeting_contract_identity' => $this->alphaV1Catalog->targetingIdentity(),
                'acquired_skill_nodes' => $definition['acquired_nodes'],
                'equipped_active_skills' => $definition['active_skills'],
                'effective_passive_modifiers' => $definition['passive_modifiers'],
                'encounter' => [
                    'key' => $encounterKey,
                    'xp_reward' => $reward['xp'],
                    'shard_reward' => $reward['shards'],
                ],
                'xp_curve' => $curve,
                'max_rounds' => $maxRounds,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
                'unspent_stp_before' => $unspentStpBefore,
                'unspent_stp_after' => $profile->unspent_stp,
                'stp_awarded' => $stpAwarded,
                'current_hp_before' => $currentHpBefore,
                'max_hp_before' => $maxHpBefore,
                'current_hp_after' => $profile->current_hp,
                'max_hp_after' => $maxHpAfter,
                'interbattle_heal_amount' => $interbattleHealAmount,
                'banked_shard_balance' => $profile->banked_shard_balance,
                'awakening' => $result->awakening,
                'trial_total_battles' => count($trial['encounters']),
                'trial_status' => $trialRun->status,
                'trial_next_battle_index' => $trialRun->next_battle_index,
                'challenge_intro' => $challengeIntro,
                'first_clear_story' => $firstClearStory,
                'drop' => [
                    'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                    'status' => 'pending',
                ],
            ]),
            'compaction_version' => UndergroundBattleStorage::COMPACTION_VERSION,
            'compacted_at' => $finishedAt,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
        $dropTierKey = $trial['drop_tier_key'] ?? null;
        $snapshot = $battle->snapshot;
        $snapshot['drop'] = is_string($dropTierKey)
            ? ($resultType === UndergroundBattle::RESULT_VICTORY
                ? $this->equipmentDrops->settleTrialVictory(
                    $profile,
                    $battle,
                    $trialRun->trial_key,
                    $dropTierKey,
                    $reward,
                    $seed,
                )
                : [
                    'identity' => $this->alphaV1Catalog->explorationDropConfig()['identity'],
                    'status' => 'ineligible',
                ])
            : null;
        $battle->snapshot = $snapshot;
        $battle->save();
        UndergroundBattleLog::query()->create([
            'underground_battle_id' => $battle->id,
            'actions' => $projection['rounds'],
            'presentation' => $this->battleStorage->detailPresentation($detailSnapshot),
            'expires_at' => $finishedAt->copy()->addHours($this->catalog->battleLogRetentionHours()),
        ]);
        $this->imageRetention->retainSnapshotImages($battle, $detailSnapshot);
        if ($resultType === UndergroundBattle::RESULT_VICTORY && $isTrialBoss) {
            $this->recordActualContentClear($profile, 'trial', $trialRun->trial_key);
        }

        return $battle->load('log');
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function withTrialOneRoundTwentyWarning(array $projection): array
    {
        $rounds = is_array($projection['rounds'] ?? null) ? $projection['rounds'] : [];
        $warning = [
            'type' => 'warning',
            'side' => 'system',
            'actor_name' => null,
            'target_name' => null,
            'label' => self::TRIAL_ONE_ROUND_TWENTY_WARNING,
            'amount' => 0,
        ];
        foreach ($rounds as &$round) {
            if (is_array($round) && ($round['round'] ?? null) === 20) {
                $actions = is_array($round['actions'] ?? null) ? $round['actions'] : [];
                array_unshift($actions, $warning);
                $round['actions'] = $actions;
                $projection['rounds'] = array_values($rounds);

                return $projection;
            }
        }
        unset($round);

        $rounds[] = ['round' => 20, 'actions' => [$warning], 'end_state' => null];
        usort(
            $rounds,
            static fn (mixed $left, mixed $right): int => (int) (is_array($left) ? ($left['round'] ?? 0) : 0)
                <=> (int) (is_array($right) ? ($right['round'] ?? 0) : 0),
        );
        $projection['rounds'] = $rounds;

        return $projection;
    }

    private function settleTrial(
        UndergroundProfile $profile,
        UndergroundTrialRun $run,
        string $resultType,
        bool $isTrialBoss,
        Carbon $finishedAt,
    ): bool {
        if ($resultType === UndergroundBattle::RESULT_WITHDRAWAL) {
            $run->status = UndergroundTrialRun::STATUS_WITHDRAWN;
            $run->next_battle_index = 1;
            $run->ended_at = $finishedAt;
            $run->save();

            return false;
        }
        if ($resultType === UndergroundBattle::RESULT_DEFEAT) {
            $run->status = UndergroundTrialRun::STATUS_DEFEATED;
            $run->next_battle_index = 1;
            $run->ended_at = $finishedAt;
            $run->save();

            return false;
        }
        if (! $isTrialBoss) {
            $run->next_battle_index++;
            $run->save();

            return false;
        }

        $progress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $run->trial_key)
            ->lockForUpdate()
            ->firstOrFail();
        $firstClear = $progress->first_cleared_at === null;
        if ($firstClear) {
            $progress->first_cleared_at = $finishedAt;
            $progress->save();
            $reward = $this->catalog->trial($run->trial_key)['first_clear_skill_points'];
            $profile->skill_points_total += $reward;
            $profile->skill_points_unspent += $reward;
            $this->reconcileTrialProgresses($profile, $finishedAt);
        }
        $unlockedAreaLayers = $this->catalog->trial($run->trial_key)['unlocked_area_layers'];
        if ($profile->unlocked_area_layers < $unlockedAreaLayers) {
            $profile->unlocked_area_layers = $unlockedAreaLayers;
        }
        $run->status = UndergroundTrialRun::STATUS_CLEARED;
        $run->next_battle_index = 1;
        $run->ended_at = $finishedAt;
        $run->save();

        return $firstClear;
    }

    private function reconcileTrialProgresses(
        UndergroundProfile $profile,
        ?Carbon $unlockedAt = null,
    ): void {
        $unlockedAt ??= Carbon::now();
        $cleared = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('first_cleared_at')
            ->pluck('trial_key')
            ->all();
        foreach ($this->catalog->trialKeys() as $trialKey) {
            $trial = $this->catalog->trial($trialKey);
            $required = $trial['required_trial_key'];
            if (is_string($required) && ! in_array($required, $cleared, true)) {
                continue;
            }
            UndergroundTrialProgress::query()->firstOrCreate(
                [
                    'underground_profile_id' => $profile->id,
                    'trial_key' => $trialKey,
                ],
                ['unlocked_at' => $unlockedAt],
            );
        }
    }

    /**
     * Check for a previously persisted request without taking the leader lock.
     * This keeps retries idempotent even if a selected lender was disabled
     * after the original battle had already settled.
     */
    private function explorationRequestExists(User $user, string $requestId): bool
    {
        $secretaryId = Secretary::query()
            ->where('user_id', $user->id)
            ->value('id');
        if (! is_int($secretaryId) && ! is_numeric($secretaryId)) {
            return false;
        }
        $profileId = UndergroundProfile::query()
            ->where('secretary_id', (int) $secretaryId)
            ->value('id');
        if (! is_int($profileId) && ! is_numeric($profileId)) {
            return false;
        }

        return UndergroundBattle::query()
            ->where('underground_profile_id', (int) $profileId)
            ->where('request_id', $requestId)
            ->exists();
    }

    /**
     * @return array{secretary_id:int, profile_id:int, combat_level:int, equipment_item_levels:array<string,int>}
     */
    private function partyLeaderSyncInputs(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $secretary = Secretary::query()->where('user_id', $user->id)->first();
            if (! $secretary instanceof Secretary) {
                throw new UndergroundRuntimeException(
                    'underground_secretary_missing',
                    '秘書がまだ作成されていません。',
                );
            }
            $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->lockForUpdate()->first();
            if (! $profile instanceof UndergroundProfile || ! is_string($profile->growth_path_key)) {
                throw new UndergroundRuntimeException(
                    'underground_exploration_locked',
                    '周囲の探索はまだ解禁されていません。',
                );
            }
            app(UndergroundRequestAdmission::class)->assertPreparationTime();
            $this->starterEquipment->reconcile($profile);
            $equipment = $this->equipmentLoadout->combatLoadout($profile);
            $itemLevels = $this->equipmentItemLevelsBySlot($equipment);
            ksort($itemLevels);

            return [
                'secretary_id' => (int) $secretary->id,
                'profile_id' => (int) $profile->id,
                'combat_level' => (int) $profile->combat_level,
                'equipment_item_levels' => $itemLevels,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function partyLeaderSyncInputsMatch(UndergroundProfile $profile, array $expected): bool
    {
        $secretary = $profile->secretary;
        if ((int) ($expected['profile_id'] ?? 0) !== (int) $profile->id
            || (int) ($expected['secretary_id'] ?? 0) !== (int) $secretary->id
            || (int) ($expected['combat_level'] ?? 0) !== (int) $profile->combat_level) {
            return false;
        }
        $equipment = $this->equipmentLoadout->combatLoadout($profile);
        $itemLevels = $this->equipmentItemLevelsBySlot($equipment);
        ksort($itemLevels);

        return ($expected['equipment_item_levels'] ?? null) === $itemLevels;
    }

    /**
     * Prepare borrowed snapshots in a short transaction. The returned
     * payload contains only detached source data needed by the combat
     * transaction; no borrowed Eloquent row is used after this method returns.
     *
     * @param  array{secretary_id:int, profile_id:int, combat_level:int, equipment_item_levels:array<string,int>}  $leaderSyncInputs
     * @param  list<int>  $secretaryIds
     * @return list<array{secretary_id:int, source_owner_user_id:int, snapshot:array<string,mixed>}>
     */
    private function prepareBorrowedPartySnapshots(
        User $leader,
        string $requestId,
        array $leaderSyncInputs,
        array $secretaryIds,
        bool $reserveImages = true,
    ): array {
        return DB::transaction(function () use ($leader, $requestId, $leaderSyncInputs, $secretaryIds, $reserveImages): array {
            $borrowed = $this->lockedBorrowedProfiles(
                $leader,
                $leaderSyncInputs['secretary_id'],
                $secretaryIds,
            );
            $prepared = [];
            foreach ($borrowed as $context) {
                $snapshot = $this->borrowedSnapshots->create(
                    $context['secretary'],
                    $context['profile'],
                    $leaderSyncInputs['combat_level'],
                    $leaderSyncInputs['equipment_item_levels'],
                    $leader,
                    $context['awakening_unlocked'],
                );
                $prepared[] = [
                    'secretary_id' => (int) $context['secretary']->id,
                    'source_owner_user_id' => (int) $context['secretary']->user_id,
                    'snapshot' => $snapshot,
                ];
            }
            app(UndergroundRequestAdmission::class)->assertPreparationTime();
            if ($reserveImages) {
                $this->imageRetention->reserveBattleImages(
                    $this->imageRetention->reservationKey($leaderSyncInputs['profile_id'], $requestId),
                    $prepared,
                    Carbon::now()->addHours($this->catalog->battleLogRetentionHours()),
                );
            }

            return $prepared;
        }, 3);
    }

    /**
     * @param  list<int>  $secretaryIds
     * @return array{leader:array<string,mixed>, members:list<array{secretary_id:int,display_name:string,current_hp:int,max_hp:int,awakening_gauge:int}>}
     */
    public function prepareRentalParty(User $user, array $secretaryIds): array
    {
        $leader = $this->partyLeaderSyncInputs($user);
        $borrowed = $this->prepareBorrowedPartySnapshots($user, '', $leader, $secretaryIds, false);
        $members = [];
        foreach ($borrowed as $context) {
            $snapshot = $context['snapshot'];
            $maxHp = (int) $snapshot['resources']['effective_max_hp'];
            $members[] = [
                'secretary_id' => $context['secretary_id'],
                'display_name' => $snapshot['display_name'],
                'current_hp' => $maxHp,
                'max_hp' => $maxHp,
                'awakening_gauge' => 0,
            ];
        }

        return ['leader' => $leader, 'members' => $members];
    }

    /** @param array<string,mixed> $expected */
    public function rentalPartyLeaderMatches(UndergroundProfile $profile, array $expected): bool
    {
        return $this->partyLeaderSyncInputsMatch($profile, $expected);
    }

    private function rescaleRentalHp(int $hp, int $oldMaxHp, int $newMaxHp): int
    {
        return $hp <= 0 ? 0 : max(1, min($newMaxHp, (int) floor($hp / max(1, $oldMaxHp) * $newMaxHp)));
    }

    private function assertSkillRebuildCompleted(UndergroundProfile $profile): void
    {
        if ($profile->skill_rebuild_required) {
            throw new UndergroundRuntimeException('underground_skill_rebuild_required', 'SPを全返還しました。技能を選び直し、装備する技を保存してから戦闘を再開してください。');
        }
    }

    private function lockedProfileForUser(User $user): UndergroundProfile
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->first();
        if (! $secretary instanceof Secretary) {
            throw new UndergroundRuntimeException(
                'underground_secretary_missing',
                '秘書がまだ作成されていません。',
            );
        }
        $profile = app(UndergroundProfileService::class)->lockForSecretary($secretary);
        if ($profile->growth_path_key !== null) {
            $this->starterEquipment->reconcile($profile);
        }

        return $profile;
    }

    /**
     * @param  list<int>  $secretaryIds
     * @return list<array{secretary: Secretary, profile: UndergroundProfile, awakening_unlocked: bool}>
     */
    private function lockedBorrowedProfiles(
        User $leader,
        int $leaderSecretaryId,
        array $secretaryIds,
    ): array {
        if (in_array($leaderSecretaryId, $secretaryIds, true)) {
            throw new UndergroundRuntimeException(
                'underground_party_self_borrow',
                '自分の秘書をborrow枠へ入れることはできません。',
            );
        }
        $lockOrder = $secretaryIds;
        sort($lockOrder, SORT_NUMERIC);
        /** @var Collection<int, UndergroundProfile> $profiles */
        $profiles = UndergroundProfile::query()
            ->whereIn('secretary_id', $lockOrder)
            ->orderBy('secretary_id')
            ->lockForUpdate()
            ->get();
        if ($profiles->count() !== count($secretaryIds)) {
            throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
        }
        foreach ($profiles as $profile) {
            if (! is_string($profile->growth_path_key)
                || $profile->skill_rebuild_required
                || $profile->underground_contract_completed_at === null) {
                throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
            }
        }

        /** @var Collection<int, Secretary> $secretaries */
        $secretaries = Secretary::query()
            ->whereIn('id', $lockOrder)
            ->orderBy('id')
            ->sharedLock()
            ->get();
        if ($secretaries->count() !== count($secretaryIds)) {
            throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
        }
        $secretaries->load('user');
        foreach ($secretaries as $secretary) {
            if ((int) $secretary->user_id === (int) $leader->id
                || ! is_string($secretary->name)
                || $secretary->name === ''
                || ! is_string($secretary->user->visitor_code)) {
                throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
            }
        }

        $settings = SecretaryLendingSetting::query()
            ->whereIn('secretary_id', $lockOrder)
            ->orderBy('secretary_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('secretary_id');
        foreach ($lockOrder as $secretaryId) {
            $setting = $settings->get($secretaryId);
            if (! $setting instanceof SecretaryLendingSetting
                || ! $setting->is_public
                || ! $setting->is_available) {
                throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
            }
        }

        $profileIds = $profiles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $equipment = UndergroundOwnedEquipment::query()
            ->whereIn('underground_profile_id', $profileIds)
            ->whereNotNull('equipped_slot')
            ->orderBy('underground_profile_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $skills = UndergroundSkillAllocation::query()
            ->whereIn('underground_profile_id', $profileIds)
            ->orderBy('underground_profile_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $images = SecretaryImage::query()
            ->whereIn('secretary_id', $lockOrder)
            ->orderBy('secretary_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($profiles as $profile) {
            $profile->setRelation(
                'ownedEquipment',
                new Collection($equipment->where('underground_profile_id', $profile->id)->values()->all()),
            );
            $profile->setRelation(
                'skillAllocations',
                new Collection($skills->where('underground_profile_id', $profile->id)->values()->all()),
            );
        }
        foreach ($secretaries as $secretary) {
            $secretary->setRelation(
                'images',
                new Collection($images->where('secretary_id', $secretary->id)->values()->all()),
            );
        }

        $bySecretary = $secretaries->keyBy('id');
        $profilesBySecretary = $profiles->keyBy('secretary_id');
        $result = [];
        foreach ($secretaryIds as $secretaryId) {
            $secretary = $bySecretary->get($secretaryId);
            $profile = $profilesBySecretary->get($secretaryId);
            if (! $secretary instanceof Secretary || ! $profile instanceof UndergroundProfile) {
                throw new UndergroundRuntimeException('underground_party_member_unavailable', '選んだ秘書は現在借りられません。');
            }
            $profile->setRelation('secretary', $secretary);
            $result[] = [
                'secretary' => $secretary,
                'profile' => $profile,
                'awakening_unlocked' => $this->awakeningUnlocked($profile),
            ];
        }

        return $result;
    }

    private function lockedActiveTrialRun(UndergroundProfile $profile): ?UndergroundTrialRun
    {
        $run = UndergroundTrialRun::query()
            ->where('underground_profile_id', $profile->id)
            ->where('status', UndergroundTrialRun::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $run instanceof UndergroundTrialRun) {
            return null;
        }

        $trial = $this->catalog->trial($run->trial_key);

        return $this->reconcileActiveTrialContent($run, $trial['content_identity']);
    }

    private function awakeningUnlocked(UndergroundProfile $profile): bool
    {
        $progress = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $this->catalog->firstTrialKey())
            ->lockForUpdate()
            ->first();

        return $progress?->first_cleared_at !== null;
    }

    private function reconcileActiveTrialContent(
        UndergroundTrialRun $run,
        string $currentContentIdentity,
    ): UndergroundTrialRun {
        if ($run->trial_content_identity === $currentContentIdentity) {
            return $run;
        }

        $run->trial_content_identity = $currentContentIdentity;
        $run->next_battle_index = 1;
        $run->status = UndergroundTrialRun::STATUS_ACTIVE;
        $run->started_at = Carbon::now();
        $run->ended_at = null;
        $run->save();

        return $run;
    }

    private function duplicateBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
        string $huntingGroundKey,
    ): ?UndergroundBattle {
        $battle = UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $battle instanceof UndergroundBattle) {
            return null;
        }
        $legacyShallowReplay = $huntingGroundKey === $this->alphaV1Catalog->explorationHuntingGroundKey()
            && $battle->activity_type === UndergroundBattle::ACTIVITY_EXPLORATION
            && $battle->activity_key === $huntingGroundKey
            && $battle->runtime_identity === 'secretary-underground-exploration-alpha-v1';
        if (! hash_equals($battle->request_fingerprint, $fingerprint) && ! $legacyShallowReplay) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の戦闘に使用されています。',
            );
        }

        return $battle->load([
            'log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
        ]);
    }

    private function duplicateTrialBattle(
        UndergroundProfile $profile,
        string $requestId,
        string $runKey,
    ): ?UndergroundBattle {
        $battle = UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $battle instanceof UndergroundBattle) {
            return null;
        }
        if ($battle->activity_type !== UndergroundBattle::ACTIVITY_TRIAL
            || $battle->trial_run_key !== $runKey) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の戦闘に使用されています。',
            );
        }

        return $battle->load([
            'log' => fn ($query) => $query->where('expires_at', '>', Carbon::now()),
        ]);
    }

    /** @return list<string> */
    private function drawExplorationEncounterKeys(
        string $huntingGroundKey,
        int $partySize,
        UndergroundRandom $random,
    ): array {
        $ground = $this->alphaV1Catalog->explorationHuntingGround($huntingGroundKey);
        $enemyCount = $this->alphaV1Catalog->explorationEnemyCountForPartySize($huntingGroundKey, $partySize);
        $rare = $ground['rare_encounter'] ?? null;
        if (is_array($rare)
            && $random->integer('runtime:rare-encounter:'.$huntingGroundKey, 1, 10_000) <= $rare['chance_bps']) {
            return array_fill(0, $enemyCount, $rare['key']);
        }
        $first = $this->alphaV1Catalog->weightedExplorationEncounter(
            $random->integer('runtime:encounter:'.$huntingGroundKey, 1, 10_000),
            $huntingGroundKey,
        );
        $firstEncounter = $this->alphaV1Catalog->explorationEncounter($first, $huntingGroundKey);
        if ($rare === null && $firstEncounter['drop_profile'] === 'rare') {
            return array_fill(0, $enemyCount, $first);
        }
        $normalEncounters = $rare === null
            ? array_values(array_filter(
                $this->alphaV1Catalog->explorationEncounters($huntingGroundKey),
                static fn (array $encounter): bool => $encounter['drop_profile'] !== 'rare',
            ))
            : null;
        $normalWeight = $normalEncounters === null
            ? 10_000
            : array_sum(array_column($normalEncounters, 'weight'));
        $keys = [$first];
        for ($index = 1; $index < $enemyCount; $index++) {
            $roll = $random->integer(
                'runtime:encounter:'.$huntingGroundKey.':slot:'.$index,
                1,
                $normalWeight,
            );
            $keys[] = $normalEncounters === null
                ? $this->alphaV1Catalog->weightedExplorationEncounter($roll, $huntingGroundKey)
                : $this->weightedEncounterKey($normalEncounters, $roll);
        }

        return $keys;
    }

    /**
     * @param  list<array{key:string,weight:int}>  $encounters
     */
    private function weightedEncounterKey(array $encounters, int $roll): string
    {
        $upper = 0;
        foreach ($encounters as $encounter) {
            $upper += $encounter['weight'];
            if ($roll <= $upper) {
                return $encounter['key'];
            }
        }

        throw new RuntimeException('Underground normal encounter weights are invalid.');
    }

    /**
     * @param  list<string>  $encounterKeys
     * @return array{xp:int,shards:int}
     */
    private function averagedExplorationEncounterReward(array $encounterKeys, string $huntingGroundKey): array
    {
        if ($encounterKeys === []) {
            throw new RuntimeException('Underground party encounter reward cannot be empty.');
        }
        $xp = 0;
        $shards = 0;
        foreach ($encounterKeys as $encounterKey) {
            $encounter = $this->alphaV1Catalog->explorationEncounter($encounterKey, $huntingGroundKey);
            $xp += $encounter['xp'];
            $shards += $encounter['shards'];
        }
        $count = count($encounterKeys);

        return [
            'xp' => intdiv($xp + intdiv($count, 2), $count),
            'shards' => intdiv($shards + intdiv($count, 2), $count),
        ];
    }

    /** @param array<string, mixed> $huntingGround */
    private function consumeExplorationEntryKey(
        UndergroundProfile $profile,
        array $huntingGround,
        int $executionCount = 1,
    ): void {
        $cost = $huntingGround['entry_key_cost'] ?? 0;
        if (! is_int($cost) || $cost < 0 || $executionCount < 1) {
            throw new RuntimeException('Underground exploration entry key cost is invalid.');
        }
        if ($cost > intdiv(PHP_INT_MAX, $executionCount)) {
            throw new RuntimeException('Underground exploration entry key cost overflowed.');
        }
        $totalCost = $cost * $executionCount;
        if ($profile->shining_kingdom_key_balance < $totalCost) {
            throw new UndergroundRuntimeException(
                'underground_shining_kingdom_key_insufficient',
                '輝きの王国の鍵が必要です。',
            );
        }
        $profile->shining_kingdom_key_balance -= $totalCost;
    }

    /**
     * @param  array<string, mixed>  $huntingGround
     * @param  array<string, mixed>  $encounter
     * @return array{shards:int,keys:int,distorted_stones:int,treasure:array{found:bool,base_g:int,multiplier:int,total_g:int}}
     */
    private function explorationVictoryReward(
        array $huntingGround,
        string $encounterKey,
        array $encounter,
        int $seed,
        bool $victory,
        ?int $baseGOverride = null,
    ): array {
        if (! $victory) {
            return ['shards' => 0, 'keys' => 0, 'distorted_stones' => 0, 'treasure' => ['found' => false, 'base_g' => 0, 'multiplier' => 1, 'total_g' => 0]];
        }
        $random = new UndergroundRandom($seed);
        $baseG = ($huntingGround['kind'] ?? 'hunting_ground') === 'vault'
            ? (int) $huntingGround['vault_base_g']
            : ($baseGOverride ?? (int) $encounter['shards']);
        $treasure = false;
        $multiplier = 1;
        if (($huntingGround['kind'] ?? null) === 'vault') {
            $rarityRoll = $random->integer('drop:rarity', 1, 10_000);
            $treasure = $rarityRoll > 7_317;
            $multiplier = $treasure ? (int) $huntingGround['treasure_multiplier'] : 1;
        }
        $keys = 0;
        $keyReward = $huntingGround['key_reward'] ?? null;
        if (is_array($keyReward)) {
            $rare = $huntingGround['rare_encounter'] ?? null;
            $keys = is_array($rare) && ($rare['key'] ?? null) === $encounterKey
                ? (int) $keyReward['rare_quantity']
                : ($random->integer('reward:shining-kingdom-key', 1, 10_000) <= $keyReward['normal_chance_bps'] ? 1 : 0);
        }
        $totalG = $baseG * $multiplier;
        $rare = $huntingGround['rare_encounter'] ?? null;
        $distortedStones = is_array($rare) && ($rare['key'] ?? null) === $encounterKey
            ? (int) ($rare['distorted_stone_quantity'] ?? 0) : 0;

        return [
            'shards' => $totalG,
            'keys' => $keys,
            'distorted_stones' => $distortedStones,
            'treasure' => ['found' => $treasure, 'base_g' => $baseG, 'multiplier' => $multiplier, 'total_g' => $totalG],
        ];
    }

    private function assertExplorationUnlocked(UndergroundProfile $profile): void
    {
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->lockForUpdate()
            ->first();
        if (! $intro instanceof UndergroundIntroProgress
            || $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || $profile->growth_path_key === null
            || $profile->growth_path_identity !== $this->alphaV1Catalog->growthIdentity()
            || $profile->growth_path_selected_at === null
            || $profile->skill_tree_identity !== $this->alphaV1Catalog->skillTreeIdentity()
            || $profile->skill_points_total < $this->alphaV1Catalog->initialSkillPoints()
            || $profile->skill_points_unspent > $profile->skill_points_total
            || $profile->combat_level < 1) {
            throw new UndergroundRuntimeException(
                'underground_exploration_locked',
                '周囲の探索はまだ解禁されていません。',
            );
        }
        $this->alphaV1Catalog->growthPath($profile->growth_path_key);
    }

    /** @param array<string, mixed> $huntingGround */
    private function assertHuntingGroundUnlocked(
        UndergroundProfile $profile,
        array $huntingGround,
    ): void {
        if (($huntingGround['kind'] ?? null) === 'otherworld') {
            $reason = $this->otherworldUnavailableReason($profile, $huntingGround['key'], $this->otherworldClearedKeys($profile));
            if ($reason !== null) {
                throw new UndergroundRuntimeException('underground_otherworld_locked', $reason);
            }
        }
        $requiredTrial = $huntingGround['required_trial_key'] ?? null;
        if ($requiredTrial === null) {
            return;
        }
        if (! is_string($requiredTrial)
            || ! UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->where('trial_key', $requiredTrial)
                ->whereNotNull('first_cleared_at')
                ->exists()) {
            throw new UndergroundRuntimeException(
                'underground_hunting_ground_locked',
                '黒晶洞は試練1を初回clearすると解禁されます。',
            );
        }
    }

    /** @param array<string,mixed> $huntingGround */
    private function assertSkippableHuntingGround(array $huntingGround): void
    {
        if ($huntingGround['kind'] === 'otherworld') {
            throw new UndergroundRuntimeException('underground_otherworld_skip_unavailable', '異世界の戦いでは戦闘をスキップできません。');
        }
    }

    private function assertRequestNotUsedByIntro(UndergroundProfile $profile, string $requestId): void
    {
        if (UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->exists()) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の操作に使用されています。',
            );
        }
    }

    private function duplicateSkipSettlement(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
    ): ?UndergroundSkipSettlement {
        $settlement = UndergroundSkipSettlement::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $settlement instanceof UndergroundSkipSettlement) {
            return null;
        }
        if (! hash_equals($settlement->request_fingerprint, $fingerprint)) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の操作に使用されています。',
            );
        }

        return $settlement;
    }

    private function duplicateSkipBatch(
        UndergroundProfile $profile,
        string $requestId,
        string $fingerprint,
    ): ?UndergroundSkipBatch {
        $batch = UndergroundSkipBatch::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $batch instanceof UndergroundSkipBatch) {
            return null;
        }
        if (! hash_equals($batch->request_fingerprint, $fingerprint)) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の操作に使用されています。',
            );
        }

        return $batch;
    }

    private function assertSkipRequestIdentityAvailable(UndergroundProfile $profile, string $requestId): void
    {
        $this->assertRequestNotUsedByIntro($profile, $requestId);
        if (UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->exists()) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別の戦闘に使用されています。',
            );
        }
        if (UndergroundSkipSettlement::query()
            ->where('underground_profile_id', $profile->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->exists()
            || UndergroundSkipBatch::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->exists()) {
            throw new UndergroundRuntimeException(
                'underground_request_conflict',
                '同じrequest IDが別のskip操作に使用されています。',
            );
        }
    }

    private function assertBulkSkipExecutionCount(int $executionCount): void
    {
        if ($executionCount < 1 || $executionCount > self::MAX_BULK_SKIP_EXECUTIONS) {
            throw new UndergroundRuntimeException(
                'underground_skip_count_invalid',
                'skip回数は1回以上1,000回以下で指定してください。',
            );
        }
    }

    private function bulkSkipTicketCost(int $unitCost, int $executionCount): int
    {
        if ($unitCost < 1 || $executionCount > intdiv(PHP_INT_MAX, $unitCost)) {
            throw new UndergroundRuntimeException(
                'underground_skip_count_invalid',
                'skip回数を確認してください。',
            );
        }

        return $unitCost * $executionCount;
    }

    private function lockedContentProgress(
        UndergroundProfile $profile,
        string $contentType,
        string $contentKey,
    ): UndergroundContentClearProgress {
        UndergroundContentClearProgress::query()->firstOrCreate([
            'underground_profile_id' => $profile->id,
            'content_type' => $contentType,
            'content_key' => $contentKey,
        ], [
            'actual_clear_count' => 0,
            'total_clear_count' => 0,
        ]);

        return UndergroundContentClearProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('content_type', $contentType)
            ->where('content_key', $contentKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function recordActualContentClear(
        UndergroundProfile $profile,
        string $contentType,
        string $contentKey,
    ): void {
        $progress = $this->lockedContentProgress($profile, $contentType, $contentKey);
        $progress->actual_clear_count++;
        $progress->total_clear_count++;
        $progress->save();
    }

    private function assertSkipUnlocked(UndergroundContentClearProgress $progress, int $required): void
    {
        if ($progress->actual_clear_count < $required) {
            throw new UndergroundRuntimeException(
                'underground_skip_locked',
                "実戦clearが{$required}回に達すると、このcontentでskipを使用できます。",
            );
        }
    }

    private function lockedSkipTicketBalance(User $user): UserSkipTicketBalance
    {
        UserSkipTicketBalance::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return UserSkipTicketBalance::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function assertSkipTicketBalance(UserSkipTicketBalance $balance, int $cost): void
    {
        if ($balance->balance < $cost) {
            throw new UndergroundRuntimeException(
                'underground_skip_ticket_insufficient',
                "skip ticketが{$cost}枚必要です。",
            );
        }
    }

    private function consumeSkipTickets(
        UserSkipTicketBalance $balance,
        UndergroundSkipSettlement $settlement,
        int $cost,
    ): void {
        $before = $balance->balance;
        $balance->balance -= $cost;
        $balance->save();
        $now = Carbon::now();
        DB::table('user_skip_ticket_ledger')->insert([
            'user_id' => $balance->user_id,
            'underground_battle_id' => null,
            'underground_party_member_id' => null,
            'underground_skip_settlement_id' => $settlement->id,
            'entry_key' => 'skip-consume:'.$settlement->id,
            'delta' => -$cost,
            'balance_before' => $before,
            'balance_after' => $balance->balance,
            'canonical_day' => $now->toDateString(),
            'metadata' => json_encode([
                'skip_identity' => $settlement->skip_identity,
                'content_type' => $settlement->content_type,
                'content_key' => $settlement->content_key,
                'request_id' => $settlement->request_id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function consumeBulkSkipTickets(
        UserSkipTicketBalance $balance,
        UndergroundSkipBatch $batch,
        int $cost,
    ): int {
        $before = $balance->balance;
        $balance->balance -= $cost;
        $balance->save();
        $now = Carbon::now();
        DB::table('user_skip_ticket_ledger')->insert([
            'user_id' => $balance->user_id,
            'underground_battle_id' => null,
            'underground_party_member_id' => null,
            'underground_skip_settlement_id' => null,
            'underground_skip_batch_id' => $batch->id,
            'entry_key' => 'bulk-skip-consume:'.$batch->id,
            'delta' => -$cost,
            'balance_before' => $before,
            'balance_after' => $balance->balance,
            'canonical_day' => $now->toDateString(),
            'metadata' => json_encode([
                'skip_identity' => $batch->skip_identity,
                'content_type' => $batch->content_type,
                'content_key' => $batch->content_key,
                'request_id' => $batch->request_id,
                'execution_count' => $batch->execution_count,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $balance->balance;
    }

    /**
     * @return array{
     *   combat_level_before: int,
     *   combat_level_after: int,
     *   combat_xp_before: int,
     *   combat_xp_after: int,
     *   shard_balance_before: int,
     *   shard_balance_after: int,
     *   stp_awarded: int,
     *   xp_curve: array{first_level_cost: int, cost_increment_per_level: int}
     * }
     */
    private function applyRepeatableReward(
        UndergroundProfile $profile,
        int $xpAwarded,
        int $shardDelta,
    ): array {
        $levelBefore = $profile->combat_level;
        $xpBefore = $profile->combat_xp;
        $shardsBefore = $profile->shard_balance;
        $profile->combat_xp += $xpAwarded;
        $profile->shard_balance += $shardDelta;
        $curve = $this->catalog->xpCurve();
        $profile->combat_level = $this->progression->levelAfterXp(
            $profile->combat_level,
            $profile->combat_xp,
            $curve['first_level_cost'],
            $curve['cost_increment_per_level'],
        );
        $stpAwarded = $this->settleLevelStp($profile, $levelBefore);

        return [
            'combat_level_before' => $levelBefore,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $xpBefore,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $shardsBefore,
            'shard_balance_after' => $profile->shard_balance,
            'stp_awarded' => $stpAwarded,
            'xp_curve' => $curve,
        ];
    }

    private function settleLevelStp(UndergroundProfile $profile, int $levelBefore): int
    {
        if ($profile->combat_level <= $levelBefore || $profile->growth_path_key === null) {
            return 0;
        }
        $path = $this->alphaV1Catalog->growthPath($profile->growth_path_key);
        $awarded = ($profile->combat_level - $levelBefore) * (int) $path['unspent_stp_per_level'];
        $profile->unspent_stp += $awarded;

        return $awarded;
    }

    private function assertCooldownElapsed(UndergroundProfile $profile): void
    {
        if ($profile->next_battle_at !== null && $profile->next_battle_at->isAfter(Carbon::now())) {
            throw new UndergroundRuntimeException(
                'underground_battle_cooldown',
                '次の戦闘を開始できる時刻まで待ってください。',
            );
        }
    }

    private function assertRequestId(string $requestId): void
    {
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException(
                'underground_request_id_invalid',
                'request IDを確認してください。',
            );
        }
    }

    private function assertExplorationCombatResult(
        BuildCombatResult $result,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        bool $awakeningUnlocked,
        int $awakeningGaugeBefore,
    ): void {
        $awakening = $this->untypedAwakeningResult($result);
        if (! is_array($awakening)
            || $result->rulesIdentity !== AlphaV1CombatRules::IDENTITY
            || $result->buildKey !== 'secretary_runtime'
            || $result->enemyKey !== $enemyKey
            || $result->seed !== $seed
            || $result->rounds < 1
            || $result->rounds > $maxRounds
            || ($result->winner === 'enemy' && $result->playerRemainingHp !== 0)
            || ($result->winner !== 'enemy'
                && ($result->playerRemainingHp < 1
                    || $result->playerRemainingHp > (int) ($awakening['final_max_hp'] ?? 0)))
            || ($awakening['identity'] ?? null) !== UndergroundAwakening::IDENTITY
            || ($awakening['unlocked'] ?? null) !== $awakeningUnlocked
            || ($awakening['gauge_before'] ?? null) !== ($awakeningUnlocked ? $awakeningGaugeBefore : 0)
            || ! is_int($awakening['gauge_after'] ?? null)
            || $awakening['gauge_after'] < 0
            || $awakening['gauge_after'] > UndergroundAwakening::GAUGE_MAX
            || ! is_int($awakening['gauge_gained'] ?? null)
            || $awakening['gauge_gained'] < 0
            || ! is_bool($awakening['triggered'] ?? null)
            || ! is_int($awakening['normal_max_hp'] ?? null)
            || ! is_int($awakening['final_max_hp'] ?? null)
            || $result->abnormalState !== []) {
            throw new UndergroundRuntimeException(
                'underground_combat_result_invalid',
                '戦闘結果を検証できなかったためsettlementを取り消しました。',
            );
        }
    }

    private function untypedAwakeningResult(BuildCombatResult $result): mixed
    {
        return $result->awakening;
    }

    /** @param array<string, bool|int|string|list<int>> $intent */
    private function fingerprint(array $intent): string
    {
        ksort($intent);

        return hash('sha256', json_encode([
            'runtime_identity' => $this->catalog->runtimeIdentity(),
            'intent' => $intent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array{activity_type: string, run_key: string} $intent */
    private function trialFingerprint(array $intent): string
    {
        ksort($intent);

        return hash('sha256', json_encode([
            'intent' => $intent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
