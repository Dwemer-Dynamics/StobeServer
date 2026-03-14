<?php

if (!function_exists('renderSelect')) {
    function renderSelect(array $options, string $fieldName, string $labelText, string $selectedValue = ''): void
    {
        echo '<label for="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8') . '</label>';
        echo '<select id="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($options as $value => $label) {
            $selected = ((string)$value === (string)$selectedValue) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>';
            echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
            echo '</option>';
        }
        echo '</select>';
    }
}

