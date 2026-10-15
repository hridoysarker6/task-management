# Feature: Task side panel (peek without leaving the board)

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Medium
- **Depends on:** `inline-edit`
- **Spec file:** `tasks/features/2026-10-15-task-side-panel.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Clicking a card on the board, list or backlog opens the task in a slide-over panel on the right; the full page stays one click away.

## 2. Goals and non-goals

- Goal: Open a task without losing board position, filters or scroll
- Goal: Panel shows the same details as the task page (status, assignee, description, comments, time, links)
- Goal: Back/forward and a shareable URL still work (`?task=ECP-12`)
- Not in this change: Replacing the full task page
- Not in this change: Editing rich description inside the panel on phones (opens full page)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can view the task | Open, read, comment, log work, change status | See tasks outside their visibility |
| Phone users | Panel opens full-screen | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Same data and abilities as `Tasks/Show`; panel reuses its components
- Closing the panel returns focus to the card; Esc closes
- Deep link `?task=KEY` opens the panel on load

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Same route binding as `tasks.show`
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Same policies as `tasks.show`
- [ ] **Models and services**: new or changed classes, transactions, soft deletes
- [x] **Routes and controllers**: new routes, validation, authorization
  - Reuse `tasks.show` with an Inertia partial/`only` request or a JSON "panel" endpoint
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Extract the body of `Tasks/Show.vue` into `Components/Tasks/TaskDetail.vue`; new `TaskPanel.vue` (Headless UI Dialog, slide-in); board/list/backlog cards open it
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Show page must render identically after the extraction; drag and drop on cards must not trigger the panel; GlobalSearch result click may open the panel too
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [x] **Performance**: queries per page, indexes, large lists
  - Load the detail on demand; cache last opened task for instant reopen

Risks and how they are handled:

- Large refactor of `Show.vue`: do it first with no visual change, test, then add the panel
- Focus trapping and screen readers: use Headless UI Dialog

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Extract `TaskDetail.vue` from `Tasks/Show.vue` (no visual change) and re-test the page
- [ ] 6.2 Endpoint/partial that returns the task detail payload for the panel
- [ ] 6.3 `TaskPanel.vue` slide-over with open/close, focus return, Esc, mobile full screen
- [ ] 6.4 Open from board cards, list rows, backlog rows, sprint lists, search results
- [ ] 6.5 URL sync `?task=KEY` (open on load, update on open/close)
- [ ] 6.6 Tests: payload parity with show page, visibility, deep link
- [ ] 6.7 Docs: screen map in WORKFLOW section 12

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
