<?php

namespace App\Domain\Map;

final class SeaAreaNameResolver
{
    /**
     * Immutable names for every chunk reachable through the first five
     * canonical expansion rotations from the initial World bounds.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        '-2:-1' => 'アレキサンドライト海域', '-1:-1' => 'アクアマリン海域', '0:-1' => 'アメシスト海域',
        '1:-1' => 'アンバー海域', '2:-1' => 'インペリアルトパーズ海域', '3:-1' => 'エメラルド海域',
        '4:-1' => 'オパール海域',
        '-2:0' => 'ガーネット海域', '-1:0' => 'カーネリアン海域', '0:0' => 'ペリドット海域',
        '1:0' => 'サファイア海域', '2:0' => 'サンストーン海域', '3:0' => 'シトリン海域', '4:0' => 'ジェイド海域',
        '-2:1' => 'スピネル海域', '-1:1' => 'スモーキークォーツ海域', '0:1' => 'セレナイト海域',
        '1:1' => 'ターコイズ海域', '2:1' => 'タイガーアイ海域', '3:1' => 'タンザナイト海域', '4:1' => 'ダイヤモンド海域',
        '-2:2' => 'トルマリン海域', '-1:2' => 'トパーズ海域', '0:2' => 'パール海域',
        '1:2' => 'ブラッドストーン海域', '2:2' => 'フローライト海域', '3:2' => 'ヘマタイト海域', '4:2' => 'ムーンストーン海域',
        '-2:3' => 'モルガナイト海域', '-1:3' => 'ラピスラズリ海域', '0:3' => 'ラブラドライト海域',
        '1:3' => 'ルビー海域', '2:3' => 'ローズクォーツ海域', '3:3' => 'ロードナイト海域', '4:3' => 'オニキス海域',
        '-2:4' => 'クリソプレーズ海域', '-1:4' => 'クンツァイト海域', '0:4' => 'ジルコン海域',
        '1:4' => 'マラカイト海域', '2:4' => 'アズライト海域', '3:4' => 'ベリル海域', '4:4' => 'コーラル海域',
    ];

    public function __construct(private readonly ChunkCoordinateService $chunks) {}

    public function forCoordinate(int $x, int $y): string
    {
        $chunk = $this->chunks->locate($x, $y);

        return self::NAMES[$chunk['chunk_x'].':'.$chunk['chunk_y']] ?? '原石の海域';
    }
}
