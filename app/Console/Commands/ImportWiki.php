<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Search\Teams;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use SplFileObject;

#[Signature('wiki:import {--limit=100000} {--batch=256}')]
#[Description('Embeds data/corpus.jsonl with bge-small through the Laravel AI SDK and inserts it into documents')]
final class ImportWiki extends Command
{
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $size = (int) $this->option('batch');
        $file = new SplFileObject(base_path('data/corpus.jsonl'));
        $batch = [];
        $done = 0;
        $started = hrtime(true);
        $embedding = 0;

        while ($file->eof() === false && $done < $limit) {
            $line = trim((string) $file->fgets());

            if ('' === $line) {
                continue;
            }

            $batch[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            if (count($batch) === $size || $done + count($batch) === $limit) {
                $embedding += $this->insert($batch);
                $done += count($batch);
                $batch = [];
                $this->output->write("\r{$done} rows");
            }
        }

        if ([] !== $batch) {
            $embedding += $this->insert($batch);
            $done += count($batch);
        }

        $seconds = (hrtime(true) - $started) / 1e9;
        $this->newLine();
        $this->info(sprintf('%d rows in %.1f s (%.1f s of it embedding)', $done, $seconds, $embedding / 1e9));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{wiki_id: int, title: string, body: string}>  $rows
     * @return int nanoseconds spent waiting for embeddings
     */
    private function insert(array $rows): int
    {
        $started = hrtime(true);
        $vectors = Embeddings::for(array_column($rows, 'body'))->generate('bge')->embeddings;
        $spent = hrtime(true) - $started;

        DB::transaction(function () use ($rows, $vectors): void {
            foreach ($rows as $index => $row) {
                DB::table('documents')->insert([
                    'wiki_id' => $row['wiki_id'],
                    'team_id' => Teams::of($row['wiki_id']),
                    'title' => $row['title'],
                    'body' => $row['body'],
                    'embedding' => json_encode($vectors[$index]),
                ]);
            }
        });

        return $spent;
    }
}
