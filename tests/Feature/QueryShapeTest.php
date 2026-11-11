<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QueryShapeTest extends TestCase
{
    #[Test]
    public function where_vector_similar_to_filters_and_orders_by_cosine_distance_with_the_vector_bound_twice(): void
    {
        $vector = $this->vectors()['questions']['bge'][0];
        $query = DB::table('documents')->whereVectorSimilarTo('embedding', $vector)->limit(10);

        $this->assertSame(
            'select * from "documents" where ("embedding" <=> ?) <= ? order by ("embedding" <=> ?) asc limit 10',
            $query->toSql(),
        );

        [$first, $maxDistance, $second] = $query->getBindings();
        $this->assertSame($first, $second);
        $this->assertEqualsWithDelta(0.4, $maxDistance, 1e-12);
        $this->assertGreaterThan(7000, strlen($first) + strlen($second));
    }

    #[Test]
    public function with_a_limit_postgres_walks_the_hnsw_index(): void
    {
        $vector = $this->vectors()['questions']['bge'][0];

        $plan = $this->plan(Document::query()->whereVectorSimilarTo('embedding', $vector)->limit(10)->toBase());

        $this->assertStringContainsString('Index Scan using documents_embedding_vectorindex', $plan);
    }

    #[Test]
    public function without_a_limit_the_index_is_not_used_and_every_row_is_compared(): void
    {
        $vector = $this->vectors()['questions']['bge'][0];

        $plan = $this->plan(Document::query()->whereVectorSimilarTo('embedding', $vector)->toBase());

        $this->assertStringNotContainsString('documents_embedding_vectorindex', $plan);
        $this->assertStringContainsString('Seq Scan on documents', $plan);
        $this->assertStringContainsString('Sort', $plan);
    }

    #[Test]
    public function paginate_counts_every_match_with_a_full_scan_before_it_fetches_a_page(): void
    {
        $vector = $this->vectors()['questions']['bge'][0];
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $page = Document::query()->whereVectorSimilarTo('embedding', $vector)->paginate(10);

        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('select count(*) as "aggregate" from "documents" where ("embedding" <=> ?) <= ?', $queries[0]);
        $countPlan = $this->plan(Document::query()->whereVectorSimilarTo('embedding', $vector, order: false)->toBase()->selectRaw('count(*)'));
        $this->assertStringContainsString('Seq Scan on documents', $countPlan);
        $this->assertGreaterThan(10, $page->total());
    }
}
