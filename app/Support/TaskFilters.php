<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the board, the list and saved filters: cleaned up from user input, then applied to a task query.
 * Anything not on the whitelist is dropped, so a saved filter or a URL can never smuggle in other conditions.
 */
class TaskFilters
{
    public const KEYS = ['q', 'status', 'assignee', 'reporter', 'type', 'priority', 'label', 'sprint', 'mine', 'overdue', 'created_from', 'created_to', 'due_from', 'due_to'];

    /** Sort keys allowed in the list view. */
    public const SORTS = ['key', 'title', 'type', 'priority', 'status', 'assignee', 'reporter', 'due_date', 'created_at', 'updated_at', 'story_points'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only known keys, with the right types and no empty values
     */
    public static function clean(array $input): array
    {
        $out = [];

        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $out['q'] = mb_substr($q, 0, 100);
        }

        foreach (['status', 'reporter', 'label'] as $key) {
            if ($ids = self::ints($input[$key] ?? null)) {
                $out[$key] = $ids;
            }
        }

        // Assignee and sprint also accept "none" (unassigned / not in a sprint).
        foreach (['assignee', 'sprint'] as $key) {
            $values = self::list($input[$key] ?? null);
            $ids = self::ints($values);
            $none = in_array('none', $values, true);
            if ($ids || $none) {
                $out[$key] = [...$ids, ...($none ? ['none'] : [])];
            }
        }

        if ($types = array_values(array_intersect(self::list($input['type'] ?? null), Task::TYPES))) {
            $out['type'] = $types;
        }

        if ($priorities = array_values(array_unique(array_filter(self::ints($input['priority'] ?? null), fn ($p) => $p >= 1 && $p <= 5)))) {
            $out['priority'] = $priorities;
        }

        foreach (['mine', 'overdue'] as $flag) {
            if (filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $out[$flag] = true;
            }
        }

        foreach (['created_from', 'created_to', 'due_from', 'due_to'] as $date) {
            $value = (string) ($input[$date] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false) {
                $out[$date] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters  already cleaned
     * @return Builder<Task>
     */
    public static function apply(Builder $query, array $filters, Membership $me): Builder
    {
        $tasks = (new Task)->getTable();

        if (isset($filters['q'])) {
            $q = $filters['q'];
            if (preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d*)$/', $q, $m)) {
                $query->whereHas('project', fn ($p) => $p->where('key', strtoupper($m[1])))
                    ->when($m[2] !== '', fn ($x) => $x->where("{$tasks}.number", (int) $m[2]));
            } else {
                $query->where("{$tasks}.title", 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%');
            }
        }

        if (isset($filters['status'])) {
            $query->whereIn("{$tasks}.status_id", $filters['status']);
        }

        if (isset($filters['assignee'])) {
            self::idsOrNone($query, "{$tasks}.assignee_id", $filters['assignee']);
        }

        if (isset($filters['sprint'])) {
            self::idsOrNone($query, "{$tasks}.sprint_id", $filters['sprint']);
        }

        if (isset($filters['reporter'])) {
            $query->whereIn("{$tasks}.reporter_id", $filters['reporter']);
        }

        if (isset($filters['type'])) {
            $query->whereIn("{$tasks}.type", $filters['type']);
        }

        if (isset($filters['priority'])) {
            $query->whereIn("{$tasks}.priority", $filters['priority']);
        }

        if (isset($filters['label'])) {
            $query->whereHas('labels', fn ($l) => $l->whereIn('labels.id', $filters['label']));
        }

        if (! empty($filters['mine'])) {
            $query->where("{$tasks}.assignee_id", $me->id);
        }

        if (! empty($filters['overdue'])) {
            $query->whereNotNull("{$tasks}.due_date")->whereDate("{$tasks}.due_date", '<', today())
                ->whereHas('status', fn ($s) => $s->whereNotIn('category', ['done', 'cancelled']));
        }

        foreach (['created_from' => ['created_at', '>='], 'created_to' => ['created_at', '<='], 'due_from' => ['due_date', '>='], 'due_to' => ['due_date', '<=']] as $key => [$column, $op]) {
            if (isset($filters[$key])) {
                $query->whereDate("{$tasks}.{$column}", $op, $filters[$key]);
            }
        }

        return $query;
    }

    /** True when no filter is set. */
    public static function none(array $filters): bool
    {
        return $filters === [];
    }

    /**
     * Order a list query by a whitelisted column. Unknown keys fall back to the newest first.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function sort(Builder $query, string $sort, string $dir): Builder
    {
        $tasks = (new Task)->getTable();
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $person = fn (string $column) => "(select u.name from memberships m join users u on u.id = m.user_id where m.id = {$tasks}.{$column})";

        match ($sort) {
            'key' => $query->orderBy("{$tasks}.number", $dir),
            'title' => $query->orderBy("{$tasks}.title", $dir),
            'type' => $query->orderBy("{$tasks}.type", $dir),
            'priority' => $query->orderBy("{$tasks}.priority", $dir),
            'status' => $query->orderByRaw("(select position from project_statuses ps where ps.id = {$tasks}.status_id) {$dir}"),
            'assignee' => $query->orderByRaw("{$tasks}.assignee_id is null")->orderByRaw($person('assignee_id')." {$dir}"),
            'reporter' => $query->orderByRaw($person('reporter_id')." {$dir}"),
            // Tasks without a value always sink to the bottom, whichever way the column sorts.
            'due_date', 'story_points' => $query->orderByRaw("{$tasks}.{$sort} is null")->orderBy("{$tasks}.{$sort}", $dir),
            'created_at', 'updated_at' => $query->orderBy("{$tasks}.{$sort}", $dir),
            default => $query->orderByDesc("{$tasks}.id"),
        };

        return $query->orderBy("{$tasks}.id", $dir === 'asc' ? 'asc' : 'desc');
    }

    /** @param list<int|string> $values */
    private static function idsOrNone(Builder $query, string $column, array $values): void
    {
        $ids = array_values(array_filter($values, 'is_int'));
        $none = in_array('none', $values, true);

        $query->where(function (Builder $q) use ($column, $ids, $none) {
            if ($ids) {
                $q->whereIn($column, $ids);
            }
            if ($none) {
                $ids ? $q->orWhereNull($column) : $q->whereNull($column);
            }
        });
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map('strval', array_filter((array) $value, fn ($v) => is_scalar($v) && $v !== '')));
    }

    /** @return list<int> */
    private static function ints(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter(self::list($value), fn ($v) => ctype_digit($v) && (int) $v > 0))));
    }
}
