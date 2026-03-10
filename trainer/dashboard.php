<?php
// ============================================
// ERROR REPORTING & DEBUGGING - CRITICAL
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');

// Increase memory limit for PDF generation
ini_set('memory_limit', '256M');
set_time_limit(300); // 5 minutes for large reports

// Start output buffering
ob_start();

// Debug mode
define('DEBUG_MODE', isset($_GET['debug']) && $_GET['debug'] === '1');

// Debug logging function
function debug_log($message, $data = null) {
    $log = date('[Y-m-d H:i:s] ') . $message;
    if ($data !== null) {
        $log .= " - " . (is_array($data) || is_object($data) ? json_encode($data) : $data);
    }
    error_log($log);
}

debug_log("=== TRAINER DASHBOARD STARTED ===");

// ============================================
// SESSION & TIMEZONE CONFIGURATION
// ============================================
try {
    session_start();
    debug_log("Session started", ['session_id' => session_id(), 'user_id' => $_SESSION['user_id'] ?? 'not_set']);
} catch (Exception $e) {
    debug_log("Session start failed", $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    die('Session initialization failed. Please try again.');
}

// Timezone configuration
date_default_timezone_set('Asia/Manila');
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'Asia/Manila');
}

// Create current date/time
try {
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $today_date = $now->format('Y-m-d');
    $today_display = $now->format('l, F j, Y');
    $current_time = $now->format('h:i:s A');
    
    debug_log("Time initialized", [
        'today_date' => $today_date,
        'today_display' => $today_display,
        'current_time' => $current_time
    ]);
} catch (Exception $e) {
    debug_log("DateTime initialization failed", $e->getMessage());
    $today_date = date('Y-m-d');
    $today_display = date('l, F j, Y');
    $current_time = date('h:i:s A');
}

// ============================================
// AUTHENTICATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    debug_log("User not authenticated", ['session' => $_SESSION]);
    header('Location: /login.php?redirectTo=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if (strtolower($_SESSION['role']) !== 'trainer') {
    debug_log("User not a trainer", ['role' => $_SESSION['role']]);
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$fullname = trim($_SESSION['fullname'] ?? 'Trainer');
$role = $_SESSION['role'];

debug_log("User authenticated", [
    'user_id' => $user_id,
    'fullname' => $fullname,
    'role' => $role
]);

// ============================================
// DATABASE CONNECTION
// ============================================
$conn = null;
try {
    $db_path = __DIR__ . '/../db.php';
    debug_log("Loading database from", $db_path);
    
    if (!file_exists($db_path)) {
        throw new Exception("Database configuration file not found at: " . $db_path);
    }
    
    require_once $db_path;
    
    // Check if $conn is set and is a valid connection
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection not established");
    }
    
    // Test connection with a simple query
    $test_query = $conn->query("SELECT 1");
    if (!$test_query) {
        throw new Exception("Database connection test failed: " . $conn->error);
    }
    
    // Set timezone for database
    $conn->query("SET time_zone = '+08:00'");
    
    debug_log("Database connected successfully");
    
} catch (Exception $e) {
    debug_log("Database connection failed", $e->getMessage());
    
    // Check if this is an AJAX request
    $is_ajax = (isset($_POST['ajax']) || isset($_GET['ajax']));
    
    if (DEBUG_MODE || $is_ajax) {
        if ($is_ajax) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'debug' => DEBUG_MODE ? ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()] : null
        ]);
        exit;
    }
    
    // For normal page load, show error but continue with defaults
    $error_output = ob_get_clean();
    echo '<div style="padding: 20px; background: #fee; border: 2px solid #f00; margin: 20px; border-radius: 8px;">
            <h2 style="color: #c00; margin-top: 0;">Database Connection Error</h2>
            <p>' . htmlspecialchars($e->getMessage()) . '</p>
            <p>The system will continue with limited functionality.</p>
          </div>';
    
    // Continue execution but set $conn to null
    $conn = null;
}

// ============================================
// GET TRAINER LOCATION SETTINGS
// ============================================
$trainer_location = [
    'pin_latitude' => 14.782043, // Default from your data
    'pin_longitude' => 120.878094, // Default from your data
    'pin_radius' => 43, // Default from your data (LEMS ADMIN radius)
    'pin_location_name' => 'LEMS ADMIN'
];

if ($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT pin_latitude, pin_longitude, pin_radius, pin_location_name 
            FROM users 
            WHERE id = ? AND role = 'trainer'
        ");
        
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Only override if values are not null
                if (!is_null($row['pin_latitude'])) {
                    $trainer_location['pin_latitude'] = floatval($row['pin_latitude']);
                }
                if (!is_null($row['pin_longitude'])) {
                    $trainer_location['pin_longitude'] = floatval($row['pin_longitude']);
                }
                if (!is_null($row['pin_radius'])) {
                    $trainer_location['pin_radius'] = intval($row['pin_radius']);
                }
                if (!is_null($row['pin_location_name'])) {
                    $trainer_location['pin_location_name'] = $row['pin_location_name'];
                }
            }
        }
        
        debug_log("Trainer location settings loaded", $trainer_location);
        
    } catch (Exception $e) {
        debug_log("Error loading trainer location", $e->getMessage());
    }
}

// ============================================
// DEBUG MODE OUTPUT
// ============================================
if (DEBUG_MODE) {
    header('Content-Type: text/plain');
    echo "=== DEBUG MODE ===\n\n";
    echo "PHP Version: " . phpversion() . "\n";
    echo "Memory Limit: " . ini_get('memory_limit') . "\n";
    echo "Max Execution Time: " . ini_get('max_execution_time') . "s\n";
    echo "Session ID: " . session_id() . "\n";
    echo "User ID: " . $user_id . "\n";
    echo "Full Name: " . $fullname . "\n";
    echo "Today: " . $today_date . "\n\n";
    echo "Trainer Location:\n";
    echo "  Latitude: " . $trainer_location['pin_latitude'] . "\n";
    echo "  Longitude: " . $trainer_location['pin_longitude'] . "\n";
    echo "  Radius: " . $trainer_location['pin_radius'] . " meters\n";
    echo "  Location Name: " . $trainer_location['pin_location_name'] . "\n\n";
    
    // Test database if connected
    if ($conn) {
        $result = $conn->query("SELECT 1 as test");
        echo "Database Test: " . ($result ? "OK" : "FAILED: " . $conn->error) . "\n";
        
        // Check tables exist
        $tables = ['trainer_attendance', 'programs', 'enrollments'];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            echo "Table '$table' exists: " . ($result && $result->num_rows > 0 ? "Yes" : "No") . "\n";
        }
        
        // Check TCPDF
        echo "\nTCPDF Check:\n";
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            echo "  Composer autoload: Found\n";
            require_once $autoload;
            if (class_exists('TCPDF')) {
                echo "  TCPDF class: Available\n";
            } else {
                echo "  TCPDF class: Not found\n";
            }
        } else {
            echo "  Composer autoload: Not found\n";
        }
    } else {
        echo "Database: Not connected\n";
    }
    
    exit;
}

// ============================================
// CONSTANTS & CONFIGURATION - USING TRAINER LOCATION
// ============================================
define('CENTER_LAT', $trainer_location['pin_latitude']);
define('CENTER_LNG', $trainer_location['pin_longitude']);
define('MAX_DISTANCE_METERS', $trainer_location['pin_radius']);
define('LOCATION_NAME', $trainer_location['pin_location_name']);

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Calculate distance using Haversine formula
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meters
    
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);
    
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Check if location is within allowed distance
 */
function isWithinDistance($lat, $lng) {
    $distance = calculateDistance(CENTER_LAT, CENTER_LNG, $lat, $lng);
    return [
        'within_range' => $distance <= MAX_DISTANCE_METERS,
        'distance' => round($distance, 2),
        'center_lat' => CENTER_LAT,
        'center_lng' => CENTER_LNG,
        'max_distance' => MAX_DISTANCE_METERS,
        'location_name' => LOCATION_NAME
    ];
}

// ============================================
// LOGOUT HANDLING
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    debug_log("User logging out", ['user_id' => $user_id]);
    session_destroy();
    header('Location: /login.php');
    exit;
}

