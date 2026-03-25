<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';
$narrator = new Narrator();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_narrator'])) {
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
    $welcomeEnabled = isset($_POST['welcome_enabled']) && $_POST['welcome_enabled'] === '1' ? '1' : '0';
    $randomEnabled = isset($_POST['random_enabled']) && $_POST['random_enabled'] === '1' ? '1' : '0';
    $inlineNarration = isset($_POST['inline_narration_enabled']) && $_POST['inline_narration_enabled'] === '1' ? '1' : '0';
    $diaryEnabled = isset($_POST['diary_enabled']) && $_POST['diary_enabled'] === '1' ? '1' : '0';
    $dynamicProfile = isset($_POST['dynamic_profile']) && $_POST['dynamic_profile'] === '1' ? '1' : '0';

    $randomChance = max(1, min(100, intval($_POST['random_chance'] ?? 15)));
    $randomCooldown = max(0, min(10, intval($_POST['random_cooldown'] ?? 2)));
    $welcomeCooldown = max(1, min(1440, intval($_POST['welcome_cooldown'] ?? 10)));

    $dynamicProfileFields = [];
    if (isset($_POST['dynamic_profile_fields']) && is_array($_POST['dynamic_profile_fields'])) {
        $dynamicProfileFields = array_values(array_filter(
            $_POST['dynamic_profile_fields'],
            static function ($field): bool {
                return in_array(strtolower(trim(strval($field))), ['personality', 'speechstyle', 'goals'], true);
            }
        ));
    }

    $profileId = intval($_POST['profile_id'] ?? 1);
    if ($profileId <= 0) {
        $profileId = 1;
    }

    $payload = [
        'enabled' => $enabled,
        'welcome_enabled' => $welcomeEnabled,
        'random_enabled' => $randomEnabled,
        'random_chance' => strval($randomChance),
        'random_cooldown' => strval($randomCooldown),
        'welcome_cooldown' => strval($welcomeCooldown),
        'inline_narration_enabled' => $inlineNarration,
        'diary_enabled' => $diaryEnabled,
        'dynamic_profile' => $dynamicProfile,
        'profile_id' => strval($profileId),
        'voiceid' => trim(strval($_POST['voiceid'] ?? '')),
        'core' => trim(strval($_POST['core'] ?? '')),
        'background' => trim(strval($_POST['background'] ?? '')),
        'personality' => trim(strval($_POST['personality'] ?? '')),
        'speechstyle' => trim(strval($_POST['speechstyle'] ?? '')),
        'goals' => trim(strval($_POST['goals'] ?? '')),
        'oghma_knowledge' => trim(strval($_POST['oghma_knowledge'] ?? 'knowall')),
        'prompt_head' => trim(strval($_POST['prompt_head'] ?? '')),
    ];

    $narrator->setMultiple($payload);
    $narrator->setDynamicProfileFields($dynamicProfileFields);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$enabled = $narrator->getBool('enabled', true);
$welcomeEnabled = $narrator->getBool('welcome_enabled', false);
$randomEnabled = $narrator->getBool('random_enabled', false);
$randomChance = $narrator->getInt('random_chance', 15);
$randomCooldown = $narrator->getInt('random_cooldown', 2);
$welcomeCooldown = $narrator->getInt('welcome_cooldown', 10);
$inlineNarration = $narrator->getBool('inline_narration_enabled', false);
$diaryEnabled = $narrator->getBool('diary_enabled', false);
$dynamicProfile = $narrator->getBool('dynamic_profile', false);
$dynamicProfileFields = $narrator->getDynamicProfileFields();
$profileId = $narrator->getInt('profile_id', 1);
$defaultNarratorSeed = Narrator::defaultSeedValues($profileId > 0 ? $profileId : 1);
$voiceid = $narrator->get('voiceid') ?? strval($defaultNarratorSeed['voiceid'] ?? 'TheNarrator');
$core = $narrator->get('core') ?? strval($defaultNarratorSeed['core'] ?? '');
$background = $narrator->get('background') ?? strval($defaultNarratorSeed['background'] ?? '');
$personality = $narrator->get('personality') ?? strval($defaultNarratorSeed['personality'] ?? '');
$speechstyle = $narrator->get('speechstyle') ?? strval($defaultNarratorSeed['speechstyle'] ?? '');
$goals = $narrator->get('goals') ?? strval($defaultNarratorSeed['goals'] ?? '');
$oghmaKnowledge = $narrator->get('oghma_knowledge') ?? strval($defaultNarratorSeed['oghma_knowledge'] ?? 'knowall');
$promptHead = $narrator->get('prompt_head') ?? strval($defaultNarratorSeed['prompt_head'] ?? '');

