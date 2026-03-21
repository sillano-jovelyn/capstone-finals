<?php
// BULK COMPREHENSIVE ASSESSMENT
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// AUTHENTICATION CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'Trainer';


// DATABASE CONNECTION

require_once __DIR__ . '/../db.php';

if (!$conn) {
    die("Database connection failed");
}


// GET PROGRAM ID
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;

if (!$program_id) {
    header('Location: trainer_programs.php');
    exit;
}


// GET PROGRAM INFO
$program = $conn->query("SELECT * FROM programs WHERE id = $program_id")->fetch_assoc();

if (!$program) {
    header('Location: trainer_programs.php?error=program_not_found');
    exit;
}

// GET ALL ENROLLMENTS FOR THIS PROGRAM
$enrollments = $conn->query("
    SELECT e.*, t.fullname, t.firstname, t.lastname, t.email, t.contact_number,
           ac.id as assessment_id, ac.practical_score, ac.project_score, ac.oral_score,
           ac.oral_max_score, ac.practical_skills_grading, ac.oral_questions,
           ac.project_visible_to_trainee, ac.oral_questions_visible_to_trainee,
           ac.project_submitted_by_trainee, ac.oral_submitted_by_trainee,
           ac.project_title, ac.project_description, ac.project_photo_path,
           ac.oral_answers, ac.practical_notes, ac.project_notes, ac.oral_notes,
           ac.practical_passed, ac.project_passed, ac.oral_passed,
           ac.overall_result as assessment_result, ac.overall_total_score,
           ac.oral_questions_set, ac.practical_date,
           ac.project_submitted_at, ac.oral_submitted_at,
           ac.is_finalized, ac.assessed_by, ac.assessed_at,
           ac.practical_skills_saved, ac.oral_questions_saved,
           ac.practical_passing_percentage, ac.project_passing_percentage, ac.oral_passing_percentage,
           ac.project_title_override, ac.project_instruction, ac.project_rubrics, ac.project_total_max
    FROM enrollments e
    JOIN trainees t ON e.user_id = t.user_id
    LEFT JOIN assessment_components ac ON e.id = ac.enrollment_id
    WHERE e.program_id = $program_id AND e.enrollment_status IN ('approved', 'completed')
    ORDER BY t.fullname ASC
")->fetch_all(MYSQLI_ASSOC);

// GET PROGRAM SKILLS TEMPLATE
$program_skills = $conn->query("SELECT * FROM program_practical_skills WHERE program_id = $program_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);
$program_questions = $conn->query("SELECT * FROM program_oral_questions WHERE program_id = $program_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);

$program_skills_exist = count($program_skills) > 0;
$program_questions_exist = count($program_questions) > 0;

// PROCESS BULK ACTIONS 
// Load skills to all trainees (creates trainee-specific records)
if (isset($_POST['load_skills_to_all'])) {
    if ($program_skills_exist) {
        $success_count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment_id = $enrollment['id'];
            
            $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            
            $stmt = $conn->prepare("INSERT INTO trainee_practical_skills (enrollment_id, skill_name, max_score, order_index, score) VALUES (?, ?, ?, ?, NULL)");
            foreach ($program_skills as $index => $skill) {
                $skill_name = $skill['skill_name'];
                $max_score = $skill['max_score'];
                $stmt->bind_param("isii", $enrollment_id, $skill_name, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
            
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET 
                    practical_skills_saved = 1,
                    practical_score = 0,
                    practical_passed = 0
                    WHERE enrollment_id = $enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components 
                    (enrollment_id, practical_skills_saved, practical_score, practical_passed, oral_max_score, 
                     practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                    VALUES ($enrollment_id, 1, 0, 0, 100, 75, 75, 75)");
            }
            $success_count++;
        }
        $_SESSION['message'] = "$success_count trainee(s) - Skills loaded successfully to individual trainee records!";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'No program skills found. Please add skills first.';
        $_SESSION['message_type'] = 'warning';
    }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical");
    exit;
}

// Load questions to all trainees (creates trainee-specific records)
if (isset($_POST['load_questions_to_all'])) {
    if ($program_questions_exist) {
        $success_count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment_id = $enrollment['id'];
            
            $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
            
            $stmt = $conn->prepare("INSERT INTO trainee_oral_questions (enrollment_id, question, max_score, order_index, score) VALUES (?, ?, ?, ?, NULL)");
            foreach ($program_questions as $index => $q) {
                $question = $q['question'];
                $max_score = $q['max_score'];
                $stmt->bind_param("isii", $enrollment_id, $question, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
            
            $total_max = 0;
            foreach ($program_questions as $q) {
                $total_max += $q['max_score'];
            }
            
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET 
                    oral_questions_saved = 1,
                    oral_max_score = $total_max,
                    oral_questions_set = 1,
                    oral_score = 0
                    WHERE enrollment_id = $enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components 
                    (enrollment_id, oral_questions_saved, oral_max_score, oral_questions_set, oral_questions_visible_to_trainee, 
                     project_visible_to_trainee, oral_score, practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                    VALUES ($enrollment_id, 1, $total_max, 1, 0, 0, 0, 75, 75, 75)");
            }
            $success_count++;
        }
        $_SESSION['message'] = "$success_count trainee(s) - Questions loaded successfully to individual trainee records!";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'No program questions found. Please add questions first.';
        $_SESSION['message_type'] = 'warning';
    }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral");
    exit;
}


// SAVE BULK PASSING PERCENTAGES
if (isset($_POST['save_bulk_passing_percentages'])) {
    $practical_passing = floatval($_POST['practical_passing_percentage'] ?? 75);
    $project_passing = floatval($_POST['project_passing_percentage'] ?? 75);
    $oral_passing = floatval($_POST['oral_passing_percentage'] ?? 75);
    
    if ($practical_passing < 65) $practical_passing = 65;
    if ($practical_passing > 100) $practical_passing = 100;
    if ($project_passing < 65) $project_passing = 65;
    if ($project_passing > 100) $project_passing = 100;
    if ($oral_passing < 65) $oral_passing = 65;
    if ($oral_passing > 100) $oral_passing = 100;
    
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET 
                practical_passing_percentage = $practical_passing,
                project_passing_percentage = $project_passing,
                oral_passing_percentage = $oral_passing
                WHERE enrollment_id = $enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components 
                (enrollment_id, practical_passing_percentage, project_passing_percentage, oral_passing_percentage, oral_max_score) 
                VALUES ($enrollment_id, $practical_passing, $project_passing, $oral_passing, 100)");
        }
        $updated++;
    }
    
    $_SESSION['message'] = "$updated trainee(s) - Passing percentages updated! (Practical: $practical_passing%, Project: $project_passing%, Oral: $oral_passing%)";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=" . ($_POST['current_tab'] ?? 'practical'));
    exit;
}

// SAVE BULK PROJECT SETUP (Title, Instructions, Rubrics)
if (isset($_POST['save_bulk_project_setup'])) {
    $project_title_override = $conn->real_escape_string($_POST['project_title_override'] ?? '');
    $project_instruction = $conn->real_escape_string($_POST['project_instruction'] ?? '');
    $rubrics_data = json_decode($_POST['rubrics_data'] ?? '[]', true);
    
    $project_total_max = 0;
    foreach ($rubrics_data as $criterion) {
        $project_total_max += floatval($criterion['max_score'] ?? 0);
    }
    if ($project_total_max == 0) $project_total_max = 100;
    
    $rubrics_json = json_encode($rubrics_data);
    $safe_rubrics = $conn->real_escape_string($rubrics_json);
    
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET 
                project_title_override = '$project_title_override',
                project_instruction = '$project_instruction',
                project_rubrics = '$safe_rubrics',
                project_total_max = $project_total_max
                WHERE enrollment_id = $enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components 
                (enrollment_id, project_title_override, project_instruction, project_rubrics, project_total_max, oral_max_score,
                 practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                VALUES ($enrollment_id, '$project_title_override', '$project_instruction', '$safe_rubrics', $project_total_max, 100, 75, 75, 75)");
        }
        $updated++;
    }
    
    $_SESSION['message'] = "$updated trainee(s) - Project setup updated! (Title, Instructions, and Rubrics applied to all)";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=project");
    exit;
}

// Reset skills for all trainees
if (isset($_POST['reset_all_skills'])) {
    $success_count = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET 
            practical_skills_saved = 0,
            practical_score = 0,
            practical_passed = 0,
            practical_notes = NULL
            WHERE enrollment_id = $enrollment_id");
        $success_count++;
    }
    $_SESSION['message'] = "$success_count trainee(s) - Skills reset successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical");
    exit;
}

// Reset questions for all trainees
if (isset($_POST['reset_all_questions'])) {
    $success_count = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET 
            oral_questions_saved = 0,
            oral_questions_set = 0,
            oral_questions_finalized = 0,
            oral_score = NULL,
            oral_passed = NULL,
            oral_notes = NULL,
            oral_answers = NULL,
            oral_submitted_by_trainee = 0,
            oral_max_score = 100
            WHERE enrollment_id = $enrollment_id");
        $success_count++;
    }
    $_SESSION['message'] = "$success_count trainee(s) - Questions reset successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral");
    exit;
}

// SAVE PROGRAM SKILLS 
if (isset($_POST['save_program_skills'])) {
    $skills_json = $_POST['program_skills'];
    
    $conn->query("DELETE FROM program_practical_skills WHERE program_id = $program_id");
    
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
    
    $_SESSION['message'] = 'Program skills template saved successfully! Use "Load Skills to All" to apply to trainees.';
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical");
    exit;
}

// SAVE PROGRAM QUESTIONS 
if (isset($_POST['save_program_questions'])) {
    $questions_json = $_POST['program_questions'];
    
    $conn->query("DELETE FROM program_oral_questions WHERE program_id = $program_id");
    
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
    
    $_SESSION['message'] = 'Program questions template saved successfully! Use "Load Questions to All" to apply to trainees.';
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral");
    exit;
}

// SAVE BULK PRACTICAL SCORES
if (isset($_POST['save_bulk_practical_detailed'])) {
    $enrollment_ids = $_POST['enrollment_id'] ?? [];
    $skill_scores_data = $_POST['skill_scores'] ?? [];
    $practical_notes = $_POST['practical_notes'] ?? [];
    $practical_date = $_POST['practical_date'] ?? date('Y-m-d');
    
    $updated = 0;
    
    foreach ($enrollment_ids as $index => $enrollment_id) {
        if (isset($skill_scores_data[$index])) {
            $skill_scores = json_decode($skill_scores_data[$index], true);
            $notes = $conn->real_escape_string($practical_notes[$index] ?? '');
            
            if (is_array($skill_scores)) {
                foreach ($skill_scores as $skill_id => $grade_data) {
                    if (strpos($skill_id, 'skill_') === 0) {
                        $skill_db_id = str_replace('skill_', '', $skill_id);
                        $score = is_array($grade_data) ? ($grade_data['score'] ?? 0) : $grade_data;
                        $conn->query("UPDATE trainee_practical_skills SET score = $score WHERE id = $skill_db_id AND enrollment_id = $enrollment_id");
                    }
                }
            }
            
            $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            $practical_total = 0;
            if ($total_query && $row = $total_query->fetch_assoc()) {
                $practical_total = $row['total'] ?? 0;
            }
            
            $passing_query = $conn->query("SELECT practical_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
            $passing_percentage = 75;
            if ($passing_query && $row = $passing_query->fetch_assoc()) {
                $passing_percentage = $row['practical_passing_percentage'] ?? 75;
            }
            
            $max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            $max_total = 0;
            if ($max_query && $row = $max_query->fetch_assoc()) {
                $max_total = $row['total'] ?? 0;
            }
            
            $percentage = $max_total > 0 ? ($practical_total / $max_total) * 100 : 0;
            $practical_passed = ($percentage >= $passing_percentage) ? 1 : 0;
            
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET 
                    practical_score = $practical_total,
                    practical_passed = $practical_passed,
                    practical_notes = '$notes',
                    practical_date = '$practical_date'
                    WHERE enrollment_id = $enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components 
                    (enrollment_id, practical_score, practical_passed, practical_notes, practical_date, oral_max_score,
                     practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                    VALUES ($enrollment_id, $practical_total, $practical_passed, '$notes', '$practical_date', 100, 75, 75, 75)");
            }
            $updated++;
        }
    }
    
    $_SESSION['message'] = "$updated trainee(s) - Practical scores updated successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical");
    exit;
}

