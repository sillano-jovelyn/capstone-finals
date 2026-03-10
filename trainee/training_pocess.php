<?php
// ==========================================
// ERROR REPORTING & CACHE CONTROL
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// PREVENT CACHING FOR REAL-TIME UPDATES
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

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
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ==========================================
// GET USER INFO
// ==========================================
$stmt = $conn->prepare("SELECT firstname, email FROM trainees WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['firstname'] ?? 'User';
$stmt->close();

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
// SET PHILIPPINES TIMEZONE
// ==========================================
date_default_timezone_set('Asia/Manila');

// ==========================================
// GET PROGRAMS WITH ASSESSMENT DATA
// ==========================================
$currentPrograms = [];
$history = [];

$query = "
    SELECT 
        e.id AS enrollment_id,
        p.id AS program_id,
        p.name AS program_name,
        p.duration,
        p.scheduleStart AS schedule_start,
        p.scheduleEnd AS schedule_end,
        p.trainer,
        e.attendance,
        e.completed_at,
        COALESCE(ac.project_visible_to_trainee, 0) as project_visible_to_trainee,
        ac.project_title,
        ac.project_description,
        ac.project_photo_path,
        COALESCE(ac.project_submitted_by_trainee, 0) as project_submitted_by_trainee,
        ac.project_submitted_at,
        ac.project_score,
        ac.project_notes,
        COALESCE(ac.oral_questions_visible_to_trainee, 0) as oral_questions_visible_to_trainee,
        ac.oral_questions,
        ac.oral_answers,
        COALESCE(ac.oral_submitted_by_trainee, 0) as oral_submitted_by_trainee,
        ac.oral_questions_finalized,
        ac.oral_score,
        ac.oral_notes,
        ac.oral_max_score,
        ac.overall_result,
        (SELECT COUNT(*) FROM attendance_records ar WHERE ar.enrollment_id = e.id AND ar.status = 'present') as sessions_attended,
        (SELECT COUNT(*) FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = p.id) as has_feedback,
        f.submitted_at as feedback_submitted_at
    FROM enrollments e
    JOIN programs p ON e.program_id = p.id
    LEFT JOIN assessment_components ac ON e.id = ac.enrollment_id
    LEFT JOIN feedback f ON f.user_id = e.user_id AND f.program_id = p.id
    WHERE e.user_id = ?
    AND e.enrollment_status IN ('approved', 'completed')
    AND e.enrollment_status != 'pending'
    ORDER BY e.applied_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$attendance_threshold = 80;

$today = new DateTime();
$today->setTime(0, 0, 0);

while ($row = $result->fetch_assoc()) {
    // Calculate total days
    $start = new DateTime($row['schedule_start']);
    $end = new DateTime($row['schedule_end']);
    $total_days = $start->diff(new DateTime($row['schedule_end']))->days + 1;
    
    $start->setTime(0, 0, 0);
    $end->setTime(23, 59, 59);
    
    $program_has_started = ($today >= $start);
    $program_has_ended = ($today > $end);
    $program_is_ongoing = ($program_has_started && !$program_has_ended);
    $program_not_started = ($today < $start);
    
    $has_feedback = $row['has_feedback'] > 0;
    $overall_result = $row['overall_result'] ?? null;
    $assessment_passed = ($overall_result === 'Passed');
    $assessment_done = !empty($overall_result);
    $attendance_met = ($row['attendance'] >= $attendance_threshold);
    
    // Decode oral questions
    $oral_questions = [];
    if (!empty($row['oral_questions'])) {
        $oral_questions = json_decode($row['oral_questions'], true) ?: [];
    }
  
    // ==========================================
    // HANDLE PROJECT SUBMISSION - SAME PAGE
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_project'])) {
        $enrollment_id = intval($_POST['enrollment_id']);
        
        // Verify na ito ang enrollment ng trainee
        $check = $conn->prepare("SELECT id FROM enrollments WHERE id = ? AND user_id = ?");
        $check->bind_param("ii", $enrollment_id, $user_id);
        $check->execute();
        if ($check->get_result()->num_rows == 0) {
            die("Invalid enrollment");
        }
        
        // Handle file upload
        $photo_path = '';
        if (isset($_FILES['project_photo']) && $_FILES['project_photo']['error'] == 0) {
            $target_dir = "../uploads/projects/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $extension = pathinfo($_FILES['project_photo']['name'], PATHINFO_EXTENSION);
            $filename = "project_" . $enrollment_id . "_" . time() . "." . $extension;
            $target_file = $target_dir . $filename;
            
            if (move_uploaded_file($_FILES['project_photo']['tmp_name'], $target_file)) {
                $photo_path = "uploads/projects/" . $filename;
            }
        }
        
        // Check if assessment component exists
        $check_ac = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        
        if ($check_ac->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE assessment_components SET 
                project_title = ?,
                project_description = ?,
                project_photo_path = ?,
                project_submitted_by_trainee = 1,
                project_submitted_at = NOW()
                WHERE enrollment_id = ?");
            $stmt->bind_param("sssi", $_POST['project_title'], $_POST['project_description'], $photo_path, $enrollment_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO assessment_components 
                (enrollment_id, project_title, project_description, project_photo_path, project_submitted_by_trainee, project_submitted_at, oral_max_score) 
                VALUES (?, ?, ?, ?, 1, NOW(), 100)");
            $stmt->bind_param("isss", $enrollment_id, $_POST['project_title'], $_POST['project_description'], $photo_path);
            $stmt->execute();
        }
        
        // Redirect to same page with success message
        header("Location: training_progress.php?project_submitted=1");
        exit;
    }
    
    // ==========================================
    // SIMPLE VISIBILITY - Kapag naka-toggle ON, lalabas
    // ==========================================
    $show_project = ($row['project_visible_to_trainee'] == 1);

    $show_oral = false;
    if ($row['oral_questions_visible_to_trainee'] == 1) {
        if (!empty($oral_questions)) {
            $show_oral = true;
        }
    }
    
    $move_to_history = $program_has_ended;
    
    // Certificate availability logic
    $show_feedback_button = false;
    $show_certificate_button = false;
    
    if ($program_has_started) {
        if ($assessment_done && $assessment_passed && $has_feedback) {
            $show_certificate_button = true;
        } elseif ($assessment_done && $assessment_passed && !$has_feedback) {
            $show_feedback_button = true;
        }
    }
    
    // Generate session description
    $daysOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $period = new DatePeriod($start, new DateInterval('P1D'), (new DateTime($row['schedule_end']))->modify('+1 day'));
    $sessionDays = [];
    foreach ($period as $date) {
        $day = $daysOfWeek[$date->format('w')];
        if($day !== 'Sat' && $day !== 'Sun' && !in_array($day, $sessionDays)) {
            $sessionDays[] = $day;
        }
    }
    
    $sessions = '';
    if(count($sessionDays) === 5) {
        $sessions = 'Monday-Friday';
    } elseif($sessionDays === ['Mon','Wed','Fri']) {
        $sessions = 'Monday, Wednesday, Friday';
    } elseif($sessionDays === ['Tue','Thu']) {
        $sessions = 'Tuesday, Thursday';
    } else {
        $map = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday'];
        $fullNames = [];
        foreach($sessionDays as $d) {
            $fullNames[] = $map[$d];
        }
        $sessions = implode(', ', $fullNames);
    }
    
    // Status display
    $status = 'Upcoming';
    if ($program_not_started) {
        $status = 'Not Started Yet';
    } elseif ($program_is_ongoing) {
        $status = 'Ongoing';
    } elseif ($program_has_ended) {
        if ($assessment_done) {
            $status = $assessment_passed ? 'Completed' : 'Failed';
        } else {
            $status = 'Ended';
        }
    }
    
    $programData = [
        "enrollment_id" => $row['enrollment_id'],
        "program_id" => $row['program_id'],
        "program_name" => $row['program_name'],
        "duration" => $row['duration'],
        "schedule_start" => $row['schedule_start'],
        "schedule_end" => $row['schedule_end'],
        "trainer" => $row['trainer'],
        "status" => $status,
        "attendance_percentage" => $row['attendance'] ?? 0,
        "sessions_attended" => $row['sessions_attended'] ?? 0,
        "total_days" => $total_days,
        "assessment" => $overall_result,
        "sessions" => $sessions,
        "show_feedback_button" => $show_feedback_button,
        "show_certificate_button" => $show_certificate_button,
        "has_feedback" => $has_feedback,
        "feedback_submitted_at" => $row['feedback_submitted_at'],
        "attendance_met" => $attendance_met,
        "assessment_done" => $assessment_done,
        "assessment_passed" => $assessment_passed,
        "program_has_started" => $program_has_started,
        "program_has_ended" => $program_has_ended,
        "program_not_started" => $program_not_started,
        "program_is_ongoing" => $program_is_ongoing,
        "completed_at" => $row['completed_at'],
        "overall_result" => $overall_result,
        // Project data
        "show_project" => $show_project,
        "project_title" => $row['project_title'],
        "project_description" => $row['project_description'],
        "project_photo_path" => $row['project_photo_path'],
        "project_submitted" => $row['project_submitted_by_trainee'],
        "project_submitted_at" => $row['project_submitted_at'],
        "project_score" => $row['project_score'],
        "project_notes" => $row['project_notes'],
        // Oral data
        "show_oral" => $show_oral,
        "oral_questions" => $oral_questions,
        "oral_submitted" => $row['oral_submitted_by_trainee'],
        "oral_questions_finalized" => $row['oral_questions_finalized'],
        "oral_score" => $row['oral_score'],
        "oral_notes" => $row['oral_notes'],
        "oral_max_score" => $row['oral_max_score']
    ];
    
    if ($move_to_history) {
        $history[] = $programData;
    } else {
        $currentPrograms[] = $programData;
    }
}

$stmt->close();
$conn->close();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Progress - LEMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f3f4f6;
        }

        .flex {
            display: flex;
        }

        .flex-col {
            flex-direction: column;
        }

        .flex-1 {
            flex: 1;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .min-h-screen {
            min-height: 100vh;
        }

        .bg-gray-100 {
            background-color: #f3f4f6;
        }

        /* Header */
        .header-bar {
            background: #1c2a3a;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            flex-wrap: nowrap;
            min-height: 60px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 0;
        }

        .logo {
            width: 2.5rem;
            height: 2.5rem;
            background: #fff;
            border-radius: 5px;
            flex-shrink: 0;
        }

        .system-name-container {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }

        .system-name-full {
            font-weight: 600;
            font-size: 1.125rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .system-name-abbr {
            font-weight: 600;
            font-size: 1rem;
            display: none;
            white-space: nowrap;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.5rem;
            z-index: 1000;
            flex-shrink: 0;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }

        /* Notifications */
        .notification-container {
            position: relative;
        }

        .notification-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            cursor: pointer;
            position: relative;
            padding: 0.5rem;
            flex-shrink: 0;
        }

        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 1.25rem;
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .notification-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.5rem;
            width: 20rem;
            background: white;
            color: black;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,.15);
            z-index: 50;
            max-height: 24rem;
            overflow: auto;
            display: none;
        }

        .notification-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            position: relative;
        }

        .notification-list {
            list-style: none;
        }

        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: 0.2s;
            position: relative;
            padding-right: 30px;
        }

        .notification-item:hover {
            background: #f9fafb;
        }

        .notification-item.unread {
            background: #f0f9ff;
            border-left: 3px solid #3b82f6;
        }

        .notification-item.unread:hover {
            background: #e0f2fe;
        }

        .notification-item.unread .notification-title {
            font-weight: 600 !important;
        }

        .notification-item.unread .notification-message {
            font-weight: 500;
            color: #1e293b;
        }

        .notification-item::after {
            content: '×';
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .notification-item:hover::after {
            opacity: 1;
            color: #ef4444;
        }

        .notification-title {
            font-weight: 500;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .notification-message {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
            line-height: 1.4;
        }

        .notification-time {
            font-size: 0.75rem;
            color: #9ca3af;
        }

        .no-notifications {
            padding: 2rem 1rem;
            text-align: center;
            color: #6b7280;
        }

        .mark-all-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: #3b82f6;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .mark-all-btn:hover {
            background: #2563eb !important;
        }

        .new-badge {
            background: #3b82f6;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        /* Profile */
        .profile-container {
            position: relative;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
            padding: 0.5rem;
            border-radius: 0.25rem;
            transition: 0.2s;
            flex-shrink: 0;
            max-width: 200px;
        }

        .profile-btn:hover {
            background: rgba(255,255,255,.1);
        }

        .profile-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }

        .profile-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.5rem;
            width: 12rem;
            background: white;
            color: black;
            border-radius: 0.375rem;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
            z-index: 50;
            display: none;
        }

        .profile-dropdown ul {
            list-style: none;
        }

        .dropdown-item {
            width: 100%;
            text-align: left;
            padding: 0.5rem 1rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.875rem;
        }

        .dropdown-item:hover {
            background: #e5e7eb;
        }

        .logout-btn {
            color: #dc2626;
        }

        .role-badge {
            background: #3b82f6;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }

        /* Body container */
        .body-container {
            display: flex;
            flex: 1;
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            width: 16rem;
            background: #1c2a3a;
            color: white;
            display: flex;
            flex-direction: column;
            padding: 1rem;
            gap: 0.5rem;
            transition: 0.3s;
            position: relative;
        }

        .sidebar-btn {
            padding: 0.75rem 1rem;
            border-radius: 0.25rem;
            border: none;
            text-align: left;
            background: #2b3b4c;
            color: white;
            cursor: pointer;
            transition: 0.3s;
            font-size: 0.875rem;
        }

        .sidebar-btn:hover {
            background: #35485b;
        }

        .sidebar-btn.active {
            background: #059669;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,.5);
            z-index: 89;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Main content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            transition: 0.3s;
        }

        .training-progress-text {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #1c2a3a;
            background-color: #d1e7ef;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            display: inline-block;
        }

        /* Programs section */
        .programs-section {
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: bold;
            color: #1c2a3a;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .program-card {
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            position: relative;
        }

        .current-program {
            background-color: #dbeafe;
            border-left: 4px solid #3b82f6;
        }

        .history-program {
            background-color: #f9fafb;
            border-left: 4px solid #10b981;
        }

        .program-name {
            font-weight: bold;
            font-size: 1.25rem;
            color: #1c2a3a;
            margin-bottom: 0.75rem;
        }

        .program-card p {
            margin-bottom: 0.5rem;
            color: #4b5563;
        }

        /* Program status indicator */
        .program-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .status-upcoming {
            background: #e5e7eb;
            color: #4b5563;
        }
        
        .status-ongoing {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-ended {
            background: #f3e8ff;
            color: #6b21a8;
        }
        
        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Sessions */
        .sessions-section {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }

        /* Attendance progress bar */
        .attendance-progress-container {
            margin: 1rem 0;
        }

        .attendance-progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .attendance-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4A90E2, #357ABD);
            border-radius: 10px;
            transition: width 0.3s;
        }

        .attendance-progress-text {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            font-weight: 500;
        }

        /* Project Display */
        .project-section {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: white;
            border-radius: 0.75rem;
            border-left: 5px solid #0ea5e9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .project-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
            border: 3px solid #e5e7eb;
        }

        /* Oral Display */
        .oral-section {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: white;
            border-radius: 0.75rem;
            border-left: 5px solid #8b5cf6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .oral-question-card {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            border: 1px solid #e5e7eb;
        }

        .oral-answer-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            font-family: inherit;
            resize: vertical;
        }

        .oral-answer-textarea:focus {
            border-color: #8b5cf6;
            outline: none;
        }

        .submit-answers-btn {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1rem;
            transition: all 0.3s;
            width: 100%;
        }

        .submit-answers-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .answers-submitted {
            background: #f0fdf4;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
            color: #065f46;
        }

        /* Certificate & Feedback */
        .certificate-section {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: #f0fdf4;
            border-radius: 0.75rem;
            border-left: 5px solid #10b981;
        }

        .certificate-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #059669;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .certificate-requirements {
            background: white;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #059669;
        }

        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
        }

        .requirement i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }

        .requirement-met {
            color: #059669;
        }

        .requirement-not-met {
            color: #dc2626;
        }

        .certificate-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .certificate-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .feedback-btn {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .feedback-btn:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .view-certificate-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .view-certificate-btn:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .assessment-info {
            margin-top: 1rem;
            padding: 1rem;
            background: #fff3cd;
            border-radius: 0.5rem;
            border-left: 4px solid #ffc107;
        }

        /* No programs message */
        .no-programs {
            text-align: center;
            color: #6b7280;
            padding: 3rem 2rem;
            font-size: 1.125rem;
            background: white;
            border-radius: 0.75rem;
            border: 2px dashed #d1d5db;
        }

        .no-programs i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #9ca3af;
        }

        /* Feedback submission date */
        .feedback-date {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
            font-style: italic;
        }

        /* Success message */
        .success-message {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Submit button */
        .submit-project-btn {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            width: 100%;
            text-decoration: none;
        }

        .submit-project-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        /* Last updated - simple lang */
        .last-updated {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 1rem;
            text-align: right;
        }

        .refresh-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-left: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .refresh-btn:hover {
            background: #2563eb;
        }

        .refresh-btn i {
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block !important;
            }
            
            .system-name-full {
                display: none;
            }
            
            .system-name-abbr {
                display: block;
            }
            
            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                z-index: 999;
                transition: left 0.3s ease-in-out;
            }
            
            .sidebar.mobile-open {
                left: 0;
            }
            
            .main-content {
                width: 100%;
                padding: 1rem;
            }
            
            .program-card {
                padding: 1rem;
            }
            
            .certificate-buttons {
                flex-direction: column;
            }
            
            .certificate-btn {
                width: 100%;
                justify-content: center;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -30px;
            }
            
            .profile-dropdown {
                width: 180px;
                right: 0;
            }
        }
    </style>
