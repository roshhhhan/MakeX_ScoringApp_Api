<?php
// ─────────────────────────────────────────────────────────────────────
// get_group_standings.php
// GET ?category_id=4
//
// Returns per-group standings for soccer qualification (group stage).
// Calculates MP, W, D, L, GF, GA, GD, Pts live from tbl_score.
// Groups come from tbl_soccer_groups; goals from score_independentscore.
//
// Response: array of rows ordered by group_label ASC, pts DESC, gd DESC, gf DESC
// [
//   {
//     "group_label": "A",
//     "team_id":     12,
//     "team_name":   "Team Alpha",
//     "mp": 2, "w": 1, "d": 1, "l": 0,
//     "gf": 3, "ga": 1, "gd": 2, "pts": 4
//   }, ...
// ]
// ─────────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once 'db_config.php';

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if ($category_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'category_id is required']);
    exit();
}

// ── 1. Get all teams in each group for this category ──────────────────
$stmt = $conn->prepare("
    SELECT sg.group_label, sg.team_id, t.team_name
    FROM tbl_soccer_groups sg
    INNER JOIN tbl_team t ON t.team_id = sg.team_id
    WHERE sg.category_id = ?
    ORDER BY sg.group_label ASC, sg.team_id ASC
");
$stmt->bind_param('i', $category_id);
$stmt->execute();
$groupRes = $stmt->get_result();
$stmt->close();

// Build a map: team_id → { group_label, team_name }
$teamInfo = [];
$groups   = []; // group_label → [ team_id, ... ]
while ($r = $groupRes->fetch_assoc()) {
    $tid   = intval($r['team_id']);
    $label = $r['group_label'];
    $teamInfo[$tid] = [
        'group_label' => $label,
        'team_name'   => $r['team_name'],
    ];
    $groups[$label][] = $tid;
}

if (empty($teamInfo)) {
    echo json_encode([]);
    exit();
}

// ── 2. Get all scored group-stage matches for this category ───────────
// Each tbl_score row = one team's result in one match.
// score_independentscore = goals scored by that team.
$stmt = $conn->prepare("
    SELECT sc.match_id, sc.team_id, sc.score_independentscore AS goals
    FROM tbl_score sc
    INNER JOIN tbl_match  m ON m.match_id = sc.match_id
    INNER JOIN tbl_team   t ON t.team_id  = sc.team_id
    WHERE t.category_id  = ?
    AND m.bracket_side = 'group'
    ORDER BY sc.match_id ASC
");
$stmt->bind_param('i', $category_id);
$stmt->execute();
$scoreRes = $stmt->get_result();
$stmt->close();

// Group score rows by match_id → [ team_id => goals ]
$matchScores = [];
while ($r = $scoreRes->fetch_assoc()) {
    $mid  = intval($r['match_id']);
    $tid  = intval($r['team_id']);
    $goals = intval($r['goals']);
    $matchScores[$mid][$tid] = $goals;
}

// ── 3. Initialise standings table ─────────────────────────────────────
// stats: mp, w, d, l, gf, ga, pts
$stats = [];
foreach ($teamInfo as $tid => $_) {
    $stats[$tid] = ['mp' => 0, 'w' => 0, 'd' => 0, 'l' => 0,
                    'gf' => 0, 'ga' => 0, 'pts' => 0];
}

// ── 4. Process each match ─────────────────────────────────────────────
foreach ($matchScores as $mid => $teams) {
    // A match must have exactly 2 team score rows to be fully scored
    if (count($teams) !== 2) continue;

    $tids   = array_keys($teams);
    $t1     = $tids[0];
    $t2     = $tids[1];
    $g1     = $teams[$t1];
    $g2     = $teams[$t2];

    // Only process if both teams are in our group map
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

// ── 5. Build output grouped and sorted ───────────────────────────────
$output = [];
foreach ($groups as $label => $teamIds) {
    $rows = [];
    foreach ($teamIds as $tid) {
        $s  = $stats[$tid];
        $gd = $s['gf'] - $s['ga'];
        $rows[] = [
            'group_label' => $label,
            'team_id'     => $tid,
            'team_name'   => $teamInfo[$tid]['team_name'],
            'mp'          => $s['mp'],
            'w'           => $s['w'],
            'd'           => $s['d'],
            'l'           => $s['l'],
            'gf'          => $s['gf'],
            'ga'          => $s['ga'],
            'gd'          => $gd,
            'pts'         => $s['pts'],
        ];
    }
    // Sort: pts DESC → gd DESC → gf DESC → team_name ASC
    usort($rows, function($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] - $a['pts'];
        if ($b['gd']  !== $a['gd'])  return $b['gd']  - $a['gd'];
        if ($b['gf']  !== $a['gf'])  return $b['gf']  - $a['gf'];
        return strcmp($a['team_name'], $b['team_name']);
    });
    foreach ($rows as $row) {
        $output[] = $row;
    }
}

$conn->close();
echo json_encode($output);
?>