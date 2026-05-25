<?php
// get_starter_championship_schedule.php
// Endpoint for Scoring App to fetch Starter Championship matches
// Called by scoring app with: ?category_id=X

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if (!$category_id) {
    echo json_encode(['error' => 'category_id is required', 'success' => false]);
    exit;
}

// Verify this is a Starter category
$catCheck = $conn->query("SELECT category_type FROM tbl_category WHERE category_id = $category_id");
$isStarter = false;
if ($catCheck && $row = $catCheck->fetch_assoc()) {
    $isStarter = strpos(strtolower($row['category_type']), 'starter') !== false;
}

if (!$isStarter) {
    echo json_encode(['error' => 'Not a starter category', 'success' => false]);
    exit;
}

// Fetch championship schedule for Starter category
// Returns individual team rows (4 rows per match: 2 teams in alliance1 + 2 teams in alliance2)
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
        cs.schedule_time AS schedule_start,
        '' AS schedule_end
    FROM tbl_starter_championship_schedule cs
    INNER JOIN tbl_starter_alliance_selections asel ON asel.alliance_id = cs.alliance1_id
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
        cs.schedule_time AS schedule_start,
        '' AS schedule_end
    FROM tbl_starter_championship_schedule cs
    INNER JOIN tbl_starter_alliance_selections asel ON asel.alliance_id = cs.alliance1_id
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
        cs.schedule_time AS schedule_start,
        '' AS schedule_end
    FROM tbl_starter_championship_schedule cs
    INNER JOIN tbl_starter_alliance_selections asel ON asel.alliance_id = cs.alliance2_id
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
        cs.schedule_time AS schedule_start,
        '' AS schedule_end
    FROM tbl_starter_championship_schedule cs
    INNER JOIN tbl_starter_alliance_selections asel ON asel.alliance_id = cs.alliance2_id
    INNER JOIN tbl_team t ON t.team_id = asel.partner_team_id
    WHERE cs.category_id = ?
    
    ORDER BY match_number ASC, arena_number ASC, team_id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('iiii', $category_id, $category_id, $category_id, $category_id);
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
        'match_time'      => $row['schedule_time'] ?? '',
        'schedule_start'  => $row['schedule_start'] ?? '',
        'schedule_end'    => $row['schedule_end'] ?? '',
        'schedule_time'   => $row['schedule_time'] ?? '',
    ];
}

echo json_encode($rows);

$stmt->close();
$conn->close();
?>