<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Partial indexes and list partitions, built inside a rolled-back transaction on the imported rows. */
final class TenantLayoutTest extends TestCase
{
    private function returned(string $table, int $team, string $iterativeScan = 'off'): int
    {
        $total = 0;

        foreach (array_slice($this->vectors()['heldout'], 0, 20) as $vector) {
            DB::statement("set local hnsw.iterative_scan = '{$iterativeScan}'");
            $total += DB::table($table)->select('id')->where('team_id', $team)
                ->whereVectorSimilarTo('embedding', $vector, minSimilarity: 0.0)->limit(10)->get()->count();
        }

        return $total;
    }

    #[Test]
    public function a_partial_hnsw_index_for_team_8_gives_it_all_200_rows(): void
    {
        DB::beginTransaction();

        try {
            DB::statement('create index documents_team8_hnsw on documents using hnsw (embedding vector_cosine_ops) where team_id = 8');

            $this->assertSame(200, $this->returned('documents', 8));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function partitions_fix_the_teams_that_have_one_and_move_the_problem_into_the_default_partition(): void
    {
        DB::beginTransaction();

        try {
            DB::unprepared((string) file_get_contents(base_path('tests/Feature/partitions.sql')));

            $this->assertSame(200, $this->returned('documents_by_team_test', 8));
            // team 13, the second largest without a partition: 1,297 of the 48,802 rows in the default partition
            $this->assertLessThan(100, $this->returned('documents_by_team_test', 13));
            $this->assertSame(200, $this->returned('documents_by_team_test', 13, 'relaxed_order'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function a_statement_planned_once_for_any_team_cannot_use_the_partial_index(): void
    {
        $vector = json_encode($this->vectors()['heldout'][0]);
        $pdo = DB::connection()->getPdo();

        DB::beginTransaction();

        try {
            DB::statement('create index documents_team8_hnsw on documents using hnsw (embedding vector_cosine_ops) where team_id = 8');
            DB::statement('set local plan_cache_mode = force_generic_plan');
            DB::statement('prepare by_team(int, vector) as select id from documents where team_id = $1 order by embedding <=> $2 limit 10');

            $generic = implode("\n", array_column(array_map(fn (object $row): array => (array) $row, DB::select('explain execute by_team(8, '.$pdo->quote($vector).')')), 'QUERY PLAN'));
            $laravel = $this->plan(DB::table('documents')->select('id')->where('team_id', 8)->whereVectorSimilarTo('embedding', $this->vectors()['heldout'][0], minSimilarity: 0.0)->limit(10));

            $this->assertStringNotContainsString('documents_team8_hnsw', $generic);
            $this->assertStringContainsString('documents_team8_hnsw', $laravel);
        } finally {
            DB::rollBack();
        }
    }
}
