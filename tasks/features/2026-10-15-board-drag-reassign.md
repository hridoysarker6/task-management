# Feature: Drag to reassign and re-parent on the board

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-board-drag-reassign.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

In swimlane mode, dropping a card into another lane changes the assignee (or epic) as well as the status.

## 2. Goals and non-goals

- Goal: Assignee swimlanes: dropping into a person's lane assigns the task to them
- Goal: Epic swimlanes: dropping into an epic lane moves the task under that epic
- Goal: One request that applies status, position and lane change together
- Not in this change: Reassigning across projects
- Not in this change: Dragging to a lane of someone outside the assign scope (refused, card snaps back)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone with change-status and assign rights | Drop into another person's lane = assign + status | Drop into a lane they may not assign to |
| Anyone who can edit the task | Drop into another epic lane = change epic | Break the epic/type hierarchy rules |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- `BoardService::moveCard` gains optional `assignee_id` / `parent_id`
- The whole move is one transaction; a refused part cancels it
- "Unassigned" and "No epic" lanes clear the field

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Assignee and parent resolved within the task's company
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuse `assign` and `update` abilities
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - Extend `BoardService::moveCard` and the `tasks.move` validation
- [x] **Routes and controllers**: new routes, validation, authorization
  - `tasks.move` accepts `assignee_id`, `parent_id`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Tasks/Board.vue` sends the lane key on drop
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Plain drops (no swimlane) must behave exactly as before; notifications for assignment fire via `TaskService`
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Not an admin action; task history records the changes
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [ ] **Performance**: queries per page, indexes, large lists

Risks and how they are handled:

- Partial success (status moves, assign fails): handle in one transaction

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Extend `BoardService::moveCard` and validation for lane changes
- [ ] 6.2 Board.vue: send lane identity on drop and roll back on refusal
- [ ] 6.3 Tests: assign via lane, epic via lane, refusals, unassigned/no-epic lanes, plain drop unchanged
- [ ] 6.4 Docs: WORKFLOW 6.5

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
