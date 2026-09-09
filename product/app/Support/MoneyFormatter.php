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
        $display = number_format($bucket).'億円';
        if ($bucket >= 10_000) {
            $trillionTenths = intdiv($bucket, 1000);
            $wholeTrillions = intdiv($trillionTenths, 10);
            $fraction = $trillionTenths % 10;
            $display = number_format($wholeTrillions)
                .($fraction === 0 ? '' : '.'.$fraction)
                .'兆円';
        }

        return [
            'display' => '約'.$display,
            'bucket' => (string) $bucket,
        ];
    }
}
