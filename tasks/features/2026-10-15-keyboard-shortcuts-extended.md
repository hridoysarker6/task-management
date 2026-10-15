# Feature: More keyboard shortcuts and a shortcut help sheet

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-keyboard-shortcuts-extended.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Navigate and act without the mouse: move between tasks, assign, change status, and see every shortcut with "?".

## 2. Goals and non-goals

- Goal: `J/K` move the selection through the list/board; `Enter` opens
- Goal: `A` assign, `S` status, `P` priority on the selected task
- Goal: `G` then `D/B/L/T` go to dashboard/board/list/timesheet
- Goal: `?` opens a help sheet listing all shortcuts
- Not in this change: User-defined shortcuts

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Use shortcuts when no field is focused and no dialog is open | Trigger an action they have no permission for (silently ignored) |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Shortcuts are ignored while typing, with modifiers held, or when a dialog is open (same guard as C and /)
- A visible focus ring shows the selected task

## 4. Data

No schema change.

## 5. Impact analysis

- [ ] **Tenancy**: company scoping, tenant isolation of every new query and route binding
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Actions go through the same permission checks as the buttons
- [ ] **Models and services**: new or changed classes, transactions, soft deletes
- [ ] **Routes and controllers**: new routes, validation, authorization
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Layouts/AppLayout.vue` handler, a `useShortcuts` composable, `ShortcutHelp.vue` dialog, selection state in list/board
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Existing `C` and `/` and Ctrl+K handlers must not conflict; drag and drop and text editors unaffected
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [x] **Performance**: queries per page, indexes, large lists
  - One global listener

Risks and how they are handled:

- Accessibility: keep everything reachable by Tab as well
- Key clashes with browser shortcuts: avoid Ctrl/Alt combos

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 `useShortcuts` composable with guards and a registry
- [ ] 6.2 Selection model for list rows and board cards
- [ ] 6.3 Implement J/K/Enter, A/S/P, G-sequences
- [ ] 6.4 `ShortcutHelp.vue` opened by `?` and a link in the user menu
- [ ] 6.5 Tests (frontend logic by a small unit test of the registry or manual checklist)
- [ ] 6.6 Docs: WORKFLOW section 12

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
