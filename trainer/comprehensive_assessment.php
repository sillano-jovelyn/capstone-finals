<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
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

/**
 * Sanitize user INPUT only (called on $_POST values before saving).
 * Converts literal "/n" typed by a user into real newlines.
 * Never call this on data already retrieved from the database.
 */
function sanitizeInstruction($input) {
    if (empty($input)) return '';

    // Convert user-typed slash sequences to real control characters
    $input = str_replace(['/n', '/r', '/t', '//n', '//r', '//t'], ["\n", "\r", "\t", "\n", "\r", "\t"], $input);

    return $input;
}

/**
 * Format DB text for HTML display (newlines → <br>, HTML-escape).
 * Do NOT run sanitizeInstruction() here — data is already clean in the DB.
 */
function formatForDisplay($text) {
    if (empty($text)) return '';
    return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
}

// TOGGLE VISIBILITY - AJAX HANDLER
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

// HANDLE TRAINEE PROJECT SUBMISSION
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

    // Sanitize POST input only
    $project_title       = sanitizeInstruction($_POST['project_title'] ?? '');
    $project_description = sanitizeInstruction($_POST['project_description'] ?? '');

    $check_ac = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");

    if ($check_ac->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE assessment_components SET
            project_title = ?,
            project_description = ?,
            project_photo_path = ?,
            project_submitted_by_trainee = 1,
            project_submitted_at = NOW()
            WHERE enrollment_id = ?");
        $stmt->bind_param("sssi", $project_title, $project_description, $photo_path, $enrollment_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO assessment_components
            (enrollment_id, project_title, project_description, project_photo_path, project_submitted_by_trainee, project_submitted_at, oral_max_score)
            VALUES (?, ?, ?, ?, 1, NOW(), 100)");
        $stmt->bind_param("isss", $enrollment_id, $project_title, $project_description, $photo_path);
        $stmt->execute();
    }

    header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=project&submitted=1");
    exit;
}

// HANDLE TRAINEE ORAL ANSWERS SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_oral_answers'])) {
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

    $answers = isset($_POST['answers']) ? $_POST['answers'] : [];
    // Sanitize POST input only
    $answers = array_map('sanitizeInstruction', $answers);
    $answers_string = implode("|!|", $answers);

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

    header("Location: trainee_assessment.php?enrollment_id=$enrollment_id&tab=oral&answers_submitted=1");
    exit;
}

// CLEAR TRAINEE ORAL ANSWERS
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

