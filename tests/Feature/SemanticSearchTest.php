<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Search\SemanticSearch;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SemanticSearchTest extends TestCase
{
    #[Test]
    public function every_one_of_the_200_teams_gets_ten_rows_in_order(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $search = new SemanticSearch;

        foreach (range(1, 200) as $team) {
            $rows = $search->forTeam($team, $vector);
            $similarities = $rows->pluck('similarity')->map(fn (mixed $s): float => (float) $s)->all();
            $sorted = $similarities;
            rsort($sorted);

            $this->assertCount(10, $rows, "team {$team}");
            $this->assertSame($sorted, $similarities, "team {$team} in order");
        }
    }

    #[Test]
    public function a_threshold_nobody_passes_comes_back_empty_without_walking_the_index(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->time;
        });

        $rows = (new SemanticSearch)->forTeam(1, $vector, minSimilarity: 0.9);

        $this->assertCount(0, $rows);
        // set local, set local, the search itself
        $this->assertLessThan(30, end($queries));
    }

    #[Test]
    public function the_settings_do_not_leak_out_of_the_transaction(): void
    {
        (new SemanticSearch(efSearch: 200))->forTeam(1, $this->vectors()['heldout'][0]);

        $this->assertSame('off', DB::scalar('show hnsw.iterative_scan'));
        $this->assertSame('40', DB::scalar('show hnsw.ef_search'));
    }

    #[Test]
    public function the_right_article_comes_first_for_a_plain_question(): void
    {
        $questions = $this->questions();
        $index = array_search('Giant panda', array_column($questions, 'title'), true);
        $team = (int) DB::table('documents')->where('title', 'Giant panda')->value('team_id');

        $first = (new SemanticSearch)->forTeam($team, $this->vectors()['questions']['bge'][$index])->first();

        $this->assertSame('Giant panda', $first->title);
    }
}
