<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use ErrorException;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** cursorPaginate() in Laravel 13.42 cannot page by vector distance, in either way of writing the order. */
final class CursorPaginateTest extends TestCase
{
    #[Test]
    public function with_where_vector_similar_to_the_next_cursor_cannot_be_built(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $page = Document::query()->select('id')->whereVectorSimilarTo('embedding', $vector)->cursorPaginate(10);

        $this->assertCount(10, $page);
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('array_flip(): Can only flip string and integer values');

        $page->nextCursor();
    }

    #[Test]
    public function with_a_distance_alias_the_second_page_fails_in_postgres(): void
    {
        $vector = $this->vectors()['heldout'][0];
        $query = fn () => Document::query()->select('id')
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->orderBy('distance')->orderBy('id');
        $first = $query()->cursorPaginate(10);

        $this->assertCount(10, $first);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bind message supplies 4 parameters, but prepared statement');

        $query()->cursorPaginate(10, cursor: $first->nextCursor());
    }
}
