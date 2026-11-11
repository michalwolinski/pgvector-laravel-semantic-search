<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TenantFilterTest extends TestCase
{
    /** @param list<float> $vector */
    private function search(int $team, array $vector): Builder
    {
        return DB::table('documents')->select('id')
            ->where('team_id', $team)
            ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)
            ->limit(10);
    }

    #[Test]
    public function a_small_team_gets_an_exact_answer_through_its_btree_index(): void
    {
        $vector = $this->vectors()['heldout'][0];

        $plan = $this->plan($this->search(150, $vector));

        $this->assertStringContainsString('documents_team_id_index', $plan);
        $this->assertStringNotContainsString('documents_embedding_vectorindex', $plan);
        $this->assertCount(10, $this->search(150, $vector)->get());
    }

    #[Test]
    public function a_team_with_two_percent_of_the_rows_gets_hnsw_and_almost_nothing_back(): void
    {
        $vectors = array_slice($this->vectors()['heldout'], 0, 20);
        $this->assertSame(2125, DB::table('documents')->where('team_id', 8)->count());
        $this->assertStringContainsString('documents_embedding_vectorindex', $this->plan($this->search(8, $vectors[0])));

        $returned = array_sum(array_map(fn (array $v): int => $this->search(8, $v)->get()->count(), $vectors));

        // 20 searches, 200 rows asked for
        $this->assertSame(14, $returned);
    }

    #[Test]
    public function iterative_scan_returns_ten_rows_for_the_same_team(): void
    {
        $vectors = array_slice($this->vectors()['heldout'], 0, 20);

        $returned = DB::transaction(function () use ($vectors): int {
            DB::statement("set local hnsw.iterative_scan = 'strict_order'");

            return array_sum(array_map(fn (array $v): int => $this->search(8, $v)->get()->count(), $vectors));
        });

        $this->assertSame(200, $returned);
    }
}
