# Project Task List

The development checklist for the Task Management System.
References: [PLAN.md](PLAN.md) Â· [WORKFLOW.md](WORKFLOW.md) Â· [DATABASE.md](DATABASE.md)

**Legend:** `[ ]` todo Â· `[x]` done Â· **Est.** = working days Â· âš‘ = depends on an earlier phase

| Phase | Name | Est. | Milestone |
|---|---|---|---|
| 0 | Project setup | 2 | |
| 1 | Company registration & System Admin | 4 | |
| 2 | Roles & permissions engine | 4 | |
| 3 | People, hierarchy, exit & rehire | 6 | |
| 4 | Projects | 3 | |
| 5 | Tasks core | 7 | |
| 6 | Board, list & backlog views | 5 | |
| 7 | Sprints | 3 | **MVP (Phases 0â€“7)** |
| 8 | Time tracking & timesheets | 4 | |
| 9 | Dashboards & reports | 6 | |
| 10 | Notifications | 3 | |
| 11 | Audit, search, polish | 4 | |
| 12 | Testing, security, deployment | 5 | **Release 1.0** |
| | **Total** | **â‰ˆ 56 days** (~11 weeks, 1 developer) | |

---

## Phase 0: Project Setup (2 days)
- [x] 0.1 Create a Laravel 12 project in `D:\laragon\www\task management` (PHP 8.3)
- [x] 0.2 Install Breeze with the **Vue + Inertia** stack; Tailwind; Vite dev server works
- [x] 0.3 Create the MySQL database `task_management`; configure `.env` (app URL, mail via Mailpit/log)
- [x] 0.4 `git init`, `.gitignore`, first commit
- [x] 0.5 Install packages: `vuedraggable`, `@headlessui/vue`, `@heroicons/vue`, `chart.js` + `vue-chartjs`, `@tiptap/vue-3` (rich text), `dayjs`
- [x] 0.6 Install dev tools: Laravel Pint, Larastan, Pest, Laravel Debugbar
- [x] 0.7 Base app layout: sidebar, top bar, company switcher placeholder, user menu, flash/toast messages
- [x] 0.8 Reusable UI components: Button, Input, Select, Modal, Table, Badge, Avatar, Dropdown, Pagination, EmptyState, ConfirmDialog
- [x] 0.9 Configure queue (database driver), scheduler, and storage link

## Phase 1: Company Registration & System Admin (4 days)
- [x] 1.1 Migrations: change `users` (is_system_admin, status, phone, avatar, last_organization_id), `organizations`, `organization_reviews`, `organization_documents`
- [x] 1.2 Models + factories: Organization, OrganizationReview, OrganizationDocument
- [x] 1.3 Seeder: first **System Admin** user
- [x] 1.4 Public **Register Company** page (company info + contact person + documents) with validation
- [x] 1.5 Email verification, then the **Awaiting approval** page; block access while pending
- [x] 1.6 System Admin area: separate layout + `system.admin` middleware
- [x] 1.7 Companies list with tabs (pending / active / rejected / suspended) and search
- [x] 1.8 Company detail page: info, documents, review history
- [x] 1.9 Actions: **Approve**, **Reject** (reason), **Request info** (note), **Suspend / Reactivate**, set user limit
- [x] 1.10 Applicant **edit & resubmit** flow (after rejected / info requested)
- [x] 1.11 `CompanyProvisioningService` on approval: Company Admin membership, copy role template, default statuses (default statuses and task types are per project, so they are created with projects in Phase 4)
- [x] 1.12 Emails: registered (to system admins), approved, rejected, info requested
- [x] 1.13 Block login for users whose only company is suspended
- [x] 1.14 Tests: registration, approval provisioning, rejected/suspended access

## Phase 2: Roles & Permissions Engine (4 days)
- [x] 2.1 Migrations: `permissions`, `roles`, `role_permission`, `membership_permission_overrides`
- [x] 2.2 Seeder: full **permission catalog** (all modules from WORKFLOW Â§4.2)
- [x] 2.3 Seeder: **default role template** (Company Admin, HOD, DGM, AGM, DM, PM, TL, Developer, Internal Stakeholder, External Stakeholder) with levels, data scopes, and the default matrix (WORKFLOW Â§4.3)
- [x] 2.4 `SetCurrentOrganization` middleware (active company from session; company switcher)
- [x] 2.5 `PermissionService`: effective permissions = role âˆª grants âˆ’ revokes, cached per membership
- [x] 2.6 Hook into Laravel Gate (`Gate::before`) so `@can('task.assign')` / `$user->can()` work
- [x] 2.7 Share permissions with Vue through Inertia props; `can('x')` helper + `v-can` directive
- [x] 2.8 **Roles & Permissions matrix page:** grid of modules Ã— roles with checkboxes and bulk select per module
- [x] 2.9 Role CRUD: create custom role, set level + data scope, copy from an existing role; lock the Company Admin role
- [x] 2.10 System Admin: edit the **default role template**
- [x] 2.11 Tests: permission resolution, overrides, cache invalidation

