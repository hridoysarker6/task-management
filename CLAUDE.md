# Task Pro (Task Management System)

Jira-style, multi-company task manager. Laravel 13 + Vue 3 + Inertia + Tailwind 3 + Headless UI, MySQL (`task_management`), Pest.

**Read before building:** `PLAN.md` (overview), `WORKFLOW.md` (behaviour and permission matrix), `DATABASE.md` (schema), `tasks/TASKS.md` (master roadmap; tick items as done), `tasks/README.md` (how feature specs are kept).

## Commands (Windows / Laragon: PHP is not on PATH)
Prepend `D:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;D:\laragon\bin\composer;D:\laragon\bin\nodejs\node-v22` to PATH.
- Tests: `php artisan test` (SQLite in memory) · Lint: `vendor\bin\pint --dirty` · Static: `vendor\bin\phpstan analyse`
- Frontend: `npm run dev` / `npm run build` · Dev server: preview `laravel` (port 8000)
- Notifications and email are queued: run `php artisan queue:work` (and `php artisan schedule:work` for the daily reminders). `start.bat` starts all three.
- Demo data: `php artisan db:seed --class=DemoSeeder`, then `--class=FeatureTestSeeder` for data in every feature. Never run `migrate:fresh` or any destructive command on the dev database (there is no `.env.testing`); test migrations against a scratch SQLite file by overriding `DB_CONNECTION` and `DB_DATABASE` in the command.

## Rules that must not be broken
- `users` = global login; `memberships` = person inside one company. Business records reference `memberships.id`, never `users.id`.
- Every company-owned table has `organization_id`; every query is scoped to the current company.
- Soft delete only; users are never hard-deleted (exit = soft-delete the membership; rehire restores it).
- Authorize with permissions + data scope + project membership (see WORKFLOW.md section 4), not hard-coded role names.
- UI components live in `resources/js/Components/UI`; layout is `Layouts/AppLayout.vue`. Colours come from the Tailwind tokens in `tailwind.config.js` (`gray` is slate, `indigo` is the Task Pro violet); do not hard-code hex colours.
- Admin and security actions are written to the audit log through `AuditLogger`; things people should hear about go through `Notifier`.

## Feature workflow (mandatory for every new feature or change request)

Work spec-first. Think, plan, find the impact, write the task list, then code. Do not start coding from the request alone.

1. **Understand.** Read the request against `PLAN.md`, `WORKFLOW.md` and `DATABASE.md`. Look at the code that already does something similar. Ask the user only when a decision truly changes the outcome and cannot be inferred; otherwise choose the sensible default and say so.
2. **Write the spec.** Copy `tasks/_TEMPLATE.md` to `tasks/features/YYYY-MM-DD-<short-name>.md` and fill in: summary, goals and non-goals, the behaviour (who can do what, empty and error cases, rules cited from WORKFLOW.md), and the data changes.
3. **Analyse the impact.** Complete the impact checklist in the spec: tenancy, permissions and policies, models and services, routes, screens, existing features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search, notifications, audit), seed data, docs and performance. List the risks and how each is handled. If the change alters existing behaviour or is large, show the user the spec and wait for a go-ahead before coding; otherwise continue.
4. **Make the task list.** Put numbered checkbox tasks in the spec, ordered: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs. Add a matching line to `tasks/TASKS.md` so the roadmap stays complete.
5. **Build task by task.** Follow the existing patterns and the rules above. Migrations must be additive and safe on a database that already has data. Tick each box in the spec as it is finished. If the plan turns out wrong, update the spec first, then the code.
6. **Test and verify.** Write tests for each rule in the spec, for permissions and visibility for every affected role (including external stakeholders), and for tenant isolation. Then run the full `php artisan test`, `vendor\bin\pint --dirty`, `vendor\bin\phpstan analyse` and `npm run build`, and check the screens in the browser on desktop and phone width. Fix what fails; never weaken a test to make it pass.
7. **Finish.** Update `FeatureTestSeeder`/`DemoSeeder` so the feature can be tried, update the docs the spec lists, fill in the Verification section, set the status to Done and tick `tasks/TASKS.md`. Report what was built, what was checked, any gaps, and that nothing is committed unless the user asked.

Small fixes (a typo, a one-line bug) may skip the spec file, but still think through what else the change touches and add a test. Anything that adds a screen, a table, a permission, a rule or a notification is a feature and follows all seven steps.

Never commit, push or run destructive database commands unless the user asks.
