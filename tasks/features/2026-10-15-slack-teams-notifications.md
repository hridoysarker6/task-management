# Feature: Slack and Microsoft Teams notifications

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-slack-teams-notifications.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Send chosen events to a Slack or Teams channel through incoming webhooks, per project.

## 2. Goals and non-goals

- Goal: Per project: connect a webhook URL and pick events (task created, status changed, comment, sprint start/complete)
- Goal: Messages with title, key, link and the person who acted
- Goal: Test button and a delivery log
- Not in this change: Two-way chat (slash commands, buttons) in the first version

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Project manager | Connect, test and disable a channel | - |
| Everyone | See nothing different | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Webhook URLs are secrets: stored encrypted, shown masked
- Only events of that project are sent; internal comments are never sent
- Failures retry with back-off and are logged

## 4. Data

New `channel_webhooks` (organization_id, project_id, provider, url encrypted, events json, is_active) and `channel_deliveries` (webhook_id, event, status, response, created_at).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Scoped by company and project
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `integration.manage` (shared with git integration)
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `ChannelNotifier` called from `Notifier` events
- [x] **Routes and controllers**: new routes, validation, authorization
  - Settings CRUD and test endpoint
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Project settings > Integrations
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - `Notifier` and `NotificationEvents`; queue worker; internal-comment rules
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Connect/disconnect recorded
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [x] **Queue, mail, scheduler**
  - Queued delivery with retries
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A disabled sample webhook
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE, README
- [x] **Performance**: queries per page, indexes, large lists
  - Batch bursts

Risks and how they are handled:

- Leaking private info to a public channel: events whitelist, no internal content, clear warning in the UI
- SSRF through webhook URLs: allow only https hosts of known providers

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration with encrypted URL
- [ ] 6.2 Provider formatters for Slack and Teams
- [ ] 6.3 `ChannelNotifier` hooked into task/sprint events with filtering
- [ ] 6.4 Settings UI with test and log
- [ ] 6.5 Retry/back-off and failure visibility
- [ ] 6.6 Tests: formatting, filtering (no internal), SSRF guard, tenant isolation
- [ ] 6.7 Docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
