<?php
header('Content-Type: application/json');
require_once 'db_config.php';

$category_id = intval($_GET['category_id'] ?? 0);
if (!$category_id) { echo json_encode([]); exit; }

$stmt = $conn->prepare("
    SELECT m.match_id, m.bracket_side AS bracket_type
    FROM tbl_match m
    WHERE m.bracket_side NOT IN ('group', '')
      AND (
          -- matches that already have teams assigned for this category
          EXISTS (
              SELECT 1
              FROM tbl_teamschedule ts
              INNER JOIN tbl_team t ON t.team_id = ts.team_id
              WHERE ts.match_id  = m.match_id
                AND t.category_id = ?
          )
          OR
          -- empty shell matches (no team assigned yet) linked to this category's schedule
          (
              NOT EXISTS (
                  SELECT 1 FROM tbl_teamschedule ts2
                  WHERE ts2.match_id = m.match_id
              )
              AND EXISTS (
                  SELECT 1 FROM tbl_schedule s
                  INNER JOIN tbl_match m2 ON m2.schedule_id = s.schedule_id
                  INNER JOIN tbl_teamschedule ts3 ON ts3.match_id = m2.match_id
                  INNER JOIN tbl_team t3 ON t3.team_id = ts3.team_id
                  WHERE t3.category_id = ?
                    AND s.schedule_id  = m.schedule_id
              )
          )
      )
    ORDER BY m.match_id ASC
");
$stmt->bind_param('ii', $category_id, $category_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
echo json_encode($rows);