<?php

namespace Tests\Unit;

use App\Support\TaskFilters;
use PHPUnit\Framework\TestCase;

class TaskFiltersCleanTest extends TestCase
{
    public function test_unknown_keys_and_empty_values_are_dropped(): void
    {
        $out = TaskFilters::clean(['q' => '  ', 'status' => [], 'evil' => '1; drop table tasks', 'type' => ['bug', 'nonsense']]);

        $this->assertSame(['type' => ['bug']], $out);
    }

    public function test_ids_are_cast_to_integers_and_priorities_limited_to_one_to_five(): void
    {
        $out = TaskFilters::clean(['status' => ['3', 'x', '4'], 'priority' => ['0', '2', '9']]);

        $this->assertSame([3, 4], $out['status']);
        $this->assertSame([2], array_values($out['priority']));
    }

    public function test_assignee_accepts_none_alongside_ids(): void
    {
        $this->assertSame([5, 'none'], TaskFilters::clean(['assignee' => ['5', 'none']])['assignee']);
    }

    public function test_dates_must_be_real_iso_dates_and_flags_are_booleans(): void
    {
        $out = TaskFilters::clean(['due_from' => '2026-02-30x', 'due_to' => '2026-03-01', 'mine' => '1', 'overdue' => '0']);

        $this->assertEquals(['mine' => true, 'due_to' => '2026-03-01'], $out);
    }

    public function test_search_text_is_trimmed_and_capped(): void
    {
        $this->assertSame(100, mb_strlen(TaskFilters::clean(['q' => str_repeat('a', 300)])['q']));
    }
}
