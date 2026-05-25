<?php
// scoring_championship.php
// API endpoints for the Scoring App to access Explorer Championship Schedule
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET TODAY'S CHAMPIONSHIP MATCHES FOR SCORING APP
// ============================================================
if ($action === 'get_today_matches' && $method === 'GET') {
    $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    
    if ($categoryId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid category_id']);
        exit;
    }
    
    // Get matches that are pending or in progress (not completed)
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
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $matches = [];
    while ($row = $result->fetch_assoc()) {
        // Build alliance names
        $row['alliance1_name'] = $row['captain1_name'] . ' / ' . $row['partner1_name'];
        $row['alliance2_name'] = $row['captain2_name'] . ' / ' . $row['partner2_name'];
        $row['alliance1_rank_display'] = '#' . $row['alliance1_rank'];
        $row['alliance2_rank_display'] = '#' . $row['alliance2_rank'];
        
        // Get scores if already entered
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
    
    echo json_encode([
        'success' => true,
        'matches' => $matches,
        'count' => count($matches)
    ]);
    exit;
}

// ============================================================
// GET MATCH DETAILS FOR SCORING (including teams in the match)
// ============================================================
if ($action === 'get_match_teams' && $method === 'GET') {
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    $roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : 0;
    
    if ($matchId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid match_id']);
        exit;
    }
    
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
            WHERE cs.match_id = ?
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $matchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Get all teams in this match (both alliances)
        $teams = [];
        
        // Alliance 1 teams (captain + partner)
        $teams[] = [
            'team_id' => $row['alliance1_id'],
            'team_name' => $row['captain1_name'],
            'role' => 'Captain',
            'alliance_id' => $row['alliance1_id'],
            'alliance_rank' => $row['alliance1_rank']
        ];
        $teams[] = [
            'team_id' => $row['alliance1_id'],
            'team_name' => $row['partner1_name'],
            'role' => 'Partner',
            'alliance_id' => $row['alliance1_id'],
            'alliance_rank' => $row['alliance1_rank']
        ];
        
        // Alliance 2 teams
        $teams[] = [
            'team_id' => $row['alliance2_id'],
            'team_name' => $row['captain2_name'],
            'role' => 'Captain',
            'alliance_id' => $row['alliance2_id'],
            'alliance_rank' => $row['alliance2_rank']
        ];
        $teams[] = [
            'team_id' => $row['alliance2_id'],
            'team_name' => $row['partner2_name'],
            'role' => 'Partner',
            'alliance_id' => $row['alliance2_id'],
            'alliance_rank' => $row['alliance2_rank']
        ];
        
        $row['teams'] = $teams;
        
        echo json_encode(['success' => true, 'match' => $row]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Match not found']);
    }
    $stmt->close();
    exit;
}

// ============================================================
// SUBMIT SCORE FOR CHAMPIONSHIP MATCH
// ============================================================
if ($action === 'submit_championship_score' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
        exit;
    }
    
    $matchId = intval($data['match_id'] ?? 0);
    $roundId = intval($data['round_id'] ?? 0);
    $teamId = intval($data['team_id'] ?? 0);
    $refereeId = intval($data['referee_id'] ?? 0);
    $individualScore = intval($data['individual_score'] ?? 0);
    $allianceScore = intval($data['alliance_score'] ?? 0);
    $violation = intval($data['violation'] ?? 0);
    $totalScore = intval($data['total_score'] ?? 0);
    $totalDuration = $data['total_duration'] ?? '00:00';
    $isApproved = intval($data['is_approved'] ?? 0);
    
    if ($matchId <= 0 || $teamId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Insert or update score in tbl_explorer_score
    $sql = "INSERT INTO tbl_explorer_score (
                match_id, round_id, team_id, referee_id,
                score_individual, score_alliance, score_violation,
                score_totalscore, score_totalduration, score_isapproved
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                score_individual = VALUES(score_individual),
                score_alliance = VALUES(score_alliance),
                score_violation = VALUES(score_violation),
                score_totalscore = VALUES(score_totalscore),
                score_totalduration = VALUES(score_totalduration),
                referee_id = VALUES(referee_id),
                score_isapproved = VALUES(score_isapproved)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiiiiisi", 
        $matchId, $roundId, $teamId, $refereeId,
        $individualScore, $allianceScore, $violation,
        $totalScore, $totalDuration, $isApproved
    );
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Score saved successfully',
            'score_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
    exit;
}

