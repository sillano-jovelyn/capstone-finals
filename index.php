<?php
// Start session if needed for login handling
session_start();

// Set timezone to Manila/Philippines
date_default_timezone_set('Asia/Manila');

// ==========================================
// DATABASE CONNECTION FOR PROGRAMS
// ==========================================
$programs = [];
$conn = null;
$db_error = '';

try {
    $db_file = __DIR__ . '/db.php';
    
    // Debug: Check if db file exists
    if (!file_exists($db_file)) {
        $db_error = "Database file not found: " . $db_file;
    } else {
        // Include db file
        include $db_file;
        
        // Check if connection was established
        if (isset($conn) && $conn) {
            // Test connection
            if ($conn->ping()) {
                // Debug: Check if programs table exists
                $tableCheck = $conn->query("SHOW TABLES LIKE 'programs'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    // Set Manila timezone for MySQL
                    $conn->query("SET time_zone = '+08:00'");
                    
                    // REAL-TIME: Get current Manila date only (without time)
                    $manila_today = date('Y-m-d');
                    
                    // OPTION 1: Show programs starting from tomorrow (including tomorrow)
                    // Hide only programs that start today or earlier
                    $cutoff_date = date('Y-m-d', strtotime('tomorrow')); // Tomorrow at 00:00:00
                    
                    // Fetch programs that are marked to show on index page
                    // First check if show_on_index column exists
                    $columnCheck = $conn->query("SHOW COLUMNS FROM programs LIKE 'show_on_index'");
                    
                    if ($columnCheck && $columnCheck->num_rows > 0) {
                        // Column exists, use it in query
                        // DATE-ONLY: Show programs starting tomorrow or later (hide today's and past programs)
                        $query = "SELECT id, name, duration, scheduleStart, scheduleEnd, 
                                         trainer, total_slots, slotsAvailable, 
                                         show_on_index 
                                  FROM programs 
                                  WHERE show_on_index = 1 
                                  AND slotsAvailable > 0 
                                  AND DATE(scheduleStart) >= ? 
                                  ORDER BY scheduleStart ASC 
                                  LIMIT 10";
                    } else {
                        // Column doesn't exist, fetch all active programs
                        // DATE-ONLY: Show programs starting tomorrow or later (hide today's and past programs)
                        $query = "SELECT id, name, duration, scheduleStart, scheduleEnd, 
                                         trainer, total_slots, slotsAvailable 
                                  FROM programs 
                                  WHERE slotsAvailable > 0 
                                  AND DATE(scheduleStart) >= ? 
                                  ORDER BY scheduleStart ASC 
                                  LIMIT 10";
                    }
                    
                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        // Bind the cutoff date parameter (shows programs starting from tomorrow)
                        $stmt->bind_param("s", $cutoff_date);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                // Calculate enrollment percentage
                                if ($row['total_slots'] > 0) {
                                    $row['enrollment_percentage'] = round((($row['total_slots'] - $row['slotsAvailable']) / $row['total_slots']) * 100, 1);
                                } else {
                                    $row['enrollment_percentage'] = 0;
                                }
                                
                                // Format dates with Manila timezone
                                if (!empty($row['scheduleStart'])) {
                                    $start_date = new DateTime($row['scheduleStart'], new DateTimeZone('UTC'));
                                    $start_date->setTimezone(new DateTimeZone('Asia/Manila'));
                                    $row['formatted_start'] = $start_date->format('F j, Y');
                                } else {
                                    $row['formatted_start'] = 'Not set';
                                }
                                
                                if (!empty($row['scheduleEnd'])) {
                                    $end_date = new DateTime($row['scheduleEnd'], new DateTimeZone('UTC'));
                                    $end_date->setTimezone(new DateTimeZone('Asia/Manila'));
                                    $row['formatted_end'] = $end_date->format('F j, Y');
                                } else {
                                    $row['formatted_end'] = 'Not set';
                                }
                                
                                // Calculate days until start for display (using Manila time - DATE ONLY)
                                $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
                                $today_date = $now->format('Y-m-d');
                                
                                $start = new DateTime($row['scheduleStart'], new DateTimeZone('UTC'));
                                $start->setTimezone(new DateTimeZone('Asia/Manila'));
                                $start_date_only = $start->format('Y-m-d');
                                
                                // Calculate days difference (date only)
                                $datetime1 = new DateTime($today_date);
                                $datetime2 = new DateTime($start_date_only);
                                $interval = $datetime1->diff($datetime2);
                                $days_until = $interval->days;
                                
                                if ($start_date_only > $today_date) {
                                    if ($days_until == 1) {
                                        $row['time_until'] = 'Tomorrow';
                                    } else {
                                        $row['time_until'] = $days_until . ' days remaining';
                                    }
                                } elseif ($start_date_only == $today_date) {
                                    $row['time_until'] = 'Today';
                                } else {
                                    $row['time_until'] = 'Started';
                                }
                                
                                // Add color class based on percentage
                                if ($row['enrollment_percentage'] >= 90) {
                                    $row['progress_class'] = 'progress-high';
                                } elseif ($row['enrollment_percentage'] >= 75) {
                                    $row['progress_class'] = 'progress-medium';
                                } else {
                                    $row['progress_class'] = 'progress-low';
                                }
                                
                                $programs[] = $row;
                            }
                        } else {
                            $db_error = "No upcoming programs found starting from tomorrow";
                        }
                        $stmt->close();
                    } else {
                        $db_error = "Failed to prepare query: " . $conn->error;
                    }
                    
                    if ($tableCheck) $tableCheck->close();
                    if (isset($columnCheck) && $columnCheck) $columnCheck->close();
                } else {
                    $db_error = "Programs table does not exist";
                    if ($tableCheck) $tableCheck->close();
                }
            } else {
                $db_error = "Database connection lost";
            }
        } else {
            $db_error = "Database connection failed - no connection object";
        }
    }
} catch (Exception $e) {
    $db_error = "Database error: " . $e->getMessage();
}

// Close connection if it exists
if (isset($conn) && $conn) {
    $conn->close();
}

// Get current Manila date for display
$manila_date = new DateTime('now', new DateTimeZone('Asia/Manila'));
$current_manila_date = $manila_date->format('F j, Y');

// Debug info (commented out by default)
/*
echo "<!-- DEBUG INFO:
    Today: " . date('Y-m-d') . "
    Cutoff (tomorrow): " . date('Y-m-d', strtotime('tomorrow')) . "
    Programs found: " . count($programs) . "
-->";
*/

// Check for enrollment success message
$enrollment_message = '';
$enrollment_program = '';
if (isset($_GET['enrolled_program'])) {
    $enrollment_program = htmlspecialchars($_GET['enrolled_program']);
    $enrollment_message = "Your application has been submitted for: " . $enrollment_program . " (Pending Approval)";
}

