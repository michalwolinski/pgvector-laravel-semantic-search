-- scripts/partitions.sql for TenantLayoutTest, under another table name and without psql meta-commands,
-- so the test can build it inside a transaction and roll it back.
create table documents_by_team_test (id bigint not null, team_id integer not null, embedding vector(384) not null) partition by list (team_id);

create table documents_by_team_test_t1 partition of documents_by_team_test for values in (1);
create table documents_by_team_test_t2 partition of documents_by_team_test for values in (2);
create table documents_by_team_test_t3 partition of documents_by_team_test for values in (3);
create table documents_by_team_test_t4 partition of documents_by_team_test for values in (4);
create table documents_by_team_test_t5 partition of documents_by_team_test for values in (5);
create table documents_by_team_test_t6 partition of documents_by_team_test for values in (6);
create table documents_by_team_test_t7 partition of documents_by_team_test for values in (7);
create table documents_by_team_test_t8 partition of documents_by_team_test for values in (8);
create table documents_by_team_test_t9 partition of documents_by_team_test for values in (9);
create table documents_by_team_test_t10 partition of documents_by_team_test for values in (10);
create table documents_by_team_test_t11 partition of documents_by_team_test for values in (11);
create table documents_by_team_test_rest partition of documents_by_team_test default;
insert into documents_by_team_test select id, team_id, embedding from documents;
set maintenance_work_mem = '512MB';
create index on documents_by_team_test using hnsw (embedding vector_cosine_ops);
create index on documents_by_team_test (team_id);
analyze documents_by_team_test;
