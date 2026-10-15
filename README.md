# Task Pro

A multi-company task and project manager: boards, backlogs and sprints, requests and triage, time tracking and timesheets,
dashboards and reports, and a reporting hierarchy that decides what each person can see.

Built with Laravel 13, Vue 3, Inertia, Tailwind CSS and Headless UI. MySQL for data, Pest/PHPUnit for tests.

## Documents

- `PLAN.md` - overview and architecture
- `WORKFLOW.md` - behaviour and the permission matrix
- `DATABASE.md` - schema
- `tasks/` - the roadmap (`tasks/TASKS.md`), the feature spec template and one spec per feature (`tasks/features/`)

## Run it (Windows / Laragon)

1. Put PHP, Composer and Node on your PATH (see `CLAUDE.md`).
2. `composer install` and `npm install`
3. Copy `.env.example` to `.env`, set the database, then `php artisan key:generate` and `php artisan migrate --seed`
4. `npm run build` (or `npm run dev` while working)
5. Run `start.bat`: it starts the web server, the queue worker (notifications and email) and the scheduler (daily reminders)
6. Optional demo company: `php artisan db:seed --class=DemoSeeder`

## Checks

```
php artisan test
vendor/bin/pint --dirty
vendor/bin/phpstan analyse
```
