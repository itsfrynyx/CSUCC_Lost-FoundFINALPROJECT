<?php
session_start();
require_once '../DB/conn.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['session_id']) && isset($data['status'])) {
    $sessionId = $data['session_id'];
    $status = $data['status'];
    $currentTime = date('Y-m-d H:i:s');
    
    if ($status === 'Inactive') {
        // Calculate session duration
        $stmt = $conn->prepare("SELECT time_in FROM user_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sessionData = $result->fetch_assoc();
        
        if ($sessionData) {
            $timeIn = strtotime($sessionData['time_in']);
            $timeOut = strtotime($currentTime);
            $duration = $timeOut - $timeIn;
            
            $stmt = $conn->prepare("UPDATE user_sessions SET status = ?, time_out = ?, session_duration = ? WHERE session_id = ?");
            $stmt->bind_param("ssis", $status, $currentTime, $duration, $sessionId);
            $stmt->execute();
        }
    } else {
        $stmt = $conn->prepare("UPDATE user_sessions SET status = ?, last_activity_time = ? WHERE session_id = ?");
        $stmt->bind_param("sss", $status, $currentTime, $sessionId);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
}
?>
