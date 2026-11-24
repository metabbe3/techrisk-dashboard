{{-- DOHA service-impact TAB table (owner 2026-11-24, item 4). Styled with the
     tr-doha-table rules in resources/css/app.css — this repo's Tailwind build
     scans only compiled/JS sources, so a style that must exist regardless is
     plain CSS (hex colors only, dark via :root.dark). --}}
<div class="tr-doha-table-wrap">
    <table class="tr-doha-table">
        @if ($header !== null)
            <thead>
                <tr>
                    @foreach ($header as $cell)
                        <th>{{ $cell }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
