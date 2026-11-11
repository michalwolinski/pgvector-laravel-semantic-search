<?php

declare(strict_types=1);

// The recommended order, not run by this repo: create the table with a plain vector column, import, and only then
// run this migration. Building HNSW over 100,000 loaded rows took 16–19 s here; inserting the same rows into a table
// that already had the index took 223–304 s (runs/index-build.log).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres' default is 64MB; at 64MB the graph stopped fitting after about 28,000 rows and the build took 58–62 s
        DB::statement("set maintenance_work_mem = '512MB'");

        Schema::table('documents', function (Blueprint $table) {
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropVectorIndex('documents_embedding_vectorindex');
        });
    }
};
