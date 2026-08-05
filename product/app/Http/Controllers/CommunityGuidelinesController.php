<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class CommunityGuidelinesController extends Controller
{
    public function __invoke(): View
    {
        $contactUrl = config('hakoniwa.community.contact_url');
        $scheme = is_string($contactUrl) ? parse_url($contactUrl, PHP_URL_SCHEME) : null;
        if (filter_var($contactUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            $contactUrl = null;
        }

        return view('community-guidelines', [
            'contactUrl' => $contactUrl,
            'content' => Str::markdown(File::get(base_path('docs/community-guidelines.md')), [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
