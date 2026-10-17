<table>
    <thead>
        <tr><th>周りの森・記念碑などの数</th><th>農場</th><th>大農場</th></tr>
    </thead>
    <tbody>
        @foreach ($groups as $row)
            <tr><td>{{ $row['protection'] }}</td><td>{{ $row['farm'] }}</td><td>{{ $row['large_farm'] }}</td></tr>
        @endforeach
    </tbody>
</table>
