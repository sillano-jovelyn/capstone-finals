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

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$archive_id = isset($_GET['archive_id']) ? (int)$_GET['archive_id'] : 0;

// First, check if this archive already has saved signatories
if ($archive_id > 0) {
    // Check if saved_signatories column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM archived_history LIKE 'saved_signatories'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $checkQuery = "SELECT saved_signatories FROM archived_history WHERE id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $archive_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['saved_signatories'])) {
                $signatories = json_decode($row['saved_signatories'], true);
                if ($signatories && count($signatories) > 0) {
                    echo json_encode(['success' => true, 'signatories' => $signatories]);
                    $stmt->close();
                    $conn->close();
                    exit();
                }
            }
        }
        $stmt->close();
    }
}

// If no saved signatories, get current active ones from templates table
$query = "SELECT signatory_name, signatory_title 
          FROM certificate_signatory_templates 
          WHERE is_active = 1 
          ORDER BY id ASC";
$result = $conn->query($query);
$signatories = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $signatories[] = [
            'signatory_name' => $row['signatory_name'],
            'signatory_title' => $row['signatory_title']
        ];
    }
}

echo json_encode(['success' => true, 'signatories' => $signatories]);
$conn->close();
?>