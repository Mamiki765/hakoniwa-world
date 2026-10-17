@php
    $theme = request()->cookie('hakoniwa_theme');
    $theme = in_array($theme, ['system', 'light', 'dark', 'old-light', 'old-dark', 'skyblue', 'autumn', 'black'], true) ? $theme : 'system';
@endphp
<!doctype html>
<html lang="ja" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | 箱庭諸島２S＋</title>
    <meta name="description" content="箱庭諸島２S＋の遊び方と、施設・コマンドの索引です。">
    @vite(['resources/css/manual.css'])
</head>
<body class="manual-page">
    <header class="manual-header">
        <a class="manual-brand" href="/">箱庭諸島２S＋</a>
        <span class="manual-kind">ゲームマニュアル</span>
        <nav class="manual-header-links" aria-label="関連ページ">
            <a href="/credits">クレジット</a>
            <a href="/community-guidelines">利用ルール</a>
        </nav>
    </header>
    <div class="manual-layout">
        <aside class="manual-side">
            <nav aria-label="マニュアル目次">
                @include('manual.toc')
            </nav>
        </aside>
        <details class="manual-toc">
            <summary>目次<span class="manual-toc-current">{{ $title }}</span></summary>
            <nav aria-label="マニュアル目次（折りたたみ）">
                @include('manual.toc')
            </nav>
        </details>
        <main class="manual-content">
            <article class="manual-article">
                {!! $content !!}
            </article>
            <nav class="manual-pager" aria-label="前後のページ">
                @if ($previous)
                    <a class="manual-pager-prev" href="{{ $previous['href'] }}"><small>前へ</small>{{ $previous['label'] }}</a>
                @endif
                @if ($next)
                    <a class="manual-pager-next" href="{{ $next['href'] }}"><small>次へ</small>{{ $next['label'] }}</a>
                @endif
            </nav>
        </main>
    </div>
</body>
</html>
