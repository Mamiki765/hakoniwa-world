<div class="manual-index">
    <ul class="manual-index-list">
        @foreach ($groups as $row)
            <li class="manual-index-item">
                <div class="manual-index-head">
                    <strong>{{ $row['name'] }}</strong>
                    <span class="manual-index-cost">{{ $row['cost'] }}</span>
                </div>
                <p>{{ $row['note'] }}</p>
            </li>
        @endforeach
    </ul>
</div>
