# Feature: Custom fields per project

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-custom-fields.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Let a project add its own fields to tasks (text, number, dropdown, date, checkbox) and use them in lists, filters and reports.

## 2. Goals and non-goals

- Goal: Managers define fields per project with type, options, required flag
- Goal: Fields appear on create/edit, the task page and optionally as list columns
- Goal: Filter and sort the list by a field; include in CSV/Excel export
- Not in this change: Formulas, cross-project shared fields (later)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Project manager | Add, reorder, archive fields and options | Delete a field that has values (archive instead) |
| Anyone who may edit the task | Set values | Edit fields marked internal when external |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Field types: text, long text, number, date, dropdown, multi-select, checkbox
- Archived fields keep their values but are hidden
- External stakeholders see only fields flagged "visible to clients"

## 4. Data

New `custom_fields` (organization_id, project_id, name, type, options json, required, client_visible, position, archived_at) and `custom_field_values` (task_id, custom_field_id, value, unique pair; indexed value).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Both tables scoped by `organization_id` and project
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `customfield.manage`; value edits use `update` policy
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `CustomFieldService`, `Task` accessor/eager load
- [x] **Routes and controllers**: new routes, validation, authorization
  - Field CRUD under project settings; values via the task update endpoint
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Settings page, task form/page, list columns and filter bar
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - `TaskFilters` and sort, `TaskPresenter`, exports in `ReportService`, create dialog, bulk actions, templates and clone
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Field create/archive recorded
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - Export includes fields; no change to totals
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A "Customer" dropdown and "Release" text field on a project
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE section 6
- [x] **Performance**: queries per page, indexes, large lists
  - Eager load values for the visible page only; index (field, value) for filters

Risks and how they are handled:

- Filter/sort over EAV is slow at scale: limit to a few indexed types and test with 50k tasks

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for fields and values
- [ ] 6.2 Permission and role defaults
- [ ] 6.3 `CustomFieldService` (validate by type, required, client visibility)
- [ ] 6.4 Settings UI to define fields
- [ ] 6.5 Render and edit fields on create/edit/task page
- [ ] 6.6 List columns, filter, sort and export support
- [ ] 6.7 Tests: each type, required, client visibility, archive, filters, tenant isolation
- [ ] 6.8 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
