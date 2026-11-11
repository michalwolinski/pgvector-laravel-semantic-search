<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

#[Signature('wiki:nomic {--sample=10000}')]
#[Description('Embeds the first articles, plus every article a test question points at, with nomic-embed-text too')]
final class EmbedNomicSample extends Command
{
    public function handle(): int
    {
        $titles = array_column(json_decode((string) file_get_contents(base_path('data/queries.json')), true, flags: JSON_THROW_ON_ERROR), 'title');
        $sample = DB::table('documents')->orderBy('id')->limit((int) $this->option('sample'))->select('id');

        $pending = DB::table('documents')
            ->where(fn ($query) => $query->whereIn('id', $sample)->orWhereIn('title', $titles))
            ->whereNotIn('id', DB::table('nomic_documents')->select('document_id'))
            ->orderBy('id')
            ->get(['id', 'body']);

        foreach ($pending->chunk(256) as $chunk) {
            // nomic was trained with task prefixes: documents get "search_document: "
            $vectors = Embeddings::for($chunk->map(fn (object $row): string => 'search_document: '.$row->body)->values()->all())
                ->generate('nomic')->embeddings;

            DB::table('nomic_documents')->insert($chunk->values()->map(fn (object $row, int $index): array => [
                'document_id' => $row->id,
                'embedding' => json_encode($vectors[$index]),
            ])->all());
        }

        $this->info(sprintf('%d rows embedded, %d in nomic_documents', $pending->count(), DB::table('nomic_documents')->count()));

        return self::SUCCESS;
    }
}
