<div class="manual-index">
    <nav class="manual-index-jump" aria-label="グループへ移動">
        @foreach ($groups as $group)
            <a href="#f{{ $loop->iteration }}">{{ $group['label'] }}</a>
        @endforeach
    </nav>
    @foreach ($groups as $group)
        <section class="manual-index-group" aria-label="{{ $group['label'] }}">
            <h3 id="f{{ $loop->iteration }}">{{ $group['label'] }}</h3>
            <ul class="manual-index-list">
                @foreach ($group['rows'] as $row)
                    <li class="manual-index-item">
                        <div class="manual-index-head">
                            <strong>{{ $row['name'] }}</strong>
                            <span class="manual-index-cost">{{ $row['cost'] }}</span>
                        </div>
                        @if ($row['place'] !== '')
                            <p class="manual-index-meta"><span>建てる場所</span>{{ $row['place'] }}</p>
                        @endif
                        <p>{{ $row['note'] }}</p>
                        @if ($row['rank_two'])
                            <p class="manual-index-rank"><span>ランク2</span>{{ $row['rank_two'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
