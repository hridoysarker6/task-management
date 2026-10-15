# Feature: Workload view (capacity planning)

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-workload-view.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A weekly grid of people against assigned hours and capacity, so overload is visible and tasks can be dragged between people.

## 2. Goals and non-goals

- Goal: Per person per week: assigned estimate hours versus capacity (from company working days and a per-person weekly hours setting)
- Goal: Highlight overload, show unassigned work
- Goal: Drag a task from one person to another to reassign (within assign scope)
- Not in this change: Automatic levelling, absence/holiday calendars (later)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Team leads and above (`dashboard.team`) | See their line's workload; reassign within scope | See people outside their data scope |
| Project manager | See members of managed projects | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Hours = remaining estimate (fallback original estimate); tasks without estimates count as "unestimated" and are listed separately
- Capacity default = 8 h x company working days per week; overridable per person
- Weeks come from task due dates (or sprint end when no due date)

## 4. Data

Add `memberships.weekly_capacity_min` (nullable int). Additive.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - People and tasks scoped to company and the viewer's data scope
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuses `dashboard.team`/`report.view`; per-person capacity edited with `user.edit`
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `WorkloadService`, extends `Membership`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `GET workload`, `PATCH people/{membership}/capacity`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Workload/Index.vue`, capacity field on the person page
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Workload report in `ReportService` could reuse the service; assignment notifications
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Capacity change recorded as `member.updated`
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - The workload report can gain a capacity column
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Capacity values in `FeatureTestSeeder`
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE section 3
- [x] **Performance**: queries per page, indexes, large lists
  - Aggregate in SQL by assignee and week

Risks and how they are handled:

- Wrong conclusions from missing estimates: show the unestimated count prominently

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration: `weekly_capacity_min`
- [ ] 6.2 `WorkloadService` (week buckets, capacity, overload)
- [ ] 6.3 Workload page with grid, overload colours, unassigned row
- [ ] 6.4 Drag to reassign through `TaskService::assign`
- [ ] 6.5 Capacity editing on the person page + audit
- [ ] 6.6 Tests: bucketing, capacity default/override, scope per role, reassign refusal, tenant isolation
- [ ] 6.7 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
