# Feature: Stuck-task and overload alerts

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-stuck-and-overload-alerts.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Warn when a task sits too long in one status, when logged time passes the estimate, or when a person's sprint load is too high.

## 2. Goals and non-goals

- Goal: "Stuck" badge when an open task has not changed status for N days (per status category, configurable)
- Goal: Alert when time spent exceeds the original estimate by a set percentage
- Goal: Alert when a person's planned hours in a sprint exceed their capacity
- Goal: Digest of alerts for leads and PMs on the dashboard and as a notification
- Not in this change: Predicting delays with machine learning

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Team leads, managers (`dashboard.team`) | See alerts for their line/projects | See alerts outside their scope |
| Assignee | See alerts about their own tasks | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Stuck days: default 5 for In Progress, 10 for To Do (company setting)
- Overrun threshold default 120% of estimate
- Alerts are computed daily by the scheduler and stored once per task per condition until resolved

## 4. Data

New `task_alerts` (organization_id, task_id, kind, raised_on, resolved_on, unique open per task and kind). Company settings stored in `organizations.settings` json.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Alerts carry `organization_id`
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuse `dashboard.team` and `report.view`
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `AlertService` and a daily command; badge flag on `TaskPresenter`
- [x] **Routes and controllers**: new routes, validation, authorization
  - Alerts list endpoint; settings in company settings
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Badges on cards/list, dashboard widget "Needs attention" extended, settings fields
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - `DashboardService::team` attention list; `ProjectHealthService`; capacity needs `workload-view`
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - New event `alerts` in `NotificationEvents` (digest to leads), preferences UI row
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Settings change recorded
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - Optional "stuck tasks" report
- [x] **Queue, mail, scheduler**
  - Daily command in `routes/console.php`
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A stuck and an overrun task
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW section 9
- [x] **Performance**: queries per page, indexes, large lists
  - Daily set-based queries, index on `updated_at` of status changes

Risks and how they are handled:

- Alert fatigue: digest once a day, resolve automatically, allow muting per project

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for `task_alerts` and settings defaults
- [ ] 6.2 `AlertService` (stuck, overrun, overload) and daily command
- [ ] 6.3 Notification event and preferences row
- [ ] 6.4 Badges in list/card and dashboard widget
- [ ] 6.5 Company settings UI for thresholds
- [ ] 6.6 Tests: each condition, resolution, scope per role, idempotent run, tenant isolation
- [ ] 6.7 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
