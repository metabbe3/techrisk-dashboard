{{-- GitHub-style contribution graph (owner 2026-11-20): Monday-first weeks as
     columns; each day colored by its highest-priority incident tier (1 running
     > 2 monitoring actions > 3 done; 0 = empty). Tooltip = native title,
     composed server-side in MyIncidents::contributionGraph(). Colors come from
     the --tr-contrib-* tokens in app.css.
     The months row uses the SAME grid definition as the cells (10px columns,
     3px gap) so every label sits exactly above its week; the year pills at
     the right switch the window ("12 months" = rolling default). --}}
@php
    $tiers = [
        1 => ['label' => 'In progress', 'class' => 'tr-contrib-t1'],
        2 => ['label' => 'Monitoring actions', 'class' => 'tr-contrib-t2'],
        3 => ['label' => 'Completed', 'class' => 'tr-contrib-t3'],
    ];
    $currentYear = $activeYear ?? null;
@endphp

<div class="tr-contrib">
    <div class="tr-contrib-wrap">
        <div class="tr-contrib-cal">
            <div class="tr-contrib-months">
                @foreach ($weeks as $week)
                    <span>{{ $week['month'] }}</span>
                @endforeach
            </div>

            <div class="tr-contrib-body">
                <div class="tr-contrib-weekdays" aria-hidden="true">
                    <span>Mon</span>
                    <span></span>
                    <span>Wed</span>
                    <span></span>
                    <span>Fri</span>
                    <span></span>
                    <span></span>
                </div>

                <div class="tr-contrib-cells">
                    @foreach ($weeks as $week)
                        @foreach ($week['days'] as $day)
                            @if ($day === null)
                                <span class="tr-contrib-cell tr-contrib-null"></span>
                            @else
                                <span
                                    class="tr-contrib-cell {{ $day['tier'] > 0 ? $tiers[$day['tier']]['class'] : 'tr-contrib-empty' }}"
                                    title="{{ $day['tooltip'] }}"
                                ></span>
                            @endif
                        @endforeach
                    @endforeach
                </div>
            </div>

            <div class="tr-contrib-legend">
                <span class="tr-contrib-legend-item"><span class="tr-contrib-cell tr-contrib-t1"></span> In progress</span>
                <span class="tr-contrib-legend-item"><span class="tr-contrib-cell tr-contrib-t2"></span> Monitoring actions</span>
                <span class="tr-contrib-legend-item"><span class="tr-contrib-cell tr-contrib-t3"></span> Completed</span>
                <span class="tr-contrib-legend-item"><span class="tr-contrib-cell tr-contrib-empty"></span> No incidents</span>
            </div>
        </div>

        <div class="tr-contrib-years">
            @foreach ($years as $year)
                <a
                    href="{{ request()->url() }}?year={{ $year }}"
                    class="tr-contrib-year {{ $currentYear === $year ? 'tr-contrib-year-active' : '' }}"
                >{{ $year }}</a>
            @endforeach
        </div>
    </div>
</div>