// SAVE BULK PROJECT SCORES
if (isset($_POST['save_bulk_project'])) {
    $enrollment_ids = $_POST['enrollment_id'] ?? [];
    $project_scores = $_POST['project_score'] ?? [];
    $project_notes = $_POST['project_notes'] ?? [];
    
    $updated = 0;
    
    foreach ($enrollment_ids as $index => $enrollment_id) {
        if (isset($project_scores[$index]) && $project_scores[$index] !== '') {
            $score = floatval($project_scores[$index]);
            $notes = $conn->real_escape_string($project_notes[$index] ?? '');
            
            $passing_query = $conn->query("SELECT project_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
            $passing_percentage = 75;
            if ($passing_query && $row = $passing_query->fetch_assoc()) {
                $passing_percentage = $row['project_passing_percentage'] ?? 75;
            }
            
            $passed = ($score >= $passing_percentage) ? 1 : 0;
            
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET 
                    project_score = $score,
                    project_passed = $passed,
                    project_notes = '$notes'
                    WHERE enrollment_id = $enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components 
                    (enrollment_id, project_score, project_passed, project_notes, oral_max_score,
                     practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                    VALUES ($enrollment_id, $score, $passed, '$notes', 100, 75, 75, 75)");
            }
            $updated++;
        }
    }
    
    $_SESSION['message'] = "$updated trainee(s) - Project scores updated successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=project");
    exit;
}


// SAVE BULK ORAL SCORES
if (isset($_POST['save_bulk_oral'])) {
    $enrollment_ids = $_POST['enrollment_id'] ?? [];
    $oral_scores = $_POST['oral_score'] ?? [];
    $oral_notes = $_POST['oral_notes'] ?? [];
    
    $updated = 0;
    
    foreach ($enrollment_ids as $index => $enrollment_id) {
        if (isset($oral_scores[$index]) && $oral_scores[$index] !== '') {
            $score = floatval($oral_scores[$index]);
            $notes = $conn->real_escape_string($oral_notes[$index] ?? '');
            
            $max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
            $oral_max = 100;
            if ($max_query && $row = $max_query->fetch_assoc()) {
                $oral_max = $row['total'] ?? 100;
            }
            
            $passing_query = $conn->query("SELECT oral_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
            $passing_percentage = 75;
            if ($passing_query && $row = $passing_query->fetch_assoc()) {
                $passing_percentage = $row['oral_passing_percentage'] ?? 75;
            }
            
            $passed = ($score >= ($oral_max * $passing_percentage / 100)) ? 1 : 0;
            
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET 
                    oral_score = $score,
                    oral_passed = $passed,
                    oral_notes = '$notes',
                    oral_max_score = $oral_max
                    WHERE enrollment_id = $enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components 
                    (enrollment_id, oral_score, oral_passed, oral_notes, oral_max_score,
                     practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                    VALUES ($enrollment_id, $score, $passed, '$notes', $oral_max, 75, 75, 75)");
            }
            $updated++;
        }
    }
    
    $_SESSION['message'] = "$updated trainee(s) - Oral scores updated successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral");
    exit;
}


