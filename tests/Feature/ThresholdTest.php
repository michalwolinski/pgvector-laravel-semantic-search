<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ThresholdTest extends TestCase
{
    #[Test]
    public function with_iterative_scan_a_threshold_nobody_passes_makes_the_index_visit_about20000_rows(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $query = DB::table('documents')->select('id')->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.9)->limit(10);

        $off = $this->analyze($query)['Plan'];
        $strict = DB::transaction(function () use ($query): array {
            DB::statement("set local hnsw.iterative_scan = 'strict_order'");

            return $this->analyze($query)['Plan'];
        });

        $this->assertSame(0, (int) $off['Actual Rows']);
        $this->assertSame(40, (int) $off['Plans'][0]['Rows Removed by Filter']);
        $this->assertSame(0, (int) $strict['Actual Rows']);
        $this->assertGreaterThan(19000, $strict['Plans'][0]['Rows Removed by Filter']);
    }

    #[Test]
    public function the_nearest_ten_in_a_subquery_with_the_threshold_outside_stops_after_ten_rows(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $nearest = DB::table('documents')->select('id')
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->orderBy('distance')
            ->limit(10);
        $query = DB::query()->fromSub($nearest, 'nearest')->select('id')->where('distance', '<=', 1 - 0.9)->orderBy('distance');

        $plan = DB::transaction(function () use ($query): array {
            DB::statement("set local hnsw.iterative_scan = 'strict_order'");

            return $this->analyze($query);
        });

        $this->assertStringContainsString('documents_embedding_vectorindex', json_encode($plan));
        $this->assertSame(0, (int) $plan['Plan']['Actual Rows']);
        $this->assertSame(10, (int) ($plan['Plan']['Plans'][0]['Actual Rows'] ?? 0));
    }

    #[Test]
    public function the_default_threshold_of06_passes_the_right_article_for38_of40_questions_and_about50_others(): void
    {
        $runs = json_decode((string) file_get_contents(base_path('runs/thresholds.json')), true, flags: JSON_THROW_ON_ERROR)['bge'];

        $this->assertSame(38, count(array_filter($runs, fn (array $r): bool => $r['target'] >= 0.6)));
        $this->assertSame(25, count(array_filter($runs, fn (array $r): bool => (int) $r['rank'] === 1)));
    }
}
