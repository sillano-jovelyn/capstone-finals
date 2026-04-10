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

function sanitizeInstruction($input) {
    if (empty($input)) return '';
    $input = str_replace(['/n', '/r', '/t', '//n', '//r', '//t'], ["\n", "\r", "\t", "\n", "\r", "\t"], $input);
    return $input;
}

function formatForDisplay($text) {
    if (empty($text)) return '';
    return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
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

// GET FILTER PARAMETERS
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'all';
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'practical';

// ============================================================
// FIX 1: FETCH ALL TRAINEES WITH THE SAME PROGRAM
// Includes ALL enrollment statuses: approved, completed, pending,
// revision_needed, rejected, empty string, NULL, Ongoing, failed, etc.
// ============================================================
$enrollments = $conn->query("
    SELECT e.*, t.fullname, t.firstname, t.lastname, t.email, t.contact_number,
           ac.id as assessment_id, ac.practical_score, ac.project_score,
           ac.project_visible_to_trainee,
           ac.project_submitted_by_trainee,
           ac.project_title, ac.project_description, ac.project_photo_path,
           ac.practical_notes, ac.project_notes,
           ac.practical_passed, ac.project_passed,
           ac.overall_result as assessment_result, ac.overall_total_score,
           ac.practical_date,
           ac.project_submitted_at,
           ac.is_finalized, ac.assessed_by, ac.assessed_at,
           ac.practical_skills_saved,
           ac.practical_passing_percentage, ac.project_passing_percentage,
           ac.project_title_override, ac.project_instruction, ac.project_rubrics, ac.project_total_max,
           ac.practical_max_score, ac.project_max_score
    FROM enrollments e
    JOIN trainees t ON e.user_id = t.user_id
    LEFT JOIN assessment_components ac ON e.id = ac.enrollment_id
    WHERE e.program_id = $program_id
    ORDER BY t.fullname ASC
")->fetch_all(MYSQLI_ASSOC);

// GET PROGRAM SKILLS TEMPLATE
$program_skills = $conn->query("SELECT * FROM program_practical_skills WHERE program_id = $program_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);
$program_questions = $conn->query("SELECT * FROM program_oral_questions WHERE program_id = $program_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);

$program_skills_exist = count($program_skills) > 0;
$program_questions_exist = count($program_questions) > 0;

// GET TRAINEE-SPECIFIC SKILLS AND QUESTIONS FOR EACH TRAINEE
$enrollments_with_details_raw = [];
foreach ($enrollments as $enrollment) {
    $enrollment_id = $enrollment['id'];
    
    $skills_result = $conn->query("SELECT * FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id ORDER BY order_index");
    $trainee_skills = $skills_result ? $skills_result->fetch_all(MYSQLI_ASSOC) : [];
    
    $questions_result = $conn->query("SELECT * FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id ORDER BY order_index");
    $trainee_questions = $questions_result ? $questions_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Calculate practical status
    $practical_total = 0;
    $practical_max = 0;
    $filled_scores = 0;
    $total_skills = count($trainee_skills);
    
    foreach ($trainee_skills as $skill) {
        $practical_max += $skill['max_score'];
        if ($skill['score'] !== null && $skill['score'] !== '') {
            $practical_total += $skill['score'];
            $filled_scores++;
        }
    }

    // FIX 2: If no trainee_practical_skills rows exist, fall back to saved practical_score
    if ($practical_max == 0 && !empty($enrollment['practical_score'])) {
        $practical_total = floatval($enrollment['practical_score']);
        $practical_max   = intval($enrollment['practical_max_score'] ?? 100);
    }
    
    $trainee_practical_passing = floatval($enrollment['practical_passing_percentage'] ?? 75);
    $practical_percentage = $practical_max > 0 ? ($practical_total / $practical_max) * 100 : 0;
    $all_scores_filled = ($total_skills > 0 && $filled_scores == $total_skills);
    
    if ($total_skills == 0) {
        $practical_status = 'no_skills';
    } elseif (!$all_scores_filled) {
        $practical_status = 'incomplete';
    } elseif ($practical_percentage >= $trainee_practical_passing) {
        $practical_status = 'passed';
    } else {
        $practical_status = 'failed';
    }
    
    // Calculate project status
    $has_submission = $enrollment['project_submitted_by_trainee'];
    $project_passing = floatval($enrollment['project_passing_percentage'] ?? 75);
    $project_total = 0;
    $project_max = 0;
    $project_filled_scores = 0;
    $total_criteria = 0;
    
    if (!empty($enrollment['project_rubrics'])) {
        $trainee_rubrics = json_decode($enrollment['project_rubrics'], true);
        if (is_array($trainee_rubrics)) {
            $total_criteria = count($trainee_rubrics);
            foreach ($trainee_rubrics as $criterion) {
                $project_max += floatval($criterion['max_score'] ?? 0);
                $score = $criterion['score'] ?? null;
                if ($score !== null && $score !== '') {
                    $project_total += floatval($score);
                    $project_filled_scores++;
                }
            }
        }
    }

    // FIX 2b: Fall back to saved project_score if rubrics give nothing
    if ($project_max == 0 && floatval($enrollment['project_score'] ?? 0) > 0) {
        $project_total = floatval($enrollment['project_score']);
        $project_max   = intval($enrollment['project_total_max'] ?? $enrollment['project_max_score'] ?? 95);
    }
    
    $project_percentage = $project_max > 0 ? ($project_total / $project_max) * 100 : 0;
    $all_project_scores_filled = ($total_criteria > 0 && $project_filled_scores == $total_criteria);
    
    if (!$has_submission) {
        $project_status = 'no_submission';
    } elseif (!$all_project_scores_filled) {
        $project_status = 'incomplete';
    } elseif ($project_percentage >= $project_passing) {
        $project_status = 'passed';
    } else {
        $project_status = 'failed';
    }
    
    $enrollment['practical_status']     = $practical_status;
    $enrollment['project_status']       = $project_status;
    $enrollment['trainee_skills']       = $trainee_skills;
    $enrollment['trainee_questions']    = $trainee_questions;
    $enrollment['practical_total']      = $practical_total;
    $enrollment['practical_max']        = $practical_max;
    $enrollment['practical_percentage'] = $practical_percentage;
    $enrollment['project_total']        = $project_total;
    $enrollment['project_max']          = $project_max;
    $enrollment['project_percentage']   = $project_percentage;
    
    $enrollments_with_details_raw[] = $enrollment;
}

// APPLY FILTER
$enrollments_with_details = [];
$filter_counts = [
    'all'                  => count($enrollments_with_details_raw),
    'failed_practical'     => 0,
    'failed_project'       => 0,
    'failed_overall'       => 0,
    'incomplete_practical' => 0,
    'incomplete_project'   => 0,
    'passed_practical'     => 0,
    'passed_project'       => 0,
    'pending_submission'   => 0,
];

foreach ($enrollments_with_details_raw as $enrollment) {
    if ($enrollment['practical_status'] == 'failed')    $filter_counts['failed_practical']++;
    if ($enrollment['project_status'] == 'failed')      $filter_counts['failed_project']++;
    if ($enrollment['assessment_result'] == 'Failed')   $filter_counts['failed_overall']++;
    if ($enrollment['practical_status'] == 'incomplete') $filter_counts['incomplete_practical']++;
    if ($enrollment['project_status'] == 'incomplete')  $filter_counts['incomplete_project']++;
    if ($enrollment['practical_status'] == 'passed')    $filter_counts['passed_practical']++;
    if ($enrollment['project_status'] == 'passed')      $filter_counts['passed_project']++;
    if ($enrollment['project_status'] == 'no_submission') $filter_counts['pending_submission']++;
    
    $include = false;
    if ($filter_status == 'all') {
        $include = true;
    } elseif ($current_tab == 'practical') {
        switch($filter_status) {
            case 'failed_practical':    $include = ($enrollment['practical_status'] == 'failed'); break;
            case 'incomplete_practical': $include = ($enrollment['practical_status'] == 'incomplete' || $enrollment['practical_status'] == 'no_skills'); break;
            case 'passed_practical':    $include = ($enrollment['practical_status'] == 'passed'); break;
            default: $include = true;
        }
    } elseif ($current_tab == 'project') {
        switch($filter_status) {
            case 'failed_project':      $include = ($enrollment['project_status'] == 'failed'); break;
            case 'incomplete_project':  $include = ($enrollment['project_status'] == 'incomplete'); break;
            case 'passed_project':      $include = ($enrollment['project_status'] == 'passed'); break;
            case 'pending_submission':  $include = ($enrollment['project_status'] == 'no_submission'); break;
            default: $include = true;
        }
    } elseif ($current_tab == 'summary') {
        switch($filter_status) {
            case 'failed_overall':  $include = ($enrollment['assessment_result'] == 'Failed'); break;
            case 'passed_overall':  $include = ($enrollment['assessment_result'] == 'Passed'); break;
            case 'pending_overall': $include = ($enrollment['assessment_result'] != 'Passed' && $enrollment['assessment_result'] != 'Failed'); break;
            default: $include = true;
        }
    } else {
        $include = true;
    }
    
    if ($include) $enrollments_with_details[] = $enrollment;
}

// ============================================================
// PROCESS BULK ACTIONS
// ============================================================

if (isset($_POST['load_skills_to_all'])) {
    if ($program_skills_exist) {
        $success_count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment_id = $enrollment['id'];
            $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            $stmt = $conn->prepare("INSERT INTO trainee_practical_skills (enrollment_id, skill_name, max_score, order_index, score) VALUES (?, ?, ?, ?, NULL)");
            foreach ($program_skills as $index => $skill) {
                $skill_name = sanitizeInstruction($skill['skill_name']);
                $max_score  = $skill['max_score'];
                if ($max_score <= 0) $max_score = 1;
                $stmt->bind_param("isii", $enrollment_id, $skill_name, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET practical_skills_saved=1, practical_score=0, practical_passed=0 WHERE enrollment_id=$enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components (enrollment_id, practical_skills_saved, practical_score, practical_passed, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,1,0,0,75,75)");
            }
            $success_count++;
        }
        $_SESSION['message'] = "$success_count trainee(s) - Skills loaded successfully!";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'No program skills found. Please add skills first.';
        $_SESSION['message_type'] = 'warning';
    }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical&filter_status=$filter_status");
    exit;
}

if (isset($_POST['load_questions_to_all'])) {
    if ($program_questions_exist) {
        $success_count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment_id = $enrollment['id'];
            $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
            $stmt = $conn->prepare("INSERT INTO trainee_oral_questions (enrollment_id, question, max_score, order_index, score) VALUES (?, ?, ?, ?, NULL)");
            foreach ($program_questions as $index => $q) {
                $question  = sanitizeInstruction($q['question']);
                $max_score = $q['max_score'];
                if ($max_score <= 0) $max_score = 1;
                $stmt->bind_param("isii", $enrollment_id, $question, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
            $total_max = 0;
            foreach ($program_questions as $q) $total_max += $q['max_score'];
            $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE assessment_components SET oral_questions_saved=1, oral_max_score=$total_max, oral_questions_set=1, oral_score=0, oral_passed=0 WHERE enrollment_id=$enrollment_id");
            } else {
                $conn->query("INSERT INTO assessment_components (enrollment_id, oral_questions_saved, oral_max_score, oral_questions_set, oral_questions_visible_to_trainee, project_visible_to_trainee, oral_score, oral_passed, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,1,$total_max,1,0,0,0,0,75,75)");
            }
            $success_count++;
        }
        $_SESSION['message'] = "$success_count trainee(s) - Questions loaded successfully!";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'No program questions found. Please add questions first.';
        $_SESSION['message_type'] = 'warning';
    }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_bulk_passing_percentages'])) {
    $practical_passing = floatval($_POST['practical_passing_percentage'] ?? 75);
    $project_passing   = floatval($_POST['project_passing_percentage'] ?? 75);
    $validation_errors = [];
    if ($practical_passing < 65) { $validation_errors[] = "Practical % cannot be less than 65%"; $practical_passing = 65; }
    elseif ($practical_passing > 100) { $validation_errors[] = "Practical % cannot exceed 100%"; $practical_passing = 100; }
    if ($project_passing < 65) { $validation_errors[] = "Project % cannot be less than 65%"; $project_passing = 65; }
    elseif ($project_passing > 100) { $validation_errors[] = "Project % cannot exceed 100%"; $project_passing = 100; }
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET practical_passing_percentage=$practical_passing, project_passing_percentage=$project_passing WHERE enrollment_id=$enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components (enrollment_id, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,$practical_passing,$project_passing)");
        }
        $updated++;
    }
    $message = "$updated trainee(s) - Passing percentages updated! (Practical: $practical_passing%, Project: $project_passing%)";
    if (!empty($validation_errors)) { $message = implode('<br>', $validation_errors) . "<br>" . $message; $_SESSION['message_type'] = 'warning'; }
    else { $_SESSION['message_type'] = 'success'; }
    $_SESSION['message'] = $message;
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=" . ($_POST['current_tab'] ?? 'practical') . "&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_bulk_project_setup'])) {
    $project_title_override = $conn->real_escape_string(sanitizeInstruction($_POST['project_title_override'] ?? ''));
    $project_instruction    = $conn->real_escape_string(sanitizeInstruction($_POST['project_instruction'] ?? ''));
    $rubrics_data = json_decode($_POST['rubrics_data'] ?? '[]', true);
    $project_total_max = 0;
    foreach ($rubrics_data as $criterion) {
        $max_score = floatval($criterion['max_score'] ?? 0);
        if ($max_score <= 0) $max_score = 1;
        $project_total_max += $max_score;
    }
    if ($project_total_max == 0) $project_total_max = 100;
    $rubrics_json = json_encode($rubrics_data);
    $safe_rubrics = $conn->real_escape_string($rubrics_json);
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $existing = $conn->query("SELECT project_title, project_description, project_photo_path, project_submitted_by_trainee, project_submitted_at, project_rubrics, project_score, project_passed FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();
        $existing_rubrics = [];
        if ($existing && !empty($existing['project_rubrics'])) {
            $existing_rubrics = json_decode($existing['project_rubrics'], true);
            if (!is_array($existing_rubrics)) $existing_rubrics = [];
        }
        $merged_rubrics = [];
        foreach ($rubrics_data as $new_criterion) {
            $found = false;
            foreach ($existing_rubrics as $ec) {
                if ($ec['name'] === $new_criterion['name']) {
                    $mc = $new_criterion;
                    $mc['score'] = (isset($ec['score']) && $ec['score'] !== null && $ec['score'] !== '') ? $ec['score'] : null;
                    $merged_rubrics[] = $mc;
                    $found = true;
                    break;
                }
            }
            if (!$found) { $new_criterion['score'] = null; $merged_rubrics[] = $new_criterion; }
        }
        $final_rubrics_json = json_encode($merged_rubrics);
        $safe_final_rubrics = $conn->real_escape_string($final_rubrics_json);
        $total_earned_score = 0; $total_max_score = 0; $has_any_score = false;
        foreach ($merged_rubrics as $criterion) {
            $total_max_score += floatval($criterion['max_score'] ?? 0);
            $score = $criterion['score'] ?? null;
            if ($score !== null && $score !== '') { $total_earned_score += floatval($score); $has_any_score = true; }
        }
        $passed = 0;
        if ($has_any_score && $total_max_score > 0) {
            $pq = $conn->query("SELECT project_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
            $passing_percentage = 75;
            if ($pq && $row = $pq->fetch_assoc()) $passing_percentage = $row['project_passing_percentage'] ?? 75;
            $percentage = ($total_earned_score / $total_max_score) * 100;
            $passed = ($percentage >= $passing_percentage) ? 1 : 0;
        } else {
            $passed = $existing['project_passed'] ?? 0;
        }
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET project_title_override='$project_title_override', project_instruction='$project_instruction', project_rubrics='$safe_final_rubrics', project_total_max=$total_max_score, project_score=$total_earned_score, project_passed=$passed WHERE enrollment_id=$enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components (enrollment_id, project_title_override, project_instruction, project_rubrics, project_total_max, project_score, project_passed, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,'$project_title_override','$project_instruction','$safe_final_rubrics',$total_max_score,$total_earned_score,$passed,75,75)");
        }
        $updated++;
    }
    $_SESSION['message'] = "$updated trainee(s) - Project setup updated! (Trainee submissions preserved)";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=project&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_bulk_rubric_scores'])) {
    $enrollment_ids    = $_POST['enrollment_id'] ?? [];
    $rubric_scores_data = $_POST['rubric_scores'] ?? [];
    $project_notes     = $_POST['project_notes'] ?? [];
    $updated = 0; $validation_errors = [];
    foreach ($enrollment_ids as $index => $enrollment_id) {
        if (isset($rubric_scores_data[$index])) {
            $rubric_scores = json_decode($rubric_scores_data[$index], true);
            $notes = $conn->real_escape_string(sanitizeInstruction($project_notes[$index] ?? ''));
            if (is_array($rubric_scores)) {
                $existing = $conn->query("SELECT project_rubrics, project_passing_percentage, project_passed FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();
                $rubrics_data = [];
                if ($existing && !empty($existing['project_rubrics'])) {
                    $rubrics_data = json_decode($existing['project_rubrics'], true);
                    if (!is_array($rubrics_data)) $rubrics_data = [];
                }
                $has_errors = false; $has_any_score = false;
                foreach ($rubric_scores as $criterion_index => $score) {
                    if (isset($rubrics_data[$criterion_index])) {
                        $score_val = ($score !== '' && $score !== null) ? floatval($score) : null;
                        $max_score = $rubrics_data[$criterion_index]['max_score'];
                        if ($score_val !== null) {
                            if ($score_val > $max_score) { $validation_errors[] = "Score exceeds max for: " . ($rubrics_data[$criterion_index]['name'] ?? 'Unnamed'); $has_errors = true; }
                            elseif ($score_val < 0) { $validation_errors[] = "Score cannot be negative for: " . ($rubrics_data[$criterion_index]['name'] ?? 'Unnamed'); $has_errors = true; }
                            else { $rubrics_data[$criterion_index]['score'] = $score_val; $has_any_score = true; }
                        } else { $rubrics_data[$criterion_index]['score'] = null; }
                    }
                }
                if ($has_errors) continue;
                $total_earned_score = 0; $total_max_score = 0; $all_scores_filled = true;
                foreach ($rubrics_data as $criterion) {
                    $total_max_score += floatval($criterion['max_score'] ?? 0);
                    if (isset($criterion['score']) && $criterion['score'] !== null && $criterion['score'] !== '') $total_earned_score += floatval($criterion['score']);
                    else $all_scores_filled = false;
                }
                $passed = 0;
                if ($has_any_score && $all_scores_filled && $total_max_score > 0) {
                    $passing_percentage = $existing['project_passing_percentage'] ?? 75;
                    $percentage = ($total_earned_score / $total_max_score) * 100;
                    $passed = ($percentage >= $passing_percentage) ? 1 : 0;
                } else { $passed = $existing['project_passed'] ?? 0; }
                $rubrics_json = json_encode($rubrics_data);
                $safe_rubrics = $conn->real_escape_string($rubrics_json);
                $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
                if ($check->num_rows > 0) {
                    $conn->query("UPDATE assessment_components SET project_rubrics='$safe_rubrics', project_score=$total_earned_score, project_total_max=$total_max_score, project_passed=$passed, project_notes='$notes' WHERE enrollment_id=$enrollment_id");
                } else {
                    $conn->query("INSERT INTO assessment_components (enrollment_id, project_rubrics, project_score, project_total_max, project_passed, project_notes, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,'$safe_rubrics',$total_earned_score,$total_max_score,$passed,'$notes',75,75)");
                }
                $updated++;
            }
        }
    }
    if (!empty($validation_errors)) { $_SESSION['message'] = implode(', ', $validation_errors); $_SESSION['message_type'] = 'danger'; }
    else { $_SESSION['message'] = "$updated trainee(s) - Rubric scores updated!"; $_SESSION['message_type'] = 'success'; }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=project&filter_status=$filter_status");
    exit;
}

if (isset($_POST['reset_all_skills'])) {
    $success_count = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET practical_skills_saved=0, practical_score=0, practical_passed=0, practical_notes=NULL WHERE enrollment_id=$enrollment_id");
        $success_count++;
    }
    $_SESSION['message'] = "$success_count trainee(s) - Skills reset successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical&filter_status=$filter_status");
    exit;
}

if (isset($_POST['reset_all_questions'])) {
    $success_count = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
        $conn->query("UPDATE assessment_components SET oral_questions_saved=0, oral_questions_set=0, oral_questions_finalized=0, oral_score=NULL, oral_passed=NULL, oral_notes=NULL, oral_answers=NULL, oral_submitted_by_trainee=0, oral_max_score=100 WHERE enrollment_id=$enrollment_id");
        $success_count++;
    }
    $_SESSION['message'] = "$success_count trainee(s) - Questions reset successfully!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_program_skills'])) {
    $skills_json = $_POST['program_skills'];
    $conn->query("DELETE FROM program_practical_skills WHERE program_id = $program_id");
    $skills = json_decode($skills_json, true);
    if (is_array($skills)) {
        $stmt = $conn->prepare("INSERT INTO program_practical_skills (program_id, skill_name, max_score, order_index) VALUES (?, ?, ?, ?)");
        foreach ($skills as $index => $skill) {
            $skill_name = sanitizeInstruction($skill['name']);
            $max_score  = intval($skill['max_score']);
            if ($max_score <= 0) $max_score = 1;
            $stmt->bind_param("isii", $program_id, $skill_name, $max_score, $index);
            $stmt->execute();
        }
    }
    $_SESSION['message'] = 'Program skills template saved! Use "Load Skills to All" to apply to trainees.';
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_program_questions'])) {
    $questions_json = $_POST['program_questions'];
    $conn->query("DELETE FROM program_oral_questions WHERE program_id = $program_id");
    $questions = json_decode($questions_json, true);
    if (is_array($questions)) {
        $stmt = $conn->prepare("INSERT INTO program_oral_questions (program_id, question, max_score, order_index) VALUES (?, ?, ?, ?)");
        foreach ($questions as $index => $q) {
            $question  = sanitizeInstruction($q['question']);
            $max_score = intval($q['max_score']);
            if ($max_score <= 0) $max_score = 1;
            $stmt->bind_param("isii", $program_id, $question, $max_score, $index);
            $stmt->execute();
        }
    }
    $_SESSION['message'] = 'Program questions template saved! Use "Load Questions to All" to apply to trainees.';
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=oral&filter_status=$filter_status");
    exit;
}

