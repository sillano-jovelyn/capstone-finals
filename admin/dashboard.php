<?php
// Start session at the very beginning to avoid headers already sent errors
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get current user info
$user_id = $_SESSION['user_id'];
$current_user = null;

try {
    $user_query = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    
    if ($user_result->num_rows > 0) {
        $current_user = $user_result->fetch_assoc();
    } else {
        // User not found or not admin
        session_destroy();
        header("Location: ../login.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching user: " . $e->getMessage());
    header("Location: ../login.php");
    exit();
}

// Handle AJAX search request
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    if (isset($_POST['action']) && $_POST['action'] === 'search_dashboard' && isset($_POST['search_term'])) {
        $searchTerm = trim($_POST['search_term']);
        
        if (empty($searchTerm)) {
            echo json_encode(['success' => false, 'message' => 'Search term is required']);
            exit();
        }
        
        $results = [
            'programs' => [],
            'users' => [],
            'enrollments' => [],
            'trainees' => []
        ];
        
        try {
            $searchTermWildcard = "%" . $searchTerm . "%";
            
            // 1. Search in programs table
            $programQuery = "
                SELECT 
                    p.*,
                    COALESCE(u.fullname, p.trainer, CONCAT('Trainer ID: ', p.trainer_id)) as trainer_name,
                    (
                        SELECT COUNT(DISTINCT user_id) 
                        FROM enrollments 
                        WHERE program_id = p.id
                    ) as enrolled_count
                FROM programs p
                LEFT JOIN users u ON p.trainer_id = u.id AND u.role = 'trainer'
                WHERE (p.name LIKE ? 
                       OR p.trainer LIKE ?
                       OR p.status LIKE ?
                       OR p.other_trainer LIKE ?)
                  AND p.is_archived = 0
                ORDER BY p.created_at DESC
                LIMIT 10
            ";
            
            $stmt = $conn->prepare($programQuery);
            $stmt->bind_param("ssss", $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, $searchTermWildcard);
            $stmt->execute();
            $programResult = $stmt->get_result();
            
            while ($row = $programResult->fetch_assoc()) {
                $total_slots = $row['total_slots'] > 0 ? $row['total_slots'] : 'Unlimited';
                $available_slots = $row['slotsAvailable'] ?? ($row['total_slots'] - $row['enrolled_count']);
                
                $results['programs'][] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'description' => $row['other_trainer'] ?? 'No description available',
                    'status' => $row['status'],
                    'date' => isset($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'Unknown',
                    'trainer' => $row['trainer_name'] ?? 'Not assigned',
                    'enrolled' => $row['enrolled_count'] ?? 0,
                    'duration' => ($row['duration'] ?? 'N/A') . ' ' . ($row['duration_unit'] ?? 'Days'),
                    'available_slots' => $available_slots,
                    'total_slots' => $total_slots,
                    'schedule_start' => isset($row['scheduleStart']) ? date('M d, Y', strtotime($row['scheduleStart'])) : 'Not Set',
                    'schedule_end' => isset($row['scheduleEnd']) ? date('M d, Y', strtotime($row['scheduleEnd'])) : 'Not Set'
                ];
            }
            $stmt->close();
            
            // 2. Search in users table (all users: admins, trainers, trainees)
            $userQuery = "
                SELECT 
                    u.*,
                    DATE_FORMAT(u.date_created, '%b %d, %Y') as formatted_date
                FROM users u
                WHERE (u.fullname LIKE ? 
                       OR u.email LIKE ? 
                       OR u.role LIKE ? 
                       OR u.status LIKE ? 
                       OR u.program LIKE ? 
                       OR u.specialization LIKE ?)
                ORDER BY u.date_created DESC
                LIMIT 15
            ";
            
            $stmt = $conn->prepare($userQuery);
            $stmt->bind_param("ssssss", $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, 
                             $searchTermWildcard, $searchTermWildcard, $searchTermWildcard);
            $stmt->execute();
            $userResult = $stmt->get_result();
            
            while ($row = $userResult->fetch_assoc()) {
                $userData = [
                    'id' => $row['id'],
                    'fullname' => $row['fullname'] ?? $row['full_name'] ?? 'Unknown',
                    'email' => $row['email'] ?? 'No email',
                    'role' => $row['role'],
                    'status' => $row['status'],
                    'phone' => 'N/A',
                    'date_registered' => $row['formatted_date'] ?? 'Unknown',
                    'specialization' => $row['specialization'] ?? ($row['role'] === 'trainer' ? 'Not specified' : ''),
                    'program' => $row['program'] ?? 'None'
                ];
                
                $results['users'][] = $userData;
                
                // If user is a trainee, also add to trainees results
                if ($row['role'] === 'trainee') {
                    $results['trainees'][] = $userData;
                }
            }
            $stmt->close();
            
            // 3. Search in enrollments table
            $enrollmentQuery = "
                SELECT 
                    e.*,
                    p.name as program_name,
                    COALESCE(u.fullname, t.fullname, CONCAT('User ID: ', e.user_id)) as trainee_name,
                    DATE_FORMAT(e.enrollment_date, '%b %d, %Y') as formatted_date,
                    e.enrollment_status as status
                FROM enrollments e
                LEFT JOIN programs p ON e.program_id = p.id
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN trainees t ON e.user_id = t.id
                WHERE (p.name LIKE ? 
                       OR u.fullname LIKE ? 
                       OR t.fullname LIKE ?
                       OR e.status LIKE ? 
                       OR e.enrollment_status LIKE ?
                       OR e.assessment LIKE ?)
                ORDER BY e.enrollment_date DESC
                LIMIT 10
            ";
            
            $stmt = $conn->prepare($enrollmentQuery);
            $stmt->bind_param("ssssss", $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, 
                             $searchTermWildcard, $searchTermWildcard, $searchTermWildcard);
            $stmt->execute();
            $enrollmentResult = $stmt->get_result();
            
            while ($row = $enrollmentResult->fetch_assoc()) {
                $results['enrollments'][] = [
                    'id' => $row['id'],
                    'program_name' => $row['program_name'] ?? 'Unknown Program',
                    'trainee_name' => $row['trainee_name'] ?? 'Unknown Trainee',
                    'date_applied' => $row['formatted_date'] ?? 'Unknown',
                    'status' => $row['status'] ?? 'pending',
                    'enrollment_type' => 'Regular',
                    'attendance' => $row['attendance'] ?? 0,
                    'assessment' => $row['assessment'] ?? 'Not assessed'
                ];
            }
            $stmt->close();
            
            // 4. Search in trainees table (detailed trainee information)
            $traineeQuery = "
                SELECT 
                    t.*,
                    DATE_FORMAT(t.created_at, '%b %d, %Y') as formatted_date,
                    u.email,
                    u.status as user_status,
                    u.role
                FROM trainees t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE (t.fullname LIKE ? 
                       OR t.email LIKE ? 
                       OR t.address LIKE ? 
                       OR t.gender LIKE ?
                       OR t.civil_status LIKE ?
                       OR t.employment_status LIKE ?
                       OR t.education LIKE ?
                       OR t.house_street LIKE ?
                       OR t.barangay LIKE ?
                       OR t.municipality LIKE ?
                       OR t.city LIKE ?)
                ORDER BY t.created_at DESC
                LIMIT 10
            ";
            
            $stmt = $conn->prepare($traineeQuery);
            $stmt->bind_param("sssssssssss", $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, 
                             $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, $searchTermWildcard,
                             $searchTermWildcard, $searchTermWildcard, $searchTermWildcard, $searchTermWildcard);
            $stmt->execute();
            $traineeResult = $stmt->get_result();
            
            while ($row = $traineeResult->fetch_assoc()) {
                $traineeData = [
                    'id' => $row['id'],
                    'fullname' => $row['fullname'] ?? ($row['firstname'] . ' ' . $row['lastname']) ?? 'Unknown',
                    'email' => $row['email'] ?? 'No email',
                    'role' => $row['role'] ?? 'trainee',
                    'status' => $row['user_status'] ?? 'Active',
                    'user_id' => $row['user_id'] ?? 'N/A',
                    'phone' => $row['contact_number'] ?? 'Not provided',
                    'date_registered' => $row['formatted_date'] ?? 'Unknown',
                    'address' => $row['address'] ?? ($row['house_street'] . ', ' . $row['barangay'] . ', ' . $row['municipality'] . ', ' . $row['city']) ?? 'Not specified',
                    'gender' => $row['gender'] ?? 'Not specified',
                    'age' => $row['age'] ?? 'N/A',
                    'education' => $row['education'] ?? 'Not specified',
                    'employment_status' => $row['employment_status'] ?? 'Not specified'
                ];
                
                // Check if trainee already exists in results
                $exists = false;
                foreach ($results['trainees'] as $existingTrainee) {
                    if (isset($existingTrainee['user_id']) && $existingTrainee['user_id'] == $traineeData['user_id']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $results['trainees'][] = $traineeData;
                }
            }
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'results' => $results,
                'count' => count($results['programs']) + count($results['users']) + count($results['enrollments']) + count($results['trainees'])
            ]);
            
        } catch (Exception $e) {
            error_log("Search error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'An error occurred during search: ' . $e->getMessage()
            ]);
        }
        
        $conn->close();
        exit();
    }
}

