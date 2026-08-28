CREATE TABLE IF NOT EXISTS stobe_settings_presets (
    scope TEXT NOT NULL CHECK (scope IN ('global', 'profile')),
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    settings JSONB NOT NULL CHECK (jsonb_typeof(settings) = 'object'),
    PRIMARY KEY (scope, name)
);
CREATE UNIQUE INDEX IF NOT EXISTS stobe_settings_presets_name_idx
    ON stobe_settings_presets (scope, lower(name));
