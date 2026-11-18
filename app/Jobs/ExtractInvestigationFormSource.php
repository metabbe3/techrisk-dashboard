<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\InvestigationFormRequestMail;
use App\Models\InvestigationFormRequest;
use App\Services\InvestigationForm\InvestigationFormDraftMerge;
use App\Services\InvestigationForm\InvestigationFormExtractor;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Services\Markdown\DocumentConverterService;
use App\Services\Notion\NotionReadService;
use App\Support\InvestigationFormMailFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The intake's slow half, off the HTTP request: collect the source (Notion
 * read-all fan-out, paste, upload conversion) and run the AI extraction,
 * then merge into the shared draft. The Dana PaaS edge proxy 504s Livewire
 * requests that run this long synchronously, so the component only dispatches
 * and polls this job's status on the request record.
 */
class ExtractInvestigationFormSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One try — a retry would double the AI spend; the user re-clicks. */
    public int $tries = 1;

    /** Notion fan-out + extract worst case; under the worker's 660s ceiling. */
    public int $timeout = 600;

    /** A 'running' status older than this is a dead job (timeout=600). */
    public const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly int $formRequestId,
        private readonly string $notionUrl,
        private readonly string $pasteText,
        private readonly string $filePath,
        private readonly string $fileName,
        private readonly string $notifyEmail,
    ) {}

    public function handle(): void
    {
        $request = InvestigationFormRequest::find($this->formRequestId);

        // Revoked/expired/removed mid-run: nothing to fill — and don't leave
        // a 'running' status stuck on the record.
        if ($request !== null && $request->status !== InvestigationFormRequest::STATUS_PENDING) {
            $this->markFailed($request, 'This form is no longer open.', '');

            return;
        }

        if ($request === null) {
            return;
        }

        try {
            $this->run($request);
        } catch (\Throwable $e) {
            Log::error('Investigation form extraction job failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            $this->markFailed($request, 'Extraction failed — try again.', '');
        }
    }

    public function failed(\Throwable $e): void
    {
        // Worker timeout / kill path — the poller must not spin forever.
        $request = InvestigationFormRequest::find($this->formRequestId);

        if ($request !== null) {
            $this->markFailed($request, 'Extraction failed — try again.', '');
        }
    }

    private function run(InvestigationFormRequest $request): void
    {
        $incident = $request->incident;
        $answers = InvestigationFormDraftMerge::normalizeRows($request->answers ?? []);
        $answers = InvestigationFormDraftMerge::prefill($answers, $incident);

        $notes = [];
        $notionError = '';
        $source = $this->collectSource($notes, $notionError);

        if (trim($source) === '') {
            // A URL that couldn't be read (not shared with the integration) is
            // a different problem from "nothing provided" — say which.
            $this->markFailed(
                $request,
                trim($this->notionUrl) !== ''
                    ? 'This Notion page could not be read'
                        .($notionError !== '' ? ' — Notion said: '.$notionError : '')
                        .' — check the link (Share → Copy link), or paste/upload the content instead.'
                    : 'Provide a Notion URL, a file, or paste your material first.',
                $notionError,
            );

            return;
        }

        /** @var InvestigationFormExtractor $extractor */
        $extractor = app(InvestigationFormExtractor::class);
        $out = $extractor->extract(
            $source,
            $answers,
            $incident === null ? null : [
                'no' => (string) $incident->incident_no,
                'title' => (string) $incident->title,
                'date' => (string) $incident->incident_date,
            ],
            InvestigationFormSettings::requiredFields(),
        );

        if (! $out['result']->success) {
            $this->markFailed($request, (string) $out['result']->error, '');

            return;
        }

        if (! $out['ok']) {
            // The reply never parsed as JSON, even after the repair re-POST
            // — a silent empty step 2 is worse than a retry.
            $this->markFailed($request, 'The AI reply could not be read — try again.', '');

            return;
        }

        if (! empty($out['source_truncated'])) {
            // The extractor-level cap cut the COMBINED source (paste/upload
            // + Notion) — Notion's own truncated flag never saw this.
            $notes[] = 'The combined source was longer than what the AI read ('
                .number_format(NotionReadService::MAX_CONTENT_CHARS).' characters) — the tail may be missing; paste any missing sections separately.';
        }

        $answers = InvestigationFormDraftMerge::applyExtraction($answers, $out);

        $request->update([
            'answers' => $answers,
            'draft_updated_by_email' => $this->notifyEmail,
            'draft_updated_at' => now(),
            'extraction_status' => 'done',
            'extraction_result' => [
                'notes' => $notes,
                'missing' => $out['missing'],
                'error' => '',
                'notion_error' => $notionError,
            ],
        ]);

        $this->notify($request);
    }

    /**
     * The component's collectSource(), moved here verbatim: paste cap, upload
     * conversion, Notion read-all — the slow part of the intake.
     *
     * @param  array<int, string>  $notes
     */
    private function collectSource(array &$notes, string &$notionError): string
    {
        $parts = [];

        // User-typed material first (paste/upload): it's the authoritative
        // intent, and under the combined budget the linked page — the bulk
        // reference — is the part that may lose its tail (the notice says so).
        if (trim($this->pasteText) !== '') {
            $parts[] = "## Pasted text\n".mb_substr($this->pasteText, 0, 48000);
        }

        // Upload → DocumentConverterService (pdf/docx) or plain text (md/txt).
        // The Livewire tmp file lives on the shared volume; a GC'd file is a
        // note, not a crash.
        if ($this->filePath !== '' && is_file($this->filePath)) {
            $ext = strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION));

            try {
                $bytes = file_get_contents($this->filePath);

                $converted = in_array($ext, ['pdf', 'docx', 'doc'], true)
                    ? app(DocumentConverterService::class)->convertRaw($bytes, $ext)
                    : null;
            } catch (\Throwable $e) {
                // A corrupted .docx / legacy .doc throws deep inside the
                // converter — a message beats a failure (blade no longer
                // offers .doc, but an old upload may still carry it). The
                // other sources still go through.
                $notes[] = 'The uploaded file could not be read'
                    .($ext === 'doc' ? ' — please convert it to .docx or .pdf first.' : ' — try exporting it to .pdf or paste the text.');

                $converted = null;
                $bytes = null;
            }

            if ($converted !== null) {
                $parts[] = "## Uploaded file ({$ext})\n".$converted;
            } elseif ($bytes !== null && in_array($ext, ['md', 'txt'], true)) {
                $parts[] = "## Uploaded file ({$ext})\n".mb_substr($bytes, 0, 48000);
            }
        } elseif ($this->fileName !== '') {
            $notes[] = 'The uploaded file is no longer available — please upload it again and extract.';
        }

        // Notion URL → page id → NotionReadService (same connection the
        // DingTalk agent reads through; no settings needed).
        if (($pageId = NotionReadService::bareId($this->notionUrl)) !== null) {
            /** @var NotionReadService $notion */
            $notion = app(NotionReadService::class);
            $page = $notion->readFullPage($pageId);

            if ($page['body'] !== null) {
                $parts[] = "## Notion page\n".$page['body'];

                if ($page['images'] > 0) {
                    $notes[] = $page['images'].' image(s) on the Notion page could not be read'
                        .' — upload them as files on step 1 and extract again.';
                }

                if ($page['truncated']) {
                    $notes[] = 'The Notion page was longer than what the AI read ('
                        .number_format(NotionReadService::MAX_CONTENT_CHARS).' characters) — paste any missing sections into step 1.';
                }
            } else {
                // Keep Notion's own refusal — "could not be read" alone hides
                // whether it's page-sharing, a dead token or a deleted page.
                $notionError = Str::limit($page['error'] !== '' ? $page['error'] : 'connection failed', 200);
            }
        }

        return implode("\n\n", $parts);
    }

    private function markFailed(InvestigationFormRequest $request, string $error, string $notionError): void
    {
        $request->update([
            'extraction_status' => 'failed',
            'extraction_result' => ['notes' => [], 'missing' => [], 'error' => $error, 'notion_error' => $notionError],
        ]);
    }

    /** Convenience notice only — the draft in the DB is the source of truth. */
    private function notify(InvestigationFormRequest $request): void
    {
        if (! filter_var($this->notifyEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            /** @var InvestigationFormRequestMail $mail */
            $mail = InvestigationFormMailFactory::readyMail($request);
            Mail::to($this->notifyEmail)->queue($mail);
        } catch (\Throwable $e) {
            Log::warning('Investigation form ready-mail failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }
    }
}
