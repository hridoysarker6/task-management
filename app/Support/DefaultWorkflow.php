<?php

namespace App\Support;

/** The default statuses every new project starts with (WORKFLOW.md section 6.1). Each project can change them. */
class DefaultWorkflow
{
    public const CATEGORIES = ['triage', 'todo', 'in_progress', 'done', 'cancelled'];

    /** @return list<array{name:string,category:string,color:string,is_initial:bool,show_on_board:bool}> */
    public static function statuses(): array
    {
        return [
            ['name' => 'Triage', 'category' => 'triage', 'color' => '#a855f7', 'is_initial' => false, 'show_on_board' => false],
            ['name' => 'Backlog', 'category' => 'todo', 'color' => '#6b7280', 'is_initial' => true, 'show_on_board' => false],
            ['name' => 'To Do', 'category' => 'todo', 'color' => '#3b82f6', 'is_initial' => false, 'show_on_board' => true],
            ['name' => 'In Progress', 'category' => 'in_progress', 'color' => '#f59e0b', 'is_initial' => false, 'show_on_board' => true],
            ['name' => 'In Review', 'category' => 'in_progress', 'color' => '#8b5cf6', 'is_initial' => false, 'show_on_board' => true],
            ['name' => 'Blocked', 'category' => 'in_progress', 'color' => '#ef4444', 'is_initial' => false, 'show_on_board' => true],
            ['name' => 'Done', 'category' => 'done', 'color' => '#22c55e', 'is_initial' => false, 'show_on_board' => true],
            ['name' => 'Cancelled', 'category' => 'cancelled', 'color' => '#9ca3af', 'is_initial' => false, 'show_on_board' => false],
            ['name' => 'Rejected', 'category' => 'cancelled', 'color' => '#dc2626', 'is_initial' => false, 'show_on_board' => false],
        ];
    }
}
