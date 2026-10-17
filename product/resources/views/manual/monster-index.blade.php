<div class="manual-index">
    <ul class="manual-index-list">
        @foreach ($groups as $row)
            <li class="manual-index-item">
                <div class="manual-index-head">
                    <strong>{{ $row['name'] }}</strong>
                    <span class="manual-index-cost">HP {{ $row['hp'] }}</span>
                </div>
                @if ($row['appearance'] !== '')
                    <p class="manual-index-meta"><span>出てくる</span>{{ $row['appearance'] }}</p>
                @endif
                <p class="manual-index-meta"><span>経験値</span>減らしたHP 1ごとに {{ $row['experience'] }}</p>
                <p class="manual-index-meta"><span>残骸</span>{{ $row['wreckage'] }}</p>
                <p>{{ $row['note'] }}</p>
            </li>
        @endforeach
    </ul>
</div>