## Phase 3: People, Hierarchy, Exit & Rehire (6 days) âš‘2
- [x] 3.1 Migrations: `departments`, `memberships`, `membership_hierarchy`, `employment_periods`, `invitations`, `teams`, `team_members`
- [x] 3.2 Models + relationships; `Membership` soft deletes; `active()` / `former()` scopes
- [x] 3.3 Departments CRUD (assign HOD)
- [x] 3.4 `HierarchyService`: set manager, cycle check, rank check (manager must have a lower level number), closure table rebuild
- [x] 3.5 **Invite user** form: email, role, department, reports-to, internal/external, designation, employee code
- [x] 3.6 Invitation email + **Accept invitation** page (new user sets password / existing user logs in)
- [x] 3.7 Resend / revoke invitation; expiry after 7 days
- [x] 3.8 **Bulk import** users from CSV/Excel with a preview and validation error report (CSV only for now; Excel files are not supported yet)
- [x] 3.9 Users list (active / invited / former tabs), filters, search, user detail page
- [x] 3.10 Edit member: role, department, manager, designation (permission-checked)
- [x] 3.11 **Org chart** page (tree, collapsible, open-task count per person) (the open-task badge shows once tasks exist in Phase 5)
- [x] 3.12 **Offboard** flow: exit type/date/reason â†’ handover screen (reassign tasks, direct reports, PM projects, approvals) (direct reports and the projects they manage are handed over; open tasks and timesheet approvals plug into the same screen in Phases 5 and 8)
- [x] 3.13 Scheduled exits: daily command `members:process-exits`
- [x] 3.14 Exit effects: soft delete, revoke sessions, closure rebuild, end project memberships, remove watchers (project memberships end through the `MembershipExited` event; task watchers are removed when tasks arrive in Phase 5)
- [x] 3.15 Login message for exited members; redirect to another active company if any
- [x] 3.16 **Rehire** flow: detect former member by email â†’ show history â†’ "same person?" â†’ new role/manager â†’ choose old projects
- [x] 3.17 "Different person, same email" path: archive the old user, create a new one
- [x] 3.18 Employment history section on the profile
- [x] 3.19 Safety rules: cannot exit the last Company Admin; must reassign direct reports first; user limit check
- [x] 3.20 Company onboarding wizard (profile â†’ departments â†’ roles â†’ invite â†’ hierarchy â†’ first project)
- [x] 3.21 Company settings page (logo, timezone, working days, date format)
- [x] 3.22 Tests: hierarchy cycles, closure correctness, exit + rehire data, invitation flows

## Phase 4: Projects (3 days) âš‘3
- [x] 4.1 Migrations: `projects`, `project_members`, `project_statuses`, `status_transitions`, `labels`
- [x] 4.2 Project CRUD (key, name, department, PM, dates, description) with a unique key per company
- [x] 4.3 Projects list (cards/table, filter by status/department, my projects only)
- [x] 4.4 Project members management (add/remove, project role, re-add sets `left_at = null`)
- [x] 4.5 Default statuses created per project; **workflow editor** (add/rename/reorder/color/category)
- [x] 4.6 Optional allowed-transitions editor
- [x] 4.7 Project settings: sprints on/off, triage mode, client can see all, allow self-close, progress mode
- [x] 4.8 Project status changes: on hold / completed / archived / restore
- [x] 4.9 Labels management
- [x] 4.10 `ProjectPolicy` + tests