// SAVE TRAINEE-SPECIFIC PRACTICAL SKILLS
if (isset($_POST['save_trainee_practical_skills'])) {
    $enrollment_id = intval($_POST['enrollment_id']);

    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $conn->query("DELETE FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");

        $skills_json = $_POST['trainee_skills'];
        $skills = json_decode($skills_json, true);

        if (is_array($skills)) {
            $stmt = $conn->prepare("INSERT INTO trainee_practical_skills
                (enrollment_id, skill_name, max_score, order_index)
                VALUES (?, ?, ?, ?)");

            foreach ($skills as $index => $skill) {
                // sanitizeInstruction on POST input only
                $skill_name = sanitizeInstruction($skill['name']);
                $max_score  = intval($skill['max_score']);

                $stmt->bind_param("isii", $enrollment_id, $skill_name, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
        }

        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $safe_json = $conn->real_escape_string($skills_json);
            $conn->query("UPDATE assessment_components SET
                practical_skills_saved = 1,
                practical_skills_grading = '$safe_json'
                WHERE enrollment_id = $enrollment_id");
        } else {
            $safe_json = $conn->real_escape_string($skills_json);
            $conn->query("INSERT INTO assessment_components
                (enrollment_id, practical_skills_saved, practical_skills_grading, oral_max_score)
                VALUES ($enrollment_id, 1, '$safe_json', 100)");
        }

        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Practical skills saved successfully']);
            exit;
        }
    }

    if (!isset($_POST['ajax'])) {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=practical&skills_saved=1");
        exit;
    }
}

// SAVE TRAINEE-SPECIFIC ORAL QUESTIONS
if (isset($_POST['save_trainee_oral_questions'])) {
    $enrollment_id = intval($_POST['enrollment_id']);

    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $conn->query("DELETE FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");

        $questions_json = $_POST['trainee_questions'];
        $questions = json_decode($questions_json, true);

        if (is_array($questions)) {
            $stmt = $conn->prepare("INSERT INTO trainee_oral_questions
                (enrollment_id, question, max_score, order_index)
                VALUES (?, ?, ?, ?)");

            foreach ($questions as $index => $q) {
                // sanitizeInstruction on POST input only
                $question  = sanitizeInstruction($q['question']);
                $max_score = intval($q['max_score']);

                $stmt->bind_param("isii", $enrollment_id, $question, $max_score, $index);
                $stmt->execute();
            }
            $stmt->close();
        }

        $check = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($check->num_rows > 0) {
            $safe_json = $conn->real_escape_string($questions_json);
            $conn->query("UPDATE assessment_components SET
                oral_questions_saved = 1,
                oral_questions = '$safe_json'
                WHERE enrollment_id = $enrollment_id");
        } else {
            $safe_json = $conn->real_escape_string($questions_json);
            $conn->query("INSERT INTO assessment_components
                (enrollment_id, oral_questions_saved, oral_questions, oral_max_score)
                VALUES ($enrollment_id, 1, '$safe_json', 100)");
        }

        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Oral questions saved successfully']);
            exit;
        }
    }

    if (!isset($_POST['ajax'])) {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=oral&questions_saved=1");
        exit;
    }
}

// SAVE SCORES FOR PRACTICAL SKILLS
if (isset($_POST['save_practical_scores'])) {
    $enrollment_id = intval($_POST['enrollment_id']);

    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
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

        $total_score  = 0;
        $total_query  = $conn->query("SELECT SUM(score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
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

// SAVE SCORES FOR ORAL QUESTIONS
if (isset($_POST['save_oral_scores'])) {
    $enrollment_id = intval($_POST['enrollment_id']);

    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
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

        $total_score  = 0;
        $total_query  = $conn->query("SELECT SUM(score) as total FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id");
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

// REMOVE TRAINEE SKILLS
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

// REMOVE TRAINEE QUESTIONS
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

// REMOVE SINGLE SKILL
if (isset($_POST['remove_single_skill'])) {
    $skill_id      = intval($_POST['skill_id']);
    $enrollment_id = intval($_POST['enrollment_id']);

    $conn->query("DELETE FROM trainee_practical_skills WHERE id = $skill_id");

    $remaining = $conn->query("SELECT COUNT(*) as count FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id")->fetch_assoc();
    if ($remaining['count'] == 0) {
        $conn->query("UPDATE assessment_components SET practical_skills_saved = 0, practical_score = 0 WHERE enrollment_id = $enrollment_id");
    } else {
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

// REMOVE SINGLE QUESTION
if (isset($_POST['remove_single_question'])) {
    $question_id   = intval($_POST['question_id']);
    $enrollment_id = intval($_POST['enrollment_id']);

    $conn->query("DELETE FROM trainee_oral_questions WHERE id = $question_id");

    $remaining = $conn->query("SELECT COUNT(*) as count FROM trainee_oral_questions WHERE enrollment_id = $enrollment_id")->fetch_assoc();
    if ($remaining['count'] == 0) {
        $conn->query("UPDATE assessment_components SET oral_questions_saved = 0, oral_score = 0 WHERE enrollment_id = $enrollment_id");
    } else {
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

// SAVE RUBRICS (UPDATED - NOW SAVES TITLE AND INSTRUCTION AS WELL)
if (isset($_POST['save_rubrics'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $rubrics_data  = json_decode($_POST['rubrics_data'], true);
    
    // Get project title and instruction from POST
    $project_title_override = isset($_POST['project_title_override']) ? sanitizeInstruction($_POST['project_title_override']) : '';
    $project_instruction = isset($_POST['project_instruction']) ? sanitizeInstruction($_POST['project_instruction']) : '';

    if ($enrollment_id > 0 && is_array($rubrics_data)) {
        // sanitizeInstruction on POST input only (criterion names come from user)
        foreach ($rubrics_data as &$criterion) {
            if (isset($criterion['name'])) {
                $criterion['name'] = sanitizeInstruction($criterion['name']);
            }
        }
        unset($criterion);

        $total_max_score    = 0;
        $total_earned_score = 0;
        foreach ($rubrics_data as $criterion) {
            $total_max_score    += floatval($criterion['max_score'] ?? 0);
            $total_earned_score += floatval($criterion['score'] ?? 0);
        }

        $check       = $conn->query("SELECT id FROM assessment_components WHERE enrollment_id = $enrollment_id");
        $rubrics_json = json_encode($rubrics_data);
        $safe_rubrics = $conn->real_escape_string($rubrics_json);
        $safe_title = $conn->real_escape_string($project_title_override);
        $safe_instruction = $conn->real_escape_string($project_instruction);

        if ($check && $check->num_rows > 0) {
            $update = $conn->query("UPDATE assessment_components SET
                project_rubrics = '$safe_rubrics',
                project_score = $total_earned_score,
                project_total_max = $total_max_score,
                project_title_override = '$safe_title',
                project_instruction = '$safe_instruction'
                WHERE enrollment_id = $enrollment_id");
            if ($update) {
                echo json_encode([
                    'success'          => true,
                    'message'          => 'Rubrics and project settings saved successfully!',
                    'total_max_score'  => $total_max_score,
                    'total_earned_score' => $total_earned_score,
                    'project_score'    => $total_earned_score,
                    'project_total_max' => $total_max_score
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $conn->error]);
            }
        } else {
            $insert = $conn->query("INSERT INTO assessment_components
                (enrollment_id, project_rubrics, project_score, project_total_max, 
                 project_title_override, project_instruction, oral_max_score)
                VALUES ($enrollment_id, '$safe_rubrics', $total_earned_score, $total_max_score, 
                        '$safe_title', '$safe_instruction', 100)");
            if ($insert) {
                echo json_encode([
                    'success'          => true,
                    'message'          => 'Rubrics and project settings saved successfully!',
                    'total_max_score'  => $total_max_score,
                    'total_earned_score' => $total_earned_score,
                    'project_score'    => $total_earned_score,
                    'project_total_max' => $total_max_score
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database insert failed: ' . $conn->error]);
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
    }
    exit;
}

// HANDLE SAVE TAB DATA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tab'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $tab           = $_POST['tab'];

    $enrollment_check = $conn->query("SELECT id FROM enrollments WHERE id = $enrollment_id");
    if ($enrollment_check->num_rows > 0) {
        $check = $conn->prepare("SELECT id FROM assessment_components WHERE enrollment_id = ?");
        $check->bind_param("i", $enrollment_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($tab === 'project') {
            $project_score              = floatval($_POST['project_score'] ?? 0);
            $project_passing_percentage = floatval($_POST['project_passing_percentage'] ?? 75);
            $project_total_max          = floatval($_POST['project_total_max'] ?? 100);

            if ($project_passing_percentage < 65)  $project_passing_percentage = 65;
            if ($project_passing_percentage > 100) $project_passing_percentage = 100;

            $project_passed = ($project_score >= $project_passing_percentage) ? 1 : 0;

            // sanitizeInstruction on POST text fields
            $project_notes          = sanitizeInstruction($_POST['project_notes'] ?? '');
            $project_instruction    = sanitizeInstruction($_POST['project_instruction'] ?? '');
            $project_title_override = sanitizeInstruction($_POST['project_title_override'] ?? '');

            // project_rubrics comes as JSON string — no sanitizeInstruction needed
            $project_rubrics_raw = $_POST['project_rubrics'] ?? '';

            if ($exists) {
                $stmt = $conn->prepare("UPDATE assessment_components SET
                    project_score = ?,
                    project_passed = ?,
                    project_notes = ?,
                    project_passing_percentage = ?,
                    project_instruction = ?,
                    project_rubrics = ?,
                    project_title_override = ?,
                    project_total_max = ?
                    WHERE enrollment_id = ?");
                $stmt->bind_param("disdsssdi", 
                    $project_score, 
                    $project_passed, 
                    $project_notes,
                    $project_passing_percentage, 
                    $project_instruction, 
                    $project_rubrics_raw,
                    $project_title_override, 
                    $project_total_max, 
                    $enrollment_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO assessment_components
                    (enrollment_id, project_score, project_passed, project_notes, project_passing_percentage,
                     project_instruction, project_rubrics, project_title_override, project_total_max, oral_max_score)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100)");
                $stmt->bind_param("idissdsssd", 
                    $enrollment_id, 
                    $project_score, 
                    $project_passed, 
                    $project_notes,
                    $project_passing_percentage, 
                    $project_instruction, 
                    $project_rubrics_raw,
                    $project_title_override, 
                    $project_total_max);
            }

            if (!$stmt->execute()) {
                error_log("Save project failed: " . $stmt->error);
            }
            $stmt->close();

        } elseif ($tab === 'practical') {
            $practical_passing_percentage = floatval($_POST['practical_passing_percentage'] ?? 75);
            if ($practical_passing_percentage < 65)  $practical_passing_percentage = 65;
            if ($practical_passing_percentage > 100) $practical_passing_percentage = 100;

            $practical_notes = sanitizeInstruction($_POST['practical_notes'] ?? '');

            if ($exists) {
                $stmt = $conn->prepare("UPDATE assessment_components SET
                    practical_notes = ?,
                    practical_passing_percentage = ?
                    WHERE enrollment_id = ?");
                $stmt->bind_param("sdi", $practical_notes, $practical_passing_percentage, $enrollment_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO assessment_components
                    (enrollment_id, practical_notes, practical_passing_percentage, oral_max_score)
                    VALUES (?, ?, ?, 100)");
                $stmt->bind_param("isd", $enrollment_id, $practical_notes, $practical_passing_percentage);
            }

            if (!$stmt->execute()) {
                error_log("Save practical settings failed: " . $stmt->error);
            }
            $stmt->close();

        } elseif ($tab === 'oral') {
            $oral_passing_percentage = floatval($_POST['oral_passing_percentage'] ?? 75);
            if ($oral_passing_percentage < 65)  $oral_passing_percentage = 65;
            if ($oral_passing_percentage > 100) $oral_passing_percentage = 100;

            $oral_notes = sanitizeInstruction($_POST['oral_notes'] ?? '');

            if ($exists) {
                $stmt = $conn->prepare("UPDATE assessment_components SET
                    oral_notes = ?,
                    oral_passing_percentage = ?
                    WHERE enrollment_id = ?");
                $stmt->bind_param("sdi", $oral_notes, $oral_passing_percentage, $enrollment_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO assessment_components
                    (enrollment_id, oral_notes, oral_passing_percentage, oral_max_score)
                    VALUES (?, ?, ?, 100)");
                $stmt->bind_param("isd", $enrollment_id, $oral_notes, $oral_passing_percentage);
            }

            if (!$stmt->execute()) {
                error_log("Save oral settings failed: " . $stmt->error);
            }
            $stmt->close();
        }

        // Recalculate overall result after saving
        $assessment_result = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id");
        if ($assessment_result && $assessment = $assessment_result->fetch_assoc()) {
            $practical     = $assessment['practical_score'] ?? 0;
            $project       = $assessment['project_score'] ?? 0;
            $oral          = $assessment['oral_score'] ?? 0;

            $practical_max = 100;
            $pm_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
            if ($pm_query && $pm_row = $pm_query->fetch_assoc()) {
                $practical_max = $pm_row['total'] ?? 100;
            }

            $project_total_max = $assessment['project_total_max'] ?? 100;
            $oral_max          = $assessment['oral_max_score'] ?? 100;

            $total      = $practical + $project + $oral;
            $max_total  = $practical_max + $project_total_max + $oral_max;
            $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;

            $total_weight    = $practical_max + $project_total_max + $oral_max;
            $practical_passing = $assessment['practical_passing_percentage'] ?? 75;
            $project_passing   = $assessment['project_passing_percentage'] ?? 75;
            $oral_passing      = $assessment['oral_passing_percentage'] ?? 75;

            $overall_passing_percentage = $total_weight > 0
                ? ($practical_max * $practical_passing + $project_total_max * $project_passing + $oral_max * $oral_passing) / $total_weight
                : 75;

            $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';

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

// HANDLE SAVE ASSESSMENT (FINALIZE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $enrollment_id = intval($_POST['enrollment_id']);
    $updated = 0;

    $enrollment_data = $conn->query("SELECT user_id, program_id FROM enrollments WHERE id = $enrollment_id")->fetch_assoc();
    if ($enrollment_data) {
        $user_id    = $enrollment_data['user_id'];
        $program_id = $enrollment_data['program_id'];
    } else {
        header("Location: comprehensive_assessment.php?enrollment_id=$enrollment_id&tab=summary&error=1");
        exit;
    }

    $assessment = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();

    if ($assessment) {
        $practical = $assessment['practical_score'] ?? 0;
        $project   = $assessment['project_score'] ?? 0;
        $oral      = $assessment['oral_score'] ?? 0;

        $practical_max = 100;
        $pm_query = $conn->query("SELECT SUM(max_score) as total FROM trainee_practical_skills WHERE enrollment_id = $enrollment_id");
        if ($pm_query && $pm_row = $pm_query->fetch_assoc()) {
            $practical_max = $pm_row['total'] ?? 100;
        }

        $project_total_max = $assessment['project_total_max'] ?? 100;
        $oral_max          = $assessment['oral_max_score'] ?? 100;

        $total      = $practical + $project + $oral;
        $max_total  = $practical_max + $project_total_max + $oral_max;
        $percentage = $max_total > 0 ? ($total / $max_total) * 100 : 0;

        $total_weight      = $practical_max + $project_total_max + $oral_max;
        $practical_passing = $assessment['practical_passing_percentage'] ?? 75;
        $project_passing   = $assessment['project_passing_percentage'] ?? 75;
        $oral_passing      = $assessment['oral_passing_percentage'] ?? 75;

        $overall_passing_percentage = $total_weight > 0
            ? ($practical_max * $practical_passing + $project_total_max * $project_passing + $oral_max * $oral_passing) / $total_weight
            : 75;

        $overall_result = ($percentage >= $overall_passing_percentage) ? 'Passed' : 'Failed';

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
            completion_date = NOW(),
            completed_at = NOW()
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
                $insert_archive->bind_param("iissi", $user_id, $program_id, $enrollment_status, $overall_result, $enrollment_id);
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

// Get existing assessment — raw from DB, no sanitizeInstruction here
$existing_assessment = $conn->query("SELECT * FROM assessment_components WHERE enrollment_id = $enrollment_id")->fetch_assoc();

// Parse rubrics JSON if exists
$project_rubrics_data = [];
if (!empty($existing_assessment['project_rubrics'])) {
    $project_rubrics_data = json_decode($existing_assessment['project_rubrics'], true);
    if (!is_array($project_rubrics_data)) {
        $project_rubrics_data = [];
    }
}

if (!$existing_assessment) {
    $existing_assessment = [
        'project_visible_to_trainee'         => 0,
        'oral_questions_visible_to_trainee'   => 0,
        'practical_skills_saved'             => 0,
        'oral_questions_saved'               => 0,
        'project_submitted_by_trainee'       => 0,
        'oral_submitted_by_trainee'          => 0,
        'oral_answers'                       => null,
        'project_score'                      => 0,
        'project_total_max'                  => 100,
        'oral_score'                         => 0,
        'practical_score'                    => 0,
        'practical_notes'                    => '',
        'practical_date'                     => date('Y-m-d'),
        'practical_passed'                   => 0,
        'project_notes'                      => '',
        'oral_notes'                         => '',
        'oral_max_score'                     => 100,
        'project_title'                      => '',
        'project_description'                => '',
        'project_photo_path'                 => '',
        'overall_result'                     => null,
        'overall_total_score'                => 0,
        'practical_passing_percentage'       => 75,
        'project_passing_percentage'         => 75,
        'oral_passing_percentage'            => 75,
        'project_instruction'                => '',
        'project_rubrics'                    => '',
        'project_title_override'             => ''
    ];
}

// Parse oral answers — raw DB value, no re-sanitizing
$oral_answers_array = [];
if (!empty($existing_assessment['oral_answers'])) {
    $oral_answers_array = explode('|!|', $existing_assessment['oral_answers']);
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

// Calculate practical totals
$practical_total          = 0;
$practical_max_total      = 0;
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

if ($practical_total == 0 && isset($existing_assessment['practical_score']) && $existing_assessment['practical_score'] > 0) {
    $practical_total = $existing_assessment['practical_score'];
}

if ($practical_max_total == 0) {
    $practical_max_total = 100;
}

// Calculate oral totals
$oral_max_from_trainee    = 0;
$oral_has_all_scores      = true;
$oral_calculated_score    = 0;
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

$oral_max   = $oral_max_from_trainee > 0 ? $oral_max_from_trainee : ($existing_assessment['oral_max_score'] ?? 100);
$oral_score = $oral_calculated_score > 0 ? $oral_calculated_score : ($existing_assessment['oral_score'] ?? 0);

// Calculate project totals
$project_score     = $existing_assessment['project_score'] ?? 0;
$project_total_max = $existing_assessment['project_total_max'] ?? 100;

// Calculate overall totals
$total_score     = $practical_total + $project_score + $oral_score;
$total_max       = $practical_max_total + $project_total_max + $oral_max;
$overall_percent = $total_max > 0 ? round(($total_score / $total_max) * 100, 1) : 0;

$total_weight      = $practical_max_total + $project_total_max + $oral_max;
$practical_passing = $existing_assessment['practical_passing_percentage'] ?? 75;
$project_passing   = $existing_assessment['project_passing_percentage'] ?? 75;
$oral_passing      = $existing_assessment['oral_passing_percentage'] ?? 75;

$overall_passing_percentage = $total_weight > 0
    ? ($practical_max_total * $practical_passing + $project_total_max * $project_passing + $oral_max * $oral_passing) / $total_weight
    : 75;
$overall_result = $overall_percent >= $overall_passing_percentage ? 'PASSED' : 'FAILED';

// Current tab & flash messages
$current_tab             = $_GET['tab'] ?? 'practical';
$removed_message         = isset($_GET['removed'])          ? 'All items removed permanently!'         : '';
$skill_removed_message   = isset($_GET['skill_removed'])    ? 'Skill removed successfully!'            : '';
$question_removed_message= isset($_GET['question_removed']) ? 'Question removed successfully!'         : '';
$saved_message           = isset($_GET['saved'])            ? 'Assessment saved successfully!'         : '';
$skills_saved_message    = isset($_GET['skills_saved'])     ? 'Trainee skills saved successfully!'     : '';
$questions_saved_message = isset($_GET['questions_saved'])  ? 'Trainee questions saved successfully!'  : '';
$scores_saved_message    = isset($_GET['scores_saved'])     ? 'Scores saved successfully!'             : '';
$answers_cleared_message = isset($_GET['answers_cleared'])  ? 'Trainee answers cleared successfully!'  : '';
$error_message           = isset($_GET['error'])            ? 'Error finalizing assessment. Please try again.' : '';

// Format project instruction for display — data is clean in DB, just HTML-escape + nl2br
$formatted_instructions = formatForDisplay($existing_assessment['project_instruction'] ?? '');

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
        /* Your existing CSS styles remain exactly the same */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }

        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }

        .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-info    { background: #cce5ff; color: #004085; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-danger  { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-bottom: 20px; }

        .assessment-card { background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; border: 1px solid #e0e0e0; }
        .card-title { font-size: 20px; font-weight: 600; color: #333; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #667eea; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .toggle-container { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .toggle-switch { position: relative; display: inline-block; width: 60px; height: 30px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(30px); }
        .toggle-label { font-size: 14px; color: #666; }

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

        .add-btn               { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .add-btn:hover         { background: #218838; }
        .remove-btn            { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .remove-btn:hover      { background: #c82333; }
        .delete-btn            { background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .delete-btn:hover      { background: #c82333; }
        .save-trainee-skills-btn    { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-trainee-skills-btn:hover { background: #218838; }
        .save-trainee-questions-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-trainee-questions-btn:hover { background: #218838; }
        .save-scores-btn       { background: #ffc107; color: #333; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .save-scores-btn:hover { background: #e0a800; }
        .clear-answers-btn     { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .clear-answers-btn:hover { background: #5a6268; }
        .save-tab-btn  { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 12px 30px; border-radius: 50px; font-size: 16px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; }
        .submit-btn    { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 40px; font-size: 18px; font-weight: 600; border-radius: 50px; cursor: pointer; }
        .print-btn     { border: 2px solid #6c757d; background: transparent; color: #6c757d; padding: 8px 20px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .print-btn:hover { background: #6c757d; color: white; }

        .custom-skill-row { background: #f8f9fa; margin-bottom: 15px; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; position: relative; }

        .scoring-section { background: #fff; padding: 20px; border-radius: 10px; margin-top: 30px; border: 2px solid #ffc107; }
        .scoring-section h4 { color: #ffc107; margin-bottom: 15px; }
        .score-input { width: 80px; text-align: center; padding: 8px; border: 2px solid #ffc107; border-radius: 5px; font-weight: 600; }
        .score-max   { font-size: 14px; color: #666; margin-top: 4px; }
        .pass-badge    { background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .fail-badge    { background: #dc3545; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .pending-badge { background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }

        .input-label { font-size: 12px; color: #666; margin-top: 4px; display: block; }

        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; text-align: center; }
        .summary-card h3    { font-size: 16px; margin-bottom: 10px; opacity: 0.9; }
        .summary-card .score { font-size: 36px; font-weight: 700; }
        .summary-card .max-score { font-size: 14px; opacity: 0.8; }
        .summary-card.passed { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .summary-card.failed { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }

        .back-btn { display: inline-block; padding: 10px 20px; background: white; color: #667eea; border: 2px solid #667eea; border-radius: 50px; text-decoration: none; font-weight: 600; margin-bottom: 20px; }

        .info-grid  { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .info-item  { display: flex; flex-direction: column; }
        .info-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .info-value { font-size: 18px; font-weight: 600; color: #333; }

        .waiting-message { text-align: center; padding: 40px; background: #f8f9fa; border-radius: 10px; }

        .button-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }

        .total-display { font-size: 24px; font-weight: 700; color: #667eea; text-align: right; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }

        .trainee-section    { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #28a745; }
        .trainee-section h4 { color: #28a745; margin-bottom: 15px; }

        .visibility-status  { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .status-visible     { background: #d4edda; color: #155724; }
        .status-hidden      { background: #f8d7da; color: #721c24; }

        .saved-status  { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
        .status-saved  { background: #d4edda; color: #155724; }
        .status-unsaved{ background: #fff3cd; color: #856404; }

        .answer-box  { background: #f3e8ff; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #8b5cf6; }
        .answer-text { white-space: pre-wrap; margin: 10px 0 0 0; }
        .no-answer   { background: #fff3cd; padding: 10px; border-radius: 6px; color: #856404; }

        .answer-status          { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .answer-submitted       { background: #d4edda; color: #155724; }
        .answer-not-submitted   { background: #fff3cd; color: #856404; }

        .modal         { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 10% auto; padding: 30px; border-radius: 15px; width: 80%; max-width: 500px; }
        .close         { float: right; font-size: 28px; font-weight: bold; cursor: pointer; }

        .passing-percentage-group { background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107; }
        .passing-percentage-group label { font-weight: 600; color: #856404; margin-bottom: 8px; display: block; }
        .passing-percentage-input  { width: 120px; padding: 8px; border: 2px solid #ffc107; border-radius: 5px; font-weight: 600; text-align: center; }
        .passing-percentage-help   { font-size: 12px; color: #856404; margin-top: 5px; }

        .passing-percentage-wrapper { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .btn-save-settings       { background: #28a745; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-save-settings:hover { background: #218838; }

        .rubric-container  { background: white; border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .rubric-header     { background: #f8f9fa; color: #333; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .rubric-criterion  { background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .rubric-criterion h4 { color: #007bff; margin-bottom: 10px; }
        .rubric-score-input   { width: 100px; text-align: center; font-size: 18px; font-weight: bold; border: 2px solid #007bff; border-radius: 8px; padding: 8px; margin-top: 10px; }
        .rubric-score-display { font-size: 14px; color: #666; margin-top: 5px; }
        .rubric-total         { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-top: 20px; text-align: center; }
        .rubric-total-score   { font-size: 32px; font-weight: bold; color: #007bff; }

        .add-rubric-btn        { background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin: 5px 0; }
        .add-rubric-btn:hover  { background: #218838; }
        .remove-rubric-btn     { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; margin-left: 10px; }
        .btn-save-rubrics      { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; }

        .criterion-name { display: inline-block; width: auto; min-width: 200px; }

        .trainee-submission-score { background: #e3f2fd; padding: 10px; border-radius: 8px; margin-top: 10px; border-left: 4px solid #2196f3; }
        .submission-score-label   { font-weight: 600; color: #1976d2; margin-bottom: 5px; }

        /* Instruction display: preserve line breaks */
        .instruction-display { white-space: pre-wrap; line-height: 1.6; }

        @media print {
            .header, .tabs, .back-btn, .toggle-container, .submit-btn,
            .save-tab-btn, .print-btn, .modal, .add-btn, .remove-btn, .delete-btn, .button-group,
            .save-trainee-skills-btn, .save-trainee-questions-btn, .save-scores-btn, .clear-answers-btn,
            .passing-percentage-group, .btn-save-settings, .btn-save-rubrics { display: none !important; }
            .assessment-card { box-shadow: none; border: 1px solid #ddd; }
            .summary-card { break-inside: avoid; }
        }


        /* Add these styles at the end of your existing <style> section */

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

    <?php if ($removed_message):          ?><div class="alert-danger"><?php  echo $removed_message; ?></div><?php endif; ?>
    <?php if ($skill_removed_message):    ?><div class="alert-danger"><?php  echo $skill_removed_message; ?></div><?php endif; ?>
    <?php if ($question_removed_message): ?><div class="alert-danger"><?php  echo $question_removed_message; ?></div><?php endif; ?>
    <?php if ($saved_message):            ?><div class="alert-success"><?php echo $saved_message; ?></div><?php endif; ?>
    <?php if ($skills_saved_message):     ?><div class="alert-success"><?php echo $skills_saved_message; ?></div><?php endif; ?>
    <?php if ($questions_saved_message):  ?><div class="alert-success"><?php echo $questions_saved_message; ?></div><?php endif; ?>
    <?php if ($scores_saved_message):     ?><div class="alert-success"><?php echo $scores_saved_message; ?></div><?php endif; ?>
    <?php if ($answers_cleared_message):  ?><div class="alert-success"><?php echo $answers_cleared_message; ?></div><?php endif; ?>
    <?php if ($error_message):            ?><div class="alert-danger"><?php  echo $error_message; ?></div><?php endif; ?>

    <div class="info-grid">
        <div class="info-item"><span class="info-label">Full Name</span><span class="info-value"><?php echo htmlspecialchars($enrollment['fullname'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Contact</span><span class="info-value"><?php echo htmlspecialchars($enrollment['contact_number'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($enrollment['email'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Program</span><span class="info-value"><?php echo htmlspecialchars($enrollment['program_name'] ?? ''); ?></span></div>
    </div>

    <div class="tabs" id="tabs">
        <div class="tab <?php echo $current_tab == 'practical' ? 'active' : ''; ?>" onclick="switchTab('practical')">1. Practical Skills</div>
        <div class="tab <?php echo $current_tab == 'project'   ? 'active' : ''; ?>" onclick="switchTab('project')">2. Project Output</div>
        <div class="tab <?php echo $current_tab == 'oral'      ? 'active' : ''; ?>" onclick="switchTab('oral')">3. Oral Questions</div>
        <div class="tab <?php echo $current_tab == 'summary'   ? 'active' : ''; ?>" onclick="switchTab('summary')">4. Summary &amp; Result</div>
    </div>

    <form id="switchTabForm" method="GET" style="display:none;">
        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
        <input type="hidden" name="tab" id="tabInput" value="">
    </form>

    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h3>Confirm Permanent Deletion</h3>
            <p id="deleteModalMessage"></p>
            <input type="hidden" id="deleteType">
            <input type="hidden" id="deleteEnrollmentId" value="<?php echo $enrollment_id; ?>">
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button class="clear-answers-btn" onclick="closeDeleteModal()">Cancel</button>
                <button class="delete-btn" onclick="confirmDelete()">Yes, Delete Permanently</button>
            </div>
        </div>
    </div>

    <!-- ===================== TAB 1: PRACTICAL SKILLS ===================== -->
    <div class="assessment-card" id="tab-practical" style="display:<?php echo $current_tab == 'practical' ? 'block' : 'none'; ?>;">
        <div class="card-title">
            <i class="fas fa-utensils"></i> Practical Skills Assessment
            <span style="margin-left:auto;background:#667eea;color:white;padding:5px 15px;border-radius:20px;">
                Total: <span id="practicalTotal"><?php echo $practical_total; ?></span>/<span id="practicalMaxTotal"><?php echo $practical_max_total; ?></span>
            </span>
            <?php if ($existing_assessment['practical_skills_saved'] ?? 0): ?>
                <span class="saved-status status-saved"><i class="fas fa-check-circle"></i> Skills Saved</span>
            <?php else: ?>
                <span class="saved-status status-unsaved"><i class="fas fa-exclamation-triangle"></i> Skills Not Saved</span>
            <?php endif; ?>
        </div>

        <div class="alert-info"><i class="fas fa-info-circle"></i> Create selective practical skills for this trainee.</div>

        <div class="passing-percentage-group">
            <label><i class="fas fa-percent"></i> Practical Skills Passing Score (65% – 100%):</label>
            <div class="passing-percentage-wrapper">
                <input type="number" id="practical_passing_percentage" class="passing-percentage-input"
                       value="<?php echo $existing_assessment['practical_passing_percentage'] ?? 75; ?>"
                       min="65" max="100" step="0.5">
                <button type="button" class="btn-save-settings" id="savePracticalSettingsBtn">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </div>

        <div class="trainee-section">
            <h4><i class="fas fa-list"></i> Skills List</h4>
            <div class="button-group">
                <button type="button" class="delete-btn" onclick="openDeleteModal('practical')">
                    <i class="fas fa-trash"></i> Delete All Skills
                </button>
            </div>

            <div id="trainee-skills-container">
                <?php if (!empty($trainee_skills)): ?>
                    <?php foreach ($trainee_skills as $skill): ?>
                    <div class="custom-skill-row" data-skill-id="<?php echo $skill['id']; ?>">
                        <div style="display:flex;gap:20px;align-items:center;">
                            <div style="flex:2;">
                                <input type="text" class="form-control trainee-skill-name"
                                       value="<?php echo htmlspecialchars($skill['skill_name']); ?>">
                                <span class="input-label">Skill name</span>
                            </div>
                            <div style="flex:1;">
                                <input type="number" class="form-control trainee-skill-max"
                                       value="<?php echo $skill['max_score']; ?>" min="1" max="100">
                                <span class="input-label">Max score</span>
                            </div>
                            <div style="flex:0.3;">
                                <button type="button" class="remove-btn" onclick="removeSingleSkill(<?php echo $skill['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="waiting-message"><i class="fas fa-info-circle"></i> No skills created yet.</div>
                <?php endif; ?>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="add-btn" onclick="addTraineeSkill()">
                    <i class="fas fa-plus"></i> Add Skill
                </button>
                <button type="button" class="save-trainee-skills-btn" onclick="saveTraineeSkills()">
                    <i class="fas fa-save"></i> Save Skills
                </button>
            </div>

            <div class="form-group" style="margin-top:20px;">
                <label class="form-label">Notes / Observations:</label>
                <textarea id="practical_notes" class="form-control" rows="3"><?php echo htmlspecialchars($existing_assessment['practical_notes'] ?? ''); ?></textarea>
            </div>

            <div class="total-display">
                Total Maximum Score: <span id="practicalMaxTotalValue"><?php echo $practical_max_total; ?></span>
            </div>
        </div>

        <?php if (!empty($trainee_skills)): ?>
        <div class="scoring-section">
            <h4><i class="fas fa-star"></i> Enter Scores</h4>
            <div id="practical-scoring-container">
                <?php foreach ($trainee_skills as $skill): ?>
                <div class="custom-skill-row" style="border-left-color:#ffc107;">
                    <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                        <div style="flex:2;">
                            <strong><?php echo htmlspecialchars($skill['skill_name']); ?></strong>
                            <div class="score-max">Max: <?php echo $skill['max_score']; ?> points</div>
                        </div>
                        <div style="flex:1;">
                            <input type="number" class="score-input practical-score"
                                   data-skill-id="<?php echo $skill['id']; ?>"
                                   value="<?php echo $skill['score'] !== null ? $skill['score'] : ''; ?>"
                                   min="0" max="<?php echo $skill['max_score']; ?>"
                                   placeholder="Score" onchange="updatePracticalScoringTotal()">
                            <span>/ <?php echo $skill['max_score']; ?></span>
                        </div>
                        <div>
                            <?php if ($skill['score'] !== null): ?>
                                <?php if ($skill['score'] >= ($skill['max_score'] * ($existing_assessment['practical_passing_percentage'] ?? 75) / 100)): ?>
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

            <div style="margin-top:15px;display:flex;justify-content:flex-end;">
                <button type="button" class="save-scores-btn" onclick="savePracticalScores()">
                    <i class="fas fa-save"></i> Save Scores
                </button>
            </div>

            <div class="total-display" style="margin-top:15px;background:#fff3cd;">
                Subtotal: <span id="practicalScoringTotal"><?php echo $practical_total; ?></span>/<span id="practicalScoringMax"><?php echo $practical_max_total; ?></span>
                <?php if ($practical_has_all_scores): ?>
                    <?php if ($practical_total >= ($practical_max_total * ($existing_assessment['practical_passing_percentage'] ?? 75) / 100)): ?>
                        <span class="pass-badge">PASSED</span>
                    <?php else: ?>
                        <span class="fail-badge">FAILED</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pending-badge">INCOMPLETE</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:30px;">
            <button type="button" class="save-tab-btn" onclick="switchTab('summary')">
                <i class="fas fa-arrow-right"></i> Proceed to Summary
            </button>
        </div>
    </div>

    <!-- ===================== TAB 2: PROJECT OUTPUT ===================== -->
    <div class="assessment-card" id="tab-project" style="display:<?php echo $current_tab == 'project' ? 'block' : 'none'; ?>;">
        <div class="card-title">
            <i class="fas fa-project-diagram"></i> Project Output
            <span style="margin-left:auto;background:#667eea;color:white;padding:5px 15px;border-radius:20px;">
                Score: <span id="project_total_display"><?php echo $project_score; ?></span>/<span id="project_total_max_display"><?php echo $project_total_max; ?></span>
            </span>
            <div class="toggle-container">
                <span class="toggle-label">Show to Trainee:</span>
                <label class="toggle-switch">
                    <input type="checkbox"
                           onchange="toggleVisibility('project', <?php echo $enrollment_id; ?>, this)"
                           <?php echo ($existing_assessment['project_visible_to_trainee'] ?? 0) ? 'checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
                <?php if ($existing_assessment['project_visible_to_trainee'] ?? 0): ?>
                    <span class="visibility-status status-visible"><i class="fas fa-eye"></i> Visible</span>
                <?php else: ?>
                    <span class="visibility-status status-hidden"><i class="fas fa-eye-slash"></i> Hidden</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="passing-percentage-group">
            <label><i class="fas fa-percent"></i> Project Output Passing Score (65% – 100%):</label>
            <div class="passing-percentage-wrapper">
                <input type="number" id="project_passing_percentage" class="passing-percentage-input"
                       value="<?php echo $existing_assessment['project_passing_percentage'] ?? 75; ?>"
                       min="65" max="100" step="0.5">
                <button type="button" class="btn-save-settings" id="saveProjectSettingsBtn">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </div>

        <div class="trainer-project-setup" style="background:#f0f7ff;padding:20px;border-radius:10px;margin-bottom:20px;border-left:5px solid #667eea;">
            <h4><i class="fas fa-pen-alt"></i> Project Setup</h4>

            <div class="form-group">
                <label class="form-label">Project Title:</label>
                <input type="text" id="project_title_override" class="form-control"
                       value="<?php echo htmlspecialchars($existing_assessment['project_title_override'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Instructions:</label>
                <textarea id="project_instruction" class="form-control" rows="4"><?php echo htmlspecialchars($existing_assessment['project_instruction'] ?? ''); ?></textarea>
                <?php if (!empty($existing_assessment['project_instruction'])): ?>
               
                <?php endif; ?>
            </div>

            <!-- RUBRIC SECTION -->
            <div class="rubric-container">
                <div class="rubric-header">
                    <h3><i class="fas fa-chart-line"></i> Grading Rubric</h3>
                    <p>Define criteria and max points for evaluation. Scores are entered in the trainee submission section below.</p>
                </div>

                <div id="rubric-criteria-container">
                    <?php if (!empty($project_rubrics_data)): ?>
                        <?php foreach ($project_rubrics_data as $index => $criterion): ?>
                        <div class="rubric-criterion" data-criterion-index="<?php echo $index; ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                <h4><i class="fas fa-check-circle"></i> Criterion <?php echo $index + 1; ?>:
                                    <input type="text" class="form-control criterion-name"
                                           style="display:inline-block;width:auto;min-width:200px;"
                                           value="<?php echo htmlspecialchars($criterion['name'] ?? ''); ?>"
                                           placeholder="Criterion name">
                                </h4>
                                <button type="button" class="remove-rubric-btn" onclick="removeRubricCriterion(this)"><i class="fas fa-trash"></i> Remove</button>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Max Points: </label>
                                <input type="number" class="form-control rubric-max-score"
                                       style="width:100px;display:inline-block;"
                                       value="<?php echo $criterion['max_score'] ?? 20; ?>"
                                       min="1" max="1000" step="1" onchange="updateRubricTotal()">
                            </div>
                            <div class="rubric-score-display">Score will be entered in trainee submission section below</div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php
                        $default_criteria = [
                            ['name' => 'Content Quality',           'max_score' => 30],
                            ['name' => 'Design & Creativity',       'max_score' => 25],
                            ['name' => 'Technical Execution',       'max_score' => 25],
                            ['name' => 'Presentation & Documentation','max_score' => 20],
                        ];
                        foreach ($default_criteria as $i => $dc):
                        ?>
                        <div class="rubric-criterion" data-criterion-index="<?php echo $i; ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                <h4><i class="fas fa-check-circle"></i> Criterion <?php echo $i + 1; ?>:
                                    <input type="text" class="form-control criterion-name"
                                           style="display:inline-block;width:auto;min-width:200px;"
                                           value="<?php echo htmlspecialchars($dc['name']); ?>">
                                </h4>
                                <button type="button" class="remove-rubric-btn" onclick="removeRubricCriterion(this)"><i class="fas fa-trash"></i> Remove</button>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Max Points: </label>
                                <input type="number" class="form-control rubric-max-score"
                                       style="width:100px;display:inline-block;"
                                       value="<?php echo $dc['max_score']; ?>"
                                       min="1" max="1000" step="1" onchange="updateRubricTotal()">
                            </div>
                            <div class="rubric-score-display">Score will be entered in trainee submission section below</div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top:15px;display:flex;gap:10px;">
                    <button type="button" class="add-rubric-btn" onclick="addRubricCriterion()">
                        <i class="fas fa-plus"></i> Add Criterion
                    </button>
                </div>
                <div class="rubric-total">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <h4 style="margin:0;">Rubric Summary:</h4>
                        <div style="text-align:right;">
                            <span style="font-size:14px;color:#666;">Total Max Points: </span>
                            <span id="rubric-max-total" style="font-size:18px;font-weight:bold;color:#667eea;">0</span>
                        </div>
                    </div>
                    <p class="rubric-score-display">Project total max points = Sum of all rubric max scores</p>
                </div>
                
            </div>
           <center><button type="button" class="btn-save-rubrics" onclick="saveRubrics()">
                <i class="fas fa-save"></i> Save Setting
            </button> </center>
        </div>

        <?php if (!empty($existing_assessment['project_submitted_by_trainee'])): ?>
        <div style="background:#e8f5e9;padding:20px;border-radius:10px;margin-bottom:20px;">
            <h4><i class="fas fa-check-circle" style="color:#28a745;"></i> Trainee's Submission</h4>
            <p><strong>Title:</strong> <?php echo htmlspecialchars($existing_assessment['project_title'] ?? ''); ?></p>
            <p><strong>Description:</strong></p>
            <div style="background:#fff;padding:12px;border-radius:6px;margin-top:6px;white-space:pre-wrap;"><?php echo htmlspecialchars($existing_assessment['project_description'] ?? ''); ?></div>
            <?php if (!empty($existing_assessment['project_photo_path'])): ?>
                <img src="/<?php echo htmlspecialchars($existing_assessment['project_photo_path']); ?>"
                     style="max-width:100%;max-height:300px;border-radius:8px;margin-top:12px;">
            <?php endif; ?>

            <div class="trainee-submission-score" style="margin-top:20px;">
                <div class="submission-score-label"><i class="fas fa-star"></i> Score per Criterion:</div>
                <div id="project-scoring-container">
                    <?php foreach ($project_rubrics_data as $index => $criterion): ?>
                    <div class="custom-skill-row" style="border-left-color:#ffc107;margin-top:10px;">
                        <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                            <div style="flex:2;">
                                <strong><?php echo htmlspecialchars($criterion['name'] ?? 'Criterion ' . ($index + 1)); ?></strong>
                                <div class="score-max">Max: <?php echo $criterion['max_score']; ?> points</div>
                            </div>
                            <div style="flex:1;">
                                <input type="number" class="score-input project-criterion-score"
                                       data-criterion-index="<?php echo $index; ?>"
                                       data-max-score="<?php echo $criterion['max_score']; ?>"
                                       value="<?php echo $criterion['score'] ?? 0; ?>"
                                       min="0" max="<?php echo $criterion['max_score']; ?>"
                                       placeholder="Score" onchange="updateProjectScoringTotal()">
                                <span>/ <?php echo $criterion['max_score']; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:15px;display:flex;justify-content:flex-end;">
                    <button type="button" class="save-scores-btn" onclick="saveProjectScores()">
                        <i class="fas fa-save"></i> Save Scores
                    </button>
                </div>
            </div>
        </div>

        <div style="margin-top:20px;">
            <h4>Trainer's Evaluation Notes</h4>
            <div class="form-group">
                <label class="form-label">Feedback:</label>
                <textarea id="project_notes" class="form-control" rows="3"><?php echo htmlspecialchars($existing_assessment['project_notes'] ?? ''); ?></textarea>
            </div>
        </div>
        <?php else: ?>
        <div class="waiting-message">
            <i class="fas fa-clock"></i>
            <h3>Waiting for Trainee Submission</h3>
        </div>
        <?php endif; ?>

        <div class="total-display" style="margin-top:15px;background:#fff3cd;">
            Project Score: <span id="projectScoringTotal"><?php echo $project_score; ?></span>/<span id="projectScoringMax"><?php echo $project_total_max; ?></span>
            <?php $project_percentage = $project_total_max > 0 ? ($project_score / $project_total_max) * 100 : 0; ?>
            <?php if ($project_percentage >= ($existing_assessment['project_passing_percentage'] ?? 75)): ?>
                <span class="pass-badge">PASSED</span>
            <?php else: ?>
                <span class="fail-badge">FAILED</span>
            <?php endif; ?>
        </div>

        <div style="text-align:center;margin-top:30px;">
            <button type="button" class="save-tab-btn" onclick="saveProjectTab()">
                <i class="fas fa-save"></i> Save Project Evaluation
            </button>
        </div>
    </div>

    <!-- ===================== TAB 3: ORAL QUESTIONS ===================== -->
    <div class="assessment-card" id="tab-oral" style="display:<?php echo $current_tab == 'oral' ? 'block' : 'none'; ?>;">
        <div class="card-title">
            <i class="fas fa-question-circle"></i> Oral Questions
            <span style="margin-left:auto;background:#667eea;color:white;padding:5px 15px;border-radius:20px;">
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
                    <span class="visibility-status status-visible"><i class="fas fa-eye"></i> Visible</span>
                <?php else: ?>
                    <span class="visibility-status status-hidden"><i class="fas fa-eye-slash"></i> Hidden</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert-info"><i class="fas fa-info-circle"></i> Create selective oral questions for this trainee.</div>

        <div class="passing-percentage-group">
            <label><i class="fas fa-percent"></i> Oral Questions Passing Score (65% – 100%):</label>
            <div class="passing-percentage-wrapper">
                <input type="number" id="oral_passing_percentage" class="passing-percentage-input"
                       value="<?php echo $existing_assessment['oral_passing_percentage'] ?? 75; ?>"
                       min="65" max="100" step="0.5">
                <button type="button" class="btn-save-settings" id="saveOralSettingsBtn">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </div>

        <div style="margin-bottom:20px;">
            <?php if ($existing_assessment['oral_submitted_by_trainee'] ?? 0): ?>
                <span class="answer-status answer-submitted"><i class="fas fa-check-circle"></i> Answers Submitted</span>
            <?php else: ?>
                <span class="answer-status answer-not-submitted"><i class="fas fa-clock"></i> Waiting for Answers</span>
            <?php endif; ?>

            <?php if (!empty($trainee_questions) && ($existing_assessment['oral_submitted_by_trainee'] ?? 0)): ?>
            <form method="POST" style="display:inline;margin-left:10px;">
                <input type="hidden" name="clear_oral_answers" value="1">
                <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                <button type="submit" class="clear-answers-btn">
                    <i class="fas fa-eraser"></i> Clear All Answers
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="trainee-section">
            <h4><i class="fas fa-list"></i> Questions List</h4>
            <div class="button-group">
                <button type="button" class="delete-btn" onclick="openDeleteModal('oral')">
                    <i class="fas fa-trash"></i> Delete All Questions
                </button>
            </div>

            <div id="trainee-questions-container">
                <?php if (!empty($trainee_questions)): ?>
                    <?php foreach ($trainee_questions as $q): ?>
                    <div class="custom-skill-row" data-question-id="<?php echo $q['id']; ?>">
                        <div style="display:flex;gap:20px;align-items:center;">
                            <div style="flex:2;">
                                <input type="text" class="form-control trainee-question"
                                       value="<?php echo htmlspecialchars($q['question']); ?>">
                                <span class="input-label">Question</span>
                            </div>
                            <div style="flex:1;">
                                <input type="number" class="form-control trainee-question-max"
                                       value="<?php echo $q['max_score']; ?>" min="1" max="100">
                                <span class="input-label">Max score</span>
                            </div>
                            <div style="flex:0.3;">
                                <button type="button" class="remove-btn" onclick="removeSingleQuestion(<?php echo $q['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="waiting-message"><i class="fas fa-info-circle"></i> No questions created yet.</div>
                <?php endif; ?>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="add-btn" onclick="addTraineeQuestion()">
                    <i class="fas fa-plus"></i> Add Question
                </button>
                <button type="button" class="save-trainee-questions-btn" onclick="saveTraineeQuestions()">
                    <i class="fas fa-save"></i> Save Questions
                </button>
            </div>
        </div>

        <?php if (!empty($trainee_questions)): ?>
        <div class="scoring-section" style="border-color:#8b5cf6;">
            <h4><i class="fas fa-star"></i> Score Oral Questions</h4>
            <div id="oral-scoring-container">
                <?php foreach ($trainee_questions as $index => $question): ?>
                <div class="custom-skill-row" style="border-left-color:#8b5cf6;">
                    <div>
                        <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($question['question']); ?>
                        <div class="score-max">Max: <?php echo $question['max_score']; ?> points</div>

                        <?php
                        // Display answer — raw from DB, just clean display
                        $answer_text = $oral_answers_array[$index] ?? '';
                        if (!empty($answer_text)) {
                            $answer_text = trim($answer_text);
                            // Strip JSON encoding if trainee app sent it as JSON string
                            $decoded = json_decode($answer_text, true);
                            if ($decoded !== null) {
                                $answer_text = is_array($decoded) ? implode(', ', $decoded) : (string)$decoded;
                            }
                        }
                        ?>
                        <?php if (!empty($answer_text)): ?>
                        <div class="answer-box">
                            <strong>Answer:</strong>
                            <div style="white-space:pre-wrap;margin-top:6px;"><?php echo htmlspecialchars($answer_text); ?></div>
                        </div>
                        <?php else: ?>
                        <div class="no-answer">No answer provided yet.</div>
                        <?php endif; ?>

                        <div style="margin-top:10px;">
                            <input type="number" class="score-input oral-score"
                                   data-question-id="<?php echo $question['id']; ?>"
                                   value="<?php echo $question['score'] !== null ? $question['score'] : ''; ?>"
                                   min="0" max="<?php echo $question['max_score']; ?>"
                                   placeholder="Score" onchange="updateOralScoringTotal()">
                            <span>/ <?php echo $question['max_score']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:15px;display:flex;justify-content:flex-end;">
                <button type="button" class="save-scores-btn" onclick="saveOralScores()">
                    <i class="fas fa-save"></i> Save Scores
                </button>
            </div>

            <div class="total-display" style="margin-top:15px;">
                Subtotal: <span id="oralScoringTotal"><?php echo $oral_score; ?></span>/<span id="oralScoringMax"><?php echo $oral_max; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:30px;">
            <button type="button" class="save-tab-btn" onclick="switchTab('summary')">
                <i class="fas fa-arrow-right"></i> Proceed to Summary
            </button>
        </div>
    </div>

    <!-- ===================== TAB 4: SUMMARY ===================== -->
    <div class="assessment-card" id="tab-summary" style="display:<?php echo $current_tab == 'summary' ? 'block' : 'none'; ?>;">
        <div class="card-title">
            <i class="fas fa-table"></i> Assessment Summary
            <div style="margin-left:auto;">
                <button class="print-btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <div class="summary-cards">
            <div class="summary-card">
                <h3>Practical Skills</h3>
                <div class="score"><?php echo $practical_total; ?></div>
                <div class="max-score">out of <?php echo $practical_max_total; ?></div>
                <div class="max-score">Passing: <?php echo $practical_passing; ?>%</div>
                <?php if (empty($trainee_skills)): ?>
                    <div>NO SKILLS</div>
                <?php elseif (!$practical_has_all_scores): ?>
                    <div>INCOMPLETE</div>
                <?php elseif ($practical_total >= ($practical_max_total * $practical_passing / 100)): ?>
                    <div>PASSED</div>
                <?php else: ?>
                    <div>FAILED</div>
                <?php endif; ?>
            </div>

            <div class="summary-card">
                <h3>Project Output</h3>
                <div class="score"><?php echo $project_score; ?></div>
                <div class="max-score">out of <?php echo $project_total_max; ?></div>
                <div class="max-score">Passing: <?php echo $project_passing; ?>%</div>
                <?php $project_pct = $project_total_max > 0 ? ($project_score / $project_total_max) * 100 : 0; ?>
                <div><?php echo $project_pct >= $project_passing ? 'PASSED' : 'FAILED'; ?> (<?php echo round($project_pct, 1); ?>%)</div>
            </div>

            <div class="summary-card">
                <h3>Oral Examination</h3>
                <div class="score"><?php echo $oral_score; ?></div>
                <div class="max-score">out of <?php echo $oral_max; ?></div>
                <div class="max-score">Passing: <?php echo $oral_passing; ?>%</div>
                <?php if (empty($trainee_questions)): ?>
                    <div>NO QUESTIONS</div>
                <?php elseif (!$oral_has_all_scores): ?>
                    <div>INCOMPLETE</div>
                <?php elseif ($oral_score >= ($oral_max * $oral_passing / 100)): ?>
                    <div>PASSED</div>
                <?php else: ?>
                    <div>FAILED</div>
                <?php endif; ?>
            </div>

            <div class="summary-card <?php echo $overall_result == 'PASSED' ? 'passed' : 'failed'; ?>">
                <h3>Overall Result</h3>
                <div class="score"><?php echo $overall_percent; ?>%</div>
                <div class="max-score">Total: <?php echo $total_score; ?>/<?php echo $total_max; ?></div>
                <div class="max-score">Required: <?php echo round($overall_passing_percentage, 1); ?>%</div>
                <div style="font-size:20px;"><?php echo $overall_result; ?></div>
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

        <div style="text-align:center;margin-top:30px;">
            <form method="POST" onsubmit="return confirmFinalize();">
                <input type="hidden" name="save_assessment" value="1">
                <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                <button type="submit" class="submit-btn">
                    <i class="fas fa-save"></i> FINALIZE ASSESSMENT
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const enrollmentId = <?php echo $enrollment_id; ?>;
let traineeSkillCount    = <?php echo count($trainee_skills); ?>;
let traineeQuestionCount = <?php echo count($trainee_questions); ?>;

function switchTab(tabName) {
    document.getElementById('tabInput').value = tabName;
    document.getElementById('switchTabForm').submit();
}

function toggleVisibility(type, enrollmentId, checkbox) {
    const newValue = checkbox.checked ? 1 : 0;
    Swal.fire({ title: 'Updating...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('ajax_toggle', '1');
    fd.append('type', type);
    fd.append('enrollment_id', enrollmentId);
    fd.append('set', newValue);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { Swal.close(); if (!data.success) { checkbox.checked = !checkbox.checked; Swal.fire('Error', data.message, 'error'); } })
        .catch(() => { Swal.close(); checkbox.checked = !checkbox.checked; Swal.fire('Error', 'An error occurred', 'error'); });
}

// ── Rubric helpers ──────────────────────────────────────────────────────────
let rubricCriterionCount = <?php echo max(count($project_rubrics_data), 4); ?>;

function addRubricCriterion() {
    rubricCriterionCount++;
    const container = document.getElementById('rubric-criteria-container');
    const el = document.createElement('div');
    el.className = 'rubric-criterion';
    el.setAttribute('data-criterion-index', rubricCriterionCount - 1);
    el.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h4><i class="fas fa-check-circle"></i> Criterion ${rubricCriterionCount}:
                <input type="text" class="form-control criterion-name" style="display:inline-block;width:auto;min-width:200px;" value="New Criterion">
            </h4>
            <button type="button" class="remove-rubric-btn" onclick="removeRubricCriterion(this)"><i class="fas fa-trash"></i> Remove</button>
        </div>
        <div style="margin-bottom:10px;">
            <label>Max Points: </label>
            <input type="number" class="form-control rubric-max-score" style="width:100px;display:inline-block;" value="20" min="1" max="1000" step="1" onchange="updateRubricTotal()">
        </div>
        <div class="rubric-score-display">Score will be entered in trainee submission section below</div>`;
    container.appendChild(el);
    updateRubricTotal();
}

function removeRubricCriterion(btn) {
    btn.closest('.rubric-criterion')?.remove();
    document.querySelectorAll('.rubric-criterion').forEach((c, idx) => {
        const h4 = c.querySelector('h4');
        if (!h4) return;
        const inp = h4.querySelector('.criterion-name');
        const val = inp ? inp.value : '';
        h4.innerHTML = `<i class="fas fa-check-circle"></i> Criterion ${idx + 1}: `;
        const newInp = document.createElement('input');
        newInp.type = 'text'; newInp.className = 'form-control criterion-name';
        newInp.style.cssText = 'display:inline-block;width:auto;min-width:200px;';
        newInp.value = val;
        h4.appendChild(newInp);
        const rmBtn = document.createElement('button');
        rmBtn.type = 'button'; rmBtn.className = 'remove-rubric-btn';
        rmBtn.innerHTML = '<i class="fas fa-trash"></i> Remove';
        rmBtn.onclick = function() { removeRubricCriterion(this); };
        h4.appendChild(rmBtn);
    });
    updateRubricTotal();
}

function getRubricData() {
    const savedRubrics = <?php echo json_encode($project_rubrics_data); ?>;
    return Array.from(document.querySelectorAll('.rubric-criterion')).map((c, idx) => {
        const nameInp = c.querySelector('.criterion-name');
        const maxInp  = c.querySelector('.rubric-max-score');
        const scoreInp = document.querySelector(`.project-criterion-score[data-criterion-index="${idx}"]`);
        const savedScore = savedRubrics && savedRubrics[idx] ? (savedRubrics[idx].score || 0) : 0;
        return {
            name: nameInp ? nameInp.value : `Criterion ${idx + 1}`,
            max_score: parseFloat(maxInp?.value) || 0,
            score: scoreInp ? (parseFloat(scoreInp.value) || 0) : savedScore
        };
    });
}

function updateRubricTotal() {
    let totalMax = 0;
    document.querySelectorAll('.rubric-criterion .rubric-max-score').forEach(i => totalMax += parseFloat(i.value) || 0);
    const el = document.getElementById('rubric-max-total');
    const dispEl = document.getElementById('project_total_max_display');
    const scoringMax = document.getElementById('projectScoringMax');
    if (el) el.textContent = totalMax.toFixed(1);
    if (dispEl) dispEl.textContent = totalMax;
    if (scoringMax) scoringMax.textContent = totalMax;
}

function updateProjectScoringTotal() {
    let earned = 0, max = 0;
    document.querySelectorAll('.project-criterion-score').forEach(i => {
        max    += parseFloat(i.dataset.maxScore) || 0;
        earned += parseFloat(i.value) || 0;
    });
    const totEl = document.getElementById('projectScoringTotal');
    const maxEl = document.getElementById('projectScoringMax');
    const dispEl = document.getElementById('project_total_display');
    if (totEl)  totEl.textContent = earned;
    if (maxEl)  maxEl.textContent = max;
    if (dispEl) dispEl.textContent = earned;
}

function saveProjectScores() {
    const rubrics = getRubricData();
    const totalMax    = rubrics.reduce((s, c) => s + c.max_score, 0);
    const totalEarned = rubrics.reduce((s, c) => s + c.score, 0);
    Swal.fire({ title: 'Saving Scores...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('save_rubrics', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('rubrics_data', JSON.stringify(rubrics));
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Scores Saved!',
                    html: `Project Score: ${totalEarned}/${totalMax} points<br>Percentage: ${totalMax > 0 ? ((totalEarned/totalMax)*100).toFixed(1) : 0}%`,
                    timer: 3000 });
            } else { Swal.fire('Error', data.message, 'error'); }
        })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
}

function saveRubrics() {
    const criteria = Array.from(document.querySelectorAll('.rubric-criterion')).map((c, idx) => ({
        name: c.querySelector('.criterion-name')?.value || `Criterion ${idx + 1}`,
        max_score: parseFloat(c.querySelector('.rubric-max-score')?.value) || 0,
        score: 0
    }));
    
    // Get the project title and instruction values
    const projectTitle = document.getElementById('project_title_override')?.value || '';
    const projectInstruction = document.getElementById('project_instruction')?.value || '';
    
    const totalMax = criteria.reduce((s, c) => s + c.max_score, 0);
    
    Swal.fire({ 
        title: 'Saving Rubrics & Project Settings...', 
        allowOutsideClick: false, 
        showConfirmButton: false, 
        didOpen: () => Swal.showLoading() 
    });
    
    const fd = new FormData();
    fd.append('save_rubrics', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('rubrics_data', JSON.stringify(criteria));
    fd.append('project_title_override', projectTitle);
    fd.append('project_instruction', projectInstruction);
    
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ 
                    icon: 'success', 
                    title: 'Saved!', 
                    html: `Project settings and rubrics saved successfully!<br>Total Max Points: ${totalMax}`,
                    timer: 3000 
                });
                updateRubricTotal();
                // Update the preview display
                updateInstructionPreview();
                setTimeout(() => location.reload(), 1600);
            } else { 
                Swal.fire('Error', data.message, 'error'); 
            }
        })
        .catch(() => { 
            Swal.close(); 
            Swal.fire('Error', 'An error occurred', 'error'); 
        });
}

// Add this new function to update instruction preview
function updateInstructionPreview() {
    const instructionText = document.getElementById('project_instruction')?.value || '';
    const previewDiv = document.querySelector('.instruction-display');
    if (previewDiv) {
        // Convert newlines to <br> for preview
        previewDiv.innerHTML = instructionText.replace(/\n/g, '<br>');
    }
}

// ── Settings save helpers (submit full form) ────────────────────────────────
function addField(form, name, value) {
    const i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    form.appendChild(i);
}

function savePracticalSettingsOnly() {
    const f = document.createElement('form'); f.method = 'POST';
    addField(f, 'save_tab', '1');
    addField(f, 'enrollment_id', enrollmentId);
    addField(f, 'tab', 'practical');
    addField(f, 'practical_passing_percentage', document.getElementById('practical_passing_percentage').value);
    addField(f, 'practical_notes', document.getElementById('practical_notes')?.value || '');
    document.body.appendChild(f); f.submit();
}

function saveProjectSettingsOnly() {
    const f = document.createElement('form'); f.method = 'POST';
    addField(f, 'save_tab', '1');
    addField(f, 'enrollment_id', enrollmentId);
    addField(f, 'tab', 'project');
    addField(f, 'project_score', document.getElementById('projectScoringTotal')?.textContent || 0);
    addField(f, 'project_total_max', document.getElementById('projectScoringMax')?.textContent || 100);
    addField(f, 'project_notes', document.getElementById('project_notes')?.value || '');
    addField(f, 'project_passing_percentage', document.getElementById('project_passing_percentage').value);
    addField(f, 'project_instruction', document.getElementById('project_instruction')?.value || '');
    addField(f, 'project_rubrics', JSON.stringify(getRubricData()));
    addField(f, 'project_title_override', document.getElementById('project_title_override')?.value || '');
    document.body.appendChild(f); f.submit();
}

function saveProjectTab() {
    const f = document.createElement('form'); f.method = 'POST';
    addField(f, 'save_tab', '1');
    addField(f, 'enrollment_id', enrollmentId);
    addField(f, 'tab', 'project');
    addField(f, 'project_score', document.getElementById('projectScoringTotal')?.textContent || 0);
    addField(f, 'project_total_max', document.getElementById('projectScoringMax')?.textContent || 100);
    addField(f, 'project_notes', document.getElementById('project_notes')?.value || '');
    addField(f, 'project_passing_percentage', document.getElementById('project_passing_percentage').value);
    addField(f, 'project_instruction', document.getElementById('project_instruction')?.value || '');
    addField(f, 'project_rubrics', JSON.stringify(getRubricData()));
    addField(f, 'project_title_override', document.getElementById('project_title_override')?.value || '');
    document.body.appendChild(f); f.submit();
}

function saveOralSettingsOnly() {
    const f = document.createElement('form'); f.method = 'POST';
    addField(f, 'save_tab', '1');
    addField(f, 'enrollment_id', enrollmentId);
    addField(f, 'tab', 'oral');
    addField(f, 'oral_passing_percentage', document.getElementById('oral_passing_percentage').value);
    addField(f, 'oral_notes', document.getElementById('oral_notes')?.value || '');
    document.body.appendChild(f); f.submit();
}

// ── Skill / Question list management ───────────────────────────────────────
function addTraineeSkill() {
    traineeSkillCount++;
    const container = document.getElementById('trainee-skills-container');
    container.querySelector('.waiting-message')?.remove();
    const row = document.createElement('div');
    row.className = 'custom-skill-row';
    row.innerHTML = `
        <div style="display:flex;gap:20px;align-items:center;">
            <div style="flex:2;">
                <input type="text" class="form-control trainee-skill-name" value="New Skill ${traineeSkillCount}">
                <span class="input-label">Skill name</span>
            </div>
            <div style="flex:1;">
                <input type="number" class="form-control trainee-skill-max" value="20" min="1" max="100">
                <span class="input-label">Max score</span>
            </div>
            <div style="flex:0.3;">
                <button type="button" class="remove-btn" onclick="this.closest('.custom-skill-row').remove();updatePracticalMaxTotal();">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>`;
    container.appendChild(row);
    updatePracticalMaxTotal();
}

function saveTraineeSkills() {
    const skills = [];
    document.querySelectorAll('#trainee-skills-container .custom-skill-row').forEach(row => {
        const n = row.querySelector('.trainee-skill-name');
        const m = row.querySelector('.trainee-skill-max');
        if (n && n.value.trim()) skills.push({ name: n.value.trim(), max_score: parseInt(m.value) || 20 });
    });
    if (!skills.length) { Swal.fire('Error', 'Please add at least one skill', 'warning'); return; }
    Swal.fire({ title: 'Saving...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('save_trainee_practical_skills', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('trainee_skills', JSON.stringify(skills));
    fd.append('ajax', '1');
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { Swal.close(); if (data.success) Swal.fire('Saved!', '', 'success').then(() => location.reload()); else Swal.fire('Error', data.message, 'error'); })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
}

function addTraineeQuestion() {
    traineeQuestionCount++;
    const container = document.getElementById('trainee-questions-container');
    container.querySelector('.waiting-message')?.remove();
    const row = document.createElement('div');
    row.className = 'custom-skill-row';
    row.innerHTML = `
        <div style="display:flex;gap:20px;align-items:center;">
            <div style="flex:2;">
                <input type="text" class="form-control trainee-question" value="New Question ${traineeQuestionCount}">
                <span class="input-label">Question</span>
            </div>
            <div style="flex:1;">
                <input type="number" class="form-control trainee-question-max" value="25" min="1" max="100">
                <span class="input-label">Max score</span>
            </div>
            <div style="flex:0.3;">
                <button type="button" class="remove-btn" onclick="this.closest('.custom-skill-row').remove();updateOralMaxTotal();">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>`;
    container.appendChild(row);
    updateOralMaxTotal();
}

function saveTraineeQuestions() {
    const questions = [];
    document.querySelectorAll('#trainee-questions-container .custom-skill-row').forEach(row => {
        const q = row.querySelector('.trainee-question');
        const m = row.querySelector('.trainee-question-max');
        if (q && q.value.trim()) questions.push({ question: q.value.trim(), max_score: parseInt(m.value) || 25 });
    });
    if (!questions.length) { Swal.fire('Error', 'Please add at least one question', 'warning'); return; }
    Swal.fire({ title: 'Saving...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('save_trainee_oral_questions', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('trainee_questions', JSON.stringify(questions));
    fd.append('ajax', '1');
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { Swal.close(); if (data.success) Swal.fire('Saved!', '', 'success').then(() => location.reload()); else Swal.fire('Error', data.message, 'error'); })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
}

// ── Score totals ────────────────────────────────────────────────────────────
function updatePracticalScoringTotal() {
    let total = 0, max = 0;
    document.querySelectorAll('#practical-scoring-container .practical-score').forEach(i => {
        max += parseInt(i.max) || 0;
        if (i.value) total += parseFloat(i.value) || 0;
    });
    document.getElementById('practicalScoringTotal').textContent = total;
    document.getElementById('practicalScoringMax').textContent   = max;
    document.getElementById('practicalTotal').textContent        = total;
}

function updateOralScoringTotal() {
    let total = 0, max = 0;
    document.querySelectorAll('#oral-scoring-container .oral-score').forEach(i => {
        max += parseInt(i.max) || 0;
        if (i.value) total += parseFloat(i.value) || 0;
    });
    document.getElementById('oralScoringTotal').textContent = total;
    document.getElementById('oralScoringMax').textContent   = max;
    document.getElementById('oralTotal').textContent        = total;
}

function updatePracticalMaxTotal() {
    let max = 0;
    document.querySelectorAll('#trainee-skills-container .trainee-skill-max').forEach(i => max += parseFloat(i.value) || 0);
    if (max === 0) max = 100;
    document.getElementById('practicalMaxTotal').textContent      = max;
    document.getElementById('practicalMaxTotalValue').textContent = max;
}

function updateOralMaxTotal() {
    let max = 0;
    document.querySelectorAll('#trainee-questions-container .trainee-question-max').forEach(i => max += parseFloat(i.value) || 0);
    document.getElementById('oralMaxTotal').textContent = max;
}

// ── Save scores via AJAX ────────────────────────────────────────────────────
function savePracticalScores() {
    const scores = {};
    document.querySelectorAll('#practical-scoring-container .practical-score').forEach(i => {
        if (i.value) scores[i.dataset.skillId] = parseFloat(i.value);
    });
    if (!Object.keys(scores).length) { Swal.fire('Error', 'Please enter at least one score', 'warning'); return; }
    Swal.fire({ title: 'Saving...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('save_practical_scores', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('skill_scores', JSON.stringify(scores));
    fd.append('ajax', '1');
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { Swal.close(); if (data.success) Swal.fire('Saved!', '', 'success').then(() => location.reload()); else Swal.fire('Error', data.message, 'error'); })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
}

function saveOralScores() {
    const scores = {};
    document.querySelectorAll('#oral-scoring-container .oral-score').forEach(i => {
        if (i.value) scores[i.dataset.questionId] = parseFloat(i.value);
    });
    if (!Object.keys(scores).length) { Swal.fire('Error', 'Please enter at least one score', 'warning'); return; }
    Swal.fire({ title: 'Saving...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('save_oral_scores', '1');
    fd.append('enrollment_id', enrollmentId);
    fd.append('question_scores', JSON.stringify(scores));
    fd.append('ajax', '1');
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { Swal.close(); if (data.success) Swal.fire('Saved!', '', 'success').then(() => location.reload()); else Swal.fire('Error', data.message, 'error'); })
        .catch(() => { Swal.close(); Swal.fire('Error', 'An error occurred', 'error'); });
}

// ── Delete helpers ──────────────────────────────────────────────────────────
function openDeleteModal(type) {
    document.getElementById('deleteType').value = type;
    document.getElementById('deleteModalMessage').innerHTML = type === 'practical'
        ? 'Delete ALL practical skills? This cannot be undone.'
        : 'Delete ALL oral questions? This cannot be undone.';
    document.getElementById('deleteModal').style.display = 'block';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
function confirmDelete() {
    const type = document.getElementById('deleteType').value;
    const f = document.createElement('form'); f.method = 'POST';
    addField(f, type === 'practical' ? 'remove_trainee_skills' : 'remove_trainee_questions', '1');
    addField(f, 'enrollment_id', enrollmentId);
    document.body.appendChild(f); f.submit();
}

function removeSingleSkill(skillId) {
    Swal.fire({ title: 'Delete?', text: 'This cannot be undone', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' }).then(r => {
        if (r.isConfirmed) {
            const f = document.createElement('form'); f.method = 'POST';
            addField(f, 'remove_single_skill', '1');
            addField(f, 'skill_id', skillId);
            addField(f, 'enrollment_id', enrollmentId);
            document.body.appendChild(f); f.submit();
        }
    });
}

function removeSingleQuestion(questionId) {
    Swal.fire({ title: 'Delete?', text: 'This cannot be undone', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' }).then(r => {
        if (r.isConfirmed) {
            const f = document.createElement('form'); f.method = 'POST';
            addField(f, 'remove_single_question', '1');
            addField(f, 'question_id', questionId);
            addField(f, 'enrollment_id', enrollmentId);
            document.body.appendChild(f); f.submit();
        }
    });
}

function confirmFinalize() {
    return Swal.fire({ title: 'Finalize Assessment?', text: "This will update the trainee's status",
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#3085d6',
        confirmButtonText: 'Yes, finalize!' }).then(r => r.isConfirmed);
}

window.onclick = e => { if (e.target == document.getElementById('deleteModal')) closeDeleteModal(); };

document.addEventListener('DOMContentLoaded', () => {
    updatePracticalMaxTotal();
    updateOralMaxTotal();
    updatePracticalScoringTotal();
    updateOralScoringTotal();
    updateRubricTotal();
    updateProjectScoringTotal();

    document.getElementById('savePracticalSettingsBtn')?.addEventListener('click', savePracticalSettingsOnly);
    document.getElementById('saveProjectSettingsBtn')?.addEventListener('click', saveProjectSettingsOnly);
    document.getElementById('saveOralSettingsBtn')?.addEventListener('click', saveOralSettingsOnly);

    document.querySelectorAll('.rubric-max-score').forEach(i => i.addEventListener('change', updateRubricTotal));
    document.querySelectorAll('.project-criterion-score').forEach(i => i.addEventListener('change', updateProjectScoringTotal));
    
    // Add event listener for instruction textarea to update preview in real-time
    const instructionTextarea = document.getElementById('project_instruction');
    if (instructionTextarea) {
        instructionTextarea.addEventListener('input', updateInstructionPreview);
    }
});
</script>
</body>
</html>