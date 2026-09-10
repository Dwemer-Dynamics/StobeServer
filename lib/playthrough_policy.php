<?php

// Explicit gameplay table policy. Shared presets/libraries and unknown plugin tables stay live.
function pts_playthrough_tables(): array {
    return [
        'audit_llm',
        'audit_memory',
        'audit_request',
        'autonomy_decision',
        'autonomy_economy_snapshot',
        'autonomy_event',
        'autonomy_pilot_step',
        'autonomy_session',
        'conf_opts',
        'core_action',
        'core_action_custom',
        'core_api_badge',
        'core_llm_connector',
        'core_narrator',
        'core_npc_master',
        'core_npc_master_history',
        'core_profile_import_rules',
        'core_profiles',
        'core_stt_connector',
        'core_tts_connector',
        'core_tts_pronunciation',
        'database_versioning',
        'diarylog',
        'eventlog',
        'faction_relation_state',
        'general_settings',
        'location_zones',
        'log',
        'memory',
        'memory_summary',
        'player_base_history',
        'player_base_presence',
        'player_bases',
        'prompts',
        'speech',
        'world_knowledge',
        'world_knowledge_context_rule',
        'world_state',
        'world_state_query_result',
    ];
}

// Shared libraries excluded here include biography templates, descriptions and preset stores.
// STOBE also keeps voice/name pools and world-state definitions/addenda shared.
// stobe_settings_presets combines global and profile presets, so its entire library stays shared.

// Run after database updates so new tables and retired labels follow the capture policy.
function pts_update_playthrough_policy($conn): bool {
    if (!pts_ensure_functions($conn)) return false;
    $result = @pg_query_params($conn,
        'SELECT stobe_meta.sync_playthrough_comments(ARRAY(SELECT jsonb_array_elements_text($1::jsonb)))',
        [json_encode(pts_playthrough_tables())]);
    if (!$result) Logger::error('Could not refresh Playthrough Save table comments: ' . pg_last_error($conn));
    return $result !== false;
}
