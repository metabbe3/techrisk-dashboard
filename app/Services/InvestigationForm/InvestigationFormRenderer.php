<?php

declare(strict_types=1);

namespace App\Services\InvestigationForm;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Renders the final Investigation Form as DOCX (the owner's 2025 v1.0 template
 * with ${placeholders}) and PDF (blade with the same section order).
 *
 * The draft is the flat submission shape: section.key => string, plus
 * chronology rows [time, event] and action_items rows [item, due_date,
 * verification, owner]. Every value is escaped where it's interpolated.
 */
class InvestigationFormRenderer
{
    public function docx(array $draft): string
    {
        $processor = new TemplateProcessor(resource_path('templates/investigation-form-v1.docx'));

        foreach (InvestigationFormSchema::flatFields() as $key => $label) {
            [$section, $field] = explode('.', $key, 2);
            $value = (string) ($draft[$section][$field] ?? '');
            $processor->setValue($key, $this->plain($value));
        }

        $processor->setValue('submitter_name', $this->plain((string) ($draft['submitter_name'] ?? '')));

        $chronology = array_values((array) ($draft['chronology'] ?? []));
        $processor->cloneRow('chrono_time', max(count($chronology), 1));
        for ($i = 0; $i < max(count($chronology), 1); $i++) {
            $n = $i + 1;
            $processor->setValue('chrono_time#'.$n, $this->plain((string) ($chronology[$i]['time'] ?? '')));
            $processor->setValue('chrono_event#'.$n, $this->plain((string) ($chronology[$i]['event'] ?? '')));
        }

        $actions = array_values((array) ($draft['action_items'] ?? []));
        $processor->cloneRow('action_item', max(count($actions), 1));
        for ($i = 0; $i < max(count($actions), 1); $i++) {
            $n = $i + 1;
            $row = $actions[$i] ?? [];
            $processor->setValue('action_item#'.$n, $this->plain((string) ($row['item'] ?? '')));
            $processor->setValue('action_due_date#'.$n, $this->plain((string) ($row['due_date'] ?? '')));
            $processor->setValue('action_verification#'.$n, $this->plain((string) ($row['verification'] ?? '')));
            $processor->setValue('action_owner#'.$n, $this->plain((string) ($row['owner'] ?? '')));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'invform-').'.docx';
        $processor->saveAs($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function pdf(array $draft): string
    {
        $html = View::make('pdf.investigation-form', [
            'draft' => $draft,
            'sections' => InvestigationFormSchema::SECTIONS,
            'listSections' => InvestigationFormSchema::LIST_SECTIONS,
            'statement' => InvestigationFormSchema::STATEMENT_OF_TRUTH,
        ])->render();

        return Pdf::loadHTML($html)->output();
    }

    /**
     * Values land in raw <w:t> text nodes — PhpWord's setValue does NOT
     * escape XML specials (a `&`/`<` in an answer corrupts the DOCX), so
     * escape here. Newlines flatten to spaces (w:t cannot hold breaks —
     * chronology rows / the PDF carry structure; DOCX answers are prose).
     */
    private function plain(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace("\n", ' ', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
    }
}
