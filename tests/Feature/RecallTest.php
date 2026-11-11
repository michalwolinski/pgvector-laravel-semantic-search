<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Search\Probe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecallTest extends TestCase
{
    private function recall(int $efSearch, int $queries): float
    {
        $found = 0;

        foreach (array_slice($this->vectors()['heldout'], 0, $queries) as $vector) {
            $query = DB::table('documents')->select('id')->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)->limit(10);
            $found += count(array_intersect(Probe::run($query, ['hnsw.ef_search' => $efSearch])['ids'], Probe::exact($query)));
        }

        return $found / (10 * $queries);
    }

    #[Test]
    public function the_default_ef_search_of40_finds1899_of_the2000_true_neighbours(): void
    {
        $this->assertSame(0.9495, $this->recall(40, 200));
    }

    #[Test]
    public function a_larger_candidate_list_buys_recall(): void
    {
        $this->assertSame(0.742, $this->recall(10, 50));
        $this->assertSame(0.896, $this->recall(40, 50));
        $this->assertSame(0.982, $this->recall(320, 50));
    }
}
