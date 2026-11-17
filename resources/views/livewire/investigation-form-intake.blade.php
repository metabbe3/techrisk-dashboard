{{-- Public no-login intake page: /investigation/{token}. --}}
<div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-violet-50 dark:from-gray-900 dark:via-gray-900 dark:to-gray-800 px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-3xl">

        @if ($invalidLink)
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-8 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 mb-4">
                    <svg class="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">This link is not valid</h1>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                    The link may have expired, been revoked, or the form was already submitted.
                    Ask the dashboard team for a fresh link.
                </p>
            </div>
        @elseif ($submitted)
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-8 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 mb-4">
                    <svg class="h-8 w-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">Investigation form submitted</h1>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                    Thank you. The documents are attached to the incident in the dashboard, and every
                    link for this team is now closed. You can close this page.
                </p>
            </div>
        @else
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-7 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                {{-- Incident banner --}}
                <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                    <p class="text-xs uppercase tracking-wide text-indigo-600 dark:text-indigo-400 font-semibold">
                        Incident Investigation Form — {{ $team }}
                    </p>
                    <h1 class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                        {{ $incident?->no }} — {{ $incident?->title }}
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Date: {{ $incident?->incident_date?->format('Y-m-d') }}
                        · Link expires: {{ $formRequest?->expires_at?->format('Y-m-d H:i') }}
                        @if ($draftUpdatedAt)
                            · Draft saved {{ $draftUpdatedAt }} UTC
                        @endif
                    </p>
                </div>

                {{-- Step indicator --}}
                <ol class="mb-6 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    @foreach (['Source', 'Fill & review', 'Verify', 'Consent'] as $i => $label)
                        <li class="flex items-center gap-1.5 {{ $step >= $i + 1 ? 'text-indigo-600 dark:text-indigo-400 font-semibold' : '' }}">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full border {{ $step > $i + 1 ? 'bg-indigo-600 border-indigo-600 text-white' : ($step === $i + 1 ? 'border-indigo-600' : 'border-gray-300 dark:border-gray-600') }}">
                                {{ $i + 1 }}
                            </span>
                            {{ $label }}
                            @unless ($loop->last)<span class="mx-1">→</span>@endunless
                        </li>
                    @endforeach
                </ol>

                {{-- Step 1: source --}}
                @if ($step === 1)
                    <form wire:submit="extractFromSource" class="space-y-4">
                        <input type="text" wire:model="honeypot" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Your name <span class="text-red-500">*</span></label>
                                <input type="text" wire:model="name" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="Full name" />
                                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Your email <span class="text-red-500">*</span></label>
                                <input type="email" wire:model="email" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="you@company.com" />
                                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Job role</label>
                                <input type="text" wire:model="jobRole" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="e.g. Backend engineer" />
                            </div>
                        </div>

                        @if ($prepareList)
                            <div class="rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-4 text-sm text-indigo-900 dark:text-indigo-200">
                                <p class="font-semibold mb-1">What to prepare</p>
                                <ul class="list-disc list-inside space-y-0.5">
                                    @foreach ($prepareList as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notion page URL</label>
                            <input type="url" wire:model="notionUrl" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="https://www.notion.so/..." />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Paste the page link (Share → Copy link). If it can't be read, paste or upload the content below.</p>
                            @error('notionUrl')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Upload a file (pdf, docx, md, txt — max 10 MB)</label>
                            <input type="file" wire:model="sourceFile" accept=".pdf,.docx,.doc,.md,.txt" class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-1.5 file:text-white file:text-sm hover:file:bg-indigo-700" />
                            @error('sourceFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            <div wire:loading wire:target="sourceFile" class="mt-1 text-xs text-indigo-500">Uploading…</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Or paste your material (timeline, details…)</label>
                            <textarea wire:model="pasteText" rows="6" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="Paste anything the AI should read — a timeline, chat logs, notes…"></textarea>
                        </div>

                        @if ($extractError)
                            <div class="rounded-xl bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">{{ $extractError }}</div>
                        @endif

                        <button type="submit" wire:loading.attr="disabled" wire:target="extractFromSource"
                            class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-md hover:from-indigo-700 hover:to-violet-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
                            <span wire:loading.remove wire:target="extractFromSource">Extract with AI</span>
                            <span wire:loading wire:target="extractFromSource">Reading your material…</span>
                        </button>
                    </form>
                @endif

                {{-- Step 2: fill & review --}}
                @if ($step === 2 || $step === 3)
                    @if ($missing)
                        <div class="mb-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-800 dark:text-amber-200">
                            <p class="font-semibold mb-1">The AI could not find these — please fill them in:</p>
                            <ul class="list-disc list-inside">
                                @foreach ($missing as $item)
                                    <li>{{ $item['question'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="space-y-5">
                        @php $grouped = []; @endphp
                        @foreach (\App\Services\InvestigationForm\InvestigationFormSchema::SECTIONS as $sectionKey => $section)
                            @continue(in_array($sectionKey, \App\Services\InvestigationForm\InvestigationFormSchema::LIST_SECTIONS))
                            @php $grouped[] = [$sectionKey, $section]; @endphp
                        @endforeach

                        @foreach ($grouped as [$sectionKey, $section])
                            <fieldset class="border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                <legend class="px-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $section['label'] }}</legend>
                                <div class="space-y-3">
                                    @foreach ($section['fields'] as $fieldKey => $label)
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                {{ $label }}
                                                @if (in_array($sectionKey.'.'.$fieldKey, $requiredFields))
                                                    <span class="text-red-500">*</span>
                                                @endif
                                            </label>
                                            <textarea wire:model="answers.{{ $sectionKey }}.{{ $fieldKey }}" rows="2"
                                                class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-1.5 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none"></textarea>
                                            @error('answers.'.$sectionKey.'.'.$fieldKey)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach

                        <fieldset class="border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                            <legend class="px-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Incident Chronology @if (in_array('chronology', $requiredFields))<span class="text-red-500">*</span>@endif</legend>
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="w-1/4 pb-1">Time</th><th class="pb-1">Event</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($chronology as $i => $row)
                                        <tr>
                                            <td class="pr-2 py-1"><input type="text" wire:model="chronology.{{ $i }}.time" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" placeholder="YYYY.MM.DD HH:MM" /></td>
                                            <td class="py-1"><input type="text" wire:model="chronology.{{ $i }}.event" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" /></td>
                                            <td class="pl-2"><button type="button" wire:click="removeChronologyRow({{ $i }})" class="text-red-500 hover:text-red-700 text-xs">Remove</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <button type="button" wire:click="addChronologyRow" class="mt-2 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">+ Add row</button>
                        </fieldset>

                        <fieldset class="border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                            <legend class="px-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Action Improvement</legend>
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="pb-1">Action item</th><th class="pb-1 w-1/6">Due date</th><th class="pb-1">Verification</th><th class="pb-1 w-1/6">Owner</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($actionItems as $i => $row)
                                        <tr>
                                            <td class="pr-2 py-1"><input type="text" wire:model="actionItems.{{ $i }}.item" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" /></td>
                                            <td class="pr-2 py-1"><input type="text" wire:model="actionItems.{{ $i }}.due_date" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" placeholder="TBD ok" /></td>
                                            <td class="pr-2 py-1"><input type="text" wire:model="actionItems.{{ $i }}.verification" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" /></td>
                                            <td class="py-1"><input type="text" wire:model="actionItems.{{ $i }}.owner" class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1 text-sm text-gray-900 dark:text-gray-100" /></td>
                                            <td class="pl-2"><button type="button" wire:click="removeActionItemRow({{ $i }})" class="text-red-500 hover:text-red-700 text-xs">Remove</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <button type="button" wire:click="addActionItemRow" class="mt-2 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">+ Add row</button>
                        </fieldset>

                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft"
                                class="rounded-xl border border-indigo-300 dark:border-indigo-700 px-4 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 disabled:opacity-50">
                                Save draft
                            </button>
                            <button type="button" wire:click="runVerify" wire:loading.attr="disabled" wire:target="runVerify"
                                class="rounded-xl border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
                                <span wire:loading.remove wire:target="runVerify">Check again (AI)</span>
                                <span wire:loading wire:target="runVerify">Checking…</span>
                            </button>
                            <button type="button" wire:click="goToConsent"
                                class="ml-auto rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-md hover:bg-indigo-700">
                                Continue to consent
                            </button>
                        </div>

                        @if ($showVerify)
                            <div class="rounded-xl border {{ $verifyIssues ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20' }} p-4 text-sm">
                                @if ($verifyIssues)
                                    <p class="font-semibold text-amber-800 dark:text-amber-200 mb-1">The AI found these issues (you may still submit):</p>
                                    <ul class="list-disc list-inside text-amber-800 dark:text-amber-200">
                                        @foreach ($verifyIssues as $issue)
                                            <li>{{ $issue }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-emerald-700 dark:text-emerald-300">No issues found — the answers look complete and consistent.</p>
                                @endif
                            </div>
                        @endif

                        @error('draft')<div class="rounded-xl bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">{{ $message }}</div>@enderror
                    </div>
                @endif

                {{-- Step 4: consent --}}
                @if ($step === 4)
                    <form wire:submit="submit" class="space-y-4">
                        <input type="text" wire:model="honeypot" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-sm space-y-3">
                            @foreach (\App\Services\InvestigationForm\InvestigationFormSchema::SECTIONS as $sectionKey => $section)
                                @continue(in_array($sectionKey, \App\Services\InvestigationForm\InvestigationFormSchema::LIST_SECTIONS))
                                <div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-300">{{ $section['label'] }}</p>
                                    <dl class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                                        @foreach ($section['fields'] as $fieldKey => $label)
                                            <div class="py-1 grid grid-cols-2 gap-2">
                                                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                                <dd class="text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ $answers[$sectionKey][$fieldKey] ?? '—' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endforeach
                            <div>
                                <p class="font-semibold text-gray-700 dark:text-gray-300">Chronology ({{ count($chronology) }} rows) & Action items ({{ count($actionItems) }} rows)</p>
                                <p class="text-gray-500 dark:text-gray-400 text-xs mt-0.5">These are submitted as tables in the documents.</p>
                            </div>
                        </div>

                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-4">
                            <p class="font-semibold text-sm text-gray-700 dark:text-gray-300 mb-1">Statement of Truth</p>
                            <p class="text-sm italic text-gray-600 dark:text-gray-300">{{ $statementOfTruth }}</p>
                        </div>

                        <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="consentAccepted" class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500" />
                            <span>I confirm the answers above are true and complete, and I accept the Statement of Truth.</span>
                        </label>
                        @error('consentAccepted')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type your email ({{ $email }}) to confirm</label>
                            <input type="email" wire:model="consentEmail" class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:outline-none" placeholder="{{ $email }}" />
                        </div>

                        @error('consent')<div class="rounded-xl bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">{{ $message }}</div>@enderror

                        <div class="flex gap-3">
                            <button type="button" wire:click="goToReview" class="rounded-xl border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Back</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                                class="ml-auto rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-2 text-sm font-semibold text-white shadow-md hover:from-indigo-700 hover:to-violet-700 disabled:opacity-50">
                                <span wire:loading.remove wire:target="submit">Submit final documents</span>
                                <span wire:loading wire:target="submit">Generating & submitting…</span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </div>
</div>
