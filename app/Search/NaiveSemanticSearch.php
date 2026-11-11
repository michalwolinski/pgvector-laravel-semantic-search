<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The puzzle: green in a test that uses RefreshDatabase, wrong in production. */
final readonly class NaiveSemanticSearch
{
    /**
     * @param  list<float>  $vector
     * @return Collection<int, object{id: int}>
     */
    public function forTeam(int $team, array $vector): Collection
    {
        DB::statement("set local hnsw.iterative_scan = 'strict_order'");

        return DB::table('documents')->select('id')
            ->where('team_id', $team)
            ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)
            ->limit(10)
            ->get();
    }
}
