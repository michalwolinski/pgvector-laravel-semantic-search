<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Search\NaiveSemanticSearch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The same search, once inside the transaction RefreshDatabase opens around a test, once outside any. */
final class PuzzleTest extends TestCase
{
    #[Test]
    public function outside_a_transaction_set_local_does_nothing_and_team_8_gets_no_rows(): void
    {
        $rows = (new NaiveSemanticSearch)->forTeam(8, $this->vectors()['heldout'][0]);

        $this->assertCount(0, $rows);
    }
}
