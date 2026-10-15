# Tasks

Everything about planned and finished work lives in this folder.

| File | What it is |
|---|---|
| `TASKS.md` | The master roadmap: Phases 0-12 and the later ideas. Tick items as they are done. |
| `_TEMPLATE.md` | The template for a new feature spec. Copy it, do not edit it. |
| `features/` | One file per new feature or change request: its spec, impact analysis, task list and verification. |

## Adding a feature

1. Copy `_TEMPLATE.md` to `features/YYYY-MM-DD-<short-name>.md`.
2. Fill in sections 1-5: summary, behaviour, data and the impact analysis.
3. Write the task list (section 6). Add a matching line to `TASKS.md` so the roadmap stays complete.
4. Build the tasks in order, ticking each box as it is finished.
5. Fill in section 7 (verification) and set the status to Done.

The full process, including when a small fix may skip the spec file, is in `CLAUDE.md` under "Feature workflow".
