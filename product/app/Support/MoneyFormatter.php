<?php

namespace App\Support;

final class MoneyFormatter
{
    public function exact(int $money): string
    {
        return number_format($money).'億円';
    }

    /** @return array{display: string, bucket: string} */
    public function publicEstimate(int $money): array
    {
        if ($money < 500) {
            return ['display' => '500億円未満', 'bucket' => 'under_500'];
        }

        $bucket = $money < 1000 ? 500 : intdiv($money, 1000) * 1000;

        return [
            'display' => '約'.number_format($bucket).'億円',
            'bucket' => (string) $bucket,
        ];
    }
}
