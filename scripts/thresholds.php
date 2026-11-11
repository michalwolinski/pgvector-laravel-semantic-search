<?php

declare(strict_types=1);

// For each hand-written question: similarity of the article it is about, its rank, and how many of the
// 10,246 sampled articles pass whereVectorSimilarTo()'s default minSimilarity of 0.6. Writes runs/thresholds.json.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$questions = json_decode((string) file_get_contents(__DIR__.'/../data/queries.json'), true, flags: JSON_THROW_ON_ERROR);
$vectors = json_decode((string) file_get_contents(__DIR__.'/../data/query-vectors.json'), true, flags: JSON_THROW_ON_ERROR)['questions'];
$columns = ['bge' => 'd.embedding', 'nomic' => 'n.embedding', 'nomic_unprefixed' => 'n.embedding'];
$out = [];

foreach ($columns as $model => $column) {
    foreach ($questions as $i => $question) {
        $vector = json_encode($vectors[$model][$i]);
        $row = DB::selectOne("
            with scored as (
                select d.title, 1 - ({$column} <=> ?) as similarity
                from documents d join nomic_documents n on n.document_id = d.id
            )
            select
                (select similarity from scored where title = ?) as target,
                (select count(*) from scored where similarity > (select similarity from scored where title = ?)) + 1 as rank,
                (select count(*) from scored where similarity >= 0.6) as above_default,
                (select max(similarity) from scored where title <> ?) as best_other,
                (select percentile_cont(0.5) within group (order by similarity) from scored) as median
        ", [$vector, $question['title'], $question['title'], $question['title']]);
        $out[$model][] = ['q' => $question['q'], 'title' => $question['title']] + (array) $row;
    }
}

file_put_contents(__DIR__.'/../runs/thresholds.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

$median = function (array $values): float {
    sort($values);
    $n = count($values);

    return 0 === $n % 2 ? ($values[$n / 2 - 1] + $values[$n / 2]) / 2 : $values[intdiv($n, 2)];
};

foreach ($out as $model => $rows) {
    printf("%-17s hit@1 %2d/40  hit@10 %2d/40  target>=0.6 %2d/40  median target %.3f  min target %.3f  median rows>=0.6 %.1f  median similarity %.3f\n",
        $model,
        count(array_filter($rows, fn (array $r): bool => 1 === (int) $r['rank'])),
        count(array_filter($rows, fn (array $r): bool => (int) $r['rank'] <= 10)),
        count(array_filter($rows, fn (array $r): bool => $r['target'] >= 0.6)),
        $median(array_column($rows, 'target')),
        min(array_column($rows, 'target')),
        $median(array_map('intval', array_column($rows, 'above_default'))),
        $median(array_column($rows, 'median')),
    );
}
