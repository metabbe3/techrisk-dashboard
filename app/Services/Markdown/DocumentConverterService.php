<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Models\InvestigationDocument;
use App\Services\EncryptionService;
use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class DocumentConverterService
{
    public function __construct(
        private EncryptionService $encryption
    ) {}

    /**
     * Convert an uploaded document to Markdown.
     */
    public function convert(InvestigationDocument $document): ?string
    {
        if (! $this->shouldConvert($document)) {
            return null;
        }

        $this->updateStatus($document, 'processing');

        try {
            // Get and decrypt the file content
            $content = $this->getDecryptedContent($document);

            // Convert based on file type
            $markdown = $this->convertContent(
                $content,
                $document->original_filename
            );

            // Check if conversion produced usable content
            if (blank($markdown) || trim(strip_tags($markdown)) === '') {
                $document->update([
                    'markdown_conversion_status' => 'failed',
                ]);

                Log::warning('Document conversion produced empty content', [
                    'document_id' => $document->id,
                    'filename' => $document->original_filename,
                ]);

                throw new \Exception('No text could be extracted from this document. It may be image-based or empty.');
            }

            // Store markdown
            $path = $this->storeMarkdown($document, $markdown);

            // Update document record
            $document->update([
                'markdown_path' => $path,
                'markdown_converted_at' => now(),
                'markdown_conversion_status' => 'completed',
            ]);

            Log::info('Document converted to markdown', [
                'document_id' => $document->id,
                'filename' => $document->original_filename,
            ]);

            return $markdown;
        } catch (\Exception $e) {
            $document->update([
                'markdown_conversion_status' => 'failed',
            ]);

            Log::error('Failed to convert document to markdown', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Convert raw file content to Markdown (no InvestigationDocument required).
     */
    public function convertRaw(string $content, string $extension): ?string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, ['pdf', 'docx', 'doc'])) {
            return null;
        }

        $markdown = $this->convertContent($content, "file.{$extension}");

        if (blank($markdown) || trim(strip_tags($markdown)) === '') {
            return null;
        }

        return $markdown;
    }

    /**
     * Check if the document should be converted.
     */
    private function shouldConvert(InvestigationDocument $document): bool
    {
        $extension = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));

        return in_array($extension, ['pdf', 'docx', 'doc']);
    }

    /**
     * Get decrypted content of the document.
     */
    private function getDecryptedContent(InvestigationDocument $document): string
    {
        $encryptionKey = $document->encryptionKey;
        if (! $encryptionKey) {
            throw new \Exception('No encryption key found for document');
        }

        $encryptedContent = Storage::disk('public')->get($document->file_path);
        $finalKey = $this->encryption->getFinalKey(
            $encryptionKey->key,
            $encryptionKey->salt,
            $encryptionKey->method
        );

        return $this->encryption->decrypt($encryptedContent, $finalKey);
    }

    /**
     * Convert content to Markdown based on file type.
     */
    private function convertContent(string $content, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->convertPdf($content),
            'docx', 'doc' => $this->convertDocx($content),
            default => throw new \Exception("Unsupported file type: {$extension}"),
        };
    }

    /**
     * Convert PDF content to Markdown.
     */
    private function convertPdf(string $content): string
    {
        $tempDir = storage_path('app/temp');
        if (! file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/'.uniqid().'.pdf';
        file_put_contents($tempPath, $content);

        try {
            $parser = new PdfToMarkdownParser;

            return $parser->parseFile($tempPath);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Convert DOCX content to Markdown.
     */
    private function convertDocx(string $content): string
    {
        $tempDir = storage_path('app/temp');
        if (! file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/'.uniqid().'.docx';
        file_put_contents($tempPath, $content);

        try {
            $phpWord = IOFactory::load($tempPath);

            return $this->phpWordToMarkdown($phpWord);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'archive') || str_contains($msg, 'PCLZIP') || str_contains($msg, 'error code')) {
                throw new \Exception(
                    'The file appears to be corrupted or in an unsupported format. ' .
                    'If this is a .doc file, please convert it to .docx first.'
                );
            }
            throw $e;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Convert PHPWord object to Markdown.
     */
    private function phpWordToMarkdown(PhpWord $phpWord): string
    {
        $markdown = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $markdown .= $this->convertElement($element);
            }
        }

        return MarkdownFormatter::clean($markdown);
    }

    /**
     * Convert a single element to Markdown.
     */
    private function convertElement($element): string
    {
        // Handle text runs
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            // Check for styling (bold, italic, etc.)
            if (method_exists($element, 'getFontStyle')) {
                $style = $element->getFontStyle();
                if ($style && isset($style->isBold) && $style->isBold) {
                    $text = "**{$text}**";
                }
            }

            return $text."\n\n";
        }

        // Handle text breaks
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            return $element->getText()."\n\n";
        }

        // Handle tables (basic support)
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            return $this->convertTable($element);
        }

        // Handle lists
        if ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
            return '- '.$element->getText()."\n";
        }

        return '';
    }

    /**
     * Convert a table element to Markdown.
     */
    private function convertTable($table): string
    {
        $markdown = "\n";

        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $cellElement) {
                    if (method_exists($cellElement, 'getText')) {
                        $cellText .= $cellElement->getText().' ';
                    }
                }
                $cells[] = trim($cellText);
            }
            $markdown .= '| '.implode(' | ', $cells)." |\n";
        }

        return $markdown."\n";
    }

    /**
     * Store the converted Markdown content.
     */
    private function storeMarkdown(InvestigationDocument $document, string $markdown): string
    {
        $filename = "markdown/documents/{$document->id}.md";
        Storage::disk('local')->put($filename, $markdown);

        return $filename;
    }

    /**
     * Update the conversion status of the document.
     */
    private function updateStatus(InvestigationDocument $document, string $status): void
    {
        $document->update([
            'markdown_conversion_status' => $status,
        ]);
    }
}
