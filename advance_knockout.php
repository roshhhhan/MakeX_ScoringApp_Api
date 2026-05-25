<?php
// ─────────────────────────────────────────────────────────────────────
// advance_knockout.php  (FIFA-style seeding)
//
// POST (JSON body)
// Called by championship_sched.dart immediately after a match score
// is saved.
//
// ── GROUP STAGE ──────────────────────────────────────────────────────
// After a group match is scored this script checks whether ALL matches
// in that group are now complete.  If so it ranks the group by the
// standard soccer tiebreaker (pts → GD → GF → team_name) and stores
// the result in tbl_group_results (group_label, rank, team_id).
//
// Once ALL groups are complete, the FIFA draw is executed:
//   Rank-1 of Group A  → Match 1 slot HOME  (vs Rank-2 of Group B)
//   Rank-2 of Group B  → Match 1 slot AWAY
//   Rank-1 of Group B  → Match 2 slot HOME  (vs Rank-2 of Group A)
//   Rank-2 of Group A  → Match 2 slot AWAY
//   ... and so on for every pair, sorted alphabetically by group label.
//
// If the group is not yet finished it returns early with "pending".
// If only SOME groups are done it returns "waiting_for_other_groups".
//
// ── KNOCKOUT ROUNDS ──────────────────────────────────────────────────
// Winner is seeded into the next round.
// Semi-final loser is seeded into the third-place match.
// Final / third-place → no advancement.
//
// Body:
// {
//   "match_id":       47,
//   "winner_team_id": 12,   <- ignored for group matches
//   "loser_team_id":  9,    <- ignored for group matches
//   "category_id":    4
// }
//
// No extra DB tables required.  Standings are computed live from
// tbl_score + tbl_soccer_groups (same logic as get_group_standings.php).
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

require_once 'db_config.php';

// ── Parse body ────────────────────────────────────────────────────────
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or empty JSON body']);
    exit();
}

$required = ['match_id', 'winner_team_id', 'loser_team_id', 'category_id'];
foreach ($required as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit();
    }
}

$match_id       = intval($data['match_id']);
$winner_team_id = intval($data['winner_team_id']);
$loser_team_id  = intval($data['loser_team_id']);
$category_id    = intval($data['category_id']);

if ($match_id <= 0 || $category_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid match_id or category_id']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT bracket_side AS bracket_type FROM tbl_match WHERE match_id = ? LIMIT 1"
);
$stmt->bind_param('i', $match_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => "Match $match_id not found"]);
    exit();
}

$currentType = $row['bracket_type'];

