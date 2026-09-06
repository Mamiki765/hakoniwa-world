<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class ManualController extends Controller
{
    /** @var array<string, string> */
    private const SECTIONS = [
        'index' => 'マニュアルの入口',
        'beginner' => 'はじめの一歩',
        'intermediate' => '土地と施設',
        'economy' => '人口と資源',
        'advanced' => 'ミサイルと怪獣',
        'disasters' => '災害と防災',
        'ships' => '港と船',
        'trading-post' => '交易場',
        'secretary' => '地上の秘書',
        'underground' => '地底の探索',
        'combat' => '育成と戦闘',
        'equipment' => '地底装備',
        'faq' => '島の状態と困ったとき',
    ];

    public function __invoke(?string $section = null): View
    {
        $section ??= 'index';
        abort_unless(array_key_exists($section, self::SECTIONS), 404);
        $markdown = File::get(base_path("docs/manual/{$section}.md"));
        $content = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('manual', [
            'title' => self::SECTIONS[$section],
            'section' => $section,
            'sections' => self::SECTIONS,
            'content' => str_replace(
                ['<table>', '</table>'],
                ['<div class="manual-table-scroll" role="region" aria-label="表（横スクロールできます）" tabindex="0"><table>', '</table></div>'],
                $content,
            ),
        ]);
    }
}
