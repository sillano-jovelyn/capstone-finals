<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();


// LOGOUT HANDLER
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
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


// DATABASE CONNECTION
$conn = null;
try {
    $db_file = __DIR__ . '/../db.php';
    if (!file_exists($db_file)) {
        throw new Exception("db.php not found");
    }
    require_once $db_file;
    if (!isset($conn) || !$conn) {
        throw new Exception("DB connection not set");
    }
    if (!$conn->ping()) {
        throw new Exception("DB connection lost");
    }
} catch (Exception $e) {
    die("Database connection error. Please try again later.");
}


//  SESSION VALIDATION
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only trainees can access this page
if ($user_role !== 'trainee') {
    header("Location: ../login.php");
    exit();
}

// Double-check user exists in the users table
$userCheck = $conn->prepare(
    "SELECT id FROM users WHERE id = ? AND role = ?"
);
$userCheck->bind_param("is", $user_id, $user_role);
$userCheck->execute();
if ($userCheck->get_result()->num_rows === 0) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$userCheck->close();

// Load the trainee's personal info
$traineeStmt = $conn->prepare(
    "SELECT id, firstname, lastname, email FROM trainees WHERE user_id = ?"
);
$traineeStmt->bind_param("i", $user_id);
$traineeStmt->execute();
$traineeData = $traineeStmt->get_result()->fetch_assoc();
$traineeStmt->close();

if (!$traineeData) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Store useful variables for use throughout the page
$username        = $traineeData['firstname'];
$trainee_id      = $traineeData['id'];
$trainee_fullname = $traineeData['firstname'] . ' ' . $traineeData['lastname'];
$_SESSION['trainee_id'] = $trainee_id;


// HELPER FUNCTIONS
function formatDate($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00') return 'N/A';
    try {
        return (new DateTime($dateStr))->format('F j, Y');
    } catch (Exception $e) {
        return $dateStr;
    }
}

function formatDateTime($dateTimeStr) {
    if (empty($dateTimeStr) || $dateTimeStr === '0000-00-00 00:00:00') return 'N/A';
    try {
        return (new DateTime($dateTimeStr))->format('F j, Y \a\t g:i A');
    } catch (Exception $e) {
        return $dateTimeStr;
    }
}


// ENROLLMENT STATUS FLAGS
$activeCheck = $conn->prepare("
    SELECT COUNT(*) as cnt
    FROM enrollments e
    WHERE e.user_id = ?
      AND (
          e.enrollment_status = 'approved'
          OR (
              e.enrollment_status = 'completed'
              AND NOT EXISTS (
                  SELECT 1 FROM feedback f
                  WHERE f.user_id = e.user_id AND f.program_id = e.program_id
              )
          )
      )
");
$activeCheck->bind_param("i", $user_id);
$activeCheck->execute();
$hasActiveProgram = ($activeCheck->get_result()->fetch_assoc()['cnt'] > 0);
$activeCheck->close();

// Does the trainee have a pending application?
$pendingCheck = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM enrollments WHERE user_id = ? AND enrollment_status = 'pending'"
);
$pendingCheck->bind_param("i", $user_id);
$pendingCheck->execute();
$hasPendingApplications = ($pendingCheck->get_result()->fetch_assoc()['cnt'] > 0);
$pendingCheck->close();

// Does the trainee have any completed enrollment?
$completedCheck = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM enrollments WHERE user_id = ? AND enrollment_status = 'completed'"
);
$completedCheck->bind_param("i", $user_id);
$completedCheck->execute();
$hasCompletedProgram = ($completedCheck->get_result()->fetch_assoc()['cnt'] > 0);
$completedCheck->close();

// Is the trainee enrolled or have a pending app? (Used to lock available programs)
$anyApprovedEnrolled = $hasActiveProgram;

// Lock the "Available Programs" section if trainee is active or pending
$disableAvailablePrograms = ($hasActiveProgram || $hasPendingApplications);


// LOAD ENROLLED PROGRAMS
$enrolledPrograms = [];

$enrolledQuery = "
    SELECT 
        p.id, p.name, p.duration, p.scheduleStart, p.scheduleEnd,
        p.trainer, p.total_slots, p.slotsAvailable,
        pc.name AS category_name,
        e.enrollment_status, e.attendance, e.assessment,
        e.completed_at, e.applied_at AS application_date,
        (
            SELECT COUNT(*) FROM enrollments e2
            WHERE e2.program_id = p.id
              AND e2.enrollment_status IN ('approved','completed')
        ) AS enrolled_count,
        (
            SELECT COUNT(*) FROM feedback f
            WHERE f.user_id = e.user_id AND f.program_id = p.id
        ) AS has_feedback
    FROM enrollments e
    JOIN programs p ON e.program_id = p.id
    LEFT JOIN program_categories pc ON p.category_id = pc.id
    WHERE e.user_id = ?
      AND e.enrollment_status IN ('approved','completed','pending')
    ORDER BY
        CASE
            WHEN e.enrollment_status = 'completed' THEN 3
            WHEN e.enrollment_status = 'pending'   THEN 2
            ELSE 1
        END,
        p.created_at DESC
";

