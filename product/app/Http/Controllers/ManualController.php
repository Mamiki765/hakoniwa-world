<?php

namespace App\Http\Controllers;

use App\Services\ManualIndexPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class ManualController extends Controller
{
    /** @var array<string, array<string, string>> 目次の見出しごとのページ。並びがそのまま目次と「前へ・次へ」の順になる。 */
    public const GROUPS = [
        'はじめに' => [
            'index' => 'マニュアルの入口',
            'beginner' => 'はじめの一歩',
        ],
        '島づくり' => [
            'island' => '人口と資源',
            'weather' => '天候と災害',
            'defense' => '怪獣と防衛',
            'sea' => '海と船',
            'trading-post' => '交易場',
        ],
        '秘書と地底' => [
            'secretary' => '地上の秘書',
            'underground' => '地底の冒険',
        ],
        '索引' => [
            'facilities' => '施設の索引',
            'commands' => 'コマンドの索引',
        ],
        '困ったとき' => [
            'faq' => '島の状態とよくある質問',
        ],
    ];

    /** @var array<string, string> 作り直す前のページ。外から貼られたリンクのために、近い新しいページへ送る。 */
    public const LEGACY = [
        'intermediate' => 'facilities',
        'economy' => 'island',
        'advanced' => 'defense',
        'disasters' => 'weather',
        'ships' => 'sea',
        'combat' => 'underground',
        'equipment' => 'underground',
    ];

    private const INDEX_PLACEHOLDERS = [
        'facilities' => 'manual.facility-index',
        'ships' => 'manual.ship-index',
        'commands' => 'manual.command-index',
        'skills' => 'manual.skill-index',
    ];

    public function __invoke(ManualIndexPresenter $indexes, ?string $section = null): View|RedirectResponse
    {
        $section ??= 'index';
        if (isset(self::LEGACY[$section])) {
            return redirect('/manual/'.self::LEGACY[$section], 301);
        }
        $pages = array_merge(...array_values(self::GROUPS));
        abort_unless(array_key_exists($section, $pages), 404);

        $content = Str::markdown($this->withRulesetValues(File::get(base_path("docs/manual/{$section}.md"))), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $content = $this->withIndexes($content, $indexes);
        [$content, $headings] = $this->withHeadingAnchors($content);
        if (count($headings) >= 3) {
            // ページ名（h1）のすぐ下に「このページの内容」を出す。
            $content = preg_replace('#</h1>#', '</h1>'.view('manual.onpage', ['headings' => $headings])->render(), $content, 1) ?? $content;
        }
        $content = str_replace(
            ['<table>', '</table>'],
            ['<div class="manual-table-scroll" role="region" aria-label="表（横スクロールできます）" tabindex="0"><table>', '</table></div>'],
            $content,
        );

        $keys = array_keys($pages);
        $position = (int) array_search($section, $keys, true);
        $neighbor = static fn (int $offset): ?array => isset($keys[$position + $offset])
            ? ['href' => self::href($keys[$position + $offset]), 'label' => $pages[$keys[$position + $offset]]]
            : null;

        return view('manual', [
            'title' => $pages[$section],
            'section' => $section,
            'groups' => self::GROUPS,
            'previous' => $neighbor(-1),
            'next' => $neighbor(1),
            'content' => $content,
        ]);
    }

    public static function href(string $section): string
    {
        return $section === 'index' ? '/manual' : "/manual/{$section}";
    }

    /** 本文中の `{{ruleset:trading_post.player.active_listing_limit}}` を、いまの Ruleset の数値に置き換える。 */
    private function withRulesetValues(string $markdown): string
    {
        return preg_replace_callback('/\{\{ruleset:([a-z0-9_.]+)\}\}/', static function (array $match): string {
            $value = data_get(config('hakoniwa.ruleset', []), $match[1]);

            return is_int($value) ? number_format($value) : '（ゲーム画面で確認してください）';
        }, $markdown) ?? $markdown;
    }

    /** 本文中の段落 `[[index:名前]]` を、Ruleset から組んだ索引の表に置き換える。 */
    private function withIndexes(string $html, ManualIndexPresenter $indexes): string
    {
        return preg_replace_callback('#<p>\[\[index:([a-z]+)\]\]</p>#', function (array $match) use ($indexes): string {
            $view = self::INDEX_PLACEHOLDERS[$match[1]] ?? null;
            if ($view === null) {
                return '';
            }

            return view($view, ['groups' => match ($match[1]) {
                'facilities' => $indexes->facilities(),
                'ships' => $indexes->ships(),
                'commands' => $indexes->commands(),
                default => $indexes->skills(),
            }])->render();
        }, $html) ?? $html;
    }

    /**
     * 大見出しに飛び先を付け、ページ上部の「このページの内容」に使う。
     *
     * @return array{0: string, 1: list<array{id: string, label: string}>}
     */
    private function withHeadingAnchors(string $html): array
    {
        $headings = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $match) use (&$headings): string {
            $id = 's'.(count($headings) + 1);
            $headings[] = ['id' => $id, 'label' => html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5)];

            return "<h2 id=\"{$id}\">{$match[1]}</h2>";
        }, $html) ?? $html;

        return [$html, $headings];
    }
}
