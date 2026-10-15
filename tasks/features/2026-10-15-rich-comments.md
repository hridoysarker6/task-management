# Feature: Richer comments: reactions, threads, paste and drop files

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-rich-comments.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Make comments feel modern: emoji reactions, replies in a thread, drag files or paste screenshots straight into the comment box.

## 2. Goals and non-goals

- Goal: React to a comment with a small set of emoji; see who reacted
- Goal: Reply to a comment (one level of threading)
- Goal: Paste an image or drop a file into the comment box to attach it
- Goal: Edit and delete keep working; internal comments stay hidden from clients
- Not in this change: Full chat, voice or video

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who may comment | React, reply, paste/drop files | React to or see internal comments as an external stakeholder |
| Comment author | Edit or delete own comment | Edit others' comments |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Reactions are unique per person, comment and emoji
- Replies are always the same visibility (internal/public) as the parent
- Attachments follow `AttachmentService` rules (types, size, count)
- A reply notifies the parent's author and watchers like a comment

## 4. Data

New `comment_reactions` (comment_id, membership_id, emoji, unique). Add `comments.parent_id` (nullable, FK, index). Both additive; reactions cascade on comment delete (soft delete keeps rows).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - `comment_reactions` carries `organization_id`; all reads scoped
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuse `comment`, `editComment`, `attach` abilities; hide internal threads from external viewers
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `CommentService` (reply, react), `AttachmentService`, `Comment` model relations
- [x] **Routes and controllers**: new routes, validation, authorization
  - `POST comments/{comment}/reactions`, `POST tasks/{task}/comments` accepts `parent_id`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Tasks/Show.vue` comment list, `MentionTextarea.vue` (paste/drop), new `Reactions.vue`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Mentions, watchers and internal-comment filtering in `Notifier::commented`; activity log; task panel
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Reply uses the `comment` event; reactions do not notify (avoid noise)
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Not an admin action
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Add reactions and a threaded reply to `FeatureTestSeeder`
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE section 6, WORKFLOW 6.5
- [x] **Performance**: queries per page, indexes, large lists
  - Load reactions with the comment list in one query

Risks and how they are handled:

- Pasted images are user content: reuse the sanitised upload path, never inline raw data URLs
- Deep threads: limit to one level

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration: `comment_reactions` and `comments.parent_id`
- [ ] 6.2 Models and `CommentService` reply/react methods with visibility rules
- [ ] 6.3 Endpoints with validation and authorization
- [ ] 6.4 UI: reaction bar, reply box and thread layout
- [ ] 6.5 Paste/drop to attach through `AttachmentService`
- [ ] 6.6 Notifications for replies via `Notifier`
- [ ] 6.7 Tests: visibility for clients, uniqueness, reply rules, tenant isolation, attachment limits
- [ ] 6.8 Seed data and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
