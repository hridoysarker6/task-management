# Feature: Subscription plans and billing per company

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-billing-plans.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Plans with limits (users, projects, storage) and feature flags per company, managed by the System Admin, with room for a payment provider later.

## 2. Goals and non-goals

- Goal: Plan catalogue (Free, Team, Business) with limits and feature flags
- Goal: System Admin assigns a plan to a company and sees usage against limits
- Goal: Limits enforced: seats (already via `user_limit`), projects, storage, optional features
- Goal: Company admin sees plan and usage, and an upgrade prompt when a limit is hit
- Not in this change: Taking payments in the first version (manual/offline invoicing), tax and invoices

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| System Admin | Create plans, assign them, override limits | - |
| Company admin | See plan, usage and limits | Change the plan themselves (first version) |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- `organizations.user_limit` becomes plan-driven with an override
- Hitting a limit blocks the creating action with a clear message, never data loss
- Downgrades never delete data; they block new creation over the limit

## 4. Data

New `plans` (name, limits json, features json, is_active) and `subscriptions` (organization_id, plan_id, starts_on, ends_on, status, overrides json). Backfill: assign every existing company the Free/Team plan matching its current `user_limit`.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - `subscriptions.organization_id`; platform-level `plans` are global (System Admin only)
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - System Admin screens; company admin read-only via `company.view_settings`
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `PlanService`, extend `SeatService` into a general limits check
- [x] **Routes and controllers**: new routes, validation, authorization
  - Admin plan and subscription pages; company usage page
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Admin > Plans, company > Plan and usage, upgrade prompts
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Seat checks in invitations, import and rehire; project creation; attachment storage; suspended company flow
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Plan changes recorded as platform-level lines
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - Usage numbers for admins
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Three plans and subscriptions for demo companies
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE, PLAN
- [x] **Performance**: queries per page, indexes, large lists
  - Cache plan lookups per request

Risks and how they are handled:

- Wrongly blocking existing customers: backfill carefully and run in "warn only" mode first

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migrations and backfill for plans and subscriptions
- [ ] 6.2 `PlanService` limits and feature flags; refactor `SeatService` onto it
- [ ] 6.3 Enforcement points (people, projects, storage) with clear messages
- [ ] 6.4 Admin plan and subscription management
- [ ] 6.5 Company plan and usage page with upgrade prompt
- [ ] 6.6 Audit, seed and docs
- [ ] 6.7 Tests: limits per resource, downgrade behaviour, backfill, tenant isolation

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
