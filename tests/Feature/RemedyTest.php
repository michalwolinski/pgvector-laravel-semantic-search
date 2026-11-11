<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Search\SemanticSearch;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Checks of the article's advice itself, not of the traps. */
final class RemedyTest extends TestCase
{
    #[Test]
    public function simple_paginate_offers_a_next_page_only_while_the_index_has_rows(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $page = fn (int $number) => Document::query()->select('id')->whereVectorSimilarTo('embedding', $vector)->simplePaginate(10, page: $number);

        $this->assertTrue($page(3)->hasMorePages());
        $this->assertCount(10, $page(4));
        $this->assertFalse($page(4)->hasMorePages());
    }

    #[Test]
    public function inside_an_outer_transaction_the_setting_lasts_until_that_transaction_ends(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $threshold = DB::table('documents')->select('id')->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.9)->limit(10);

        DB::beginTransaction();

        try {
            (new SemanticSearch)->forTeam(1, $vector);

            $this->assertSame('relaxed_order', DB::scalar('show hnsw.iterative_scan'));
            // so Laravel's threshold query that follows in the same transaction walks the index to the cap
            $this->assertGreaterThan(19000, (int) $this->analyze($threshold)['Plan']['Plans'][0]['Rows Removed by Filter']);
        } finally {
            DB::rollBack();
        }

        $this->assertSame('off', DB::scalar('show hnsw.iterative_scan'));
    }

    #[Test]
    public function with_relaxed_order_a_plain_outer_order_by_lets_unsorted_rows_through_and_plus_zero_sorts_them(): void
    {
        $vectors = array_slice($this->vectors()['heldout'], 0, 20);
        $outOfOrder = function (string $order) use ($vectors): int {
            $count = 0;

            foreach ($vectors as $vector) {
                $nearest = DB::table('documents')->select('id')
                    ->selectVectorDistance('embedding', $vector, as: 'distance')
                    ->where('team_id', 2)
                    ->orderBy('distance')
                    ->limit(10);
                $distances = DB::transaction(function () use ($nearest, $order): array {
                    DB::statement("set local hnsw.iterative_scan = 'relaxed_order'");

                    return DB::query()->fromSub($nearest, 'nearest')->select('distance')->orderByRaw($order)->pluck('distance')->all();
                });
                $sorted = $distances;
                sort($sorted);
                $count += $distances === $sorted ? 0 : 1;
            }

            return $count;
        };

        $this->assertGreaterThan(0, $outOfOrder('distance'));
        $this->assertSame(0, $outOfOrder('distance + 0'));
    }
}
