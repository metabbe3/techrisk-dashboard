{{-- Read-only modal: the submitted form's final answers. --}}
@php
use App\Services\InvestigationForm\InvestigationFormSchema;
$answers = $request->answers ?? [];
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-2 fi-ac">
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Team</span>
            <p class="font-medium">{{ $request->team }}</p>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Submitted</span>
            <p class="font-medium">{{ $request->submitted_at?->format('Y-m-d H:i') }}</p>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Submitter</span>
            <p class="font-medium">{{ $request->submitter_name }} ({{ $request->submitter_email }})</p>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Consent</span>
            <p class="font-medium">{{ $request->consented_at?->format('Y-m-d H:i') }}</p>
        </div>
    </div>

    @foreach (InvestigationFormSchema::SECTIONS as $sectionKey => $section)
        @continue(in_array($sectionKey, InvestigationFormSchema::LIST_SECTIONS))

        <div>
            <h4 class="font-semibold mb-1">{{ $section['label'] }}</h4>
            <dl class="divide-y divide-gray-100 dark:divide-gray-700 border rounded-lg">
                @foreach ($section['fields'] as $fieldKey => $label)
                    <div class="grid grid-cols-2 gap-2 px-3 py-1.5">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd>{{ $answers[$sectionKey][$fieldKey] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach

    @if (! empty($answers['chronology']))
        <div>
            <h4 class="font-semibold mb-1">Incident Chronology</h4>
            <table class="w-full border rounded-lg text-left">
                <thead>
                    <tr class="border-b">
                        <th class="px-3 py-1.5 w-1/4">Time</th>
                        <th class="px-3 py-1.5">Event</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($answers['chronology'] as $row)
                        <tr class="border-b last:border-b-0">
                            <td class="px-3 py-1.5 align-top">{{ $row['time'] ?? '—' }}</td>
                            <td class="px-3 py-1.5">{{ $row['event'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($answers['action_items']))
        <div>
            <h4 class="font-semibold mb-1">Action Improvement</h4>
            <table class="w-full border rounded-lg text-left">
                <thead>
                    <tr class="border-b">
                        <th class="px-3 py-1.5">Action item</th>
                        <th class="px-3 py-1.5">Due date</th>
                        <th class="px-3 py-1.5">Verification</th>
                        <th class="px-3 py-1.5">Owner</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($answers['action_items'] as $row)
                        <tr class="border-b last:border-b-0">
                            <td class="px-3 py-1.5">{{ $row['item'] ?? '—' }}</td>
                            <td class="px-3 py-1.5">{{ $row['due_date'] ?? 'TBD' }}</td>
                            <td class="px-3 py-1.5">{{ $row['verification'] ?? '—' }}</td>
                            <td class="px-3 py-1.5">{{ $row['owner'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div>
        <h4 class="font-semibold mb-1">Statement of Truth</h4>
        <p class="italic">{{ InvestigationFormSchema::STATEMENT_OF_TRUTH }}</p>
        <p class="mt-1 text-gray-500 dark:text-gray-400">Consented by {{ $request->submitter_email }} at {{ $request->consented_at?->format('Y-m-d H:i') }} (IP {{ $request->submitter_ip }})</p>
    </div>
</div>
