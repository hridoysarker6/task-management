# Task Management System: Development Plan

A Jira-style task manager with projects, a reporting hierarchy (Head of Department → AGM → DM → Team Lead → Developer, plus Project Managers and Stakeholders), and progress visibility based on role.

---

## 1. Recommended Tech Stack

Chosen to fit the existing Laragon setup (PHP 8.3, MySQL 8.4, Node 22).

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 12** (PHP 8.3) | Mature, fast to build with, works with Laragon out of the box |
| Frontend | **Inertia.js + Vue 3** + **Tailwind CSS** (confirmed) | SPA feel without building a separate API; good fit for drag-and-drop boards |
| Auth | Laravel Breeze / Fortify | Login, password reset, email verification |
| Roles & permissions | **Custom tables** (see [DATABASE.md](DATABASE.md) §2) | Roles attach to company memberships and need a level, a data scope, and per-user grant/revoke overrides |
| Activity log | **spatie/laravel-activitylog** | Task history ("X moved TMS-12 to In Review") |
| Realtime (later) | Laravel Reverb (WebSockets) | Live board updates and notifications |
| Queue | Database queue → Redis later | Emails and notifications |
| Drag & drop | vuedraggable / SortableJS | Kanban board |
| Charts | Chart.js / ApexCharts | Burndown, progress, workload |
| DB | MySQL 8.4 | Already installed |

---

## 2. Core Concepts

### 2.1 Organization (multi-tenant)
- A user can belong to **one or more organizations**. All data (departments, projects, tasks) is scoped to an organization.
- The org creator becomes the **Org Admin** and can invite users, create departments, define roles, and create projects.
- An org switcher in the header changes the active organization.

### 2.2 Two separate dimensions of access
This is the key design decision. Access to a task depends on two independent things:

1. **Reporting hierarchy (organizational role).** This decides *whose work you can oversee*.
   ```
   Head of Department
        └── DGM
             └── AGM
             └── DM
                  ├── Team Lead A ── Developer 1, Developer 2, ...
                  └── Team Lead B ── Developer 3, Developer 4, ...
   ```
   Every member has a `reports_to` link. A manager can see the tasks and progress of **everyone below them in the tree** (recursive).

2. **Project membership (project role).** This decides *which projects you work in*.
   - Project roles: `Project Manager`, `Member`, `Stakeholder`, `Viewer`.
   - A Project Manager may sit outside the reporting chain (cross-functional). The PM sees everything in their projects, whatever the hierarchy.

**Visibility rule:** a user can see a task if **any** of these hold:
- they are the reporter, assignee, or a watcher, **or**
- they are a member of the task's project, **or**
- the assignee or reporter is in their reporting subtree, **or**
- they are the Org Admin.

### 2.3 Roles (seeded defaults; Org Admin can edit them)

| Role | Level | Typical abilities |
|---|---|---|
| Org Admin | 0 | Everything in the org: users, roles, departments, settings |
| Head of Department | 10 | Sees the whole department; creates projects; department reports |
| DGM | 20 | Sees all AGMs below them and their teams; reports |
| AGM | 30 | Sees all DMs below them and their teams; reports |
| DM | 40 | Manages 2 or more Team Leads; sees their teams' tasks; creates projects |
| Project Manager | 50 | Manages the projects assigned to them: sprints, board, assignment |
| Team Lead | 60 | Manages their developers; assigns tasks and reviews them |
| Developer | 70 | Works on assigned tasks; creates tasks in projects they belong to |
| Stakeholder (internal / external) | 80 | Creates tasks or requests in the projects they belong to; tracks progress; comments |

The **level** number is used for rules such as "you can only assign to people at your level or below in your subtree". Roles stay configurable per org, so a different ladder can be modelled.

### 2.4 Who can create tasks
- **Everyone** who is a member of a project (including Stakeholders) can create tasks in it.
- Optional per-project setting: tasks created by Stakeholders go to a **"Triage / Requested"** status, so the PM or TL can accept and assign them.

---

## 3. Database Design (main tables)

> **Superseded by [DATABASE.md](DATABASE.md).** That file has the full, final schema; `organization_user` is renamed `memberships` there. The sketch below is kept for reference only.

