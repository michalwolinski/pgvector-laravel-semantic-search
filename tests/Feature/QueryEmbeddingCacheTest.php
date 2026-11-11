<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QueryEmbeddingCacheTest extends TestCase
{
    #[Test]
    public function a_text_query_is_embedded_once_and_cached_for30_days_although_config_says_cache_false(): void
    {
        $this->assertFalse(config('ai.caching.embeddings.cache'));
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'model' => 'bge-small-en-v1.5',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 384, 0.05)]],
            'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6],
        ])]);

        DB::table('documents')->whereVectorSimilarTo('embedding', 'black and white bear')->limit(10)->get();
        $this->travel(29)->days();
        DB::table('documents')->whereVectorSimilarTo('embedding', 'black and white bear')->limit(10)->get();
        Http::assertSentCount(1);

        $this->travel(2)->days();
        DB::table('documents')->whereVectorSimilarTo('embedding', 'black and white bear')->limit(10)->get();
        Http::assertSentCount(2);

        Cache::flush();
    }
}
