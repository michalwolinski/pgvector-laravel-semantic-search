<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IndexMigrationExampleTest extends TestCase
{
    #[Test]
    public function the_example_migration_builds_the_same_hnsw_index_on_loaded_rows(): void
    {
        /** @var Migration $migration */
        $migration = require base_path('examples/migrations/2026_11_11_000002_add_embedding_index_to_documents.php');
        $definition = fn (): ?string => DB::scalar("select indexdef from pg_indexes where indexname = 'documents_embedding_vectorindex'");
        $before = $definition();

        DB::beginTransaction();

        try {
            DB::statement('drop index documents_embedding_vectorindex');
            $this->assertNull($definition());

            $migration->up();

            $this->assertSame($before, $definition());
        } finally {
            DB::rollBack();
        }
    }
}
