<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="hakoniwa-application-version" content="{{ config('hakoniwa.application_version') }}">
    <title>箱庭諸島２S＋</title>
    <meta name="description" content="小さな島を開発し、人口・食料・産業を育てながら長く繁栄させる国造りゲームです。">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    @if (session('oauth_error'))
        <p class="server-alert" role="alert">{{ session('oauth_error') }}</p>
    @endif
    <div id="app"></div>
</body>
</html>