// ============================================================
// UPDATE MATCH STATUS (start match, complete match)
// ============================================================
if ($action === 'update_match_status' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $matchId = intval($data['match_id'] ?? 0);
    $status = $data['status'] ?? '';
    $winnerAllianceId = isset($data['winner_alliance_id']) ? intval($data['winner_alliance_id']) : null;
    
    if ($matchId <= 0 || empty($status)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    $allowedStatus = ['pending', 'in_progress', 'completed'];
    if (!in_array($status, $allowedStatus)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }
    
    $sql = "UPDATE tbl_explorer_championship_schedule SET status = ?";
    $params = [$status];
    $types = "s";
    
    if ($status === 'completed' && $winnerAllianceId !== null) {
        $sql .= ", winner_alliance_id = ?";
        $params[] = $winnerAllianceId;
        $types .= "i";
    }
    
    $sql .= " WHERE match_id = ?";
    $params[] = $matchId;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        // If match completed with winner, also update bestof3 table
        if ($status === 'completed' && $winnerAllianceId !== null) {
            // Get match details to update bestof3
            $matchSql = "SELECT match_round, match_position, bracket_side, match_number,
                                alliance1_id, alliance2_id
                         FROM tbl_explorer_championship_schedule 
                         WHERE match_id = ?";
            $matchStmt = $conn->prepare($matchSql);
            $matchStmt->bind_param("i", $matchId);
            $matchStmt->execute();
            $matchResult = $matchStmt->get_result();
            
            if ($matchRow = $matchResult->fetch_assoc()) {
                // Determine which alliance won
                $allianceId = $winnerAllianceId;
                $opponentId = ($matchRow['alliance1_id'] == $winnerAllianceId) 
                    ? $matchRow['alliance2_id'] 
                    : $matchRow['alliance1_id'];
                
                // Get scores for this match
                $scoreSql = "SELECT score_totalscore, score_violation 
                            FROM tbl_explorer_score 
                            WHERE match_id = ? AND team_id = ?";
                $scoreStmt = $conn->prepare($scoreSql);
                $scoreStmt->bind_param("ii", $matchId, $allianceId);
                $scoreStmt->execute();
                $scoreResult = $scoreStmt->get_result();
                
                $allianceScore = 0;
                $allianceViolation = 0;
                $opponentScore = 0;
                $opponentViolation = 0;
                
                if ($scoreRow = $scoreResult->fetch_assoc()) {
                    $allianceScore = $scoreRow['score_totalscore'];
                    $allianceViolation = $scoreRow['score_violation'];
                }
                $scoreStmt->close();
                
                // Get opponent score
                $oppScoreStmt = $conn->prepare($scoreSql);
                $oppScoreStmt->bind_param("ii", $matchId, $opponentId);
                $oppScoreStmt->execute();
                $oppScoreResult = $oppScoreStmt->get_result();
                if ($oppScoreRow = $oppScoreResult->fetch_assoc()) {
                    $opponentScore = $oppScoreRow['score_totalscore'];
                    $opponentViolation = $oppScoreRow['score_violation'];
                }
                $oppScoreStmt->close();
                
                // Insert into bestof3 table
                $bestSql = "INSERT INTO tbl_championship_bestof3 (
                                category_id, alliance_id, opponent_alliance_id, match_number,
                                alliance_score, alliance_violation, opponent_score, opponent_violation,
                                winner_alliance_id, is_completed, match_round, match_position, bracket_side
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                alliance_score = VALUES(alliance_score),
                                alliance_violation = VALUES(alliance_violation),
                                opponent_score = VALUES(opponent_score),
                                opponent_violation = VALUES(opponent_violation),
                                winner_alliance_id = VALUES(winner_alliance_id),
                                is_completed = 1";
                
                $bestStmt = $conn->prepare($bestSql);
                $categoryId = 2; // Explorer category
                $bestStmt->bind_param("iiiiiiiiiii", 
                    $categoryId, $allianceId, $opponentId, $matchRow['match_number'],
                    $allianceScore, $allianceViolation, $opponentScore, $opponentViolation,
                    $winnerAllianceId, $matchRow['match_round'], $matchRow['match_position'], 
                    $matchRow['bracket_side']
                );
                $bestStmt->execute();
                $bestStmt->close();
            }
            $matchStmt->close();
        }
        
        echo json_encode(['success' => true, 'message' => 'Match status updated']);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
    exit;
}

// ============================================================
// GET BEST OF 3 RESULTS FOR A MATCH SERIES
// ============================================================
if ($action === 'get_series_results' && $method === 'GET') {
    $matchRound = isset($_GET['match_round']) ? intval($_GET['match_round']) : 0;
    $matchPosition = isset($_GET['match_position']) ? intval($_GET['match_position']) : 0;
    $bracketSide = $_GET['bracket_side'] ?? 'winners';
    $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    
    if ($matchRound <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid match_round']);
        exit;
    }
    
    $sql = "SELECT 
                match_number,
                alliance_id,
                opponent_alliance_id,
                alliance_score,
                alliance_violation,
                opponent_score,
                opponent_violation,
                winner_alliance_id,
                is_completed
            FROM tbl_championship_bestof3
            WHERE category_id = ?
                AND match_round = ?
                AND match_position = ?
                AND bracket_side = ?
            ORDER BY match_number";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiis", $categoryId, $matchRound, $matchPosition, $bracketSide);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// ============================================================
// CHECK IF MATCH HAS BEEN SCORED
// ============================================================
if ($action === 'check_match_scored' && $method === 'GET') {
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    $teamId = isset($_GET['team_id']) ? intval($_GET['team_id']) : 0;
    
    if ($matchId <= 0 || $teamId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid match_id or team_id']);
        exit;
    }
    
    $sql = "SELECT COUNT(*) as count FROM tbl_explorer_score WHERE match_id = ? AND team_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $matchId, $teamId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'scored' => ($row['count'] > 0),
        'match_id' => $matchId,
        'team_id' => $teamId
    ]);
    exit;
}

// ============================================================
// DEFAULT RESPONSE
// ============================================================
echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
?>