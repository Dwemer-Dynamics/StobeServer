<?php
/**
 * StobeServer Diary Log.
 * Calendar/date-filtered view over diarylog with Kenshi calendar time.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

date_default_timezone_set("UTC");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function sanitizeInt(mixed $value, int $default): int
{
    $normalized = filter_var($value, FILTER_VALIDATE_INT);
    return ($normalized !== false) ? intval($normalized) : $default;
}

function parsePeopleList(mixed $rawPeople): string
{
    return implode(", ", parsePeopleArray($rawPeople));
}

function parsePeopleArray(mixed $rawPeople): array
{
    $text = trim(strval($rawPeople ?? ""));
    if ($text === "") {
        return [];
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $names = [];
        foreach ($decoded as $entry) {
            $entryText = trim(strval($entry));
            if ($entryText !== "") {
                $names[] = $entryText;
            }
        }
        if (count($names) > 0) {
            return array_values(array_unique($names));
        }
    }

    $clean = trim($text, "|() ");
    if (strpos($clean, "|") !== false) {
        $parts = array_filter(array_map("trim", explode("|", $clean)), static function ($item) {
            return $item !== "";
        });
        if (count($parts) > 0) {
            return array_values(array_unique($parts));
        }
    }

    return ($clean !== "") ? [$clean] : [];
}

function diaryRowMatchesPerson(array $row, string $selectedPerson): bool
{
    if ($selectedPerson === "") {
        return true;
    }

    foreach (parsePeopleArray($row["people"] ?? "") as $personName) {
        if (strcasecmp($personName, $selectedPerson) === 0) {
            return true;
        }
    }

    return false;
}

function processDiaryRow(array $row, bool $forCsv = false): array
{
    $timestamp = intval($row["localts"] ?? 0);
    $timeDisplay = "";
    if ($timestamp > 0) {
        $dt = new DateTime("@" . $timestamp);
        $dt->setTimezone(new DateTimeZone("UTC"));
        $timeDisplay = $dt->format("H:i:s - d-m-Y");
    }

    $people = parsePeopleList($row["people"] ?? "");
    $topic = trim(strval($row["topic"] ?? "Diary Entry"));
    if ($topic === "") {
        $topic = "Diary Entry";
    }
    $content = trim(strval($row["content"] ?? ""));
    if ($content === "") {
        $content = "(empty)";
    }
    $tags = trim(strval($row["tags"] ?? ""));

    $location = trim(strval($row["location"] ?? ""));
    if ($location === "") {
        $location = "Unknown";
    }
    $kenshiCalendar = stobeGametsDisplayWithRaw($row["gamets"] ?? 0);
    $locationAndCalendar = $location . " - " . $kenshiCalendar;

    if (!$forCsv) {
        $topic = h($topic);
        $content = h($content);
        $tags = h($tags);
        $people = h($people);
        $locationAndCalendar = h($locationAndCalendar);
        $timeDisplay = h($timeDisplay);
    }

    return [
        "rowid" => intval($row["rowid"] ?? 0),
        "Topic" => $topic,
        "Diary Entry" => $content,
        "Diary Entry Raw" => $forCsv ? $content : trim(strval($row["content"] ?? "")),
        "People" => $people,
        "Tags" => $tags,
        "Location & Kenshi Calendar" => $locationAndCalendar,
        "Time(UTC)" => $timeDisplay,
    ];
}

function renderCalendar(int $month, int $year, array $eventDates): string
{
    $daysOfWeek = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    $firstDayTimestamp = strtotime(sprintf("%04d-%02d-01 UTC", $year, $month));
    $firstDayOfWeek = intval(date("w", $firstDayTimestamp));
    $daysInMonth = intval(date("t", $firstDayTimestamp));

    $calendar = "<table class='calendar'>";
    $calendar .= "<tr>";
    foreach ($daysOfWeek as $dayName) {
        $calendar .= "<th>" . h($dayName) . "</th>";
    }
    $calendar .= "</tr><tr>";

    if ($firstDayOfWeek > 0) {
        for ($i = 0; $i < $firstDayOfWeek; $i++) {
            $calendar .= "<td></td>";
        }
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $currentDate = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $hasEvent = in_array($currentDate, $eventDates, true);
        $class = $hasEvent ? "has-event" : "";
        $link = "<a href='" . h(diaryUrl([
            "date" => $currentDate,
            "month" => strval($month),
            "year" => strval($year),
        ])) . "'>" . strval($day) . "</a>";
        $calendar .= "<td class='" . $class . "'>" . $link . "</td>";

        if ((($day + $firstDayOfWeek) % 7) === 0 && $day !== $daysInMonth) {
            $calendar .= "</tr><tr>";
        }
    }

    $lastDayOfWeek = intval(date("w", strtotime(sprintf("%04d-%02d-%02d UTC", $year, $month, $daysInMonth))));
    if ($lastDayOfWeek < 6) {
        for ($i = $lastDayOfWeek + 1; $i <= 6; $i++) {
            $calendar .= "<td></td>";
        }
    }

    $calendar .= "</tr></table>";
    return $calendar;
}

function buildDiaryRows(sql $db, ?int $startLocalTs = null, ?int $endLocalTs = null, string $selectedPerson = ""): array
{
    $params = [];
    $where = "1=1";
    $nextParam = 1;

    if ($startLocalTs !== null && $endLocalTs !== null) {
        $where .= " AND localts BETWEEN $" . strval($nextParam) . " AND $" . strval($nextParam + 1);
        $params[] = $startLocalTs;
        $params[] = $endLocalTs;
    }

    $query = "
        SELECT rowid, topic, content, tags, people, location, localts, gamets
        FROM diarylog
        WHERE {$where}
        ORDER BY localts ASC, rowid ASC
    ";

    try {
        $rows = $db->fetchAll($query, $params);
        if ($selectedPerson === "") {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($selectedPerson) {
            return diaryRowMatchesPerson($row, $selectedPerson);
        }));
    } catch (Throwable $exception) {
        return [];
    }
}

function buildPeopleFilterOptions(sql $db): array
{
    try {
        $rows = $db->fetchAll("SELECT people FROM diarylog WHERE COALESCE(TRIM(people), '') <> '' ORDER BY rowid ASC");
    } catch (Throwable $exception) {
        return [];
    }

    $counts = [];
    foreach ($rows as $row) {
        $people = parsePeopleArray($row["people"] ?? "");
        foreach ($people as $personName) {
            if (!isset($counts[$personName])) {
                $counts[$personName] = 0;
            }
            $counts[$personName]++;
        }
    }

    ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
    arsort($counts, SORT_NUMERIC);

    $options = [];
    foreach ($counts as $personName => $entryCount) {
        $options[] = [
            "name" => $personName,
            "count" => $entryCount,
        ];
    }

    return $options;
}

function exportCsvIfRequested(sql $db): void
{
    $exportType = trim(strval($_GET["export"] ?? ""));
    if ($exportType === "") {
        return;
    }

    $isSpecificDateExport = ($exportType === "csv" && isset($_GET["date"]));
    $isAllExport = ($exportType === "all_csv");
    if (!$isSpecificDateExport && !$isAllExport) {
        return;
    }

    $rows = [];
    $fileName = "diary_log_full.csv";
    $selectedPerson = trim(strval($_GET["person"] ?? ""));

    if ($isSpecificDateExport) {
        $selectedDate = trim(strval($_GET["date"] ?? ""));
        if (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $selectedDate) !== 1) {
            header("HTTP/1.1 400 Bad Request");
            echo "Invalid date format.";
            exit;
        }
        $dtStart = new DateTime($selectedDate . " 00:00:00", new DateTimeZone("UTC"));
        $startOfDay = $dtStart->getTimestamp();
        $dtEnd = clone $dtStart;
        $dtEnd->modify("+1 day")->modify("-1 second");
        $endOfDay = $dtEnd->getTimestamp();
        $rows = buildDiaryRows($db, $startOfDay, $endOfDay, $selectedPerson);
        $fileName = "diary_log_" . $selectedDate . ".csv";
    } else {
        $rows = buildDiaryRows($db, null, null, $selectedPerson);
        if ($selectedPerson !== "") {
            $safePerson = preg_replace('/[^A-Za-z0-9._-]+/', '_', $selectedPerson);
            $fileName = "diary_log_" . trim($safePerson, "_") . ".csv";
        }
    }

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $fileName);

    $output = fopen("php://output", "w");
    if ($output === false) {
        exit;
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ["Topic", "Diary Entry", "People", "Tags", "Location & Kenshi Calendar", "Time(UTC)"]);

    foreach ($rows as $row) {
        $processed = processDiaryRow($row, true);
        fputcsv($output, [
            $processed["Topic"],
            $processed["Diary Entry"],
            $processed["People"],
            $processed["Tags"],
            $processed["Location & Kenshi Calendar"],
            $processed["Time(UTC)"],
        ]);
    }

    fclose($output);
    exit;
}

function renderCsvButtons(): void
{
    $currentCsvParams = [];
    if (isset($_GET["date"])) {
        $currentCsvParams["date"] = strval($_GET["date"]);
    }
    if (isset($_GET["month"])) {
        $currentCsvParams["month"] = strval($_GET["month"]);
    }
    if (isset($_GET["year"])) {
        $currentCsvParams["year"] = strval($_GET["year"]);
    }
    if (isset($_GET["person"]) && trim(strval($_GET["person"])) !== "") {
        $currentCsvParams["person"] = trim(strval($_GET["person"]));
    }
    $currentCsvParams["export"] = "csv";

    $allCsvParams = [];
    if (isset($_GET["month"])) {
        $allCsvParams["month"] = strval($_GET["month"]);
    }
    if (isset($_GET["year"])) {
        $allCsvParams["year"] = strval($_GET["year"]);
    }
    if (isset($_GET["person"]) && trim(strval($_GET["person"])) !== "") {
        $allCsvParams["person"] = trim(strval($_GET["person"]));
    }
    $allCsvParams["export"] = "all_csv";
    $currentCsvHref = diaryUrl($currentCsvParams);
    $allCsvHref = diaryUrl($allCsvParams);
    ?>
    <div class="csv-buttons">
        <a href="<?= h($currentCsvHref) ?>" class="log-action-button">Download Current Date</a>
        <a href="<?= h($allCsvHref) ?>" class="log-action-button">Download Entire Diary Log</a>
    </div>
    <?php
}

function diaryUrl(array $params): string
{
    if (isset($_GET["embed"]) && strval($_GET["embed"]) === "1") {
        $params["embed"] = "1";
    }
    return "?" . http_build_query($params);
}

$scriptPath = $_SERVER["SCRIPT_NAME"] ?? "";
$uiPos = strpos($scriptPath, "/ui/");
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = "";
}
if ($webRoot === "/") {
    $webRoot = "";
}
$webRoot = rtrim($webRoot, "/");
$isEmbed = (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");

$db = $GLOBALS["db"];
exportCsvIfRequested($db);

$selectedPerson = trim(strval($_GET["person"] ?? ""));
$peopleFilterOptions = buildPeopleFilterOptions($db);
$selectedPersonKnown = false;
foreach ($peopleFilterOptions as $option) {
    if (strcasecmp($option["name"], $selectedPerson) === 0) {
        $selectedPerson = $option["name"];
        $selectedPersonKnown = true;
        break;
    }
}
if ($selectedPerson !== "" && !$selectedPersonKnown) {
    $selectedPerson = "";
}

$month = isset($_GET["month"]) && isset($_GET["year"])
    ? sanitizeInt($_GET["month"], intval(date("n")))
    : intval(date("n"));
$year = isset($_GET["month"]) && isset($_GET["year"])
    ? sanitizeInt($_GET["year"], intval(date("Y")))
    : intval(date("Y"));

$month = ($month >= 1 && $month <= 12) ? $month : intval(date("n"));
$year = ($year >= 1970 && $year <= 2100) ? $year : intval(date("Y"));

$dtStartOfMonth = new DateTime(sprintf("%04d-%02d-01 00:00:00", $year, $month), new DateTimeZone("UTC"));
$startOfMonth = $dtStartOfMonth->getTimestamp();
$dtEndOfMonth = clone $dtStartOfMonth;
$dtEndOfMonth->modify("+1 month")->modify("-1 second");
$endOfMonth = $dtEndOfMonth->getTimestamp();

$allEventDates = [];
foreach (buildDiaryRows($db, $startOfMonth, $endOfMonth, $selectedPerson) as $dateRow) {
    $timestamp = intval($dateRow["localts"] ?? 0);
    if ($timestamp > 0) {
        $allEventDates[] = gmdate("Y-m-d", $timestamp);
    }
}
$allEventDates = array_values(array_unique($allEventDates));

$selectedDate = isset($_GET["date"]) ? trim(strval($_GET["date"])) : date("Y-m-d");
if (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $selectedDate) !== 1) {
    $selectedDate = date("Y-m-d");
}

$dtSelected = new DateTime($selectedDate . " 00:00:00", new DateTimeZone("UTC"));
$startOfDay = $dtSelected->getTimestamp();
$dtSelectedEnd = clone $dtSelected;
$dtSelectedEnd->modify("+1 day")->modify("-1 second");
$endOfDay = $dtSelectedEnd->getTimestamp();

$rows = buildDiaryRows($db, $startOfDay, $endOfDay, $selectedPerson);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diary Log</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: <?= $isEmbed ? "10px" : "20px" ?>;
            padding-bottom: 40px;
            padding-left: 10px;
        }

        .calendar {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .calendar th, .calendar td {
            border: 1px solid #555555;
            padding: 10px;
            text-align: center;
            vertical-align: middle;
            position: relative;
        }

        .calendar td.has-event {
            background-color: #007bff;
        }

        .calendar td a {
            color: #ffffff;
            text-decoration: none;
            display: block;
            width: 100%;
            height: 100%;
        }

        .calendar td.has-event a:hover {
            background-color: #0056b3;
            color: #ffcc00;
        }

        .calendar-navigation {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
            gap: 15px;
        }

        .calendar-navigation a {
            padding: 8px 16px;
            color: #ffffff;
            text-decoration: none;
            background-color: #007bff;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .calendar-navigation a:hover {
            background-color: #0056b3;
            text-decoration: none;
        }

        .calendar-navigation span {
            color: #ffffff;
        }

        .csv-buttons {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            justify-content: center;
            flex-wrap: wrap;
        }

        .csv-buttons .button {
            margin: 0;
        }

        .filter-toolbar {
            display: flex;
            justify-content: center;
            margin: 20px auto;
            width: 100%;
        }

        .filter-panel {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 14px;
            width: 100%;
            max-width: 520px;
        }

        .filter-panel label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .filter-panel small {
            display: block;
            color: #c9c9c9;
            margin-top: 6px;
        }

        .filter-panel input,
        .filter-panel select {
            width: 100%;
            border-radius: 6px;
            border: 1px solid #555555;
            background: #151515;
            color: #ffffff;
            padding: 10px 12px;
        }
        .selected-filter {
            margin: 10px auto 0;
            max-width: 860px;
            color: #f8f9fa;
        }

        .selected-filter strong {
            color: #ffcc00;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        tbody tr {
            cursor: pointer;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .entry-preview {
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
            white-space: pre-wrap;
        }

        .diary-modal .modal-dialog {
            max-width: 980px;
        }

        .diary-modal .modal-content {
            background: #171717;
            color: #f8f9fa;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .diary-modal .modal-header,
        .diary-modal .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }

        .diary-modal .modal-body {
            padding: 24px;
        }

        .diary-modal-entry {
            white-space: pre-wrap;
            line-height: 1.75;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 18px;
        }

        th a {
            color: yellow;
        }

    </style>
<link rel="stylesheet" href="css/diary_adventure.css?v=<?= (int) @filemtime(__DIR__ . '/css/diary_adventure.css') ?>">
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="container">
    <div class="log-page-shell">
        <div class="page-header">
            <h1>Diary Log</h1>
            <p>Browse diary entries by Kenshi date or character and export the current view.</p>
        </div>

        <form method="get" class="filter-toolbar">
            <div class="filter-panel">
                <label for="person">Filter By Person</label>
                <input type="text" id="person" name="person" list="diary-people-list" value="<?= h($selectedPerson) ?>" placeholder="Start typing a name">
                <datalist id="diary-people-list">
                    <?php foreach ($peopleFilterOptions as $option): ?>
                        <option value="<?= h($option["name"]) ?>"><?= h($option["name"] . " (" . strval($option["count"]) . " entries)") ?></option>
                    <?php endforeach; ?>
                </datalist>
                <small><?= h(strval(count($peopleFilterOptions))) ?> people with diary entries.</small>
            </div>
            <input type="hidden" name="month" value="<?= h(strval($month)) ?>">
            <input type="hidden" name="year" value="<?= h(strval($year)) ?>">
            <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
            <?php if ($isEmbed): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
        </form>

        <?php if ($selectedPerson !== ""): ?>
            <div class="selected-filter">
                Showing diary entries involving <strong><?= h($selectedPerson) ?></strong>.
            </div>
        <?php endif; ?>

        <?php renderCsvButtons(); ?>

        <div class="calendar-navigation">
            <?php
            $prevMonth = $month - 1;
            $prevYear = $year;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }

            $nextMonth = $month + 1;
            $nextYear = $year;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
            }

            echo "<a href='" . h(diaryUrl([
                "month" => strval($prevMonth),
                "year" => strval($prevYear),
            ])) . "'>&laquo; <b>Previous Month</b></a>";
            $monthName = date("F", strtotime(sprintf("%04d-%02d-01 UTC", $year, $month)));
            echo "<span style='padding: 0 15px; color: #f8f9fa; font-size: 1.5em;'><b>" . h($monthName . " " . strval($year)) . "</b></span>";
            echo "<a href='" . h(diaryUrl([
                "month" => strval($nextMonth),
                "year" => strval($nextYear),
            ])) . "'><b>Next Month</b> &raquo;</a>";
            ?>
        </div>

        <?= renderCalendar($month, $year, $allEventDates) ?>

        <table class="event-table" id="event-table">
            <colgroup>
                <col style="width: 12%;">
                <col style="width: 34%;">
                <col style="width: 14%;">
                <col style="width: 10%;">
                <col style="width: 25%;">
                <col style="width: 5%;">
            </colgroup>
            <tr>
                <th>Topic</th>
                <th>Diary Entry</th>
                <th>People</th>
                <th>Tags</th>
                <th>Location & Kenshi Calendar</th>
                <th>Time(UTC)</th>
            </tr>
            <?php if (count($rows) === 0): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 24px;">
                        No diary entries found for this date<?= ($selectedPerson !== "") ? " and person" : "" ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php $processedRow = processDiaryRow($row, false); ?>
                    <?php
                    $modalPayload = [
                        "topic" => trim(strval($row["topic"] ?? "Diary Entry")),
                        "content" => trim(strval($row["content"] ?? "")),
                        "people" => parsePeopleList($row["people"] ?? ""),
                        "tags" => trim(strval($row["tags"] ?? "")),
                        "location" => trim(strval($row["location"] ?? "")),
                        "timeUtc" => $processedRow["Time(UTC)"],
                        "kenshiCalendar" => stobeGametsDisplayWithRaw($row["gamets"] ?? 0),
                    ];
                    $modalPayloadJson = h(json_encode($modalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr class="diary-row" data-entry="<?= $modalPayloadJson ?>">
                        <td><?= $processedRow["Topic"] ?></td>
                        <td><div class="entry-preview"><?= $processedRow["Diary Entry"] ?></div></td>
                        <td><?= $processedRow["People"] ?></td>
                        <td><?= $processedRow["Tags"] ?></td>
                        <td><?= $processedRow["Location & Kenshi Calendar"] ?></td>
                        <td><?= $processedRow["Time(UTC)"] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?php renderCsvButtons(); ?>
    </div>
</main>

<div class="modal fade diary-modal" id="diaryEntryModal" tabindex="-1" aria-labelledby="diaryEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="diaryEntryModalLabel">Diary Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="diary-modal-entry" id="diaryModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="log-action-button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const diaryEntryModalElement = document.getElementById('diaryEntryModal');
    const diaryEntryModal = diaryEntryModalElement ? new bootstrap.Modal(diaryEntryModalElement) : null;
    const personFilterInput = document.getElementById('person');
    const personFilterForm = personFilterInput ? personFilterInput.form : null;
    let personFilterSubmitTimer = null;

    function submitPersonFilter() {
        if (!personFilterForm) {
            return;
        }
        personFilterForm.submit();
    }

    if (personFilterInput && personFilterForm) {
        personFilterInput.addEventListener('input', () => {
            window.clearTimeout(personFilterSubmitTimer);
            personFilterSubmitTimer = window.setTimeout(submitPersonFilter, 350);
        });

        personFilterInput.addEventListener('change', () => {
            window.clearTimeout(personFilterSubmitTimer);
            submitPersonFilter();
        });

        personFilterInput.addEventListener('search', () => {
            window.clearTimeout(personFilterSubmitTimer);
            submitPersonFilter();
        });
    }

    document.querySelectorAll('.diary-row').forEach((row) => {
        row.addEventListener('click', () => {
            if (!diaryEntryModal) {
                return;
            }

            const rawPayload = row.getAttribute('data-entry') || '{}';
            let entry = {};
            try {
                entry = JSON.parse(rawPayload);
            } catch (error) {
                entry = {};
            }

            const contentElement = document.getElementById('diaryModalContent');
            if (contentElement) {
                contentElement.textContent = String(entry.content || '').trim() || '(empty)';
            }

            diaryEntryModal.show();
        });
    });
</script>
</body>
</html>

