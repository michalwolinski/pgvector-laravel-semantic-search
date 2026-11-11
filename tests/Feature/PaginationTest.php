<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaginationTest extends TestCase
{
    #[Test]
    public function paginate_promises94_pages_but_the_hnsw_scan_runs_dry_after_page4(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $page = fn (int $number) => Document::query()->select('id')->whereVectorSimilarTo('embedding', $vector)->paginate(10, page: $number);

        $first = $page(1);

        $this->assertSame(931, $first->total());
        $this->assertSame(94, $first->lastPage());
        $this->assertCount(10, $page(4));
        // hnsw.ef_search = 40: the index hands over 40 rows and the scan ends
        $this->assertCount(0, $page(5));
        $this->assertCount(0, $page(50));
    }

    #[Test]
    public function iterative_scan_fills_more_pages_but_still_stops_before_the_promised_last_one(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $page = fn (int $number) => Document::query()->select('id')->whereVectorSimilarTo('embedding', $vector)->paginate(10, page: $number);

        DB::transaction(function () use ($page): void {
            DB::statement("set local hnsw.iterative_scan = 'strict_order'");

            $this->assertCount(10, $page(5));
            $this->assertCount(10, $page(87));
            $this->assertCount(7, $page(88));
            $this->assertCount(0, $page(94));
        });
    }

    #[Test]
    public function strict_order_gives_up_54_of_the_931_rows_and_the_scan_cap_is_not_the_reason(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $matching = fn (array $settings): int => DB::transaction(function () use ($vector, $settings): int {
            foreach ($settings as $name => $value) {
                DB::statement("set local {$name} = '{$value}'");
            }

            return DB::table('documents')->select('id')->whereVectorSimilarTo('embedding', $vector)->limit(2000)->get()->count();
        });

        $this->assertSame(877, $matching(['hnsw.iterative_scan' => 'strict_order']));
        $this->assertSame(877, $matching(['hnsw.iterative_scan' => 'strict_order', 'hnsw.max_scan_tuples' => 100000]));
        $this->assertSame(931, $matching(['hnsw.iterative_scan' => 'relaxed_order']));
    }
}
