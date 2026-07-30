<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

function worldKnowledgeRulesH(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

function worldKnowledgeRuleEnabled(mixed $value): bool
{
    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');
$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';
$pageUrl = 'world_knowledge_rules.php' . ($isEmbed ? '?embed=1' : '');

$message = '';
$messageType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_context_rule'])) {
        $deleted = stobeWorldKnowledgeDeleteContextRule(
            $GLOBALS['db'],
            max(0, intval($_POST['context_rule_id'] ?? 0))
        );
        if ($deleted) {
            header('Location: ' . $pageUrl . ($isEmbed ? '&' : '?') . 'ok=deleted');
            exit;
        }
        $message = 'Unable to delete context rule.';
        $messageType = 'err';
    } elseif (isset($_POST['save_context_rule'])) {
        $result = stobeWorldKnowledgeSaveContextRule($GLOBALS['db'], $_POST);
        if (boolval($result['ok'] ?? false)) {
            $created = intval($_POST['context_rule_id'] ?? 0) <= 0;
            header('Location: ' . $pageUrl . ($isEmbed ? '&' : '?') . 'ok=' . ($created ? 'created' : 'updated'));
            exit;
        }
        $message = strval($result['message'] ?? 'Unable to save context rule.');
        $messageType = 'err';
    }
}

$ok = strtolower(trim(strval($_GET['ok'] ?? '')));
if ($message === '') {
    $message = match ($ok) {
        'created' => 'Context rule created.',
        'updated' => 'Context rule updated.',
        'deleted' => 'Context rule deleted.',
        default => '',
    };
}