// ═════════════════════════════════════════════════════════════════════
// PATH A — GROUP STAGE  (FIFA-style draw)
// ═════════════════════════════════════════════════════════════════════
if ($currentType === 'group') {

    // ── A1. Find which group label this match belongs to ──────────────
    $stmt = $conn->prepare("
        SELECT ts.team_id
        FROM   tbl_teamschedule ts
        WHERE  ts.match_id = ?
        LIMIT  2
    ");
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $tsRes = $stmt->get_result();
    $stmt->close();

    $matchTeamIds = [];
    while ($r = $tsRes->fetch_assoc()) {
        $matchTeamIds[] = intval($r['team_id']);
    }

    if (empty($matchTeamIds)) {
        echo json_encode([
            'success' => false,
            'message' => "No teams found in tbl_teamschedule for match $match_id",
        ]);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT group_label
        FROM   tbl_soccer_groups
        WHERE  team_id     = ?
          AND  category_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $matchTeamIds[0], $category_id);
    $stmt->execute();
    $glRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$glRow) {
        echo json_encode([
            'success' => false,
            'message' => "Team {$matchTeamIds[0]} not found in tbl_soccer_groups for category $category_id",
        ]);
        exit();
    }

    $groupLabel = $glRow['group_label'];

    // ── A2. Get all teams in this group ───────────────────────────────
    $stmt = $conn->prepare("
        SELECT team_id
        FROM   tbl_soccer_groups
        WHERE  group_label = ?
          AND  category_id = ?
    ");
    $stmt->bind_param('si', $groupLabel, $category_id);
    $stmt->execute();
    $grpTeamRes = $stmt->get_result();
    $stmt->close();

    $groupTeamIds = [];
    while ($r = $grpTeamRes->fetch_assoc()) {
        $groupTeamIds[] = intval($r['team_id']);
    }

    $n            = count($groupTeamIds);
    $totalMatches = ($n * ($n - 1)) / 2;

    // ── A3. Count scored matches for this group ───────────────────────
    $placeholders = implode(',', array_fill(0, $n, '?'));
    $types        = str_repeat('i', $n + 1);

    $stmt = $conn->prepare("
        SELECT m.match_id
        FROM   tbl_match m
        WHERE  m.bracket_side = 'group'
          AND  (
              SELECT COUNT(*)
              FROM   tbl_teamschedule ts
              WHERE  ts.match_id = m.match_id
                AND  ts.team_id IN ($placeholders)
          ) = 2
          AND  (
              SELECT COUNT(*)
              FROM   tbl_score sc
              WHERE  sc.match_id = m.match_id
          ) = 2
          AND EXISTS (
              SELECT 1
              FROM   tbl_teamschedule ts2
              INNER JOIN tbl_team t ON t.team_id = ts2.team_id
              WHERE  ts2.match_id   = m.match_id
                AND  t.category_id  = ?
          )
    ");
    $bindArgs = array_merge($groupTeamIds, [$category_id]);
    $stmt->bind_param($types, ...$bindArgs);
    $stmt->execute();
    $scoredRes = $stmt->get_result();
    $stmt->close();

    $scoredMatches = [];
    while ($r = $scoredRes->fetch_assoc()) {
        $scoredMatches[] = intval($r['match_id']);
    }
    $scoredCount = count($scoredMatches);

    if ($scoredCount < $totalMatches) {
        echo json_encode([
            'success'        => true,
            'status'         => 'pending',
            'group_label'    => $groupLabel,
            'matches_scored' => $scoredCount,
            'matches_total'  => $totalMatches,
            'message'        => "Group $groupLabel: $scoredCount / $totalMatches matches scored. Waiting for remaining matches.",
        ]);
        exit();
    }

    // ── A4. All matches done — calculate standings via shared helper ─────
    // computeGroupStandings() replicates get_group_standings.php logic
    // in-process. No tbl_group_results table required.
    $rows = computeGroupStandings($conn, $groupTeamIds, $scoredMatches);

    $rank1TeamId = $rows[0]['team_id'];
    $rank2TeamId = $rows[1]['team_id'];

    // ── A5. Check if ALL groups for this category are now complete ────
    // A group is "complete" when its scored-match count equals totalMatches.
    // We recalculate this for every group live — no helper table needed.
    $allGroupsStmt = $conn->prepare("
        SELECT DISTINCT group_label
        FROM   tbl_soccer_groups
        WHERE  category_id = ?
        ORDER  BY group_label ASC
    ");
    $allGroupsStmt->bind_param('i', $category_id);
    $allGroupsStmt->execute();
    $allGroupsRes = $allGroupsStmt->get_result();
    $allGroupsStmt->close();

    $allGroupLabels = [];
    while ($r = $allGroupsRes->fetch_assoc()) {
        $allGroupLabels[] = $r['group_label'];
    }
    $totalGroups = count($allGroupLabels);

    // Count how many groups have all their matches scored
    $doneGroups = 0;
    foreach ($allGroupLabels as $lbl) {
        // Get team IDs for this group
        $gStmt = $conn->prepare("
            SELECT team_id FROM tbl_soccer_groups
            WHERE group_label = ? AND category_id = ?
        ");
        $gStmt->bind_param('si', $lbl, $category_id);
        $gStmt->execute();
        $gRes = $gStmt->get_result();
        $gStmt->close();

        $gTeamIds = [];
        while ($gr = $gRes->fetch_assoc()) {
            $gTeamIds[] = intval($gr['team_id']);
        }

        $gN            = count($gTeamIds);
        $gTotalMatches = ($gN * ($gN - 1)) / 2;
        $gPH           = implode(',', array_fill(0, $gN, '?'));
        $gTypes        = str_repeat('i', $gN + 1);

        $cStmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM   tbl_match m
            WHERE  m.bracket_side = 'group'
              AND  (
                  SELECT COUNT(*)
                  FROM   tbl_teamschedule ts
                  WHERE  ts.match_id = m.match_id
                    AND  ts.team_id IN ($gPH)
              ) = 2
              AND  (
                  SELECT COUNT(*)
                  FROM   tbl_score sc
                  WHERE  sc.match_id = m.match_id
              ) = 2
              AND EXISTS (
                  SELECT 1
                  FROM   tbl_teamschedule ts2
                  INNER JOIN tbl_team t ON t.team_id = ts2.team_id
                  WHERE  ts2.match_id  = m.match_id
                    AND  t.category_id = ?
              )
        ");
        $cArgs = array_merge($gTeamIds, [$category_id]);
        $cStmt->bind_param($gTypes, ...$cArgs);
        $cStmt->execute();
        $cRow = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if (intval($cRow['cnt']) >= $gTotalMatches) {
            $doneGroups++;
        }
    }

    if ($doneGroups < $totalGroups) {
        // This group is done but others aren't — hold off on seeding
        echo json_encode([
            'success'             => true,
            'status'              => 'waiting_for_other_groups',
            'group_label'         => $groupLabel,
            'groups_complete'     => $doneGroups,
            'groups_total'        => $totalGroups,
            'rank1_team_id'       => $rank1TeamId,
            'rank1_team_name'     => $rows[0]['team_name'],
            'rank2_team_id'       => $rank2TeamId,
            'rank2_team_name'     => $rows[1]['team_name'],
            'standings'           => $rows,
            'message'             => "Group $groupLabel complete ($doneGroups/$totalGroups groups done). Waiting for remaining groups before draw.",
        ]);
        exit();
    }

    // ── A6. ALL groups done — build groupResults live (no helper table) ─
    // For each group, run computeGroupStandings() to get rank-1 and rank-2.
    $groupResults = []; // $groupResults['A'][1] = team_id, [2] = team_id
    foreach ($allGroupLabels as $lbl) {
        // Fetch team IDs for this group
        $gStmt = $conn->prepare("
            SELECT team_id FROM tbl_soccer_groups
            WHERE group_label = ? AND category_id = ?
        ");
        $gStmt->bind_param('si', $lbl, $category_id);
        $gStmt->execute();
        $gRes = $gStmt->get_result();
        $gStmt->close();

        $gTeamIds = [];
        while ($gr = $gRes->fetch_assoc()) {
            $gTeamIds[] = intval($gr['team_id']);
        }

        // Fetch scored match IDs for this group
        $gN  = count($gTeamIds);
        $gPH = implode(',', array_fill(0, $gN, '?'));
        $gTypes = str_repeat('i', $gN + 1);

        $smStmt = $conn->prepare("
            SELECT m.match_id
            FROM   tbl_match m
            WHERE  m.bracket_side = 'group'
              AND  (
                  SELECT COUNT(*)
                  FROM   tbl_teamschedule ts
                  WHERE  ts.match_id = m.match_id
                    AND  ts.team_id IN ($gPH)
              ) = 2
              AND  (
                  SELECT COUNT(*)
                  FROM   tbl_score sc
                  WHERE  sc.match_id = m.match_id
              ) = 2
              AND EXISTS (
                  SELECT 1
                  FROM   tbl_teamschedule ts2
                  INNER JOIN tbl_team t ON t.team_id = ts2.team_id
                  WHERE  ts2.match_id  = m.match_id
                    AND  t.category_id = ?
              )
        ");
        $smArgs = array_merge($gTeamIds, [$category_id]);
        $smStmt->bind_param($gTypes, ...$smArgs);
        $smStmt->execute();
        $smRes = $smStmt->get_result();
        $smStmt->close();

        $gScoredMatches = [];
        while ($sm = $smRes->fetch_assoc()) {
            $gScoredMatches[] = intval($sm['match_id']);
        }

        $gRows = computeGroupStandings($conn, $gTeamIds, $gScoredMatches);
        $groupResults[$lbl][1] = $gRows[0]['team_id'] ?? null;
        $groupResults[$lbl][2] = $gRows[1]['team_id'] ?? null;
    }

    // Sort group labels alphabetically (A, B, C, D …)
    ksort($groupResults);
    $sortedLabels = array_keys($groupResults);

    // ── A7. Find next knockout round ──────────────────────────────────
    $knownOrder = [
        'elimination', 'round-of-32', 'round-of-16',
        'quarter-finals', 'semi-finals', 'third-place', 'final'
    ];

    $roundsStmt = $conn->prepare("
        SELECT DISTINCT m.bracket_side AS bracket_type
        FROM   tbl_match m
        WHERE  m.bracket_side IN (
                   'elimination', 'round-of-32', 'round-of-16',
                   'quarter-finals', 'semi-finals', 'third-place', 'final'
               )
    ");
    $roundsStmt->execute();
    $roundsResult = $roundsStmt->get_result();
    $roundsStmt->close();

    $presentRounds = [];
    while ($r = $roundsResult->fetch_assoc()) {
        // Skip third-place and final — they are not valid entry rounds from group stage
        if ($r['bracket_type'] === 'third-place' || $r['bracket_type'] === 'final') continue;
        $presentRounds[] = $r['bracket_type'];
    }

    usort($presentRounds, function ($a, $b) use ($knownOrder) {
        return array_search($a, $knownOrder) - array_search($b, $knownOrder);
    });

    $nextKnockoutType = $presentRounds[0] ?? 'semi-finals';

    // ── A8. Get default referee ───────────────────────────────────────
    $refStmt = $conn->prepare(
        "SELECT referee_id FROM tbl_referee ORDER BY referee_id ASC LIMIT 1"
    );
    $refStmt->execute();
    $refRow     = $refStmt->get_result()->fetch_assoc();
    $refStmt->close();
    $referee_id = $refRow ? intval($refRow['referee_id']) : 1;

    // ── A9. FIFA-style pairing ────────────────────────────────────────
    // Standard FIFA World Cup draw logic:
    //   Pair 1: 1A vs 2B  |  Pair 2: 1B vs 2A
    //   Pair 3: 1C vs 2D  |  Pair 4: 1D vs 2C  ...
    //
    // Groups are paired by position: (0,1), (2,3), (4,5) ...
    // Within each pair (groupX, groupY):
    //   Match N:   HOME = 1st of groupX,  AWAY = 2nd of groupY
    //   Match N+1: HOME = 1st of groupY,  AWAY = 2nd of groupX
    //
    // If there is an odd number of groups, the last group is paired
    // with itself as a fallback (1st vs 2nd), which is the best we
    // can do without a bye system.

    $seedingLog = [];

    for ($i = 0; $i < count($sortedLabels); $i += 2) {
        $labelA = $sortedLabels[$i];
        // If odd group count, pair last group with itself
        $labelB = $sortedLabels[$i + 1] ?? $labelA;

        $rank1A = $groupResults[$labelA][1] ?? null;
        $rank2A = $groupResults[$labelA][2] ?? null;
        $rank1B = $groupResults[$labelB][1] ?? null;
        $rank2B = $groupResults[$labelB][2] ?? null;

        // Match 1: Winner of A  vs  Runner-up of B
        // They are seeded into the SAME match slot (home + away).
        if ($rank1A && $rank2B) {
            $r1 = seedIntoMatchTogether($conn, $nextKnockoutType, $rank1A, $rank2B, $referee_id, $category_id);
            $seedingLog[] = [
                'match'   => "1{$labelA} vs 2{$labelB}",
                'result'  => $r1,
            ];
        }

        // Match 2: Winner of B  vs  Runner-up of A  (skip if same group)
        if ($labelA !== $labelB && $rank1B && $rank2A) {
            $r2 = seedIntoMatchTogether($conn, $nextKnockoutType, $rank1B, $rank2A, $referee_id, $category_id);
            $seedingLog[] = [
                'match'   => "1{$labelB} vs 2{$labelA}",
                'result'  => $r2,
            ];
        }
    }

    $conn->close();

    echo json_encode([
        'success'         => true,
        'status'          => 'group_complete',
        'group_label'     => $groupLabel,
        'groups_complete' => $doneGroups,
        'groups_total'    => $totalGroups,
        'next_round'      => $nextKnockoutType,
        'draw_executed'   => true,
        'seeding_log'     => $seedingLog,
        'standings'       => $rows,
        // rank1/rank2 for THIS group — used by the Dart snackbar message
        'rank1_team_id'   => $rank1TeamId,
        'rank1_team_name' => $rows[0]['team_name'] ?? '',
        'rank2_team_id'   => $rank2TeamId,
        'rank2_team_name' => $rows[1]['team_name'] ?? '',
        'message'         => "All $totalGroups groups complete. FIFA draw executed into $nextKnockoutType.",
    ]);
    exit();
}

// ═════════════════════════════════════════════════════════════════════
// PATH B — KNOCKOUT ROUNDS  (FIFA-correct bracket pairing)
//
// Pairing rules (verified against FIFA draw):
//
//   R16  (8 matches) — FIFA draw seeding:
//     M1: 1st A vs 2nd B  |  M2: 1st B vs 2nd A
//     M3: 1st C vs 2nd D  |  M4: 1st D vs 2nd C
//     M5: 1st E vs 2nd F  |  M6: 1st F vs 2nd E
//     M7: 1st G vs 2nd H  |  M8: 1st H vs 2nd G
//
//   R16 → QF  (4 matches):
//     QF1: Winner M1 vs Winner M3  (pos 1 & 3 → QF slot 0)
//     QF2: Winner M2 vs Winner M4  (pos 2 & 4 → QF slot 1)
//     QF3: Winner M5 vs Winner M7  (pos 5 & 7 → QF slot 2)
//     QF4: Winner M6 vs Winner M8  (pos 6 & 8 → QF slot 3)
//
//   QF → SF  (2 matches):
//     SF1: Winner QF1 vs Winner QF2  (pos 1 & 2 → SF slot 0)
//     SF2: Winner QF3 vs Winner QF4  (pos 3 & 4 → SF slot 1)
//
//   SF → Final + 3rd-place:
//     Final: Winner SF1 vs Winner SF2  (pos 1 & 2 → Final)
//     losers → 3rd-place
//
// Strategy:
//   - Get the 1-based position of match_id within its round
//     (ordered by schedule_start ASC, match_id ASC).
//   - Derive which next-round slot this winner belongs to and
//     which match is its "partner".
//   - If the partner is NOT yet scored → winner is the FIRST to
//     arrive: seed alone via seedIntoRound() as a placeholder.
//   - If the partner IS already scored → winner is the SECOND to
//     arrive: find the already-seeded placeholder and pair them
//     together via seedIntoMatchTogether().
// ═════════════════════════════════════════════════════════════════════

// ── B1. No advancement for final or third-place ───────────────────────
if ($currentType === 'final' || $currentType === 'third-place') {
    echo json_encode([
        'success' => true,
        'message' => 'No advancement needed for ' . $currentType,
    ]);
    exit();
}

// ── B2. Determine next round ──────────────────────────────────────────
$knownOrder = [
    'elimination', 'round-of-32', 'round-of-16',
    'quarter-finals', 'semi-finals', 'third-place', 'final'
];

$roundsStmt = $conn->prepare("
    SELECT DISTINCT m.bracket_side AS bracket_type
    FROM   tbl_match m
    WHERE  m.bracket_side IN (
               'elimination', 'round-of-32', 'round-of-16',
               'quarter-finals', 'semi-finals', 'third-place', 'final'
           )
");
$roundsStmt->execute();
$roundsResult = $roundsStmt->get_result();
$roundsStmt->close();

$presentRounds = [];
while ($r = $roundsResult->fetch_assoc()) {
    // Exclude third-place — it is a parallel branch, not a sequential round
    if ($r['bracket_type'] === 'third-place') continue;
    $presentRounds[] = $r['bracket_type'];
}
usort($presentRounds, function ($a, $b) use ($knownOrder) {
    return array_search($a, $knownOrder) - array_search($b, $knownOrder);
});

$nextWinnerType = null;
$nextLoserType  = null;

if ($currentType === 'semi-finals') {
    $nextWinnerType = 'final';
    $nextLoserType  = 'third-place';
} else {
    $curIdx = array_search($currentType, $presentRounds);
    if ($curIdx !== false && $curIdx + 1 < count($presentRounds)) {
        $nextWinnerType = $presentRounds[$curIdx + 1];
    } else {
        $nextWinnerType = 'final';
    }
}

// ── B3. Get default referee ───────────────────────────────────────────
$refStmt = $conn->prepare(
    "SELECT referee_id FROM tbl_referee ORDER BY referee_id ASC LIMIT 1"
);
$refStmt->execute();
$refRow     = $refStmt->get_result()->fetch_assoc();
$refStmt->close();
$referee_id = $refRow ? intval($refRow['referee_id']) : 1;

// ── B4. Get 1-based position of current match within its round ────────
// Ordered by schedule_start ASC, match_id ASC — same order used when
// slots were originally created.
$posStmt = $conn->prepare("
    SELECT m.match_id
    FROM   tbl_match m
    LEFT   JOIN tbl_schedule s ON s.schedule_id = m.schedule_id
    WHERE  m.bracket_side = ?
    ORDER  BY s.schedule_start ASC, m.match_id ASC
");
$posStmt->bind_param('s', $currentType);
$posStmt->execute();
$posRes = $posStmt->get_result();
$posStmt->close();

$allMatchIds   = [];
$currentPos    = null;   // 1-based
$posIndex      = 1;
while ($pr = $posRes->fetch_assoc()) {
    $allMatchIds[] = intval($pr['match_id']);
    if (intval($pr['match_id']) === $match_id) {
        $currentPos = $posIndex;
    }
    $posIndex++;
}

if ($currentPos === null) {
    // Fallback: position unknown, use simple seedIntoRound
    $winnerResult = seedIntoRound($conn, $nextWinnerType, $winner_team_id, $referee_id, $category_id);
    $loserResult  = ['seeded' => false];
    if ($nextLoserType && $loser_team_id > 0) {
        $loserResult = seedIntoRound($conn, $nextLoserType, $loser_team_id, $referee_id, $category_id);
    }
    $conn->close();
    echo json_encode([
        'success'            => true,
        'current_round'      => $currentType,
        'winner_advanced_to' => $nextWinnerType,
        'winner_seeded'      => $winnerResult['seeded'] ?? false,
        'winner_detail'      => $winnerResult,
        'message'            => 'Advancement complete (fallback: position unknown)',
    ]);
    exit();
}

// ── B5. Derive partner position using FIFA bracket pairing ────────────
//
//   R16 → QF pairing map (1-based positions):
//     M1↔M3 (QF1), M2↔M4 (QF2), M5↔M7 (QF3), M6↔M8 (QF4)
//   QF → SF pairing map:
//     QF1↔QF2 (SF1), QF3↔QF4 (SF2)
//   SF → Final pairing map:
//     SF1↔SF2 (Final)
//
// "Partner" is the other match whose winner shares the same next slot.
// The next-slot index (0-based) is also derived so we can target the
// correct pre-created match row in the next round.

$pairingMaps = [
    'round-of-16'   => [1=>3, 3=>1, 2=>4, 4=>2, 5=>7, 7=>5, 6=>8, 8=>6],
    'quarter-finals' => [1=>2, 2=>1, 3=>4, 4=>3],
    'semi-finals'   => [1=>2, 2=>1],
    // Generic fallback for other rounds (sequential pairs)
    'elimination'   => [],
    'round-of-32'   => [],
];

// Next-slot index (0-based) for each position in each round.
// Both partners map to the same slot index.
$slotMaps = [
    'round-of-16'   => [1=>0, 3=>0, 2=>1, 4=>1, 5=>2, 7=>2, 6=>3, 8=>3],
    'quarter-finals' => [1=>0, 2=>0, 3=>1, 4=>1],
    'semi-finals'   => [1=>0, 2=>0],
];

$pairingMap = $pairingMaps[$currentType] ?? [];
$slotMap    = $slotMaps[$currentType]    ?? [];

$partnerPos = $pairingMap[$currentPos] ?? null;
$slotIndex  = $slotMap[$currentPos]    ?? null;

// ── B6. Check if partner match is already scored ──────────────────────
$partnerMatchId  = ($partnerPos !== null) ? ($allMatchIds[$partnerPos - 1] ?? null) : null;
$partnerIsScored = false;

if ($partnerMatchId) {
    $pScoreStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM tbl_score WHERE match_id = ?
    ");
    $pScoreStmt->bind_param('i', $partnerMatchId);
    $pScoreStmt->execute();
    $pScoreRow = $pScoreStmt->get_result()->fetch_assoc();
    $pScoreStmt->close();
    $partnerIsScored = intval($pScoreRow['cnt']) >= 2;
}

// ── B7. Seed winner into next round ───────────────────────────────────
$winnerResult = ['seeded' => false];
$loserResult  = ['seeded' => false];

if ($nextWinnerType && $winner_team_id > 0) {

    if ($partnerPos === null || $slotIndex === null) {
        // No pairing map for this round — fallback to simple slot fill
        $winnerResult = seedIntoRound($conn, $nextWinnerType, $winner_team_id, $referee_id, $category_id);

    } else {
        // ── Shared: resolve the ordered list of next-round match IDs ─────────
        // Used by BOTH the "partner not yet scored" (placeholder) path
        // and the "partner already scored" (pair-up) path.
        // Both need to target the SAME slot ($slotIndex) so winners from
        // the correct pairing (e.g. M1 & M3 → QF slot 0) always land together.
        $nrStmt = $conn->prepare("
            SELECT m.match_id
            FROM   tbl_match m
            LEFT   JOIN tbl_schedule s ON s.schedule_id = m.schedule_id
            WHERE  m.bracket_side = ?
            ORDER  BY s.schedule_start ASC, m.match_id ASC
        ");
        $nrStmt->bind_param('s', $nextWinnerType);
        $nrStmt->execute();
        $nrRes = $nrStmt->get_result();
        $nrStmt->close();

        $nextRoundMatchIds = [];
        while ($nr = $nrRes->fetch_assoc()) {
            $nextRoundMatchIds[] = intval($nr['match_id']);
        }

        $targetNextMatchId = $nextRoundMatchIds[$slotIndex] ?? null;

        if (!$partnerIsScored) {
            // ── PLACEHOLDER path ──────────────────────────────────────────────
            // Partner not finished yet. Seed this winner alone into the CORRECT
            // slot ($slotIndex), NOT just "first available". This prevents e.g.
            // Winner M1 and Winner M2 from both landing in QF slot 0 before M3
            // and M4 are scored.
            if ($targetNextMatchId) {
                // Check how many teams are already in the correct slot
                $slotCountStmt = $conn->prepare("
                    SELECT COUNT(*) AS cnt,
                           MAX(team_id) AS existing_team_id
                    FROM   tbl_teamschedule
                    WHERE  match_id = ?
                ");
                $slotCountStmt->bind_param('i', $targetNextMatchId);
                $slotCountStmt->execute();
                $slotCountRow = $slotCountStmt->get_result()->fetch_assoc();
                $slotCountStmt->close();

                $slotTeamCount     = intval($slotCountRow['cnt']);
                $existingTeamInSlot = intval($slotCountRow['existing_team_id'] ?? 0);

                if ($slotTeamCount === 0) {
                    // Slot is empty — insert as first placeholder
                    $ins = $conn->prepare("
                        INSERT IGNORE INTO tbl_teamschedule
                            (match_id, round_id, team_id, referee_id, arena_number)
                        VALUES (?, 1, ?, ?, 1)
                    ");
                    $ins->bind_param('iii', $targetNextMatchId, $winner_team_id, $referee_id);
                    $ins->execute();
                    $affected = $ins->affected_rows;
                    $ins->close();
                    $winnerResult = [
                        'seeded'   => $affected > 0,
                        'match_id' => $targetNextMatchId,
                        'note'     => "Placeholder seeded alone into correct slot (index $slotIndex, match $targetNextMatchId)",
                    ];
                } elseif ($slotTeamCount === 1 && $existingTeamInSlot !== $winner_team_id) {
                    // One team already there from the other pairing partner —
                    // pair up now (this can happen if the partner scored first
                    // but $partnerIsScored flag missed it due to a timing edge)
                    $winnerResult = seedIntoMatchTogether(
                        $conn, $nextWinnerType,
                        $existingTeamInSlot,
                        $winner_team_id,
                        $referee_id, $category_id
                    );
                    $winnerResult['note'] = "Slot had 1 team already; paired in correct slot (index $slotIndex)";
                } else {
                    // Already in slot (duplicate call) or slot is full
                    $winnerResult = [
                        'seeded' => false,
                        'note'   => "Slot index $slotIndex (match $targetNextMatchId) already has $slotTeamCount team(s); skipped",
                    ];
                }
            } else {
                // Could not resolve slot — fallback to simple fill
                $winnerResult = seedIntoRound($conn, $nextWinnerType, $winner_team_id, $referee_id, $category_id);
                $winnerResult['note'] = "Could not resolve slot index $slotIndex in $nextWinnerType; fallback";
            }
            $winnerResult['note'] = ($winnerResult['note'] ?? '') . " (partner match $partnerMatchId not yet scored)";

        } else {
            // ── PAIR-UP path ──────────────────────────────────────────────────
            // Partner IS finished. Find its winner already sitting as a
            // placeholder in the correct slot and pair them together.
            if ($targetNextMatchId) {
                $partnerTeamStmt = $conn->prepare("
                    SELECT ts.team_id
                    FROM   tbl_teamschedule ts
                    WHERE  ts.match_id = ?
                    LIMIT  1
                ");
                $partnerTeamStmt->bind_param('i', $targetNextMatchId);
                $partnerTeamStmt->execute();
                $partnerTeamRow = $partnerTeamStmt->get_result()->fetch_assoc();
                $partnerTeamStmt->close();

                $partnerTeamId = $partnerTeamRow ? intval($partnerTeamRow['team_id']) : null;

                if ($partnerTeamId) {
                    // Pair them: placeholder (already there) = home, new winner = away
                    $winnerResult = seedIntoMatchTogether(
                        $conn, $nextWinnerType,
                        $partnerTeamId,   // home (already seeded)
                        $winner_team_id,  // away (arriving now)
                        $referee_id, $category_id
                    );
                    $winnerResult['note'] = "Paired with placeholder team_id=$partnerTeamId in match_id=$targetNextMatchId (slot index $slotIndex)";
                } else {
                    // Slot is empty — partner winner was not seeded yet; seed alone in correct slot
                    $ins = $conn->prepare("
                        INSERT IGNORE INTO tbl_teamschedule
                            (match_id, round_id, team_id, referee_id, arena_number)
                        VALUES (?, 1, ?, ?, 1)
                    ");
                    $ins->bind_param('iii', $targetNextMatchId, $winner_team_id, $referee_id);
                    $ins->execute();
                    $affected = $ins->affected_rows;
                    $ins->close();
                    $winnerResult = [
                        'seeded'   => $affected > 0,
                        'match_id' => $targetNextMatchId,
                        'note'     => "Target slot $targetNextMatchId was empty; seeded alone in correct slot (index $slotIndex)",
                    ];
                }
            } else {
                // Could not resolve target slot — fallback
                $winnerResult = seedIntoRound($conn, $nextWinnerType, $winner_team_id, $referee_id, $category_id);
                $winnerResult['note'] = "Could not resolve slot index $slotIndex in $nextWinnerType; fallback";
            }
        }
    }
}

// Loser handling (semi-finals only → third-place, simple slot fill)
if ($nextLoserType && $loser_team_id > 0) {
    $loserResult = seedIntoRound($conn, $nextLoserType, $loser_team_id, $referee_id, $category_id);
}

$conn->close();

$response = [
    'success'            => true,
    'current_round'      => $currentType,
    'current_match_pos'  => $currentPos,
    'partner_match_pos'  => $partnerPos,
    'partner_scored'     => $partnerIsScored,
    'winner_advanced_to' => $nextWinnerType,
    'winner_seeded'      => $winnerResult['seeded'] ?? false,
    'winner_detail'      => $winnerResult,
    'message'            => 'Advancement complete',
];

if ($nextLoserType) {
    $response['loser_advanced_to'] = $nextLoserType;
    $response['loser_seeded']      = $loserResult['seeded'] ?? false;
    $response['loser_detail']      = $loserResult;
}

echo json_encode($response);


// ═════════════════════════════════════════════════════════════════════
// HELPER — compute standings for one group
// ═════════════════════════════════════════════════════════════════════
// Mirrors the logic in get_group_standings.php so we never need to
// store results in tbl_group_results.
//
// $teamIds      — array of team_id ints in this group
// $scoredMatchIds — array of match_id ints that are fully scored
//
// Returns rows sorted pts DESC → gd DESC → gf DESC → team_name ASC.
// Each row: [ team_id, team_name, pts, gd, gf, ga, mp, w, d, l ]
function computeGroupStandings(mysqli $conn, array $teamIds, array $scoredMatchIds): array {
    if (empty($teamIds)) return [];

    $n = count($teamIds);

    // Fetch team names
    $ph = implode(',', array_fill(0, $n, '?'));
    $nmStmt = $conn->prepare("SELECT team_id, team_name FROM tbl_team WHERE team_id IN ($ph)");
    $nmStmt->bind_param(str_repeat('i', $n), ...$teamIds);
    $nmStmt->execute();
    $nmRes = $nmStmt->get_result();
    $nmStmt->close();

    $teamNames = [];
    while ($r = $nmRes->fetch_assoc()) {
        $teamNames[intval($r['team_id'])] = $r['team_name'];
    }

    // Initialise stats
    $stats = [];
    foreach ($teamIds as $tid) {
        $stats[$tid] = ['mp' => 0, 'w' => 0, 'd' => 0, 'l' => 0,
                        'gf' => 0, 'ga' => 0, 'pts' => 0];
    }

    // Fetch scores for these matches
    if (!empty($scoredMatchIds)) {
        $sm = count($scoredMatchIds);
        $sph = implode(',', array_fill(0, $sm, '?'));
        $scStmt = $conn->prepare("
            SELECT match_id, team_id, score_independentscore AS goals
            FROM   tbl_score
            WHERE  match_id IN ($sph)
            ORDER  BY match_id ASC
        ");
        $scStmt->bind_param(str_repeat('i', $sm), ...$scoredMatchIds);
        $scStmt->execute();
        $scRes = $scStmt->get_result();
        $scStmt->close();

        $matchScores = [];
        while ($r = $scRes->fetch_assoc()) {
            $matchScores[intval($r['match_id'])][intval($r['team_id'])] = intval($r['goals']);
        }

        foreach ($matchScores as $teams) {
            if (count($teams) !== 2) continue;
            $tids = array_keys($teams);
            $t1 = $tids[0]; $t2 = $tids[1];
            $g1 = $teams[$t1]; $g2 = $teams[$t2];
            if (!isset($stats[$t1]) || !isset($stats[$t2])) continue;

            $stats[$t1]['mp']++; $stats[$t2]['mp']++;
            $stats[$t1]['gf'] += $g1; $stats[$t1]['ga'] += $g2;
            $stats[$t2]['gf'] += $g2; $stats[$t2]['ga'] += $g1;

            if ($g1 > $g2) {
                $stats[$t1]['w']++; $stats[$t1]['pts'] += 3;
                $stats[$t2]['l']++;
            } elseif ($g2 > $g1) {
                $stats[$t2]['w']++; $stats[$t2]['pts'] += 3;
                $stats[$t1]['l']++;
            } else {
                $stats[$t1]['d']++; $stats[$t1]['pts']++;
                $stats[$t2]['d']++; $stats[$t2]['pts']++;
            }
        }
    }

    $rows = [];
    foreach ($teamIds as $tid) {
        $s = $stats[$tid];
        $rows[] = [
            'team_id'   => $tid,
            'team_name' => $teamNames[$tid] ?? "Team $tid",
            'mp'        => $s['mp'],
            'w'         => $s['w'],
            'd'         => $s['d'],
            'l'         => $s['l'],
            'gf'        => $s['gf'],
            'ga'        => $s['ga'],
            'gd'        => $s['gf'] - $s['ga'],
            'pts'       => $s['pts'],
        ];
    }

    usort($rows, function ($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] - $a['pts'];
        if ($b['gd']  !== $a['gd'])  return $b['gd']  - $a['gd'];
        if ($b['gf']  !== $a['gf'])  return $b['gf']  - $a['gf'];
        return strcmp($a['team_name'], $b['team_name']);
    });

    return $rows;
}

// ═════════════════════════════════════════════════════════════════════
// HELPER — seed TWO specific teams into the SAME knockout match slot
// ═════════════════════════════════════════════════════════════════════
// Used for the FIFA group-stage draw where we know exactly who faces who.
//
// RE-SCORE AWARE (upsert logic):
//   1. If an unscored slot already has EXACTLY these two teams → no-op.
//   2. If either team is in a stale unscored slot (standings changed after
//      a re-score) → remove that stale seed and re-insert into a fresh slot.
//   3. If neither team is seeded yet → find the first empty slot and insert.
//
// Scored knockout slots are NEVER touched, so already-played matches are safe.
function seedIntoMatchTogether(
    mysqli $conn,
    string $targetType,
    int    $homeTeamId,
    int    $awayTeamId,
    int    $refereeId,
    int    $categoryId
): array {

    // ── Step 1: Are both teams already paired correctly in an unscored slot? ─
    $pairStmt = $conn->prepare("
        SELECT ts1.match_id
        FROM   tbl_teamschedule ts1
        INNER  JOIN tbl_teamschedule ts2 ON ts2.match_id = ts1.match_id
                                        AND ts2.team_id  = ?
        INNER  JOIN tbl_match m          ON m.match_id   = ts1.match_id
        WHERE  ts1.team_id    = ?
          AND  m.bracket_side = ?
          AND  NOT EXISTS (
              SELECT 1 FROM tbl_score sc WHERE sc.match_id = ts1.match_id
          )
        LIMIT 1
    ");
    $pairStmt->bind_param('iis', $homeTeamId, $awayTeamId, $targetType);
    $pairStmt->execute();
    $pairRow = $pairStmt->get_result()->fetch_assoc();
    $pairStmt->close();

    if ($pairRow) {
        // Both teams are already correctly paired — nothing to do.
        return [
            'seeded'   => false,
            'reason'   => "Both teams already correctly seeded into '$targetType' (idempotent skip).",
            'match_id' => intval($pairRow['match_id']),
        ];
    }

    // ── Step 2: Clear any stale unscored seeds for either team in this round ─
    // This corrects the draw when standings change after a re-score.
    foreach ([$homeTeamId, $awayTeamId] as $tid) {
        $staleStmt = $conn->prepare("
            SELECT ts.match_id
            FROM   tbl_teamschedule ts
            INNER  JOIN tbl_match m ON m.match_id = ts.match_id
            WHERE  ts.team_id     = ?
              AND  m.bracket_side = ?
              AND  NOT EXISTS (
                  SELECT 1 FROM tbl_score sc WHERE sc.match_id = ts.match_id
              )
            LIMIT 1
        ");
        $staleStmt->bind_param('is', $tid, $targetType);
        $staleStmt->execute();
        $staleRow = $staleStmt->get_result()->fetch_assoc();
        $staleStmt->close();

        if ($staleRow) {
            $delStmt = $conn->prepare(
                "DELETE FROM tbl_teamschedule WHERE match_id = ? AND team_id = ?"
            );
            $delStmt->bind_param('ii', $staleRow['match_id'], $tid);
            $delStmt->execute();
            $delStmt->close();
        }
    }

    // ── Step 3: Find the first completely empty slot and insert both teams ─
    $stmt = $conn->prepare("
        SELECT m.match_id,
               (
                   SELECT COUNT(*)
                   FROM   tbl_teamschedule ts2
                   WHERE  ts2.match_id = m.match_id
               ) AS team_count
        FROM   tbl_match m
        LEFT   JOIN tbl_schedule s ON s.schedule_id = m.schedule_id
        WHERE  m.bracket_side = ?
        HAVING team_count = 0
        ORDER  BY s.schedule_start ASC, m.match_id ASC
        LIMIT  1
    ");
    $stmt->bind_param('s', $targetType);
    $stmt->execute();
    $slotRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$slotRow) {
        return [
            'seeded' => false,
            'reason' => "No empty match slot found for bracket_type='$targetType'",
        ];
    }

    $targetMatchId = intval($slotRow['match_id']);

    // Insert HOME team
    $ins = $conn->prepare("
        INSERT IGNORE INTO tbl_teamschedule
            (match_id, round_id, team_id, referee_id, arena_number)
        VALUES (?, 1, ?, ?, 1)
    ");
    $ins->bind_param('iii', $targetMatchId, $homeTeamId, $refereeId);
    $ins->execute();
    $homeAffected = $ins->affected_rows;
    $ins->close();

    // Insert AWAY team
    $ins2 = $conn->prepare("
        INSERT IGNORE INTO tbl_teamschedule
            (match_id, round_id, team_id, referee_id, arena_number)
        VALUES (?, 1, ?, ?, 1)
    ");
    $ins2->bind_param('iii', $targetMatchId, $awayTeamId, $refereeId);
    $ins2->execute();
    $awayAffected = $ins2->affected_rows;
    $ins2->close();

    return [
        'seeded'       => ($homeAffected > 0 || $awayAffected > 0),
        'match_id'     => $targetMatchId,
        'home_team_id' => $homeTeamId,
        'away_team_id' => $awayTeamId,
        'home_rows'    => $homeAffected,
        'away_rows'    => $awayAffected,
    ];
}

// ═════════════════════════════════════════════════════════════════════
// HELPER — seed ONE team into the next available slot (knockout rounds)
// ═════════════════════════════════════════════════════════════════════
function seedIntoRound(mysqli $conn, string $targetType, int $teamId, int $refereeId, int $categoryId): array {

    $stmt = $conn->prepare("
        SELECT m.match_id,
               s.schedule_start,
               (
                   SELECT COUNT(*)
                   FROM   tbl_teamschedule ts2
                   WHERE  ts2.match_id = m.match_id
               ) AS team_count,
               (
                   SELECT COUNT(*)
                   FROM   tbl_teamschedule ts3
                   WHERE  ts3.match_id = m.match_id
                     AND  ts3.team_id  = ?
               ) AS already_in
        FROM   tbl_match m
        LEFT   JOIN tbl_schedule s ON s.schedule_id = m.schedule_id
        WHERE  m.bracket_side = ?
        ORDER  BY s.schedule_start ASC, m.match_id ASC
    ");
    $stmt->bind_param('is', $teamId, $targetType);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $targetMatchId = null;
    while ($r = $result->fetch_assoc()) {
        if (intval($r['already_in']) > 0) continue;
        if (intval($r['team_count']) >= 2) continue;
        $targetMatchId = intval($r['match_id']);
        break;
    }

    if (!$targetMatchId) {
        $debugStmt = $conn->prepare("
            SELECT m.match_id,
                   (SELECT COUNT(*) FROM tbl_teamschedule ts WHERE ts.match_id = m.match_id) AS cnt
            FROM   tbl_match m
            WHERE  m.bracket_side = ?
            ORDER  BY m.match_id ASC
        ");
        $debugStmt->bind_param('s', $targetType);
        $debugStmt->execute();
        $debugRes = $debugStmt->get_result();
        $debugStmt->close();

        $debugInfo = [];
        while ($d = $debugRes->fetch_assoc()) {
            $debugInfo[] = ['match_id' => $d['match_id'], 'team_count' => $d['cnt']];
        }

        return [
            'seeded'  => false,
            'reason'  => "No available slot found for bracket_type='$targetType'",
            'matches' => $debugInfo,
        ];
    }

    $ins = $conn->prepare("
        INSERT IGNORE INTO tbl_teamschedule
            (match_id, round_id, team_id, referee_id, arena_number)
        VALUES (?, 1, ?, ?, 1)
    ");
    $ins->bind_param('iii', $targetMatchId, $teamId, $refereeId);
    $ins->execute();
    $affected = $ins->affected_rows;
    $ins->close();

    if ($affected > 0) {
        return ['seeded' => true, 'match_id' => $targetMatchId];
    }

    return [
        'seeded' => false,
        'reason' => "INSERT IGNORE had 0 affected rows for match_id=$targetMatchId (duplicate?)",
    ];
}
?>