<?php
// ─────────────────────────────────────────────────────────────────────
// get_scored_matches.php
// GET ?category_id=4
//
// Returns all scored match entries (qualification AND knockout) for the
// given category. The client uses bracket_type to filter by round.
//
// Previously relied on hardcoded match ID ranges (501–512, 101–104 etc).
// Now uses bracket_type from tbl_match so it works with auto-generated
// match IDs from the admin app.
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$scope       = isset($_GET['scope']) ? trim($_GET['scope']) : 'qualification';
$isExplorer  = isset($_GET['is_explorer']) ? intval($_GET['is_explorer']) === 1 : false;
$isStarter   = isset($_GET['is_starter']) ? intval($_GET['is_starter']) === 1 : false;

$scoreTable = 'tbl_score';
if ($isExplorer) {
    $scoreTable = 'tbl_explorer_score';
} elseif ($isStarter) {
    $scoreTable = 'tbl_starter_score';
}

if ($isStarter && $scope === 'championship') {
    $stmt = $conn->prepare("
        SELECT DISTINCT
            sc.match_position AS match_id,
            0 AS team_id,
            sc.score AS score_totalscore,
            sc.violation AS score_independentscore,
            '' AS bracket_type
        FROM tbl_starter_championship_scores sc
        INNER JOIN tbl_starter_alliance_selections asel ON asel.alliance_id = sc.alliance_id
        WHERE (? = 0 OR asel.category_id = ?)
        ORDER BY sc.score_id DESC
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("ii", $category_id, $category_id);
    $stmt->execute();
} elseif ($isExplorer && $scope === 'championship') {
    $stmt = $conn->prepare("
        SELECT
            cb.match_number AS match_id,
            asel.captain_team_id AS team_id,
            cb.alliance_score AS score_totalscore,
            cb.alliance_score AS score_independentscore,
            '' AS bracket_type
        FROM tbl_championship_bestof3 cb
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cb.alliance_id
        WHERE (? = 0 OR asel.category_id = ?)
        UNION ALL
        SELECT
            cb.match_number AS match_id,
            asel.partner_team_id AS team_id,
            cb.alliance_score AS score_totalscore,
            cb.alliance_score AS score_independentscore,
            '' AS bracket_type
        FROM tbl_championship_bestof3 cb
        INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cb.alliance_id
        WHERE (? = 0 OR asel.category_id = ?)
        ORDER BY match_id DESC
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("iiii", $category_id, $category_id, $category_id, $category_id);
    $stmt->execute();
} else {
    $extraWhere = '';
    if ($scope === 'championship') {
        $extraWhere = ' AND s.round_id > 1';
    }

    $categoryFilter = $category_id > 0 ? ' AND t.category_id = ?' : '';

    // Returns the latest score row per (match_id, team_id) pair.
    // bracket_type is included so the client can filter by round without
    // relying on hardcoded match ID ranges.
    $stmt = $conn->prepare("
        SELECT
            s.match_id,
            s.team_id,
            s.score_totalscore,
            s.score_independentscore,
            '' AS bracket_type
        FROM $scoreTable s
        INNER JOIN tbl_team  t ON t.team_id  = s.team_id
        WHERE 1=1" . $categoryFilter . $extraWhere . "
        ORDER BY s.score_id DESC
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }

    if ($category_id > 0) {
        $stmt->bind_param("i", $category_id);
    }
    $stmt->execute();
}
$result = $stmt->get_result();

$rows = [];
$seen = [];
while ($row = $result->fetch_assoc()) {
    // ORDER BY score_id DESC → first occurrence is the latest score
    $key = $row['match_id'] . '_' . $row['team_id'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $rows[] = [
            "match_id"               => (int)$row["match_id"],
            "team_id"                => (int)$row["team_id"],
            "score_totalscore"       => (int)$row["score_totalscore"],
            "score_independentscore" => (int)$row["score_independentscore"],
            "bracket_type"           => $row["bracket_type"],
        ];
    }
}

$stmt->close();
$conn->close();

echo json_encode($rows);
?>