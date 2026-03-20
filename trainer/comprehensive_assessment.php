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
// CREATE/UPDATE TABLES AND COLUMNS
// ============================================

// Add columns to assessment_components if they don't exist
$conn->query("ALTER TABLE assessment_components 
    ADD COLUMN IF NOT EXISTS project_visible_to_trainee TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS oral_questions_visible_to_trainee TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS practical_skills_saved TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS oral_questions_saved TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS practical_skills_grading TEXT,
    ADD COLUMN IF NOT EXISTS oral_questions TEXT
");

// Add results column to enrollments table if not exists
$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS results VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS assessment VARCHAR(20) DEFAULT NULL");

// ============================================
// CREATE TRAINEE-SPECIFIC TABLES
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS trainee_practical_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    skill_name VARCHAR(255) NOT NULL,
    max_score INT DEFAULT 20,
    score DECIMAL(5,2) DEFAULT NULL,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (enrollment_id),
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS trainee_oral_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    question TEXT NOT NULL,
    max_score INT DEFAULT 25,
    score DECIMAL(5,2) DEFAULT NULL,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (enrollment_id),
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
)");

// ============================================
// TOGGLE VISIBILITY - AJAX HANDLER
// ============================================
if (isset($_POST['ajax_toggle'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
        echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        exit;
    }
    
    $enrollment_id = intval($_POST['enrollment_id']);
    $type = $_POST['type'] ?? '';
    $new_value = intval($_POST['set']);
    
    if ($enrollment_id > 0 && in_array($type, ['project', 'oral'])) {
        $check_enrollment = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
        if ($check_enrollment && $check_enrollment->num_rows > 0) {
            $field = $type === 'project' ? 'project_visible_to_trainee' : 'oral_questions_visible_to_trainee';
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            
            if ($check && $check->num_rows > 0) {
                $update = $conn->query("UPDATE assessment_components SET $field = $new_value WHERE enrollment_id = $enrollment_id");
                if ($update) {
                    echo json_encode(['success' => true, 'message' => 'Visibility updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $conn->error]);
                }
            } else {
                $insert = $conn->query("INSERT INTO assessment_components (enrollment_id, $field, oral_max_score) VALUES ($enrollment_id, $new_value, 100)");
                if ($insert) {
                    echo json_encode(['success' => true, 'message' => 'Visibility created successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database insert failed: ' . $conn->error]);
                }
            }
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request type']);
    exit;
}

// ============================================
// HANDLE TRAINEE PROJECT SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_project_trainee'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
        header('Location: /login.php');
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    
    $check = $conn->prepare("SELECT id FROM enrollments WHERE id = ? AND user_id = ?");
    $check->bind_param("ii", $enrollment_id, $user_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("Invalid enrollment");
    }
    
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
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=project&submitted=1");
    exit;
}

// ============================================
// HANDLE TRAINEE ORAL ANSWERS SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_oral_answers'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainee') {
        header('Location: /login.php');
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    
    // Verify this enrollment belongs to the trainee
    $check = $conn->prepare("SELECT id FROM enrollments WHERE id = ? AND user_id = ?");
    $check->bind_param("ii", $enrollment_id, $user_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("Invalid enrollment");
    }
    
    // Get the answers from the form (submitted as an array)
    $answers = isset($_POST['answers']) ? $_POST['answers'] : [];
    
    // Convert answers array to comma-separated string
    $answers_string = implode(",", $answers);
    
    // Get existing assessment component or create new one
    $check_ac = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    
    if ($check_ac->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE assessment_components SET 
            oral_answers = ?,
            oral_submitted_by_trainee = 1,
            oral_submitted_at = NOW()
            WHERE enrollment_id = ?");
        $stmt->bind_param("si", $answers_string, $enrollment_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO assessment_components 
            (enrollment_id, oral_answers, oral_submitted_by_trainee, oral_submitted_at, oral_max_score) 
            VALUES (?, ?, 1, NOW(), 100)");
        $stmt->bind_param("is", $enrollment_id, $answers_string);
        $stmt->execute();
    }
    
    // Redirect back to the trainee's view
    header("Location: trainee_assessment.php?enrollment_id=$enrollment_id&tab=oral&answers_submitted=1");
    exit;
}

// ============================================
// CLEAR TRAINEE ORAL ANSWERS (Trainer action)
// ============================================
if (isset($_POST['clear_oral_answers'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
        header('Location: /login.php');
        exit;
    }
    
    $conn->query("UPDATE assessment_components SET 
        oral_answers = NULL,
        oral_submitted_by_trainee = 0,
        oral_submitted_at = NULL 
        WHERE enrollment_id = $enrollment_id");
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&answers_cleared=1");
    exit;
}

// ============================================
// SAVE TRAINEE-SPECIFIC PRACTICAL SKILLS (WITHOUT SCORES)
// ============================================
if (isset($_POST['save_trainee_practical_skills'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        // DELETE old skills for this trainee
        $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        
        // Insert new trainee skills (without scores)
        $skills_json = $_POST['trainee_skills'];
        $skills = json_decode($skills_json, true);
        
        if (is_array($skills)) {
            $stmt = $conn->prepare("INSERT INTO trainee_practical_skills 
                (enrollment_id, skill_name, max_score, order_index) 
                VALUES (?, ?, ?, ?)");
            
            foreach ($skills as $index => $skill) {
                $skill_name = $skill['name'];
                $max_score = $skill['max_score'];
                
                $stmt->bind_param("isii", $enrollment_id, $skill_name, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Update assessment_components to mark as saved
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET 
                practical_skills_saved = 1,
                practical_skills_grading = '" . $conn->real_escape_string($skills_json) . "'
                WHERE enrollment_id = $enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components 
                (enrollment_id, practical_skills_saved, practical_skills_grading, oral_max_score) 
                VALUES ($enrollment_id, 1, '" . $conn->real_escape_string($skills_json) . "', 100)");
        }
        
        // Return JSON response for AJAX
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Practical skills saved successfully']);
            exit;
        }
    }
    
    // Redirect for form submission
    if (!isset($_POST['ajax'])) {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&skills_saved=1");
        exit;
    }
}

// ============================================
// SAVE TRAINEE-SPECIFIC ORAL QUESTIONS (WITHOUT SCORES)
// ============================================
if (isset($_POST['save_trainee_oral_questions'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        // DELETE old questions for this trainee
        $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        
        // Insert new trainee questions (without scores)
        $questions_json = $_POST['trainee_questions'];
        $questions = json_decode($questions_json, true);
        
        if (is_array($questions)) {
            $stmt = $conn->prepare("INSERT INTO trainee_oral_questions 
                (enrollment_id, question, max_score, order_index) 
                VALUES (?, ?, ?, ?)");
            
            foreach ($questions as $index => $q) {
                $question = $q['question'];
                $max_score = $q['max_score'];
                
                $stmt->bind_param("isii", $enrollment_id, $question, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Update assessment_components to mark as saved
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET 
                oral_questions_saved = 1,
                oral_questions = '" . $conn->real_escape_string($questions_json) . "'
                WHERE enrollment_id = $enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components 
                (enrollment_id, oral_questions_saved, oral_questions, oral_max_score) 
                VALUES ($enrollment_id, 1, '" . $conn->real_escape_string($questions_json) . "', 100)");
        }
        
        // Return JSON response for AJAX
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Oral questions saved successfully']);
            exit;
        }
    }
    
    // Redirect for form submission
    if (!isset($_POST['ajax'])) {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&questions_saved=1");
        exit;
    }
}

// ============================================
// SAVE SCORES FOR PRACTICAL SKILLS
// ============================================
if (isset($_POST['save_practical_scores'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        // Update scores for each skill
        $score_data = json_decode($_POST['skill_scores'], true);
        
        if (is_array($score_data)) {
            $update_stmt = $conn->prepare("UPDATE trainee_practical_skills SET score = ? WHERE id = ?");
            
            foreach ($score_data as $skill_id => $score) {
                if (is_numeric($skill_id)) {
                    $score_val = ($score !== '' && $score !== null) ? floatval($score) : null;
                    $update_stmt->bind_param("di", $score_val, $skill_id);
                    $update_stmt->execute();
                }
            }
            $update_stmt->close();
        }
        
        // Update assessment_components practical_score
        $total_score = 0;
        $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        if ($total_query && $total_row = $total_query->fetch_assoc()) {
            $total_score = $total_row['total'] ?? 0;
        }
        
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET practical_score = $total_score WHERE enrollment_id = $enrollment_id");
        }
        
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Scores saved successfully']);
            exit;
        }
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&scores_saved=1");
    exit;
}

// ============================================
// SAVE SCORES FOR ORAL QUESTIONS
// ============================================
if (isset($_POST['save_oral_scores'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        // Update scores for each question
        $score_data = json_decode($_POST['question_scores'], true);
        
        if (is_array($score_data)) {
            $update_stmt = $conn->prepare("UPDATE trainee_oral_questions SET score = ? WHERE id = ?");
            
            foreach ($score_data as $question_id => $score) {
                if (is_numeric($question_id)) {
                    $score_val = ($score !== '' && $score !== null) ? floatval($score) : null;
                    $update_stmt->bind_param("di", $score_val, $question_id);
                    $update_stmt->execute();
                }
            }
            $update_stmt->close();
        }
        
        // Update assessment_components oral_score
        $total_score = 0;
        $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        if ($total_query && $total_row = $total_query->fetch_assoc()) {
            $total_score = $total_row['total'] ?? 0;
        }
        
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET oral_score = $total_score WHERE enrollment_id = $enrollment_id");
        }
        
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Scores saved successfully']);
            exit;
        }
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&scores_saved=1");
    exit;
}

// ============================================
// ACTUAL REMOVE TRAINEE SKILLS (PERMANENT DELETE)
// ============================================
if (isset($_POST['remove_trainee_skills'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET practical_skills_saved = 0, practical_skills_grading = NULL, practical_score = 0 WHERE enrollment_id = $enrollment_id");
    }
    
    if (isset($_POST['ajax'])) {
        echo json_encode(['success' => true, 'message' => 'All skills removed permanently']);
        exit;
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&removed=1");
    exit;
}

// ============================================
// ACTUAL REMOVE TRAINEE QUESTIONS (PERMANENT DELETE)
// ============================================
if (isset($_POST['remove_trainee_questions'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET oral_questions_saved = 0, oral_questions = NULL, oral_score = 0 WHERE enrollment_id = $enrollment_id");
    }
    
    if (isset($_POST['ajax'])) {
        echo json_encode(['success' => true, 'message' => 'All questions removed permanently']);
        exit;
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&removed=1");
    exit;
}

// ============================================
// REMOVE SINGLE SKILL
// ============================================
if (isset($_POST['remove_single_skill'])) {
    $skill_id = intval($_POST['skill_id']);
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $conn->query("DELETE FROM trainee_practical_skills WHERE id = $skill_id");
    
    // Check if any skills remain
    $remaining = $conn->query("SELECT COUNT(*) as count FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id")->fetch_assoc();
    if ($remaining['count'] == 0) {
        $conn->query("UPDATE assessment_components SET practical_skills_saved = 0, practical_score = 0 WHERE enrollment_id = $enrollment_id");
    } else {
        // Update practical_score
        $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        if ($total_query && $total_row = $total_query->fetch_assoc()) {
            $total_score = $total_row['total'] ?? 0;
            $conn->query("UPDATE assessment_components SET practical_score = $total_score WHERE enrollment_id = $enrollment_id");
        }
    }
    
    if (isset($_POST['ajax'])) {
        echo json_encode(['success' => true, 'message' => 'Skill removed successfully']);
        exit;
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&skill_removed=1");
    exit;
}

// ============================================
// REMOVE SINGLE QUESTION
// ============================================
if (isset($_POST['remove_single_question'])) {
    $question_id = intval($_POST['question_id']);
    $enrollment_id = intval($_POST['enrollment_id']);
    
    $conn->query("DELETE FROM trainee_oral_questions WHERE id = $question_id");
    
    // Check if any questions remain
    $remaining = $conn->query("SELECT COUNT(*) as count FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id")->fetch_assoc();
    if ($remaining['count'] == 0) {
        $conn->query("UPDATE assessment_components SET oral_questions_saved = 0, oral_score = 0 WHERE enrollment_id = $enrollment_id");
    } else {
        // Update oral_score
        $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        if ($total_query && $total_row = $total_query->fetch_assoc()) {
            $total_score = $total_row['total'] ?? 0;
            $conn->query("UPDATE assessment_components SET oral_score = $total_score WHERE enrollment_id = $enrollment_id");
        }
    }
    
    if (isset($_POST['ajax'])) {
        echo json_encode(['success' => true, 'message' => 'Question removed successfully']);
        exit;
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&question_removed=1");
    exit;
}

// ============================================
// HANDLE SAVE TAB DATA (for project only)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tab'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $tab = $_POST['tab'];
    
    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $check = $conn->prepare("SELECT id FROM assessment_components WHERE enrollment_id = ?");
        $check->bind_param("i", $enrollment_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        
        if ($tab === 'project') {
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
        }
        
        // Calculate overall result after saving
        $assessment_result = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($assessment_result && $assessment = $assessment_result->fetch_assoc()) {
            $practical = $assessment['practical_score'] ?? 0;
            $project = $assessment['project_score'] ?? 0;
            $oral = $assessment['oral_score'] ?? 0;
            
            // Get practical max from trainee skills
            $practical_max = 100; // Default
            $practical_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            if ($practical_max_query && $practical_max_row = $practical_max_query->fetch_assoc()) {
                $practical_max = $practical_max_row['total'] ?? 100;
            }
            
            // Get oral max
            $oral_max = $assessment['oral_max_score'] ?? 100;
            
            $total = $practical + $project + $oral;
            $max_total = $practical_max + 100 + $oral_max;
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
    }
    
    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=" . urlencode($tab) . "&saved=1");
    exit;
}

// ============================================
// HANDLE SAVE ASSESSMENT (from summary tab)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $updated = 0;
    
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
        
        // Get practical max from trainee skills
        $practical_max = 100; // Default
        $practical_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        if ($practical_max_query && $practical_max_row = $practical_max_query->fetch_assoc()) {
            $practical_max = $practical_max_row['total'] ?? 100;
        }
        
        // Get oral max
        $oral_max = $assessment['oral_max_score'] ?? 100;
        
        $total = $practical + $project + $oral;
        $max_total = $practical_max + 100 + $oral_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
        $overall_result = ($percentage >= 75) ? 'Passed' : 'Failed';
        
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

        $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
        $update_enrollment = $conn->prepare("UPDATE enrollments SET 
            enrollment_status = ?,
            results = ?,
            assessment = ?,
            completion_date = NOW()
            WHERE id = ?");
        $update_enrollment->bind_param("sssi", $enrollment_status, $overall_result, $overall_result, $enrollment_id);
        
        $update_archive = $conn->prepare("UPDATE archived_history SET 
            enrollment_completed_at = NOW(),
            enrollment_status = ?,
            enrollment_assessment = ?,
            updated_at = NOW()
            WHERE user_id = ? AND enrollment_id = ? AND archive_trigger = 'enrollment_completed'");
        $update_archive->bind_param("ssii", $enrollment_status, $overall_result, $user_id, $enrollment_id);

        $enrollment_updated = $update_enrollment->execute();
        
        if ($update_archive) {
            $archive_updated = $update_archive->execute();
            if (!$archive_updated && $update_archive->affected_rows === 0) {
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
        'practical_skills_saved' => 0,
        'oral_questions_saved' => 0,
        'project_submitted_by_trainee' => 0,
        'oral_submitted_by_trainee' => 0,
        'oral_answers' => null,
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
        'overall_result' => null,
        'overall_total_score' => 0
    ];
}

// Parse oral answers if they exist (comma-separated string)
$oral_answers_array = [];
if (!empty($existing_assessment['oral_answers'])) {
    $oral_answers_array = explode(',', $existing_assessment['oral_answers']);
}

// Get trainee-specific practical skills
$trainee_skills = [];
$trainee_skills_result = $conn->query("SELECT * FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id ORDER BY order_index");
if ($trainee_skills_result && $trainee_skills_result->num_rows > 0) {
    $trainee_skills = $trainee_skills_result->fetch_all(MYSQLI_ASSOC);
}

// Get trainee-specific oral questions
$trainee_questions = [];
$trainee_questions_result = $conn->query("SELECT * FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id ORDER BY order_index");
if ($trainee_questions_result && $trainee_questions_result->num_rows > 0) {
    $trainee_questions = $trainee_questions_result->fetch_all(MYSQLI_ASSOC);
}

// Calculate practical total and max from trainee skills
$practical_total = 0;
$practical_max_total = 0;
$practical_has_all_scores = true;
if (!empty($trainee_skills)) {
    foreach ($trainee_skills as $skill) {
        $practical_max_total += intval($skill['max_score'] ?? 0);
        if ($skill['score'] === null) {
            $practical_has_all_scores = false;
        } else {
            $practical_total += floatval($skill['score']);
        }
    }
} else {
    $practical_has_all_scores = false;
}

// If no scores yet, use existing assessment practical_score
if ($practical_total == 0 && isset($existing_assessment['practical_score']) && $existing_assessment['practical_score'] > 0) {
    $practical_total = $existing_assessment['practical_score'];
}

// Ensure practical_max_total is at least 100 for display consistency
if ($practical_max_total == 0) {
    $practical_max_total = 100;
}

// Calculate oral max total from trainee questions
$oral_max_from_trainee = 0;
$oral_has_all_scores = true;
$oral_calculated_score = 0;
if (!empty($trainee_questions)) {
    foreach ($trainee_questions as $q) {
        $oral_max_from_trainee += intval($q['max_score'] ?? 0);
        if ($q['score'] === null) {
            $oral_has_all_scores = false;
        } else {
            $oral_calculated_score += floatval($q['score']);
        }
    }
}

$oral_max = $oral_max_from_trainee > 0 ? $oral_max_from_trainee : ($existing_assessment['oral_max_score'] ?? 100);
$oral_score = $oral_calculated_score > 0 ? $oral_calculated_score : ($existing_assessment['oral_score'] ?? 0);

// Calculate totals for summary
$project_score = $existing_assessment['project_score'] ?? 0;

$total_score = $practical_total + $project_score + $oral_score;
$total_max = $practical_max_total + 100 + $oral_max;
$overall_percent = $total_max > 0 ? round(($total_score / $total_max) * 100, 1) : 0;
$overall_result = $overall_percent >= 75 ? 'PASSED' : 'FAILED';

// Get current tab from URL
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'practical';
$removed_message = isset($_GET['removed']) ? 'All items removed permanently!' : '';
$skill_removed_message = isset($_GET['skill_removed']) ? 'Skill removed successfully!' : '';
$question_removed_message = isset($_GET['question_removed']) ? 'Question removed successfully!' : '';
$saved_message = isset($_GET['saved']) ? 'Assessment saved successfully!' : '';
$skills_saved_message = isset($_GET['skills_saved']) ? 'Trainee skills saved successfully!' : '';
$questions_saved_message = isset($_GET['questions_saved']) ? 'Trainee questions saved successfully!' : '';
$scores_saved_message = isset($_GET['scores_saved']) ? 'Scores saved successfully!' : '';
$answers_cleared_message = isset($_GET['answers_cleared']) ? 'Trainee answers cleared successfully!' : '';
$error_message = isset($_GET['error']) ? 'Error finalizing assessment. Please try again.' : '';



// Decode JSON - handles both ["value"] and "value" formats
if (!empty($answer_text)) {
    $decoded = json_decode($answer_text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) {
            $answer_text = implode(', ', $decoded);
        } elseif (is_string($decoded)) {
            $answer_text = $decoded;
        }
    }
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
        .delete-btn { background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .delete-btn:hover { background: #c82333; }
        .save-trainee-skills-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-trainee-skills-btn:hover { background: #218838; }
        .save-trainee-questions-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-trainee-questions-btn:hover { background: #218838; }
        .save-scores-btn { background: #ffc107; color: #333; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-scores-btn:hover { background: #e0a800; }
        .clear-answers-btn { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .clear-answers-btn:hover { background: #5a6268; }
        .save-tab-btn { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 12px 30px; border-radius: 50px; font-size: 16px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; }
        .submit-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 40px; font-size: 18px; font-weight: 600; border-radius: 50px; cursor: pointer; }
        .print-btn { border-color: #6c757d; color: #6c757d; padding: 8px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .print-btn:hover { background: #6c757d; color: white; }
        
        /* Custom Skill Row */
        .custom-skill-row { background: #f8f9fa; margin-bottom: 15px; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; position: relative; }
        .skill-id { display: none; }
        
        /* Scoring Card Styles */
        .scoring-section { background: #fff; padding: 20px; border-radius: 10px; margin-top: 30px; border: 2px solid #ffc107; }
        .scoring-section h4 { color: #ffc107; margin-bottom: 15px; }
        .score-input { width: 80px; text-align: center; padding: 8px; border: 2px solid #ffc107; border-radius: 5px; font-weight: 600; }
        .score-max { font-size: 14px; color: #666; margin-top: 4px; }
        .pass-badge { background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .fail-badge { background: #dc3545; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .pending-badge { background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        
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
        
        .trainee-section { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #28a745; }
        .trainee-section h4 { color: #28a745; margin-bottom: 15px; }
        
        /* Visibility Status */
        .visibility-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .status-visible { background: #d4edda; color: #155724; }
        .status-hidden { background: #f8d7da; color: #721c24; }
        
        /* Saved Status */
        .saved-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .status-saved { background: #d4edda; color: #155724; }
        .status-unsaved { background: #fff3cd; color: #856404; }
        
        /* Oral answers display */
        .oral-answer-card { background: #f3e8ff; padding: 15px; margin-bottom: 10px; border-left: 5px solid #8b5cf6; border-radius: 5px; }
        
        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 10% auto; padding: 30px; border-radius: 15px; width: 80%; max-width: 500px; }
        .close { float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        
        /* Section Divider */
        .section-divider { margin: 40px 0 20px; border-top: 2px dashed #667eea; text-align: center; }
        .section-divider span { background: #667eea; color: white; padding: 5px 20px; border-radius: 50px; font-size: 18px; font-weight: 600; display: inline-block; transform: translateY(-50%); }
        
        /* Print Styles */
        @media print {
            .header, .tabs, .back-btn, .toggle-container, .submit-btn, 
            .save-tab-btn, .print-btn, .modal, .add-btn, .remove-btn, .delete-btn, .button-group,
            .save-trainee-skills-btn, .save-trainee-questions-btn, .save-scores-btn, .clear-answers-btn {
                display: none !important;
            }
            .assessment-card { box-shadow: none; border: 1px solid #ddd; }
            .summary-card { break-inside: avoid; }
        }
        
        /* Disabled inputs in scoring section */
        .max-score-display { background-color: #e9ecef; padding: 8px 12px; border-radius: 5px; font-weight: 600; display: inline-block; }
        
        /* Answer styling */
        .answer-box { background: #f3e8ff; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #8b5cf6; }
        .answer-text { white-space: pre-wrap; margin: 10px 0 0 0; }
        .no-answer { background: #fff3cd; padding: 10px; border-radius: 6px; color: #856404; }
        
        /* Answer status */
        .answer-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .answer-submitted { background: #d4edda; color: #155724; }
        .answer-not-submitted { background: #fff3cd; color: #856404; }
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
        
        <?php if ($removed_message): ?>
            <div class="alert-danger"><?php echo $removed_message; ?></div>
        <?php endif; ?>
        
        <?php if ($skill_removed_message): ?>
            <div class="alert-danger"><?php echo $skill_removed_message; ?></div>
        <?php endif; ?>
        
        <?php if ($question_removed_message): ?>
            <div class="alert-danger"><?php echo $question_removed_message; ?></div>
        <?php endif; ?>
        
        <?php if ($saved_message): ?>
            <div class="alert-success"><?php echo $saved_message; ?></div>
        <?php endif; ?>
        
        <?php if ($skills_saved_message): ?>
            <div class="alert-success"><?php echo $skills_saved_message; ?></div>
        <?php endif; ?>
        
        <?php if ($questions_saved_message): ?>
            <div class="alert-success"><?php echo $questions_saved_message; ?></div>
        <?php endif; ?>
        
        <?php if ($scores_saved_message): ?>
            <div class="alert-success"><?php echo $scores_saved_message; ?></div>
        <?php endif; ?>
        
        <?php if ($answers_cleared_message): ?>
            <div class="alert-success"><?php echo $answers_cleared_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-danger"><?php echo $error_message; ?></div>
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
        
        <!-- Tabs -->
        <div class="tabs" id="tabs">
            <div class="tab <?php echo $current_tab == 'practical' ? 'active' : ''; ?>" onclick="switchTab('practical')">1. Practical Skills</div>
            <div class="tab <?php echo $current_tab == 'project' ? 'active' : ''; ?>" onclick="switchTab('project')">2. Project Output</div>
            <div class="tab <?php echo $current_tab == 'oral' ? 'active' : ''; ?>" onclick="switchTab('oral')">3. Oral Questions</div>
            <div class="tab <?php echo $current_tab == 'summary' ? 'active' : ''; ?>" onclick="switchTab('summary')">4. Summary & Result</div>
        </div>
        
        <!-- Hidden form for tab switching -->
        <form id="switchTabForm" method="GET" style="display:none;">
            <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
            <input type="hidden" name="tab" id="tabInput" value="">
        </form>
        
        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeDeleteModal()">&times;</span>
                <h3>Confirm Permanent Deletion</h3>
                <p id="deleteModalMessage"></p>
                <input type="hidden" id="deleteType">
                <input type="hidden" id="deleteEnrollmentId" value="<?php echo $enrollment_id; ?>">
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button class="reset-btn" onclick="closeDeleteModal()">Cancel</button>
                    <button class="delete-btn" onclick="confirmDelete()">Yes, Delete Permanently</button>
                </div>
            </div>
        </div>
        
        <!-- TAB 1: PRACTICAL SKILLS (with Scoring Section) -->
        <div class="assessment-card" id="tab-practical" style="display: <?php echo $current_tab == 'practical' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-utensils"></i> Practical Skills Assessment (Trainee-Selective)
                <span style="margin-left: auto; background: #667eea; color: white; padding: 5px 15px; border-radius: 20px;">
                    Total: <span id="practicalTotal"><?php echo $practical_total; ?></span>/<span id="practicalMaxTotal"><?php echo $practical_max_total; ?></span>
                </span>
                <?php if ($existing_assessment['practical_skills_saved'] ?? 0): ?>
                    <span class="saved-status status-saved"><i class="fas fa-check-circle"></i> Skills Saved</span>
                <?php else: ?>
                    <span class="saved-status status-unsaved"><i class="fas fa-exclamation-triangle"></i> Skills Not Saved</span>
                <?php endif; ?>
            </div>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> Create selective practical skills for this specific trainee. Add, remove, or modify skills as needed. Then enter scores below.
            </div>
            
            <!-- Trainee's Selective Skills Section (No Scores) -->
            <div class="trainee-section">
                <h4><i class="fas fa-list"></i> Skills List (Set Maximum Scores)</h4>
                
                <div class="button-group">
                    <button type="button" class="delete-btn" onclick="openDeleteModal('practical')">
                        <i class="fas fa-trash"></i> Delete All Skills Permanently
                    </button>
                </div>
                
                <p style="margin-bottom: 15px; color: #28a745;"><i class="fas fa-info-circle"></i> Create and manage selective skills for this trainee. These skills are specific to this trainee only.</p>
                
                <!-- Trainee's Skills Container (No Score Inputs) -->
                <div id="trainee-skills-container">
                    <?php if (!empty($trainee_skills)): ?>
                        <?php foreach ($trainee_skills as $index => $skill): ?>
                        <div class="custom-skill-row" data-skill-id="<?php echo $skill['id']; ?>">
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <div style="flex: 2;">
                                    <input type="text" class="form-control trainee-skill-name" value="<?php echo htmlspecialchars($skill['skill_name']); ?>">
                                    <span class="input-label">Skill name</span>
                                </div>
                                <div style="flex: 1;">
                                    <input type="number" class="form-control trainee-skill-max" value="<?php echo $skill['max_score']; ?>" min="1" max="100">
                                    <span class="input-label">Max score</span>
                                </div>
                                <div style="flex: 0.3;">
                                    <button type="button" class="remove-btn" onclick="removeSingleSkill(<?php echo $skill['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="waiting-message">
                        <i class="fas fa-info-circle"></i> No skills created for this trainee yet. Add new skills below to create a selective assessment for this trainee.
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="add-btn" onclick="addTraineeSkill()">
                        <i class="fas fa-plus"></i> Add Skill for This Trainee
                    </button>
                    <button type="button" class="save-trainee-skills-btn" onclick="saveTraineeSkills()">
                        <i class="fas fa-save"></i> Save Skills (Max Scores)
                    </button>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label">Notes / Observations:</label>
                    <textarea id="practical_notes" class="form-control" rows="3" placeholder="Add any observations..."><?php echo htmlspecialchars($existing_assessment['practical_notes'] ?? ''); ?></textarea>
                </div>
                
                <div class="total-display" id="practicalMaxDisplay">
                    Total Maximum Score: <span id="practicalMaxTotalValue"><?php echo $practical_max_total; ?></span>
                    <?php if (empty($trainee_skills)): ?>
                        <span style="color: #ffc107; margin-left: 10px;"><i class="fas fa-clock"></i> NO SKILLS</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Scoring Section (under the trainee section) -->
            <?php if (!empty($trainee_skills)): ?>
            <div class="scoring-section">
                <h4><i class="fas fa-star"></i> Enter Scores for Practical Skills</h4>
                
                <div id="practical-scoring-container">
                    <?php foreach ($trainee_skills as $skill): ?>
                    <div class="custom-skill-row" style="border-left-color: #ffc107;">
                        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                            <div style="flex: 2; min-width: 200px;">
                                <strong><?php echo htmlspecialchars($skill['skill_name']); ?></strong>
                                <div class="score-max">Max: <?php echo $skill['max_score']; ?> points</div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="number" class="score-input practical-score" 
                                           data-skill-id="<?php echo $skill['id']; ?>" 
                                           value="<?php echo $skill['score'] !== null ? $skill['score'] : ''; ?>" 
                                           min="0" max="<?php echo $skill['max_score']; ?>" 
                                           placeholder="Score" onchange="updatePracticalScoringTotal()">
                                    <span>/ <?php echo $skill['max_score']; ?></span>
                                </div>
                            </div>
                            <div style="flex: 0.5;">
                                <?php if ($skill['score'] !== null): ?>
                                    <?php if ($skill['score'] >= ($skill['max_score'] * 0.75)): ?>
                                        <span class="pass-badge"><i class="fas fa-check-circle"></i> Pass</span>
                                    <?php else: ?>
                                        <span class="fail-badge"><i class="fas fa-times-circle"></i> Fail</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="pending-badge"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="save-scores-btn" onclick="savePracticalScores()">
                        <i class="fas fa-save"></i> Save Scores
                    </button>
                </div>
                
                <div class="total-display" style="margin-top: 15px; background: #fff3cd;">
                    Practical Subtotal: <span id="practicalScoringTotal"><?php echo $practical_total; ?></span>/<span id="practicalScoringMax"><?php echo $practical_max_total; ?></span>
                    <?php if ($practical_has_all_scores): ?>
                        <?php if ($practical_total >= ($practical_max_total * 0.75)): ?>
                            <span class="pass-badge"><i class="fas fa-check-circle"></i> PASSED</span>
                        <?php else: ?>
                            <span class="fail-badge"><i class="fas fa-times-circle"></i> FAILED</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pending-badge"><i class="fas fa-clock"></i> INCOMPLETE</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="save-tab-btn" onclick="switchTab('summary')">
                    <i class="fas fa-arrow-right"></i> Proceed to Summary
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
        
        <!-- TAB 3: ORAL QUESTIONS (with Scoring Section and Integrated Answers) -->
        <div class="assessment-card" id="tab-oral" style="display: <?php echo $current_tab == 'oral' ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-question-circle"></i> Oral Questions (Trainee-Selective)
                <span style="margin-left: auto; background: #667eea; color: white; padding: 5px 15px; border-radius: 20px;">
                    Total: <span id="oralTotal"><?php echo $oral_score; ?></span>/<span id="oralMaxTotal"><?php echo $oral_max; ?></span>
                </span>
                <?php if ($existing_assessment['oral_questions_saved'] ?? 0): ?>
                    <span class="saved-status status-saved"><i class="fas fa-check-circle"></i> Questions Saved</span>
                <?php else: ?>
                    <span class="saved-status status-unsaved"><i class="fas fa-exclamation-triangle"></i> Questions Not Saved</span>
                <?php endif; ?>
                <div class="toggle-container">
                    <span class="toggle-label">Show to Trainee:</span>
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               onchange="toggleVisibility('oral', <?php echo $enrollment_id; ?>, this)"
                               <?php echo ($existing_assessment['oral_questions_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <?php if ($existing_assessment['oral_questions_visible_to_trainee'] ?? 0): ?>
                        <span class="visibility-status status-visible"><i class="fas fa-eye"></i> Visible to trainee</span>
                    <?php else: ?>
                        <span class="visibility-status status-hidden"><i class="fas fa-eye-slash"></i> Hidden from trainee</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> Create selective oral questions for this specific trainee. Add, remove, or modify questions as needed. Trainee answers are displayed below each question for scoring.
            </div>
            
            <!-- Answer Status -->
            <div style="margin-bottom: 20px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                <?php if ($existing_assessment['oral_submitted_by_trainee'] ?? 0): ?>
                    <span class="answer-status answer-submitted"><i class="fas fa-check-circle"></i> Answers Submitted by Trainee on <?php echo date('F d, Y H:i', strtotime($existing_assessment['oral_submitted_at'] ?? '')); ?></span>
                <?php else: ?>
                    <span class="answer-status answer-not-submitted"><i class="fas fa-clock"></i> Waiting for Trainee Answers</span>
                <?php endif; ?>
                
                <?php if (!empty($trainee_questions) && ($existing_assessment['oral_submitted_by_trainee'] ?? 0)): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirmClearAnswers()">
                    <input type="hidden" name="clear_oral_answers" value="1">
                    <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                    <button type="submit" class="clear-answers-btn">
                        <i class="fas fa-eraser"></i> Clear All Answers
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <!-- Trainee's Selective Questions Section (No Scores) -->
            <div class="trainee-section">
                <h4><i class="fas fa-list"></i> Questions List (Set Maximum Scores)</h4>
                
                <div class="button-group">
                    <button type="button" class="delete-btn" onclick="openDeleteModal('oral')">
                        <i class="fas fa-trash"></i> Delete All Questions Permanently
                    </button>
                </div>
                
                <p style="margin-bottom: 15px; color: #28a745;"><i class="fas fa-info-circle"></i> Create and manage selective questions for this trainee. These questions are specific to this trainee only.</p>
                
                <input type="hidden" id="oral_max_score" value="<?php echo $oral_max; ?>">
                
                <!-- Trainee's Questions Container (No Score Inputs) -->
                <div id="trainee-questions-container">
                    <?php if (!empty($trainee_questions)): ?>
                        <?php foreach ($trainee_questions as $index => $q): ?>
                        <div class="custom-skill-row" data-question-id="<?php echo $q['id']; ?>">
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <div style="flex: 2;">
                                    <input type="text" class="form-control trainee-question" value="<?php echo htmlspecialchars($q['question']); ?>">
                                    <span class="input-label">Question</span>
                                </div>
                                <div style="flex: 1;">
                                    <input type="number" class="form-control trainee-question-max" value="<?php echo $q['max_score']; ?>" min="1" max="100">
                                    <span class="input-label">Max score</span>
                                </div>
                                <div style="flex: 0.3;">
                                    <button type="button" class="remove-btn" onclick="removeSingleQuestion(<?php echo $q['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="waiting-message">
                        <i class="fas fa-info-circle"></i> No questions created for this trainee yet. Add new questions below to create a selective oral assessment for this trainee.
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="add-btn" onclick="addTraineeQuestion()">
                        <i class="fas fa-plus"></i> Add Question for This Trainee
                    </button>
                    <button type="button" class="save-trainee-questions-btn" onclick="saveTraineeQuestions()">
                        <i class="fas fa-save"></i> Save Questions (Max Scores)
                    </button>
                </div>
            </div>
            
<!-- Scoring Section with Answers (under the trainee section) -->

<!-- Scoring Section with Answers (under the trainee section) -->

<?php if (!empty($trainee_questions)): ?>
<div class="scoring-section" style="border-color: #8b5cf6;">
    <h4><i class="fas fa-star"></i> Enter Scores for Oral Questions</h4>
    <p style="margin-bottom: 15px; color: #8b5cf6;"><i class="fas fa-info-circle"></i> Review trainee answers and assign scores below.</p>
    
    <div id="oral-scoring-container">
        <?php foreach ($trainee_questions as $index => $question): ?>
        <div class="custom-skill-row" style="border-left-color: #8b5cf6; margin-bottom: 20px;">
            <div style="margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong style="font-size: 16px;">Question <?php echo $index + 1; ?>:</strong>
                    <span class="score-max">Max Score: <?php echo $question['max_score']; ?> points</span>
                </div>
                
                <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                    <p style="margin: 0; font-weight: 500;"><?php echo htmlspecialchars($question['question']); ?></p>
                </div>
                
                <?php 
                // Get answer for this question from the array
                $answer_text = isset($oral_answers_array[$index]) ? $oral_answers_array[$index] : '';
                
                // ROBUST CLEANING - Remove all JSON formatting artifacts
                if (!empty($answer_text)) {
                    $answer_text = trim($answer_text);

                    // Try JSON decode first (handles valid JSON arrays/strings)
                    $decoded = json_decode($answer_text, true);
                    if ($decoded !== null) {
                        if (is_array($decoded)) {
                            $answer_text = implode(', ', $decoded);
                        } else {
                            $answer_text = (string)$decoded;
                        }
                    } else {
                        // Manual cleanup for malformed/partial JSON
                        // Strip leading/trailing [, ], " characters
                        $answer_text = preg_replace('/^[\["\s]+/', '', $answer_text);
                        $answer_text = preg_replace('/[\]"\s]+$/', '', $answer_text);
                        $answer_text = trim($answer_text);
                    }
                }
                ?>
                
                <?php if (!empty($answer_text)): ?>
                <div class="answer-box">
                    <strong style="color: #8b5cf6; display: block; margin-bottom: 8px;">
                        <i class="fas fa-comment"></i> Trainee's Answer:
                    </strong>
                    <div class="answer-text" style="background: #f8f9fa; padding: 10px; border-radius: 5px;">
                        <?php echo nl2br(htmlspecialchars($answer_text)); ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-answer">
                    <i class="fas fa-clock"></i> No answer provided yet.
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-top: 15px;">
                    <div style="flex: 1; min-width: 200px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Score:</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" class="score-input oral-score" 
                                   data-question-id="<?php echo $question['id']; ?>" 
                                   value="<?php echo $question['score'] !== null ? $question['score'] : ''; ?>" 
                                   min="0" max="<?php echo $question['max_score']; ?>" 
                                   placeholder="Score" onchange="updateOralScoringTotal()" style="width: 100px;">
                            <span>/ <?php echo $question['max_score']; ?></span>
                        </div>
                    </div>
                    <div>
                        <?php if ($question['score'] !== null): ?>
                            <?php if ($question['score'] >= ($question['max_score'] * 0.75)): ?>
                                <span class="pass-badge"><i class="fas fa-check-circle"></i> Pass</span>
                            <?php else: ?>
                                <span class="fail-badge"><i class="fas fa-times-circle"></i> Fail</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="pending-badge"><i class="fas fa-clock"></i> Pending</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
        <button type="button" class="save-scores-btn" onclick="saveOralScores()">
            <i class="fas fa-save"></i> Save All Scores
        </button>
    </div>
    
    <div class="total-display" style="margin-top: 20px; background: #f3e8ff;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span>Oral Subtotal:</span>
            <span><span id="oralScoringTotal"><?php echo $oral_score; ?></span>/<span id="oralScoringMax"><?php echo $oral_max; ?></span></span>
        </div>
        <?php if ($oral_has_all_scores): ?>
            <?php if ($oral_score >= ($oral_max * 0.75)): ?>
                <div style="margin-top: 10px;"><span class="pass-badge"><i class="fas fa-check-circle"></i> PASSED</span></div>
            <?php else: ?>
                <div style="margin-top: 10px;"><span class="fail-badge"><i class="fas fa-times-circle"></i> FAILED</span></div>
            <?php endif; ?>
        <?php else: ?>
            <div style="margin-top: 10px;"><span class="pending-badge"><i class="fas fa-clock"></i> INCOMPLETE</span></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="save-tab-btn" onclick="switchTab('summary')">
                    <i class="fas fa-arrow-right"></i> Proceed to Summary
                </button>
            </div>
        </div>
        
        <!-- TAB 4: SUMMARY -->
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
                    <div class="score"><?php echo $practical_total; ?></div>
                    <div class="max-score">out of <?php echo $practical_max_total; ?></div>
                    <?php if (empty($trainee_skills)): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">NO SKILLS</div>
                    <?php elseif (!$practical_has_all_scores): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">INCOMPLETE</div>
                    <?php elseif ($practical_total >= ($practical_max_total * 0.75)): ?>
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
                    <?php if (empty($trainee_questions)): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">NO QUESTIONS</div>
                    <?php elseif (!$oral_has_all_scores): ?>
                        <div style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 5px; border-radius: 20px;">INCOMPLETE</div>
                    <?php elseif ($oral_score >= ($oral_max * 0.75)): ?>
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
                <?php if (!empty($trainee_skills)): ?>
                <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h4 style="color: #667eea; margin-bottom: 15px;">Practical Skills (Trainee-Selective)</h4>
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
                            foreach ($trainee_skills as $skill):
                                $practical_details_total += $skill['score'] ?? 0;
                                $practical_details_max += $skill['max_score'];
                                
                                $passed = ($skill['score'] ?? 0) >= ($skill['max_score'] * 0.75);
                            ?>
                            <tr>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo htmlspecialchars($skill['skill_name']); ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $skill['max_score']; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $skill['score'] ?? '—'; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($skill['score'] === null): ?>
                                        <span style="color: #ffc107;"><i class="fas fa-clock"></i> Pending</span>
                                    <?php elseif ($passed): ?>
                                        <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Pass</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Fail</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: #e9ecef; font-weight: bold;">
                                <td style="padding: 10px; border: 1px solid #dee2e6;" colspan="2">Total</td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $practical_details_total; ?>/<?php echo $practical_details_max; ?></td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($practical_has_all_scores): ?>
                                        <?php if ($practical_details_total >= ($practical_details_max * 0.75)): ?>
                                            <span style="color: #28a745;">PASSED</span>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">FAILED</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #ffc107;">INCOMPLETE</span>
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
                
                <!-- Oral Assessment Breakdown with Answers -->
                <?php if (!empty($trainee_questions)): ?>
                <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h4 style="color: #8b5cf6; margin-bottom: 15px;">Oral Assessment (Trainee-Selective)</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #e9ecef;">
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">#</th>
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">Question</th>
                                <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">Answer</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Max Score</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Score</th>
                                <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $oral_details_total = 0;
                            $oral_details_max = 0;
                            foreach ($trainee_questions as $index => $q):
                                $oral_details_max += $q['max_score'];
                                $oral_details_total += $q['score'] ?? 0;
                                $passed = ($q['score'] ?? 0) >= ($q['max_score'] * 0.75);
                                $answer_text = isset($oral_answers_array[$index]) ? $oral_answers_array[$index] : '';
                            ?>
                            <tr>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo $index + 1; ?></td>
                                <td style="padding: 8px 10px; border: 1px solid #dee2e6;"><?php echo htmlspecialchars($q['question']); ?></td>
                               <td style="padding: 8px 10px; border: 1px solid #dee2e6; max-width: 300px;">
                                        <?php if (!empty($answer_text)): ?>
                                            <div style="background: #f3e8ff; padding: 5px; border-radius: 4px;">
                                                <?php echo nl2br(htmlspecialchars(substr($answer_text, 0, 100))); ?>
                                                <?php if (strlen($answer_text) > 100): ?>...<?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #ffc107;"><i class="fas fa-clock"></i> No answer</span>
                                        <?php endif; ?>
                                    </td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $q['max_score']; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $q['score'] ?? '—'; ?></td>
                                <td style="padding: 8px 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($q['score'] === null): ?>
                                        <span style="color: #ffc107;"><i class="fas fa-clock"></i> Pending</span>
                                    <?php elseif ($passed): ?>
                                        <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Pass</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Fail</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: #e9ecef; font-weight: bold;">
                                <td style="padding: 10px; border: 1px solid #dee2e6;" colspan="4">Total</td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;"><?php echo $oral_details_total; ?>/<?php echo $oral_details_max; ?></td>
                                <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($oral_has_all_scores): ?>
                                        <?php if ($oral_details_total >= ($oral_details_max * 0.75)): ?>
                                            <span style="color: #28a745;">PASSED</span>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">FAILED</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #ffc107;">INCOMPLETE</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
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
        let traineeSkillCount = <?php echo count($trainee_skills); ?>;
        let traineeQuestionCount = <?php echo count($trainee_questions); ?>;
        
        // Tab switching
        function switchTab(tabName) {
            document.getElementById('tabInput').value = tabName;
            document.getElementById('switchTabForm').submit();
        }
        
        // Toggle visibility using AJAX
        function toggleVisibility(type, enrollmentId, checkbox) {
            const newValue = checkbox.checked ? 1 : 0;
            
            // Show loading state
            Swal.fire({
                title: 'Updating...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Create form data
            const formData = new FormData();
            formData.append('ajax_toggle', '1');
            formData.append('type', type);
            formData.append('enrollment_id', enrollmentId);
            formData.append('set', newValue);
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: 'Visibility setting has been updated.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    // Update the status label
                    const container = checkbox.closest('.toggle-container');
                    const statusSpan = container.querySelector('.visibility-status');
                    if (statusSpan) {
                        if (checkbox.checked) {
                            statusSpan.className = 'visibility-status status-visible';
                            statusSpan.innerHTML = '<i class="fas fa-eye"></i> Visible to trainee';
                        } else {
                            statusSpan.className = 'visibility-status status-hidden';
                            statusSpan.innerHTML = '<i class="fas fa-eye-slash"></i> Hidden from trainee';
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to update visibility'
                    });
                    // Revert checkbox
                    checkbox.checked = !checkbox.checked;
                }
            })
            .catch(error => {
                console.error('AJAX Error:', error);
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred. Please try again.'
                });
                // Revert checkbox
                checkbox.checked = !checkbox.checked;
            });
        }
        
        // ========== DELETE MODAL FUNCTIONS ==========
        function openDeleteModal(type) {
            document.getElementById('deleteType').value = type;
            document.getElementById('deleteModalMessage').innerHTML = 
                type === 'practical' ? 
                'Are you sure you want to permanently delete ALL practical skills for this trainee? This action CANNOT be undone.' :
                'Are you sure you want to permanently delete ALL oral questions for this trainee? This action CANNOT be undone.';
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function confirmDelete() {
            const type = document.getElementById('deleteType').value;
            const enrollmentId = document.getElementById('deleteEnrollmentId').value;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'comprehensive_assessment.php';
            
            if (type === 'practical') {
                addField(form, 'remove_trainee_skills', '1');
            } else if (type === 'oral') {
                addField(form, 'remove_trainee_questions', '1');
            }
            
            addField(form, 'enrollment_id', enrollmentId);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // ========== SINGLE ITEM DELETE ==========
        function removeSingleSkill(skillId) {
            Swal.fire({
                title: 'Delete Skill?',
                text: 'Are you sure you want to permanently delete this skill?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'comprehensive_assessment.php';
                    
                    addField(form, 'remove_single_skill', '1');
                    addField(form, 'skill_id', skillId);
                    addField(form, 'enrollment_id', enrollmentId);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function removeSingleQuestion(questionId) {
            Swal.fire({
                title: 'Delete Question?',
                text: 'Are you sure you want to permanently delete this question?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'comprehensive_assessment.php';
                    
                    addField(form, 'remove_single_question', '1');
                    addField(form, 'question_id', questionId);
                    addField(form, 'enrollment_id', enrollmentId);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // ========== TRAINEE SKILLS FUNCTIONS (WITHOUT SCORES) ==========
        function addTraineeSkill() {
            traineeSkillCount++;
            const container = document.getElementById('trainee-skills-container');
            
            // Remove waiting message if present
            const waitingMsg = container.querySelector('.waiting-message');
            if (waitingMsg) {
                waitingMsg.remove();
            }
            
            const newRow = document.createElement('div');
            newRow.className = 'custom-skill-row';
            newRow.setAttribute('data-temp', 'true');
            newRow.innerHTML = `
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div style="flex: 2;">
                        <input type="text" class="form-control trainee-skill-name" value="New Skill ${traineeSkillCount}" placeholder="Enter skill name">
                        <span class="input-label">Skill name</span>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" class="form-control trainee-skill-max" value="20" min="1" max="100">
                        <span class="input-label">Max score</span>
                    </div>
                    <div style="flex: 0.3;">
                        <button type="button" class="remove-btn" onclick="this.closest('.custom-skill-row').remove(); updatePracticalMaxTotal();">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            updatePracticalMaxTotal();
        }
        
        function saveTraineeSkills() {
            const skills = [];
            let hasValidSkills = false;
            
            document.querySelectorAll('#trainee-skills-container .custom-skill-row').forEach(row => {
                const nameInput = row.querySelector('.trainee-skill-name');
                const maxInput = row.querySelector('.trainee-skill-max');
                
                if (nameInput && maxInput && nameInput.value.trim() !== '') {
                    hasValidSkills = true;
                    
                    skills.push({
                        name: nameInput.value.trim(),
                        max_score: parseInt(maxInput.value) || 20
                    });
                }
            });
            
            if (!hasValidSkills) {
                Swal.fire('No Skills', 'Please add at least one skill with a name for this trainee.', 'warning');
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
                    
                    // Create form data
                    const formData = new FormData();
                    formData.append('save_trainee_practical_skills', '1');
                    formData.append('enrollment_id', enrollmentId);
                    formData.append('trainee_skills', JSON.stringify(skills));
                    formData.append('ajax', '1');
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Practical skills saved successfully',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload to show updated data
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to save skills'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred. Please try again.'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        // ========== TRAINEE QUESTIONS FUNCTIONS (WITHOUT SCORES) ==========
        function addTraineeQuestion() {
            traineeQuestionCount++;
            const container = document.getElementById('trainee-questions-container');
            
            // Remove waiting message if present
            const waitingMsg = container.querySelector('.waiting-message');
            if (waitingMsg) {
                waitingMsg.remove();
            }
            
            const newRow = document.createElement('div');
            newRow.className = 'custom-skill-row';
            newRow.setAttribute('data-temp', 'true');
            newRow.innerHTML = `
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div style="flex: 2;">
                        <input type="text" class="form-control trainee-question" value="New Question ${traineeQuestionCount}" placeholder="Enter question">
                        <span class="input-label">Question</span>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" class="form-control trainee-question-max" value="25" min="1" max="100">
                        <span class="input-label">Max score</span>
                    </div>
                    <div style="flex: 0.3;">
                        <button type="button" class="remove-btn" onclick="this.closest('.custom-skill-row').remove(); updateOralMaxTotal();">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            updateOralMaxTotal();
        }
        
        function saveTraineeQuestions() {
            const questions = [];
            let hasValidQuestions = false;
            
            document.querySelectorAll('#trainee-questions-container .custom-skill-row').forEach(row => {
                const questionInput = row.querySelector('.trainee-question');
                const maxInput = row.querySelector('.trainee-question-max');
                
                if (questionInput && maxInput && questionInput.value.trim() !== '') {
                    hasValidQuestions = true;
                    
                    questions.push({
                        question: questionInput.value.trim(),
                        max_score: parseInt(maxInput.value) || 25
                    });
                }
            });
            
            if (!hasValidQuestions) {
                Swal.fire('No Questions', 'Please add at least one question with text for this trainee.', 'warning');
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
                    
                    // Create form data
                    const formData = new FormData();
                    formData.append('save_trainee_oral_questions', '1');
                    formData.append('enrollment_id', enrollmentId);
                    formData.append('trainee_questions', JSON.stringify(questions));
                    formData.append('ajax', '1');
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Oral questions saved successfully',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload to show updated data
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to save questions'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred. Please try again.'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        // ========== UPDATE MAX TOTALS ==========
        function updatePracticalMaxTotal() {
            let maxTotal = 0;
            
            document.querySelectorAll('#trainee-skills-container .custom-skill-row').forEach(row => {
                const maxInput = row.querySelector('.trainee-skill-max');
                
                if (maxInput) {
                    maxTotal += parseFloat(maxInput.value) || 0;
                }
            });
            
            if (maxTotal === 0) maxTotal = 100;
            
            document.getElementById('practicalMaxTotal').textContent = maxTotal;
            document.getElementById('practicalMaxTotalValue').textContent = maxTotal;
        }
        
        function updateOralMaxTotal() {
            let maxTotal = 0;
            
            document.querySelectorAll('#trainee-questions-container .custom-skill-row').forEach(row => {
                const maxInput = row.querySelector('.trainee-question-max');
                
                if (maxInput) {
                    maxTotal += parseFloat(maxInput.value) || 0;
                }
            });
            
            document.getElementById('oralMaxTotal').textContent = maxTotal;
            document.getElementById('oral_max_score').value = maxTotal;
        }
        
        // ========== SCORING FUNCTIONS ==========
        function updatePracticalScoringTotal() {
            let total = 0;
            let maxTotal = 0;
            
            document.querySelectorAll('#practical-scoring-container .custom-skill-row').forEach(row => {
                const scoreInput = row.querySelector('.practical-score');
                const maxScore = parseInt(scoreInput.max) || 0;
                
                maxTotal += maxScore;
                
                const scoreVal = scoreInput.value;
                if (scoreVal !== null && scoreVal !== '') {
                    total += parseFloat(scoreVal) || 0;
                }
            });
            
            document.getElementById('practicalScoringTotal').textContent = total;
            document.getElementById('practicalScoringMax').textContent = maxTotal;
            document.getElementById('practicalTotal').textContent = total;
        }
        
        function updateOralScoringTotal() {
            let total = 0;
            let maxTotal = 0;
            
            document.querySelectorAll('#oral-scoring-container .custom-skill-row').forEach(row => {
                const scoreInput = row.querySelector('.oral-score');
                const maxScore = parseInt(scoreInput.max) || 0;
                
                maxTotal += maxScore;
                
                const scoreVal = scoreInput.value;
                if (scoreVal !== null && scoreVal !== '') {
                    total += parseFloat(scoreVal) || 0;
                }
            });
            
            document.getElementById('oralScoringTotal').textContent = total;
            document.getElementById('oralScoringMax').textContent = maxTotal;
            document.getElementById('oralTotal').textContent = total;
        }
        
        function savePracticalScores() {
            const scores = {};
            let hasScores = false;
            
            document.querySelectorAll('#practical-scoring-container .custom-skill-row').forEach(row => {
                const scoreInput = row.querySelector('.practical-score');
                if (scoreInput) {
                    const skillId = scoreInput.dataset.skillId;
                    const score = scoreInput.value;
                    
                    if (score !== null && score !== '') {
                        scores[skillId] = parseFloat(score) || 0;
                        hasScores = true;
                    }
                }
            });
            
            if (!hasScores) {
                Swal.fire('No Scores', 'Please enter at least one score to save.', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Saving...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    const formData = new FormData();
                    formData.append('save_practical_scores', '1');
                    formData.append('enrollment_id', enrollmentId);
                    formData.append('skill_scores', JSON.stringify(scores));
                    formData.append('ajax', '1');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Practical scores saved successfully',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to save scores'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred. Please try again.'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        function saveOralScores() {
            const scores = {};
            let hasScores = false;
            
            document.querySelectorAll('#oral-scoring-container .custom-skill-row').forEach(row => {
                const scoreInput = row.querySelector('.oral-score');
                if (scoreInput) {
                    const questionId = scoreInput.dataset.questionId;
                    const score = scoreInput.value;
                    
                    if (score !== null && score !== '') {
                        scores[questionId] = parseFloat(score) || 0;
                        hasScores = true;
                    }
                }
            });
            
            if (!hasScores) {
                Swal.fire('No Scores', 'Please enter at least one score to save.', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Saving...',
                text: 'Please wait',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    const formData = new FormData();
                    formData.append('save_oral_scores', '1');
                    formData.append('enrollment_id', enrollmentId);
                    formData.append('question_scores', JSON.stringify(scores));
                    formData.append('ajax', '1');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Oral scores saved successfully',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to save scores'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred. Please try again.'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        // ========== SAVE FUNCTIONS ==========
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
        
        function confirmClearAnswers() {
            return Swal.fire({
                title: 'Clear All Answers?',
                text: 'Are you sure you want to clear all trainee answers? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#6c757d',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, clear them!'
            }).then((result) => {
                return result.isConfirmed;
            });
        }
        
        function confirmFinalize() {
            // Check if practical skills exist and are complete
            let practicalSkillsExist = document.querySelectorAll('#trainee-skills-container .custom-skill-row').length > 0;
            let practicalComplete = true;
            
            if (practicalSkillsExist) {
                document.querySelectorAll('.practical-score').forEach(input => {
                    if (input.value === null || input.value === '') {
                        practicalComplete = false;
                    }
                });
            }
            
            // Check if oral questions exist and are complete
            let oralQuestionsExist = document.querySelectorAll('#trainee-questions-container .custom-skill-row').length > 0;
            let oralComplete = true;
            
            if (oralQuestionsExist) {
                document.querySelectorAll('.oral-score').forEach(input => {
                    if (input.value === null || input.value === '') {
                        oralComplete = false;
                    }
                });
            }
            
            // Check if project has score
            let projectScore = document.getElementById('project_score')?.value;
            let projectComplete = projectScore && projectScore !== '';
            
            if (practicalSkillsExist && !practicalComplete) {
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
            
            if (oralQuestionsExist && !oralComplete) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Assessment',
                    text: 'Please complete all oral assessment scores before finalizing.',
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const deleteModal = document.getElementById('deleteModal');
            if (event.target == deleteModal) {
                deleteModal.style.display = 'none';
            }
        }
        
        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            updatePracticalMaxTotal();
            updateOralMaxTotal();
            updatePracticalScoringTotal();
            updateOralScoringTotal();
        });
    </script>
</body>
</html>