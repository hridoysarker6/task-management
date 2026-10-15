# Feature: Git commits and pull requests linked to tasks

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-git-integration.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Commits, branches and pull requests whose message or name contains a task key (for example `ECP-12`) show up on that task, and can move its status.

## 2. Goals and non-goals

- Goal: Webhook endpoint for GitHub (first), later GitLab/Bitbucket
- Goal: Task page lists linked branches, commits and PRs with state
- Goal: Optional rules: PR opened -> In Review, PR merged -> Done
- Not in this change: Creating branches from the task, code review inside Task Pro

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Project manager | Connect a repository, choose status rules | - |
| Members | See development info on the task | See repositories of projects they are not in |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Match keys case-insensitively and only for projects of the connected company
- Webhook is verified with a shared secret signature
- Status moves follow the project workflow; a refused move is only logged

## 4. Data

New `git_repositories` (organization_id, project_id, provider, full_name, secret) and `git_links` (organization_id, task_id, kind, external_id, title, url, state, author, occurred_at; unique per external id and task).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Both tables carry `organization_id`; webhook resolves company through the repository record
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `integration.manage` for managers
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `GitHookService`, reuse `TaskService::changeStatus` with a system actor
- [x] **Routes and controllers**: new routes, validation, authorization
  - Public signed webhook, connect/disconnect UI routes
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Project settings > Integrations; "Development" panel on the task page
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Task activity log, notifications for status changes, automation rules (shares triggers)
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Connect/disconnect and secret rotation recorded
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Webhook processing queued; failures visible in Failed jobs
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A repository and sample commit/PR links
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE, deployment (public URL)
- [x] **Performance**: queries per page, indexes, large lists
  - Index (task_id), unique external id

Risks and how they are handled:

- Forged webhooks: signature check and rate limit
- Key collisions with unrelated text: require the project key to exist and match word boundaries

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for repositories and links
- [ ] 6.2 `integration.manage` permission and defaults
- [ ] 6.3 Webhook endpoint with signature verification and idempotency
- [ ] 6.4 `GitHookService`: parse keys, create/update links, optional status rules
- [ ] 6.5 Settings UI and task "Development" panel
- [ ] 6.6 Audit and failed-job visibility
- [ ] 6.7 Tests: signature, key parsing, status rules incl. refusal, tenant isolation
- [ ] 6.8 Seed and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
