# Feature: <short name>

- **Status:** Planned | In progress | Done | Dropped
- **Requested:** YYYY-MM-DD
- **Spec file:** `tasks/features/YYYY-MM-DD-<slug>.md`
- **Related master task items:** (for example `tasks/TASKS.md` section or "new")

## 1. Summary

One short paragraph: what this adds or changes, and why.

## 2. Goals and non-goals

- Goal:
- Goal:
- Not in this change:

## 3. Behaviour (the spec)

Write what the user sees and what the system does, in plain steps. Cover the happy path, the empty state, errors and limits.

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| | | |

Rules taken from `WORKFLOW.md` (cite the section) and any new rule this feature introduces:

- 

## 4. Data

- New tables / columns (name, type, index, foreign key, `organization_id`, soft delete):
- Changed tables:
- Migration is additive and safe on a database that already has data? (yes / how)
- Backfill of existing rows:

## 5. Impact analysis

Tick what is touched and say how. Anything left unticked was considered and is not affected.

- [ ] **Tenancy**: company scoping, tenant isolation of every new query and route binding
- [ ] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
- [ ] **Models and services**: new or changed classes, transactions, soft deletes
- [ ] **Routes and controllers**: new routes, validation, authorization
- [ ] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
- [ ] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [ ] **Performance**: queries per page, indexes, large lists

Risks and how they are handled:

- 

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 
- [ ] 6.2 
- [ ] 6.3 Tests: policies and visibility, tenant isolation, each rule above, edge cases
- [ ] 6.4 Seed data and docs updated

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
