<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Runs one Laravel query under given pgvector settings and reports rows, time and the index it used. */
final class Probe
{
    /**
     * @param  array<string, string|int>  $settings  e.g. ['hnsw.ef_search' => 100]
     * @return array{ids: list<int>, ms: float, plan: string}
     */
    public static function run(Builder $query, array $settings = []): array
    {
        return DB::transaction(function () use ($query, $settings): array {
            foreach ($settings as $name => $value) {
                DB::statement("set local {$name} = '{$value}'");
            }

            $plan = implode("\n", array_map(
                fn (object $row): string => $row->{'QUERY PLAN'},
                DB::select('explain '.$query->toSql(), $query->getBindings()),
            ));

            $started = hrtime(true);
            $ids = $query->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $ms = (hrtime(true) - $started) / 1e6;

            return ['ids' => $ids, 'ms' => $ms, 'plan' => $plan];
        });
    }

    /** The true nearest neighbours: same query with index scans switched off. */
    public static function exact(Builder $query): array
    {
        return self::run($query, ['enable_indexscan' => 'off', 'enable_bitmapscan' => 'off'])['ids'];
    }
}
