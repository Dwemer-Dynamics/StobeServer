<?php

function stobeEventsDefaultHiddenTypes(): array
{
    return ['inputtext', 'inputtext_s', 'bored', 'infonpc', 'infonpc_close', 'infoloc'];
}

function stobeNormalizeTypeList(array $types): array
{
    $normalized = [];
    foreach ($types as $type) {
        $type = trim((string)$type);
        if ($type === '') {
            continue;
        }
        $normalized[$type] = $type;
    }
    return array_values($normalized);
}

function stobeEventsPersistedHiddenConfKey(): string
{
    return 'stobe_eventlog_hidden_types';
}

function stobeEventsPersistedHiddenTypes(): array
{
    $rawValue = trim(getConfOpt(stobeEventsPersistedHiddenConfKey(), ''));
    if ($rawValue === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (is_array($decoded)) {
        return stobeNormalizeTypeList($decoded);
    }

    return stobeNormalizeTypeList(explode(',', $rawValue));
}

function stobeEventsAllHiddenTypes(array $persistedHiddenTypes = []): array
{
    return stobeNormalizeTypeList(array_merge(
        stobeEventsDefaultHiddenTypes(),
        $persistedHiddenTypes
    ));
}