// Check for error messages
$error_message = '';
if (isset($_GET['message'])) {
    switch($_GET['message']) {
        case 'already_enrolled':
            $error_message = "You already have an application for this program.";
            break;
        case 'pending_application':
            $error_message = "You already have a pending application for this program.";
            break;
        case 'no_slots':
            $error_message = "This program has no available slots.";
            break;
        case 'enrollment_failed':
            $error_message = "Failed to submit application. Please try again.";
            break;
        case 'program_not_found':
            $error_message = "The program could not be found.";
            break;
        case 'application_submitted':
            $error_message = "Application submitted successfully!";
            break;
        case 'program_started':
            $error_message = "This program has already started and is no longer accepting applications.";
            break;
    }
}

// Check if user has a pending program after login
$has_pending_program = false;
$pending_program_id = null;
$pending_program_name = null;

if (isset($_SESSION['pending_enrollment']) && is_array($_SESSION['pending_enrollment'])) {
    $has_pending_program = true;
    $pending_program_id = $_SESSION['pending_enrollment']['program_id'];
    $pending_program_name = $_SESSION['pending_enrollment']['program_name'];
} elseif (isset($_SESSION['pending_program_id']) && isset($_SESSION['pending_program_name'])) {
    $has_pending_program = true;
    $pending_program_id = $_SESSION['pending_program_id'];
    $pending_program_name = $_SESSION['pending_program_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livelihood Enrollment & Monitoring System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
    /* ==========================================
       OVERALL BACKGROUND WITH SMBHALL.PNG
    ========================================== */
    body {
        margin: 0;
        padding: 0;
        font-family: 'Poppins', sans-serif;
        background-image: url('css/SMBHALL.png');
        background-size: cover;
        background-position: center center;
        background-attachment: fixed;
        background-repeat: no-repeat;
        min-height: 100vh;
        color: white;
    }

    /* Optional overlay to improve text readability */
    .homepage {
        min-height: 100vh;

    }

    /* Manila date display */
    .manila-date {
        background: rgba(32, 201, 151, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid rgba(32, 201, 151, 0.3);
        margin-left: 1rem;
    }

    .manila-date i {
        color: #20c997;
    }

    /* ==========================================
       EXISTING STYLES (Modified for new background)
    ========================================== */
    .top-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 2rem;
        background: rgba(28, 42, 58, 0.9); /* Semi-transparent dark blue */
        backdrop-filter: blur(10px);
        position: sticky;
        top: 0;
        z-index: 1000;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .left-section {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .logo {
        width: 50px;
        height: 50px;
        border-radius: 8px;
    }

    .title {
        font-size: 1.5rem;
        font-weight: 600;
        color: white;
    }

    .desktop-title {
        display: block;
    }

    .mobile-title {
        display: none;
        color: white;
    }

    .burger-btn {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        z-index: 1001;
    }

    .right-section {
        display: flex;
        gap: 2rem;
        align-items: center;
    }

    .nav-link {
        color: white;
        text-decoration: none;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: 5px;
        transition: background 0.3s;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       UPDATED MOBILE MENU - FLOATS AT THE TOP
       WITH WHITE TEXT
    ========================================== */
    .mobile-menu {
        display: none;
        flex-direction: column;
        background: rgba(28, 42, 58, 0.98);
        backdrop-filter: blur(15px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 999;
        padding-top: 70px; /* Space for the top bar */
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        max-height: 100vh;
        overflow-y: auto;
    }

    .mobile-menu.active {
        display: flex;
        animation: slideDown 0.3s ease forwards;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .mobile-menu .nav-link {
        padding: 1.2rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 1.1rem;
        text-align: left;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 1rem;
        color: white !important; /* Force white text */
        text-decoration: none;
        font-weight: 500;
    }

    .mobile-menu .nav-link:last-child {
        border-bottom: none;
    }

    .mobile-menu .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
        padding-left: 2.5rem;
        color: white !important;
    }

    .mobile-menu .nav-link i {
        width: 20px;
        text-align: center;
        font-size: 1.2rem;
        color: white !important; /* White icons too */
    }

    /* Mobile menu top bar */
    .mobile-menu-header {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: rgba(28, 42, 58, 0.95);
        backdrop-filter: blur(10px);
        padding: 1rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        z-index: 1000;
        align-items: center;
        justify-content: space-between;
    }

    .mobile-menu-header.active {
        display: flex;
    }

    .mobile-menu-title {
        font-size: 1.2rem;
        font-weight: 600;
        color: white;
    }

    .mobile-close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
    }

    .main-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 80vh;
        padding: 2rem;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .main-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(28, 42, 58, 0.7); /* Darker overlay for main section */
        z-index: 1;
    }

    .main-content {
        position: relative;
        z-index: 2;
        max-width: 800px;
    }

    .main-title {
        font-size: 3rem;
        font-weight: 700;
        margin-bottom: 1rem;
        background: linear-gradient(90deg, #20c997, #3b82f6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .main-subtitle {
        font-size: 1.5rem;
        margin-bottom: 2rem;
        opacity: 0.9;
        color: white;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .cta-button {
        display: inline-block;
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
        padding: 1rem 2.5rem;
        border-radius: 50px;
        text-decoration: none;
        font-weight: 600;
        font-size: 1.2rem;
        margin-top: 1rem;
        transition: transform 0.3s, box-shadow 0.3s;
        box-shadow: 0 4px 15px rgba(32, 201, 151, 0.3);
        border: none;
        cursor: pointer;
    }

    .cta-button:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(32, 201, 151, 0.4);
        background: linear-gradient(90deg, #17a589, #20c997);
    }

    .scroll-indicator {
        position: absolute;
        bottom: 2rem;
        left: 50%;
        transform: translateX(-50%);
        color: rgba(255, 255, 255, 0.7);
        animation: bounce 2s infinite;
        z-index: 2;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% {
            transform: translateX(-50%) translateY(0);
        }
        40% {
            transform: translateX(-50%) translateY(-10px);
        }
        60% {
            transform: translateX(-50%) translateY(-5px);
        }
    }

    .footer {
        text-align: center;
        padding: 2rem;
        background: rgba(0, 0, 0, 0.5);
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(5px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       FULL LENGTH & WIDTH SPACE SECTION
    ========================================== */
    .full-space-section {
        width: 100vw;
        height: 100vh;
        margin: 0;
        padding: 0;
        border: 0;
        background: none;
        display: block;
        clear: both;
        position: relative;
        left: 50%;
        right: 50%;
        margin-left: -50vw;
        margin-right: -50vw;
    }

    /* ==========================================
       NEW STYLES FOR PROGRAMS SECTION
    ========================================== */
    .programs-section {
        padding: 5rem 2rem;
        background: rgba(28, 42, 58, 0.85); /* Semi-transparent background */
        color: white;
        position: relative;
        backdrop-filter: blur(5px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .section-header {
        text-align: center;
        margin-bottom: 3rem;
        position: relative;
    }

    .section-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 1rem;
        background: linear-gradient(90deg, #20c997, #3b82f6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .section-subtitle {
        font-size: 1.2rem;
        color: rgba(255, 255, 255, 0.9);
        max-width: 600px;
        margin: 0 auto;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .programs-container {
        max-width: 1200px;
        margin: 0 auto;
    }

    .programs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 2rem;
        margin-bottom: 3rem;
    }

    .program-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 15px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .program-card:hover {
        transform: translateY(-10px);
        border-color: #20c997;
        box-shadow: 0 15px 30px rgba(32, 201, 151, 0.2);
        background: rgba(255, 255, 255, 0.15);
    }

    .program-header {
        background: rgba(0, 0, 0, 0.3);
        padding: 1.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .program-name {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0;
        color: white;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .program-duration {
        font-size: 0.9rem;
        color: #20c997;
        margin-top: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .program-body {
        padding: 1.5rem;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .program-details {
        margin-bottom: 1.5rem;
    }

    .program-detail {
        display: flex;
        align-items: center;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        color: rgba(255, 255, 255, 0.95);
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .program-detail i {
        width: 24px;
        color: #20c997;
        margin-right: 0.75rem;
        font-size: 1rem;
    }

    .slots-info {
        background: rgba(0, 0, 0, 0.25);
        padding: 1.2rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .progress-container {
        margin-bottom: 0.75rem;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
        color: rgba(255, 255, 255, 0.95);
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .progress-bar {
        height: 8px;
        background: rgba(255, 255, 255, 0.15);
        border-radius: 4px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.6s ease;
    }

    .progress-low {
        background: linear-gradient(90deg, #10b981, #20c997);
    }

    .progress-medium {
        background: linear-gradient(90deg, #f59e0b, #fbbf24);
    }

    .progress-high {
        background: linear-gradient(90deg, #ef4444, #f87171);
    }

    .slots-count {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.8);
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .enroll-btn {
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
        border: none;
        padding: 1rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: auto;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        box-shadow: 0 4px 10px rgba(32, 201, 151, 0.2);
    }

    .enroll-btn:hover {
        background: linear-gradient(90deg, #17a589, #20c997);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(32, 201, 151, 0.3);
    }

    .no-programs {
        text-align: center;
        padding: 4rem;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 15px;
        border: 2px dashed rgba(255, 255, 255, 0.2);
        grid-column: 1 / -1;
        backdrop-filter: blur(5px);
    }

    .no-programs i {
        font-size: 3rem;
        color: rgba(255, 255, 255, 0.4);
        margin-bottom: 1rem;
    }

    .no-programs h3 {
        color: white;
        margin-bottom: 1rem;
        font-size: 1.5rem;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .no-programs p {
        color: rgba(255, 255, 255, 0.8);
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    /* Days remaining styling */
    .days-remaining {
        color: #20c997;
        font-weight: 500;
    }

    .days-today {
        color: #ffc107;
        font-weight: 600;
    }

    .days-tomorrow {
        color: #ffc107;
        font-weight: 600;
    }

    /* Manila date badge */
    .date-badge {
        background: rgba(32, 201, 151, 0.15);
        border-radius: 50px;
        padding: 0.25rem 0.75rem;
        font-size: 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border: 1px solid rgba(32, 201, 151, 0.3);
        margin-left: 0.5rem;
    }

    .date-badge i {
        font-size: 0.7rem;
    }

    /* ==========================================
       MODAL STYLES
    ========================================== */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(5px);
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        background: linear-gradient(145deg, rgba(28, 42, 58, 0.95), rgba(43, 59, 76, 0.95));
        border-radius: 20px;
        width: 90%;
        max-width: 450px;
        animation: modalSlideIn 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        overflow: hidden;
        backdrop-filter: blur(10px);
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal-header {
        background: rgba(0, 0, 0, 0.4);
        padding: 2rem;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }

    .modal-title {
        font-size: 1.8rem;
        margin: 0;
        color: white;
        font-weight: 600;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .modal-program-name {
        color: #20c997;
        font-weight: 500;
        margin-top: 0.5rem;
        font-size: 1.1rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .modal-body {
        padding: 2rem;
    }

    .modal-message {
        text-align: center;
        margin-bottom: 2rem;
        color: rgba(255, 255, 255, 0.95);
        font-size: 1.1rem;
        line-height: 1.6;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .modal-buttons {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .modal-btn {
        padding: 1rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-decoration: none;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }

    .login-btn {
        background: linear-gradient(90deg, #3b82f6, #2563eb);
        color: white;
    }

    .login-btn:hover {
        background: linear-gradient(90deg, #2563eb, #1d4ed8);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3);
    }

    .register-btn-modal {
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
    }

    .register-btn-modal:hover {
        background: linear-gradient(90deg, #17a589, #20c997);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(32, 201, 151, 0.3);
    }

    .confirm-enroll-btn {
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
    }

    .confirm-enroll-btn:hover {
        background: linear-gradient(90deg, #17a589, #20c997);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(32, 201, 151, 0.3);
    }

    .confirm-enroll-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .close-btn {
        background: rgba(255, 255, 255, 0.15);
        color: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.25);
    }

    .close-btn:hover {
        background: rgba(255, 255, 255, 0.25);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       SUCCESS/ERROR MESSAGE STYLES
    ========================================== */
    .message-container {
        position: fixed;
        top: 90px;
        right: 20px;
        z-index: 1001;
        max-width: 400px;
    }

    .alert-message {
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        box-shadow: 0 5px 15px rgba(32, 201, 151, 0.3);
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: slideInRight 0.3s ease;
        border-left: 4px solid #17a589;
    }

    .alert-message.error {
        background: linear-gradient(90deg, #ef4444, #dc2626);
        border-left-color: #dc2626;
    }

    .alert-message.warning {
        background: linear-gradient(90deg, #f59e0b, #d97706);
        border-left-color: #d97706;
    }

    .alert-message.info {
        background: linear-gradient(90deg, #3b82f6, #2563eb);
        border-left-color: #2563eb;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    .alert-message.hiding {
        animation: slideOutRight 0.3s ease forwards;
    }

    /* ==========================================
       RESPONSIVE STYLES
    ========================================== */
    @media (max-width: 768px) {
        .desktop-title {
            display: none;
        }
        
        .mobile-title {
            display: block;
            color: white;
        }
        
        .burger-btn {
            display: block;
            color: white;
        }
        
        .right-section {
            display: none;
        }
        
        .main-title {
            font-size: 2.2rem;
        }
        
        .main-subtitle {
            font-size: 1.2rem;
            color: white;
        }
        
        .section-title {
            font-size: 2rem;
        }
        
        .programs-grid {
            grid-template-columns: 1fr;
        }
        
        .programs-section {
            padding: 3rem 1rem;
        }
        
        .main-section {
            min-height: 70vh;
            padding: 1.5rem;
        }
        
        .top-nav {
            padding: 1rem;
        }
        
        .logo {
            width: 40px;
            height: 40px;
        }
        
        .title {
            font-size: 1.2rem;
            color: white;
        }

        .manila-date {
            margin-left: 0;
            margin-top: 0.5rem;
            width: 100%;
            justify-content: center;
        }
        
        .modal-content {
            width: 95%;
            max-width: 350px;
        }
        
        /* Ensure background still looks good on mobile */
        body {
            background-attachment: scroll;
        }
        
        /* Mobile menu takes full height */
        .mobile-menu {
            padding-top: 80px;
            height: calc(100vh - 80px);
        }
        
        /* Ensure mobile menu text is white */
        .mobile-menu .nav-link {
            color: white !important;
            font-weight: 500;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
        
        /* Adjust message container for mobile */
        .message-container {
            top: 80px;
            right: 10px;
            left: 10px;
            max-width: none;
        }
    }

    @media (max-width: 480px) {
        .main-title {
            font-size: 1.8rem;
        }
        
        .main-subtitle {
            font-size: 1rem;
            color: white;
        }
        
        .section-title {
            font-size: 1.6rem;
        }
        
        .section-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .program-card {
            margin-bottom: 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-btn {
            padding: 0.9rem 1rem;
            font-size: 0.95rem;
            color: white;
        }
        
        .mobile-menu .nav-link {
            padding: 1.2rem 1.5rem;
            font-size: 1rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link:hover {
            padding-left: 2rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
    }

    /* Animation for program cards when they appear */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .program-card {
        animation: fadeInUp 0.6s ease forwards;
        opacity: 0;
    }

    .program-card:nth-child(1) { animation-delay: 0.1s; }
    .program-card:nth-child(2) { animation-delay: 0.2s; }
    .program-card:nth-child(3) { animation-delay: 0.3s; }
    .program-card:nth-child(4) { animation-delay: 0.4s; }
    .program-card:nth-child(5) { animation-delay: 0.5s; }
    .program-card:nth-child(6) { animation-delay: 0.6s; }
    .program-card:nth-child(7) { animation-delay: 0.7s; }
    .program-card:nth-child(8) { animation-delay: 0.8s; }
    .program-card:nth-child(9) { animation-delay: 0.9s; }
    .program-card:nth-child(10) { animation-delay: 1s; }
    
    /* ==========================================
       DEBUG STYLES
    ========================================== */
    .debug-info {
        position: fixed;
        top: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 10px;
        border-radius: 5px;
        font-size: 12px;
        z-index: 9999;
        max-width: 300px;
        display: none; /* Change to 'block' to see debug info */
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    </style>
</head>
<body>


    <!-- MESSAGE CONTAINER FOR ALERTS -->
    <div class="message-container" id="messageContainer">
        <?php if ($enrollment_message): ?>
            <div class="alert-message info" id="successMessage">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Application Submitted!</strong><br>
                    <?php echo $enrollment_message; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-message <?php echo ($error_message === 'Application submitted successfully!') ? 'info' : 'error'; ?>" id="errorMessage">
                <i class="fas <?php echo ($error_message === 'Application submitted successfully!') ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div>
                    <strong><?php echo ($error_message === 'Application submitted successfully!') ? 'Success' : 'Notice'; ?></strong><br>
                    <?php echo $error_message; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="homepage">

        <!-- TOP NAVBAR -->
        <div class="top-nav">
            <!-- LEFT SECTION -->
            <div class="left-section">
                <img src="/css/logo.png" alt="Logo" class="logo">
                <h1 class="title" title="Livelihood Enrollment & Monitoring System">
                    <span class="desktop-title">Livelihood Enrollment & Monitoring System</span>
                    <span class="mobile-title">LEMS</span>
                </h1>
            </div>

            <!-- BURGER BUTTON (mobile only) -->
            <button class="burger-btn" id="burgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>

            <!-- DESKTOP NAV -->
            <nav class="right-section">
                <a href="index.php" class="nav-link">Home</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="faqs.php" class="nav-link">FAQs</a>
                <a href="login.php" class="nav-link">Login</a>
            </nav>
        </div>

        <!-- MOBILE MENU DROPDOWN - NOW FLOATS AT THE TOP -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="index.php" class="nav-link">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="about.php" class="nav-link">
                <i class="fas fa-info-circle"></i> About
            </a>
            <a href="faqs.php" class="nav-link">
                <i class="fas fa-question-circle"></i> FAQs
            </a>
            <a href="login.php" class="nav-link">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
        </div>

        <!-- FULL LENGTH & WIDTH SPACE SECTION -->
        <div class="full-space-section"></div>

        <!-- NEW PROGRAMS SECTION -->
        <section class="programs-section" id="programs">
            <div class="section-header">
                <h2 class="section-title">Available Training Programs</h2>
                <p class="section-subtitle">
                    Browse through our available livelihood training programs and apply today. 
                    <strong>All applications require admin approval.</strong>
                </p>
                <div style="margin-top: 1rem; font-size: 0.9rem; background: rgba(32,201,151,0.1); padding: 0.5rem 1rem; border-radius: 50px; display: inline-block;">
                    <i class="fas fa-info-circle"></i> Showing programs starting <strong>tomorrow (<?php echo date('F j, Y', strtotime('tomorrow')); ?>)</strong> and beyond
                </div>
            </div>
            
            <div class="programs-container">
                <?php if (count($programs) > 0): ?>
                    <div class="programs-grid">
                        <?php foreach ($programs as $program): ?>
                            <div class="program-card" 
                                 data-program-id="<?php echo $program['id']; ?>"
                                 data-total-slots="<?php echo $program['total_slots']; ?>"
                                 data-start-date="<?php echo date('Y-m-d', strtotime($program['scheduleStart'])); ?>"
                                 <?php if (!empty($program['background_image_url'])): ?>
                                 style="background: linear-gradient(rgba(28, 42, 58, 0.85), rgba(28, 42, 58, 0.9)), url('<?php echo htmlspecialchars($program['background_image_url']); ?>'); background-size: cover; background-position: center;"
                                 <?php endif; ?>>
                                <div class="program-header">
                                    <h3 class="program-name"><?php echo htmlspecialchars($program['name']); ?></h3>
                                    <div class="program-duration">
                                        <?php echo htmlspecialchars($program['duration']); ?> Days
                                    </div>
                                </div>
                                
                                <div class="program-body">
                                    <div class="program-details">
                                        <div class="program-detail">
                                            <i class="far fa-calendar-alt"></i>
                                            <span><?php echo $program['formatted_start']; ?> - <?php echo $program['formatted_end']; ?></span>
                                        </div>
                                        <div class="program-detail">
                                            <i class="fas fa-user-tie"></i>
                                            <span>Trainer: <?php echo htmlspecialchars($program['trainer']); ?></span>
                                        </div>
                                        <div class="program-detail">
                                            <i class="fas fa-hourglass-half"></i>
                                            <span class="days-remaining <?php echo ($program['time_until'] == 'Tomorrow' || $program['time_until'] == 'Today') ? 'days-tomorrow' : ''; ?>">
                                                <?php echo $program['time_until']; ?>
                                            </span>
                                            <?php if ($program['time_until'] == 'Tomorrow'): ?>
                                                <span class="date-badge"><i class="fas fa-clock"></i> Starts tomorrow!</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="slots-info">
                                        <div class="progress-container">
                                            <div class="progress-label">
                                                <span>Enrollment Progress</span>
                                                <span><?php echo $program['enrollment_percentage']; ?>%</span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $program['progress_class']; ?>" 
                                                     style="width: <?php echo $program['enrollment_percentage']; ?>%"></div>
                                            </div>
                                        </div>
                                        <div class="slots-count">
                                            <i class="fas fa-users"></i>
                                            <?php echo $program['slotsAvailable']; ?> slots available out of <?php echo $program['total_slots']; ?>
                                        </div>
                                    </div>
                                    
                                    <button class="enroll-btn" onclick="showEnrollModal(<?php echo $program['id']; ?>, '<?php echo addslashes($program['name']); ?>')">
                                        <i class="fas fa-user-plus"></i> Apply Now
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-programs">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Programs Available</h3>
                        <p>There are no programs starting from tomorrow onwards at the moment.</p>
                        <p style="margin-top: 1rem; font-size: 0.9rem; color: #20c997;">
                            <i class="fas fa-info-circle"></i> Today's date: <?php echo $current_manila_date; ?><br>
                            <i class="fas fa-calendar-day"></i> Showing programs starting from: <?php echo date('F j, Y', strtotime('tomorrow')); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="footer">
            © <?php echo date('Y'); ?> Livelihood Enrollment and Monitoring System. All Rights Reserved.<br>
            <small><i class="fas fa-calendar-day"></i> All dates are in Manila Time (UTC+8)</small>
        </footer>
    </div>

    <!-- ENROLL MODAL -->
    <div class="modal" id="enrollModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Program Application</h3>
                <div class="modal-program-name" id="modalProgramName"></div>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="modalMessage">
                    Loading...
                </p>
                <div class="modal-buttons">
                    <a href="#" class="modal-btn login-btn" id="loginBtn" onclick="redirectToLogin(event)">
                        <i class="fas fa-sign-in-alt"></i> Login to Apply
                    </a>
                    <a href="#" class="modal-btn register-btn-modal" id="registerBtn" onclick="redirectToRegister(event)">
                        <i class="fas fa-user-plus"></i> Create Account
                    </a>
                    <!-- Confirm button will be added dynamically by JavaScript -->
                    <button class="modal-btn close-btn" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // ==========================================
        // MANILA DATE DISPLAY
        // ==========================================
        function updateManilaDate() {
            const manilaDate = document.getElementById('manilaDate');
            if (manilaDate) {
                const options = {
                    timeZone: 'Asia/Manila',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                };
                
                const manilaDateStr = new Date().toLocaleDateString('en-US', options);
                manilaDate.textContent = manilaDateStr;
            }
        }

        // Update Manila date display
        setInterval(updateManilaDate, 60000); // Update every minute

        // ==========================================
        // DEBUG: Show debug info on Ctrl+D
        // ==========================================
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                const debugInfo = document.getElementById('debugInfo');
                debugInfo.style.display = debugInfo.style.display === 'block' ? 'none' : 'block';
            }
        });

        // ==========================================
        // MOBILE MENU FUNCTIONALITY - UPDATED
        // ==========================================
        const burgerBtn = document.getElementById('burgerBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const body = document.body;

        if (burgerBtn && mobileMenu) {
            burgerBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                mobileMenu.classList.toggle('active');
                body.classList.toggle('menu-open');
                
                // Change burger icon to X when menu is open
                const icon = burgerBtn.querySelector('i');
                if (mobileMenu.classList.contains('active')) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                } else {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!burgerBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    
                    // Reset burger icon
                    const icon = burgerBtn.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });

            // Close mobile menu when clicking a link
            const mobileLinks = mobileMenu.querySelectorAll('.nav-link');
            mobileLinks.forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    
                    // Reset burger icon
                    const icon = burgerBtn.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                });
            });

            // Close menu with Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    
                    // Reset burger icon
                    const icon = burgerBtn.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });
        }

        // Prevent body scroll when menu is open
        function toggleBodyScroll(disable) {
            if (disable) {
                body.style.overflow = 'hidden';
            } else {
                body.style.overflow = '';
            }
        }

        // Observe mobile menu for changes
        if (mobileMenu) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        toggleBodyScroll(mobileMenu.classList.contains('active'));
                    }
                });
            });
            
            observer.observe(mobileMenu, { attributes: true });
        }

        // ==========================================
        // ENROLLMENT MODAL FUNCTIONALITY - UPDATED FOR PENDING SYSTEM
        // ==========================================
        const enrollModal = document.getElementById('enrollModal');
        const modalMessage = document.getElementById('modalMessage');
        const modalProgramName = document.getElementById('modalProgramName');
        const loginBtn = document.getElementById('loginBtn');
        const registerBtn = document.getElementById('registerBtn');
        let currentProgramId = null;
        let currentProgramName = '';

        function showEnrollModal(programId, programName) {
            currentProgramId = programId;
            currentProgramName = programName;
            
            console.log('Application modal opened for:', programId, programName);
            
            if (modalProgramName) modalProgramName.textContent = programName;
            
            // Show loading message
            modalMessage.innerHTML = 'Checking your account status...';
            
            // Hide all buttons initially
            if (loginBtn) loginBtn.style.display = 'none';
            if (registerBtn) registerBtn.style.display = 'none';
            
            // Remove any existing confirm button
            const existingConfirmBtn = document.querySelector('.confirm-enroll-btn');
            if (existingConfirmBtn) existingConfirmBtn.remove();
            
            // Check if user is logged in
            fetch('check-login.php')
                .then(response => response.json())
                .then(data => {
                    if (data.loggedIn) {
                        // User is logged in - show pending enrollment message
                        modalMessage.innerHTML = `
                            You are about to apply for:<br>
                            <strong>${programName}</strong><br><br>
                            <div style="background: rgba(255,193,7,0.1); padding: 10px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #ffc107;">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Important:</strong> Your application will be submitted for <strong>admin approval</strong>.
                            </div>
                            You'll receive a notification once your application is reviewed.
                        `;
                        
                        // Show confirm enrollment button
                        const confirmBtn = document.createElement('button');
                        confirmBtn.className = 'modal-btn confirm-enroll-btn';
                        confirmBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                        confirmBtn.onclick = submitEnrollment;
                        
                        // Insert before cancel button
                        const cancelBtn = document.querySelector('.close-btn');
                        cancelBtn.parentNode.insertBefore(confirmBtn, cancelBtn);
                        
                    } else {
                        // User not logged in - show login/register options
                        modalMessage.innerHTML = `
                            To apply for "<strong>${programName}</strong>", you need to have an account.<br><br>
                            <div style="background: rgba(32,201,151,0.1); padding: 10px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #20c997;">
                                <i class="fas fa-info-circle"></i> 
                                <small>After registration, your application will be submitted for admin approval.</small>
                            </div>
                        `;
                        
                        // Show login/register buttons
                        if (loginBtn) loginBtn.style.display = 'flex';
                        if (registerBtn) registerBtn.style.display = 'flex';
                        
                        // IMPORTANT: Set session data before redirect
                        // Use AJAX to store in server session
                        fetch('set-pending-program.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                program_id: programId,
                                program_name: programName
                            }),
                            credentials: 'same-origin'
                        }).catch(err => console.error('Error setting pending program:', err));
                    }
                })
                .catch(error => {
                    console.error('Error checking login:', error);
                    modalMessage.innerHTML = `
                        To apply for "<strong>${programName}</strong>", you need to have an account.<br><br>
                        <div style="background: rgba(32,201,151,0.1); padding: 10px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #20c997;">
                            <i class="fas fa-info-circle"></i> 
                            <small>After registration, your application will be submitted for admin approval.</small>
                        </div>
                    `;
                    
                    // Show login/register buttons as fallback
                    if (loginBtn) loginBtn.style.display = 'flex';
                    if (registerBtn) registerBtn.style.display = 'flex';
                });
            
            // Store in sessionStorage as backup
            sessionStorage.setItem('enrollProgramId', programId);
            sessionStorage.setItem('enrollProgramName', programName);
            
            if (enrollModal) {
                enrollModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function submitEnrollment() {
            if (!currentProgramId) return;
            
            // Show loading state
            const confirmBtn = document.querySelector('.confirm-enroll-btn');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            confirmBtn.disabled = true;
            
            // Submit enrollment via AJAX
            fetch('submit-enrollment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    program_id: currentProgramId,
                    program_name: currentProgramName
                }),
                credentials: 'same-origin' // Include session cookies
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Application Submitted!',
                        html: `
                            <div style="text-align: left;">
                                <p>Your application for <strong>${currentProgramName}</strong> has been submitted successfully.</p>
                                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ffc107;">
                                    <i class="fas fa-clock"></i> 
                                    <strong>Status:</strong> <span class="badge" style="background: #ffc107; color: #856404;">Pending Approval</span><br>
                                    <small>An administrator will review your application and you will be notified of the decision.</small>
                                </div>
                                <p><small>You can track your application status from your dashboard.</small></p>
                            </div>
                        `,
                        confirmButtonColor: '#20c997',
                        confirmButtonText: 'OK',
                        timer: 8000,
                        timerProgressBar: true
                    });
                    
                    // Update program slots display
                    updateProgramSlots(currentProgramId);
                    
                    // Close modal
                    closeModal();
                    
                    // Refresh page after 3 seconds to show updated slots
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                } else {
                    // Show error
                    Swal.fire({
                        icon: 'error',
                        title: 'Submission Failed',
                        html: data.message || 'Failed to submit application. Please try again.',
                        confirmButtonColor: '#ef4444'
                    });
                    
                    // Reset button
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Failed to submit application. Please check your connection.',
                    confirmButtonColor: '#ef4444'
                });
                
                // Reset button
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });
        }

        function updateProgramSlots(programId) {
            // Update the slots display for this program
            const programCard = document.querySelector(`[data-program-id="${programId}"]`);
            if (programCard) {
                const slotsCount = programCard.querySelector('.slots-count');
                if (slotsCount) {
                    // Decrease slots by 1 (for visual feedback)
                    const text = slotsCount.textContent;
                    const match = text.match(/(\d+)\s+slots available/);
                    if (match) {
                        const currentSlots = parseInt(match[1]);
                        const newSlots = currentSlots - 1;
                        const totalSlots = programCard.dataset.totalSlots || '?';
                        slotsCount.innerHTML = `<i class="fas fa-users"></i> ${newSlots} slots available out of ${totalSlots}`;
                        
                        // Update progress bar
                        const progressBar = programCard.querySelector('.progress-fill');
                        const progressLabel = programCard.querySelector('.progress-label span:last-child');
                        if (progressBar && progressLabel && totalSlots !== '?') {
                            const total = parseInt(totalSlots);
                            const enrollmentPercentage = Math.round(((total - newSlots) / total) * 100, 1);
                            progressBar.style.width = `${enrollmentPercentage}%`;
                            progressLabel.textContent = `${enrollmentPercentage}%`;
                            
                            // Update progress class
                            progressBar.classList.remove('progress-low', 'progress-medium', 'progress-high');
                            if (enrollmentPercentage >= 90) {
                                progressBar.classList.add('progress-high');
                            } else if (enrollmentPercentage >= 75) {
                                progressBar.classList.add('progress-medium');
                            } else {
                                progressBar.classList.add('progress-low');
                            }
                        }
                    }
                }
            }
        }

        function redirectToLogin(e) {
            e.preventDefault();
            if (currentProgramId) {
                // First ensure session data is set
                fetch('set-pending-program.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        program_id: currentProgramId,
                        program_name: currentProgramName
                    }),
                    credentials: 'same-origin'
                }).then(() => {
                    // Then redirect with URL parameters as backup
                    window.location.href = `login.php?redirect=enroll&program_id=${currentProgramId}&program_name=${encodeURIComponent(currentProgramName)}`;
                }).catch(() => {
                    // If fetch fails, redirect with just URL params
                    window.location.href = `login.php?redirect=enroll&program_id=${currentProgramId}&program_name=${encodeURIComponent(currentProgramName)}`;
                });
            } else {
                window.location.href = 'login.php?redirect=enroll';
            }
        }

        function redirectToRegister(e) {
            e.preventDefault();
            if (currentProgramId) {
                // First ensure session data is set
                fetch('set-pending-program.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        program_id: currentProgramId,
                        program_name: currentProgramName
                    }),
                    credentials: 'same-origin'
                }).then(() => {
                    // Then redirect with URL parameters as backup
                    window.location.href = `register-trainee.php?redirect=enroll&program_id=${currentProgramId}&program_name=${encodeURIComponent(currentProgramName)}`;
                }).catch(() => {
                    // If fetch fails, redirect with just URL params
                    window.location.href = `register-trainee.php?redirect=enroll&program_id=${currentProgramId}&program_name=${encodeURIComponent(currentProgramName)}`;
                });
            } else {
                window.location.href = 'register-trainee.php?redirect=enroll';
            }
        }

        function closeModal() {
            if (enrollModal) {
                enrollModal.classList.remove('active');
                document.body.style.overflow = '';
            }
            currentProgramId = null;
            currentProgramName = '';
            
            // Remove any dynamically added confirm button
            const confirmBtn = document.querySelector('.confirm-enroll-btn');
            if (confirmBtn) confirmBtn.remove();
        }

        // Close modal when clicking outside
        if (enrollModal) {
            enrollModal.addEventListener('click', (e) => {
                if (e.target === enrollModal) {
                    closeModal();
                }
            });
        }

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && enrollModal && enrollModal.classList.contains('active')) {
                closeModal();
            }
        });

        // ==========================================
        // SCROLL TO PROGRAMS
        // ==========================================
        function scrollToPrograms() {
            const programsSection = document.getElementById('programs');
            if (programsSection) {
                programsSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        // Auto-scroll indicator click
        const scrollIndicator = document.querySelector('.scroll-indicator');
        if (scrollIndicator) {
            scrollIndicator.addEventListener('click', scrollToPrograms);
        }

        // ==========================================
        // REAL-TIME PROGRAM VISIBILITY UPDATES (Manila Date Only)
        // ==========================================
        function checkProgramVisibility() {
            const programCards = document.querySelectorAll('.program-card');
            
            // Get Manila date only (without time)
            const manilaDateStr = new Date().toLocaleDateString('en-US', { 
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            
            // Convert to YYYY-MM-DD format for comparison
            const manilaDateParts = manilaDateStr.split('/');
            const manilaDate = `${manilaDateParts[2]}-${manilaDateParts[0].padStart(2,'0')}-${manilaDateParts[1].padStart(2,'0')}`;
            
            // Calculate tomorrow's date
            const tomorrow = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
            tomorrow.setDate(tomorrow.getDate() + 1);
            const tomorrowStr = tomorrow.toISOString().split('T')[0];
            
            let visibleCount = 0;
            
            programCards.forEach(card => {
                const startDate = card.dataset.startDate;
                if (startDate) {
                    try {
                        // OPTION 1: Show programs starting tomorrow and beyond (hide today's and past programs)
                        if (startDate < tomorrowStr) {
                            card.style.display = 'none';
                        } else {
                            card.style.display = 'flex';
                            visibleCount++;
                            
                            // Add special styling for tomorrow's programs
                            const daysElement = card.querySelector('.days-remaining');
                            if (daysElement) {
                                if (startDate === tomorrowStr) {
                                    daysElement.classList.add('days-tomorrow');
                                } else {
                                    daysElement.classList.remove('days-tomorrow');
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Error parsing date:', e);
                    }
                }
            });
            
            // Update no programs message
            updateNoProgramsMessage(visibleCount);
        }

        function updateNoProgramsMessage(visibleCount) {
            const programsGrid = document.querySelector('.programs-grid');
            const container = document.querySelector('.programs-container');
            let noProgramsDiv = document.querySelector('.no-programs');
            
            if (visibleCount === 0) {
                // All cards are hidden, show no programs message
                if (programsGrid) {
                    programsGrid.style.display = 'none';
                }
                
                if (!noProgramsDiv) {
                    // Create no programs message if it doesn't exist
                    const manilaDate = new Date().toLocaleDateString('en-US', { 
                        timeZone: 'Asia/Manila',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    const tomorrow = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowFormatted = tomorrow.toLocaleDateString('en-US', { 
                        timeZone: 'Asia/Manila',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    const newNoPrograms = document.createElement('div');
                    newNoPrograms.className = 'no-programs';
                    newNoPrograms.innerHTML = `
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Programs Available</h3>
                        <p>There are no programs starting from tomorrow onwards at the moment.</p>
                        <p style="margin-top: 1rem; font-size: 0.9rem; color: #20c997;">
                            <i class="fas fa-info-circle"></i> Today's date: ${manilaDate}<br>
                            <i class="fas fa-calendar-day"></i> Showing programs starting from: ${tomorrowFormatted}
                        </p>
                    `;
                    container.appendChild(newNoPrograms);
                }
            } else {
                // Some cards are visible, ensure grid is visible
                if (programsGrid) {
                    programsGrid.style.display = 'grid';
                }
                
                // Remove no programs message if it exists
                if (noProgramsDiv) {
                    noProgramsDiv.remove();
                }
            }
        }

        // ==========================================
        // UPDATE DAYS REMAINING DISPLAY
        // ==========================================
        function updateDaysRemaining() {
            const daysElements = document.querySelectorAll('.days-remaining');
            
            // Get Manila date only
            const today = new Date().toLocaleDateString('en-US', { 
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            
            daysElements.forEach(el => {
                const card = el.closest('.program-card');
                if (card) {
                    const startDate = card.dataset.startDate;
                    if (startDate) {
                        const startDateObj = new Date(startDate);
                        const todayObj = new Date(today);
                        
                        const diffTime = startDateObj - todayObj;
                        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                        
                        if (diffDays > 1) {
                            el.textContent = `${diffDays} days remaining`;
                        } else if (diffDays === 1) {
                            el.textContent = 'Tomorrow';
                        } else if (diffDays === 0) {
                            el.textContent = 'Today';
                        } else {
                            el.textContent = 'Started';
                        }
                    }
                }
            });
        }

        // Run initial checks
        setTimeout(() => {
            checkProgramVisibility();
            updateDaysRemaining();
        }, 100);

        // Run updates every minute (60,000 ms)
        setInterval(() => {
            checkProgramVisibility();
            updateDaysRemaining();
        }, 60000);

        // Also check when page gains focus
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                checkProgramVisibility();
                updateDaysRemaining();
            }
        });

        // ==========================================
        // CHECK FOR PENDING ENROLLMENT AFTER LOGIN/REGISTRATION
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded, checking for pending enrollment...');
            
            // Check if we have a pending program from PHP session
            const hasPendingProgram = <?php echo $has_pending_program ? 'true' : 'false'; ?>;
            const pendingProgramId = <?php echo $pending_program_id ?: 'null'; ?>;
            const pendingProgramName = '<?php echo addslashes($pending_program_name ?: ''); ?>';
            
            console.log('PHP Session - Has pending:', hasPendingProgram, 'ID:', pendingProgramId, 'Name:', pendingProgramName);
            
            if (hasPendingProgram && pendingProgramId && pendingProgramName) {
                console.log('Auto-showing enrollment modal for pending program...');
                
                // Wait a moment for page to load, then show modal
                setTimeout(() => {
                    showEnrollModal(pendingProgramId, pendingProgramName);
                    
                    // Clear the session variables after showing modal
                    fetch('clear-pending.php')
                        .then(response => response.json())
                        .then(data => {
                            console.log('Cleared pending enrollment:', data.success);
                        })
                        .catch(err => console.error('Error clearing pending:', err));
                }, 1000);
            }
            
            // Check if user just enrolled and show message
            const urlParams = new URLSearchParams(window.location.search);
            const enrolledProgram = urlParams.get('enrolled_program');
            
            if (enrolledProgram) {
                // Show SweetAlert message
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Application Submitted!',
                        html: `Your application for <strong>${enrolledProgram}</strong> has been submitted for admin approval.`,
                        confirmButtonColor: '#20c997',
                        timer: 5000,
                        timerProgressBar: true,
                        showConfirmButton: true
                    });
                    
                    // Clean URL
                    const url = new URL(window.location);
                    url.searchParams.delete('enrolled_program');
                    window.history.replaceState({}, '', url);
                    
                    // Remove the alert message from DOM
                    const successMessage = document.getElementById('successMessage');
                    if (successMessage) {
                        setTimeout(() => {
                            successMessage.classList.add('hiding');
                            setTimeout(() => {
                                successMessage.remove();
                            }, 300);
                        }, 4000);
                    }
                }, 500);
            }
            
            // Check for error messages
            const message = urlParams.get('message');
            if (message) {
                setTimeout(() => {
                    let title = 'Notice';
                    let icon = 'info';
                    let text = '';
                    
                    switch(message) {
                        case 'already_enrolled':
                            title = 'Already Applied';
                            text = 'You already have an application for this program.';
                            icon = 'info';
                            break;
                        case 'pending_application':
                            title = 'Pending Application';
                            text = 'You already have a pending application for this program.';
                            icon = 'warning';
                            break;
                        case 'no_slots':
                            title = 'Program Full';
                            text = 'This program has no available slots.';
                            icon = 'warning';
                            break;
                        case 'enrollment_failed':
                            title = 'Application Failed';
                            text = 'Failed to submit application. Please try again.';
                            icon = 'error';
                            break;
                        case 'program_not_found':
                            title = 'Program Not Found';
                            text = 'The program could not be found.';
                            icon = 'error';
                            break;
                        case 'application_submitted':
                            title = 'Success!';
                            text = 'Application submitted successfully!';
                            icon = 'success';
                            break;
                        case 'program_started':
                            title = 'Program Already Started';
                            text = 'This program has already started and is no longer accepting applications.';
                            icon = 'warning';
                            break;
                    }
                    
                    if (text) {
                        Swal.fire({
                            icon: icon,
                            title: title,
                            text: text,
                            confirmButtonColor: '#20c997',
                            timer: 4000,
                            timerProgressBar: true
                        });
                        
                        // Clean URL
                        const url = new URL(window.location);
                        url.searchParams.delete('message');
                        window.history.replaceState({}, '', url);
                        
                        // Remove error message from DOM
                        const errorMessage = document.getElementById('errorMessage');
                        if (errorMessage) {
                            setTimeout(() => {
                                errorMessage.classList.add('hiding');
                                setTimeout(() => {
                                    errorMessage.remove();
                                }, 300);
                            }, 4000);
                        }
                    }
                }, 500);
            }
            
            // Check for program_id in sessionStorage (if user just registered)
            const storedProgramId = sessionStorage.getItem('enrollProgramId');
            const storedProgramName = sessionStorage.getItem('enrollProgramName');
            
            if (storedProgramId && storedProgramName) {
                console.log('Found pending program in sessionStorage:', storedProgramId, storedProgramName);
                
                // Clear storage
                sessionStorage.removeItem('enrollProgramId');
                sessionStorage.removeItem('enrollProgramName');
                
                // Highlight the program card
                setTimeout(() => {
                    const programCard = document.querySelector(`[data-program-id="${storedProgramId}"]`);
                    if (programCard) {
                        programCard.style.borderColor = '#20c997';
                        programCard.style.boxShadow = '0 0 0 2px #20c997, 0 15px 30px rgba(32, 201, 151, 0.3)';
                        
                        // Scroll to program
                        programCard.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        
                        // Reset after 5 seconds
                        setTimeout(() => {
                            programCard.style.borderColor = '';
                            programCard.style.boxShadow = '';
                        }, 5000);
                    }
                }, 1000);
            }
            
            // Auto-remove alert messages after 5 seconds
            const alertMessages = document.querySelectorAll('.alert-message');
            alertMessages.forEach(message => {
                setTimeout(() => {
                    message.classList.add('hiding');
                    setTimeout(() => {
                        message.remove();
                    }, 300);
                }, 5000);
            });
            
            // Debug: Log found programs
            console.log('Found programs:', <?php echo count($programs); ?>);
            console.log('DB Error:', '<?php echo addslashes($db_error); ?>');
        });

        // ==========================================
        // PROGRAM CARD INTERACTIONS
        // ==========================================
        document.querySelectorAll('.program-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                if (!this.classList.contains('no-hover')) {
                    this.style.transform = 'translateY(-10px)';
                }
            });
            
            card.addEventListener('mouseleave', function() {
                if (!this.classList.contains('no-hover')) {
                    this.style.transform = 'translateY(0)';
                }
            });
        });

        // ==========================================
        // ANIMATE PROGRESS BARS WHEN IN VIEW
        // ==========================================
        function animateProgressBars() {
            document.querySelectorAll('.progress-fill').forEach(bar => {
                const computedStyle = getComputedStyle(bar);
                const width = computedStyle.width;
                bar.style.width = '0%';
                
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        }

        // Trigger animation when programs section is in view
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateProgressBars();
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        const programsSection = document.getElementById('programs');
        if (programsSection) {
            observer.observe(programsSection);
        }

        // ==========================================
        // SMOOTH SCROLL FOR ANCHOR LINKS
        // ==========================================
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // ==========================================
        // TEST FUNCTION TO VERIFY DATE-ONLY RULE
        // ==========================================
        function testDateRule() {
            const programCards = document.querySelectorAll('.program-card');
            const today = new Date().toLocaleDateString('en-US', { 
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            
            const tomorrow = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
            tomorrow.setDate(tomorrow.getDate() + 1);
            const tomorrowStr = tomorrow.toISOString().split('T')[0];
            
            console.log('===== PROGRAM VISIBILITY TEST (OPTION 1) =====');
            console.log('Current Manila Date:', today);
            console.log('Showing programs from:', tomorrowStr, '(tomorrow and beyond)');
            console.log('=================================================');
            
            programCards.forEach((card, index) => {
                const startDate = card.dataset.startDate;
                if (startDate) {
                    const isVisible = window.getComputedStyle(card).display !== 'none';
                    const shouldBeVisible = startDate >= tomorrowStr;
                    
                    console.log(`Program ${index + 1}:`);
                    console.log(`  Start Date: ${startDate}`);
                    console.log(`  Should be ${shouldBeVisible ? 'VISIBLE' : 'HIDDEN'} (starts ${shouldBeVisible ? 'tomorrow or later' : 'today or earlier'})`);
                    console.log(`  Currently: ${isVisible ? 'VISIBLE' : 'HIDDEN'}`);
                }
            });
            console.log('=================================================');
        }

        // Run test after 3 seconds (uncomment to use)
        // setTimeout(testDateRule, 3000);
    </script>
</body>
</html>