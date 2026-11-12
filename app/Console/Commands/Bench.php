<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Search\Probe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('wiki:bench {part : recall|teams|threshold|remedy|layouts} {--queries=200}')]
#[Description('Measures recall, filtered search and similarity thresholds against the imported articles; writes runs/<part>.json')]
final class Bench extends Command
{
    public function handle(): int
    {
        $vectors = json_decode((string) file_get_contents(base_path('data/query-vectors.json')), true, flags: JSON_THROW_ON_ERROR);
        $queries = array_slice($vectors['heldout'], 0, (int) $this->option('queries'));
        $part = (string) $this->argument('part');

        $result = match ($part) {
            'recall' => $this->recall($queries),
            'teams' => $this->teams($queries),
            'threshold' => $this->threshold($queries),
            'remedy' => $this->remedy($queries),
            'layouts' => $this->layouts($queries),
        };

        $result['meta'] = [
            'queries' => count($queries),
            'pgvector' => DB::scalar("select extversion from pg_extension where extname = 'vector'"),
            'postgres' => DB::scalar('show server_version'),
            'rows' => DB::table('documents')->count(),
            'at' => now()->toIso8601String(),
        ];

        file_put_contents(base_path("runs/{$part}.json"), json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->info("runs/{$part}.json");

        return self::SUCCESS;
    }

    /** The query an app writes: ten nearest articles, nothing else. */
    private function nearest(array $vector): Builder
    {
        return DB::table('documents')->select('id')
            ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)
            ->limit(10);
    }

    /** @param list<list<float>> $queries */
    private function recall(array $queries): array
    {
        $truth = array_map(fn (array $v): array => Probe::exact($this->nearest($v)), $queries);
        $rows = [];

        foreach ([10, 20, 40, 80, 160, 320] as $ef) {
            $found = 0;
            $times = [];

            foreach ($queries as $i => $vector) {
                $run = Probe::run($this->nearest($vector), ['hnsw.ef_search' => $ef]);
                $found += count(array_intersect($run['ids'], $truth[$i]));
                $times[] = $run['ms'];
            }

            $rows[] = ['ef_search' => $ef, 'recall_at_10' => $found / (10 * count($queries)), 'median_ms' => $this->median($times)];
            $this->line(json_encode(end($rows)));
        }

        $times = [];
        foreach (array_slice($queries, 0, 50) as $vector) {
            $times[] = Probe::run($this->nearest($vector), ['enable_indexscan' => 'off'])['ms'];
        }

        return ['by_ef_search' => $rows, 'exact_median_ms' => $this->median($times)];
    }

    /** @param list<list<float>> $queries */
    private function teams(array $queries): array
    {
        $sizes = DB::table('documents')->select('team_id', DB::raw('count(*) as size'))
            ->groupBy('team_id')->orderBy('team_id')->pluck('size', 'team_id');
        $teams = [];

        foreach ($sizes as $team => $size) {
            $row = ['team_id' => (int) $team, 'size' => (int) $size];

            foreach (['off' => 'off', 'strict' => 'strict_order'] as $label => $mode) {
                $returned = 0;
                $correct = 0;
                $times = [];
                $plans = [];

                foreach (array_slice($queries, 0, 20) as $vector) {
                    $query = $this->nearest($vector)->where('team_id', $team);
                    $truth = Probe::exact($query);
                    $run = Probe::run($query, ['hnsw.iterative_scan' => $mode]);
                    $returned += count($run['ids']);
                    $correct += count(array_intersect($run['ids'], $truth));
                    $times[] = $run['ms'];
                    $plans[str_contains($run['plan'], 'documents_embedding_vectorindex') ? 'hnsw' : 'other'] = true;
                }

                $row[$label] = [
                    'avg_rows' => $returned / 20,
                    'recall' => $correct / (20 * min(10, (int) $size)),
                    'median_ms' => $this->median($times),
                    'plans' => array_keys($plans),
                ];
            }

            $teams[] = $row;
            $this->line(json_encode($row));
        }

        return ['teams' => $teams];
    }

    /** @param list<list<float>> $queries */
    private function threshold(array $queries): array
    {
        $rows = [];

        foreach ([0.5, 0.6, 0.7, 0.8, 0.9] as $min) {
            foreach (['off', 'strict_order'] as $mode) {
                $laravel = [];
                $sub = [];
                $counts = [];

                foreach (array_slice($queries, 0, 50) as $vector) {
                    $run = Probe::run(
                        DB::table('documents')->select('id')->whereVectorSimilarTo('embedding', $vector, minSimilarity: $min)->limit(10),
                        ['hnsw.iterative_scan' => $mode],
                    );
                    $laravel[] = $run['ms'];
                    $counts[] = count($run['ids']);

                    $sub[] = Probe::run($this->nearestThenFilter($vector, $min), ['hnsw.iterative_scan' => $mode])['ms'];
                }

                $rows[] = [
                    'min_similarity' => $min,
                    'iterative_scan' => $mode,
                    'avg_rows' => array_sum($counts) / count($counts),
                    'laravel_median_ms' => $this->median($laravel),
                    'subquery_median_ms' => $this->median($sub),
                ];
                $this->line(json_encode(end($rows)));
            }
        }

        return ['by_threshold' => $rows];
    }

