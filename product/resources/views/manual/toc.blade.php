@foreach ($groups as $group => $pages)
    <p class="manual-toc-group">{{ $group }}</p>
    <ul>
        @foreach ($pages as $key => $label)
            <li><a href="{{ \App\Http\Controllers\ManualController::href($key) }}" @if ($section === $key) class="current" aria-current="page" @endif>{{ $label }}</a></li>
        @endforeach
    </ul>
@endforeach
