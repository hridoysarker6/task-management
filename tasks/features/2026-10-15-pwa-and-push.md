# Feature: Installable app (PWA) and push notifications

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Medium
- **Depends on:** `realtime-board-reverb`
- **Spec file:** `tasks/features/2026-10-15-pwa-and-push.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Make Task Pro installable on phones and desktops, with push notifications for the events people choose.

## 2. Goals and non-goals

- Goal: Web app manifest, icons and a service worker (offline shell, cache of static assets)
- Goal: Opt-in push notifications that follow the existing notification preferences
- Goal: Open the relevant task when a push is tapped
- Not in this change: Offline data editing, native store apps

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Install, enable or disable push per device | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Push is a third channel next to bell and email in `NotificationEvents` preferences
- Subscriptions are per membership and device; removed on logout or failure
- Never put task content in a push for events marked private; send a generic text

## 4. Data

New `push_subscriptions` (organization_id, membership_id, endpoint unique, keys, user_agent, last_used_at).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Subscriptions belong to a membership in one company
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Own subscriptions only
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - A `WebPush` channel on `AppNotification::via` (needs a web-push package: check it can be installed offline first)
- [x] **Routes and controllers**: new routes, validation, authorization
  - Subscribe/unsubscribe endpoints; service worker file in `public/`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Install prompt, push toggle in notification settings, `manifest.webmanifest`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - `AppNotification` channels, preferences table (add `via_push`), queue worker
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Not an admin action
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Push sent from the queued notification
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Nothing
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - README, deployment (HTTPS required)
- [x] **Performance**: queries per page, indexes, large lists
  - Prune dead endpoints

Risks and how they are handled:

- Needs HTTPS and a VAPID key pair in production; package availability

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Check web-push package availability; fall back to a minimal implementation
- [ ] 6.2 Manifest, icons, service worker
- [ ] 6.3 `push_subscriptions` migration and endpoints
- [ ] 6.4 `via_push` preference and notification channel
- [ ] 6.5 UI: install prompt and toggle
- [ ] 6.6 Tests: subscribe/unsubscribe, channel selection by preference, cleanup, tenant isolation
- [ ] 6.7 Docs and deployment notes

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
