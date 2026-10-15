# Feature: Favourites and pinned views

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-saved-views-and-favorites.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Pin projects, saved filters and reports to the sidebar for one-click access.

## 2. Goals and non-goals

- Goal: Star a project or a saved filter; starred items appear in a "Favourites" section of the sidebar
- Goal: Reorder favourites by drag; remove with one click
- Goal: Remember the last-used view (board/list/backlog) per project
- Not in this change: Shared team favourites (a saved filter can already be shared)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Star/unstar and reorder their own favourites | See other people's favourites |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Favourites are per person per company
- A favourite that the person can no longer see (left the project) is hidden and cleaned up lazily

## 4. Data

New `favorites` (organization_id, membership_id, type project|saved_filter|report, target_id, position, unique per person/type/target).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - `organization_id` on the table; resolved targets must be visible to the person
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Visibility check of each target on read
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `FavoriteService`
- [x] **Routes and controllers**: new routes, validation, authorization
  - Toggle and reorder endpoints
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Sidebar section in `AppLayout.vue`, star buttons on project header and saved-filter menu
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Saved filters (`SavedFilter`), `ProjectHeader`, sidebar width when collapsed
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A few favourites for the PM
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE
- [x] **Performance**: queries per page, indexes, large lists
  - Load favourites with the shared Inertia props (single query)

Risks and how they are handled:

- Stale targets after offboarding or project deletion: filter on read

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for `favorites`
- [ ] 6.2 `FavoriteService` with visibility filtering
- [ ] 6.3 Endpoints and shared prop
- [ ] 6.4 Sidebar section with drag reorder and collapsed state
- [ ] 6.5 Star toggles on project header and saved filters
- [ ] 6.6 Last-used view memory per project
- [ ] 6.7 Tests: toggle, order, hidden targets, isolation per person and company
- [ ] 6.8 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
