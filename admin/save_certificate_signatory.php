<?php
session_start();
include __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

$user_id = isset($data['user_id']) ? intval($data['user_id']) : 0;
$certificate_unique_id = isset($data['certificate_unique_id']) ? $data['certificate_unique_id'] : '';
$barcode_data = isset($data['barcode_data']) ? $data['barcode_data'] : '';
$signatories = isset($data['signatories']) ? $data['signatories'] : [];

if (!$user_id || !$certificate_unique_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Check if table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'certificate_signatory'");
if ($table_check->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS certificate_signatory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        certificate_unique_id VARCHAR(100) NOT NULL,
        barcode_data TEXT,
        original_signatory TEXT,
        edited_signatory TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_certificate_unique_id (certificate_unique_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($create_table);
}

// Save or update the signatory information
$query = "INSERT INTO certificate_signatory (user_id, certificate_unique_id, barcode_data, edited_signatory) 
          VALUES (?, ?, ?, ?) 
          ON DUPLICATE KEY UPDATE 
          edited_signatory = VALUES(edited_signatory),
          barcode_data = VALUES(barcode_data),
          updated_at = CURRENT_TIMESTAMP";

$edited_signatory = json_encode($signatories);
$stmt = $conn->prepare($query);
$stmt->bind_param("isss", $user_id, $certificate_unique_id, $barcode_data, $edited_signatory);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Signatory saved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save signatory: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>