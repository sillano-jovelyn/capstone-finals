<?php
// ==========================================
// ERROR REPORTING
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// ==========================================
// DATABASE CONNECTION
// ==========================================
$conn = null;
try {
    $db_file = __DIR__ . '/../db.php';
    if (!file_exists($db_file)) {
        throw new Exception("Database configuration file not found: " . $db_file);
    }

    include $db_file;

    if (!$conn) {
        throw new Exception("Database connection not established");
    }

    if (!$conn->ping()) {
        throw new Exception("Database connection lost");
    }

} catch (Exception $e) {
    die("Database connection error. Please try again later.");
}

// ==========================================
// CREATE FEEDBACK TABLE IF NOT EXISTS
// ==========================================
$create_feedback_table = "CREATE TABLE IF NOT EXISTS feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    program_id INT NOT NULL,
    trainer_expertise INT NOT NULL DEFAULT 3,
    trainer_communication INT NOT NULL DEFAULT 3,
    trainer_methods INT NOT NULL DEFAULT 3,
    trainer_requests INT NOT NULL DEFAULT 3,
    trainer_questions INT NOT NULL DEFAULT 3,
    trainer_instructions INT NOT NULL DEFAULT 3,
    trainer_prioritization INT NOT NULL DEFAULT 3,
    trainer_fairness INT NOT NULL DEFAULT 3,
    program_knowledge INT NOT NULL DEFAULT 3,
    program_process INT NOT NULL DEFAULT 3,
    program_environment INT NOT NULL DEFAULT 3,
    program_algorithms INT NOT NULL DEFAULT 3,
    program_preparation INT NOT NULL DEFAULT 3,
    system_technology INT NOT NULL DEFAULT 3,
    system_workflow INT NOT NULL DEFAULT 3,
    system_instructions INT NOT NULL DEFAULT 3,
    system_answers INT NOT NULL DEFAULT 3,
    system_performance INT NOT NULL DEFAULT 3,
    additional_comments TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_feedback (user_id, program_id)
)";

if (!$conn->query($create_feedback_table)) {
    error_log("Feedback table creation failed: " . $conn->error);
}