$profiles = function_exists('getAllCoreProfiles') ? getAllCoreProfiles() : [];

if (!$isEmbed) {
    require_once(__DIR__ . "/../profile_loader.php");
    $TITLE = "Narrator Management";
    ob_start();
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/head.html");
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/navbar.php");
}
?>

<link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/css/main.css">
<style>
main {
    padding-top: <?= $isEmbed ? '18px' : '80px' ?>;
    padding-bottom: 40px;
}
.narrator-page {
    max-width: 1100px;
    margin: 0 auto;
}
.narrator-card {
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(30, 30, 30, 0.98));
    border: 1px solid #3a3a3a;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
}
.narrator-card h2 {
    margin-top: 0;
    margin-bottom: 12px;
    color: rgb(242, 124, 17);
}
.narrator-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.narrator-card label {
    display: block;
    color: #cfd8e3;
    margin-bottom: 6px;
    font-size: 13px;
}
.narrator-card input[type="text"],
.narrator-card input[type="number"],
.narrator-card select,
.narrator-card textarea {
    width: 100%;
    border-radius: 6px;
    border: 1px solid #3a3a3a;
    background: rgba(22, 22, 22, 0.85);
    color: #f0f2f5;
    padding: 10px;
    margin-bottom: 10px;
}
.narrator-card textarea {
    min-height: 90px;
    resize: vertical;
}
.toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}
.toggle-row label {
    margin-bottom: 0;
}
.help-text {
    color: #98a6bd;
    font-size: 12px;
    margin-top: -6px;
    margin-bottom: 10px;
}
.save-btn {
    background: #176529;
    color: white;
    border: 1px solid rgba(72, 187, 120, 0.35);
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
    cursor: pointer;
}
@media (max-width: 900px) {
    .narrator-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<main>
  <div class="narrator-page">
    <form method="post" action="">
      <div class="narrator-card">
        <h2>Narrator Management</h2>
        <div class="toggle-row">
          <input type="checkbox" id="enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <label for="enabled">Enable Narrator Mode</label>
        </div>
        <div class="toggle-row">
          <input type="checkbox" id="welcome_enabled" name="welcome_enabled" value="1" <?= $welcomeEnabled ? 'checked' : '' ?>>
          <label for="welcome_enabled">Enable Welcome Narration</label>
        </div>
        <div class="toggle-row">
          <input type="checkbox" id="random_enabled" name="random_enabled" value="1" <?= $randomEnabled ? 'checked' : '' ?>>
          <label for="random_enabled">Enable Random Narration</label>
        </div>
        <div class="toggle-row">
          <input type="checkbox" id="inline_narration_enabled" name="inline_narration_enabled" value="1" <?= $inlineNarration ? 'checked' : '' ?>>
          <label for="inline_narration_enabled">Enable Inline Narration</label>
        </div>
        <div class="toggle-row">
          <input type="checkbox" id="diary_enabled" name="diary_enabled" value="1" <?= $diaryEnabled ? 'checked' : '' ?>>
          <label for="diary_enabled">Enable Narrator Diary Writes</label>
        </div>
        <div class="toggle-row">
          <input type="checkbox" id="dynamic_profile" name="dynamic_profile" value="1" <?= $dynamicProfile ? 'checked' : '' ?>>
          <label for="dynamic_profile">Enable Dynamic Profile</label>
        </div>
      </div>

      <div class="narrator-grid">
        <div class="narrator-card">
          <h2>Rules</h2>
          <label for="welcome_cooldown">Welcome Cooldown (minutes)</label>
          <input type="number" min="1" max="1440" id="welcome_cooldown" name="welcome_cooldown" value="<?= htmlspecialchars(strval($welcomeCooldown), ENT_QUOTES, 'UTF-8') ?>">
          <div class="help-text">Minimum time in minutes between welcome narrations. Range: 1-1440, default: 10.</div>

          <label for="random_chance">Random Narration Chance (%)</label>
          <input type="number" min="1" max="100" id="random_chance" name="random_chance" value="<?= htmlspecialchars(strval($randomChance), ENT_QUOTES, 'UTF-8') ?>">
          <div class="help-text">Probability (1-100) that the Narrator will interject with a scene description. Default: 15%.</div>

          <label for="random_cooldown">Random Narration Cooldown (events)</label>
          <input type="number" min="0" max="10" id="random_cooldown" name="random_cooldown" value="<?= htmlspecialchars(strval($randomCooldown), ENT_QUOTES, 'UTF-8') ?>">
          <div class="help-text">Minimum number of dialogue events between random narration lines. Range: 0-10, default: 2.</div>
        </div>

        <div class="narrator-card">
          <h2>Dynamic Profile Fields</h2>
          <div class="toggle-row">
            <input type="checkbox" id="dynamic_field_personality" name="dynamic_profile_fields[]" value="personality" <?= in_array('personality', $dynamicProfileFields, true) ? 'checked' : '' ?>>
            <label for="dynamic_field_personality">Personality</label>
          </div>
          <div class="toggle-row">
            <input type="checkbox" id="dynamic_field_speechstyle" name="dynamic_profile_fields[]" value="speechstyle" <?= in_array('speechstyle', $dynamicProfileFields, true) ? 'checked' : '' ?>>
            <label for="dynamic_field_speechstyle">Speech Style</label>
          </div>
          <div class="toggle-row">
            <input type="checkbox" id="dynamic_field_goals" name="dynamic_profile_fields[]" value="goals" <?= in_array('goals', $dynamicProfileFields, true) ? 'checked' : '' ?>>
            <label for="dynamic_field_goals">Goals</label>
          </div>
          <div class="help-text">These fields are only used when dynamic profile is enabled.</div>
        </div>
      </div>

      <div class="narrator-grid">
        <div class="narrator-card">
          <h2>Profile & Voice</h2>
          <label for="profile_id">Profile</label>
          <select id="profile_id" name="profile_id">
            <?php foreach ($profiles as $profile): ?>
              <?php $pid = intval($profile['id'] ?? 0); ?>
              <?php if ($pid <= 0) continue; ?>
              <option value="<?= htmlspecialchars(strval($pid), ENT_QUOTES, 'UTF-8') ?>" <?= $profileId === $pid ? 'selected' : '' ?>>
                <?= htmlspecialchars(strval($profile['label'] ?? ('Profile ' . $pid)), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="voiceid">Voice ID</label>
          <input type="text" id="voiceid" name="voiceid" value="<?= htmlspecialchars($voiceid, ENT_QUOTES, 'UTF-8') ?>">

          <label for="oghma_knowledge">World Knowledge Tags</label>
          <input type="text" id="oghma_knowledge" name="oghma_knowledge" value="<?= htmlspecialchars($oghmaKnowledge, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="narrator-card">
          <h2>Prompt</h2>
          <label for="prompt_head">Prompt Head Override</label>
          <textarea id="prompt_head" name="prompt_head"><?= htmlspecialchars($promptHead, ENT_QUOTES, 'UTF-8') ?></textarea>

          <label for="core">Core Summary</label>
          <textarea id="core" name="core"><?= htmlspecialchars($core, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="narrator-card">
        <h2>Character Description</h2>
        <label for="background">Background</label>
        <textarea id="background" name="background"><?= htmlspecialchars($background, ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="personality">Personality</label>
        <textarea id="personality" name="personality"><?= htmlspecialchars($personality, ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="speechstyle">Speech Style</label>
        <textarea id="speechstyle" name="speechstyle"><?= htmlspecialchars($speechstyle, ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="goals">Goals</label>
        <textarea id="goals" name="goals"><?= htmlspecialchars($goals, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <button class="save-btn" type="submit" name="save_narrator" value="1">Save Narrator Settings</button>
    </form>
  </div>
</main>

<?php
if (!$isEmbed) {
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/footer.html");
    $buffer = ob_get_contents();
    ob_end_clean();
    $title = $TITLE;
    $buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
    echo $buffer;
}
?>
