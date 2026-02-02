# Active Projects

*This document tracks all ongoing and planned projects for the Technical Risk Dashboard.*

---

## Project Template

```markdown
### [PROJ-XXX] Project Name

**Status:** [Backlog / In Progress / In Review / Done]
**Priority:** [P1 / P2 / P3 / P4]
**PM:** [Name]
**Assigned Agents:** [List of agents/team members]
**Start Date:** YYYY-MM-DD
**Target Completion:** YYYY-MM-DD

#### Description
[Brief description of project goals and business value]

#### Technical Approach
- [Architecture decisions]
- [Key technologies]
- [Dependencies]

#### Tasks
- [ ] Task 1 - [Agent] - [Status]
- [ ] Task 2 - [Agent] - [Status]
- [ ] Task 3 - [Agent] - [Status]

#### Dependencies
- Dependency 1
- Dependency 2

#### Blockers
- [Blocker if any, otherwise write "None"]

#### Progress Updates
- YYYY-MM-DD: Update description
- YYYY-MM-DD: Update description
```

---

## Active Projects

### [PROJ-001] Initial SOP and Documentation Setup

**Status:** Done
**Priority:** P1
**PM:** Claude (PM Agent)
**Assigned Agents:** PM
**Start Date:** 2026-02-02
**Target Completion:** 2026-02-02

#### Description
Establish Standard Operating Procedures (SOP) and documentation structure for the Technical Risk Dashboard project to ensure consistent development practices and continuous improvement.

#### Technical Approach
- Created CLAUDE.md with comprehensive SOPs for all roles
- Established docs/ folder structure for projects, bugs, and findings
- Implemented PM-centralized workflow for all requests
- Created templates for lesson learned and project tracking

#### Tasks
- [x] Create CLAUDE.md with role-based SOPs - PM - Done
- [x] Create docs/ folder structure - PM - Done
- [x] Create active-projects.md template - PM - Done
- [x] Create lesson-learned.md template - PM - Done
- [x] Create findings.md template - PM - Done

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project completed. Documentation structure established.

---

## Backlog

### [PROJ-003] Export Incident Reports to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, frontend-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Implement a Markdown export feature for individual incident reports. The export will be AI-consumable with well-structured, clean markdown that includes all relevant incident data (summary, timeline, root cause, financial impact, action items, etc.). This will enable AI analysis of incident patterns and facilitate automated reporting.

#### Technical Approach
- Create a dedicated Markdown export service in `app/Services/Markdown/`
- Add Filament action on Incident view page
- Use Laravel Blade templates for markdown generation
- Implement proper formatting for AI consumption (structured headers, consistent data formats)
- Include all relationships: StatusUpdate, InvestigationDocument, ActionImprovement, Label, incidentType
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Tasks
- [ ] Phase 1.1: Create MarkdownExportService base class - backend-architect-engineer - Backlog
- [ ] Phase 1.2: Create IncidentMarkdownExporter service - backend-architect-engineer - Backlog
- [ ] Phase 1.3: Create resources/views/markdown/incident.blade.php template - frontend-engineer - Backlog
- [ ] Phase 1.4: Add export_markdown action to ViewIncident page - backend-architect-engineer - Backlog
- [ ] Phase 1.5: Create tests for markdown export format - backend-qa-engineer - Backlog
- [ ] Phase 1.6: Manual testing with various incident data - frontend-qa-specialist - Backlog
- [ ] Phase 1.7: Documentation update - backend-architect-engineer - Backlog

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---

### [PROJ-004] Convert Uploaded Documents to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, backend-qa-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Automatically convert uploaded PDF/DOC files to Markdown format when they're attached to incidents. This makes documents searchable and AI-consumable, enabling advanced analysis and search capabilities across incident documentation.

#### Technical Approach
- **Recommended packages:**
  - PDF: `iamgerwin/php-pdf-to-markdown-parser` (lightweight, dedicated PDF to Markdown)
  - DOCX: `phpoffice/phpword` (well-established, robust DOCX parsing)
- **Alternative:** Doxswap (LibreOffice-based) - NOT recommended due to server dependency
- Create DocumentConverterService in `app/Services/Markdown/`
- Implement queue-based async conversion (ConvertDocumentToMarkdown job)
- Add markdown_path, markdown_converted_at, markdown_conversion_status to investigation_documents table
- Handle encrypted files (decrypt → convert → store markdown → re-encrypt if needed)
- Add Filament actions for viewing/downloading converted markdown
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Database Schema Changes
```php
// Add to investigation_documents table
$table->string('markdown_path')->nullable();
$table->timestamp('markdown_converted_at')->nullable();
$table->string('markdown_conversion_status')->default('pending');
// Values: pending, processing, completed, failed
```

#### Storage Strategy
- Converted markdown: `storage/app/markdown/documents/{document_id}.md`
- Temp files during conversion: `storage/app/temp/` (auto-cleanup)

#### Tasks
- [ ] Phase 2.1: Create database migration for markdown fields - backend-architect-engineer - Backlog
- [ ] Phase 2.2: Install composer packages (iamgerwin/php-pdf-to-markdown-parser, phpoffice/phpword) - backend-architect-engineer - Backlog
- [ ] Phase 2.3: Create DocumentConverterService - backend-architect-engineer - Backlog
- [ ] Phase 2.4: Create ConvertDocumentToMarkdown queue job - backend-architect-engineer - Backlog
- [ ] Phase 2.5: Update InvestigationDocumentsRelationManager to dispatch job - backend-architect-engineer - Backlog
- [ ] Phase 2.6: Create Filament actions for viewing/downloading markdown - frontend-engineer - Backlog
- [ ] Phase 2.7: Create temp storage directory and configure cleanup - backend-architect-engineer - Backlog
- [ ] Phase 2.8: Create tests for conversion service - backend-qa-engineer - Backlog
- [ ] Phase 2.9: Manual testing with PDF and DOCX files - backend-qa-engineer - Backlog
- [ ] Phase 2.10: Error handling and retry logic testing - backend-qa-engineer - Backlog

#### Dependencies
- None (can be developed in parallel with PROJ-003)

#### Blockers
- None

#### Risks & Considerations
- **Medium Risk:** PDF/DOCX conversion quality may vary
  - Mitigation: Provide manual review process and reconvert option
- **Medium Risk:** Performance issues with large files
  - Mitigation: Queue processing, file size limits (15MB already enforced)
- **Low Risk:** Package compatibility issues
  - Mitigation: Thorough testing, both packages are actively maintained

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---

## Completed Projects

### [PROJ-001] Initial SOP and Documentation Setup
- **Completed:** 2026-02-02
- **Outcome:** Established documentation framework for continuous improvement

---

*Last Updated: 2026-02-02*
