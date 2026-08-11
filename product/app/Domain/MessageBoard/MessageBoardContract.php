<?php

namespace App\Domain\MessageBoard;

final class MessageBoardContract
{
    public const TIMELINE_LIMIT = 16;

    public const TARGET_RETENTION_LIMIT = 100;

    public const BODY_MAX_CHARACTERS = 140;

    public const COOLDOWN_SECONDS = 10;

    public const SECRET_COST_MONEY = 100;

    public const SECRET_PLACEHOLDER = '--秘密通信あり--';
}
