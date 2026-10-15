# Feature: Command palette actions

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Small
- **Depends on:** `keyboard-shortcuts-extended`
- **Spec file:** `tasks/features/2026-10-15-command-palette-actions.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Extend Ctrl+K from search to actions: create a task, go to a page, log work on a task, change a task's status, switch company.

## 2. Goals and non-goals

- Goal: Type a verb to see actions ("create", "log", "go to timesheet", "switch")
- Goal: Contextual actions when a task is open or selected
- Goal: Recently opened tasks and projects when the box is empty
- Not in this change: Natural language commands

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Run any action they could do from the UI | See actions they lack permission for |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Actions list is built from the person's permissions (`usePermissions`)
- Search and actions share one keyboard model (arrows, Enter, Esc)

## 4. Data

No schema change; recents kept in `localStorage`.

## 5. Impact analysis

- [ ] **Tenancy**: company scoping, tenant isolation of every new query and route binding
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Actions filtered by permission; the server still authorizes
- [ ] **Models and services**: new or changed classes, transactions, soft deletes
- [x] **Routes and controllers**: new routes, validation, authorization
  - Reuse existing endpoints and pages; no new routes
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Components/GlobalSearch.vue` becomes the command palette; `AppLayout.vue` provides context
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Current search endpoint and shortcuts; create dialog event
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [x] **Performance**: queries per page, indexes, large lists
  - Actions are static data; search remains debounced

Risks and how they are handled:

- Mixing results and actions confusingly: clear groups and icons

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Action registry with permission filter and context
- [ ] 6.2 Palette UI groups: Actions, Tasks, Projects, People, Recent
- [ ] 6.3 Contextual actions (log work, change status) using existing endpoints
- [ ] 6.4 Recents in `localStorage`
- [ ] 6.5 Tests of the registry logic and manual checklist per role
- [ ] 6.6 Docs: shortcuts help sheet entry

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
