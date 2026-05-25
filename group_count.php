<?php
// ─────────────────────────────────────────────────────────────────────
// get_group_count.php
// GET ?category_id=4
//
// Returns the number of distinct groups for a category.
// Used by championship_sched.dart to pick the correct bracket flow:
//
//   2 grp → 4 teams  → SF  → 3RD → FINAL
//   3 grp → 6 teams  → ELIM(3) → QF → SF → FINAL
//   4 grp → 8 teams  → QF  → SF  → 3RD → FINAL
//   5 grp → 10 teams → ELIM(2) → QF → SF → FINAL
//   6 grp → 12 teams → ELIM(4) → QF → SF → FINAL
//   7 grp → 14 teams → ELIM(6) → QF → SF → FINAL
//   8 grp → 16 teams → R16(8)  → QF → SF → FINAL
//   9 grp → 18 teams → ELIM(2) → R16(8) → QF → SF → FINAL
//
// The admin app stores group info in tbl_group (or equivalent).
// Adjust the query below if your schema uses a different table/column.
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
    echo json_encode(['error' => 'category_id is required']);
    exit;
}

// ── Query: count distinct group_label values in tbl_soccer_groups ────
// tbl_soccer_groups columns: id, category_id, group_label, team_id, team_name, created_at
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT group_label) AS group_count
     FROM tbl_soccer_groups
     WHERE category_id = ?"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('i', $category_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$count = (int)($row['group_count'] ?? 0);

echo json_encode(['group_count' => $count]);
?>