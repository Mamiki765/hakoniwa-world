<?php

declare(strict_types=1);

/**
 * 4.6設計相談用の計算メモ。production/戦闘シミュレーターではない。
 * Laravel、DB、環境変数、ネットワーク、既存装備ファイルを読み書きしない。
 * 仮の「1 rating = 基準時0.01 percentage point」を比較にのみ使う。
 * min/mean/maxのいずれもOwner未決。浮動小数・丸めも本番契約ではない。
 *
 * php rating-probe.php --weapon-il=220 --armor-il=200 --item-il=200 --rating=840
 * php rating-probe.php --weapon-il=220 --armor-il=220 --item-il=220 --rating=840 --source=new --json
 */

function positiveInteger(mixed $value, string $name): int
{
    if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
        throw new InvalidArgumentException("{$name} は正の整数で指定してください。");
    }
    $result = filter_var($value, FILTER_VALIDATE_INT);
    if ($result === false) {
        throw new InvalidArgumentException("{$name} がこのPHPで扱える整数範囲外です。");
    }

    return $result;
}

function generationScale(float $itemLevel): float
{
    $result = 1.1 ** (max(0.0, $itemLevel - 200.0) / 10.0);
    if (! is_finite($result)) {
        throw new InvalidArgumentException('この試算では扱えない倍率です。入力を小さくしてください。');
    }

    return $result;
}

/** @return list<array<string, int|float|string>> */
function policyRows(int $weaponIl, int $armorIl, int $itemIl, int $baseRating, string $source): array
{
    // 旧保存値は再抽選せずそのまま、新生成案は同じ基準rollをIL倍率で増やす。
    $rating = $baseRating * ($source === 'new' ? generationScale((float) $itemIl) : 1.0);
    if (! is_finite($rating)) {
        throw new InvalidArgumentException('この試算では扱えないratingです。');
    }
    $references = [
        'min' => (float) min($weaponIl, $armorIl),
        'mean' => ($weaponIl / 2.0) + ($armorIl / 2.0),
        'max' => (float) max($weaponIl, $armorIl),
    ];
    $rows = [];
    foreach ($references as $policy => $reference) {
        $reference = max(200.0, $reference);
        $effectiveBps = $rating / generationScale($reference);
        $rows[] = [
            'policy' => $policy,
            'reference_il' => $reference,
            'requirement_factor' => generationScale($reference),
            'raw_rating' => $rating,
            'effective_percentage_points' => $effectiveBps / 100.0,
            // 旧案の「IL先行でも100%効率止まり」を採る場合との差も表示する。
            // 鉱石でbaseRating自体を強くすることの上限を決めるものではない。
            'no_extra_il_bonus_percentage_points' => min((float) $baseRating, $effectiveBps) / 100.0,
        ];
    }

    return $rows;
}

function number(float|int $value): string
{
    return number_format($value, 4, '.', '');
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('CLI専用です。');
    }
    $args = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '--json') {
            $args[substr($argument, 2)] = true;
            continue;
        }
        if (! preg_match('/^--(weapon-il|armor-il|item-il|rating|source)=(.+)$/D', $argument, $match)) {
            throw new InvalidArgumentException("不明な引数: {$argument}");
        }
        if (array_key_exists($match[1], $args)) {
            throw new InvalidArgumentException("重複した引数: {$match[1]}");
        }
        $args[$match[1]] = $match[2];
    }
    if (isset($args['help'])) {
        echo "設計用比較のみ。runtime仕様を決定するものではありません。\n";
        echo "php rating-probe.php [--weapon-il=220] [--armor-il=200] [--item-il=200] [--rating=840] [--source=legacy|new] [--json]\n";
        echo "ratingは基準世代の同じroll値。source=newではitem-ilの倍率を乗せて生成した仮ratingを使います。\n";
        exit(0);
    }
    $weaponIl = positiveInteger($args['weapon-il'] ?? '220', 'weapon-il');
    $armorIl = positiveInteger($args['armor-il'] ?? '200', 'armor-il');
    $itemIl = positiveInteger($args['item-il'] ?? '200', 'item-il');
    $baseRating = positiveInteger($args['rating'] ?? '840', 'rating');
    $source = $args['source'] ?? 'legacy';
    if (! in_array($source, ['legacy', 'new'], true)) {
        throw new InvalidArgumentException('sourceはlegacyまたはnewです。');
    }
    $scales = [];
    foreach ([180, 185, 200, 210, 220, 230, 240] as $il) {
        $scales[] = ['item_level' => $il, 'scale' => generationScale((float) $il)];
    }
    $report = [
        'status' => 'design_probe_only_not_canonical_combat',
        'source_sha_read' => '8b65d320a97caa0ca99735fc20445592f95a9c84',
        'formula_proposal' => '1.1 ** (max(0, IL - 200) / 10)',
        'rating_unit_assumption' => '100 rating = 1 percentage point at reference IL <= 200',
        'inputs' => compact('weaponIl', 'armorIl', 'itemIl', 'baseRating', 'source'),
        'scale_examples' => $scales,
        'current' => policyRows($weaponIl, $armorIl, $itemIl, $baseRating, $source),
        'armor_only_plus_10' => policyRows($weaponIl, $armorIl + 10, $itemIl, $baseRating, $source),
        'notes' => [
            'min/mean/max、IL先行時上限、rating単位は未決であり、このスクリプトは選ばない。',
            '表示数値はOPのpercentage pointで、総DPS増加率ではない。',
            '旧保存値はILから再抽選しない。newは新規生成案の比較のみ。',
            '実戦のHP・防御・会心上限・回復・技・敵・共鳴・貸出補正は計算しない。',
            'PHP floatは一時試算専用。本番の数値型・丸め・保存形式の提案ではない。',
        ],
    ];
    if (isset($args['json'])) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    echo "# 4.6 装備倍率・ratingの仮計算\n\n";
    echo "**設計比較専用。ゲームのDPS測定でも本番仕様でもありません。**\n\n";
    echo "武器IL {$weaponIl} / 鎧IL {$armorIl} / 対象IL {$itemIl} / 基準rating {$baseRating} / {$source}\n\n";
    echo "| IL | 世代倍率 |\n|---:|---:|\n";
    foreach ($scales as $row) {
        echo '| '.$row['item_level'].' | '.number($row['scale'])." |\n";
    }
    foreach (['current' => '現在の組合せ', 'armor_only_plus_10' => '鎧だけIL+10'] as $key => $label) {
        echo "\n## {$label}\n\n";
        echo "| 仮の基準式 | 基準IL | 必要値倍率 | rating | 換算効果（pt） | IL先行ボーナスなし案（pt） |\n";
        echo "|---|---:|---:|---:|---:|---:|\n";
        foreach ($report[$key] as $row) {
            echo '| '.$row['policy'].' | '.number($row['reference_il']).' | '.number($row['requirement_factor'])
                .' | '.number($row['raw_rating']).' | '.number($row['effective_percentage_points'])
                .' | '.number($row['no_extra_il_bonus_percentage_points'])." |\n";
        }
    }
    echo "\n## 読み方\n\n";
    foreach ($report['notes'] as $note) {
        echo "- {$note}\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
