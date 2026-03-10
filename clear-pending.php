<?php
session_start();
header('Content-Type: application/json');

// Clear any pending enrollment data
unset($_SESSION['pending_enrollment']);
unset($_SESSION['pending_program_id']);
unset($_SESSION['pending_program_name']);

error_log("clear-pending.php: Cleared pending enrollment data");

echo json_encode(['success' => true, 'message' => 'Pending enrollment cleared']);
?>