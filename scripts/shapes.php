<?php

declare(strict_types=1);

// Server-side execution time (EXPLAIN ANALYZE) of the query shapes a Laravel app writes, over the first
// 50 held-out paragraphs. Client timings through Docker Desktop's port forwarder add ~14 ms to any reply
// over a few KB, so they are not used here. Writes runs/shapes.json.

use App\Models\Document;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$vectors = array_slice(json_decode((string) file_get_contents(__DIR__.'/../data/query-vectors.json'), true, flags: JSON_THROW_ON_ERROR)['heldout'], 0, 50);

/** @return array{ms: float, rows: int, index: bool} */
function analyze(Builder $query): array
{
    $base = $query->toBase();
    $plan = DB::selectOne('explain (analyze, format json) '.$base->toSql(), $base->getBindings());
    $json = json_decode($plan->{'QUERY PLAN'}, true)[0];
    $text = json_encode($json);

    return ['ms' => $json['Execution Time'], 'rows' => $json['Plan']['Actual Rows'], 'index' => str_contains($text, 'documents_embedding_vectorindex')];
}

$shapes = [
    'limit_10' => fn (array $v) => analyze(Document::query()->whereVectorSimilarTo('embedding', $v)->limit(10)),
    'no_limit' => fn (array $v) => analyze(Document::query()->whereVectorSimilarTo('embedding', $v)),
    // what paginate() runs before it fetches a page
    'paginate_count' => fn (array $v) => analyze(Document::query()->whereVectorSimilarTo('embedding', $v, order: false)->selectRaw('count(*) as aggregate')),
];
$out = [];

foreach ($shapes as $name => $run) {
    $runs = array_map($run, $vectors);
    $ms = array_column($runs, 'ms');
    $rows = array_column($runs, 'rows');
    sort($ms);
    sort($rows);
    $out[$name] = [
        'median_ms' => ($ms[24] + $ms[25]) / 2,
        'median_rows' => ($rows[24] + $rows[25]) / 2,
        'max_rows' => end($rows),
        'used_hnsw' => count(array_filter(array_column($runs, 'index'))),
    ];
    printf("%-15s %7.2f ms  rows median %6.1f max %5d  hnsw %d/50\n", $name, $out[$name]['median_ms'], $out[$name]['median_rows'], $out[$name]['max_rows'], $out[$name]['used_hnsw']);
}

$out['avg_embedding_text_bytes'] = (float) DB::scalar('select avg(length(embedding::text)) from documents where id <= 1000');
printf("embedding as text: %.0f bytes per row\n", $out['avg_embedding_text_bytes']);

file_put_contents(__DIR__.'/../runs/shapes.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