```
organizations          id, name, slug, logo, owner_id, settings(json), timestamps
users                  id, name, email, password, avatar, timestamps
organization_user      id, organization_id, user_id, role_id, department_id,
                       reports_to_id (→ organization_user.id), designation,
                       status (active/invited/disabled), joined_at
hierarchy_paths        ancestor_id, descendant_id, depth      ← closure table for fast
                                                                "all my subordinates" queries
departments            id, organization_id, name, head_id
teams (optional)       id, organization_id, department_id, name, lead_id
team_user              team_id, user_id

roles / permissions    (spatie, team_id = organization_id)

projects               id, organization_id, department_id, key ("TMS"), name, description,
                       manager_id, status (active/on_hold/archived), start_date, end_date,
                       visibility (private/org), task_counter, settings(json)
project_user           project_id, user_id, project_role (manager/member/stakeholder/viewer)
project_statuses       id, project_id, name, category (todo/in_progress/done), color, position
                       ← per-project workflow columns, e.g. Backlog, To Do, In Progress, In Review, Done

sprints                id, project_id, name, goal, start_date, end_date, status (planned/active/closed)

tasks                  id, organization_id, project_id, number (→ key "TMS-42"),
                       parent_id (subtasks / epic children), type (epic/story/task/bug/subtask),
                       title, description (rich text), status_id, priority (lowest..highest),
                       reporter_id, assignee_id, sprint_id, story_points,
                       original_estimate, remaining_estimate, start_date, due_date,
                       position (board ordering), completed_at, timestamps, soft deletes
task_watchers          task_id, user_id
labels / label_task    project-scoped tags
task_links             task_id, linked_task_id, type (blocks / is_blocked_by / relates_to / duplicates)
comments               id, task_id, user_id, body, timestamps   (with @mentions)
attachments            id, attachable (morph), user_id, path, name, size, mime
work_logs              id, task_id, user_id, minutes, logged_on, note
activity_log           (spatie) every field change on a task
notifications          (Laravel) assigned, mentioned, status changed, due soon, commented
invitations            id, organization_id, email, role_id, reports_to_id, token, expires_at
```

**Why a closure table:** "Show me every task under my whole tree" must be a single indexed query, not recursion in PHP:
```sql
SELECT t.* FROM tasks t
JOIN organization_user ou ON ou.user_id = t.assignee_id AND ou.organization_id = ?
JOIN hierarchy_paths hp ON hp.descendant_id = ou.id
WHERE hp.ancestor_id = :my_membership_id
```
The closure table is rebuilt for the affected subtree whenever `reports_to_id` changes.

---

## 4. Features by Module

### M1. Auth & Organization
- Register, login, password reset, profile and avatar
- Create organization; switch between organizations
- Invite users by email with a role, department, and "reports to" manager
- Org settings: name, logo, default workflow

### M2. People & Hierarchy
- Departments CRUD; teams (Team Lead + developers)
- Assign role and manager to each member
- **Org chart view** (tree) showing the reporting structure
- Guard against cycles (A reports to B, B reports to A)

### M3. Projects
- Create project (key, name, department, PM, dates)
- Add members with project roles
- Configure workflow statuses (columns) per project
- Project settings: stakeholder triage on/off, default assignee
- Archive / restore

### M4. Tasks (core)
- Create task from anywhere (global "+ Create" button, as in Jira)
- Types: Epic, Story, Task, Bug, Sub-task; parent/child relations
- Fields: title, rich description, assignee, reporter, priority, status, labels, sprint, story points, estimate, start/due dates
- Comments with @mentions, attachments, watchers
- Task links (blocks, relates to, duplicates)
- Work log / time tracking
- Full **activity history** per task
- Task key auto-numbering per project (`TMS-1`, `TMS-2`, …)

### M5. Views
- **Kanban board** per project (drag between status columns, filter by assignee, type, or label)
- **Backlog + Sprints** (Scrum): plan the sprint, start it, complete it, roll over unfinished work
- **List / table view** with filters, sorting, and saved filters
- **My Tasks** (assigned to me, reported by me, watching)
- **Team view** for TL, DM, and HOD: tasks grouped by person in their subtree
- Calendar / timeline view (later phase)

### M6. Dashboards & Progress (role-based)
| Who | Dashboard shows |
|---|---|
| Developer | My open tasks, due soon, overdue, recently updated |
| Team Lead | Each developer's workload (open / in progress / done), overdue, blocked |
| DM | Rollup per Team Lead and team: completion %, overdue, velocity |
| AGM / HOD | Department-wide: projects health, % complete, overdue by team, trend charts |
| Project Manager | Project progress %, sprint burndown, velocity, status distribution, risk (overdue/blocked) |
| Stakeholder | Progress of the tasks and projects they requested or follow |

Progress % = done tasks (or story points) ÷ total, per project, sprint, epic, person, or team.

### M7. Notifications
- In-app bell + email: assigned, mentioned, commented, status changed, due tomorrow, overdue
- Per-user notification preferences
- (Later) real-time with Laravel Reverb

### M8. Reports
- Sprint report, velocity chart, burndown
- Workload by person or team, overdue report, time-logged report
- Export to CSV / Excel

### M9. Admin & Audit
- Manage roles and permissions per org
- Audit log of admin actions
- Soft delete + restore for tasks and projects

---

## 5. Authorization Design

