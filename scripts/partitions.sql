-- The same 100,000 rows, list-partitioned by team: teams 1 to 11 (the ones that lost rows on the shared HNSW
-- index) get a partition each, every other team shares the default partition. Indexes on the parent are
-- created on every partition. Run: docker compose exec -T db psql -U laravel wiki < scripts/partitions.sql
\timing on
drop table if exists documents_by_team;
create table documents_by_team (id bigint not null, team_id integer not null, embedding vector(384) not null) partition by list (team_id);
select format('create table documents_by_team_%s partition of documents_by_team for values in (%s)', t, t) from generate_series(1, 11) t \gexec
create table documents_by_team_rest partition of documents_by_team default;
insert into documents_by_team select id, team_id, embedding from documents;
set maintenance_work_mem = '512MB';
create index on documents_by_team using hnsw (embedding vector_cosine_ops);
create index on documents_by_team (team_id);
analyze documents_by_team;
