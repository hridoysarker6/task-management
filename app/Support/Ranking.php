<?php

namespace App\Support;

/**
 * Ordering by "rank" numbers, the way boards do it: dropping a card between two others gives it a number
 * between theirs, so one move changes one row. When two neighbours get too close the caller renumbers the column.
 */
class Ranking
{
    /** The distance between neighbours after a renumber. */
    public const STEP = 1000.0;

    /** Below this gap there is no room left to insert (the column needs renumbering). */
    public const MIN_GAP = 0.000001;

    /**
     * The rank for something dropped between $above and $below (either may be null: top, bottom or an empty column).
     * Returns null when the neighbours are too close together.
     */
    public static function between(?float $above, ?float $below): ?float
    {
        return match (true) {
            $above === null && $below === null => self::STEP,
            $above === null => $below - self::STEP,       // dropped at the very top
            $below === null => $above + self::STEP,       // dropped at the very bottom
            ($below - $above) < self::MIN_GAP => null,    // no room
            default => ($above + $below) / 2,
        };
    }
}
