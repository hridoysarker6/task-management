<?php

namespace App\Support;

use App\Models\Task;

/** Turns tasks into the plain arrays the Vue pages use. */
class TaskPresenter
{
    /**
     * A task as one line in a list. Load `project`, `status` and `assignee.user` first.
     *
     * @return array<string, mixed>
     */
    public static function row(Task $t, bool $showEffort = true): array
    {
        return [
            'id' => $t->id,
            'key' => $t->key_label,
            'title' => $t->title,
            'type' => $t->type,
            'priority' => $t->priority,
            'status' => $t->status ? ['id' => $t->status->id, 'name' => $t->status->name, 'category' => $t->status->category, 'color' => $t->status->color] : null,
            'assignee' => $t->assignee?->user ? ['id' => $t->assignee->id, 'name' => $t->assignee->user->name] : null,
            'project' => ['id' => $t->project->id, 'key' => $t->project->key, 'name' => $t->project->name],
            'due_date' => $t->due_date?->toDateString(),
            'overdue' => $t->due_date !== null && $t->due_date->isPast() && ! $t->due_date->isToday() && $t->status?->category !== 'done' && $t->status?->category !== 'cancelled',
            'story_points' => $showEffort ? ($t->story_points !== null ? (float) $t->story_points : null) : null,
        ];
    }

    /**
     * A task as a card on the board or a line in the backlog: the list row plus labels, the epic it belongs to,
     * and its position. Load `project`, `status`, `assignee.user`, `labels` and `parent.parent` first.
     *
     * @return array<string, mixed>
     */
    public static function card(Task $t, bool $showEffort = true): array
    {
        $epic = $t->parent?->type === 'epic' ? $t->parent : ($t->parent?->parent?->type === 'epic' ? $t->parent->parent : null);

        return self::row($t, $showEffort) + [
            'labels' => $t->labels->map->only(['id', 'name', 'color'])->values(),
            'epic' => $epic ? ['id' => $epic->id, 'key' => $t->project->key.'-'.$epic->number, 'title' => $epic->title] : null,
            'assignee_key' => $t->assignee_id ?? 'none',
            'epic_key' => $epic ? $epic->id : 'none',
            'position' => (float) $t->board_position,
            'rank' => (float) $t->backlog_rank,
        ];
    }

    /** "1h 30m" for a number of minutes. */
    public static function minutes(?int $m): ?string
    {
        if ($m === null) {
            return null;
        }

        $h = intdiv($m, 60);
        $r = $m % 60;

        return trim(($h ? "{$h}h " : '').($r || ! $h ? "{$r}m" : ''));
    }
}
