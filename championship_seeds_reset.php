<?php
// cleanup_championship_seeds.php
//
// DELETE { team_ids: [id, id], category_id }
//   Removes those teams from any UNSCORED knockout tbl_teamschedule slots.
//   Called by qualification_sched.dart before re-seeding after a re-score,
//   so the old winner's slot is cleared before the new winner is inserted.
//   Only removes from unscored matches — scored knockout slots are kept.
//
// POST { category_id }
//   Removes seeds that are orphaned in pure knockout rounds (round N):
//   team is seeded there but its feeder knockout match (round N-1) has no
//   score (was deleted). Skips seeds whose feeder is 'group'.

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or empty JSON body']);
    exit();
}

// ---------------------------------------------------------------------------
// DELETE: remove knockout seeds for a list of team IDs
// Body: { "team_ids": [12, 9], "category_id": 4 }
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $category_id = intval($data['category_id'] ?? 0);
    $raw_ids     = $data['team_ids'] ?? [];

    if ($category_id <= 0 || !is_array($raw_ids) || count($raw_ids) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'category_id and team_ids[] are required']);
        exit();
    }

    $team_ids = array_values(array_filter(array_map('intval', $raw_ids), fn($id) => $id > 0));

    if (empty($team_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'team_ids must contain valid positive integers']);
        exit();
    }

    $totalRemoved = 0;
    $removedList  = [];

    foreach ($team_ids as $team_id) {
        $del = $conn->prepare("
            DELETE ts FROM tbl_teamschedule ts
            INNER JOIN tbl_match m ON m.match_id = ts.match_id
            WHERE ts.team_id = ?
              AND m.bracket_side IN (
                  'elimination','round-of-32','round-of-16','round-of-8',
                  'quarter-finals','semi-finals','third-place','final'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM tbl_score sc WHERE sc.match_id = ts.match_id
              )
        ");
        $del->bind_param('i', $team_id);
        $del->execute();
        $rows = $del->affected_rows;
        $del->close();

        $totalRemoved += $rows;
        if ($rows > 0) $removedList[] = $team_id;
    }

    $conn->close();

    echo json_encode([
        'success'       => true,
        'teams_cleared' => $removedList,
        'rows_removed'  => $totalRemoved,
    ]);
    exit();
}