// Function to log session login
function logSessionLogin($conn, $user_id) {
    try {
        // Check if sessions table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'sessions'");
        if ($table_check->num_rows > 0) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // First, update any existing active sessions to logged_out
            $cleanup_query = "UPDATE sessions 
                            SET logout_time = NOW(), status = 'logged_out' 
                            WHERE user_id = ? AND status = 'active' AND logout_time IS NULL";
            $cleanup_stmt = $conn->prepare($cleanup_query);
            $cleanup_stmt->bind_param("i", $user_id);
            $cleanup_stmt->execute();
            
            // Now insert new session
            $query = "INSERT INTO sessions (user_id, login_time, ip_address, user_agent, status) 
                     VALUES (?, NOW(), ?, ?, 'active')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iss", $user_id, $ip_address, $user_agent);
            $stmt->execute();
            
            $session_id = $stmt->insert_id;
            
            // Store session_id in PHP session for logout tracking
            $_SESSION['db_session_id'] = $session_id;
            
            // Also store in a cookie or session for better persistence
            setcookie('db_session_id', $session_id, time() + (86400 * 30), "/"); // 30 days
            
            return $session_id;
        }
    } catch (Exception $e) {
        error_log("Error logging session login: " . $e->getMessage());
    }
    return false;
}

// Function to log session logout - UPDATED with session_id parameter
function logSessionLogout($conn, $user_id, $session_id = null) {
    try {
        // Check if sessions table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'sessions'");
        if ($table_check->num_rows > 0) {
            
            // Try to get session_id from multiple sources
            if (empty($session_id)) {
                if (isset($_SESSION['db_session_id'])) {
                    $session_id = $_SESSION['db_session_id'];
                } elseif (isset($_COOKIE['db_session_id'])) {
                    $session_id = $_COOKIE['db_session_id'];
                }
            }
            
            if (!empty($session_id)) {
                // Update specific session by session ID
                $query = "UPDATE sessions 
                         SET logout_time = NOW(), status = 'logged_out' 
                         WHERE id = ? AND user_id = ? AND logout_time IS NULL";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $session_id, $user_id);
                $stmt->execute();
                
                $affected = $stmt->affected_rows;
                
                // If no rows affected, try the fallback
                if ($affected === 0) {
                    // Fallback: Update the most recent active session for this user
                    $query = "UPDATE sessions 
                             SET logout_time = NOW(), status = 'logged_out' 
                             WHERE user_id = ? AND logout_time IS NULL 
                             ORDER BY login_time DESC 
                             LIMIT 1";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                }
            } else {
                // No session_id available, update most recent
                $query = "UPDATE sessions 
                         SET logout_time = NOW(), status = 'logged_out' 
                         WHERE user_id = ? AND logout_time IS NULL 
                         ORDER BY login_time DESC 
                         LIMIT 1";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
            }
            
            // Clean up session data
            unset($_SESSION['db_session_id']);
            setcookie('db_session_id', '', time() - 3600, "/"); // Delete cookie
            
            return $affected;
        }
    } catch (Exception $e) {
        error_log("Error logging session logout: " . $e->getMessage());
    }
    return false;
}

// Function to log admin activity
function logAdminActivity($conn, $user_id, $activity_type, $details = null) {
    try {
        // Check if activity_logs table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        
        if ($table_check->num_rows > 0) {
            // Use activity_logs table
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Serialize details if array
            $details_json = null;
            if (is_array($details)) {
                $details_json = json_encode($details);
            } elseif ($details !== null) {
                $details_json = $details;
            }
            
            $query = "INSERT INTO activity_logs (user_id, activity_type, activity_details, ip_address, user_agent) 
                     VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("issss", $user_id, $activity_type, $details_json, $ip_address, $user_agent);
            $stmt->execute();
            
            return $stmt->insert_id;
        } else {
            // Fallback: Use sessions table
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $query = "INSERT INTO sessions (user_id, login_time, ip_address, user_agent, status) 
                     VALUES (?, NOW(), ?, ?, ?)";
            $stmt = $conn->prepare($query);
            
            $status = "activity: " . $activity_type;
            $stmt->bind_param("isss", $user_id, $ip_address, $user_agent, $status);
            $stmt->execute();
            
            return $stmt->insert_id;
        }
    } catch (Exception $e) {
        error_log("Error logging admin activity: " . $e->getMessage());
    }
    return false;
}

// Static menu items
$menu_items = [
    'User Management',
    'Program Management', 
    'Enrollment Management',
    'Reports & Monitoring',
    'System Management'
];

// Initialize dashboard data with defaults
$dashboard_data = [
    'total_programs' => 0,
    'total_enrollments' => 0,
    'approved_trainees' => 0,
    'total_trainers' => 0,
    'ongoing_programs' => 0,
    
    'recent_programs' => [],
    'new_trainees' => [],
    'new_trainers' => [],
    'ongoing_programs_list' => [],
    'recent_activities' => [],
    'menu_items' => $menu_items,
    'current_user' => $current_user
];

// Function to safely execute queries
function safe_query($conn, $query, $default = 0) {
    try {
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row;
        }
    } catch (Exception $e) {
        // Silently fail and return default
        error_log("Query failed: " . $e->getMessage());
    }
    return ['count' => $default];
}

