<?php

namespace App\Application;

use Illuminate\Support\Str;

final class AnnouncementBodyRenderer
{
    public function render(string $body): string
    {
        return Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);
    }
}