// CALCULATE ALL OVERALL RESULTS
if (isset($_POST['calculate_all_results'])) {
    $updated = 0;
    $passed_count = 0;
    $failed_count = 0;
    
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        
        $practical_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical = 0;
        if ($practical_query && $row = $practical_query->fetch_assoc()) {
            $practical = $row['total'] ?? 0;
        }
        
        $project_query = $conn->query("SELECT project_score FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project = 0;
        if ($project_query && $row = $project_query->fetch_assoc()) {
            $project = $row['project_score'] ?? 0;
        }
        
        $oral_query = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $oral = 0;
        if ($oral_query && $row = $oral_query->fetch_assoc()) {
            $oral = $row['total'] ?? 0;
        }
        
        $practical_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical_max = 100;
        if ($practical_max_query && $row = $practical_max_query->fetch_assoc()) {
            $practical_max = $row['total'] ?? 100;
        }
        
        $project_max_query = $conn->query("SELECT project_total_max FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project_max = 100;
        if ($project_max_query && $row = $project_max_query->fetch_assoc()) {
            $project_max = $row['project_total_max'] ?? 100;
        }
        
        $oral_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $oral_max = 100;
        if ($oral_max_query && $row = $oral_max_query->fetch_assoc()) {
            $oral_max = $row['total'] ?? 100;
        }
        
        $passing_query = $conn->query("SELECT practical_passing_percentage, project_passing_percentage, oral_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $practical_passing = 75;
        $project_passing = 75;
        $oral_passing = 75;
        if ($passing_query && $row = $passing_query->fetch_assoc()) {
            $practical_passing = $row['practical_passing_percentage'] ?? 75;
            $project_passing = $row['project_passing_percentage'] ?? 75;
            $oral_passing = $row['oral_passing_percentage'] ?? 75;
        }
        
        $total = $practical + $project + $oral;
        $max_total = $practical_max + $project_max + $oral_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
        
        $total_weight = $practical_max + $project_max + $oral_max;
        $overall_passing_percentage = $total_weight > 0
            ? ($practical_max * $practical_passing + $project_max * $project_passing + $oral_max * $oral_passing) / $total_weight
            : 75;
        
        $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';
        
        if ($overall_result == 'Passed') $passed_count++;
        else $failed_count++;
        
        $conn->query("UPDATE assessment_components SET 
            overall_total_score = $total,
            overall_result = '$overall_result',
            assessed_by = '$fullname',
            assessed_at = NOW(),
            is_finalized = 1
            WHERE enrollment_id = $enrollment_id");
        
        $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
        $conn->query("UPDATE enrollments SET 
            enrollment_status = '$enrollment_status',
            overall_result = '$overall_result',
            completed_at = NOW(),
            assessment = '$overall_result', 
            assessed_by = '$fullname',
            assessed_at = NOW()
            WHERE id = $enrollment_id");
        
        $updated++;
    }
    
    $_SESSION['message'] = "$updated trainee(s) processed - $passed_count Passed, $failed_count Failed. Results saved to both tables!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=summary");
    exit;
}

// FINALIZE ALL COMPLETED ASSESSMENTS
if (isset($_POST['finalize_all_completed'])) {
    $updated = 0;
    $passed_count = 0;
    $failed_count = 0;
    
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        
        $practical_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical = 0;
        if ($practical_query && $row = $practical_query->fetch_assoc()) {
            $practical = $row['total'] ?? 0;
        }
        
        $project_query = $conn->query("SELECT project_score FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project = 0;
        if ($project_query && $row = $project_query->fetch_assoc()) {
            $project = $row['project_score'] ?? 0;
        }
        
        $oral_query = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $oral = 0;
        if ($oral_query && $row = $oral_query->fetch_assoc()) {
            $oral = $row['total'] ?? 0;
        }
        
        $practical_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical_max = 100;
        if ($practical_max_query && $row = $practical_max_query->fetch_assoc()) {
            $practical_max = $row['total'] ?? 100;
        }
        
        $project_max_query = $conn->query("SELECT project_total_max FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project_max = 100;
        if ($project_max_query && $row = $project_max_query->fetch_assoc()) {
            $project_max = $row['project_total_max'] ?? 100;
        }
        
        $oral_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $oral_max = 100;
        if ($oral_max_query && $row = $oral_max_query->fetch_assoc()) {
            $oral_max = $row['total'] ?? 100;
        }
        
        $passing_query = $conn->query("SELECT practical_passing_percentage, project_passing_percentage, oral_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $practical_passing = 75;
        $project_passing = 75;
        $oral_passing = 75;
        if ($passing_query && $row = $passing_query->fetch_assoc()) {
            $practical_passing = $row['practical_passing_percentage'] ?? 75;
            $project_passing = $row['project_passing_percentage'] ?? 75;
            $oral_passing = $row['oral_passing_percentage'] ?? 75;
        }
        
        $has_practical_scores = $practical > 0;
        $has_project_scores = $project > 0;
        $has_oral_scores = $oral > 0;
        
        if ($has_practical_scores && $has_project_scores && $has_oral_scores) {
            $total = $practical + $project + $oral;
            $max_total = $practical_max + $project_max + $oral_max;
            $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
            
            $total_weight = $practical_max + $project_max + $oral_max;
            $overall_passing_percentage = $total_weight > 0
                ? ($practical_max * $practical_passing + $project_max * $project_passing + $oral_max * $oral_passing) / $total_weight
                : 75;
            
            $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';
            
            if ($overall_result == 'Passed') $passed_count++;
            else $failed_count++;
            
            $update_assessment = $conn->prepare("UPDATE assessment_components SET 
                overall_total_score = ?,
                overall_result = ?,
                assessed_by = ?,
                assessed_at = NOW(),
                is_finalized = 1
                WHERE enrollment_id = ?");
            $update_assessment->bind_param("dssi", $total, $overall_result, $fullname, $enrollment_id);
            $update_assessment->execute();
            $update_assessment->close();
            
            $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
            $update_enrollment = $conn->prepare("UPDATE enrollments SET 
                enrollment_status = ?,
                overall_result = ?,
                completion_date = NOW(),
                assessed_by = ?,
                assessed_at = NOW()
                WHERE id = ?");
            $update_enrollment->bind_param("sssi", $enrollment_status, $overall_result, $fullname, $enrollment_id);
            $update_enrollment->execute();
            $update_enrollment->close();
            
            $updated++;
        }
    }
    
    $_SESSION['message'] = "$updated trainee(s) finalized - $passed_count Passed, $failed_count Failed!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=summary");
    exit;
}

// TOGGLE VISIBILITY FOR ALL
if (isset($_POST['toggle_all_visibility'])) {
    $type = $_POST['visibility_type'];
    $value = intval($_POST['visibility_value']);
    $field = ($type === 'project') ? 'project_visible_to_trainee' : 'oral_questions_visible_to_trainee';
    
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET $field = $value WHERE enrollment_id = $enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components (enrollment_id, $field, oral_max_score, 
                           practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                           VALUES ($enrollment_id, $value, 100, 75, 75, 75)");
        }
        $updated++;
    }
    
    $status = $value ? 'visible' : 'hidden';
    $_SESSION['message'] = "$updated trainee(s) - All $type components are now $status to trainees!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=" . ($type === 'project' ? 'project' : 'oral'));
    exit;
}


// TOGGLE INDIVIDUAL VISIBILITY 
if (isset($_GET['toggle_project'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value = isset($_GET['set']) ? intval($_GET['set']) : 1;
    
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE assessment_components SET project_visible_to_trainee = $new_value WHERE enrollment_id = $enrollment_id");
    } else {
        $conn->query("INSERT INTO assessment_components (enrollment_id, project_visible_to_trainee, oral_max_score,
                       practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                       VALUES ($enrollment_id, $new_value, 100, 75, 75, 75)");
    }
    
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['toggle_oral'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value = isset($_GET['set']) ? intval($_GET['set']) : 1;
    
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE assessment_components SET oral_questions_visible_to_trainee = $new_value WHERE enrollment_id = $enrollment_id");
    } else {
        $conn->query("INSERT INTO assessment_components (enrollment_id, oral_questions_visible_to_trainee, oral_max_score,
                       practical_passing_percentage, project_passing_percentage, oral_passing_percentage) 
                       VALUES ($enrollment_id, $new_value, 100, 75, 75, 75)");
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// GET CURRENT TAB
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'practical';
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

// GET TRAINEE-SPECIFIC SKILLS AND QUESTIONS FOR EACH TRAINEE
$enrollments_with_details = [];
foreach ($enrollments as $enrollment) {
    $enrollment_id = $enrollment['id'];
    
    $skills_result = $conn->query("SELECT * FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id ORDER BY order_index");
    $trainee_skills = $skills_result ? $skills_result->fetch_all(MYSQLI_ASSOC) : [];
    
    $questions_result = $conn->query("SELECT * FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id ORDER BY order_index");
    $trainee_questions = $questions_result ? $questions_result->fetch_all(MYSQLI_ASSOC) : [];
    
    $enrollment['trainee_skills'] = $trainee_skills;
    $enrollment['trainee_questions'] = $trainee_questions;
    $enrollment['decoded_skills'] = [];
    
    foreach ($trainee_skills as $skill) {
        $skill_id = 'skill_' . $skill['id'];
        $enrollment['decoded_skills'][$skill_id] = [
            'score' => $skill['score'] ?? 0,
            'name' => $skill['skill_name'],
            'max_score' => $skill['max_score']
        ];
    }
    
    $enrollment['decoded_questions'] = [];
    foreach ($trainee_questions as $question) {
        $enrollment['decoded_questions'][] = [
            'question' => $question['question'],
            'max_score' => $question['max_score'],
            'score' => $question['score'] ?? 0
        ];
    }
    
    $enrollment['decoded_answers'] = !empty($enrollment['oral_answers']) ? 
        json_decode($enrollment['oral_answers'], true) : [];
    
    $enrollments_with_details[] = $enrollment;
}

// GET GLOBAL PROJECT SETUP (from first trainee or defaults)
$default_project_title = '';
$default_project_instruction = '';
$default_rubrics_data = [];

if (!empty($enrollments)) {
    $first_enrollment = $enrollments[0];
    $setup_query = $conn->query("SELECT project_title_override, project_instruction, project_rubrics FROM assessment_components WHERE enrollment_id = {$first_enrollment['id']}");
    if ($setup_query && $row = $setup_query->fetch_assoc()) {
        $default_project_title = $row['project_title_override'] ?? '';
        $default_project_instruction = $row['project_instruction'] ?? '';
        if (!empty($row['project_rubrics'])) {
            $default_rubrics_data = json_decode($row['project_rubrics'], true);
            if (!is_array($default_rubrics_data)) $default_rubrics_data = [];
        }
    }
}

if (empty($default_rubrics_data)) {
    $default_rubrics_data = [
        ['name' => 'Content Quality', 'max_score' => 30, 'score' => 0],
        ['name' => 'Design & Creativity', 'max_score' => 25, 'score' => 0],
        ['name' => 'Technical Execution', 'max_score' => 25, 'score' => 0],
        ['name' => 'Presentation & Documentation', 'max_score' => 20, 'score' => 0]
    ];
}

// GET GLOBAL PASSING PERCENTAGES
$global_practical_passing = 75;
$global_project_passing = 75;
$global_oral_passing = 75;

if (!empty($enrollments)) {
    $first_enrollment = $enrollments[0];
    $ac_check = $conn->query("SELECT practical_passing_percentage, project_passing_percentage, oral_passing_percentage 
                              FROM assessment_components WHERE enrollment_id = {$first_enrollment['id']}");
    if ($ac_check && $row = $ac_check->fetch_assoc()) {
        $global_practical_passing = $row['practical_passing_percentage'] ?? 75;
        $global_project_passing = $row['project_passing_percentage'] ?? 75;
        $global_oral_passing = $row['oral_passing_percentage'] ?? 75;
    }
}

// CALCULATE STATISTICS
$total_trainees = count($enrollments);
$passed_count = 0;
$failed_count = 0;
$pending_count = 0;
$practical_completed = 0;
$project_completed = 0;
$oral_completed = 0;
$skills_loaded = 0;
$questions_loaded = 0;
$project_submitted = 0;
$oral_answered = 0;
$finalized_count = 0;

foreach ($enrollments as $e) {
    $overall_result = $e['assessment_result'] ?? $e['overall_result'] ?? null;
    if ($overall_result == 'Passed') $passed_count++;
    elseif ($overall_result == 'Failed') $failed_count++;
    else $pending_count++;
    
    $skills_check = $conn->query("SELECT COUNT(*) as cnt FROM trainee_practical_skills WHERE enrollment_id = {$e['id']}");
    if ($skills_check && $row = $skills_check->fetch_assoc()) {
        if ($row['cnt'] > 0) $skills_loaded++;
    }
    
    $questions_check = $conn->query("SELECT COUNT(*) as cnt FROM trainee_oral_questions WHERE enrollment_id = {$e['id']}");
    if ($questions_check && $row = $questions_check->fetch_assoc()) {
        if ($row['cnt'] > 0) $questions_loaded++;
    }
    
    if (!is_null($e['practical_score']) && $e['practical_score'] > 0) $practical_completed++;
    if (!is_null($e['project_score']) && $e['project_score'] > 0) $project_completed++;
    if (!is_null($e['oral_score']) && $e['oral_score'] > 0) $oral_completed++;
    if (!empty($e['project_submitted_by_trainee'])) $project_submitted++;
    if (!empty($e['oral_answers'])) $oral_answered++;
    if (!empty($e['is_finalized'])) $finalized_count++;
}

$completion_rate = $total_trainees > 0 ? round(($passed_count / $total_trainees) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Comprehensive Assessment - <?php echo htmlspecialchars($program['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header .subtitle { font-size: 16px; opacity: 0.9; }
        
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .alert-info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 13px; color: #666; margin-bottom: 5px; }
        .stat-card .number { font-size: 24px; font-weight: 700; color: #667eea; }
        .stat-card .label { font-size: 11px; color: #999; margin-top: 5px; }
        
        .program-section { background: #e8f4f8; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px dashed #17a2b8; }
        .program-section h4 { color: #17a2b8; margin-bottom: 15px; }
        
        .bulk-actions { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #28a745; }
        .bulk-actions h4 { color: #28a745; margin-bottom: 15px; }
        
        .passing-settings { background: #fff3cd; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #ffc107; }
        .passing-settings h4 { color: #ffc107; margin-bottom: 15px; }
        
        .project-setup-section { background: #e8f4f8; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #17a2b8; }
        .project-setup-section h4 { color: #17a2b8; margin-bottom: 15px; }
        
        .rubric-container { background: white; border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .rubric-header { background: #f8f9fa; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .rubric-criterion { background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .rubric-criterion h4 { color: #007bff; margin-bottom: 10px; }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 12px 25px; background: white; border: 2px solid #e0e0e0; border-radius: 50px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .tab.active { background: #667eea; color: white; border-color: #667eea; }
        .tab:hover:not(.active) { background: #f0f0f0; }
        
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; color: #555; border-bottom: 2px solid #dee2e6; position: sticky; top: 0; z-index: 10; }
        td { padding: 12px; border-bottom: 1px solid #dee2e6; vertical-align: top; }
        tr:hover td { background: #f8f9fa; }
        
        .form-control { width: 100%; padding: 8px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 13px; transition: all 0.3s; }
        .form-control:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .form-control.small { width: 70px; }
        .form-control.mini { width: 50px; padding: 4px; }
        
        .btn { padding: 8px 15px; border: none; border-radius: 50px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        
        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        
        .status-passed { background: #d4edda; color: #155724; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .status-failed { background: #f8d7da; color: #721c24; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .status-pending { background: #fff3cd; color: #856404; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        
        .back-link { display: inline-block; margin-bottom: 20px; color: #667eea; text-decoration: none; font-weight: 600; }
        .back-link i { margin-right: 5px; }
        
        .skill-row { background: #f8f9fa; padding: 10px; margin-bottom: 5px; border-radius: 5px; border-left: 3px solid #28a745; }
        .question-row { background: #f8f9fa; padding: 10px; margin-bottom: 5px; border-radius: 5px; border-left: 3px solid #17a2b8; }
        
        .total-display { font-size: 18px; font-weight: 700; color: #667eea; margin-top: 5px; }
        
        .skill-detail-row { display: flex; gap: 10px; align-items: center; margin-bottom: 5px; padding: 5px; background: white; border-radius: 5px; }
        
        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; margin-left: 5px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px; }
        .toggle-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }
        
        .submission-box { background: #e8f5e9; padding: 10px; border-radius: 8px; margin: 5px 0; border-left: 4px solid #28a745; }
        .pending-box { background: #fff3cd; padding: 10px; border-radius: 8px; margin: 5px 0; border-left: 4px solid #ffc107; }
        
        .project-thumbnail { max-width: 80px; max-height: 80px; border-radius: 5px; cursor: pointer; border: 2px solid #28a745; transition: transform 0.3s; }
        .project-thumbnail:hover { transform: scale(1.1); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        
        .image-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); animation: fadeIn 0.3s; }
        .modal-content { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; }
        .modal-image { max-width: 90%; max-height: 80%; object-fit: contain; border: 5px solid white; border-radius: 10px; }
        .modal-caption { margin-top: 20px; color: white; font-size: 18px; text-align: center; }
        .close-modal { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .close-modal:hover { color: #bbb; }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .summary-stats { 
            display: flex; 
            gap: 20px; 
            margin: 20px 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px; 
            color: white; 
        }
        .summary-stat-item { 
            flex: 1; 
            text-align: center; 
            border-right: 1px solid rgba(255,255,255,0.3); 
            padding: 0 20px;
        }
        .summary-stat-item:last-child { border-right: none; }
        .summary-stat-number { font-size: 36px; font-weight: 700; }
        .summary-stat-label { font-size: 14px; opacity: 0.9; }
        
        .percentage-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            background: #e9ecef;
            color: #495057;
            display: inline-block;
            margin-top: 3px;
        }
        
        .finalized-badge {
            background: #6f42c1;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        @media print {
            .header, .tabs, .btn-group, .btn, .back-link, .bulk-actions, .program-section, 
            .toggle-switch, .image-modal, .close-modal, .stats-grid, .summary-stats,
            .bulk-actions, .program-section, .passing-settings, .project-setup-section, .print\:hidden {
                display: none !important;
            }
            
            body { background: white; padding: 0; }
            .container { max-width: 100%; margin: 0; padding: 20px; }
            .table-container { box-shadow: none; padding: 0; margin: 0; background: white; }
            table { border-collapse: collapse; width: 100%; border: 1px solid #000; font-size: 10pt; }
            th { background: #333 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            td, th { border: 1px solid #000; padding: 6px; }
            tr:nth-child(even) { background: #f9f9f9; }
        }

            /* Mobile Responsive Styles */
            @media screen and (max-width: 768px) {
                body {
                    padding: 10px;
                }
                
                .container {
                    padding: 0;
                }
                
                .header {
                    padding: 20px 15px;
                    margin-bottom: 20px;
                }
                
                .header h1 {
                    font-size: 20px;
                    margin-bottom: 8px;
                }
                
                .header .subtitle {
                    font-size: 14px;
                }
                
                .info-grid {
                    grid-template-columns: 1fr;
                    gap: 10px;
                    padding: 15px;
                }
                
                .info-item {
                    margin-bottom: 5px;
                }
                
                .info-value {
                    font-size: 16px;
                }
                
                .tabs {
                    gap: 5px;
                    margin-bottom: 15px;
                    flex-wrap: wrap;
                }
                
                .tab {
                    padding: 8px 15px;
                    font-size: 12px;
                    flex: 1;
                    text-align: center;
                    min-width: 80px;
                }
                
                .assessment-card {
                    padding: 15px;
                    margin-bottom: 20px;
                }
                
                .card-title {
                    font-size: 18px;
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }
                
                .toggle-container {
                    margin-left: 0;
                    width: 100%;
                    justify-content: space-between;
                }
                
                .row {
                    flex-direction: column;
                    gap: 15px;
                }
                
                .col {
                    min-width: 100%;
                }
                
                .form-control {
                    font-size: 16px; /* Prevents zoom on mobile */
                    padding: 10px;
                }
                
                .custom-skill-row {
                    padding: 12px;
                }
                
                .custom-skill-row > div {
                    flex-direction: column !important;
                    gap: 10px !important;
                }
                
                .custom-skill-row > div > div {
                    width: 100%;
                }
                
                .custom-skill-row .remove-btn {
                    align-self: flex-end;
                    margin-top: 5px;
                }
                
                .button-group {
                    flex-direction: column;
                    gap: 8px;
                }
                
                .button-group button {
                    width: 100%;
                    justify-content: center;
                }
                
                .add-btn, .delete-btn, .save-trainee-skills-btn, 
                .save-trainee-questions-btn, .save-scores-btn, 
                .clear-answers-btn, .save-tab-btn, .submit-btn {
                    width: 100%;
                    justify-content: center;
                    padding: 12px 20px;
                    font-size: 14px;
                }
                
                .scoring-section {
                    padding: 15px;
                }
                
                .scoring-section .custom-skill-row > div {
                    flex-direction: column !important;
                    gap: 12px !important;
                }
                
                .score-input {
                    width: 100%;
                    max-width: 120px;
                }
                
                .passing-percentage-group {
                    padding: 12px;
                }
                
                .passing-percentage-wrapper {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 10px;
                }
                
                .passing-percentage-input {
                    width: 100%;
                }
                
                .btn-save-settings {
                    width: 100%;
                    justify-content: center;
                }
                
                .total-display {
                    font-size: 18px;
                    text-align: center;
                }
                
                .summary-cards {
                    grid-template-columns: 1fr;
                    gap: 15px;
                }
                
                .summary-card {
                    padding: 15px;
                }
                
                .summary-card .score {
                    font-size: 28px;
                }
                
                /* Table responsive */
                table {
                    display: block;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }
                
                table thead, table tbody {
                    display: table;
                    width: 100%;
                }
                
                table th, table td {
                    padding: 8px 10px;
                    font-size: 13px;
                    min-width: 100px;
                }
                
                /* Modal responsive */
                .modal-content {
                    width: 95%;
                    margin: 20% auto;
                    padding: 20px;
                }
                
                .back-btn {
                    font-size: 14px;
                    padding: 8px 15px;
                    margin-bottom: 15px;
                }
                
                /* Rubric section responsive */
                .rubric-container {
                    padding: 15px;
                }
                
                .rubric-criterion {
                    padding: 12px;
                }
                
                .rubric-criterion > div:first-child {
                    flex-direction: column !important;
                    align-items: stretch !important;
                    gap: 10px !important;
                }
                
                .criterion-name {
                    width: 100% !important;
                    margin: 10px 0;
                }
                
                .remove-rubric-btn {
                    align-self: flex-end;
                }
                
                .rubric-total {
                    text-align: center;
                }
                
                .rubric-total > div {
                    flex-direction: column;
                    gap: 10px;
                }
                
                /* Alert messages */
                .alert-success, .alert-info, .alert-warning, .alert-danger {
                    padding: 12px;
                    font-size: 14px;
                    margin-bottom: 15px;
                }
                
                /* Waiting message */
                .waiting-message {
                    padding: 30px 20px;
                }
                
                .waiting-message h3 {
                    font-size: 16px;
                }
                
                /* Answer box */
                .answer-box {
                    padding: 12px;
                    margin-top: 10px;
                }
                
                .answer-text {
                    font-size: 14px;
                }
                
                /* Training section */
                .trainee-section {
                    padding: 15px;
                }
                
                .trainee-section h4 {
                    font-size: 16px;
                }
            }

            /* Extra small devices (phones, 480px and below) */
            @media screen and (max-width: 480px) {
                .tab {
                    font-size: 11px;
                    padding: 6px 12px;
                    min-width: 70px;
                }
                
                .card-title {
                    font-size: 16px;
                }
                
                .header h1 {
                    font-size: 18px;
                }
                
                .info-value {
                    font-size: 14px;
                }
                
                .form-control {
                    font-size: 14px;
                }
                
                .custom-skill-row {
                    padding: 10px;
                }
                
                .score-input {
                    font-size: 14px;
                    padding: 6px;
                }
                
                table th, table td {
                    font-size: 12px;
                    padding: 6px 8px;
                    min-width: 80px;
                }
                
                .summary-card .score {
                    font-size: 24px;
                }
                
                .summary-card h3 {
                    font-size: 14px;
                }
                
                .pass-badge, .fail-badge, .pending-badge {
                    font-size: 10px;
                    padding: 2px 8px;
                }
                
                .saved-status, .visibility-status {
                    font-size: 11px;
                    padding: 2px 8px;
                }
            }

            /* Landscape mode on mobile */
            @media screen and (max-width: 768px) and (orientation: landscape) {
                body {
                    padding: 10px;
                }
                
                .tabs {
                    flex-wrap: wrap;
                }
                
                .tab {
                    flex: 0 1 auto;
                }
                
                .custom-skill-row > div {
                    flex-direction: row !important;
                    flex-wrap: wrap;
                }
                
                .custom-skill-row > div > div {
                    flex: 1;
                    min-width: 120px;
                }
            }

            /* Touch-friendly adjustments */
            @media (hover: none) and (pointer: coarse) {
                button, .tab, .back-btn, .add-btn, .delete-btn, 
                .save-tab-btn, .submit-btn, .remove-btn {
                    min-height: 44px; /* Minimum touch target size */
                }
                
                input, select, textarea {
                    font-size: 16px; /* Prevents zoom on focus in iOS */
                }
            }
    </style>
</head>
<body>
    <div class="container">
        <a href="trainer_participants.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Participants
        </a>
        
        <div class="header">
            <h1><i class="fas fa-users"></i> Bulk Comprehensive Assessment</h1>
            <div class="subtitle">
                <strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?> | 
                <strong>Schedule:</strong> <?php echo date('M d, Y', strtotime($program['scheduleStart'])); ?> - <?php echo date('M d, Y', strtotime($program['scheduleEnd'])); ?>
                <?php if ($finalized_count > 0): ?>
                    <span style="margin-left: 20px; background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px;">
                        <i class="fas fa-check-circle"></i> <?php echo $finalized_count; ?>/<?php echo $total_trainees; ?> Finalized
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
       
        
        <!-- Passing Percentage Settings (Global) -->
        <div class="passing-settings">
            <h4><i class="fas fa-percent"></i> Global Passing Percentage Settings</h4>
            <p style="font-size: 13px; color: #666; margin-bottom: 15px;">Set passing percentages for all assessment components. These will apply to all trainees.</p>
            
            <form method="POST" id="passingPercentagesForm">
                <input type="hidden" name="save_bulk_passing_percentages" value="1">
                <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>">
                <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #28a745;">
                            <i class="fas fa-utensils"></i> Practical Skills
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="practical_passing_percentage" name="practical_passing_percentage" 
                                   class="form-control" value="<?php echo $global_practical_passing; ?>" 
                                   min="65" max="100" step="0.5" style="width: 100px;">
                            <span>%</span>
                        </div>
                        <small style="font-size: 11px; color: #666;">(65% - 100%)</small>
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #17a2b8;">
                            <i class="fas fa-project-diagram"></i> Project Output
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="project_passing_percentage" name="project_passing_percentage" 
                                   class="form-control" value="<?php echo $global_project_passing; ?>" 
                                   min="65" max="100" step="0.5" style="width: 100px;">
                            <span>%</span>
                        </div>
                        <small style="font-size: 11px; color: #666;">(65% - 100%)</small>
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #8b5cf6;">
                            <i class="fas fa-microphone-alt"></i> Oral Assessment
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="oral_passing_percentage" name="oral_passing_percentage" 
                                   class="form-control" value="<?php echo $global_oral_passing; ?>" 
                                   min="65" max="100" step="0.5" style="width: 100px;">
                            <span>%</span>
                        </div>
                        <small style="font-size: 11px; color: #666;">(65% - 100%)</small>
                    </div>
                    
                    <div>
                        <button type="submit" class="btn btn-warning" style="background: #ffc107; color: #212529;">
                            <i class="fas fa-save"></i> Apply to All Trainees
                        </button>
                    </div>
                </div>
            </form>
            
            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 5px; font-size: 12px;">
                <i class="fas fa-info-circle"></i> 
                <strong>Note:</strong> These passing percentages determine whether a trainee passes or fails each component. 
                The overall passing score is calculated as a weighted average based on these percentages.
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab <?php echo $current_tab == 'practical' ? 'active' : ''; ?>" onclick="switchTab('practical')">
                <i class="fas fa-utensils"></i> Practical Skills (<?php echo $practical_completed; ?>/<?php echo $total_trainees; ?>)
            </div>
            <div class="tab <?php echo $current_tab == 'project' ? 'active' : ''; ?>" onclick="switchTab('project')">
                <i class="fas fa-project-diagram"></i> Project Output (<?php echo $project_completed; ?>/<?php echo $total_trainees; ?>)
            </div>
            <div class="tab <?php echo $current_tab == 'oral' ? 'active' : ''; ?>" onclick="switchTab('oral')">
                <i class="fas fa-microphone-alt"></i> Oral Assessment (<?php echo $oral_completed; ?>/<?php echo $total_trainees; ?>)
            </div>
            <div class="tab <?php echo $current_tab == 'summary' ? 'active' : ''; ?>" onclick="switchTab('summary')">
                <i class="fas fa-table"></i> Summary & Results
            </div>
        </div>
        
        <!-- TAB 1: PRACTICAL SKILLS -->
        <?php if ($current_tab == 'practical'): ?>
            <!-- Program Skills Setup (Template) -->
            <div class="program-section">
                <h4><i class="fas fa-cog"></i> Program Default Practical Skills (Template)</h4>
                <p>Define the skills template that can be loaded to all trainees. Skills saved here will be loaded to individual trainee records.</p>
                
                <div id="program-skills-container">
                    <?php foreach ($program_skills as $index => $skill): ?>
                    <div class="skill-row">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" class="form-control program-skill-name" value="<?php echo htmlspecialchars($skill['skill_name']); ?>" placeholder="Skill name" style="flex: 2;">
                            <input type="number" class="form-control program-skill-max" value="<?php echo $skill['max_score']; ?>" min="1" max="100" style="flex: 1;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($program_skills)): ?>
                    <div class="skill-row">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" class="form-control program-skill-name" value="Basic Knife Skills" placeholder="Skill name" style="flex: 2;">
                            <input type="number" class="form-control program-skill-max" value="20" min="1" max="100" style="flex: 1;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-success" onclick="addProgramSkill()">
                        <i class="fas fa-plus"></i> Add Skill
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveProgramSkills()">
                        <i class="fas fa-save"></i> Save Program Skills Template
                    </button>
                </div>
            </div>
            
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <h4><i class="fas fa-bolt"></i> Bulk Actions - Practical Skills</h4>
                <div class="btn-group">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="load_skills_to_all" class="btn btn-info" <?php echo !$program_skills_exist ? 'disabled' : ''; ?>>
                            <i class="fas fa-download"></i> Load Skills to All (Create Individual Records)
                        </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset ALL trainees\' skills? This will delete all individual skill records.');">
                        <button type="submit" name="reset_all_skills" class="btn btn-warning">
                            <i class="fas fa-undo"></i> Reset All Skills (Delete Individual Records)
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Detailed Skills Entry per Trainee -->
            <form method="POST" id="practicalForm">
                <input type="hidden" name="save_bulk_practical_detailed" value="1">
                <input type="hidden" name="practical_date" value="<?php echo date('Y-m-d'); ?>">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="15%">Trainee</th>
                                <th width="45%">Skills & Scores</th>
                                <th width="12%">Total / Status</th>
                                <th width="25%">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrollments_with_details as $index => $enrollment): 
                                $practical_total = 0;
                                $practical_max = 0;
                                $trainee_skills = $enrollment['trainee_skills'];
                                
                                foreach ($trainee_skills as $skill) {
                                    $practical_total += $skill['score'] ?? 0;
                                    $practical_max += $skill['max_score'];
                                }
                                
                                $trainee_practical_passing = $enrollment['practical_passing_percentage'] ?? 75;
                                $practical_percentage = $practical_max > 0 ? ($practical_total / $practical_max) * 100 : 0;
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                                    <input type="hidden" name="enrollment_id[]" value="<?php echo $enrollment['id']; ?>">
                                    <div style="margin-top: 5px;">
                                        <?php if (!empty($trainee_skills)): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> Skills Loaded</span>
                                            <span class="badge badge-info"><?php echo count($trainee_skills); ?> skills</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fas fa-exclamation"></i> No skills</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div id="skills-<?php echo $index; ?>" class="skills-container">
                                        <?php 
                                        $skill_scores_json = [];
                                        if (!empty($trainee_skills)):
                                            foreach ($trainee_skills as $skill):
                                                $skill_id = 'skill_' . $skill['id'];
                                                $score = $skill['score'] ?? 0;
                                        ?>
                                        <div class="skill-detail-row">
                                            <span style="flex: 2; font-size: 12px; font-weight: 500;">
                                                <?php echo htmlspecialchars($skill['skill_name']); ?> 
                                                <span style="color: #666;">(max: <?php echo $skill['max_score']; ?>)</span>
                                            </span>
                                            <input type="number" class="form-control mini skill-score" 
                                                   data-index="<?php echo $index; ?>" 
                                                   data-skill-id="<?php echo $skill_id; ?>"
                                                   value="<?php echo $score; ?>" 
                                                   min="0" max="<?php echo $skill['max_score']; ?>" 
                                                   style="flex: 1; width: 60px;" 
                                                   onchange="updateSkillTotal(<?php echo $index; ?>)">
                                            <span style="flex: 0.5; font-size: 11px; color: #666;">
                                                <?php echo $score >= ($skill['max_score'] * $trainee_practical_passing / 100) ? '✅' : '❌'; ?>
                                            </span>
                                        </div>
                                        <?php 
                                                $skill_scores_json[$skill_id] = ['score' => $score];
                                            endforeach;
                                        else: 
                                        ?>
                                        <div style="color: #999; font-style: italic; padding: 10px; text-align: center;">
                                            <i class="fas fa-info-circle"></i> No skills loaded. Click "Load Skills to All" first.
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="skill_scores[]" id="skill-scores-<?php echo $index; ?>" value='<?php echo json_encode($skill_scores_json); ?>'>
                                </td>
                                <td>
                                    <div class="total-display" id="practical-total-<?php echo $index; ?>"><?php echo $practical_total; ?></div>
                                    <div style="font-size: 11px; color: #666;">out of <?php echo $practical_max; ?></div>
                                    <div style="font-size: 10px; color: #666;">Target: <?php echo $trainee_practical_passing; ?>%</div>
                                    <?php if ($practical_total > 0): ?>
                                        <?php if ($practical_percentage >= $trainee_practical_passing): ?>
                                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> PASS</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> FAIL</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <textarea name="practical_notes[]" class="form-control" rows="3" 
                                              placeholder="Add notes/observations..."><?php echo htmlspecialchars($enrollment['practical_notes'] ?? ''); ?></textarea>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($enrollments)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc; margin-bottom: 10px;"></i>
                                    <p>No approved trainees found for this program.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($enrollments)): ?>
                <div style="text-align: center; margin-top: 20px; margin-bottom: 30px;">
                    <button type="submit" class="btn btn-primary" style="padding: 15px 40px; font-size: 16px;">
                        <i class="fas fa-save"></i> Save All Practical Scores
                    </button>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        
        <!-- TAB 2: PROJECT OUTPUT -->
        <?php if ($current_tab == 'project'): ?>
            <!-- Project Setup Section -->
            <div class="project-setup-section">
                <h4><i class="fas fa-pen-alt"></i> Project Setup (Apply to All Trainees)</h4>
                <p>Set up the project title, instructions, and grading rubric. These will be applied to ALL trainees in this program.</p>
                
                <div style="background: #f0f7ff; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 600; margin-bottom: 8px; display: block;">
                            <i class="fas fa-tag"></i> Project Title:
                        </label>
                        <input type="text" id="project_title_override" class="form-control" 
                               value="<?php echo htmlspecialchars($default_project_title); ?>"
                               placeholder="Enter project title...">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 600; margin-bottom: 8px; display: block;">
                            <i class="fas fa-info-circle"></i> Instructions for Trainees:
                        </label>
                        <textarea id="project_instruction" class="form-control" rows="4" 
                                  placeholder="Enter detailed instructions for the project..."><?php echo htmlspecialchars($default_project_instruction); ?></textarea>
                        <small style="font-size: 11px; color: #666;">These instructions will be visible to trainees when the project is made visible.</small>
                    </div>
                    
                    <!-- RUBRIC SECTION -->
                    <div class="rubric-container">
                        <div class="rubric-header">
                            <h3><i class="fas fa-chart-line"></i> Grading Rubric</h3>
                            <p>Define criteria and max points for evaluation. These will be used for all trainees.</p>
                        </div>
                        
                        <div id="rubric-criteria-container">
                            <?php foreach ($default_rubrics_data as $index => $criterion): ?>
                            <div class="rubric-criterion" data-criterion-index="<?php echo $index; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <h4 style="margin: 0; color: #007bff;">
                                        <i class="fas fa-check-circle"></i> Criterion <?php echo $index + 1; ?>:
                                        <input type="text" class="form-control criterion-name"
                                               style="display: inline-block; width: auto; min-width: 200px; margin-left: 10px;"
                                               value="<?php echo htmlspecialchars($criterion['name'] ?? ''); ?>"
                                               placeholder="Criterion name">
                                    </h4>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRubricCriterion(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                                <div style="margin-bottom: 10px;">
                                    <label>Max Points: </label>
                                    <input type="number" class="form-control rubric-max-score"
                                           style="width: 100px; display: inline-block;"
                                           value="<?php echo $criterion['max_score'] ?? 20; ?>"
                                           min="1" max="1000" step="1" onchange="updateRubricTotal()">
                                </div>
                                <div class="rubric-score-display" style="font-size: 12px; color: #666;">
                                    Score will be entered per trainee in the section below
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <button type="button" class="btn btn-success" onclick="addRubricCriterion()">
                                <i class="fas fa-plus"></i> Add Criterion
                            </button>
                        </div>
                        
                        <div class="rubric-total" style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-top: 20px; text-align: center;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h4 style="margin: 0;">Rubric Summary:</h4>
                                <div style="text-align: right;">
                                    <span style="font-size: 14px; color: #666;">Total Max Points: </span>
                                    <span id="rubric-max-total" style="font-size: 18px; font-weight: bold; color: #667eea;">0</span>
                                </div>
                            </div>
                            <p class="rubric-score-display" style="font-size: 12px; color: #666;">Project total max points = Sum of all rubric max scores</p>
                        </div>
                        
                        <div style="margin-top: 20px; text-align: center;">
                            <button type="button" class="btn btn-primary" onclick="saveBulkProjectSetup()" style="padding: 12px 30px;">
                                <i class="fas fa-save"></i> Apply Project Setup to All Trainees
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Visibility Bulk Actions -->
            <div class="bulk-actions">
                <h4><i class="fas fa-eye"></i> Visibility Settings</h4>
                <div class="btn-group">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="toggle_all_visibility" value="1">
                        <input type="hidden" name="visibility_type" value="project">
                        <button type="submit" name="visibility_value" value="1" class="btn btn-success">
                            <i class="fas fa-eye"></i> Show to All
                        </button>
                        <button type="submit" name="visibility_value" value="0" class="btn btn-secondary">
                            <i class="fas fa-eye-slash"></i> Hide from All
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Project Scores Entry with Submission Details -->
            <form method="POST">
                <input type="hidden" name="save_bulk_project" value="1">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="15%">Trainee</th>
                                <th width="25%">Submission Details</th>
                                <th width="10%">Image</th>
                                <th width="10%">Score / Status</th>
                                <th width="20%">Feedback</th>
                                <th width="10%">Status</th>
                                <th width="7%">Visibility</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrollments as $index => $enrollment): 
                                $has_submission = $enrollment['project_submitted_by_trainee'];
                                $image_path = $enrollment['project_photo_path'] ?? '';
                                $submission_date = $enrollment['project_submitted_at'] ?? '';
                                $project_passing = $enrollment['project_passing_percentage'] ?? 75;
                                $project_score = $enrollment['project_score'] ?? null;
                                $project_total_max = $enrollment['project_total_max'] ?? 100;
                                
                                $trainee_rubrics = [];
                                if (!empty($enrollment['project_rubrics'])) {
                                    $trainee_rubrics = json_decode($enrollment['project_rubrics'], true);
                                    if (!is_array($trainee_rubrics)) $trainee_rubrics = [];
                                }
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                                    <input type="hidden" name="enrollment_id[]" value="<?php echo $enrollment['id']; ?>">
                                    <div style="font-size: 11px; color: #666; margin-top: 3px;">
                                        <?php echo htmlspecialchars($enrollment['email']); ?>
                                    </div>
                                    <?php if (!empty($trainee_rubrics)): ?>
                                        <div style="margin-top: 5px;">
                                            <span class="badge badge-info">Rubric: <?php echo count($trainee_rubrics); ?> criteria</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($has_submission): ?>
                                        <div class="submission-box">
                                            <div style="font-weight: bold; color: #28a745; margin-bottom: 5px;">
                                                <i class="fas fa-check-circle"></i> Submitted
                                                <?php if ($submission_date): ?>
                                                    <span style="font-size: 11px; margin-left: 5px;">
                                                        (<?php echo date('M d, Y h:i A', strtotime($submission_date)); ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($enrollment['project_title'])): ?>
                                                <div style="margin-top: 5px;">
                                                    <strong>Title:</strong> 
                                                    <span style="color: #333;"><?php echo htmlspecialchars($enrollment['project_title']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($enrollment['project_description'])): ?>
                                                <div style="margin-top: 8px;">
                                                    <strong>Description:</strong>
                                                    <div style="background: white; padding: 8px; border-radius: 5px; margin-top: 3px; font-size: 12px; max-height: 80px; overflow-y: auto;">
                                                        <?php echo nl2br(htmlspecialchars($enrollment['project_description'])); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="pending-box" style="text-align: center;">
                                            <i class="fas fa-clock" style="color: #856404; font-size: 20px; margin-bottom: 5px;"></i>
                                            <div style="color: #856404;">No submission yet</div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($has_submission && !empty($image_path)): ?>
                                        <?php 
                                        $image_full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $image_path;
                                        if (file_exists($image_full_path)): 
                                        ?>
                                            <div>
                                                <img src="/<?php echo $image_path; ?>" 
                                                     alt="Project Image" 
                                                     class="project-thumbnail"
                                                     onclick="showImageModal('/<?php echo $image_path; ?>', '<?php echo htmlspecialchars($enrollment['project_title'] ?: 'Project Image'); ?>')">
                                                <div style="margin-top: 5px;">
                                                    <button type="button" class="btn btn-info btn-sm" 
                                                            onclick="showImageModal('/<?php echo $image_path; ?>', '<?php echo htmlspecialchars($enrollment['project_title'] ?: 'Project Image'); ?>')">
                                                        <i class="fas fa-search-plus"></i> View
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Image not found</span>
                                        <?php endif; ?>
                                    <?php elseif ($has_submission): ?>
                                        <span class="badge badge-secondary"><i class="fas fa-image"></i> No image</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-eye-slash"></i> No image</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 5px; align-items: center;">
                                        <input type="number" name="project_score[]" class="form-control" 
                                               value="<?php echo $project_score; ?>" 
                                               min="0" max="<?php echo $project_total_max; ?>" step="0.5"
                                               style="width: 80px; text-align: center; font-weight: bold;"
                                               onchange="updateProjectStatus(this, <?php echo $index; ?>, <?php echo $project_passing; ?>)">
                                        <div id="project-status-<?php echo $index; ?>">
                                            <?php if (!is_null($project_score)): ?>
                                                <?php if ($project_score >= $project_passing): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> PASS</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><i class="fas fa-times"></i> FAIL</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <small style="font-size: 10px;">Target: <?php echo $project_passing; ?>%</small>
                                        <small style="font-size: 9px; color: #666;">Max: <?php echo $project_total_max; ?></small>
                                    </div>
                                </td>
                                <td>
                                    <textarea name="project_notes[]" class="form-control" rows="3" 
                                              placeholder="Enter feedback..."><?php echo htmlspecialchars($enrollment['project_notes'] ?? ''); ?></textarea>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($has_submission): ?>
                                        <span class="badge badge-success" style="display: block; margin-bottom: 5px;">
                                            <i class="fas fa-check-circle"></i> Submitted
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="display: block; margin-bottom: 5px;">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!is_null($project_score)): ?>
                                        <small style="display: block; color: #666;">Score: <?php echo $project_score; ?>/<?php echo $project_total_max; ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($enrollment['is_finalized'])): ?>
                                        <span class="finalized-badge" style="margin-top: 5px;"><i class="fas fa-check-circle"></i> Finalized</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <label class="toggle-switch" title="Toggle visibility for this trainee">
                                        <input type="checkbox" 
                                               onchange="toggleVisibility('project', <?php echo $enrollment['id']; ?>, this)"
                                               <?php echo ($enrollment['project_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>
                                               <?php echo !empty($enrollment['is_finalized']) ? 'disabled' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <div style="font-size: 10px; margin-top: 3px;">
                                        <?php echo ($enrollment['project_visible_to_trainee'] ?? 0) ? 'Visible' : 'Hidden'; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($enrollments)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc; margin-bottom: 10px;"></i>
                                    <p>No approved trainees found for this program.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($enrollments)): ?>
                <div style="text-align: center; margin-top: 20px; margin-bottom: 30px;">
                    <button type="submit" class="btn btn-primary" style="padding: 15px 40px; font-size: 16px;">
                        <i class="fas fa-save"></i> Save All Project Scores
                    </button>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        
        <!-- TAB 3: ORAL ASSESSMENT -->
        <?php if ($current_tab == 'oral'): ?>
            <!-- Program Questions Setup (Template) -->
            <div class="program-section">
                <h4><i class="fas fa-cog"></i> Program Default Oral Questions (Template)</h4>
                <p>Define the questions template that can be loaded to all trainees. Questions saved here will be loaded to individual trainee records.</p>
                
                <div id="program-questions-container">
                    <?php foreach ($program_questions as $index => $q): ?>
                    <div class="question-row">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" class="form-control program-question" value="<?php echo htmlspecialchars($q['question']); ?>" placeholder="Question" style="flex: 3;">
                            <input type="number" class="form-control program-question-max" value="<?php echo $q['max_score']; ?>" min="1" max="100" style="flex: 1;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramQuestion(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($program_questions)): ?>
                    <div class="question-row">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" class="form-control program-question" value="What are the basic safety procedures?" placeholder="Question" style="flex: 3;">
                            <input type="number" class="form-control program-question-max" value="25" min="1" max="100" style="flex: 1;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramQuestion(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" class="btn btn-success" onclick="addProgramQuestion()">
                        <i class="fas fa-plus"></i> Add Question
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveProgramQuestions()">
                        <i class="fas fa-save"></i> Save Program Questions Template
                    </button>
                </div>
            </div>
            
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <h4><i class="fas fa-bolt"></i> Bulk Actions - Oral Assessment</h4>
                <div class="btn-group">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="load_questions_to_all" class="btn btn-info" <?php echo !$program_questions_exist ? 'disabled' : ''; ?>>
                            <i class="fas fa-download"></i> Load Questions to All (Create Individual Records)
                        </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset ALL trainees\' questions? This will delete all individual question records.');">
                        <button type="submit" name="reset_all_questions" class="btn btn-warning">
                            <i class="fas fa-undo"></i> Reset All Questions (Delete Individual Records)
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="toggle_all_visibility" value="1">
                        <input type="hidden" name="visibility_type" value="oral">
                        <button type="submit" name="visibility_value" value="1" class="btn btn-success">
                            <i class="fas fa-eye"></i> Show to All
                        </button>
                        <button type="submit" name="visibility_value" value="0" class="btn btn-secondary">
                            <i class="fas fa-eye-slash"></i> Hide from All
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Oral Scores Entry with Answer Preview -->
            <form method="POST">
                <input type="hidden" name="save_bulk_oral" value="1">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="15%">Trainee</th>
                                <th width="25%">Questions & Answers</th>
                                <th width="8%">Max</th>
                                <th width="8%">Score / Status</th>
                                <th width="15%">Feedback</th>
                                <th width="15%">Status</th>
                                <th width="8%">Visibility</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrollments_with_details as $index => $enrollment): 
                                $trainee_questions = $enrollment['trainee_questions'];
                                $oral_max = 0;
                                foreach ($trainee_questions as $q) {
                                    $oral_max += $q['max_score'];
                                }
                                if ($oral_max == 0) $oral_max = 100;
                                
                                $answers = $enrollment['decoded_answers'];
                                $has_answers = !empty($answers);
                                $questions_set = !empty($trainee_questions);
                                $submission_date = $enrollment['oral_submitted_at'] ?? '';
                                $oral_passing = $enrollment['oral_passing_percentage'] ?? 75;
                                $oral_score = $enrollment['oral_score'] ?? null;
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                                    <input type="hidden" name="enrollment_id[]" value="<?php echo $enrollment['id']; ?>">
                                    <div style="font-size: 11px; color: #666; margin-top: 3px;">
                                        <?php echo htmlspecialchars($enrollment['email']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($questions_set && !empty($trainee_questions)): ?>
                                        <div style="background: #f8f9fa; padding: 10px; border-radius: 8px; max-height: 200px; overflow-y: auto;">
                                            <?php foreach ($trainee_questions as $q_index => $q): ?>
                                                <div style="margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #dee2e6;">
                                                    <div style="font-weight: 600; color: #17a2b8; font-size: 12px;">
                                                        Q<?php echo $q_index + 1; ?>: <?php echo htmlspecialchars($q['question']); ?>
                                                        <span style="color: #666; font-weight: normal;"> (<?php echo $q['max_score']; ?> pts)</span>
                                                    </div>
                                                    <?php if ($has_answers && isset($answers[$q_index])): ?>
                                                        <div style="margin-top: 5px; background: white; padding: 8px; border-radius: 5px; font-size: 12px;">
                                                            <span style="color: #28a745;"><i class="fas fa-comment"></i></span>
                                                            <?php echo nl2br(htmlspecialchars(substr($answers[$q_index], 0, 100))); ?>
                                                            <?php if (strlen($answers[$q_index]) > 100): ?>
                                                                <a href="#" onclick="showFullAnswer(<?php echo $index; ?>, <?php echo $q_index; ?>); return false;">... more</a>
                                                                <div id="answer-<?php echo $index; ?>-<?php echo $q_index; ?>" style="display: none;">
                                                                    <?php echo nl2br(htmlspecialchars($answers[$q_index])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($questions_set): ?>
                                                        <div style="margin-top: 5px; color: #856404; font-style: italic; font-size: 12px;">
                                                            <i class="fas fa-clock"></i> No answer yet
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="pending-box" style="text-align: center;">
                                            <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                                            <span style="color: #856404;"> Questions not set. Load questions first.</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: bold;"><?php echo $oral_max; ?></td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 5px; align-items: center;">
                                        <input type="number" name="oral_score[]" class="form-control" 
                                               value="<?php echo $oral_score; ?>" 
                                               min="0" max="<?php echo $oral_max; ?>" step="0.5"
                                               style="width: 70px; text-align: center; font-weight: bold;"
                                               onchange="updateOralStatus(this, <?php echo $index; ?>, <?php echo $oral_max; ?>, <?php echo $oral_passing; ?>)"
                                               <?php echo !$questions_set ? 'disabled' : ''; ?>>
                                        <div id="oral-status-<?php echo $index; ?>">
                                            <?php if (!is_null($oral_score)): ?>
                                                <?php if ($oral_score >= ($oral_max * $oral_passing / 100)): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> PASS</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><i class="fas fa-times"></i> FAIL</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <small style="font-size: 10px;">Target: <?php echo $oral_passing; ?>%</small>
                                    </div>
                                </td>
                                <td>
                                    <textarea name="oral_notes[]" class="form-control" rows="3" 
                                              placeholder="Enter feedback..." <?php echo !$questions_set ? 'disabled' : ''; ?>><?php echo htmlspecialchars($enrollment['oral_notes'] ?? ''); ?></textarea>
                                </td>
                                <td>
                                    <?php if ($questions_set): ?>
                                        <span class="badge badge-info" style="display: block; margin-bottom: 5px;">
                                            <i class="fas fa-check-circle"></i> Set
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($has_answers): ?>
                                        <span class="badge badge-success" style="display: block; margin-bottom: 5px;">
                                            <i class="fas fa-comment"></i> Answered
                                            <?php if ($submission_date): ?>
                                                <span style="font-size: 10px;">(<?php echo date('M d', strtotime($submission_date)); ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!is_null($oral_score)): ?>
                                        <small style="display: block; color: #666;">Score: <?php echo $oral_score; ?>/<?php echo $oral_max; ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($enrollment['is_finalized'])): ?>
                                        <span class="finalized-badge" style="margin-top: 5px;"><i class="fas fa-check-circle"></i> Finalized</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <label class="toggle-switch" title="Toggle visibility for this trainee">
                                        <input type="checkbox" 
                                               onchange="toggleVisibility('oral', <?php echo $enrollment['id']; ?>, this)"
                                               <?php echo ($enrollment['oral_questions_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>
                                               <?php echo !$questions_set || !empty($enrollment['is_finalized']) ? 'disabled' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <div style="font-size: 10px; margin-top: 3px;">
                                        <?php echo ($enrollment['oral_questions_visible_to_trainee'] ?? 0) ? 'Visible' : 'Hidden'; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($enrollments)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc; margin-bottom: 10px;"></i>
                                    <p>No approved trainees found for this program.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($enrollments)): ?>
                <div style="text-align: center; margin-top: 20px; margin-bottom: 30px;">
                    <button type="submit" class="btn btn-primary" style="padding: 15px 40px; font-size: 16px;">
                        <i class="fas fa-save"></i> Save All Oral Scores
                    </button>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        
        <!-- TAB 4: SUMMARY & RESULTS -->
        <?php if ($current_tab == 'summary'): ?>
            <!-- Calculate All Button -->
            <div class="bulk-actions">
                <h4><i class="fas fa-calculator"></i> Finalize Assessments</h4>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Calculate results for ALL trainees? This will update overall results based on current scores.');">
                        <button type="submit" name="calculate_all_results" class="btn btn-primary">
                            <i class="fas fa-calculator"></i> Calculate All Results
                        </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Finalize ALL completed assessments? This will update enrollment status and cannot be undone.');">
                        <input type="hidden" name="finalize_all_completed" value="1">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i> Finalize All Completed
                        </button>
                    </form>
                </div>
            </div>

            <!-- Print Button and Program Info -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <div style="background: #f8f9fa; padding: 10px 15px; border-radius: 8px;">
                    <strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?> | 
                    <strong>Total Trainees:</strong> <?php echo $total_trainees; ?> |
                    <strong>Date:</strong> <?php echo date('F d, Y'); ?>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button onclick="printSummary()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Summary
                    </button>
                </div>
            </div>
            
            <!-- Passing Thresholds Summary -->
            <div style="background: #fff3cd; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h4><i class="fas fa-percent"></i> Current Passing Thresholds</h4>
                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px;">
                    <div><strong>Practical:</strong> <span style="color: #28a745;"><?php echo $global_practical_passing; ?>%</span></div>
                    <div><strong>Project:</strong> <span style="color: #17a2b8;"><?php echo $global_project_passing; ?>%</span></div>
                    <div><strong>Oral:</strong> <span style="color: #8b5cf6;"><?php echo $global_oral_passing; ?>%</span></div>
                    <div><strong>Overall:</strong> <span style="color: #667eea;">Weighted Average (Calculated)</span></div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="summary-stat-item">
                    <div class="summary-stat-number"><?php echo $passed_count; ?></div>
                    <div class="summary-stat-label">Passed</div>
                </div>
                <div class="summary-stat-item">
                    <div class="summary-stat-number"><?php echo $failed_count; ?></div>
                    <div class="summary-stat-label">Failed</div>
                </div>
                <div class="summary-stat-item">
                    <div class="summary-stat-number"><?php echo $pending_count; ?></div>
                    <div class="summary-stat-label">Pending</div>
                </div>
                <div class="summary-stat-item">
                    <div class="summary-stat-number"><?php echo $completion_rate; ?>%</div>
                    <div class="summary-stat-label">Completion Rate</div>
                </div>
            </div>

            <!-- Summary Table -->
            <div class="table-container" id="summaryTable">
                <table>
                    <thead>
                        <tr>
                            <th width="3%">#</th>
                            <th width="15%">Trainee Name</th>
                            <th width="8%">Practical</th>
                            <th width="8%">Practical Status</th>
                            <th width="8%">Project</th>
                            <th width="8%">Project Status</th>
                            <th width="10%">Oral</th>
                            <th width="8%">Oral Status</th>
                            <th width="8%">Total</th>
                            <th width="8%">Result</th>
                            <th width="8%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_practical = 0;
                        $total_project = 0;
                        $total_oral = 0;
                        $total_overall = 0;
                        $practical_count = 0;
                        $project_count = 0;
                        $oral_count = 0;
                        
                        foreach ($enrollments as $index => $enrollment):
                            $enrollment_id = $enrollment['id'];
                            
                            $practical_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
                            $practical = 0;
                            if ($practical_query && $row = $practical_query->fetch_assoc()) {
                                $practical = $row['total'] ?? 0;
                            }
                            
                            $project = $enrollment['project_score'] ?? 0;
                            
                            $oral_query = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
                            $oral = 0;
                            if ($oral_query && $row = $oral_query->fetch_assoc()) {
                                $oral = $row['total'] ?? 0;
                            }
                            
                            $oral_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
                            $oral_max = 100;
                            if ($oral_max_query && $row = $oral_max_query->fetch_assoc()) {
                                $oral_max = $row['total'] ?? 100;
                            }
                            
                            $practical_max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
                            $practical_max = 100;
                            if ($practical_max_query && $row = $practical_max_query->fetch_assoc()) {
                                $practical_max = $row['total'] ?? 100;
                            }
                            
                            $project_total_max = $enrollment['project_total_max'] ?? 100;
                            
                            $practical_passing = $enrollment['practical_passing_percentage'] ?? 75;
                            $project_passing = $enrollment['project_passing_percentage'] ?? 75;
                            $oral_passing = $enrollment['oral_passing_percentage'] ?? 75;
                            
                            $total = $practical + $project + $oral;
                            $max_total = $practical_max + $project_total_max + $oral_max;
                            $percentage = $max_total > 0 ? round(($total / $max_total) * 100, 1) : 0;
                            
                            $practical_percentage = $practical_max > 0 ? ($practical / $practical_max) * 100 : 0;
                            $project_percentage = $project_total_max > 0 ? ($project / $project_total_max) * 100 : 0;
                            $oral_percentage = $oral_max > 0 ? ($oral / $oral_max) * 100 : 0;
                            
                            $practical_status = $practical_percentage >= $practical_passing ? 'PASS' : ($practical > 0 ? 'FAIL' : 'Pending');
                            $project_status = $project_percentage >= $project_passing ? 'PASS' : ($project > 0 ? 'FAIL' : 'Pending');
                            $oral_status = $oral_percentage >= $oral_passing ? 'PASS' : ($oral > 0 ? 'FAIL' : 'Pending');
                            
                            if ($practical > 0) { $total_practical += $practical; $practical_count++; }
                            if ($project > 0) { $total_project += $project; $project_count++; }
                            if ($oral > 0) { $total_oral += $oral; $oral_count++; }
                            $total_overall += $total;
                            
                            $result_class = '';
                            $result_text = '';
                            if ($enrollment['overall_result'] == 'Passed' || $enrollment['assessment_result'] == 'Passed') {
                                $result_class = 'badge-success';
                                $result_text = 'PASSED';
                            } elseif ($enrollment['overall_result'] == 'Failed' || $enrollment['assessment_result'] == 'Failed') {
                                $result_class = 'badge-danger';
                                $result_text = 'FAILED';
                            } else {
                                $result_class = 'badge-warning';
                                $result_text = 'PENDING';
                            }
                        ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $index + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                                <div style="font-size: 11px; color: #666;"><?php echo htmlspecialchars($enrollment['email']); ?></div>
                            </td>
                            <td style="text-align: center; font-weight: bold;"><?php echo $practical ?: '-'; ?></td>
                            <td style="text-align: center;">
                                <?php if ($practical_status == 'PASS'): ?>
                                    <span class="badge badge-success">PASS</span>
                                <?php elseif ($practical_status == 'FAIL'): ?>
                                    <span class="badge badge-danger">FAIL</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Pending</span>
                                <?php endif; ?>
                                <div class="percentage-badge">Target: <?php echo $practical_passing; ?>%</div>
                            </td>
                            <td style="text-align: center; font-weight: bold;"><?php echo $project ?: '-'; ?></td>
                            <td style="text-align: center;">
                                <?php if ($project_status == 'PASS'): ?>
                                    <span class="badge badge-success">PASS</span>
                                <?php elseif ($project_status == 'FAIL'): ?>
                                    <span class="badge badge-danger">FAIL</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Pending</span>
                                <?php endif; ?>
                                <div class="percentage-badge">Target: <?php echo $project_passing; ?>%</div>
                            </td>
                            <td style="text-align: center; font-weight: bold;"><?php echo $oral ? $oral . '/' . $oral_max : '-'; ?></td>
                            <td style="text-align: center;">
                                <?php if ($oral_status == 'PASS'): ?>
                                    <span class="badge badge-success">PASS</span>
                                <?php elseif ($oral_status == 'FAIL'): ?>
                                    <span class="badge badge-danger">FAIL</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Pending</span>
                                <?php endif; ?>
                                <div class="percentage-badge">Target: <?php echo $oral_passing; ?>%</div>
                            </td>
                            <td style="text-align: center; font-weight: bold;"><?php echo $total ?: '-'; ?></td>
                            <td style="text-align: center;">
                                <span class="badge <?php echo $result_class; ?>"><?php echo $result_text; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($practical > 0 && $project > 0 && $oral > 0): ?>
                                    <span class="badge badge-success">Complete</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (!empty($enrollments)): ?>
                        <tr style="background: #e8f5e9; font-weight: bold;">
                            <td colspan="2" style="text-align: right;">TOTALS / AVERAGES:</td>
                            <td style="text-align: center;"><?php echo $practical_count ? round($total_practical / $practical_count, 1) : 0; ?></td>
                            <td></td>
                            <td style="text-align: center;"><?php echo $project_count ? round($total_project / $project_count, 1) : 0; ?></td>
                            <td></td>
                            <td style="text-align: center;"><?php echo $oral_count ? round($total_oral / $oral_count, 1) : 0; ?></td>
                            <td></td>
                            <td style="text-align: center;"><?php echo $total_trainees ? round($total_overall / $total_trainees, 1) : 0; ?></td>
                            <td colspan="2"></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if (empty($enrollments)): ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc; margin-bottom: 10px;"></i>
                    <p>No approved trainees found for this program.</p>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <span class="close-modal" onclick="closeImageModal()">&times;</span>
        <div class="modal-content">
            <img id="modalImage" class="modal-image">
            <div id="modalCaption" class="modal-caption"></div>
            <div style="margin-top: 15px;">
                <a id="downloadLink" href="#" download class="btn btn-success">
                    <i class="fas fa-download"></i> Download Image
                </a>
            </div>
        </div>
    </div>

    <!-- Answer Modal -->
    <div id="answerModal" class="image-modal" style="z-index: 10000;">
        <span class="close-modal" onclick="closeAnswerModal()">&times;</span>
        <div class="modal-content">
            <div style="background: white; padding: 30px; border-radius: 10px; max-width: 600px; max-height: 80vh; overflow-y: auto;">
                <h3 style="margin-bottom: 20px; color: #17a2b8;">Full Answer</h3>
                <div id="fullAnswer" style="white-space: pre-wrap; line-height: 1.6;"></div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            window.location.href = '?program_id=<?php echo $program_id; ?>&tab=' + tabName;
        }
        
        // Program Skills Functions
        function addProgramSkill() {
            const container = document.getElementById('program-skills-container');
            const newRow = document.createElement('div');
            newRow.className = 'skill-row';
            newRow.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control program-skill-name" value="New Skill" placeholder="Skill name" style="flex: 2;">
                    <input type="number" class="form-control program-skill-max" value="20" min="1" max="100" style="flex: 1;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeProgramSkill(btn) {
            Swal.fire({
                title: 'Remove Skill?',
                text: 'Are you sure you want to remove this skill from the template?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, remove'
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.closest('.skill-row').remove();
                }
            });
        }
        
        function saveProgramSkills() {
            const skills = [];
            document.querySelectorAll('#program-skills-container .skill-row').forEach(row => {
                const nameInput = row.querySelector('.program-skill-name');
                const maxInput = row.querySelector('.program-skill-max');
                
                if (nameInput && maxInput && nameInput.value.trim() !== '') {
                    skills.push({
                        name: nameInput.value.trim(),
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
            form.action = 'bulk_comprehensive_assessment.php?program_id=<?php echo $program_id; ?>';
            
            addField(form, 'save_program_skills', '1');
            addField(form, 'program_skills', JSON.stringify(skills));
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Program Questions Functions
        function addProgramQuestion() {
            const container = document.getElementById('program-questions-container');
            const newRow = document.createElement('div');
            newRow.className = 'question-row';
            newRow.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control program-question" value="New Question" placeholder="Question" style="flex: 3;">
                    <input type="number" class="form-control program-question-max" value="25" min="1" max="100" style="flex: 1;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramQuestion(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeProgramQuestion(btn) {
            Swal.fire({
                title: 'Remove Question?',
                text: 'Are you sure you want to remove this question from the template?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, remove'
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.closest('.question-row').remove();
                }
            });
        }
        
        function saveProgramQuestions() {
            const questions = [];
            document.querySelectorAll('#program-questions-container .question-row').forEach(row => {
                const questionInput = row.querySelector('.program-question');
                const maxInput = row.querySelector('.program-question-max');
                
                if (questionInput && maxInput && questionInput.value.trim() !== '') {
                    questions.push({
                        question: questionInput.value.trim(),
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
            form.action = 'bulk_comprehensive_assessment.php?program_id=<?php echo $program_id; ?>';
            
            addField(form, 'save_program_questions', '1');
            addField(form, 'program_questions', JSON.stringify(questions));
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Update skill total for a trainee
        function updateSkillTotal(index) {
            let total = 0;
            const skillScores = {};
            
            document.querySelectorAll(`#skills-${index} .skill-score`).forEach(input => {
                const score = parseFloat(input.value) || 0;
                const skillId = input.dataset.skillId;
                total += score;
                skillScores[skillId] = { score: score };
            });
            
            document.getElementById(`practical-total-${index}`).textContent = total;
            document.getElementById(`skill-scores-${index}`).value = JSON.stringify(skillScores);
        }
        
        // Update project status with passing percentage
        function updateProjectStatus(input, index, passingPercentage) {
            const score = parseFloat(input.value) || 0;
            const statusDiv = document.getElementById('project-status-' + index);
            
            if (score >= passingPercentage) {
                statusDiv.innerHTML = '<span class="badge badge-success"><i class="fas fa-check"></i> PASS</span>';
            } else if (score > 0) {
                statusDiv.innerHTML = '<span class="badge badge-danger"><i class="fas fa-times"></i> FAIL</span>';
            } else {
                statusDiv.innerHTML = '';
            }
        }
        
        // Update oral status with passing percentage
        function updateOralStatus(input, index, maxScore, passingPercentage) {
            const score = parseFloat(input.value) || 0;
            const statusDiv = document.getElementById('oral-status-' + index);
            const passingScore = maxScore * (passingPercentage / 100);
            
            if (score >= passingScore) {
                statusDiv.innerHTML = '<span class="badge badge-success"><i class="fas fa-check"></i> PASS</span>';
            } else if (score > 0) {
                statusDiv.innerHTML = '<span class="badge badge-danger"><i class="fas fa-times"></i> FAIL</span>';
            } else {
                statusDiv.innerHTML = '';
            }
        }
        
        // Toggle visibility (individual)
        function toggleVisibility(type, enrollmentId, checkbox) {
            const newValue = checkbox.checked ? 1 : 0;
            const toggleType = type === 'project' ? 'toggle_project' : 'toggle_oral';
            
            fetch(`bulk_comprehensive_assessment.php?${toggleType}=1&enrollment_id=${enrollmentId}&set=${newValue}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const badge = checkbox.closest('td').querySelector('div:last-child');
                        if (badge) {
                            badge.innerHTML = newValue ? 'Visible' : 'Hidden';
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Updated!',
                            text: `Visibility ${newValue ? 'enabled' : 'disabled'} for this trainee.`,
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Image modal functions
        function showImageModal(imageSrc, title) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('modalCaption').innerHTML = title || 'Project Image';
            document.getElementById('downloadLink').href = imageSrc;
            document.getElementById('imageModal').style.display = 'block';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
        }
        
        // Answer modal functions
        function showFullAnswer(index, qIndex) {
            const answerDiv = document.getElementById(`answer-${index}-${qIndex}`);
            if (answerDiv) {
                document.getElementById('fullAnswer').innerHTML = answerDiv.innerHTML;
                document.getElementById('answerModal').style.display = 'block';
            }
        }
        
        function closeAnswerModal() {
            document.getElementById('answerModal').style.display = 'none';
        }
        
        // Helper function to add form fields
        function addField(form, name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }
        
        // Rubric management functions
        let rubricCriterionCount = <?php echo count($default_rubrics_data); ?>;
        
        function addRubricCriterion() {
            rubricCriterionCount++;
            const container = document.getElementById('rubric-criteria-container');
            const el = document.createElement('div');
            el.className = 'rubric-criterion';
            el.setAttribute('data-criterion-index', rubricCriterionCount - 1);
            el.style.cssText = 'background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin-bottom: 20px; border-radius: 8px;';
            el.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0; color: #007bff;">
                        <i class="fas fa-check-circle"></i> Criterion ${rubricCriterionCount}:
                        <input type="text" class="form-control criterion-name"
                               style="display: inline-block; width: auto; min-width: 200px; margin-left: 10px;"
                               value="New Criterion"
                               placeholder="Criterion name">
                    </h4>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRubricCriterion(this)">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Max Points: </label>
                    <input type="number" class="form-control rubric-max-score"
                           style="width: 100px; display: inline-block;"
                           value="20" min="1" max="1000" step="1" onchange="updateRubricTotal()">
                </div>
                <div class="rubric-score-display" style="font-size: 12px; color: #666;">
                    Score will be entered per trainee in the section below
                </div>`;
            container.appendChild(el);
            updateRubricTotal();
        }
        
        function removeRubricCriterion(btn) {
            Swal.fire({
                title: 'Remove Criterion?',
                text: 'This will remove this criterion from the rubric for ALL trainees.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, remove'
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.closest('.rubric-criterion')?.remove();
                    document.querySelectorAll('.rubric-criterion').forEach((c, idx) => {
                        const h4 = c.querySelector('h4');
                        if (h4) {
                            const text = h4.innerHTML;
                            h4.innerHTML = text.replace(/Criterion \d+:/, `Criterion ${idx + 1}:`);
                        }
                        c.setAttribute('data-criterion-index', idx);
                    });
                    updateRubricTotal();
                }
            });
        }
        
        function getRubricData() {
            return Array.from(document.querySelectorAll('.rubric-criterion')).map((c, idx) => ({
                name: c.querySelector('.criterion-name')?.value || `Criterion ${idx + 1}`,
                max_score: parseFloat(c.querySelector('.rubric-max-score')?.value) || 0,
                score: 0
            }));
        }
        
        function updateRubricTotal() {
            let totalMax = 0;
            document.querySelectorAll('.rubric-criterion .rubric-max-score').forEach(i => {
                totalMax += parseFloat(i.value) || 0;
            });
            const el = document.getElementById('rubric-max-total');
            if (el) el.textContent = totalMax.toFixed(1);
        }
        
        function saveBulkProjectSetup() {
            const projectTitle = document.getElementById('project_title_override')?.value || '';
            const projectInstruction = document.getElementById('project_instruction')?.value || '';
            const rubrics = getRubricData();
            
            if (rubrics.length === 0) {
                Swal.fire('Error', 'Please add at least one rubric criterion.', 'warning');
                return;
            }
            
            const totalMax = rubrics.reduce((s, c) => s + c.max_score, 0);
            
            Swal.fire({
                title: 'Apply to All Trainees?',
                html: `This will apply the following project setup to ALL trainees:<br><br>
                       <strong>Title:</strong> ${escapeHtml(projectTitle) || '(None)'}<br>
                       <strong>Rubric:</strong> ${rubrics.length} criteria, ${totalMax} total points<br><br>
                       Existing project data will be overwritten.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Apply',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Applying...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'bulk_comprehensive_assessment.php?program_id=<?php echo $program_id; ?>';
                    
                    addField(form, 'save_bulk_project_setup', '1');
                    addField(form, 'project_title_override', projectTitle);
                    addField(form, 'project_instruction', projectInstruction);
                    addField(form, 'rubrics_data', JSON.stringify(rubrics));
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Validate and submit passing percentages
        function validatePassingPercentages() {
            const practical = parseFloat(document.getElementById('practical_passing_percentage').value);
            const project = parseFloat(document.getElementById('project_passing_percentage').value);
            const oral = parseFloat(document.getElementById('oral_passing_percentage').value);
            
            let isValid = true;
            let errorMessage = '';
            
            if (practical < 65 || practical > 100) {
                isValid = false;
                errorMessage += 'Practical passing percentage must be between 65% and 100%. ';
            }
            if (project < 65 || project > 100) {
                isValid = false;
                errorMessage += 'Project passing percentage must be between 65% and 100%. ';
            }
            if (oral < 65 || oral > 100) {
                isValid = false;
                errorMessage += 'Oral passing percentage must be between 65% and 100%. ';
            }
            
            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Percentage',
                    text: errorMessage,
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            Swal.fire({
                title: 'Apply to All Trainees?',
                html: `This will set the following passing percentages for ALL trainees:<br><br>
                       <strong>Practical:</strong> ${practical}%<br>
                       <strong>Project:</strong> ${project}%<br>
                       <strong>Oral:</strong> ${oral}%<br><br>
                       This will affect the pass/fail status for all components.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Apply',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('passingPercentagesForm').submit();
                }
            });
            
            return false;
        }
        
        // Escape HTML helper
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Print Summary Function
        function printSummary() {
            const printWindow = window.open('', '_blank');
            const today = new Date();
            const formattedDate = today.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            const programName = '<?php echo htmlspecialchars($program['name']); ?>';
            const table = document.querySelector('#summaryTable table').cloneNode(true);
            
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Assessment Summary - ${programName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.4; }
                        .print-header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #333; }
                        .print-header h1 { color: #333; margin-bottom: 10px; font-size: 24px; }
                        .print-header h2 { color: #666; margin-bottom: 5px; font-size: 18px; }
                        .print-header .date { color: #888; font-size: 14px; }
                        .stats-summary { display: flex; justify-content: space-around; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; }
                        .stat-item { text-align: center; }
                        .stat-number { font-size: 24px; font-weight: bold; }
                        .stat-label { font-size: 12px; opacity: 0.9; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
                        th { background: #333; color: white; padding: 10px; text-align: left; font-size: 12px; }
                        td { padding: 8px; border: 1px solid #ddd; }
                        tr:nth-child(even) { background: #f9f9f9; }
                        .badge { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block; }
                        .badge-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                        .badge-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                        .badge-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
                        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #666; }
                        .signature { margin-top: 50px; display: flex; justify-content: space-between; }
                        .signature-line { width: 200px; border-top: 1px solid #333; margin-top: 40px; text-align: center; }
                        @media print { body { margin: 0.5in; } }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>COMPREHENSIVE ASSESSMENT SUMMARY</h1>
                        <h2>${programName}</h2>
                        <div class="date">Date Printed: ${formattedDate}</div>
                    </div>
                    
                    <div class="stats-summary">
                        <div class="stat-item"><div class="stat-number"><?php echo $total_trainees; ?></div><div class="stat-label">Total Trainees</div></div>
                        <div class="stat-item"><div class="stat-number"><?php echo $passed_count; ?></div><div class="stat-label">Passed</div></div>
                        <div class="stat-item"><div class="stat-number"><?php echo $failed_count; ?></div><div class="stat-label">Failed</div></div>
                        <div class="stat-item"><div class="stat-number"><?php echo $pending_count; ?></div><div class="stat-label">Pending</div></div>
                        <div class="stat-item"><div class="stat-number"><?php echo $completion_rate; ?>%</div><div class="stat-label">Completion Rate</div></div>
                    </div>
                    
                    ${table.outerHTML}
                    
                    <div class="footer"><p>This is a system-generated summary report. All scores are final and subject to validation.</p></div>
                    <div class="signature">
                        <div><div class="signature-line"></div><p>Assessed By: _________________________</p></div>
                        <div><div class="signature-line"></div><p>Date: _________________________</p></div>
                    </div>
                    <script>window.onload = function() { window.print(); window.onafterprint = function() { window.close(); } }<\/script>
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
        }
        
        // Initialize all skill totals on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($enrollments_with_details as $index => $enrollment): ?>
                updateSkillTotal(<?php echo $index; ?>);
            <?php endforeach; ?>
            
            updateRubricTotal();
            
            const passingForm = document.getElementById('passingPercentagesForm');
            if (passingForm) {
                passingForm.onsubmit = function(e) {
                    e.preventDefault();
                    validatePassingPercentages();
                    return false;
                };
            }
            
            <?php if ($current_tab == 'summary' && !empty($enrollments)): ?>
            setTimeout(function() { location.reload(); }, 30000);
            <?php endif; ?>
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const imageModal = document.getElementById('imageModal');
            const answerModal = document.getElementById('answerModal');
            if (event.target == imageModal) imageModal.style.display = 'none';
            if (event.target == answerModal) answerModal.style.display = 'none';
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeImageModal();
                closeAnswerModal();
            }
        });
    </script>
</body>
</html>