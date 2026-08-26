@php
    $theme = request()->cookie('hakoniwa_theme');
    $theme = in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
@endphp
<!doctype html>
<html lang="ja" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | 箱庭諸島２S＋</title>
    <meta name="description" content="箱庭諸島２S＋の遊び方を初級・中級・上級に分けて説明します。">
    @vite(['resources/css/manual.css'])
</head>
<body>
    <header class="manual-header">
        <a href="/">箱庭諸島２S＋</a>
        <span>ゲームマニュアル</span>
        <a href="/credits">クレジット</a>
        <a href="/community-guidelines">利用ルール</a>
    </header>
    <div class="manual-layout">
        <nav aria-label="マニュアル目次">
            @foreach ($sections as $key => $label)
                <a href="{{ $key === 'index' ? '/manual' : "/manual/{$key}" }}" @class(['current' => $section === $key])>{{ $label }}</a>
            @endforeach
        </nav>
        <main class="manual-content">
            {!! $content !!}
        </main>
    </div>
</body>
</html>
