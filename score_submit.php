<?php
// ─────────────────────────────────────────────────────────────────────
// scoring.php
//
// ENDPOINTS
//   GET  scoring.php?action=check_score&match_id=1&team_id=1
//   GET  scoring.php?action=get_match&match_id=1
//   GET  scoring.php?action=get_referee&referee_id=1
//   GET  scoring.php?action=get_team&team_id=1
//   GET  scoring.php?action=get_categories
//   GET  scoring.php?action=get_rounds
//   GET  scoring.php?action=get_match_scores&match_id=1
//   GET  scoring.php?action=get_team_count&category_id=4
//   GET  scoring.php?action=get_group_standings&category_id=4
//   GET  scoring.php?action=get_qualifiers&category_id=4
//   GET  scoring.php?action=get_knockout_matches&category_id=4&bracket_type=quarter-finals
//   POST scoring.php?action=submit_score          (JSON body)
//   POST scoring.php?action=championship_submit_score  (JSON body)
// ─────────────────────────────────────────────────────────────────────

require_once 'db_config.php';

// ─────────────────────────────────────────────────────────────────────
// DB CONNECTION
// ─────────────────────────────────────────────────────────────────────
function getConnection() {
    global $conn;
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit();
    }
    return $conn;
}

function boolFromValue($value): bool {
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return intval($value) === 1;
    if (is_string($value)) {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes'], true);
    }
    return false;
}

function getScoreTableName($isExplorer = false, $isStarter = false): string {
    if ($isExplorer) return 'tbl_explorer_score';
    if ($isStarter) return 'tbl_starter_score';
    return 'tbl_score';
}

function getBestOf3TableName(): string {
    return 'tbl_championship_bestof3';
}

function resolveExplorerChampionshipSchedule($conn, int $categoryId, int $matchNumber, string $matchRound, int $matchPosition, string $bracketSide): array {
    $matchRound = trim($matchRound);

    $sql = "SELECT match_id, alliance1_id, alliance2_id, match_round, match_position, bracket_side
            FROM tbl_explorer_championship_schedule
            WHERE category_id = ?
              AND match_number = ?
              AND match_round = ?
              AND match_position = ?
              AND bracket_side = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iisis", $categoryId, $matchNumber, $matchRound, $matchPosition, $bracketSide);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: [];
        $stmt->close();
    } else {
        $row = [];
    }

    if (empty($row) && $matchNumber > 0 && $matchRound !== '') {
        $sql = "SELECT match_id, alliance1_id, alliance2_id, match_round, match_position, bracket_side
                FROM tbl_explorer_championship_schedule
                WHERE category_id = ?
                  AND match_number = ?
                  AND match_round = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iis", $categoryId, $matchNumber, $matchRound);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc() ?: [];
            }
            $stmt->close();
        }
    }

    if (empty($row) && $matchNumber > 0 && $matchPosition > 0 && $bracketSide !== '') {
        $sql = "SELECT match_id, alliance1_id, alliance2_id, match_round, match_position, bracket_side
                FROM tbl_explorer_championship_schedule
                WHERE category_id = ?
                  AND match_number = ?
                  AND match_position = ?
                  AND bracket_side = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iiis", $categoryId, $matchNumber, $matchPosition, $bracketSide);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc() ?: [];
            }
            $stmt->close();
        }
    }

    if (empty($row) && $matchNumber > 0) {
        $sql = "SELECT match_id, alliance1_id, alliance2_id, match_round, match_position, bracket_side
                FROM tbl_explorer_championship_schedule
                WHERE category_id = ?
                  AND match_number = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $categoryId, $matchNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc() ?: [];
            }
            $stmt->close();
        }
    }

    return $row;
}

function getExplorerChampionshipAllianceTeamIds($conn, int $allianceId): array {
    if ($allianceId <= 0) {
        return [];
    }

    $sql = "SELECT captain_team_id, partner_team_id
            FROM tbl_alliance_selections
            WHERE alliance_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $allianceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: [];
    $stmt->close();

    if (empty($row)) {
        return [];
    }

    return [
        intval($row['captain_team_id']),
        intval($row['partner_team_id']),
    ];
}

