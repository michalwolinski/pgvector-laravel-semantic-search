<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Search\NaiveSemanticSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PuzzleWithRefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** RefreshDatabase would migrate:fresh the 100,000 rows away; the schema is already there. */
    protected function refreshTestDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    #[Test]
    public function inside_the_test_transaction_set_local_works_and_team_8_gets_ten_rows(): void
    {
        $rows = (new NaiveSemanticSearch)->forTeam(8, $this->vectors()['heldout'][0]);

        $this->assertCount(10, $rows);
    }
}
