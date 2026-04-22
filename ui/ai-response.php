<?php
/**
 * StobeServer AI Responses.
 * Herika-style response log page backed by persisted DB log rows.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function formatLocalTs(mixed $value): string
{
    $ts = intval($value ?? 0);
    if ($ts <= 0) {
        return "";
    }
    $dt = new DateTime("@" . $ts);
    $dt->setTimezone(new DateTimeZone("UTC"));
    return $dt->format("d-m-Y H:i:s");
}

function safeFetchAll(sql $db, string $query, array $params = []): array
{
    try {
        return $db->fetchAll($query, $params);
    } catch (Throwable $exception) {
        return [];
    }
}

function safeFetchOne(sql $db, string $query, array $params = []): array|false
{
    try {
        return $db->fetchOne($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function safeExec(sql $db, string $query, array $params = []): bool
{
    try {
        return (bool)$db->exec($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function normalizeAiResponseLogMarkup(mixed $value): string
{
    if ($value === null) {
        return "";
    }

    $text = strval($value);
    $text = str_replace(["<br />", "<br>", "<br/>"], "\n", $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    return trim($text);
}

function extractWorldKnowledgeTopics(mixed $rawPromptValue): array
{
    $text = normalizeAiResponseLogMarkup($rawPromptValue);
    if ($text === "") {
        return [];
    }

    $topics = [];
    $seen = [];

    if (preg_match_all('/<knowledge>\s*(.*?)\s*<\/knowledge>/is', $text, $knowledgeMatches) < 1) {
        return [];
    }

    foreach ($knowledgeMatches[1] as $knowledgeBlock) {
        if (!is_string($knowledgeBlock) || trim($knowledgeBlock) === "") {
            continue;
        }

        if (preg_match_all('/<entry>\s*(.*?)\s*<\/entry>/is', $knowledgeBlock, $entryMatches) < 1) {
            continue;
        }

        foreach ($entryMatches[1] as $entryText) {
            $entry = trim(html_entity_decode(strip_tags(strval($entryText)), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"));
            if ($entry === "") {
                continue;
            }

            $topic = $entry;
            $colonPos = strpos($entry, ":");
            if ($colonPos !== false) {
                $topic = trim(substr($entry, 0, $colonPos));
            }

            if ($topic === "") {
                continue;
            }

            $topicKey = strtolower($topic);
            if (isset($seen[$topicKey])) {
                continue;
            }

            $seen[$topicKey] = true;
            $topics[] = $topic;
        }
    }

    return $topics;
}

function formatWorldKnowledgeTopics(mixed $rawPromptValue): string
{
    $topics = extractWorldKnowledgeTopics($rawPromptValue);
    if (count($topics) === 0) {
        return "None";
    }

    return implode(", ", $topics);
}

$db = $GLOBALS["db"];
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 50;
$limit = max(10, min(500, $limit));
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

if (isset($_GET["cleanlog"]) && $_GET["cleanlog"]) {
    safeExec($db, "DELETE FROM log");
    header("Location: ai-response.php");
    exit;
}

$rows = safeFetchAll(
    $db,
    "SELECT rowid, localts, prompt, response, url
     FROM log
     ORDER BY localts DESC, rowid DESC
     LIMIT $1 OFFSET $2",
    [$limit, $offset]
);

$totalRow = safeFetchOne($db, "SELECT COUNT(*) AS total FROM log");
$totalRecords = intval($totalRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));

if (isset($_GET["export"]) && $_GET["export"] === "1") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"stobe_ai_responses.csv\"");
    $out = fopen("php://output", "w");
    if ($out !== false) {
        fputcsv($out, ["rowid", "time_utc", "response", "world_knowledge", "url", "prompt"]);
        foreach ($rows as $row) {
            fputcsv($out, [
                intval($row["rowid"] ?? 0),
                formatLocalTs($row["localts"] ?? 0),
                strval($row["response"] ?? ""),
                formatWorldKnowledgeTopics($row["prompt"] ?? ""),
                strval($row["url"] ?? ""),
                strval($row["prompt"] ?? ""),
            ]);
        }
        fclose($out);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Responses</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        body {
            padding-top: 80px;
        }

        main {
            padding-top: 20px;
            padding-bottom: 40px;
            padding-left: 10px;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1.5px;
        }

        .tab-container {
            margin: 20px 0;
        }

        .tab-buttons {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.2);
            gap: 5px;
            word-spacing: 5px;
        }

        .tab-button {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.8), rgba(34, 34, 34, 0.9));
            border: 2px solid #3a3a3a;
            border-bottom: none;
            padding: 12px 18px;
            color: #f8f9fa;
            cursor: pointer;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            transition: all 0.3s ease;
            font-size: 1em;
            white-space: nowrap;
            font-family: "MagicCards", sans-serif;
            word-spacing: 5px;
            letter-spacing: 1.5px;
            margin-bottom: -2px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
        }

        .tab-button:hover {
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 1));
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-decoration: none;
        }

        .tab-button.active {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-color: rgba(230, 183, 108, 0.5);
            border-bottom: 2px solid rgba(42, 42, 42, 0.95);
            color: #e6b76c;
            box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
        }

        .tab-content {
            display: block;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 8px;
            border-top-left-radius: 0;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .table-container {
            max-height: calc(100vh - 450px) !important;
            margin-top: 20px;
            width: 100%;
            overflow-x: auto;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: small;
        }

        th {
            padding: 12px;
            font-weight: bold;
            text-align: left;
            color: #e6b76c;
            background: rgba(26, 26, 26, 0.6);
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
        }

        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(74, 74, 74, 0.3);
            color: #f8f9fa;
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: top;
            line-height: 1.5;
        }

        tr:hover td {
            background: rgba(230, 183, 108, 0.05);
        }

        .pagination-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .info-message {
            color: #9ca3af;
            padding: 14px 2px;
        }

        .view-contents-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            margin: 2px;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .view-contents-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.4);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .modal-content {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98));
            margin: 3% auto;
            padding: 20px;
            border: 2px solid rgba(230, 183, 108, 0.5);
            width: 90%;
            max-width: 1600px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 10px;
            color: #fff;
            position: relative;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: sticky;
            z-index: 1;
        }

        .close:hover,
        .close:focus {
            color: #fff;
            text-decoration: none;
        }

        #modalText {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.8;
            padding: 20px;
            font-size: 13px;
            font-family: "Consolas", "Monaco", "Courier New", monospace;
            background: #1a1a1a;
            border-radius: 8px;
            color: #e0e0e0;
        }

        body.modal-open {
            overflow: hidden;
        }

        @media (max-width: 768px) {
            .table-container {
                margin: 10px -15px;
                border-radius: 0;
            }
            table {
                font-size: smaller;
            }
            th, td {
                padding: 8px;
            }
            .tab-button {
                padding: 10px 14px;
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>

<div id="contentModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0; color: #e6b76c; font-family: 'MagicCards', sans-serif;">Prompt Viewer</h2>
            <div>
                <button id="copyPromptBtn" class="btn-base btn-primary" style="margin-right: 10px; padding: 8px 16px;">Copy</button>
                <span class="close">&times;</span>
            </div>
        </div>
        <div id="modalText"></div>
    </div>
</div>

<main class="container-fluid">
    <div class="tab-container">
        <div class="tab-buttons">
            <a class="tab-button" href="events.php">&#x1F4DD; Events</a>
            <a class="tab-button active" href="ai-response.php">&#x1F916; AI Responses</a>
            <a class="tab-button" href="memories.php">&#x1F9E0; Memories</a>
        </div>

        <div id="responselog-tab" class="tab-content">
            <div style="background: #2a2a2a; border-left: 4px solid #e6b76c; padding: 12px 15px; border-radius: 5px; margin: 15px 0; font-size: 0.9em;">
                <span style="color: #e6b76c; font-weight: bold;">AI Responses:</span>
                <span style="color: #f8f9fa;">Complete log of AI-generated responses including the full context payload sent to the LLM. Use this to debug model behavior and prompt composition.</span>
            </div>

            <div class="pagination-row" style="margin-bottom: 10px;">
                <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
                <?php if ($page > 1): ?>
                    <a class="btn-base btn-primary" href="ai-response.php?page=<?= intval($page - 1) ?>&limit=<?= intval($limit) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-base btn-primary" href="ai-response.php?page=<?= intval($page + 1) ?>&limit=<?= intval($limit) ?>">Next</a>
                <?php endif; ?>
                <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap;">
                    <button onclick="if(confirm('This will clear all the entries in the Response Log. ARE YOU SURE?')) window.location.href='ai-response.php?cleanlog=true'" class="btn-base btn-danger" style="padding: 8px 12px; font-size: 0.9em;">Clean Response Log</button>
                    <button onclick="window.open('ai-response.php?export=1&limit=<?= intval($limit) ?>&page=<?= intval($page) ?>', '_blank')" class="btn-base btn-primary" style="padding: 8px 12px; font-size: 0.9em;">Export Response Log</button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                    <tr>
                        <th style="width:12%">Time (UTC)</th>
                        <th style="width:34%">AI Response</th>
                        <th style="width:18%">World Knowledge</th>
                        <th style="width:14%">Prompt</th>
                        <th style="width:22%">HTTP Request</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $promptId = "prompt_" . intval($row["rowid"] ?? 0);
                            $promptRaw = trim((string)($row["prompt"] ?? ""));
                            if ($promptRaw === "") {
                                $promptRaw = "Prompt payload is empty for this row.";
                            }
                            $worldKnowledgeDisplay = formatWorldKnowledgeTopics($row["prompt"] ?? "");
                            ?>
                            <tr>
                                <td><?= h(formatLocalTs($row["localts"] ?? 0)) ?></td>
                                <td><?= nl2br(h($row["response"] ?? "")) ?></td>
                                <td><?= nl2br(h($worldKnowledgeDisplay)) ?></td>
                                <td>
                                    <div id="<?= h($promptId) ?>" style="display:none;">
                                        <pre style="white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, monospace;"><?= h($promptRaw) ?></pre>
                                    </div>
                                    <button class="view-contents-btn" data-prompt-id="<?= h($promptId) ?>">View Prompt</button>
                                </td>
                                <td><?= nl2br(h($row["url"] ?? "")) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No AI response rows found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-row">
                <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
                <?php if ($page > 1): ?>
                    <a class="btn-base" href="ai-response.php?page=<?= intval($page - 1) ?>&limit=<?= intval($limit) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-base" href="ai-response.php?page=<?= intval($page + 1) ?>&limit=<?= intval($limit) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("contentModal");
    const modalText = document.getElementById("modalText");
    const closeBtn = document.getElementsByClassName("close")[0];
    const copyBtn = document.getElementById("copyPromptBtn");

    if (closeBtn) {
        closeBtn.onclick = function() {
            modal.style.display = "none";
            document.body.classList.remove("modal-open");
        };
    }

    window.onclick = function(event) {
        if (event.target === modal) {
            modal.style.display = "none";
            document.body.classList.remove("modal-open");
        }
    };

    if (copyBtn) {
        copyBtn.onclick = function() {
            const textToCopy = modalText.innerText || modalText.textContent || "";
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(function() {
                    const originalText = copyBtn.innerHTML;
                    copyBtn.innerHTML = "Copied!";
                    copyBtn.style.background = "#28a745";
                    setTimeout(function() {
                        copyBtn.innerHTML = originalText;
                        copyBtn.style.background = "";
                    }, 1500);
                }).catch(function() {
                    alert("Failed to copy to clipboard");
                });
            } else {
                const textArea = document.createElement("textarea");
                textArea.value = textToCopy;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand("copy");
                    const originalText = copyBtn.innerHTML;
                    copyBtn.innerHTML = "Copied!";
                    copyBtn.style.background = "#28a745";
                    setTimeout(function() {
                        copyBtn.innerHTML = originalText;
                        copyBtn.style.background = "";
                    }, 1500);
                } catch (err) {
                    alert("Failed to copy to clipboard");
                }
                document.body.removeChild(textArea);
            }
        };
    }

    document.querySelectorAll(".view-contents-btn").forEach(function(element) {
        element.addEventListener("click", function() {
            const promptId = this.getAttribute("data-prompt-id");
            const promptDiv = document.getElementById(promptId);
            if (promptDiv) {
                modalText.innerHTML = promptDiv.innerHTML;
            } else {
                modalText.innerHTML = "Prompt not found.";
            }
            modal.style.display = "block";
            document.body.classList.add("modal-open");
        });
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

