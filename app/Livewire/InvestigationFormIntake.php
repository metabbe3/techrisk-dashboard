<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\StoreInvestigationDocument;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Services\Ai\AiTextResult;
use App\Services\InvestigationForm\InvestigationFormExtractor;
use App\Services\InvestigationForm\InvestigationFormRenderer;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Services\Markdown\DocumentConverterService;
use App\Services\Notion\NotionReadService;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The no-login public intake page: /investigation/{token}.
 * A link is one recipient's personal key into the team's shared draft.
 * Steps: source (notion/file/paste) → AI extract → edit + verify → consent.
 * AI source text is DATA only; the AI suggests, never auto-submits.
 */
#[Layout('layouts.public')]
class InvestigationFormIntake extends Component implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    /** Hard cap on AI calls per page session (abuse control). */
    private const AI_CALL_CAP = 10;

    public ?InvestigationFormRecipient $recipient = null;

    public ?InvestigationFormRequest $formRequest = null;

    public int $aiCalls = 0;

    /** Source inputs (step 1). */
    public string $notionUrl = '';

    public string $pasteText = '';

    /** Uploaded source file (Livewire temp upload, pdf/docx/md/txt ≤10MB). */
    public $sourceFile;

    public string $name = '';

    public string $email = '';

    public string $jobRole = '';

    /** The shared draft: nested section.field => value plus chronology/action_items rows. */
    public array $answers = [];

    public array $chronology = [];

    public array $actionItems = [];

    public ?string $draftUpdatedAt = null;

    /** Concurrency guard: reload warning when the draft moved under us. */
    public ?string $loadedDraftAt = null;

    /** Notion's own refusal text when a provided URL can't be fetched (diagnostic). */
    public string $notionError = '';

    public array $missing = [];

    public array $verifyIssues = [];

    public bool $showVerify = false;

    public int $step = 1;

    public bool $submitted = false;

    public bool $invalidLink = false;

    public string $consentEmail = '';

    public bool $consentAccepted = false;

    public string $honeypot = '';

    public string $extractError = '';

    /** Step-2 notices: unreadable images on the page, truncated Notion source. */
    public array $sourceNotes = [];

    private ?AiTextResult $lastResult = null;

    public function mount(string $token): void
    {
        $recipient = InvestigationFormRecipient::query()
            ->where('token_hash', InvestigationFormRecipient::hashToken($token))
            ->first();

        // Generic invalid page for bad/expired/revoked/submitted — no oracle.
        if ($recipient === null || ! $recipient->linkIsValid()) {
            $this->invalidLink = true;

            return;
        }

        $this->recipient = $recipient;
        $this->formRequest = $recipient->request;
        $this->recipient->update(['last_opened_at' => now()]);

        $this->email = $recipient->email;
        $this->name = (string) ($this->formRequest->answers['header']['name'] ?? '');
        $this->jobRole = (string) ($this->formRequest->answers['header']['job_role'] ?? '');

        $this->loadDraft();
    }

    public function render()
    {
        return view('livewire.investigation-form-intake', [
            'incident' => $this->formRequest?->incident,
            'team' => $this->formRequest?->team,
            'prepareList' => InvestigationFormSettings::prepareList(),
            'requiredFields' => InvestigationFormSettings::requiredFields(),
            'statementOfTruth' => InvestigationFormSchema::STATEMENT_OF_TRUTH,
            'nowMax' => now()->format('Y-m-d\TH:i'),
        ])
            ->layout('layouts.public');
    }

    public function saveDraft(): void
    {
        $this->guardValidLink();
        $this->rateLimit(30);

        $this->checkDraftConcurrency();

        $this->persistDraft();
    }

    public function extractFromSource(): void
    {
        $this->guardValidLink();
        $this->rateLimit(10);

        if ($this->honeypot !== '') {
            throw ValidationException::withMessages(['notionUrl' => 'Validation failed.']);
        }

        if ($this->aiCalls >= self::AI_CALL_CAP) {
            throw ValidationException::withMessages(['notionUrl' => 'AI call limit reached for this session.']);
        }

        $source = $this->collectSource();

        if (trim($source) === '') {
            // A URL that couldn't be read (not shared with the integration) is a
            // different problem from "nothing provided" — say which.
            throw ValidationException::withMessages(['notionUrl' => trim($this->notionUrl) !== ''
                ? 'This Notion page could not be read'
                    .($this->notionError !== '' ? ' — Notion said: '.$this->notionError : '')
                    .' — check the link (Share → Copy link), or paste/upload the content instead.'
                : 'Provide a Notion URL, a file, or paste your material first.']);
        }

        $this->aiCalls++;

        /** @var InvestigationFormExtractor $extractor */
        $extractor = app(InvestigationFormExtractor::class);
        $incident = $this->formRequest->incident;
        $out = $extractor->extract(
            $source,
            $this->draftArray(),
            $incident === null ? null : [
                'no' => (string) $incident->incident_no,
                'title' => (string) $incident->title,
                'date' => (string) $incident->incident_date,
            ],
            InvestigationFormSettings::requiredFields(),
        );

        if (! $out['result']->success) {
            $this->extractError = (string) $out['result']->error;

            return;
        }

        if (! $out['ok']) {
            // The reply never parsed as JSON, even after the repair re-POST
            // — a silent empty step 2 is worse than a retry.
            $this->extractError = 'The AI reply could not be read — try again.';

            return;
        }

        $this->extractError = '';

        // Merge: extraction fills BLANK fields only — a manual edit or draft
        // value (non-empty) is never erased.
        foreach ($out['fields'] as $key => $value) {
            [$section, $field] = explode('.', $key, 2);

            if (trim((string) ($this->answers[$section][$field] ?? '')) === '') {
                $this->answers[$section][$field] = $value;
            }
        }

        // Rows are matched by their text, not by position — a re-extract
        // whose rows shifted must not duplicate or overwrite the draft's.
        $this->mergeListRows($this->chronology, $out['chronology'], 'event');
        $this->mergeListRows($this->actionItems, $out['action_items'], 'item');

        $this->missing = $out['missing'];
        $this->step = 2;

        $this->persistDraft();
    }

    public function goToReview(): void
    {
        $this->guardValidLink();
        $this->step = 3;
    }

    public function runVerify(): void
    {
        $this->guardValidLink();
        $this->rateLimit(10);

        if ($this->aiCalls >= self::AI_CALL_CAP) {
            throw ValidationException::withMessages(['verify' => 'AI call limit reached for this session.']);
        }

        $this->aiCalls++;

        $out = app(InvestigationFormExtractor::class)->verify($this->draftArray());
        $this->verifyIssues = $out['result']->success ? $out['issues'] : [];
        $this->showVerify = true;
    }

    public function addChronologyRow(): void
    {
        $this->chronology[] = ['time' => '', 'event' => ''];
    }

    public function removeChronologyRow(int $index): void
    {
        unset($this->chronology[$index]);
        $this->chronology = array_values($this->chronology);
    }

    public function addActionItemRow(): void
    {
        $this->actionItems[] = ['item' => '', 'due_date' => '', 'verification' => '', 'owner' => ''];
    }

    public function removeActionItemRow(int $index): void
    {
        unset($this->actionItems[$index]);
        $this->actionItems = array_values($this->actionItems);
    }

    /**
     * The consent gate before submit. Required fields block; AI issues are
     * warnings the user overrides by proceeding anyway.
     */
    public function goToConsent(): void
    {
        $this->guardValidLink();

        $missingRequired = $this->missingRequiredFields();

        if ($missingRequired !== []) {
            throw ValidationException::withMessages([
                'consent' => 'Required fields are still empty: '.implode(', ', array_slice($missingRequired, 0, 5)).(count($missingRequired) > 5 ? ' …' : ''),
            ]);
        }

        if (! $this->verifyIssues && ! $this->showVerify) {
            $this->runVerify();
        }

        $this->step = 4;
    }

    public function submit(): void
    {
        $this->guardValidLink();
        $this->rateLimit(5);

        if ($this->honeypot !== '') {
            throw ValidationException::withMessages(['consent' => 'Validation failed.']);
        }

        $missingRequired = $this->missingRequiredFields();

        if ($missingRequired !== []) {
            throw ValidationException::withMessages(['consent' => 'Required fields are still empty.']);
        }

        // Datetime fields come from `<input type="datetime-local">`, but the
        // shared draft may carry old free-text values — a non-canonical one
        // can't be rendered or compared, so reject with the offending fields.
        $badDates = [];
        $draft = $this->draftArray();

        foreach (InvestigationFormSchema::DATETIME_FIELDS as $key) {
            [$section, $field] = explode('.', $key, 2);
            $value = trim((string) ($draft[$section][$field] ?? ''));

            if ($value !== '' && InvestigationFormSchema::toPickerValue($value) !== $value) {
                $badDates[] = InvestigationFormSchema::flatFields()[$key];
            }
        }

        foreach ($draft['chronology'] as $i => $row) {
            if (($row['time'] ?? '') !== '' && InvestigationFormSchema::toPickerValue($row['time']) !== $row['time']) {
                $badDates[] = 'chronology row '.($i + 1).' time';
            }
        }

        if ($badDates !== []) {
            throw ValidationException::withMessages([
                'consent' => 'These date/time fields must be picked to the minute (use the picker): '.implode(', ', $badDates).'.',
            ]);
        }

        if (! $this->consentAccepted) {
            throw ValidationException::withMessages(['consent' => 'You must accept the Statement of Truth.']);
        }

        if (mb_strtolower(trim($this->consentEmail)) !== mb_strtolower(trim($this->email))) {
            throw ValidationException::withMessages(['consent' => 'The confirmation email does not match your email address.']);
        }

        // Render before the transaction — a render failure changes nothing.
        $draft = $this->finalDraftArray();

        /** @var InvestigationFormRenderer $renderer */
        $renderer = app(InvestigationFormRenderer::class);

        try {
            $docx = $renderer->docx($draft);
            $pdf = $renderer->pdf($draft);
        } catch (\Throwable $e) {
            Log::error('Investigation form render failed', ['request_id' => $this->formRequest->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['consent' => 'Could not generate the documents — nothing was submitted. Please try again.']);
        }

        $storeDocument = app(StoreInvestigationDocument::class);
        $incident = $this->formRequest->incident;
        $description = 'Investigation Form – '.$this->formRequest->team.' – '.now()->format('Y-m-d');

        try {
            DB::transaction(function () use ($draft, $docx, $pdf, $storeDocument, $incident, $description) {
                $docxDoc = $storeDocument->handle($incident, $docx, $description.'.docx', $description.' (DOCX)');
                $pdfDoc = $storeDocument->handle($incident, $pdf, $description.'.pdf', $description.' (PDF)');

                $this->formRequest->update([
                    'answers' => $draft,
                    'status' => InvestigationFormRequest::STATUS_SUBMITTED,
                    'submitter_email' => mb_strtolower(trim($this->email)),
                    'submitter_name' => trim($this->name),
                    'consented_at' => now(),
                    'submitter_ip' => request()->ip(),
                    'submitted_at' => now(),
                    'draft_updated_at' => now(),
                    'draft_updated_by_email' => mb_strtolower(trim($this->email)),
                    'docx_document_id' => $docxDoc->id,
                    'pdf_document_id' => $pdfDoc->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Investigation form submit failed', ['request_id' => $this->formRequest->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['consent' => 'Submission failed — please try again.']);
        }

        $this->submitted = true;
    }

    // --- helpers -----------------------------------------------------------

    private function guardValidLink(): void
    {
        // Re-check on every action: one submit/revoke/expiry kills all links.
        if ($this->recipient === null || ! $this->recipient->fresh()->linkIsValid()) {
            $this->invalidLink = true;

            throw ValidationException::withMessages(['token' => 'This link is no longer valid.']);
        }
    }

    private function checkDraftConcurrency(): void
    {
        $fresh = $this->formRequest->fresh();

        if ($fresh !== null && $this->loadedDraftAt !== null && $fresh->draft_updated_at !== null
            && $fresh->draft_updated_at->format('U.u') !== $this->loadedDraftAt) {
            throw ValidationException::withMessages([
                'draft' => 'A teammate saved changes while you were editing — reload to get their version (your unsaved edits will be lost).',
            ]);
        }
    }

    private function persistDraft(): void
    {
        $draft = $this->draftArray();

        $this->formRequest->update([
            'answers' => $draft,
            'draft_updated_by_email' => mb_strtolower(trim($this->email)),
            'draft_updated_at' => now(),
        ]);

        $this->loadedDraftAt = $this->formRequest->fresh()->draft_updated_at?->format('U.u');
    }

    private function loadDraft(): void
    {
        $answers = $this->formRequest->answers ?? [];

        foreach (InvestigationFormSchema::flatFields() as $key => $label) {
            [$section, $field] = explode('.', $key, 2);
            $this->answers[$section][$field] = (string) ($answers[$section][$field] ?? '');
        }

        $this->chronology = array_values(array_map(
            fn (array $row): array => ['time' => (string) ($row['time'] ?? ''), 'event' => (string) ($row['event'] ?? '')],
            (array) ($answers['chronology'] ?? []),
        ));

        $this->actionItems = array_values(array_map(
            fn (array $row): array => [
                'item' => (string) ($row['item'] ?? ''),
                'due_date' => (string) ($row['due_date'] ?? ''),
                'verification' => (string) ($row['verification'] ?? ''),
                'owner' => (string) ($row['owner'] ?? ''),
            ],
            (array) ($answers['action_items'] ?? []),
        ));

        $this->loadedDraftAt = $this->formRequest->draft_updated_at?->format('U.u');
        $this->draftUpdatedAt = $this->formRequest->draft_updated_at?->format('Y-m-d H:i');

        // The incident's own times fill still-blank datetime fields (draft
        // values win — prefill is blank-only, like the AI merge).
        $incident = $this->formRequest->incident;

        if ($incident !== null) {
            $prefill = [
                'header.incident_date' => $incident->incident_date,
                'summary.occurred_time' => $incident->incident_date,
                'summary.discovery_time' => $incident->discovered_at,
                'summary.stop_bleeding_time' => $incident->stop_bleeding_at,
            ];

            foreach ($prefill as $key => $value) {
                if ($value === null) {
                    continue;
                }

                [$section, $field] = explode('.', $key, 2);

                if (trim((string) ($this->answers[$section][$field] ?? '')) === '') {
                    $this->answers[$section][$field] = $value->format('Y-m-d\TH:i');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function draftArray(): array
    {
        $answers = [];

        foreach (InvestigationFormSchema::flatFields() as $key => $label) {
            [$section, $field] = explode('.', $key, 2);
            $answers[$section][$field] = (string) ($this->answers[$section][$field] ?? '');
        }

        $answers['chronology'] = array_values(array_filter(
            $this->chronology,
            fn (array $row): bool => trim((string) ($row['event'] ?? '')) !== '',
        ));

        $answers['action_items'] = array_values(array_filter(
            $this->actionItems,
            fn (array $row): bool => trim((string) ($row['item'] ?? '')) !== '',
        ));

        return $answers;
    }

    private function finalDraftArray(): array
    {
        $draft = $this->draftArray();

        // Identity fields ride the form's own header — but step 2 renders
        // them editable (the AI may have filled them from the source), so a
        // non-blank answer wins over the step-1 input, not the other way.
        $draft['header']['name'] = trim((string) ($draft['header']['name'] ?? '')) !== ''
            ? trim((string) $draft['header']['name'])
            : trim($this->name);
        $draft['header']['job_role'] = trim((string) ($draft['header']['job_role'] ?? '')) !== ''
            ? trim((string) $draft['header']['job_role'])
            : trim($this->jobRole);
        $draft['submitter_name'] = trim($this->name);

        return $draft;
    }

    /**
     * @return array<int, string> flat keys (or "chronology") that are empty but required
     */
    private function missingRequiredFields(): array
    {
        $draft = $this->draftArray();
        $missing = [];

        foreach (InvestigationFormSettings::requiredFields() as $key) {
            if ($key === 'chronology') {
                if ($draft['chronology'] === []) {
                    $missing[] = 'chronology (at least one row)';
                }

                continue;
            }

            [$section, $field] = explode('.', $key, 2);

            if (trim((string) ($draft[$section][$field] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * The page id in a Notion link: the LAST 32-hex (or dashed UUID) in the
     * URL path, hex-bounded. Never strip dashes first — that glues slug
     * letters onto the id ("RootCaus[e]-1022…", "v[1-0]-d352…") and sends a
     * shifted, nonexistent id.
     * ponytail: peek links (?p=<id>) resolve to their database; paste the page's own link.
     */
    private static function notionPageId(string $url): ?string
    {
        preg_match_all(
            '/(?<![0-9a-f])(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-f])/i',
            (string) strtok(trim($url), '?#'),
            $m,
        );

        return $m[0] === [] ? null : end($m[0]);
    }

    private function collectSource(): string
    {
        $parts = [];

        // User-typed material first (paste/upload): it's the authoritative
        // intent, and under the combined budget the linked page — the bulk
        // reference — is the part that may lose its tail (the notice says so).
        if (trim($this->pasteText) !== '') {
            $parts[] = "## Pasted text\n".mb_substr($this->pasteText, 0, 48000);
        }

        // Upload → DocumentConverterService (pdf/docx) or plain text (md/txt).
        if ($this->sourceFile instanceof TemporaryUploadedFile) {
            $ext = strtolower($this->sourceFile->getClientOriginalExtension());

            try {
                $bytes = $this->sourceFile->get();

                $converted = in_array($ext, ['pdf', 'docx', 'doc'], true)
                    ? app(DocumentConverterService::class)->convertRaw($bytes, $ext)
                    : null;
            } catch (\Throwable $e) {
                // A corrupted .docx / legacy .doc throws deep inside the
                // converter — a message beats a 500 (blade no longer offers
                // .doc, but an old upload may still carry it). The other
                // sources still go through.
                $this->sourceNotes[] = 'The uploaded file could not be read'
                    .($ext === 'doc' ? ' — please convert it to .docx or .pdf first.' : ' — try exporting it to .pdf or paste the text.');

                $converted = null;
            }

            if ($converted !== null) {
                $parts[] = "## Uploaded file ({$ext})\n".$converted;
            } elseif (in_array($ext, ['md', 'txt'], true)) {
                $parts[] = "## Uploaded file ({$ext})\n".mb_substr($bytes, 0, 48000);
            }
        }

        // Notion URL → page id → NotionReadService (same connection the
        // DingTalk agent reads through; no settings needed).
        if (($pageId = self::notionPageId($this->notionUrl)) !== null) {
            /** @var NotionReadService $notion */
            $notion = app(NotionReadService::class);
            $page = $notion->readFullPage($pageId);

            if ($page['body'] !== null) {
                $parts[] = "## Notion page\n".$page['body'];

                if ($page['images'] > 0) {
                    $this->sourceNotes[] = $page['images'].' image(s) on the Notion page could not be read'
                        .' — upload them as files on step 1 and extract again.';
                }

                if ($page['truncated']) {
                    $this->sourceNotes[] = 'The Notion page was longer than what the AI read ('
                        .number_format(NotionReadService::MAX_CONTENT_CHARS).' characters) — paste any missing sections into step 1.';
                }
            } else {
                // Keep Notion's own refusal — "could not be read" alone hides
                // whether it's page-sharing, a dead token or a deleted page.
                $this->notionError = Str::limit($page['error'] !== '' ? $page['error'] : 'connection failed', 200);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Draft rows stay; extracted rows whose identifying text isn't already
     * present are appended (position-based matching duplicated rows and lost
     * shifted ones on a re-extract).
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, string>>  $incoming
     */
    private function mergeListRows(array &$rows, array $incoming, string $key): void
    {
        if ($rows === []) {
            $rows = $incoming;

            return;
        }

        $existing = array_map(fn (array $row): string => mb_strtolower(trim((string) ($row[$key] ?? ''))), $rows);

        foreach ($incoming as $row) {
            if (! in_array(mb_strtolower(trim((string) ($row[$key] ?? ''))), $existing, true)) {
                $rows[] = $row;
                $existing[] = mb_strtolower(trim((string) ($row[$key] ?? '')));
            }
        }
    }

    public function getLastResultProperty(): ?AiTextResult
    {
        return $this->lastResult;
    }
}
