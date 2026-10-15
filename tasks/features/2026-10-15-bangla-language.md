# Feature: Bangla language support

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-bangla-language.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Use Task Pro in Bangla or English, chosen per person, with Bangla dates and numbers where sensible.

## 2. Goals and non-goals

- Goal: Language switcher (English, Bangla) in the user menu, remembered per person
- Goal: All interface text, emails and validation messages translated
- Goal: Correct Bangla font rendering and date/number formatting options
- Not in this change: Translating user content (titles, comments), right-to-left languages

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Any signed-in person | Choose a language | - |
| Company admin | Set the company default language | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Fallback to English for any missing key
- Company default applies until a person chooses
- Dates keep the company date format; digits can be shown in Bangla numerals

## 4. Data

Add `users.locale` and `organizations.default_locale` (nullable). Additive.

## 5. Impact analysis

- [ ] **Tenancy**: company scoping, tenant isolation of every new query and route binding
- [ ] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - Laravel `lang/bn` files for validation, notifications, mail views
- [x] **Routes and controllers**: new routes, validation, authorization
  - `PATCH profile/locale`; middleware sets `app()->setLocale`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Every Vue page and component: move literals to translation keys (vue-i18n or a light helper); check layouts for longer Bangla text
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Emails (`resources/views/emails`), `NotificationEvents` labels, exports, error pages, audit labels
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Mail and bell text rendered in the recipient's language at send time
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Nothing
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - CLAUDE.md: no hard-coded UI strings
- [x] **Performance**: queries per page, indexes, large lists
  - Load only the active language file

Risks and how they are handled:

- Huge mechanical change: extract strings page by page behind a helper and keep English default so nothing breaks mid-way
- Package availability offline: a small in-house `t()` helper is an option

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Decide library vs helper; add the `t()` plumbing and locale middleware
- [ ] 6.2 Migration for locale columns, switcher and company default
- [ ] 6.3 Extract and translate shared components and layouts
- [ ] 6.4 Extract and translate pages in batches (auth, dashboard, projects, tasks, time, reports, settings)
- [ ] 6.5 Translate emails, notifications, validation and error pages
- [ ] 6.6 Font and layout checks in Bangla
- [ ] 6.7 Tests: locale resolution, fallback, mail language, missing-key report
- [ ] 6.8 Docs: contributor rule

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
