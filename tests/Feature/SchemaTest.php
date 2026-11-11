<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SchemaTest extends TestCase
{
    #[Test]
    public function index_on_a_vector_column_becomes_hnsw_with_cosine_ops(): void
    {
        $definition = DB::scalar("select indexdef from pg_indexes where indexname = 'documents_embedding_vectorindex'");

        $this->assertSame(
            'CREATE INDEX documents_embedding_vectorindex ON public.documents USING hnsw (embedding vector_cosine_ops)',
            $definition,
        );
    }

    #[Test]
    public function a_vector_of_the_wrong_length_is_rejected_on_insert(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('expected 384 dimensions, not 1536');

        DB::transaction(fn () => DB::table('documents')->insert([
            'wiki_id' => 0,
            'team_id' => 1,
            'title' => 'text-embedding-3-small has 1536 dimensions',
            'body' => '',
            'embedding' => json_encode(array_fill(0, 1536, 0.01)),
        ]));
    }

    #[Test]
    public function hnsw_refuses_a_vector_column_with_more_than2000_dimensions(): void
    {
        try {
            DB::transaction(function (): void {
                Schema::create('large_embeddings', function (Blueprint $table) {
                    $table->id();
                    // text-embedding-3-large
                    $table->vector('embedding', dimensions: 3072)->index();
                });
            });
            $this->fail('the index was created');
        } catch (QueryException $e) {
            $this->assertStringContainsString('column cannot have more than 2000 dimensions for hnsw index', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('large_embeddings'));
    }

    #[Test]
    public function a_halfvec_expression_index_takes3072_dimensions_and_laravel_can_query_through_it(): void
    {
        DB::beginTransaction();

        try {
            Schema::create('large_embeddings', function (Blueprint $table) {
                $table->id();
                $table->vector('embedding', dimensions: 3072);
            });
            DB::statement('create index large_embeddings_halfvec on large_embeddings using hnsw ((embedding::halfvec(3072)) halfvec_cosine_ops)');
            DB::statement('set local enable_seqscan = off');

            $vector = array_fill(0, 3072, 0.01);
            $plain = DB::table('large_embeddings')->whereVectorSimilarTo('embedding', $vector)->limit(10);
            $cast = DB::table('large_embeddings')->whereVectorSimilarTo(DB::raw('embedding::halfvec(3072)'), $vector)->limit(10);

            $this->assertStringNotContainsString('large_embeddings_halfvec', $this->plan($plain));
            $this->assertStringContainsString('Index Scan using large_embeddings_halfvec', $this->plan($cast));
        } finally {
            DB::rollBack();
        }
    }
}