## Phase 5: Tasks Core (7 days) âš‘4
- [x] 5.1 Migrations: `tasks`, `task_watchers`, `task_links`, `label_task`, `comments`, `comment_mentions`, `attachments`, `task_activities`
- [x] 5.2 `TaskNumberService` (row lock on `projects.task_counter`) â†’ keys like `ECP-42`
- [x] 5.3 **VisibleTo scope** on Task (own / team / department / organization + project membership + external rules)
- [x] 5.4 `TaskPolicy`: view, create, edit_own/any, delete_own/any, assign, change_status, close, reopen, triage
- [x] 5.5 **Create task modal** (global + button): project, type, title, description editor, priority, assignee, labels, parent, sprint, points, estimate, dates, attachments (the sprint field arrives with sprints in Phase 7)
- [x] 5.6 Initial status logic (Triage / Backlog / To Do); auto-watch reporter + assignee
- [x] 5.7 **Task detail page / side panel:** inline field editing, breadcrumbs (epic â†’ story â†’ subtask)
- [x] 5.8 Sub-tasks list + create subtask; epic children with a progress bar
- [x] 5.9 Status change with transition and permission checks; `completed_at` handling; close-parent warning
- [x] 5.10 Assignment rules (self-assign, assign within scope, PM assigns any member)
- [x] 5.11 Comments: create/edit/delete, **Internal/Public** toggle, @mention autocomplete
- [x] 5.12 Attachments: upload (size/type limits), preview images, download, delete
- [x] 5.13 Task links (blocks / relates / duplicates / clones) with a search picker
- [x] 5.14 Watchers: watch/unwatch, list watchers
- [x] 5.15 **Activity timeline** (Task observer writes `task_activities` for every change)
- [x] 5.16 **Triage queue** page: accept (set type/priority/assignee), reject (reason), ask for info
- [x] 5.17 External stakeholder view restrictions (hide internal comments, estimates, work logs)
- [x] 5.18 Delete / restore task (soft delete)
- [x] 5.19 **My Work** page: assigned to me / reported by me / watching
- [x] 5.20 Tests: visibility for **every role** (Dev can't see the other team, DM sees both TLs, external sees only own, â€¦)

## Phase 6: Board, List & Backlog Views (5 days) âš‘5
- [x] 6.1 **Kanban board**: columns = statuses, drag & drop between and within columns (`board_position`)
- [x] 6.2 Board filters: assignee avatars, type, priority, label, "only my issues", search
- [x] 6.3 Board swimlanes (none / assignee / epic)
- [x] 6.4 Card design: key, title, type icon, priority, assignee avatar, points, due date (red if overdue)
- [x] 6.5 **List view**: sortable columns, pagination, column picker
- [x] 6.6 Advanced filters (status, assignee, reporter, sprint, label, date ranges, overdue) (sprint filter backend is in; the sprint picker arrives with Phase 7)
- [x] 6.7 **Saved filters** (private / shared)
- [x] 6.8 Bulk actions in the list (assign, status, sprint, label, delete) behind `task.bulk_update` (sprint action arrives with Phase 7)
- [x] 6.9 **Team view**: tasks grouped by person for everyone under me
- [x] 6.10 Backlog page (rank ordering by drag, `backlog_rank`)

## Phase 7: Sprints (3 days) âš‘6 â†’ **MVP complete**
- [ ] 7.1 Migrations: `sprints`, `sprint_task`
- [ ] 7.2 Create / edit / delete sprint (name, goal, dates)
- [ ] 7.3 Sprint planning: drag tasks backlog â‡„ sprint; capacity per person (points + hours)
- [ ] 7.4 **Start sprint** (one active per project; snapshot committed points)
- [ ] 7.5 Board shows the active sprint only (when sprints are on)
- [ ] 7.6 **Complete sprint**: unfinished tasks â†’ next sprint or backlog; record outcomes
- [ ] 7.7 Kanban-mode projects hide sprint UI
- [ ] 7.8 Tests: sprint lifecycle, carry-over
- [ ] 7.9 **MVP demo seed data**: 1 company, full hierarchy (HOD â†’ DGM â†’ AGM â†’ DM â†’ 2 TL â†’ 6 Dev), PM, stakeholders, 2 projects, ~60 tasks
- [ ] 7.10 MVP review with stakeholders â†’ collect feedback

## Phase 8: Time Tracking & Timesheets (4 days) âš‘5
- [ ] 8.1 Migrations: `timesheets`, `work_logs`
- [ ] 8.2 **Log work** dialog on a task (date, hours:minutes, note); update remaining estimate + `time_spent_min`
- [ ] 8.3 Work log list on the task (edit/delete own)
- [ ] 8.4 **My Timesheet** weekly grid (tasks Ã— days), add time inline
- [ ] 8.5 Submit timesheet â†’ lock entries
- [ ] 8.6 **Approval queue** for TL/DM: approve / return with comment
- [ ] 8.7 Team timesheet view (who has submitted / missing)
- [ ] 8.8 Estimate vs actual display on task and epic
- [ ] 8.9 Tests: locking, approval permissions

## Phase 9: Dashboards & Reports (6 days) âš‘7, âš‘8
- [ ] 9.1 `ProgressService`: % complete (count / points) for project, sprint, epic, person, team
- [ ] 9.2 Project health rule (on track / at risk / delayed)
- [ ] 9.3 Dashboard widgets: my tasks, due soon, overdue, workload per person, status donut, project health list
- [ ] 9.4 Role-based dashboard layouts (Developer, TL, DM, AGM/DGM, HOD, PM, Stakeholder, Company Admin, System Admin)
- [ ] 9.5 **Drill-down**: HOD â†’ DGM â†’ AGM â†’ DM â†’ TL â†’ developer â†’ tasks
- [ ] 9.6 Project overview page (progress, burndown, members, recent activity)
- [ ] 9.7 Reports: **sprint report**, **burndown**, **velocity**
- [ ] 9.8 Reports: workload by person/team, overdue report, time logged (by person/project/date range)
- [ ] 9.9 Report filters + "include former employees" option
- [ ] 9.10 **Export** to Excel/CSV (`maatwebsite/excel`) and print-friendly PDF
- [ ] 9.11 Query performance: indexes checked, caching heavy widgets (5 min)

## Phase 10: Notifications (3 days) âš‘5
- [ ] 10.1 Notification classes for every event in WORKFLOW Â§10
- [ ] 10.2 In-app **bell**: unread count, dropdown, mark read / all read, notifications page
- [ ] 10.3 Email templates (branded, with company logo)
- [ ] 10.4 `notification_preferences` page (per event: app / email)
- [ ] 10.5 Scheduler: daily **due tomorrow** and **overdue** (assignee + manager) jobs
- [ ] 10.6 Queue all mail; failed job monitoring
- [ ] 10.7 (Optional) Real-time with **Laravel Reverb**: live board updates + instant notifications

## Phase 11: Audit, Search, Polish (4 days)
- [ ] 11.1 `audit_logs` writer for admin actions (roles, permissions, members, exits, rehires, company approvals)
- [ ] 11.2 Audit log page (company + System Admin) with filters
- [ ] 11.3 **Global search** (Ctrl+K): tasks by key/title, projects, people
- [ ] 11.4 Profile page: name, avatar, password, notification settings
- [ ] 11.5 Company switcher (users in more than one company)
- [ ] 11.6 Responsive / mobile layout check
- [ ] 11.7 Empty states, loading skeletons, error pages (403/404/419/500)
- [ ] 11.8 Keyboard shortcuts (C = create, / = search)
- [ ] 11.9 Dark mode (optional)

## Phase 12: Testing, Security, Deployment (5 days)
- [ ] 12.1 Feature test coverage for all policies and the visibility scope (target: every role Ã— every module)
- [ ] 12.2 Tenant isolation tests (company A can never read company B data via IDs in URLs)
- [ ] 12.3 Security review: authorization on every controller, mass-assignment, file upload validation, XSS in rich text (sanitize HTML), rate limiting on login/registration
- [ ] 12.4 Performance: N+1 check (Debugbar), eager loading, DB indexes, load test with 50k tasks
- [ ] 12.5 Backup plan: daily DB + uploads backup (`spatie/laravel-backup`)
- [ ] 12.6 Production server setup (PHP 8.3, MySQL 8, Nginx, Supervisor for queue, cron for scheduler, SSL)
- [ ] 12.7 Deploy script / CI (GitHub Actions: tests + Pint + Larastan)
- [ ] 12.8 User guides: System Admin, Company Admin, Manager, Developer, Stakeholder
- [ ] 12.9 UAT with a pilot company â†’ fix bugs â†’ **Release 1.0**

---

## Future (after 1.0)
- [ ] Gantt / timeline view, calendar view
- [ ] Recurring tasks, task templates
- [ ] Custom fields per project
- [ ] Automation rules ("when status = Done, notify X")
- [ ] Bangla language support
- [ ] Mobile app / PWA
- [ ] Integrations: Git commits linked to task keys, Slack/Teams, email-to-task
- [ ] Billing / subscription plans per company
