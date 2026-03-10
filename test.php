<?php

include __DIR__ . '/../db.php';

// Include TCPDF library
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

// Include Dompdf if available (for HTML to PDF conversion)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Check if user is logged in and has appropriate permissions
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Set page title
$pageTitle = "Reports & Monitoring";

// Get current date range for filtering
$currentYear = date('Y');
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : $currentYear . '-01-01';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : $currentYear . '-12-31';

// Initialize variables to prevent undefined variable errors
$traineeOutcomesData = null;
$trainerAttendanceData = null;
$traineeAttendanceData = null;
$trainerEvaluationData = null;
$traineesDetailedData = null;
$certificateData = null;
$certificateError = null;
$allCompletedData = null;

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Function to fetch data for different reports
function fetchTraineeOutcomes($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.fullname,
                p.name AS program_enrolled,
                e.enrollment_status AS status,
                CASE 
                    WHEN e.enrollment_status = 'completed' AND e.assessment = 'passed' THEN 'Certified'
                    WHEN e.enrollment_status = 'completed' AND (e.assessment != 'passed' OR e.assessment IS NULL) THEN 'Pending Certification'
                    WHEN e.enrollment_status = 'pending' THEN 'Pending'
                    ELSE 'Not Certified'
                END AS certification,
                DATE_FORMAT(e.completed_at, '%b %d, %Y') AS completion_date,
                e.completed_at,
                e.assessment
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              WHERE (e.applied_at BETWEEN ? AND ? OR e.applied_at IS NULL)
                AND u.role = 'trainee'
              ORDER BY e.completed_at DESC, u.fullname";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTrainerAttendance($conn, $dateFrom, $dateTo) {
    $trainerQuery = "SELECT 
                      u.fullname AS name,
                      GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS programs_handled,
                      COUNT(DISTINCT DATE(ta.attendance_time)) AS days_present,
                      MAX(ta.attendance_time) AS last_attendance,
                      u.id AS trainer_id
                    FROM users u
                    LEFT JOIN trainer_attendance ta ON u.fullname COLLATE utf8mb4_unicode_ci = ta.trainer_name COLLATE utf8mb4_unicode_ci
                        AND DATE(ta.attendance_time) BETWEEN ? AND ?
                    LEFT JOIN programs p ON u.id = p.trainer_id
                    WHERE u.role = 'trainer'
                    GROUP BY u.id
                    ORDER BY days_present DESC, u.fullname";
    
    $stmt = $conn->prepare($trainerQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTraineeAttendance($conn, $dateFrom, $dateTo) {
    $traineeQuery = "SELECT 
                      u.fullname AS name,
                      p.name AS program,
                      e.attendance AS attendance_percentage,
                      COUNT(DISTINCT ar.attendance_date) AS days_present,
                      MAX(ar.attendance_date) AS last_attendance
                    FROM users u
                    JOIN enrollments e ON u.id = e.user_id
                    JOIN programs p ON e.program_id = p.id
                    LEFT JOIN attendance_records ar ON e.id = ar.enrollment_id
                        AND ar.attendance_date BETWEEN ? AND ?
                    WHERE u.role = 'trainee'
                    GROUP BY u.id, e.id
                    ORDER BY e.attendance DESC, u.fullname";
    
    $stmt = $conn->prepare($traineeQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTrainerEvaluations($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.fullname AS trainer_name,
                p.name AS program,
                DATE_FORMAT(p.scheduleStart, '%b %d, %Y') AS start_date,
                COUNT(e.id) AS trainees_assigned,
                p.slotsAvailable AS slots_available
              FROM users u
              LEFT JOIN programs p ON u.id = p.trainer_id
              LEFT JOIN enrollments e ON p.id = e.program_id
              WHERE u.role = 'trainer'
                AND (p.scheduleStart BETWEEN ? AND ? OR p.scheduleStart IS NULL)
              GROUP BY u.id, p.id
              ORDER BY p.scheduleStart DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTraineesDetailed($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                t.fullname,
                t.address,
                t.gender,
                t.civil_status,
                t.age,
                t.contact_number,
                t.employment_status,
                t.education,
                t.trainings_attended,
                DATE_FORMAT(t.created_at, '%Y-%m-%d') AS registration_date,
                p.name AS enrolled_program,
                e.enrollment_status
              FROM trainees t
              LEFT JOIN users u ON t.email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
              LEFT JOIN enrollments e ON u.id = e.user_id
              LEFT JOIN programs p ON e.program_id = p.id
              WHERE (t.created_at BETWEEN ? AND ? OR t.created_at IS NULL)
              ORDER BY t.fullname";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to fetch certificate data with feedback and assessment conditions
function fetchCertificateData($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.id as user_id,
                u.fullname,
                p.name AS program_name,
                p.id as program_id,
                e.id as enrollment_id,
                CASE 
                    WHEN p.scheduleStart IS NOT NULL AND p.scheduleEnd IS NOT NULL THEN 
                        DATEDIFF(p.scheduleEnd, p.scheduleStart) * 8
                    ELSE 40
                END AS duration_hours,
                DATE_FORMAT(p.scheduleStart, '%M %d, %Y') AS program_start_date,
                DATE_FORMAT(p.scheduleEnd, '%M %d, %Y') AS program_end_date,
                DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
                e.enrollment_status,
                e.assessment,
                f.id as feedback_id
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              LEFT JOIN feedback f ON u.id = f.user_id AND p.id = f.program_id
              WHERE e.enrollment_status = 'completed'
                AND e.completed_at BETWEEN ? AND ?
                AND u.role = 'trainee'
                AND e.assessment = 'passed'
                AND f.id IS NOT NULL
              ORDER BY p.name, u.fullname";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to fetch ALL completed trainees
function fetchAllCompletedTrainees($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.id as user_id,
                u.fullname,
                p.name AS program_name,
                p.id as program_id,
                e.id as enrollment_id,
                DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
                e.enrollment_status,
                e.assessment,
                CASE 
                    WHEN f.id IS NOT NULL THEN 'Yes'
                    ELSE 'No'
                END AS has_feedback,
                CASE 
                    WHEN e.assessment = 'passed' AND f.id IS NOT NULL THEN 'Eligible'
                    WHEN e.assessment = 'passed' AND f.id IS NULL THEN 'Missing Feedback'
                    WHEN e.assessment != 'passed' AND f.id IS NOT NULL THEN 'Assessment Not Passed'
                    WHEN e.assessment IS NULL AND f.id IS NULL THEN 'Missing Both'
                    ELSE 'Not Eligible'
                END AS eligibility_status
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              LEFT JOIN feedback f ON u.id = f.user_id AND p.id = f.program_id
              WHERE e.enrollment_status = 'completed'
                AND e.completed_at BETWEEN ? AND ?
                AND u.role = 'trainee'
              ORDER BY 
                CASE 
                    WHEN e.assessment = 'passed' AND f.id IS NOT NULL THEN 1
                    WHEN e.assessment = 'passed' AND f.id IS NULL THEN 2
                    WHEN e.assessment != 'passed' AND f.id IS NOT NULL THEN 3
                    ELSE 4
                END,
                p.name, u.fullname";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to fetch single certificate data
function fetchSingleCertificateData($conn, $user_id, $program_id) {
    $query = "SELECT 
                u.id as user_id,
                u.fullname,
                p.name AS program_name,
                p.id as program_id,
                DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
                e.completed_at AS raw_completion_date
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              WHERE u.id = ? AND p.id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("ii", $user_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return null;
}

// Function to generate certificate HTML (EXACT template from generate_single_certificate.php)

function generateCertificateHTML($data) {
    $fullname = htmlspecialchars(strtoupper($data['fullname'] ?? 'NAME'));
    $program_name = htmlspecialchars(strtoupper($data['program_name'] ?? 'NAME OF TRAINING'));
    
    // Format date to match "27th day of October 2025" format
    $completion_date = $data['completion_date'] ?? date('F d, Y');
    $day = date('jS', strtotime($completion_date));
    $month_year = date('F Y', strtotime($completion_date));
    $formatted_date = $day . ' day of ' . $month_year;
    
    // Get base URL for images
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $base_path = dirname($_SERVER['PHP_SELF']);
    
    $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Certificate of Training - ' . $fullname . '</title>
            <style>
                body {
                    font-family: "Times New Roman", Times, serif;
                    margin: 0;
                    padding: 0;
                    background: white;
                    box-sizing: border-box;
                }
                
                .certificate-container {
                    width: 210mm;
                    height: 297mm;
                    background: #f5f0e8;
                    position: relative;
                    box-sizing: border-box;
                }
                
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
                
                .certificate-content {
                    position: absolute;
                    width: 100%;
                    height: 100%;
                    top: 0;
                    left: 0;
                    padding: 50px 70px;
                    z-index: 1;
                }
                
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
                    color: #2d8b8e;
                    margin: 8px 0 35px 0;
                    padding: 0;
                    line-height: 1.2;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                
                .certificate-title {
                    text-align: center;
                    margin: 0 0 35px 0;
                    padding: 0;
                }
                
                .certificate-title h1 {
                    font-size: 48px;
                    margin: 0;
                    color: #2d8b8e;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 6px;
                    line-height: 1;
                }
                
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
                
                @media print {
                    body {
                        background: white;
                        padding: 0;
                        margin: 0;
                    }
                    
                    .certificate-container {
                        width: 210mm;
                        height: 297mm;
                        margin: 0;
                        page-break-after: always;
                        box-shadow: none;
                    }
                    
                    @page {
                        size: A4 portrait;
                        margin: 0;
                    }
                }
                
                * {
                    box-sizing: border-box;
                }
            </style>
        </head>
        <body>
            <div class="certificate-container">
                <!-- Watermark -->
                <div class="watermark">
                    <img src="' . $base_url . '/trainee/SLOGO.jpg" alt="Watermark" onerror="this.style.display=\'none\';">
                </div>
                
                <div class="decorative-border"></div>
                <div class="inner-border"></div>
                
                <div class="certificate-content">
                    <div class="logos-row">
                        <div class="logo-item">
                            <img src="' . $base_url . '/trainee/SMBLOGO.jpg" alt="Santa Maria Logo" onerror="this.style.display=\'none\';">
                        </div>
                        <div class="logo-item">
                            <img src="' . $base_url . '/trainee/SLOGO.jpg" alt="Training Center Logo" onerror="this.style.display=\'none\';">
                        </div>
                        <div class="logo-item">
                            <img src="' . $base_url . '/trainee/TESDALOGO.png" alt="TESDA Logo" onerror="this.style.display=\'none\';">
                        </div>
                    </div>
                    
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
                    
                    <div class="certificate-title">
                        <h1>CERTIFICATE OF TRAINING</h1>
                    </div>
                    
                    <div class="awarded-to">
                        <p>is awarded to</p>
                    </div>
                    
                    <div class="trainee-name-container">
                        <div class="trainee-name">
                            ' . $fullname . '
                        </div>
                    </div>
                    
                    <div class="completion-text">
                        <p>For having satisfactorily completed the</p>
                    </div>
                    
                    <div class="training-name-container">
                        <div class="training-name">
                            ' . $program_name . '
                        </div>
                    </div>
                    
                    <div class="given-date">
                        <p>Given this ' . $formatted_date . ' at Santa Maria Livelihood Training and</p>
                        <p>Employment Center, Santa Maria, Bulacan.</p>
                    </div>
                    
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
                // Auto-print after 1 second
                setTimeout(function() {
                    window.print();
                }, 1000);
            </script>
        </body>
        </html>';
            
    return $html;
}

try {
    // Fetch data for each tab
    $traineeOutcomesData = fetchTraineeOutcomes($conn, $dateFrom, $dateTo);
    $trainerAttendanceData = fetchTrainerAttendance($conn, $dateFrom, $dateTo);
    $traineeAttendanceData = fetchTraineeAttendance($conn, $dateFrom, $dateTo);
    $trainerEvaluationData = fetchTrainerEvaluations($conn, $dateFrom, $dateTo);
    $traineesDetailedData = fetchTraineesDetailed($conn, $dateFrom, $dateTo);
    
    // Try to fetch certificate data with error handling
    try {
        $certificateData = fetchCertificateData($conn, $dateFrom, $dateTo);
        $allCompletedData = fetchAllCompletedTrainees($conn, $dateFrom, $dateTo);
    } catch (Exception $e) {
        error_log("Certificate data error: " . $e->getMessage());
        $certificateData = null;
        $certificateError = "Unable to load certificate data: " . $e->getMessage();
    }

} catch (Exception $e) {
    $errorMessage = "Error loading reports: " . $e->getMessage();
    error_log($errorMessage);
}

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $tab = $_GET['tab'] ?? 'trainees';
    $subtab = $_GET['subtab'] ?? '';
    
    switch($exportType) {
        case 'pdf':
            if ($tab === 'certificates') {
                generateBulkCertificates($conn, $dateFrom, $dateTo);
            } else {
                generatePDFReport($conn, $tab, $subtab, $dateFrom, $dateTo);
            }
            exit();
    }
}




// Function to generate bulk certificates (UPDATED to use HTML template)
function generateBulkCertificates($conn, $dateFrom, $dateTo) {
    // Fetch certificate data
    $result = fetchCertificateData($conn, $dateFrom, $dateTo);
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Create a temporary directory for certificates
        $tempDir = sys_get_temp_dir() . '/certificates_' . uniqid();
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        // Generate each certificate using the HTML template
        foreach($data as $index => $row) {
            // Fetch additional data needed for the certificate
            $certData = fetchSingleCertificateData($conn, $row['user_id'], $row['program_id']);
            
            if ($certData) {
                // Generate HTML certificate
                $html = generateCertificateHTML($certData);
                
                // Save as HTML file first
                $htmlFile = $tempDir . '/cert_' . ($index + 1) . '_' . sanitizeFileName($certData['fullname']) . '.html';
                file_put_contents($htmlFile, $html);
                
                // Convert HTML to PDF using TCPDF
                $pdfFile = $tempDir . '/' . sanitizeFileName($certData['fullname'] . '_' . $certData['program_name']) . '.pdf';
                
                // Use TCPDF to convert HTML to PDF
                $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(0, 0, 0);
                $pdf->SetAutoPageBreak(false);
                $pdf->AddPage();
                
                // Write HTML content
                $pdf->writeHTML($html, true, false, true, false, '');
                
                // Save PDF
                $pdf->Output($pdfFile, 'F');
                
                // Delete the HTML file
                unlink($htmlFile);
            }
        }
        
        // Create ZIP archive
        $zip = new ZipArchive();
        $zipFileName = 'certificates_' . date('Ymd_His') . '.zip';
        $zipPath = $tempDir . '.zip';
        
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            $files = glob($tempDir . '/*.pdf');
            foreach($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
            
            // Clean up temporary directory
            array_map('unlink', glob($tempDir . '/*'));
            rmdir($tempDir);
            
            // Send ZIP file to browser
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            readfile($zipPath);
            
            // Clean up ZIP file
            unlink($zipPath);
            exit();
        } else {
            // Clean up
            array_map('unlink', glob($tempDir . '/*'));
            rmdir($tempDir);
            die('Failed to create ZIP archive');
        }
    } else {
        // If no eligible trainees, redirect with error
        header('Location: reports_monitoring.php?error=no_certificates&tab=certificates');
        exit();
    }
}

// Function to generate PDF reports using TCPDF
function generatePDFReport($conn, $tab, $subtab, $dateFrom, $dateTo) {
    // Create new PDF document
    $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Livelihood Program Management System');
    $pdf->SetAuthor('System Administrator');
    $pdf->SetTitle('Reports - ' . ucfirst($tab));
    $pdf->SetSubject('Program Reports');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);
    
    // Add a page
    $pdf->AddPage();
    
    // Add logo
    $logoPath = __DIR__ . '/../css/logo2.jpg';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 15, 25, 25, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $pdf->SetX(50);
    } else {
        $pdf->SetX(15);
    }
    
    // Header
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'LIVELIHOOD ENROLLMENT AND MONITORING SYSTEM', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'OFFICIAL REPORT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'I', 11);
    $pdf->Cell(0, 8, 'Comprehensive Program Management Dashboard', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Divider line
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    $pdf->Ln(10);
    
    // Title based on tab
    $title = '';
    switch($tab) {
        case 'outcomes':
            $title = 'TRAINEE OUTCOMES REPORT';
            break;
        case 'attendance':
            if ($subtab == 'trainer-attendance') {
                $title = 'TRAINER ATTENDANCE REPORT';
            } else {
                $title = 'TRAINEE ATTENDANCE REPORT';
            }
            break;
        case 'evaluation':
            $title = 'TRAINER EVALUATION REPORT';
            break;
        case 'trainees':
            $title = 'TRAINEE MASTERLIST REPORT';
            break;
        case 'certificates':
            $title = 'CERTIFICATE ELIGIBILITY REPORT';
            break;
    }
    
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    
    $pdf->Ln(8);
    
    // Add period info
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Period: ' . date('F d, Y', strtotime($dateFrom)) . ' to ' . date('F d, Y', strtotime($dateTo)), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'L');
    $pdf->Ln(10);
    
    // Generate content based on tab
    switch($tab) {
        case 'outcomes':
            generateOutcomesPDF($pdf, $conn, $dateFrom, $dateTo);
            break;
        case 'attendance':
            generateAttendancePDF($pdf, $conn, $dateFrom, $dateTo, $subtab);
            break;
        case 'evaluation':
            generateEvaluationPDF($pdf, $conn, $dateFrom, $dateTo);
            break;
        case 'trainees':
            generateTraineesPDF($pdf, $conn, $dateFrom, $dateTo);
            break;
        case 'certificates':
            generateCertificatesPDF($pdf, $conn, $dateFrom, $dateTo);
            break;
    }
    
    // Add footer
    $pdf->SetY(-50);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(90, 8, 'Prepared by:', 0, 0, 'L');
    $pdf->Cell(90, 8, 'Noted by:', 0, 1, 'L');
    $pdf->Ln(12);
    $pdf->Cell(90, 8, '_________________________', 0, 0, 'L');
    $pdf->Cell(90, 8, '_________________________', 0, 1, 'L');
    $pdf->Cell(90, 8, 'Signature over Printed Name', 0, 0, 'L', 0, '', 0, false, 'T', 'B');
    $pdf->Cell(90, 8, 'Program Coordinator', 0, 1, 'L', 0, '', 0, false, 'T', 'B');
    $pdf->Ln(3);
    $pdf->Cell(90, 8, 'Date: ' . date('F d, Y'), 0, 0, 'L');
    $pdf->Cell(90, 8, 'Date: ' . date('F d, Y'), 0, 1, 'L');
    
    // Add page number
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Close and output PDF document
    $pdf->Output('LEMS_Report_' . $tab . '_' . date('Ymd_His') . '.pdf', 'D');
}

// Helper function for sanitizing file names
function sanitizeFileName($fileName) {
    $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
    $fileName = preg_replace('/_+/', '_', $fileName);
    return $fileName;
}

// Function to generate certificates PDF report
function generateCertificatesPDF($pdf, $conn, $dateFrom, $dateTo) {
    // Fetch all completed trainees
    $result = fetchAllCompletedTrainees($conn, $dateFrom, $dateTo);
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        $eligibleCount = 0;
        $notEligibleCount = 0;
        
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
            if ($row['eligibility_status'] == 'Eligible') {
                $eligibleCount++;
            } else {
                $notEligibleCount++;
            }
        }
        
        // Add summary statistics
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Certificate Eligibility Summary', 0, 1, 'L');
        $pdf->Ln(5);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Total Completed Trainees: ' . count($data), 0, 1, 'L');
        $pdf->Cell(0, 6, 'Eligible for Certificates: ' . $eligibleCount, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Not Eligible: ' . $notEligibleCount, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Period: ' . date('F d, Y', strtotime($dateFrom)) . ' to ' . date('F d, Y', strtotime($dateTo)), 0, 1, 'L');
        $pdf->Ln(10);
        
        // Create table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        
        // Define column widths and alignments
        $header = array('Trainee Name', 'Program', 'Completion Date', 'Assessment', 'Feedback', 'Eligibility Status');
        $w = array(60, 50, 40, 30, 30, 50);
        $align = array('L', 'L', 'C', 'C', 'C', 'C');
        
        // Header
        for($i=0; $i<count($header); $i++) {
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
        }
        $pdf->Ln();
        
        // Data
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        
        foreach($data as $row) {
            // Alternate row background
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            // Trainee Name
            $pdf->Cell($w[0], 8, htmlspecialchars($row['fullname']), 'LR', 0, 'L', $fill);
            
            // Program
            $pdf->Cell($w[1], 8, htmlspecialchars($row['program_name']), 'LR', 0, 'L', $fill);
            
            // Completion Date
            $pdf->Cell($w[2], 8, $row['completion_date'], 'LR', 0, 'C', $fill);
            
            // Assessment
            $assessment = $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set';
            $pdf->Cell($w[3], 8, $assessment, 'LR', 0, 'C', $fill);
            
            // Feedback
            $pdf->Cell($w[4], 8, $row['has_feedback'], 'LR', 0, 'C', $fill);
            
            // Eligibility Status
            $pdf->Cell($w[5], 8, $row['eligibility_status'], 'LR', 0, 'C', $fill);
            $pdf->Ln();
            
            $fill = !$fill;
        }
        
        // Close the table
        $pdf->Cell(array_sum($w), 0, '', 'T');
        
        // Add eligibility requirements note
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Certificate Eligibility Requirements:', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', '', 9);
        $requirements = [
            '1. Enrollment status must be "completed"',
            '2. Assessment must be marked as "passed"',
            '3. Feedback must be submitted for the program'
        ];
        
        foreach($requirements as $req) {
            $pdf->Cell(0, 6, $req, 0, 1, 'L');
        }
        
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 10, 'No completed trainee data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

function generateOutcomesPDF($pdf, $conn, $dateFrom, $dateTo) {
    // Fetch data
    $result = fetchTraineeOutcomes($conn, $dateFrom, $dateTo);
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Create table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        
        // Define column widths and alignments
        $header = array('Full Name', 'Program', 'Status', 'Certification', 'Assessment', 'Date Completed');
        $w = array(60, 50, 30, 40, 30, 40);
        $align = array('L', 'L', 'C', 'C', 'C', 'C');
        
        // Header
        for($i=0; $i<count($header); $i++) {
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
        }
        $pdf->Ln();
        
        // Data
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        
        foreach($data as $row) {
            // Alternate row background
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            // Full Name
            $name = htmlspecialchars($row['fullname']);
            $pdf->Cell($w[0], 8, $name, 'LR', 0, 'L', $fill);
            
            // Program
            $program = htmlspecialchars($row['program_enrolled']);
            $pdf->Cell($w[1], 8, $program, 'LR', 0, 'L', $fill);
            
            // Status
            $status = ucfirst($row['status']);
            $pdf->Cell($w[2], 8, $status, 'LR', 0, 'C', $fill);
            
            // Certification
            $cert = $row['certification'];
            $pdf->Cell($w[3], 8, $cert, 'LR', 0, 'C', $fill);
            
            // Assessment
            $assessment = $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set';
            $pdf->Cell($w[4], 8, $assessment, 'LR', 0, 'C', $fill);
            
            // Date Completed
            $date = $row['completion_date'] ? $row['completion_date'] : 'In Progress';
            $pdf->Cell($w[5], 8, $date, 'LR', 0, 'C', $fill);
            $pdf->Ln();
            
            $fill = !$fill;
        }
        
        // Close the table
        $pdf->Cell(array_sum($w), 0, '', 'T');
        
        // Add total count at bottom
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Total Records: ' . count($data), 0, 1, 'R');
        
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 10, 'No trainee outcome data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

function generateAttendancePDF($pdf, $conn, $dateFrom, $dateTo, $subtab) {
    if ($subtab == 'trainer-attendance') {
        // Fetch data
        $result = fetchTrainerAttendance($conn, $dateFrom, $dateTo);
        
        if ($result && $result->num_rows > 0) {
            $data = [];
            
            while($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            // Create table header with reduced font size
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->SetTextColor(0, 0, 0);
            
            $header = array('Trainer Name', 'Program(s) Handled', 'Days Present', 'Last Attendance', 'Status');
            $w = array(60, 70, 30, 50, 30);
            $align = array('L', 'L', 'C', 'C', 'C');
            
            // Header
            for($i=0; $i<count($header); $i++) {
                $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
            }
            $pdf->Ln();
            
            // Data with reduced font size
            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
            
            foreach($data as $row) {
                // Determine status
                $status = '';
                if ($row['days_present'] >= 20) {
                    $status = 'Regular';
                } elseif ($row['days_present'] >= 10) {
                    $status = 'Irregular';
                } elseif ($row['days_present'] > 0) {
                    $status = 'Poor';
                } else {
                    $status = 'No Attendance';
                }
                
                // Alternate row background
                if ($fill) {
                    $pdf->SetFillColor(245, 245, 245);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }
                
                $pdf->Cell($w[0], 7, htmlspecialchars($row['name']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[1], 7, htmlspecialchars($row['programs_handled'] ?: 'No program assigned'), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[2], 7, $row['days_present'] . ' days', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[3], 7, $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[4], 7, $status, 'LR', 0, 'C', $fill);
                
                $pdf->Ln();
                $fill = !$fill;
            }
            
            // Close the table
            $pdf->Cell(array_sum($w), 0, '', 'T');
            
            // Add total count
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 8, 'Total Trainers: ' . count($data), 0, 1, 'R');
            
        } else {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(231, 76, 60);
            $pdf->Cell(0, 10, 'No trainer attendance data found for the selected period.', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
        
    } else {
        // Fetch data
        $result = fetchTraineeAttendance($conn, $dateFrom, $dateTo);
        
        if ($result && $result->num_rows > 0) {
            $data = [];
            
            while($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            // Create table header
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->SetTextColor(0, 0, 0);
            
            $header = array('Trainee Name', 'Program', 'Attendance %', 'Days Present', 'Last Attendance', 'Status');
            $w = array(50, 50, 30, 30, 50, 30);
            $align = array('L', 'L', 'C', 'C', 'C', 'C');
            
            // Header
            for($i=0; $i<count($header); $i++) {
                $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
            }
            $pdf->Ln();
            
            // Data
            $pdf->SetFont('helvetica', '', 9);
            $fill = false;
            
            foreach($data as $row) {
                // Determine status
                $status = '';
                if ($row['attendance_percentage'] >= 80) {
                    $status = 'Good';
                } elseif ($row['attendance_percentage'] >= 60) {
                    $status = 'Fair';
                } else {
                    $status = 'Poor';
                }
                
                // Alternate row background
                if ($fill) {
                    $pdf->SetFillColor(245, 245, 245);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }
                
                $pdf->Cell($w[0], 8, htmlspecialchars($row['name']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[1], 8, htmlspecialchars($row['program']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[2], 8, $row['attendance_percentage'] . '%', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[3], 8, $row['days_present'] . ' days', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[4], 8, $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[5], 8, $status, 'LR', 0, 'C', $fill);
                
                $pdf->Ln();
                $fill = !$fill;
            }
            
            // Close the table
            $pdf->Cell(array_sum($w), 0, '', 'T');
            
            // Add total count
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 8, 'Total Trainees: ' . count($data), 0, 1, 'R');
            
        } else {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(231, 76, 60);
            $pdf->Cell(0, 10, 'No trainee attendance data found for the selected period.', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    }
}

function generateEvaluationPDF($pdf, $conn, $dateFrom, $dateTo) {
    // Fetch data
    $result = fetchTrainerEvaluations($conn, $dateFrom, $dateTo);
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Create table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        
        $header = array('Trainer Name', 'Program', 'Start Date', 'Trainees Assigned', 'Slots Available', 'Utilization %');
        $w = array(60, 60, 40, 40, 40, 40);
        $align = array('L', 'L', 'C', 'C', 'C', 'C');
        
        // Header
        for($i=0; $i<count($header); $i++) {
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
        }
        $pdf->Ln();
        
        // Data
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        
        foreach($data as $row) {
            $utilization = $row['slots_available'] > 0 
                ? round(($row['trainees_assigned'] / $row['slots_available']) * 100, 1)
                : 0;
            
            // Alternate row background
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            $pdf->Cell($w[0], 8, htmlspecialchars($row['trainer_name']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[1], 8, htmlspecialchars($row['program']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[2], 8, $row['start_date'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[3], 8, $row['trainees_assigned'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[4], 8, $row['slots_available'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[5], 8, $utilization . '%', 'LR', 0, 'C', $fill);
            
            $pdf->Ln();
            $fill = !$fill;
        }
        
        // Close the table
        $pdf->Cell(array_sum($w), 0, '', 'T');
        
        // Add total count
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Total Trainers: ' . count($data), 0, 1, 'R');
        
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 10, 'No trainer evaluation data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

function generateTraineesPDF($pdf, $conn, $dateFrom, $dateTo) {
    // Fetch data
    $result = fetchTraineesDetailed($conn, $dateFrom, $dateTo);
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Create table with proper alignment
        $pageWidth = 297;
        $availableWidth = $pageWidth - 30;
        
        // Column widths
        $w = [
            $availableWidth * 0.12,
            $availableWidth * 0.12,
            $availableWidth * 0.10,
            $availableWidth * 0.10,
            $availableWidth * 0.06,
            $availableWidth * 0.10,
            $availableWidth * 0.04,
            $availableWidth * 0.09,
            $availableWidth * 0.10,
            $availableWidth * 0.09,
            $availableWidth * 0.11,
            $availableWidth * 0.07
        ];
        
        // Ensure minimum widths
        $minWidths = [25, 20, 20, 20, 15, 18, 10, 20, 18, 20, 15];
        for($i = 0; $i < count($w); $i++) {
            $w[$i] = max($minWidths[$i], $w[$i]);
        }
        
        // Create table header with reduced font size
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $header = array('NAME', 'STREET', 'BARANGAY', 'CITY/MUNICIPALITY', 'GENDER', 'CIVIL STATUS', 'AGE', 'CONTACT', 'EMPLOYMENT', 'EDUCATION', 'TRAINING', 'PROGRAM');
        $align = array('L', 'L', 'L', 'L', 'C', 'C', 'C', 'L', 'C', 'L', 'L', 'L');
        
        // Header
        for($i=0; $i<count($header); $i++) {
            $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
        }
        $pdf->Ln();
        
        // Data rows with reduced font size
        $pdf->SetFont('helvetica', '', 6.5);
        $fill = false;
        $rowCount = 0;
        
        foreach($data as $row) {
            // Alternate row background
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            // Split address into components
            $address = $row['address'];
            $street = '';
            $barangay = '';
            $city = '';
            
            // Parse address
            if (!empty($address)) {
                $addressParts = explode(',', $address);
                if (count($addressParts) >= 3) {
                    $street = trim($addressParts[0]);
                    $barangay = trim($addressParts[1]);
                    $city = trim($addressParts[2]);
                } elseif (count($addressParts) == 2) {
                    $street = trim($addressParts[0]);
                    $barangay = trim($addressParts[1]);
                    $city = '';
                } else {
                    $street = trim($address);
                    $barangay = '';
                    $city = '';
                }
            }
            
            // Truncate text content for display
            $name = (strlen($row['fullname']) > 20) ? substr($row['fullname'], 0, 17) . '...' : $row['fullname'];
            $streetDisplay = (strlen($street) > 20) ? substr($street, 0, 17) . '...' : $street;
            $barangayDisplay = (strlen($barangay) > 15) ? substr($barangay, 0, 12) . '...' : $barangay;
            $cityDisplay = (strlen($city) > 15) ? substr($city, 0, 12) . '...' : $city;
            $contact = (strlen($row['contact_number']) > 15) ? substr($row['contact_number'], 0, 12) . '...' : $row['contact_number'];
            $education = (strlen($row['education']) > 15) ? substr($row['education'], 0, 12) . '...' : $row['education'];
            $training = (strlen($row['trainings_attended']) > 10) ? substr($row['trainings_attended'], 0, 7) . '...' : $row['trainings_attended'];
            $program = (strlen($row['enrolled_program']) > 15) ? substr($row['enrolled_program'], 0, 12) . '...' : $row['enrolled_program'];
            
            // Draw cells with split address
            $pdf->Cell($w[0], 6, $name, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[1], 6, $streetDisplay, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[2], 6, $barangayDisplay, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[3], 6, $cityDisplay, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[4], 6, $row['gender'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[5], 6, $row['civil_status'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[6], 6, $row['age'] ?: '', 'LR', 0, 'C', $fill);
            $pdf->Cell($w[7], 6, $contact, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[8], 6, $row['employment_status'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[9], 6, $education, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[10], 6, $training, 'LR', 0, 'L', $fill);
            $pdf->Cell($w[11], 6, $program ?: 'Not enrolled', 'LR', 0, 'L', $fill);
            $pdf->Ln();
            
            $fill = !$fill;
            $rowCount++;
            
            // Check if we need a new page
            if ($pdf->GetY() > 180 && $rowCount < count($data)) {
                $pdf->AddPage('L');
                
                // Redraw header on new page with smaller font
                $pdf->SetFont('helvetica', 'B', 7);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->SetTextColor(0, 0, 0);
                for($i=0; $i<count($header); $i++) {
                    $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
                }
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 6.5);
            }
        }
        
        // Close the table
        $pdf->Cell(array_sum($w), 0, '', 'T');
        
        // Footer text
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Total Records: ' . count($data), 0, 1, 'R');
        
    } else {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 20, 'No trainee data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

// Include header
include '../components/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Livelihood Program Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS styles remain the same as your original file */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --transition-speed: 0.3s;
            --transition-speed-fast: 0.2s;
            --transition-speed-slow: 0.4s;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .section-header h2 {
            color: var(--primary-color);
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-filter {
            background: white;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .date-filter label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .date-filter input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            height: 36px;
        }
        
        .date-filter button {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all var(--transition-speed-fast);
            font-size: 0.9rem;
            height: 36px;
        }
        
        .date-filter button:hover {
            background-color: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(41, 128, 185, 0.2);
        }
        
        .main-tabs {
            display: flex;
            background: white;
            border-radius: 6px 6px 0 0;
            overflow: hidden;
            margin-bottom: 0;
        }
        
        .main-tab {
            padding: 14px 20px;
            background: #f8f9fa;
            border: none;
            font-size: 0.95rem;
            font-weight: 600;
            color: #555;
            cursor: pointer;
            transition: all var(--transition-speed-fast);
            flex: 1;
            text-align: center;
            border-bottom: 2px solid transparent;
        }
        
        .main-tab:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        .main-tab.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 2px solid var(--secondary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .tab-content {
            display: none;
            background: white;
            padding: 20px;
            border-radius: 0 0 6px 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        
        /* Attendance Sub-tabs */
        .sub-tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 6px 6px 0 0;
            overflow: hidden;
            margin-bottom: 0;
            width: 100%;
            max-width: 350px;
        }
        
        .sub-tab {
            padding: 12px 18px;
            background: #f8f9fa;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: #555;
            cursor: pointer;
            transition: all var(--transition-speed-fast);
            flex: 1;
            text-align: center;
            border-bottom: 2px solid transparent;
        }
        
        .sub-tab:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        .sub-tab.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 2px solid var(--secondary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .sub-tab-content {
            display: none;
        }
        
        .sub-tab-content.active {
            display: block;
            animation: slideIn 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        
        /* Enhanced Animations */
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(8px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0; 
                transform: translateX(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateX(0); 
            }
        }
        
        .export-buttons {
            display: flex;
            gap: 8px;
        }
        
        .export-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-speed-fast);
            border: none;
            font-size: 0.85rem;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.12);
        }
        
        .export-pdf {
            background-color: var(--danger-color);
            color: white;
        }
        
        .export-pdf:hover {
            background-color: #c0392b;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.25);
        }
        
        .export-certificate {
            background-color: var(--success-color);
            color: white;
        }
        
        .export-certificate:hover {
            background-color: #219653;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.25);
        }
        
        .export-btn i {
            font-size: 0.9rem;
        }
        
        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 0.85rem;
        }
        
        th {
            background-color: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 2px solid #dee2e6;
            font-size: 0.9rem;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
            font-size: 0.85rem;
        }
        
        tr {
            transition: background-color var(--transition-speed-fast);
        }
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Certificate tab specific styles */
        .certificate-preview {
            background: white;
            border: 2px solid #8B0000;
            padding: 40px;
            margin: 20px auto;
            max-width: 800px;
            position: relative;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            background-image: linear-gradient(to bottom, #fff 0%, #f9f9f9 100%);
        }
        
        .certificate-preview:before {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            bottom: 20px;
            border: 1px solid #8B0000;
            pointer-events: none;
        }
        
        .certificate-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #8B0000;
            padding-bottom: 20px;
        }
        
        .certificate-title {
            color: #8B0000;
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        
        .certificate-body {
            text-align: center;
            padding: 20px 0;
        }
        
        .certificate-name {
            color: #00008B;
            font-size: 2.2rem;
            font-weight: bold;
            margin: 20px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .certificate-program {
            color: #8B0000;
            font-size: 1.5rem;
            font-style: italic;
            margin: 15px 0;
        }
        
        .certificate-details {
            color: #333;
            font-size: 1rem;
            margin: 10px 0;
        }
        
        .certificate-footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .certificate-signature {
            text-align: center;
            width: 45%;
        }
        
        .certificate-id {
            text-align: center;
            margin-top: 30px;
            color: #666;
            font-style: italic;
            font-size: 0.9rem;
        }
        
        .certificate-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .certificate-stat-card {
            background: white;
            padding: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            text-align: center;
            transition: all var(--transition-speed);
            border: 1px solid #eee;
        }
        
        .certificate-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        }
        
        .certificate-stat-card i {
            font-size: 2rem;
            margin-bottom: 12px;
            color: #8B0000;
        }
        
        .certificate-stat-card h3 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: var(--primary-color);
        }
        
        .certificate-stat-card p {
            color: #666;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .certificate-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all var(--transition-speed-fast);
        }
        
        .status-badge:hover {
            transform: scale(1.05);
        }
        
        .status-complete {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-ongoing {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-dropped {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .attendance-high {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .attendance-medium {
            color: var(--warning-color);
            font-weight: 600;
        }
        
        .attendance-low {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        /* Centered Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            justify-items: center;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            text-align: center;
            transition: all var(--transition-speed);
            border: 1px solid #eee;
            width: 100%;
            max-width: 240px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        }
        
        .stat-card i {
            font-size: 2rem;
            margin-bottom: 12px;
            transition: transform var(--transition-speed);
        }
        
        .stat-card:hover i {
            transform: scale(1.1);
        }
        
        .stat-card .stat-icon-1 { color: #3498db; }
        .stat-card .stat-icon-2 { color: #9b59b6; }
        .stat-card .stat-icon-3 { color: #2ecc71; }
        .stat-card .stat-icon-4 { color: #e74c3c; }
        .stat-card .stat-icon-5 { color: #17a2b8; }
        
        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 8px;
            color: var(--primary-color);
        }
        
        .stat-card p {
            color: #666;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .no-data {
            text-align: center;
            padding: 30px;
            color: #777;
            font-size: 0.95rem;
        }
        
        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #f5c6cb;
            font-size: 0.9rem;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #c3e6cb;
            font-size: 0.9rem;
        }
        
        .summary-section {
            margin-top: 15px;
            padding: 12px;
            background-color: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid var(--info-color);
        }
        
        .summary-section h3 {
            color: var(--primary-color);
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .summary-section p {
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* Reset Filters button */
        a.export-btn[style*="background-color: #95a5a6"] {
            background-color: #95a5a6 !important;
            transition: all var(--transition-speed-fast);
        }
        
        a.export-btn[style*="background-color: #95a5a6"]:hover {
            background-color: #7f8c8d !important;
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(149, 165, 166, 0.2);
        }
        
        /* Single certificate button */
        .single-cert-btn {
            background-color: var(--info-color);
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        
        .single-cert-btn:hover {
            background-color: #138496;
            transform: translateY(-1px);
        }
        
        /* Eligibility status colors */
        .eligibility-eligible {
            background-color: #d4edda;
            color: #155724;
        }
        
        .eligibility-missing {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .eligibility-not-passed {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .eligibility-missing-both {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            .certificate-stats {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
        

        @media (max-width: 992px) {
            .container {
                padding: 10px;
            }
            
            .section-header h2 {
                font-size: 1.2rem;
            }
            
            .stat-card h3 {
                font-size: 1.8rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
            
            .certificate-preview {
                padding: 30px;
            }
            
            .certificate-title {
                font-size: 1.8rem;
            }
            
            .certificate-name {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-tabs {
                flex-direction: column;
            }
            
            .main-tab {
                padding: 12px;
                font-size: 0.9rem;
            }
            
            .sub-tabs {
                flex-direction: column;
                max-width: 100%;
            }
            
            .sub-tab {
                padding: 10px;
                font-size: 0.85rem;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .export-btn {
                width: 100%;
                justify-content: center;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            table {
                display: block;
                overflow-x: auto;
                font-size: 0.8rem;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            /* Trainee masterlist responsive */
            #trainees table {
                font-size: 0.7rem !important;
            }
            
            #trainees th,
            #trainees td {
                padding: 6px !important;
                font-size: 0.7rem !important;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            
            .stat-card {
                padding: 15px;
                max-width: 180px;
            }
            
            .stat-card h3 {
                font-size: 1.6rem;
            }
            
            .stat-card i {
                font-size: 1.8rem;
            }
            
            .certificate-footer {
                flex-direction: column;
                gap: 30px;
            }
            
            .certificate-signature {
                width: 100%;
            }
            
            .certificate-preview {
                padding: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .date-filter {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .date-filter > div {
                width: 100%;
            }
            
            .date-filter input,
            .date-filter button {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                max-width: 280px;
                margin-left: auto;
                margin-right: auto;
            }
            
            .stat-card {
                max-width: 100%;
            }
            
            .section-header h2 {
                font-size: 1.1rem;
            }
            
            .certificate-title {
                font-size: 1.5rem;
            }
            
            .certificate-name {
                font-size: 1.5rem;
            }
            
            .certificate-program {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>

 <header>
        <div class="container">
            <h1>Reports and Monitoring</h1>
        </div>
    </header>

    <div class="container">
        <?php if (isset($errorMessage)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                <p>Please check your database connection and try again.</p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($certificateError)): ?>
            <div class="error-message">
                <strong>Certificate Data Error:</strong> <?php echo htmlspecialchars($certificateError); ?>
                <p>The system is calculating duration based on program dates instead of using a duration_hours column.</p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'no_certificates'): ?>
            <div class="error-message">
                <strong>No Certificates Available:</strong> No trainees are eligible for certificates in the selected date range.
                <p>To be eligible, trainees must have: 1) Completed status, 2) Passed assessment, 3) Submitted feedback</p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success']) && $_GET['success'] == 'certificates_generated'): ?>
            <div class="success-message">
                <strong>Success!</strong> Certificates have been generated successfully. The download should start automatically.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'feedback_table'): ?>
            <div class="error-message">
                <strong>Database Error:</strong> The feedback table is required for certificate generation.
                <p>Please run the SQL script to create the feedback table.</p>
            </div>
        <?php endif; ?>
        
        <form method="GET" class="date-filter">
            <div>
                <label for="date_from">From:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div>
                <label for="date_to">To:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div>
                <button type="submit">Apply Filter</button>
            </div>
            <div style="margin-left: auto;">
                <a href="reports_monitoring.php" class="export-btn" style="background-color: #95a5a6; text-decoration: none;">Reset Filters</a>
            </div>
        </form>
        
        <!-- Main Tab Navigation -->
        <div class="main-tabs">
            <button class="main-tab active" data-tab="outcomes">Trainee Outcomes</button>
            <button class="main-tab" data-tab="trainees">Trainee Masterlist</button>
            <button class="main-tab" data-tab="certificates">Certificate Generation</button>
            <button class="main-tab" data-tab="attendance">Attendance Tracking</button>
            <button class="main-tab" data-tab="evaluation">Trainer Evaluation</button>
        </div>
        
        <!-- Trainee Outcomes Tab -->
        <div id="outcomes" class="tab-content active">
            <div class="section-header">
                <h2><i class="fas fa-user-graduate"></i> Trainee Outcomes Tracking</h2>
                <div class="export-buttons">
                    <button class="export-btn export-pdf" onclick="exportReport('outcomes', 'pdf')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            
            <?php if ($traineeOutcomesData && $traineeOutcomesData->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Program Enrolled</th>
                        <th>Status</th>
                        <th>Certification</th>
                        <th>Assessment</th>
                        <th>Date Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $traineeOutcomesData->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['program_enrolled']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?php 
                                echo $row['certification'] == 'Certified' ? 'status-complete' : 
                                     ($row['certification'] == 'Pending' ? 'status-pending' : 'status-dropped');
                            ?>">
                                <?php echo $row['certification']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?php 
                                echo $row['assessment'] == 'passed' ? 'status-complete' : 'status-pending';
                            ?>">
                                <?php echo $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set'; ?>
                            </span>
                        </td>
                        <td><?php echo $row['completion_date'] ? $row['completion_date'] : 'In Progress'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-user-graduate"></i>
                <p>No trainee outcome data found for the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Trainee Masterlist Tab -->
        <div id="trainees" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Trainee Masterlist</h2>
                <div class="export-buttons">
                    <button class="export-btn export-pdf" onclick="exportReport('trainees', 'pdf')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            
            <?php 
            if ($traineesDetailedData && $traineesDetailedData->num_rows > 0): 
                $maleCount = 0;
                $femaleCount = 0;
                $data = [];
                while($row = $traineesDetailedData->fetch_assoc()) {
                    $data[] = $row;
                    if ($row['gender'] == 'Male') $maleCount++;
                    if ($row['gender'] == 'Female') $femaleCount++;
                }
                $totalCount = $maleCount + $femaleCount;
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-male stat-icon-1"></i>
                    <h3><?php echo $maleCount; ?></h3>
                    <p>Total Male</p>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-female stat-icon-2"></i>
                    <h3><?php echo $femaleCount; ?></h3>
                    <p>Total Female</p>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-users stat-icon-3"></i>
                    <h3><?php echo $totalCount; ?></h3>
                    <p>Grand Total</p>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-calendar-alt stat-icon-5"></i>
                    <h3><?php echo count($data); ?></h3>
                    <p>Total Records</p>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>NAME</th>
                        <th>STREET</th>
                        <th>BARANGAY</th>
                        <th>CITY/MUNICIPALITY</th>
                        <th>GENDER</th>
                        <th>CIVIL STATUS</th>
                        <th>AGE</th>
                        <th>CONTACT NUMBER</th>
                        <th>EMPLOYMENT STATUS</th>
                        <th>EDUCATION</th>
                        <th>TRAINING ATTENDED</th>
                        <th>ENROLLED PROGRAM</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data as $row): 
                        // Split address for HTML display
                        $address = $row['address'];
                        $street = '';
                        $barangay = '';
                        $city = '';
                        
                        if (!empty($address)) {
                            $addressParts = explode(',', $address);
                            if (count($addressParts) >= 3) {
                                $street = trim($addressParts[0]);
                                $barangay = trim($addressParts[1]);
                                $city = trim($addressParts[2]);
                            } elseif (count($addressParts) == 2) {
                                $street = trim($addressParts[0]);
                                $barangay = trim($addressParts[1]);
                                $city = '';
                            } else {
                                $street = trim($address);
                                $barangay = '';
                                $city = '';
                            }
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                        <td><?php echo htmlspecialchars($street); ?></td>
                        <td><?php echo htmlspecialchars($barangay); ?></td>
                        <td><?php echo htmlspecialchars($city); ?></td>
                        <td><?php echo htmlspecialchars($row['gender']); ?></td>
                        <td><?php echo htmlspecialchars($row['civil_status']); ?></td>
                        <td><?php echo $row['age']; ?></td>
                        <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                        <td>
                            <span class="status-badge <?php 
                                echo $row['employment_status'] == 'Employed' ? 'status-complete' : 
                                     ($row['employment_status'] == 'Self-employed' ? 'status-ongoing' : 'status-pending');
                            ?>">
                                <?php echo htmlspecialchars($row['employment_status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['education']); ?></td>
                        <td><?php echo htmlspecialchars($row['trainings_attended']); ?></td>
                        <td>
                            <?php if ($row['enrolled_program']): ?>
                            <span class="status-badge status-complete">
                                <?php echo htmlspecialchars($row['enrolled_program']); ?>
                            </span>
                            <?php else: ?>
                            <span class="status-badge status-pending">Not enrolled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="summary-section">
                <h3>Summary Report:</h3>
                <p><strong>Total Male Trainees:</strong> <?php echo $maleCount; ?></p>
                <p><strong>Total Female Trainees:</strong> <?php echo $femaleCount; ?></p>
                <p><strong>Grand Total Trainees:</strong> <?php echo $totalCount; ?></p>
                <p><strong>Period Covered:</strong> <?php echo date('F d, Y', strtotime($dateFrom)); ?> to <?php echo date('F d, Y', strtotime($dateTo)); ?></p>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-users"></i>
                <p>No trainee data found for the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Certificate Generation Tab -->
        <div id="certificates" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-certificate"></i> Certificate of Completion Generation</h2>
                <div class="export-buttons">
                    <?php if ($certificateData && $certificateData->num_rows > 0): ?>
                    <button class="export-btn export-certificate" onclick="generateBulkCertificates()">
                        <i class="fas fa-download"></i> Generate All Certificates (ZIP)
                    </button>
                    <?php endif; ?>
                    <button class="export-btn export-pdf" onclick="exportReport('certificates', 'pdf')">
                        <i class="fas fa-file-pdf"></i> Export Eligibility Report
                    </button>
                </div>
            </div>
            
            <?php 
            if ($certificateData && $certificateData->num_rows > 0): 
                $programStats = [];
                $eligibleData = [];
                
                while($row = $certificateData->fetch_assoc()) {
                    $eligibleData[] = $row;
                    $programName = $row['program_name'];
                    if (!isset($programStats[$programName])) {
                        $programStats[$programName] = 0;
                    }
                    $programStats[$programName]++;
                }
                $totalCertificates = count($eligibleData);
            ?>
            <div class="certificate-stats">
                <div class="certificate-stat-card">
                    <i class="fas fa-certificate"></i>
                    <h3><?php echo $totalCertificates; ?></h3>
                    <p>Total Eligible Certificates</p>
                </div>
                
                <div class="certificate-stat-card">
                    <i class="fas fa-graduation-cap"></i>
                    <h3><?php echo count($programStats); ?></h3>
                    <p>Programs Completed</p>
                </div>
                
                <?php 
                $mostCommonProgram = '';
                $maxCount = 0;
                foreach($programStats as $program => $count) {
                    if ($count > $maxCount) {
                        $maxCount = $count;
                        $mostCommonProgram = $program;
                    }
                }
                ?>
                
                <div class="certificate-stat-card">
                    <i class="fas fa-chart-bar"></i>
                    <h3><?php echo $maxCount; ?></h3>
                    <p>Most Completed: <?php echo substr($mostCommonProgram, 0, 20) . (strlen($mostCommonProgram) > 20 ? '...' : ''); ?></p>
                </div>
            </div>
            
            <!-- Certificate Preview -->
            <div class="certificate-preview">
                <div class="certificate-header">
                    <h2 class="certificate-title">Certificate of Completion</h2>
                </div>
                
                <div class="certificate-body">
                    <p>This is to certify that</p>
                    <h3 class="certificate-name">[TRAINEE NAME]</h3>
                    <p>has successfully completed</p>
                    <h4 class="certificate-program">[PROGRAM NAME]</h4>
                    <p class="certificate-details">Duration: [DURATION] hours</p>
                    <p class="certificate-details">From [START DATE] to [END DATE]</p>
                    <p class="certificate-details">Completed on: [COMPLETION DATE]</p>
                </div>
                
                <div class="certificate-footer">
                    <div class="certificate-signature">
                        <p>_________________________</p>
                        <p>Program Coordinator</p>
                        <p>Date: <?php echo date('F d, Y'); ?></p>
                    </div>
                    
                    <div class="certificate-signature">
                        <p>_________________________</p>
                        <p>Training Director</p>
                        <p>Date: <?php echo date('F d, Y'); ?></p>
                    </div>
                </div>
                
                <div class="certificate-id">
                    <p>Certificate ID: CERT-XXXXXXX-<?php echo date('Y'); ?></p>
                </div>
            </div>
            
            <div class="certificate-actions">
                <button class="export-btn export-certificate" onclick="generateBulkCertificates()" style="padding: 12px 24px; font-size: 1rem;">
                    <i class="fas fa-download"></i> Generate All Eligible Certificates (<?php echo $totalCertificates; ?> files)
                </button>
            </div>
            
            <?php endif; ?>
            
            <h3 style="margin-top: 30px; color: var(--primary-color);">Trainees Eligibility Status</h3>
            
            <?php if ($allCompletedData && $allCompletedData->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Trainee Name</th>
                        <th>Program</th>
                        <th>Completion Date</th>
                        <th>Assessment</th>
                        <th>Feedback</th>
                        <th>Eligibility Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    $allCompletedData->data_seek(0);
                    ?>
                    <?php while($row = $allCompletedData->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['program_name']); ?></td>
                        <td><?php echo $row['completion_date']; ?></td>
                        <td>
                            <span class="status-badge <?php 
                                echo $row['assessment'] == 'passed' ? 'status-complete' : 'status-dropped';
                            ?>">
                                <?php echo $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?php 
                                echo $row['has_feedback'] == 'Yes' ? 'status-complete' : 'status-pending';
                            ?>">
                                <?php echo $row['has_feedback']; ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusClass = '';
                            switch($row['eligibility_status']) {
                                case 'Eligible':
                                    $statusClass = 'eligibility-eligible';
                                    break;
                                case 'Missing Feedback':
                                    $statusClass = 'eligibility-missing';
                                    break;
                                case 'Assessment Not Passed':
                                    $statusClass = 'eligibility-not-passed';
                                    break;
                                case 'Missing Both':
                                case 'Not Eligible':
                                    $statusClass = 'eligibility-missing-both';
                                    break;
                            }
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $row['eligibility_status']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($row['eligibility_status'] == 'Eligible'): ?>
                            <button class="single-cert-btn" onclick="generateSingleCertificate(<?php echo $row['user_id']; ?>, <?php echo $row['program_id']; ?>)">
                                <i class="fas fa-certificate"></i> Generate
                            </button>
                            <?php else: ?>
                            <span style="color: #777; font-size: 0.8rem;">Not eligible</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <div class="summary-section">
                <h3>Certificate Eligibility Summary:</h3>
                <?php 
                // Count eligibility statuses
                $allCompletedData->data_seek(0);
                $eligibleCount = 0;
                $missingFeedbackCount = 0;
                $notPassedCount = 0;
                $missingBothCount = 0;
                
                while($row = $allCompletedData->fetch_assoc()) {
                    switch($row['eligibility_status']) {
                        case 'Eligible':
                            $eligibleCount++;
                            break;
                        case 'Missing Feedback':
                            $missingFeedbackCount++;
                            break;
                        case 'Assessment Not Passed':
                            $notPassedCount++;
                            break;
                        case 'Missing Both':
                        case 'Not Eligible':
                            $missingBothCount++;
                            break;
                    }
                }
                ?>
                <p><strong>Total Completed Trainees:</strong> <?php echo ($eligibleCount + $missingFeedbackCount + $notPassedCount + $missingBothCount); ?></p>
                <p><strong>Eligible for Certificates:</strong> <?php echo $eligibleCount; ?></p>
                <p><strong>Missing Feedback:</strong> <?php echo $missingFeedbackCount; ?></p>
                <p><strong>Assessment Not Passed:</strong> <?php echo $notPassedCount; ?></p>
                <p><strong>Missing Both Requirements:</strong> <?php echo $missingBothCount; ?></p>
                <p><strong>Period Covered:</strong> <?php echo date('F d, Y', strtotime($dateFrom)); ?> to <?php echo date('F d, Y', strtotime($dateTo)); ?></p>
                <p><strong>Eligibility Requirements:</strong></p>
                <ul style="margin-left: 20px; margin-top: 5px;">
                    <li>✓ Enrollment status: Completed</li>
                    <li>✓ Assessment: Passed</li>
                    <li>✓ Feedback: Submitted</li>
                </ul>
                <p><strong>Note:</strong> Only trainees meeting all three requirements are eligible for certificates.</p>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-certificate"></i>
                <p>No trainees have completed their programs in the selected date range.</p>
                <p style="margin-top: 10px; font-size: 0.9rem;">Certificates are only available for trainees with "completed" status.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Attendance Tracking Tab -->
        <div id="attendance" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-calendar-check"></i> Attendance Tracking</h2>
                <div class="export-buttons">
                    <button class="export-btn export-pdf" onclick="exportReport('attendance', 'pdf')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            
            <!-- Attendance Sub-tabs -->
            <div style="margin-bottom: 30px;">
                <div class="sub-tabs">
                    <button class="sub-tab active" data-subtab="trainer-attendance">
                        <i class="fas fa-chalkboard-teacher"></i> Trainer Attendance
                    </button>
                    <button class="sub-tab" data-subtab="trainee-attendance">
                        <i class="fas fa-user-graduate"></i> Trainee Attendance
                    </button>
                </div>
                
                <!-- Trainer Attendance Sub-tab -->
                <div id="trainer-attendance" class="sub-tab-content active">
                    <div style="margin-top: 25px;">
                        <h3 style="color: var(--primary-color); margin-bottom: 20px;">
                            <i class="fas fa-chalkboard-teacher"></i> Trainer Attendance Records
                        </h3>
                        
                        <?php if ($trainerAttendanceData && $trainerAttendanceData->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Trainer Name</th>
                                    <th>Program(s) Handled</th>
                                    <th>Days Present</th>
                                    <th>Last Attendance</th>
                                    <th>Attendance Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $trainerAttendanceData->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td>
                                        <?php 
                                        if (!empty($row['programs_handled'])) {
                                            echo htmlspecialchars($row['programs_handled']);
                                        } else {
                                            echo '<span class="status-badge status-pending">No program assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $row['days_present']; ?> days</td>
                                    <td>
                                        <?php 
                                        if ($row['last_attendance']) {
                                            echo date('M d, Y', strtotime($row['last_attendance']));
                                        } else {
                                            echo 'No record';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status = '';
                                        $statusClass = '';
                                        if ($row['days_present'] >= 20) {
                                            $status = 'Regular';
                                            $statusClass = 'status-complete';
                                        } elseif ($row['days_present'] >= 10) {
                                            $status = 'Irregular';
                                            $statusClass = 'status-ongoing';
                                        } elseif ($row['days_present'] > 0) {
                                            $status = 'Poor';
                                            $statusClass = 'status-dropped';
                                        } else {
                                            $status = 'No Attendance';
                                            $statusClass = 'status-pending';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="no-data" style="padding: 40px;">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <p>No trainer attendance data found for the selected date range.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Trainee Attendance Sub-tab -->
                <div id="trainee-attendance" class="sub-tab-content">
                    <div style="margin-top: 25px;">
                        <h3 style="color: var(--primary-color); margin-bottom: 20px;">
                            <i class="fas fa-user-graduate"></i> Trainee Attendance Records
                        </h3>
                        
                        <?php if ($traineeAttendanceData && $traineeAttendanceData->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Trainee Name</th>
                                    <th>Program</th>
                                    <th>Attendance %</th>
                                    <th>Days Present</th>
                                    <th>Last Attendance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $traineeAttendanceData->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['program']); ?></td>
                                    <td class="<?php 
                                        if ($row['attendance_percentage'] >= 80) echo 'attendance-high';
                                        elseif ($row['attendance_percentage'] >= 60) echo 'attendance-medium';
                                        else echo 'attendance-low';
                                    ?>">
                                        <?php echo $row['attendance_percentage']; ?>%
                                    </td>
                                    <td><?php echo $row['days_present']; ?> days</td>
                                    <td>
                                        <?php 
                                        if ($row['last_attendance']) {
                                            echo date('M d, Y', strtotime($row['last_attendance']));
                                        } else {
                                            echo 'No record';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status = '';
                                        $statusClass = '';
                                        if ($row['attendance_percentage'] >= 80) {
                                            $status = 'Good';
                                            $statusClass = 'status-complete';
                                        } elseif ($row['attendance_percentage'] >= 60) {
                                            $status = 'Fair';
                                            $statusClass = 'status-ongoing';
                                        } else {
                                            $status = 'Poor';
                                            $statusClass = 'status-dropped';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="no-data" style="padding: 40px;">
                            <i class="fas fa-user-graduate"></i>
                            <p>No trainee attendance data found for the selected date range.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Trainer Evaluation Tab -->
        <div id="evaluation" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-clipboard-check"></i> Trainer Evaluation & Performance</h2>
                <div class="export-buttons">
                    <button class="export-btn export-pdf" onclick="exportReport('evaluation', 'pdf')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            
            <?php if ($trainerEvaluationData && $trainerEvaluationData->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Trainer Name</th>
                        <th>Program</th>
                        <th>Start Date</th>
                        <th>Trainees Assigned</th>
                        <th>Slots Available</th>
                        <th>Utilization Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $trainerEvaluationData->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['trainer_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['program']); ?></td>
                        <td><?php echo $row['start_date']; ?></td>
                        <td><?php echo $row['trainees_assigned']; ?></td>
                        <td><?php echo $row['slots_available']; ?></td>
                        <td class="<?php 
                            $utilization = $row['slots_available'] > 0 
                                ? round(($row['trainees_assigned'] / $row['slots_available']) * 100, 1)
                                : 0;
                            
                            if ($utilization >= 80) echo 'attendance-high';
                            elseif ($utilization >= 60) echo 'attendance-medium';
                            else echo 'attendance-low';
                        ?>">
                            <?php echo $utilization; ?>%
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-clipboard-check"></i>
                <p>No trainer evaluation data found for the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Main tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mainTabs = document.querySelectorAll('.main-tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            mainTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    // Remove active class from all tabs and contents
                    mainTabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    tab.classList.add('active');
                    
                    // Show corresponding content
                    const tabId = tab.getAttribute('data-tab');
                    document.getElementById(tabId).classList.add('active');
                });
            });
            
            // Sub-tab switching functionality
            const subTabs = document.querySelectorAll('.sub-tab');
            const subTabContents = document.querySelectorAll('.sub-tab-content');
            
            subTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    // Remove active class from all sub-tabs and sub-contents
                    subTabs.forEach(t => t.classList.remove('active'));
                    subTabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked sub-tab
                    tab.classList.add('active');
                    
                    // Show corresponding sub-content
                    const subTabId = tab.getAttribute('data-subtab');
                    document.getElementById(subTabId).classList.add('active');
                });
            });
            
            // Add hover effect to buttons
            const buttons = document.querySelectorAll('button, .export-btn, .single-cert-btn');
            buttons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    this.style.transition = 'all 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                });
            });
            
            // Check if we should scroll to certificate tab
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tab') === 'certificates') {
                // Find and click the certificates tab
                const certTab = document.querySelector('.main-tab[data-tab="certificates"]');
                if (certTab) {
                    certTab.click();
                }
            }
        });
        
        // Export functionality for regular reports
        function exportReport(tab, format) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            // Check if we're in a sub-tab for attendance
            const activeSubTab = document.querySelector('.sub-tab.active');
            if (activeSubTab && tab === 'attendance') {
                const subTab = activeSubTab.getAttribute('data-subtab');
                window.location.href = `reports_monitoring.php?export=${format}&tab=${tab}&subtab=${subTab}&date_from=${dateFrom}&date_to=${dateTo}`;
            } else {
                window.location.href = `reports_monitoring.php?export=${format}&tab=${tab}&date_from=${dateFrom}&date_to=${dateTo}`;
            }
        }
        
        // Bulk certificate generation
        function generateBulkCertificates() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            // Show loading indicator
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Certificates...';
            button.disabled = true;
            
            // Make the request
            window.location.href = `reports_monitoring.php?export=pdf&tab=certificates&date_from=${dateFrom}&date_to=${dateTo}`;
            
            // Reset button after 5 seconds
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 5000);
        }
        
        // Single certificate generation
        function generateSingleCertificate(userId, programId) {
            // Show loading indicator
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.disabled = true;
            
            // Make the request with BOTH user_id AND program_id
            window.open(`generate_single_certificate.php?user_id=${userId}&program_id=${programId}`, '_blank');
            
            // Reset button after 3 seconds
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 3000);
        }
    </script>
</body>
</html>