</head>
<body>
<div class="flex flex-col min-h-screen bg-gray-100">

<!-- Header -->
<header class="header-bar">
    <div class="header-left">
        <img src="../css/logo2.jpg" class="logo" alt="LEMS Logo" draggable="false">
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
                                        <span class="new-badge">NEW</span>
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
                <span class="profile-text"><?php echo htmlspecialchars($username); ?></span> ▾
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

<!-- Body -->
<div class="body-container">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar">
        <button class="sidebar-btn" onclick="location.href='dashboard.php'">Dashboard</button>
        <button class="sidebar-btn active" onclick="location.href='training_progress.php'">My Training Progress</button>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <h1 class="training-progress-text">Your Training Progress</h1>
            <div class="last-updated">
                <span id="lastUpdatedTime"><?php echo date('F j, Y g:i:s A'); ?></span>
                <button class="refresh-btn" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Success messages -->
        <?php if (isset($_GET['feedback_submitted']) && $_GET['feedback_submitted'] == 1): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <div>Thank you! Your feedback has been submitted successfully.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['project_submitted'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> Project submitted successfully!
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['oral_submitted'])): ?>
            <div class="success-message" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);">
                <i class="fas fa-check-circle"></i> Oral answers submitted successfully!
            </div>
        <?php endif; ?>

        <!-- Programs Content -->
        <div id="programsContent">
            <?php if (empty($currentPrograms) && empty($history)): ?>
                <div class="no-programs">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>You don't have any approved training programs yet.</h3>
                    <p>Your program applications are either pending approval or you haven't applied to any programs yet.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($currentPrograms)): ?>
                <div class="programs-section">
                    <h2 class="section-title">Current Programs</h2>
                    
                    <?php foreach ($currentPrograms as $program): ?>
                        <div class="program-card current-program">
                            <h3 class="program-name">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                                <?php if ($program['program_not_started']): ?>
                                    <span class="program-status status-upcoming">Not Started</span>
                                <?php elseif ($program['program_is_ongoing']): ?>
                                    <span class="program-status status-ongoing">Ongoing</span>
                                <?php endif; ?>
                            </h3>
                            
                            <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer']); ?></p>
                            <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> days</p>
                            <p>
                                <b>Schedule:</b> 
                                <?php echo date('F j, Y', strtotime($program['schedule_start'])) . ' – ' . date('F j, Y', strtotime($program['schedule_end'])); ?>
                                <?php if ($program['program_is_ongoing']): ?>
                                    <span style="color: #3b82f6; font-size: 0.9rem;">
                                        <i class="fas fa-clock"></i> Ongoing
                                    </span>
                                <?php elseif ($program['program_not_started']): ?>
                                    <span style="color: #6b7280; font-size: 0.9rem;">
                                        <i class="fas fa-calendar"></i> Starts on <?php echo date('F j, Y', strtotime($program['schedule_start'])); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            
                            <!-- Show progress and attendance (only if program has started) -->
                            <?php if ($program['program_has_started']): ?>
                                <div class="attendance-progress-container">
                                    <b>Attendance:</b> <?php echo $program['sessions_attended']; ?> / <?php echo $program['total_days']; ?> days attended
                                    <div class="attendance-progress-bar">
                                        <div class="attendance-progress-fill" style="width: <?php echo $program['attendance_percentage']; ?>%"></div>
                                    </div>
                                    <div class="attendance-progress-text"><?php echo round($program['attendance_percentage'], 1); ?>%</div>
                                    <small style="color: #666; font-size: 0.8rem;">
                                        <i class="fas fa-info-circle"></i> Minimum required: 80% attendance
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <p><b>Assessment:</b> 
                                <?php if ($program['assessment']): ?>
                                    <span style="color: <?php echo $program['assessment_passed'] ? '#10b981' : '#dc2626'; ?>; font-weight: bold;">
                                        <?php echo htmlspecialchars($program['assessment']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #f97316; font-weight: bold;">Not yet assessed</span>
                                <?php endif; ?>
                            </p>
                            
                            <!-- ========================================== -->
                            <!-- PROJECT OUTPUT SECTION - MAY FORM KATULAD NG ORAL -->
                            <!-- ========================================== -->
                            <?php if ($program['show_project']): ?>
                                <div class="project-section">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                                        <h4 style="color: #0ea5e9; margin: 0; display: flex; align-items: center; gap: 10px;">
                                            <i class="fas fa-project-diagram"></i> Project Output (100 pts)
                                        </h4>
                                        <?php if ($program['project_submitted']): ?>
                                            <span style="background: #28a745; color: white; padding: 5px 15px; border-radius: 50px; font-size: 14px;">
                                                <i class="fas fa-check-circle"></i> Submitted
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #f59e0b; color: white; padding: 5px 15px; border-radius: 50px; font-size: 14px;">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($program['project_submitted']): ?>
                                        <!-- DISPLAY SUBMITTED PROJECT -->
                                        <div style="background: #f0fdf4; padding: 20px; border-radius: 10px;">
                                            <h5 style="color: #28a745; margin-bottom: 15px;">
                                                <i class="fas fa-check-circle"></i> Your Submission
                                                <?php if (!empty($program['project_submitted_at'])): ?>
                                                    <small style="float: right; font-size: 12px;">
                                                        <?php echo date('F j, Y g:i A', strtotime($program['project_submitted_at'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </h5>
                                            
                                            <?php if (!empty($program['project_title'])): ?>
                                                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                                    <strong style="color: #333;">Title:</strong>
                                                    <p style="margin-top: 5px;"><?php echo htmlspecialchars($program['project_title']); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($program['project_description'])): ?>
                                                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                                    <strong style="color: #333;">Description:</strong>
                                                    <p style="margin-top: 5px; white-space: pre-line;"><?php echo nl2br(htmlspecialchars($program['project_description'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($program['project_photo_path'])): ?>
                                                <div style="background: white; padding: 15px; border-radius: 8px;">
                                                    <strong style="color: #333;">Project Image:</strong>
                                                    <img src="/<?php echo $program['project_photo_path']; ?>" 
                                                         class="project-image" 
                                                         onclick="window.open(this.src)" 
                                                         style="max-width: 100%; max-height: 300px; border-radius: 8px; margin-top: 10px; cursor: pointer;">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- PROJECT SUBMISSION FORM -->
                                        <div style="background: #f0f9ff; padding: 25px; border-radius: 10px;">
                                            <p style="font-size: 0.9rem; color: #0ea5e9; margin-bottom: 20px;">
                                                <i class="fas fa-info-circle"></i> Submit your project output below. You can only submit once.
                                            </p>
                                            
                                            <form method="POST" action="" enctype="multipart/form-data" onsubmit="return validateProjectForm()">
                                                <input type="hidden" name="submit_project" value="1">
                                                <input type="hidden" name="enrollment_id" value="<?php echo $program['enrollment_id']; ?>">
                                                
                                                <div style="margin-bottom: 20px;">
                                                    <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                                                        Project Title <span style="color: #dc3545;">*</span>
                                                    </label>
                                                    <input type="text" name="project_title" required 
                                                           style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: inherit;"
                                                           placeholder="Enter your project title">
                                                </div>
                                                
                                                <div style="margin-bottom: 20px;">
                                                    <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                                                        Project Description <span style="color: #dc3545;">*</span>
                                                    </label>
                                                    <textarea name="project_description" rows="6" required 
                                                              style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: inherit; resize: vertical;"
                                                              placeholder="Describe your project in detail..."></textarea>
                                                </div>
                                                
                                                <div style="margin-bottom: 20px;">
                                                    <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                                                        Project Photo (Optional)
                                                    </label>
                                                    <input type="file" name="project_photo" accept="image/*"
                                                           style="width: 100%; padding: 10px; border: 2px dashed #0ea5e9; border-radius: 8px; background: white;">
                                                    <p style="font-size: 12px; color: #666; margin-top: 5px;">
                                                        <i class="fas fa-info-circle"></i> Accepted formats: JPG, PNG, GIF. Max size: 5MB
                                                    </p>
                                                </div>
                                                
                                                <button type="submit" class="submit-project-btn">
                                                    <i class="fas fa-paper-plane"></i> Submit Project
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <!-- TRAINER'S EVALUATION (kung may score na) -->
                                    <?php if (!empty($program['project_score'])): ?>
                                        <div style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                                            <h5 style="color: #0ea5e9; margin-bottom: 15px;">
                                                <i class="fas fa-clipboard-check"></i> Trainer's Evaluation
                                            </h5>
                                            
                                            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center; margin-bottom: 15px;">
                                                <div style="background: white; padding: 15px 25px; border-radius: 10px; border-left: 4px solid #0ea5e9;">
                                                    <div style="font-size: 12px; color: #666;">Project Score</div>
                                                    <div style="font-size: 24px; font-weight: 700; color: #0ea5e9;">
                                                        <?php echo $program['project_score']; ?>/100
                                                    </div>
                                                </div>
                                                
                                                <?php if ($program['project_score'] >= 75): ?>
                                                    <span style="background: #28a745; color: white; padding: 8px 20px; border-radius: 50px;">
                                                        <i class="fas fa-check-circle"></i> PASSED
                                                    </span>
                                                <?php else: ?>
                                                    <span style="background: #dc3545; color: white; padding: 8px 20px; border-radius: 50px;">
                                                        <i class="fas fa-times-circle"></i> FAILED
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if (!empty($program['project_notes'])): ?>
                                                <div style="background: white; padding: 15px; border-radius: 8px;">
                                                    <strong style="color: #333;">Feedback:</strong>
                                                    <p style="margin-top: 8px; white-space: pre-line;"><?php echo nl2br(htmlspecialchars($program['project_notes'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- ORAL QUESTIONS SECTION -->
                            <?php if ($program['show_oral'] && !empty($program['oral_questions'])): ?>
                                <div class="oral-section">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                                        <h4 style="color: #8b5cf6; margin: 0; display: flex; align-items: center; gap: 10px;">
                                            <i class="fas fa-question-circle"></i> Oral Examination Questions
                                        </h4>
                                        <?php if ($program['oral_submitted']): ?>
                                            <span style="background: #28a745; color: white; padding: 5px 15px; border-radius: 50px; font-size: 14px;">
                                                <i class="fas fa-check-circle"></i> Submitted
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #f59e0b; color: white; padding: 5px 15px; border-radius: 50px; font-size: 14px;">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!$program['oral_submitted']): ?>
                                        <div style="background: #f5f3ff; padding: 25px; border-radius: 10px;">
                                            <p style="font-size: 0.9rem; color: #6b7280; margin-bottom: 20px;">
                                                <i class="fas fa-info-circle" style="color: #8b5cf6;"></i> Answer all questions below. You can only submit once.
                                            </p>
                                            
                                            <form method="POST" action="submit_oral_answers.php" onsubmit="return validateOralForm()">
                                                <input type="hidden" name="enrollment_id" value="<?php echo $program['enrollment_id']; ?>">
                                                
                                                <?php foreach ($program['oral_questions'] as $index => $question): ?>
                                                    <div class="oral-question-card">
                                                        <p style="font-weight: 600; margin-bottom: 10px;">
                                                            Q<?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question']); ?>
                                                            <span style="font-size: 12px; color: #8b5cf6; margin-left: 10px;">
                                                                (<?php echo $question['max_score'] ?? 25; ?> pts)
                                                            </span>
                                                        </p>
                                                        <textarea name="answers[<?php echo $index; ?>]" class="oral-answer-textarea" rows="4" placeholder="Type your answer here..." required></textarea>
                                                    </div>
                                                <?php endforeach; ?>
                                                
                                                <button type="submit" class="submit-answers-btn">
                                                    <i class="fas fa-paper-plane"></i> Submit All Answers
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <div class="answers-submitted" style="text-align: center; padding: 30px;">
                                            <i class="fas fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 15px;"></i>
                                            <h4 style="color: #28a745;">Answers Submitted Successfully!</h4>
                                            <p>Your oral exam answers have been submitted and are waiting for trainer evaluation.</p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- TRAINER'S ORAL EVALUATION -->
                                    <?php if (!empty($program['oral_score'])): ?>
                                        <div style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                                            <h5 style="color: #8b5cf6; margin-bottom: 15px;">
                                                <i class="fas fa-clipboard-check"></i> Trainer's Oral Evaluation
                                            </h5>
                                            
                                            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center; margin-bottom: 15px;">
                                                <div style="background: white; padding: 15px 25px; border-radius: 10px; border-left: 4px solid #8b5cf6;">
                                                    <div style="font-size: 12px; color: #666;">Oral Score</div>
                                                    <div style="font-size: 24px; font-weight: 700; color: #8b5cf6;">
                                                        <?php echo $program['oral_score']; ?>/<?php echo $program['oral_max_score'] ?? 100; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($program['oral_score'] >= (($program['oral_max_score'] ?? 100) * 0.75)): ?>
                                                    <span style="background: #28a745; color: white; padding: 8px 20px; border-radius: 50px;">
                                                        <i class="fas fa-check-circle"></i> PASSED
                                                    </span>
                                                <?php else: ?>
                                                    <span style="background: #dc3545; color: white; padding: 8px 20px; border-radius: 50px;">
                                                        <i class="fas fa-times-circle"></i> FAILED
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if (!empty($program['oral_notes'])): ?>
                                                <div style="background: white; padding: 15px; border-radius: 8px;">
                                                    <strong>Feedback:</strong>
                                                    <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($program['oral_notes'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Sessions as text description -->
                            <div class="sessions-section">
                                <p><b>Sessions:</b> <?php echo htmlspecialchars($program['sessions']); ?></p>
                            </div>
                            
                            <!-- Certificate/Feedback Section -->
                            <?php if ($program['show_certificate_button']): ?>
                                <div class="certificate-section">
                                    <div class="certificate-title">
                                        <i class="fas fa-award"></i> Certificate Available
                                    </div>
                                    <div class="certificate-requirements">
                                        <p><strong>Certificate Requirements Met:</strong></p>
                                        <div class="requirement requirement-met">
                                            <i class="fas fa-check-circle"></i> Attendance: 80% or more
                                        </div>
                                        <div class="requirement requirement-met">
                                            <i class="fas fa-check-circle"></i> Assessment: Passed
                                        </div>
                                        <div class="requirement requirement-met">
                                            <i class="fas fa-check-circle"></i> Feedback: Submitted
                                        </div>
                                    </div>
                                    <div class="certificate-buttons">
                                        <button class="certificate-btn view-certificate-btn" onclick="location.href='feedback_certificate.php?generate_certificate=1&program_id=<?php echo $program['program_id']; ?>'">
                                            <i class="fas fa-certificate"></i> View Certificate
                                        </button>
                                    </div>
                                </div>
                            <?php elseif ($program['show_feedback_button']): ?>
                                <div class="certificate-section">
                                    <div class="certificate-title">
                                        <i class="fas fa-comment-dots"></i> Feedback Required
                                    </div>
                                    <div class="certificate-requirements">
                                        <p><strong>Certificate Requirements:</strong></p>
                                        <div class="requirement requirement-met">
                                            <i class="fas fa-check-circle"></i> Attendance: 80% or more
                                        </div>
                                        <div class="requirement requirement-met">
                                            <i class="fas fa-check-circle"></i> Assessment: Passed
                                        </div>
                                        <div class="requirement requirement-not-met">
                                            <i class="fas fa-times-circle"></i> Feedback: Pending
                                        </div>
                                    </div>
                                    <div class="certificate-buttons">
                                        <button class="certificate-btn feedback-btn" onclick="location.href='feedback_certificate.php?program_id=<?php echo $program['program_id']; ?>'">
                                            <i class="fas fa-comment-dots"></i> Submit Feedback
                                        </button>
                                    </div>
                                </div>
                            <?php elseif ($program['attendance_met'] && !$program['assessment_done']): ?>
                                <div class="assessment-info">
                                    <h5><i class="fas fa-clock"></i> Awaiting Assessment</h5>
                                    <p>Your attendance requirement is met. Waiting for trainer to assess your performance.</p>
                                </div>
                            <?php elseif (!$program['attendance_met'] && $program['program_has_started']): ?>
                                <div class="assessment-info">
                                    <h5><i class="fas fa-running"></i> Training in Progress</h5>
                                    <p>Current attendance: <?php echo $program['attendance_percentage']; ?>% (Need 80% minimum)</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
                <div class="programs-section">
                    <h2 class="section-title">Training History</h2>
                    
                    <?php foreach ($history as $program): ?>
                        <div class="program-card history-program">
                            <h3 class="program-name">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                                <?php if ($program['assessment_done'] && $program['assessment_passed']): ?>
                                    <span class="program-status status-completed">Completed</span>
                                <?php elseif ($program['assessment_done'] && !$program['assessment_passed']): ?>
                                    <span class="program-status status-failed">Failed</span>
                                <?php else: ?>
                                    <span class="program-status status-ended">Ended</span>
                                <?php endif; ?>
                            </h3>
                            
                            <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer']); ?></p>
                            <p><b>Schedule:</b> <?php echo date('F j, Y', strtotime($program['schedule_start'])) . ' – ' . date('F j, Y', strtotime($program['schedule_end'])); ?></p>
                            
                            <div class="attendance-progress-container">
                                <b>Attendance:</b> <?php echo $program['sessions_attended']; ?> / <?php echo $program['total_days']; ?> days
                                <div class="attendance-progress-bar">
                                    <div class="attendance-progress-fill" style="width: <?php echo $program['attendance_percentage']; ?>%"></div>
                                </div>
                                <div class="attendance-progress-text"><?php echo round($program['attendance_percentage'], 1); ?>%</div>
                            </div>
                            
                            <p><b>Assessment:</b> 
                                <?php if ($program['assessment']): ?>
                                    <span style="color: <?php echo $program['assessment_passed'] ? '#10b981' : '#dc2626'; ?>;">
                                        <?php echo htmlspecialchars($program['assessment']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6b7280;">Not assessed</span>
                                <?php endif; ?>
                            </p>
                            
                            <?php if ($program['show_certificate_button']): ?>
                                <div style="margin-top: 15px;">
                                    <button class="certificate-btn view-certificate-btn" onclick="location.href='feedback_certificate.php?generate_certificate=1&program_id=<?php echo $program['program_id']; ?>'">
                                        <i class="fas fa-certificate"></i> View Certificate
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</div>

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
                ${isUnread ? '<span class="new-badge">NEW</span>' : ''}
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

document.addEventListener('DOMContentLoaded', function() {
    // Initialize notifications
    initNotifications();

    // Mobile menu toggle
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.createElement('div');
    sidebarOverlay.className = 'sidebar-overlay';
    document.body.appendChild(sidebarOverlay);
    
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
    
    mobileMenuBtn?.addEventListener('click', toggleMobileMenu);
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
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    
    profileBtn?.addEventListener('click', function(e) {
        e.stopPropagation();
        if (profileDropdown) {
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
        }
        if (notificationDropdown) notificationDropdown.style.display = 'none';
    });
    
    // Notification dropdown
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn) {
        notificationBtn.addEventListener('click', function(e) {
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
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (profileBtn && profileDropdown && !profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
            profileDropdown.style.display = 'none';
        }
        if (notificationBtn && notificationDropdown && !notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
            notificationDropdown.style.display = 'none';
        }
    });
});

function goToProfile() {
    window.location.href = 'profile.php';
}

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
        if (result.isConfirmed) {
            window.location.href = '?action=logout';
        }
    });
}

function validateOralForm() {
    const textareas = document.querySelectorAll('.oral-answer-textarea');
    let allFilled = true;
    
    textareas.forEach(textarea => {
        if (!textarea.value.trim()) {
            allFilled = false;
        }
    });
    
    if (!allFilled) {
        Swal.fire({
            title: 'Incomplete Answers',
            text: 'Please answer all questions before submitting.',
            icon: 'warning',
            confirmButtonColor: '#8b5cf6'
        });
        return false;
    }
    
    return Swal.fire({
        title: 'Submit Answers?',
        text: 'You can only submit once. Make sure your answers are final.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#8b5cf6',
        confirmButtonText: 'Yes, submit'
    }).then((result) => {
        return result.isConfirmed;
    });
}

function validateProjectForm() {
    const title = document.querySelector('input[name="project_title"]')?.value.trim();
    const description = document.querySelector('textarea[name="project_description"]')?.value.trim();
    
    if (!title) {
        Swal.fire('Error', 'Please enter a project title', 'error');
        return false;
    }
    if (!description) {
        Swal.fire('Error', 'Please enter a project description', 'error');
        return false;
    }
    
    return Swal.fire({
        title: 'Submit Project?',
        text: 'You can only submit once. Make sure your project is final.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0ea5e9',
        confirmButtonText: 'Yes, submit'
    }).then((result) => {
        return result.isConfirmed;
    });
}

// MANUAL REFRESH LANG - WALANG AUTO
function refreshData() {
    Swal.fire({
        title: 'Refreshing...',
        text: 'Updating training progress',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    fetch(window.location.href, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newContent = doc.getElementById('programsContent');
        
        if (newContent) {
            document.getElementById('programsContent').innerHTML = newContent.innerHTML;
        }
        
        const now = new Date();
        document.getElementById('lastUpdatedTime').textContent = now.toLocaleString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        
        Swal.close();
    })
    .catch(error => {
        console.error('Refresh failed:', error);
        Swal.fire({
            title: 'Refresh Failed',
            text: 'Unable to update data. Please try again.',
            icon: 'error',
            confirmButtonColor: '#3b82f6'
        });
    });
}
</script>
</body>
</html>