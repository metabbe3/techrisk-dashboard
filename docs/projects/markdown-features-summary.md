# Markdown Features Implementation Summary

**Quick Reference Guide for Developers**

---

## Overview

Two related Markdown features for the Technical Risk Dashboard:

| Feature | Project ID | Description |
|---------|-----------|-------------|
| **Export Incident Reports** | PROJ-003 | Export individual incident reports to AI-consumable Markdown |
| **Convert Documents** | PROJ-004 | Auto-convert uploaded PDF/DOC files to Markdown |

**Status:** Planning Phase
**Total Timeline:** 10-14 days
**Detailed Plan:** `/docs/projects/markdown-features-technical-plan.md`

---

## Recommended Packages

### For PDF Conversion
```bash
composer require iamgerwin/php-pdf-to-markdown-parser
```
- **Why:** Lightweight, dedicated PDF to Markdown conversion
- **Updated:** September 2025
- **Pros:** Focused purpose, good feature support (tables, headings, lists)

### For DOCX Conversion
```bash
composer require phpoffice/phpword
```
- **Why:** Well-established, robust DOCX parsing with large community
- **Pros:** Battle-tested, excellent documentation, active maintenance

### Alternative (NOT Recommended)
- **Doxswap** - Requires LibreOffice server dependency
- **Pandoc** - Requires CLI tool installation

---

## Database Changes

### Add to `investigation_documents` table:

```php
// Migration: 2026_02_02_xxxxx_add_markdown_to_investigation_documents.php
Schema::table('investigation_documents', function (Blueprint $table) {
    $table->string('markdown_path')->nullable()->after('file_path');
    $table->timestamp('markdown_converted_at')->nullable();
    $table->string('markdown_conversion_status')->default('pending');
});
```

**Status values:** `pending`, `processing`, `completed`, `failed`

---

## File Structure

```
app/
├── Services/
│   └── Markdown/
│       ├── MarkdownExportService.php              # Base service
│       ├── IncidentMarkdownExporter.php           # Feature 1
│       ├── DocumentConverterService.php           # Feature 2
│       └── MarkdownFormatter.php                  # Shared utilities
├── Jobs/
│   └── ConvertDocumentToMarkdown.php              # Queue job
└── Models/
    └── InvestigationDocument.php                  # Extended

resources/
└── views/
    └── markdown/
        └── incident.blade.php                      # Template for Feature 1

storage/
└── app/
    └── markdown/
        ├── incidents/                              # Feature 1 exports
        └── documents/                              # Feature 2 conversions
```

---

## Architecture Highlights

### Feature 1: Incident Export
```
User clicks "Export to Markdown"
    ↓
IncidentMarkdownExporter generates markdown
    ↓
Blade template renders structured markdown
    ↓
Streamed download to user
```

### Feature 2: Document Conversion
```
User uploads PDF/DOCX
    ↓
File encrypted and stored (existing flow)
    ↓
ConvertDocumentToMarkdown job dispatched
    ↓
DocumentConverterService decrypts file
    ↓
Convert based on type (PDF or DOCX)
    ↓
Store markdown at storage/app/markdown/documents/{id}.md
    ↓
Update document record with status and path
```

---

## Key Implementation Points

### 1. Encryption Handling
Documents are encrypted. Conversion flow:
1. Decrypt using existing `EncryptionService`
2. Convert to Markdown
3. Store Markdown (unencrypted, for searchability)
4. Clean up temporary decrypted content

### 2. Queue Processing
```php
// In InvestigationDocumentsRelationManager
->after(function (Model $record) {
    \App\Jobs\ConvertDocumentToMarkdown::dispatch($record);
});
```

**Job configuration:**
- Tries: 3
- Backoff: 60s, 300s, 900s (1min, 5min, 15min)
- Dedicated queue recommended: `document-conversion`

### 3. Filament Actions (Feature 2)
```php
// View converted markdown
Tables\Actions\Action::make('view_markdown')
    ->visible(fn($record) => $record->markdown_path !== null)

// Download converted markdown
Tables\Actions\Action::make('download_markdown')
    ->visible(fn($record) => $record->markdown_path !== null)

// Reconvert (if conversion failed or needs update)
Tables\Actions\Action::make('reconvert')
    ->color('warning')
```

---

## Testing Strategy

### Unit Tests
- `IncidentMarkdownExporterTest` - Markdown generation
- `DocumentConverterServiceTest` - Conversion logic
- `MarkdownFormatterTest` - Utilities

