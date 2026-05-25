<?php
// ─────────────────────────────────────────────────────────────────────
// get_categories.php
// GET (no params) — returns all categories with available columns
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");

require_once 'db_config.php';

// First, check what columns exist in the table
$columns = [];
$colResult = $conn->query("SHOW COLUMNS FROM tbl_category");
if ($colResult) {
    while ($col = $colResult->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    $colResult->free();
}

// Build SELECT query based on existing columns
$selectFields = ['category_id', 'category_type'];

if (in_array('status', $columns)) {
    $selectFields[] = 'status';
}
if (in_array('access_code', $columns)) {
    $selectFields[] = 'access_code';
}

$sql = "SELECT " . implode(',', $selectFields) . " FROM tbl_category ORDER BY category_id ASC";
$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(["error" => "Query failed: " . $conn->error]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $item = [
        'category_id'   => (int)$row['category_id'],
        'category_type' => $row['category_type'],
    ];
    
    // Add status if it exists in the row
    if (isset($row['status'])) {
        $item['status'] = $row['status'];
    } else {
        $item['status'] = 'active'; // Default value
    }
    
    // Add access_code if it exists in the row
    if (isset($row['access_code'])) {
        $item['access_code'] = $row['access_code'];
    } else {
        $item['access_code'] = null;
    }
    
    $data[] = $item;
}

echo json_encode($data);
$conn->close();
?>