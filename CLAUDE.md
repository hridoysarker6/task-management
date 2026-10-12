# Task Management System

Jira-style, multi-company task manager. Laravel 13 + Vue 3 + Inertia + Tailwind 3, MySQL (`task_management`), Pest.

**Read before building:** `PLAN.md` (overview), `WORKFLOW.md` (behaviour and permission matrix), `DATABASE.md` (schema), `TASKS.md` (checklist; tick items as done).

## Commands (Windows / Laragon: PHP is not on PATH)
Prepend `D:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;D:\laragon\bin\composer;D:\laragon\bin\nodejs\node-v22` to PATH.
- Tests: `php artisan test` (SQLite in memory) · Lint: `vendor\bin\pint --dirty` · Static: `vendor\bin\phpstan analyse`
- Frontend: `npm run dev` / `npm run build` · Dev server: preview `laravel` (port 8000)

## Rules that must not be broken
- `users` = global login; `memberships` = person inside one company. Business records reference `memberships.id`, never `users.id`.
- Every company-owned table has `organization_id`; every query is scoped to the current company.
- Soft delete only; users are never hard-deleted (exit = soft-delete the membership; rehire restores it).
- Authorize with permissions + data scope + project membership (see WORKFLOW.md section 4), not hard-coded role names.
- UI components live in `resources/js/Components/UI`; layout is `Layouts/AppLayout.vue`.