- **Policies** (`TaskPolicy`, `ProjectPolicy`, `OrganizationPolicy`) implement the visibility rule in §2.2.
- **Global query scope `VisibleTo($user)`** on `Task` so every list, board, and report filters automatically and nothing leaks.
- **Middleware `SetCurrentOrganization`** resolves the active org and sets the spatie team id.
- Sample permission names: `project.create`, `project.manage`, `task.create`, `task.assign`, `task.edit.any`, `task.delete`, `sprint.manage`, `report.view.department`, `user.invite`, `role.manage`.
- Edit rules:
  - Assignee and reporter can edit their own task.
  - PM, and managers above the assignee, can edit any task they can see.
  - Stakeholders can comment and edit only their own tasks while those tasks are still in Triage.

---

## 6. Development Phases

| Phase | Scope | Est. |
|---|---|---|
| **0. Setup** | Laravel 12 + Inertia/Vue + Tailwind, Breeze auth, MySQL DB, base layout, git repo | 1–2 days |
| **1. Org & People** | Organizations, memberships, invitations, departments, roles (spatie), reports_to + closure table, org chart | 4–5 days |
| **2. Projects** | Project CRUD, members, project roles, custom workflow statuses | 3 days |
| **3. Tasks core** | Task CRUD, types, subtasks, keys, comments, attachments, watchers, activity log | 5–6 days |
| **4. Visibility & permissions** | Policies, `VisibleTo` scope, role-based edit rules, triage flow, plus tests for each role | 3–4 days |
| **5. Board, list & backlog** | Kanban drag-drop, list view with filters, backlog, sprints | 5–6 days |
| **6. Dashboards & reports** | Role-based dashboards, progress %, burndown, velocity, workload, exports | 5 days |
| **7. Notifications** | In-app + email, mentions, due-date reminders (scheduler) | 3 days |
| **8. Polish & deploy** | Search, saved filters, performance (indexes, eager loading), feature tests, seed demo data, deployment | 4–5 days |

**MVP = Phases 0–5** (orgs, hierarchy, projects, tasks, board, role visibility).

---

## 7. Folder / Code Structure (Laravel)

```
app/
  Models/            Organization, Membership, Department, Team, Project, ProjectStatus,
                     Sprint, Task, Comment, Attachment, WorkLog, Label
  Policies/          OrganizationPolicy, ProjectPolicy, TaskPolicy, SprintPolicy
  Services/          HierarchyService (closure table), TaskNumberService, ProgressService
  Http/Controllers/  OrganizationController, MemberController, ProjectController,
                     TaskController, BoardController, SprintController, DashboardController
  Http/Middleware/   SetCurrentOrganization
  Notifications/     TaskAssigned, TaskMentioned, TaskStatusChanged, TaskDueSoon
resources/js/
  Pages/             Dashboard, Projects/*, Tasks/*, Board, Backlog, Org/*, Reports/*
  Components/        TaskCard, TaskModal, KanbanColumn, OrgChart, UserAvatar, Filters
database/
  migrations/, seeders/ (default roles, demo org with full hierarchy)
tests/Feature/       visibility tests per role (Developer cannot see other team, DM sees both TLs, ...)
```

---

## 8. Demo Seed Data (for testing the hierarchy)
- Org "SSG Demo" → Department "Software"
- 1 HOD → 1 AGM → 1 DM → 2 Team Leads → 3 Developers each
- 1 Project Manager, 1 Stakeholder
- 2 projects, ~50 tasks across statuses

Log in as each role to verify visibility.

---

## 9. Confirmed Decisions
1. Frontend: **Vue 3 + Inertia.js**
2. Hierarchy: **HOD → DGM → AGM → DM → TL → Developer**. PM works across teams through project membership.
3. Companies **self-register**, and a **System Admin approves** them (platform-level Super Admin panel).
4. Stakeholders can be **internal or external**. External users see only their own projects.
5. **Sprints and time tracking** are included in the first release.
6. **Every role has a permission for each feature**: an editable permission matrix plus a data scope per role.

The complete end-to-end behaviour is in **[WORKFLOW.md](WORKFLOW.md)**.

Extra tables needed for these decisions: `organizations.status` (pending/info_requested/active/rejected/suspended), `company_applications` (review notes, documents), `roles.level` + `roles.data_scope`, `organization_user.user_type` (internal/external), `comments.is_internal`, `timesheets` (user, week, status, approved_by).

**Employee exit and rehire** (see WORKFLOW.md §3.4–3.5):
- `organization_user` gets `employee_code`, `status` (invited/active/exited), `left_at`, `exit_type` (resigned/terminated/contract_ended), `exit_reason`, `scheduled_exit_at`, and `deleted_at` (soft delete). There is **one row per (company, user)**. On rehire the same row is restored, so its id, hierarchy links, and history stay stable.
- New table `employment_periods`: membership_id, joined_at, left_at, role_id, department_id, reports_to_id, designation, exit_type, exit_reason. This is a new row for every join or rejoin.
- `project_user` gets `left_at` so past project membership is kept.
- `users` are never hard-deleted. Privacy removal = anonymize (System Admin only).
