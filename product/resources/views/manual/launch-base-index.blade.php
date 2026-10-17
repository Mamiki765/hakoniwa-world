<table>
    <thead>
        <tr><th>基地</th><th>Lv</th><th>累積の経験値</th><th>1ターンに撃てる本数</th></tr>
    </thead>
    <tbody>
        @foreach ($groups as $base)
            @foreach ($base['levels'] as $level)
                <tr>
                    <td>{{ $loop->first ? $base['name'] : '' }}</td>
                    <td>{{ $level['level'] }}</td>
                    <td>{{ $level['experience'] }}</td>
                    <td>{{ $level['shots'] }}発</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>
