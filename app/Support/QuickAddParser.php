<?php

namespace App\Support;

/**
 * Reads the line typed into a "quick add" box. Besides the title it understands three shortcuts, anywhere in the line:
 *
 *   @name   assign to the person whose name starts with it (only when exactly one person matches)
 *   !3      priority 1-5, or !lowest !low !medium !high !highest
 *   #label  add the label whose name starts with it (only when exactly one label matches)
 * A shortcut that matches nothing is kept in the title as typed.
 */
class QuickAddParser
{
    private const PRIORITY_WORDS = ['lowest' => 1, 'low' => 2, 'medium' => 3, 'normal' => 3, 'high' => 4, 'highest' => 5, 'urgent' => 5];

    /**
     * @param  list<array{id:int,name:string}>  $people  who the line may assign to
     * @param  list<array{id:int,name:string}>  $labels  the project's labels
     * @return array{title:string,assignee_id:?int,priority:?int,labels:list<int>}
     */
    public static function parse(string $line, array $people, array $labels): array
    {
        $assignee = null;
        $priority = null;
        $labelIds = [];

        $kept = preg_replace_callback('/(?<!\S)([@!#])([\p{L}\p{N}_.-]+)/u', function (array $m) use ($people, $labels, &$assignee, &$priority, &$labelIds) {
            [$whole, $mark, $word] = $m;

            if ($mark === '@' && $assignee === null && ($id = self::unique($people, $word))) {
                $assignee = $id;

                return '';
            }

            if ($mark === '!' && $priority === null) {
                $level = ctype_digit($word) ? (int) $word : (self::PRIORITY_WORDS[mb_strtolower($word)] ?? 0);
                if ($level >= 1 && $level <= 5) {
                    $priority = $level;

                    return '';
                }
            }

            if ($mark === '#' && ($id = self::unique($labels, $word))) {
                $labelIds[] = $id;

                return '';
            }

            return $whole;
        }, $line) ?? $line;

        $title = trim((string) preg_replace('/\s{2,}/', ' ', $kept));

        return [
            'title' => $title !== '' ? $title : trim($line),
            'assignee_id' => $assignee,
            'priority' => $priority,
            'labels' => array_values(array_unique($labelIds)),
        ];
    }

    /**
     * The id of the one entry whose name starts with the word (spaces ignored, case ignored), or null when none or several do.
     *
     * @param  list<array{id:int,name:string}>  $items
     */
    private static function unique(array $items, string $word): ?int
    {
        $needle = mb_strtolower($word);
        $squash = fn (string $name) => str_replace(' ', '', mb_strtolower($name));

        $exact = array_values(array_filter($items, fn ($i) => $squash($i['name']) === $needle));
        if (count($exact) === 1) {
            return $exact[0]['id'];
        }

        $starts = array_values(array_filter($items, fn ($i) => str_starts_with($squash($i['name']), $needle) || str_starts_with(mb_strtolower($i['name']), $needle)));

        return count($starts) === 1 ? $starts[0]['id'] : null;
    }
}