// ============================================
// PDF DOWNLOAD HANDLING - FIXED VERSION
// ============================================
if (isset($_GET['download_pdf']) && $_GET['download_pdf'] === '1') {
    debug_log("PDF download requested", $_GET);
    
    try {
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $trainer_name = $fullname;
        
        debug_log("Generating PDF", [
            'trainer_name' => $trainer_name,
            'user_id' => $user_id,
            'year' => $year,
            'month' => $month
        ]);
        
        // Clear any output buffers before PDF generation
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Try to find TCPDF
        $tcpdfFound = false;
        
        // Try Composer autoload first
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('TCPDF')) {
                $tcpdfFound = true;
                debug_log("TCPDF loaded via Composer autoload");
            }
        }
        
        // If not found, try direct paths
        if (!$tcpdfFound) {
            $tcpdfPaths = [
                __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
                __DIR__ . '/tcpdf/tcpdf.php',
                __DIR__ . '/../tcpdf/tcpdf.php',
                '/usr/share/php/tcpdf/tcpdf.php'
            ];
            
            foreach ($tcpdfPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    if (class_exists('TCPDF')) {
                        $tcpdfFound = true;
                        debug_log("TCPDF loaded from", $path);
                        break;
                    }
                }
            }
        }
        
        if (!$tcpdfFound) {
            throw new Exception('TCPDF library not found. Please install TCPDF using: composer require tecnickcom/tcpdf');
        }
        
        // Generate the PDF
        generateTrainerAttendancePDF($conn, $trainer_name, $user_id, $year, $month);
        exit;
        
    } catch (Exception $e) {
        debug_log("PDF generation failed", $e->getMessage());
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html');
        echo '<!DOCTYPE html>
<html>
<head>
    <title>PDF Generation Error</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .error-container { max-width: 800px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .error-header { background: #dc2626; color: white; padding: 20px; }
        .error-header h1 { margin: 0; font-size: 24px; }
        .error-body { padding: 30px; }
        .error-message { background: #fee; border-left: 4px solid #dc2626; padding: 15px; margin-bottom: 20px; }
        .error-message p { margin: 0; color: #991b1b; font-size: 16px; }
        .debug-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .debug-info h3 { margin-top: 0; color: #1f2937; }
        .debug-info ul { list-style: none; padding: 0; margin: 0; }
        .debug-info li { padding: 8px 0; border-bottom: 1px solid #e5e7eb; }
        .debug-info li:last-child { border-bottom: none; }
        .install-section { background: #e8f4fd; padding: 20px; border-radius: 8px; }
        .install-section h3 { margin-top: 0; color: #0369a1; }
        code { background: #1f2937; color: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 14px; }
        pre { background: #1f2937; color: #e5e7eb; padding: 15px; border-radius: 8px; overflow-x: auto; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .btn { display: inline-block; padding: 10px 20px; background: #4A90E2; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
        .btn:hover { background: #357ABD; text-decoration: none; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <h1><i class="fas fa-file-pdf"></i> PDF Generation Failed</h1>
        </div>
        <div class="error-body">
            <div class="error-message">
                <p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            </div>
            
            <div class="debug-info">
                <h3>📋 Debug Information</h3>
                <ul>
                    <li><strong>PHP Version:</strong> ' . phpversion() . '</li>
                    <li><strong>Memory Limit:</strong> ' . ini_get('memory_limit') . '</li>
                    <li><strong>User ID:</strong> ' . $user_id . '</li>
                    <li><strong>Trainer Name:</strong> ' . htmlspecialchars($trainer_name) . '</li>
                    <li><strong>Year/Month:</strong> ' . $year . '/' . $month . '</li>
                    <li><strong>Database Connected:</strong> ' . ($conn ? 'Yes' : 'No') . '</li>
                    <li><strong>Composer Autoload:</strong> ' . (file_exists(__DIR__ . '/../vendor/autoload.php') ? 'Found' : 'Not Found') . '</li>
                    <li><strong>TCPDF Class:</strong> ' . (class_exists('TCPDF') ? 'Available' : 'Not Available') . '</li>
                </ul>
            </div>
            
            <div class="install-section">
                <h3>🔧 Installation Instructions</h3>
                <p>To fix this issue, install TCPDF using Composer:</p>
                <pre>cd ' . dirname(__DIR__) . '
composer require tecnickcom/tcpdf</pre>
                
                <p>Or if you don\'t have Composer installed, download manually:</p>
                <ol>
                    <li>Download TCPDF from: <a href="https://github.com/tecnickcom/tcpdf" target="_blank">https://github.com/tecnickcom/tcpdf</a></li>
                    <li>Extract the contents to <code>' . __DIR__ . '/tcpdf/</code></li>
                    <li>Make sure the file <code>tcpdf.php</code> exists in that directory</li>
                </ol>
                
                <a href="?download_pdf=1&year=' . $year . '&month=' . $month . '" class="btn">
                    <i class="fas fa-redo"></i> Try Again
                </a>
            </div>
        </div>
    </div>
</body>
</html>';
        exit;
    }
}

// ============================================
// DATABASE FUNCTIONS
// ============================================

/**
 * Get trainer's assigned program
 */
function getTrainerProgram($conn, $user_id, $trainer_name) {
    if (!$conn) return null;
    
    debug_log("Getting trainer program", [
        'user_id' => $user_id,
        'trainer_name' => $trainer_name
    ]);
    
    try {
        $stmt = $conn->prepare("
            SELECT p.*, 
                   COUNT(DISTINCT e.user_id) as total_trainees
            FROM programs p
            LEFT JOIN enrollments e ON p.id = e.program_id 
                AND e.enrollment_status = 'approved'
            WHERE p.trainer = ? AND p.archived = 0
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $trainer_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $program = $result->fetch_assoc();
        
        if ($program) {
            $program['scheduleStart'] = date('Y-m-d', strtotime($program['scheduleStart']));
            $program['scheduleEnd'] = date('Y-m-d', strtotime($program['scheduleEnd']));
        }
        
        debug_log("Trainer program result", $program ?: 'No program found');
        return $program;
        
    } catch (Exception $e) {
        debug_log("getTrainerProgram error", $e->getMessage());
        return null;
    }
}

/**
 * Record trainer attendance
 */
function recordTrainerAttendance($conn, $user_id, $trainer_name, $action, $lat, $lng) {
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection not available'];
    }
    
    debug_log("Recording attendance", [
        'user_id' => $user_id,
        'trainer_name' => $trainer_name,
        'action' => $action,
        'lat' => $lat,
        'lng' => $lng
    ]);
    
    try {
        $manilaTz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $manilaTz);
        $today = $now->format('Y-m-d');
        $currentTime = $now->format('h:i A');
        
        // Calculate distance using constants (now set from trainer's location)
        $distance = calculateDistance($lat, $lng, CENTER_LAT, CENTER_LNG);
        
        // Distance check
        if ($distance > MAX_DISTANCE_METERS) {
            debug_log("Distance check failed", [
                'distance' => $distance,
                'max_distance' => MAX_DISTANCE_METERS
            ]);
            return [
                'success' => false, 
                'message' => 'You are too far from the training location!',
                'distance' => round($distance, 2),
                'required_distance' => MAX_DISTANCE_METERS,
                'location_error' => true,
                'location_name' => LOCATION_NAME
            ];
        }
        
        $conn->query("SET time_zone = '+08:00'");
        
        if ($action === 'time_in') {
            // Check if already timed in today
            $check_stmt = $conn->prepare("
                SELECT id, attendance_time 
                FROM trainer_attendance 
                WHERE DATE(attendance_time) = ? 
                AND attendance_type = 'Time In' 
                AND user_id = ?
                ORDER BY attendance_time DESC
                LIMIT 1
            ");
            
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $check_stmt->bind_param("si", $today, $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                $existing_time = new DateTime($existing['attendance_time']);
                $existing_time->setTimezone($manilaTz);
                return [
                    'success' => false, 
                    'message' => 'Already timed in today at ' . $existing_time->format('h:i A')
                ];
            }

            // Insert time in
            $stmt = $conn->prepare("
                INSERT INTO trainer_attendance 
                (user_id, trainer_name, attendance_type, attendance_time, latitude, longitude, distance_from_office) 
                VALUES (?, ?, 'Time In', NOW(), ?, ?, ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $distance_decimal = round($distance, 2);
            $stmt->bind_param("isddd", $user_id, $trainer_name, $lat, $lng, $distance_decimal);
            
            if ($stmt->execute()) {
                debug_log("Time in recorded successfully");
                return [
                    'success' => true, 
                    'message' => 'Time in recorded successfully at ' . $currentTime,
                    'time' => $currentTime,
                    'distance' => $distance_decimal,
                    'location_name' => LOCATION_NAME
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } else if ($action === 'time_out') {
            // Check if timed in today
            $check_stmt = $conn->prepare("
                SELECT id, attendance_time 
                FROM trainer_attendance 
                WHERE DATE(attendance_time) = ? 
                AND attendance_type = 'Time In' 
                AND user_id = ?
                ORDER BY attendance_time DESC
                LIMIT 1
            ");
            
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $check_stmt->bind_param("si", $today, $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows === 0) {
                return [
                    'success' => false, 
                    'message' => 'No time in record found for today. Please time in first.'
                ];
            }
            
            // Check if already timed out today
            $check_out_stmt = $conn->prepare("
                SELECT id, attendance_time 
                FROM trainer_attendance 
                WHERE DATE(attendance_time) = ? 
                AND attendance_type = 'Time Out' 
                AND user_id = ?
                ORDER BY attendance_time DESC
                LIMIT 1
            ");
            
            if (!$check_out_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $check_out_stmt->bind_param("si", $today, $user_id);
            $check_out_stmt->execute();
            $out_result = $check_out_stmt->get_result();
            
            if ($out_result->num_rows > 0) {
                $existing = $out_result->fetch_assoc();
                $existing_time = new DateTime($existing['attendance_time']);
                $existing_time->setTimezone($manilaTz);
                return [
                    'success' => false, 
                    'message' => 'Already timed out today at ' . $existing_time->format('h:i A')
                ];
            }
            
            // Get time in record
            $time_in_data = $result->fetch_assoc();
            $time_in_obj = new DateTime($time_in_data['attendance_time']);
            
            // Insert time out
            $stmt = $conn->prepare("
                INSERT INTO trainer_attendance 
                (user_id, trainer_name, attendance_type, attendance_time, latitude, longitude, distance_from_office) 
                VALUES (?, ?, 'Time Out', NOW(), ?, ?, ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $distance_decimal = round($distance, 2);
            $stmt->bind_param("isddd", $user_id, $trainer_name, $lat, $lng, $distance_decimal);
            
            if ($stmt->execute()) {
                // Calculate duration
                $time_out_obj = new DateTime('now', $manilaTz);
                $duration = $time_in_obj->diff($time_out_obj);
                $hours = ($duration->days * 24) + $duration->h;
                $duration_str = sprintf('%d h %02d m', $hours, $duration->i);
                
                debug_log("Time out recorded successfully");
                return [
                    'success' => true, 
                    'message' => 'Time out recorded successfully at ' . $currentTime,
                    'time' => $currentTime,
                    'duration' => $duration_str,
                    'distance' => $distance_decimal,
                    'location_name' => LOCATION_NAME
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        }
        
        return ['success' => false, 'message' => 'Invalid action'];
        
    } catch (Exception $e) {
        debug_log("recordTrainerAttendance error", $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get today's attendance status
 */
function getTodayAttendanceStatus($conn, $user_id) {
    if (!$conn) {
        return ['status' => 'error', 'message' => 'Database connection not available'];
    }
    
    debug_log("Getting today's attendance status", ['user_id' => $user_id]);
    
    try {
        $manilaTz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $manilaTz);
        $today = $now->format('Y-m-d');
        
        // Get all attendance records for today
        $stmt = $conn->prepare("
            SELECT * FROM trainer_attendance 
            WHERE DATE(attendance_time) = ? 
            AND user_id = ?
            ORDER BY attendance_time ASC
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("si", $today, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $time_in = null;
        $time_out = null;
        $time_in_record = null;
        $time_out_record = null;
        
        while ($row = $result->fetch_assoc()) {
            if ($row['attendance_type'] === 'Time In') {
                $time_in = new DateTime($row['attendance_time']);
                $time_in->setTimezone($manilaTz);
                $time_in_record = $row;
            } else if ($row['attendance_type'] === 'Time Out') {
                $time_out = new DateTime($row['attendance_time']);
                $time_out->setTimezone($manilaTz);
                $time_out_record = $row;
            }
        }
        
        if ($time_in && $time_out) {
            $interval = $time_in->diff($time_out);
            $hours = ($interval->days * 24) + $interval->h;
            $duration = sprintf('%d h %02d m', $hours, $interval->i);
            
            return [
                'status' => 'completed',
                'time_in' => $time_in->format('h:i A'),
                'time_out' => $time_out->format('h:i A'),
                'duration' => $duration,
                'time_in_record' => $time_in_record,
                'time_out_record' => $time_out_record
            ];
        } else if ($time_in) {
            return [
                'status' => 'timed_in',
                'time_in' => $time_in->format('h:i A'),
                'time_in_record' => $time_in_record
            ];
        } else {
            return [
                'status' => 'not_timed_in'
            ];
        }
        
    } catch (Exception $e) {
        debug_log("getTodayAttendanceStatus error", $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get trainer attendance records for date range
 */
function getTrainerAttendanceRecords($conn, $user_id, $start_date, $end_date) {
    if (!$conn) {
        return ['records' => [], 'total_days' => 0, 'error' => 'Database connection not available'];
    }
    
    debug_log("Getting attendance records", [
        'user_id' => $user_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    
    try {
        $manilaTz = new DateTimeZone('Asia/Manila');
        
        // Get all distinct dates with attendance
        $stmt = $conn->prepare("
            SELECT DATE(attendance_time) as attendance_date,
                   GROUP_CONCAT(
                       CONCAT(
                           attendance_type, '|',
                           TIME(attendance_time), '|',
                           COALESCE(latitude, ''), '|',
                           COALESCE(longitude, ''), '|',
                           COALESCE(distance_from_office, '')
                       ) SEPARATOR '||'
                   ) as attendance_data
            FROM trainer_attendance 
            WHERE DATE(attendance_time) BETWEEN ? AND ?
            AND user_id = ?
            GROUP BY DATE(attendance_time)
            ORDER BY attendance_date DESC
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ssi", $start_date, $end_date, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $records = [];
        $total_days = 0;
        
        // Generate all dates in range
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($current, $interval, $end->modify('+1 day'));
        
        $attendance_by_date = [];
        while ($row = $result->fetch_assoc()) {
            $attendance_by_date[$row['attendance_date']] = $row['attendance_data'];
        }
        
        foreach ($date_range as $date) {
            $date_str = $date->format('Y-m-d');
            $total_days++;
            
            $record = [
                'date' => $date_str,
                'day_name' => $date->format('l'),
                'time_in' => null,
                'time_out' => null,
                'duration' => null,
                'time_in_data' => null,
                'time_out_data' => null,
                'status' => 'incomplete'
            ];
            
            if (isset($attendance_by_date[$date_str])) {
                $entries = explode('||', $attendance_by_date[$date_str]);
                $time_in_obj = null;
                $time_out_obj = null;
                
                foreach ($entries as $entry) {
                    $parts = explode('|', $entry);
                    if (count($parts) >= 2) {
                        $type = $parts[0];
                        $time = $parts[1];
                        $lat = $parts[2] ?? '';
                        $lng = $parts[3] ?? '';
                        $distance = $parts[4] ?? '';
                        
                        if ($type === 'Time In') {
                            $record['time_in'] = date('h:i A', strtotime($time));
                            $record['time_in_data'] = [
                                'latitude' => $lat,
                                'longitude' => $lng,
                                'distance_from_office' => floatval($distance)
                            ];
                            $time_in_obj = new DateTime($date_str . ' ' . $time, $manilaTz);
                        } else if ($type === 'Time Out') {
                            $record['time_out'] = date('h:i A', strtotime($time));
                            $record['time_out_data'] = [
                                'latitude' => $lat,
                                'longitude' => $lng,
                                'distance_from_office' => floatval($distance)
                            ];
                            $time_out_obj = new DateTime($date_str . ' ' . $time, $manilaTz);
                        }
                    }
                }
                
                if ($time_in_obj && $time_out_obj) {
                    $interval = $time_in_obj->diff($time_out_obj);
                    $hours = ($interval->days * 24) + $interval->h;
                    $record['duration'] = sprintf('%d h %02d m', $hours, $interval->i);
                    $record['status'] = 'completed';
                } else if ($time_in_obj) {
                    $record['status'] = 'timed_in_only';
                }
            }
            
            $records[] = $record;
        }
        
        debug_log("Attendance records retrieved", [
            'total_days' => $total_days,
            'records_count' => count($records)
        ]);
        
        return [
            'records' => $records,
            'total_days' => $total_days
        ];
        
    } catch (Exception $e) {
        debug_log("getTrainerAttendanceRecords error", $e->getMessage());
        return [
            'records' => [],
            'total_days' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Generate trainer attendance PDF - FIXED VERSION
 */
function generateTrainerAttendancePDF($conn, $trainer_name, $user_id, $year, $month) {
    if (!$conn) {
        throw new Exception('Database connection not available');
    }
    
    debug_log("Generating PDF", [
        'trainer_name' => $trainer_name,
        'user_id' => $user_id,
        'year' => $year,
        'month' => $month
    ]);
    
    try {
        // Get trainer program info - FIXED: Check column names
        $program_stmt = $conn->prepare("
            SELECT p.*, 
                   (SELECT COUNT(*) FROM enrollments e 
                    WHERE e.program_id = p.id 
                    AND e.enrollment_status = 'approved') as total_trainees
            FROM programs p
            WHERE p.trainer = ? AND p.archived = 0
            ORDER BY p.created_at DESC
            LIMIT 1
        ");
        
        if (!$program_stmt) {
            throw new Exception("Program query prepare failed: " . $conn->error);
        }
        
        $program_stmt->bind_param("s", $trainer_name);
        $program_stmt->execute();
        $program_result = $program_stmt->get_result();
        $program = $program_result->fetch_assoc();
        
        // Get attendance records for the month
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $attendance_stmt = $conn->prepare("
            SELECT * FROM trainer_attendance 
            WHERE DATE(attendance_time) BETWEEN ? AND ?
            AND user_id = ?
            ORDER BY attendance_time ASC
        ");
        
        if (!$attendance_stmt) {
            throw new Exception("Attendance query prepare failed: " . $conn->error);
        }
        
        $attendance_stmt->bind_param("ssi", $start_date, $end_date, $user_id);
        $attendance_stmt->execute();
        $attendance_result = $attendance_stmt->get_result();
        
        $attendance_data = [];
        while ($row = $attendance_result->fetch_assoc()) {
            $date = date('Y-m-d', strtotime($row['attendance_time']));
            if (!isset($attendance_data[$date])) {
                $attendance_data[$date] = [];
            }
            $attendance_data[$date][] = $row;
        }
        
        // Check if TCPDF class exists
        if (!class_exists('TCPDF')) {
            throw new Exception('TCPDF class not found. Please install TCPDF library.');
        }
        
        // Create PDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('LEMS');
        $pdf->SetAuthor('LEMS System');
        $pdf->SetTitle("Trainer Attendance Report - $trainer_name");
        $pdf->SetSubject("Attendance Report for " . date('F Y', strtotime($start_date)));
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', '', 12);
        
        // Logo and Header
        $logo_path = __DIR__ . '/../css/logo.png';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 15, 10, 30, 30, 'PNG');
        }
        
        // Title
        $pdf->SetY(20);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->Cell(0, 10, 'Livelihood Enrollment & Monitoring System', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Trainer Attendance Report', 0, 1, 'C');
        
        // Trainer Info
        $pdf->SetY(50);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 7, 'Trainer Name:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 7, $trainer_name, 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 7, 'Report Period:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 7, date('F Y', strtotime($start_date)), 0, 1);
        
        if ($program) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(40, 7, 'Program:', 0, 0);
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 7, $program['program_name'] ?? 'N/A', 0, 1);
            
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(40, 7, 'Total Trainees:', 0, 0);
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 7, $program['total_trainees'] ?? '0', 0, 1);
        }
        
        // Location Info
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 7, 'Location:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 7, LOCATION_NAME, 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 7, 'Allowed Radius:', 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 7, MAX_DISTANCE_METERS . ' meters', 0, 1);
        
        // Attendance Table
        $pdf->Ln(10);
        
        // Table header
        $pdf->SetFillColor(74, 144, 226); // #4A90E2
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        
        $pdf->Cell(30, 10, 'Date', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'Day', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'Time In', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'Time Out', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'Duration', 1, 0, 'C', true);
        $pdf->Cell(35, 10, 'Distance (m)', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        
        $total_days = 0;
        $completed_days = 0;
        
        // Generate all dates in month
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($current, $interval, $end->modify('+1 day'));
        
        foreach ($date_range as $date) {
            $date_str = $date->format('Y-m-d');
            $day_name = $date->format('D');
            
            $time_in = '-';
            $time_out = '-';
            $duration = '-';
            $distance = '-';
            
            if (isset($attendance_data[$date_str])) {
                $time_in_obj = null;
                $time_out_obj = null;
                $distances = [];
                
                foreach ($attendance_data[$date_str] as $record) {
                    if ($record['attendance_type'] === 'Time In') {
                        $time_in = date('h:i A', strtotime($record['attendance_time']));
                        $time_in_obj = new DateTime($record['attendance_time']);
                        if (isset($record['distance_from_office'])) {
                            $distances[] = $record['distance_from_office'];
                        }
                    } else if ($record['attendance_type'] === 'Time Out') {
                        $time_out = date('h:i A', strtotime($record['attendance_time']));
                        $time_out_obj = new DateTime($record['attendance_time']);
                        if (isset($record['distance_from_office'])) {
                            $distances[] = $record['distance_from_office'];
                        }
                    }
                }
                
                if ($time_in_obj && $time_out_obj) {
                    $diff = $time_in_obj->diff($time_out_obj);
                    $hours = ($diff->days * 24) + $diff->h;
                    $duration = sprintf('%d:%02d', $hours, $diff->i);
                    $completed_days++;
                }
                
                if (!empty($distances)) {
                    $avg_distance = array_sum($distances) / count($distances);
                    $distance = number_format($avg_distance, 1);
                }
                
                $total_days++;
            }
            
            $pdf->Cell(30, 8, date('M d, Y', strtotime($date_str)), 1, 0, 'L');
            $pdf->Cell(25, 8, $day_name, 1, 0, 'L');
            $pdf->Cell(30, 8, $time_in, 1, 0, 'C');
            $pdf->Cell(30, 8, $time_out, 1, 0, 'C');
            $pdf->Cell(30, 8, $duration, 1, 0, 'C');
            $pdf->Cell(35, 8, $distance, 1, 1, 'C');
        }
        
        // Summary
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Summary:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Total Days in Month: ' . $total_days, 0, 1);
        $pdf->Cell(0, 6, 'Days with Complete Attendance: ' . $completed_days, 0, 1);
        $pdf->Cell(0, 6, 'Completion Rate: ' . ($total_days > 0 ? round(($completed_days / $total_days) * 100, 1) : 0) . '%', 0, 1);
        
        // Footer with generation info
        $pdf->SetY(-30);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, 'Generated on: ' . date('F j, Y h:i A'), 0, 1, 'C');
        $pdf->Cell(0, 5, 'Livelihood Enrollment & Monitoring System', 0, 1, 'C');
        
        // Output PDF
        $filename = "Trainer_Attendance_{$trainer_name}_" . date('F_Y', strtotime($start_date)) . ".pdf";
        $pdf->Output($filename, 'D');
        
        debug_log("PDF generated successfully", ['filename' => $filename]);
        exit;
        
    } catch (Exception $e) {
        debug_log("generateTrainerAttendancePDF error", $e->getMessage());
        throw $e;
    }
}

/**
 * Debug trainer data
 */
function debugTrainerData($conn, $user_id, $trainer_name) {
    debug_log("Running debug check", [
        'user_id' => $user_id,
        'trainer_name' => $trainer_name
    ]);
    
    $debug = [
        'user_id' => $user_id,
        'trainer_name' => $trainer_name,
        'attendance_records' => 0,
        'program_records' => 0,
        'tables_exist' => [],
        'attendance_sample' => [],
        'program_sample' => []
    ];
    
    if (!$conn) {
        $debug['database_error'] = 'Database connection not available';
        return $debug;
    }
    
    try {
        // Check tables exist
        $tables = ['trainer_attendance', 'programs', 'enrollments'];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $debug['tables_exist'][$table] = $result && $result->num_rows > 0;
        }
        
        // Count attendance records
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM trainer_attendance WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $debug['attendance_records'] = $row['count'];
            
            // Get sample records
            $sample_stmt = $conn->prepare("
                SELECT attendance_type, attendance_time, trainer_name 
                FROM trainer_attendance 
                WHERE user_id = ? 
                ORDER BY attendance_time DESC 
                LIMIT 3
            ");
            if ($sample_stmt) {
                $sample_stmt->bind_param("i", $user_id);
                $sample_stmt->execute();
                $sample_result = $sample_stmt->get_result();
                while ($row = $sample_result->fetch_assoc()) {
                    $debug['attendance_sample'][] = $row;
                }
            }
            
            // Get latest attendance
            $latest_stmt = $conn->prepare("
                SELECT attendance_type, attendance_time 
                FROM trainer_attendance 
                WHERE user_id = ? 
                ORDER BY attendance_time DESC 
                LIMIT 1
            ");
            if ($latest_stmt) {
                $latest_stmt->bind_param("i", $user_id);
                $latest_stmt->execute();
                $latest_result = $latest_stmt->get_result();
                if ($row = $latest_result->fetch_assoc()) {
                    $debug['attendance_latest'] = $row['attendance_type'] . ' at ' . $row['attendance_time'];
                }
            }
        }
        
        // Count program records
        $prog_stmt = $conn->prepare("SELECT COUNT(*) as count FROM programs WHERE trainer = ?");
        if ($prog_stmt) {
            $prog_stmt->bind_param("s", $trainer_name);
            $prog_stmt->execute();
            $result = $prog_stmt->get_result();
            $row = $result->fetch_assoc();
            $debug['program_records'] = $row['count'];
            
            // Get sample programs
            $sample_prog_stmt = $conn->prepare("
                SELECT program_name, trainer 
                FROM programs 
                WHERE trainer = ? 
                LIMIT 3
            ");
            if ($sample_prog_stmt) {
                $sample_prog_stmt->bind_param("s", $trainer_name);
                $sample_prog_stmt->execute();
                $sample_result = $sample_prog_stmt->get_result();
                while ($row = $sample_result->fetch_assoc()) {
                    $debug['program_sample'][] = $row;
                }
            }
        }
        
    } catch (Exception $e) {
        $debug['database_error'] = $e->getMessage();
    }
    
    debug_log("Debug data collected", $debug);
    return $debug;
}

// ============================================
// AJAX HANDLING
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    debug_log("AJAX request received", $_POST);
    
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'check_location':
                $lat = floatval($_POST['lat']);
                $lng = floatval($_POST['lng']);
                $locationCheck = isWithinDistance($lat, $lng);
                echo json_encode([
                    'success' => true,
                    'within_range' => $locationCheck['within_range'],
                    'distance' => $locationCheck['distance'],
                    'center_lat' => CENTER_LAT,
                    'center_lng' => CENTER_LNG,
                    'max_distance' => MAX_DISTANCE_METERS,
                    'location_name' => LOCATION_NAME
                ]);
                break;
                
            case 'trainer_attendance':
                $attendance_action = $_POST['attendance_action'];
                $lat = floatval($_POST['lat']);
                $lng = floatval($_POST['lng']);
                
                $result = recordTrainerAttendance($conn, $user_id, $fullname, $attendance_action, $lat, $lng);
                echo json_encode($result);
                break;
                
            case 'filter_attendance':
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;
                
                if ($start_date && $end_date) {
                    $attendance_data = getTrainerAttendanceRecords($conn, $user_id, $start_date, $end_date);
                    echo json_encode([
                        'success' => true,
                        'records' => $attendance_data['records'],
                        'total_days' => $attendance_data['total_days'],
                        'start_date' => $start_date,
                        'end_date' => $end_date
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Please select both start and end dates']);
                }
                break;
                
            case 'get_today_status':
                $status = getTodayAttendanceStatus($conn, $user_id);
                echo json_encode([
                    'success' => true,
                    'status' => $status['status'],
                    'time_in' => $status['time_in'] ?? null,
                    'time_out' => $status['time_out'] ?? null,
                    'duration' => $status['duration'] ?? null
                ]);
                break;
                
            case 'debug_data':
                $debug_info = debugTrainerData($conn, $user_id, $fullname);
                echo json_encode([
                    'success' => true,
                    'debug_info' => $debug_info
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
        
    } catch (Exception $e) {
        debug_log("AJAX error", $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage(),
            'debug' => DEBUG_MODE ? ['trace' => $e->getTraceAsString()] : null
        ]);
    }
    
    exit;
}

// ============================================
// PAGE DATA PREPARATION
// ============================================
debug_log("Preparing page data");

try {
    // Get debug info
    $debug_info = debugTrainerData($conn, $user_id, $fullname);
    
    // Get trainer program
    $trainer_program = getTrainerProgram($conn, $user_id, $fullname);
    $program_id = $trainer_program['id'] ?? 0;
    
    // Get today's attendance status
    $attendance_status = getTodayAttendanceStatus($conn, $user_id);
    
    // Get date filter parameters
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // Ensure dates are properly formatted
    $start_date = date('Y-m-d', strtotime($start_date));
    $end_date = date('Y-m-d', strtotime($end_date));
    
    // Get attendance records
    $attendance_data = getTrainerAttendanceRecords($conn, $user_id, $start_date, $end_date);
    $attendance_records = $attendance_data['records'];
    $total_days = $attendance_data['total_days'];
    
    // Calculate statistics
    $completed_days = array_filter($attendance_records, function($record) {
        return $record['status'] === 'completed';
    });
    $completed_count = count($completed_days);
    
    $incomplete_days = array_filter($attendance_records, function($record) {
        return $record['status'] === 'incomplete' || $record['status'] === 'timed_in_only';
    });
    $incomplete_count = count($incomplete_days);
    $completion_rate = $total_days > 0 ? round(($completed_count / $total_days) * 100, 2) : 0;
    
    debug_log("Page data prepared successfully", [
        'attendance_records_count' => count($attendance_records),
        'total_days' => $total_days,
        'completed_count' => $completed_count,
        'location' => LOCATION_NAME,
        'radius' => MAX_DISTANCE_METERS . 'm'
    ]);
    
} catch (Exception $e) {
    debug_log("Page data preparation failed", $e->getMessage());
    // Set default values to prevent fatal errors
    $attendance_records = [];
    $total_days = 0;
    $completed_count = 0;
    $incomplete_count = 0;
    $completion_rate = 0;
    $debug_info = [
        'error' => $e->getMessage(),
        'attendance_records' => 0
    ];
}

// ============================================
// HTML OUTPUT (CONTINUED)
// ============================================
// Get buffered content and clear buffer
$error_output = ob_get_clean();
if (!empty($error_output)) {
    debug_log("Output buffer contained errors", $error_output);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Dashboard - Livelihood Enrollment & Monitoring System</title>
    
    <!-- External Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <style>
        /* Your existing styles remain exactly the same */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f9fafb; color: #333; line-height: 1.6; }
        
        /* Header */
        .header { background-color: #344152; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .logo { width: 40px; height: 40px; }
        .system-name { font-weight: 600; font-size: 18px; }
        .header-right { display: flex; align-items: center; gap: 24px; }
        
        /* Profile Dropdown */
        .profile-container { position: relative; }
        .profile-btn { background: none; border: none; color: white; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 12px; border-radius: 6px; transition: background-color 0.2s; }
        .profile-btn:hover { background-color: rgba(255,255,255,0.1); }
        .profile-dropdown { display: none; position: absolute; right: 0; top: 100%; margin-top: 8px; width: 192px; background: white; color: black; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000; overflow: hidden; }
        .profile-dropdown.show { display: block; }
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 12px 16px;
            text-align: left;
            background: none;
            border: none;
            color: #333;
            cursor: pointer;
            transition: background-color 0.2s;
            text-decoration: none;
            font-size: 14px;
        }
        .dropdown-item:hover { background-color: #f3f4f6; }
        .dropdown-item i { margin-right: 8px; width: 20px; }
        .logout-btn { 
            color: #dc2626; 
            border-top: 1px solid #e5e7eb;
        }
        .logout-btn:hover { background-color: #fee2e2; }
        
        /* Layout */
        .main-container { display: flex; min-height: calc(100vh - 60px); }
        .sidebar { width: 256px; background-color: #344152; min-height: calc(100vh - 60px); padding: 16px; }
        .sidebar-btn { width: 100%; padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; text-align: left; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; color: white; background-color: #344152; display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .sidebar-btn:hover { background-color: #3d4d62; }
        .sidebar-btn.active { background-color: #4a5568; color: white; }
        .main-content { flex: 1; padding: 32px; background-color: #f9fafb; }
        
        /* Welcome Card */
        .welcome-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 40px; margin-bottom: 24px; color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
        .welcome-card::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .welcome-card h1 { font-size: 36px; font-weight: 700; margin-bottom: 10px; }
        .welcome-card p { font-size: 18px; opacity: 0.9; }
        
        /* Debug Panel */
        .debug-panel { background: #f8f9fa; border: 2px dashed #6c757d; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .debug-panel h3 { color: #495057; margin-bottom: 10px; font-size: 16px; }
        .debug-info { font-size: 13px; color: #6c757d; }
        .debug-item { margin-bottom: 5px; }
        
        /* Location Warning */
        .location-warning { background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 12px; padding: 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2); }
        .location-warning i { font-size: 32px; color: #f59e0b; }
        .location-warning-content { flex: 1; }
        .location-warning-title { font-weight: 700; font-size: 16px; color: #92400e; margin-bottom: 5px; }
        .location-warning-text { font-size: 14px; color: #78350f; }
        
        /* Attendance Card */
        .attendance-card { background: linear-gradient(135deg, #4a5568, #2d3748); border-radius: 16px; padding: 40px; margin-bottom: 24px; color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.2); text-align: center; }
        .attendance-card h2 { font-size: 32px; font-weight: 700; margin-bottom: 30px; }
        .attendance-buttons { display: flex; gap: 20px; justify-content: center; margin-bottom: 30px; flex-wrap: wrap; }
        .attendance-btn { padding: 16px 40px; border: none; border-radius: 12px; font-size: 18px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .btn-time-in { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .btn-time-in:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
        .btn-time-out { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .btn-time-out:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4); }
        .btn-time-out:disabled, .btn-time-in:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .attendance-status { font-size: 18px; color: #fbbf24; font-weight: 600; }
        
        /* Page Header */
        .page-header { background: white; padding: 20px 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 700; color: #1a1a1a; margin: 0; }
        
        /* Summary */
        .attendance-summary { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
        .summary-item { padding: 12px 20px; border-radius: 8px; background-color: #f8f9fa; min-width: 150px; text-align: center; }
        .summary-label { font-size: 12px; color: #6b7280; margin-bottom: 5px; font-weight: 600; text-transform: uppercase; }
        .summary-value { font-size: 20px; font-weight: bold; color: #1f2937; }
        
        /* Filters */
        .filter-container { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .filter-group { display: flex; gap: 8px; align-items: center; }
        .filter-label { font-weight: 600; color: #555; font-size: 14px; }
        .filter-btn { padding: 10px 20px; border: 2px solid #e0e0e0; background: white; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px; color: #666; transition: all 0.3s ease; }
        .filter-btn:hover { border-color: #4A90E2; color: #4A90E2; }
        .filter-btn.active { background: #4A90E2; color: white; border-color: #4A90E2; }
        
        /* Date Range Filter */
        .date-range-filter { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .date-input-group { display: flex; flex-direction: column; gap: 5px; }
        .date-input { padding: 10px; border: 1px solid #e0e0e0; border-radius: 6px; width: 150px; }
        
        /* Month Selector for PDF */
        .pdf-month-selector { display: flex; align-items: center; gap: 10px; }
        .month-select, .year-select { padding: 10px; border: 1px solid #e0e0e0; border-radius: 6px; }
        
        /* Table */
        .table-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { background: #4A90E2; color: white; padding: 16px 24px; font-size: 18px; font-weight: 600; }
        .table-container { overflow-x: auto; max-height: 600px; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; position: sticky; top: 0; z-index: 10; }
        th { padding: 16px; text-align: left; font-weight: 600; color: #333; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #e0e0e0; }
        td { padding: 16px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #555; }
        tbody tr { transition: background 0.2s; }
        tbody tr:hover { background: #f8f9fa; }
        
        /* Status Badges */
        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; display: inline-block; }
        .status-ongoing { background: #fff3cd; color: #856404; }
        .status-certified { background: #d4edda; color: #155724; }
        .status-dropout { background: #f8d7da; color: #721c24; }
        
        /* Progress Bar */
        .progress-bar { width: 100%; height: 8px; background: #e0e0e0; border-radius: 10px; overflow: hidden; position: relative; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #4A90E2, #357ABD); border-radius: 10px; transition: width 0.3s; }
        .progress-text { font-size: 12px; color: #666; margin-top: 4px; font-weight: 500; }
        
        /* Buttons */
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s; text-decoration: none; font-size: 13px; }
        .btn-primary { background: #4A90E2; color: white; }
        .btn-primary:hover { background: #357ABD; transform: translateY(-2px); }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; transform: translateY(-2px); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        
        /* Modals */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: white; padding: 30px; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0; }
        .modal-header h3 { color: #1a1a1a; font-size: 20px; margin: 0; }
        .close-modal { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; }
        
        /* Details */
        .detail-row { display: grid; grid-template-columns: 150px 1fr; gap: 12px; margin-bottom: 12px; }
        .detail-label { font-weight: 600; color: #666; }
        .detail-value { color: #333; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; color: #ddd; }
        
        /* Error Display */
        .error-panel {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 2px solid #ef4444;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            color: #991b1b;
        }
        .error-panel h3 {
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Today Row Highlight */
        .today-row {
            background-color: rgba(59, 130, 246, 0.1);
            border-left: 4px solid #3b82f6;
            font-weight: 500;
        }
        
        .today-row:hover {
            background-color: rgba(59, 130, 246, 0.15) !important;
        }
        
        /* Additional Status Badge Styles */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-certified {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-ongoing {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-dropout {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-container { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; display: flex; overflow-x: auto; }
            .sidebar-btn { flex: 1; margin-bottom: 0; margin-right: 8px; }
            .main-content { padding: 16px; }
            .welcome-card h1 { font-size: 24px; }
            .attendance-buttons { flex-direction: column; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .filter-group { flex-direction: column; width: 100%; }
            .date-range-filter { flex-direction: column; align-items: stretch; }
            .date-input-group { width: 100%; }
            .date-input { width: 100%; }
            .attendance-summary { flex-direction: column; }
            .summary-item { min-width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="/css/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">
            <span class="system-name">Livelihood Enrollments & Monitoring System</span>
        </div>
        <div class="header-right">
            <div class="profile-container">
                <button class="profile-btn" id="profileBtn">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($fullname); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <a href="trainer.php" class="dropdown-item">
                        <i class="fas fa-user"></i> View Profile
                    </a>
                    <button class="dropdown-item logout-btn" id="logoutBtn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <a href="/trainer/dashboard" class="sidebar-btn active">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="/trainer/trainer_participants" class="sidebar-btn">
                <i class="fas fa-users"></i> Trainer Participants
            </a>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Card -->
            <div class="welcome-card">
                <h1>Welcome back, <?php echo htmlspecialchars($fullname); ?> 👋</h1>
                <p><?php echo htmlspecialchars($role); ?></p>
                <p style="margin-top: 10px; font-size: 16px; opacity: 0.8;">
                    <i class="fas fa-calendar-day"></i> Today is <?php echo $today_display; ?>
                </p>
                <p style="margin-top: 5px; font-size: 14px; opacity: 0.8;">
                    <i class="fas fa-clock"></i> Current time: <?php echo $current_time; ?>
                </p>
            </div>

            
            <!-- Location Warning - Now shows trainer's specific location -->
            <div class="location-warning" id="locationWarning">
                <i class="fas fa-map-marker-alt"></i>
                <div class="location-warning-content">
                    <div class="location-warning-title" id="locationTitle">Checking your location...</div>
                    <div class="location-warning-text" id="locationText">Please wait while we verify your position.</div>
                </div>
            </div>

            <!-- Location Info Card (New) -->
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 8px; padding: 15px; margin-bottom: 20px; color: white; display: flex; align-items: center; gap: 15px;">
                <i class="fas fa-map-pin" style="font-size: 24px;"></i>
                <div>
                    <strong>Training Location:</strong> <?php echo htmlspecialchars(LOCATION_NAME ?: 'Main Office'); ?><br>
                    <small>📍 <?php echo CENTER_LAT; ?>, <?php echo CENTER_LNG; ?> • Allowed radius: <?php echo MAX_DISTANCE_METERS; ?> meters</small>
                </div>
            </div>

            <!-- Attendance Card -->
            <div class="attendance-card">
                <h2>Trainer's Attendance</h2>
                <div class="attendance-buttons">
                    <button class="attendance-btn btn-time-in" id="timeInBtn" 
                            <?php echo ($attendance_status['status'] !== 'not_timed_in') ? 'disabled' : ''; ?>>
                        <i class="fas fa-sign-in-alt"></i> Time In
                    </button>
                    <button class="attendance-btn btn-time-out" id="timeOutBtn"
                            <?php echo ($attendance_status['status'] !== 'timed_in') ? 'disabled' : ''; ?>>
                        <i class="fas fa-sign-out-alt"></i> Time Out
                    </button>
                </div>
                <div class="attendance-status" id="attendanceStatusDisplay">
                    Status: 
                    <?php
                    if ($attendance_status['status'] === 'completed') {
                        echo "Completed today ✓ (In: {$attendance_status['time_in']}, Out: {$attendance_status['time_out']})";
                        if (!empty($attendance_status['duration'])) {
                            echo " - Duration: {$attendance_status['duration']}";
                        }
                    } elseif ($attendance_status['status'] === 'timed_in') {
                        echo "Timed in at {$attendance_status['time_in']} - Ready to time out";
                    } elseif ($attendance_status['status'] === 'error') {
                        echo "Error checking attendance status";
                    } else {
                        echo "Not yet timed in today";
                    }
                    ?>
                </div>
            </div>

            <!-- Attendance Statistics -->
            <div class="page-header">
                <h2 class="page-title"><i class="fas fa-chart-line"></i> Attendance Statistics</h2>
                <div class="attendance-summary">
                    <div class="summary-item">
                        <div class="summary-label">Date Range</div>
                        <div class="summary-value" style="font-size: 16px;">
                            <?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Days</div>
                        <div class="summary-value"><?php echo $total_days; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Completed Days</div>
                        <div class="summary-value"><?php echo $completed_count; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Completion Rate</div>
                        <div class="summary-value"><?php echo $completion_rate; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Attendance Records Table -->
            <div class="page-header" style="margin-top: 20px;">
                <h2 class="page-title"><i class="fas fa-history"></i> Attendance History</h2>
                <div style="margin-top: 10px; font-size: 14px; color: #666;">
                    Showing attendance records for <strong><?php echo htmlspecialchars($fullname); ?></strong>
                    (User ID: <?php echo $user_id; ?>) at <strong><?php echo htmlspecialchars(LOCATION_NAME ?: 'Main Office'); ?></strong>
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="filter-container">
                <div class="date-range-filter">
                    <div class="date-input-group">
                        <label class="filter-label">From Date:</label>
                        <input type="date" id="startDate" class="date-input" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="date-input-group">
                        <label class="filter-label">To Date:</label>
                        <input type="date" id="endDate" class="date-input" value="<?php echo $end_date; ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applyDateFilter()">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <button class="btn btn-secondary" onclick="resetDateFilter()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                    
                    <!-- PDF Generation -->
                    <div style="margin-left: auto; display: flex; align-items: center; gap: 10px;">
                        <div class="pdf-month-selector">
                            <select id="pdfMonth" class="month-select">
                                <option value="">Select Month</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>" <?php echo $i == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select id="pdfYear" class="year-select">
                                <option value="">Select Year</option>
                                <?php for($i = date('Y'); $i >= date('Y')-5; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button class="btn btn-danger" onclick="generatePDF()">
                            <i class="fas fa-file-pdf"></i> Generate PDF
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <i class="fas fa-calendar-alt"></i> Attendance Records 
                    <span style="font-size: 14px; margin-left: 10px;">
                        (Showing <?php echo $total_days; ?> days from <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>)
                    </span>
                </div>
                <div class="table-container">
                    <table id="attendanceTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Duration</th>
                                <th>Distance</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendance_records)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-times"></i>
                                        <h3>No attendance records found</h3>
                                        <p>No attendance records found for the selected date range.</p>
                                        <?php if ($debug_info['attendance_records'] == 0): ?>
                                        <p style="color: #dc2626; margin-top: 10px;">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            Debug: No records in database for user_id <?php echo $user_id; ?>
                                        </p>
                                        <?php endif; ?>
                                        <?php if (isset($attendance_data['error'])): ?>
                                        <p style="color: #dc2626; margin-top: 10px;">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            Error: <?php echo htmlspecialchars($attendance_data['error']); ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($attendance_records as $record): ?>
                            <?php 
                            $is_today = ($record['date'] == $today_date);
                            $row_class = $is_today ? 'today-row' : '';
                            ?>
                            <tr class="attendance-row <?php echo $row_class; ?>" 
                                data-date="<?php echo $record['date']; ?>"
                                data-status="<?php echo $record['status']; ?>">
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($record['date'])); ?></strong>
                                    <?php if ($is_today): ?>
                                    <span style="background: #3b82f6; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px;">TODAY</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $record['day_name']; ?></td>
                                <td>
                                    <?php if ($record['time_in']): ?>
                                        <span style="color: #10b981; font-weight: 600;">
                                            <i class="fas fa-sign-in-alt"></i> <?php echo $record['time_in']; ?>
                                        </span>
                                        <?php if ($record['time_in_data']): ?>
                                        <br>
                                        <small style="color: #6b7280; font-size: 11px;">
                                            Dist: <?php echo number_format($record['time_in_data']['distance_from_office'], 1); ?>m
                                        </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-style: italic;">Not recorded</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['time_out']): ?>
                                        <span style="color: #ef4444; font-weight: 600;">
                                            <i class="fas fa-sign-out-alt"></i> <?php echo $record['time_out']; ?>
                                        </span>
                                        <?php if ($record['time_out_data']): ?>
                                        <br>
                                        <small style="color: #6b7280; font-size: 11px;">
                                            Dist: <?php echo number_format($record['time_out_data']['distance_from_office'], 1); ?>m
                                        </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #f59e0b; font-style: italic;">Not timed out</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['duration']): ?>
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 6px; font-weight: 600;">
                                            <?php echo $record['duration']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $distances = [];
                                    if ($record['time_in_data']) $distances[] = $record['time_in_data']['distance_from_office'];
                                    if ($record['time_out_data']) $distances[] = $record['time_out_data']['distance_from_office'];
                                    if (!empty($distances)): 
                                        $avg_distance = array_sum($distances) / count($distances);
                                    ?>
                                        <span style="color: <?php echo $avg_distance <= MAX_DISTANCE_METERS ? '#10b981' : ($avg_distance <= MAX_DISTANCE_METERS*2 ? '#f59e0b' : '#ef4444'); ?>; font-weight: 600;">
                                            <?php echo number_format($avg_distance, 1); ?>m
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['time_in_data'] || $record['time_out_data']): ?>
                                        <button class="btn btn-secondary btn-sm" onclick="showLocationDetails(this)"
                                                data-date="<?php echo $record['date']; ?>"
                                                data-lat-in="<?php echo $record['time_in_data']['latitude'] ?? ''; ?>"
                                                data-lng-in="<?php echo $record['time_in_data']['longitude'] ?? ''; ?>"
                                                data-lat-out="<?php echo $record['time_out_data']['latitude'] ?? ''; ?>"
                                                data-lng-out="<?php echo $record['time_out_data']['longitude'] ?? ''; ?>">
                                            <i class="fas fa-map-marker-alt"></i> View Map
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">No location</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['status'] === 'completed'): ?>
                                        <span class="status-badge status-certified">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </span>
                                    <?php elseif ($record['status'] === 'timed_in_only'): ?>
                                        <span class="status-badge status-ongoing">
                                            <i class="fas fa-clock"></i> Timed In Only
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-dropout">
                                            <i class="fas fa-times-circle"></i> Incomplete
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm" onclick="viewDayDetails('<?php echo $record['date']; ?>', '<?php echo $record['day_name']; ?>')">
                                        <i class="fas fa-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Location Details Modal -->
    <div class="modal" id="locationModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-marked-alt"></i> Location Details</h3>
                <button class="close-modal" onclick="closeModal('locationModal')">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div id="locationDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Day Details Modal -->
    <div class="modal" id="dayDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-day"></i> Day Details</h3>
                <button class="close-modal" onclick="closeModal('dayDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div id="dayDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Configuration - Now using trainer's specific location from database
        const CENTER_LAT = <?php echo CENTER_LAT; ?>;
        const CENTER_LNG = <?php echo CENTER_LNG; ?>;
        const MAX_DISTANCE = <?php echo MAX_DISTANCE_METERS; ?>;
        const LOCATION_NAME = "<?php echo addslashes(LOCATION_NAME); ?>";
        const TODAY_DATE = "<?php echo date('Y-m-d'); ?>";
        const SERVER_DATE = "<?php echo $today_date; ?>";
        const TRAINER_NAME = "<?php echo addslashes($fullname); ?>";
        const USER_ID = <?php echo $user_id; ?>;
        const DEBUG_INFO = <?php echo json_encode($debug_info); ?>;
        
        let userLocation = null;
        let isWithinRange = false;
        let isInitializing = true;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard initialized with location:', LOCATION_NAME);
            console.log('Center:', CENTER_LAT, CENTER_LNG, 'Radius:', MAX_DISTANCE + 'm');
            
            // Check time synchronization
            checkTimeSynchronization();
            
            // Check geolocation support
            if (!navigator.geolocation) {
                showLocationError('Geolocation is not supported by your browser');
                isInitializing = false;
                return;
            }
            
            // Get user location
            checkUserLocation();
            
            // Update location every 30 seconds
            setInterval(checkUserLocation, 30000);
            
            // Profile dropdown
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', () => {
                    profileDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', (e) => {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('show');
                    }
                });
            }
            
            // Logout
            const logoutBtn = document.getElementById('logoutBtn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', async () => {
                    const result = await Swal.fire({
                        title: 'Logout',
                        text: 'Are you sure you want to logout?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Yes, logout',
                        cancelButtonText: 'Cancel'
                    });
                    
                    if (result.isConfirmed) {
                        window.location.href = '?action=logout';
                    }
                });
            }
            
            // Time In/Out buttons
            const timeInBtn = document.getElementById('timeInBtn');
            const timeOutBtn = document.getElementById('timeOutBtn');
            
            if (timeInBtn) {
                timeInBtn.addEventListener('click', () => handleAttendance('time_in'));
            }
            if (timeOutBtn) {
                timeOutBtn.addEventListener('click', () => handleAttendance('time_out'));
            }
            
            // Initialize date pickers
            if (flatpickr) {
                flatpickr("#startDate", {
                    dateFormat: "Y-m-d",
                    maxDate: "today"
                });
                
                flatpickr("#endDate", {
                    dateFormat: "Y-m-d",
                    maxDate: "today"
                });
            }
            
            // Highlight today's row
            highlightTodayRow();
            
            // Auto-refresh attendance status every minute
            setInterval(refreshAttendanceStatus, 60000);
            
            // Show debug alert if no records
            if (DEBUG_INFO.attendance_records === 0) {
                console.log('Debug Info:', DEBUG_INFO);
            }
            
            isInitializing = false;
        });
        
        function checkTimeSynchronization() {
            const clientDate = new Date().toISOString().split('T')[0];
            const serverDate = SERVER_DATE;
            
            if (clientDate !== serverDate) {
                console.warn(`Date mismatch - Client: ${clientDate}, Server: ${serverDate}`);
                // Optionally show warning to user
                if (document.getElementById('locationWarning')) {
                    const warning = document.getElementById('locationWarning');
                    warning.style.background = 'linear-gradient(135deg, #fef3c7, #fde68a)';
                    warning.style.borderColor = '#f59e0b';
                    const title = document.getElementById('locationTitle');
                    const text = document.getElementById('locationText');
                    if (title) title.textContent = '⚠️ Time Synchronization Warning';
                    if (text) text.textContent = `Your device date (${clientDate}) differs from server date (${serverDate}). Please sync your device time.`;
                }
            } else {
                console.log('Time synchronized - Client and server dates match');
            }
        }
        
        function runDebug() {
            Swal.fire({
                title: 'Running Debug Check...',
                html: 'Checking database connections and data...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=1&action=debug_data'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const debugInfo = data.debug_info;
                    let html = `
                        <div style="text-align: left; max-height: 300px; overflow-y: auto;">
                            <h4>Debug Results:</h4>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <p><strong>User ID:</strong> ${debugInfo.user_id}</p>
                                <p><strong>Trainer Name:</strong> "${debugInfo.trainer_name}"</p>
                                <p><strong>Attendance Records Found:</strong> ${debugInfo.attendance_records}</p>
                                <p><strong>Program Records Found:</strong> ${debugInfo.program_records}</p>
                                <p><strong>Location:</strong> ${LOCATION_NAME}</p>
                                <p><strong>Center:</strong> ${CENTER_LAT}, ${CENTER_LNG} (${MAX_DISTANCE}m radius)</p>
                    `;
                    
                    if (debugInfo.attendance_latest) {
                        html += `<p><strong>Latest Attendance:</strong> ${debugInfo.attendance_latest}</p>`;
                    }
                    
                    if (debugInfo.database_error) {
                        html += `<p style="color: #dc2626;"><strong>Database Error:</strong> ${debugInfo.database_error}</p>`;
                    }
                    
                    html += `</div>`;
                    
                    if (debugInfo.tables_exist) {
                        html += `<h5>Tables Status:</h5><ul>`;
                        for (const [table, exists] of Object.entries(debugInfo.tables_exist)) {
                            html += `<li>${table}: ${exists ? '✅ Exists' : '❌ Missing'}</li>`;
                        }
                        html += `</ul>`;
                    }
                    
                    if (debugInfo.attendance_sample.length > 0) {
                        html += `<h5>Sample Attendance Records:</h5><ul>`;
                        debugInfo.attendance_sample.forEach(record => {
                            html += `<li>${record.attendance_type} at ${record.attendance_time} (Name: ${record.trainer_name})</li>`;
                        });
                        html += `</ul>`;
                    }
                    
                    if (debugInfo.program_sample.length > 0) {
                        html += `<h5>Sample Program Records:</h5><ul>`;
                        debugInfo.program_sample.forEach(program => {
                            html += `<li>${program.program_name} - Trainer: "${program.trainer}"</li>`;
                        });
                        html += `</ul>`;
                    }
                    
                    html += `</div>`;
                    
                    Swal.fire({
                        title: 'Debug Results',
                        html: html,
                        icon: 'info',
                        confirmButtonText: 'OK',
                        width: 600
                    });
                } else {
                    Swal.fire({
                        title: 'Debug Failed',
                        text: data.message || 'Unknown error',
                        icon: 'error'
                    });
                }
            })
            .catch(error => {
                console.error('Debug error:', error);
                Swal.fire({
                    title: 'Debug Error',
                    text: 'Failed to run debug check: ' + error.message,
                    icon: 'error'
                });
            });
        }
        
        function refreshAttendanceStatus() {
            if (isInitializing) return;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=1&action=get_today_status'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateAttendanceDisplay(data);
                }
            })
            .catch(error => {
                console.error('Refresh attendance status error:', error);
            });
        }
        
        function updateAttendanceDisplay(data) {
            const statusDisplay = document.getElementById('attendanceStatusDisplay');
            const timeInBtn = document.getElementById('timeInBtn');
            const timeOutBtn = document.getElementById('timeOutBtn');
            
            if (!statusDisplay) return;
            
            if (data.status === 'completed') {
                statusDisplay.innerHTML = `Status: Completed today ✓ (In: ${data.time_in}, Out: ${data.time_out})${data.duration ? ' - Duration: ' + data.duration : ''}`;
                if (timeInBtn) timeInBtn.disabled = true;
                if (timeOutBtn) timeOutBtn.disabled = true;
            } else if (data.status === 'timed_in') {
                statusDisplay.innerHTML = `Status: Timed in at ${data.time_in} - Ready to time out`;
                if (timeInBtn) timeInBtn.disabled = true;
                if (timeOutBtn) timeOutBtn.disabled = false;
            } else if (data.status === 'error') {
                statusDisplay.innerHTML = `Status: Error checking attendance`;
            } else {
                statusDisplay.innerHTML = `Status: Not yet timed in today`;
                if (timeInBtn) timeInBtn.disabled = false;
                if (timeOutBtn) timeOutBtn.disabled = true;
            }
            
            // Update button states based on location
            updateButtonStates();
        }
        
        function highlightTodayRow() {
            const rows = document.querySelectorAll('.attendance-row');
            rows.forEach(row => {
                const rowDate = row.getAttribute('data-date');
                if (rowDate === TODAY_DATE) {
                    row.classList.add('today-row');
                }
            });
        }
        
        function checkUserLocation() {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    userLocation = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    
                    // Calculate REAL distance using proper formula
                    const distance = calculateRealDistance(userLocation.lat, userLocation.lng, CENTER_LAT, CENTER_LNG);
                    
                    // Update global flag - STRICT CHECK
                    isWithinRange = distance <= MAX_DISTANCE;
                    
                    // Update UI
                    updateLocationUI({
                        within_range: isWithinRange,
                        distance: distance,
                        center_lat: CENTER_LAT,
                        center_lng: CENTER_LNG,
                        max_distance: MAX_DISTANCE
                    });
                    
                    // Enable/disable buttons based on location
                    updateButtonStates();
                },
                (error) => {
                    let errorMsg = 'Unable to retrieve your location';
                    if (error.code === error.PERMISSION_DENIED) {
                        errorMsg = 'Location permission denied. Please enable location access.';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        errorMsg = 'Location information unavailable';
                    } else if (error.code === error.TIMEOUT) {
                        errorMsg = 'Location request timed out';
                    }
                    showLocationError(errorMsg);
                    isWithinRange = false;
                    updateButtonStates();
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
        
        // Calculate REAL distance using Haversine formula
        function calculateRealDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Earth radius in meters
            const φ1 = lat1 * Math.PI / 180;
            const φ2 = lat2 * Math.PI / 180;
            const Δφ = (lat2 - lat1) * Math.PI / 180;
            const Δλ = (lon2 - lon1) * Math.PI / 180;
            
            const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                    Math.cos(φ1) * Math.cos(φ2) *
                    Math.sin(Δλ/2) * Math.sin(Δλ/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            
            return R * c; // Distance in meters
        }
        
        function updateButtonStates() {
            const timeInBtn = document.getElementById('timeInBtn');
            const timeOutBtn = document.getElementById('timeOutBtn');
            
            // Only update if buttons are not disabled by attendance status
            if (timeInBtn && !timeInBtn.disabled) {
                timeInBtn.disabled = !isWithinRange;
                timeInBtn.title = isWithinRange ? 'Click to time in' : `You must be within ${MAX_DISTANCE}m of ${LOCATION_NAME}`;
            }
            
            if (timeOutBtn && !timeOutBtn.disabled) {
                timeOutBtn.disabled = !isWithinRange;
                timeOutBtn.title = isWithinRange ? 'Click to time out' : `You must be within ${MAX_DISTANCE}m of ${LOCATION_NAME}`;
            }
        }
        
        function updateLocationUI(data) {
            const warning = document.getElementById('locationWarning');
            const title = document.getElementById('locationTitle');
            const text = document.getElementById('locationText');
            
            if (!warning || !title || !text) return;
            
            if (data.within_range) {
                warning.style.background = 'linear-gradient(135deg, #d1fae5, #a7f3d0)';
                warning.style.borderColor = '#10b981';
                const icon = warning.querySelector('i');
                if (icon) icon.style.color = '#10b981';
                title.style.color = '#065f46';
                text.style.color = '#047857';
                title.textContent = `📍 Location: ${userLocation.lat.toFixed(6)}, ${userLocation.lng.toFixed(6)}`;
                text.textContent = `✓ You are within ${LOCATION_NAME} (${data.distance.toFixed(0)}m from center, allowed: ${MAX_DISTANCE}m)`;
            } else {
                warning.style.background = 'linear-gradient(135deg, #fee2e2, #fecaca)';
                warning.style.borderColor = '#ef4444';
                const icon = warning.querySelector('i');
                if (icon) icon.style.color = '#ef4444';
                title.style.color = '#991b1b';
                text.style.color = '#b91c1c';
                title.textContent = `📍 Location: ${userLocation ? userLocation.lat.toFixed(6) + ', ' + userLocation.lng.toFixed(6) : 'Unknown'}`;
                text.textContent = userLocation ? 
                    `⚠️ You are ${data.distance.toFixed(0)}m from center. Must be within ${MAX_DISTANCE}m of ${LOCATION_NAME} to time in/out` :
                    '⚠️ Unable to determine your location. Please enable location services.';
            }
        }
        
        function showLocationError(message) {
            const warning = document.getElementById('locationWarning');
            const title = document.getElementById('locationTitle');
            const text = document.getElementById('locationText');
            
            if (!warning || !title || !text) return;
            
            warning.style.background = 'linear-gradient(135deg, #fee2e2, #fecaca)';
            warning.style.borderColor = '#ef4444';
            const icon = warning.querySelector('i');
            if (icon) icon.style.color = '#ef4444';
            title.style.color = '#991b1b';
            text.style.color = '#b91c1c';
            title.textContent = '❌ Location Error';
            text.textContent = message;
        }
        
        function calculateDisplayDistance() {
            if (!userLocation) return '?';
            return Math.round(calculateRealDistance(userLocation.lat, userLocation.lng, CENTER_LAT, CENTER_LNG));
        }
        
        function handleAttendance(action) {
            // FIRST: Check if we have location
            if (!userLocation) {
                Swal.fire({
                    icon: 'error',
                    title: 'Location Required',
                    text: 'Please allow location access to record attendance',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            // SECOND: Strict check - Must be within range
            if (!isWithinRange) {
                const distance = calculateDisplayDistance();
                Swal.fire({
                    icon: 'error',
                    title: '❌ Location Too Far',
                    html: `
                        <div style="text-align: center;">
                            <div style="font-size: 48px; margin-bottom: 20px;">🚫</div>
                            <p style="font-size: 18px; font-weight: bold; color: #dc2626; margin-bottom: 20px;">
                                You are too far from ${LOCATION_NAME}!
                            </p>
                            <div style="background: #fee2e2; padding: 20px; border-radius: 10px; margin-bottom: 15px;">
                                <p style="margin: 10px 0;"><strong>📍 Your distance:</strong> <span style="color: #dc2626; font-size: 20px; font-weight: bold;">${distance}m</span></p>
                                <p style="margin: 10px 0;"><strong>✅ Required:</strong> <span style="color: #059669; font-size: 20px; font-weight: bold;">Within ${MAX_DISTANCE}m</span></p>
                            </div>
                            <p style="color: #991b1b; font-weight: 600;">
                                ⚠️ Please move closer to ${LOCATION_NAME} to time in/out.
                            </p>
                        </div>
                    `,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'OK, I understand',
                    allowOutsideClick: false
                });
                return;
            }
            
            // THIRD: If within range, confirm action
            const actionText = action === 'time_in' ? 'Time In' : 'Time Out';
            const distance = calculateDisplayDistance();
            
            Swal.fire({
                title: 'Confirm ' + actionText,
                html: `
                    <p>Record your ${actionText.toLowerCase()} at ${LOCATION_NAME} now?</p>
                    <p style="margin-top: 15px; font-size: 14px; color: #666;">
                        📍 Distance from center: <strong>${distance}m</strong> (within ${MAX_DISTANCE}m allowed)
                    </p>
                    <p style="margin-top: 10px; font-size: 12px; color: #999;">
                        Server time: ${new Date().toLocaleString('en-US', {timeZone: 'Asia/Manila'})}
                    </p>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'time_in' ? '#10b981' : '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, ' + actionText,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Recording...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `ajax=1&action=trainer_attendance&attendance_action=${action}&lat=${userLocation.lat}&lng=${userLocation.lng}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                confirmButtonColor: '#10b981',
                                timer: 2000,
                                showConfirmButton: true
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            // Check if it's a location error
                            if (data.location_error) {
                                Swal.fire({
                                    icon: 'error',
                                    title: '❌ Location Too Far',
                                    html: `
                                        <div style="text-align: center;">
                                            <div style="font-size: 48px; margin-bottom: 20px;">🚫</div>
                                            <p style="font-size: 18px; font-weight: bold; color: #dc2626; margin-bottom: 20px;">
                                                ${data.message}
                                            </p>
                                            <div style="background: #fee2e2; padding: 20px; border-radius: 10px; margin-bottom: 15px;">
                                                <p style="margin: 10px 0;"><strong>📍 Your distance:</strong> <span style="color: #dc2626; font-size: 20px; font-weight: bold;">${Math.round(data.distance)}m</span></p>
                                                <p style="margin: 10px 0;"><strong>✅ Required:</strong> <span style="color: #059669; font-size: 20px; font-weight: bold;">Within ${data.required_distance}m of ${data.location_name || LOCATION_NAME}</span></p>
                                            </div>
                                            <p style="color: #991b1b; font-weight: 600;">
                                                ⚠️ Please move closer to the training location to record attendance.
                                            </p>
                                        </div>
                                    `,
                                    confirmButtonColor: '#dc2626',
                                    confirmButtonText: 'OK',
                                    allowOutsideClick: false
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: data.message,
                                    confirmButtonColor: '#dc2626'
                                });
                            }
                        }
                    })
                    .catch((error) => {
                        console.error('Attendance recording error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to record attendance. Please try again.',
                            confirmButtonColor: '#dc2626'
                        });
                    });
                }
            });
        }
        
        // Date filter functions
        function applyDateFilter() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Dates',
                    text: 'Please select both start and end dates',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            if (startDate > endDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Date Range',
                    text: 'Start date cannot be after end date',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            // Show loading
            Swal.fire({
                title: 'Filtering...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=1&action=filter_attendance&start_date=${startDate}&end_date=${endDate}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `?start_date=${startDate}&end_date=${endDate}`;
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#ef4444'
                    });
                }
            })
            .catch((error) => {
                console.error('Filter error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to filter records. Please try again.',
                    confirmButtonColor: '#ef4444'
                });
            });
        }
        
        function resetDateFilter() {
            // Reset to default (last 30 days)
            const defaultStartDate = new Date();
            defaultStartDate.setDate(defaultStartDate.getDate() - 30);
            const formattedStartDate = defaultStartDate.toISOString().split('T')[0];
            const formattedEndDate = new Date().toISOString().split('T')[0];
            
            window.location.href = `?start_date=${formattedStartDate}&end_date=${formattedEndDate}`;
        }
        
        // PDF Generation
        function generatePDF() {
            const month = document.getElementById('pdfMonth').value;
            const year = document.getElementById('pdfYear').value;
            
            if (!month || !year) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please select both month and year for PDF generation',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            // Show confirmation
            const monthName = new Date(`${year}-${month}-01`).toLocaleString('default', { month: 'long' });
            
            Swal.fire({
                title: 'Generate PDF Report',
                html: `Generate attendance PDF for <strong>${monthName} ${year}</strong>?<br><br>
                       <small>Location: ${LOCATION_NAME}<br>Radius: ${MAX_DISTANCE}m</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, generate PDF',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Generating PDF...',
                        text: 'Please wait while we prepare your report',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Direct download using GET request
                    const downloadUrl = `?download_pdf=1&year=${year}&month=${month}`;
                    
                    // Create invisible iframe to trigger download
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.src = downloadUrl;
                    document.body.appendChild(iframe);
                    
                    // Check download after 3 seconds
                    setTimeout(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'PDF Generated',
                            html: `PDF report for ${monthName} ${year} has been downloaded.<br><br>
                                   <small>Check your downloads folder. The PDF includes your logo and location details.</small>`,
                            confirmButtonColor: '#10b981',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            document.body.removeChild(iframe);
                        });
                    }, 3000);
                }
            });
        }
        
        // Location details
        function showLocationDetails(button) {
            const date = button.dataset.date;
            const latIn = button.dataset.latIn;
            const lngIn = button.dataset.lngIn;
            const latOut = button.dataset.latOut;
            const lngOut = button.dataset.lngOut;
            
            let content = `<h4 style="margin: 0 0 15px 0; color: #1a1a1a;">Location Details for ${date}</h4>`;
            
            if (latIn && lngIn) {
                const distanceIn = calculateRealDistance(parseFloat(latIn), parseFloat(lngIn), CENTER_LAT, CENTER_LNG);
                content += `
                    <div style="margin-bottom: 20px; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #10b981;">
                        <h4 style="margin: 0 0 10px 0; color: #065f46;">
                            <i class="fas fa-sign-in-alt"></i> Time In Location
                        </h4>
                        <div class="detail-row">
                            <div class="detail-label">Coordinates:</div>
                            <div class="detail-value">${latIn}, ${lngIn}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Distance from ${LOCATION_NAME}:</div>
                            <div class="detail-value" style="color: ${distanceIn <= MAX_DISTANCE ? '#10b981' : (distanceIn <= MAX_DISTANCE*2 ? '#f59e0b' : '#ef4444')}; font-weight: 600;">
                                ${Math.round(distanceIn)} meters
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span style="color: ${distanceIn <= MAX_DISTANCE ? '#10b981' : (distanceIn <= MAX_DISTANCE*2 ? '#f59e0b' : '#ef4444')};">
                                    ${distanceIn <= MAX_DISTANCE ? '✓ Within allowed range' : '⚠️ Outside allowed range'}
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            if (latOut && lngOut) {
                const distanceOut = calculateRealDistance(parseFloat(latOut), parseFloat(lngOut), CENTER_LAT, CENTER_LNG);
                content += `
                    <div style="padding: 15px; background: #fef2f2; border-radius: 8px; border-left: 4px solid #ef4444;">
                        <h4 style="margin: 0 0 10px 0; color: #991b1b;">
                            <i class="fas fa-sign-out-alt"></i> Time Out Location
                        </h4>
                        <div class="detail-row">
                            <div class="detail-label">Coordinates:</div>
                            <div class="detail-value">${latOut}, ${lngOut}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Distance from ${LOCATION_NAME}:</div>
                            <div class="detail-value" style="color: ${distanceOut <= MAX_DISTANCE ? '#10b981' : (distanceOut <= MAX_DISTANCE*2 ? '#f59e0b' : '#ef4444')}; font-weight: 600;">
                                ${Math.round(distanceOut)} meters
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span style="color: ${distanceOut <= MAX_DISTANCE ? '#10b981' : (distanceOut <= MAX_DISTANCE*2 ? '#f59e0b' : '#ef4444')};">
                                    ${distanceOut <= MAX_DISTANCE ? '✓ Within allowed range' : '⚠️ Outside allowed range'}
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            if (!latIn && !latOut) {
                content += '<p style="text-align: center; color: #6b7280;">No location data available for this date</p>';
            }
            
            document.getElementById('locationDetailsContent').innerHTML = content;
            document.getElementById('locationModal').style.display = 'flex';
        }
        
        // View day details
        function viewDayDetails(date, dayName) {
            const formattedDate = new Date(date).toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Find the row with this date
            const rows = document.querySelectorAll('.attendance-row');
            let rowData = null;
            
            rows.forEach(row => {
                if (row.dataset.date === date) {
                    const cells = row.querySelectorAll('td');
                    rowData = {
                        timeIn: cells[2].innerText,
                        timeOut: cells[3].innerText,
                        duration: cells[4].innerText,
                        distance: cells[5].innerText,
                        status: row.dataset.status
                    };
                }
            });
            
            let content = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #1a1a1a; margin-bottom: 10px;">${formattedDate}</h4>
                    <p style="color: #666; margin-bottom: 20px;">Attendance details for this day at ${LOCATION_NAME}</p>
                </div>
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
            `;
            
            if (rowData) {
                content += `
                    <div class="detail-row">
                        <div class="detail-label">Time In:</div>
                        <div class="detail-value">${rowData.timeIn || 'Not recorded'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Time Out:</div>
                        <div class="detail-value">${rowData.timeOut || 'Not recorded'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Duration:</div>
                        <div class="detail-value">${rowData.duration || '-'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Average Distance:</div>
                        <div class="detail-value">${rowData.distance || '-'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Location:</div>
                        <div class="detail-value">${LOCATION_NAME}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Allowed Radius:</div>
                        <div class="detail-value">${MAX_DISTANCE} meters</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            ${rowData.status === 'completed' ? 
                                '<span class="status-badge status-certified">Completed</span>' : 
                                rowData.status === 'timed_in_only' ? 
                                '<span class="status-badge status-ongoing">Timed In Only</span>' : 
                                '<span class="status-badge status-dropout">Incomplete</span>'}
                        </div>
                    </div>
                `;
            } else {
                content += '<p style="text-align: center; color: #6b7280;">No attendance data available for this date</p>';
            }
            
            content += '</div>';
            
            document.getElementById('dayDetailsContent').innerHTML = content;
            document.getElementById('dayDetailsModal').style.display = 'flex';
        }
        
        // Modal functions
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        };
    </script>
</body>
</html>