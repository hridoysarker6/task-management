# Feature: First-login guided tour

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-onboarding-tour.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A short, skippable tour of the main areas on a person's first login, plus an empty-state checklist for new companies.

## 2. Goals and non-goals

- Goal: 3 to 5 step tour tailored to the role (worker, lead, manager, client)
- Goal: Remembered as completed; can be restarted from the user menu
- Goal: "Getting started" checklist on the dashboard for admins (already partly present as company onboarding)
- Not in this change: Video tutorials, in-app chat support

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Run, skip and restart the tour | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Steps only reference items the person can see (permission aware)
- Skipping is permanent until restarted

## 4. Data

Add `memberships.tour_completed_at` (nullable). Additive.

## 5. Impact analysis

- [ ] **Tenancy**: company scoping, tenant isolation of every new query and route binding
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Step list built from the person's permissions
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `Membership` attribute
- [x] **Routes and controllers**: new routes, validation, authorization
  - `POST tour/complete`, `DELETE tour`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Components/Tour.vue` (spotlight and popover), hooks in `AppLayout.vue`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Company onboarding wizard (`OnboardingController`) must not clash
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW section 12
- [ ] **Performance**: queries per page, indexes, large lists

Risks and how they are handled:

- Annoyance: easy skip, never repeat unless asked

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for `tour_completed_at`
- [ ] 6.2 Tour step definitions per role capability
- [ ] 6.3 `Tour.vue` component with focus handling and Esc
- [ ] 6.4 Endpoints and user-menu "Restart tour"
- [ ] 6.5 Tests: completion state, steps by permission
- [ ] 6.6 Docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
