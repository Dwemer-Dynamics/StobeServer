CREATE TABLE IF NOT EXISTS public.core_tts_pronunciation (
    id BIGSERIAL PRIMARY KEY,
    source_text VARCHAR(120) NOT NULL,
    spoken_text VARCHAR(240) NOT NULL,
    npc_names VARCHAR(512) NOT NULL DEFAULT '',
    races VARCHAR(512) NOT NULL DEFAULT '',
    oghma_tags VARCHAR(512) NOT NULL DEFAULT '',
    is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT core_tts_pronunciation_source_not_blank CHECK (BTRIM(source_text) <> ''),
    CONSTRAINT core_tts_pronunciation_spoken_not_blank CHECK (BTRIM(spoken_text) <> '')
);

ALTER TABLE public.core_tts_pronunciation
    ADD COLUMN IF NOT EXISTS deleted BOOLEAN NOT NULL DEFAULT FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS core_tts_pronunciation_unique_entry
    ON public.core_tts_pronunciation (
        LOWER(source_text),
        MD5(LOWER(npc_names) || E'\x1f' || LOWER(races) || E'\x1f' || LOWER(oghma_tags)),
        is_builtin
    );
