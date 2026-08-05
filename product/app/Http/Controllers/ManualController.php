<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class ManualController extends Controller
{
    /** @var array<string, string> */
    private const SECTIONS = [
        'index' => 'はじめに',
        'beginner' => '初級編',
        'intermediate' => '中級編',
        'advanced' => '上級編',
    ];

    public function __invoke(?string $section = null): View
    {
        $section ??= 'index';
        abort_unless(array_key_exists($section, self::SECTIONS), 404);
        $markdown = File::get(base_path("docs/manual/{$section}.md"));

        return view('manual', [
            'title' => self::SECTIONS[$section],
            'section' => $section,
            'sections' => self::SECTIONS,
            'content' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
