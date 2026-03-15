<?php

/**
 * StobeServer DB updates (release consolidator).
 * Set STOBE_DB_UPDATES_LEGACY=1 to run the archived pre-release migrator.
 */

$useLegacy = strval(getenv('STOBE_DB_UPDATES_LEGACY') ?: '') === '1';
if ($useLegacy) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'db_updates_legacy.php');
    return;
}

if (!function_exists('stobeRunDatabaseUpdates')) {
    function stobeRunDatabaseUpdates(): void
    {
        if (!empty($GLOBALS['__stobe_db_updates_ran'])) {
            return;
        }
        $GLOBALS['__stobe_db_updates_ran'] = true;

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            stobeLogWarn('DB updates skipped: database handle is missing');
            return;
        }

        $versionSqlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'database_versioning.sql';
        if (is_file($versionSqlPath)) {
            $sql = file_get_contents($versionSqlPath);
            if (is_string($sql) && trim($sql) !== '') {
                $db->exec($sql);
            }
        }

        $db->exec("DO $$
        BEGIN
            IF EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'database_versioning'
            ) AND NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'database_versioning' AND column_name = 'tablename'
            ) THEN
                ALTER TABLE public.database_versioning RENAME TO database_versioning_legacy;
                CREATE TABLE IF NOT EXISTS public.database_versioning (
                    tablename TEXT PRIMARY KEY,
                    version BIGINT NOT NULL
                );
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = 'database_versioning_legacy' AND column_name = 'version'
                ) THEN
                    INSERT INTO public.database_versioning (tablename, version)
                    SELECT 'schema', COALESCE(MAX(version), 0)::BIGINT
                    FROM public.database_versioning_legacy
                    ON CONFLICT (tablename) DO UPDATE
                    SET version = GREATEST(public.database_versioning.version, EXCLUDED.version);
                END IF;
                DROP TABLE IF EXISTS public.database_versioning_legacy;
            END IF;
        END $$;");

        $checkVersion = static function (string $tableName) use ($db): int {
            $row = $db->fetchOne("SELECT version FROM public.database_versioning WHERE tablename = $1", [$tableName]);
            return $row ? intval($row['version'] ?? -1) : -1;
        };

        $setVersion = static function (string $tableName, int $version) use ($db): void {
            $db->exec(
                "INSERT INTO public.database_versioning (tablename, version)
                 VALUES ($1, $2)
                 ON CONFLICT (tablename) DO UPDATE SET version = EXCLUDED.version",
                [$tableName, $version]
            );
            stobeLogInfo('DB patch version updated', ['tablename' => $tableName, 'version' => $version]);
        };

        $applyPatch = static function (string $tableName, int $version, callable $callback) use ($checkVersion, $setVersion): void {
            if ($checkVersion($tableName) >= $version) {
                return;
            }
            try {
                $callback();
                $setVersion($tableName, $version);
            } catch (Throwable $e) {
                stobeLogException($e, 'DB patch failed', ['tablename' => $tableName, 'version' => $version]);
            }
        };

        $runSqlSeedFile = static function (
            string $seedPath,
            string $missingMessage,
            string $emptyMessage,
            string $normalizedEmptyMessage,
            bool $stripTx = true,
            bool $throwOnFail = false
        ) use ($db): void {
            if (!is_file($seedPath)) {
                stobeLogWarn($missingMessage, ['path' => $seedPath]);
                return;
            }
            $sql = file_get_contents($seedPath);
            if (!is_string($sql) || trim($sql) === '') {
                stobeLogWarn($emptyMessage, ['path' => $seedPath]);
                return;
            }
            if ($stripTx) {
                $sql = preg_replace('/^\s*BEGIN\s*;\s*/mi', '', $sql) ?? $sql;
                $sql = preg_replace('/\s*COMMIT\s*;\s*$/mi', '', $sql) ?? $sql;
            }
            $sql = trim($sql);
            if ($sql === '') {
                stobeLogWarn($normalizedEmptyMessage, ['path' => $seedPath]);
                return;
            }
            $ok = $db->exec($sql);
            if ($throwOnFail && $ok === false) {
                throw new RuntimeException('Seed SQL execution failed: ' . $seedPath);
            }
        };
        $importWorldKnowledgeCsv = static function (string $seedPath) use ($db): void {
            if (!is_file($seedPath)) {
                stobeLogWarn('world_knowledge import skipped: seed file missing', ['path' => $seedPath]);
                return;
            }
            $has = $db->fetchOne("SELECT to_regclass('public.world_knowledge') AS rel");
            if (!is_array($has) || trim(strval($has['rel'] ?? '')) === '') {
                stobeLogWarn('world_knowledge import skipped: table not found');
                return;
            }
            $h = @fopen($seedPath, 'r');
            if (!$h) {
                stobeLogWarn('world_knowledge import skipped: cannot open seed file', ['path' => $seedPath]);
                return;
            }
            $header = fgetcsv($h);
            if (!is_array($header) || count($header) === 0) {
                fclose($h);
                stobeLogWarn('world_knowledge import skipped: empty CSV header', ['path' => $seedPath]);
                return;
            }
            $map = [];
            foreach ($header as $i => $nameRaw) {
                $name = trim(strval($nameRaw ?? ''));
                if ($i === 0) {
                    $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
                }
                if ($name !== '') {
                    $map[strtolower($name)] = intval($i);
                }
            }
            $pick = static function (array $row, array $columnMap, array $aliases, int $fallback = -1): string {
                foreach ($aliases as $alias) {
                    $k = strtolower(trim(strval($alias)));
                    if ($k !== '' && array_key_exists($k, $columnMap)) {
                        return trim(strval($row[intval($columnMap[$k])] ?? ''));
                    }
                }
                return $fallback >= 0 ? trim(strval($row[$fallback] ?? '')) : '';
            };
            while (($row = fgetcsv($h)) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                $topic = $pick($row, $map, ['topic', 'stringid', 'baseid'], 0);
                $desc = $pick($row, $map, ['topic_desc', 'description'], 1);
                $descBasic = $pick($row, $map, ['topic_desc_basic', 'basic_description'], 2);
                $kClass = $pick($row, $map, ['knowledge_class']);
                $kClassBasic = $pick($row, $map, ['knowledge_class_basic']);
                $aliases = $pick($row, $map, ['aliases']);
                $tags = $pick($row, $map, ['tags', 'category']);
                if ($topic === '' || ($desc === '' && $descBasic === '')) {
                    continue;
                }
                $existing = $db->fetchOne("SELECT id FROM world_knowledge WHERE LOWER(topic)=LOWER($1) LIMIT 1", [$topic]);
                $id = intval($existing['id'] ?? 0);
                if ($id > 0) {
                    $db->exec(
                        "UPDATE world_knowledge
                         SET topic=$1, topic_desc=$2, topic_desc_basic=$3,
                             knowledge_class=$4, knowledge_class_basic=$5,
                             aliases=$6, tags=$7
                         WHERE id=$8",
                        [$topic, $desc, $descBasic, $kClass, $kClassBasic, $aliases, $tags, $id]
                    );
                } else {
                    $rowId = $db->fetchOne(
                        "INSERT INTO world_knowledge (
                            topic, topic_desc, topic_desc_basic,
                            knowledge_class, knowledge_class_basic,
                            aliases, tags
                         ) VALUES ($1, $2, $3, $4, $5, $6, $7)
                         RETURNING id",
                        [$topic, $desc, $descBasic, $kClass, $kClassBasic, $aliases, $tags]
                    );
                    $id = intval($rowId['id'] ?? 0);
                }
                if ($id > 0) {
                    $db->exec(
                        "UPDATE world_knowledge
                         SET native_vector =
                             setweight(to_tsvector('simple', COALESCE(topic, '')), 'A')
                             || setweight(to_tsvector('simple', COALESCE(topic_desc, '')), 'B')
                             || setweight(to_tsvector('simple', COALESCE(topic_desc_basic, '')), 'C')
                         WHERE id = $1",
                        [$id]
                    );
                }
            }
            fclose($h);
        };

        $defaultMetadata = json_encode([
            'DYNAMIC_PROFILE_ENABLED' => true,
            'MIDDLE_TERM_MEMORY_ENABLED' => true,
            'DIARY_DAYS' => 1,
            'DYNAMIC_PROFILE_FIELDS' => ['personality', 'occupation', 'speechstyle', 'goals'],
            'RECHAT_RESPONSES' => 3,
            'RECHAT_PROBABILITY' => 66,
            'DIARY_PROMPT' => "Please write a short summary of #PLAYER_NAME# and #NPC_NAME#'s last dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
            'DIARY_COOLDOWN' => 120,
            'CONTEXT_HISTORY' => 75,
            'CONTEXT_HISTORY_DIARY' => 100,
            'CONTEXT_HISTORY_DYNAMIC_PROFILE' => 50,
            'BORED_EVENT_CHANCE' => 50,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($defaultMetadata) || trim($defaultMetadata) === '') {
            $defaultMetadata = '{}';
        }

        $applyPatch('core_npc_master', 202603130201, static function () use ($db): void {
            $db->exec("DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc' AND table_type='BASE TABLE')
                   AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc_master' AND table_type='BASE TABLE') THEN
                    ALTER TABLE core_npc RENAME TO core_npc_master;
                END IF;
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc_master' AND table_type='BASE TABLE') THEN
                    EXECUTE 'CREATE OR REPLACE VIEW core_npc AS SELECT * FROM core_npc_master';
                END IF;
            END $$;");

            $db->exec("ALTER TABLE core_npc_master ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS bounty JSONB DEFAULT '{}'::jsonb");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS snapshot_reason VARCHAR(64) DEFAULT 'snapshot'");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS snapshot_hash TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS source_created_at TIMESTAMP");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS source_updated_at TIMESTAMP");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_npc_id ON core_npc_master_history (npc_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_created ON core_npc_master_history (created DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_gamets ON core_npc_master_history (gamets_last_updated DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_bounty ON core_npc_master ((CASE WHEN COALESCE(bounty->>'total','') ~ '^[0-9]+$' THEN (bounty->>'total')::BIGINT ELSE 0 END) DESC)");

            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE src RECORD; reason TEXT;
            BEGIN
                IF TG_OP='DELETE' THEN src := OLD; reason := 'delete';
                ELSIF TG_OP='INSERT' THEN src := NEW; reason := 'create';
                ELSE src := NEW; reason := 'snapshot';
                END IF;
                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
                    COALESCE(src.bounty,'{}'::jsonb), COALESCE(src.limbs,'{}'::jsonb), COALESCE(src.blood,'0/0'), COALESCE(src.hunger,'300/300'), COALESCE(src.tags,''), COALESCE(src.is_animal,FALSE), COALESCE(src.is_slave,FALSE),
                    COALESCE(src.world_knowledge_tags,''),
                    reason, md5(COALESCE(to_jsonb(src)::text,'')), src.created_at, src.updated_at, NOW()
                );
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
        });
        $applyPatch('world_knowledge', 202603130202, static function () use ($db): void {
            $db->exec("DO $$
            BEGIN
                IF to_regclass('public.world_knowledge') IS NULL AND to_regclass('public.oghma') IS NOT NULL THEN
                    ALTER TABLE oghma RENAME TO world_knowledge;
                END IF;
            END $$;");
            $db->exec("CREATE TABLE IF NOT EXISTS world_knowledge (id SERIAL PRIMARY KEY, topic VARCHAR(255) NOT NULL, topic_desc TEXT NOT NULL, topic_desc_basic TEXT DEFAULT '', knowledge_class TEXT DEFAULT '', knowledge_class_basic TEXT DEFAULT '', aliases TEXT DEFAULT '', tags TEXT DEFAULT '', native_vector TSVECTOR)");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS topic_desc_basic TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS knowledge_class TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS knowledge_class_basic TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS aliases TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS native_vector TSVECTOR");
            $db->exec("ALTER TABLE world_knowledge DROP COLUMN IF EXISTS category");
            $db->exec("DROP INDEX IF EXISTS idx_world_knowledge_category_lower");
            $db->exec("DROP INDEX IF EXISTS idx_oghma_category_lower");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_knowledge_topic_lower ON world_knowledge (LOWER(topic))");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_knowledge_native_vector_gin ON world_knowledge USING GIN (native_vector)");
            $db->exec("ALTER TABLE world_knowledge ALTER COLUMN knowledge_class SET DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ALTER COLUMN knowledge_class_basic SET DEFAULT ''");
            $db->exec("UPDATE world_knowledge SET knowledge_class = '' WHERE LOWER(BTRIM(COALESCE(knowledge_class,'')))='knowall'");
            $db->exec("UPDATE world_knowledge SET knowledge_class_basic = '' WHERE LOWER(BTRIM(COALESCE(knowledge_class_basic,'')))='knowall'");
        });

        $applyPatch('general_settings', 202603130203, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('WORLD_KNOWLEDGE_ENABLED','true','Enable world knowledge retrieval',NOW()),
                ('WORLD_KNOWLEDGE_AMOUNT','2','Max extracted world knowledge topics per turn',NOW()),
                ('WORLD_KNOWLEDGE_CONTEXT_HISTORY','16','Recent event rows used for world knowledge keyword context',NOW()),
                ('WORLD_KNOWLEDGE_CONTEXT_KEYWORDS','8','Max world knowledge context keywords',NOW()),
                ('WORLD_KNOWLEDGE_MIN_RANK','3.30','Minimum combined rank for world knowledge hints (Herika-aligned threshold)',NOW()),
                ('SPEAKER_RECHAT','false','When true, the initiating player speaker may be selected in rechat; when false, they are excluded.',NOW()),
                ('STOBE_QUICKSTART_COMPLETED','false','When false, first dashboard visit redirects to the quickstart menu.',NOW())
                ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value, description=EXCLUDED.description, updated_at=NOW()");
            $db->exec("DELETE FROM general_settings WHERE id IN ('OGHMA_ENABLED','OGHMA_AMOUNT','OGHMA_CONTEXT_HISTORY','OGHMA_CONTEXT_KEYWORDS','OGHMA_MIN_RANK')");
        });
        $applyPatch('general_settings', 202603130211, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id IN ('BORED_EVENT_RANGE','BORED_EVENT_ENABLED','BORED_EVENT_INTERVAL','BORED_EVENT_NPC_LIMIT')");
        });
        $applyPatch('general_settings', 202603130212, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('WORLD_KNOWLEDGE_MIN_RANK','3.30','Minimum combined rank for world knowledge hints (Herika-aligned threshold)',NOW())
                ON CONFLICT (id) DO UPDATE
                SET value = CASE
                        WHEN BTRIM(COALESCE(general_settings.value,'')) IN ('', '0.10', '0.1')
                            THEN EXCLUDED.value
                        ELSE general_settings.value
                    END,
                    description = EXCLUDED.description,
                    updated_at = NOW()");
        });
        $applyPatch('general_settings', 202603130213, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id = 'WORLD_KNOWLEDGE_HEURISTIC_ENABLED'");
        });
        $applyPatch('prompts', 202603130214, static function () use ($db): void {
            $analysisPrompt = <<<'PROMPT'
You are a relationship analyzer for Kenshi NPCs. Analyze relationship descriptions and output JSON.

AFFINITY SCALE (-100 to +100, bell curve - extremes are rare):
+91 to +100: Bonded (unbreakable trust)
+76 to +90: Devoted (deep loyalty)
+56 to +75: Fond (genuine affection)
+31 to +55: Friendly (pleasant and helpful)
+6 to +30: Acquaintance (polite familiarity)
-5 to +5: Neutral (stranger/indifferent)
-6 to -30: Wary (distrustful)
-31 to -55: Cold (unfriendly)
-56 to -75: Resentful (bitter)
-76 to -90: Hateful (active malice)
-91 to -100: Hostile (kill on sight)

TYPES: romantic, platonic, familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective, indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious, admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive

INFERENCE RULES:
1. Infer likely attitudes from faction, race, and occupation clues when clearly implied.
2. Ignore legacy placeholders such as #PLAYER_NAME# as relationship targets.
3. Be conservative when uncertain. Prefer small shifts over dramatic values.

OUTPUT (JSON only):
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}
PROMPT;
            $evalPrompt = <<<'PROMPT'
You are a behavioral psychologist. Evaluate interactions and provide brief insight.

SPEAKER ATTRIBUTION:
- [SPEAKER] and [LISTENER] tags show who said what.
- Evaluate the listener-targeted impact of the exchange.

AFFINITY SCALE (-100 to +100):
- +/-1: Normal chat
- +/-2-3: Notably friendly/rude, small favors
- +/-5-10: Meaningful help, gifts, insults
- +/-15-25: Saving life, violence, betrayal
- +/-50+: Extreme events

MOST INTERACTIONS = 0 or +/-1. Be conservative.

REASON FORMAT (under 15 words):
- "Teasing triggered defensiveness"
- "Genuine interest builds trust"
- "Helpful action appreciated"

TYPE CHANGES (rare):
- Change type only on defining moments: confession, betrayal, violence, marriage, family reveal.
- Most interactions only adjust affinity.

OUTPUT (JSON only):
{"changes": {"ListenerName": {"delta": 1, "reason": "brief insight"}}}

No changes? Return: {"changes": {}}
PROMPT;

            $db->exec(
                "UPDATE prompts
                 SET default_prompt = $1,
                     description = 'System prompt for relationship LLM analysis. Used in ext/relationship_system/relationship_llm.php.',
                     updated_at = NOW()
                 WHERE prompt_key = 'rel_llm_analysis'",
                [$analysisPrompt]
            );
            $db->exec(
                "UPDATE prompts
                 SET default_prompt = $1,
                     description = 'System prompt for relationship conversation evaluation. Used in ext/relationship_system/relationship_llm.php.',
                     updated_at = NOW()
                 WHERE prompt_key = 'rel_llm_evaluation'",
                [$evalPrompt]
            );
        });

        $applyPatch('core_action', 202603130204, static function () use ($db): void {
            $desc = 'Display a descriptive roleplay action as a world notification. Put action text in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('ROLEPLAY_ACTION','RoleplayAction',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()", [$desc]);
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('TRAVEL_LOCATION','TravelLocation','Travel to a previously visited location by name. Uses stored x/y/z coordinates from location_zones.',TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()");
            $db->exec("DELETE FROM core_action WHERE UPPER(COALESCE(command,'')) IN ('NOTIFY','RELEASE_PLAYER','RELEASE_PRISONER','RELEASEPLAYER')");
            $db->exec("UPDATE core_action_custom SET command='ROLEPLAY_ACTION', action_name='RoleplayAction', description=$1, updated_at=NOW() WHERE UPPER(COALESCE(command,''))='NOTIFY'", [$desc]);
            $db->exec("DELETE FROM core_action_custom WHERE UPPER(COALESCE(command,'')) IN ('RELEASE_PLAYER','RELEASE_PRISONER','RELEASEPLAYER')");
        });
        $applyPatch('core_action', 202603140205, static function () use ($db): void {
            $desc = 'Remove one limb from a helpless target. Requires a hacksaw in inventory. Use target and item as LEFT_ARM, RIGHT_ARM, LEFT_LEG, or RIGHT_LEG. Works only on knocked-out, unconscious, imprisoned, or carried targets.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('REMOVE_LIMB','RemoveLimb',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140206, static function () use ($db): void {
            $desc = 'Consume Hashish from your inventory/equipment. Applies a high state for 5 in-game hours and increases hunger drain to 1.5x during that time. Put the item name in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('USE_DRUGS','UseDrugs',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140207, static function () use ($db): void {
            $desc = 'Consume Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment. Applies drunk effects and can escalate to knockout. Put the item name in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('DRINK','Drink',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140208, static function () use ($db): void {
            $desc = 'Kill a helpless target immediately. Works only on knocked-out, unconscious, imprisoned, or carried targets. Put target name in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('KILL','Kill',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_npc_master', 202603140211, static function () use ($db): void {
            $db->exec("DO $$
            DECLARE
                schema_name TEXT;
                source_schema TEXT;
                col_list TEXT;
            BEGIN
                FOR schema_name IN
                    SELECT t.table_schema
                    FROM information_schema.tables t
                    WHERE t.table_name='core_npc_master' AND t.table_type='BASE TABLE'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %I.core_npc_master ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT %L',
                        schema_name,
                        ''
                    );
                END LOOP;

                FOR schema_name IN
                    SELECT t.table_schema
                    FROM information_schema.tables t
                    WHERE t.table_name='core_npc_master_history' AND t.table_type='BASE TABLE'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %I.core_npc_master_history ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT %L',
                        schema_name,
                        ''
                    );
                END LOOP;

                FOR schema_name IN
                    SELECT v.table_schema
                    FROM information_schema.views v
                    WHERE v.table_name='core_npc'
                LOOP
                    source_schema := schema_name;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM information_schema.tables t
                        WHERE t.table_schema = schema_name
                          AND t.table_name = 'core_npc_master'
                          AND t.table_type = 'BASE TABLE'
                    ) THEN
                        source_schema := 'public';
                    END IF;

                    SELECT string_agg(format('%I', c.column_name), ', ' ORDER BY c.ordinal_position)
                    INTO col_list
                    FROM information_schema.columns c
                    WHERE c.table_schema = source_schema
                      AND c.table_name = 'core_npc_master'
                      AND c.column_name <> 'knowledge_tags';

                    IF COALESCE(col_list, '') <> '' THEN
                        EXECUTE format('DROP VIEW IF EXISTS %I.core_npc', schema_name);
                        EXECUTE format(
                            'CREATE VIEW %I.core_npc AS SELECT %s FROM %I.core_npc_master',
                            schema_name,
                            col_list,
                            source_schema
                        );
                    END IF;
                END LOOP;

                FOR schema_name IN
                    SELECT c.table_schema
                    FROM information_schema.columns c
                    WHERE c.table_name='core_npc_master' AND c.column_name='knowledge_tags'
                LOOP
                    EXECUTE format(
                        'UPDATE %I.core_npc_master SET world_knowledge_tags = COALESCE(NULLIF(world_knowledge_tags, %L), NULLIF(knowledge_tags, %L), %L)',
                        schema_name,
                        '',
                        '',
                        ''
                    );
                    EXECUTE format('ALTER TABLE %I.core_npc_master DROP COLUMN knowledge_tags', schema_name);
                END LOOP;

                FOR schema_name IN
                    SELECT c.table_schema
                    FROM information_schema.columns c
                    WHERE c.table_name='core_npc_master_history' AND c.column_name='knowledge_tags'
                LOOP
                    EXECUTE format(
                        'UPDATE %I.core_npc_master_history SET world_knowledge_tags = COALESCE(NULLIF(world_knowledge_tags, %L), NULLIF(knowledge_tags, %L), %L)',
                        schema_name,
                        '',
                        '',
                        ''
                    );
                    EXECUTE format('ALTER TABLE %I.core_npc_master_history DROP COLUMN knowledge_tags', schema_name);
                END LOOP;
            END $$;");
            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE src RECORD; reason TEXT;
            BEGIN
                IF TG_OP='DELETE' THEN src := OLD; reason := 'delete';
                ELSIF TG_OP='INSERT' THEN src := NEW; reason := 'create';
                ELSE src := NEW; reason := 'snapshot';
                END IF;
                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
                    COALESCE(src.bounty,'{}'::jsonb), COALESCE(src.limbs,'{}'::jsonb), COALESCE(src.blood,'0/0'), COALESCE(src.hunger,'300/300'), COALESCE(src.tags,''), COALESCE(src.is_animal,FALSE), COALESCE(src.is_slave,FALSE),
                    COALESCE(src.world_knowledge_tags,''),
                    reason, md5(COALESCE(to_jsonb(src)::text,'')), src.created_at, src.updated_at, NOW()
                );
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
        });

        $applyPatch('core_profiles', 202603130210, static function () use ($db, $defaultMetadata): void {
            $db->exec("INSERT INTO core_api_badge (label, api_key) VALUES ('Player2','CHIM') ON CONFLICT (label) DO NOTHING");
            $badge = $db->fetchOne("SELECT id FROM core_api_badge WHERE LOWER(label) IN ('player2','chim') ORDER BY CASE WHEN LOWER(label)='player2' THEN 0 ELSE 1 END, id ASC LIMIT 1");
            $badgeId = intval($badge['id'] ?? 0);
            $db->exec("INSERT INTO core_llm_connector (name, connector_type, api_badge_id, api_key, base_url, model, max_tokens, temperature, is_default, config)
                VALUES ('Player2 Local','player2json',$1,'','http://127.0.0.1:4315/v1/chat/completions','player2-app-selected',750,1.0,FALSE,'{\"player2_game_key\":\"CHIM\"}'::jsonb)
                ON CONFLICT (name) DO UPDATE SET connector_type=EXCLUDED.connector_type, api_badge_id=COALESCE(core_llm_connector.api_badge_id,EXCLUDED.api_badge_id)", [$badgeId > 0 ? $badgeId : null]);
            $std = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='gemini 2.5 flash' LIMIT 1");
            $stdId = intval($std['id'] ?? 0);
            if ($stdId <= 0) {
                $fallback = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='openrouter default' LIMIT 1");
                $stdId = intval($fallback['id'] ?? 0);
            }
            $lite = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='gemini 2.5 flash lite' LIMIT 1");
            $liteId = intval($lite['id'] ?? 0);
            if ($liteId <= 0) {
                $liteId = $stdId;
            }
            $memory = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='mistral small 3.2 24b' LIMIT 1");
            $memoryId = intval($memory['id'] ?? 0);
            if ($memoryId <= 0) {
                $memoryId = $stdId;
            }
            $responseConnectorId = $stdId > 0 ? $stdId : null;
            $diaryConnectorId = $responseConnectorId;
            $autochatConnectorId = $liteId > 0 ? $liteId : $responseConnectorId;
            $middletermConnectorId = $memoryId > 0 ? $memoryId : $responseConnectorId;
            $backgroundlifeConnectorId = $middletermConnectorId;
            $dynamicConnectorId = $responseConnectorId;
            $relationshipConnectorId = $responseConnectorId;
            $tts = $db->fetchOne("SELECT id FROM core_tts_connector WHERE LOWER(name)='pocket tts default' LIMIT 1");
            $ttsId = intval($tts['id'] ?? 0);
            $row = $db->fetchOne(
                "INSERT INTO core_profiles (label, is_default_npc, response_connector, diary_connector, autochat_connector, middleterm_connector, backgroundlife_connector, dynamic_connector, relationship_connector, tts_connector_id, metadata)
                 VALUES ('Default Profile', TRUE, $1, $2, $3, $4, $5, $6, $7, $8, $9::jsonb)
                 ON CONFLICT (label) DO UPDATE SET is_default_npc=TRUE, metadata = CASE WHEN core_profiles.metadata IS NULL OR core_profiles.metadata='[]'::jsonb OR jsonb_typeof(core_profiles.metadata) <> 'object' THEN EXCLUDED.metadata ELSE EXCLUDED.metadata || core_profiles.metadata END, updated_at=NOW()
                 RETURNING id",
                [
                    $responseConnectorId,
                    $diaryConnectorId,
                    $autochatConnectorId,
                    $middletermConnectorId,
                    $backgroundlifeConnectorId,
                    $dynamicConnectorId,
                    $relationshipConnectorId,
                    $ttsId > 0 ? $ttsId : null,
                    $defaultMetadata
                ]
            );
            $pid = intval($row['id'] ?? 0);
            if ($pid > 0) {
                $db->exec("UPDATE core_profiles SET is_default_npc=FALSE WHERE id<>$1 AND COALESCE(is_default_npc,FALSE)=TRUE", [$pid]);
                $db->exec("UPDATE core_npc_master SET profile_id=$1 WHERE profile_id IS NULL", [$pid]);
            }
            if (
                $responseConnectorId !== null
                || $autochatConnectorId !== null
                || $middletermConnectorId !== null
            ) {
                $db->exec(
                    "UPDATE core_profiles
                     SET response_connector=$1,
                         diary_connector=$2,
                         autochat_connector=$3,
                         middleterm_connector=$4,
                         backgroundlife_connector=$5,
                         dynamic_connector=$6,
                         relationship_connector=$7
                     WHERE COALESCE(is_default_npc,FALSE)=TRUE
                        OR LOWER(COALESCE(label,''))='default profile'",
                    [
                        $responseConnectorId,
                        $diaryConnectorId,
                        $autochatConnectorId,
                        $middletermConnectorId,
                        $backgroundlifeConnectorId,
                        $dynamicConnectorId,
                        $relationshipConnectorId
                    ]
                );
            }
        });
        $applyPatch('core_npc_master', 202603130215, static function () use ($db): void {
            $db->exec("
                UPDATE core_npc_master
                SET extended_data = jsonb_set(
                    COALESCE(extended_data, '{}'::jsonb),
                    '{relationships}',
                    COALESCE(
                        (
                            SELECT jsonb_object_agg(e.key, e.value)
                            FROM jsonb_each(COALESCE(core_npc_master.extended_data->'relationships', '{}'::jsonb)) AS e(key, value)
                            WHERE LOWER(BTRIM(e.key)) NOT IN ('player', 'the player', '#player_name#', 'dragonborn', 'the dragonborn')
                        ),
                        '{}'::jsonb
                    ),
                    true
                )
                WHERE COALESCE(extended_data, '{}'::jsonb) ? 'relationships'
            ");
        });

        $applyPatch('rename_token_global', 202603130206, static function () use ($runSqlSeedFile): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_characters_rename_token_global_upsert.sql';
            $runSqlSeedFile($seed, 'rename_token_global category characters seed file missing', 'rename_token_global category characters seed file empty', 'rename_token_global category characters seed file normalized to empty SQL');
        });

        $applyPatch('bio_random', 202603130207, static function () use ($runSqlSeedFile): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_bio_random_upsert.sql';
            $runSqlSeedFile($seed, 'bio_random seed file missing', 'bio_random seed file empty', 'bio_random seed file normalized to empty SQL', true, false);
            $occ = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_rename_token_bio_random_occupation_upsert.sql';
            $runSqlSeedFile($occ, 'bio_random rename-token occupation seed file missing', 'bio_random rename-token occupation seed file empty', 'bio_random rename-token occupation seed normalized to empty SQL', true, true);
        });

        $applyPatch('bio_unique', 202603130208, static function () use ($db, $runSqlSeedFile): void {
            $boss = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_boss_bio_unique_upsert.sql';
            $runSqlSeedFile($boss, 'Boss bio_unique seed file missing', 'Boss bio_unique seed file empty', 'Boss bio_unique seed file normalized to empty SQL');
            $unique = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_unique_bio_unique_upsert.sql';
            $runSqlSeedFile($unique, 'Unique bio_unique seed file missing', 'Unique bio_unique seed file empty', 'Unique bio_unique seed file normalized to empty SQL');
            $animals = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_animals_bio_unique_upsert.sql';
            $runSqlSeedFile($animals, 'Animals personified bio_unique seed file missing', 'Animals personified bio_unique seed file empty', 'Animals personified bio_unique seed file normalized to empty SQL');
            $db->exec("DELETE FROM bio_unique WHERE LOWER(name) IN ('amateur recruit','ameteur recruit','cpu of cat-lon','cpu of general hat-12','cpu of general jang','cpu of rhinobot','cpu of the head of agriculture')");
            $db->exec("DELETE FROM bio_unique_custom WHERE LOWER(name) IN ('amateur recruit','ameteur recruit','cpu of cat-lon','cpu of general hat-12','cpu of general jang','cpu of rhinobot','cpu of the head of agriculture')");
        });

        $applyPatch('world_knowledge_seed', 202603130209, static function () use ($importWorldKnowledgeCsv): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'world_knowledge_v1.csv';
            $importWorldKnowledgeCsv($seed);
        });

        $applyPatch('world_state', 202603150001, static function () use ($db): void {
            $db->exec("CREATE TABLE IF NOT EXISTS world_state (
                id BIGSERIAL PRIMARY KEY,
                game_ts BIGINT NOT NULL DEFAULT 0,
                source VARCHAR(64) NOT NULL DEFAULT 'world_event_state_query',
                query_name TEXT NOT NULL DEFAULT '',
                query_string_id TEXT NOT NULL DEFAULT '',
                query_numeric_id INT NOT NULL DEFAULT 0,
                player_involvement BOOLEAN NOT NULL DEFAULT FALSE,
                rule_category VARCHAR(64) NOT NULL,
                entity_name TEXT NOT NULL DEFAULT '',
                entity_string_id TEXT NOT NULL DEFAULT '',
                entity_numeric_id INT NOT NULL DEFAULT 0,
                state_value VARCHAR(32) NOT NULL DEFAULT '',
                bool_value BOOLEAN,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_game_ts ON world_state (game_ts DESC, id DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_created_at ON world_state (created_at DESC, id DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_rule_category ON world_state (rule_category)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_query_name_lower ON world_state (LOWER(query_name))");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_entity_name_lower ON world_state (LOWER(entity_name))");
        });
        $applyPatch('world_state', 202603150002, static function () use ($db): void {
            $db->exec("DROP TABLE IF EXISTS world_state_snapshot");
            $db->exec("DELETE FROM public.database_versioning WHERE tablename='world_state_snapshot'");
        });
        $applyPatch('world_state', 202603150003, static function () use ($db): void {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_source ON world_state (source)");
        });
        $applyPatch('world_state', 202603150004, static function () use ($db): void {
            $db->exec("ALTER TABLE world_state ADD COLUMN IF NOT EXISTS merge_key TEXT");
            $db->exec("
                UPDATE world_state
                SET merge_key =
                    LOWER(COALESCE(source, '')) || '|' ||
                    LOWER(COALESCE(query_name, '')) || '|' ||
                    LOWER(COALESCE(query_string_id, '')) || '|' ||
                    COALESCE(query_numeric_id, 0)::text || '|' ||
                    CASE WHEN COALESCE(player_involvement, FALSE) THEN '1' ELSE '0' END || '|' ||
                    LOWER(COALESCE(rule_category, '')) || '|' ||
                    LOWER(COALESCE(entity_name, '')) || '|' ||
                    LOWER(COALESCE(entity_string_id, '')) || '|' ||
                    COALESCE(entity_numeric_id, 0)::text
                WHERE COALESCE(merge_key, '') = ''
            ");
            $db->exec("
                WITH ranked AS (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY COALESCE(merge_key, '')
                            ORDER BY COALESCE(game_ts, 0) DESC, id DESC
                        ) AS rn
                    FROM world_state
                )
                DELETE FROM world_state
                WHERE id IN (SELECT id FROM ranked WHERE rn > 1)
            ");
            $db->exec("
                UPDATE world_state
                SET merge_key =
                    LOWER(COALESCE(source, '')) || '|' ||
                    LOWER(COALESCE(query_name, '')) || '|' ||
                    LOWER(COALESCE(query_string_id, '')) || '|' ||
                    COALESCE(query_numeric_id, 0)::text || '|' ||
                    CASE WHEN COALESCE(player_involvement, FALSE) THEN '1' ELSE '0' END || '|' ||
                    LOWER(COALESCE(rule_category, '')) || '|' ||
                    LOWER(COALESCE(entity_name, '')) || '|' ||
                    LOWER(COALESCE(entity_string_id, '')) || '|' ||
                    COALESCE(entity_numeric_id, 0)::text
                WHERE merge_key IS NULL
            ");
            $db->exec("ALTER TABLE world_state ALTER COLUMN merge_key SET NOT NULL");
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_world_state_merge_key ON world_state (merge_key)");
        });

        stobeLogInfo('DB updates completed (release consolidator)');
    }
}

stobeRunDatabaseUpdates();
