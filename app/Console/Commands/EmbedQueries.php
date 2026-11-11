<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Embeddings;

#[Signature('wiki:embed-queries')]
#[Description('Embeds the 40 hand-written questions and the 1,000 held-out paragraphs once, so tests and benchmarks need no model server')]
final class EmbedQueries extends Command
{
    public function handle(): int
    {
        $queries = json_decode((string) file_get_contents(base_path('data/queries.json')), true, flags: JSON_THROW_ON_ERROR);
        $questions = array_column($queries, 'q');

        $heldout = array_map(
            fn (string $line): string => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['body'],
            file(base_path('data/heldout.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );

        $vectors = [
            'questions' => [
                'bge' => Embeddings::for($questions)->generate('bge')->embeddings,
                // nomic expects "search_query: " in front of a question...
                'nomic' => Embeddings::for(array_map(fn (string $q): string => 'search_query: '.$q, $questions))->generate('nomic')->embeddings,
                // ...and this is what a plain string sent through whereVectorSimilarTo() gets instead
                'nomic_unprefixed' => Embeddings::for($questions)->generate('nomic')->embeddings,
            ],
            'heldout' => [],
        ];

        foreach (array_chunk($heldout, 250) as $chunk) {
            array_push($vectors['heldout'], ...Embeddings::for($chunk)->generate('bge')->embeddings);
        }

        file_put_contents(base_path('data/query-vectors.json'), json_encode($vectors, JSON_THROW_ON_ERROR));
        $this->info(sprintf('%d questions, %d held-out paragraphs', count($questions), count($vectors['heldout'])));

        return self::SUCCESS;
    }
}
