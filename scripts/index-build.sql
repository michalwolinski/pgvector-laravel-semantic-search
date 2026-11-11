-- HNSW build cost on the 100,000 imported vectors: index first then insert (what a migration with
-- ->index() does), insert then build, and the build with Postgres' default maintenance_work_mem.
-- Run: docker compose exec -T db psql -U laravel wiki < scripts/index-build.sql | tee runs/index-build.log
\timing on
select version(), (select extversion from pg_extension where extname = 'vector');
show max_parallel_maintenance_workers;

create table copy_a (id bigint, embedding vector(384));
create index on copy_a using hnsw (embedding vector_cosine_ops);
insert into copy_a select id, embedding from documents;

create table copy_b (id bigint, embedding vector(384));
insert into copy_b select id, embedding from documents;
set maintenance_work_mem = '512MB';
create index on copy_b using hnsw (embedding vector_cosine_ops);
drop index copy_b_embedding_idx;

set maintenance_work_mem = '64MB';
create index on copy_b using hnsw (embedding vector_cosine_ops);

drop table copy_a;
drop table copy_b;