function getExplorerChampionshipAllianceScore($conn, int $matchId, int $allianceId): ?array {
    if ($matchId <= 0 || $allianceId <= 0) {
        return null;
    }

    $teamIds = getExplorerChampionshipAllianceTeamIds($conn, $allianceId);
    if (empty($teamIds)) {
        return null;
    }

    $sql = "SELECT 
                MAX(score_alliance) AS score_alliance,
                MAX(score_violation) AS score_violation
            FROM tbl_explorer_score
            WHERE match_id = ?
              AND team_id IN (?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("iii", $matchId, $teamIds[0], $teamIds[1]);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function determineChampionshipWinnerAllianceId(int $allianceId, int $opponentAllianceId, int $allianceScore, int $opponentScore): int {
    if ($allianceScore > $opponentScore) {
        return $allianceId;
    }
    if ($opponentScore > $allianceScore) {
        return $opponentAllianceId;
    }
    return 0;
}

function resolveExplorerMatchId($conn, $matchId, $teamId, $roundId): int {
    // CRITICAL: IGNORE the scoring app's matchId completely
    // Always look up by team_id and round_id from the schedule table
    
    error_log("resolveExplorerMatchId: looking up real match_id for team=$teamId, round=$roundId");
    
    // First try to find by team_id and round_id in explorer teamschedule
    $query = "SELECT match_id FROM tbl_explorer_teamschedule WHERE team_id = ? AND round_id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("resolveExplorerMatchId: prepare failed");
        return 0;
    }
    $stmt->bind_param("ii", $teamId, $roundId);
    $stmt->execute();
    $stmt->bind_result($resolvedMatchId);
    $stmt->fetch();
    $stmt->close();
    
    if ($resolvedMatchId > 0) {
        error_log("resolveExplorerMatchId: found real match_id = $resolvedMatchId (scoring app sent $matchId)");
        return (int)$resolvedMatchId;
    }
    
    // Fallback: try without round_id
    $query2 = "SELECT match_id FROM tbl_explorer_teamschedule WHERE team_id = ? LIMIT 1";
    $stmt2 = $conn->prepare($query2);
    if ($stmt2) {
        $stmt2->bind_param("i", $teamId);
        $stmt2->execute();
        $stmt2->bind_result($resolvedMatchId2);
        $stmt2->fetch();
        $stmt2->close();
        if ($resolvedMatchId2 > 0) {
            error_log("resolveExplorerMatchId: fallback found real match_id = $resolvedMatchId2");
            return (int)$resolvedMatchId2;
        }
    }
    
    error_log("resolveExplorerMatchId: WARNING - could not find real match_id for team=$teamId, round=$roundId");
    return 0; // Return 0 so we don't use the scoring app's fake ID
}

// ─────────────────────────────────────────────────────────────────────
// AUTO-COMPLETE MATCH FUNCTION
// ─────────────────────────────────────────────────────────────────────
function autoCompleteMatchIfBothScored($conn, $matchId, $teamId) {
    try {
        error_log("=== AUTO-COMPLETE FUNCTION START ===");
        error_log("Received matchId=$matchId, teamId=$teamId");
        
        // Get round_id from team_id
        $roundStmt = $conn->prepare("
            SELECT DISTINCT ts.round_id, t.category_id, c.category_type
            FROM tbl_explorer_teamschedule ts
            JOIN tbl_team t ON ts.team_id = t.team_id
            JOIN tbl_category c ON t.category_id = c.category_id
            WHERE ts.team_id = ?
            LIMIT 1
        ");
        $roundStmt->bind_param("i", $teamId);
        $roundStmt->execute();
        $roundResult = $roundStmt->get_result();
        
        if ($roundResult->num_rows == 0) {
            error_log("No round found for team $teamId");
            return false;
        }
        
        $info = $roundResult->fetch_assoc();
        $roundId = $info['round_id'];
        $categoryType = strtolower($info['category_type']);
        $roundStmt->close();
        
        $isExplorer = strpos($categoryType, 'explorer') !== false;
        if (!$isExplorer) {
            // Handle starter if needed
            error_log("Not explorer category, skipping auto-complete");
            return false;
        }
        
        // Get ALL teams in this round from schedule
        $teamsQuery = "
            SELECT DISTINCT team_id, match_id FROM tbl_explorer_teamschedule
            WHERE round_id = ?
        ";
        $teamsStmt = $conn->prepare($teamsQuery);
        $teamsStmt->bind_param("i", $roundId);
        $teamsStmt->execute();
        $teamsResult = $teamsStmt->get_result();
        
        $teamIds = [];
        $realMatchId = 0;
        while ($row = $teamsResult->fetch_assoc()) {
            $teamIds[] = $row['team_id'];
            if ($realMatchId == 0) $realMatchId = $row['match_id'];
        }
        $teamsStmt->close();
        
        error_log("Teams in round $roundId: " . implode(',', $teamIds));
        error_log("Real match_id: $realMatchId");
        
        // Check which teams have scores in tbl_explorer_score
        $teamsWithScores = 0;
        foreach ($teamIds as $tid) {
            $scoreQuery = "
                SELECT COUNT(*) as cnt FROM tbl_explorer_score
                WHERE round_id = ? AND team_id = ?
            ";
            $scoreStmt = $conn->prepare($scoreQuery);
            $scoreStmt->bind_param("ii", $roundId, $tid);
            $scoreStmt->execute();
            $scoreResult = $scoreStmt->get_result();
            $scoreRow = $scoreResult->fetch_assoc();
            
            if ($scoreRow['cnt'] > 0) {
                $teamsWithScores++;
                error_log("Team $tid HAS score");
            } else {
                error_log("Team $tid NO score");
            }
            $scoreStmt->close();
        }
        
        $expectedTeams = 4; // Explorer has 4 teams per round
        error_log("Teams with scores: $teamsWithScores / $expectedTeams");
        
        if ($teamsWithScores >= $expectedTeams) {
            error_log("ALL TEAMS HAVE SCORES! Updating match status...");
            
            // Update the team schedule table
            $updateStmt = $conn->prepare("
                UPDATE tbl_explorer_teamschedule 
                SET status = 'completed'
                WHERE match_id = ? AND round_id = ?
            ");
            $updateStmt->bind_param("ii", $realMatchId, $roundId);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Update the match table
            $updateMatchStmt = $conn->prepare("
                UPDATE tbl_match 
                SET status = 'completed'
                WHERE match_id = ?
            ");
            $updateMatchStmt->bind_param("i", $realMatchId);
            $updateMatchStmt->execute();
            $updateMatchStmt->close();
            
            error_log("Match $realMatchId marked as completed for round $roundId");
            return true;
        }
        
        error_log("Waiting for " . ($expectedTeams - $teamsWithScores) . " more team(s)");
        return false;
        
    } catch (Exception $e) {
        error_log("Auto-complete error: " . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────
// ROUTER
// ─────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action parameter']);
    exit();
}

switch ($action) {

    // ── GET TODAY'S EXPLORER CHAMPIONSHIP MATCHES ──────────────────────
    case 'get_today_matches':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        if ($categoryId <= 0) { badRequest('Invalid category_id'); break; }

        $conn = getConnection();
        $sql = "SELECT 
                cs.match_id,
                cs.match_round,
                cs.match_position,
                cs.match_number,
                cs.schedule_time,
                cs.status,
                cs.alliance1_id,
                cs.alliance2_id,
                cs.winner_alliance_id,
                cs.round_name,
                cs.bracket_side,
                cs.arena_number,
                a1.captain_team_id as captain1_id,
                a1.partner_team_id as partner1_id,
                a2.captain_team_id as captain2_id,
                a2.partner_team_id as partner2_id,
                a1.selection_round as alliance1_rank,
                a2.selection_round as alliance2_rank,
                COALESCE(t1.team_name, 'Unknown') as captain1_name,
                COALESCE(t2.team_name, 'Unknown') as partner1_name,
                COALESCE(t3.team_name, 'Unknown') as captain2_name,
                COALESCE(t4.team_name, 'Unknown') as partner2_name
            FROM tbl_explorer_championship_schedule cs
            LEFT JOIN tbl_alliance_selections a1 ON cs.alliance1_id = a1.alliance_id
            LEFT JOIN tbl_alliance_selections a2 ON cs.alliance2_id = a2.alliance_id
            LEFT JOIN tbl_team t1 ON a1.captain_team_id = t1.team_id
            LEFT JOIN tbl_team t2 ON a1.partner_team_id = t2.team_id
            LEFT JOIN tbl_team t3 ON a2.captain_team_id = t3.team_id
            LEFT JOIN tbl_team t4 ON a2.partner_team_id = t4.team_id
            WHERE cs.category_id = ?
                AND cs.status IN ('pending', 'in_progress')
                AND cs.alliance1_id IS NOT NULL 
                AND cs.alliance1_id > 0
                AND cs.alliance2_id IS NOT NULL 
                AND cs.alliance2_id > 0
            ORDER BY cs.schedule_time ASC, cs.match_round ASC, cs.match_position ASC, cs.match_number ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();

        $matches = [];
        while ($row = $result->fetch_assoc()) {
            $row['alliance1_name'] = $row['captain1_name'] . ' / ' . $row['partner1_name'];
            $row['alliance2_name'] = $row['captain2_name'] . ' / ' . $row['partner2_name'];
            $row['alliance1_rank_display'] = '#' . $row['alliance1_rank'];
            $row['alliance2_rank_display'] = '#' . $row['alliance2_rank'];

            $scores = [];
            $scoreSql = "SELECT 
                        team_id, 
                        score_individual, 
                        score_alliance, 
                        score_violation, 
                        score_totalscore 
                    FROM tbl_explorer_score 
                    WHERE match_id = ? AND round_id = ?";
            $scoreStmt = $conn->prepare($scoreSql);
            $scoreStmt->bind_param("ii", $row['match_id'], $row['match_round']);
            $scoreStmt->execute();
            $scoreResult = $scoreStmt->get_result();
            while ($scoreRow = $scoreResult->fetch_assoc()) {
                $scores[$scoreRow['team_id']] = $scoreRow;
            }
            $scoreStmt->close();

            $row['scores'] = $scores;
            $matches[] = $row;
        }

        $stmt->close();
        $conn->close();

        echo json_encode([
            'success' => true,
            'matches' => $matches,
            'count' => count($matches)
        ]);
        break;

    // ── GET MATCH ─────────────────────────────────────────────────────
    case 'get_match':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
        if ($match_id <= 0) { badRequest('Invalid or missing match_id'); break; }

        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT
                m.match_id,
                m.schedule_id,
                m.bracket_side AS bracket_type,
                s.schedule_start,
                s.schedule_end,
                TIME_FORMAT(s.schedule_start, '%H:%i') AS match_time
            FROM tbl_match m
            INNER JOIN tbl_schedule s ON m.schedule_id = s.schedule_id
            WHERE m.match_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $match_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Match not found']);
        }

        $stmt->close();
        $conn->close();
        break;

    // ── GET REFEREE ───────────────────────────────────────────────────
    case 'get_referee':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $referee_id = isset($_GET['referee_id']) ? intval($_GET['referee_id']) : 0;
        if ($referee_id <= 0) { badRequest('Invalid or missing referee_id'); break; }

        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT
                referee_id,
                referee_name
            FROM tbl_referee
            WHERE referee_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $referee_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Referee not found']);
        }

        $stmt->close();
        $conn->close();
        break;

    // ── GET TEAM ──────────────────────────────────────────────────────
    case 'get_team':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $team_id = isset($_GET['team_id']) ? intval($_GET['team_id']) : 0;
        if ($team_id <= 0) { badRequest('Invalid or missing team_id'); break; }

        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT
                team_id,
                team_name,
                team_ispresent,
                mentor_id,
                category_id
            FROM tbl_team
            WHERE team_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $team_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Team not found']);
        }

        $stmt->close();
        $conn->close();
        break;

    // ── GET CATEGORIES ────────────────────────────────────────────────
    case 'get_categories':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $conn = getConnection();
        $columns = [];
        $colResult = $conn->query("SHOW COLUMNS FROM tbl_category");
        if ($colResult) {
            while ($col = $colResult->fetch_assoc()) {
                $columns[] = $col['Field'];
            }
            $colResult->free();
        }

        $selectFields = ['category_id', 'category_type'];
        if (in_array('status', $columns)) {
            $selectFields[] = 'status';
        }

        $sql = "SELECT " . implode(',', $selectFields) . " FROM tbl_category ORDER BY category_id ASC";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'Query failed: ' . $conn->error]);
            $conn->close();
            break;
        }

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $item = [
                'category_id'   => $row['category_id'],
                'category_type' => $row['category_type'],
            ];
            if (isset($row['status'])) {
                $item['status'] = $row['status'];
            } else {
                $item['status'] = 'active';
            }
            $categories[] = $item;
        }

        echo json_encode($categories);
        $conn->close();
        break;

    // ── GET ROUNDS ────────────────────────────────────────────────────
    case 'get_rounds':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $conn = getConnection();
        $result = $conn->query("
            SELECT
                round_id,
                round_type
            FROM tbl_round
            ORDER BY round_id ASC
        ");

        $rounds = [];
        while ($row = $result->fetch_assoc()) {
            $rounds[] = $row;
        }

        echo json_encode($rounds);
        $conn->close();
        break;

    // ── SUBMIT SCORE ──────────────────────────────────────────────────
    case 'submit_score':
        if ($method !== 'POST') { methodNotAllowed(); break; }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!$data) { badRequest('Invalid or empty JSON body'); break; }

        $required = [
            'match_id', 'round_id', 'team_id', 'referee_id',
            'score_independentscore', 'score_violation',
            'score_totalscore', 'score_totalduration'
        ];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                badRequest("Missing required field: $field");
                exit();
            }
        }

        $match_id = intval($data['match_id']);
        $round_id = intval($data['round_id']);
        $team_id = intval($data['team_id']);
        $referee_id = intval($data['referee_id']);
        $independentScore = isset($data['score_individual'])
            ? intval($data['score_individual'])
            : intval($data['score_independentscore']);
        $allianceScore = isset($data['score_alliance'])
            ? intval($data['score_alliance'])
            : (isset($data['score_alliancescore']) ? intval($data['score_alliancescore']) : 0);
        $manualScore = isset($data['score_manualscore'])
            ? intval($data['score_manualscore'])
            : (isset($data['score_manual']) ? intval($data['score_manual']) : 0);
        error_log('submit_score resolved manualScore=' . $manualScore);
        $violation = intval($data['score_violation']);
        $totalScore = intval($data['score_totalscore']);
        $totalDuration = $data['score_totalduration'];
        $isApproved       = isset($data['score_isapproved']) ? intval($data['score_isapproved']) : 0;
        $isExplorer       = boolFromValue($data['is_explorer'] ?? false);
        $isStarter        = boolFromValue($data['is_starter'] ?? false);
        $scoreTable       = getScoreTableName($isExplorer, $isStarter);

        if ($isStarter && !isset($data['score_alliance']) && !isset($data['score_alliancescore'])) {
            badRequest('Missing required field: score_alliance');
            break;
        }

        error_log('submit_score request body: ' . $body);
        error_log('submit_score fields: score_manualscore=' .
            (isset($data['score_manualscore']) ? $data['score_manualscore'] : 'MISSING') .
            ', score_manual=' .
            (isset($data['score_manual']) ? $data['score_manual'] : 'MISSING') .
            ', is_explorer=' .
            (isset($data['is_explorer']) ? $data['is_explorer'] : 'MISSING') .
            ', match_id=' . $data['match_id'] .
            ', team_id=' . $data['team_id'] .
            ', round_id=' . $data['round_id']);

        $conn = getConnection();

        if ($isExplorer && isset($data['scores']) && is_array($data['scores'])) {
            $stmt = $conn->prepare("
                INSERT INTO $scoreTable (
                    score_independentscore,
                    score_manualscore,
                    score_violation,
                    score_totalscore,
                    score_totalduration,
                    score_isapproved,
                    match_id,
                    round_id,
                    team_id,
                    referee_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score_independentscore = VALUES(score_independentscore),
                    score_manualscore = VALUES(score_manualscore),
                    score_violation = VALUES(score_violation),
                    score_totalscore = VALUES(score_totalscore),
                    score_totalduration = VALUES(score_totalduration),
                    score_isapproved = VALUES(score_isapproved),
                    referee_id = VALUES(referee_id)
            ");

            if (!$stmt) {
                http_response_code(500);
                echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
                $conn->close();
                break;
            }

            $results = [];
            $autoCompletedAny = false;

            foreach ($data['scores'] as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemMatchId = isset($item['match_id']) ? intval($item['match_id']) : $match_id;
                $itemRoundId = isset($item['round_id']) ? intval($item['round_id']) : $round_id;
                $itemTeamId = isset($item['team_id']) ? intval($item['team_id']) : 0;
                $itemRefereeId = isset($item['referee_id']) ? intval($item['referee_id']) : $referee_id;
                $itemIndependent = isset($item['score_independentscore']) ? intval($item['score_independentscore']) : 0;
                $itemManual = isset($item['score_manualscore'])
                    ? intval($item['score_manualscore'])
                    : (isset($item['score_manual']) ? intval($item['score_manual']) : 0);
                $itemViolation = isset($item['score_violation']) ? intval($item['score_violation']) : 0;
                $itemTotal = isset($item['score_totalscore']) ? intval($item['score_totalscore']) : 0;
                $itemDuration = isset($item['score_totalduration']) ? $item['score_totalduration'] : $totalDuration;
                $itemApproved = isset($item['score_isapproved']) ? intval($item['score_isapproved']) : $isApproved;

                if ($itemTeamId <= 0 || $itemRoundId <= 0) {
                    $results[] = ['index' => $idx, 'success' => false, 'error' => 'Missing team_id or round_id'];
                    continue;
                }

                $resolvedMatchId = resolveExplorerMatchId($conn, $itemMatchId, $itemTeamId, $itemRoundId);
                if ($resolvedMatchId <= 0) {
                    $results[] = ['index' => $idx, 'success' => false, 'error' => 'Unable to resolve explorer match_id'];
                    continue;
                }

                $stmt->bind_param(
                    "iiiisiiiii",
                    $itemIndependent,
                    $itemManual,
                    $itemViolation,
                    $itemTotal,
                    $itemDuration,
                    $itemApproved,
                    $resolvedMatchId,
                    $itemRoundId,
                    $itemTeamId,
                    $itemRefereeId
                );

                if ($stmt->execute()) {
                    $autoCompleted = autoCompleteMatchIfBothScored(
                        $conn,
                        $resolvedMatchId,
                        $itemTeamId
                    );
                    $autoCompletedAny = $autoCompletedAny || $autoCompleted;
                    $results[] = [
                        'index' => $idx,
                        'success' => true,
                        'match_id' => $resolvedMatchId,
                        'team_id' => $itemTeamId,
                        'round_id' => $itemRoundId,
                        'auto_completed' => $autoCompleted,
                    ];
                } else {
                    $results[] = [
                        'index' => $idx,
                        'success' => false,
                        'error' => $stmt->error,
                    ];
                }
            }

            $stmt->close();
            $conn->close();

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'table' => $scoreTable,
                'auto_completed' => $autoCompletedAny,
                'results' => $results,
                'score_manualscore' => isset($data['score_manualscore']) ? intval($data['score_manualscore']) : null,
                'score_manual' => isset($data['score_manual']) ? intval($data['score_manual']) : null,
            ]);
            break;
        }

        if ($isExplorer) {
    $resolvedMatchId = resolveExplorerMatchId($conn, $match_id, $team_id, $round_id);
    if ($resolvedMatchId > 0) {
        $match_id = $resolvedMatchId;
        error_log("submit_score: Using resolved match_id=$match_id for team $team_id");
    } else {
        error_log("submit_score: WARNING - Could not resolve match_id for team $team_id, using original $match_id");
        // DO NOT use the scoring app's match_id - it will be 1000001 which is wrong!
        // Instead, try to get from schedule by round_id
        $scheduleQuery = "SELECT DISTINCT match_id FROM tbl_explorer_teamschedule WHERE round_id = ? LIMIT 1";
        $scheduleStmt = $conn->prepare($scheduleQuery);
        if ($scheduleStmt) {
            $scheduleStmt->bind_param("i", $round_id);
            $scheduleStmt->execute();
            $scheduleStmt->bind_result($scheduleMatchId);
            $scheduleStmt->fetch();
            $scheduleStmt->close();
            if ($scheduleMatchId > 0) {
                $match_id = $scheduleMatchId;
                error_log("submit_score: Using schedule match_id=$match_id from round_id=$round_id");
            }
        }
    }
}
        
        if ($isStarter) {
            $stmt = $conn->prepare("
                INSERT INTO $scoreTable (
                    score_independentscore,
                    score_individual,
                    score_alliance,
                    score_manualscore,
                    score_violation,
                    score_totalscore,
                    score_totalduration,
                    score_isapproved,
                    match_id,
                    round_id,
                    team_id,
                    referee_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score_independentscore = VALUES(score_independentscore),
                    score_individual = VALUES(score_individual),
                    score_alliance = VALUES(score_alliance),
                    score_manualscore = VALUES(score_manualscore),
                    score_violation = VALUES(score_violation),
                    score_totalscore = VALUES(score_totalscore),
                    score_totalduration = VALUES(score_totalduration),
                    score_isapproved = VALUES(score_isapproved),
                    referee_id = VALUES(referee_id)
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO $scoreTable (
                    score_independentscore,
                    score_manualscore,
                    score_violation,
                    score_totalscore,
                    score_totalduration,
                    score_isapproved,
                    match_id,
                    round_id,
                    team_id,
                    referee_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score_independentscore = VALUES(score_independentscore),
                    score_manualscore = VALUES(score_manualscore),
                    score_violation = VALUES(score_violation),
                    score_totalscore = VALUES(score_totalscore),
                    score_totalduration = VALUES(score_totalduration),
                    score_isapproved = VALUES(score_isapproved),
                    referee_id = VALUES(referee_id)
            ");
        }

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }

        if ($isStarter) {
            $stmt->bind_param(
                "iiiiiisiiiii",
                $independentScore,
                $independentScore,
                $allianceScore,
                $manualScore,
                $violation,
                $totalScore,
                $totalDuration,
                $isApproved,
                $match_id,
                $round_id,
                $team_id,
                $referee_id
            );
        } else {
            $stmt->bind_param(
                "iiiisiiiii",
                $independentScore,
                $manualScore,
                $violation,
                $totalScore,
                $totalDuration,
                $isApproved,
                $match_id,
                $round_id,
                $team_id,
                $referee_id
            );
        }

        if ($stmt->execute()) {
            // ============================================================
            // CRITICAL: Call auto-complete after saving score
            // ============================================================
            $autoCompleted = autoCompleteMatchIfBothScored($conn, $match_id, $team_id);

            $verifiedManualScore = null;
            $verifiedIndependentScore = null;
            $verifiedAllianceScore = null;
            $verifiedTotalScore = null;
            $verifiedIndividualScore = null;
            $verifyQuery = "SELECT score_independentscore, score_manualscore, score_totalscore";
            if ($isStarter) {
                $verifyQuery .= ", score_individual, score_alliance";
            }
            $verifyQuery .= " FROM $scoreTable WHERE match_id = ? AND team_id = ? AND round_id = ? LIMIT 1";

            $verifyStmt = $conn->prepare($verifyQuery);
            if ($verifyStmt) {
                $verifyStmt->bind_param("iii", $match_id, $team_id, $round_id);
                $verifyStmt->execute();
                if ($isStarter) {
                    $verifyStmt->bind_result($verifiedIndependentScore, $verifiedManualScore, $verifiedTotalScore, $verifiedIndividualScore, $verifiedAllianceScore);
                } else {
                    $verifyStmt->bind_result($verifiedIndependentScore, $verifiedManualScore, $verifiedTotalScore);
                }
                $verifyStmt->fetch();
                $verifyStmt->close();
            }
            
            http_response_code(201);
            $response = [
                'success'        => true,
                'score_id'       => $conn->insert_id,
                'table'          => $scoreTable,
                'match_id'       => $match_id,
                'team_id'        => $team_id,
                'round_id'       => $round_id,
                'score_manualscore' => $manualScore,
                'score_manualscore_saved' => $verifiedManualScore,
                'score_independentscore_saved' => $verifiedIndependentScore,
                'score_totalscore_saved' => $verifiedTotalScore,
                'auto_completed' => $autoCompleted,
                'message'        => $autoCompleted ? 'Score submitted and match auto-completed!' : 'Score submitted successfully'
            ];
            if ($isStarter) {
                $response['score_individual'] = $independentScore;
                $response['score_alliance'] = $allianceScore;
                $response['score_individual_saved'] = $verifiedIndividualScore;
                $response['score_alliance_saved'] = $verifiedAllianceScore;
            }
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert score: ' . $stmt->error]);
        }

        $stmt->close();
        $conn->close();
        break;

    // ── GET MATCH SCORES ─────────────────────────────────────────────
    case 'get_match_scores':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $match_id   = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
        $isExplorer = boolFromValue($_GET['is_explorer'] ?? false);
        $isStarter  = boolFromValue($_GET['is_starter'] ?? false);
        if ($match_id <= 0) { badRequest('Invalid or missing match_id'); break; }

        $scoreTable = getScoreTableName($isExplorer, $isStarter);
        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT
                sc.score_id,
                sc.team_id,
                t.team_name,
                sc.score_independentscore,
                sc.score_violation,
                sc.score_totalscore,
                sc.score_totalduration,
                sc.score_isapproved,
                sc.round_id,
                sc.referee_id
            FROM $scoreTable sc
            JOIN tbl_team t ON t.team_id = sc.team_id
            WHERE sc.match_id = ?
            ORDER BY sc.score_id ASC
        ");
        $stmt->bind_param("i", $match_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        $stmt->close();
        $conn->close();
        break;

    // ── GET TEAM COUNT ────────────────────────────────────────────────
    case 'get_team_count':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        if ($category_id <= 0) { badRequest('Invalid or missing category_id'); break; }

        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT team_id) AS count
            FROM tbl_soccer_groups
            WHERE category_id = ?
        ");
        $stmt->bind_param("i", $category_id);
        $stmt->execute();
        $row   = $stmt->get_result()->fetch_assoc();
        $count = (int)($row['count'] ?? 0);
        echo json_encode(['count' => $count]);
        $stmt->close();
        $conn->close();
        break;

    // ── GET GROUP STANDINGS ───────────────────────────────────────────
    case 'get_group_standings':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        if ($category_id <= 0) { badRequest('Invalid or missing category_id'); break; }

        $conn = getConnection();

        $gStmt = $conn->prepare("
            SELECT group_label, team_id, team_name
            FROM tbl_soccer_groups
            WHERE category_id = ?
            ORDER BY group_label ASC, team_id ASC
        ");
        $gStmt->bind_param("i", $category_id);
        $gStmt->execute();
        $gRows = $gStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $gStmt->close();

        $groups    = [];
        $teamGroup = [];
        foreach ($gRows as $r) {
            $g   = $r['group_label'];
            $tid = (int)$r['team_id'];
            if (!isset($groups[$g])) $groups[$g] = [];
            $groups[$g][$tid] = $r['team_name'];
            $teamGroup[$tid]  = $g;
        }

        $sStmt = $conn->prepare("
            SELECT s.match_id, s.team_id, s.score_independentscore AS goals
            FROM tbl_score s
            JOIN tbl_team  t ON t.team_id  = s.team_id
            JOIN tbl_match m ON m.match_id = s.match_id
            WHERE t.category_id = ?
              AND m.bracket_side = 'group'
            ORDER BY s.match_id ASC, s.score_id ASC
        ");
        $sStmt->bind_param("i", $category_id);
        $sStmt->execute();
        $sRows = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sStmt->close();

        $matchRows = [];
        foreach ($sRows as $r) {
            $matchRows[(int)$r['match_id']][] = $r;
        }

        $standings = [];
        foreach (array_keys($teamGroup) as $tid) {
            $standings[$tid] = ['played'=>0,'won'=>0,'drawn'=>0,'lost'=>0,
                                'gf'=>0,'ga'=>0,'pts'=>0];
        }

        foreach ($matchRows as $mid => $rows) {
            if (count($rows) < 2) continue;
            $a      = $rows[0];
            $b      = $rows[1];
            $tidA   = (int)$a['team_id'];
            $tidB   = (int)$b['team_id'];
            $goalsA = (int)$a['goals'];
            $goalsB = (int)$b['goals'];

            if (!isset($standings[$tidA]) || !isset($standings[$tidB])) continue;

            $standings[$tidA]['played']++;
            $standings[$tidA]['gf'] += $goalsA;
            $standings[$tidA]['ga'] += $goalsB;
            $standings[$tidB]['played']++;
            $standings[$tidB]['gf'] += $goalsB;
            $standings[$tidB]['ga'] += $goalsA;

            if ($goalsA > $goalsB) {
                $standings[$tidA]['won']++;  $standings[$tidA]['pts'] += 3;
                $standings[$tidB]['lost']++;
            } elseif ($goalsA < $goalsB) {
                $standings[$tidB]['won']++;  $standings[$tidB]['pts'] += 3;
                $standings[$tidA]['lost']++;
            } else {
                $standings[$tidA]['drawn']++; $standings[$tidA]['pts']++;
                $standings[$tidB]['drawn']++; $standings[$tidB]['pts']++;
            }
        }

        $response = [];
        foreach ($groups as $label => $members) {
            $groupStandings = [];
            foreach ($members as $tid => $tname) {
                $s = $standings[$tid] ?? ['played'=>0,'won'=>0,'drawn'=>0,
                                          'lost'=>0,'gf'=>0,'ga'=>0,'pts'=>0];
                $groupStandings[] = [
                    'team_id'   => $tid,
                    'team_name' => $tname,
                    'group'     => $label,
                    'played'    => $s['played'],
                    'won'       => $s['won'],
                    'drawn'     => $s['drawn'],
                    'lost'      => $s['lost'],
                    'gf'        => $s['gf'],
                    'ga'        => $s['ga'],
                    'gd'        => $s['gf'] - $s['ga'],
                    'pts'       => $s['pts'],
                ];
            }
            usort($groupStandings, function($x, $y) {
                if ($x['pts'] !== $y['pts']) return $y['pts'] - $x['pts'];
                if ($x['gd']  !== $y['gd'])  return $y['gd']  - $x['gd'];
                return $y['gf'] - $x['gf'];
            });
            $response[$label] = $groupStandings;
        }

        echo json_encode($response);
        $conn->close();
        break;

    // ── GET QUALIFIERS ────────────────────────────────────────────────
    case 'get_qualifiers':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        if ($category_id <= 0) { badRequest('Invalid or missing category_id'); break; }

        $conn = getConnection();

        $gStmt = $conn->prepare("
            SELECT group_label, team_id, team_name
            FROM tbl_soccer_groups
            WHERE category_id = ?
            ORDER BY group_label ASC
        ");
        $gStmt->bind_param("i", $category_id);
        $gStmt->execute();
        $gRows = $gStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $gStmt->close();

        $groups2 = []; $teamGroup2 = [];
        foreach ($gRows as $r) {
            $g   = $r['group_label'];
            $tid = (int)$r['team_id'];
            if (!isset($groups2[$g])) $groups2[$g] = [];
            $groups2[$g][$tid] = $r['team_name'];
            $teamGroup2[$tid]  = $g;
        }

        $sStmt = $conn->prepare("
            SELECT s.match_id, s.team_id, s.score_independentscore AS goals
            FROM tbl_score s
            JOIN tbl_team  t ON t.team_id  = s.team_id
            JOIN tbl_match m ON m.match_id = s.match_id
            WHERE t.category_id = ?
              AND m.bracket_side = 'group'
            ORDER BY s.match_id ASC, s.score_id ASC
        ");
        $sStmt->bind_param("i", $category_id);
        $sStmt->execute();
        $sRows2 = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sStmt->close();

        $matchRows2 = [];
        foreach ($sRows2 as $r) $matchRows2[(int)$r['match_id']][] = $r;

        $standings2 = [];
        foreach (array_keys($teamGroup2) as $tid) {
            $standings2[$tid] = ['played'=>0,'won'=>0,'drawn'=>0,'lost'=>0,
                                  'gf'=>0,'ga'=>0,'pts'=>0,'team_name'=>''];
        }
        foreach ($gRows as $r) {
            $standings2[(int)$r['team_id']]['team_name'] = $r['team_name'];
        }

        foreach ($matchRows2 as $mid => $rows) {
            if (count($rows) < 2) continue;
            $tidA   = (int)$rows[0]['team_id']; $goalsA = (int)$rows[0]['goals'];
            $tidB   = (int)$rows[1]['team_id']; $goalsB = (int)$rows[1]['goals'];
            if (!isset($standings2[$tidA]) || !isset($standings2[$tidB])) continue;
            $standings2[$tidA]['played']++; $standings2[$tidA]['gf'] += $goalsA; $standings2[$tidA]['ga'] += $goalsB;
            $standings2[$tidB]['played']++; $standings2[$tidB]['gf'] += $goalsB; $standings2[$tidB]['ga'] += $goalsA;
            if ($goalsA > $goalsB)     { $standings2[$tidA]['won']++;  $standings2[$tidA]['pts'] += 3; $standings2[$tidB]['lost']++; }
            elseif ($goalsA < $goalsB) { $standings2[$tidB]['won']++;  $standings2[$tidB]['pts'] += 3; $standings2[$tidA]['lost']++; }
            else                       { $standings2[$tidA]['drawn']++; $standings2[$tidA]['pts']++; $standings2[$tidB]['drawn']++; $standings2[$tidB]['pts']++; }
        }

        $firsts = []; $seconds = [];
        foreach ($groups2 as $label => $members) {
            $ranked = [];
            foreach ($members as $tid => $tname) {
                $s        = $standings2[$tid];
                $ranked[] = ['team_id'    => $tid,
                             'team_name'  => $s['team_name'],
                             'pts'        => $s['pts'],
                             'gd'         => $s['gf'] - $s['ga'],
                             'gf'         => $s['gf'],
                             'total_score'=> $s['pts'],
                             'group'      => $label];
            }
            usort($ranked, function($x, $y) {
                if ($x['pts'] !== $y['pts']) return $y['pts'] - $x['pts'];
                if ($x['gd']  !== $y['gd'])  return $y['gd']  - $x['gd'];
                return $y['gf'] - $x['gf'];
            });
            if (count($ranked) >= 1) $firsts[]  = $ranked[0];
            if (count($ranked) >= 2) $seconds[] = $ranked[1];
        }

        $qualifiers = array_merge($firsts, $seconds);
        echo json_encode($qualifiers);
        $conn->close();
        break;

    // ── GET KNOCKOUT MATCHES BY ROUND ─────────────────────────────────
    case 'get_knockout_matches':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $category_id  = isset($_GET['category_id'])  ? intval($_GET['category_id'])  : 0;
        $bracket_type = isset($_GET['bracket_type'])  ? trim($_GET['bracket_type'])   : '';

        if ($category_id <= 0)    { badRequest('Invalid or missing category_id');  break; }
        if ($bracket_type === '') { badRequest('Invalid or missing bracket_type'); break; }

        $conn = getConnection();

        $stmt = $conn->prepare("
            SELECT
                m.match_id,
                m.bracket_side AS bracket_type,
                TIME_FORMAT(s.schedule_start, '%H:%i') AS match_time,
                ts.team_id,
                t.team_name,
                ts.arena_number,
                ts.referee_id
            FROM tbl_match m
            JOIN tbl_schedule     s  ON s.schedule_id = m.schedule_id
            JOIN tbl_teamschedule ts ON ts.match_id   = m.match_id
            JOIN tbl_team         t  ON t.team_id     = ts.team_id
            WHERE t.category_id  = ?
              AND m.bracket_side = ?
            ORDER BY s.schedule_start ASC, m.match_id ASC, ts.teamschedule_id ASC
        ");

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }

        $stmt->bind_param("is", $category_id, $bracket_type);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $matches = [];
        foreach ($rows as $row) {
            $mid = (int)$row['match_id'];
            if (!isset($matches[$mid])) {
                $matches[$mid] = [
                    'match_id'     => $mid,
                    'bracket_type' => $row['bracket_type'],
                    'match_time'   => $row['match_time'],
                    'arena_number' => (int)$row['arena_number'],
                    'referee_id'   => (int)$row['referee_id'],
                    'team1_id'     => null,
                    'team1_name'   => null,
                    'team2_id'     => null,
                    'team2_name'   => null,
                ];
            }
            if ($matches[$mid]['team1_id'] === null) {
                $matches[$mid]['team1_id']   = (int)$row['team_id'];
                $matches[$mid]['team1_name'] = $row['team_name'];
            } else {
                $matches[$mid]['team2_id']   = (int)$row['team_id'];
                $matches[$mid]['team2_name'] = $row['team_name'];
            }
        }

        echo json_encode(array_values($matches));
        $conn->close();
        break;

    // ── CHAMPIONSHIP SUBMIT SCORE ─────────────────────────────────────
    case 'championship_submit_score':
        if ($method !== 'POST') { methodNotAllowed(); break; }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data) { badRequest('Invalid or empty JSON body'); break; }

        $required = [
            'match_id', 'team_id', 'referee_id',
            'score_independentscore', 'score_violation',
            'score_totalscore', 'score_totalduration'
        ];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                badRequest("Missing required field: $field"); exit();
            }
        }

        $match_id         = intval($data['match_id']);
        $team_id          = intval($data['team_id']);
        $referee_id       = intval($data['referee_id']);
        $independentScore = intval($data['score_independentscore']);
        $violation        = intval($data['score_violation']);
        $totalScore       = intval($data['score_totalscore']);
        $totalDuration    = $data['score_totalduration'];
        $isApproved       = isset($data['score_isapproved']) ? intval($data['score_isapproved']) : 0;
        $isExplorer       = boolFromValue($data['is_explorer'] ?? false);
        $isStarter        = boolFromValue($data['is_starter'] ?? false);
        $scoreTable       = getScoreTableName($isExplorer, $isStarter);

        $conn = getConnection();

        $btStmt = $conn->prepare(
            "SELECT bracket_side AS bracket_type FROM tbl_match WHERE match_id = ? LIMIT 1"
        );
        $btStmt->bind_param("i", $match_id);
        $btStmt->execute();
        $btRow        = $btStmt->get_result()->fetch_assoc();
        $btStmt->close();
        $bracketType  = $btRow['bracket_type'] ?? '';

        switch ($bracketType) {
            case 'group':
                $round_id = 1; break;
            case 'elimination':
            case 'round-of-32':
            case 'round-of-16':
            case 'round-of-8':
                $round_id = 2; break;
            case 'quarter-finals':
                $round_id = 3; break;
            case 'semi-finals':
                $round_id = 4; break;
            case 'third-place':
            case 'final':
                $round_id = 5; break;
            default:
                $round_id = isset($data['round_id']) ? intval($data['round_id']) : 1;
        }

        $stmt = $conn->prepare("
            INSERT INTO $scoreTable (
                score_independentscore,
                score_violation,
                score_totalscore,
                score_totalduration,
                score_isapproved,
                match_id,
                round_id,
                team_id,
                referee_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                score_independentscore = VALUES(score_independentscore),
                score_violation        = VALUES(score_violation),
                score_totalscore       = VALUES(score_totalscore),
                score_totalduration    = VALUES(score_totalduration),
                score_isapproved       = VALUES(score_isapproved)
        ");

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }

        $stmt->bind_param(
            "iiisiiiii",
            $independentScore, $violation, $totalScore,
            $totalDuration, $isApproved,
            $match_id, $round_id, $team_id, $referee_id
        );

        $ok = $stmt->execute();

        if ($ok) {
            http_response_code(201);
            echo json_encode([
                'success'      => true,
                'score_id'     => $conn->insert_id,
                'round_id'     => $round_id,
                'bracket_type' => $bracketType,
                'message'      => 'Championship score submitted successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert score: ' . $stmt->error]);
        }

        $stmt->close();
        $conn->close();
        break;

    // ── SUBMIT STARTER CHAMPIONSHIP SCORE ─────────────────────────────
    case 'submit_starter_championship_score':
        if ($method !== 'POST') { methodNotAllowed(); break; }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data) { badRequest('Invalid or empty JSON body'); break; }

        $required = [
            'match_id', 'team_id',
            'score_violation', 'score_totalscore'
        ];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                badRequest("Missing required field: $field"); exit();
            }
        }

        $match_position = intval($data['match_id']);
        $team_id        = intval($data['team_id']);
        $violation      = intval($data['score_violation']);
        $totalScore     = intval($data['score_totalscore']);
        $scoreDuration  = $data['score_totalduration'] ?? '';

        if ($match_position <= 0 || $team_id <= 0) {
            badRequest('Invalid match_id or team_id');
            break;
        }

        $conn = getConnection();
        $alliance_id = 0;
        $alliance1Id = 0;
        $alliance2Id = 0;
        $scheduleMatchPosition = 0;

        $stmtSch = $conn->prepare(
            'SELECT match_position, alliance1_id, alliance2_id FROM tbl_starter_championship_schedule WHERE match_position = ? LIMIT 1'
        );
        if ($stmtSch) {
            $stmtSch->bind_param('i', $match_position);
            $stmtSch->execute();
            $stmtSch->bind_result($scheduleMatchPosition, $alliance1Id, $alliance2Id);
            $stmtSch->fetch();
            $stmtSch->close();
            if ($scheduleMatchPosition > 0) {
                $match_position = $scheduleMatchPosition;
            }
        }

        if ($alliance1Id === 0 && $alliance2Id === 0) {
            $stmtSch = $conn->prepare(
                'SELECT match_position, alliance1_id, alliance2_id FROM tbl_starter_championship_schedule WHERE match_id = ? LIMIT 1'
            );
            if ($stmtSch) {
                $stmtSch->bind_param('i', $match_position);
                $stmtSch->execute();
                $stmtSch->bind_result($scheduleMatchPosition, $alliance1Id, $alliance2Id);
                $stmtSch->fetch();
                $stmtSch->close();
                if ($scheduleMatchPosition > 0) {
                    $match_position = $scheduleMatchPosition;
                }
            }
        }

        if ($alliance1Id > 0 && $alliance2Id > 0) {
            $stmtTeam = $conn->prepare(
                'SELECT 1 FROM tbl_starter_alliance_selections WHERE alliance_id = ? AND (captain_team_id = ? OR partner_team_id = ?) LIMIT 1'
            );
            if ($stmtTeam) {
                $stmtTeam->bind_param('iii', $alliance1Id, $team_id, $team_id);
                $stmtTeam->execute();
                $stmtTeam->store_result();
                if ($stmtTeam->num_rows > 0) {
                    $alliance_id = $alliance1Id;
                }
                $stmtTeam->close();
            }
            if ($alliance_id === 0) {
                $stmtTeam = $conn->prepare(
                    'SELECT 1 FROM tbl_starter_alliance_selections WHERE alliance_id = ? AND (captain_team_id = ? OR partner_team_id = ?) LIMIT 1'
                );
                if ($stmtTeam) {
                    $stmtTeam->bind_param('iii', $alliance2Id, $team_id, $team_id);
                    $stmtTeam->execute();
                    $stmtTeam->store_result();
                    if ($stmtTeam->num_rows > 0) {
                        $alliance_id = $alliance2Id;
                    }
                    $stmtTeam->close();
                }
            }
        }

        if ($alliance_id <= 0) {
            $stmtTeam = $conn->prepare(
                'SELECT alliance_id FROM tbl_starter_alliance_selections WHERE captain_team_id = ? OR partner_team_id = ? LIMIT 1'
            );
            if ($stmtTeam) {
                $stmtTeam->bind_param('ii', $team_id, $team_id);
                $stmtTeam->execute();
                $stmtTeam->bind_result($alliance_id);
                $stmtTeam->fetch();
                $stmtTeam->close();
            }
        }

        if ($alliance_id <= 0) {
            $stmtTeam = $conn->prepare(
                'SELECT 1 FROM tbl_starter_alliance_selections WHERE alliance_id = ? LIMIT 1'
            );
            if ($stmtTeam) {
                $stmtTeam->bind_param('i', $team_id);
                $stmtTeam->execute();
                $stmtTeam->store_result();
                if ($stmtTeam->num_rows > 0) {
                    $alliance_id = $team_id;
                }
                $stmtTeam->close();
            }
        }

        if ($alliance_id <= 0 && $alliance1Id > 0) {
            $alliance_id = $alliance1Id;
        }

        if ($alliance_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Unable to determine starter alliance for this match/team.',
                'match_id' => $data['match_id'],
                'team_id' => $data['team_id'],
                'match_position' => $match_position,
                'alliance1_id' => $alliance1Id,
                'alliance2_id' => $alliance2Id,
            ]);
            $conn->close();
            break;
        }

        $existingScoreId = 0;
        $stmtExist = $conn->prepare(
            'SELECT score_id FROM tbl_starter_championship_scores WHERE alliance_id = ? AND match_position = ? LIMIT 1'
        );
        if ($stmtExist) {
            $stmtExist->bind_param('ii', $alliance_id, $match_position);
            $stmtExist->execute();
            $stmtExist->bind_result($existingScoreId);
            $stmtExist->fetch();
            $stmtExist->close();
        }

        if ($existingScoreId > 0) {
            $stmt = $conn->prepare(
                'UPDATE tbl_starter_championship_scores
                 SET score = ?, violation = ?
                 WHERE score_id = ?'
            );
            if (!$stmt) {
                http_response_code(500);
                echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
                $conn->close();
                break;
            }
            $stmt->bind_param('iii', $totalScore, $violation, $existingScoreId);
            $ok = $stmt->execute();
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO tbl_starter_championship_scores (
                    alliance_id,
                    match_position,
                    score,
                    violation
                ) VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                http_response_code(500);
                echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
                $conn->close();
                break;
            }
            $stmt->bind_param('iiii', $alliance_id, $match_position, $totalScore, $violation);
            $ok = $stmt->execute();
        }

        if ($stmt && $ok) {
            http_response_code(201);
            echo json_encode(['success' => true, 'score_id' => $conn->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save starter championship score: ' . ($stmt ? $stmt->error : $conn->error)]);
        }

        if ($stmt) {
            $stmt->close();
        }
        $conn->close();
        break;

    // ── SUBMIT EXPLORER CHAMPIONSHIP SCORE ────────────────────────────
    case 'submit_explorer_championship_score':
        if ($method !== 'POST') { methodNotAllowed(); break; }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data) { badRequest('Invalid or empty JSON body'); break; }

        $required = [
            'match_id', 'round_id', 'team_id', 'referee_id',
            'score_independentscore', 'score_alliancescore',
            'score_violation', 'score_totalscore',
            'score_totalduration'
        ];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                badRequest("Missing required field: $field"); exit();
            }
        }

        $match_id         = intval($data['match_id']);
        $round_id         = intval($data['round_id']);
        $team_id          = intval($data['team_id']);
        $referee_id       = intval($data['referee_id']);
        $independentScore = intval($data['score_independentscore']);
        $allianceScore    = intval($data['score_alliancescore']);
        $violation        = intval($data['score_violation']);
        $totalScore       = intval($data['score_totalscore']);
        $totalDuration    = $data['score_totalduration'];
        $isApproved       = isset($data['score_isapproved']) ? intval($data['score_isapproved']) : 0;

        $conn = getConnection();
        $stmt = $conn->prepare(
            "INSERT INTO tbl_explorer_score (
                score_independentscore,
                score_alliance,
                score_violation,
                score_totalscore,
                score_totalduration,
                score_isapproved,
                match_id,
                round_id,
                team_id,
                referee_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }
        $stmt->bind_param(
            "iiiiisiiii",
            $independentScore,
            $allianceScore,
            $violation,
            $totalScore,
            $totalDuration,
            $isApproved,
            $match_id,
            $round_id,
            $team_id,
            $referee_id
        );
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['success' => true, 'score_id' => $conn->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert score: ' . $stmt->error]);
        }
        $stmt->close();
        $conn->close();
        break;

    // ── SUBMIT EXPLORER CHAMPIONSHIP BEST-OF-3 SCORE ────────────────
    case 'submit_explorer_championship_bestof3':
        if ($method !== 'POST') { methodNotAllowed(); break; }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data) { badRequest('Invalid or empty JSON body'); break; }

        $required = [
            'category_id', 'match_number', 'match_round',
            'match_position', 'bracket_side', 'alliance_number',
            'alliance_score', 'alliance_violation'
        ];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                badRequest("Missing required field: $field"); exit();
            }
        }

        $categoryId         = intval($data['category_id']);
        $allianceId         = intval($data['alliance_id'] ?? 0);
        $opponentAllianceId = intval($data['opponent_alliance_id'] ?? 0);
        $matchNumber        = intval($data['match_number']);
        $allianceScore      = intval($data['alliance_score']);
        $allianceViolation  = intval($data['alliance_violation']);
        $opponentScore      = array_key_exists('opponent_score', $data) ? intval($data['opponent_score']) : null;
        $opponentViolation  = array_key_exists('opponent_violation', $data) ? intval($data['opponent_violation']) : null;
        $winnerAllianceId   = intval($data['winner_alliance_id'] ?? 0);
        $isCompleted        = intval($data['is_completed'] ?? 1);
        $matchRound         = $data['match_round'];
        $matchPosition      = intval($data['match_position'] ?? 0);
        $bracketSide        = $data['bracket_side'] ?? '';
        $allianceNumber     = intval($data['alliance_number']);
        $createdAt          = isset($data['created_at']) ? $data['created_at'] : null;

        $conn = getConnection();

        $schedule = resolveExplorerChampionshipSchedule(
            $conn,
            $categoryId,
            $matchNumber,
            $matchRound,
            $matchPosition,
            $bracketSide
        );

        if (($allianceId <= 0 || $opponentAllianceId <= 0) && !empty($schedule) && $allianceNumber > 0) {
            if ($allianceNumber === 1) {
                $allianceId = intval($schedule['alliance1_id']);
                $opponentAllianceId = intval($schedule['alliance2_id']);
            } else {
                $allianceId = intval($schedule['alliance2_id']);
                $opponentAllianceId = intval($schedule['alliance1_id']);
            }
        }

        if (!empty($schedule)) {
            $matchId = intval($schedule['match_id']);
            $matchPosition = intval($schedule['match_position']);
            $bracketSide = $schedule['bracket_side'] ?? $bracketSide;
            if (isset($schedule['match_round']) && $schedule['match_round'] !== '') {
                $matchRound = $schedule['match_round'];
            }
        } else {
            $matchId = 0;
        }

        if ($allianceId <= 0 || $opponentAllianceId <= 0) {
            badRequest('Unable to resolve explorer championship alliance IDs for submission');
            break;
        }

        if ($matchId > 0) {
            $scoreRow = getExplorerChampionshipAllianceScore($conn, $matchId, $allianceId);
            if ($scoreRow) {
                $allianceScore = intval($scoreRow['score_alliance']);
                $allianceViolation = intval($scoreRow['score_violation']);
            }

            $oppScoreRow = getExplorerChampionshipAllianceScore($conn, $matchId, $opponentAllianceId);
            if ($oppScoreRow) {
                $opponentScore = intval($oppScoreRow['score_alliance']);
                $opponentViolation = intval($oppScoreRow['score_violation']);
            }
        }

        if ($opponentScore === null) {
            $opponentScore = 0;
        }
        if ($opponentViolation === null) {
            $opponentViolation = 0;
        }

        if ($winnerAllianceId <= 0 && $allianceId > 0 && $opponentAllianceId > 0) {
            $winnerAllianceId = determineChampionshipWinnerAllianceId(
                $allianceId,
                $opponentAllianceId,
                $allianceScore,
                $opponentScore
            );
        }

        $sql = "INSERT INTO " . getBestOf3TableName() . " (
            category_id,
            alliance_id,
            opponent_alliance_id,
            match_number,
            alliance_score,
            alliance_violation,
            opponent_score,
            opponent_violation,
            winner_alliance_id,
            is_completed,
            match_round,
            match_position,
            bracket_side";
        if ($createdAt !== null) {
            $sql .= ", created_at";
        }
        $sql .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
        if ($createdAt !== null) {
            $sql .= ", ?";
        }
        $sql .= ") ON DUPLICATE KEY UPDATE
            alliance_score = VALUES(alliance_score),
            alliance_violation = VALUES(alliance_violation),
            opponent_score = VALUES(opponent_score),
            opponent_violation = VALUES(opponent_violation),
            winner_alliance_id = VALUES(winner_alliance_id),
            is_completed = VALUES(is_completed),
            match_round = VALUES(match_round),
            match_position = VALUES(match_position),
            bracket_side = VALUES(bracket_side)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            $conn->close();
            break;
        }

        if ($createdAt !== null) {
            $stmt->bind_param(
                'iiiiiiiiiiisss',
                $categoryId,
                $allianceId,
                $opponentAllianceId,
                $matchNumber,
                $allianceScore,
                $allianceViolation,
                $opponentScore,
                $opponentViolation,
                $winnerAllianceId,
                $isCompleted,
                $matchRound,
                $matchPosition,
                $bracketSide,
                $createdAt
            );
        } else {
            $stmt->bind_param(
                'iiiiiiiiiiiss',
                $categoryId,
                $allianceId,
                $opponentAllianceId,
                $matchNumber,
                $allianceScore,
                $allianceViolation,
                $opponentScore,
                $opponentViolation,
                $winnerAllianceId,
                $isCompleted,
                $matchRound,
                $matchPosition,
                $bracketSide
            );
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert best-of-3 score: ' . $stmt->error]);
            $stmt->close();
            $conn->close();
            break;
        }

        // Mirror the row for the opponent alliance so both sides remain synchronized.
        $mirroredWinnerAllianceId = $winnerAllianceId;
        $mirroredSql = $sql;
        $mirroredStmt = $conn->prepare($mirroredSql);
        if ($mirroredStmt) {
            if ($createdAt !== null) {
                $mirroredStmt->bind_param(
                    'iiiiiiiiiiisss',
                    $categoryId,
                    $opponentAllianceId,
                    $allianceId,
                    $matchNumber,
                    $opponentScore,
                    $opponentViolation,
                    $allianceScore,
                    $allianceViolation,
                    $mirroredWinnerAllianceId,
                    $isCompleted,
                    $matchRound,
                    $matchPosition,
                    $bracketSide,
                    $createdAt
                );
            } else {
                $mirroredStmt->bind_param(
                    'iiiiiiiiiiiss',
                    $categoryId,
                    $opponentAllianceId,
                    $allianceId,
                    $matchNumber,
                    $opponentScore,
                    $opponentViolation,
                    $allianceScore,
                    $allianceViolation,
                    $mirroredWinnerAllianceId,
                    $isCompleted,
                    $matchRound,
                    $matchPosition,
                    $bracketSide
                );
            }
            $mirroredStmt->execute();
            $mirroredStmt->close();
        }

        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Best-of-3 scores saved successfully']);

        $stmt->close();
        $conn->close();
        break;

    // ── CHECK SCORE ───────────────────────────────────────────────────
    case 'check_score':
        if ($method !== 'GET') { methodNotAllowed(); break; }

        $match_id     = intval($_GET['match_id'] ?? 0);
        $team_id      = intval($_GET['team_id']  ?? 0);
        $scope        = isset($_GET['scope']) ? trim($_GET['scope']) : 'qualification';
        $isExplorer   = boolFromValue($_GET['is_explorer'] ?? false);
        $isStarter    = boolFromValue($_GET['is_starter'] ?? false);
        $isChampionship = $scope === 'championship';

        if ($match_id === 0) {
            badRequest('match_id is required');
            break;
        }

        $conn = getConnection();
        if ($isStarter && $isChampionship) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM tbl_starter_championship_scores
                  WHERE match_position = ?
                  LIMIT 1"
            );
            $stmt->bind_param("i", $match_id);
            $table = 'tbl_starter_championship_scores';
        } elseif ($isExplorer && $isChampionship) {
            if ($team_id === 0) {
                badRequest('team_id is required');
                break;
            }
            $stmt = $conn->prepare(
                "SELECT COUNT(*)
                  FROM tbl_championship_bestof3 cb
                  INNER JOIN tbl_alliance_selections asel ON asel.alliance_id = cb.alliance_id
                  WHERE cb.match_number = ?
                    AND (asel.captain_team_id = ? OR asel.partner_team_id = ?)
                  LIMIT 1"
            );
            $stmt->bind_param("iii", $match_id, $team_id, $team_id);
            $table = 'tbl_championship_bestof3';
        } else {
            if ($team_id === 0) {
                badRequest('team_id is required');
                break;
            }
            $scoreTable = getScoreTableName($isExplorer, $isStarter);
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS count_rows, MAX(round_id) AS max_round_id
                  FROM $scoreTable
                  WHERE match_id = ?
                    AND team_id  = ?
                  LIMIT 1"
            );
            $stmt->bind_param("ii", $match_id, $team_id);
            $table = $scoreTable;
        }

        $stmt->execute();
        $row   = $stmt->get_result()->fetch_row();
        $count = (int)($row[0] ?? 0);
        $maxRoundId = intval($row[1] ?? 0);
        $stmt->close();
        $conn->close();

        echo json_encode([
            'exists' => $count > 0,
            'table' => $table,
            'max_round_id' => $maxRoundId,
        ]);
        break;

    // ── UNKNOWN ACTION ────────────────────────────────────────────────
    default:
        http_response_code(404);
        echo json_encode(['error' => "Unknown action: '$action'"]);
        break;
}

// ─────────────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ─────────────────────────────────────────────────────────────────────
function badRequest(string $msg): void {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit();
}

function methodNotAllowed(): void {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}
?>