$stmt = $conn->prepare($enrolledQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$enrolledRes = $stmt->get_result();

while ($row = $enrolledRes->fetch_assoc()) {
    // --- Slot calculations ---
    $total_slots    = $row['total_slots'] ?? $row['slotsAvailable'] ?? 0;
    $enrolled_count = $row['enrolled_count'] ?? 0;
    $available_slots = max(0, $total_slots - $enrolled_count);

    // --- Status booleans ---
    $enrollment_status  = $row['enrollment_status'] ?? null;
    $is_enrolled        = in_array($enrollment_status, ['approved', 'completed']);
    $is_pending         = ($enrollment_status === 'pending');
    $is_completed_status = ($enrollment_status === 'completed');

    // --- FIX #1: Determine if the program is truly "completed" visually ---
    // Even if enrollment_status = 'completed', if feedback has NOT been
    // submitted yet, we treat the card as still ACTIVE/ONGOING.
    // The trainee must fill out feedback before seeing "completed" state.
    $has_feedback = ($row['has_feedback'] > 0);

    $is_completed    = false;
    $show_certificate = false;
    $is_from_history = false;
    $is_past_end_date = false;

    if ($is_enrolled) {
        // Check if the program's end date has already passed
        $end_date_raw = $row['scheduleEnd'] ?? null;
        if ($end_date_raw && $end_date_raw !== '0000-00-00') {
            try {
                $end_obj   = (new DateTime($end_date_raw))->setTime(0,0,0);
                $today_obj = (new DateTime())->setTime(0,0,0);
                $is_past_end_date = ($end_obj < $today_obj);
            } catch (Exception $e) {
                $is_past_end_date = false;
            }
        }

        // FIX #1 core: even if enrollment_status = 'completed',
        // without feedback → still show as active/ongoing
        if ($is_completed_status && $has_feedback) {
            // Feedback done → truly completed
            $is_completed    = true;
            $is_from_history = true;
            $show_certificate = true;
        } elseif ($is_completed_status && !$has_feedback) {
            // Admin marked complete but trainee hasn't filled feedback yet
            // → treat as still active/ongoing
            $is_completed    = false;
            $is_from_history = false;
            $show_certificate = false;
        } elseif ($enrollment_status === 'approved' && $is_past_end_date && $has_feedback) {
            // Edge case: approved, end date passed, feedback submitted
            $is_completed    = true;
            $is_from_history = true;
            $show_certificate = true;
        }
        // All other approved cases = active/ongoing
    }

    // Build the full program row for use in templates 
    $row['available_slots']      = $available_slots;
    $row['total_slots']          = $total_slots;
    $row['enrolled_count']       = $enrolled_count;
    $row['is_enrolled']          = $is_enrolled;
    $row['is_pending']           = $is_pending;
    $row['is_completed_status']  = $is_completed_status;
    $row['has_enrollment']       = ($is_enrolled || $is_pending);
    $row['is_completed']         = $is_completed;
    $row['is_past_end_date']     = $is_past_end_date;
    $row['show_certificate']     = $show_certificate;
    $row['is_from_history']      = $is_from_history;
    $row['has_feedback_value']   = $has_feedback;
    $row['is_full']              = ($available_slots <= 0);
    $row['enrolled_user_id']     = $user_id;
    $row['is_archived']          = false;
    $row['archive_id']           = null;
    $row['enrollment_percentage'] = ($total_slots > 0)
        ? round(($enrolled_count / $total_slots) * 100, 1)
        : 0;

    $enrolledPrograms[] = $row;
}
$stmt->close();


// LOAD ARCHIVED HISTORY
$archivedPrograms = [];

$archivedQuery = "
    SELECT
        ah.id AS archive_id,
        ah.original_program_id AS program_id,
        ah.program_name,
        ah.program_duration,
        ah.program_duration_unit,
        ah.program_schedule_start,
        ah.program_schedule_end,
        ah.program_trainer_name AS trainer,
        ah.program_category_id,
        pc.name AS category_name,
        ah.enrollment_status,
        ah.enrollment_attendance AS attendance,
        ah.enrollment_assessment AS assessment,
        ah.enrollment_completed_at AS completion_date,
        ah.archived_at,
        ah.archive_trigger,
        -- All feedback rating fields
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
    ORDER BY ah.archived_at DESC
";

$archivedStmt = $conn->prepare($archivedQuery);
$archivedStmt->bind_param("i", $user_id);
$archivedStmt->execute();
$archivedResult = $archivedStmt->get_result();

while ($archiveRow = $archivedResult->fetch_assoc()) {
    // --- Calculate if feedback was submitted and average rating ---
    $ratingFields = [
        'trainer_expertise_rating','trainer_communication_rating',
        'trainer_methods_rating','trainer_requests_rating',
        'trainer_questions_rating','trainer_instructions_rating',
        'trainer_prioritization_rating','trainer_fairness_rating',
        'program_knowledge_rating','program_process_rating',
        'program_environment_rating','program_algorithms_rating',
        'program_preparation_rating','system_technology_rating',
        'system_workflow_rating','system_instructions_rating',
        'system_answers_rating','system_performance_rating',
    ];

    $ratings    = [];
    $hasFeedback = false;
    foreach ($ratingFields as $field) {
        if (!empty($archiveRow[$field]) && is_numeric($archiveRow[$field])) {
            $ratings[]   = (int)$archiveRow[$field];
            $hasFeedback = true;
        }
    }
    if (!empty($archiveRow['feedback_comments'])) {
        $hasFeedback = true;
    }
    $avgRating = !empty($ratings)
        ? round(array_sum($ratings) / count($ratings), 1)
        : null;

    // A certificate is shown if the record is completed AND assessment = Passed
    $certificateIssued = (
        $archiveRow['enrollment_status'] === 'completed' &&
        strtolower($archiveRow['assessment'] ?? '') === 'passed'
    );

    // Build the archived program row in the same shape as enrolled programs
    $archivedPrograms[] = [
        'id'              => $archiveRow['program_id'] ?? $archiveRow['archive_id'],
        'archive_id'      => $archiveRow['archive_id'],
        'name'            => $archiveRow['program_name'] ?: 'Unknown Program',
        'duration'        => $archiveRow['program_duration'],
        'duration_unit'   => $archiveRow['program_duration_unit'] ?? 'Days',
        'scheduleStart'   => $archiveRow['program_schedule_start'],
        'scheduleEnd'     => $archiveRow['program_schedule_end'],
        'trainer'         => $archiveRow['trainer'] ?? 'Unknown Trainer',
        'category_name'   => $archiveRow['category_name'] ?? 'General',
        'enrollment_status' => $archiveRow['enrollment_status'] ?? 'archived',
        'attendance'      => $archiveRow['attendance'] ?? 0,
        'assessment'      => $archiveRow['assessment'],
        'completed_at'    => $archiveRow['completion_date'] ?? $archiveRow['archived_at'],
        'archived_at'     => $archiveRow['archived_at'],
        'archive_trigger' => $archiveRow['archive_trigger'],

        'has_feedback'         => $hasFeedback,
        'feedback_rating'      => $avgRating,
        'feedback_comments'    => $archiveRow['feedback_comments'],
        'feedback_submitted_at'=> $archiveRow['feedback_submitted_at'],
        'feedback_details'     => $archiveRow,

        // These are set so the template/JS logic stays consistent
        'is_enrolled'          => false,
        'is_pending'           => false,
        'is_completed_status'  => ($archiveRow['enrollment_status'] === 'completed'),
        'has_enrollment'       => false,
        'is_completed'         => true,
        'is_past_end_date'     => true,
        'show_certificate'     => $certificateIssued,
        'is_from_history'      => true,
        'is_archived'          => true,   // KEY FLAG: marks this as archive record
        'has_feedback_value'   => $hasFeedback,
        'enrolled_user_id'     => $user_id,

        'total_slots'          => 0,
        'available_slots'      => 0,
        'enrolled_count'       => 0,
        'enrollment_percentage'=> 0,
        'is_full'              => false,
    ];
}
$archivedStmt->close();

// LOAD AVAILABLE PROGRAMS
$availablePrograms = [];
$today = new DateTime();
$today->setTime(0, 0, 0);

$availableQuery = "
    SELECT
        p.id, p.name, p.duration, p.scheduleStart, p.scheduleEnd,
        p.trainer, p.total_slots, p.slotsAvailable,
        pc.name AS category_name,
        (
            SELECT COUNT(*) FROM enrollments e2
            WHERE e2.program_id = p.id
              AND e2.enrollment_status IN ('approved','completed')
        ) AS enrolled_count
    FROM programs p
    LEFT JOIN program_categories pc ON p.category_id = pc.id
    WHERE p.id NOT IN (
        SELECT program_id FROM enrollments WHERE user_id = ?
    )
    AND p.status  = 'active'
    AND p.show_on_index = 1
    ORDER BY p.created_at DESC
";

$stmtAvailable = $conn->prepare($availableQuery);
$stmtAvailable->bind_param("i", $user_id);
$stmtAvailable->execute();
$availableRes = $stmtAvailable->get_result();

while ($availableRow = $availableRes->fetch_assoc()) {
    // Hide programs whose start date was BEFORE today
    // (strictly yesterday or earlier). Programs starting TODAY
    // are still shown — we apply the 1-day grace for the defense.
    $hideProgram = false;
    if (!empty($availableRow['scheduleStart']) && $availableRow['scheduleStart'] !== '0000-00-00') {
        try {
            $schedStart = (new DateTime($availableRow['scheduleStart']))->setTime(0, 0, 0);
            // Only hide if start date is STRICTLY before today
            // (today's programs are still visible)
            if ($schedStart < $today) {
                $hideProgram = true;
            }
        } catch (Exception $e) {
            $hideProgram = false;
        }
    }
    if ($hideProgram) continue;

    $total_slots    = $availableRow['total_slots'] ?? $availableRow['slotsAvailable'] ?? 0;
    $enrolled_count = $availableRow['enrolled_count'] ?? 0;
    $available_slots = max(0, $total_slots - $enrolled_count);

    $availableRow['available_slots']      = $available_slots;
    $availableRow['total_slots']          = $total_slots;
    $availableRow['enrolled_count']       = $enrolled_count;
    $availableRow['is_enrolled']          = false;
    $availableRow['is_pending']           = false;
    $availableRow['is_completed_status']  = false;
    $availableRow['has_enrollment']       = false;
    $availableRow['is_completed']         = false;
    $availableRow['is_past_end_date']     = false;
    $availableRow['show_certificate']     = false;
    $availableRow['is_from_history']      = false;
    $availableRow['is_archived']          = false;
    $availableRow['archive_id']           = null;
    $availableRow['has_feedback_value']   = false;
    $availableRow['is_full']              = ($available_slots <= 0);
    $availableRow['enrolled_user_id']     = null;
    $availableRow['enrollment_percentage'] = ($total_slots > 0)
        ? round(($enrolled_count / $total_slots) * 100, 1)
        : 0;

    $availablePrograms[] = $availableRow;
}
$stmtAvailable->close();


// MERGE ALL PROGRAMS
$programs = array_merge($enrolledPrograms, $archivedPrograms, $availablePrograms);

// Split for template use
$myPrograms            = array_filter($programs, fn($p) => isset($p['enrolled_user_id']) && $p['enrolled_user_id'] == $user_id);
$availableOnlyPrograms = array_filter($programs, fn($p) => !isset($p['enrolled_user_id']) || $p['enrolled_user_id'] != $user_id);


// FILTER TAB COUNTS
$filter_counts = ['active' => 0, 'pending' => 0, 'completed' => 0];

foreach ($programs as $program) {
    if ($program['is_archived'] === true) {
        // Completed tab = ONLY archived_history records
        // AND only if feedback was actually submitted
        // (no feedback = trainee never finished = don't count as completed)
        if (!empty($program['has_feedback_value'])) {
            $filter_counts['completed']++;
        }
    } elseif ($program['is_pending'] === true) {
        $filter_counts['pending']++;
    } elseif ($program['is_enrolled'] === true) {
        // — Count as ACTIVE if:
        //   a) enrollment_status = 'approved' (normal active), OR
        //   b) enrollment_status = 'completed' BUT feedback not yet submitted
        if ($program['is_completed'] === false && $program['is_archived'] !== true) {
            $filter_counts['active']++;
        }
    }
}

// Default active filter tab: first non-zero tab
$defaultFilter = 'active';
if ($filter_counts['active'] > 0)         $defaultFilter = 'active';
elseif ($filter_counts['pending'] > 0)    $defaultFilter = 'pending';
elseif ($filter_counts['completed'] > 0)  $defaultFilter = 'completed';

// NOTIFICATIONS
$notifications    = [];
$allNotifications = [];

$notifStmt = $conn->prepare("
    SELECT id, title, message, is_read,
           DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') AS created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$notifStmt->bind_param("i", $user_id);
$notifStmt->execute();
$notifResult = $notifStmt->get_result();
while ($row = $notifResult->fetch_assoc()) {
    $allNotifications[] = $row;
    if (!$row['is_read']) {
        $notifications[] = $row;
    }
}
$notifStmt->close();

// Debug helper (visible only in server error log)
error_log("Filter Counts — Active: {$filter_counts['active']}, Pending: {$filter_counts['pending']}, Completed: {$filter_counts['completed']}");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainee Dashboard - Livelihood Enrollment and Monitoring System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ---- Base Reset ---- */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; background-color: #f3f4f6; overflow-x: hidden; }
    .flex { display: flex; } .flex-col { flex-direction: column; } .min-h-screen { min-height: 100vh; } .bg-gray-100 { background-color: #f3f4f6; }

    /* ---- Header ---- */
    .header-bar { background: #1c2a3a; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.5rem; position: sticky; top: 0; z-index: 100; flex-wrap: nowrap; min-height: 60px; }
    .header-left { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0; }
    .logo { width: 2.5rem; height: 2.5rem; background: #fff; border-radius: 5px; flex-shrink: 0; }
    .system-name-container { display: flex; flex-direction: column; min-width: 0; flex: 1; }
    .system-name-full { font-weight: 600; font-size: 1.125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .system-name-abbr { font-weight: 600; font-size: 1rem; display: none; white-space: nowrap; }
    .mobile-menu-btn { display: none; background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; padding: 0.5rem; z-index: 1000; flex-shrink: 0; }
    .header-right { display: flex; align-items: center; gap: 1rem; flex-shrink: 0; }

    /* ---- Notifications ---- */
    .notification-container { position: relative; }
    .notification-btn { background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; position: relative; padding: 0.5rem; flex-shrink: 0; }
    .notification-badge { position: absolute; top: 0; right: 0; background: #ef4444; color: white; border-radius: 50%; width: 1.25rem; height: 1.25rem; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; }
    .notification-dropdown { position: absolute; right: 0; top: 100%; margin-top: 0.5rem; width: 20rem; background: white; color: black; border-radius: 0.5rem; box-shadow: 0 10px 25px rgba(0,0,0,.15); z-index: 50; max-height: 24rem; overflow: auto; display: none; }
    .notification-header { padding: 0.75rem 1rem; border-bottom: 1px solid #e5e7eb; font-weight: 600; position: relative; }
    .notification-list { list-style: none; }
    .notification-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: 0.2s; position: relative; padding-right: 30px; }
    .notification-item:hover { background: #f9fafb; }
    .notification-item.unread { background: #f0f9ff; border-left: 3px solid #3b82f6; }
    .notification-title { font-weight: 500; font-size: 0.875rem; margin-bottom: 0.25rem; }
    .notification-message { font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem; line-height: 1.4; }
    .notification-time { font-size: 0.75rem; color: #9ca3af; }
    .no-notifications { padding: 2rem 1rem; text-align: center; color: #6b7280; }
    .mark-all-btn { position: absolute; right: 10px; top: 10px; background: #3b82f6; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 4px; }

    /* ---- Profile Dropdown ---- */
    .profile-container { position: relative; }
    .profile-btn { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none; padding: 0.5rem; border-radius: 0.25rem; transition: 0.2s; flex-shrink: 0; max-width: 200px; }
    .profile-btn:hover { background: rgba(255,255,255,.1); }
    .profile-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
    .profile-dropdown { position: absolute; right: 0; top: 100%; margin-top: 0.5rem; width: 12rem; background: white; color: black; border-radius: 0.375rem; box-shadow: 0 4px 6px rgba(0,0,0,.1); z-index: 50; display: none; }
    .profile-dropdown ul { list-style: none; }
    .dropdown-item { width: 100%; text-align: left; padding: 0.5rem 1rem; background: none; border: none; cursor: pointer; transition: 0.2s; font-size: 0.875rem; }
    .dropdown-item:hover { background: #e5e7eb; }
    .logout-btn { color: #dc2626; }

    /* ---- Layout ---- */
    .body-container { display: flex; flex: 1; position: relative; }
    .sidebar { width: 16rem; background: #1c2a3a; color: white; display: flex; flex-direction: column; padding: 1rem; gap: 0.5rem; transition: 0.3s; }
    .sidebar-btn { padding: 0.75rem 1rem; border-radius: 0.25rem; border: none; text-align: left; background: #2b3b4c; color: white; cursor: pointer; transition: 0.3s; font-size: 0.875rem; }
    .sidebar-btn:hover { background: #35485b; }
    .sidebar-btn.active { background: #059669; }
    .main-content { flex: 1; padding: 1.5rem; transition: 0.3s; }
    .welcome-text { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; }

    /* ---- Search ---- */
    .search-container { margin-bottom: 1.5rem; max-width: 500px; position: relative; }
    .search-input { padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid #d1d5db; background: white; color: black; width: 100%; font-size: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .search-input:focus { outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,0.3); border-color: #3b82f6; }
    .clear-search-btn { position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer; font-size: 1.125rem; }

    /* ---- Filter Tabs ---- */
    .filter-container { margin: 1.5rem 0; padding: 1rem; background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .filter-label { font-weight: 600; color: #374151; margin-bottom: 0.75rem; font-size: 1rem; }
    .filter-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .filter-btn { padding: 0.625rem 1.25rem; border-radius: 0.375rem; border: 2px solid #e5e7eb; background: white; color: #374151; font-size: 0.875rem; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.25rem; font-weight: 500; min-height: 44px; }
    .filter-btn:hover:not(:disabled) { background: #f3f4f6; border-color: #9ca3af; transform: translateY(-1px); }
    .filter-btn.active { background: #3b82f6; color: white; border-color: #3b82f6; font-weight: 600; box-shadow: 0 2px 4px rgba(59,130,246,0.3); }
    .filter-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* ---- Program Sections ---- */
    .programs-sections-wrapper { display: flex; flex-direction: column; gap: 2.5rem; }
    .programs-section { background: white; border-radius: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
    .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; border-bottom: 2px solid #f3f4f6; gap: 1rem; flex-wrap: wrap; }
    .section-header-left { display: flex; align-items: center; gap: 0.75rem; }
    .section-icon { width: 2.5rem; height: 2.5rem; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: white; flex-shrink: 0; }
    .section-icon.available-icon { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
    .section-icon.available-icon.locked { background: linear-gradient(135deg, #9ca3af, #6b7280); }
    .section-icon.my-programs-icon { background: linear-gradient(135deg, #10b981, #059669); }
    .section-title { font-size: 1.125rem; font-weight: 700; color: #1c2a3a; }
    .section-subtitle { font-size: 0.8rem; color: #6b7280; margin-top: 0.1rem; }
    .section-count-badge { padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; flex-shrink: 0; }
    .section-count-badge.available-badge { background: #e0f2fe; color: #0369a1; }
    .section-count-badge.my-programs-badge { background: #d1fae5; color: #065f46; }
    .section-body { padding: 1.25rem 1.5rem; }
    .active-lock-banner { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.875rem 1rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 0.5rem; margin-bottom: 1.25rem; color: #991b1b; font-size: 0.875rem; }

    /* ---- Program Cards ---- */
    .programs-list { display: flex; flex-direction: column; gap: 1rem; }
    .program-card { border-radius: 0.75rem; padding: 1.25rem; transition: all 0.3s ease; background: #ffffff; border: 1px solid #e5e7eb; display: block; cursor: pointer; text-decoration: none; color: inherit; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 0.5rem; }
    .program-card.enrolled-active { background: linear-gradient(to right, #dbeafe, #eff6ff); border-left: 6px solid #3b82f6; }
    .program-card.pending { background: linear-gradient(to right, #fef3c7, #fffbeb); border-left: 6px solid #f59e0b; }
    .program-card.completed { background: linear-gradient(to right, #d1fae5, #ecfdf5); border-left: 6px solid #10b981; }
    .program-card.available { background: linear-gradient(to right, #f0f9ff, #ffffff); border-left: 6px solid #0ea5e9; }
    .program-card.available.locked-card { background: #f3f4f6 !important; border-left: 6px solid #9ca3af !important; opacity: 0.6; cursor: not-allowed; pointer-events: none; }
    .program-card.archived { background: linear-gradient(to right, #ede9fe, #f5f3ff); border-left: 6px solid #8b5cf6; }
    .program-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem; }
    .program-name { font-weight: 700; font-size: 1.125rem; color: #1c2a3a; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .full-badge { background: #ef4444; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; }
    .new-badge { background: #0ea5e9; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; }
    .status-badge { padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 600; }
    .status-approved { background: #3b82f6; color: white; }
    .status-pending { background: #f59e0b; color: white; }
    .status-completed { background: #10b981; color: white; }
    .status-available { background: #0ea5e9; color: white; }
    .status-archived { background: #8b5cf6; color: white; }
    .status-needs-feedback { background: #f97316; color: white; }
    .needs-feedback-banner { background: #fff7ed; border-left: 4px solid #f97316; }
    .program-card.enrolled-active.needs-feedback-card { border-left-color: #f97316; background: linear-gradient(to right, #fff7ed, #fffbf5); }
    .program-details { font-size: 0.875rem; color: #4b5563; }
    .program-details p { margin-bottom: 0.35rem; display: flex; flex-wrap: wrap; }
    .program-details p b { min-width: 100px; color: #374151; }
    .no-programs { text-align: center; color: #6b7280; font-size: 1rem; padding: 2.5rem 1.5rem; background: #f9fafb; border-radius: 0.75rem; border: 2px dashed #e5e7eb; }

    /* ---- Enrollment Status Banners ---- */
    .enrollment-status { margin-top: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 0.375rem; background: #f8fafc; border-left: 4px solid #3b82f6; }
    .status-message { font-size: 0.875rem; color: #4b5563; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; }
    .completed-status { background: #d1fae5; border-left: 4px solid #10b981; }
    .available-status { background: #e0f2fe; border-left: 4px solid #0ea5e9; }
    .active-enrolled-status { background: #dbeafe; border-left: 4px solid #3b82f6; }
    .pending-status { background: #fef3c7; border-left: 4px solid #f59e0b; }
    .archived-status { background: #ede9fe; border-left: 4px solid #8b5cf6; }
    .archive-badge { background: #8b5cf6; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; margin-left: 0.5rem; }
    .feedback-rating { display: inline-flex; align-items: center; gap: 0.25rem; background: #fbbf24; color: #92400e; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; }

    /* ---- Modals ---- */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center; overflow-y: auto; padding: 1rem; }
    .modal-overlay.active { display: flex; }
    .modal-container { background: white; border-radius: 1rem; max-width: 1000px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    .modal-header { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; padding: 1.5rem; border-top-left-radius: 1rem; border-top-right-radius: 1rem; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h2 { font-size: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .modal-close { background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.5rem; cursor: pointer; width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .modal-body { padding: 1.5rem; }
    .modal-section { background: #f9fafb; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #8b5cf6; }
    .modal-section h3 { font-size: 1.1rem; font-weight: 600; color: #1c2a3a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .modal-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    .modal-info-item { padding: 0.5rem; background: white; border-radius: 0.5rem; border: 1px solid #e5e7eb; }
    .modal-info-item strong { display: block; font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem; }
    .modal-info-item span { font-size: 1rem; font-weight: 500; color: #1c2a3a; }
    .rating-badge-large { display: inline-block; background: #fbbf24; color: #92400e; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; font-size: 1.125rem; }
    .feedback-detail { background: white; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
    .feedback-rating-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
    .rating-category { text-align: center; padding: 0.75rem; background: #f3f4f6; border-radius: 0.5rem; }
    .rating-stars { color: #fbbf24; margin-left: 0.25rem; }
    .certificate-actions { display: flex; gap: 1rem; justify-content: center; margin-top: 1.5rem; flex-wrap: wrap; }
    .btn { padding: 0.75rem 1.5rem; border-radius: 0.5rem; border: none; font-weight: 500; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem; }
    .btn-primary { background: #0ea5e9; color: white; }
    .btn-secondary { background: #8b5cf6; color: white; }

    /* ---- Mobile ---- */
    @media (max-width: 768px) {
        .mobile-menu-btn { display: block !important; }
        .system-name-full { display: none; }
        .system-name-abbr { display: block; }
        .sidebar { position: fixed; top: 0; left: -100%; width: 280px; height: 100vh; z-index: 999; transition: left 0.3s ease-in-out; }
        .sidebar.mobile-open { left: 0; }
        .main-content { width: 100%; padding: 1rem; }
        .program-card { padding: 1rem; }
        .program-details p b { min-width: 80px; }
        .welcome-text { font-size: 1.25rem; }
        .filter-container { padding: 0.75rem; }
        .filter-buttons { width: 100%; }
        .filter-btn { flex: 1; text-align: center; justify-content: center; }
        .notification-dropdown { width: 280px; right: -30px; }
        .profile-dropdown { width: 180px; }
        .modal-grid { grid-template-columns: 1fr; }
        .feedback-rating-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>
<div class="flex flex-col min-h-screen bg-gray-100">

<!--  HEADER: Logo, system name, notifications, profile -->
<header class="header-bar">
    <div class="header-left">
        <img src="../css/logo2.jpg" class="logo" alt="Logo" draggable="false">
        <div class="system-name-container">
            <span class="system-name-full">Livelihood Enrollment &amp; Monitoring System</span>
            <span class="system-name-abbr">LEMS</span>
        </div>
        <button id="mobileMenuBtn" class="mobile-menu-btn"><i class="fas fa-bars"></i></button>
    </div>
    <div class="header-right">
        <!-- Notification Bell -->
        <div class="notification-container">
            <button id="notificationBtn" class="notification-btn">
                <i class="fas fa-bell"></i>
                <?php if (count($notifications) > 0): ?>
                    <span class="notification-badge"><?php echo count($notifications); ?></span>
                <?php endif; ?>
            </button>
            <div id="notificationDropdown" class="notification-dropdown">
                <div class="notification-header">
                    Notifications
                    <?php if (count($notifications) > 0): ?>
                        <button class="mark-all-btn" onclick="markAllAsRead()">
                            <i class="fas fa-check-double"></i> Mark all read
                        </button>
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
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notification-time"><?php echo $notif['created_at']; ?></div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="no-notifications">No notifications</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Profile Dropdown -->
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

<div class="body-container">

    <!--  SIDEBAR: Navigation links-->
    <aside id="sidebar" class="sidebar">
        <button class="sidebar-btn active" onclick="location.href='dashboard.php'">Dashboard</button>
        <button class="sidebar-btn" onclick="location.href='training_progress.php'">My Training Progress</button>
    </aside>

    <!-- MAIN CONTENT-->
    <main class="main-content">
        <p class="welcome-text">
            Welcome, <?php echo htmlspecialchars($username); ?>
            <span class="role-badge" style="font-size:.875rem;">TRAINEE</span>
        </p>

        <div id="dashboardView">
            <!-- Page title & search -->
            <div class="view-header">
                <h1 style="font-size:1.25rem;font-weight:700;margin-bottom:1rem;">Programs</h1>
                <div class="search-container">
                    <input type="text" id="searchQuery" placeholder="Search programs..." class="search-input">
                    <button id="clearSearch" class="clear-search-btn" style="display:none;">×</button>
                </div>
                <p id="searchResults" style="display:none;color:#6b7280;font-size:0.875rem;margin-bottom:1rem;"></p>
            </div>

            <!-- Top status banner depending on enrollment state -->
            <?php if ($hasActiveProgram): ?>
                <div class="enrollment-status active-enrolled-status" style="margin-bottom:2rem;">
                    <p class="status-message"><i class="fas fa-info-circle"></i> You are currently enrolled in an active program. Complete it before applying to a new one.</p>
                </div>
            <?php elseif ($hasPendingApplications): ?>
                <div class="enrollment-status pending-status" style="margin-bottom:2rem;">
                    <p class="status-message"><i class="fas fa-clock"></i> You have a pending application. Wait for admin approval before applying to new programs.</p>
                </div>
            <?php elseif ($hasCompletedProgram && !$hasActiveProgram): ?>
                <div class="enrollment-status completed-status" style="margin-bottom:2rem;">
                    <p class="status-message"><i class="fas fa-check-circle"></i> You have completed your training. You can apply for new programs.</p>
                </div>
            <?php elseif (!$anyApprovedEnrolled && !$hasPendingApplications): ?>
                <div class="enrollment-status" style="margin-bottom:2rem;background:#fef3c7;border-left:4px solid #f59e0b;">
                    <p class="status-message" style="color:#92400e;"><i class="fas fa-info-circle"></i> You are not enrolled in any program yet. Browse available programs below.</p>
                </div>
            <?php endif; ?>

            <?php if (count($programs) === 0): ?>
                <!-- Empty state when no programs exist at all -->
                <div style="text-align:center;padding:3rem;">
                    <i class="fas fa-graduation-cap" style="font-size:3rem;color:#d1d5db;margin-bottom:1rem;display:block;"></i>
                    <h2>No Programs Yet</h2>
                    <p>You haven't applied to any programs yet.</p>
                </div>
            <?php else: ?>

            <div id="programsContainer" class="programs-sections-wrapper">

                <!-- AVAILABLE PROGRAMS -->
                <div class="programs-section" id="availableSection">
                    <div class="section-header">
                        <div class="section-header-left">
                            <div class="section-icon available-icon <?php echo $disableAvailablePrograms ? 'locked' : ''; ?>">
                                <i class="fas fa-<?php echo $disableAvailablePrograms ? 'lock' : 'list-alt'; ?>"></i>
                            </div>
                            <div>
                                <div class="section-title">
                                    Available Programs
                                    <?php if ($disableAvailablePrograms): ?>
                                        <span style="font-size:0.75rem;color:#6b7280;">(Locked)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="section-subtitle">
                                    <?php if ($disableAvailablePrograms): ?>
                                        <?php echo $hasActiveProgram
                                            ? 'Complete your active program to apply for new ones'
                                            : 'Wait for pending application approval to apply for new ones'; ?>
                                    <?php else: ?>
                                        Programs you can apply to
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <span class="section-count-badge available-badge" id="availableCount">
                            <?php $availCount = count($availableOnlyPrograms); echo "$availCount program" . ($availCount !== 1 ? 's' : ''); ?>
                        </span>
                    </div>

                    <div class="section-body">
                        <?php if ($disableAvailablePrograms): ?>
                            <div class="active-lock-banner">
                                <i class="fas fa-lock"></i>
                                <div>
                                    <strong>Applications are currently locked.</strong>
                                    <?php if ($hasActiveProgram): ?>
                                        You are already enrolled in an active program.
                                    <?php elseif ($hasPendingApplications): ?>
                                        You have a pending application. Wait for admin approval.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (count($availableOnlyPrograms) === 0): ?>
                            <div class="no-programs">
                                <i class="fas fa-check-circle" style="font-size:2rem;color:#10b981;margin-bottom:0.5rem;display:block;"></i>
                                <p>You're enrolled in all available programs, or no new programs are currently open.</p>
                            </div>
                        <?php else: ?>
                            <div class="programs-list" id="availableProgramsList">
                                <?php foreach ($availableOnlyPrograms as $program):
                                    $isFull     = $program['is_full'];
                                    $isBlocked  = $disableAvailablePrograms || $isFull;
                                    $programName = htmlspecialchars($program['name']);
                                ?>
                                    <?php if ($isBlocked): ?>
                                        <div class="program-card available locked-card"
                                             data-program-id="<?php echo $program['id']; ?>"
                                             title="<?php echo $isFull ? 'Full' : 'Locked'; ?>">
                                    <?php else: ?>
                                        <div class="program-card available"
                                             data-program-id="<?php echo $program['id']; ?>"
                                             onclick="applyForProgram(<?php echo $program['id']; ?>, '<?php echo addslashes($programName); ?>')"
                                             style="cursor:pointer;">
                                    <?php endif; ?>
                                        <div class="program-header">
                                            <h2 class="program-name">
                                                <?php echo htmlspecialchars($program['name']); ?>
                                                <?php if ($isBlocked): ?>
                                                    <span class="status-badge status-locked"><?php echo $isFull ? 'FULL' : 'LOCKED'; ?></span>
                                                <?php else: ?>
                                                    <span class="status-badge status-available">AVAILABLE</span>
                                                    <span class="new-badge">NEW</span>
                                                <?php endif; ?>
                                                <?php if ($isFull): ?><span class="full-badge">FULL</span><?php endif; ?>
                                            </h2>
                                        </div>
                                        <div class="program-details">
                                            <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                            <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> days</p>
                                            <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                            <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'To be assigned'); ?></p>
                                            <p><b>Available Slots:</b> <?php echo $program['available_slots']; ?> / <?php echo $program['total_slots']; ?> (<?php echo $program['enrollment_percentage']; ?>% filled)</p>
                                            <?php if ($isBlocked): ?>
                                                <div class="enrollment-status" style="background:#fef2f2;border-left:4px solid #ef4444;">
                                                    <p class="status-message"><i class="fas fa-lock"></i>
                                                        <?php
                                                        if ($isFull) echo "This program is full.";
                                                        elseif ($hasActiveProgram) echo "You cannot apply while enrolled in an active program.";
                                                        elseif ($hasPendingApplications) echo "You cannot apply while you have a pending application.";
                                                        ?>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <div class="enrollment-status available-status">
                                                    <span class="status-message"><i class="fas fa-info-circle"></i> Click to apply for this program</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- end #availableSection -->


                <!--  MY PROGRAMS -->
                <div class="programs-section" id="myProgramsSection">

                    <!-- Filter Tabs -->
                    <div class="filter-container">
                        <div class="filter-label">Filter My Programs by:</div>
                        <div class="filter-buttons">
                            <button class="filter-btn <?php echo ($filter_counts['active'] > 0) ? 'active' : ''; ?>"
                                    data-filter="active">
                                Active Programs
                                <?php if ($filter_counts['active'] > 0): ?>(<?php echo $filter_counts['active']; ?>)<?php endif; ?>
                            </button>
                            <button class="filter-btn"
                                    data-filter="pending"
                                    <?php echo ($filter_counts['pending'] === 0) ? 'disabled' : ''; ?>>
                                Pending Applications
                                <?php if ($filter_counts['pending'] > 0): ?>(<?php echo $filter_counts['pending']; ?>)<?php endif; ?>
                            </button>
                            <button class="filter-btn"
                                    data-filter="completed"
                                    <?php echo ($filter_counts['completed'] === 0) ? 'disabled' : ''; ?>>
                                Completed Programs
                                <?php if ($filter_counts['completed'] > 0): ?>(<?php echo $filter_counts['completed']; ?>)<?php endif; ?>
                            </button>
                        </div>
                    </div>

                    <div class="section-header">
                        <div class="section-header-left">
                            <div class="section-icon my-programs-icon"><i class="fas fa-user-graduate"></i></div>
                            <div><div class="section-title">My Programs</div></div>
                        </div>
                        <span class="section-count-badge my-programs-badge" id="myProgramsCount">
                            <?php $myCount = count($myPrograms); echo "$myCount program" . ($myCount !== 1 ? 's' : ''); ?>
                        </span>
                    </div>

                    <div class="section-body">
                        <?php if (count($myPrograms) === 0): ?>
                            <div class="no-programs">
                                <i class="fas fa-graduation-cap" style="font-size:2rem;color:#d1d5db;margin-bottom:0.5rem;display:block;"></i>
                                <p>You haven't enrolled in any programs yet.</p>
                            </div>
                        <?php else: ?>
                            <div id="myProgramsList">
                                <div class="programs-list">
                                <?php foreach ($myPrograms as $program):
                                    $isEnrolled       = $program['is_enrolled'] ?? false;
                                    $isPending        = $program['is_pending'] ?? false;
                                    $isCompletedStatus = $program['is_completed_status'] ?? false;
                                    $isCompleted      = $program['is_completed'] ?? false;
                                    $showCertificate  = $program['show_certificate'] ?? false;
                                    $hasFeedback      = $program['has_feedback_value'] ?? false;
                                    $isArchived       = $program['is_archived'] ?? false;
                                    $feedbackRating   = $program['feedback_rating'] ?? null;
                                    $archiveId        = $program['archive_id'] ?? null;

                                    // Determine card color class and badge text.
                                    // IMPORTANT: $isCompletedStatus=true means admin set enrollment_status='completed'.
                                    // But if $hasFeedback=false, the trainee hasn't finished — show as ACTIVE (blue),
                                    // not green completed. Only use green 'completed' card when feedback is done.
                                    $cardClass   = 'enrolled-active';
                                    $statusBadge = 'ENROLLED';
                                    if ($isArchived) {
                                        $cardClass   = 'archived';
                                        $statusBadge = 'COMPLETED';
                                    } elseif ($isPending) {
                                        $cardClass   = 'pending';
                                        $statusBadge = 'PENDING';
                                    } elseif ($isEnrolled && $isCompleted && $hasFeedback) {
                                        // Truly completed: feedback submitted
                                        $cardClass   = 'completed';
                                        $statusBadge = 'COMPLETED';
                                    } elseif ($isEnrolled && $isCompletedStatus && !$hasFeedback) {
                                        // Admin marked complete but awaiting feedback — keep as active/blue
                                        // Add a special badge to signal the trainee they need to answer feedback
                                        $cardClass   = 'enrolled-active';
                                        $statusBadge = 'NEEDS FEEDBACK';
                                    }
                                ?>
                                    <?php if ($isArchived): ?>
                                        <div class="program-card <?php echo $cardClass; ?>"
                                             data-program-id="<?php echo $program['id']; ?>"
                                             data-archive-id="<?php echo $archiveId; ?>"
                                             onclick="showArchivedModal(this)">
                                    <?php elseif ($isPending): ?>
                                        <div class="program-card <?php echo $cardClass; ?>"
                                             data-program-id="<?php echo $program['id']; ?>"
                                             onclick="showPendingModal(this)">
                                    <?php elseif ($isEnrolled): ?>
                                        <a href="<?php
                                            // Routing logic for enrolled program cards:
                                            // 1. Truly completed with cert  → generate certificate page
                                            // 2. Admin marked complete, no feedback yet → go to feedback page so they can submit
                                            // 3. Normal active program → go to training progress
                                            if ($isCompleted && $showCertificate) {
                                                echo 'feedback_certificate.php?generate_certificate=1&program_id=' . $program['id'];
                                            } elseif ($isCompletedStatus && !$hasFeedback) {
                                                echo 'feedback_certificate.php?program_id=' . $program['id'];
                                            } else {
                                                echo 'training_progress.php?program_id=' . $program['id'];
                                            }
                                        ?>"
                                           class="program-card <?php echo $cardClass; ?> <?php echo ($isCompletedStatus && !$hasFeedback) ? 'needs-feedback-card' : ''; ?>"
                                           data-program-id="<?php echo $program['id']; ?>">
                                    <?php else: ?>
                                        <div class="program-card <?php echo $cardClass; ?>"
                                             data-program-id="<?php echo $program['id']; ?>">
                                    <?php endif; ?>

                                        <div class="program-header">
                                            <h2 class="program-name">
                                                <?php echo htmlspecialchars($program['name']); ?>
                                                <?php if ($statusBadge): ?>
                                                    <span class="status-badge <?php echo match($statusBadge) {
                                                        'COMPLETED'      => 'status-completed',
                                                        'ENROLLED'       => 'status-approved',
                                                        'PENDING'        => 'status-pending',
                                                        'NEEDS FEEDBACK' => 'status-needs-feedback',
                                                        default          => 'status-archived'
                                                    }; ?>"><?php echo $statusBadge; ?></span>
                                                <?php endif; ?>
                                                <?php if ($isArchived): ?>
                                                    <span class="archive-badge">ARCHIVED</span>
                                                <?php endif; ?>
                                            </h2>
                                            <?php if ($feedbackRating): ?>
                                                <span class="feedback-rating"><i class="fas fa-star"></i> <?php echo $feedbackRating; ?>/5</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="program-details">
                                            <p><b>Category:</b> <?php echo htmlspecialchars($program['category_name'] ?? 'General'); ?></p>
                                            <p><b>Duration:</b> <?php echo htmlspecialchars($program['duration']); ?> <?php echo $isArchived ? ($program['duration_unit'] ?? 'days') : 'days'; ?></p>
                                            <p><b>Schedule:</b> <?php echo formatDate($program['scheduleStart']); ?> – <?php echo formatDate($program['scheduleEnd']); ?></p>
                                            <p><b>Trainer:</b> <?php echo htmlspecialchars($program['trainer'] ?? 'To be assigned'); ?></p>

                                            <?php if ($isArchived): ?>
                                                <p><b>Attendance:</b> <?php echo $program['attendance'] ?? 0; ?>%</p>
                                                <p><b>Assessment:</b> <?php echo !empty($program['assessment']) ? htmlspecialchars($program['assessment']) : 'N/A'; ?></p>
                                                <p><b>Completed:</b> <?php echo formatDate($program['completed_at']); ?></p>
                                                <?php if ($hasFeedback): // $hasFeedback is the bool computed from feedback_details ?>
                                                    <p><b>Feedback:</b> Submitted <?php echo !empty($program['feedback_submitted_at']) ? 'on ' . formatDateTime($program['feedback_submitted_at']) : ''; ?></p>
                                                <?php endif; ?>
                                            <?php elseif ($isPending): ?>
                                                <p><b>Available Slots:</b> <?php echo $program['available_slots']; ?> / <?php echo $program['total_slots']; ?></p>
                                            <?php elseif ($isEnrolled && $isCompletedStatus && !$hasFeedback): ?>
                                                <!-- Admin marked complete, feedback still needed -->
                                                <p><b>Attendance:</b> <?php echo $program['attendance'] ?? 0; ?>%</p>
                                                <p><b>Assessment:</b> <?php echo !empty($program['assessment']) ? htmlspecialchars($program['assessment']) : 'Not yet assessed'; ?></p>
                                                <p><b>Status:</b> <span style="color:#f97316;font-weight:600;">Awaiting Feedback</span></p>
                                            <?php else: ?>
                                                <p><b>Attendance:</b> <?php echo $program['attendance'] ?? 0; ?>%</p>
                                                <p><b>Assessment:</b> <?php echo !empty($program['assessment']) ? htmlspecialchars($program['assessment']) : 'Not yet assessed'; ?></p>
                                                <p><b>Status:</b> <?php echo htmlspecialchars($program['enrollment_status'] ?? 'N/A'); ?></p>
                                            <?php endif; ?>

                                            <!-- Status hint banner at the bottom of each card -->
                                            <div class="enrollment-status <?php
                                                // Pick the banner colour class:
                                                // - needs-feedback-banner (orange) when admin completed but trainee hasn't answered feedback
                                                // - archived/pending/completed/active as normal
                                                if ($isArchived) echo 'archived-status';
                                                elseif ($isPending) echo 'pending-status';
                                                elseif ($isEnrolled && $isCompletedStatus && !$hasFeedback) echo 'needs-feedback-banner';
                                                elseif ($isEnrolled && ($isCompleted || $showCertificate)) echo 'completed-status';
                                                elseif ($isEnrolled) echo 'active-enrolled-status';
                                            ?>">
                                                <p class="status-message">
                                                    <i class="fas fa-<?php
                                                        if ($isArchived) echo 'archive';
                                                        elseif ($isPending) echo 'clock';
                                                        elseif ($isEnrolled && $isCompletedStatus && !$hasFeedback) echo 'exclamation-circle';
                                                        else echo 'info-circle';
                                                    ?>"></i>
                                                    <?php if ($isArchived): ?>
                                                        This program has been archived. Click to view full record.
                                                    <?php elseif ($isPending): ?>
                                                        Your application is pending admin approval. Click to view details.
                                                    <?php elseif ($isEnrolled): ?>
                                                        <?php if ($isCompletedStatus && !$hasFeedback): ?>
                                                            <strong>Action Required:</strong> Your training is complete. Please submit your feedback to receive your certificate.
                                                        <?php elseif ($isCompleted && $showCertificate): ?>
                                                            Program completed. Your certificate is ready.
                                                        <?php elseif ($isCompleted && !$showCertificate): ?>
                                                            Program completed. Submit feedback to get your certificate.
                                                        <?php else: ?>
                                                            You are currently enrolled and actively training.
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>

                                    <?php if ($isEnrolled && !$isArchived && !$isPending): ?></a>
                                    <?php else: ?></div><?php endif; ?>

                                <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- end #myProgramsSection -->

            </div><!-- end #programsContainer -->
            <?php endif; // end if programs exist ?>
        </div><!-- end #dashboardView -->
    </main>
</div>
</div>


<!-- MODAL: Pending Application Details -->
<div id="pendingApplicationModal" class="modal-overlay">
    <div class="modal-container" style="max-width:600px;">
        <div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
            <h2><i class="fas fa-clock"></i> <span id="pendingModalTitle">Pending Application</span></h2>
            <button class="modal-close" onclick="closePendingModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="pendingModalBodyContent">
            <div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>


<!--  MODAL: Archived Program Details -->
<div id="archivedProgramModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h2><i class="fas fa-archive"></i> <span id="modalProgramName">Archived Program Details</span></h2>
            <button class="modal-close" onclick="closeArchivedModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="modalBodyContent">
            <div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

// DATA FROM PHP
const allProgramsData      = <?php echo json_encode(array_values($programs)); ?>;
const myProgramsData       = allProgramsData.filter(p => p.enrolled_user_id == <?php echo $user_id; ?>);
const availableProgramsData = allProgramsData.filter(p => !p.enrolled_user_id || p.enrolled_user_id != <?php echo $user_id; ?>);

// Flags passed from PHP
const hasActiveProgram      = <?php echo $hasActiveProgram ? 'true' : 'false'; ?>;
const hasPendingApplications = <?php echo $hasPendingApplications ? 'true' : 'false'; ?>;
const disableAvailablePrograms = <?php echo $disableAvailablePrograms ? 'true' : 'false'; ?>;

// Track the currently active filter tab
let currentFilter = '<?php echo $defaultFilter; ?>';



// UTILITY HELPERS, Small reusable functions used across the page.

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}


function formatDate(dateStr) {
    if (!dateStr || dateStr === '0000-00-00') return 'N/A';
    try {
        return new Date(dateStr).toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
    } catch { return dateStr; }
}


function formatDateTime(dateTimeStr) {
    if (!dateTimeStr || dateTimeStr === '0000-00-00 00:00:00') return 'N/A';
    try {
        return new Date(dateTimeStr).toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    } catch { return dateTimeStr; }
}



function loadNotifications() {
    fetch('notification.php?load=notifications')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateNotificationBadge(data.count);
                updateNotificationDropdown(data.notifications, data.count);
            }
        })
        .catch(err => console.error('Notification error:', err));
}


function updateNotificationBadge(count) {
    const btn = document.querySelector('.notification-btn');
    if (!btn) return;
    const existing = btn.querySelector('.notification-badge');
    if (existing) existing.remove();
    if (count > 0) {
        const badge = document.createElement('span');
        badge.className = 'notification-badge';
        badge.textContent = count;
        btn.appendChild(badge);
    }
}


function updateNotificationDropdown(notifications, count) {
    const dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;

    dropdown.innerHTML = `
        <div class="notification-header">
            Notifications
            ${count > 0 ? '<button class="mark-all-btn" onclick="markAllAsRead()"><i class="fas fa-check-double"></i> Mark all read</button>' : ''}
        </div>
        <ul class="notification-list" id="notificationList"></ul>`;

    const list = document.getElementById('notificationList');
    if (!notifications.length) {
        list.innerHTML = '<li class="no-notifications">No notifications</li>';
        return;
    }
    notifications.forEach(n => {
        const unread = !n.is_read;
        const li = document.createElement('li');
        li.className = 'notification-item' + (unread ? ' unread' : '');
        li.setAttribute('data-id', n.id);
        li.setAttribute('data-read', n.is_read ? 'true' : 'false');
        li.innerHTML = `
            <div class="notification-title">
                ${escapeHtml(n.title)}
                ${unread ? '<span class="new-badge" style="background:#3b82f6;color:white;padding:2px 6px;border-radius:3px;font-size:0.7rem;margin-left:5px;">NEW</span>' : ''}
            </div>
            <div class="notification-message">${escapeHtml(n.message)}</div>
            <div class="notification-time">${escapeHtml(n.created_at)}</div>`;
        li.addEventListener('click', function(e) {
            e.stopPropagation();
            if (this.getAttribute('data-read') !== 'true') {
                markAsRead(this.getAttribute('data-id'), this);
            }
        });
        list.appendChild(li);
    });
}


function markAsRead(notificationId, element) {
    const fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('notification_id', notificationId);
    fetch('notification.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                element.classList.remove('unread');
                element.setAttribute('data-read', 'true');
                const badge = element.querySelector('.new-badge');
                if (badge) badge.remove();
                updateBadgeCountAfterRead();
            }
        })
        .catch(err => console.error('markAsRead error:', err));
}


function markAllAsRead() {
    const fd = new FormData();
    fd.append('action', 'mark_all_read');
    fetch('notification.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('unread');
                    item.setAttribute('data-read', 'true');
                    const b = item.querySelector('.new-badge');
                    if (b) b.remove();
                });
                const badge = document.querySelector('.notification-badge');
                if (badge) badge.remove();
                const markBtn = document.querySelector('.mark-all-btn');
                if (markBtn) markBtn.style.display = 'none';
            }
        })
        .catch(err => console.error('markAllAsRead error:', err));
}


function updateBadgeCountAfterRead() {
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        const n = parseInt(badge.textContent) - 1;
        if (n > 0) badge.textContent = n;
        else {
            badge.remove();
            const btn = document.querySelector('.mark-all-btn');
            if (btn) btn.style.display = 'none';
        }
    }
}


window.applyForProgram = function(programId, programName) {
    // Guard: prevent applying if locked (also enforced server-side)
    if (hasActiveProgram || hasPendingApplications) {
        Swal.fire({
            title: 'Cannot Apply',
            html: hasActiveProgram
                ? 'You are currently enrolled in an active program. Complete it first.'
                : 'You have a pending application. Wait for admin approval.',
            icon: 'warning',
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'OK'
        });
        return;
    }

    Swal.fire({
        title: 'Apply for Program',
        html: `Are you sure you want to apply for <strong>${escapeHtml(programName)}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Apply',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0ea5e9',
        cancelButtonColor: '#6b7280'
    }).then(result => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Submitting...',
            text: 'Please wait',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('program_id', programId);
                fetch('apply_program.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: data.message,
                                icon: 'success',
                                confirmButtonColor: '#0ea5e9'
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({ title: 'Error', text: data.message, icon: 'error', confirmButtonColor: '#ef4444' });
                        }
                    })
                    .catch(() => {
                        Swal.fire({ title: 'Error', text: 'An error occurred. Please try again.', icon: 'error', confirmButtonColor: '#ef4444' });
                    });
            }
        });
    });
};



// PENDING MODAL
window.showPendingModal = function(element) {
    const programId = element.getAttribute('data-program-id');
    const program   = myProgramsData.find(p => p.id == programId && p.is_pending);
    if (!program) {
        Swal.fire({ title: 'Error', text: 'Pending data not found.', icon: 'error' });
        return;
    }
    populatePendingModal(program);
    document.getElementById('pendingApplicationModal').classList.add('active');
    document.body.style.overflow = 'hidden';
};


window.closePendingModal = function() {
    document.getElementById('pendingApplicationModal').classList.remove('active');
    document.body.style.overflow = '';
};


function populatePendingModal(program) {
    document.getElementById('pendingModalTitle').textContent = program.name || 'Pending Application';
    document.getElementById('pendingModalBodyContent').innerHTML = `
        <div class="modal-section" style="border-left-color:#f59e0b;">
            <h3><i class="fas fa-info-circle" style="color:#f59e0b;"></i> Application Details</h3>
            <div class="modal-grid">
                <div class="modal-info-item"><strong>Program Name</strong><span>${escapeHtml(program.name)}</span></div>
                <div class="modal-info-item"><strong>Category</strong><span>${escapeHtml(program.category_name || 'General')}</span></div>
                <div class="modal-info-item"><strong>Duration</strong><span>${escapeHtml(program.duration)} days</span></div>
                <div class="modal-info-item"><strong>Schedule</strong><span>${formatDate(program.scheduleStart)} – ${formatDate(program.scheduleEnd)}</span></div>
                <div class="modal-info-item"><strong>Trainer</strong><span>${escapeHtml(program.trainer || 'To be assigned')}</span></div>
                <div class="modal-info-item"><strong>Available Slots</strong><span>${program.available_slots || 0} / ${program.total_slots || 0}</span></div>
                <div class="modal-info-item"><strong>Status</strong><span style="color:#f59e0b;font-weight:600;"><i class="fas fa-clock"></i> PENDING</span></div>
                <div class="modal-info-item"><strong>Applied On</strong><span>${formatDateTime(program.application_date)}</span></div>
            </div>
        </div>
        <div class="modal-section" style="border-left-color:#f59e0b;background:#fef3c7;">
            <h3><i class="fas fa-hourglass-half" style="color:#f59e0b;"></i> What's Next?</h3>
            <div style="padding:1rem;">
                <div style="display:flex;gap:1rem;margin-bottom:1rem;align-items:center;">
                    <div style="width:40px;height:40px;background:#f59e0b;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;">1</div>
                    <div><strong style="color:#92400e;">Application Submitted</strong><p style="color:#6b7280;font-size:0.875rem;">Your application has been received and is pending review.</p></div>
                </div>
                <div style="display:flex;gap:1rem;margin-bottom:1rem;align-items:center;">
                    <div style="width:40px;height:40px;background:#e5e7eb;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#6b7280;font-weight:bold;">2</div>
                    <div><strong style="color:#374151;">Admin Review</strong><p style="color:#6b7280;font-size:0.875rem;">An administrator will review your application.</p></div>
                </div>
                <div style="display:flex;gap:1rem;align-items:center;">
                    <div style="width:40px;height:40px;background:#e5e7eb;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#6b7280;font-weight:bold;">3</div>
                    <div><strong style="color:#374151;">Approval Decision</strong><p style="color:#6b7280;font-size:0.875rem;">You'll receive a notification once approved or rejected.</p></div>
                </div>
            </div>
        </div>
        <div class="certificate-actions">
            <button class="btn btn-secondary" onclick="closePendingModal()" style="background:#f59e0b;"><i class="fas fa-times"></i> Close</button>
        </div>`;
}


// ARCHIVED MODAL
window.showArchivedModal = function(element) {
    const archiveId     = element.getAttribute('data-archive-id');
    const archivedProgram = allProgramsData.find(p => p.archive_id == archiveId && p.is_archived);
    if (!archivedProgram) {
        Swal.fire({ title: 'Error', text: 'Archived data not found.', icon: 'error' });
        return;
    }
    populateArchivedModal(archivedProgram);
    document.getElementById('archivedProgramModal').classList.add('active');
    document.body.style.overflow = 'hidden';
};


window.closeArchivedModal = function() {
    document.getElementById('archivedProgramModal').classList.remove('active');
    document.body.style.overflow = '';
};


function populateArchivedModal(program) {
    document.getElementById('modalProgramName').textContent = program.name || 'Archived Program';

    // Build feedback section HTML if feedback was submitted
    let feedbackHtml = '';
    if (program.has_feedback && program.feedback_details) {
        const r = program.feedback_details;

        const trainerRatings = [
            { name: 'Expertise',      value: r.trainer_expertise_rating },
            { name: 'Communication',  value: r.trainer_communication_rating },
            { name: 'Methods',        value: r.trainer_methods_rating },
            { name: 'Requests',       value: r.trainer_requests_rating },
            { name: 'Questions',      value: r.trainer_questions_rating },
            { name: 'Instructions',   value: r.trainer_instructions_rating },
            { name: 'Prioritization', value: r.trainer_prioritization_rating },
            { name: 'Fairness',       value: r.trainer_fairness_rating },
        ].filter(x => x.value);

        const programRatings = [
            { name: 'Knowledge',   value: r.program_knowledge_rating },
            { name: 'Process',     value: r.program_process_rating },
            { name: 'Environment', value: r.program_environment_rating },
            { name: 'Algorithms',  value: r.program_algorithms_rating },
            { name: 'Preparation', value: r.program_preparation_rating },
        ].filter(x => x.value);

        const systemRatings = [
            { name: 'Technology',  value: r.system_technology_rating },
            { name: 'Workflow',    value: r.system_workflow_rating },
            { name: 'Instructions',value: r.system_instructions_rating },
            { name: 'Answers',     value: r.system_answers_rating },
            { name: 'Performance', value: r.system_performance_rating },
        ].filter(x => x.value);

        // Helper to render a rating grid section
        const ratingSection = (title, ratings) => ratings.length === 0 ? '' : `
            <div class="feedback-detail">
                <h4 style="margin-bottom:1rem;">${title}</h4>
                <div class="feedback-rating-grid">
                    ${ratings.map(x => `
                        <div class="rating-category">
                            <div style="font-size:0.8rem;color:#6b7280;">${x.name}</div>
                            <div style="font-weight:600;">${x.value}/5
                                <span class="rating-stars">${'★'.repeat(x.value)}${'☆'.repeat(5-x.value)}</span>
                            </div>
                        </div>`).join('')}
                </div>
            </div>`;

        feedbackHtml = `
            <div class="modal-section">
                <h3><i class="fas fa-star"></i> Feedback Summary</h3>
                <div class="rating-badge-large" style="margin-bottom:1rem;">
                    <i class="fas fa-star"></i> Overall Rating: ${program.feedback_rating}/5
                </div>
                ${ratingSection('Trainer Evaluation', trainerRatings)}
                ${ratingSection('Program Evaluation', programRatings)}
                ${ratingSection('System Evaluation', systemRatings)}
                ${program.feedback_comments ? `
                    <div class="feedback-detail">
                        <h4 style="margin-bottom:0.5rem;">Additional Comments</h4>
                        <p style="font-style:italic;background:#f9fafb;padding:1rem;border-radius:0.5rem;">
                            "${escapeHtml(program.feedback_comments)}"
                        </p>
                    </div>` : ''}
                ${program.feedback_submitted_at ? `
                    <p style="font-size:0.85rem;color:#6b7280;">
                        <i class="far fa-calendar-check"></i> Submitted: ${formatDateTime(program.feedback_submitted_at)}
                    </p>` : ''}
            </div>`;
    }

    document.getElementById('modalBodyContent').innerHTML = `
        <div class="modal-section">
            <h3><i class="fas fa-info-circle"></i> Program Information</h3>
            <div class="modal-grid">
                <div class="modal-info-item"><strong>Program Name</strong><span>${escapeHtml(program.name)}</span></div>
                <div class="modal-info-item"><strong>Category</strong><span>${escapeHtml(program.category_name || 'General')}</span></div>
                <div class="modal-info-item"><strong>Duration</strong><span>${escapeHtml(String(program.duration))} ${escapeHtml(program.duration_unit || 'Days')}</span></div>
                <div class="modal-info-item"><strong>Trainer</strong><span>${escapeHtml(program.trainer || 'N/A')}</span></div>
                <div class="modal-info-item"><strong>Schedule Start</strong><span>${formatDate(program.scheduleStart)}</span></div>
                <div class="modal-info-item"><strong>Schedule End</strong><span>${formatDate(program.scheduleEnd)}</span></div>
                <div class="modal-info-item"><strong>Attendance</strong><span>${program.attendance || 0}%</span></div>
                <div class="modal-info-item"><strong>Assessment</strong><span>${program.assessment || 'N/A'}</span></div>
                <div class="modal-info-item"><strong>Completion Date</strong><span>${formatDate(program.completed_at)}</span></div>
                <div class="modal-info-item"><strong>Archived Date</strong><span>${formatDateTime(program.archived_at)}</span></div>
            </div>
        </div>
        ${feedbackHtml}
        <div class="certificate-actions">
            <button class="btn btn-secondary" onclick="closeArchivedModal()"><i class="fas fa-times"></i> Close</button>
        </div>`;
}



function applyMyProgramsFilter(filterType) {
    currentFilter = filterType;

    // Highlight the active tab button
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-filter') === filterType) btn.classList.add('active');
    });

    const searchQuery = (document.getElementById('searchQuery')?.value || '').toLowerCase().trim();
    const cards = document.querySelectorAll('#myProgramsList .programs-list .program-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const programId = parseInt(card.getAttribute('data-program-id'));
        const archiveId = card.getAttribute('data-archive-id');

        // Find the matching program from our JS data
        let program = null;
        if (archiveId) {
            program = myProgramsData.find(p => p.archive_id == archiveId && p.is_archived);
        }
        if (!program) {
            program = myProgramsData.find(p => p.id === programId);
        }

        if (!program) { card.style.display = 'none'; return; }

        // Determine if the card matches the current filter tab
        let show = false;
        if (filterType === 'active') {
            // ACTIVE = is_enrolled AND is_completed=false AND not archived.
            // This catches both normal 'approved' enrollments AND the case where
            // admin set status='completed' but trainee has not submitted feedback yet.
            // PHP sets is_completed=false in that case, so this gate works for both.
            show = program.is_enrolled === true &&
                   program.is_completed === false &&
                   program.is_archived !== true;
        } else if (filterType === 'pending') {
            show = program.is_pending === true && program.is_archived !== true;
        } else if (filterType === 'completed') {
            // COMPLETED = ONLY archived_history records AND feedback must be submitted.
            // If archived but no feedback, the trainee never truly finished — hidden here.
            show = program.is_archived === true && program.has_feedback_value === true;
        }

        // Also apply search filter if query exists
        if (show && searchQuery) {
            const name     = (program.name || '').toLowerCase();
            const category = (program.category_name || '').toLowerCase();
            const trainer  = (program.trainer || '').toLowerCase();
            show = name.includes(searchQuery) || category.includes(searchQuery) || trainer.includes(searchQuery);
        }

        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    // Update the "My Programs" count badge
    const badge = document.getElementById('myProgramsCount');
    if (badge) badge.textContent = visibleCount + ' program' + (visibleCount !== 1 ? 's' : '');
}



document.addEventListener('DOMContentLoaded', function() {

    // Start notification polling
    loadNotifications();
    setInterval(loadNotifications, 30000); // refresh every 30s

    // Apply the default filter tab
    setTimeout(() => applyMyProgramsFilter(currentFilter), 100);

    // ---- Search input ----
    const searchInput = document.getElementById('searchQuery');
    const clearBtn    = document.getElementById('clearSearch');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

            // Filter available programs
            let availableVisible = 0;
            document.querySelectorAll('#availableProgramsList .program-card').forEach(card => {
                const programId = parseInt(card.getAttribute('data-program-id'));
                const program   = availableProgramsData.find(p => p.id === programId);
                const show = !program || !q || [program.name, program.category_name, program.trainer]
                    .some(s => (s || '').toLowerCase().includes(q));
                card.style.display = show ? '' : 'none';
                if (show) availableVisible++;
            });
            const availBadge = document.getElementById('availableCount');
            if (availBadge) availBadge.textContent = availableVisible + ' program' + (availableVisible !== 1 ? 's' : '');

            // Re-apply my programs filter with new search term
            applyMyProgramsFilter(currentFilter);

            const resultsEl = document.getElementById('searchResults');
            if (resultsEl) {
                resultsEl.style.display = q ? 'block' : 'none';
                resultsEl.textContent   = q ? `Search results for "${q}"` : '';
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            clearBtn.style.display = 'none';
            // Reset available programs visibility
            document.querySelectorAll('#availableProgramsList .program-card').forEach(c => c.style.display = '');
            const availBadge = document.getElementById('availableCount');
            if (availBadge) availBadge.textContent = availableProgramsData.length + ' program' + (availableProgramsData.length !== 1 ? 's' : '');
            applyMyProgramsFilter(currentFilter);
            const resultsEl = document.getElementById('searchResults');
            if (resultsEl) resultsEl.style.display = 'none';
        });
    }

    // ---- Filter tab buttons ----
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!this.disabled) applyMyProgramsFilter(this.getAttribute('data-filter'));
        });
    });

    // ---- Profile dropdown toggle ----
    const profileBtn      = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileBtn) {
        profileBtn.addEventListener('click', e => {
            e.stopPropagation();
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
            document.getElementById('notificationDropdown').style.display = 'none';
        });
    }

    // ---- Notification dropdown toggle ----
    const notificationBtn      = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    if (notificationBtn) {
        notificationBtn.addEventListener('click', e => {
            e.stopPropagation();
            notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            profileDropdown.style.display = 'none';
        });
    }

    // ---- Close dropdowns when clicking elsewhere ----
    document.addEventListener('click', e => {
        if (profileBtn && !profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
            profileDropdown.style.display = 'none';
        }
        if (notificationBtn && !notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
            notificationDropdown.style.display = 'none';
        }
    });

    // ---- Mobile sidebar toggle ----
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar       = document.getElementById('sidebar');
    const overlay       = document.createElement('div');
    overlay.className   = 'sidebar-overlay';
    Object.assign(overlay.style, {
        position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
        background: 'rgba(0,0,0,0.5)', zIndex: 998, display: 'none'
    });
    document.body.appendChild(overlay);

    function toggleSidebar() {
        const isOpen = sidebar.classList.contains('mobile-open');
        sidebar.classList.toggle('mobile-open', !isOpen);
        overlay.style.display = isOpen ? 'none' : 'block';
        document.body.style.overflow = isOpen ? '' : 'hidden';
    }

    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', toggleSidebar);
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    });

    // ---- Close modals on ESC or backdrop click ----
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeArchivedModal(); closePendingModal(); }
    });
    document.getElementById('archivedProgramModal').addEventListener('click', function(e) {
        if (e.target === this) closeArchivedModal();
    });
    document.getElementById('pendingApplicationModal').addEventListener('click', function(e) {
        if (e.target === this) closePendingModal();
    });
});



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
    }).then(result => {
        if (result.isConfirmed) window.location.href = '?action=logout';
    });
}


function goToProfile() {
    window.location.href = 'profile.php';
}
</script>
</body>
</html>