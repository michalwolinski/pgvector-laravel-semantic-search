<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-scoped semantic search that keeps HNSW honest: the scan keeps going until the team has its
 * rows (iterative scan, set for this transaction only), and the similarity threshold is applied after
 * the nearest rows are found, so a threshold nobody passes does not walk 20,000 index entries.
 * Relaxed order finds more of the true nearest rows than strict order; the outer query sorts them again.
 */
final readonly class SemanticSearch
{
    public function __construct(private int $efSearch = 40) {}

    /**
     * @param  list<float>  $vector
     * @return Collection<int, object{id: int, title: string, similarity: float}>
     */
    public function forTeam(int $team, array $vector, int $limit = 10, float $minSimilarity = 0.0): Collection
    {
        return DB::transaction(function () use ($team, $vector, $limit, $minSimilarity): Collection {
            DB::statement("set local hnsw.iterative_scan = 'relaxed_order'");
            DB::statement('set local hnsw.ef_search = '.$this->efSearch);

            $nearest = DB::table('documents')
                ->select('id', 'title')
                ->selectVectorDistance('embedding', $vector, as: 'distance')
                ->where('team_id', $team)
                ->orderBy('distance')
                ->limit($limit);

            return DB::query()
                ->fromSub($nearest, 'nearest')
                ->select('id', 'title', DB::raw('1 - distance as similarity'))
                ->where('distance', '<=', 1 - $minSimilarity)
                // + 0: with a plain "order by distance" Postgres trusts the subquery's order and skips this sort
                ->orderByRaw('distance + 0')
                ->get();
        });
    }
}
