<?php
session_start();
header('Content-Type: application/json');

$response = [
    'loggedIn' => isset($_SESSION['user_id']),
    'userId' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null
];

// Also check for pending enrollment
if (isset($_SESSION['pending_enrollment'])) {
    $response['pendingEnrollment'] = $_SESSION['pending_enrollment'];
} elseif (isset($_SESSION['pending_program_id'])) {
    $response['pendingEnrollment'] = [
        'program_id' => $_SESSION['pending_program_id'],
        'program_name' => $_SESSION['pending_program_name'] ?? ''
    ];
}

error_log("check-login.php: loggedIn=" . ($response['loggedIn'] ? 'true' : 'false') . 
          ", userId=" . $response['userId'] .
          ", hasPending=" . (isset($response['pendingEnrollment']) ? 'true' : 'false'));

echo json_encode($response);
?>