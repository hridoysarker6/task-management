# Feature: Automation rules

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-automation-rules.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Per-project "when this happens, do that" rules, for example when a task moves to Done notify the reporter, or when a bug is created assign it to the team lead.

## 2. Goals and non-goals

- Goal: Triggers: task created, status changed, assigned, due date passed, comment added
- Goal: Conditions: type, priority, status, label, assignee, "is overdue"
- Goal: Actions: assign, set priority, add label, move status, post a comment, notify a person or role
- Goal: A log of every run, and a safe on/off switch per rule
- Not in this change: Custom code, webhooks to outside systems (separate integration features), cross-project rules

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Project manager | Create, edit, enable/disable rules for the project | Create rules in another project |
| Everyone | See the effect (activity lines marked "by rule") | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- A rule never acts beyond what its creator may do; it runs as the "system" but obeys workflow transitions and project membership
- Loop protection: a rule cannot trigger itself or more than N chained rules; depth and per-task run limits
- Actions that fail are logged and skipped, never roll back the original action
- Rules are evaluated after the original change is committed (queued)

## 4. Data

New `automation_rules` (organization_id, project_id, name, trigger, conditions json, actions json, is_active, created_by, soft delete) and `automation_runs` (rule_id, task_id, status, message, created_at).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Both tables carry `organization_id`; rule evaluation sets the company context
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `automation.manage` (managers, admins) in `PermissionCatalog` and `DefaultRoleTemplate`
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `AutomationService` (match + run), listeners on `TaskObserver` events and the scheduler for due-date rules
- [x] **Routes and controllers**: new routes, validation, authorization
  - CRUD, toggle, run log
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Project settings > Automation: rule builder (trigger, conditions, actions), run log
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Every status/assignee change path (`TaskService`, `BoardService`, bulk, triage) must emit the events; notifications and activity log; sprint rules
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Notify action goes through `Notifier`
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Rule create/update/delete/enable recorded
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - Optional "rule runs" count
- [x] **Queue, mail, scheduler**
  - Evaluation runs in a queued job; due-date triggers via the daily scheduler
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Two sample rules and run log entries
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW (new section), DATABASE, PLAN
- [x] **Performance**: queries per page, indexes, large lists
  - Index rules by (project, trigger, is_active); cheap pre-check before queueing

Risks and how they are handled:

- Infinite loops and surprise changes: depth limit, per-task cap, visible "by rule" activity, dry-run preview
- Security: actions are validated against the rule owner's scope at save time and again at run time

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for rules and runs
- [ ] 6.2 `automation.manage` permission and defaults
- [ ] 6.3 Domain events for the triggers emitted from every change path
- [ ] 6.4 `AutomationService`: condition matching, action runners, loop guard, run logging
- [ ] 6.5 Queued evaluation job and daily due-date trigger
- [ ] 6.6 Rule builder UI and run log
- [ ] 6.7 Audit and "by rule" activity labelling
- [ ] 6.8 Tests: each trigger/condition/action, loop guard, permission limits, failure isolation, tenant isolation
- [ ] 6.9 Seed data and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