    /**
     * The subquery form for teams 1 to 11 (the ones on HNSW), with strict and relaxed order, and with the outer
     * sort written as "order by distance" or "order by distance + 0".
     *
     * @param  list<list<float>>  $queries
     */
    private function remedy(array $queries): array
    {
        $rows = [];

        foreach (['strict_order' => 'distance', 'relaxed_order' => 'distance', 'relaxed_order ' => 'distance + 0'] as $mode => $order) {
            $returned = 0;
            $correct = 0;
            $unsorted = 0;
            $times = [];

            foreach (range(1, 11) as $team) {
                foreach (array_slice($queries, 0, 20) as $vector) {
                    $truth = Probe::exact($this->nearest($vector)->where('team_id', $team));
                    $nearest = DB::table('documents')->select('id')
                        ->selectVectorDistance('embedding', $vector, as: 'distance')
                        ->where('team_id', $team)
                        ->orderBy('distance')
                        ->limit(10);
                    $query = DB::query()->fromSub($nearest, 'nearest')->select('id', 'distance')->orderByRaw($order);

                    $result = DB::transaction(function () use ($mode, $query, &$times) {
                        DB::statement("set local hnsw.iterative_scan = '".trim($mode)."'");
                        $started = hrtime(true);
                        $result = $query->get();
                        $times[] = (hrtime(true) - $started) / 1e6;

                        return $result;
                    });

                    $distances = $result->pluck('distance')->map(fn (mixed $d): float => (float) $d)->all();
                    $sorted = $distances;
                    sort($sorted);
                    $unsorted += $distances === $sorted ? 0 : 1;
                    $returned += $result->count();
                    $correct += count(array_intersect($result->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), $truth));
                }
            }

            $rows[] = [
                'iterative_scan' => trim($mode),
                'outer_order' => $order,
                'rows' => $returned,
                'recall' => $correct / 2200,
                'searches_out_of_order' => $unsorted,
                'median_ms' => $this->median($times),
            ];
            $this->line(json_encode(end($rows)));
        }

        return ['teams_1_to_11' => $rows];
    }

    /**
     * The same team searches on three layouts: the shared table, the shared table plus a partial HNSW index for
     * team 8, and documents_by_team (scripts/partitions.sql), where teams 1 to 11 have a partition each.
     *
     * @param  list<list<float>>  $queries
     */
    private function layouts(array $queries): array
    {
        $queries = array_slice($queries, 0, 20);
        $measure = function (string $table, int $team, array $settings = []) use ($queries): array {
            $returned = 0;
            $correct = 0;
            $times = [];
            $plans = [];

            foreach ($queries as $vector) {
                $query = DB::table($table)->select('id')->where('team_id', $team)
                    ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)->limit(10);
                $truth = Probe::exact($this->nearest($vector)->where('team_id', $team));
                $run = Probe::run($query, $settings);
                $returned += count($run['ids']);
                $correct += count(array_intersect($run['ids'], $truth));
                $times[] = $run['ms'];
                preg_match('/(Index Scan using \w+|Bitmap Heap Scan on \w+|Seq Scan on \w+)/', $run['plan'], $match);
                $plans[$match[1] ?? 'other'] = true;
            }

            return ['rows' => $returned, 'recall' => $correct / (10 * count($queries)), 'median_ms' => $this->median($times), 'plans' => array_keys($plans)];
        };

        $rows = [];
        DB::statement('create index documents_team8_hnsw on documents using hnsw (embedding vector_cosine_ops) where team_id = 8');
        DB::statement('analyze documents');

        try {
            $rows['partial_index_team_8'] = $measure('documents', 8);
        } finally {
            DB::statement('drop index documents_team8_hnsw');
        }

        foreach ([1, 2, 4, 8, 11, 12, 13, 14, 150] as $team) {
            $rows["shared_team_{$team}"] = $measure('documents', $team);
            $rows["partitioned_team_{$team}"] = $measure('documents_by_team', $team);
            $rows["partitioned_relaxed_team_{$team}"] = $measure('documents_by_team', $team, ['hnsw.iterative_scan' => 'relaxed_order']);
            $this->line($team.' '.json_encode([$rows["shared_team_{$team}"], $rows["partitioned_team_{$team}"]]));
        }

        $all = function (string $table) use ($queries): array {
            $correct = 0;
            $times = [];
            foreach ($queries as $vector) {
                $run = Probe::run(DB::table($table)->select('id')->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)->limit(10));
                $correct += count(array_intersect($run['ids'], Probe::exact($this->nearest($vector))));
                $times[] = $run['ms'];
            }

            return ['recall' => $correct / (10 * count($queries)), 'median_ms' => $this->median($times)];
        };
        $rows['all_teams_shared'] = $all('documents');
        $rows['all_teams_partitioned'] = $all('documents_by_team');
        $rows['default_partition_rows'] = DB::table('documents_by_team_rest')->count();
        $rows['team_sizes'] = DB::table('documents')->whereIn('team_id', [8, 12, 13, 14])->groupBy('team_id')->selectRaw('team_id, count(*) as size')->pluck('size', 'team_id');

        return ['layouts' => $rows];
    }

    /** pgvector's README advice in Laravel terms: nearest ten in a subquery, the distance filter outside it. */
    private function nearestThenFilter(array $vector, float $min): Builder
    {
        $nearest = DB::table('documents')
            ->select('id')
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->orderBy('distance')
            ->limit(10);

        return DB::query()
            ->fromSub($nearest, 'nearest')
            ->select('id')
            ->where('distance', '<=', 1 - $min)
            ->orderBy('distance');
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
