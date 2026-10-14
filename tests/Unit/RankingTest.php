<?php

namespace Tests\Unit;

use App\Support\Ranking;
use PHPUnit\Framework\TestCase;

class RankingTest extends TestCase
{
    public function test_an_empty_column_starts_at_one_step(): void
    {
        $this->assertSame(Ranking::STEP, Ranking::between(null, null));
    }

    public function test_dropping_at_the_top_goes_one_step_above_the_first_card(): void
    {
        $this->assertSame(0.0, Ranking::between(null, 1000.0));
    }

    public function test_dropping_at_the_bottom_goes_one_step_below_the_last_card(): void
    {
        $this->assertSame(3000.0, Ranking::between(2000.0, null));
    }

    public function test_dropping_between_two_cards_takes_the_middle(): void
    {
        $this->assertSame(1500.0, Ranking::between(1000.0, 2000.0));
    }

    public function test_neighbours_that_are_too_close_leave_no_room(): void
    {
        $this->assertNull(Ranking::between(1.0, 1.0 + Ranking::MIN_GAP / 2));
    }
}
