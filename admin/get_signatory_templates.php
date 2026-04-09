<?php
session_start();
include __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if templates table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'certificate_signatory_templates'");
if ($table_check->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS certificate_signatory_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(100) NOT NULL,
        signatory_name VARCHAR(255) NOT NULL,
        signatory_title VARCHAR(255) NOT NULL,
        is_active BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($create_table);
    
    // Insert default templates
    $insert_defaults = "INSERT IGNORE INTO certificate_signatory_templates (template_name, signatory_name, signatory_title, is_active) VALUES
        ('Default PESO Manager', 'ZENAIDA S. MANINGAS', 'PESO Manager', TRUE),
        ('Default Vice Mayor', 'ROBERTO B. PEREZ', 'Municipal Vice Mayor', TRUE),
        ('Default Mayor', 'BARTOLOME R. RAMOS', 'Municipal Mayor', TRUE),
        ('Alternate PESO Manager', 'MARIA C. SANTOS', 'OIC - PESO Manager', FALSE),
        ('Alternate Vice Mayor', 'JUAN D. DELA CRUZ', 'Acting Vice Mayor', FALSE)";
    $conn->query($insert_defaults);
}

$query = "SELECT id, template_name, signatory_name, signatory_title, is_active 
          FROM certificate_signatory_templates 
          ORDER BY is_active DESC, id ASC";

$result = $conn->query($query);
$templates = [];

while ($row = $result->fetch_assoc()) {
    $templates[] = $row;
}

echo json_encode(['success' => true, 'templates' => $templates]);
$conn->close();
?>