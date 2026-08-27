<?php

// Converts final dialogue prompt sections after XML-based context filtering.
function stobeFormatPromptHeadSection(string $systemPrompt, bool $markdownEnabled): string
{
    if (!$markdownEnabled) {
        return $systemPrompt;
    }

    $systemPrompt = str_replace(["\r\n", "\r"], "\n", $systemPrompt);
    $formatTagName = static function (string $tag): string {
        return $tag === 'available_actions_list'
            ? 'Available Actions'
            : ucwords(str_replace(['_', '-'], ' ', strtolower($tag)));
    };
    // Keep paired scalar fields together so Stobe's state values and skill names
    // become list items, while multiline sections retain their actual nesting.
    $parts = preg_split(
        '/(^[ \t]*<[A-Za-z][A-Za-z0-9_-]*(?: name="[^"]*")?>[^<\n]*<\/[A-Za-z][A-Za-z0-9_-]*>[ \t]*(?=\n|$)|^[ \t]*<[A-Za-z][A-Za-z0-9_-]*(?: name="[^"]*")?>[ \t]*(?=\n|$)|<\/[A-Za-z][A-Za-z0-9_-]*>)/m',
        $systemPrompt,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    $sections = [];
    $formattedPrompt = '';
    $legacyTitles = [
        'equipment' => 'Current Equipment',
        'nearby_actors' => 'NEARBY ACTORS/NPC IN THE SCENE',
        'points_of_interest' => 'POIs - Points of Interest nearby',
    ];
    $listSections = ['people_present', 'nearby_actors', 'nearby_items', 'points_of_interest', 'scene_notes'];
    $fieldSections = [
        'world', 'character_state', 'player_base', 'security', 'power', 'construction',
        'group', 'nearby_player_allies', 'player_faction_funds', 'narrator_profile',
        'speaker_context', 'combat_priority',
    ];

    foreach ($parts as $part) {
        if (preg_match('/^\s*<([A-Za-z][A-Za-z0-9_-]*)(?: name="([^"]*)")?>([^<\n]*)<\/\1>[ \t]*$/i', $part, $tag)) {
            $name = strtolower($tag[1]);
            $label = ($tag[2] ?? '') !== '' ? $tag[2] : $formatTagName($name);
            $value = trim($tag[3]);
            if (in_array($name, ['rule', 'entry', 'line'], true)) {
                $formattedPrompt .= "\n- " . $value . "\n";
            } elseif ($name === 'skill' || in_array(end($sections), $fieldSections, true)) {
                $formattedPrompt .= "\n- " . $label . ': ' . $value . "\n";
            } else {
                $formattedPrompt .= "\n\n" . str_repeat('#', min(6, count($sections) + 1))
                    . ' ' . $label . "\n\n" . $value . "\n\n";
            }
            continue;
        }
        if (preg_match('/^\s*<([A-Za-z][A-Za-z0-9_-]*)(?: name="([^"]*)")?>\s*$/', $part, $tag)) {
            $sections[] = strtolower($tag[1]);
            $label = ($tag[2] ?? '') !== '' ? ucwords($tag[2]) : $formatTagName(end($sections));
            $formattedPrompt .= "\n\n" . str_repeat('#', min(6, count($sections))) . ' ' . $label . "\n\n";
            continue;
        }
        if (preg_match('/^<\/([A-Za-z][A-Za-z0-9_-]*)>$/', $part, $tag)) {
            $index = array_search(strtolower($tag[1]), array_reverse($sections, true), true);
            if ($index !== false) {
                $sections = array_slice($sections, 0, $index);
            }
            $formattedPrompt .= "\n\n";
            continue;
        }

        $section = end($sections);
        $part = preg_replace_callback(
            '/^[ \t]*(#{1,6})[ \t]*([^\n]+)$/m',
            static function (array $matches) use ($sections, $section, $formatTagName, $legacyTitles, $listSections): string {
                $title = trim($matches[2]);
                if ($section !== false) {
                    if (strcasecmp($title, $formatTagName($section)) === 0
                        || strcasecmp($title, $legacyTitles[$section] ?? '') === 0) {
                        return '';
                    }
                    if (in_array($section, $listSections, true)) {
                        if (strlen($matches[1]) >= 2) {
                            return '- ' . $title;
                        }
                        if ($section === 'nearby_items' && strcasecmp($title, 'ITEM DESCRIPTIONS') === 0) {
                            return "\n\n" . str_repeat('#', min(6, count($sections) + 1)) . " Item Descriptions\n\n";
                        }
                        // Keep targeting/format instructions, without repeating the section title.
                        return preg_replace('/^NEARBY ITEMS[ \t]+(?=\()/i', '', $title);
                    }
                }
                $level = $section === false ? strlen($matches[1]) : min(6, count($sections) + 1);
                return "\n\n" . str_repeat('#', $level) . ' ' . $title . "\n\n";
            },
            $part
        );
        if ($section === 'available_actions_list') {
            $part = preg_replace('/^([ \t]*)(AVAILABLE ACTION:)/m', '$1- $2', $part);
        }
        $formattedPrompt .= preg_replace_callback(
            '/<([A-Za-z][A-Za-z0-9_-]*)>/',
            static fn(array $matches): string => '`' . $formatTagName(strtolower($matches[1])) . '`',
            $part
        );
    }
    $formattedPrompt = preg_replace('/^([ \t]*)(?:•|\*|\+)[ \t]+/m', '$1- ', (string) $formattedPrompt);
    $formattedPrompt = trim(preg_replace('/\n{3,}/', "\n\n", $formattedPrompt));
    $formattedPrompt = preg_replace('/^(- [^\n]+)\n{2,}(?=- )/m', "$1\n", $formattedPrompt);

    return $formattedPrompt === '' ? '' : $formattedPrompt . "\n";
}
