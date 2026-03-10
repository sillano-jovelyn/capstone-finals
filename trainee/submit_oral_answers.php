<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
    header('Location: ../login.php');
    exit;
}

$enrollment_id = intval($_POST['enrollment_id']);
$answers = json_encode($_POST['answers']);

// Save to database
$conn->query("INSERT INTO assessment_components 
    (enrollment_id, oral_answers, oral_submitted_by_trainee, oral_submitted_at) 
    VALUES ($enrollment_id, '$answers', 1, NOW())
    ON DUPLICATE KEY UPDATE 
    oral_answers = '$answers',
    oral_submitted_by_trainee = 1,
    oral_submitted_at = NOW()");

header('Location: training_progress.php?oral_submitted=1');
exit;
?>