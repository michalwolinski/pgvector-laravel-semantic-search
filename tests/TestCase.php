<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * The tests read the 100,000 imported articles (php artisan wiki:import) and the vectors from
 * php artisan wiki:embed-queries, so they need the database but no model server.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array{questions: array{bge: list<list<float>>, nomic: list<list<float>>, nomic_unprefixed: list<list<float>>}, heldout: list<list<float>>}|null */
    private static ?array $vectors = null;

    /** @return list<array{q: string, title: string}> */
    protected function questions(): array
    {
        return json_decode((string) file_get_contents(base_path('data/queries.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{questions: array{bge: list<list<float>>, nomic: list<list<float>>, nomic_unprefixed: list<list<float>>}, heldout: list<list<float>>} */
    protected function vectors(): array
    {
        return self::$vectors ??= json_decode((string) file_get_contents(base_path('data/query-vectors.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Runs EXPLAIN ANALYZE on a Laravel query and returns the top plan node as JSON.
     *
     * @return array<string, mixed>
     */
    protected function analyze(Builder $query): array
    {
        $row = DB::selectOne('explain (analyze, format json) '.$query->toSql(), $query->getBindings());

        return json_decode($row->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
    }

    /** The plan Postgres picks for a Laravel query, as plain text lines. */
    protected function plan(Builder $query): string
    {
        $rows = DB::select('explain '.$query->toSql(), $query->getBindings());

        return implode("\n", array_map(fn (object $row): string => $row->{'QUERY PLAN'}, $rows));
    }
}
