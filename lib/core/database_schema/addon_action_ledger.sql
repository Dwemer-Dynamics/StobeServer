-- Opt-in addon action follow-ups (docs/plugin-runtime.md). Live infrastructure,
-- not playthrough data: each row is stamped with the playthrough and expires.
-- Idempotent: safe on fresh databases and on ledgers created by earlier patches.
CREATE TABLE IF NOT EXISTS public.stobe_addon_action_ledger (
    aid BIGINT PRIMARY KEY CHECK (aid BETWEEN 1 AND 4294967295),
    playthrough_id TEXT NOT NULL DEFAULT '',
    runtime_generation TEXT NOT NULL DEFAULT '',
    interaction_generation BIGINT NOT NULL DEFAULT 0,
    actor TEXT NOT NULL,
    actor_sid BIGINT NOT NULL CHECK (actor_sid BETWEEN 1 AND 4294967295),
    code VARCHAR(64) NOT NULL,
    argument TEXT NOT NULL DEFAULT '',
    state VARCHAR(16) NOT NULL DEFAULT 'issued' CHECK (state IN ('issued', 'completed', 'failed')),
    localts BIGINT NOT NULL,
    resolved_at BIGINT,
    client_request_id BIGINT
);
-- Playthrough runtime generation at issue (lib/playthrough_runtime.php); a
-- same-campaign switch or restore changes it. Older rows keep '' and expire.
ALTER TABLE public.stobe_addon_action_ledger ADD COLUMN IF NOT EXISTS runtime_generation TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS idx_stobe_addon_action_ledger_localts ON public.stobe_addon_action_ledger (localts);
