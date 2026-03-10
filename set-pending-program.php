<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['program_id']) && isset($input['program_name'])) {
        // Store in session for enrollment after login
        $_SESSION['pending_enrollment'] = [
            'program_id' => intval($input['program_id']),
            'program_name' => $input['program_name'],
            'timestamp' => time()
        ];
        
        // Also store in legacy format for compatibility
        $_SESSION['pending_program_id'] = intval($input['program_id']);
        $_SESSION['pending_program_name'] = $input['program_name'];
        
        error_log("set-pending-program.php: Stored program_id=" . $input['program_id'] . ", program_name=" . $input['program_name']);
        
        echo json_encode(['success' => true, 'message' => 'Pending enrollment stored']);
    } else {
        error_log("set-pending-program.php: Missing program data");
        echo json_encode(['success' => false, 'error' => 'Missing program data']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>