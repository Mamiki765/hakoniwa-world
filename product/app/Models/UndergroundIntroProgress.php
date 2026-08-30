<?php

namespace App\Models;

use App\Domain\Underground\Intro\UndergroundIntroStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Secretary-owned finite-state progress for the first Underground introduction.
 *
 * @property int $id
 * @property int $underground_profile_id
 * @property string $stage
 * @property string|null $shopkeeper_name
 * @property bool|null $special_loss_required
 * @property string|null $branch_identity
 * @property int|null $tutorial_battle_id
 * @property int|null $scripted_loss_battle_id
 * @property-read UndergroundProfile $profile
 * @property-read UndergroundBattle|null $tutorialBattle
 * @property-read UndergroundBattle|null $scriptedLossBattle
 */
final class UndergroundIntroProgress extends Model
{
    protected $fillable = [
        'underground_profile_id', 'stage', 'shopkeeper_name', 'special_loss_required', 'branch_identity',
        'tutorial_battle_id', 'scripted_loss_battle_id',
    ];

    protected $attributes = ['stage' => UndergroundIntroStage::NOT_STARTED];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'special_loss_required' => 'boolean',
            'tutorial_battle_id' => 'integer',
            'scripted_loss_battle_id' => 'integer',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }

    /** @return BelongsTo<UndergroundBattle, $this> */
    public function tutorialBattle(): BelongsTo
    {
        return $this->belongsTo(UndergroundBattle::class, 'tutorial_battle_id');
    }

    /** @return BelongsTo<UndergroundBattle, $this> */
    public function scriptedLossBattle(): BelongsTo
    {
        return $this->belongsTo(UndergroundBattle::class, 'scripted_loss_battle_id');
    }
}
