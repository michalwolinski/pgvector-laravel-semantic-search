<?php

declare(strict_types=1);

// Runs ANALYZE on documents five times and records, after each, which teams the planner sends to the HNSW index
// for a team-filtered search. ANALYZE samples rows, so the estimated team sizes, and the cut-off, move.
// Writes runs/analyze-flips.json.

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$vector = json_decode((string) file_get_contents(__DIR__.'/../data/query-vectors.json'), true, flags: JSON_THROW_ON_ERROR)['heldout'][0];
$runs = [];

foreach (range(1, 5) as $run) {
    DB::statement('analyze documents');
    $hnsw = [];

    foreach (range(1, 200) as $team) {
        $query = DB::table('documents')->select('id')->where('team_id', $team)
            ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)->limit(10);
        $plan = implode("\n", array_map(fn (object $row): string => $row->{'QUERY PLAN'}, DB::select('explain '.$query->toSql(), $query->getBindings())));

        if (str_contains($plan, 'documents_embedding_vectorindex')) {
            $hnsw[] = $team;
        }
    }

    $runs[] = $hnsw;
    printf("ANALYZE %d: %d teams on HNSW: %s\n", $run, count($hnsw), implode(',', $hnsw));
}

$sizes = DB::table('documents')->selectRaw('team_id, count(*) as size')->whereBetween('team_id', [9, 16])->groupBy('team_id')->orderBy('team_id')->pluck('size', 'team_id');
file_put_contents(__DIR__.'/../runs/analyze-flips.json', json_encode(['runs' => $runs, 'sizes_9_to_16' => $sizes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
