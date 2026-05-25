<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$is_starter   = isset($_GET['is_starter']) ? intval($_GET['is_starter']) === 1 : false;

if ($category_id === 0) {
    http_response_code(400);
    echo json_encode(["error" => "category_id is required"]);
    exit;
}

$table = $is_starter ? 'tbl_starter_teamschedule' : 'tbl_teamschedule';

// Get distinct arena_number values for this category.
$stmt = $conn->prepare(
    "SELECT DISTINCT ts.arena_number
     FROM {$table} ts
     INNER JOIN tbl_team t ON ts.team_id = t.team_id
     WHERE t.category_id = ?
     ORDER BY ts.arena_number ASC"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();

$arenas = [];
while ($row = $result->fetch_assoc()) {
    $num = (int)$row["arena_number"];
    $arenas[] = [
        "arena_id"     => $num,
        "arena_number" => $num,
        "arena_name"   => "Arena " . $num,
    ];
}

$stmt->close();
$conn->close();

echo json_encode($arenas);
?>