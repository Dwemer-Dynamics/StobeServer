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
    $text = trim(strval($rawPeople ?? ""));
    if ($text === "") {
        return "";
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
            return implode(", ", $names);
        }
    }

    $clean = trim($text, "|() ");
    if (strpos($clean, "|") !== false) {
        $parts = array_filter(array_map("trim", explode("|", $clean)), static function ($item) {
            return $item !== "";
        });
        if (count($parts) > 0) {
            return implode(", ", $parts);
        }
    }

    return $clean;
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
        "Topic" => $topic,
        "Diary Entry" => $content,
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

function buildDiaryRows(sql $db, ?int $startLocalTs = null, ?int $endLocalTs = null): array
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
        SELECT topic, content, tags, people, location, localts, gamets
        FROM diarylog
        WHERE {$where}
        ORDER BY localts ASC, rowid ASC
    ";

    try {
        return $db->fetchAll($query, $params);
    } catch (Throwable $exception) {
        return [];
    }
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
        $rows = buildDiaryRows($db, $startOfDay, $endOfDay);
        $fileName = "diary_log_" . $selectedDate . ".csv";
    } else {
        $rows = buildDiaryRows($db);
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
    $currentCsvParams["export"] = "csv";

    $allCsvParams = [];
    if (isset($_GET["month"])) {
        $allCsvParams["month"] = strval($_GET["month"]);
    }
    if (isset($_GET["year"])) {
        $allCsvParams["year"] = strval($_GET["year"]);
    }
    $allCsvParams["export"] = "all_csv";
    $currentCsvHref = diaryUrl($currentCsvParams);
    $allCsvHref = diaryUrl($allCsvParams);
    ?>
    <div class="csv-buttons">
        <a href="<?= h($currentCsvHref) ?>" class="button">Download Current Date</a>
        <a href="<?= h($allCsvHref) ?>" class="button">Download Entire Diary Log</a>
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

$allDateParams = [$startOfMonth, $endOfMonth];
$allDates = [];
try {
    $allDates = $db->fetchAll(
        "SELECT DISTINCT TO_CHAR(TO_TIMESTAMP(localts::double precision) AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS event_date
         FROM diarylog
         WHERE localts BETWEEN $1 AND $2
         ORDER BY event_date ASC",
        $allDateParams
    );
} catch (Throwable $exception) {
    $allDates = [];
}
$allEventDates = [];
foreach ($allDates as $dateRow) {
    $dateValue = trim(strval($dateRow["event_date"] ?? ""));
    if ($dateValue !== "") {
        $allEventDates[] = $dateValue;
    }
}

$selectedDate = isset($_GET["date"]) ? trim(strval($_GET["date"])) : date("Y-m-d");
if (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $selectedDate) !== 1) {
    $selectedDate = date("Y-m-d");
}

$dtSelected = new DateTime($selectedDate . " 00:00:00", new DateTimeZone("UTC"));
$startOfDay = $dtSelected->getTimestamp();
$dtSelectedEnd = clone $dtSelected;
$dtSelectedEnd->modify("+1 day")->modify("-1 second");
$endOfDay = $dtSelectedEnd->getTimestamp();

$rows = buildDiaryRows($db, $startOfDay, $endOfDay);

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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        th a {
            color: yellow;
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div class="indent5">
        <h1>Diary Log</h1>
        <h2>All timestamps are UTC. Calendar labels use Kenshi game time (`gamets`).</h2>
        <h3>Filtered view of diary entries by day, with CSV export.</h3>

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

        <table>
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
            <?php foreach ($rows as $row): ?>
                <?php $processedRow = processDiaryRow($row, false); ?>
                <tr>
                    <td><?= $processedRow["Topic"] ?></td>
                    <td><?= $processedRow["Diary Entry"] ?></td>
                    <td><?= $processedRow["People"] ?></td>
                    <td><?= $processedRow["Tags"] ?></td>
                    <td><?= $processedRow["Location & Kenshi Calendar"] ?></td>
                    <td><?= $processedRow["Time(UTC)"] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php renderCsvButtons(); ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

