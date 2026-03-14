CREATE TABLE IF NOT EXISTS public.database_versioning (
    tablename TEXT PRIMARY KEY,
    version BIGINT NOT NULL
);
