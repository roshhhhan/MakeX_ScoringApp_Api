<?php
// ─────────────────────────────────────────────────────────────────────
// get_score.php
// GET ?category_id=4                      (all rounds)
// GET ?category_id=4&bracket_type=final   (specific round)
// GET ?match_id=47                        (specific match)
//
// Returns score rows with bracket_type included so the scoring app
// can identify which round each score belongs to.
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");

require_once 'db_config.php';

$category_id  = isset($_GET['category_id'])  ? intval($_GET['category_id'])  : 0;
$match_id     = isset($_GET['match_id'])      ? intval($_GET['match_id'])      : 0;
$bracket_type = isset($_GET['bracket_type'])  ? trim($_GET['bracket_type'])   : '';

if ($match_id > 0) {
    // ── Fetch scores for a specific match ────────────────────────────
    $stmt = $conn->prepare("
        SELECT
            sc.score_id,
            sc.match_id,
            sc.team_id,
            t.team_name,
            sc.round_id,
            sc.score_independentscore,
            sc.score_violation,
            sc.score_totalscore,
            sc.score_totalduration,
            sc.score_isapproved,
              m.bracket_side AS bracket_type
        FROM tbl_score sc
        JOIN tbl_team  t ON t.team_id  = sc.team_id
        JOIN tbl_match m ON m.match_id = sc.match_id
        WHERE sc.match_id = ?
        ORDER BY sc.score_id ASC
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $match_id);

} elseif ($category_id > 0 && $bracket_type !== '') {
    // ── Fetch scores for a category + specific round ─────────────────
    $stmt = $conn->prepare("
        SELECT
            sc.score_id,
            sc.match_id,
            sc.team_id,
            t.team_name,
            sc.round_id,
            sc.score_independentscore,
            sc.score_violation,
            sc.score_totalscore,
            sc.score_totalduration,
            sc.score_isapproved,
                m.bracket_side AS bracket_type
        FROM tbl_score sc
        JOIN tbl_team  t ON t.team_id  = sc.team_id
        JOIN tbl_match m ON m.match_id = sc.match_id
        WHERE t.category_id  = ?
             AND m.bracket_side = ?
        ORDER BY sc.match_id ASC, sc.score_id ASC
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("is", $category_id, $bracket_type);

} elseif ($category_id > 0) {
    // ── Fetch all scores for a category ──────────────────────────────
    $stmt = $conn->prepare("
        SELECT
            sc.score_id,
            sc.match_id,
            sc.team_id,
            t.team_name,
            sc.round_id,
            sc.score_independentscore,
            sc.score_violation,
            sc.score_totalscore,
            sc.score_totalduration,
            sc.score_isapproved,
              m.bracket_side AS bracket_type
        FROM tbl_score sc
        JOIN tbl_team  t ON t.team_id  = sc.team_id
        JOIN tbl_match m ON m.match_id = sc.match_id
        WHERE t.category_id = ?
        ORDER BY sc.match_id ASC, sc.score_id ASC
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $category_id);

} else {
    http_response_code(400);
    echo json_encode(["error" => "Provide category_id or match_id"]);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'score_id'               => (int)$row['score_id'],
        'match_id'               => (int)$row['match_id'],
        'team_id'                => (int)$row['team_id'],
        'team_name'              => $row['team_name'],
        'round_id'               => (int)$row['round_id'],
        'score_independentscore' => (int)$row['score_independentscore'],
        'score_violation'        => (int)$row['score_violation'],
        'score_totalscore'       => (int)$row['score_totalscore'],
        'score_totalduration'    => $row['score_totalduration'],
        'score_isapproved'       => (int)$row['score_isapproved'],
        'bracket_type'           => $row['bracket_type'],
    ];
}

$stmt->close();
$conn->close();

echo json_encode($data);
?>