<?php
// ==========================================
// ERROR REPORTING & SESSION START
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();

// ==========================================
// LOGOUT HANDLING
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// ==========================================
// DATABASE CONNECTION
// ==========================================
$conn = null;
try {
    $db_file = __DIR__ . '/../db.php';
    if (!file_exists($db_file)) {
        throw new Exception("Database configuration file not found");
    }
    require_once $db_file;
    if (!isset($conn) || !$conn) throw new Exception("Database connection not established");
    if (!$conn->ping()) throw new Exception("Database connection lost");
} catch (Exception $e) {
    die("Database connection error. Please try again later.");
}

// ==========================================
// SESSION VALIDATION
// ==========================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Redirect non-trainee users
if ($user_role !== 'trainee') {
    header("Location: ../login.php");
    exit();
}

$userCheck = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND role = ?");
$userCheck->bind_param("is", $user_id, $user_role);
$userCheck->execute();
$userResult = $userCheck->get_result();

if ($userResult->num_rows === 0) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$userCheck->close();

// Get trainee data
$traineeCheck = $conn->prepare("SELECT id, firstname, lastname, email FROM trainees WHERE user_id = ?");
$traineeCheck->bind_param("i", $user_id);
$traineeCheck->execute();
$traineeData = $traineeCheck->get_result()->fetch_assoc();
$traineeCheck->close();

if (!$traineeData) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$username = $traineeData['firstname'];
$trainee_id = $traineeData['id'];
$trainee_fullname = $traineeData['firstname'] . ' ' . $traineeData['lastname'];
$_SESSION['trainee_id'] = $trainee_id;

// ==========================================
// DEFINE CERTIFICATE VARIABLES
// ==========================================
$fullname = strtoupper($trainee_fullname ?? 'TRAINEE NAME');
$program_name = ''; // Will be set dynamically in the modal
$formatted_date = date('F j, Y'); // Default to current date

// Get trainee program data
$programs = [];
$anyApprovedEnrolled = false;
$hasCompletedProgram = false;
$hasActiveProgram = false;
$hasPendingApplications = false;
$hasArchivedHistory = false;

// Check enrollment statuses
$activeCheck = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE user_id = ? AND enrollment_status IN ('approved', 'completed')");
$activeCheck->bind_param("i", $user_id);
$activeCheck->execute();
$activeResult = $activeCheck->get_result()->fetch_assoc();
$anyApprovedEnrolled = ($activeResult['count'] > 0);
$activeCheck->close();

$pendingCheck = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE user_id = ? AND enrollment_status = 'pending'");
$pendingCheck->bind_param("i", $user_id);
$pendingCheck->execute();
$pendingResult = $pendingCheck->get_result()->fetch_assoc();
$hasPendingApplications = ($pendingResult['count'] > 0);
$pendingCheck->close();

$completedCheck = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE user_id = ? AND enrollment_status = 'completed'");
$completedCheck->bind_param("i", $user_id);
$completedCheck->execute();
$completedResult = $completedCheck->get_result()->fetch_assoc();
$hasCompletedProgram = ($completedResult['count'] > 0);
$completedCheck->close();

$activeProgramCheck = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE user_id = ? AND enrollment_status = 'approved'");
$activeProgramCheck->bind_param("i", $user_id);
$activeProgramCheck->execute();
$activeProgramResult = $activeProgramCheck->get_result()->fetch_assoc();
$hasActiveProgram = ($activeProgramResult['count'] > 0);
$activeProgramCheck->close();

// Check archived history
$archivedCheck = $conn->prepare("SELECT COUNT(*) as count FROM archived_history WHERE user_id = ?");
$archivedCheck->bind_param("i", $user_id);
$archivedCheck->execute();
$archivedResult = $archivedCheck->get_result()->fetch_assoc();
$hasArchivedHistory = ($archivedResult['count'] > 0);
$archivedCheck->close();

// ==========================================
// GET ENROLLED PROGRAMS
// ==========================================
$enrolledPrograms = [];
$enrolledQuery = "
    SELECT p.id, p.name, p.duration, p.scheduleStart, p.scheduleEnd, p.trainer, p.total_slots, p.slotsAvailable,
           pc.name as category_name, e.enrollment_status, e.attendance, e.assessment, e.completed_at, e.applied_at as application_date,
           (SELECT COUNT(*) FROM enrollments e2 WHERE e2.program_id = p.id AND e2.enrollment_status IN ('approved', 'completed')) as enrolled_count,
           (SELECT COUNT(*) FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = p.id) as has_feedback
    FROM enrollments e
    JOIN programs p ON e.program_id = p.id
    LEFT JOIN program_categories pc ON p.category_id = pc.id
    WHERE e.user_id = ? AND e.enrollment_status IN ('approved', 'completed', 'pending')
    ORDER BY CASE 
        WHEN e.enrollment_status = 'completed' THEN 3 
        WHEN e.enrollment_status = 'pending' THEN 2 
        ELSE 1 
    END, p.created_at DESC
";

