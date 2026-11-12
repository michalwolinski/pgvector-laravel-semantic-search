# pgvector in Laravel 13

By [Michał Woliński](https://wolinski.com). Companion repository for the article
[pgvector in Laravel 13: whereVectorSimilarTo, HNSW and the rows that go missing](https://wolinski.com/blog/pgvector-laravel-semantic-search.html).

100,000 paragraphs of Simple English Wikipedia, embedded through the Laravel AI SDK with a local model, in
PostgreSQL 18.6 with pgvector 0.8.7, spread over 200 synthetic teams. Every claim in the article is a PHPUnit test
here, run against the imported data, and every number comes from a file in `runs/`.

## Run it

Needs Docker, PHP 8.5 with `pdo_pgsql`, Composer, Python 3 with `uv`, and llama.cpp's `llama-server`.

```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d --wait

# the corpus (CC BY-SA 4.0) and the two embedding models; checksums in data/SHA256SUMS
curl -L -o data/simplewiki-20231101.parquet https://huggingface.co/datasets/wikimedia/wikipedia/resolve/main/20231101.simple/train-00000-of-00001.parquet
curl -L -o models/bge-small-en-v1.5-f16.gguf https://huggingface.co/CompendiumLabs/bge-small-en-v1.5-gguf/resolve/main/bge-small-en-v1.5-f16.gguf
curl -L -o models/nomic-embed-text-v1.5.Q8_0.gguf https://huggingface.co/nomic-ai/nomic-embed-text-v1.5-GGUF/resolve/main/nomic-embed-text-v1.5.Q8_0.gguf
shasum -a 256 -c data/SHA256SUMS
uv run --with pyarrow==22.0.0 python -I scripts/corpus.py data/simplewiki-20231101.parquet data/corpus.jsonl 100000
uv run --with pyarrow==22.0.0 python -I scripts/corpus.py data/simplewiki-20231101.parquet data/corpus-101k.jsonl 101000 \
  && tail -n 1000 data/corpus-101k.jsonl > data/heldout.jsonl && rm data/corpus-101k.jsonl

# embedding servers (512 tokens per slot: -c is shared by the -np slots)
llama-server -m models/bge-small-en-v1.5-f16.gguf --embeddings --pooling cls -c 4096 -b 4096 -ub 4096 -np 8 -ngl 99 --port 8091 &
llama-server -m models/nomic-embed-text-v1.5.Q8_0.gguf --embeddings --pooling mean -c 2048 -b 4096 -ub 4096 -np 4 -ngl 99 --port 8092 &

php artisan migrate
php artisan wiki:import          # about 15 minutes, most of it inserting into the HNSW index
php artisan wiki:nomic
php artisan wiki:embed-queries   # from here on, no model server is needed

php artisan test
```

## Measurements

| Command | Writes | Article |
| --- | --- | --- |
| `php artisan wiki:bench recall --queries=200` | `runs/recall.json` | Recall and ef_search |
| `php artisan wiki:bench teams --queries=20` | `runs/teams.json` | Filtering by team, chart 1 |
| `php artisan wiki:bench threshold --queries=50` | `runs/threshold.json` | The threshold and iterative scan collide |
| `php artisan wiki:bench remedy --queries=20` | `runs/remedy.json` | strict vs relaxed order in the subquery form |
| `docker compose exec -T db psql -U laravel wiki < scripts/partitions.sql`, then `php artisan wiki:bench layouts --queries=20` | `runs/partitions.log`, `runs/layouts.json` | Partial indexes and partitions |
| `php scripts/analyze-flips.php` | `runs/analyze-flips.json`, `runs/analyze-flips.txt` | which teams go to HNSW after each ANALYZE, default and with statistics 1000 |
| `php artisan wiki:bench combined --queries=10` | `runs/combined.json` | partial indexes for teams 1–12 plus SemanticSearch for all 200 |
| `php scripts/thresholds.php` | `runs/thresholds.json` | Is 0.6 a good threshold? |
| `php scripts/shapes.php` | `runs/shapes.json` | What whereVectorSimilarTo sends |
| `docker compose exec -T db psql -U laravel wiki < scripts/index-build.sql` | `runs/index-build.log` | Building the index |
| `python3 -I scripts/check_article.py <article.html>` | | every quoted number and code block |

## Claims and tests

| Claim | Test |
| --- | --- |
| `->index()` on a vector column is HNSW with `vector_cosine_ops` | `SchemaTest` |
| A 1,536-dimension vector is rejected by `vector(384)` | `SchemaTest` |
| HNSW refuses more than 2,000 dimensions; a `halfvec` expression index works with `DB::raw()` | `SchemaTest` |
| `whereVectorSimilarTo()` filters and orders by `<=>`, binding the vector twice | `QueryShapeTest` |
| With a limit it uses HNSW; without one, a sequential scan and a sort | `QueryShapeTest` |
| `paginate()` counts with a full scan | `QueryShapeTest` |
| 94 pages promised, page 5 empty; iterative scan fills up to page 88 | `PaginationTest` |
| Small teams get exact answers through the B-tree; team 8 gets 14 of 200 rows; iterative scan gives 200 | `TenantFilterTest` |
| With iterative scan, a threshold nobody passes visits ~20,000 rows; the subquery form stops after 10 | `ThresholdTest` |
| 0.6 passes the right article for 38 of 40 questions | `ThresholdTest` |
| A text query is embedded once and cached for 30 days despite `cache => false` | `QueryEmbeddingCacheTest` |
| Recall@10 at the default `ef_search` is 94.95% | `RecallTest` |
| `SemanticSearch` gives every team ten rows and leaks no settings | `SemanticSearchTest` |
| The puzzle: `SET LOCAL` works under `RefreshDatabase` and does nothing without it | `PuzzleTest`, `PuzzleWithRefreshDatabaseTest` |
| The example migration builds the same index after the import | `IndexMigrationExampleTest` |
| A partial index gives team 8 all its rows; partitions fix the teams that have one and move the problem into the default partition | `TenantLayoutTest` |
| `cursorPaginate()` fails with vector ordering in both forms | `CursorPaginateTest` |
| A generic plan cannot use the partial index; Laravel's queries can | `TenantLayoutTest` |
| `simplePaginate()` stops offering pages at page 4; inside an outer transaction `SET LOCAL` outlives the search; relaxed order needs `distance + 0` outside | `RemedyTest` |
| Strict order returns 877 of 931 matches, with or without a higher scan cap; relaxed returns 931 | `PaginationTest` |

## Author

Michał Woliński, [wolinski.com](https://wolinski.com).

The corpus is derived from Simple English Wikipedia, available under CC BY-SA 4.0; it is downloaded, not included.
