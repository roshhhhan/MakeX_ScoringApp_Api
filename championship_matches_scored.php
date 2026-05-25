<?php
// ─────────────────────────────────────────────────────────────────────
// get_scored_championship_matches.php
// GET ?category_id=4
//
// Returns all match_id values that have at least one score entry in
// tbl_score for knockout rounds (elimination → final).
//
// Uses bracket_type from tbl_match instead of hardcoded match ID ranges,
// so it works correctly with auto-generated match IDs from the admin app.
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if ($category_id === 0) {
    http_response_code(400);
    echo json_encode(["error" => "category_id is required"]);
    exit;
}

// A match is considered scored when at least ONE tbl_score entry exists
// for that match_id, confirmed via tbl_team to belong to this category.
// Uses bracket_type to identify knockout rounds — no hardcoded match IDs.
$stmt = $conn->prepare("
    SELECT DISTINCT m.match_id, m.bracket_side AS bracket_type
    FROM tbl_score sc
    INNER JOIN tbl_match m ON m.match_id = sc.match_id
    INNER JOIN tbl_team  t ON t.team_id  = sc.team_id
    WHERE t.category_id = ?
    AND m.bracket_side IN (
          'elimination',
          'round-of-32',
          'round-of-16',
          'round-of-8',
          'quarter-finals',
          'semi-finals',
          'third-place',
          'final'
      )
    ORDER BY m.match_id ASC
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        "match_id"     => (int)$row["match_id"],
        "bracket_type" => $row["bracket_type"],
    ];
}

$stmt->close();
$conn->close();

echo json_encode($rows);
?>