$stmt = $conn->prepare($enrolledQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$enrolledRes = $stmt->get_result();

while ($row = $enrolledRes->fetch_assoc()) {
    $total_slots = $row['total_slots'] ?? $row['slotsAvailable'] ?? 0;
    $enrolled_count = $row['enrolled_count'] ?? 0;
    $available_slots = max(0, $total_slots - $enrolled_count);

    $enrollment_status = $row['enrollment_status'] ?? null;
    $is_enrolled = ($enrollment_status === 'approved' || $enrollment_status === 'completed');
    $is_pending = ($enrollment_status === 'pending');
    $is_completed_status = ($enrollment_status === 'completed');
    $has_enrollment = ($is_enrolled || $is_pending);

    $is_completed = false;
    $is_past_end_date = false;
    $show_certificate = false;
    $is_from_history = false;

    if ($is_enrolled) {
        $end_date = $row['scheduleEnd'] ?? null;
        if ($end_date) {
            try {
                $end_date_obj = new DateTime($end_date);
                $today = new DateTime();
                $is_past_end_date = ($end_date_obj < $today);
            } catch (Exception $e) {
                $is_past_end_date = false;
            }
        }
        $has_min_attendance = ($row['attendance'] >= 80);
        $has_passed_assessment = (strtolower($row['assessment'] ?? '') === 'passed');
        $has_feedback = ($row['has_feedback'] > 0);
        $has_completion_date = !empty($row['completed_at']);
        $is_completed = $is_completed_status || $has_completion_date || ($has_feedback && (($has_min_attendance && $has_passed_assessment) || $is_past_end_date));
        $is_from_history = $is_completed_status || $has_completion_date || $is_past_end_date;
        $show_certificate = ($is_completed && $has_feedback) || $is_completed_status;
    }

    $row['available_slots'] = $available_slots;
    $row['total_slots'] = $total_slots;
    $row['enrolled_count'] = $enrolled_count;
    $row['is_enrolled'] = $is_enrolled;
    $row['is_pending'] = $is_pending;
    $row['is_completed_status'] = $is_completed_status;
    $row['has_enrollment'] = $has_enrollment;
    $row['is_completed'] = $is_completed;
    $row['is_past_end_date'] = $is_past_end_date;
    $row['show_certificate'] = $show_certificate;
    $row['is_from_history'] = $is_from_history;
    $row['has_feedback_value'] = ($row['has_feedback'] > 0);
    $row['is_full'] = ($available_slots <= 0);
    $row['enrolled_user_id'] = $user_id;
    $row['is_archived'] = false;
    $row['archive_id'] = null;
    $row['enrollment_percentage'] = 0;
    
    if ($total_slots > 0) {
        $row['enrollment_percentage'] = round(($enrolled_count / $total_slots) * 100, 1);
    }

    $enrolledPrograms[] = $row;
}
$stmt->close();

// ==========================================
// GET ARCHIVED HISTORY DATA
// ==========================================
$archivedPrograms = [];

$archivedQuery = "
    SELECT 
        ah.id as archive_id,
        ah.original_program_id as program_id,
        ah.program_name,
        ah.program_duration,
        ah.program_duration_unit,
        ah.program_schedule_start,
        ah.program_schedule_end,
        ah.program_trainer_name as trainer,
        ah.program_category_id,
        pc.name as category_name,
        ah.enrollment_status,
        ah.enrollment_attendance as attendance,
        ah.enrollment_assessment as assessment,
        ah.enrollment_completed_at as completion_date,
        ah.archived_at,
        ah.archive_trigger,
        -- Feedback related fields
        ah.trainer_expertise_rating,
        ah.trainer_communication_rating,
        ah.trainer_methods_rating,
        ah.trainer_requests_rating,
        ah.trainer_questions_rating,
        ah.trainer_instructions_rating,
        ah.trainer_prioritization_rating,
        ah.trainer_fairness_rating,
        ah.program_knowledge_rating,
        ah.program_process_rating,
        ah.program_environment_rating,
        ah.program_algorithms_rating,
        ah.program_preparation_rating,
        ah.system_technology_rating,
        ah.system_workflow_rating,
        ah.system_instructions_rating,
        ah.system_answers_rating,
        ah.system_performance_rating,
        ah.feedback_comments,
        ah.feedback_submitted_at
    FROM archived_history ah
    LEFT JOIN program_categories pc ON ah.program_category_id = pc.id
    WHERE ah.user_id = ?
    ORDER BY ah.archived_at DESC, ah.enrollment_completed_at DESC
";

$archivedStmt = $conn->prepare($archivedQuery);
$archivedStmt->bind_param("i", $user_id);
$archivedStmt->execute();
$archivedResult = $archivedStmt->get_result();

while ($archiveRow = $archivedResult->fetch_assoc()) {
    // Calculate if feedback was submitted (check if any rating exists)
    $hasFeedback = false;
    $ratingFields = [
        'trainer_expertise_rating', 'trainer_communication_rating',
        'trainer_methods_rating', 'trainer_requests_rating',
        'trainer_questions_rating', 'trainer_instructions_rating',
        'trainer_prioritization_rating', 'trainer_fairness_rating',
        'program_knowledge_rating', 'program_process_rating',
        'program_environment_rating', 'program_algorithms_rating',
        'program_preparation_rating', 'system_technology_rating',
        'system_workflow_rating', 'system_instructions_rating',
        'system_answers_rating', 'system_performance_rating'
    ];
    
    $ratings = [];
    foreach ($ratingFields as $field) {
        if (!empty($archiveRow[$field]) && is_numeric($archiveRow[$field])) {
            $ratings[] = (int)$archiveRow[$field];
            $hasFeedback = true;
        }
    }
    
    // Also check if there are comments
    if (!empty($archiveRow['feedback_comments'])) {
        $hasFeedback = true;
    }
    
    // Calculate average rating if feedback exists
    $avgRating = null;
    if (!empty($ratings)) {
        $avgRating = round(array_sum($ratings) / count($ratings), 1);
    }
    
    // Determine if certificate can be shown
    $certificateIssued = (
        $archiveRow['enrollment_status'] === 'completed' && 
        !empty($archiveRow['assessment']) && 
        strtolower($archiveRow['assessment'] ?? '') === 'passed'
    );
    
    // Format the archived data to match the program structure
    $archivedProgram = [
        'id' => $archiveRow['program_id'] ?? $archiveRow['archive_id'],
        'archive_id' => $archiveRow['archive_id'],
        'name' => $archiveRow['program_name'] ?: 'Unknown Program',
        'duration' => $archiveRow['program_duration'],
        'duration_unit' => $archiveRow['program_duration_unit'] ?? 'Days',
        'scheduleStart' => $archiveRow['program_schedule_start'],
        'scheduleEnd' => $archiveRow['program_schedule_end'],
        'trainer' => $archiveRow['trainer'] ?? 'Unknown Trainer',
        'category_name' => $archiveRow['category_name'] ?? 'General',
        'enrollment_status' => $archiveRow['enrollment_status'] ?? 'archived',
        'attendance' => $archiveRow['attendance'] ?? 0,
        'assessment' => $archiveRow['assessment'],
        'completed_at' => $archiveRow['completion_date'] ?? $archiveRow['archived_at'],
        'archived_at' => $archiveRow['archived_at'],
        'archive_trigger' => $archiveRow['archive_trigger'],
        
        // Feedback summary
        'has_feedback' => $hasFeedback,
        'feedback_rating' => $avgRating,
        'feedback_comments' => $archiveRow['feedback_comments'],
        'feedback_submitted_at' => $archiveRow['feedback_submitted_at'],
        'feedback_details' => $archiveRow,
        
        // Set flags for archived records
        'is_enrolled' => false,
        'is_pending' => false,
        'is_completed_status' => ($archiveRow['enrollment_status'] === 'completed'),
        'has_enrollment' => false,
        'is_completed' => true, // Treat archived as completed
        'is_past_end_date' => true,
        'show_certificate' => $certificateIssued,
        'is_from_history' => true,
        'is_archived' => true,
        'has_feedback_value' => $hasFeedback,
        'enrolled_user_id' => $user_id,
        
        // Use original counts or set defaults
        'total_slots' => 0,
        'available_slots' => 0,
        'enrolled_count' => 0,
        'enrollment_percentage' => 0,
        'is_full' => false
    ];
    
    $archivedPrograms[] = $archivedProgram;
}
$archivedStmt->close();

// ==========================================
// GET AVAILABLE PROGRAMS (WITH SCHEDULE START CHECK)
// ==========================================
$availablePrograms = [];

// Get current date/time for comparison
$currentDateTime = new DateTime();

$availableQuery = "
    SELECT p.id, p.name, p.duration, p.scheduleStart, p.scheduleEnd, p.trainer, p.total_slots, p.slotsAvailable,
           pc.name as category_name,
           (SELECT COUNT(*) FROM enrollments e2 WHERE e2.program_id = p.id AND e2.enrollment_status IN ('approved', 'completed')) as enrolled_count
    FROM programs p
    LEFT JOIN program_categories pc ON p.category_id = pc.id
    WHERE p.id NOT IN (SELECT program_id FROM enrollments WHERE user_id = ?)
    AND p.status = 'active'
    AND p.show_on_index = 1
    ORDER BY p.created_at DESC
";

$stmtAvailable = $conn->prepare($availableQuery);
$stmtAvailable->bind_param("i", $user_id);
$stmtAvailable->execute();
$availableRes = $stmtAvailable->get_result();

while ($availableRow = $availableRes->fetch_assoc()) {
    // Check if scheduleStart has already started or passed
    $hideProgram = false;
    
    if (!empty($availableRow['scheduleStart']) && $availableRow['scheduleStart'] !== '0000-00-00') {
        try {
            $scheduleStart = new DateTime($availableRow['scheduleStart']);
            
            // If schedule start is today or in the past, hide the program
            if ($scheduleStart <= $currentDateTime) {
                $hideProgram = true;
            }
        } catch (Exception $e) {
            // If date parsing fails, don't hide (show by default)
            $hideProgram = false;
        }
    }
    
    // Skip this program if schedule has already started
    if ($hideProgram) {
        continue;
    }
    
    $total_slots = $availableRow['total_slots'] ?? $availableRow['slotsAvailable'] ?? 0;
    $enrolled_count = $availableRow['enrolled_count'] ?? 0;
    $available_slots = max(0, $total_slots - $enrolled_count);

    $availableRow['available_slots'] = $available_slots;
    $availableRow['total_slots'] = $total_slots;
    $availableRow['enrolled_count'] = $enrolled_count;
    $availableRow['is_enrolled'] = false;
    $availableRow['is_pending'] = false;
    $availableRow['is_completed_status'] = false;
    $availableRow['has_enrollment'] = false;
    $availableRow['is_completed'] = false;
    $availableRow['is_past_end_date'] = false;
    $availableRow['show_certificate'] = false;
    $availableRow['is_from_history'] = false;
    $availableRow['is_archived'] = false;
    $availableRow['archive_id'] = null;
    $availableRow['has_feedback_value'] = false;
    $availableRow['is_full'] = ($available_slots <= 0);
    $availableRow['enrolled_user_id'] = null;
    $availableRow['enrollment_percentage'] = 0;
    
    if ($total_slots > 0) {
        $availableRow['enrollment_percentage'] = round(($enrolled_count / $total_slots) * 100, 1);
    }
    $availablePrograms[] = $availableRow;
}
$stmtAvailable->close();

// Merge all programs
$programs = array_merge($enrolledPrograms, $archivedPrograms, $availablePrograms);

// Get filter counts
$filter_counts = ['active' => 0, 'pending' => 0, 'completed' => 0, 'archived' => 0, 'available' => 0];

foreach ($programs as $program) {
    if (isset($program['is_archived']) && $program['is_archived']) {
        $filter_counts['archived']++;
    } elseif ($program['has_enrollment'] ?? false) {
        if ($program['is_enrolled'] ?? false) {
            if (($program['is_completed_status'] ?? false) || ($program['is_completed'] ?? false) || ($program['is_from_history'] ?? false)) {
                $filter_counts['completed']++;
            } else {
                $filter_counts['active']++;
            }
        } elseif ($program['is_pending'] ?? false) {
            $filter_counts['pending']++;
        }
    }
}

// ==========================================
// GET NOTIFICATIONS (ALL, NOT JUST UNREAD)
// ==========================================
$notifications = [];
$allNotifications = []; // Store all notifications for display

// Get ALL notifications (read + unread)
$notifQuery = $conn->prepare("
    SELECT id, title, message, is_read, 
           DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') AS created_at 
    FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$notifQuery->bind_param("i", $user_id);
$notifQuery->execute();
$nResult = $notifQuery->get_result();
while ($row = $nResult->fetch_assoc()) {
    $allNotifications[] = $row;
    // Keep unread count separate for badge
    if (!$row['is_read']) {
        $notifications[] = $row;
    }
}
$notifQuery->close();

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function formatDate($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00') {
        return 'N/A';
    }
    try {
        $date = new DateTime($dateStr);
        return $date->format('F j, Y');
    } catch (Exception $e) {
        return $dateStr;
    }
}

function formatDateTime($dateTimeStr) {
    if (empty($dateTimeStr) || $dateTimeStr === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    try {
        $date = new DateTime($dateTimeStr);
        return $date->format('F j, Y \a\t g:i A');
    } catch (Exception $e) {
        return $dateTimeStr;
    }
}

// Filter program arrays
$myPrograms = array_filter($programs, function($p) use ($user_id) {
    return isset($p['enrolled_user_id']) && $p['enrolled_user_id'] == $user_id;
});

$availableOnlyPrograms = array_filter($programs, function($p) use ($user_id) {
    return !isset($p['enrolled_user_id']) || $p['enrolled_user_id'] != $user_id;
});

$archivedOnlyPrograms = array_filter($programs, function($p) {
    return isset($p['is_archived']) && $p['is_archived'] === true;
});

// Determine if available programs should be disabled (has active program OR has pending enrollment)
$disableAvailablePrograms = ($hasActiveProgram || $hasPendingApplications);
$disableReason = $hasActiveProgram ? 'active program' : 'pending application';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainee Dashboard - Livelihood Enrollment and Monitoring System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';">
    <style>
    * {margin:0; padding:0; box-sizing:border-box;}
    body {font-family: 'Poppins', sans-serif; background-color: #f3f4f6; overflow-x:hidden;}
    .flex {display:flex;} .flex-col {flex-direction:column;} .min-h-screen {min-height:100vh;} .bg-gray-100 {background-color:#f3f4f6;}

    /* Header */
    .header-bar {background: #1c2a3a; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.5rem; position: sticky; top: 0; z-index: 100; flex-wrap: nowrap; min-height: 60px;}
    .header-left {display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0;}
    .logo {width: 2.5rem; height: 2.5rem; background: #fff; border-radius: 5px; flex-shrink: 0;}
    .system-name-container {display: flex; flex-direction: column; min-width: 0; flex: 1;}
    .system-name-full {font-weight: 600; font-size: 1.125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
    .system-name-abbr {font-weight: 600; font-size: 1rem; display: none; white-space: nowrap;}
    .mobile-menu-btn {display: none; background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; padding: 0.5rem; z-index: 1000; flex-shrink: 0;}
    .header-right {display: flex; align-items: center; gap: 1rem; flex-shrink: 0;}

    /* Notifications */
    .notification-container {position: relative;}
    .notification-btn {background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; position: relative; padding: 0.5rem; flex-shrink: 0;}
    .notification-badge {position: absolute; top: 0; right: 0; background: #ef4444; color: white; border-radius: 50%; width: 1.25rem; height: 1.25rem; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;}
    .notification-dropdown {position: absolute; right: 0; top: 100%; margin-top: 0.5rem; width: 20rem; background: white; color: black; border-radius: 0.5rem; box-shadow: 0 10px 25px rgba(0,0,0,.15); z-index: 50; max-height: 24rem; overflow: auto; display: none;}
    .notification-header {padding: 0.75rem 1rem; border-bottom: 1px solid #e5e7eb; font-weight: 600; position: relative;}
    .notification-list {list-style: none;}
    .notification-item {padding: 0.75rem 1rem; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: 0.2s; position: relative; padding-right: 30px;}
    .notification-item:hover {background: #f9fafb;}
    .notification-title {font-weight: 500; font-size: 0.875rem; margin-bottom: 0.25rem;}
    .notification-message {font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem; line-height: 1.4;}
    .notification-time {font-size: 0.75rem; color: #9ca3af;}
    .no-notifications {padding: 2rem 1rem; text-align: center; color: #6b7280;}
    .mark-all-btn {position: absolute; right: 10px; top: 10px; background: #3b82f6; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 4px;}
    .mark-all-btn:hover {background: #2563eb !important;}
    .notification-item::after {content: '×'; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; cursor: pointer; font-size: 1.2rem; opacity: 0; transition: opacity 0.2s;}
    .notification-item:hover::after {opacity: 1; color: #ef4444;}

    /* Notification item styles - FIXED */
    .notification-item.unread {
        background: #f0f9ff;
        border-left: 3px solid #3b82f6;
    }

    .notification-item.unread:hover {
        background: #e0f2fe;
    }

    .notification-item .notification-title {
        transition: font-weight 0.2s;
    }

    /* Keep unread items bold even after hover */
    .notification-item.unread .notification-title {
        font-weight: 600 !important;
    }

    .notification-item.unread .notification-message {
        font-weight: 500;
        color: #1e293b;
    }

    /* Profile */
    .profile-container {position: relative;}
    .profile-btn {display: flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none; padding: 0.5rem; border-radius: 0.25rem; transition: 0.2s; flex-shrink: 0; max-width: 200px;}
    .profile-btn:hover {background: rgba(255,255,255,.1);}
    .profile-text {white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;}
    .profile-dropdown {position: absolute; right: 0; top: 100%; margin-top: 0.5rem; width: 12rem; background: white; color: black; border-radius: 0.375rem; box-shadow: 0 4px 6px rgba(0,0,0,.1); z-index: 50; display: none;}
    .profile-dropdown ul {list-style: none;}
    .dropdown-item {width: 100%; text-align: left; padding: 0.5rem 1rem; background: none; border: none; cursor: pointer; transition: 0.2s; font-size: 0.875rem;}
    .dropdown-item:hover {background: #e5e7eb;}
    .logout-btn {color: #dc2626;}

    /* Body */
    .body-container {display: flex; flex: 1; position: relative;}

    /* Sidebar - Trainee only */
    .sidebar {width: 16rem; background: #1c2a3a; color: white; display: flex; flex-direction: column; padding: 1rem; gap: 0.5rem; transition: 0.3s; position: relative;}
    .sidebar-btn {padding: 0.75rem 1rem; border-radius: 0.25rem; border: none; text-align: left; background: #2b3b4c; color: white; cursor: pointer; transition: 0.3s; font-size: 0.875rem;}
    .sidebar-btn:hover {background: #35485b;}
    .sidebar-btn.active {background: #059669;}

    /* Main Content */
    .main-content {flex: 1; padding: 1.5rem; transition: 0.3s;}
    .welcome-text {font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem;}
    .view-content {animation: fadeIn 0.3s ease-in-out;}
    @keyframes fadeIn {from {opacity:0;} to {opacity:1;}}

    /* Search */
    .search-container {margin-bottom: 1.5rem; max-width: 500px; position: relative;}
    .search-input {padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid #d1d5db; background: white; color: black; width: 100%; font-size: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);}
    .search-input:focus {outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,0.3); border-color: #3b82f6;}
    .clear-search-btn {position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer; font-size: 1.125rem;}

    /* Filter */
    .filter-container {margin: 1.5rem 0; padding: 1rem; background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);}
    .filter-label {font-weight: 600; color: #374151; margin-bottom: 0.75rem; font-size: 1rem;}
    .filter-buttons {display: flex; gap: 0.75rem; flex-wrap: wrap;}
    .filter-btn {padding: 0.625rem 1.25rem; border-radius: 0.375rem; border: 2px solid #e5e7eb; background: white; color: #374151; font-size: 0.875rem; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.25rem; font-weight: 500; min-height: 44px;}
    .filter-btn:hover:not(:disabled) {background: #f3f4f6; border-color: #9ca3af; transform: translateY(-1px);}
    .filter-btn.active {background: #3b82f6; color: white; border-color: #3b82f6; font-weight: 600; box-shadow: 0 2px 4px rgba(59,130,246,0.3);}
    .filter-btn:disabled {opacity: 0.5; cursor: not-allowed;}
    .filter-btn:active:not(:disabled) {transform: scale(0.98);}

    /* Two-Section Layout Styles */
    .programs-sections-wrapper {display: flex; flex-direction: column; gap: 2.5rem;}

    .programs-section {background: white; border-radius: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden;}

    .section-header {display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; border-bottom: 2px solid #f3f4f6; gap: 1rem; flex-wrap: wrap;}

    .section-header-left {display: flex; align-items: center; gap: 0.75rem;}

    .section-icon {width: 2.5rem; height: 2.5rem; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: white; flex-shrink: 0;}
    .section-icon.available-icon {background: linear-gradient(135deg, #0ea5e9, #0284c7);}
    .section-icon.available-icon.locked {background: linear-gradient(135deg, #9ca3af, #6b7280);}
    .section-icon.my-programs-icon {background: linear-gradient(135deg, #10b981, #059669);}
    .section-icon.archived-icon {background: linear-gradient(135deg, #8b5cf6, #6d28d9);}

    .section-title {font-size: 1.125rem; font-weight: 700; color: #1c2a3a;}
    .section-subtitle {font-size: 0.8rem; color: #6b7280; margin-top: 0.1rem;}

    .section-count-badge {padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; flex-shrink: 0;}
    .section-count-badge.available-badge {background: #e0f2fe; color: #0369a1;}
    .section-count-badge.available-badge.locked {background: #f3f4f6; color: #6b7280;}
    .section-count-badge.my-programs-badge {background: #d1fae5; color: #065f46;}
    .section-count-badge.archived-badge {background: #ede9fe; color: #5b21b6;}

    .section-body {padding: 1.25rem 1.5rem;}

    .active-lock-banner {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.875rem 1rem;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 0.5rem;
        margin-bottom: 1.25rem;
        color: #991b1b;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    .active-lock-banner i {font-size: 1.1rem; margin-top: 0.1rem; flex-shrink: 0; color: #dc2626;}
    .active-lock-banner strong {display: block; margin-bottom: 0.2rem; font-size: 0.9rem;}

    .section-divider {display: flex; align-items: center; gap: 1rem; color: #9ca3af; font-size: 0.8rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;}
    .section-divider::before, .section-divider::after {content: ''; flex: 1; height: 1px; background: #e5e7eb;}

    /* Program Cards - Fixed Layout */
    .programs-list {display: flex; flex-direction: column; gap: 1rem;}

    .program-card {
        border-radius: 0.75rem;
        padding: 1.25rem;
        transition: all 0.3s ease;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        display: block;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 0.5rem;
    }
    
    .program-card:hover:not(.full):not(.locked-card):not([onclick*="showArchivedModal"]) {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }

    .program-card.enrolled-active {
        background: linear-gradient(to right, #dbeafe, #eff6ff);
        border-left: 6px solid #3b82f6;
        border-top: 1px solid #bfdbfe;
        border-right: 1px solid #bfdbfe;
        border-bottom: 1px solid #bfdbfe;
    }
    .program-card.enrolled-active:hover {
        background: #bfdbfe;
        border-left-color: #2563eb;
    }

    .program-card.full {
        background: #fee2e2;
        border-left: 6px solid #ef4444;
        opacity: 0.8;
        cursor: not-allowed;
    }
    .program-card.full:hover {
        background: #fecaca;
        transform: none;
        box-shadow: none;
    }
    
    .program-card.pending {
        background: linear-gradient(to right, #fef3c7, #fffbeb);
        border-left: 6px solid #f59e0b;
        border-top: 1px solid #fde68a;
        border-right: 1px solid #fde68a;
        border-bottom: 1px solid #fde68a;
        cursor: pointer;
    }
    .program-card.pending:hover {
        background: #fde68a;
        border-left-color: #d97706;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }
    
    .program-card.completed {
        background: linear-gradient(to right, #d1fae5, #ecfdf5);
        border-left: 6px solid #10b981;
        border-top: 1px solid #a7f3d0;
        border-right: 1px solid #a7f3d0;
        border-bottom: 1px solid #a7f3d0;
    }
    .program-card.completed:hover {
        background: #a7f3d0;
        border-left-color: #059669;
    }
    
    .program-card.available {
        background: linear-gradient(to right, #f0f9ff, #ffffff);
        border-left: 6px solid #0ea5e9;
        border-top: 1px solid #bae6fd;
        border-right: 1px solid #bae6fd;
        border-bottom: 1px solid #bae6fd;
    }
    .program-card.available:hover:not(.locked-card) {
        background: #e0f2fe;
        border-left-color: #0284c7;
    }
    
    .program-card.available.locked-card {
        background: #f3f4f6 !important;
        border-left: 6px solid #9ca3af !important;
        opacity: 0.6;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    .program-card.history {
        background: linear-gradient(to right, #f3f4f6, #fafafa);
        border-left: 6px solid #6b7280;
        border-top: 1px solid #e5e7eb;
        border-right: 1px solid #e5e7eb;
        border-bottom: 1px solid #e5e7eb;
    }
    .program-card.history:hover {
        background: #e5e7eb;
        border-left-color: #4b5563;
    }
    
    .program-card.archived {
        background: linear-gradient(to right, #ede9fe, #f5f3ff);
        border-left: 6px solid #8b5cf6;
        border-top: 1px solid #ddd6fe;
        border-right: 1px solid #ddd6fe;
        border-bottom: 1px solid #ddd6fe;
        cursor: pointer;
    }
    .program-card.archived:hover {
        background: #ddd6fe;
        border-left-color: #7c3aed;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2);
    }

    .program-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .program-name {
        font-weight: 700;
        font-size: 1.125rem;
        color: #1c2a3a;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        line-height: 1.4;
    }
    
    .full-badge {
        background: #ef4444;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .new-badge {
        background: #0ea5e9;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .locked-badge {
        background: #6b7280;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .status-badge {
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-left: 0.5rem;
    }
    .status-approved {background: #3b82f6; color: white;}
    .status-pending {background: #f59e0b; color: white;}
    .status-completed {background: #10b981; color: white;}
    .status-history {background: #6b7280; color: white;}
    .status-archived {background: #8b5cf6; color: white;}
    .status-available {background: #0ea5e9; color: white;}
    .status-locked {background: #6b7280; color: white;}

    .program-details {
        font-size: 0.875rem;
        color: #4b5563;
    }
    
    .program-details p {
        margin-bottom: 0.35rem;
        line-height: 1.5;
        display: flex;
        flex-wrap: wrap;
    }
    
    .program-details p b {
        min-width: 100px;
        color: #374151;
        font-weight: 600;
    }

    .no-programs {
        text-align: center;
        color: #6b7280;
        font-size: 1rem;
        padding: 2.5rem 1.5rem;
        background: #f9fafb;
        border-radius: 0.75rem;
        border: 2px dashed #e5e7eb;
    }
    
    .no-programs i {
        font-size: 2.5rem;
        color: #9ca3af;
        margin-bottom: 1rem;
    }

    .sidebar-overlay {display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.5); z-index: 89;}
    .sidebar-overlay.active {display: block;}
    .role-badge {background: #3b82f6; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: bold; margin-left: 0.5rem;}

    .enrollment-status {
        margin-top: 0.75rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem;
        background: #f8fafc;
        border-left: 4px solid #3b82f6;
    }
    .status-message {font-size: 0.875rem; color: #4b5563; font-weight: 500; display: flex; align-items: center; gap: 0.5rem;}
    .completed-status {background: #d1fae5; border-left: 4px solid #10b981;}
    .completed-status .status-message {color: #065f46;}
    .available-status {background: #e0f2fe; border-left: 4px solid #0ea5e9;}
    .available-status .status-message {color: #0369a1;}
    .locked-status {background: #fef2f2; border-left: 4px solid #ef4444;}
    .locked-status .status-message {color: #991b1b;}
    .history-status {background: #f3f4f6; border-left: 4px solid #6b7280;}
    .history-status .status-message {color: #4b5563;}
    .archived-status {
        background: #ede9fe;
        border-left: 4px solid #8b5cf6;
    }
    .archived-status .status-message {
        color: #5b21b6;
    }
    .active-enrolled-status {background: #dbeafe; border-left: 4px solid #3b82f6;}
    .active-enrolled-status .status-message {color: #1e40af;}
    .pending-status {
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
    }
    .pending-status .status-message {
        color: #92400e;
    }

    .click-indicator {margin-top: 0.5rem; font-size: 0.75rem; color: #6b7280; display: flex; align-items: center; gap: 0.25rem;}
    .history-badge {background: #6b7280; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 600; margin-left: 0.5rem;}
    .archive-badge {background: #8b5cf6; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 600; margin-left: 0.5rem;}

    .loading-archive {text-align: center; padding: 3rem; color: #6b7280; font-size: 1.125rem;}
    .loading-archive i {font-size: 2rem; margin-bottom: 1rem; color: #3b82f6;}

    .certificate-btn {display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #10b981; color: white; border: none; border-radius: 0.5rem; font-weight: 500; cursor: pointer; margin-top: 0.5rem; transition: all 0.3s;}
    .certificate-btn:hover {background: #059669; transform: translateY(-2px);}

    .search-results {margin: 0.5rem 0 1.5rem 0; color: #6b7280; font-size: 0.875rem; padding: 0.5rem; background: #f8fafc; border-radius: 0.25rem; border-left: 3px solid #3b82f6;}

    .no-enrollment-container {text-align: center; padding: 3rem 2rem; background: white; border-radius: 1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-top: 2rem; max-width: 600px; margin-left: auto; margin-right: auto;}
    .no-enrollment-icon {font-size: 4rem; color: #d1d5db; margin-bottom: 1.5rem;}
    .no-enrollment-title {font-size: 1.5rem; color: #374151; margin-bottom: 1rem; font-weight: 600;}
    .no-enrollment-message {color: #6b7280; margin-bottom: 2rem; font-size: 1rem; line-height: 1.6;}
    .landing-page-action-btn {display: inline-flex; align-items: center; gap: 0.75rem; padding: 0.75rem 2rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: 600; font-size: 1rem; transition: all 0.3s; border: none; cursor: pointer;}
    .landing-page-action-btn:hover {background: #2563eb; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(37,99,235,0.3);}

    .archived-detail-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: #8b5cf6;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        margin-top: 0.25rem;
    }

    .feedback-rating {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: #fbbf24;
        color: #92400e;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* ==========================================
       MODAL STYLES FOR ARCHIVED PROGRAM DETAILS
       ========================================== */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        overflow-y: auto;
        padding: 1rem;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-container {
        background: white;
        border-radius: 1rem;
        max-width: 1000px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: modalSlideIn 0.3s ease-out;
        position: relative;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        background: linear-gradient(135deg, #8b5cf6, #6d28d9);
        color: white;
        padding: 1.5rem;
        border-top-left-radius: 1rem;
        border-top-right-radius: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
    }

    .modal-header h2 {
        font-size: 1.5rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-header h2 i {
        font-size: 1.75rem;
    }

    .modal-close {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }

    .modal-close:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-section {
        background: #f9fafb;
        border-radius: 0.75rem;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        border-left: 4px solid #8b5cf6;
    }

    .modal-section h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1c2a3a;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-section h3 i {
        color: #8b5cf6;
    }

    .modal-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .modal-info-item {
        padding: 0.5rem;
        background: white;
        border-radius: 0.5rem;
        border: 1px solid #e5e7eb;
    }

    .modal-info-item strong {
        display: block;
        font-size: 0.75rem;
        color: #6b7280;
        margin-bottom: 0.25rem;
    }

    .modal-info-item span {
        font-size: 1rem;
        font-weight: 500;
        color: #1c2a3a;
    }

    .rating-badge-large {
        display: inline-block;
        background: #fbbf24;
        color: #92400e;
        padding: 0.5rem 1rem;
        border-radius: 2rem;
        font-weight: 600;
        font-size: 1.125rem;
    }

    .feedback-detail {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .feedback-rating-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .rating-category {
        text-align: center;
        padding: 0.75rem;
        background: #f3f4f6;
        border-radius: 0.5rem;
    }

    .rating-category .category-name {
        font-size: 0.8rem;
        color: #6b7280;
        margin-bottom: 0.25rem;
    }

    .rating-category .category-value {
        font-weight: 600;
        color: #1c2a3a;
        font-size: 1.1rem;
    }

    .rating-stars {
        color: #fbbf24;
        margin-left: 0.25rem;
    }

    /* Certificate Preview Section with Screenshot Protection */
    .certificate-container {
        width: 100%;
        max-width: 210mm;
        margin: 0 auto;
        background: #f5f0e8;
        position: relative;
        box-sizing: border-box;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        padding: 20px;
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        pointer-events: none;
    }
    
    /* Screenshot Prevention Overlay */
    .screenshot-protection-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.001); /* Nearly invisible */
        z-index: 9999;
        cursor: default;
        pointer-events: none;
    }
    
    /* Dynamic Moving Watermark */
    .dynamic-watermark {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        z-index: 10000;
        overflow: hidden;
    }
    
    .watermark-text {
        position: absolute;
        color: rgba(139, 92, 246, 0.15);
        font-size: 24px;
        font-weight: bold;
        white-space: nowrap;
        transform: rotate(-45deg);
        text-transform: uppercase;
        letter-spacing: 5px;
        animation: moveWatermark 20s linear infinite;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        border: 2px solid rgba(139, 92, 246, 0.2);
        padding: 10px 30px;
        border-radius: 50px;
    }
    
    @keyframes moveWatermark {
        0% {
            transform: rotate(-45deg) translateX(-100%) translateY(-100%);
        }
        100% {
            transform: rotate(-45deg) translateX(100%) translateY(100%);
        }
    }
    
    .watermark-text:nth-child(1) { animation-delay: 0s; top: 10%; left: 10%; }
    .watermark-text:nth-child(2) { animation-delay: 5s; top: 30%; left: 30%; }
    .watermark-text:nth-child(3) { animation-delay: 10s; top: 50%; left: 50%; }
    .watermark-text:nth-child(4) { animation-delay: 15s; top: 70%; left: 70%; }
    .watermark-text:nth-child(5) { animation-delay: 2.5s; top: 90%; left: 90%; }
    
    /* Decorative border */
    .decorative-border {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border: 35px solid transparent;
        border-image: repeating-linear-gradient(
            45deg,
            #2d8b8e 0px,
            #2d8b8e 10px,
            #d4a574 10px,
            #d4a574 20px,
            #2d8b8e 20px,
            #2d8b8e 30px,
            #f5f0e8 30px,
            #f5f0e8 40px
        ) 35;
        pointer-events: none;
        z-index: 2;
    }
    
    /* Inner decorative pattern border */
    .inner-border {
        position: absolute;
        top: 20px;
        left: 20px;
        right: 20px;
        bottom: 20px;
        border: 15px solid;
        border-image: repeating-linear-gradient(
            0deg,
            #2d8b8e 0px,
            #2d8b8e 3px,
            #d4a574 3px,
            #d4a574 6px,
            #2d8b8e 6px,
            #2d8b8e 9px,
            #f5f0e8 9px,
            #f5f0e8 12px
        ) 15;
        pointer-events: none;
        z-index: 2;
    }
    
    .certificate-content {
        position: relative;
        width: 100%;
        height: 100%;
        padding: 40px 50px;
        z-index: 1;
        filter: blur(0.3px); /* Subtle blur to deter high-quality screenshots */
    }
    
    .logos-row {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 30px;
        margin: 15px 0 20px 0;
        flex-wrap: wrap;
    }
    
    .logo-item {
        width: 80px;
        height: 80px;
    }
    
    .logo-item img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        filter: grayscale(20%); /* Subtle effect to deter copying */
    }
    
    .header-top {
        text-align: center;
        font-size: 16px;
        font-weight: bold;
        color: black;
        margin: 15px 0 5px 0;
        padding: 0;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .cooperation {
        text-align: center;
        font-size: 14px;
        color: black;
        margin: 5px 0;
        padding: 0;
        line-height: 1.2;
    }
    
    .tesda {
        text-align: center;
        font-size: 14px;
        font-weight: bold;
        color: black;
        margin: 5px 0;
        padding: 0;
        line-height: 1.2;
        text-transform: uppercase;
    }
    
    .training-center {
        text-align: center;
        font-size: 20px;
        font-weight: bold;
        color: #2d8b8e;
        margin: 8px 0 35px 0;
        padding: 0;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .certificate-title {
        text-align: center;
        margin: 0 0 35px 0;
        padding: 0;
    }
    
    .certificate-title h1 {
        font-size: 42px;
        margin: 0;
        color: #2d8b8e;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 4px;
        line-height: 1;
    }
    
    .awarded-to {
        text-align: center;
        margin: 0 0 20px 0;
        padding: 0;
    }
    
    .awarded-to p {
        font-size: 18px;
        margin: 0;
        color: black;
        line-height: 1.3;
        font-weight: normal;
    }
    
    .trainee-name-container {
        text-align: center;
        margin: 0 0 25px 0;
        padding: 0;
    }
    
    .trainee-name {
        font-size: 42px;
        color: black;
        font-weight: bold;
        text-transform: uppercase;
        padding: 0;
        display: inline-block;
        letter-spacing: 2px;
        line-height: 1.1;
        border-bottom: 2px solid #2d8b8e;
        padding-bottom: 5px;
    }
    
    .completion-text {
        text-align: center;
        margin: 0 0 20px 0;
        padding: 0;
    }
    
    .completion-text p {
        font-size: 16px;
        margin: 0;
        color: black;
        line-height: 1.3;
        font-weight: normal;
    }
    
    .hours-highlight {
        color: #d94a3d;
        font-weight: bold;
    }
    
    .training-name-container {
        text-align: center;
        margin: 0 0 30px 0;
        padding: 0;
    }
    
    .training-name {
        font-size: 32px;
        color: black;
        font-weight: bold;
        text-transform: uppercase;
        padding: 0;
        display: inline-block;
        letter-spacing: 2px;
        line-height: 1.1;
        border-bottom: 2px solid #2d8b8e;
        padding-bottom: 5px;
    }
    
    .given-date {
        text-align: center;
        margin: 0 0 40px 0;
        padding: 0;
    }
    
    .given-date p {
        font-size: 16px;
        margin: 0;
        color: black;
        line-height: 1.4;
        font-weight: normal;
    }
    
    .signatures {
        margin-top: 40px;
    }
    
    .signatures-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        position: relative;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .left-signatures {
        display: flex;
        flex-direction: column;
        gap: 35px;
        flex: 1;
        min-width: 300px;
    }
    
    .signature-block {
        text-align: center;
    }
    
    .signature-line {
        border-bottom: 2px solid black;
        width: 280px;
        margin: 0 auto 5px auto;
        height: 1px;
    }
    
    .signature-name {
        font-size: 15px;
        font-weight: bold;
        color: black;
        text-transform: uppercase;
        margin: 0;
        letter-spacing: 0.5px;
        line-height: 1.2;
    }
    
    .signature-title {
        font-size: 14px;
        color: black;
        margin: 3px 0 0 0;
        font-weight: normal;
        line-height: 1.2;
    }
    
    .photo-signature-section {
        width: 180px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .photo-box {
        width: 150px;
        height: 180px;
        border: 2px solid #888;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 10px;
    }
    
    .photo-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .photo-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(to bottom, #e8e8e8 0%, #f5f5f5 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #999;
        font-size: 12px;
    }
    
    .photo-signature-line {
        border-bottom: 2px solid black;
        width: 150px;
        margin: 5px 0;
    }
    
    .photo-signature-label {
        font-size: 12px;
        color: black;
        text-align: center;
    }
    
    /* Non-Official Watermark */
    .non-official-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 60px;
        font-weight: 900;
        color: rgba(255, 0, 0, 0.15);
        text-transform: uppercase;
        white-space: nowrap;
        pointer-events: none;
        z-index: 9998;
        border: 5px solid rgba(255, 0, 0, 0.2);
        padding: 20px 50px;
        border-radius: 20px;
        letter-spacing: 10px;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        animation: pulse 3s ease-in-out infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 0.15; transform: translate(-50%, -50%) rotate(-30deg) scale(1); }
        50% { opacity: 0.25; transform: translate(-50%, -50%) rotate(-30deg) scale(1.05); }
    }
    
    /* QR Code Watermark */
    .qr-watermark {
        position: absolute;
        bottom: 20px;
        right: 20px;
        width: 80px;
        height: 80px;
        background: rgba(0,0,0,0.1);
        border: 2px solid rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        color: rgba(0,0,0,0.3);
        z-index: 9999;
        pointer-events: none;
        border-radius: 10px;
    }
    
    .qr-watermark::before {
        content: 'UNOFFICIAL';
        font-weight: bold;
        font-size: 8px;
        color: rgba(255,0,0,0.3);
        transform: rotate(-90deg);
    }
    
    .watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.06;
        z-index: 0;
        pointer-events: none;
    }
    
    .watermark img {
        max-width: 500px;
        max-height: 500px;
        width: 100%;
        height: auto;
        object-fit: contain;
    }
    
    @media print {
        body * {
            visibility: hidden;
        }
        .certificate-preview, .certificate-preview * {
            visibility: visible;
        }
        .certificate-preview {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            border: none;
        }
        .certificate-actions, .modal-header, .modal-close, .btn {
            display: none;
        }
    }
    
    @media (max-width: 768px) {
        .certificate-content {padding: 20px;}
        .certificate-title h1 {font-size: 32px;}
        .trainee-name {font-size: 32px;}
        .training-name {font-size: 24px;}
        .signature-line {width: 200px;}
        .left-signatures {min-width: 200px;}
        .modal-grid {grid-template-columns: 1fr;}
        .feedback-rating-grid {grid-template-columns: 1fr;}
    }
    
    * {
        box-sizing: border-box;
    }
    
    .screenshot-restriction {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: transparent;
        z-index: 10;
        display: none;
    }

    .screenshot-restriction.active {
        display: block;
    }

    .restriction-message {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 1rem 2rem;
        border-radius: 0.5rem;
        font-size: 1.1rem;
        text-align: center;
        animation: fadeInOut 2s ease;
    }

    @keyframes fadeInOut {
        0% { opacity: 0; }
        20% { opacity: 1; }
        80% { opacity: 1; }
        100% { opacity: 0; }
    }

    .certificate-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        border: none;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-primary {
        background: #0ea5e9;
        color: white;
    }

    .btn-primary:hover {
        background: #0284c7;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #8b5cf6;
        color: white;
    }

    .btn-secondary:hover {
        background: #7c3aed;
        transform: translateY(-2px);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid #0ea5e9;
        color: #0ea5e9;
    }

    .btn-outline:hover {
        background: #0ea5e9;
        color: white;
    }

    /* Pending Modal Specific Styles */
    .modal-header.pending-header {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    
    .modal-section.pending-section {
        border-left-color: #f59e0b;
    }
    
    .modal-section.pending-section h3 i {
        color: #f59e0b;
    }

    /* Disable image dragging */
    img {
        -webkit-user-drag: none;
        -khtml-user-drag: none;
        -moz-user-drag: none;
        -o-user-drag: none;
        user-drag: none;
    }

    @media (max-width: 768px) {
        .mobile-menu-btn {display: block !important;}
        .system-name-full {display: none;}
        .system-name-abbr {display: block;}
        .sidebar {position: fixed; top: 0; left: -100%; width: 280px; height: 100vh; z-index: 999; transition: left 0.3s ease-in-out;}
        .sidebar.mobile-open {left: 0;}
        .main-content {width: 100%; padding: 1rem;}
        .program-card {padding: 1rem;}
        .program-details p b {min-width: 80px;}
        .welcome-text {font-size: 1.25rem; margin-bottom: 1rem;}
        .filter-container {padding: 0.75rem;}
        .filter-buttons {width: 100%;}
        .filter-btn {flex: 1; min-width: 0; text-align: center; justify-content: center; padding: 0.5rem 0.75rem;}
        body.sidebar-open {overflow: hidden; position: fixed; width: 100%;}
        .notification-dropdown {width: 280px; right: -30px;}
        .profile-dropdown {width: 180px; right: 0;}
        .search-container {max-width: 100%;}
        .section-header {padding: 1rem;}
        .section-body {padding: 1rem;}
        .programs-sections-wrapper {gap: 1.5rem;}
        .certificate-actions {flex-direction: column;}
    }
    @media (max-width: 480px) {
        .system-name-abbr {font-size: 0.9rem;}
        .profile-text {max-width: 80px; font-size: 0.8rem;}
        .header-right {gap: 0.5rem;}
        .filter-buttons {flex-direction: column;}
        .filter-btn {width: 100%;}
        .section-title {font-size: 1rem;}
        .program-name {font-size: 1rem;}
        .program-header {flex-direction: column; align-items: flex-start;}
    }
    </style>
</head>
<body>
<div class="flex flex-col min-h-screen bg-gray-100">

    <!-- HEADER -->
    <header class="header-bar">
        <div class="header-left">
            <img src="../css/logo2.jpg" class="logo" alt="Logo" draggable="false">
            <div class="system-name-container">
                <span class="system-name-full">Livelihood Enrollment & Monitoring System</span>
                <span class="system-name-abbr">LEMS</span>
            </div>
            <button id="mobileMenuBtn" class="mobile-menu-btn"><i class="fas fa-bars"></i></button>
        </div>
        <div class="header-right">
           
          <!-- Notifications - FIXED -->
<div class="notification-container">
    <button id="notificationBtn" class="notification-btn">
        <i class="fas fa-bell"></i>
        <?php if (count($notifications) > 0): // Only unread count for badge ?>
            <span class="notification-badge"><?php echo count($notifications); ?></span>
        <?php endif; ?>
    </button>
    <div id="notificationDropdown" class="notification-dropdown">
        <div class="notification-header">
            Notifications
            <?php if (count($notifications) > 0): ?>
                <button class="mark-all-btn" onclick="markAllAsRead()"><i class="fas fa-check-double"></i> Mark all read</button>
            <?php endif; ?>
        </div>
        <ul class="notification-list" id="notificationList">
            <?php if (count($allNotifications) > 0): ?>
                <?php foreach ($allNotifications as $notif): ?>
                    <li class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" 
                        data-id="<?php echo $notif['id']; ?>"
                        data-read="<?php echo $notif['is_read'] ? 'true' : 'false'; ?>">
                        <div class="notification-title">
                            <?php echo htmlspecialchars($notif['title']); ?>
                            <?php if (!$notif['is_read']): ?>
                                <span class="new-badge" style="background: #3b82f6; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.7rem; margin-left: 5px;">NEW</span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-message">
                            <?php echo htmlspecialchars($notif['message']); ?>
                        </div>
                        <div class="notification-time"><?php echo $notif['created_at']; ?></div>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="no-notifications">No notifications</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

            <!-- Profile -->
            <div class="profile-container">
                <div id="profileBtn" class="profile-btn">
                    <i class="fas fa-user-circle"></i>
                    <span class="profile-text"><?php echo htmlspecialchars($username); ?> ▾</span>
                </div>
                <div id="profileDropdown" class="profile-dropdown">
                    <ul>
                        <li><button class="dropdown-item" onclick="goToProfile()">View/Edit Profile</button></li>
                        <li><button class="dropdown-item logout-btn" onclick="confirmLogout()">Logout</button></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <!-- BODY CONTAINER -->
    <div class="body-container">
        <!-- SIDEBAR - Trainee only -->
        <aside id="sidebar" class="sidebar">
            <button class="sidebar-btn active" onclick="location.href='dashboard.php'">Dashboard</button>
            <button class="sidebar-btn" onclick="location.href='training_progress.php'">My Training Progress</button>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <p class="welcome-text">
                Welcome, <?php echo htmlspecialchars($username); ?>
                <span class="role-badge" style="font-size:.875rem;">TRAINEE</span>
            </p>

            <div id="dashboardView" class="view-content">
                <div class="view-header">
                    <h1 class="view-title">Programs</h1>

                    <!-- SEARCH BAR -->
                    <div class="search-container">
                        <input type="text" id="searchQuery" placeholder="Search programs..." class="search-input">
                        <button id="clearSearch" class="clear-search-btn" style="display:none;">×</button>
                    </div>

                    <p id="searchResults" class="search-results" style="display:none;"></p>
                </div>

                <!-- ENROLLMENT STATUS MESSAGES -->
                <?php if($hasActiveProgram): ?>
                    <div class="enrollment-status" style="margin-bottom: 2rem; background: #dbeafe; border-left: 4px solid #3b82f6;">
                        <p class="status-message" style="font-weight: 600; color: #1e40af;">
                            <i class="fas fa-info-circle"></i> You are currently enrolled in an active program. Complete it before applying to a new one.
                        </p>
                    </div>
                <?php elseif($hasPendingApplications): ?>
                    <div class="enrollment-status pending-status" style="margin-bottom: 2rem;">
                        <p class="status-message" style="font-weight: 600;">
                            <i class="fas fa-clock"></i> You have a pending application. Wait for admin approval before applying to new programs.
                        </p>
                    </div>
                <?php elseif($hasCompletedProgram && !$hasActiveProgram): ?>
                    <div class="enrollment-status completed-status" style="margin-bottom: 2rem;">
                        <p class="status-message" style="font-weight: 600;">
                            <i class="fas fa-check-circle"></i> You have completed your training. You can apply for new programs.
                        </p>
                    </div>
                <?php elseif(!$anyApprovedEnrolled && !$hasPendingApplications): ?>
                    <div class="enrollment-status" style="margin-bottom: 2rem; background: #fef3c7; border-left: 4px solid #f59e0b;">
                        <p class="status-message" style="font-weight: 600; color: #92400e;">
                            <i class="fas fa-info-circle"></i> You are not enrolled in any program yet. Browse available programs below.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- TWO-SECTION PROGRAMS DISPLAY -->
                <?php if(count($programs) === 0): ?>
                    <div class="no-enrollment-container">
                        <div class="no-enrollment-icon"><i class="fas fa-graduation-cap"></i></div>
                        <h2 class="no-enrollment-title">No Programs Yet</h2>
                        <p class="no-enrollment-message">You haven't applied to any programs yet.<br>Browse available programs below.</p>
                        <a href="../index.php" class="landing-page-action-btn">
                            <i class="fas fa-external-link-alt"></i> Go to Landing Page
                        </a>
                    </div>
                <?php else: ?>

                <div id="programsContainer" class="programs-sections-wrapper">

                    <!-- ===== SECTION 1: AVAILABLE PROGRAMS ===== -->
                    <div class="programs-section" id="availableSection">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon available-icon <?php echo $disableAvailablePrograms ? 'locked' : ''; ?>">
                                    <i class="fas fa-<?php echo $disableAvailablePrograms ? 'lock' : 'list-alt'; ?>"></i>
                                </div>
                                <div>
                                    <div class="section-title">
                                        Available Programs
                                        <?php if($disableAvailablePrograms): ?>
                                            <span style="font-size:0.75rem; font-weight:500; color:#6b7280; margin-left:0.5rem;">(Locked)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="section-subtitle">
                                        <?php if($disableAvailablePrograms): ?>
                                            <?php echo $hasActiveProgram ? 'Complete your active program' : 'Wait for pending application approval'; ?> to apply for new ones
                                        <?php else: ?>
                                            Programs you can apply to
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="section-count-badge available-badge <?php echo $disableAvailablePrograms ? 'locked' : ''; ?>" id="availableCount">
                                <?php echo count($availableOnlyPrograms); ?> program<?php echo count($availableOnlyPrograms) !== 1 ? 's' : ''; ?>
                            </span>
                        </div>
                        <div class="section-body">

                            <?php if($disableAvailablePrograms): ?>
                            <div class="active-lock-banner">
                                <i class="fas fa-lock"></i>
                                <div>
                                    <strong>Applications are currently locked.</strong>
                                    <?php if($hasActiveProgram): ?>
                                        You are already enrolled in an active program. You must complete it before you can apply to another program.
                                    <?php elseif($hasPendingApplications): ?>
                                        You have a pending application. Please wait for admin approval before applying to new programs.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if(count($availableOnlyPrograms) === 0): ?>
                                <div class="no-programs">
                                    <i class="fas fa-check-circle" style="font-size:2rem;color:#10b981;margin-bottom:0.5rem;display:block;"></i>
                                    <p>You're enrolled in all available programs, or no new programs are currently open.</p>
                                </div>
                            <?php else: ?>
                                <div class="programs-list" id="availableProgramsList">
                                    <?php foreach($availableOnlyPrograms as $program): ?>
                                        <?php
                                        $total_slots      = $program['total_slots'];
                                        $enrolled_count   = $program['enrolled_count'];
                                        $available_slots  = $program['available_slots'];
                                        $isFull           = $program['is_full'];
                                        $isBlocked = $disableAvailablePrograms || $isFull;
                                        $programId        = $program['id'];
                                        $programName      = htmlspecialchars($program['name']);

                                        $cardClasses = 'program-card available';
                                        if ($isBlocked) {
                                            $cardClasses .= ' locked-card';
                                        }
                                        ?>

                                        <?php if($isBlocked): ?>
                                            <div class="<?php echo $cardClasses; ?>"
                                                 data-program-id="<?php echo $program['id']; ?>"
                                                 data-section="available"
                                                 title="<?php echo $isFull ? 'This program is full' : ($hasActiveProgram ? 'You cannot apply while enrolled in an active program' : 'You cannot apply while you have a pending application'); ?>.">
                                        <?php else: ?>
                                            <!-- Using onclick with JavaScript function for overlay -->
                                            <div class="<?php echo $cardClasses; ?>"
                                                 data-program-id="<?php echo $program['id']; ?>"
                                                 data-program-name="<?php echo $programName; ?>"
                                                 data-section="available"
                                                 onclick="applyForProgram(<?php echo $program['id']; ?>, '<?php echo $programName; ?>')"
                                                 style="cursor: pointer;">
                                        <?php endif; ?>

                                            <div class="program-header">
                                                <h2 class="program-name">
                                                    <?php echo htmlspecialchars($program['name']); ?>
                                                    <?php if($isBlocked): ?>
                                                        <span class="status-badge status-locked"><?php echo $isFull ? 'FULL' : 'LOCKED'; ?></span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-available">AVAILABLE</span>
                                                        <?php if(!$program['is_full']): ?>
                                                            <span class="new-badge">NEW</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if($isFull): ?><span class="full-badge">FULL</span><?php endif; ?>
                                                </h2>
                                            </div>
                                            <div class="program-details">
                                                <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                                <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> days</p>
                                                <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                                <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'To be assigned'); ?></p>
                                                <p><b>Available Slots:</b> <?php echo $available_slots; ?> / <?php echo $total_slots; ?> (<?php echo $program['enrollment_percentage']; ?>% filled)</p>

                                                <?php if($isBlocked): ?>
                                                    <div class="enrollment-status locked-status">
                                                        <p class="status-message">
                                                            <i class="fas fa-lock"></i>
                                                            <?php 
                                                            if($isFull) {
                                                                echo "This program is full. No slots available.";
                                                            } elseif($hasActiveProgram) {
                                                                echo "You cannot apply while enrolled in an active program.";
                                                            } elseif($hasPendingApplications) {
                                                                echo "You cannot apply while you have a pending application.";
                                                            } else {
                                                                echo "You cannot apply for this program at this time.";
                                                            }
                                                            ?>
                                                        </p>
                                                    </div>
                                                <?php else: ?>
                                                   <div class="enrollment-status available-status">
                                                        <span class="status-message">
                                                            <i class="fas fa-info-circle"></i>
                                                            Click to apply for this program
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                        </div><!-- Closing div for program card -->

                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== SECTION 2: MY PROGRAMS ===== -->
                    <div class="programs-section" id="myProgramsSection">
                        
                        <!-- FILTER BUTTONS -->
                        <div class="filter-container">
                            <div class="filter-label">Filter My Programs by:</div>
                            <div class="filter-buttons">
                                <button class="filter-btn <?php echo ($filter_counts['active'] > 0) ? 'active' : ''; ?>"
                                        data-filter="active">
                                    Active Programs <?php echo ($filter_counts['active'] > 0) ? '(' . $filter_counts['active'] . ')' : ''; ?>
                                </button>
                                <button class="filter-btn"
                                        data-filter="pending"
                                        <?php echo ($filter_counts['pending'] === 0) ? 'disabled' : ''; ?>>
                                    Pending Applications <?php echo ($filter_counts['pending'] > 0) ? '(' . $filter_counts['pending'] . ')' : ''; ?>
                                </button>
                                <button class="filter-btn"
                                        data-filter="archived"
                                        id="archivedFilterBtn"
                                        <?php echo ($filter_counts['archived'] === 0) ? 'disabled' : ''; ?>>
                                  Completed Programs  <?php echo ($filter_counts['archived'] > 0) ? '(' . $filter_counts['archived'] . ')' : ''; ?>
                                </button>
                            </div>
                        </div>

                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon my-programs-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div>
                                    <div class="section-title">My Programs</div>
                                </div>
                            </div>
                            <span class="section-count-badge my-programs-badge" id="myProgramsCount">
                                <?php echo count($myPrograms); ?> program<?php echo count($myPrograms) !== 1 ? 's' : ''; ?>
                            </span>
                        </div>
                        <div class="section-body">
                            <?php if(count($myPrograms) === 0): ?>
                                <div class="no-programs">
                                    <i class="fas fa-graduation-cap" style="font-size:2rem;color:#d1d5db;margin-bottom:0.5rem;display:block;"></i>
                                    <p>You haven't enrolled in any programs yet. Browse the available programs above to get started!</p>
                                </div>
                            <?php else: ?>
                                <div id="myProgramsList">
                                    <div class="programs-list">
                                    <?php foreach($myPrograms as $program):
                                        $isEnrolled        = $program['is_enrolled'] ?? false;
                                        $isPending         = $program['is_pending'] ?? false;
                                        $isCompletedStatus = $program['is_completed_status'] ?? false;
                                        $isCompleted       = $program['is_completed'] ?? false;
                                        $isPastEndDate     = $program['is_past_end_date'] ?? false;
                                        $showCertificate   = $program['show_certificate'] ?? false;
                                        $isFromHistory     = $program['is_from_history'] ?? false;
                                        $hasFeedback       = $program['has_feedback_value'] ?? false;
                                        $isArchived        = $program['is_archived'] ?? false;
                                        $feedbackRating    = $program['feedback_rating'] ?? null;
                                        $archiveId         = $program['archive_id'] ?? null;
                                        $applicationDate   = $program['application_date'] ?? null;

                                        $cardClass     = '';
                                        $isClickable   = false;
                                        $clickUrl      = '#';
                                        $actionMessage = '';
                                        $statusBadge   = '';

                                        if ($isArchived) {
                                            $cardClass     = 'archived';
                                            $isClickable   = true;
                                            $clickUrl      = '#'; // We'll handle with modal
                                            $actionMessage = 'View Archived Record';
                                            $statusBadge   = 'ARCHIVED';
                                        } elseif ($isEnrolled) {
                                            if ($isCompletedStatus) {
                                                $cardClass     = 'completed';
                                                $isClickable   = true;
                                                $clickUrl      = ($showCertificate || $hasFeedback)
                                                    ? 'feedback_certificate.php?generate_certificate=1&program_id=' . $program['id']
                                                    : 'feedback_certificate.php?program_id=' . $program['id'];
                                                $actionMessage = ($showCertificate || $hasFeedback) ? 'View Certificate' : 'Submit Feedback';
                                                $statusBadge   = 'COMPLETED';
                                            } elseif ($isFromHistory) {
                                                $cardClass     = 'history';
                                                $isClickable   = true;
                                                $clickUrl      = ($showCertificate || $hasFeedback)
                                                    ? 'feedback_certificate.php?generate_certificate=1&program_id=' . $program['id']
                                                    : 'feedback_certificate.php?program_id=' . $program['id'];
                                                $actionMessage = ($showCertificate || $hasFeedback) ? 'View Certificate' : 'Submit Feedback';
                                                $statusBadge   = ($showCertificate || $hasFeedback) ? 'COMPLETED' : 'AWAITING FEEDBACK';
                                            } elseif ($showCertificate) {
                                                $cardClass     = 'completed';
                                                $isClickable   = true;
                                                $clickUrl      = 'feedback_certificate.php?generate_certificate=1&program_id=' . $program['id'];
                                                $actionMessage = 'View Certificate';
                                                $statusBadge   = 'COMPLETED';
                                            } elseif ($isCompleted) {
                                                $cardClass     = 'completed';
                                                $isClickable   = true;
                                                $clickUrl      = 'feedback_certificate.php?program_id=' . $program['id'];
                                                $actionMessage = 'Submit Feedback';
                                                $statusBadge   = 'AWAITING FEEDBACK';
                                            } else {
                                                /* ACTIVE / CURRENTLY ENROLLED */
                                                $cardClass     = 'enrolled-active';
                                                $isClickable   = true;
                                                $clickUrl      = 'training_progress.php?program_id=' . $program['id'];
                                                $actionMessage = 'View Progress';
                                                $statusBadge   = 'ENROLLED';
                                            }
                                        } elseif ($isPending) {
                                            $cardClass     = 'pending';
                                            $isClickable   = true;
                                            $clickUrl      = '#'; // Change to # to prevent direct navigation
                                            $actionMessage = 'View Application';
                                            $statusBadge   = 'PENDING';
                                        }
                                    ?>
                                        <?php if($isClickable): ?>
                                            <?php if($isArchived): ?>
                                                <div class="program-card <?php echo $cardClass; ?>" data-program-id="<?php echo $program['id']; ?>" data-archive-id="<?php echo $archiveId; ?>" data-section="my-programs" data-archived="true" onclick="showArchivedModal(this)">
                                            <?php elseif($isPending): ?>
                                                <div class="program-card <?php echo $cardClass; ?>" data-program-id="<?php echo $program['id']; ?>" data-section="my-programs" data-pending="true" onclick="showPendingModal(this)">
                                            <?php else: ?>
                                                <a href="<?php echo $clickUrl; ?>" class="program-card <?php echo $cardClass; ?>" data-program-id="<?php echo $program['id']; ?>" data-section="my-programs">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="program-card <?php echo $cardClass; ?>" data-program-id="<?php echo $program['id']; ?>" data-section="my-programs">
                                        <?php endif; ?>
                                            <div class="program-header">
                                                <h2 class="program-name">
                                                    <?php echo htmlspecialchars($program['name']); ?>
                                                    <?php if($statusBadge): ?>
                                                        <span class="status-badge 
                                                            <?php 
                                                            if ($statusBadge === 'COMPLETED')         echo 'status-completed';
                                                            elseif ($statusBadge === 'ENROLLED')      echo 'status-approved';
                                                            elseif ($statusBadge === 'PENDING')       echo 'status-pending';
                                                            elseif ($statusBadge === 'ARCHIVED')      echo 'status-archived';
                                                            elseif ($statusBadge === 'AWAITING FEEDBACK') echo 'status-history';
                                                            ?>">
                                                            <?php echo $statusBadge; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if($isArchived): ?>
                                                        <span class="archive-badge">COMPLETED</span>
                                                    <?php elseif($isFromHistory || $isCompletedStatus): ?>
                                                        <span class="history-badge">HISTORY</span>
                                                    <?php endif; ?>
                                                </h2>
                                                <?php if($feedbackRating): ?>
                                                    <span class="feedback-rating">
                                                        <i class="fas fa-star"></i> <?php echo $feedbackRating; ?>/5
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="program-details">
                                                <?php if($isArchived): ?>
                                                    <!-- Archived Program Details -->
                                                    <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                                    <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> <?php echo $program['duration_unit'] ?? 'days'; ?></p>
                                                    <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                                    <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'N/A'); ?></p>
                                                    <p><b>Attendance:</b> <?php echo $program['attendance'] ?? 0; ?>%</p>
                                                    <p><b>Assessment:</b> <?php echo !empty($program['assessment']) ? htmlspecialchars($program['assessment']) : 'N/A'; ?></p>
                                                    <p><b>Completed:</b> <?php echo formatDate($program['completed_at']); ?></p>
                                                    <p><b>Archived:</b> <?php echo formatDateTime($program['archived_at']); ?></p>
                                                    <?php if(!empty($program['archive_trigger'])): ?>
                                                        <p><b>Archive Reason:</b> <?php echo ucfirst(str_replace('_', ' ', $program['archive_trigger'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if($program['has_feedback'] ?? false): ?>
                                                        <p><b>Feedback:</b> Submitted <?php echo !empty($program['feedback_submitted_at']) ? 'on ' . formatDateTime($program['feedback_submitted_at']) : ''; ?></p>
                                                        <?php if(!empty($program['feedback_comments'])): ?>
                                                            <p><b>Comments:</b> <?php echo htmlspecialchars(substr($program['feedback_comments'], 0, 100)) . (strlen($program['feedback_comments'] ?? '') > 100 ? '...' : ''); ?></p>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php elseif($isPending): ?>
                                                    <!-- Pending Application Details -->
                                                    <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                                    <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> days</p>
                                                    <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                                    <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'To be assigned'); ?></p>
                                                    <p><b>Available Slots:</b> <?php echo $program['available_slots']; ?> / <?php echo $program['total_slots']; ?></p>
                                                <?php else: ?>
                                                    <!-- Regular Program Details -->
                                                    <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                                    <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> days</p>
                                                    <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                                    <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'To be assigned'); ?></p>
                                                    <p><b>Attendance:</b> <?php echo $program['attendance'] ?? 0; ?>%</p>
                                                    <p><b>Assessment:</b> <?php echo !empty($program['assessment']) ? htmlspecialchars($program['assessment']) : 'Not yet assessed'; ?></p>
                                                    <p><b>Status:</b> <?php echo htmlspecialchars($program['enrollment_status'] ?? 'N/A'); ?></p>
                                                    <?php if(!$isArchived): ?>
                                                        <p><b>Available Slots:</b> <?php echo $program['available_slots']; ?> / <?php echo $program['total_slots']; ?> (<?php echo $program['enrollment_percentage']; ?>% filled)</p>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if($isEnrolled || $isPending || $isArchived): ?>
                                                    <div class="enrollment-status
                                                        <?php
                                                        if ($isArchived)                          echo 'archived-status';
                                                        elseif ($isPending)                       echo 'pending-status';
                                                        elseif ($isFromHistory || $isCompletedStatus) echo 'history-status';
                                                        elseif ($isCompleted || $showCertificate) echo 'completed-status';
                                                        elseif ($cardClass === 'enrolled-active') echo 'active-enrolled-status';
                                                        ?>">
                                                        <p class="status-message">
                                                            <i class="fas fa-<?php 
                                                                echo ($isArchived) ? 'archive' : 
                                                                    (($isPending) ? 'clock' : 
                                                                    (($cardClass === 'enrolled-active') ? 'running' : 'info-circle')); 
                                                                ?>"></i>
                                                            <?php if($isArchived): ?>
                                                                This program has been archived. Click to view full archived record.
                                                            <?php elseif($isPending): ?>
                                                                Your application is pending admin approval. Click to view details.
                                                            <?php elseif($isEnrolled): ?>
                                                                <?php if($isCompletedStatus || $isFromHistory): ?>
                                                                    <?php if($showCertificate || $hasFeedback): ?>
                                                                        Program completed. Certificate available.
                                                                    <?php else: ?>
                                                                        Program completed. Submit feedback to get your certificate.
                                                                    <?php endif; ?>
                                                                <?php elseif($showCertificate): ?>
                                                                    You have successfully completed this program.
                                                                <?php elseif($isCompleted): ?>
                                                                    Program completed. Submit feedback to get your certificate.
                                                                <?php else: ?>
                                                                    You are currently enrolled and actively training in this program.
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php if($isClickable && !$isArchived && !$isPending): ?></a><?php elseif($isClickable && ($isArchived || $isPending)): ?></div><?php else: ?></div><?php endif; ?>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- end programs-sections-wrapper -->

                <?php endif; ?>

            </div><!-- end dashboardView -->
        </main>
    </div>
</div>

<!-- ==========================================
     PENDING APPLICATION DETAIL MODAL
     ========================================== -->
<div id="pendingApplicationModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header pending-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
            <h2>
                <i class="fas fa-clock"></i>
                <span id="pendingModalTitle">Pending Application</span>
            </h2>
            <button class="modal-close" onclick="closePendingModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="pendingModalBodyContent">
            <div class="loading-archive">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading application details...</p>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     ARCHIVED PROGRAM DETAIL MODAL WITH CERTIFICATE PREVIEW
     ========================================== -->
<div id="archivedProgramModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h2>
                <i class="fas fa-archive"></i>
                <span id="modalProgramName">Archived Program Details</span>
            </h2>
            <button class="modal-close" onclick="closeArchivedModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBodyContent">
            <!-- Content will be populated dynamically via JavaScript -->
            <div class="loading-archive">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading archived record...</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ==========================================
// NOTIFICATION FUNCTIONS - FIXED
// ==========================================

// Load notifications via AJAX
function loadNotifications() {
    fetch('notification.php?load=notifications')
        .then(response => response.json())
        .then(data => { 
            if (data.success) {
                updateNotificationUI(data.notifications, data.count);
            }
        })
        .catch(err => console.error('Notification error:', err));
}

// Update notification UI
function updateNotificationUI(notifications, unreadCount) {
    // Update badge
    updateNotificationBadge(unreadCount);
    
    // Update dropdown content
    updateNotificationDropdown(notifications, unreadCount);
}

// Update notification badge
function updateNotificationBadge(unreadCount) {
    const notificationBtn = document.querySelector('.notification-btn');
    if (!notificationBtn) return;
    
    // Remove existing badge
    const existingBadge = notificationBtn.querySelector('.notification-badge');
    if (existingBadge) existingBadge.remove();
    
    // Add new badge if there are unread notifications
    if (unreadCount > 0) {
        const badge = document.createElement('span');
        badge.className = 'notification-badge';
        badge.textContent = unreadCount;
        notificationBtn.appendChild(badge);
    }
}

// Update notification dropdown content
function updateNotificationDropdown(notifications, unreadCount) {
    const dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;
    
    // Get the notification list element
    let notificationList = document.getElementById('notificationList');
    
    // If notificationList doesn't exist, create it
    if (!notificationList) {
        // Remove old content and create new structure
        dropdown.innerHTML = `
            <div class="notification-header">
                Notifications
                ${unreadCount > 0 ? '<button class="mark-all-btn" onclick="markAllAsRead()"><i class="fas fa-check-double"></i> Mark all read</button>' : ''}
            </div>
            <ul class="notification-list" id="notificationList"></ul>
        `;
        notificationList = document.getElementById('notificationList');
    } else {
        // Update header
        const header = dropdown.querySelector('.notification-header');
        if (header) {
            header.innerHTML = `
                Notifications
                ${unreadCount > 0 ? '<button class="mark-all-btn" onclick="markAllAsRead()"><i class="fas fa-check-double"></i> Mark all read</button>' : ''}
            `;
        }
        
        // Clear existing list
        notificationList.innerHTML = '';
    }
    
    if (!notificationList) return;
    
    // Populate notifications
    if (notifications.length === 0) {
        notificationList.innerHTML = '<li class="no-notifications">No notifications</li>';
        return;
    }
    
    notifications.forEach(notif => {
        const isUnread = !notif.is_read;
        const li = document.createElement('li');
        li.className = `notification-item ${isUnread ? 'unread' : ''}`;
        li.setAttribute('data-id', notif.id);
        li.setAttribute('data-read', notif.is_read ? 'true' : 'false');
        
        li.innerHTML = `
            <div class="notification-title">
                ${escapeHtml(notif.title)}
                ${isUnread ? '<span class="new-badge" style="background: #3b82f6; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.7rem; margin-left: 5px;">NEW</span>' : ''}
            </div>
            <div class="notification-message">
                ${escapeHtml(notif.message)}
            </div>
            <div class="notification-time">${escapeHtml(notif.created_at)}</div>
        `;
        
        // Add click handler directly to the li element
        li.addEventListener('click', function(e) {
            e.stopPropagation();
            const notificationId = this.getAttribute('data-id');
            const isRead = this.getAttribute('data-read') === 'true';
            
            if (!isRead) {
                markAsRead(notificationId, this);
            }
        });
        
        notificationList.appendChild(li);
    });
}

// Mark a single notification as read
function markAsRead(notificationId, element) {
    // Create form data
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', notificationId);
    
    fetch('notification.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the element
            element.classList.remove('unread');
            element.setAttribute('data-read', 'true');
            
            // Remove NEW badge
            const titleDiv = element.querySelector('.notification-title');
            if (titleDiv) {
                const newBadge = titleDiv.querySelector('.new-badge');
                if (newBadge) newBadge.remove();
            }
            
            // Update notification message style
            const messageDiv = element.querySelector('.notification-message');
            if (messageDiv) {
                messageDiv.style.fontWeight = '';
            }
            
            // Update badge count
            updateBadgeCountAfterRead();
        }
    })
    .catch(error => console.error('Error marking as read:', error));
}

// Mark all notifications as read
function markAllAsRead() {
    const formData = new FormData();
    formData.append('action', 'mark_all_read');
    
    fetch('notification.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update all notification items
            document.querySelectorAll('.notification-item').forEach(item => {
                item.classList.remove('unread');
                item.setAttribute('data-read', 'true');
                
                // Remove NEW badges
                const titleDiv = item.querySelector('.notification-title');
                if (titleDiv) {
                    const newBadge = titleDiv.querySelector('.new-badge');
                    if (newBadge) newBadge.remove();
                }
                
                // Reset message style
                const messageDiv = item.querySelector('.notification-message');
                if (messageDiv) {
                    messageDiv.style.fontWeight = '';
                }
            });
            
            // Remove badge
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
            
            // Hide mark all button
            const markAllBtn = document.querySelector('.mark-all-btn');
            if (markAllBtn) markAllBtn.style.display = 'none';
        }
    })
    .catch(error => console.error('Error marking all as read:', error));
}

// Update badge count after marking one as read
function updateBadgeCountAfterRead() {
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        const count = parseInt(badge.textContent) - 1;
        if (count > 0) {
            badge.textContent = count;
        } else {
            badge.remove();
            // Hide mark all button if no unread
            const markAllBtn = document.querySelector('.mark-all-btn');
            if (markAllBtn) markAllBtn.style.display = 'none';
        }
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize notification system
function initNotifications() {
    // Load notifications immediately
    loadNotifications();
    
    // Set up periodic refresh (every 30 seconds)
    setInterval(loadNotifications, 30000);
    
    // Add click handlers to initial notification items
    document.querySelectorAll('.notification-item').forEach(item => {
        // Remove any existing listeners to prevent duplicates
        item.removeEventListener('click', handleNotificationItemClick);
        item.addEventListener('click', handleNotificationItemClick);
    });
}

// Handler for notification item clicks
function handleNotificationItemClick(e) {
    e.stopPropagation();
    const notificationId = this.getAttribute('data-id');
    const isRead = this.getAttribute('data-read') === 'true';
    
    if (!isRead) {
        markAsRead(notificationId, this);
    }
}

// ==========================================
// PROGRAM APPLICATION FUNCTION WITH OVERLAY
// ==========================================
window.applyForProgram = function(programId, programName) {
    // Check if user can apply (this should match your PHP logic)
    const hasActiveProgram = <?php echo $hasActiveProgram ? 'true' : 'false'; ?>;
    const hasPendingApplications = <?php echo $hasPendingApplications ? 'true' : 'false'; ?>;
    
    if (hasActiveProgram || hasPendingApplications) {
        Swal.fire({
            title: 'Cannot Apply',
            html: hasActiveProgram 
                ? 'You are currently enrolled in an active program. You must complete it before applying to a new program.'
                : 'You have a pending application. Please wait for admin approval before applying to new programs.',
            icon: 'warning',
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    // Show confirmation dialog
    Swal.fire({
        title: 'Apply for Program',
        html: `Are you sure you want to apply for <strong>${escapeHtml(programName)}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Apply',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0ea5e9',
        cancelButtonColor: '#6b7280',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Submitting Application...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    // Create form data
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('program_id', programId);
                    
                    // Submit the application via AJAX
                    fetch('apply_program.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: data.message,
                                icon: 'success',
                                confirmButtonColor: '#0ea5e9'
                            }).then(() => {
                                // Reload the page to show updated status
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.message,
                                icon: 'error',
                                confirmButtonColor: '#ef4444'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Application error:', error);
                        Swal.fire({
                            title: 'Error',
                            text: 'An error occurred while submitting your application. Please try again.',
                            icon: 'error',
                            confirmButtonColor: '#ef4444'
                        });
                    });
                }
            });
        }
    });
};

document.addEventListener('DOMContentLoaded', function() {
    // Initialize notifications
    initNotifications();
    
    const searchInput      = document.getElementById('searchQuery');
    const clearSearchBtn   = document.getElementById('clearSearch');
    const profileBtn       = document.getElementById('profileBtn');
    const profileDropdown  = document.getElementById('profileDropdown');
    const notificationBtn  = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const mobileMenuBtn    = document.getElementById('mobileMenuBtn');
    const sidebar          = document.getElementById('sidebar');
    const sidebarOverlay   = document.createElement('div');
    sidebarOverlay.className = 'sidebar-overlay';
    document.body.appendChild(sidebarOverlay);
    const filterButtons = document.querySelectorAll('.filter-btn');

    let allProgramsData      = <?php echo json_encode($programs); ?>;
    let myProgramsData       = allProgramsData.filter(p => p.enrolled_user_id == <?php echo $user_id; ?>);
    let availableProgramsData = allProgramsData.filter(p => !p.enrolled_user_id || p.enrolled_user_id != <?php echo $user_id; ?>);
    const hasActiveProgram   = <?php echo $hasActiveProgram ? 'true' : 'false'; ?>;
    const hasPendingApplications = <?php echo $hasPendingApplications ? 'true' : 'false'; ?>;
    const disableAvailablePrograms = <?php echo $disableAvailablePrograms ? 'true' : 'false'; ?>;

    let currentFilter = '<?php 
        if ($filter_counts["active"] > 0) echo "active";
        elseif ($filter_counts["pending"] > 0) echo "pending";
        elseif ($filter_counts["completed"] > 0) echo "completed";
        elseif ($filter_counts["archived"] > 0) echo "archived";
        else echo "active";
    ?>';

    // ==========================================
    // SCREENSHOT RESTRICTION FUNCTIONALITY
    // ==========================================
    function enableScreenshotRestriction() {
        // Disable right-click on certificate
        document.addEventListener('contextmenu', function(e) {
            if (e.target.closest('.certificate-container') || e.target.closest('.modal-overlay.active')) {
                e.preventDefault();
                showRestrictionMessage('Screenshots are not allowed for security reasons.');
                return false;
            }
        });

        // Detect print screen attempts (limited detection)
        document.addEventListener('keyup', function(e) {
            if (e.key === 'PrintScreen') {
                if (document.querySelector('.modal-overlay.active')) {
                    showRestrictionMessage('Screenshots are not allowed for security reasons.');
                }
            }
        });

        // Detect common screenshot shortcuts
        document.addEventListener('keydown', function(e) {
            // Windows: Win + Shift + S, Ctrl + Shift + S
            // Mac: Cmd + Shift + 4, Cmd + Shift + 3, Cmd + Shift + 5
            if ((e.metaKey || e.ctrlKey) && e.shiftKey && (e.key === 'S' || e.key === 's' || e.key === '4' || e.key === '3' || e.key === '5')) {
                if (document.querySelector('.modal-overlay.active')) {
                    e.preventDefault();
                    showRestrictionMessage('Screenshots are not allowed for security reasons.');
                }
            }
            
            // Alt + PrintScreen
            if (e.altKey && e.key === 'PrintScreen') {
                if (document.querySelector('.modal-overlay.active')) {
                    showRestrictionMessage('Screenshots are not allowed for security reasons.');
                }
            }

            // Ctrl + P (Print)
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                if (document.querySelector('.modal-overlay.active')) {
                    // Allow printing but add watermark
                    // We'll handle this in the print function
                }
            }
        });

        // Disable copy on certificate
        document.addEventListener('copy', function(e) {
            if (e.target.closest('.certificate-container') || e.target.closest('.modal-overlay.active')) {
                e.preventDefault();
                showRestrictionMessage('Copying certificate content is not allowed.');
            }
        });

        // Disable cut on certificate
        document.addEventListener('cut', function(e) {
            if (e.target.closest('.certificate-container') || e.target.closest('.modal-overlay.active')) {
                e.preventDefault();
                showRestrictionMessage('Cutting certificate content is not allowed.');
            }
        });

        // Disable drag on certificate
        document.addEventListener('dragstart', function(e) {
            if (e.target.closest('.certificate-container') || e.target.closest('.modal-overlay.active')) {
                e.preventDefault();
            }
        });

        // Disable select on certificate
        document.addEventListener('selectstart', function(e) {
            if (e.target.closest('.certificate-container') || e.target.closest('.modal-overlay.active')) {
                e.preventDefault();
            }
        });

        // Blur certificate when window loses focus (possible screenshot attempt)
        window.addEventListener('blur', function() {
            if (document.querySelector('.modal-overlay.active')) {
                const certificate = document.querySelector('.certificate-container');
                if (certificate) {
                    certificate.style.filter = 'blur(5px)';
                    certificate.style.transition = 'filter 0.3s';
                }
            }
        });

        window.addEventListener('focus', function() {
            const certificate = document.querySelector('.certificate-container');
            if (certificate) {
                certificate.style.filter = '';
                certificate.style.transition = 'filter 0.3s';
            }
        });

        // Detect visibility change (possible screenshot app)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (document.querySelector('.modal-overlay.active')) {
                    const certificate = document.querySelector('.certificate-container');
                    if (certificate) {
                        certificate.style.filter = 'blur(5px)';
                        certificate.style.opacity = '0.5';
                    }
                }
            } else {
                const certificate = document.querySelector('.certificate-container');
                if (certificate) {
                    certificate.style.filter = '';
                    certificate.style.opacity = '';
                }
            }
        });
    }

    function showRestrictionMessage(message) {
        const modal = document.getElementById('archivedProgramModal');
        if (!modal.classList.contains('active')) return;

        // Check if restriction overlay already exists
        let restrictionOverlay = modal.querySelector('.screenshot-restriction');
        if (!restrictionOverlay) {
            restrictionOverlay = document.createElement('div');
            restrictionOverlay.className = 'screenshot-restriction';
            modal.querySelector('.modal-container').appendChild(restrictionOverlay);
        }

        // Show message
        restrictionOverlay.classList.add('active');
        const messageEl = document.createElement('div');
        messageEl.className = 'restriction-message';
        messageEl.textContent = message;
        restrictionOverlay.appendChild(messageEl);

        // Remove after animation
        setTimeout(() => {
            messageEl.remove();
            restrictionOverlay.classList.remove('active');
        }, 2000);
    }

    // ==========================================
    // PENDING APPLICATION MODAL FUNCTIONS
    // ==========================================
    window.showPendingModal = function(element) {
        const programId = element.getAttribute('data-program-id');
        
        // Find program data
        const program = allProgramsData.find(p => p.id == programId && p.is_pending);
        
        if (!program) {
            Swal.fire({
                title: 'Error',
                text: 'Pending application data not found.',
                icon: 'error',
                confirmButtonColor: '#f59e0b'
            });
            return;
        }

        // Populate modal with pending application details
        populatePendingModal(program);
        
        // Show modal
        document.getElementById('pendingApplicationModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    window.closePendingModal = function() {
        document.getElementById('pendingApplicationModal').classList.remove('active');
        document.body.style.overflow = '';
    };

    function populatePendingModal(program) {
        const modalBody = document.getElementById('pendingModalBodyContent');
        const modalTitle = document.getElementById('pendingModalTitle');
        
        modalTitle.textContent = program.name || 'Pending Application';
        
        // Get application date
        const applicationDate = program.application_date ? formatDateTime(program.application_date) : 'N/A';
        
        modalBody.innerHTML = `
            <div class="modal-section pending-section" style="border-left-color: #f59e0b;">
                <h3><i class="fas fa-info-circle" style="color: #f59e0b;"></i> Application Details</h3>
                <div class="modal-grid">
                    <div class="modal-info-item">
                        <strong>Program Name</strong>
                        <span>${escapeHtml(program.name)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Category</strong>
                        <span>${escapeHtml(program.category_name || 'General')}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Duration</strong>
                        <span>${escapeHtml(program.duration)} days</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Schedule</strong>
                        <span>${formatDate(program.scheduleStart)} – ${formatDate(program.scheduleEnd)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Trainer</strong>
                        <span>${escapeHtml(program.trainer || 'To be assigned')}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Available Slots</strong>
                        <span>${program.available_slots || 0} / ${program.total_slots || 0}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Application Status</strong>
                        <span style="color: #f59e0b; font-weight: 600;">
                            <i class="fas fa-clock"></i> PENDING
                        </span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Applied On</strong>
                        <span>${applicationDate}</span>
                    </div>
                </div>
            </div>
            
            <div class="modal-section pending-section" style="border-left-color: #f59e0b; background: #fef3c7;">
                <h3><i class="fas fa-hourglass-half" style="color: #f59e0b;"></i> What's Next?</h3>
                <div style="padding: 1rem;">
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center;">
                        <div style="width: 40px; height: 40px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">1</div>
                        <div style="flex: 1;">
                            <strong style="color: #92400e;">Application Submitted</strong>
                            <p style="color: #6b7280; font-size: 0.875rem;">Your application has been received and is pending review.</p>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center;">
                        <div style="width: 40px; height: 40px; background: #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #6b7280; font-weight: bold;">2</div>
                        <div style="flex: 1;">
                            <strong style="color: #374151;">Admin Review</strong>
                            <p style="color: #6b7280; font-size: 0.875rem;">An administrator will review your application.</p>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <div style="width: 40px; height: 40px; background: #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #6b7280; font-weight: bold;">3</div>
                        <div style="flex: 1;">
                            <strong style="color: #374151;">Approval Decision</strong>
                            <p style="color: #6b7280; font-size: 0.875rem;">You'll receive a notification once your application is approved or rejected.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-section pending-section" style="border-left-color: #f59e0b;">
                <h3><i class="fas fa-bell" style="color: #f59e0b;"></i> Important Notes</h3>
                <ul style="padding-left: 1.5rem; color: #4b5563; margin: 0;">
                    <li style="margin-bottom: 0.5rem;">While your application is pending, you cannot apply for other programs.</li>
                    <li style="margin-bottom: 0.5rem;">You will receive a notification when the admin responds to your application.</li>
                    <li style="margin-bottom: 0.5rem;">If approved, you'll be automatically enrolled in the program.</li>
                    <li>If rejected, you'll be able to apply for other programs.</li>
                </ul>
            </div>
            
            <div class="certificate-actions" style="justify-content: center;">
                <button class="btn btn-secondary" onclick="closePendingModal()" style="background: #f59e0b;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        `;
    }

    // ==========================================
    // ARCHIVED MODAL FUNCTIONS
    // ==========================================
    window.showArchivedModal = function(element) {
        const archiveId = element.getAttribute('data-archive-id');
        const programId = element.getAttribute('data-program-id');
        
        // Find archived program data
        const archivedProgram = allProgramsData.find(p => p.archive_id == archiveId && p.is_archived);
        
        if (!archivedProgram) {
            Swal.fire({
                title: 'Error',
                text: 'Archived program data not found.',
                icon: 'error',
                confirmButtonColor: '#8b5cf6'
            });
            return;
        }

        // Populate modal with archived program details
        populateArchivedModal(archivedProgram);
        
        // Show modal
        document.getElementById('archivedProgramModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Enable screenshot restrictions
        enableScreenshotRestriction();
    };

    window.closeArchivedModal = function() {
        document.getElementById('archivedProgramModal').classList.remove('active');
        document.body.style.overflow = '';
    };

    function populateArchivedModal(program) {
        const modalBody = document.getElementById('modalBodyContent');
        const modalTitle = document.getElementById('modalProgramName');
        
        modalTitle.textContent = program.name || 'Archived Program';
        
        // Generate unique ID for this certificate instance
        const certificateId = 'cert_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        // Format feedback ratings if available
        let feedbackHtml = '';
        if (program.has_feedback && program.feedback_details) {
            const ratings = program.feedback_details;
            
            // Group ratings by category
            const trainerRatings = [
                { name: 'Expertise', value: ratings.trainer_expertise_rating },
                { name: 'Communication', value: ratings.trainer_communication_rating },
                { name: 'Methods', value: ratings.trainer_methods_rating },
                { name: 'Requests', value: ratings.trainer_requests_rating },
                { name: 'Questions', value: ratings.trainer_questions_rating },
                { name: 'Instructions', value: ratings.trainer_instructions_rating },
                { name: 'Prioritization', value: ratings.trainer_prioritization_rating },
                { name: 'Fairness', value: ratings.trainer_fairness_rating }
            ].filter(r => r.value);
            
            const programRatings = [
                { name: 'Knowledge', value: ratings.program_knowledge_rating },
                { name: 'Process', value: ratings.program_process_rating },
                { name: 'Environment', value: ratings.program_environment_rating },
                { name: 'Algorithms', value: ratings.program_algorithms_rating },
                { name: 'Preparation', value: ratings.program_preparation_rating }
            ].filter(r => r.value);
            
            const systemRatings = [
                { name: 'Technology', value: ratings.system_technology_rating },
                { name: 'Workflow', value: ratings.system_workflow_rating },
                { name: 'Instructions', value: ratings.system_instructions_rating },
                { name: 'Answers', value: ratings.system_answers_rating },
                { name: 'Performance', value: ratings.system_performance_rating }
            ].filter(r => r.value);
            
            feedbackHtml = `
                <div class="modal-section">
                    <h3><i class="fas fa-star"></i> Feedback Summary</h3>
                    <div class="rating-badge-large" style="margin-bottom: 1rem;">
                        <i class="fas fa-star"></i> Overall Rating: ${program.feedback_rating}/5
                    </div>
                    
                    ${trainerRatings.length > 0 ? `
                    <div class="feedback-detail">
                        <h4 style="margin-bottom: 1rem; color: #1c2a3a;">Trainer Evaluation</h4>
                        <div class="feedback-rating-grid">
                            ${trainerRatings.map(r => `
                                <div class="rating-category">
                                    <div class="category-name">${r.name}</div>
                                    <div class="category-value">
                                        ${r.value}/5
                                        <span class="rating-stars">${'★'.repeat(r.value)}${'☆'.repeat(5-r.value)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${programRatings.length > 0 ? `
                    <div class="feedback-detail">
                        <h4 style="margin-bottom: 1rem; color: #1c2a3a;">Program Evaluation</h4>
                        <div class="feedback-rating-grid">
                            ${programRatings.map(r => `
                                <div class="rating-category">
                                    <div class="category-name">${r.name}</div>
                                    <div class="category-value">
                                        ${r.value}/5
                                        <span class="rating-stars">${'★'.repeat(r.value)}${'☆'.repeat(5-r.value)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${systemRatings.length > 0 ? `
                    <div class="feedback-detail">
                        <h4 style="margin-bottom: 1rem; color: #1c2a3a;">System Evaluation</h4>
                        <div class="feedback-rating-grid">
                            ${systemRatings.map(r => `
                                <div class="rating-category">
                                    <div class="category-name">${r.name}</div>
                                    <div class="category-value">
                                        ${r.value}/5
                                        <span class="rating-stars">${'★'.repeat(r.value)}${'☆'.repeat(5-r.value)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${program.feedback_comments ? `
                    <div class="feedback-detail">
                        <h4 style="margin-bottom: 0.5rem; color: #1c2a3a;">Additional Comments</h4>
                        <p style="font-style: italic; color: #4b5563; background: #f9fafb; padding: 1rem; border-radius: 0.5rem;">
                            "${escapeHtml(program.feedback_comments)}"
                        </p>
                    </div>
                    ` : ''}
                    
                    ${program.feedback_submitted_at ? `
                    <p style="font-size: 0.85rem; color: #6b7280; margin-top: 0.5rem;">
                        <i class="far fa-calendar-check"></i> Feedback submitted: ${formatDateTime(program.feedback_submitted_at)}
                    </p>
                    ` : ''}
                </div>
            `;
        }

        // Get trainee name from PHP
        const traineeName = "<?php echo htmlspecialchars(strtoupper($trainee_fullname ?? 'TRAINEE NAME')); ?>";
        
        // Build certificate preview HTML if certificate is available
        let certificateHtml = '';
        if (program.show_certificate && program.has_feedback && program.assessment === 'Passed') {
            const completionDate = program.completed_at ? new Date(program.completed_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            
            certificateHtml = `
                <div class="certificate-container" id="certificatePreview_${certificateId}">
                    <!-- Screenshot Protection Overlay -->
                    <div class="screenshot-protection-overlay"></div>
                    
                    <!-- Dynamic Moving Watermark -->
                    <div class="dynamic-watermark">
                        <div class="watermark-text">UNOFFICIAL COPY</div>
                        <div class="watermark-text">NOT FOR OFFICIAL USE</div>
                        <div class="watermark-text">SAMPLE ONLY</div>
                        <div class="watermark-text">UNOFFICIAL</div>
                        <div class="watermark-text">NOT VALID</div>
                    </div>
                    
                    <!-- Non-Official Watermark -->
                    <div class="non-official-watermark">UNOFFICIAL</div>
                    
                    <!-- QR Code Watermark -->
                    <div class="qr-watermark"></div>
                    
                    <!-- Decorative borders -->
                    <div class="decorative-border"></div>
                    <div class="inner-border"></div>
                    
                    <div class="certificate-content">
                        <!-- Logos at top in horizontal row -->
                        <div class="logos-row">
                            <div class="logo-item">
                                <img src="/trainee/SMBLOGO.jpg" alt="Santa Maria Logo" onerror="this.style.display='none';" draggable="false">
                            </div>
                            <div class="logo-item">
                                <img src="/trainee/SLOGO.jpg" alt="Training Center Logo" onerror="this.style.display='none';" draggable="false">
                            </div>
                            <div class="logo-item">
                                <img src="/trainee/TESDALOGO.png" alt="TESDA Logo" onerror="this.style.display='none';" draggable="false">
                            </div>
                        </div>
                        
                        <!-- Header Text -->
                        <div class="header-top">
                            MUNICIPALITY OF SANTA MARIA, BULACAN
                        </div>
                        
                        <div class="cooperation">
                            IN COOPERATION WITH
                        </div>
                        
                        <div class="tesda">
                            TECHNICAL EDUCATION & SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN
                        </div>
                        
                        <div class="training-center">
                            SANTA MARIA LIVELIHOOD TRAINING CENTER
                        </div>
                        
                        <!-- Certificate Title -->
                        <div class="certificate-title">
                            <h1>CERTIFICATE OF TRAINING</h1>
                        </div>
                        
                        <!-- Awarded To -->
                        <div class="awarded-to">
                            <p>is awarded to</p>
                        </div>
                        
                        <!-- Trainee Name -->
                        <div class="trainee-name-container">
                            <div class="trainee-name">
                                ${traineeName}
                            </div>
                        </div>
                        
                        <!-- Completion Text -->
                        <div class="completion-text">
                            <p>For having satisfactorily completed the</p>
                        </div>
                        
                        <!-- Training Name -->
                        <div class="training-name-container">
                            <div class="training-name">
                                ${program.name ? program.name.toUpperCase() : 'TRAINING PROGRAM'}
                            </div>
                        </div>
                        
                        <!-- Date and Location -->
                        <div class="given-date">
                            <p>Given this ${completionDate} at Santa Maria Livelihood Training and</p>
                            <p>Employment Center, Santa Maria, Bulacan.</p>
                        </div>
                        
                        <!-- Signatures with photo -->
                        <div class="signatures">
                            <div class="signatures-row">
                                <div class="left-signatures">
                                    <div class="signature-block">
                                        <div class="signature-line"></div>
                                        <div class="signature-name">ZENAIDA S. MANINGAS</div>
                                        <div class="signature-title">PESO Manager</div>
                                    </div>
                                    
                                    <div class="signature-block">
                                        <div class="signature-line"></div>
                                        <div class="signature-name">ROBERTO B. PEREZ</div>
                                        <div class="signature-title">Municipal Vice Mayor</div>
                                    </div>
                                    
                                    <div class="signature-block">
                                        <div class="signature-line"></div>
                                        <div class="signature-name">BARTOLOME R. RAMOS</div>
                                        <div class="signature-title">Municipal Mayor</div>
                                    </div>
                                </div>
                                
                                <!-- Photo and signature section -->
                                <div class="photo-signature-section">
                                    <div class="photo-box">
                                        <div class="photo-placeholder">
                                            <!-- Placeholder for photo -->
                                        </div>
                                    </div>
                                    <div class="photo-signature-line"></div>
                                    <div class="photo-signature-label">Signature</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        modalBody.innerHTML = `
            <div class="modal-section">
                <h3><i class="fas fa-info-circle"></i> Program Information</h3>
                <div class="modal-grid">
                    <div class="modal-info-item">
                        <strong>Program Name</strong>
                        <span>${escapeHtml(program.name)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Category</strong>
                        <span>${escapeHtml(program.category_name || 'General')}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Duration</strong>
                        <span>${escapeHtml(program.duration)} ${escapeHtml(program.duration_unit || 'Days')}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Trainer</strong>
                        <span>${escapeHtml(program.trainer || 'N/A')}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Schedule Start</strong>
                        <span>${formatDate(program.scheduleStart)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Schedule End</strong>
                        <span>${formatDate(program.scheduleEnd)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Attendance</strong>
                        <span>${program.attendance || 0}%</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Assessment</strong>
                        <span>${program.assessment || 'N/A'}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Completion Date</strong>
                        <span>${formatDate(program.completed_at)}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Archived Date</strong>
                        <span>${formatDateTime(program.archived_at)}</span>
                    </div>
                </div>
            </div>
            
            ${feedbackHtml}
            
            ${certificateHtml}
            
            ${!certificateHtml ? `
            <div style="text-align: center; padding: 2rem; background: #f9fafb; border-radius: 0.5rem; color: #6b7280;">
                <i class="fas fa-info-circle" style="font-size: 3rem; margin-bottom: 1rem; color: #8b5cf6;"></i>
                <p>No certificate available for this archived program.</p>
                ${program.has_feedback ? '' : '<p style="font-size: 0.9rem; margin-top: 0.5rem;">Feedback must be submitted to generate a certificate.</p>'}
            </div>
            ` : ''}
            
            <div class="certificate-actions">
                <button class="btn btn-secondary" onclick="closeArchivedModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        `;
    }

    // Certificate functions
    window.printCertificate = function(certificateId) {
        const certificate = document.getElementById(certificateId);
        if (certificate) {
            // Create a print-friendly version with watermarks
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Certificate of Training</title>
                    <style>
                        body { margin: 0; padding: 0; background: white; }
                        .certificate-container { width: 100%; max-width: 210mm; margin: 0 auto; position: relative; }
                        .non-official-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 60px; font-weight: 900; color: rgba(255, 0, 0, 0.15); text-transform: uppercase; white-space: nowrap; pointer-events: none; z-index: 9998; border: 5px solid rgba(255, 0, 0, 0.2); padding: 20px 50px; border-radius: 20px; letter-spacing: 10px; }
                        .dynamic-watermark { position: absolute; top: 0; left: 0; right: 0; bottom: 0; pointer-events: none; z-index: 10000; overflow: hidden; }
                        .watermark-text { position: absolute; color: rgba(139, 92, 246, 0.15); font-size: 24px; font-weight: bold; white-space: nowrap; transform: rotate(-45deg); text-transform: uppercase; letter-spacing: 5px; animation: moveWatermark 20s linear infinite; text-shadow: 2px 2px 4px rgba(0,0,0,0.1); border: 2px solid rgba(139, 92, 246, 0.2); padding: 10px 30px; border-radius: 50px; }
                        @keyframes moveWatermark { 0% { transform: rotate(-45deg) translateX(-100%) translateY(-100%); } 100% { transform: rotate(-45deg) translateX(100%) translateY(100%); } }
                        @media print { body { margin: 0; padding: 0; } .certificate-container { margin: 0; } }
                    </style>
                </head>
                <body>
                    ${certificate.outerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            
            // Add slight delay for styles to load
            setTimeout(() => {
                printWindow.print();
            }, 500);
        }
    };

    window.downloadCertificate = function() {
        Swal.fire({
            title: 'Download Feature',
            text: 'PDF download will be available soon. You can print the certificate for now.',
            icon: 'info',
            confirmButtonColor: '#8b5cf6'
        });
    };

    // Format date function
    function formatDate(dateStr) {
        if (!dateStr || dateStr === '0000-00-00' || dateStr === 'N/A') return 'N/A';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        } catch {
            return dateStr;
        }
    }

    function formatDateTime(dateTimeStr) {
        if (!dateTimeStr || dateTimeStr === '0000-00-00 00:00:00' || dateTimeStr === 'N/A') return 'N/A';
        try {
            const date = new Date(dateTimeStr);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch {
            return dateTimeStr;
        }
    }

    // ==========================================
    // HANDLE PROGRAM APPLICATION CLICK
    // ==========================================
    function setupApplicationHandlers() {
        document.querySelectorAll('#availableProgramsList .program-card.available:not(.locked-card)').forEach(link => {
            // Remove existing listeners to prevent duplicates
            link.removeEventListener('click', handleApplicationClick);
            link.addEventListener('click', handleApplicationClick);
        });
    }

    function handleApplicationClick(e) {
        if (disableAvailablePrograms) {
            e.preventDefault();
            return false;
        }
        
        // For locked cards, do nothing
        if (this.classList.contains('locked-card')) {
            e.preventDefault();
            return false;
        }
        
        // Let the onclick handler work
        return true;
    }

    // Mobile menu
    function toggleMobileMenu() {
        const isOpen = sidebar.classList.contains('mobile-open');
        if (isOpen) {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            document.body.style.overflow = '';
        } else {
            sidebar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.classList.add('sidebar-open');
            document.body.style.overflow = 'hidden';
            if (profileDropdown) profileDropdown.style.display = 'none';
            if (notificationDropdown) notificationDropdown.style.display = 'none';
        }
    }

    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleMobileMenu);
    sidebarOverlay.addEventListener('click', toggleMobileMenu);
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            document.body.style.overflow = '';
        }
    });

    // Profile dropdown
    if (profileBtn) {
        profileBtn.addEventListener('click', e => {
            e.stopPropagation();
            if (profileDropdown) {
                profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
            }
            if (notificationDropdown) notificationDropdown.style.display = 'none';
        });
    }

    // Notification dropdown
    if (notificationBtn) {
        notificationBtn.addEventListener('click', e => {
            e.stopPropagation();
            if (notificationDropdown) {
                notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            }
            if (profileDropdown) profileDropdown.style.display = 'none';
            
            // Re-attach click handlers when dropdown opens
            setTimeout(() => {
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.removeEventListener('click', handleNotificationItemClick);
                    item.addEventListener('click', handleNotificationItemClick);
                });
            }, 100);
        });
    }

    document.addEventListener('click', e => {
        if (profileBtn && profileDropdown && !profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
            profileDropdown.style.display = 'none';
        }
        if (notificationBtn && notificationDropdown && !notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
            notificationDropdown.style.display = 'none';
        }
    });

    // Filter buttons
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!this.disabled) {
                applyMyProgramsFilter(this.getAttribute('data-filter'));
            }
        });
    });

    function applyMyProgramsFilter(filterType) {
        currentFilter = filterType;

        filterButtons.forEach(btn => {
            btn.classList.remove('active');
            if (btn.getAttribute('data-filter') === filterType) btn.classList.add('active');
        });

        const myProgramsList = document.getElementById('myProgramsList');
        if (!myProgramsList) return;
        
        const regularList = myProgramsList.querySelector('.programs-list');
        if (!regularList) return;

        const allMyCards = document.querySelectorAll('#myProgramsList .programs-list .program-card');
        let visibleCount = 0;

        allMyCards.forEach(card => {
            const programId = parseInt(card.getAttribute('data-program-id'));
            const program   = myProgramsData.find(p => p.id === programId);
            if (!program) { 
                card.style.display = 'none'; 
                return; 
            }

            let show = false;
            switch(filterType) {
                case 'active':
                    show = program.is_enrolled && 
                           !program.is_completed_status && 
                           !program.is_completed && 
                           !program.is_from_history &&
                           !program.is_archived;
                    break;
                case 'pending':
                    show = program.is_pending && !program.is_archived;
                    break;
                case 'archived':
                    // Show only archived programs
                    show = program.is_archived === true;
                    break;
                default:
                    show = true;
            }

            if (show && searchInput && searchInput.value.trim()) {
                const q = searchInput.value.toLowerCase();
                show = program.name.toLowerCase().includes(q) ||
                       (program.category_name && program.category_name.toLowerCase().includes(q)) ||
                       (program.trainer && program.trainer.toLowerCase().includes(q));
            }

            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        const myCountBadge = document.getElementById('myProgramsCount');
        if (myCountBadge) {
            myCountBadge.textContent = visibleCount + ' program' + (visibleCount !== 1 ? 's' : '');
        }
    }

    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            if (clearSearchBtn) clearSearchBtn.style.display = q ? 'block' : 'none';

            const availableCards = document.querySelectorAll('#availableProgramsList .program-card');
            let availableVisible = 0;
            availableCards.forEach(card => {
                const programId = parseInt(card.getAttribute('data-program-id'));
                const program   = availableProgramsData.find(p => p.id === programId);
                if (!program) { card.style.display = 'none'; return; }
                const show = !q || program.name.toLowerCase().includes(q) ||
                             (program.category_name && program.category_name.toLowerCase().includes(q)) ||
                             (program.trainer && program.trainer.toLowerCase().includes(q));
                card.style.display = show ? '' : 'none';
                if (show) availableVisible++;
            });

            const availableCountBadge = document.getElementById('availableCount');
            if (availableCountBadge) availableCountBadge.textContent = availableVisible + ' program' + (availableVisible !== 1 ? 's' : '');

            applyMyProgramsFilter(currentFilter);

            const searchResults = document.getElementById('searchResults');
            if (q && searchResults) {
                searchResults.style.display = 'block';
                searchResults.textContent = `Search results for "${q}"`;
            } else if (searchResults) {
                searchResults.style.display = 'none';
            }
        });
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', () => {
            if (searchInput) {
                searchInput.value = '';
                clearSearchBtn.style.display = 'none';

                const availableCards = document.querySelectorAll('#availableProgramsList .program-card');
                availableCards.forEach(card => card.style.display = '');
                const availableCountBadge = document.getElementById('availableCount');
                if (availableCountBadge) availableCountBadge.textContent = availableProgramsData.length + ' program' + (availableProgramsData.length !== 1 ? 's' : '');

                applyMyProgramsFilter(currentFilter);

                const searchResults = document.getElementById('searchResults');
                if (searchResults) searchResults.style.display = 'none';
            }
        });
    }

    // Setup application handlers initially
    setupApplicationHandlers();

    // Re-setup handlers when content might change (e.g., after search)
    const observer = new MutationObserver(function(mutations) {
        setupApplicationHandlers();
    });

    const availableList = document.getElementById('availableProgramsList');
    if (availableList) {
        observer.observe(availableList, { childList: true, subtree: true });
    }

    setTimeout(() => applyMyProgramsFilter(currentFilter), 100);

    // Close modals with ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeArchivedModal();
            closePendingModal();
        }
    });

    // Close modals when clicking outside
    document.getElementById('archivedProgramModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeArchivedModal();
        }
    });

    document.getElementById('pendingApplicationModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePendingModal();
        }
    });
});

// Global helpers
function confirmLogout() {
    Swal.fire({
        title: 'Confirm Logout',
        text: 'Are you sure you want to logout?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Logout',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) window.location.href = '?action=logout';
    });
}

function goToProfile() { 
    window.location.href = 'profile.php'; 
}
</script>
</body>
</html>