// ==========================================
// FEEDBACK SUBMISSION HANDLING
// ==========================================
if (isset($_POST['submit_feedback'])) {
    $program_id = intval($_POST['program_id'] ?? 0);
    $program_name = $_POST['program_name'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if ($program_id > 0 && $user_id > 0 && !empty($program_name)) {
        try {
            // Check if feedback already exists
            $check_sql = "SELECT id FROM feedback WHERE user_id = ? AND program_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $user_id, $program_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $_SESSION['feedback_error'] = "You have already submitted feedback for this program.";
            } else {
                // Prepare feedback data
                $feedback_data = [
                    'user_id' => $user_id,
                    'program_id' => $program_id,
                    'trainer_expertise' => intval($_POST['trainer_expertise'] ?? 3),
                    'trainer_communication' => intval($_POST['trainer_communication'] ?? 3),
                    'trainer_methods' => intval($_POST['trainer_methods'] ?? 3),
                    'trainer_requests' => intval($_POST['trainer_requests'] ?? 3),
                    'trainer_questions' => intval($_POST['trainer_questions'] ?? 3),
                    'trainer_instructions' => intval($_POST['trainer_instructions'] ?? 3),
                    'trainer_prioritization' => intval($_POST['trainer_prioritization'] ?? 3),
                    'trainer_fairness' => intval($_POST['trainer_fairness'] ?? 3),
                    'program_knowledge' => intval($_POST['program_knowledge'] ?? 3),
                    'program_process' => intval($_POST['program_process'] ?? 3),
                    'program_environment' => intval($_POST['program_environment'] ?? 3),
                    'program_algorithms' => intval($_POST['program_algorithms'] ?? 3),
                    'program_preparation' => intval($_POST['program_preparation'] ?? 3),
                    'system_technology' => intval($_POST['system_technology'] ?? 3),
                    'system_workflow' => intval($_POST['system_workflow'] ?? 3),
                    'system_instructions' => intval($_POST['system_instructions'] ?? 3),
                    'system_answers' => intval($_POST['system_answers'] ?? 3),
                    'system_performance' => intval($_POST['system_performance'] ?? 3),
                    'additional_comments' => $_POST['additional_comments'] ?? ''
                ];
                
                // Insert feedback
                $insert_sql = "INSERT INTO feedback (
                    user_id, program_id, trainer_expertise, trainer_communication, trainer_methods,
                    trainer_requests, trainer_questions, trainer_instructions, trainer_prioritization,
                    trainer_fairness, program_knowledge, program_process, program_environment,
                    program_algorithms, program_preparation, system_technology, system_workflow,
                    system_instructions, system_answers, system_performance, additional_comments
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiiiiiiiiiiiiiiiiiiis",
                    $feedback_data['user_id'],
                    $feedback_data['program_id'],
                    $feedback_data['trainer_expertise'],
                    $feedback_data['trainer_communication'],
                    $feedback_data['trainer_methods'],
                    $feedback_data['trainer_requests'],
                    $feedback_data['trainer_questions'],
                    $feedback_data['trainer_instructions'],
                    $feedback_data['trainer_prioritization'],
                    $feedback_data['trainer_fairness'],
                    $feedback_data['program_knowledge'],
                    $feedback_data['program_process'],
                    $feedback_data['program_environment'],
                    $feedback_data['program_algorithms'],
                    $feedback_data['program_preparation'],
                    $feedback_data['system_technology'],
                    $feedback_data['system_workflow'],
                    $feedback_data['system_instructions'],
                    $feedback_data['system_answers'],
                    $feedback_data['system_performance'],
                    $feedback_data['additional_comments']
                );
                
                if ($insert_stmt->execute()) {
                    // Update assessment to "Passed"
                    $update_assessment_sql = "UPDATE enrollments SET assessment = 'Passed' WHERE user_id = ? AND program_id = ?";
                    $update_assessment_stmt = $conn->prepare($update_assessment_sql);
                    $update_assessment_stmt->bind_param("ii", $user_id, $program_id);
                    $update_assessment_stmt->execute();
                    $update_assessment_stmt->close();
                    
                    // Update enrollment_status to 'completed'
                    $update_status_sql = "UPDATE enrollments SET enrollment_status = 'completed' WHERE user_id = ? AND program_id = ?";
                    $update_status_stmt = $conn->prepare($update_status_sql);
                    $update_status_stmt->bind_param("ii", $user_id, $program_id);
                    $update_status_stmt->execute();
                    $update_status_stmt->close();
                    
                    // Update completed_at
                    $update_completed_sql = "UPDATE enrollments SET completed_at = NOW() WHERE user_id = ? AND program_id = ?";
                    $update_completed_stmt = $conn->prepare($update_completed_sql);
                    $update_completed_stmt->bind_param("ii", $user_id, $program_id);
                    $update_completed_stmt->execute();
                    $update_completed_stmt->close();
                    
                    $_SESSION['feedback_success'] = "Thank you! Your feedback has been submitted successfully.";
                    $_SESSION['certificate_program'] = $program_name;
                    $_SESSION['feedback_submitted'] = true;
                    
                    header("Location: training_progress.php?feedback_submitted=1&program_id=" . $program_id);
                    exit();
                    
                } else {
                    $_SESSION['feedback_error'] = "Failed to save feedback. Please try again.";
                }
                
                $insert_stmt->close();
            }
            
            $check_stmt->close();
            
        } catch (Exception $e) {
            $_SESSION['feedback_error'] = "An error occurred. Please try again.";
            error_log("Feedback submission error: " . $e->getMessage());
        }
    } else {
        $_SESSION['feedback_error'] = "Invalid form data.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?program_id=" . $program_id . "&program_name=" . urlencode($program_name));
    exit();
}

