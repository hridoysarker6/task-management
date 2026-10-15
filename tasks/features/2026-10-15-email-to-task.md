# Feature: Email to task

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-email-to-task.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Each project gets an inbound address; an email sent to it becomes a request in triage, with the body as description and attachments kept.

## 2. Goals and non-goals

- Goal: Per-project secret inbound address (for example `hrp+token@inbox.example.com`)
- Goal: Sender matched to a person by email; unknown senders become a request marked "external sender"
- Goal: Replies to the notification email become comments on the task
- Not in this change: Reading a personal mailbox, calendar invites, auto-replies

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Project manager | Turn on/rotate the inbound address, choose triage on/off | - |
| Anyone emailing the address | Create a request (triage) or comment | Create a task directly in a status of their choice |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Always lands in Triage regardless of project triage mode
- Spoof protection: require the secret token and reject mail failing SPF/DKIM when the provider reports it
- HTML is sanitised through `HtmlSanitizer`; size and attachment limits as in `AttachmentService`

## 4. Data

Add `projects.inbound_token` (unique, nullable) and a `inbound_messages` log table (organization_id, project_id, message_id unique, from, subject, status, task_id) for idempotency.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Token maps to exactly one project and company; log carries `organization_id`
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `project.manage_inbox` (managers); public webhook protected by token and signature
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `InboundMailService`, reuse `TaskService::create`, `AttachmentService`, `CommentService`
- [x] **Routes and controllers**: new routes, validation, authorization
  - Public `POST /inbound/mail` webhook (provider-specific), rate limited
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Project settings: inbox address, copy and rotate
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Triage queue and stakeholder notifications; security review items for public endpoints
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - `task_in_triage` as usual; reply-to threading
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Rotate/enable recorded
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Processing in a queued job; failed messages visible in Failed jobs
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Sample inbound message log
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE, deployment notes (mail provider)
- [x] **Performance**: queries per page, indexes, large lists
  - Idempotent by Message-ID

Risks and how they are handled:

- Public endpoint abuse: token, rate limit, size cap, signature check
- Provider lock-in: hide behind an interface; start with one provider or a local test driver

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Choose provider and write the interface and a local test driver
- [ ] 6.2 Migration: inbound token and message log
- [ ] 6.3 `InboundMailService` with sender matching, sanitising, attachments, idempotency
- [ ] 6.4 Webhook route with signature/token and rate limit
- [ ] 6.5 Reply-to threading into comments
- [ ] 6.6 Project settings UI and permission
- [ ] 6.7 Audit and failed-job visibility
- [ ] 6.8 Tests: valid/invalid token, unknown sender, duplicates, oversize, tenant isolation
- [ ] 6.9 Docs and deployment notes

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
