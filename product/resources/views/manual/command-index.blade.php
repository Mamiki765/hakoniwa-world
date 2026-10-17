<div class="manual-index">
    <nav class="manual-index-jump" aria-label="グループへ移動">
        @foreach ($groups as $group)
            <a href="#c{{ $loop->iteration }}">{{ $group['label'] }}</a>
        @endforeach
    </nav>
    @foreach ($groups as $group)
        <section class="manual-index-group" aria-label="{{ $group['label'] }}">
            <h3 id="c{{ $loop->iteration }}">{{ $group['label'] }}</h3>
            <ul class="manual-index-list">
                @foreach ($group['rows'] as $row)
                    <li class="manual-index-item">
                        <div class="manual-index-head">
                            <strong>{{ $row['name'] }}</strong>
                            <span class="manual-index-cost">{{ $row['cost'] }}</span>
                            <span @class(['manual-turn', 'manual-turn-free' => ! $row['turn']])>{{ $row['turn'] ? 'ターンを使う' : 'ターンを使わない' }}</span>
                        </div>
                        <p>{{ $row['note'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