// ==========================================
// CERTIFICATE GENERATION - SIMPLE PROTECTION
// ==========================================
if (isset($_GET['generate_certificate'])) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $program_id = intval($_GET['program_id'] ?? 0);
    
    if ($user_id > 0 && $program_id > 0) {
        // Get user's full name
        $fullname = "";
        if ($_SESSION['role'] === 'trainee') {
            $stmt = $conn->prepare("SELECT fullname, firstname, lastname FROM trainees WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!empty($row['fullname'])) {
                    $fullname = $row['fullname'];
                } elseif (!empty($row['firstname']) && !empty($row['lastname'])) {
                    $fullname = $row['firstname'] . ' ' . $row['lastname'];
                } elseif (!empty($row['firstname'])) {
                    $fullname = $row['firstname'];
                }
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $fullname = $row['fullname'] ?? '';
            }
            $stmt->close();
        }
        
        // Get program details
        $program_name = $_SESSION['certificate_program'] ?? 'Training Program';
        
        // Get program name from database
        $stmt = $conn->prepare("SELECT name FROM programs WHERE id = ?");
        $stmt->bind_param("i", $program_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $program_name = $row['name'];
        }
        $stmt->close();
        
        // Get completion date
        $completion_date = date('F d, Y');
        $date_query = $conn->prepare("SELECT completed_at FROM enrollments WHERE user_id = ? AND program_id = ?");
        if ($date_query) {
            $date_query->bind_param("ii", $user_id, $program_id);
            $date_query->execute();
            $result = $date_query->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!empty($row['completed_at'])) {
                    $completion_date = date('F d, Y', strtotime($row['completed_at']));
                }
            }
            $date_query->close();
        }
        
        // Format date to match "27th day of October 2025" format EXACTLY
        $day = date('jS', strtotime($completion_date));
        $month_year = date('F Y', strtotime($completion_date));
        $formatted_date = $day . ' day of ' . $month_year;
        
        // Check if feedback exists
        $has_feedback = false;
        $feedback_stmt = $conn->prepare("SELECT id FROM feedback WHERE user_id = ? AND program_id = ?");
        $feedback_stmt->bind_param("ii", $user_id, $program_id);
        $feedback_stmt->execute();
        $feedback_result = $feedback_stmt->get_result();
        $has_feedback = $feedback_result->num_rows > 0;
        $feedback_stmt->close();
        
        $conn->close();
        
        if (!$has_feedback) {
            die("Certificate not available. Please submit feedback first.");
        }
        
        if (empty($fullname)) {
            $fullname = $_SESSION['username'] ?? 'Trainee';
        }
        
        // Generate certificate - SIMPLE PROTECTION LANG
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
            <title>Certificate of Training - <?php echo htmlspecialchars($fullname); ?></title>
            
            <style>
                /* EXACT layout from the image - pixel perfect */
                body {
                    font-family: 'Times New Roman', Times, serif;
                    margin: 0;
                    padding: 0;
                    background: #f5f5f5;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    box-sizing: border-box;
                }
                
                .certificate-container {
                    width: 210mm; /* A4 width */
                    height: 297mm; /* A4 height */
                    background: #f5f0e8; /* Cream/beige background from image */
                    position: relative;
                    box-sizing: border-box;
                    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                }
                
                /* Decorative border - EXACT from image */
                .decorative-border {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    border: 35px solid transparent;
                    border-image: repeating-linear-gradient(
                        45deg,
                        #2d8b8e 0px,
                        #2d8b8e 10px,
                        #d4a574 10px,
                        #d4a574 20px,
                        #2d8b8e 20px,
                        #2d8b8e 30px,
                        #f5f0e8 30px,
                        #f5f0e8 40px
                    ) 35;
                    pointer-events: none;
                    z-index: 2;
                }
                
                /* Inner decorative pattern border */
                .inner-border {
                    position: absolute;
                    top: 20px;
                    left: 20px;
                    right: 20px;
                    bottom: 20px;
                    border: 15px solid;
                    border-image: repeating-linear-gradient(
                        0deg,
                        #2d8b8e 0px,
                        #2d8b8e 3px,
                        #d4a574 3px,
                        #d4a574 6px,
                        #2d8b8e 6px,
                        #2d8b8e 9px,
                        #f5f0e8 9px,
                        #f5f0e8 12px
                    ) 15;
                    pointer-events: none;
                    z-index: 2;
                }
                
                /* EXACT text positions from the image */
                .certificate-content {
                    position: absolute;
                    width: 100%;
                    height: 100%;
                    top: 0;
                    left: 0;
                    padding: 50px 70px;
                    z-index: 1;
                }
                
                /* Logos at top - EXACT from image (horizontal row) */
                .logos-row {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    gap: 30px;
                    margin: 15px 0 20px 0;
                }
                
                .logo-item {
                    width: 80px;
                    height: 80px;
                }
                
                .logo-item img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                }
                
                /* Header text - EXACT from image */
                .header-top {
                    text-align: center;
                    font-size: 16px;
                    font-weight: bold;
                    color: black;
                    margin: 15px 0 5px 0;
                    padding: 0;
                    line-height: 1.2;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .cooperation {
                    text-align: center;
                    font-size: 14px;
                    color: black;
                    margin: 5px 0;
                    padding: 0;
                    line-height: 1.2;
                }
                
                .tesda {
                    text-align: center;
                    font-size: 14px;
                    font-weight: bold;
                    color: black;
                    margin: 5px 0;
                    padding: 0;
                    line-height: 1.2;
                    text-transform: uppercase;
                }
                
                .training-center {
                    text-align: center;
                    font-size: 20px;
                    font-weight: bold;
                    color: #2d8b8e; /* Teal color from image */
                    margin: 8px 0 35px 0;
                    padding: 0;
                    line-height: 1.2;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                
                /* Certificate title - EXACT from image with teal color */
                .certificate-title {
                    text-align: center;
                    margin: 0 0 35px 0;
                    padding: 0;
                }
                
                .certificate-title h1 {
                    font-size: 48px;
                    margin: 0;
                    color: #2d8b8e; /* Teal color from image */
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 6px;
                    line-height: 1;
                }
                
                /* Awarded to section - EXACT spacing */
                .awarded-to {
                    text-align: center;
                    margin: 0 0 20px 0;
                    padding: 0;
                }
                
                .awarded-to p {
                    font-size: 18px;
                    margin: 0;
                    color: black;
                    line-height: 1.3;
                    font-weight: normal;
                }
                
                /* Trainee name - EXACT from image */
                .trainee-name-container {
                    text-align: center;
                    margin: 0 0 25px 0;
                    padding: 0;
                }
                
                .trainee-name {
                    font-size: 48px;
                    color: black;
                    font-weight: bold;
                    text-transform: uppercase;
                    padding: 0;
                    display: inline-block;
                    letter-spacing: 2px;
                    line-height: 1.1;
                }
                
                /* Completion text - EXACT from image */
                .completion-text {
                    text-align: center;
                    margin: 0 0 20px 0;
                    padding: 0;
                }
                
                .completion-text p {
                    font-size: 16px;
                    margin: 0;
                    color: black;
                    line-height: 1.3;
                    font-weight: normal;
                }
                
                .hours-highlight {
                    color: #d94a3d; /* Red/orange color from image */
                    font-weight: bold;
                }
                
                /* Training name - EXACT from image */
                .training-name-container {
                    text-align: center;
                    margin: 0 0 30px 0;
                    padding: 0;
                }
                
                .training-name {
                    font-size: 36px;
                    color: black;
                    font-weight: bold;
                    text-transform: uppercase;
                    padding: 0;
                    display: inline-block;
                    letter-spacing: 2px;
                    line-height: 1.1;
                }
                
                /* Date and location - EXACT from image */
                .given-date {
                    text-align: center;
                    margin: 0 0 40px 0;
                    padding: 0;
                }
                
                .given-date p {
                    font-size: 16px;
                    margin: 0;
                    color: black;
                    line-height: 1.4;
                    font-weight: normal;
                }
                
                /* Signatures section - EXACT from image with photo */
                .signatures {
                    position: absolute;
                    bottom: 60px;
                    left: 70px;
                    right: 70px;
                }
                
                .signatures-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    position: relative;
                }
                
                .left-signatures {
                    display: flex;
                    flex-direction: column;
                    gap: 35px;
                    flex: 1;
                }
                
                .signature-block {
                    text-align: center;
                }
                
                .signature-line {
                    border-bottom: 2px solid black;
                    width: 280px;
                    margin: 0 auto 5px auto;
                    height: 1px;
                }
                
                .signature-name {
                    font-size: 15px;
                    font-weight: bold;
                    color: black;
                    text-transform: uppercase;
                    margin: 0;
                    letter-spacing: 0.5px;
                    line-height: 1.2;
                }
                
                .signature-title {
                    font-size: 14px;
                    color: black;
                    margin: 3px 0 0 0;
                    font-weight: normal;
                    line-height: 1.2;
                }
                
                /* Photo and signature section - EXACT from image */
                .photo-signature-section {
                    width: 180px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    margin-left: 40px;
                }
                
                .photo-box {
                    width: 150px;
                    height: 180px;
                    border: 2px solid #888;
                    background: white;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 10px;
                }
                
                .photo-box img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                
                .photo-placeholder {
                    width: 100%;
                    height: 100%;
                    background: linear-gradient(to bottom, #e8e8e8 0%, #f5f5f5 100%);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #999;
                    font-size: 12px;
                }
                
                .photo-signature-line {
                    border-bottom: 2px solid black;
                    width: 150px;
                    margin: 5px 0;
                }
                
                .photo-signature-label {
                    font-size: 12px;
                    color: black;
                    text-align: center;
                }
                
                /* Watermark - EXACT from image */
                .watermark {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    opacity: 0.06;
                    z-index: 0;
                    pointer-events: none;
                }
                
                .watermark img {
                    width: 500px;
                    height: 500px;
                    object-fit: contain;
                }
                
                /* UNOFFICIAL COPY WATERMARK - SIMPLE LANG */
                .unofficial-watermark {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    z-index: 10;
                    pointer-events: none;
                }
                
                .unofficial-text {
                    font-size: 42px;
                    font-weight: 800;
                    color: rgba(0, 0, 0, 0.15);
                    text-transform: uppercase;
                    font-family: 'Times New Roman', Times, serif;
                    letter-spacing: 6px;
                    transform: rotate(-45deg);
                    white-space: nowrap;
                    padding: 15px 40px;
                    background: transparent;
                    border: none;
                }
                
                /* Para hindi maprint */
                @media print {
                    body {
                        display: none;
                    }
                }
                
                * {
                    box-sizing: border-box;
                }
            </style>
        </head>
        <body>
            <div class="certificate-container">
                <!-- Original Watermark (logo) -->
                <div class="watermark">
                    <img src="/trainee/SLOGO.jpg" alt="Watermark" onerror="this.style.display='none';">
                </div>
                
                <!-- UNOFFICIAL COPY WATERMARK -->
                <div class="unofficial-watermark">
                    <div class="unofficial-text">UNOFFICIAL COPY</div>
                </div>
                
                <!-- Decorative borders -->
                <div class="decorative-border"></div>
                <div class="inner-border"></div>
                
                <div class="certificate-content">
                    <!-- Logos at top in horizontal row -->
                    <div class="logos-row">
                        <div class="logo-item">
                            <img src="/trainee/SMBLOGO.jpg" alt="Santa Maria Logo" onerror="this.style.display='none';">
                        </div>
                        <div class="logo-item">
                            <img src="/trainee/SLOGO.jpg" alt="Training Center Logo" onerror="this.style.display='none';">
                        </div>
                        <div class="logo-item">
                            <img src="/trainee/TESDALOGO.png" alt="TESDA Logo" onerror="this.style.display='none';">
                        </div>
                    </div>
                    
                    <!-- Header Text -->
                    <div class="header-top">
                        MUNICIPALITY OF SANTA MARIA, BULACAN
                    </div>
                    
                    <div class="cooperation">
                        IN COOPERATION WITH
                    </div>
                    
                    <div class="tesda">
                        TECHNICAL EDUCATION & SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN
                    </div>
                    
                    <div class="training-center">
                        SANTA MARIA LIVELIHOOD TRAINING CENTER
                    </div>
                    
                    <!-- Certificate Title -->
                    <div class="certificate-title">
                        <h1>CERTIFICATE OF TRAINING</h1>
                    </div>
                    
                    <!-- Awarded To -->
                    <div class="awarded-to">
                        <p>is awarded to</p>
                    </div>
                    
                    <!-- Trainee Name -->
                    <div class="trainee-name-container">
                        <div class="trainee-name">
                            <?php echo htmlspecialchars(strtoupper($fullname)); ?>
                        </div>
                    </div>
                    
                    <!-- Completion Text -->
                    <div class="completion-text">
                        <p>For having satisfactorily completed the</p>
                    </div>
                    
                    <!-- Training Name -->
                    <div class="training-name-container">
                        <div class="training-name">
                            <?php echo htmlspecialchars(strtoupper($program_name)); ?>
                        </div>
                    </div>
                    
                    <!-- Date and Location -->
                    <div class="given-date">
                        <p>Given this <?php echo $formatted_date; ?> at Santa Maria Livelihood Training and</p>
                        <p>Employment Center, Santa Maria, Bulacan.</p>
                    </div>
                    
                    <!-- Signatures with photo -->
                    <div class="signatures">
                        <div class="signatures-row">
                            <div class="left-signatures">
                                <div class="signature-block">
                                    <div class="signature-line"></div>
                                    <div class="signature-name">ZENAIDA S. MANINGAS</div>
                                    <div class="signature-title">PESO Manager</div>
                                </div>
                                
                                <div class="signature-block">
                                    <div class="signature-line"></div>
                                    <div class="signature-name">ROBERTO B. PEREZ</div>
                                    <div class="signature-title">Municipal Vice Mayor</div>
                                </div>
                                
                                <div class="signature-block">
                                    <div class="signature-line"></div>
                                    <div class="signature-name">BARTOLOME R. RAMOS</div>
                                    <div class="signature-title">Municipal Mayor</div>
                                </div>
                            </div>
                            
                            <!-- Photo and signature section -->
                            <div class="photo-signature-section">
                                <div class="photo-box">
                                    <div class="photo-placeholder"></div>
                                </div>
                                <div class="photo-signature-line"></div>
                                <div class="photo-signature-label">Signature</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
                (function() {
                    'use strict';
                    
                    // =========================================
                    // SIMPLE PROTECTION - BAWAL LANG TALAGA
                    // =========================================
                    
                    // 1. BAWAL PRINT (Desktop)
                    document.addEventListener('keydown', function(e) {
                        // Ctrl+P
                        if (e.ctrlKey && e.key === 'p') {
                            e.preventDefault();
                            return false;
                        }
                    });
                    
                    // 2. BAWAL PRINT SCREEN
                    document.addEventListener('keyup', function(e) {
                        if (e.key === 'PrintScreen' || e.keyCode === 44) {
                            e.preventDefault();
                            return false;
                        }
                    });
                    
                    // 3. BAWAL WINDOWS+SHIFT+S (Snipping Tool)
                    let shiftPressed = false;
                    let windowsPressed = false;
                    
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Meta' || e.keyCode === 91) windowsPressed = true;
                        if (e.key === 'Shift' || e.keyCode === 16) shiftPressed = true;
                        
                        if (windowsPressed && shiftPressed && e.key === 's') {
                            e.preventDefault();
                            return false;
                        }
                    });
                    
                    document.addEventListener('keyup', function(e) {
                        if (e.key === 'Meta' || e.keyCode === 91) windowsPressed = false;
                        if (e.key === 'Shift' || e.keyCode === 16) shiftPressed = false;
                    });
                    
                    // 4. BAWAL MOBILE VOLUME BUTTONS
                    document.addEventListener('keydown', function(e) {
                        // Volume buttons
                        if (e.key === 'AudioVolumeUp' || e.key === 'AudioVolumeDown' || 
                            e.keyCode === 175 || e.keyCode === 176 || e.keyCode === 174) {
                            e.preventDefault();
                            return false;
                        }
                    });
                    
                    // 5. BAWAL MOBILE SCREENSHOT GESTURES (3 fingers)
                    document.addEventListener('touchstart', function(e) {
                        if (e.touches.length >= 3) {
                            e.preventDefault();
                            return false;
                        }
                    }, { passive: false });
                    
                    // 6. BAWAL RIGHT CLICK
                    document.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        return false;
                    });
                    
                    // 7. BAWAL COPY
                    document.addEventListener('copy', function(e) {
                        e.preventDefault();
                        return false;
                    });
                    
                    // 8. BAWAL PRINT VIA CSS
                    const style = document.createElement('style');
                    style.innerHTML = '@media print { body { display: none; } }';
                    document.head.appendChild(style);
                    
                    // WALANG WARNING, WALANG POP-UP, WALANG OVERLAY
                    // Certificate lang makikita, bawal lang talaga mag-screenshot at mag-print
                    
                })();
            </script>
        </body>
        </html>
        <?php
        exit();
    } else {
        die("Invalid certificate request.");
    }
}
// ==========================================
// SESSION VALIDATION
// ==========================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$program_id = intval($_GET['program_id'] ?? 0);
$program_name = urldecode($_GET['program_name'] ?? '');

