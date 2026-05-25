<?php
// ─────────────────────────────────────────────────────────────────────
// get_teamschedule.php
// GET ?category_id=4
// GET ?category_id=4&bracket_type=quarter-finals   (optional filter)
//
// Returns all team schedule entries for the given category.
// bracket_type is now included in every row so the scoring app can
// identify which round each match belongs to without hardcoded IDs.
//
// Optional bracket_type filter:
//   group, elimination, round-of-32, round-of-16, round-of-8,
//   quarter-finals, semi-finals, third-place, final
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

require_once 'db_config.php';

$cat_id       = isset($_GET['category_id'])  ? intval($_GET['category_id'])      : 0;
$arena_number = isset($_GET['arena_number']) ? intval($_GET['arena_number'])    : 0;
$bracket_type = isset($_GET['bracket_type']) ? trim($_GET['bracket_type'])      : '';
$is_starter   = isset($_GET['is_starter']) ? intval($_GET['is_starter']) === 1 : false;
$is_explorer  = isset($_GET['is_explorer']) ? intval($_GET['is_explorer']) === 1 : false;

if (!$cat_id) {
    echo json_encode(["error" => "No category selected"]);
    exit();
}

if ($is_explorer) {
    $table = 'tbl_explorer_teamschedule';
} elseif ($is_starter) {
    $table = 'tbl_starter_teamschedule';
} else {
    $table = 'tbl_teamschedule';
}

$sql = "
    SELECT
        ts.teamschedule_id,
        ts.match_id,
        ts.match_id        AS match_number,
        ts.team_id,
        t.team_name,
        ts.referee_id,
        ts.arena_number,
        ts.round_id,
        COALESCE(r.round_type, '') AS round_type,
        TIME_FORMAT(s.schedule_start, '%H:%i') AS match_time,
        s.schedule_start,
        s.schedule_end
    FROM {$table} ts
    INNER JOIN tbl_team     t  ON t.team_id      = ts.team_id
    INNER JOIN tbl_match    m  ON m.match_id     = ts.match_id
    INNER JOIN tbl_schedule s  ON s.schedule_id  = m.schedule_id
    LEFT  JOIN tbl_round    r  ON r.round_id     = ts.round_id
    WHERE t.category_id = ?";

if ($arena_number > 0) {
    $sql .= "\n      AND ts.arena_number = ?";
}
if ($bracket_type !== '') {
    $sql .= "\n      AND r.round_type = ?";
}
$sql .= "\n    ORDER BY s.schedule_start ASC, ts.match_id ASC, ts.teamschedule_id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit();
}

if ($arena_number > 0 && $bracket_type !== '') {
    $stmt->bind_param("iis", $cat_id, $arena_number, $bracket_type);
} elseif ($arena_number > 0) {
    $stmt->bind_param("ii", $cat_id, $arena_number);
} elseif ($bracket_type !== '') {
    $stmt->bind_param("is", $cat_id, $bracket_type);
} else {
    $stmt->bind_param("i", $cat_id);
}

$stmt->execute();

if ($stmt->errno) {
    echo json_encode(["error" => "Execute failed: " . $stmt->error]);
    exit();
}

$result   = $stmt->get_result();
$schedule = [];
$grouped = [];

while ($row = $result->fetch_assoc()) {
    if ($is_explorer) {
        $matchId = (int)$row['match_id'];
        $roundId = (int)$row['round_id'];
        $key = $matchId . '_' . $roundId;

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'match_id'       => $matchId,
                'round_id'       => $roundId,
                'match_number'   => (int)$row['match_number'],
                'round_type'     => $row['round_type'] ?? '',
                'match_time'     => $row['match_time'] ?? '',
                'schedule_start' => $row['schedule_start'] ?? '',
                'schedule_end'   => $row['schedule_end'] ?? '',
                'teams'          => [],
            ];
        }

        $arenaNumber = (int)$row['arena_number'];
        $grouped[$key]['teams'][] = [
            'team_id'      => (int)$row['team_id'],
            'team_name'    => $row['team_name'],
            'referee_id'   => (int)$row['referee_id'],
            'arena_number' => $arenaNumber,
            'alliance'     => $arenaNumber === 1 ? 'RED' : ($arenaNumber === 2 ? 'BLUE' : ''),
        ];
    } else {
        $schedule[] = [
            'teamschedule_id' => (int)$row['teamschedule_id'],
            'match_id'        => (int)$row['match_id'],
            'match_number'    => (int)$row['match_number'],
            'team_id'         => (int)$row['team_id'],
            'team_name'       => $row['team_name'],
            'referee_id'      => (int)$row['referee_id'],
            'arena_number'    => (int)$row['arena_number'],
            'round_id'        => (int)$row['round_id'],
            'round_type'      => $row['round_type'] ?? '',
            'match_time'      => $row['match_time'] ?? '',
            'schedule_start'  => $row['schedule_start'] ?? '',
            'schedule_end'    => $row['schedule_end'] ?? '',
        ];
    }
}

if ($is_explorer) {
    $schedule = array_values($grouped);
}

echo json_encode($schedule);

$stmt->close();
$conn->close();
?>