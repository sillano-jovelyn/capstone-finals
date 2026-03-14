<?php
// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// ============================================
// AUTHENTICATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'Trainer';


// ============================================
// DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../db.php';

if (!$conn) {
    die("Database connection failed");
}

// ============================================
// ENSURE COLUMNS EXIST
// ============================================
$conn->query("ALTER TABLE assessment_components ADD COLUMN IF NOT EXISTS project_visible_to_trainee TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE assessment_components ADD COLUMN IF NOT EXISTS oral_questions_visible_to_trainee TINYINT(1) DEFAULT 0");

// Add results column to enrollments table if not exists
$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS results VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS assessment VARCHAR(20) DEFAULT NULL");

// ============================================
// CHECK IF PROGRAM SKILLS TABLE EXISTS
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS program_practical_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    skill_name VARCHAR(255) NOT NULL,
    max_score INT DEFAULT 20,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (program_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS program_oral_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    question TEXT NOT NULL,
    max_score INT DEFAULT 25,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (program_id)
)");

// ============================================
// TOGGLE VISIBILITY - SIMPLE LANG
// ============================================
if (isset($_GET['toggle_project'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value = isset($_GET['set']) ? intval($_GET['set']) : 1;
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'project';
    
    // Check if record exists
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    
    if ($check->num_rows > 0) {
        // Update existing
        $conn->query("UPDATE assessment_components SET project_visible_to_trainee = $new_value WHERE enrollment_id = $enrollment_id");
    } else {
        // Insert new
        $conn->query("INSERT INTO assessment_components (enrollment_id, project_visible_to_trainee, oral_max_score) VALUES ($enrollment_id, $new_value, 100)");
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=$tab");
    exit;
}

if (isset($_GET['toggle_oral'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value = isset($_GET['set']) ? intval($_GET['set']) : 1;
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'oral';
    
    // Check if record exists
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    
    if ($check->num_rows > 0) {
        // Update existing
        $conn->query("UPDATE assessment_components SET oral_questions_visible_to_trainee = $new_value WHERE enrollment_id = $enrollment_id");
    } else {
        // Insert new
        $conn->query("INSERT INTO assessment_components (enrollment_id, oral_questions_visible_to_trainee, oral_max_score) VALUES ($enrollment_id, $new_value, 100)");
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=$tab");
    exit;
}

// ============================================
// HANDLE TRAINEE PROJECT SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_project_trainee'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    // Verify na trainee ito
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
        header('Location: /login.php');
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    
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
    
    // I-redirect sa comprehensive assessment ng trainer
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=project&submitted=1");
    exit;
}

// ============================================
// SAVE PROGRAM SKILLS HANDLER
// ============================================
if (isset($_POST['save_program_skills'])) {
    $program_id = intval($_POST['program_id']);
    $skills_json = $_POST['program_skills'];
    
    // Delete existing skills for this program
    $conn->query("DELETE FROM program_practical_skills WHERE program_id = $program_id");
    
    // Insert new skills
    $skills = json_decode($skills_json, true);
    if (is_array($skills)) {
        $stmt = $conn->prepare("INSERT INTO program_practical_skills (program_id, skill_name, max_score, order_index) VALUES (?, ?, ?, ?)");
        foreach ($skills as $index => $skill) {
            $skill_name = $skill['name'];
            $max_score = $skill['max_score'];
            $stmt->bind_param("isii", $program_id, $skill_name, $max_score, $index);
            $stmt->execute();
        }
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=" . intval($_POST['enrollment_id']) . "&tab=practical&program_saved=1");
    exit;
}

// ============================================
// SAVE PROGRAM QUESTIONS HANDLER
// ============================================
if (isset($_POST['save_program_questions'])) {
    $program_id = intval($_POST['program_id']);
    $questions_json = $_POST['program_questions'];
    
    // Delete existing questions for this program
    $conn->query("DELETE FROM program_oral_questions WHERE program_id = $program_id");
    
    // Insert new questions
    $questions = json_decode($questions_json, true);
    if (is_array($questions)) {
        $stmt = $conn->prepare("INSERT INTO program_oral_questions (program_id, question, max_score, order_index) VALUES (?, ?, ?, ?)");
        foreach ($questions as $index => $q) {
            $question = $q['question'];
            $max_score = $q['max_score'];
            $stmt->bind_param("isii", $program_id, $question, $max_score, $index);
            $stmt->execute();
        }
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=" . intval($_POST['enrollment_id']) . "&tab=oral&program_saved=1");
    exit;
}

// ============================================
// LOAD PROGRAM SKILLS TO TRAINEE HANDLER - FIXED
// ============================================
if (isset($_POST['load_program_skills'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $program_id = intval($_POST['program_id']);
    
    // Get program skills
    $program_skills_result = $conn->query("SELECT skill_name, max_score FROM program_practical_skills WHERE program_id = $program_id ORDER BY order_index");
    $program_skills = $program_skills_result->fetch_all(MYSQLI_ASSOC);
    
    // Format skills for trainee - set scores to null instead of 0
    $skill_scores = [];
    foreach ($program_skills as $index => $skill) {
        $skill_id = 'custom_' . $index . '|' . $skill['skill_name'] . '|' . $skill['max_score'];
        $skill_scores[$skill_id] = ['score' => null];
    }
    
    $skills_json = json_encode($skill_scores);
    
    // Check if assessment component exists
    $check = $conn->query("SELECT id, practical_score FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) {
        $existing = $check->fetch_assoc();
        // Only reset practical_score to 0 if there's no existing score
        $practical_score = ($existing['practical_score'] > 0) ? $existing['practical_score'] : 0;
        
        $conn->query("UPDATE assessment_components SET 
            practical_skills_grading = '$skills_json',
            practical_score = $practical_score,
            practical_passed = 0
            WHERE enrollment_id = $enrollment_id");
    } else {
        $conn->query("INSERT INTO assessment_components 
            (enrollment_id, practical_skills_grading, practical_score, practical_passed, oral_max_score) 
            VALUES ($enrollment_id, '$skills_json', 0, 0, 100)");
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&loaded=1");
    exit;
}

// ============================================
// LOAD PROGRAM QUESTIONS TO TRAINEE HANDLER
// ============================================
if (isset($_POST['load_program_questions'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $program_id = intval($_POST['program_id']);
    
    // Get program questions
    $program_questions_result = $conn->query("SELECT question, max_score FROM program_oral_questions WHERE program_id = $program_id ORDER BY order_index");
    $program_questions = $program_questions_result->fetch_all(MYSQLI_ASSOC);
    
    // Format questions for trainee
    $questions = [];
    $total_max = 0;
    foreach ($program_questions as $q) {
        $questions[] = [
            'question' => $q['question'],
            'max_score' => $q['max_score']
        ];
        $total_max += $q['max_score'];
    }
    
    $questions_json = json_encode($questions);
    
    // Check if assessment component exists
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE assessment_components SET 
            oral_questions = '$questions_json',
            oral_max_score = $total_max,
            oral_questions_set = 1
            WHERE enrollment_id = $enrollment_id");
    } else {
        $conn->query("INSERT INTO assessment_components 
            (enrollment_id, oral_questions, oral_max_score, oral_questions_set, oral_questions_visible_to_trainee, project_visible_to_trainee) 
            VALUES ($enrollment_id, '$questions_json', $total_max, 1, 0, 0)");
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&loaded=1");
    exit;
}

// ============================================
// RESET TRAINEE SKILLS HANDLER
// ============================================
if (isset($_POST['reset_trainee_skills'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $update = $conn->prepare("UPDATE assessment_components SET 
        practical_skills_grading = NULL,
        practical_score = 0,
        practical_passed = 0,
        practical_notes = NULL
        WHERE enrollment_id = ?");
    $update->bind_param("i", $enrollment_id);
    $update->execute();
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&reset=1");
    exit;
}

// ============================================
// RESET TRAINEE QUESTIONS HANDLER
// ============================================
if (isset($_POST['reset_trainee_questions'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $update = $conn->prepare("UPDATE assessment_components SET 
        oral_questions = NULL,
        oral_questions_set = 0,
        oral_score = NULL,
        oral_passed = NULL,
        oral_notes = NULL,
        oral_answers = NULL,
        oral_submitted_by_trainee = 0
        WHERE enrollment_id = ?");
    $update->bind_param("i", $enrollment_id);
    $update->execute();
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&reset=1");
    exit;
}

// ============================================
// HANDLE SAVE TAB DATA - OPTIMIZED VERSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tab'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $tab = $_POST['tab'];
    
    // Check if assessment component exists
    $check = $conn->prepare("SELECT id FROM assessment_components WHERE enrollment_id = ?");
    $check->bind_param("i", $enrollment_id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    
    if ($tab === 'practical') {
        $practical_notes = $conn->real_escape_string($_POST['practical_notes'] ?? '');
        $skill_scores = $_POST['skill_scores'] ?? '{}';
        
        // Decode and calculate total
        $skill_grades = json_decode($skill_scores, true);
        $practical_total = 0;
        if (is_array($skill_grades)) {
            foreach ($skill_grades as $grade) {
                if (is_array($grade) && isset($grade['score'])) {
                    $practical_total += floatval($grade['score']);
                }
            }
        }
        
        $practical_passed = ($practical_total >= 75) ? 1 : 0;
        $practical_date = date('Y-m-d'); // Use current date
        
        // Save to database using prepared statements for security
        if ($exists) {
            $stmt = $conn->prepare("UPDATE assessment_components SET 
                    practical_score = ?,
                    practical_passed = ?,
                    practical_date = ?,
                    practical_notes = ?,
                    practical_skills_grading = ?
                    WHERE enrollment_id = ?");
            $stmt->bind_param("iisssi", $practical_total, $practical_passed, $practical_date, $practical_notes, $skill_scores, $enrollment_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO assessment_components 
                    (enrollment_id, practical_score, practical_passed, practical_date, practical_notes, practical_skills_grading, oral_max_score) 
                    VALUES (?, ?, ?, ?, ?, ?, 100)");
            $stmt->bind_param("iiisss", $enrollment_id, $practical_total, $practical_passed, $practical_date, $practical_notes, $skill_scores);
        }
        
        if (!$stmt->execute()) {
            error_log("Save practical failed: " . $stmt->error);
        }
        $stmt->close();
        
    } elseif ($tab === 'project') {
        $project_score = floatval($_POST['project_score'] ?? 0);
        $project_notes = $conn->real_escape_string($_POST['project_notes'] ?? '');
        $project_passed = ($project_score >= 75) ? 1 : 0;
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE assessment_components SET 
                project_score = ?,
                project_passed = ?,
                project_notes = ?
                WHERE enrollment_id = ?");
            $stmt->bind_param("iisi", $project_score, $project_passed, $project_notes, $enrollment_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO assessment_components 
                (enrollment_id, project_score, project_passed, project_notes, oral_max_score) 
                VALUES (?, ?, ?, ?, 100)");
            $stmt->bind_param("iiis", $enrollment_id, $project_score, $project_passed, $project_notes);
        }
        
        if (!$stmt->execute()) {
            error_log("Save project failed: " . $stmt->error);
        }
        $stmt->close();
        
    } elseif ($tab === 'oral') {
        // Combined oral tab - save both setup and review
        $oral_questions = $_POST['oral_questions'] ?? '[]';
        $oral_max_score = intval($_POST['oral_max_score'] ?? 0);
        $oral_score = floatval($_POST['oral_score'] ?? 0);
        $oral_notes = $conn->real_escape_string($_POST['oral_notes'] ?? '');
        
        // Calculate total max from questions
        $questions_array = json_decode($oral_questions, true);
        $calculated_max = 0;
        if (is_array($questions_array)) {
            foreach ($questions_array as $q) {
                $calculated_max += intval($q['max_score'] ?? 0);
            }
        }
        
        $final_max_score = $calculated_max > 0 ? $calculated_max : $oral_max_score;
        $oral_passed = ($oral_score >= ($final_max_score * 0.75)) ? 1 : 0;
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE assessment_components SET 
                oral_questions = ?,
                oral_max_score = ?,
                oral_questions_set = 1,
                oral_score = ?,
                oral_passed = ?,
                oral_notes = ?
                WHERE enrollment_id = ?");
            $stmt->bind_param("siiiis", $oral_questions, $final_max_score, $oral_score, $oral_passed, $oral_notes, $enrollment_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO assessment_components 
                (enrollment_id, oral_questions, oral_max_score, oral_questions_set, oral_score, oral_passed, oral_notes, oral_questions_visible_to_trainee, project_visible_to_trainee) 
                VALUES (?, ?, ?, 1, ?, ?, ?, 0, 0)");
            $stmt->bind_param("isiiis", $enrollment_id, $oral_questions, $final_max_score, $oral_score, $oral_passed, $oral_notes);
        }
        
        if (!$stmt->execute()) {
            error_log("Save oral failed: " . $stmt->error);
        }
        $stmt->close();
    }
    
    // Calculate overall result after saving
    $assessment_result = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($assessment_result && $assessment = $assessment_result->fetch_assoc()) {
        $practical = $assessment['practical_score'] ?? 0;
        $project = $assessment['project_score'] ?? 0;
        $oral = $assessment['oral_score'] ?? 0;
        $oral_max = $assessment['oral_max_score'] ?? 100;
        
        $total = $practical + $project + $oral;
        $max_total = 100 + 100 + $oral_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
        $overall_result = ($percentage >= 75) ? 'Passed' : 'Failed';
        
        $update_stmt = $conn->prepare("UPDATE assessment_components SET 
            overall_total_score = ?,
            overall_result = ?,
            assessed_by = ?,
            assessed_at = NOW()
            WHERE enrollment_id = ?");
        $update_stmt->bind_param("issi", $total, $overall_result, $fullname, $enrollment_id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    // Return success response
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=" . urlencode($tab) . "&saved=1");
    exit;
}

// ============================================
// HANDLE SAVE ASSESSMENT (from summary tab)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $updated = 0;
    
    // First, get the user_id and program_id from the enrollment
    $enrollment_data = $conn->query("SELECT user_id, program_id FROM enrollments WHERE id = $enrollment_id")->fetch_assoc();
    if ($enrollment_data) {
        $user_id = $enrollment_data['user_id'];
        $program_id = $enrollment_data['program_id'];
    } else {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=summary&error=1");
        exit;
    }
    
    $assessment = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();
    
    if ($assessment) {
        $practical = $assessment['practical_score'] ?? 0;
        $project = $assessment['project_score'] ?? 0;
        $oral = $assessment['oral_score'] ?? 0;
        $oral_max = $assessment['oral_max_score'] ?? 100;
        
        $total = $practical + $project + $oral;
        $max_total = 100 + 100 + $oral_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
        $overall_result = ($percentage >= 75) ? 'Passed' : 'Failed';
        
        // Update assessment_components
        $update_assessment = $conn->prepare("UPDATE assessment_components SET 
            overall_total_score = ?,
            overall_result = ?,
            assessed_by = ?,
            assessed_at = NOW()
            WHERE enrollment_id = ?");
        $update_assessment->bind_param("dssi", $total, $overall_result, $fullname, $enrollment_id);
        
        if (!$update_assessment->execute()) {
            error_log("Failed to update assessment: " . $update_assessment->error);
        }
        $update_assessment->close();

        // Update enrollments table
        $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
        $update_enrollment = $conn->prepare("UPDATE enrollments SET 
            enrollment_status = ?,
            results = ?,
            assessment = ?,
            completion_date = NOW()
            WHERE id = ?");
        $update_enrollment->bind_param("sssi", $enrollment_status, $overall_result, $overall_result, $enrollment_id);
        
        // UPDATE the archived_history record for this user and enrollment
        // Using original_program_id instead of program_id (based on your table structure)
        $update_archive = $conn->prepare("UPDATE archived_history SET 
            enrollment_completed_at = NOW(),
            enrollment_status = ?,
            enrollment_assessment = ?,
            updated_at = NOW()
            WHERE user_id = ? AND enrollment_id = ? AND archive_trigger = 'enrollment_completed'");
        $update_archive->bind_param("ssii", $enrollment_status, $overall_result, $user_id, $enrollment_id);

        // Execute updates
        $enrollment_updated = $update_enrollment->execute();
        
        // Try to update archive
        $archive_updated = false;
        if ($update_archive) {
            $archive_updated = $update_archive->execute();
            if (!$archive_updated) {
                error_log("Failed to update archive: " . $update_archive->error);
                // If no rows were updated, try to INSERT instead
                if ($update_archive->affected_rows === 0) {
                    $insert_archive = $conn->prepare("INSERT INTO archived_history 
                        (user_id, original_program_id, enrollment_id, enrollment_status, 
                         enrollment_assessment, enrollment_completed_at, archive_trigger, 
                         archive_source, program_name, program_duration, program_duration_unit)
                        SELECT ?, ?, id, ?, ?, NOW(), 'enrollment_completed', 'direct_from_programs',
                               program_name, program_duration, program_duration_unit
                        FROM enrollments WHERE id = ?");
                    $insert_archive->bind_param("iissi", $user_id, $program_id, $enrollment_status, 
                                               $overall_result, $enrollment_id);
                    $insert_archive->execute();
                    $insert_archive->close();
                }
            }
            $update_archive->close();
        }
        
        if ($enrollment_updated) {
            $updated = 1;
        } else {
            error_log("Failed to update enrollment: " . $update_enrollment->error);
        }
        
        $update_enrollment->close();
    }
    
    if ($updated) {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=summary&saved=1");
    } else {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=summary&error=1");
    }
    exit;
}


// ============================================
// GET ENROLLMENT ID
// ============================================
$enrollment_id = isset($_GET['enrollment_id']) ? intval($_GET['enrollment_id']) : 0;

if (!$enrollment_id) {
    header('Location: trainer_participants.php');
    exit;
}

// ============================================
// GET ENROLLMENT AND ASSESSMENT DATA
// ============================================
$enrollment = $conn->query("
    SELECT e.*, t.fullname, t.firstname, t.lastname, t.email, t.contact_number, 
           p.name as program_name, p.scheduleStart, p.scheduleEnd, p.id as program_id
    FROM enrollments e
    JOIN trainees t ON e.user_id = t.user_id
    JOIN programs p ON e.program_id = p.id
    WHERE e.id = $enrollment_id
")->fetch_assoc();

if (!$enrollment) {
    header('Location: trainer_participants.php?error=no_enrollment');
    exit;
}

$program_id = $enrollment['program_id'] ?? 0;

// Get existing assessment
$existing_assessment = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();

if (!$existing_assessment) {
    $existing_assessment = [
        'project_visible_to_trainee' => 0,
        'oral_questions_visible_to_trainee' => 0,
        'oral_questions_set' => 0,
        'project_submitted_by_trainee' => 0,
        'oral_submitted_by_trainee' => 0,
        'project_score' => null,
        'oral_score' => null,
        'practical_score' => 0,
        'practical_notes' => '',
        'practical_date' => date('Y-m-d'),
        'practical_passed' => 0,
        'project_notes' => '',
        'oral_notes' => '',
        'oral_max_score' => 100,
        'project_title' => '',
        'project_description' => '',
        'project_photo_path' => '',
        'oral_questions' => '',
        'oral_answers' => '',
        'practical_skills_grading' => '',
        'overall_result' => null,
        'overall_total_score' => 0
    ];
}

// Ensure visibility columns are set
if (!isset($existing_assessment['project_visible_to_trainee'])) {
    $existing_assessment['project_visible_to_trainee'] = 0;
}
if (!isset($existing_assessment['oral_questions_visible_to_trainee'])) {
    $existing_assessment['oral_questions_visible_to_trainee'] = 0;
}

// Get program practical skills
$program_skills = [];
$program_skills_exist = false;
if ($program_id > 0) {
    $program_skills_result = $conn->query("SELECT * FROM program_practical_skills WHERE program_id = $program_id ORDER BY order_index");
    $program_skills = $program_skills_result->fetch_all(MYSQLI_ASSOC);
    $program_skills_exist = count($program_skills) > 0;
}

// Get program oral questions
$program_questions = [];
$program_questions_exist = false;
if ($program_id > 0) {
    $program_questions_result = $conn->query("SELECT * FROM program_oral_questions WHERE program_id = $program_id ORDER BY order_index");
    $program_questions = $program_questions_result->fetch_all(MYSQLI_ASSOC);
    $program_questions_exist = count($program_questions) > 0;
}

// Decode practical skills grading
$existing_skill_grades = [];
if (!empty($existing_assessment['practical_skills_grading'])) {
    $decoded_grades = json_decode($existing_assessment['practical_skills_grading'], true);
    if (is_array($decoded_grades)) {
        $existing_skill_grades = $decoded_grades;
    }
}

// Calculate practical total
$practical_total = $existing_assessment['practical_score'] ?? 0;

if ($practical_total == 0 && !empty($existing_skill_grades)) {
    $practical_total = 0;
    $has_scores = false;
    foreach ($existing_skill_grades as $grade) {
        if (is_array($grade) && isset($grade['score']) && $grade['score'] !== null) {
            $practical_total += floatval($grade['score']);
            $has_scores = true;
        } elseif (is_numeric($grade)) {
            $practical_total += floatval($grade);
            $has_scores = true;
        }
    }
    // If no scores yet, keep practical_total as 0 but don't mark as failed
    if (!$has_scores) {
        $practical_total = null;
    }
}

// Get oral questions and answers
$oral_questions = [];
if (!empty($existing_assessment['oral_questions'])) {
    $oral_questions = json_decode($existing_assessment['oral_questions'], true) ?: [];
}

$oral_answers = [];
if (!empty($existing_assessment['oral_answers'])) {
    $oral_answers = json_decode($existing_assessment['oral_answers'], true) ?: [];
}

// Calculate oral max total from questions
$oral_max_from_questions = 0;
foreach ($oral_questions as $q) {
    $oral_max_from_questions += intval($q['max_score'] ?? 0);
}

$oral_max = $oral_max_from_questions > 0 ? $oral_max_from_questions : ($existing_assessment['oral_max_score'] ?? 100);

// Calculate totals for summary
$project_score = $existing_assessment['project_score'] ?? 0;
$oral_score = $existing_assessment['oral_score'] ?? 0;

$total_score = ($practical_total ?? 0) + $project_score + $oral_score;
$total_max = 100 + 100 + $oral_max;
$overall_percent = $total_max > 0 ? round(($total_score / $total_max) * 100, 1) : 0;
$overall_result = $overall_percent >= 75 ? 'PASSED' : 'FAILED';

// Check if practical has all scores
$practical_has_all_scores = true;
if (!empty($existing_skill_grades)) {
    foreach ($existing_skill_grades as $grade) {
        if (is_array($grade) && (!isset($grade['score']) || $grade['score'] === null)) {
            $practical_has_all_scores = false;
            break;
        }
    }
} else {
    $practical_has_all_scores = false;
}

// Get current tab from URL - now only 4 tabs (practical, project, oral, summary)
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'practical';
$reset_message = isset($_GET['reset']) ? 'Reset successful!' : '';
$saved_message = isset($_GET['saved']) ? 'Assessment saved successfully!' : '';
$loaded_message = isset($_GET['loaded']) ? 'Program skills loaded successfully!' : '';
$program_saved_message = isset($_GET['program_saved']) ? 'Program settings saved successfully!' : '';
$error_message = isset($_GET['error']) ? 'Error finalizing assessment. Please try again.' : '';

// Get program stats for summary (optional)
$program_stats = [];
if ($program_id > 0) {
    $stats_query = $conn->query("
        SELECT 
            COUNT(*) as total_trainees,
            SUM(CASE WHEN ac.overall_result = 'Passed' THEN 1 ELSE 0 END) as passed_count,
            SUM(CASE WHEN ac.overall_result = 'Failed' THEN 1 ELSE 0 END) as failed_count
        FROM enrollments e
        LEFT JOIN assessment_components ac ON e.id = ac.enrollment_id
        WHERE e.program_id = $program_id
        AND e.enrollment_status IN ('approved', 'completed')
    ");
    $program_stats = $stats_query->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Assessment - <?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        
        .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-info { background: #cce5ff; color: #004085; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        
        .assessment-card { background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; border: 1px solid #e0e0e0; }
        .card-title { font-size: 20px; font-weight: 600; color: #333; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #667eea; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        
        /* Toggle Switch */
        .toggle-container { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .toggle-switch { position: relative; display: inline-block; width: 60px; height: 30px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(30px); }
        .toggle-label { font-size: 14px; color: #666; }
        
        /* Form Styles */
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        .form-control { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; }
        .form-control:focus { border-color: #667eea; outline: none; }
        .form-control[readonly] { background-color: #e9ecef; opacity: 1; }
        
        .row { display: flex; gap: 20px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 200px; }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 12px 25px; background: white; border: 2px solid #e0e0e0; border-radius: 50px; cursor: pointer; font-weight: 600; }
        .tab.active { background: #667eea; color: white; border-color: #667eea; }
        
        /* Buttons */
        .add-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .add-btn:hover { background: #218838; }
        .remove-btn { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .remove-btn:hover { background: #c82333; }
        .reset-btn { background: #ffc107; color: #212529; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .reset-btn:hover { background: #e0a800; }
        .load-btn { background: #17a2b8; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .load-btn:hover { background: #138496; }
        .save-program-btn { background: #6f42c1; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-program-btn:hover { background: #5e34b1; }
        .save-tab-btn { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 12px 30px; border-radius: 50px; font-size: 16px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; }
        .submit-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 40px; font-size: 18px; font-weight: 600; border-radius: 50px; cursor: pointer; }
        .pdf-btn, .print-btn { background: white; border: 2px solid #dc3545; color: #dc3545; padding: 8px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .pdf-btn:hover { background: #dc3545; color: white; }
        .print-btn { border-color: #6c757d; color: #6c757d; }
        .print-btn:hover { background: #6c757d; color: white; }
        
        /* Custom Skill Row */
        .custom-skill-row { background: #f8f9fa; margin-bottom: 15px; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; }
        
        /* Input Labels */
        .input-label { font-size: 12px; color: #666; margin-top: 4px; display: block; }
        
        /* Summary Cards */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; text-align: center; }
        .summary-card h3 { font-size: 16px; margin-bottom: 10px; opacity: 0.9; }
        .summary-card .score { font-size: 36px; font-weight: 700; }
        .summary-card .max-score { font-size: 14px; opacity: 0.8; }
        .summary-card.passed { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .summary-card.failed { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        
        .back-btn { display: inline-block; padding: 10px 20px; background: white; color: #667eea; border: 2px solid #667eea; border-radius: 50px; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .info-value { font-size: 18px; font-weight: 600; color: #333; }
        
        .waiting-message { text-align: center; padding: 40px; background: #f8f9fa; border-radius: 10px; }
        
        .button-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        
        .total-display { font-size: 24px; font-weight: 700; color: #667eea; text-align: right; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        
        .program-section { background: #e8f4f8; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px dashed #17a2b8; }
        .program-section h4 { color: #17a2b8; margin-bottom: 15px; }
        
        .trainee-section { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #28a745; }
        .trainee-section h4 { color: #28a745; margin-bottom: 15px; }
        
        /* Visibility Status */
        .visibility-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .status-visible { background: #d4edda; color: #155724; }
        .status-hidden { background: #f8d7da; color: #721c24; }
        
        /* Oral answers display */
        .oral-answer-card { background: #f3e8ff; padding: 15px; margin-bottom: 10px; border-left: 5px solid #8b5cf6; border-radius: 5px; }
        
        /* Program Stats */
        .program-stats { display: flex; gap: 20px; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; justify-content: space-around; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        
        /* Print Styles */
        @media print {
            .header, .tabs, .back-btn, .toggle-container, .submit-btn, 
            .save-tab-btn, .pdf-btn, .print-btn, .modal, .add-btn, .remove-btn, .reset-btn, .button-group,
            .load-btn, .save-program-btn, .program-section, .program-stats {
                display: none !important;
            }
            .assessment-card { box-shadow: none; border: 1px solid #ddd; }
            .summary-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="trainer_participants.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Participants
        </a>
        
        <div class="header">
            <h1><i class="fas fa-clipboard-check"></i> Comprehensive Assessment</h1>
            <div class="subtitle">
                <strong>Trainee:</strong> <?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?> | 
                <strong>Program:</strong> <?php echo htmlspecialchars($enrollment['program_name'] ?? ''); ?>
            </div>
        </div>
        
        <?php if ($reset_message): ?>
            <div class="alert-info"><?php echo $reset_message; ?></div>
        <?php endif; ?>
        
        <?php if ($saved_message): ?>
            <div class="alert-success"><?php echo $saved_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if ($loaded_message): ?>
            <div class="alert-success"><?php echo $loaded_message; ?></div>
        <?php endif; ?>
        
        <?php if ($program_saved_message): ?>
            <div class="alert-success"><?php echo $program_saved_message; ?></div>
        <?php endif; ?>
        
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Full Name</span><span class="info-value"><?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?></span></div>
            <div class="info-item"><span class="info-label">Contact</span><span class="info-value"><?php echo htmlspecialchars($enrollment['contact_number'] ?? ''); ?></span></div>
            <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($enrollment['email'] ?? ''); ?></span></div>
            <div class="info-item">
                <span class="info-label">Program</span>
                <span class="info-value"><?php echo htmlspecialchars($enrollment['program_name'] ?? ''); ?></span>
            </div>
        </div>
        
        <!-- Tabs - Now only 4 tabs (oral-setup and oral-review combined) -->
        <div class="tabs" id="tabs">
            <div class="tab <?php echo $current_tab == 'practical' ? 'active' : ''; ?>" onclick="switchTab('practical')">1. Practical Skills</div>
            <div class="tab <?php echo $current_tab == 'project' ? 'active' : ''; ?>" onclick="switchTab('project')">2. Project Output</div>
            <div class="tab <?php echo $current_tab == 'oral' ? 'active' : ''; ?>" onclick="switchTab('oral')">3. Oral Assessment</div>
            <div class="tab <?php echo $current_tab == 'summary' ? 'active' : ''; ?>" onclick="switchTab('summary')">4. Summary & Result</div>
        </div>
        
        <!-- TAB 1: PRACTICAL SKILLS -->
        <div class="assessment-card" id="tab-practical" style="display: <?php echo $current_tab == 'practical' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-utensils"></i> Practical Skills Assessment
                <span style="margin-left: auto; background: #667eea; color: white; padding: 5px 15px; border-radius: 20px;">
                    Total: <span id="practicalTotal"><?php echo $practical_total ?? 0; ?></span>/100
                </span>
            </div>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> Set default skills for this program. These will be used for all trainees.
            </div>
            
            <!-- Program Skills Management Section -->
            <div class="program-section">
                <h4><i class="fas fa-cog"></i> Program Default Practical Skills</h4>
                <p>Define the skills that all trainees in this program will be assessed on.</p>
                
                <div id="program-skills-container">
                    <?php foreach ($program_skills as $index => $skill): ?>
                    <div class="custom-skill-row" style="border-left-color: #17a2b8;">
                        <div style="display: flex; gap: 20px; align-items: center;">
                            <div style="flex: 2;">
                                <input type="text" class="form-control program-skill-name" value="<?php echo htmlspecialchars($skill['skill_name']); ?>">
                                <span class="input-label">Skill name</span>
                            </div>
                            <div style="flex: 1;">
                                <input type="number" class="form-control program-skill-max" value="<?php echo $skill['max_score']; ?>" min="1" max="100">
                                <span class="input-label">Max score</span>
                            </div>
                            <div style="flex: 0.5;">
                                <button type="button" class="remove-btn" onclick="removeProgramSkill(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="add-btn" onclick="addProgramSkill()">
                        <i class="fas fa-plus"></i> Add Program Skill
                    </button>
                    <button type="button" class="save-program-btn" onclick="saveProgramSkills()">
                        <i class="fas fa-save"></i> Save as Program Defaults
                    </button>
                </div>
            </div>
            
            <!-- Trainee's Assessment Section -->
            <div class="trainee-section">
                <h4><i class="fas fa-user"></i> This Trainee's Assessment</h4>
                
                <div class="button-group">
                    <?php if ($program_skills_exist): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="load_program_skills" value="1">
                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                        <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                        <button type="submit" class="load-btn">
                            <i class="fas fa-download"></i> Load Program Skills to This Trainee
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset this trainee\'s skills?');">
                        <input type="hidden" name="reset_trainee_skills" value="1">
                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                        <button type="submit" class="reset-btn">
                            <i class="fas fa-undo"></i> Reset This Trainee's Scores
                        </button>
                    </form>
                </div>
                
                <!-- Header Labels -->
                <div style="display: flex; gap: 20px; margin-bottom: 10px; padding: 0 15px; font-weight: bold; color: #555;">
                    <div style="flex: 1;">SKILL NAME</div>
                    <div style="flex: 1;">MAX SCORE</div>
                    <div style="flex: 1;">SCORE OBTAINED</div>
                    <div style="flex: 0.5;">STATUS</div>
                </div>
                
                <!-- Trainee's Skills Container -->
                <div id="trainee-skills-container">
                    <?php 
                    if (!empty($existing_skill_grades)):
                        $has_null_score = false;
                        foreach ($existing_skill_grades as $skill_id => $grade_data):
                            if (strpos($skill_id, 'custom_') === 0):
                                $skill_parts = explode('|', $skill_id);
                                $skill_name = $skill_parts[1] ?? 'Skill';
                                $max_score = $skill_parts[2] ?? 20;
                                
                                $existing_score = null;
                                if (is_array($grade_data) && isset($grade_data['score'])) {
                                    $existing_score = $grade_data['score'];
                                } elseif (is_numeric($grade_data)) {
                                    $existing_score = $grade_data;
                                }
                                
                                // Check if score is null
                                if ($existing_score === null) {
                                    $has_null_score = true;
                                }
                                
                                $passed = ($existing_score !== null && $existing_score >= ($max_score * 0.75));
                    ?>
                    <div class="custom-skill-row" data-skill-id="<?php echo $skill_id; ?>">
                        <div style="display: flex; gap: 20px; align-items: center;">
                            <div style="flex: 1;">
                                <input type="text" class="form-control trainee-skill-name" value="<?php echo htmlspecialchars($skill_name); ?>" readonly>
                                <span class="input-label">Skill name (from program)</span>
                            </div>
                            <div style="flex: 1;">
                                <input type="number" class="form-control trainee-skill-max" value="<?php echo $max_score; ?>" readonly>
                                <span class="input-label">Maximum score</span>
                            </div>
                            <div style="flex: 1;">
                                <input type="number" class="form-control trainee-skill-score" value="<?php echo ($existing_score !== null) ? $existing_score : ''; ?>" min="0" max="<?php echo $max_score; ?>" placeholder="Enter score" onchange="updatePracticalTotal()">
                                <span class="input-label">Enter score obtained</span>
                            </div>
                            <div style="flex: 0.5;">
                                <?php if ($existing_score !== null): ?>
                                    <?php if ($passed): ?>
                                        <span style="color: #28a745; font-weight: bold;"><i class="fas fa-check-circle"></i> Pass</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545; font-weight: bold;"><i class="fas fa-times-circle"></i> Fail</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #ffc107; font-weight: bold;"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                                <span class="input-label">Need: <?php echo ceil($max_score * 0.75); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php 
                            endif;
                        endforeach;
                        
                        if ($has_null_score):
                    ?>
                    <div class="alert-warning" style="margin-top: 15px;">
                        <i class="fas fa-info-circle"></i> Some skills don't have scores yet. Please enter scores for all skills.
                    </div>
                    <?php 
                        endif;
                    else: 
                    ?>
                    <div class="waiting-message">
                        <i class="fas fa-info-circle"></i> No skills loaded yet. Click "Load Program Skills" to get started.
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes / Observations:</label>
                    <textarea id="practical_notes" class="form-control" rows="3" placeholder="Add any observations..."><?php echo htmlspecialchars($existing_assessment['practical_notes'] ?? ''); ?></textarea>
                </div>
                
                <div class="total-display" id="practicalTotalDisplay">
                    <?php 
                    if ($practical_total === null || !$practical_has_all_scores): 
                    ?>
                        Total Practical Score: <span id="practicalTotalValue"><?php echo $practical_total ?? 0; ?></span>/100 
                        <span style="color: #ffc107; margin-left: 10px;"><i class="fas fa-clock"></i> INCOMPLETE</span>
                    <?php elseif ($practical_total >= 75): ?>
                        Total Practical Score: <span id="practicalTotalValue"><?php echo $practical_total; ?></span>/100 
                        <span style="color: #28a745; margin-left: 10px;"><i class="fas fa-check-circle"></i> PASSED</span>
                    <?php else: ?>
                        Total Practical Score: <span id="practicalTotalValue"><?php echo $practical_total; ?></span>/100 
                        <span style="color: #dc3545; margin-left: 10px;"><i class="fas fa-times-circle"></i> FAILED</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="save-tab-btn" onclick="savePracticalTab()">
                    <i class="fas fa-save"></i> Save This Trainee's Scores
                </button>
            </div>
        </div>
        
        <!-- TAB 2: PROJECT OUTPUT -->
        <div class="assessment-card" id="tab-project" style="display: <?php echo $current_tab == 'project' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-project-diagram"></i> Project Output (100 pts)
                <div class="toggle-container">
                    <span class="toggle-label">Show to Trainee:</span>
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               onchange="toggleVisibility('project', <?php echo $enrollment_id; ?>, this)"
                               <?php echo ($existing_assessment['project_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <?php if ($existing_assessment['project_visible_to_trainee'] ?? 0): ?>
                        <span class="visibility-status status-visible"><i class="fas fa-eye"></i> Visible to trainee</span>
                    <?php else: ?>
                        <span class="visibility-status status-hidden"><i class="fas fa-eye-slash"></i> Hidden from trainee</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($existing_assessment['project_submitted_by_trainee'])): ?>
                <div style="background: #e8f5e9; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Trainee's Submission</h4>
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($existing_assessment['project_title'] ?? ''); ?></p>
                    <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($existing_assessment['project_description'] ?? '')); ?></p>
                    <?php if (!empty($existing_assessment['project_photo_path'])): ?>
                        <img src="/<?php echo $existing_assessment['project_photo_path']; ?>" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="waiting-message">
                    <i class="fas fa-clock"></i>
                    <h3>Waiting for Trainee Submission</h3>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <h4>Trainer's Evaluation</h4>
                <div class="row">
                    <div class="col">
                        <label class="form-label">Project Score (0-100):</label>
                        <input type="number" id="project_score" class="form-control" value="<?php echo $existing_assessment['project_score'] ?? ''; ?>">
                        <span class="input-label">Score obtained by trainee</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Feedback:</label>
                    <textarea id="project_notes" class="form-control" rows="3" placeholder="Add feedback..."><?php echo htmlspecialchars($existing_assessment['project_notes'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <div class="total-display">
                Project Score: <?php echo $project_score; ?>/100
                <?php if ($project_score >= 75): ?>
                    <span style="color: #28a745; margin-left: 10px;"><i class="fas fa-check-circle"></i> PASSED</span>
                <?php else: ?>
                    <span style="color: #dc3545; margin-left: 10px;"><i class="fas fa-times-circle"></i> FAILED</span>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="save-tab-btn" onclick="saveProjectTab()">
                    <i class="fas fa-save"></i> Save Project Evaluation
                </button>
            </div>
        </div>
        
        <!-- TAB 3: ORAL ASSESSMENT (COMBINED SETUP AND REVIEW) -->
        <div class="assessment-card" id="tab-oral" style="display: <?php echo $current_tab == 'oral' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-question-circle"></i> Oral Assessment
                <?php if (!empty($existing_assessment['oral_questions_set'])): ?>
                    <span style="background: #28a745; color: white; padding: 5px 15px; border-radius: 20px;">
                        <i class="fas fa-check"></i> QUESTIONS SET
                    </span>
                <?php endif; ?>
                <div class="toggle-container">
                    <span class="toggle-label">Show to Trainee:</span>
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               onchange="toggleVisibility('oral', <?php echo $enrollment_id; ?>, this)"
                               <?php echo ($existing_assessment['oral_questions_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>
                               <?php echo empty($oral_questions) ? 'disabled' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <?php if (!empty($oral_questions)): ?>
                        <?php if ($existing_assessment['oral_questions_visible_to_trainee'] ?? 0): ?>
                            <span class="visibility-status status-visible"><i class="fas fa-eye"></i> Visible to trainee</span>
                        <?php else: ?>
                            <span class="visibility-status status-hidden"><i class="fas fa-eye-slash"></i> Hidden from trainee</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> Set default questions for this program. These will be used for all trainees.
            </div>
            
            <!-- Program Questions Management Section -->
            <div class="program-section">
                <h4><i class="fas fa-cog"></i> Program Default Oral Questions</h4>
                <p>Define the questions that all trainees in this program will answer.</p>
                
                <div id="program-questions-container">
                    <?php foreach ($program_questions as $index => $q): ?>
                    <div class="custom-skill-row" style="border-left-color: #17a2b8;">
                        <div style="display: flex; gap: 20px; align-items: center;">
                            <div style="flex: 2;">
                                <input type="text" class="form-control program-question" value="<?php echo htmlspecialchars($q['question']); ?>">
                                <span class="input-label">Question</span>
                            </div>
                            <div style="flex: 1;">
                                <input type="number" class="form-control program-question-max" value="<?php echo $q['max_score']; ?>" min="1" max="100">
                                <span class="input-label">Max score</span>
                            </div>
                            <div style="flex: 0.5;">
                                <button type="button" class="remove-btn" onclick="removeProgramQuestion(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="add-btn" onclick="addProgramQuestion()">
                        <i class="fas fa-plus"></i> Add Program Question
                    </button>
                    <button type="button" class="save-program-btn" onclick="saveProgramQuestions()">
                        <i class="fas fa-save"></i> Save as Program Defaults
                    </button>
                </div>
            </div>
            
            <!-- Trainee's Questions Section -->
            <div class="trainee-section">
                <h4><i class="fas fa-user"></i> This Trainee's Questions</h4>
                
                <div class="button-group">
                    <?php if ($program_questions_exist): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="load_program_questions" value="1">
                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                        <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                        <button type="submit" class="load-btn">
                            <i class="fas fa-download"></i> Load Program Questions to This Trainee
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset this trainee\'s questions?');">
                        <input type="hidden" name="reset_trainee_questions" value="1">
                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                        <button type="submit" class="reset-btn">
                            <i class="fas fa-undo"></i> Reset This Trainee's Questions
                        </button>
                    </form>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Total Oral Maximum Score: <span id="oralMaxTotal"><?php echo $oral_max; ?></span></label>
                    <input type="hidden" id="oral_max_score" value="<?php echo $oral_max; ?>">
                </div>
                
                <!-- Header Labels for Questions -->
                <div style="display: flex; gap: 20px; margin-bottom: 10px; padding: 0 15px; font-weight: bold; color: #555;">
                    <div style="flex: 2;">QUESTION</div>
                    <div style="flex: 1;">MAX SCORE</div>
                    <div style="flex: 0.5;">STATUS</div>
                </div>
                
                <!-- Trainee's Questions Container -->
                <div id="trainee-questions-container">
                    <?php if (!empty($oral_questions)): ?>
                        <?php foreach ($oral_questions as $index => $q): ?>
                        <div class="custom-skill-row" data-question-index="<?php echo $index; ?>">
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <div style="flex: 2;">
                                    <input type="text" class="form-control trainee-question" value="<?php echo htmlspecialchars($q['question']); ?>" readonly>
                                    <span class="input-label">Question (from program)</span>
                                </div>
                                <div style="flex: 1;">
                                    <input type="number" class="form-control trainee-question-max" value="<?php echo $q['max_score'] ?? 25; ?>" readonly>
                                    <span class="input-label">Maximum points</span>
                                </div>
                                <div style="flex: 0.5;">
                                    <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Loaded</span>
                                    <span class="input-label">Need: <?php echo ceil(($q['max_score'] ?? 25) * 0.75); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="waiting-message">
                        <i class="fas fa-info-circle"></i> No questions loaded yet. Click "Load Program Questions" to get started.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Trainee's Answers Section -->
            <?php if (!empty($oral_answers)): ?>
            <div style="margin-top: 30px;">
                <h4><i class="fas fa-comment"></i> Trainee's Answers</h4>
                <?php foreach ($oral_questions as $index => $q): ?>
                    <div class="oral-answer-card">
                        <h5>Q<?php echo $index + 1; ?>: <?php echo htmlspecialchars($q['question']); ?> (<?php echo $q['max_score']; ?> pts)</h5>
                        <p><strong>Answer:</strong> <?php echo nl2br(htmlspecialchars($oral_answers[$index] ?? '')); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Trainer's Evaluation -->
            <div style="margin-top: 20px;">
                <h4>Trainer's Evaluation</h4>
                <div class="row">
                    <div class="col">
                        <label class="form-label">Oral Score (0-<?php echo $oral_max; ?>):</label>
                        <input type="number" id="oral_score" class="form-control" value="<?php echo $existing_assessment['oral_score'] ?? ''; ?>">
                        <span class="input-label">Total score for all answers</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Feedback:</label>
                    <textarea id="oral_notes" class="form-control" rows="3" placeholder="Add feedback..."><?php echo htmlspecialchars($existing_assessment['oral_notes'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <div class="total-display">
                Oral Score: <?php echo $oral_score; ?>/<?php echo $oral_max; ?>
                <?php if ($oral_score >= ($oral_max * 0.75)): ?>
                    <span style="color: #28a745; margin-left: 10px;"><i class="fas fa-check-circle"></i> PASSED</span>
                <?php else: ?>
                    <span style="color: #dc3545; margin-left: 10px;"><i class="fas fa-times-circle"></i> FAILED</span>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="save-tab-btn" onclick="saveOralTab()">
                    <i class="fas fa-save"></i> Save Oral Assessment
                </button>
            </div>
        </div>
        
        <!-- TAB 4: SUMMARY - UPDATED TO SHOW ONLY CURRENT TRAINEE -->
        <div class="assessment-card" id="tab-summary" style="display: <?php echo $current_tab == 'summary' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-table"></i> Assessment Summary - <?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?>
                <div style="margin-left: auto; display: flex; gap: 10px;">
                    <button class="print-btn" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Practical Skills</h3>
                    <div class="score"><?php echo $practical_total ?? 0; ?></div>
                    <div class="max-score">out of 100</div>
                    <?php if ($practical_total === null || !$practical_has_all_scores): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">INCOMPLETE</div>
                    <?php elseif ($practical_total >= 75): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">PASSED</div>
                    <?php else: ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">FAILED</div>
                    <?php endif; ?>
                </div>
                
                <div class="summary-card">
                    <h3>Project Output</h3>
                    <div class="score"><?php echo $project_score; ?></div>
                    <div class="max-score">out of 100</div>
                    <?php if ($project_score >= 75): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">PASSED</div>
                    <?php else: ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">FAILED</div>
                    <?php endif; ?>
                </div>
                
                <div class="summary-card">
                    <h3>Oral Examination</h3>
                    <div class="score"><?php echo $oral_score; ?></div>
                    <div class="max-score">out of <?php echo $oral_max; ?></div>
                    <?php if ($oral_score >= ($oral_max * 0.75)): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">PASSED</div>
                    <?php else: ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">FAILED</div>
                    <?php endif; ?>
                </div>
                
                <div class="summary-card <?php echo $overall_result == 'PASSED' ? 'passed' : 'failed'; ?>">
                    <h3>Overall Result</h3>
                    <div class="score"><?php echo $overall_percent; ?>%</div>
                    <div class="max-score">Total: <?php echo $total_score; ?>/<?php echo $total_max; ?></div>
                    <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px; font-size: 20px;"><?php echo $overall_result; ?></div>
                </div>
            </div>
            
        
         
            
            <!-- Detailed Assessment Breakdown -->
            <div style="margin-top: 30px;">
                <h3><i class="fas fa-clipboard-list"></i> Detailed Assessment Breakdown</h3>
                
                <!-- Practical Skills Breakdown -->
                <?php if (!empty($existing_skill_grades)): ?>
                <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h4 style="color: #667eea; margin-bottom: 15px;">Practical Skills</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #e9ecef;">
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">Skill</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Max Score</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Score Obtained</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $practical_details_total = 0;
                            $practical_details_max = 0;
                            foreach ($existing_skill_grades as $skill_id => $grade_data):
                                if (strpos($skill_id, 'custom_') === 0):
                                    $skill_parts = explode('|', $skill_id);
                                    $skill_name = $skill_parts[1] ?? 'Skill';
                                    $max_score = $skill_parts[2] ?? 20;
                                    
                                    $score = 0;
                                    if (is_array($grade_data) && isset($grade_data['score'])) {
                                        $score = $grade_data['score'];
                                    } elseif (is_numeric($grade_data)) {
                                        $score = $grade_data;
                                    }
                                    
                                    $practical_details_total += $score;
                                    $practical_details_max += $max_score;
                                    
                                    $passed = $score >= ($max_score * 0.75);
                            ?>
                            <tr>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo htmlspecialchars($skill_name); ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $max_score; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $score; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($passed): ?>
                                        <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Pass</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Fail</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                            <tr style="background: #e9ecef; font-weight: bold;">
                                <td style="padding: 10px; border: 1px solid #dee2e6;" colspan="2">Total</td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $practical_details_total; ?>/<?php echo $practical_details_max; ?></td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($practical_details_total >= 75): ?>
                                        <span style="color: #28a745;">PASSED</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">FAILED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php if (!empty($existing_assessment['practical_notes'])): ?>
                        <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
                            <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($existing_assessment['practical_notes'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Project Breakdown -->
                <?php if (!empty($existing_assessment['project_submitted_by_trainee'])): ?>
                <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h4 style="color: #28a745; margin-bottom: 15px;">Project Output</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <th style="padding: 10px; text-align: left; background: #e9ecef; border: 1px solid #dee2e6; width: 30%;">Project Title</th>
                            <td style="padding: 10px; border: 1px solid #dee2e6;"><?php echo htmlspecialchars($existing_assessment['project_title'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th style="padding: 10px; text-align: left; background: #e9ecef; border: 1px solid #dee2e6;">Description</th>
                            <td style="padding: 10px; border: 1px solid #dee2e6;"><?php echo nl2br(htmlspecialchars($existing_assessment['project_description'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <th style="padding: 10px; text-align: left; background: #e9ecef; border: 1px solid #dee2e6;">Score</th>
                            <td style="padding: 10px; border: 1px solid #dee2e6;">
                                <strong><?php echo $project_score; ?>/100</strong>
                                <?php if ($project_score >= 75): ?>
                                    <span style="color: #28a745; margin-left: 10px;">(PASSED)</span>
                                <?php else: ?>
                                    <span style="color: #dc3545; margin-left: 10px;">(FAILED)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($existing_assessment['project_notes'])): ?>
                        <tr>
                            <th style="padding: 10px; text-align: left; background: #e9ecef; border: 1px solid #dee2e6;">Feedback</th>
                            <td style="padding: 10px; border: 1px solid #dee2e6;"><?php echo nl2br(htmlspecialchars($existing_assessment['project_notes'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Oral Assessment Breakdown -->
                <?php if (!empty($oral_questions)): ?>
                <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h4 style="color: #8b5cf6; margin-bottom: 15px;">Oral Assessment</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #e9ecef;">
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">#</th>
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">Question</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Max Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($oral_questions as $index => $q): ?>
                            <tr>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo $index + 1; ?></td>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo htmlspecialchars($q['question']); ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $q['max_score'] ?? 25; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (!empty($oral_answers)): ?>
                    <h5 style="margin-top: 20px; color: #6c757d;">Trainee's Answers</h5>
                    <?php foreach ($oral_questions as $index => $q): ?>
                        <div style="margin-top: 15px; padding: 15px; background: #f3e8ff; border-left: 5px solid #8b5cf6; border-radius: 5px;">
                            <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($q['question']); ?><br>
                            <strong>Answer:</strong> <?php echo nl2br(htmlspecialchars($oral_answers[$index] ?? '')); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #e9ecef; border-radius: 5px;">
                        <strong>Total Score:</strong> <?php echo $oral_score; ?>/<?php echo $oral_max; ?>
                        <?php if ($oral_score >= ($oral_max * 0.75)): ?>
                            <span style="color: #28a745; margin-left: 10px;">(PASSED)</span>
                        <?php else: ?>
                            <span style="color: #dc3545; margin-left: 10px;">(FAILED)</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($existing_assessment['oral_notes'])): ?>
                        <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
                            <strong>Feedback:</strong> <?php echo nl2br(htmlspecialchars($existing_assessment['oral_notes'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Assessment Summary -->
                <div style="margin-top: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 15px;">
                    <h3 style="margin-bottom: 20px;">Assessment Summary</h3>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                        <div>
                            <strong>Trainee:</strong> <?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?>
                        </div>
                        <div>
                            <strong>Program:</strong> <?php echo htmlspecialchars($enrollment['program_name'] ?? ''); ?>
                        </div>
                        <div>
                            <strong>Date Assessed:</strong> <?php echo date('F d, Y'); ?>
                        </div>
                        <div>
                            <strong>Assessed By:</strong> <?php echo htmlspecialchars($fullname); ?>
                        </div>
                    </div>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid rgba(255,255,255,0.3); text-align: center;">
                        <div style="font-size: 48px; font-weight: bold;"><?php echo $overall_percent; ?>%</div>
                        <div style="font-size: 24px; margin-top: 10px;"><?php echo $overall_result; ?></div>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <form method="POST" style="display: inline;" onsubmit="return confirmFinalize();">
                    <input type="hidden" name="save_assessment" value="1">
                    <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                    <button type="submit" class="submit-btn" id="finalizeBtn">
                        <i class="fas fa-save"></i> FINALIZE ASSESSMENT
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const enrollmentId = <?php echo $enrollment_id; ?>;
        const programId = <?php echo $program_id; ?>;
        let programSkillCount = <?php echo count($program_skills); ?>;
        let programQuestionCount = <?php echo count($program_questions); ?>;
        
        // Tab switching
        function switchTab(tabName) {
            window.location.href = '?enrollment_id=' + enrollmentId + '&tab=' + tabName;
        }
        
        // Toggle visibility - SIMPLE LANG
        function toggleVisibility(type, enrollmentId, checkbox) {
            const newValue = checkbox.checked ? 1 : 0;
            const toggleType = type === 'project' ? 'toggle_project' : 'toggle_oral';
            
            // Get current tab from URL or default
            const urlParams = new URLSearchParams(window.location.search);
            let currentTab = urlParams.get('tab');
            if (!currentTab) {
                currentTab = type === 'project' ? 'project' : 'oral';
            }
            
            // Direct redirect - walang loading, walang alert
            window.location.href = '?enrollment_id=' + enrollmentId + 
                                  '&' + toggleType + '=1' + 
                                  '&set=' + newValue +
                                  '&tab=' + currentTab;
        }
        
        // ========== PROGRAM SKILLS FUNCTIONS ==========
        function addProgramSkill() {
            programSkillCount++;
            const container = document.getElementById('program-skills-container');
            const newRow = document.createElement('div');
            newRow.className = 'custom-skill-row';
            newRow.style.borderLeftColor = '#17a2b8';
            newRow.innerHTML = `
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div style="flex: 2;">
                        <input type="text" class="form-control program-skill-name" value="New Skill ${programSkillCount}">
                        <span class="input-label">Skill name</span>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" class="form-control program-skill-max" value="20" min="1" max="100">
                        <span class="input-label">Max score</span>
                    </div>
                    <div style="flex: 0.5;">
                        <button type="button" class="remove-btn" onclick="removeProgramSkill(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeProgramSkill(btn) {
            btn.closest('.custom-skill-row').remove();
        }
        
        function saveProgramSkills() {
            const skills = [];
            document.querySelectorAll('#program-skills-container .custom-skill-row').forEach(row => {
                const nameInput = row.querySelector('.program-skill-name');
                const maxInput = row.querySelector('.program-skill-max');
                
                if (nameInput && maxInput) {
                    skills.push({
                        name: nameInput.value,
                        max_score: parseInt(maxInput.value) || 20
                    });
                }
            });
            
            if (skills.length === 0) {
                Swal.fire('No Skills', 'Please add at least one skill.', 'warning');
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'comprehensive_assessment.php';
            
            addField(form, 'save_program_skills', '1');
            addField(form, 'program_id', programId);
            addField(form, 'enrollment_id', enrollmentId);
            addField(form, 'program_skills', JSON.stringify(skills));
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // ========== PROGRAM QUESTIONS FUNCTIONS ==========
        function addProgramQuestion() {
            programQuestionCount++;
            const container = document.getElementById('program-questions-container');
            const newRow = document.createElement('div');
            newRow.className = 'custom-skill-row';
            newRow.style.borderLeftColor = '#17a2b8';
            newRow.innerHTML = `
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div style="flex: 2;">
                        <input type="text" class="form-control program-question" value="New Question ${programQuestionCount}">
                        <span class="input-label">Question</span>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" class="form-control program-question-max" value="25" min="1" max="100">
                        <span class="input-label">Max score</span>
                    </div>
                    <div style="flex: 0.5;">
                        <button type="button" class="remove-btn" onclick="removeProgramQuestion(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeProgramQuestion(btn) {
            btn.closest('.custom-skill-row').remove();
        }
        
        function saveProgramQuestions() {
            const questions = [];
            document.querySelectorAll('#program-questions-container .custom-skill-row').forEach(row => {
                const questionInput = row.querySelector('.program-question');
                const maxInput = row.querySelector('.program-question-max');
                
                if (questionInput && maxInput) {
                    questions.push({
                        question: questionInput.value,
                        max_score: parseInt(maxInput.value) || 25
                    });
                }
            });
            
            if (questions.length === 0) {
                Swal.fire('No Questions', 'Please add at least one question.', 'warning');
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'comprehensive_assessment.php';
            
            addField(form, 'save_program_questions', '1');
            addField(form, 'program_id', programId);
            addField(form, 'enrollment_id', enrollmentId);
            addField(form, 'program_questions', JSON.stringify(questions));
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // ========== UPDATE PRACTICAL TOTAL ==========
        function updatePracticalTotal() {
            let total = 0;
            let allFilled = true;
            let hasAnyScore = false;
            
            document.querySelectorAll('.trainee-skill-score').forEach(input => {
                const val = input.value;
                if (val === null || val === '') {
                    allFilled = false;
                } else {
                    total += parseFloat(val) || 0;
                    hasAnyScore = true;
                }
            });
            
            document.getElementById('practicalTotal').textContent = total;
            
            const totalDisplay = document.getElementById('practicalTotalDisplay');
            if (totalDisplay) {
                if (!allFilled) {
                    totalDisplay.innerHTML = `Total Practical Score: ${total}/100 <span style="color: #ffc107; margin-left: 10px;"><i class="fas fa-clock"></i> INCOMPLETE</span>`;
                } else if (total >= 75) {
                    totalDisplay.innerHTML = `Total Practical Score: ${total}/100 <span style="color: #28a745; margin-left: 10px;"><i class="fas fa-check-circle"></i> PASSED</span>`;
                } else {
                    totalDisplay.innerHTML = `Total Practical Score: ${total}/100 <span style="color: #dc3545; margin-left: 10px;"><i class="fas fa-times-circle"></i> FAILED</span>`;
                }
            }
        }
        
        // ========== SAVE FUNCTIONS ==========
        function savePracticalTab() {
            // Check if all scores are filled
            let allFilled = true;
            document.querySelectorAll('.trainee-skill-score').forEach(input => {
                if (input.value === null || input.value === '') {
                    allFilled = false;
                }
            });
            
            if (!allFilled) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Scores',
                    text: 'Please enter scores for all skills before saving.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }
            
            // Show loading
            Swal.fire({
                title: 'Saving...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'comprehensive_assessment.php';
                    
                    addField(form, 'save_tab', '1');
                    addField(form, 'enrollment_id', enrollmentId);
                    addField(form, 'tab', 'practical');
                    addField(form, 'practical_notes', document.getElementById('practical_notes')?.value || '');
                    
                    const skillScores = {};
                    
                    document.querySelectorAll('#trainee-skills-container .custom-skill-row').forEach((row, index) => {
                        const nameInput = row.querySelector('.trainee-skill-name');
                        const maxInput = row.querySelector('.trainee-skill-max');
                        const scoreInput = row.querySelector('.trainee-skill-score');
                        
                        if (nameInput && maxInput && scoreInput) {
                            const skillName = nameInput.value;
                            const maxScore = parseFloat(maxInput.value) || 20;
                            let score = parseFloat(scoreInput.value) || 0;
                            
                            if (score > maxScore) score = maxScore;
                            if (score < 0) score = 0;
                            
                            const skillId = 'custom_' + index + '|' + skillName + '|' + maxScore;
                            skillScores[skillId] = { score: score };
                        }
                    });
                    
                    addField(form, 'skill_scores', JSON.stringify(skillScores));
                    
                    // Submit form
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function saveProjectTab() {
            Swal.fire({
                title: 'Saving...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'comprehensive_assessment.php';
                    
                    addField(form, 'save_tab', '1');
                    addField(form, 'enrollment_id', enrollmentId);
                    addField(form, 'tab', 'project');
                    
                    const score = document.getElementById('project_score')?.value || 0;
                    addField(form, 'project_score', score);
                    addField(form, 'project_notes', document.getElementById('project_notes')?.value || '');
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function saveOralTab() {
            Swal.fire({
                title: 'Saving...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'comprehensive_assessment.php';
                    
                    addField(form, 'save_tab', '1');
                    addField(form, 'enrollment_id', enrollmentId);
                    addField(form, 'tab', 'oral');
                    
                    // Get the current questions from the trainee questions container
                    const questions = [];
                    document.querySelectorAll('#trainee-questions-container .custom-skill-row').forEach(row => {
                        const questionInput = row.querySelector('.trainee-question');
                        const maxInput = row.querySelector('.trainee-question-max');
                        
                        if (questionInput && maxInput) {
                            questions.push({
                                question: questionInput.value,
                                max_score: parseInt(maxInput.value) || 25
                            });
                        }
                    });
                    
                    // If no questions in trainee container but program questions exist, use program questions
                    if (questions.length === 0 && <?php echo $program_questions_exist ? 'true' : 'false'; ?>) {
                        <?php if ($program_questions_exist): ?>
                        const programQuestions = <?php echo json_encode($program_questions); ?>;
                        programQuestions.forEach(q => {
                            questions.push({
                                question: q.question,
                                max_score: q.max_score
                            });
                        });
                        <?php endif; ?>
                    }
                    
                    if (questions.length === 0) {
                        Swal.close();
                        Swal.fire({
                            icon: 'warning',
                            title: 'No Questions Found',
                            text: 'Please add questions first before saving.',
                            confirmButtonColor: '#3085d6'
                        });
                        return;
                    }
                    
                    let totalMax = 0;
                    questions.forEach(q => totalMax += q.max_score);
                    
                    addField(form, 'oral_questions', JSON.stringify(questions));
                    addField(form, 'oral_max_score', totalMax);
                    
                    // Add oral score and notes
                    const score = document.getElementById('oral_score')?.value || 0;
                    addField(form, 'oral_score', score);
                    addField(form, 'oral_notes', document.getElementById('oral_notes')?.value || '');
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function confirmFinalize() {
            // Check if practical skills are complete
            let practicalComplete = true;
            let practicalScores = document.querySelectorAll('.trainee-skill-score');
            
            if (practicalScores.length > 0) {
                practicalScores.forEach(input => {
                    if (input.value === null || input.value === '') {
                        practicalComplete = false;
                    }
                });
            }
            
            // Check if project has score
            let projectScore = document.getElementById('project_score')?.value;
            let projectComplete = projectScore && projectScore !== '';
            
            // Check if oral has score
            let oralScore = document.getElementById('oral_score')?.value;
            let oralComplete = oralScore && oralScore !== '';
            
            if (!practicalComplete) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Assessment',
                    text: 'Please complete all practical skills scores before finalizing.',
                    confirmButtonColor: '#3085d6'
                });
                return false;
            }
            
            if (!projectComplete) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Assessment',
                    text: 'Please enter project score before finalizing.',
                    confirmButtonColor: '#3085d6'
                });
                return false;
            }
            
            if (!oralComplete) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Assessment',
                    text: 'Please enter oral assessment score before finalizing.',
                    confirmButtonColor: '#3085d6'
                });
                return false;
            }
            
            return Swal.fire({
                title: 'Finalize Assessment?',
                text: 'This will update the trainee\'s status and cannot be undone easily.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, finalize it!'
            }).then((result) => {
                return result.isConfirmed;
            });
        }
        
        function addField(form, name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }
        
        function downloadPDF() {
            Swal.fire({
                title: 'Generating PDF...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    // Pass only this trainee's ID
                    window.open('generate_pdf.php?enrollment_id=' + enrollmentId + '&single=true', '_blank');
                    
                    setTimeout(() => {
                        Swal.close();
                    }, 2000);
                }
            });
        }
        
        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            updatePracticalTotal();
        });
    </script>
</body>
</html>"