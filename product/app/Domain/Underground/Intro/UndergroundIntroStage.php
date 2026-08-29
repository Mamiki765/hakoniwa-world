<?php

namespace App\Domain\Underground\Intro;

final class UndergroundIntroStage
{
    public const NOT_STARTED = 'not_started';

    public const INITIAL_DESCENT = 'initial_descent';

    public const TUTORIAL_READY = 'tutorial_ready';

    public const ESCAPE_PENDING = 'escape_pending';

    public const RETURNED_AFTER_TUTORIAL = 'returned_after_tutorial';

    public const SHOPKEEPER_ENCOUNTER = 'shopkeeper_encounter';

    public const SHOPKEEPER_NAMING = 'shopkeeper_naming';

    public const SPECIAL_LOSS_PENDING = 'special_loss_pending';

    public const SPECIAL_LOSS_COMPLETE = 'special_loss_complete';

    public const SHOP_EXPLANATION = 'shop_explanation';

    public const UNDERGROUND_OPEN = 'underground_open';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_STARTED,
            self::INITIAL_DESCENT,
            self::TUTORIAL_READY,
            self::ESCAPE_PENDING,
            self::RETURNED_AFTER_TUTORIAL,
            self::SHOPKEEPER_ENCOUNTER,
            self::SHOPKEEPER_NAMING,
            self::SPECIAL_LOSS_PENDING,
            self::SPECIAL_LOSS_COMPLETE,
            self::SHOP_EXPLANATION,
            self::UNDERGROUND_OPEN,
        ];
    }
}
