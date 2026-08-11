<?php

namespace App\Domain\MessageBoard;

use DomainException;
use Illuminate\Support\Carbon;

final class MessageBoardCooldownException extends DomainException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly Carbon $retryAt,
    ) {
        parent::__construct("次の投稿まで{$retryAfterSeconds}秒お待ちください。");
    }
}
