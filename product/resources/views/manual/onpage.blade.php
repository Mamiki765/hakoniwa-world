<nav class="manual-onpage" aria-label="このページの内容">
    <p>このページの内容</p>
    <ul>
        @foreach ($headings as $heading)
            <li><a href="#{{ $heading['id'] }}">{{ $heading['label'] }}</a></li>
        @endforeach
    </ul>
</nav>
