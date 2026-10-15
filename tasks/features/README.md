# Planned features

One spec per feature (summary, behaviour, data, impact analysis, task list). Status is kept inside each file.
Build in the order of the table below unless a dependency says otherwise, and follow the Feature workflow in `CLAUDE.md`.

## A. Daily productivity

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Inline editing on list and board](2026-10-15-inline-edit.md) | High | Medium | - | Done |
| [Task side panel (peek without leaving the board)](2026-10-15-task-side-panel.md) | High | Medium | inline-edit | Done |
| [Drag to reassign and re-parent on the board](2026-10-15-board-drag-reassign.md) | Medium | Small | - | Done |
| [Quick add at the bottom of a column or list](2026-10-15-quick-add-task.md) | Medium | Small | - | Done |
| [More keyboard shortcuts and a shortcut help sheet](2026-10-15-keyboard-shortcuts-extended.md) | Medium | Small | - | Done |

## B. Collaboration

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Real-time updates and presence (Laravel Reverb)](2026-10-15-realtime-board-reverb.md) | High | Large | - | Planned |
| [Richer comments: reactions, threads, paste and drop files](2026-10-15-rich-comments.md) | Medium | Medium | - | Planned |
| [Task templates and recurring tasks](2026-10-15-task-templates-and-recurring.md) | Medium | Large | - | Planned |
| [Duplicate (clone) a task](2026-10-15-clone-task.md) | Low | Small | - | Planned |
| [Email to task](2026-10-15-email-to-task.md) | Low | Large | - | Planned |

## C. New views

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Calendar view](2026-10-15-calendar-view.md) | High | Medium | - | Planned |
| [Gantt / timeline view](2026-10-15-gantt-timeline.md) | Medium | Large | - | Planned |
| [Workload view (capacity planning)](2026-10-15-workload-view.md) | Medium | Medium | - | Planned |
| [Roadmap view (epics by quarter)](2026-10-15-roadmap-view.md) | Low | Medium | gantt-timeline | Planned |

## D. Smart and automation

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Automation rules](2026-10-15-automation-rules.md) | High | Large | - | Planned |
| [Custom fields per project](2026-10-15-custom-fields.md) | Medium | Large | - | Planned |
| [Stuck-task and overload alerts](2026-10-15-stuck-and-overload-alerts.md) | Medium | Medium | - | Planned |

## E. Personal experience

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Dark mode](2026-10-15-dark-mode.md) | Medium | Medium | - | Planned |
| [Favourites and pinned views](2026-10-15-saved-views-and-favorites.md) | Medium | Small | - | Planned |
| [First-login guided tour](2026-10-15-onboarding-tour.md) | Low | Small | - | Planned |
| [Command palette actions](2026-10-15-command-palette-actions.md) | High | Small | keyboard-shortcuts-extended | Planned |
| [Installable app (PWA) and push notifications](2026-10-15-pwa-and-push.md) | Low | Medium | realtime-board-reverb | Planned |
| [Bangla language support](2026-10-15-bangla-language.md) | Medium | Large | - | Planned |

## F. Integrations and reports

| Feature | Priority | Effort | Depends on | Status |
|---|---|---|---|---|
| [Git commits and pull requests linked to tasks](2026-10-15-git-integration.md) | Medium | Large | - | Planned |
| [Slack and Microsoft Teams notifications](2026-10-15-slack-teams-notifications.md) | Medium | Medium | - | Planned |
| [Scheduled report emails](2026-10-15-scheduled-report-emails.md) | Medium | Medium | - | Planned |
| [Subscription plans and billing per company](2026-10-15-billing-plans.md) | Low | Large | - | Planned |

## Suggested order

1. Quick wins that change daily use: inline-edit, command-palette-actions, quick-add-task, keyboard-shortcuts-extended, board-drag-reassign
2. Views: calendar-view, task-side-panel, workload-view, gantt-timeline, roadmap-view
3. Collaboration: rich-comments, clone-task, task-templates-and-recurring, realtime-board-reverb
4. Smart: automation-rules, stuck-and-overload-alerts, custom-fields
5. Reach: dark-mode, saved-views-and-favorites, onboarding-tour, bangla-language, pwa-and-push
6. Integrations and business: git-integration, slack-teams-notifications, scheduled-report-emails, email-to-task, billing-plans