### Integration Tests
- End-to-end incident export
- Document upload → conversion flow
- Queue job processing

### Manual Testing Checklist
- [ ] Export incidents with various data combinations
- [ ] Test with text-based PDFs
- [ ] Test with scanned/image-based PDFs
- [ ] Test with DOCX containing tables
- [ ] Test with DOCX containing images
- [ ] Test with large files (>10MB)
- [ ] Test queue failures and retries
- [ ] Test encryption/decryption during conversion

---

## Risk Mitigation

| Risk | Level | Mitigation |
|------|-------|------------|
| Conversion quality issues | Medium | Manual review, reconvert option |
| Performance with large files | Medium | Queue processing, 15MB limit |
| Package compatibility | Low | Thorough testing |
| Memory issues | High | Streaming, memory limits, queues |
| Queue failures | Medium | Monitoring, retry logic |
| Storage growth | Low | Cleanup policies, monitoring |

---

## Monitoring & Observability

### Metrics to Track
- Conversion success rate
- Average conversion time by file type
- Queue depth and processing time
- Storage usage trends
- Error rates by file type

### Logging
```php
Log::info("Document converted to markdown", [
    'document_id' => $document->id,
    'file_type' => $extension,
    'duration_ms' => $duration
]);

Log::error("Failed to convert document", [
    'document_id' => $document->id,
    'error' => $e->getMessage()
]);
```

### Alert Triggers
- Conversion failure rate > 20%
- Queue depth > 100 jobs
- Storage usage > 90% capacity
- Average conversion time > 5 minutes

---

## Performance Considerations

### Queue Configuration
```php
// config/queue.php
'document-conversion' => [
    'driver' => 'redis',
    'queue' => 'document-conversion',
    'retry_after' => 600, // 10 minutes
    'timeout' => 480, // 8 minutes
];
```

### Memory Management
```php
// In queue job
public $memoryLimit = 512; // MB
```

### Storage Cleanup
```php
// Scheduled task
$schedule->command('markdown:cleanup-old')
    ->daily()
    ->at('02:00');
```

---

## Security Checklist

- [ ] Validate file types before conversion (check magic numbers)
- [ ] Sanitize converted markdown output
- [ ] Apply same permissions as original documents
- [ ] Store temp files outside web root
- [ ] Use random filenames for temp files
- [ ] Clean up temp files immediately
- [ ] Limit file sizes (15MB already enforced)

---

## Quick Start Commands

```bash
# Install dependencies
composer require iamgerwin/php-pdf-to-markdown-parser
composer require phpoffice/phpword

# Run migration
php artisan migrate

# Create storage directories
mkdir -p storage/app/markdown/incidents
mkdir -p storage/app/markdown/documents
mkdir -p storage/app/temp

# Clear cache
php artisan config:clear
php artisan route:clear

# Run tests
php artisan test --filter=Markdown

# Process queue (for testing)
php artisan queue:work --queue=document-conversion --timeout=480
```

---

## Dependencies Between Projects

```
PROJ-003 (Incident Export)
    ↓ (no dependency)
    ↓
    Can develop in parallel

PROJ-004 (Document Conversion)
    ↓ (no dependency)
    ↓
    Can develop in parallel

Both share:
    - MarkdownFormatter utility
    - Storage structure (/markdown)
    - Similar patterns for download/actions
```

---

## Next Steps

1. **Review** technical plan: `markdown-features-technical-plan.md`
2. **Approve** package choices and architecture
3. **Assign** developers to each project
4. **Set up** development environment with packages
5. **Start** Phase 1 (Incident Export) - simpler, validates approach
6. **Start** Phase 2 (Document Conversion) in parallel
7. **Integration** testing once both features complete
8. **Documentation** and handoff

---

## Contact & Resources

- **Technical Plan:** `/docs/projects/markdown-features-technical-plan.md`
- **Active Projects:** `/docs/projects/active-projects.md` (PROJ-003, PROJ-004)
- **Package Research:**
  - [iamgerwin/php-pdf-to-markdown-parser](https://packagist.org/packages/iamgerwin/php-pdf-to-markdown-parser)
  - [phpoffice/phpword](https://github.com/PHPOffice/PHPWord)
  - [Doxswap Article](https://laravel-news.com/seamless-document-conversion-in-laravel-with-docswap)

---

**Document Version:** 1.0
**Last Updated:** 2026-02-02
**Status:** Ready for Implementation
