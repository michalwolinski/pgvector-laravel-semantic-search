<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('wiki_id')->unique();
            $table->unsignedInteger('team_id')->index();
            $table->string('title');
            $table->text('body');
            $table->vector('embedding', dimensions: 384)->index();
        });

        Schema::create('nomic_documents', function (Blueprint $table) {
            $table->foreignId('document_id')->primary()->constrained();
            $table->vector('embedding', dimensions: 768);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomic_documents');
        Schema::dropIfExists('documents');
    }
};
