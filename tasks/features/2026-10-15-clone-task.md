# Feature: Duplicate (clone) a task

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-clone-task.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Copy a task, optionally with its sub-tasks, labels and links, into the same or another project.

## 2. Goals and non-goals

- Goal: "Clone" action on the task page and card menu
- Goal: Choose what to copy: sub-tasks, labels, attachments, estimates; never comments or logged time
- Goal: The clone gets a new key, status Backlog (or triage rules) and a "clones" link to the original
- Not in this change: Cloning whole projects (separate idea)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can create tasks in the target project and see the source | Clone into the same or another project they belong to | Clone a task they cannot see |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Assignee is kept only if they are a member of the target project
- Labels are matched by name in the target project (missing ones are skipped)
- Link type `clones` already exists in `Task::LINK_TYPES`

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Source and target projects resolved within the company
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Uses `view` on source and `createTask` on target
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `TaskService::clone` built on `create`; `TaskLinkService::link` with `clones`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `POST tasks/{task}/clone`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Clone dialog from `Tasks/Show.vue` and card menu
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Numbering, backlog rank, notifications for the new assignee
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Normal assignment/triage notifications
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Nothing
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW 6.5
- [ ] **Performance**: queries per page, indexes, large lists

Risks and how they are handled:

- Copying attachments duplicates files: copy storage objects, not references

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 `TaskService::clone` with options and rules above
- [ ] 6.2 Endpoint, validation, authorization
- [ ] 6.3 Clone dialog UI
- [ ] 6.4 Tests: options, cross-project, label matching, permissions, tenant isolation
- [ ] 6.5 Docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