if (isset($_POST['save_bulk_practical_detailed'])) {
    $enrollment_ids   = $_POST['enrollment_id'] ?? [];
    $skill_scores_data = $_POST['skill_scores'] ?? [];
    $practical_notes  = $_POST['practical_notes'] ?? [];
    $practical_date   = $_POST['practical_date'] ?? date('Y-m-d');
    $updated = 0; $validation_errors = [];
    foreach ($enrollment_ids as $index => $enrollment_id) {
        if (isset($skill_scores_data[$index])) {
            $skill_scores = json_decode($skill_scores_data[$index], true);
            $notes = $conn->real_escape_string(sanitizeInstruction($practical_notes[$index] ?? ''));
            if (is_array($skill_scores)) {
                $has_errors = false;
                foreach ($skill_scores as $skill_id => $grade_data) {
                    if (strpos($skill_id, 'skill_') === 0) {
                        $skill_db_id = str_replace('skill_', '', $skill_id);
                        $score = is_array($grade_data) ? ($grade_data['score'] ?? null) : $grade_data;
                        $max_check = $conn->query("SELECT max_score, skill_name FROM trainee_practical_skills WHERE id = $skill_db_id AND enrollment_id = $enrollment_id");
                        if ($max_check && $max_row = $max_check->fetch_assoc()) {
                            $max_score = $max_row['max_score'];
                            $skill_name = $max_row['skill_name'];
                            if ($score !== null && $score !== '') {
                                $score_val = floatval($score);
                                if ($score_val > $max_score) { $validation_errors[] = "Score ($score_val) exceeds max ($max_score) for: $skill_name"; $has_errors = true; }
                                elseif ($score_val < 0) { $validation_errors[] = "Score cannot be negative for: $skill_name"; $has_errors = true; }
                                else $conn->query("UPDATE trainee_practical_skills SET score=$score_val WHERE id=$skill_db_id AND enrollment_id=$enrollment_id");
                            } else {
                                $conn->query("UPDATE trainee_practical_skills SET score=NULL WHERE id=$skill_db_id AND enrollment_id=$enrollment_id");
                            }
                        }
                    }
                }
                if ($has_errors) continue;
                $total_query = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
                $practical_total = 0;
                if ($total_query && $row = $total_query->fetch_assoc()) $practical_total = $row['total'] ?? 0;
                $passing_query = $conn->query("SELECT practical_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
                $passing_percentage = 75;
                if ($passing_query && $row = $passing_query->fetch_assoc()) $passing_percentage = $row['practical_passing_percentage'] ?? 75;
                $max_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
                $max_total = 0;
                if ($max_query && $row = $max_query->fetch_assoc()) $max_total = $row['total'] ?? 0;
                $percentage = $max_total > 0 ? ($practical_total / $max_total) * 100 : 0;
                $practical_passed = ($percentage >= $passing_percentage) ? 1 : 0;
                $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
                if ($check->num_rows > 0) {
                    $conn->query("UPDATE assessment_components SET practical_score=$practical_total, practical_passed=$practical_passed, practical_notes='$notes', practical_date='$practical_date' WHERE enrollment_id=$enrollment_id");
                } else {
                    $conn->query("INSERT INTO assessment_components (enrollment_id, practical_score, practical_passed, practical_notes, practical_date, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,$practical_total,$practical_passed,'$notes','$practical_date',75,75)");
                }
                $updated++;
            }
        }
    }
    if (!empty($validation_errors)) { $_SESSION['message'] = implode(', ', $validation_errors); $_SESSION['message_type'] = 'danger'; }
    else { $_SESSION['message'] = "$updated trainee(s) - Practical scores updated!"; $_SESSION['message_type'] = 'success'; }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=practical&filter_status=$filter_status");
    exit;
}

if (isset($_POST['calculate_all_results'])) {
    $updated = 0; $passed_count = 0; $failed_count = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $pq = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical = 0;
        if ($pq && $row = $pq->fetch_assoc()) $practical = $row['total'] ?? 0;
        // FIX: also fall back to saved practical_score
        if ($practical == 0 && !empty($enrollment['practical_score'])) $practical = floatval($enrollment['practical_score']);

        $proj_q = $conn->query("SELECT project_score FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project = 0;
        if ($proj_q && $row = $proj_q->fetch_assoc()) $project = $row['project_score'] ?? 0;

        $pm_q = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        $practical_max = 100;
        if ($pm_q && $row = $pm_q->fetch_assoc()) $practical_max = $row['total'] ?? 100;
        if ($practical_max == 0) $practical_max = intval($enrollment['practical_max_score'] ?? 100);

        $jm_q = $conn->query("SELECT project_total_max FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $project_max = 100;
        if ($jm_q && $row = $jm_q->fetch_assoc()) $project_max = $row['project_total_max'] ?? 100;
        if ($project_max == 0) $project_max = intval($enrollment['project_max_score'] ?? 95);

        $pass_q = $conn->query("SELECT practical_passing_percentage, project_passing_percentage FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $practical_passing = 75; $project_passing = 75;
        if ($pass_q && $row = $pass_q->fetch_assoc()) {
            $practical_passing = $row['practical_passing_percentage'] ?? 75;
            $project_passing   = $row['project_passing_percentage'] ?? 75;
        }
        $total = $practical + $project;
        $max_total = $practical_max + $project_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;
        $total_weight = $practical_max + $project_max;
        $overall_passing_percentage = $total_weight > 0 ? ($practical_max * $practical_passing + $project_max * $project_passing) / $total_weight : 75;
        $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';
        if ($overall_result == 'Passed') $passed_count++; else $failed_count++;
        $conn->query("UPDATE assessment_components SET overall_total_score=$total, overall_result='$overall_result', assessed_by='$fullname', assessed_at=NOW(), is_finalized=1 WHERE enrollment_id=$enrollment_id");
        $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
        $conn->query("UPDATE enrollments SET enrollment_status='$enrollment_status', overall_result='$overall_result', completed_at=NOW(), assessment='$overall_result', assessed_by='$fullname', assessed_at=NOW() WHERE id=$enrollment_id");
        $updated++;
    }
    $_SESSION['message'] = "$updated trainee(s) processed - $passed_count Passed, $failed_count Failed.";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=summary&filter_status=$filter_status");
    exit;
}

if (isset($_POST['finalize_all_completed'])) {
    $updated = 0; $passed_count = 0; $failed_count = 0;
    $conn->begin_transaction();
    try {
        foreach ($enrollments as $enrollment) {
            $enrollment_id = $enrollment['id'];

            $ps = $conn->prepare("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = ?");
            $ps->bind_param("i", $enrollment_id); $ps->execute();
            $pr = $ps->get_result(); $practical = 0;
            if ($row = $pr->fetch_assoc()) $practical = $row['total'] ?? 0;
            $ps->close();
            // FIX: fall back to saved practical_score
            if ($practical == 0 && !empty($enrollment['practical_score'])) $practical = floatval($enrollment['practical_score']);

            $pjs = $conn->prepare("SELECT project_score FROM assessment_components WHERE enrollment_id = ?");
            $pjs->bind_param("i", $enrollment_id); $pjs->execute();
            $pjr = $pjs->get_result(); $project = 0;
            if ($row = $pjr->fetch_assoc()) $project = $row['project_score'] ?? 0;
            $pjs->close();

            $pms = $conn->prepare("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = ?");
            $pms->bind_param("i", $enrollment_id); $pms->execute();
            $pmr = $pms->get_result(); $practical_max = 100;
            if ($row = $pmr->fetch_assoc()) $practical_max = $row['total'] ?? 100;
            $pms->close();
            if ($practical_max == 0) $practical_max = intval($enrollment['practical_max_score'] ?? 100);

            $jms = $conn->prepare("SELECT project_total_max FROM assessment_components WHERE enrollment_id = ?");
            $jms->bind_param("i", $enrollment_id); $jms->execute();
            $jmr = $jms->get_result(); $project_max = 100;
            if ($row = $jmr->fetch_assoc()) $project_max = $row['project_total_max'] ?? 100;
            $jms->close();
            if ($project_max == 0) $project_max = intval($enrollment['project_max_score'] ?? 95);

            $has_practical_scores = $practical > 0;
            $has_project_scores   = $project > 0;

            if ($has_practical_scores && $has_project_scores) {
                $total = $practical + $project;
                $max_total = $practical_max + $project_max;
                $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;

                $pass_s = $conn->prepare("SELECT practical_passing_percentage, project_passing_percentage FROM assessment_components WHERE enrollment_id = ?");
                $pass_s->bind_param("i", $enrollment_id); $pass_s->execute();
                $pass_r = $pass_s->get_result(); $practical_passing = 75; $project_passing = 75;
                if ($row = $pass_r->fetch_assoc()) { $practical_passing = $row['practical_passing_percentage'] ?? 75; $project_passing = $row['project_passing_percentage'] ?? 75; }
                $pass_s->close();

                $total_weight = $practical_max + $project_max;
                $overall_passing_percentage = $total_weight > 0 ? ($practical_max * $practical_passing + $project_max * $project_passing) / $total_weight : 75;
                $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';
                if ($overall_result == 'Passed') $passed_count++; else $failed_count++;

                $ua = $conn->prepare("UPDATE assessment_components SET overall_total_score=?, overall_result=?, assessed_by=?, assessed_at=NOW(), is_finalized=1 WHERE enrollment_id=?");
                $ua->bind_param("issi", $total, $overall_result, $fullname, $enrollment_id);
                if (!$ua->execute()) throw new Exception("Failed to update assessment for enrollment ID: $enrollment_id");
                $ua->close();

                $enrollment_status = ($overall_result == 'Passed') ? 'completed' : 'failed';
                $ue = $conn->prepare("UPDATE enrollments SET enrollment_status=?, results=?, assessment=?, completed_at=NOW() WHERE id=?");
                $ue->bind_param("sssi", $enrollment_status, $overall_result, $overall_result, $enrollment_id);
                if (!$ue->execute()) throw new Exception("Failed to update enrollment for ID: $enrollment_id");
                $ue->close();
                $updated++;
            }
        }
        $conn->commit();
        $_SESSION['message'] = "$updated trainee(s) finalized - $passed_count Passed, $failed_count Failed!";
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=summary&filter_status=$filter_status");
    exit;
}

if (isset($_POST['toggle_all_visibility'])) {
    $type  = $_POST['visibility_type'];
    $value = intval($_POST['visibility_value']);
    $field = ($type === 'project') ? 'project_visible_to_trainee' : 'oral_questions_visible_to_trainee';
    $updated = 0;
    foreach ($enrollments as $enrollment) {
        $enrollment_id = $enrollment['id'];
        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE assessment_components SET $field=$value WHERE enrollment_id=$enrollment_id");
        } else {
            $conn->query("INSERT INTO assessment_components (enrollment_id, $field, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,$value,75,75)");
        }
        $updated++;
    }
    $status = $value ? 'visible' : 'hidden';
    $_SESSION['message'] = "$updated trainee(s) - $type now $status to trainees!";
    $_SESSION['message_type'] = 'success';
    header("Location: bulk_comprehensive_assessment.php?program_id=$program_id&tab=" . ($type === 'project' ? 'project' : 'oral') . "&filter_status=$filter_status");
    exit;
}

if (isset($_GET['toggle_project'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value     = isset($_GET['set']) ? intval($_GET['set']) : 1;
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) $conn->query("UPDATE assessment_components SET project_visible_to_trainee=$new_value WHERE enrollment_id=$enrollment_id");
    else $conn->query("INSERT INTO assessment_components (enrollment_id, project_visible_to_trainee, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,$new_value,75,75)");
    echo json_encode(['success' => true]); exit;
}

if (isset($_GET['toggle_oral'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    $new_value     = isset($_GET['set']) ? intval($_GET['set']) : 1;
    $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
    if ($check->num_rows > 0) $conn->query("UPDATE assessment_components SET oral_questions_visible_to_trainee=$new_value WHERE enrollment_id=$enrollment_id");
    else $conn->query("INSERT INTO assessment_components (enrollment_id, oral_questions_visible_to_trainee, practical_passing_percentage, project_passing_percentage) VALUES ($enrollment_id,$new_value,75,75)");
    echo json_encode(['success' => true]); exit;
}

// ============================================================
// PAGE DATA
// ============================================================
$message      = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

$default_project_title       = '';
$default_project_instruction = '';
$default_rubrics_data        = [];

if (!empty($enrollments)) {
    $first_enrollment = $enrollments[0];
    $setup_query = $conn->query("SELECT project_title_override, project_instruction, project_rubrics FROM assessment_components WHERE enrollment_id = {$first_enrollment['id']}");
    if ($setup_query && $row = $setup_query->fetch_assoc()) {
        $default_project_title       = $row['project_title_override'] ?? '';
        $default_project_instruction = $row['project_instruction'] ?? '';
        if (!empty($row['project_rubrics'])) {
            $default_rubrics_data = json_decode($row['project_rubrics'], true);
            if (!is_array($default_rubrics_data)) $default_rubrics_data = [];
            // Strip scores from default rubric display
            foreach ($default_rubrics_data as &$r) unset($r['score']);
            unset($r);
        }
    }
}

if (empty($default_rubrics_data)) {
    $default_rubrics_data = [
        ['name' => 'Content Quality',             'max_score' => 30],
        ['name' => 'Design & Creativity',          'max_score' => 25],
        ['name' => 'Technical Execution',          'max_score' => 25],
        ['name' => 'Presentation & Documentation', 'max_score' => 20],
    ];
}

$global_practical_passing = 75;
$global_project_passing   = 75;
if (!empty($enrollments)) {
    $first_enrollment = $enrollments[0];
    $ac_check = $conn->query("SELECT practical_passing_percentage, project_passing_percentage FROM assessment_components WHERE enrollment_id = {$first_enrollment['id']}");
    if ($ac_check && $row = $ac_check->fetch_assoc()) {
        $global_practical_passing = $row['practical_passing_percentage'] ?? 75;
        $global_project_passing   = $row['project_passing_percentage']   ?? 75;
    }
}

// STATISTICS
$total_trainees    = count($enrollments);
$passed_count      = 0; $failed_count = 0; $pending_count = 0;
$practical_completed = 0; $project_completed = 0;
$skills_loaded     = 0; $questions_loaded = 0;
$project_submitted = 0; $finalized_count  = 0;

foreach ($enrollments as $e) {
    $overall_result = $e['assessment_result'] ?? $e['overall_result'] ?? null;
    if ($overall_result == 'Passed')      $passed_count++;
    elseif ($overall_result == 'Failed')  $failed_count++;
    else                                   $pending_count++;

    $sc = $conn->query("SELECT COUNT(*) as cnt FROM trainee_practical_skills WHERE enrollment_id = {$e['id']}");
    if ($sc && $row = $sc->fetch_assoc()) if ($row['cnt'] > 0) $skills_loaded++;

    $qc = $conn->query("SELECT COUNT(*) as cnt FROM trainee_oral_questions WHERE enrollment_id = {$e['id']}");
    if ($qc && $row = $qc->fetch_assoc()) if ($row['cnt'] > 0) $questions_loaded++;

    if (!is_null($e['practical_score']) && $e['practical_score'] > 0) $practical_completed++;
    if (!is_null($e['project_score'])   && $e['project_score']   > 0) $project_completed++;
    if (!empty($e['project_submitted_by_trainee'])) $project_submitted++;
    if (!empty($e['is_finalized'])) $finalized_count++;
}
$completion_rate = $total_trainees > 0 ? round(($passed_count / $total_trainees) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
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
        .alert-info    { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .alert-danger  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 13px; color: #666; margin-bottom: 5px; }
        .stat-card .number { font-size: 24px; font-weight: 700; color: #667eea; }
        .stat-card .label  { font-size: 11px; color: #999; margin-top: 5px; }
        .filter-section { background: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filter-title   { font-weight: 600; margin-bottom: 10px; color: #333; display: flex; align-items: center; gap: 10px; }
        .filter-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-btn { padding: 8px 16px; border: 2px solid #e0e0e0; background: white; border-radius: 25px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s; }
        .filter-btn:hover { border-color: #667eea; background: #f8f9ff; }
        .filter-btn.active { background: #667eea; border-color: #667eea; color: white; }
        .filter-count { display: inline-block; margin-left: 8px; padding: 2px 6px; border-radius: 12px; font-size: 11px; background: rgba(0,0,0,0.1); }
        .filter-btn.active .filter-count { background: rgba(255,255,255,0.3); }
        /* enrollment status badge */
        .enroll-badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: 600; margin-top: 3px; }
        .enroll-approved  { background:#d4edda; color:#155724; }
        .enroll-completed { background:#cce5ff; color:#004085; }
        .enroll-pending   { background:#fff3cd; color:#856404; }
        .enroll-failed    { background:#f8d7da; color:#721c24; }
        .enroll-other     { background:#e2e3e5; color:#383d41; }
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
        .rubric-criterion-score { margin-bottom: 12px; padding: 8px; background: #f8f9fa; border-radius: 5px; }
        .rubric-criterion-score:hover { background: #e8f0fe !important; }
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
        .input-error { border-color: #dc3545 !important; background-color: #fff8f8 !important; }
        .btn { padding: 8px 15px; border: none; border-radius: 50px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s; }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .btn-success   { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-info      { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; }
        .btn-warning   { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger    { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success   { background: #d4edda; color: #155724; }
        .badge-danger    { background: #f8d7da; color: #721c24; }
        .badge-warning   { background: #fff3cd; color: #856404; }
        .badge-info      { background: #cce5ff; color: #004085; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .status-passed    { background: #d4edda; color: #155724; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .status-failed    { background: #f8d7da; color: #721c24; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .status-pending   { background: #fff3cd; color: #856404; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .status-incomplete { background: #cce5ff; color: #004085; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
        .visibility-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-visible { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-hidden  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .visibility-toggle-container { display: flex; flex-direction: column; align-items: center; gap: 8px; min-width: 80px; }
        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px; }
        .toggle-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }
        .back-link { display: inline-block; margin-bottom: 20px; color: #667eea; text-decoration: none; font-weight: 600; }
        .back-link i { margin-right: 5px; }
        .skill-row    { background: #f8f9fa; padding: 10px; margin-bottom: 5px; border-radius: 5px; border-left: 3px solid #28a745; }
        .question-row { background: #f8f9fa; padding: 10px; margin-bottom: 5px; border-radius: 5px; border-left: 3px solid #17a2b8; }
        .total-display { font-size: 18px; font-weight: 700; color: #667eea; margin-top: 5px; }
        .skill-detail-row { display: flex; gap: 10px; align-items: center; margin-bottom: 5px; padding: 5px; background: white; border-radius: 5px; }
        .submission-box { background: #e8f5e9; padding: 10px; border-radius: 8px; margin: 5px 0; border-left: 4px solid #28a745; }
        .pending-box    { background: #fff3cd; padding: 10px; border-radius: 8px; margin: 5px 0; border-left: 4px solid #ffc107; }
        .image-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); animation: fadeIn 0.3s; }
        .modal-content { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; }
        .modal-image   { max-width: 90%; max-height: 80%; object-fit: contain; border: 5px solid white; border-radius: 10px; }
        .modal-caption { margin-top: 20px; color: white; font-size: 18px; text-align: center; }
        .close-modal   { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .percentage-badge { font-size: 10px; padding: 2px 6px; border-radius: 12px; background: #e9ecef; color: #495057; display: inline-block; margin-top: 3px; }
        .finalized-badge  { background: #6f42c1; color: white; padding: 3px 8px; border-radius: 20px; font-size: 11px; margin-top: 5px; }
        .score-input { width: 80px; text-align: center; padding: 8px; border: 2px solid #ffc107; border-radius: 5px; font-weight: 600; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @media screen and (max-width: 768px) {
            body { padding: 10px; }
            .header { padding: 20px 15px; } .header h1 { font-size: 20px; }
            .tabs { gap: 5px; } .tab { padding: 8px 15px; font-size: 12px; flex: 1; text-align: center; min-width: 80px; }
            table th, table td { padding: 8px; font-size: 12px; min-width: 100px; }
            .btn { padding: 6px 12px; font-size: 12px; }
        }
        @media print {
            .header, .tabs, .btn-group, .btn, .back-link, .bulk-actions, .program-section,
            .toggle-switch, .image-modal, .close-modal, .stats-grid,
            .bulk-actions, .program-section, .passing-settings, .project-setup-section,
            .visibility-toggle-container, .filter-section { display: none !important; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; margin: 0; padding: 20px; }
            .table-container { box-shadow: none; padding: 0; background: white; }
            table { border-collapse: collapse; width: 100%; border: 1px solid #000; font-size: 10pt; }
            th { background: #333 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            td, th { border: 1px solid #000; padding: 6px; }
        }
    </style>
</head>
<body>
<div class="container">
    <a href="trainer_participants.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Participants</a>

    <div class="header">
        <h1><i class="fas fa-users"></i> Bulk Comprehensive Assessment</h1>
        <div class="subtitle">
            <strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?> |
            <strong>Schedule:</strong> <?php echo date('M d, Y', strtotime($program['scheduleStart'])); ?> - <?php echo date('M d, Y', strtotime($program['scheduleEnd'])); ?>
            <span style="margin-left:20px; background:rgba(255,255,255,0.2); padding:5px 15px; border-radius:20px;">
                <i class="fas fa-users"></i> <?php echo $total_trainees; ?> Total Trainees
            </span>
            <?php if ($finalized_count > 0): ?>
            <span style="margin-left:10px; background:rgba(255,255,255,0.2); padding:5px 15px; border-radius:20px;">
                <i class="fas fa-check-circle"></i> <?php echo $finalized_count; ?>/<?php echo $total_trainees; ?> Finalized
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <i class="fas fa-info-circle"></i> <?php echo $message; ?>
    </div>
    <?php endif; ?>

   

    <!-- FILTER -->
    <div class="filter-section">
        <div class="filter-title">
            <i class="fas fa-filter"></i> Filter Trainees
            <?php if ($filter_status != 'all'): ?>
            <a href="?program_id=<?php echo $program_id; ?>&tab=<?php echo $current_tab; ?>&filter_status=all" style="font-size:12px; margin-left:10px;">Clear Filter</a>
            <?php endif; ?>
        </div>
        <?php if ($current_tab == 'practical'): ?>
        <div class="filter-buttons">
            <button class="filter-btn <?php echo $filter_status=='all'?'active':''; ?>" onclick="applyFilter('all')">All Trainees <span class="filter-count"><?php echo $filter_counts['all']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='failed_practical'?'active':''; ?>" onclick="applyFilter('failed_practical')"><i class="fas fa-times-circle"></i> Failed <span class="filter-count"><?php echo $filter_counts['failed_practical']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='incomplete_practical'?'active':''; ?>" onclick="applyFilter('incomplete_practical')"><i class="fas fa-hourglass-half"></i> Incomplete <span class="filter-count"><?php echo $filter_counts['incomplete_practical']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='passed_practical'?'active':''; ?>" onclick="applyFilter('passed_practical')"><i class="fas fa-check-circle"></i> Passed <span class="filter-count"><?php echo $filter_counts['passed_practical']; ?></span></button>
        </div>
        <?php elseif ($current_tab == 'project'): ?>
        <div class="filter-buttons">
            <button class="filter-btn <?php echo $filter_status=='all'?'active':''; ?>" onclick="applyFilter('all')">All Trainees <span class="filter-count"><?php echo $filter_counts['all']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='failed_project'?'active':''; ?>" onclick="applyFilter('failed_project')"><i class="fas fa-times-circle"></i> Failed <span class="filter-count"><?php echo $filter_counts['failed_project']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='passed_project'?'active':''; ?>" onclick="applyFilter('passed_project')"><i class="fas fa-check-circle"></i> Passed <span class="filter-count"><?php echo $filter_counts['passed_project']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='pending_submission'?'active':''; ?>" onclick="applyFilter('pending_submission')"><i class="fas fa-clock"></i> No Submission <span class="filter-count"><?php echo $filter_counts['pending_submission']; ?></span></button>
        </div>
        <?php elseif ($current_tab == 'summary'): ?>
        <div class="filter-buttons">
            <button class="filter-btn <?php echo $filter_status=='all'?'active':''; ?>" onclick="applyFilter('all')">All Trainees <span class="filter-count"><?php echo $filter_counts['all']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='failed_overall'?'active':''; ?>" onclick="applyFilter('failed_overall')"><i class="fas fa-times-circle"></i> Failed <span class="filter-count"><?php echo $filter_counts['failed_overall']; ?></span></button>
            <button class="filter-btn <?php echo $filter_status=='passed_overall'?'active':''; ?>" onclick="applyFilter('passed_overall')"><i class="fas fa-check-circle"></i> Passed <span class="filter-count"><?php echo $passed_count; ?></span></button>
        </div>
        <?php endif; ?>
    </div>

    <!-- PASSING SETTINGS -->
    <div class="passing-settings">
        <h4><i class="fas fa-percent"></i> Global Passing Percentage Settings</h4>
        <p style="font-size:13px; color:#666; margin-bottom:15px;">Set passing percentages for all trainees. <strong style="color:#dc3545;">* Must be between 65% and 100%.</strong></p>
        <form method="POST" id="passingPercentagesForm" onsubmit="return validatePassingPercentages()">
            <input type="hidden" name="save_bulk_passing_percentages" value="1">
            <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>">
            <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-end;">
                <div style="flex:1; min-width:150px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px; color:#28a745;"><i class="fas fa-utensils"></i> Practical Skills</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" name="practical_passing_percentage" id="practical_passing_percentage" class="form-control" value="<?php echo $global_practical_passing; ?>" min="65" max="100" step="0.5" style="width:100px;" oninput="validatePercentage(this,'practical')">
                        <span>%</span>
                    </div>
                    <div id="practical-error" style="font-size:11px; color:#dc3545; margin-top:5px; display:none;"></div>
                </div>
                <div style="flex:1; min-width:150px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px; color:#17a2b8;"><i class="fas fa-project-diagram"></i> Project Output</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" name="project_passing_percentage" id="project_passing_percentage" class="form-control" value="<?php echo $global_project_passing; ?>" min="65" max="100" step="0.5" style="width:100px;" oninput="validatePercentage(this,'project')">
                        <span>%</span>
                    </div>
                    <div id="project-error" style="font-size:11px; color:#dc3545; margin-top:5px; display:none;"></div>
                </div>
                <div>
                    <button type="submit" class="btn btn-warning" id="submitPercentagesBtn"><i class="fas fa-save"></i> Apply to All Trainees</button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABS -->
    <div class="tabs">
        <div class="tab <?php echo $current_tab=='practical'?'active':''; ?>" onclick="switchTab('practical')"><i class="fas fa-utensils"></i> Practical Skills</div>
        <div class="tab <?php echo $current_tab=='project'?'active':''; ?>"  onclick="switchTab('project')"><i class="fas fa-project-diagram"></i> Project Output</div>
        <div class="tab <?php echo $current_tab=='summary'?'active':''; ?>"  onclick="switchTab('summary')"><i class="fas fa-table"></i> Summary & Results</div>
    </div>

    <?php if ($current_tab == 'practical'): ?>
    <!-- ===== PRACTICAL TAB ===== -->
    <div class="program-section">
        <h4><i class="fas fa-cog"></i> Program Default Practical Skills (Template)</h4>
        <p>Define the skills template loaded to all trainees.</p>
        <div id="program-skills-container">
            <?php if (empty($program_skills)): ?>
            <div class="skill-row">
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" class="form-control program-skill-name" value="Basic Knife Skills" placeholder="Skill name" style="flex:2;">
                    <input type="number" class="form-control program-skill-max" value="20" min="1" max="100" style="flex:1;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($program_skills as $skill): ?>
            <div class="skill-row">
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" class="form-control program-skill-name" value="<?php echo htmlspecialchars($skill['skill_name']); ?>" placeholder="Skill name" style="flex:2;">
                    <input type="number" class="form-control program-skill-max" value="<?php echo $skill['max_score']; ?>" min="1" max="100" style="flex:1;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-success" onclick="addProgramSkill()"><i class="fas fa-plus"></i> Add Skill</button>
            <button type="button" class="btn btn-primary" onclick="saveProgramSkills()"><i class="fas fa-save"></i> Save Program Skills Template</button>
        </div>
    </div>

    <div class="bulk-actions">
        <h4><i class="fas fa-bolt"></i> Bulk Actions - Practical Skills</h4>
        <div class="btn-group">
            <form method="POST" style="display:inline;">
                <button type="submit" name="load_skills_to_all" class="btn btn-info" <?php echo !$program_skills_exist?'disabled':''; ?>><i class="fas fa-download"></i> Load Skills to All</button>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Reset ALL trainees\' skills?');">
                <button type="submit" name="reset_all_skills" class="btn btn-warning"><i class="fas fa-undo"></i> Reset All Skills</button>
            </form>
        </div>
    </div>

    <form method="POST" id="practicalForm">
        <input type="hidden" name="save_bulk_practical_detailed" value="1">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="3%">#</th>
                        <th width="16%">Trainee</th>
                        <th width="44%">Skills & Scores</th>
                        <th width="12%">Total / Status</th>
                        <th width="25%">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($enrollments_with_details)): ?>
                    <tr><td colspan="5" style="text-align:center; padding:40px;"><i class="fas fa-filter"></i> No trainees match the current filter.</td></tr>
                    <?php else: ?>
                    <?php foreach ($enrollments_with_details as $index => $enrollment):
                        $practical_total   = $enrollment['practical_total'];
                        $practical_max     = $enrollment['practical_max'];
                        $trainee_skills    = $enrollment['trainee_skills'];
                        $filled_scores     = 0;
                        $total_skills      = count($trainee_skills);
                        foreach ($trainee_skills as $skill) {
                            if ($skill['score'] !== null && $skill['score'] !== '') $filled_scores++;
                        }
                        $trainee_practical_passing = floatval($enrollment['practical_passing_percentage'] ?? 75);
                        $practical_percentage = $practical_max > 0 ? ($practical_total / $practical_max) * 100 : 0;
                        $all_scores_filled = ($total_skills > 0 && $filled_scores == $total_skills);
                        if ($total_skills == 0)            { $pc = 'status-pending';    $pt = 'No skills'; }
                        elseif (!$all_scores_filled)       { $pc = 'status-incomplete'; $pt = "Incomplete ($filled_scores/$total_skills)"; }
                        elseif ($practical_percentage >= $trainee_practical_passing) { $pc = 'status-passed'; $pt = 'PASSED'; }
                        else                               { $pc = 'status-failed';     $pt = 'FAILED'; }
                        // enrollment status badge
                        $es = strtolower($enrollment['enrollment_status'] ?? '');
                        $esc = $es == 'approved' ? 'enroll-approved' : ($es == 'completed' ? 'enroll-completed' : ($es == 'pending' ? 'enroll-pending' : ($es == 'failed' ? 'enroll-failed' : 'enroll-other')));
                        $esl = $enrollment['enrollment_status'] ?: 'N/A';
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                            <input type="hidden" name="enrollment_id[]" value="<?php echo $enrollment['id']; ?>">
                            <div><span class="enroll-badge <?php echo $esc; ?>"><?php echo htmlspecialchars($esl); ?></span></div>
                            <div style="margin-top:5px;">
                                <?php if (!empty($trainee_skills)): ?>
                                <span class="badge badge-success"><?php echo count($trainee_skills); ?> skills</span>
                                <?php else: ?>
                                <span class="badge badge-warning">No skills</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div id="skills-<?php echo $index; ?>" class="skills-container">
                                <?php
                                $skill_scores_json = [];
                                if (!empty($trainee_skills)):
                                    foreach ($trainee_skills as $skill):
                                        $skill_id      = 'skill_' . $skill['id'];
                                        $score_display = ($skill['score'] !== '' && $skill['score'] !== null) ? $skill['score'] : '';
                                ?>
                                <div class="skill-detail-row">
                                    <span style="flex:2; font-size:12px;"><?php echo htmlspecialchars($skill['skill_name']); ?> (max: <?php echo $skill['max_score']; ?>)</span>
                                    <input type="number" class="form-control mini skill-score"
                                           data-index="<?php echo $index; ?>"
                                           data-skill-id="<?php echo $skill_id; ?>"
                                           value="<?php echo $score_display; ?>"
                                           min="0" max="<?php echo $skill['max_score']; ?>"
                                           style="width:70px;"
                                           onchange="validateScoreInput(this,<?php echo $skill['max_score']; ?>,'practical',<?php echo $index; ?>)"
                                           onkeyup="this.value=Math.min(Math.max(this.value,0),<?php echo $skill['max_score']; ?>)">
                                </div>
                                <?php
                                        $skill_scores_json[$skill_id] = ['score' => $score_display !== '' ? floatval($score_display) : null];
                                    endforeach;
                                else:
                                ?>
                                <div style="color:#999; padding:10px; text-align:center;">No skills loaded. Click "Load Skills to All" first.</div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="skill_scores[]" id="skill-scores-<?php echo $index; ?>" value='<?php echo json_encode($skill_scores_json); ?>'>
                        </td>
                        <td>
                            <div class="total-display" id="practical-total-<?php echo $index; ?>"><?php echo round($practical_total, 2); ?></div>
                            <div style="font-size:11px;">out of <?php echo $practical_max; ?></div>
                            <div style="font-size:10px;">Target: <?php echo $trainee_practical_passing; ?>%</div>
                            <div style="margin-top:5px;"><span class="<?php echo $pc; ?>"><?php echo $pt; ?></span></div>
                            <?php if ($all_scores_filled && $practical_total > 0): ?>
                            <div class="percentage-badge"><?php echo round($practical_percentage, 1); ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <textarea name="practical_notes[]" class="form-control" rows="2" placeholder="Add notes..."><?php echo htmlspecialchars($enrollment['practical_notes'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($enrollments_with_details)): ?>
        <div style="text-align:center; margin:20px 0;">
            <button type="submit" class="btn btn-primary" style="padding:12px 30px;"><i class="fas fa-save"></i> Save All Practical Scores</button>
        </div>
        <?php endif; ?>
    </form>

    <?php elseif ($current_tab == 'project'): ?>
    <!-- ===== PROJECT TAB ===== -->
    <div class="project-setup-section">
        <h4><i class="fas fa-pen-alt"></i> Project Setup (Apply to All Trainees)</h4>
        <p>Set the project title, instructions, and grading rubric. Trainee submissions are <strong>never overwritten</strong>.</p>
        <div class="alert-info" style="margin-bottom:15px; padding:10px;">
            <i class="fas fa-info-circle"></i> Existing rubric scores are preserved where criteria names match.
        </div>
        <div style="background:#f0f7ff; padding:20px; border-radius:10px; margin-bottom:20px;">
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-weight:600; margin-bottom:8px; display:block;"><i class="fas fa-tag"></i> Project Title:</label>
                <input type="text" id="project_title_override" class="form-control" value="<?php echo htmlspecialchars($default_project_title); ?>" placeholder="Enter project title...">
            </div>
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-weight:600; margin-bottom:8px; display:block;"><i class="fas fa-info-circle"></i> Instructions for Trainees:</label>
                <textarea id="project_instruction" class="form-control" rows="4" placeholder="Enter detailed instructions..."><?php echo htmlspecialchars($default_project_instruction); ?></textarea>
            </div>
            <div class="rubric-container">
                <div class="rubric-header"><h3><i class="fas fa-chart-line"></i> Grading Rubric</h3><p>Define criteria and max points.</p></div>
                <div id="rubric-criteria-container">
                    <?php foreach ($default_rubrics_data as $index => $criterion): ?>
                    <div class="rubric-criterion" data-criterion-index="<?php echo $index; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <h4 style="margin:0;"><i class="fas fa-check-circle"></i> Criterion <?php echo $index+1; ?>:
                                <input type="text" class="form-control criterion-name" style="display:inline-block; width:auto; min-width:200px; margin-left:10px;" value="<?php echo htmlspecialchars($criterion['name'] ?? ''); ?>" placeholder="Criterion name">
                            </h4>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeRubricCriterion(this)"><i class="fas fa-trash"></i> Remove</button>
                        </div>
                        <div>
                            <label>Max Points: </label>
                            <input type="number" class="form-control rubric-max-score" style="width:100px; display:inline-block;" value="<?php echo $criterion['max_score'] ?? 20; ?>" min="1" max="1000" step="1" onchange="updateRubricTotal()">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:15px;"><button type="button" class="btn btn-success" onclick="addRubricCriterion()"><i class="fas fa-plus"></i> Add Criterion</button></div>
                <div class="rubric-total" style="margin-top:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h4 style="margin:0;">Total Max Points:</h4>
                        <span id="rubric-max-total" style="font-size:18px; font-weight:bold;">0</span>
                    </div>
                </div>
                <div style="margin-top:20px; text-align:center;">
                    <button type="button" class="btn btn-primary" onclick="saveBulkProjectSetup()" style="padding:12px 30px;"><i class="fas fa-save"></i> Apply Project Setup to All Trainees</button>
                </div>
            </div>
        </div>
    </div>

    <div class="bulk-actions">
        <h4><i class="fas fa-eye"></i> Visibility Settings</h4>
        <div class="btn-group">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="toggle_all_visibility" value="1">
                <input type="hidden" name="visibility_type" value="project">
                <button type="submit" name="visibility_value" value="1" class="btn btn-success"><i class="fas fa-eye"></i> Show to All</button>
                <button type="submit" name="visibility_value" value="0" class="btn btn-secondary"><i class="fas fa-eye-slash"></i> Hide from All</button>
            </form>
        </div>
    </div>

    <form method="POST" id="bulkRubricForm">
        <input type="hidden" name="save_bulk_rubric_scores" value="1">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="3%">#</th>
                        <th width="11%">Trainee</th>
                        <th width="20%">Submission</th>
                        <th width="30%">Rubric Scores</th>
                        <th width="10%">Total / Status</th>
                        <th width="10%">Feedback</th>
                        <th width="9%">Visibility</th>
                        <th width="7%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($enrollments_with_details)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:40px;"><i class="fas fa-filter"></i> No trainees match the current filter.</td></tr>
                    <?php else: ?>
                    <?php foreach ($enrollments_with_details as $index => $enrollment):
                        $has_submission  = $enrollment['project_submitted_by_trainee'];
                        $image_path      = $enrollment['project_photo_path'] ?? '';
                        $submission_date = $enrollment['project_submitted_at'] ?? '';
                        $project_passing = floatval($enrollment['project_passing_percentage'] ?? 75);
                        $is_visible      = $enrollment['project_visible_to_trainee'] ?? 0;
                        $trainee_rubrics = [];
                        if (!empty($enrollment['project_rubrics'])) {
                            $trainee_rubrics = json_decode($enrollment['project_rubrics'], true);
                            if (!is_array($trainee_rubrics)) $trainee_rubrics = [];
                        }
                        $rubric_total     = $enrollment['project_total'];
                        $rubric_max       = $enrollment['project_max'];
                        $rubric_scores_array = [];
                        $filled_scores    = 0;
                        $total_criteria   = count($trainee_rubrics);
                        foreach ($trainee_rubrics as $ci => $criterion) {
                            $score = $criterion['score'] ?? null;
                            if ($score !== null && $score !== '') $filled_scores++;
                            $rubric_scores_array[$ci] = $score !== null ? floatval($score) : null;
                        }
                        $rubric_percentage    = $rubric_max > 0 ? ($rubric_total / $rubric_max) * 100 : 0;
                        $all_scores_filled    = ($total_criteria > 0 && $filled_scores == $total_criteria);
                        if (!$has_submission)          { $psc = 'status-pending';    $pst = 'No submission'; }
                        elseif (!$all_scores_filled)   { $psc = 'status-incomplete'; $pst = "Incomplete ($filled_scores/$total_criteria)"; }
                        elseif ($rubric_percentage >= $project_passing) { $psc = 'status-passed'; $pst = 'PASSED'; }
                        else                           { $psc = 'status-failed';     $pst = 'FAILED'; }
                        $vc   = $is_visible ? 'status-visible' : 'status-hidden';
                        $vt   = $is_visible ? 'Visible' : 'Hidden';
                        $vi   = $is_visible ? 'fa-eye' : 'fa-eye-slash';
                        $es   = strtolower($enrollment['enrollment_status'] ?? '');
                        $esc  = $es == 'approved' ? 'enroll-approved' : ($es == 'completed' ? 'enroll-completed' : ($es == 'pending' ? 'enroll-pending' : ($es == 'failed' ? 'enroll-failed' : 'enroll-other')));
                        $esl  = $enrollment['enrollment_status'] ?: 'N/A';
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                            <input type="hidden" name="enrollment_id[]" value="<?php echo $enrollment['id']; ?>">
                            <div><span class="enroll-badge <?php echo $esc; ?>"><?php echo htmlspecialchars($esl); ?></span></div>
                            <div style="font-size:11px; color:#666; margin-top:3px;"><?php echo htmlspecialchars($enrollment['email']); ?></div>
                        </td>
                        <td>
                            <?php if ($has_submission): ?>
                            <div class="submission-box">
                                <div style="font-weight:bold; color:#28a745; margin-bottom:5px;"><i class="fas fa-check-circle"></i> Submitted
                                    <?php if ($submission_date): ?><span style="font-size:11px;">(<?php echo date('M d, Y', strtotime($submission_date)); ?>)</span><?php endif; ?>
                                </div>
                                <?php if (!empty($enrollment['project_title'])): ?><div><strong>Title:</strong> <?php echo htmlspecialchars($enrollment['project_title']); ?></div><?php endif; ?>
                                <?php if (!empty($enrollment['project_description'])): ?>
                                <div style="background:white; padding:5px; border-radius:5px; margin-top:3px; font-size:12px; max-height:60px; overflow-y:auto;">
                                    <?php echo nl2br(htmlspecialchars(substr($enrollment['project_description'], 0, 100))); ?>
                                    <?php if (strlen($enrollment['project_description']) > 100): ?>...<?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($image_path) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $image_path)): ?>
                                <div style="margin-top:5px;">
                                    <img src="/<?php echo $image_path; ?>" style="max-width:60px; max-height:60px; border-radius:4px; cursor:pointer;"
                                         onclick="showImageModal('/<?php echo $image_path; ?>','<?php echo htmlspecialchars($enrollment['project_title'] ?? 'Project Image'); ?>')">
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="pending-box"><i class="fas fa-clock"></i> No submission yet</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div id="rubric-scores-<?php echo $index; ?>" class="rubric-scores-container">
                                <?php if (!empty($trainee_rubrics)): ?>
                                <?php foreach ($trainee_rubrics as $ci => $criterion): ?>
                                <div class="rubric-criterion-score">
                                    <div style="font-weight:600; font-size:12px; margin-bottom:5px;">
                                        <?php echo htmlspecialchars($criterion['name'] ?? 'Criterion ' . ($ci + 1)); ?>
                                        <span style="color:#666;">(Max: <?php echo $criterion['max_score']; ?>)</span>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <input type="number" class="form-control rubric-score-input"
                                               data-index="<?php echo $index; ?>"
                                               data-criterion-index="<?php echo $ci; ?>"
                                               value="<?php echo isset($criterion['score']) && $criterion['score'] !== null && $criterion['score'] !== '' ? $criterion['score'] : ''; ?>"
                                               min="0" max="<?php echo $criterion['max_score']; ?>" step="0.5" style="width:80px;"
                                               onchange="validateScoreInput(this,<?php echo $criterion['max_score']; ?>,'project',<?php echo $index; ?>)"
                                               onkeyup="this.value=Math.min(Math.max(this.value,0),<?php echo $criterion['max_score']; ?>)">
                                        <span>/ <?php echo $criterion['max_score']; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div style="color:#999; padding:10px; text-align:center;"><i class="fas fa-info-circle"></i> No rubric set. Save project setup first.</div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="rubric_scores[]" id="rubric-scores-json-<?php echo $index; ?>" value='<?php echo json_encode($rubric_scores_array); ?>'>
                        </td>
                        <td>
                            <div class="total-display" id="rubric-total-<?php echo $index; ?>"><?php echo round($rubric_total, 2); ?></div>
                            <div style="font-size:11px;">out of <?php echo $rubric_max; ?></div>
                            <div style="font-size:10px;">Target: <?php echo $project_passing; ?>%</div>
                            <div style="margin-top:5px;"><span class="<?php echo $psc; ?>"><?php echo $pst; ?></span></div>
                            <?php if ($all_scores_filled && $rubric_total > 0): ?>
                            <div class="percentage-badge"><?php echo round($rubric_percentage, 1); ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <textarea name="project_notes[]" class="form-control" rows="2" placeholder="Feedback..."><?php echo htmlspecialchars($enrollment['project_notes'] ?? ''); ?></textarea>
                        </td>
                        <td style="text-align:center;">
                            <div class="visibility-toggle-container">
                                <label class="toggle-switch">
                                    <input type="checkbox" onchange="toggleVisibility('project',<?php echo $enrollment['id']; ?>,this)" <?php echo $is_visible?'checked':''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="visibility-status <?php echo $vc; ?>"><i class="fas <?php echo $vi; ?>"></i> <?php echo $vt; ?></span>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($has_submission): ?><span class="badge badge-success">Submitted</span><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?>
                            <?php if (!empty($enrollment['is_finalized'])): ?><div class="finalized-badge">Finalized</div><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($enrollments_with_details)): ?>
        <div style="text-align:center; margin:20px 0;">
            <button type="submit" class="btn btn-primary" style="padding:12px 30px;"><i class="fas fa-save"></i> Save All Rubric Scores</button>
        </div>
        <?php endif; ?>
    </form>

    <?php elseif ($current_tab == 'summary'): ?>
    <!-- ===== SUMMARY TAB ===== -->
    <div class="bulk-actions">
        <h4><i class="fas fa-calculator"></i> Finalize Assessments</h4>
        <div class="btn-group">
            <form method="POST" onsubmit="return confirm('Calculate results for ALL trainees?');">
                <button type="submit" name="calculate_all_results" class="btn btn-primary"><i class="fas fa-calculator"></i> Calculate All Results</button>
            </form>
            <form method="POST" onsubmit="return confirm('Finalize all completed assessments?');">
                <input type="hidden" name="finalize_all_completed" value="1">
                <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle"></i> Finalize All Completed</button>
            </form>
        </div>
    </div>

   

    <div class="table-container">
        <div style="margin-bottom:15px; padding:10px; background:#f8f9fa; border-radius:8px; font-size:13px;">
            <i class="fas fa-info-circle"></i>
            Showing:
            <?php if ($filter_status=='failed_overall') echo '<strong>Failed Only</strong>';
            elseif ($filter_status=='passed_overall') echo '<strong>Passed Only</strong>';
            elseif ($filter_status=='pending_overall') echo '<strong>Pending Only</strong>';
            else echo '<strong>All Trainees</strong>'; ?>
            <?php if ($filter_status != 'all'): ?>
            <a href="?program_id=<?php echo $program_id; ?>&tab=summary&filter_status=all" style="margin-left:10px;">Show All</a>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Trainee Name</th>
                    <th>Enrollment Status</th>
                    <th>Practical Score</th>
                    <th>Practical Status</th>
                    <th>Project Score</th>
                    <th>Project Status</th>
                    <th>Total Score</th>
                    <th>Overall Result</th>
                    <th>Finalized</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($enrollments_with_details)): ?>
                <tr>
                    <td colspan="10" style="text-align:center; padding:40px;">
                        <i class="fas fa-filter"></i> No trainees match the current filter.
                        <br><br>
                        <a href="?program_id=<?php echo $program_id; ?>&tab=summary&filter_status=all" class="btn btn-sm btn-primary">Show All Trainees</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php
                $display_index = 1;
                foreach ($enrollments_with_details as $enrollment):
                    $enrollment_id = $enrollment['id'];

                    // ============================================================
                    // FIX 2: Summary - live sum from trainee_practical_skills,
                    // falls back to saved practical_score in assessment_components
                    // ============================================================
                    $pq = $conn->query("SELECT SUM(score) as total, SUM(max_score) as max_total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
                    $pd = $pq ? $pq->fetch_assoc() : null;
                    $practical     = floatval($pd['total'] ?? 0);
                    $practical_max = floatval($pd['max_total'] ?? 0);

                    if ($practical_max == 0 && floatval($enrollment['practical_score'] ?? 0) > 0) {
                        $practical     = floatval($enrollment['practical_score']);
                        $practical_max = floatval($enrollment['practical_max_score'] ?? 100);
                    }

                    // FIX 2b: project score from assessment_components (already stored correctly)
                    $project     = floatval($enrollment['project_score'] ?? 0);
                    $project_max = floatval($enrollment['project_total_max'] ?? 0);
                    if ($project_max == 0 && $project > 0) {
                        $project_max = floatval($enrollment['project_max_score'] ?? 95);
                    }

                    $practical_passing = floatval($enrollment['practical_passing_percentage'] ?? 75);
                    $project_passing   = floatval($enrollment['project_passing_percentage']   ?? 75);
                    $has_submission    = $enrollment['project_submitted_by_trainee'];

                    $practical_percentage = $practical_max > 0 ? ($practical / $practical_max) * 100 : 0;
                    $project_percentage   = $project_max   > 0 ? ($project   / $project_max)   * 100 : 0;

                    // Practical status
                    if ($practical_max == 0) { $pst = 'Not Assessed'; $psc = 'badge-secondary'; }
                    elseif ($practical_percentage >= $practical_passing) { $pst = 'PASSED'; $psc = 'badge-success'; }
                    else { $pst = 'FAILED'; $psc = 'badge-danger'; }

                    // Project status
                    if (!$has_submission)       { $jst = 'No Submission'; $jsc = 'badge-warning'; }
                    elseif ($project_max == 0)  { $jst = 'Not Assessed';  $jsc = 'badge-secondary'; }
                    elseif ($project_percentage >= $project_passing) { $jst = 'PASSED'; $jsc = 'badge-success'; }
                    else { $jst = 'FAILED'; $jsc = 'badge-danger'; }

                    $total        = $practical + $project;
                    $total_max    = $practical_max + $project_max;
                    $total_pct    = $total_max > 0 ? ($total / $total_max) * 100 : 0;

                    // Weighted overall passing percentage
                    $total_weight = $practical_max + $project_max;
                    $overall_passing = $total_weight > 0 ? ($practical_max * $practical_passing + $project_max * $project_passing) / $total_weight : 75;

                    // Use saved result if available
                    $saved_result = $enrollment['assessment_result'] ?? $enrollment['overall_result'] ?? null;
                    if ($saved_result) {
                        $overall_result = $saved_result;
                    } elseif ($total_max > 0) {
                        $overall_result = ($total_pct >= $overall_passing) ? 'Passed' : 'Failed';
                    } else {
                        $overall_result = null;
                    }

                    if ($overall_result == 'Passed')      { $rc = 'badge-success'; $rt = 'Passed'; }
                    elseif ($overall_result == 'Failed')   { $rc = 'badge-danger';  $rt = 'Failed'; }
                    else                                   { $rc = 'badge-warning'; $rt = 'Pending'; }

                    // Enrollment status display
                    $es  = strtolower($enrollment['enrollment_status'] ?? '');
                    $esc = $es == 'approved' ? 'enroll-approved' : ($es == 'completed' ? 'enroll-completed' : ($es == 'pending' ? 'enroll-pending' : ($es == 'failed' ? 'enroll-failed' : 'enroll-other')));
                    $esl = $enrollment['enrollment_status'] ?: 'N/A';
                ?>
                <tr>
                    <td><?php echo $display_index++; ?></td>
                    <td><strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong>
                        <div style="font-size:11px; color:#666;"><?php echo htmlspecialchars($enrollment['email']); ?></div>
                    </td>
                    <td style="text-align:center;"><span class="enroll-badge <?php echo $esc; ?>"><?php echo htmlspecialchars($esl); ?></span></td>
                    <td style="text-align:center;">
                        <?php echo $practical > 0 ? round($practical,2) : '0'; ?> / <?php echo $practical_max > 0 ? round($practical_max) : '0'; ?>
                        <?php if ($practical_max > 0): ?><br><small>(<?php echo round($practical_percentage,1); ?>%)</small><?php endif; ?>
                    </td>
                    <td style="text-align:center;"><span class="badge <?php echo $psc; ?>"><?php echo $pst; ?></span></td>
                    <td style="text-align:center;">
                        <?php echo $project > 0 ? round($project,2) : '0'; ?> / <?php echo $project_max > 0 ? round($project_max) : '0'; ?>
                        <?php if ($project_max > 0): ?><br><small>(<?php echo round($project_percentage,1); ?>%)</small><?php endif; ?>
                    </td>
                    <td style="text-align:center;"><span class="badge <?php echo $jsc; ?>"><?php echo $jst; ?></span></td>
                    <td style="text-align:center;">
                        <strong><?php echo $total > 0 ? round($total,2) : '0'; ?></strong> / <?php echo $total_max > 0 ? round($total_max) : '0'; ?>
                        <?php if ($total_max > 0): ?><br><small>(<?php echo round($total_pct,1); ?>%)</small><?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge <?php echo $rc; ?>" style="font-size:13px; padding:5px 12px;"><?php echo $rt; ?></span>
                    </td>
                    <td style="text-align:center;">
                        <?php if (!empty($enrollment['is_finalized'])): ?>
                        <span class="badge badge-info"><i class="fas fa-check-circle"></i> Finalized</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Image Modal -->
<div id="imageModal" class="image-modal">
    <span class="close-modal" onclick="closeImageModal()">&times;</span>
    <div class="modal-content">
        <img id="modalImage" class="modal-image">
        <div id="modalCaption" class="modal-caption"></div>
    </div>
</div>

<script>
function switchTab(t) { window.location.href='?program_id=<?php echo $program_id; ?>&tab='+t+'&filter_status=<?php echo $filter_status; ?>'; }
function applyFilter(f) { window.location.href='?program_id=<?php echo $program_id; ?>&tab=<?php echo $current_tab; ?>&filter_status='+f; }

function validateScoreInput(input, maxScore, type, index) {
    let value = parseFloat(input.value);
    const max = parseFloat(maxScore);
    if (input.value === '') {
        if (type==='practical') updateSkillTotal(index);
        else if (type==='project') updateRubricTraineeTotal(index);
        return true;
    }
    if (isNaN(value)) { input.value=''; return false; }
    if (value > max) { Swal.fire({icon:'warning',title:'Score Exceeds Max',text:'Score cannot exceed '+max+' points',timer:2000,showConfirmButton:false}); input.value=max; value=max; }
    else if (value < 0) { input.value=0; value=0; }
    if (type==='practical') updateSkillTotal(index);
    else if (type==='project') updateRubricTraineeTotal(index);
    return true;
}

function addProgramSkill() {
    const c = document.getElementById('program-skills-container');
    const d = document.createElement('div'); d.className='skill-row';
    d.innerHTML=`<div style="display:flex;gap:10px;align-items:center;"><input type="text" class="form-control program-skill-name" value="New Skill" placeholder="Skill name" style="flex:2;"><input type="number" class="form-control program-skill-max" value="20" min="1" max="100" style="flex:1;"><button type="button" class="btn btn-danger btn-sm" onclick="removeProgramSkill(this)"><i class="fas fa-trash"></i></button></div>`;
    c.appendChild(d);
}
function removeProgramSkill(btn) {
    Swal.fire({title:'Remove Skill?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes'}).then(r=>{ if(r.isConfirmed) btn.closest('.skill-row').remove(); });
}
function saveProgramSkills() {
    const skills=[];
    document.querySelectorAll('#program-skills-container .skill-row').forEach(row=>{
        const n=row.querySelector('.program-skill-name'), m=row.querySelector('.program-skill-max');
        if(n&&n.value.trim()) skills.push({name:n.value.trim(),max_score:parseInt(m.value)||20});
    });
    if(!skills.length){Swal.fire('No Skills','Add at least one skill.','warning');return;}
    const f=document.createElement('form');f.method='POST';
    addField(f,'save_program_skills','1');addField(f,'program_skills',JSON.stringify(skills));
    document.body.appendChild(f);f.submit();
}

function updateSkillTotal(index) {
    let total=0; const skillScores={};
    document.querySelectorAll(`#skills-${index} .skill-score`).forEach(input=>{
        const sid=input.dataset.skillId, max=parseFloat(input.getAttribute('max'));
        let score=parseFloat(input.value);
        if(input.value!==''){
            if(!isNaN(score)&&score>=0&&score<=max){total+=score; skillScores[sid]={score:score};}
            else skillScores[sid]={score:null};
        } else skillScores[sid]={score:null};
    });
    document.getElementById(`practical-total-${index}`).textContent=Math.round(total*100)/100;
    document.getElementById(`skill-scores-${index}`).value=JSON.stringify(skillScores);
}

let rubricCriterionCount=<?php echo count($default_rubrics_data); ?>;
function addRubricCriterion() {
    rubricCriterionCount++;
    const c=document.getElementById('rubric-criteria-container'), el=document.createElement('div');
    el.className='rubric-criterion'; el.setAttribute('data-criterion-index',rubricCriterionCount-1);
    el.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"><h4 style="margin:0;"><i class="fas fa-check-circle"></i> Criterion ${rubricCriterionCount}: <input type="text" class="form-control criterion-name" style="display:inline-block;width:auto;min-width:200px;margin-left:10px;" value="New Criterion" placeholder="Criterion name"></h4><button type="button" class="btn btn-danger btn-sm" onclick="removeRubricCriterion(this)"><i class="fas fa-trash"></i> Remove</button></div><div><label>Max Points: </label><input type="number" class="form-control rubric-max-score" style="width:100px;display:inline-block;" value="20" min="1" max="1000" step="1" onchange="updateRubricTotal()"></div>`;
    c.appendChild(el); updateRubricTotal();
}
function removeRubricCriterion(btn) {
    Swal.fire({title:'Remove Criterion?',text:'This removes it from ALL trainees.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes'}).then(r=>{
        if(r.isConfirmed){btn.closest('.rubric-criterion')?.remove(); updateRubricTotal();}
    });
}
function getRubricData() {
    return Array.from(document.querySelectorAll('.rubric-criterion')).map((c,i)=>({name:c.querySelector('.criterion-name')?.value||`Criterion ${i+1}`,max_score:parseFloat(c.querySelector('.rubric-max-score')?.value)||0,score:null}));
}
function updateRubricTotal() {
    let t=0; document.querySelectorAll('.rubric-criterion .rubric-max-score').forEach(i=>t+=parseFloat(i.value)||0);
    const el=document.getElementById('rubric-max-total'); if(el) el.textContent=t.toFixed(1);
}
function saveBulkProjectSetup() {
    const title=document.getElementById('project_title_override')?.value||'';
    const instr=document.getElementById('project_instruction')?.value||'';
    const rubrics=getRubricData();
    if(!rubrics.length){Swal.fire('Error','Add at least one rubric criterion.','warning');return;}
    const totalMax=rubrics.reduce((s,c)=>s+c.max_score,0);
    Swal.fire({title:'Apply to All Trainees?',html:`Title: <strong>${escapeHtml(title)||'(None)'}</strong><br>Rubric: ${rubrics.length} criteria, ${totalMax} total points<br><em>Trainee submissions preserved.</em>`,icon:'question',showCancelButton:true,confirmButtonText:'Yes, Apply'}).then(r=>{
        if(r.isConfirmed){
            Swal.fire({title:'Applying...',allowOutsideClick:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
            const f=document.createElement('form');f.method='POST';
            addField(f,'save_bulk_project_setup','1');addField(f,'project_title_override',title);addField(f,'project_instruction',instr);addField(f,'rubrics_data',JSON.stringify(rubrics));
            document.body.appendChild(f);f.submit();
        }
    });
}
function updateRubricTraineeTotal(index) {
    let total=0; const scores={}; let filled=0;
    const inputs=document.querySelectorAll(`#rubric-scores-${index} .rubric-score-input`);
    const totalCriteria=inputs.length;
    inputs.forEach(input=>{
        const ci=input.dataset.criterionIndex, max=parseFloat(input.getAttribute('max'));
        let score=parseFloat(input.value);
        if(input.value!==''){
            if(!isNaN(score)&&score>=0&&score<=max){total+=score;scores[ci]=score;filled++;}
            else scores[ci]=null;
        } else scores[ci]=null;
    });
    const el=document.getElementById(`rubric-total-${index}`);
    if(el) el.textContent=Math.round(total*100)/100;
    const jel=document.getElementById(`rubric-scores-json-${index}`);
    if(jel) jel.value=JSON.stringify(scores);
}

function validatePercentage(input, type) {
    const value=parseFloat(input.value);
    const errorEl=document.getElementById(`${type}-error`);
    const submitBtn=document.getElementById('submitPercentagesBtn');
    input.classList.remove('input-error');
    if(isNaN(value)){errorEl.innerHTML='<i class="fas fa-exclamation-circle"></i> Enter a valid number';errorEl.style.display='block';input.classList.add('input-error');if(submitBtn)submitBtn.disabled=true;return false;}
    if(value<65){errorEl.innerHTML='<i class="fas fa-exclamation-circle"></i> Minimum is 65%';errorEl.style.display='block';input.classList.add('input-error');if(submitBtn)submitBtn.disabled=true;return false;}
    if(value>100){errorEl.innerHTML='<i class="fas fa-exclamation-circle"></i> Maximum is 100%';errorEl.style.display='block';input.classList.add('input-error');if(submitBtn)submitBtn.disabled=true;return false;}
    errorEl.innerHTML='';errorEl.style.display='none';
    const pi=document.getElementById('practical_passing_percentage'), ji=document.getElementById('project_passing_percentage');
    const pv=pi&&!pi.classList.contains('input-error')&&pi.value!=='', jv=ji&&!ji.classList.contains('input-error')&&ji.value!=='';
    if(submitBtn) submitBtn.disabled=!(pv&&jv);
    return true;
}
function validatePassingPercentages() {
    const pi=document.getElementById('practical_passing_percentage'), ji=document.getElementById('project_passing_percentage');
    let valid=true;
    if(pi){const v=parseFloat(pi.value);if(isNaN(v)||v<65||v>100){validatePercentage(pi,'practical');valid=false;}}
    if(ji){const v=parseFloat(ji.value);if(isNaN(v)||v<65||v>100){validatePercentage(ji,'project');valid=false;}}
    if(!valid){Swal.fire({icon:'error',title:'Invalid Percentage',html:'Must be between <strong>65% and 100%</strong>.',confirmButtonColor:'#dc3545'});return false;}
    return true;
}

function toggleVisibility(type, enrollmentId, checkbox) {
    const newValue=checkbox.checked?1:0, toggleType=type==='project'?'toggle_project':'toggle_oral';
    const tc=checkbox.closest('.visibility-toggle-container'), ss=tc.querySelector('.visibility-status'), orig=ss.innerHTML;
    ss.innerHTML='<i class="fas fa-spinner fa-spin"></i> Updating...';
    fetch(`bulk_comprehensive_assessment.php?${toggleType}=1&enrollment_id=${enrollmentId}&set=${newValue}`)
        .then(r=>r.json()).then(data=>{
            if(data.success){
                ss.innerHTML=newValue?'<i class="fas fa-eye"></i> Visible':'<i class="fas fa-eye-slash"></i> Hidden';
                ss.className='visibility-status '+(newValue?'status-visible':'status-hidden');
                Swal.fire({icon:'success',title:'Updated!',text:`Project is now ${newValue?'visible':'hidden'} to trainee`,timer:1500,showConfirmButton:false});
            } else {checkbox.checked=!checkbox.checked;ss.innerHTML=orig;Swal.fire('Error','Failed to update visibility','error');}
        }).catch(()=>{checkbox.checked=!checkbox.checked;ss.innerHTML=orig;Swal.fire('Error','An error occurred','error');});
}

function addField(form, name, value) {
    const i=document.createElement('input');i.type='hidden';i.name=name;i.value=value;form.appendChild(i);
}
function escapeHtml(text) {
    if(!text)return'';const d=document.createElement('div');d.textContent=text;return d.innerHTML;
}
function showImageModal(src, title) {
    document.getElementById('modalImage').src=src;
    document.getElementById('modalCaption').innerHTML=title||'Project Image';
    document.getElementById('imageModal').style.display='block';
}
function closeImageModal() { document.getElementById('imageModal').style.display='none'; }

document.addEventListener('DOMContentLoaded', function() {
    updateRubricTotal();
    validatePercentage(document.getElementById('practical_passing_percentage'),'practical');
    validatePercentage(document.getElementById('project_passing_percentage'),'project');
    <?php foreach ($enrollments_with_details as $index => $enrollment): ?>
    updateSkillTotal(<?php echo $index; ?>);
    updateRubricTraineeTotal(<?php echo $index; ?>);
    <?php endforeach; ?>
});
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeImageModal(); });
window.onclick = e=>{ const m=document.getElementById('imageModal'); if(e.target==m) m.style.display='none'; };
</script>
</body>
</html>