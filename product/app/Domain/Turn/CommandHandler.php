<?php

namespace App\Domain\Turn;

use App\Models\CommandDefinition;
use App\Models\NationCommandQueueItem;

interface CommandHandler
{
    public function supports(CommandDefinition $definition): bool;

    public function execute(
        CommandExecutionContext $context,
        NationCommandQueueItem $item,
    ): CommandExecutionResult;
}
