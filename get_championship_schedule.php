<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$competition = isset($_GET['competition']) ? trim($_GET['competition']) : '';
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$bracket = '';
if (isset($_GET['bracket']) && trim($_GET['bracket']) !== '') {
    $bracket = trim($_GET['bracket']);
} elseif (isset($_GET['bracket_side']) && trim($_GET['bracket_side']) !== '') {
    $bracket = trim($_GET['bracket_side']);
}

if (!$category_id) {
    echo json_encode([]);
    exit;
}

if ($competition === 'explorer' && $mode === 'championship') {
    $sql = "
        SELECT
            cs.match_id,
            cs.match_number,
            cs.match_round,
            cs.match_position,
            cs.bracket_side,
            cs.round_name,
            cs.status,
            cs.schedule_time,
            asel.captain_team_id AS team_id,
            t.team_name,
            0 AS referee_id,
            1 AS arena_number,
            cs.match_round AS round_id,
            cs.schedule_time AS match_time,
            cs.schedule_time AS schedule_start,
            '' AS schedule_end
        FROM tbl_explorer_championship_schedule cs
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cs.alliance1_id
        INNER JOIN tbl_team t ON t.team_id = asel.captain_team_id
        WHERE cs.category_id = ?
        UNION ALL
        SELECT
            cs.match_id,
            cs.match_number,
            cs.match_round,
            cs.match_position,
            cs.bracket_side,
            cs.round_name,
            cs.status,
            cs.schedule_time,
            asel.partner_team_id AS team_id,
            t.team_name,
            0 AS referee_id,
            1 AS arena_number,
            cs.match_round AS round_id,
            cs.schedule_time AS match_time,
            cs.schedule_time AS schedule_start,
            '' AS schedule_end
        FROM tbl_explorer_championship_schedule cs
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cs.alliance1_id
        INNER JOIN tbl_team t ON t.team_id = asel.partner_team_id
        WHERE cs.category_id = ?
        UNION ALL
        SELECT
            cs.match_id,
            cs.match_number,
            cs.match_round,
            cs.match_position,
            cs.bracket_side,
            cs.round_name,
            cs.status,
            cs.schedule_time,
            asel.captain_team_id AS team_id,
            t.team_name,
            0 AS referee_id,
            2 AS arena_number,
            cs.match_round AS round_id,
            cs.schedule_time AS match_time,
            cs.schedule_time AS schedule_start,
            '' AS schedule_end
        FROM tbl_explorer_championship_schedule cs
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cs.alliance2_id
        INNER JOIN tbl_team t ON t.team_id = asel.captain_team_id
        WHERE cs.category_id = ?
        UNION ALL
        SELECT
            cs.match_id,
            cs.match_number,
            cs.match_round,
            cs.match_position,
            cs.bracket_side,
            cs.round_name,
            cs.status,
            cs.schedule_time,
            asel.partner_team_id AS team_id,
            t.team_name,
            0 AS referee_id,
            2 AS arena_number,
            cs.match_round AS round_id,
            cs.schedule_time AS match_time,
            cs.schedule_time AS schedule_start,
            '' AS schedule_end
        FROM tbl_explorer_championship_schedule cs
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cs.alliance2_id
        INNER JOIN tbl_team t ON t.team_id = asel.partner_team_id
        WHERE cs.category_id = ?";
    $bracketPattern = '';
    if ($bracket !== '') {
        $b = strtolower(preg_replace('/[^a-z0-9]/', '', $bracket));
        // Use substring matching to be resilient to variations like "winners", "winners bracket", "winner", 'w', etc.
        if (strpos($b, 'win') !== false || $b === 'w') {
            $bracketPattern = '%win%';
        } elseif (strpos($b, 'los') !== false || $b === 'l') {
            $bracketPattern = '%los%';
        } elseif (strpos($b, 'grand') !== false || strpos($b, 'final') !== false || $b === 'g') {
            $bracketPattern = '%grand%';
        }
        if ($bracketPattern !== '') {
            // append bracket filter to each union branch (adds one extra ? per branch)
            $sql = str_replace("WHERE cs.category_id = ?", "WHERE cs.category_id = ? AND LOWER(cs.bracket_side) LIKE ?", $sql);
        }
    }

    $sql .= " ORDER BY match_number ASC, arena_number ASC, team_id ASC";

    $stmt = $conn->prepare($sql);
    if ($bracketPattern !== '') {
        // each branch has category_id and bracket pattern (int,string repeated)
        $stmt->bind_param('isisisis', $category_id, $bracketPattern, $category_id, $bracketPattern, $category_id, $bracketPattern, $category_id, $bracketPattern);
    } else {
        $stmt->bind_param('iiii', $category_id, $category_id, $category_id, $category_id);
    }
} else {
    echo json_encode(['error' => 'Invalid competition/mode', 'success' => false]);
    exit;
}

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->execute();

if ($stmt->errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'teamschedule_id' => 0,
        'match_id'        => (int)$row['match_id'],
        'match_number'    => (int)$row['match_number'],
        'match_round'     => (int)$row['match_round'],
        'match_position'  => (int)$row['match_position'],
        'bracket_side'    => $row['bracket_side'],
        'round_name'      => $row['round_name'],
        'status'          => $row['status'] ?? '',
        'team_id'         => (int)$row['team_id'],
        'team_name'       => $row['team_name'],
        'referee_id'      => (int)$row['referee_id'],
        'arena_number'    => (int)$row['arena_number'],
        'round_id'        => (int)$row['round_id'],
        'match_time'      => $row['match_time'] ?? '',
        'schedule_start'  => $row['schedule_start'] ?? '',
        'schedule_end'    => $row['schedule_end'] ?? '',
        'schedule_time'   => $row['schedule_time'] ?? '',
    ];
}

echo json_encode($rows);

$stmt->close();
$conn->close();
?>