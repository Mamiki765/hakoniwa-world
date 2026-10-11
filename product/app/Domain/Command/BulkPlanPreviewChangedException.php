<?php

namespace App\Domain\Command;

use DomainException;

final class BulkPlanPreviewChangedException extends DomainException
{
    public const ERROR_CODE = 'bulk_plan_preview_changed';

    public function __construct()
    {
        parent::__construct('一括操作の確認内容が変わったか期限が切れました。確認し直してください。');
    }
}
