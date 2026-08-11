<?php

function stobeAdventureEventTypes(): array
{
    return [
        'im_alive',
        'chat',
        'infoaction',
        'rpg_word',
        'rpg_lvlup',
        'rechat',
        'quest',
        'itemfound',
        'inputtext',
        'injection',
        'goodnight',
        'goodmorning',
        'ginputtext',
        'death',
        'combatendmighty',
        'combatend',
    ];
}

// Match one exact NPC token while ignoring transient audience-state suffixes.
function stobeBuildNpcEventPeopleWhereClause(string $npcPlaceholder = '$1', string $peopleColumn = 'people'): string
{
    if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*$/', $peopleColumn)) {
        $peopleColumn = 'people';
    }
    if (!preg_match('/^\$[1-9][0-9]*$/', $npcPlaceholder)) {
        $npcPlaceholder = '$1';
    }

    return "EXISTS (
        SELECT 1
        FROM jsonb_array_elements_text(
            CASE
                WHEN left(btrim(COALESCE({$peopleColumn}, '')), 1) = '['
                    THEN COALESCE(NULLIF(btrim({$peopleColumn}), ''), '[]')::jsonb
                ELSE to_jsonb(string_to_array(trim(BOTH '|' FROM COALESCE({$peopleColumn}, '')), '|'))
            END
        ) AS stobe_person(person_name)
        WHERE lower(regexp_replace(
            btrim(stobe_person.person_name),
            '( \\((busy|hostile|in combat|restrained)\\)|\\|hand_[0-9]+)+$',
            '',
            'i'
        )) = lower({$npcPlaceholder})
    )";
}

function stobeParseEventPeople(mixed $people): array
{
    $text = trim(strval($people ?? ''));
    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    $parts = is_array($decoded) ? $decoded : explode('|', trim($text, '|'));
    $recipients = [];
    foreach ($parts as $part) {
        $name = trim(strval($part));
        $name = preg_replace('/(?: \\((?:busy|hostile|in combat|restrained)\\)|\\|hand_[0-9]+)+$/i', '', $name) ?? $name;
        if ($name !== '' && !in_array($name, $recipients, true)) {
            $recipients[] = $name;
        }
    }
    return $recipients;
}
