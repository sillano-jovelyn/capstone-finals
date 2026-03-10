<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
    header('Location: ../login.php');
    exit;
}

$enrollment_id = intval($_POST['enrollment_id']);
$project_title = $conn->real_escape_string($_POST['project_title']);
$project_description = $conn->real_escape_string($_POST['project_description']);

// Upload photo
$photo_path = '';
if (isset($_FILES['project_photo']) && $_FILES['project_photo']['error'] == 0) {
    $upload_dir = '../uploads/projects/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $ext = pathinfo($_FILES['project_photo']['name'], PATHINFO_EXTENSION);
    $filename = 'project_' . $enrollment_id . '_' . time() . '.' . $ext;
    
    if (move_uploaded_file($_FILES['project_photo']['tmp_name'], $upload_dir . $filename)) {
        $photo_path = 'uploads/projects/' . $filename;
    }
}

// Save to database
$conn->query("INSERT INTO assessment_components 
    (enrollment_id, project_title, project_description, project_photo_path, project_submitted_by_trainee, project_submitted_at) 
    VALUES ($enrollment_id, '$project_title', '$project_description', '$photo_path', 1, NOW())
    ON DUPLICATE KEY UPDATE 
    project_title = '$project_title',
    project_description = '$project_description',
    project_photo_path = '$photo_path',
    project_submitted_by_trainee = 1,
    project_submitted_at = NOW()");

header('Location: training_progress.php?project_submitted=1');
exit;
?>