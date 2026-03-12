<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Query to get user's completed programs from archived_history table
// Filtering out programs that ended (archive_trigger = 'program_ended')
$query = "
    SELECT 
        ah.id as archive_id,
        ah.original_program_id as program_id,
        ah.program_name,
        ah.program_duration,
        ah.program_duration_unit,
        DATE_FORMAT(ah.program_schedule_start, '%M %d, %Y') as schedule_start,
        DATE_FORMAT(ah.program_schedule_end, '%M %d, %Y') as schedule_end,
        ah.program_trainer_name as trainer,
        ah.program_category_id,
        pc.name as category_name,
        ah.enrollment_status,
        ah.enrollment_attendance as attendance,
        ah.enrollment_assessment as assessment,
        DATE_FORMAT(ah.enrollment_completed_at, '%M %d, %Y') as completion_date,
        DATE_FORMAT(ah.archived_at, '%M %d, %Y %h:%i %p') as archived_date,
        ah.archive_trigger,
        -- Check if certificate is available (has feedback)
        CASE 
            WHEN ah.feedback_id IS NOT NULL THEN 1 
            ELSE 0 
        END as has_certificate
    FROM archived_history ah
    LEFT JOIN program_categories pc ON ah.program_category_id = pc.id
    WHERE ah.user_id = ?
    AND ah.enrollment_status = 'completed'
    AND ah.archive_trigger != 'program_ended' -- Exclude programs that ended
    ORDER BY ah.enrollment_completed_at DESC, ah.archived_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$completed_programs = [];
while ($row = $result->fetch_assoc()) {
    $completed_programs[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'count' => count($completed_programs),
    'programs' => $completed_programs
]);