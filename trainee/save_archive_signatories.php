<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Allow both trainee and admin
$allowed_roles = ['trainee', 'admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized role']);
    exit();
}

// Database connection
require_once __DIR__ . '/../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$archive_id = isset($input['archive_id']) ? (int)$input['archive_id'] : 0;
$signatories = isset($input['signatories']) ? $input['signatories'] : [];

if ($archive_id > 0 && !empty($signatories)) {
    $signatories_json = json_encode($signatories);
    
    // Check if the column exists in archived_history
    $checkColumn = $conn->query("SHOW COLUMNS FROM archived_history LIKE 'saved_signatories'");
    if ($checkColumn && $checkColumn->num_rows === 0) {
        // Add the column if it doesn't exist
        $alterQuery = "ALTER TABLE archived_history ADD COLUMN saved_signatories TEXT NULL";
        if (!$conn->query($alterQuery)) {
            echo json_encode(['success' => false, 'message' => 'Failed to add column: ' . $conn->error]);
            $conn->close();
            exit();
        }
    }
    
    // Update the archive record with saved signatories
    $updateQuery = "UPDATE archived_history SET saved_signatories = ? WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("si", $signatories_json, $archive_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
}

$conn->close();
?>