# Feature: Real-time updates and presence (Laravel Reverb)

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-realtime-board-reverb.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Cards, comments and notifications update live for everyone looking at the same board or task; see who else is on a page and who is typing.

## 2. Goals and non-goals

- Goal: Board, list and task page refresh when someone else changes a visible task
- Goal: Bell updates instantly instead of every minute
- Goal: Presence avatars on a board/task and a "typing..." hint in comments
- Goal: Falls back to the current polling when the socket is down
- Not in this change: Offline editing and conflict-free merging
- Not in this change: Live cursors

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone viewing a board/task | Receive live changes they are allowed to see | Receive events about tasks outside their visibility |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Channels are private and per company and project (`company.{id}`, `project.{id}`, `task.{id}`, `membership.{id}`)
- Authorization callbacks reuse the task/project policies and visibility scope
- Events carry minimal data (ids and changed fields); the client reloads the needed partial

## 4. Data

No tables. Needs a Reverb server process and `.env` keys.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Channel authorization must check the company; a user must never subscribe to another company's channel
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Channel auth = `view` policy of the project/task; external stakeholders only their own tasks
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - Broadcast events from `TaskObserver`, `CommentService`, `Notifier`, `BoardService`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `routes/channels.php`, broadcasting auth endpoint
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Echo client in `app.js`; board, list, task, bell and presence components
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Polling bell stays as fallback; drag and drop must ignore echoes of the person's own move
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Bell uses the `membership.{id}` channel
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Broadcasts are queued; Reverb process added to `start.bat` and deployment notes (Supervisor)
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - PLAN architecture, README run steps
- [x] **Performance**: queries per page, indexes, large lists
  - Throttle bursts, debounce reloads; load-test with 50 viewers

Risks and how they are handled:

- Data leaks through channel names or payloads: authorize every channel, send ids not content
- Operational: one more process to run and monitor

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Install and configure Reverb (`php artisan install:broadcasting`), Echo in the frontend (check offline availability of packages first)
- [ ] 6.2 `routes/channels.php` with company/project/task/membership channels and tests of each authorization
- [ ] 6.3 Broadcast events: task changed, card moved, comment added, notification sent
- [ ] 6.4 Board/list/task listeners that merge changes and ignore the actor's own events
- [ ] 6.5 Bell switched to live with polling fallback
- [ ] 6.6 Presence channel and avatars; typing indicator in comments
- [ ] 6.7 Process management: `start.bat`, Supervisor notes
- [ ] 6.8 Tests: channel authorization for every role and tenant, event payload contents
- [ ] 6.9 Seed/docs updates

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