if ($program_id === 0 || empty($program_name)) {
    header("Location: training_progress.php");
    exit();
}

// Check if feedback already submitted
$check_sql = "SELECT id FROM feedback WHERE user_id = ? AND program_id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("ii", $user_id, $program_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$has_feedback = $check_result->num_rows > 0;
$check_stmt->close();

if ($has_feedback) {
    header("Location: training_progress.php?already_submitted=1");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Feedback - LEMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .feedback-container {
            width: 100%;
            max-width: 900px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .feedback-header {
            background: linear-gradient(135deg, #1c2a3a, #2c3e50);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .feedback-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .feedback-header p {
            font-size: 16px;
            opacity: 0.9;
        }

        .program-info {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-bottom: 2px solid #e9ecef;
        }

        .program-name {
            font-size: 22px;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .instruction {
            color: #6c757d;
            font-size: 14px;
        }

        .feedback-form {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .feedback-section {
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-title {
            font-size: 20px;
            color: #1c2a3a;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3b82f6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #3b82f6;
        }

        .feedback-question {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #3b82f6;
        }

        .question-text {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .rating-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rating-option {
            flex: 1;
            min-width: 80px;
            text-align: center;
        }

        .rating-option input[type="radio"] {
            display: none;
        }

        .rating-label {
            display: block;
            padding: 12px 5px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .rating-label:hover {
            border-color: #3b82f6;
            background: #e8f4fe;
        }

        .rating-option input[type="radio"]:checked + .rating-label {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
        }

        .rating-scale {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 12px;
            color: #6c757d;
        }

        .comments-section {
            margin-top: 30px;
        }

        .comments-section textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            resize: vertical;
            min-height: 120px;
            transition: border-color 0.3s;
        }

        .comments-section textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .form-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 2px solid #e9ecef;
        }

        .back-btn {
            padding: 12px 30px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #545b62;
            transform: translateX(-3px);
        }

        .submit-btn {
            padding: 12px 40px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .submit-btn:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        .message-alert {
            padding: 15px;
            margin: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease;
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

        .error-alert {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
        }

        .success-alert {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        @media (max-width: 768px) {
            .feedback-container {
                margin: 10px;
            }
            
            .feedback-header {
                padding: 20px;
            }
            
            .feedback-form {
                padding: 20px;
            }
            
            .rating-options {
                flex-direction: column;
            }
            
            .form-footer {
                flex-direction: column;
                gap: 15px;
            }
            
            .back-btn, .submit-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="feedback-container">
        <div class="feedback-header">
            <h1><i class="fas fa-comment-dots"></i> Training Feedback Form</h1>
            <p>Your feedback helps us improve our training programs</p>
        </div>

        <div class="program-info">
            <div class="program-name"><?php echo htmlspecialchars($program_name); ?></div>
            <div class="instruction">Please rate each item on a scale of 1 (Poor) to 5 (Excellent)</div>
        </div>

        <?php if (isset($_SESSION['feedback_error'])): ?>
            <div class="message-alert error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error!</strong>
                    <p><?php echo htmlspecialchars($_SESSION['feedback_error']); ?></p>
                </div>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php unset($_SESSION['feedback_error']); ?>
        <?php endif; ?>

        <form method="POST" action="" class="feedback-form">
            <input type="hidden" name="program_name" value="<?php echo htmlspecialchars($program_name); ?>">
            <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">

            <!-- For Trainer -->
            <div class="feedback-section">
                <h2 class="section-title"><i class="fas fa-chalkboard-teacher"></i> A. For Trainer</h2>
                
                <?php 
                $trainer_questions = [
                    'trainer_expertise' => 'The trainer demonstrates expertise in the program topic.',
                    'trainer_communication' => 'The trainer communicates messages in an understandable way.',
                    'trainer_methods' => 'The trainer uses effective learning methods (demonstration, discussion, practical activities).',
                    'trainer_requests' => 'The trainer listens to participants\' requests throughout the session.',
                    'trainer_questions' => 'The trainer encourages questions and provides clear answers.',
                    'trainer_instructions' => 'The trainer gives clear instructions.',
                    'trainer_prioritization' => 'The trainer prioritizes self-paced work appropriately.',
                    'trainer_fairness' => 'The trainer treats all participants with respect and fairness.'
                ];
                
                foreach ($trainer_questions as $field => $question): 
                ?>
                <div class="feedback-question">
                    <div class="question-text"><?php echo $question; ?></div>
                    <div class="rating-options">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                            <div class="rating-option">
                                <input type="radio" id="<?php echo $field . '_' . $i; ?>" name="<?php echo $field; ?>" value="<?php echo $i; ?>" required>
                                <label for="<?php echo $field . '_' . $i; ?>" class="rating-label">
                                    <?php echo $i; ?>
                                    <?php if($i == 1): ?><br><small>Poor</small>
                                    <?php elseif($i == 5): ?><br><small>Excellent</small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-scale">
                        <span>Poor</span>
                        <span>Excellent</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- For Program -->
            <div class="feedback-section">
                <h2 class="section-title"><i class="fas fa-book-open"></i> B. For Program</h2>
                
                <?php 
                $program_questions = [
                    'program_knowledge' => 'The program provides knowledge that is relevant to the topic.',
                    'program_process' => 'The program follows a logical and understandable process.',
                    'program_environment' => 'The program creates an environment that encourages learning.',
                    'program_algorithms' => 'The program introduces appropriate algorithms for the training topics.',
                    'program_preparation' => 'The program helps learners better prepare for practical application.'
                ];
                
                foreach ($program_questions as $field => $question): 
                ?>
                <div class="feedback-question">
                    <div class="question-text"><?php echo $question; ?></div>
                    <div class="rating-options">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                            <div class="rating-option">
                                <input type="radio" id="<?php echo $field . '_' . $i; ?>" name="<?php echo $field; ?>" value="<?php echo $i; ?>" required>
                                <label for="<?php echo $field . '_' . $i; ?>" class="rating-label">
                                    <?php echo $i; ?>
                                    <?php if($i == 1): ?><br><small>Poor</small>
                                    <?php elseif($i == 5): ?><br><small>Excellent</small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-scale">
                        <span>Poor</span>
                        <span>Excellent</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- For System -->
            <div class="feedback-section">
                <h2 class="section-title"><i class="fas fa-laptop-code"></i> C. For System</h2>
                
                <?php 
                $system_questions = [
                    'system_technology' => 'The system uses appropriate technology for the training.',
                    'system_workflow' => 'The system workflow is clear and efficient.',
                    'system_instructions' => 'The system provides clear instructions.',
                    'system_answers' => 'The system helps learners find answers to questions.',
                    'system_performance' => 'The system performs well and is reliable.'
                ];
                
                foreach ($system_questions as $field => $question): 
                ?>
                <div class="feedback-question">
                    <div class="question-text"><?php echo $question; ?></div>
                    <div class="rating-options">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                            <div class="rating-option">
                                <input type="radio" id="<?php echo $field . '_' . $i; ?>" name="<?php echo $field; ?>" value="<?php echo $i; ?>" required>
                                <label for="<?php echo $field . '_' . $i; ?>" class="rating-label">
                                    <?php echo $i; ?>
                                    <?php if($i == 1): ?><br><small>Poor</small>
                                    <?php elseif($i == 5): ?><br><small>Excellent</small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-scale">
                        <span>Poor</span>
                        <span>Excellent</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="comments-section">
                <h2 class="section-title"><i class="fas fa-edit"></i> Additional Comments</h2>
                <textarea name="additional_comments" placeholder="Please provide any additional comments, suggestions, or feedback about the training program..."></textarea>
            </div>

            <div class="form-footer">
                <button type="button" class="back-btn" onclick="window.location.href='training_progress.php'">
                    <i class="fas fa-arrow-left"></i> Back to Progress
                </button>
                <button type="submit" name="submit_feedback" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit Feedback & Get Certificate
                </button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const allRadios = this.querySelectorAll('input[type="radio"]');
                const radioGroups = new Set();
                
                allRadios.forEach(radio => {
                    if (radio.name) {
                        radioGroups.add(radio.name);
                    }
                });
                
                let allAnswered = true;
                radioGroups.forEach(groupName => {
                    const checked = this.querySelectorAll(`input[name="${groupName}"]:checked`);
                    if (checked.length === 0) {
                        allAnswered = false;
                        const questionDiv = this.querySelector(`input[name="${groupName}"]`).closest('.feedback-question');
                        if (questionDiv) {
                            questionDiv.style.borderLeftColor = '#dc2626';
                            questionDiv.style.background = '#fee';
                        }
                    }
                });
                
                if (!allAnswered) {
                    e.preventDefault();
                    alert('Please answer all questions before submitting.');
                }
            });
            
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    const questionDiv = this.closest('.feedback-question');
                    if (questionDiv) {
                        questionDiv.style.borderLeftColor = '#3b82f6';
                        questionDiv.style.background = '#f8f9fa';
                    }
                });
            });
        });
    </script>
</body>
</html>