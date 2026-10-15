# Feature: Scheduled report emails

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-scheduled-report-emails.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Email a report (workload, overdue, time, sprint) as CSV/Excel/PDF to chosen people on a schedule, for example every Monday 8am.

## 2. Goals and non-goals

- Goal: Subscribe yourself or others to a saved report with filters and a schedule (daily, weekly, monthly)
- Goal: Delivered by email with the file attached and a link to the live report
- Goal: Pause, edit and delete schedules; see last run and failures
- Not in this change: Custom report builder

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone with `report.view` and `report.export` | Schedule a report for themselves | Schedule one that shows data beyond their scope |
| Company admin | See and manage all schedules | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- A report always runs with the recipient's own data scope, not the creator's
- Recipients must still hold `report.view`; otherwise the run is skipped and logged
- Idempotent per schedule and period

## 4. Data

New `report_schedules` (organization_id, membership_id owner, report, filters json, format, frequency, weekday, send_at, recipients json, last_run_at, is_active) and `report_runs` (schedule_id, status, message, created_at).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Both tables scoped; the command iterates companies
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuse `report.view`/`report.export`; new `reportschedule.manage_all` for admins
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `ReportService` + `ReportExporter` reuse; `ReportScheduleService`
- [x] **Routes and controllers**: new routes, validation, authorization
  - Schedule CRUD from the Reports page
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - "Schedule" dialog on `Reports/Index.vue`, list of schedules
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Exporter, scope rules, former-employee filter
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Email via a new `ReportDelivered` notification (preference row `reports`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Schedule create/delete by admins recorded
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Command `reports:send-scheduled` hourly; queued mail with attachment
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A weekly overdue report schedule
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE, WORKFLOW section 9
- [x] **Performance**: queries per page, indexes, large lists
  - Generate once per recipient scope, cap rows

Risks and how they are handled:

- Data leakage to a recipient with a different scope: always evaluate per recipient

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for schedules and runs
- [ ] 6.2 `ReportScheduleService` and `reports:send-scheduled` command with idempotency
- [ ] 6.3 Delivery notification with attachment and preference row
- [ ] 6.4 Schedule dialog and manager list
- [ ] 6.5 Permission for admins, audit
- [ ] 6.6 Tests: scope per recipient, skipped runs, idempotency, formats, tenant isolation
- [ ] 6.7 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