$rules = stobeWorldKnowledgeLoadContextRules($GLOBALS['db'], false);
$fields = stobeWorldKnowledgeRuleConditionFields();
$TITLE = 'World Knowledge Context Rules';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . '../tmpl/head.html');
?>
<link rel="stylesheet" href="<?= worldKnowledgeRulesH($webRoot) ?>/ui/css/main.css">
<style>
main {
    padding: 30px 5px 40px;
    width: 100%;
    margin: 0;
}
.page-header,
.content-section {
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
    border: 1px solid #3a3a3a;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
}
.page-header {
    padding: 20px;
    text-align: center;
    margin-bottom: 20px;
}
.page-header h1 {
    margin: 0 0 8px;
    color: #fff;
    font-family: var(--stobe-title-font);
    font-size: 2.2em;
    word-spacing: 8px;
}
.page-header p,
.rule-help {
    margin: 0;
    color: #c9d3e5;
}
.content-section {
    padding: 20px;
    margin-bottom: 16px;
}
.rules-grid {
    display: block;
}
.rule-card {
    background: rgba(26, 26, 26, 0.55);
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 14px;
}
.rule-card h2 {
    color: #fff;
    font-family: var(--stobe-title-font);
    font-size: 1.35em;
    margin: 0 0 12px;
}
.rule-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px 12px;
}
.rule-field label {
    display: block;
    color: #e6b76c;
    font-weight: 700;
    margin-bottom: 4px;
}
.rule-field small {
    display: block;
    min-height: 34px;
    color: #9fb1c9;
    margin-bottom: 5px;
}
.rule-field input,
.rule-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 10px;
    border-radius: 6px;
    border: 1px solid #3a3a3a;
    background: rgba(26, 26, 26, 0.8);
    color: #e9efff;
}
.rule-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
}
.rule-enabled {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-right: auto;
    color: #e6b76c;
    font-weight: 700;
}
.rule-enabled input {
    margin: 0;
}
.ok { color: #8ee0a2; }
.err { color: #ffb862; }
.empty-state {
    color: #9fb1c9;
    text-align: center;
    padding: 20px;
}
@media (max-width: 1100px) {
    .rule-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 700px) {
    .rule-grid { grid-template-columns: 1fr; }
}
</style>
<?php
function renderWorldKnowledgeRuleForm(array $rule, array $fields, string $pageUrl, bool $isNew = false): void
{
    $conditions = stobeWorldKnowledgeNormalizeRuleConditions($rule['conditions'] ?? []);
    $enabled = $isNew || worldKnowledgeRuleEnabled($rule['enabled'] ?? false);
    $idPrefix = $isNew ? 'new_' : ('rule_' . intval($rule['id'] ?? 0) . '_');
    ?>
    <form method="post" action="<?= worldKnowledgeRulesH($pageUrl) ?>" class="rule-card">
        <input type="hidden" name="save_context_rule" value="1">
        <input type="hidden" name="context_rule_id" value="<?= intval($rule['id'] ?? 0) ?>">
        <h2><?= $isNew ? 'Create Context Rule' : worldKnowledgeRulesH($rule['label'] ?? 'Context Rule') ?></h2>
        <div class="rule-grid">
            <div class="rule-field">
                <label for="<?= $idPrefix ?>name">Rule Name</label>
                <small>Human-readable label included in retrieval audits.</small>
                <input id="<?= $idPrefix ?>name" type="text" name="context_rule_label" required value="<?= worldKnowledgeRulesH($rule['label'] ?? '') ?>">
            </div>
            <div class="rule-field">
                <label for="<?= $idPrefix ?>selector_type">Selector Type</label>
                <small>Choose an exact topic or a World Knowledge tag.</small>
                <select id="<?= $idPrefix ?>selector_type" name="context_rule_selector_type">
                    <option value="topic" <?= strval($rule['selector_type'] ?? 'topic') === 'topic' ? 'selected' : '' ?>>Exact Topic or Alias</option>
                    <option value="tag" <?= strval($rule['selector_type'] ?? '') === 'tag' ? 'selected' : '' ?>>Tag</option>
                </select>
            </div>
            <div class="rule-field">
                <label for="<?= $idPrefix ?>selector_value">Selector Value</label>
                <small>Exact topic, alias, or tag to insert when this rule matches.</small>
                <input id="<?= $idPrefix ?>selector_value" type="text" name="context_rule_selector_value" required value="<?= worldKnowledgeRulesH($rule['selector_value'] ?? '') ?>">
            </div>
            <div class="rule-field">
                <label for="<?= $idPrefix ?>priority">Priority</label>
                <small>Lower numbers are evaluated first.</small>
                <input id="<?= $idPrefix ?>priority" type="number" name="context_rule_priority" min="-100000" max="100000" value="<?= intval($rule['priority'] ?? 100) ?>">
            </div>
            <div class="rule-field">
                <label for="<?= $idPrefix ?>max_articles">Maximum Articles</label>
                <small>Limits this selector to between one and five articles.</small>
                <input id="<?= $idPrefix ?>max_articles" type="number" name="context_rule_max_articles" min="1" max="5" value="<?= max(1, min(5, intval($rule['max_articles'] ?? 1))) ?>">
            </div>
            <?php foreach ($fields as $field => [$label, $description]): ?>
                <div class="rule-field">
                    <label for="<?= $idPrefix . worldKnowledgeRulesH($field) ?>"><?= worldKnowledgeRulesH($label) ?></label>
                    <small><?= worldKnowledgeRulesH($description) ?> Separate alternatives with commas.</small>
                    <input id="<?= $idPrefix . worldKnowledgeRulesH($field) ?>" type="text" name="condition_<?= worldKnowledgeRulesH($field) ?>" value="<?= worldKnowledgeRulesH(implode(', ', $conditions[$field] ?? [])) ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <div class="rule-actions">
            <label class="rule-enabled">
                <input type="checkbox" name="context_rule_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                Enabled
            </label>
            <button type="submit" class="btn-save"><?= $isNew ? 'Create Rule' : 'Save Rule' ?></button>
            <?php if (!$isNew): ?>
                <button type="submit" name="delete_context_rule" value="1" class="btn-danger" onclick="return confirm('Delete this context rule?');">Delete</button>
            <?php endif; ?>
        </div>
    </form>
    <?php
}
?>
<main>
    <div class="page-header">
        <h1>Context Rules</h1>
        <p>Insert specific World Knowledge when the current Kenshi scene matches deterministic conditions.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="content-section <?= $messageType === 'err' ? 'err' : 'ok' ?>"><?= worldKnowledgeRulesH($message) ?></div>
    <?php endif; ?>

    <section class="content-section">
        <p class="rule-help">Every populated condition on a rule must match. Comma-separated values within one condition are alternatives. Leave all conditions blank for an always-on rule. Normal scored retrieval and the Always Insert settings continue to work alongside these rules.</p>
    </section>

    <div class="rules-grid">
        <?php
        renderWorldKnowledgeRuleForm([
            'priority' => 100,
            'selector_type' => 'topic',
            'max_articles' => 1,
            'conditions' => [],
        ], $fields, $pageUrl, true);
        foreach ($rules as $rule) {
            renderWorldKnowledgeRuleForm($rule, $fields, $pageUrl, false);
        }
        ?>
    </div>
    <?php if (count($rules) === 0): ?>
        <div class="empty-state">No context rules have been created.</div>
    <?php endif; ?>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . '../tmpl/footer.html');
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;
?>