// ---------------------------------------------------------------------------
// POST: remove orphaned seeds in pure knockout rounds
// Body: { "category_id": 4 }
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = intval($data['category_id'] ?? 0);

    if ($category_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'category_id is required']);
        exit();
    }

    // ── Early-exit guard ────────────────────────────────────────────────────
    // Before doing any round-by-round work, quickly check whether there are
    // ANY knockout seeds that look potentially orphaned (team is in a knockout
    // round but has no scored feeder knockout match). If there are none, we
    // return immediately — this makes every normal open/refresh a near-instant
    // no-op instead of running the full scan.
    $guardStmt = $conn->prepare("
        SELECT 1
        FROM tbl_teamschedule ts_cur
        INNER JOIN tbl_match  m_cur ON m_cur.match_id = ts_cur.match_id
        INNER JOIN tbl_team   t     ON t.team_id      = ts_cur.team_id
        WHERE t.category_id = ?
          AND m_cur.bracket_side IN (
              'elimination','round-of-32','round-of-16','round-of-8',
              'quarter-finals','semi-finals','third-place','final'
          )
          AND NOT EXISTS (
              SELECT 1 FROM tbl_score sc WHERE sc.match_id = ts_cur.match_id
          )
        LIMIT 1
    ");
    $guardStmt->bind_param('i', $category_id);
    $guardStmt->execute();
    $guardResult = $guardStmt->get_result();
    $guardStmt->close();

    if ($guardResult->num_rows === 0) {
        // Nothing to clean up — return immediately without touching the DB.
        $conn->close();
        echo json_encode([
            'success'        => true,
            'orphaned_seeds' => [],
            'rows_removed'   => 0,
            'note'           => 'no_candidates',
        ]);
        exit();
    }

    // ── Fixed round order ───────────────────────────────────────────────────
    // Use a hardcoded canonical sequence instead of time-based ordering.
    // Time-based ordering is unreliable: 'final' and 'third-place' share the
    // same schedule_start, causing them to sort alphabetically ('final' before
    // 'third-place'), which breaks the feeder-chain logic.
    //
    // 'third-place' and 'final' are SIBLINGS — both fed from 'semi-finals'.
    // They are NOT in a linear chain, so they must be handled separately below.
    $canonicalOrder = [
        'group', 'elimination', 'round-of-32', 'round-of-16', 'round-of-8',
        'quarter-finals', 'semi-finals', 'third-place', 'final',
    ];

    // Fetch only bracket types that actually exist for this category.
    $bStmt = $conn->prepare("
        SELECT DISTINCT m.bracket_side AS bracket_type
        FROM tbl_match m
        INNER JOIN tbl_teamschedule ts ON ts.match_id = m.match_id
        INNER JOIN tbl_team t ON t.team_id = ts.team_id
        WHERE t.category_id = ?
          AND m.bracket_side IN (
              'group','elimination','round-of-32','round-of-16','round-of-8',
              'quarter-finals','semi-finals','third-place','final'
          )
    ");
    $bStmt->bind_param('i', $category_id);
    $bStmt->execute();
    $bResult = $bStmt->get_result();
    $bStmt->close();

    $presentSet = [];
    while ($r = $bResult->fetch_assoc()) {
        $presentSet[$r['bracket_type']] = true;
    }

    // Build ordered list respecting canonical sequence, excluding siblings.
    // 'third-place' and 'final' are handled separately — remove them from the
    // linear chain so they don't get treated as feeders for each other.
    $linearRounds = [];
    foreach ($canonicalOrder as $rt) {
        if (isset($presentSet[$rt]) && $rt !== 'third-place' && $rt !== 'final') {
            $linearRounds[] = $rt;
        }
    }

    $orphanedMatchTeams = [];

    // ── Linear rounds: each round's feeder is the previous round ────────────
    for ($i = 1; $i < count($linearRounds); $i++) {
        $currentRound = $linearRounds[$i];
        $feederRound  = $linearRounds[$i - 1];

        $stmt = $conn->prepare("
            SELECT ts_cur.match_id AS cur_match_id,
                   ts_cur.team_id  AS team_id
            FROM tbl_teamschedule ts_cur
            INNER JOIN tbl_match m_cur ON m_cur.match_id = ts_cur.match_id
            INNER JOIN tbl_team  t     ON t.team_id      = ts_cur.team_id
            WHERE t.category_id      = ?
              AND m_cur.bracket_side = ?
              AND NOT EXISTS (
                  SELECT 1 FROM tbl_score sc
                  WHERE sc.match_id = ts_cur.match_id
              )
              AND EXISTS (
                  SELECT 1
                  FROM tbl_teamschedule ts_feed
                  INNER JOIN tbl_match m_feed ON m_feed.match_id = ts_feed.match_id
                  WHERE ts_feed.team_id     = ts_cur.team_id
                    AND m_feed.bracket_side = ?
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM tbl_teamschedule ts_feed2
                  INNER JOIN tbl_match  m_feed2 ON m_feed2.match_id = ts_feed2.match_id
                  INNER JOIN tbl_score  sc_feed ON sc_feed.match_id = ts_feed2.match_id
                  WHERE ts_feed2.team_id     = ts_cur.team_id
                    AND m_feed2.bracket_side = ?
              )
        ");
        $stmt->bind_param('isss', $category_id, $currentRound, $feederRound, $feederRound);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        while ($row = $res->fetch_assoc()) {
            $orphanedMatchTeams[] = [
                'match_id' => intval($row['cur_match_id']),
                'team_id'  => intval($row['team_id']),
            ];
        }
    }

    // ── Sibling rounds: 'final' and 'third-place' both feed from 'semi-finals'
    // They must never be treated as feeders for each other.
    // The feeder for both is the last round in $linearRounds (semi-finals or
    // whatever the deepest linear round is for this bracket size).
    $siblingFeeder = !empty($linearRounds) ? end($linearRounds) : null;

    foreach (['final', 'third-place'] as $siblingRound) {
        if (!isset($presentSet[$siblingRound]) || $siblingFeeder === null) continue;

        $stmt = $conn->prepare("
            SELECT ts_cur.match_id AS cur_match_id,
                   ts_cur.team_id  AS team_id
            FROM tbl_teamschedule ts_cur
            INNER JOIN tbl_match m_cur ON m_cur.match_id = ts_cur.match_id
            INNER JOIN tbl_team  t     ON t.team_id      = ts_cur.team_id
            WHERE t.category_id      = ?
              AND m_cur.bracket_side = ?
              AND NOT EXISTS (
                  SELECT 1 FROM tbl_score sc
                  WHERE sc.match_id = ts_cur.match_id
              )
              AND EXISTS (
                  SELECT 1
                  FROM tbl_teamschedule ts_feed
                  INNER JOIN tbl_match m_feed ON m_feed.match_id = ts_feed.match_id
                  WHERE ts_feed.team_id     = ts_cur.team_id
                    AND m_feed.bracket_side = ?
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM tbl_teamschedule ts_feed2
                  INNER JOIN tbl_match  m_feed2 ON m_feed2.match_id = ts_feed2.match_id
                  INNER JOIN tbl_score  sc_feed ON sc_feed.match_id = ts_feed2.match_id
                  WHERE ts_feed2.team_id     = ts_cur.team_id
                    AND m_feed2.bracket_side = ?
              )
        ");
        $stmt->bind_param('isss', $category_id, $siblingRound, $siblingFeeder, $siblingFeeder);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        while ($row = $res->fetch_assoc()) {
            $orphanedMatchTeams[] = [
                'match_id' => intval($row['cur_match_id']),
                'team_id'  => intval($row['team_id']),
            ];
        }
    }

    $totalRemoved = 0;
    $orphanedList = [];

    foreach ($orphanedMatchTeams as $pair) {
        $del = $conn->prepare(
            "DELETE FROM tbl_teamschedule WHERE match_id = ? AND team_id = ?"
        );
        $del->bind_param('ii', $pair['match_id'], $pair['team_id']);
        $del->execute();
        $totalRemoved += $del->affected_rows;
        $del->close();
        $orphanedList[] = $pair;
    }

    $conn->close();

    echo json_encode([
        'success'        => true,
        'orphaned_seeds' => $orphanedList,
        'rows_removed'   => $totalRemoved,
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed. Use POST or DELETE.']);
?>