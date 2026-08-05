<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>利用ルール | 箱庭諸島２S＋</title>
    <meta name="description" content="箱庭諸島２S＋の禁止行為と通報・異議申立ての連絡方法です。">
    @vite(['resources/css/manual.css'])
</head>
<body>
    <header class="manual-header">
        <a href="/">箱庭諸島２S＋</a>
        <span>利用ルール</span>
    </header>
    <main class="standalone-content">
        {!! $content !!}
        @if ($contactUrl !== null)
            <p class="contact-action"><a href="{{ $contactUrl }}" rel="external nofollow">通報・異議申立て窓口を開く</a></p>
        @else
            <p class="contact-unavailable">現在、連絡窓口を準備しています。</p>
        @endif
    </main>
</body>
</html>
