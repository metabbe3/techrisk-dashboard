# Project Management (Reference)

Detailed PM workflow, templates, and lesson-learned format. Read this when managing requests or documenting bugs.

---

## PM Workflow Diagram

```
User/Stakeholder Request
         ↓
    ┌──────────┐
    │    PM    │ ← Central Point of Contact
    └────┬─────┘
         │
         ├──→ Assess & Prioritize
         │    └──→ Check Active Projects
         │    └──→ Review Findings
         │    └──→ Consult Lesson Learned
         │
         ├──→ Is it a BUG?
         │    ↓ YES
         │    ├──→ Document in docs/bugs/lesson-learned.md
         │    ├──→ Root Cause Analysis
         │    ├──→ Prevention Strategy
         │    └──→ Then assign to appropriate agent
         │
         ├────→ Is it a NEW FEATURE?
         │    ↓ YES
         │    ├──→ Add to docs/projects/active-projects.md
         │    ├──→ Technical Breakdown (use architect-planning-design agent)
         │    ├──→ Resource Estimation
         │    └──→ Assign to agents with approval
         │
         └───→ Is it ENHANCEMENT?
              ↓ YES
              ├──→ Review existing code
              ├──→ Impact Analysis
              └──→ Assign with approval
```

## Agent Coordination

| Agent | Triggered By | PM Approval Required |
|-------|--------------|---------------------|
| `backend-architect-engineer` | Backend work | YES |
| `frontend-engineer` | Frontend/UI work | YES |
| `backend-qa-engineer` | Backend QA | YES |
| `frontend-qa-specialist` | Frontend QA | YES |
| `database-architect` | DB schema changes | YES |
| `sre-engineer` | Infra/Docker/deployment | YES |
| `security-pentest-auditor` | Security review | YES |
| `architect-planning-design` | New features/planning | YES |
| `Explore` | Codebase exploration | PM initiated |
| `feature-strategist` | Feature ideation | YES |

## Lesson Learned Template

**MANDATORY for EVERY BUG.** Document in `docs/bugs/lesson-learned.md`.

```markdown
## [BUG-XXX] - Brief Bug Title

**Date:** YYYY-MM-DD
**Discovered By:** [Name]
**Severity:** [Critical/High/Medium/Low]

### Description
[Brief description of what happened]

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - Answer
2. Why did that happen?
   - Answer
3. Continue asking "why" until root cause is found

### Impact
- [ ] User facing?
- [ ] Data loss/corruption?
- [ ] Performance degradation?
- [ ] Security vulnerability?

### Prevention Strategy
1. **Process Change:**
   - [ ] Update SOP
   - [ ] Add validation
   - [ ] Add monitoring

2. **Code Change:**
   - [ ] Add unit test
   - [ ] Add integration test
   - [ ] Refactor code

3. **Documentation:**
   - [ ] Update CLAUDE.md
   - [ ] Add code comments

### Action Items
- [ ] Action item 1 - [Assigned To] - [Due Date]
- [ ] Action item 2 - [Assigned To] - [Due Date]

### Verification
- [ ] Test case added: `path/to/test.php`
- [ ] Code review completed
- [ ] Deployment verified
```

## Active Projects Template

**File:** `docs/projects/active-projects.md`

```markdown
### [PROJ-XXX] Project Name
**Status:** [Backlog/In Progress/In Review/Done]
**Priority:** [P1/P2/P3/P4]
**PM:** [Name]
**Assigned Agents:** [List]
**Start Date:** YYYY-MM-DD
**Target Completion:** YYYY-MM-DD

#### Description
[Brief description of project goals]

#### Technical Approach
- [Architecture decisions]

#### Tasks
- [ ] Task 1 - [Agent] - [Status]
- [ ] Task 2 - [Agent] - [Status]

#### Dependencies
- Dependency 1

#### Blockers
- Blocker (if any)

#### Progress Updates
- YYYY-MM-DD: Update 1
```

## Findings Template

**File:** `docs/findings/findings.md`

```markdown
### [FIND-XXX] Finding Title
**Date:** YYYY-MM-DD
**Category:** [Performance/Security/Architecture/Technical Debt]
**Severity:** [Critical/High/Medium/Low]
**Status:** [Open/In Progress/Resolved]

#### Description
[What was found]

#### Impact
[Why it matters]

#### Recommended Action
[What should be done]

#### Priority
[When should it be addressed]
```
