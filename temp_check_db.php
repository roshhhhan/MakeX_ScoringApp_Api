<?php
$conn = new mysqli('localhost', 'root', 'root', 'make_x');
if ($conn->connect_error) {
    echo 'ERR=' . $conn->connect_error;
    exit(1);
}
$res = $conn->query('SHOW TABLES LIKE "tbl_explorer_score"');
echo 'TABLES=' . ($res ? $res->num_rows : 0);
$res2 = $conn->query('SELECT COUNT(*) AS cnt FROM tbl_explorer_score');
if ($res2) {
    $row = $res2->fetch_assoc();
    echo ' COUNT=' . ($row['cnt'] ?? '0');
}
$conn->close();