// Fetch dashboard statistics
try {
    // Check if programs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'programs'");
    if ($table_check->num_rows > 0) {
        $programs_count = safe_query($conn, "SELECT COUNT(*) as count FROM programs WHERE is_archived = 0 AND status = 'active'", 0);
        $dashboard_data['total_programs'] = $programs_count['count'];
        
        $ongoing_count = safe_query($conn, "SELECT COUNT(*) as count FROM programs WHERE status = 'active' AND scheduleEnd >= CURDATE() AND scheduleStart <= CURDATE()", 0);
        $dashboard_data['ongoing_programs'] = $ongoing_count['count'];
    }
    
    // Check if enrollments table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'enrollments'");
    if ($table_check->num_rows > 0) {
        $enrollments_count = safe_query($conn, "SELECT COUNT(*) as count FROM enrollments", 0);
        $dashboard_data['total_enrollments'] = $enrollments_count['count'];
    }
    
    // Check if users table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'users'");
    if ($table_check->num_rows > 0) {
        $trainees_count = safe_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'trainee' AND status = 'Active'", 0);
        $dashboard_data['approved_trainees'] = $trainees_count['count'];
        
        $trainers_count = safe_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'trainer' AND status = 'Active'", 0);
        $dashboard_data['total_trainers'] = $trainers_count['count'];
    }
} catch (Exception $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Fetch recent programs
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'programs'");
    if ($table_check->num_rows > 0) {
        $recent_programs_query = "
            SELECT 
                p.*,
                COALESCE(u.fullname, p.trainer, CONCAT('Trainer ID: ', p.trainer_id)) as trainer_name,
                (
                    SELECT COUNT(DISTINCT user_id) 
                    FROM enrollments 
                    WHERE program_id = p.id
                ) as enrolled_count
            FROM programs p
            LEFT JOIN users u ON p.trainer_id = u.id AND u.role = 'trainer'
            WHERE p.is_archived = 0
            ORDER BY p.created_at DESC 
            LIMIT 3
        ";
        
        $recent_programs_result = $conn->query($recent_programs_query);
        if ($recent_programs_result && $recent_programs_result->num_rows > 0) {
            while ($row = $recent_programs_result->fetch_assoc()) {
                $enrolled_count = isset($row['enrolled_count']) ? (int)$row['enrolled_count'] : 0;
                $total_slots = isset($row['total_slots']) && $row['total_slots'] > 0 ? $row['total_slots'] : null;
                $capacity = $total_slots ?? 'Unlimited';
                
                if ($total_slots !== null) {
                    $available_slots = max(0, $total_slots - $enrolled_count);
                } else {
                    $available_slots = 'Unlimited';
                }
                
                $trainer_name = 'Not Assigned';
                if (isset($row['trainer_name']) && !empty($row['trainer_name'])) {
                    $trainer_name = $row['trainer_name'];
                } elseif (isset($row['trainer']) && !empty($row['trainer'])) {
                    $trainer_name = $row['trainer'];
                } elseif (isset($row['trainer_id']) && $row['trainer_id'] > 0) {
                    $trainer_name = 'Trainer ID: ' . $row['trainer_id'];
                }
                
                $dashboard_data['recent_programs'][] = [
                    'id' => $row['id'],
                    'name' => $row['name'] ?? 'Unknown Program',
                    'status' => $row['status'] ?? 'active',
                    'date' => isset($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : date('M d, Y'),
                    'enrolled' => $enrolled_count,
                    'capacity' => $capacity,
                    'available_slots' => $available_slots,
                    'duration' => ($row['duration'] ?? 0) . ' ' . ($row['duration_unit'] ?? 'Days'),
                    'trainer' => $trainer_name,
                    'schedule_start' => isset($row['scheduleStart']) ? date('M d, Y', strtotime($row['scheduleStart'])) : 'Not Set',
                    'schedule_end' => isset($row['scheduleEnd']) ? date('M d, Y', strtotime($row['scheduleEnd'])) : 'Not Set'
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching recent programs: " . $e->getMessage());
}

// Fetch newly registered trainees from users table
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'users'");
    if ($table_check->num_rows > 0) {
        $new_trainees_query = "
            SELECT u.fullname, u.date_created
            FROM users u
            WHERE u.role = 'trainee' 
            AND u.status = 'Active'
            ORDER BY u.date_created DESC
            LIMIT 5
        ";
        
        $new_trainees_result = $conn->query($new_trainees_query);
        if ($new_trainees_result && $new_trainees_result->num_rows > 0) {
            while ($row = $new_trainees_result->fetch_assoc()) {
                $dashboard_data['new_trainees'][] = [
                    'name' => $row['fullname'] ?? 'Unknown Trainee',
                    'date_registered' => isset($row['date_created']) ? date('M d, Y', strtotime($row['date_created'])) : 'Unknown'
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching new trainees: " . $e->getMessage());
}

// Fetch new trainers
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'users'");
    if ($table_check->num_rows > 0) {
        $new_trainers_query = "
            SELECT u.fullname, u.date_created, u.specialization
            FROM users u
            WHERE u.role = 'trainer' 
            AND u.status = 'Active'
            ORDER BY u.date_created DESC
            LIMIT 5
        ";
        
        $new_trainers_result = $conn->query($new_trainers_query);
        if ($new_trainers_result && $new_trainers_result->num_rows > 0) {
            while ($row = $new_trainers_result->fetch_assoc()) {
                $dashboard_data['new_trainers'][] = [
                    'name' => $row['fullname'],
                    'specialization' => $row['specialization'] ?? 'Not Specified',
                    'date_registered' => isset($row['date_created']) ? date('M d, Y', strtotime($row['date_created'])) : 'Unknown'
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching new trainers: " . $e->getMessage());
}

// Fetch ongoing programs
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'programs'");
    if ($table_check->num_rows > 0) {
        $ongoing_programs_query = "
            SELECT 
                p.*,
                COALESCE(u.fullname, p.trainer, CONCAT('Trainer ID: ', p.trainer_id)) as trainer_name,
                (
                    SELECT COUNT(DISTINCT user_id) 
                    FROM enrollments 
                    WHERE program_id = p.id
                ) as enrolled_count
            FROM programs p
            LEFT JOIN users u ON p.trainer_id = u.id AND u.role = 'trainer'
            WHERE p.status = 'active'
            AND p.scheduleEnd >= CURDATE()
            AND p.scheduleStart <= CURDATE()
            ORDER BY p.scheduleStart ASC
            LIMIT 5
        ";
        
        $ongoing_programs_result = $conn->query($ongoing_programs_query);
        if ($ongoing_programs_result && $ongoing_programs_result->num_rows > 0) {
            while ($row = $ongoing_programs_result->fetch_assoc()) {
                $progress = 0;
                if (isset($row['scheduleStart']) && isset($row['scheduleEnd'])) {
                    $start_time = strtotime($row['scheduleStart']);
                    $end_time = strtotime($row['scheduleEnd']);
                    $total_days = ($end_time - $start_time) / (60 * 60 * 24);
                    $current_time = time();
                    $days_passed = ($current_time - $start_time) / (60 * 60 * 24);
                    
                    if ($total_days > 0) {
                        $progress = min(100, max(0, ($days_passed / $total_days) * 100));
                    }
                }
                
                $trainer_name = 'Not Assigned';
                if (isset($row['trainer_name']) && !empty($row['trainer_name'])) {
                    $trainer_name = $row['trainer_name'];
                } elseif (isset($row['trainer']) && !empty($row['trainer'])) {
                    $trainer_name = $row['trainer'];
                } elseif (isset($row['trainer_id']) && $row['trainer_id'] > 0) {
                    $trainer_name = 'Trainer ID: ' . $row['trainer_id'];
                }
                
                $enrolled_count = isset($row['enrolled_count']) ? (int)$row['enrolled_count'] : 0;
                
                $dashboard_data['ongoing_programs_list'][] = [
                    'name' => $row['name'] ?? 'Unknown Program',
                    'trainer' => $trainer_name,
                    'enrolled' => $enrolled_count,
                    'schedule_start' => isset($row['scheduleStart']) ? date('M d, Y', strtotime($row['scheduleStart'])) : 'Not Set',
                    'schedule_end' => isset($row['scheduleEnd']) ? date('M d, Y', strtotime($row['scheduleEnd'])) : 'Not Set',
                    'days_remaining' => isset($row['scheduleEnd']) ? max(0, floor((strtotime($row['scheduleEnd']) - time()) / (60 * 60 * 24))) : 0,
                    'progress' => round($progress, 1)
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching ongoing programs: " . $e->getMessage());
}



// Close database connection
$conn->close();

// Include header component
include __DIR__ . '/../components/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livelihood Enrollment & Monitoring System - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --accent: #2ecc71;
            --warning: #e74c3c;
            --info: #9b59b6;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --gray: #95a5a6;
            --success: #27ae60;
        }
        
        /* Animation Keyframes */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translate3d(0, 30px, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translate3d(50px, 0, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }
        
        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }
        
        /* Stats Cards - Full Width with Animation */
       /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            border-top: 4px solid var(--secondary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card:nth-child(1) { border-top-color: var(--secondary); }
        .stat-card:nth-child(2) { border-top-color: var(--accent); }
        .stat-card:nth-child(3) { border-top-color: #f39c12; }
        .stat-card:nth-child(4) { border-top-color: var(--info); }
        .stat-card:nth-child(5) { border-top-color: var(--warning); }
        
        .stat-card h3 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-card .change {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .change.positive {
            color: var(--accent);
        }
        
       
        
        /* Search Bar with Animation */
        .search-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 25px;
            animation: fadeInUp 0.7s ease-out 0.6s forwards;
            opacity: 0;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .search-box {
            flex: 1;
            min-width: 300px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 18px 25px 18px 60px;
            border: 2px solid #e0e6ed;
            border-radius: 50px;
            font-size: 1.05rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            background: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 
                0 0 0 4px rgba(52, 152, 219, 0.15),
                0 10px 25px rgba(52, 152, 219, 0.1);
            transform: translateY(-2px);
        }
        
        .search-box i {
            position: absolute;
            left: 25px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus + i {
            color: var(--secondary);
            transform: translateY(-50%) scale(1.1);
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--secondary) 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9 0%, var(--secondary) 100%);
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.4);
        }
        
        /* Dashboard Sections - Full Width Cards */
        .dashboard-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .dashboard-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        /* Stagger animations for dashboard cards */
        .dashboard-card:nth-child(1) { animation-delay: 0.7s; }
        .dashboard-card:nth-child(2) { animation-delay: 0.8s; }
        .dashboard-card:nth-child(3) { animation-delay: 0.9s; }
        .dashboard-card:nth-child(4) { animation-delay: 1.0s; }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, #34495e 100%);
            color: white;
            padding: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .card-header h3 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 0;
        }
        
        .card-header i {
            font-size: 1.5rem;
            transition: transform 0.3s ease;
        }
        
        .dashboard-card:hover .card-header i {
            transform: scale(1.1) rotate(5deg);
        }
        
        .card-content {
            padding: 25px;
            max-height: 400px;
            overflow-y: auto;
            animation: fadeIn 0.5s ease-out 0.2s forwards;
            opacity: 0;
        }
        
        /* Scrollbar Styling */
        .card-content::-webkit-scrollbar {
            width: 6px;
        }
        
        .card-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .card-content::-webkit-scrollbar-thumb {
            background: var(--gray);
            border-radius: 10px;
        }
        
        .card-content::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }
        
        /* Activity List with Animation */
        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 18px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            animation: slideInRight 0.5s ease-out forwards;
            opacity: 0;
            transform: translateX(20px);
        }
        
        /* Stagger animation for activity items */
        .activity-item:nth-child(1) { animation-delay: 0.1s; }
        .activity-item:nth-child(2) { animation-delay: 0.2s; }
        .activity-item:nth-child(3) { animation-delay: 0.3s; }
        .activity-item:nth-child(4) { animation-delay: 0.4s; }
        .activity-item:nth-child(5) { animation-delay: 0.5s; }
        
        .activity-item:hover {
            background: rgba(52, 152, 219, 0.03);
            border-radius: 8px;
            padding-left: 10px;
            padding-right: 10px;
            transform: translateX(5px);
        }
        
        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover .activity-icon {
            transform: scale(1.1);
        }
        
        .activity-icon.success {
            background: linear-gradient(135deg, rgba(46, 204, 113, 0.2) 0%, rgba(46, 204, 113, 0.1) 100%);
            color: var(--accent);
        }
        
        .activity-icon.info {
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.2) 0%, rgba(52, 152, 219, 0.1) 100%);
            color: var(--secondary);
        }
        
        .activity-icon.primary {
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.2) 0%, rgba(155, 89, 182, 0.1) 100%);
            color: var(--info);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
            line-height: 1.4;
        }
        
        .activity-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* Program Progress WITHOUT Animation */
        .program-progress {
            margin-bottom: 20px;
            padding: 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 5px solid var(--secondary);
        }
        
        .program-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .program-name {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .program-trainer {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .progress-container {
            margin-top: 15px;
        }
        
        .progress-bar {
            height: 10px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 5px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent) 0%, #27ae60 100%);
            border-radius: 5px;
            position: relative;
            /* No animation transition */
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 8px;
        }
        
        /* Tables with Animation */
        table {
            width: 100%;
            border-collapse: collapse;
            animation: fadeIn 0.8s ease-out forwards;
            opacity: 0;
        }
        
        th {
            text-align: left;
            padding: 15px 12px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.1);
            color: var(--dark);
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(0, 0, 0, 0.02);
        }
        
        td {
            padding: 15px 12px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        tr {
            animation: slideInRight 0.5s ease-out forwards;
            opacity: 0;
            transform: translateX(10px);
        }
        
        /* Stagger animation for table rows */
        tr:nth-child(1) { animation-delay: 0.1s; }
        tr:nth-child(2) { animation-delay: 0.2s; }
        tr:nth-child(3) { animation-delay: 0.3s; }
        tr:nth-child(4) { animation-delay: 0.4s; }
        tr:nth-child(5) { animation-delay: 0.5s; }
        
        tr:hover td {
            background: rgba(52, 152, 219, 0.05);
            transform: scale(1.01);
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        /* Badges with Animation */
        .badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            animation: fadeIn 0.5s ease-out forwards;
            opacity: 0;
        }
        
        .badge-success {
            background: linear-gradient(135deg, var(--accent) 0%, #27ae60 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.3);
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(241, 196, 15, 0.3);
        }
        
        .badge-info {
            background: linear-gradient(135deg, var(--secondary) 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }
        
        .badge-danger {
            background: linear-gradient(135deg, var(--warning) 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }
        
        .badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        /* Dashboard Title Animation */
        .dashboard-title {
            margin: 20px 0 40px 0;
            text-align: center;
            animation: fadeInUp 0.8s ease-out forwards;
            opacity: 0;
        }
        
        .dashboard-title h1 {
            color: var(--primary);
            font-size: 2.5rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-title p {
            color: var(--gray);
            font-size: 1.2rem;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        /* Search Results with Animation */
        .search-results-container {
            display: none;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(0, 0, 0, 0.08);
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
        }
        
        .search-results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.08);
        }
        
        .search-results-header h3 {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
        }
        
        .results-count {
            background: linear-gradient(135deg, var(--secondary) 0%, #2980b9 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            animation: pulse 2s infinite;
        }
        
        .search-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            padding-bottom: 15px;
            overflow-x: auto;
        }
        
        .search-tab {
            padding: 10px 25px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 0.9rem;
            border-radius: 25px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            color: var(--gray);
            white-space: nowrap;
            font-weight: 600;
        }
        
        .search-tab.active {
            background: linear-gradient(135deg, var(--secondary) 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
            transform: translateY(-2px);
        }
        
        .search-tab:hover:not(.active) {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .results-section {
            display: none;
            animation: fadeIn 0.5s ease-out forwards;
            opacity: 0;
        }
        
        .results-section.active {
            display: block;
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .result-item {
            padding: 20px;
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.05);
            border-radius: 12px;
            margin-bottom: 15px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: fadeInUp 0.5s ease-out forwards;
            opacity: 0;
            transform: translateY(10px);
        }
        
        /* Stagger animation for result items */
        .result-item:nth-child(1) { animation-delay: 0.1s; }
        .result-item:nth-child(2) { animation-delay: 0.2s; }
        .result-item:nth-child(3) { animation-delay: 0.3s; }
        .result-item:nth-child(4) { animation-delay: 0.4s; }
        .result-item:nth-child(5) { animation-delay: 0.5s; }
        
        .result-item:hover {
            background: rgba(52, 152, 219, 0.03);
            border-color: var(--secondary);
            transform: translateY(-5px) translateX(10px);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.1);
        }
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            animation: fadeIn 0.8s ease-out forwards;
            opacity: 0;
        }
        
        .no-results i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: float 3s ease-in-out infinite;
        }
        
        /* Mobile Responsive */
        @media (max-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            }
            
            .dashboard-sections {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-sections {
                grid-template-columns: 1fr;
            }
            
            .stat-card .value {
                font-size: 2.2rem;
            }
        }
        
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .search-container {
                flex-direction: column;
                align-items: stretch;
                padding: 25px;
            }
            
            .search-tabs {
                flex-wrap: wrap;
            }
            
            .dashboard-title h1 {
                font-size: 2rem;
            }
            
            .card-header {
                padding: 20px;
            }
            
            .card-content {
                padding: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .stat-card .value {
                font-size: 2rem;
            }
            
            .btn {
                padding: 12px 20px;
                font-size: 0.9rem;
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-title h1 {
                font-size: 1.8rem;
            }
            
            .dashboard-title p {
                font-size: 1rem;
            }
            
            .stat-card {
                padding: 25px 20px;
            }
            
            .search-container {
                padding: 20px;
            }
        }
        
        /* Loading Animation */
        .loading {
            position: relative;
            overflow: hidden;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(255, 255, 255, 0.6) 50%,
                transparent 100%
            );
            animation: shimmer 1.5s infinite linear;
        }
    </style>
</head>
<body>  
   
    <!-- Stats Cards -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-project-diagram"></i>
            <h3>Total Programs</h3>
            <div class="value"><?php echo $dashboard_data['total_programs']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3>Total Enrollments</h3>
            <div class="value"><?php echo $dashboard_data['total_enrollments']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-user-graduate"></i>
            <h3>Active Trainees</h3>
            <div class="value"><?php echo $dashboard_data['approved_trainees']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-chalkboard-teacher"></i>
            <h3>Active Trainers</h3>
            <div class="value"><?php echo $dashboard_data['total_trainers']; ?></div>
        </div>
    </div>
    
    <!-- Search Bar -->
    <div class="search-container">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search across everything: trainees, programs, enrollments, users...">
        </div>
        <button class="btn btn-primary" id="searchBtn">
            <i class="fas fa-search"></i> Search Everything
        </button>
    </div>
    
    <!-- Search Results Container -->
    <div id="searchResults" class="search-results-container">
        <div class="search-results-header">
            <h3>Search Results</h3>
            <span class="results-count" id="resultsCount">0 results</span>
        </div>
        
        <div class="search-tabs">
            <button class="search-tab active" data-tab="all">All Results</button>
            <button class="search-tab" data-tab="programs">Programs</button>
            <button class="search-tab" data-tab="users">Users</button>
            <button class="search-tab" data-tab="trainees">Trainees</button>
            <button class="search-tab" data-tab="enrollments">Enrollments</button>
        </div>
        
        <div class="results-section active" id="resultsAll">
            <!-- All results will be populated here -->
        </div>
        
        <div class="results-section" id="resultsPrograms">
            <!-- Program results will be populated here -->
        </div>
        
        <div class="results-section" id="resultsUsers">
            <!-- User results will be populated here -->
        </div>
        
        <div class="results-section" id="resultsTrainees">
            <!-- Trainee results will be populated here -->
        </div>
        
        <div class="results-section" id="resultsEnrollments">
            <!-- Enrollment results will be populated here -->
        </div>
    </div>
    
    <!-- Dashboard Sections -->
    <div class="dashboard-sections">
        
        <!-- Ongoing Programs -->

        <!-- Newly Registered Trainees -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-user-graduate"></i> New Trainees</h3>
                <span class="badge badge-info">Recently Added</span>
            </div>
            <div class="card-content">
                <?php if (!empty($dashboard_data['new_trainees'])): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboard_data['new_trainees'] as $trainee): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($trainee['name']); ?></td>
                            <td style="font-size: 0.85rem; color: var(--gray);"><?php echo $trainee['date_registered']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-user-graduate"></i>
                    <p>No new trainees registered recently</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- New Trainers -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chalkboard-teacher"></i> New Trainers</h3>
                <span class="badge badge-warning">Recently Added</span>
            </div>
            <div class="card-content">
                <?php if (!empty($dashboard_data['new_trainers'])): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Specialization</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboard_data['new_trainers'] as $trainer): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($trainer['name']); ?></td>
                            <td><span style="background: linear-gradient(135deg, rgba(241, 196, 15, 0.2) 0%, rgba(241, 196, 15, 0.1) 100%); padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;"><?php echo htmlspecialchars($trainer['specialization']); ?></span></td>
                            <td style="font-size: 0.85rem; color: var(--gray);"><?php echo $trainer['date_registered']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <p>No new trainers added recently</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Programs -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-project-diagram"></i> Recent Programs</h3>
                <span class="badge badge-success">New</span>
            </div>
            <div class="card-content">
                <?php if (!empty($dashboard_data['recent_programs'])): ?>
                <?php foreach ($dashboard_data['recent_programs'] as $program): ?>
                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(0, 0, 0, 0.08); transition: all 0.3s ease; padding: 15px; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <h4 style="color: var(--primary); margin: 0; font-weight: 700;"><?php echo htmlspecialchars($program['name']); ?></h4>
                        <span class="badge badge-success"><?php echo $program['status']; ?></span>
                    </div>
                    <p style="color: var(--gray); margin-bottom: 8px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px;">
                        <span><i class="fas fa-calendar"></i> <?php echo $program['date']; ?></span>
                        <span><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($program['trainer']); ?></span>
                    </p>
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--gray);">
                        <div><strong><?php echo $program['enrolled']; ?></strong> Enrolled</div>
                        <div><strong><?php echo $program['available_slots']; ?></strong> Available</div>
                        <div><strong><?php echo $program['duration']; ?></strong></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-project-diagram"></i>
                    <p>No recent programs found</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
   

    <script>
        // DOM Elements
        const searchInput = document.getElementById('searchInput');
        const searchBtn = document.getElementById('searchBtn');
        const searchResults = document.getElementById('searchResults');
        const resultsCount = document.getElementById('resultsCount');
        
        // Tab elements
        const searchTabs = document.querySelectorAll('.search-tab');
        const resultsSections = {
            'all': document.getElementById('resultsAll'),
            'programs': document.getElementById('resultsPrograms'),
            'users': document.getElementById('resultsUsers'),
            'trainees': document.getElementById('resultsTrainees'),
            'enrollments': document.getElementById('resultsEnrollments')
        };
        
        // Initialize all animations on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation classes to elements
            const animatedElements = document.querySelectorAll('.stat-card, .search-container, .dashboard-card, .program-progress, .activity-item, tr');
            
            animatedElements.forEach(element => {
                if (element.style.animationName) {
                    // Reset animation to trigger it again
                    element.style.animation = 'none';
                    element.offsetHeight; // Trigger reflow
                    element.style.animation = null;
                }
            });
            
            // Add hover effect to cards
            const cards = document.querySelectorAll('.dashboard-card, .stat-card, .result-item');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Note: Removed the JavaScript that animated progress bars on scroll
        });
        
        // Search Functionality
        searchBtn.addEventListener('click', function() {
            const searchTerm = searchInput.value.trim();
            if (searchTerm) {
                // Animate search results container
                searchResults.style.display = 'block';
                searchResults.style.animation = 'fadeInUp 0.6s ease-out forwards';
                searchResults.style.opacity = '0';
                
                // Show loading state with animation
                const originalText = searchBtn.innerHTML;
                searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
                searchBtn.classList.add('loading');
                searchBtn.disabled = true;
                
                // Add loading animation to results container
                Object.values(resultsSections).forEach(section => {
                    section.innerHTML = `
                        <div class="no-results">
                            <i class="fas fa-spinner fa-spin fa-3x"></i>
                            <p>Searching for "${searchTerm}"...</p>
                        </div>
                    `;
                });
                
                // Perform actual search
                performSearch(searchTerm);
            } else {
                showNotification('Please enter a search term', 'warning');
                searchInput.focus();
                searchResults.style.display = 'none';
            }
        });
        
        // Perform search function
        async function performSearch(searchTerm) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=search_dashboard&search_term=${encodeURIComponent(searchTerm)}`
                });
                
                const data = await response.json();
                
                // Reset button
                searchBtn.innerHTML = '<i class="fas fa-search"></i> Search Everything';
                searchBtn.classList.remove('loading');
                searchBtn.disabled = false;
                
                if (data.success) {
                    // Update results count
                    resultsCount.textContent = `${data.count} results`;
                    
                    // Display all results
                    displayResults(data.results);
                } else {
                    showNotification(data.message || 'Search failed', 'error');
                    searchResults.style.display = 'none';
                }
            } catch (error) {
                console.error('Search error:', error);
                showNotification('Network error. Please try again.', 'error');
                searchBtn.innerHTML = '<i class="fas fa-search"></i> Search Everything';
                searchBtn.classList.remove('loading');
                searchBtn.disabled = false;
            }
        }
        
        // Display results function
        function displayResults(results) {
            // Clear all sections
            Object.values(resultsSections).forEach(section => {
                section.innerHTML = '';
            });
            
            // Display all results in respective tabs
            displayTabResults('all', results);
            displayTabResults('programs', {programs: results.programs});
            displayTabResults('users', {users: results.users});
            displayTabResults('trainees', {trainees: results.trainees});
            displayTabResults('enrollments', {enrollments: results.enrollments});
        }
        
        // Display tab results
        function displayTabResults(tabName, results) {
            const section = resultsSections[tabName];
            let content = '';
            
            if (tabName === 'all') {
                // Display all results combined
                const allResults = [
                    ...(results.programs || []).map(r => ({...r, type: 'program'})),
                    ...(results.users || []).map(r => ({...r, type: 'user'})),
                    ...(results.trainees || []).map(r => ({...r, type: 'trainee'})),
                    ...(results.enrollments || []).map(r => ({...r, type: 'enrollment'}))
                ];
                
                if (allResults.length === 0) {
                    content = '<div class="no-results"><i class="fas fa-search"></i><p>No results found</p></div>';
                } else {
                    allResults.forEach(item => {
                        content += createResultItem(item);
                    });
                }
            } else {
                const items = results[tabName] || [];
                if (items.length === 0) {
                    content = '<div class="no-results"><i class="fas fa-search"></i><p>No results found in this category</p></div>';
                } else {
                    items.forEach(item => {
                        content += createResultItem({...item, type: tabName.slice(0, -1)}); // Remove 's' from plural
                    });
                }
            }
            
            section.innerHTML = content;
        }
        
        // Create result item HTML
        function createResultItem(item) {
            let html = `<div class="result-item" data-type="${item.type}">`;
            
            switch(item.type) {
                case 'program':
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: var(--primary);">${item.name}</h4>
                            <span class="badge badge-success">${item.status}</span>
                        </div>
                        <p style="color: var(--gray); margin-bottom: 8px;">${item.description}</p>
                        <div style="display: flex; gap: 15px; font-size: 0.85rem; color: var(--gray);">
                            <span><i class="fas fa-calendar"></i> ${item.date}</span>
                            <span><i class="fas fa-user-tie"></i> ${item.trainer}</span>
                            <span><i class="fas fa-users"></i> ${item.enrolled} enrolled</span>
                        </div>
                    `;
                    break;
                    
                case 'user':
                case 'trainee':
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: var(--primary);">${item.fullname}</h4>
                            <span class="badge badge-info">${item.role}</span>
                        </div>
                        <p style="color: var(--gray); margin-bottom: 8px;">${item.email}</p>
                        <div style="display: flex; gap: 15px; font-size: 0.85rem; color: var(--gray);">
                            <span><i class="fas fa-calendar"></i> ${item.date_registered}</span>
                            <span><i class="fas fa-circle" style="color: ${item.status === 'Active' ? 'var(--accent)' : 'var(--gray)'};"></i> ${item.status}</span>
                            ${item.specialization ? `<span><i class="fas fa-graduation-cap"></i> ${item.specialization}</span>` : ''}
                        </div>
                    `;
                    break;
                    
                case 'enrollment':
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: var(--primary);">${item.trainee_name}</h4>
                            <span class="badge badge-warning">${item.status}</span>
                        </div>
                        <p style="color: var(--gray); margin-bottom: 8px;">Enrolled in: ${item.program_name}</p>
                        <div style="display: flex; gap: 15px; font-size: 0.85rem; color: var(--gray);">
                            <span><i class="fas fa-calendar"></i> ${item.date_applied}</span>
                            <span><i class="fas fa-chart-line"></i> ${item.attendance}% attendance</span>
                        </div>
                    `;
                    break;
            }
            
            html += `</div>`;
            return html;
        }
        
        // Tab switching functionality
        searchTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                
                // Update active tab
                searchTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Show active section
                Object.values(resultsSections).forEach(section => {
                    section.classList.remove('active');
                });
                resultsSections[tabName].classList.add('active');
            });
        });
        
        // Enter key search
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchBtn.click();
            }
        });
        
        // Show notification function with animation
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            
            // Set colors based on type
            let bgColor, icon;
            switch(type) {
                case 'success':
                    bgColor = '#2ecc71';
                    icon = 'check-circle';
                    break;
                case 'warning':
                    bgColor = '#f39c12';
                    icon = 'exclamation-triangle';
                    break;
                case 'error':
                    bgColor = '#e74c3c';
                    icon = 'times-circle';
                    break;
                default:
                    bgColor = '#3498db';
                    icon = 'info-circle';
            }
            
            notification.style.cssText = `
                position: fixed;
                top: 30px;
                right: 30px;
                background: linear-gradient(135deg, ${bgColor} 0%, ${type === 'success' ? '#27ae60' : type === 'warning' ? '#d35400' : type === 'error' ? '#c0392b' : '#2980b9'} 100%);
                color: white;
                padding: 18px 25px;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: slideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards, float 3s ease-in-out infinite 0.4s;
                display: flex;
                align-items: center;
                gap: 12px;
                max-width: 350px;
                opacity: 0;
                transform: translateX(100px);
                font-weight: 600;
            `;
            
            notification.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
            document.body.appendChild(notification);
            
            // Remove notification after 4 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-out forwards';
                setTimeout(() => {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 4000);
        }
        
        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { 
                    transform: translateX(100px); 
                    opacity: 0; 
                }
                to { 
                    transform: translateX(0); 
                    opacity: 1; 
                }
            }
            @keyframes slideOut {
                from { 
                    transform: translateX(0); 
                    opacity: 1; 
                }
                to { 
                    transform: translateX(100px); 
                    opacity: 0; 
                }
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .fa-spin {
                animation: spin 1s linear infinite;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>