<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\StoreInvestigationDocument;
use App\Jobs\ExtractInvestigationFormSource;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Services\InvestigationForm\InvestigationFormDraftMerge;
use App\Services\InvestigationForm\InvestigationFormRenderer;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    // Locked: the browser could otherwise $wire.set('aiCalls', 0) and bypass
    // the cap on this no-login page (rate limit alone allows far more runs).
    #[Locked]
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

    /** True while the queued extraction (job) is running — drives wire:poll. */
    public bool $extractionRunning = false;

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

        // A reload mid-extraction resumes waiting (status lives on the
        // request — this is a public no-login page, the session can't hold it).
        $this->extractionRunning = $this->extractionStatus() === 'running';
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
        $this->formRequest->refresh();
        $this->checkDraftConcurrency();

        if ($this->honeypot !== '') {
            throw ValidationException::withMessages(['notionUrl' => 'Validation failed.']);
        }

        if ($this->aiCalls >= self::AI_CALL_CAP) {
            throw ValidationException::withMessages(['notionUrl' => 'AI call limit reached for this session.']);
        }

        if ($this->extractionStatus() === 'running') {
            throw ValidationException::withMessages(['notionUrl' => 'An extraction is already running — it will appear here (and in your email) when it finishes.']);
        }

        // Input presence is checked here (cheap); the Notion read itself runs
        // in the queued job — a slow read can no longer 504 the page (Dana's
        // edge proxy kills long Livewire requests).
        $hasFile = $this->sourceFile instanceof TemporaryUploadedFile;

        if (trim($this->notionUrl) === '' && trim($this->pasteText) === '' && ! $hasFile) {
            throw ValidationException::withMessages(['notionUrl' => 'Provide a Notion URL, a file, or paste your material first.']);
        }

        // Fresh attempt — a stale refusal or stacked notes from a previous
        // extract must not survive into this one.
        $this->sourceNotes = [];
        $this->extractError = '';
        $this->aiCalls++;

        $this->formRequest->update([
            'extraction_status' => 'running',
            'extraction_started_at' => now(),
            'extraction_result' => null,
        ]);

        ExtractInvestigationFormSource::dispatch(
            $this->formRequest->id,
            trim($this->notionUrl),
            mb_substr($this->pasteText, 0, 48000),
            $hasFile ? $this->sourceFile->getRealPath() : '',
            $hasFile ? $this->sourceFile->getClientOriginalName() : '',
            mb_strtolower(trim($this->email)),
        );

        $this->extractionRunning = true;
    }

    /** wire:poll hook — applies the queued job's outcome when it lands. */
    public function pollExtraction(): void
    {
        $this->guardValidLink();
        $this->rateLimit(60);
        $this->formRequest->refresh();

        $status = $this->extractionStatus();

        if ($status === 'done') {
            $result = (array) ($this->formRequest->extraction_result ?? []);

            // Re-load from the DB (the job merged the AI output there) — and
            // re-sync loadedDraftAt, or the next save hits the concurrency guard.
            $this->loadDraft();
            $this->missing = array_values((array) ($result['missing'] ?? []));
            $this->sourceNotes = array_values((array) ($result['notes'] ?? []));
            $this->step = 2;
            $this->extractionRunning = false;
        } elseif ($status === 'failed') {
            $result = (array) ($this->formRequest->extraction_result ?? []);
            $this->extractError = (string) ($result['error'] ?? 'Extraction failed — try again.');
            $this->extractionRunning = false;
        }
        // still running → keep polling
    }

    /**
     * Effective status with a dead-job guard: a 'running' status older than
     * the job timeout window means the worker died without failed() firing —
     * mark it failed so the user can retry.
     */
    private function extractionStatus(): string
    {
        $status = (string) ($this->formRequest->extraction_status ?? '');

        if ($status === 'running'
            && $this->formRequest->extraction_started_at !== null
            && $this->formRequest->extraction_started_at->lt(now()->subMinutes(ExtractInvestigationFormSource::STALE_AFTER_MINUTES))) {
            $this->formRequest->update([
                'extraction_status' => 'failed',
                'extraction_result' => ['notes' => [], 'missing' => [], 'error' => 'Extraction took too long — try again.', 'notion_error' => ''],
            ]);

            return 'failed';
        }

        return $status;
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
        $answers = InvestigationFormDraftMerge::prefill(
            InvestigationFormDraftMerge::normalizeRows($this->formRequest->answers ?? []),
            $this->formRequest->incident,
        );

        foreach (InvestigationFormSchema::flatFields() as $key => $label) {
            [$section, $field] = explode('.', $key, 2);
            $this->answers[$section][$field] = (string) ($answers[$section][$field] ?? '');
        }

        $this->chronology = $answers['chronology'];
        $this->actionItems = $answers['action_items'];

        $this->loadedDraftAt = $this->formRequest->draft_updated_at?->format('U.u');
        $this->draftUpdatedAt = $this->formRequest->draft_updated_at?->format('Y-m-d H:i');
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
        foreach (['name' => $this->name, 'job_role' => $this->jobRole] as $key => $fallback) {
            $draft['header'][$key] = trim((string) ($draft['header'][$key] ?? '')) !== ''
                ? trim((string) $draft['header'][$key])
                : trim($fallback);
        }
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
}
