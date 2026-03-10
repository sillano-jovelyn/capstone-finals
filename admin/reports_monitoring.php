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

// Set connection collation to prevent mismatches
if ($conn) {
    mysqli_set_charset($conn, "utf8mb4");
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->query("SET collation_connection = utf8mb4_unicode_ci");
    $conn->query("SET collation_database = utf8mb4_unicode_ci");
    $conn->query("SET collation_server = utf8mb4_unicode_ci");
}

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

// Fetch data for display based on current filters
try {
    $traineeOutcomesData    = fetchTraineeOutcomes($conn, $dateFrom, $dateTo);
    $trainerAttendanceData  = fetchTrainerAttendance($conn, $dateFrom, $dateTo);
    $traineeAttendanceData  = fetchTraineeAttendance($conn, $dateFrom, $dateTo);
    $trainerEvaluationData  = fetchTrainerEvaluations($conn, $dateFrom, $dateTo);
    $traineesDetailedData   = fetchTraineesDetailed($conn, $dateFrom, $dateTo);
    $certificateData        = fetchCertificateData($conn, $dateFrom, $dateTo);
    $allCompletedData       = fetchAllCompletedTrainees($conn, $dateFrom, $dateTo);
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

// ── Query Functions ────────────────────────────────────────────────────────────

function fetchTraineeOutcomes($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
                p.name COLLATE utf8mb4_unicode_ci AS program_enrolled,
                e.enrollment_status COLLATE utf8mb4_unicode_ci AS status,
                CASE 
                    WHEN e.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
                        AND e.assessment COLLATE utf8mb4_unicode_ci = 'passed' THEN 'Certified'
                    WHEN e.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
                        AND (e.assessment COLLATE utf8mb4_unicode_ci != 'passed' OR e.assessment IS NULL) THEN 'Pending Certification'
                    WHEN e.enrollment_status COLLATE utf8mb4_unicode_ci = 'pending' THEN 'Pending'
                    ELSE 'Not Certified'
                END AS certification,
                DATE_FORMAT(e.completed_at, '%b %d, %Y') AS completion_date,
                e.completed_at,
                e.assessment COLLATE utf8mb4_unicode_ci AS assessment
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              WHERE (e.applied_at BETWEEN ? AND ? OR e.applied_at IS NULL)
                AND u.role COLLATE utf8mb4_unicode_ci = 'trainee'
              ORDER BY e.completed_at DESC, u.fullname";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTrainerAttendance($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.id,
                u.fullname COLLATE utf8mb4_unicode_ci AS name,
                GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS programs_handled,
                COUNT(DISTINCT DATE(ta.attendance_time)) AS days_present,
                MAX(ta.attendance_time) AS last_attendance
              FROM users u
              LEFT JOIN trainer_attendance ta
                  ON u.fullname COLLATE utf8mb4_unicode_ci = ta.trainer_name COLLATE utf8mb4_unicode_ci
                  AND DATE(ta.attendance_time) BETWEEN ? AND ?
              LEFT JOIN programs p ON u.id = p.trainer_id
              WHERE u.role COLLATE utf8mb4_unicode_ci = 'trainer'
              GROUP BY u.id
              ORDER BY days_present DESC, u.fullname";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTraineeAttendance($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.fullname COLLATE utf8mb4_unicode_ci AS name,
                p.name COLLATE utf8mb4_unicode_ci AS program,
                e.attendance AS attendance_percentage,
                COUNT(DISTINCT ar.attendance_date) AS days_present,
                MAX(ar.attendance_date) AS last_attendance,
                e.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              LEFT JOIN attendance_records ar ON e.id = ar.enrollment_id
                  AND ar.attendance_date BETWEEN ? AND ?
              WHERE u.role COLLATE utf8mb4_unicode_ci = 'trainee'
              GROUP BY u.id, e.id
              ORDER BY e.attendance DESC, u.fullname";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTrainerEvaluations($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                u.fullname COLLATE utf8mb4_unicode_ci AS trainer_name,
                p.name COLLATE utf8mb4_unicode_ci AS program,
                DATE_FORMAT(p.scheduleStart, '%b %d, %Y') AS start_date,
                COUNT(e.id) AS trainees_assigned,
                p.slotsAvailable AS slots_available
              FROM users u
              LEFT JOIN programs p ON u.id = p.trainer_id
              LEFT JOIN enrollments e ON p.id = e.program_id
              WHERE u.role COLLATE utf8mb4_unicode_ci = 'trainer'
                AND (p.scheduleStart BETWEEN ? AND ? OR p.scheduleStart IS NULL)
              GROUP BY u.id, p.id
              ORDER BY p.scheduleStart DESC";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchTraineesDetailed($conn, $dateFrom, $dateTo) {
    $query = "SELECT 
                t.fullname COLLATE utf8mb4_unicode_ci AS fullname,
                t.lastname COLLATE utf8mb4_unicode_ci AS lastname,
                t.firstname COLLATE utf8mb4_unicode_ci AS firstname,
                t.middleinitial,
                t.address,
                t.house_street,
                t.barangay,
                t.municipality,
                t.city,
                t.gender COLLATE utf8mb4_unicode_ci AS gender,
                t.gender_specify,
                t.civil_status,
                t.age,
                t.contact_number,
                t.email COLLATE utf8mb4_unicode_ci AS email,
                t.employment_status COLLATE utf8mb4_unicode_ci AS employment_status,
                t.education,
                t.education_specify,
                t.trainings_attended,
                t.toolkit_received,
                t.applicant_type,
                t.nc_holder,
                DATE_FORMAT(t.created_at, '%Y-%m-%d') AS registration_date,
                p.name COLLATE utf8mb4_unicode_ci AS enrolled_program,
                e.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status,
                e.completed_at
              FROM trainees t
              LEFT JOIN users u ON t.email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
              LEFT JOIN enrollments e ON u.id = e.user_id
              LEFT JOIN programs p ON e.program_id = p.id
              WHERE (t.created_at BETWEEN ? AND ? OR t.created_at IS NULL)
              ORDER BY t.lastname, t.firstname";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchCertificateData($conn, $dateFrom, $dateTo) {
    $query = "
        SELECT 
            u.id as user_id,
            u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
            p.name COLLATE utf8mb4_unicode_ci AS program_name,
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
            e.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status,
            e.assessment COLLATE utf8mb4_unicode_ci AS assessment,
            f.id as feedback_id
        FROM users u
        JOIN enrollments e ON u.id = e.user_id
        JOIN programs p ON e.program_id = p.id
        LEFT JOIN feedback f ON u.id = f.user_id AND p.id = f.program_id
        WHERE e.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
            AND e.completed_at BETWEEN ? AND ?
            AND u.role COLLATE utf8mb4_unicode_ci = 'trainee'
            AND e.assessment COLLATE utf8mb4_unicode_ci = 'passed'
            AND f.id IS NOT NULL

        UNION

        SELECT 
            u.id as user_id,
            u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
            ah.program_name COLLATE utf8mb4_unicode_ci AS program_name,
            ah.original_program_id as program_id,
            ah.enrollment_id as enrollment_id,
            ah.program_duration AS duration_hours,
            DATE_FORMAT(ah.program_schedule_start, '%M %d, %Y') AS program_start_date,
            DATE_FORMAT(ah.program_schedule_end, '%M %d, %Y') AS program_end_date,
            DATE_FORMAT(ah.enrollment_completed_at, '%M %d, %Y') AS completion_date,
            ah.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status,
            ah.enrollment_assessment COLLATE utf8mb4_unicode_ci AS assessment,
            ah.feedback_id
        FROM archived_history ah
        JOIN users u ON ah.user_id = u.id
        WHERE ah.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
            AND ah.enrollment_completed_at BETWEEN ? AND ?
            AND LOWER(ah.enrollment_assessment COLLATE utf8mb4_unicode_ci) = 'passed'
            AND ah.feedback_id IS NOT NULL
            AND ah.program_name COLLATE utf8mb4_unicode_ci != '0'
            AND ah.program_name COLLATE utf8mb4_unicode_ci != ''

        ORDER BY program_name, fullname
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ssss", $dateFrom, $dateTo, $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchAllCompletedTrainees($conn, $dateFrom, $dateTo) {
    $query = "
        SELECT 
            u.id as user_id,
            u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
            p.name COLLATE utf8mb4_unicode_ci AS program_name,
            p.id as program_id,
            e.id as enrollment_id,
            DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
            e.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status,
            e.assessment COLLATE utf8mb4_unicode_ci AS assessment,
            CASE WHEN f.id IS NOT NULL THEN 'Yes' ELSE 'No' END AS has_feedback,
            CASE 
                WHEN e.assessment COLLATE utf8mb4_unicode_ci = 'passed' AND f.id IS NOT NULL THEN 'Eligible'
                WHEN e.assessment COLLATE utf8mb4_unicode_ci = 'passed' AND f.id IS NULL THEN 'Missing Feedback'
                WHEN e.assessment COLLATE utf8mb4_unicode_ci != 'passed' AND f.id IS NOT NULL THEN 'Assessment Not Passed'
                WHEN e.assessment IS NULL AND f.id IS NULL THEN 'Missing Both'
                ELSE 'Not Eligible'
            END AS eligibility_status
        FROM users u
        JOIN enrollments e ON u.id = e.user_id
        JOIN programs p ON e.program_id = p.id
        LEFT JOIN feedback f ON u.id = f.user_id AND p.id = f.program_id
        WHERE e.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
            AND e.completed_at BETWEEN ? AND ?
            AND u.role COLLATE utf8mb4_unicode_ci = 'trainee'

        UNION

        SELECT 
            u.id as user_id,
            u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
            ah.program_name COLLATE utf8mb4_unicode_ci AS program_name,
            ah.original_program_id as program_id,
            ah.enrollment_id as enrollment_id,
            DATE_FORMAT(ah.enrollment_completed_at, '%M %d, %Y') AS completion_date,
            ah.enrollment_status COLLATE utf8mb4_unicode_ci AS enrollment_status,
            ah.enrollment_assessment COLLATE utf8mb4_unicode_ci AS assessment,
            CASE WHEN ah.feedback_id IS NOT NULL THEN 'Yes' ELSE 'No' END AS has_feedback,
            CASE 
                WHEN LOWER(ah.enrollment_assessment COLLATE utf8mb4_unicode_ci) = 'passed' AND ah.feedback_id IS NOT NULL THEN 'Eligible'
                WHEN LOWER(ah.enrollment_assessment COLLATE utf8mb4_unicode_ci) = 'passed' AND ah.feedback_id IS NULL THEN 'Missing Feedback'
                WHEN LOWER(ah.enrollment_assessment COLLATE utf8mb4_unicode_ci) != 'passed' AND ah.feedback_id IS NOT NULL THEN 'Assessment Not Passed'
                WHEN ah.enrollment_assessment IS NULL AND ah.feedback_id IS NULL THEN 'Missing Both'
                ELSE 'Not Eligible'
            END AS eligibility_status
        FROM archived_history ah
        JOIN users u ON ah.user_id = u.id
        WHERE ah.enrollment_status COLLATE utf8mb4_unicode_ci = 'completed'
            AND ah.enrollment_completed_at BETWEEN ? AND ?
            AND ah.program_name COLLATE utf8mb4_unicode_ci != '0'
            AND ah.program_name COLLATE utf8mb4_unicode_ci != ''

        ORDER BY 
            CASE 
                WHEN eligibility_status = 'Eligible' THEN 1
                WHEN eligibility_status = 'Missing Feedback' THEN 2
                WHEN eligibility_status = 'Assessment Not Passed' THEN 3
                ELSE 4
            END,
            program_name, fullname
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ssss", $dateFrom, $dateTo, $dateFrom, $dateTo);
    $stmt->execute();
    return $stmt->get_result();
}

function fetchSingleCertificateData($conn, $user_id, $program_id) {
    $query = "SELECT 
                u.id as user_id,
                u.fullname COLLATE utf8mb4_unicode_ci AS fullname,
                p.name COLLATE utf8mb4_unicode_ci AS program_name,
                p.id as program_id,
                DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
                e.completed_at AS raw_completion_date
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              WHERE u.id = ? AND p.id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) return null;
    $stmt->bind_param("ii", $user_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) return $row;
    return null;
}

// ── Certificate PDF ────────────────────────────────────────────────────────────

function generateSingleCertificatePDF($data) {
    $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Livelihood Program Management System');
    $pdf->SetAuthor('System Administrator');
    $pdf->SetTitle('Certificate of Training - ' . $data['fullname']);
    $pdf->SetSubject('Certificate of Training');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(FALSE);
    $pdf->AddPage();

    $pdf->SetFillColor(245, 240, 232);
    $pdf->Rect(0, 0, 210, 297, 'F');

    $pdf->SetLineWidth(2);
    $pdf->SetDrawColor(45, 139, 142);
    $pdf->Rect(10, 10, 190, 277, 'D');
    $pdf->SetLineWidth(2);
    $pdf->SetDrawColor(212, 165, 116);
    $pdf->Rect(12, 12, 186, 273, 'D');
    $pdf->SetLineWidth(1);
    $pdf->SetDrawColor(45, 139, 142);
    $pdf->Rect(20, 20, 170, 257, 'D');

    $logoPath1 = __DIR__ . '/../trainee/SMBLOGO.jpg';
    $logoPath2 = __DIR__ . '/../trainee/SLOGO.jpg';
    $logoPath3 = __DIR__ . '/../trainee/TESDALOGO.png';
    $logoY = 35; $logoSize = 25; $spacing = 15;
    $totalLogoWidth = (3 * $logoSize) + (2 * $spacing);
    $startX = (210 - $totalLogoWidth) / 2;

    foreach ([[$logoPath1, $startX], [$logoPath2, $startX + $logoSize + $spacing], [$logoPath3, $startX + 2*($logoSize+$spacing)]] as [$path, $x]) {
        if (file_exists($path)) {
            $pdf->Image($path, $x, $logoY, $logoSize, $logoSize, '', '', '', true, 300, '', false, false, 0, false, false, false);
        } else {
            $pdf->SetFillColor(221, 221, 221);
            $pdf->Rect($x, $logoY, $logoSize, $logoSize, 'F');
        }
    }

    $pdf->SetY(70);
    $pdf->SetFont('times', 'B', 12); $pdf->SetTextColor(0,0,0);
    $pdf->Cell(0, 5, 'MUNICIPALITY OF SANTA MARIA, BULACAN', 0, 1, 'C');
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, 'IN COOPERATION WITH', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'TECHNICAL EDUCATION & SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 14); $pdf->SetTextColor(45, 139, 142);
    $pdf->Cell(0, 8, 'SANTA MARIA LIVELIHOOD TRAINING CENTER', 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->SetFont('times', 'B', 36); $pdf->SetTextColor(45, 139, 142);
    $pdf->Cell(0, 15, 'CERTIFICATE OF TRAINING', 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->SetFont('times', '', 14); $pdf->SetTextColor(0,0,0);
    $pdf->Cell(0, 8, 'is awarded to', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('times', 'B', 36);
    $pdf->Cell(0, 15, strtoupper($data['fullname']), 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 12);
    $pdf->Cell(0, 6, 'For having satisfactorily completed the', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('times', 'B', 28);
    $programName = strtoupper($data['program_name']);
    if (strlen($programName) > 40) {
        $pdf->MultiCell(0, 12, $programName, 0, 'C', false, 1);
    } else {
        $pdf->Cell(0, 12, $programName, 0, 1, 'C');
    }
    $pdf->Ln(8);
    $pdf->SetFont('times', '', 12);
    $timestamp = strtotime($data['completion_date']);
    $formattedDate = date('jS', $timestamp) . ' day of ' . date('F Y', $timestamp);
    $pdf->Cell(0, 6, 'Given this ' . $formattedDate . ' at Santa Maria Livelihood Training and', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Employment Center, Santa Maria, Bulacan.', 0, 1, 'C');
    $pdf->Ln(15);

    $signatureY = 210; $leftX = 35; $signatureWidth = 70;
    foreach ([
        [$signatureY,      'ZENAIDA S. MANINGAS',  'PESO Manager'],
        [$signatureY + 25, 'ROBERTO B. PEREZ',      'Municipal Vice Mayor'],
        [$signatureY + 50, 'BARTOLOME R. RAMOS',    'Municipal Mayor'],
    ] as [$y, $name, $title]) {
        $pdf->SetXY($leftX, $y);
        $pdf->SetFont('times', '', 10); $pdf->Cell($signatureWidth, 5, '____________________________', 0, 1, 'C');
        $pdf->SetX($leftX); $pdf->SetFont('times', 'B', 11); $pdf->Cell($signatureWidth, 5, $name, 0, 1, 'C');
        $pdf->SetX($leftX); $pdf->SetFont('times', '', 10); $pdf->Cell($signatureWidth, 5, $title, 0, 1, 'C');
    }

    $photoX = 135; $photoY = $signatureY + 10; $photoWidth = 45; $photoHeight = 55;
    $pdf->SetLineWidth(1); $pdf->SetDrawColor(136,136,136);
    $pdf->Rect($photoX, $photoY, $photoWidth, $photoHeight, 'D');
    $pdf->SetFillColor(245,245,245);
    $pdf->Rect($photoX+0.5, $photoY+0.5, $photoWidth-1, $photoHeight-1, 'F');
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(150,150,150);
    $pdf->SetXY($photoX, $photoY + ($photoHeight/2) - 4);
    $pdf->Cell($photoWidth, 8, 'PHOTO', 0, 0, 'C');
    $pdf->SetTextColor(0,0,0);
    $pdf->SetXY($photoX, $photoY + $photoHeight + 5);
    $pdf->SetFont('times', '', 10); $pdf->Cell($photoWidth, 5, '____________________', 0, 1, 'C');
    $pdf->SetX($photoX); $pdf->Cell($photoWidth, 4, 'Signature', 0, 1, 'C');

    $watermarkPath = __DIR__ . '/../trainee/SLOGO.jpg';
    if (file_exists($watermarkPath)) {
        $pdf->SetAlpha(0.06);
        $pdf->Image($watermarkPath, 55, 100, 100, 100, '', '', '', false, 300, '', false, false, 0, false, false, false);
        $pdf->SetAlpha(1);
    }

    return $pdf;
}

// ── Bulk Certificates ──────────────────────────────────────────────────────────

function generateBulkCertificates($conn, $dateFrom, $dateTo) {
    $result = fetchCertificateData($conn, $dateFrom, $dateTo);
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $tempDir = sys_get_temp_dir() . '/certificates_' . uniqid();
        if (!file_exists($tempDir)) mkdir($tempDir, 0777, true);

        $generatedFiles = [];
        foreach ($data as $row) {
            $certData = fetchSingleCertificateData($conn, $row['user_id'], $row['program_id']);
            if ($certData) {
                $pdf = generateSingleCertificatePDF($certData);
                $filename = sanitizeFileName($certData['fullname'] . '_' . $certData['program_name']) . '.pdf';
                $pdfFile  = $tempDir . '/' . $filename;
                $pdf->Output($pdfFile, 'F');
                $generatedFiles[] = $pdfFile;
            }
        }

        if (count($generatedFiles) > 0) {
            $zip         = new ZipArchive();
            $zipFileName = 'certificates_' . date('Ymd_His') . '.zip';
            $zipPath     = sys_get_temp_dir() . '/' . $zipFileName;

            if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
                foreach ($generatedFiles as $file) $zip->addFile($file, basename($file));
                $zip->close();
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                header('Content-Length: ' . filesize($zipPath));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                readfile($zipPath);
                unlink($zipPath);
                exit();
            } else {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
                die('Failed to create ZIP archive');
            }
        } else {
            rmdir($tempDir);
            header('Location: reports_monitoring.php?error=no_certificates&tab=certificates');
            exit();
        }
    } else {
        header('Location: reports_monitoring.php?error=no_certificates&tab=certificates');
        exit();
    }
}

// ── Main PDF Report Generator ──────────────────────────────────────────────────

function generatePDFReport($conn, $tab, $subtab, $dateFrom, $dateTo) {

    // Use long bond landscape for trainees tab; default for all others
    if ($tab === 'trainees') {
        $pdf = new TCPDF('L', PDF_UNIT, [330, 216], true, 'UTF-8', false);
    } else {
        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    }

    $pdf->SetCreator('Livelihood Program Management System');
    $pdf->SetAuthor('System Administrator');
    $pdf->SetTitle('Reports - ' . ucfirst($tab));
    $pdf->SetSubject('Program Reports');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Add first page with correct size
    if ($tab === 'trainees') {
        $pdf->AddPage('L', [330, 216]);
    } else {
        $pdf->AddPage();
    }

    // ── Page header (logo + title block) ──────────────────────────────────────
    $logoPath = __DIR__ . '/../css/logo2.jpg';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 15, 25, 25, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $pdf->SetX(50);
    } else {
        $pdf->SetX(15);
    }

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'LIVELIHOOD ENROLLMENT AND MONITORING SYSTEM', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'OFFICIAL REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'I', 11);
    $pdf->Ln(5);

    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), ($tab === 'trainees' ? 315 : 282), $pdf->GetY());
    $pdf->Ln(8);

    // ── Report title ───────────────────────────────────────────────────────────
    $titles = [
        'outcomes'    => 'TRAINEE OUTCOMES REPORT',
        'trainees'    => 'TRAINEE MASTERLIST REPORT',
        'certificates'=> 'CERTIFICATE ELIGIBILITY REPORT',
        'attendance'  => ($subtab == 'trainer-attendance') ? 'TRAINER ATTENDANCE REPORT' : 'TRAINEE ATTENDANCE REPORT',
        'evaluation'  => 'TRAINER EVALUATION REPORT',
    ];
    $title = $titles[$tab] ?? strtoupper($tab) . ' REPORT';

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(5);

    // ── Period info ────────────────────────────────────────────────────────────
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, 'Period: ' . date('F d, Y', strtotime($dateFrom)) . ' to ' . date('F d, Y', strtotime($dateTo)), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'L');
    $pdf->Ln(6);

    // ── Tab content ────────────────────────────────────────────────────────────
    switch ($tab) {
        case 'outcomes':     generateOutcomesPDF($pdf, $conn, $dateFrom, $dateTo); break;
        case 'attendance':   generateAttendancePDF($pdf, $conn, $dateFrom, $dateTo, $subtab); break;
        case 'evaluation':   generateEvaluationPDF($pdf, $conn, $dateFrom, $dateTo); break;
        case 'trainees':     generateTraineesPDF($pdf, $conn, $dateFrom, $dateTo); break;
        case 'certificates': generateCertificatesEligibilityPDF($pdf, $conn, $dateFrom, $dateTo); break;
    }

    // ── Footer (not on trainees — it uses full page width differently) ─────────
    if ($tab !== 'trainees') {
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
    }

    // Page number
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    $pdf->Output('LEMS_Report_' . $tab . '_' . date('Ymd_His') . '.pdf', 'D');
}

// ── Helper ─────────────────────────────────────────────────────────────────────

function sanitizeFileName($fileName) {
    $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
    return preg_replace('/_+/', '_', $fileName);
}

// ── Individual Report Functions ────────────────────────────────────────────────

function generateOutcomesPDF($pdf, $conn, $dateFrom, $dateTo) {
    $result = fetchTraineeOutcomes($conn, $dateFrom, $dateTo);
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $header = ['Full Name', 'Program', 'Status', 'Certification', 'Assessment', 'Date Completed'];
        $w      = [60, 50, 30, 40, 30, 40];
        $align  = ['L', 'L', 'C', 'C', 'C', 'C'];

        for ($i = 0; $i < count($header); $i++)
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        foreach ($data as $row) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell($w[0], 8, htmlspecialchars($row['fullname']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[1], 8, htmlspecialchars($row['program_enrolled']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[2], 8, ucfirst($row['status']), 'LR', 0, 'C', $fill);
            $pdf->Cell($w[3], 8, $row['certification'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[4], 8, $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set', 'LR', 0, 'C', $fill);
            $pdf->Cell($w[5], 8, $row['completion_date'] ? $row['completion_date'] : 'In Progress', 'LR', 0, 'C', $fill);
            $pdf->Ln();
            $fill = !$fill;
        }
        $pdf->Cell(array_sum($w), 0, '', 'T');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Total Records: ' . count($data), 0, 1, 'R');
    } else {
        $pdf->SetFont('helvetica', '', 10); $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 10, 'No trainee outcome data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

function generateAttendancePDF($pdf, $conn, $dateFrom, $dateTo, $subtab) {
    if ($subtab == 'trainer-attendance') {
        $result = fetchTrainerAttendance($conn, $dateFrom, $dateTo);
        if ($result && $result->num_rows > 0) {
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;

            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            $header = ['Trainer Name', 'Program(s) Handled', 'Days Present', 'Last Attendance', 'Status'];
            $w      = [60, 70, 30, 50, 30];
            $align  = ['L', 'L', 'C', 'C', 'C'];
            for ($i = 0; $i < count($header); $i++)
                $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
            foreach ($data as $row) {
                $days = $row['days_present'];
                $s    = $days >= 20 ? 'Regular' : ($days >= 10 ? 'Irregular' : ($days > 0 ? 'Poor' : 'No Attendance'));
                $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                $pdf->Cell($w[0], 7, htmlspecialchars($row['name']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[1], 7, htmlspecialchars($row['programs_handled'] ?: 'No program assigned'), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[2], 7, $days . ' days', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[3], 7, $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[4], 7, $s, 'LR', 0, 'C', $fill);
                $pdf->Ln();
                $fill = !$fill;
            }
            $pdf->Cell(array_sum($w), 0, '', 'T');
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 8, 'Total Trainers: ' . count($data), 0, 1, 'R');
        } else {
            $pdf->SetFont('helvetica', '', 10); $pdf->SetTextColor(231, 76, 60);
            $pdf->Cell(0, 10, 'No trainer attendance data found for the selected period.', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    } else {
        $result = fetchTraineeAttendance($conn, $dateFrom, $dateTo);
        if ($result && $result->num_rows > 0) {
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;

            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(240, 240, 240);
            $header = ['Trainee Name', 'Program', 'Attendance %', 'Days Present', 'Last Attendance', 'Status'];
            $w      = [50, 50, 30, 30, 50, 30];
            $align  = ['L', 'L', 'C', 'C', 'C', 'C'];
            for ($i = 0; $i < count($header); $i++)
                $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 9);
            $fill = false;
            foreach ($data as $row) {
                $pct = $row['attendance_percentage'];
                $s   = $pct >= 80 ? 'Good' : ($pct >= 60 ? 'Fair' : 'Poor');
                $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                $pdf->Cell($w[0], 8, htmlspecialchars($row['name']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[1], 8, htmlspecialchars($row['program']), 'LR', 0, 'L', $fill);
                $pdf->Cell($w[2], 8, $pct . '%', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[3], 8, $row['days_present'] . ' days', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[4], 8, $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record', 'LR', 0, 'C', $fill);
                $pdf->Cell($w[5], 8, $s, 'LR', 0, 'C', $fill);
                $pdf->Ln();
                $fill = !$fill;
            }
            $pdf->Cell(array_sum($w), 0, '', 'T');
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 8, 'Total Trainees: ' . count($data), 0, 1, 'R');
        } else {
            $pdf->SetFont('helvetica', '', 10); $pdf->SetTextColor(231, 76, 60);
            $pdf->Cell(0, 10, 'No trainee attendance data found for the selected period.', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    }
}

function generateEvaluationPDF($pdf, $conn, $dateFrom, $dateTo) {
    $result = fetchTrainerEvaluations($conn, $dateFrom, $dateTo);
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $header = ['Trainer Name', 'Program', 'Start Date', 'Trainees Assigned', 'Slots Available', 'Utilization %'];
        $w      = [60, 60, 40, 40, 40, 40];
        $align  = ['L', 'L', 'C', 'C', 'C', 'C'];
        for ($i = 0; $i < count($header); $i++)
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, $align[$i], true);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        foreach ($data as $row) {
            $utilization = $row['slots_available'] > 0
                ? round(($row['trainees_assigned'] / $row['slots_available']) * 100, 1) : 0;
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell($w[0], 8, htmlspecialchars($row['trainer_name']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[1], 8, htmlspecialchars($row['program']), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[2], 8, $row['start_date'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[3], 8, $row['trainees_assigned'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[4], 8, $row['slots_available'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[5], 8, $utilization . '%', 'LR', 0, 'C', $fill);
            $pdf->Ln();
            $fill = !$fill;
        }
        $pdf->Cell(array_sum($w), 0, '', 'T');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Total Trainers: ' . count($data), 0, 1, 'R');
    } else {
        $pdf->SetFont('helvetica', '', 10); $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 10, 'No trainer evaluation data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

function generateCertificatesEligibilityPDF($pdf, $conn, $dateFrom, $dateTo) {
    $result = fetchAllCompletedTrainees($conn, $dateFrom, $dateTo);
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $eligible = $missing = $notPassed = $missingBoth = 0;
        foreach ($data as $row) {
            switch ($row['eligibility_status']) {
                case 'Eligible':              $eligible++;    break;
                case 'Missing Feedback':      $missing++;     break;
                case 'Assessment Not Passed': $notPassed++;   break;
                default:                      $missingBoth++; break;
            }
        }

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Certificate Eligibility Summary', 0, 1, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Total Completed Trainees: ' . count($data), 0, 1, 'L');
        $pdf->Cell(0, 6, 'Eligible for Certificates: ' . $eligible, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Missing Feedback: ' . $missing, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Assessment Not Passed: ' . $notPassed, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Missing Both Requirements: ' . $missingBoth, 0, 1, 'L');
        $pdf->Ln(10);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $header = ['#', 'Trainee Name', 'Program', 'Completion Date', 'Assessment', 'Feedback', 'Eligibility Status'];
        $w      = [10, 50, 50, 35, 30, 25, 40];
        $align  = ['C', 'L', 'L', 'C', 'C', 'C', 'L'];
        for ($i = 0; $i < count($header); $i++)
            $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 8);
        $fill = false; $counter = 1;
        foreach ($data as $row) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell($w[0], 6, $counter++, 'LR', 0, 'C', $fill);
            $pdf->Cell($w[1], 6, htmlspecialchars(substr($row['fullname'], 0, 25) . (strlen($row['fullname']) > 25 ? '...' : '')), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[2], 6, htmlspecialchars(substr($row['program_name'], 0, 25) . (strlen($row['program_name']) > 25 ? '...' : '')), 'LR', 0, 'L', $fill);
            $pdf->Cell($w[3], 6, $row['completion_date'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[4], 6, $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set', 'LR', 0, 'C', $fill);
            $pdf->Cell($w[5], 6, $row['has_feedback'], 'LR', 0, 'C', $fill);
            $pdf->Cell($w[6], 6, $row['eligibility_status'], 'LR', 0, 'L', $fill);
            $pdf->Ln();
            $fill = !$fill;

            if ($pdf->GetY() > 180 && $counter <= count($data)) {
                $pdf->AddPage('L');
                $pdf->SetFont('helvetica', 'B', 9); $pdf->SetFillColor(240, 240, 240);
                for ($i = 0; $i < count($header); $i++)
                    $pdf->Cell($w[$i], 8, $header[$i], 1, 0, $align[$i], true);
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 8);
            }
        }
        $pdf->Cell(array_sum($w), 0, '', 'T');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Eligibility Requirements:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, '✓ Enrollment status: Completed', 0, 1, 'L');
        $pdf->Cell(0, 5, '✓ Assessment: Passed', 0, 1, 'L');
        $pdf->Cell(0, 5, '✓ Feedback: Submitted', 0, 1, 'L');
    } else {
        $pdf->SetFont('helvetica', '', 12); $pdf->SetTextColor(231, 76, 60);
        $pdf->Cell(0, 20, 'No certificate eligibility data found for the selected period.', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
}

// ── KEY FIX: generateTraineesPDF — no AddPage(), continues on existing page ───


function generateTraineesPDF($pdf, $conn, $dateFrom, $dateTo) {
    $result = fetchTraineesDetailed($conn, $dateFrom, $dateTo);
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;

        $pageWidth    = 297;
        $pageHeight   = 210;
        $marginTop    = 15;
        $marginSide   = 15;
        $marginBottom = 15;
        $avail        = $pageWidth - ($marginSide * 2);
        $lineH        = 2.8;

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins($marginSide, $marginTop, $marginSide);

        $w = [
            $avail * 0.045655,  // Full Name
            $avail * 0.032439,  // Street
            $avail * 0.041249,  // Barangay
            $avail * 0.058871,  // Municipality
            $avail * 0.023628,  // City
            $avail * 0.032439,  // Gender
            $avail * 0.067681,  // Gender Specify
            $avail * 0.058871,  // Civil Status
            $avail * 0.019223,  // Age
            $avail * 0.054465,  // Contact No.
            $avail * 0.028034,  // Email
            $avail * 0.05006,  // Employment
            $avail * 0.045655,  // Education
            $avail * 0.058871,  // Edu. Specify
            $avail * 0.045655,  // Trainings
            $avail * 0.063276,  // Failure Notes
            $avail * 0.036844,  // Toolkit
            $avail * 0.076492,  // Special Category
            $avail * 0.045655,  // NC Holder
            $avail * 0.036844,  // Program
            $avail * 0.032439,  // Status
            $avail * 0.045655,  // Reg. Date
        ];

        $headers = [
            'Full Name', 'Street', 'Barangay', 'Municipality', 'City',
            'Gender', 'Gender Specify', 'Civil Status', 'Age', 'Contact No.', 'Email',
            'Employment', 'Education', 'Edu. Specify', 'Trainings', 'Failure Notes',
            'Toolkit', 'Special Category', 'NC Holder', 'Program', 'Status', 'Reg. Date',
        ];

        $align = array_fill(0, count($headers), 'L');
        $align[8]  = 'C';
        $align[9]  = 'C';
        $align[20] = 'C';
        $align[21] = 'C';
        $drawCell = function($x, $y, $cw, $rh, $txt, $ha, $bold, $r, $g, $b) use ($pdf, $lineH) {
            $pdf->SetFillColor($r, $g, $b);
            $pdf->SetDrawColor(150, 150, 150);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $y, $cw, $rh, "DF");
            $pdf->SetFont("helvetica", $bold ? "B" : "", $bold ? 3.5 : 3.2);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x + 0.4, $y);
            $pdf->MultiCell($cw - 0.6, $lineH, $txt, 0, $ha, false, 0);
        };

        $drawHeaders = function() use ($pdf, $w, $headers, $align, $lineH, $drawCell, $marginSide) {
            $startY = $pdf->GetY();
            $curX   = $marginSide;
            foreach ($headers as $i => $lbl) {
                $drawCell($curX, $startY, $w[$i], $lineH, $lbl, $align[$i], true, 220, 220, 220);
                $curX += $w[$i];
            }
            $pdf->SetXY($marginSide, $startY + $lineH);
        };

        $drawHeaders();

        $clean = function($v) {
            $v = $v ?? "";
            $v = str_replace([chr(13), chr(10)], " ", trim($v));
            return ($v === "1") ? "" : $v;
        };
        $fill = false;
        foreach ($data as $row) {
            $specialCats = "";
            if (!empty($row["special_categories"])) {
                $cats = json_decode($row["special_categories"], true);
                if (is_array($cats)) $specialCats = implode(", ", $cats);
            }
            $ncHolder = "";
            if (!empty($row["nc_holder"])) {
                $nc = json_decode($row["nc_holder"], true);
                if (is_array($nc)) $ncHolder = implode(", ", $nc);
            }
            $cells = [
                $clean($row["fullname"]),
                $clean($row["house_street"]),
                $clean($row["barangay"]),
                $clean($row["municipality"]),
                $clean($row["city"]),
                $clean($row["gender"]),
                $clean($row["gender_specify"] !== "1" ? ($row["gender_specify"] ?? "") : ""),
                $clean($row["civil_status"]),
                (string)($row["age"] ?: ""),
                $clean($row["contact_number"]),
                $clean($row["email"]),
                $clean($row["employment_status"]),
                $clean($row["education"]),
                $clean($row["education_specify"] !== "1" ? ($row["education_specify"] ?? "") : ""),
                $clean($row["trainings_attended"]),
                $clean($row["failure_notes_copy"]),
                $clean($row["toolkit_received"]),
                $specialCats,
                $ncHolder,
                $clean($row["enrolled_program"]),
                $clean($row["enrollment_status"]),
                ($row["registration_date"] ? substr($row["registration_date"], 5) : ""),
            ];

            $maxLines = 1;
            foreach ($cells as $i => $val) {
                $n = $pdf->getNumLines($val, $w[$i]);
                if ($n > $maxLines) $maxLines = $n;
            }
            $rowH   = $lineH * $maxLines;
            $startY = $pdf->GetY();

            if (($startY + $rowH) > ($pageHeight - $marginBottom)) {
                $pdf->AddPage("L", [297, 210]);
                $pdf->SetAutoPageBreak(false);
                $pdf->SetMargins($marginSide, $marginTop, $marginSide);
                $drawHeaders();
                $startY = $pdf->GetY();
                $fill   = false;
            }

            $bg   = $fill ? [245, 245, 245] : [255, 255, 255];
            $curX = $marginSide;
            foreach ($cells as $i => $val) {
                $drawCell($curX, $startY, $w[$i], $rowH, $val, $align[$i], false, $bg[0], $bg[1], $bg[2]);
                $curX += $w[$i];
            }
            $pdf->SetXY($marginSide, $startY + $rowH);
            $fill = !$fill;
        }

        $pdf->Ln(3);
        $pdf->SetFont("helvetica", "B", 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 4, "Total Records: " . count($data) . " trainees", 0, 1, "R");

    } else {
        $pdf->SetFont("helvetica", "", 10);
        $pdf->SetTextColor(200, 50, 50);
        $pdf->Cell(0, 20, "No trainee data found for the selected period.", 0, 1, "C");
        $pdf->SetTextColor(0, 0, 0);
    }
}

// ── Handle export actions ──────────────────────────────────────────────────────

if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $tab    = isset($_GET['tab'])    ? $_GET['tab']    : 'outcomes';
    $subtab = isset($_GET['subtab']) ? $_GET['subtab'] : '';

    if ($tab == 'certificates' && !isset($_GET['subtab'])) {
        generateBulkCertificates($conn, $dateFrom, $dateTo);
    } else {
        generatePDFReport($conn, $tab, $subtab, $dateFrom, $dateTo);
    }
    exit();
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
        .container { max-width: 1400px; margin: 0 auto; padding: 15px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #eee; }
        .section-header h2 { color: var(--primary-color); font-size: 1.4rem; display: flex; align-items: center; gap: 10px; }
        .date-filter { background: white; padding: 15px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .date-filter label { font-weight: 600; color: var(--primary-color); font-size: 0.9rem; }
        .date-filter input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; height: 36px; }
        .date-filter button { background-color: var(--secondary-color); color: white; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.9rem; height: 36px; transition: all var(--transition-speed-fast); }
        .date-filter button:hover { background-color: #2980b9; transform: translateY(-1px); box-shadow: 0 3px 6px rgba(41,128,185,0.2); }
        .main-tabs { display: flex; background: white; border-radius: 6px 6px 0 0; overflow: hidden; }
        .main-tab { padding: 14px 20px; background: #f8f9fa; border: none; font-size: 0.95rem; font-weight: 600; color: #555; cursor: pointer; transition: all var(--transition-speed-fast); flex: 1; text-align: center; border-bottom: 2px solid transparent; }
        .main-tab:hover { background: #e9ecef; transform: translateY(-1px); }
        .main-tab.active { background: white; color: var(--primary-color); border-bottom: 2px solid var(--secondary-color); }
        .tab-content { display: none; background: white; padding: 20px; border-radius: 0 0 6px 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease; }
        .sub-tabs { display: flex; background: #f8f9fa; border-radius: 6px 6px 0 0; overflow: hidden; max-width: 350px; }
        .sub-tab { padding: 12px 18px; background: #f8f9fa; border: none; font-size: 0.9rem; font-weight: 600; color: #555; cursor: pointer; transition: all var(--transition-speed-fast); flex: 1; text-align: center; border-bottom: 2px solid transparent; }
        .sub-tab:hover { background: #e9ecef; }
        .sub-tab.active { background: white; color: var(--primary-color); border-bottom: 2px solid var(--secondary-color); }
        .sub-tab-content { display: none; }
        .sub-tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .export-buttons { display: flex; gap: 8px; }
        .export-btn { display: flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: all var(--transition-speed-fast); border: none; font-size: 0.85rem; }
        .export-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .export-pdf { background-color: var(--danger-color); color: white; }
        .export-pdf:hover { background-color: #c0392b; }
        .export-certificate { background-color: var(--success-color); color: white; }
        .export-certificate:hover { background-color: #219653; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.85rem; }
        th { background-color: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; color: var(--primary-color); border-bottom: 2px solid #dee2e6; font-size: 0.9rem; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: top; font-size: 0.85rem; }
        tr:hover { background-color: #f8f9fa; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 16px; font-size: 0.8rem; font-weight: 600; }
        .status-complete { background-color: #d4edda; color: #155724; }
        .status-ongoing  { background-color: #fff3cd; color: #856404; }
        .status-dropped  { background-color: #f8d7da; color: #721c24; }
        .status-pending  { background-color: #e2e3e5; color: #383d41; }
        .attendance-high   { color: var(--success-color); font-weight: 600; }
        .attendance-medium { color: var(--warning-color); font-weight: 600; }
        .attendance-low    { color: var(--danger-color);  font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; justify-items: center; }
        .stat-card { background: white; padding: 20px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); text-align: center; border: 1px solid #eee; width: 100%; max-width: 240px; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.08); }
        .stat-card i { font-size: 2rem; margin-bottom: 12px; }
        .stat-card h3 { font-size: 2rem; margin-bottom: 8px; color: var(--primary-color); }
        .stat-card p  { color: #666; font-weight: 600; font-size: 0.9rem; }
        .stat-icon-1 { color: #3498db; } .stat-icon-2 { color: #9b59b6; } .stat-icon-3 { color: #2ecc71; }
        .certificate-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .certificate-stat-card { background: white; padding: 20px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); text-align: center; border: 1px solid #eee; }
        .certificate-stat-card i { font-size: 2rem; margin-bottom: 12px; color: #8B0000; }
        .certificate-stat-card h3 { font-size: 1.8rem; margin-bottom: 8px; color: var(--primary-color); }
        .certificate-stat-card p { color: #666; font-weight: 600; font-size: 0.9rem; }
        .no-data { text-align: center; padding: 30px; color: #777; font-size: 0.95rem; }
        .no-data i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; display: block; }
        .error-message { background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #f5c6cb; font-size: 0.9rem; }
        .success-message { background-color: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c3e6cb; font-size: 0.9rem; }
        .summary-section { margin-top: 15px; padding: 12px; background-color: #f8f9fa; border-radius: 4px; border-left: 3px solid var(--info-color); }
        .summary-section h3 { color: var(--primary-color); margin-bottom: 8px; font-size: 1.1rem; }
        .eligibility-eligible     { background-color: #d4edda; color: #155724; }
        .eligibility-missing      { background-color: #fff3cd; color: #856404; }
        .eligibility-not-passed   { background-color: #f8d7da; color: #721c24; }
        .eligibility-missing-both { background-color: #e2e3e5; color: #383d41; }
        .certificate-container { width: 210mm; height: 297mm; background: #f5f0e8; position: relative; box-sizing: border-box; margin: 0 auto; }
        .decorative-border { position: absolute; top:0;left:0;right:0;bottom:0; border: 35px solid transparent; border-image: repeating-linear-gradient(45deg,#2d8b8e 0px,#2d8b8e 10px,#d4a574 10px,#d4a574 20px,#2d8b8e 20px,#2d8b8e 30px,#f5f0e8 30px,#f5f0e8 40px) 35; pointer-events: none; z-index: 2; }
        .inner-border { position: absolute; top:20px;left:20px;right:20px;bottom:20px; border: 15px solid; border-image: repeating-linear-gradient(0deg,#2d8b8e 0px,#2d8b8e 3px,#d4a574 3px,#d4a574 6px,#2d8b8e 6px,#2d8b8e 9px,#f5f0e8 9px,#f5f0e8 12px) 15; pointer-events: none; z-index: 2; }
        .certificate-content { position: absolute; width:100%;height:100%;top:0;left:0;padding:50px 70px;z-index:1;box-sizing:border-box; }
        .logos-row { display:flex;justify-content:center;align-items:center;gap:30px;margin:15px 0 20px 0; }
        .logo-item { width:80px;height:80px; } .logo-item img { width:100%;height:100%;object-fit:contain; }
        .header-top { text-align:center;font-size:16px;font-weight:bold;color:black;margin:15px 0 5px 0;text-transform:uppercase;letter-spacing:0.5px; }
        .cooperation { text-align:center;font-size:14px;color:black;margin:5px 0; }
        .tesda { text-align:center;font-size:14px;font-weight:bold;color:black;margin:5px 0;text-transform:uppercase; }
        .training-center { text-align:center;font-size:20px;font-weight:bold;color:#2d8b8e;margin:8px 0 35px 0;text-transform:uppercase;letter-spacing:1px; }
        .certificate-title { text-align:center;margin:0 0 35px 0; }
        .certificate-title h1 { font-size:48px;margin:0;color:#2d8b8e;font-weight:bold;text-transform:uppercase;letter-spacing:6px;line-height:1; }
        .awarded-to { text-align:center;margin:0 0 20px 0; } .awarded-to p { font-size:18px;margin:0;color:black; }
        .trainee-name-container { text-align:center;margin:0 0 25px 0; }
        .trainee-name { font-size:48px;color:black;font-weight:bold;text-transform:uppercase;letter-spacing:2px;line-height:1.1; }
        .completion-text { text-align:center;margin:0 0 20px 0; } .completion-text p { font-size:16px;margin:0;color:black; }
        .training-name-container { text-align:center;margin:0 0 30px 0; }
        .training-name { font-size:36px;color:black;font-weight:bold;text-transform:uppercase;letter-spacing:2px;line-height:1.1; }
        .given-date { text-align:center;margin:0 0 40px 0; } .given-date p { font-size:16px;margin:0;color:black;line-height:1.4; }
        .signatures { position:absolute;bottom:60px;left:70px;right:70px; }
        .signatures-row { display:flex;justify-content:space-between;align-items:flex-end; }
        .left-signatures { display:flex;flex-direction:column;gap:35px;flex:1; }
        .signature-block { text-align:center; }
        .signature-line { border-bottom:2px solid black;width:280px;margin:0 auto 5px auto; }
        .signature-name { font-size:15px;font-weight:bold;color:black;text-transform:uppercase; }
        .signature-title { font-size:14px;color:black;margin:3px 0 0 0; }
        .photo-signature-section { width:180px;display:flex;flex-direction:column;align-items:center;margin-left:40px; }
        .photo-box { width:150px;height:180px;border:2px solid #888;background:white;display:flex;align-items:center;justify-content:center;margin-bottom:10px; }
        .photo-signature-line { border-bottom:2px solid black;width:150px;margin:5px 0; }
        .photo-signature-label { font-size:12px;color:black;text-align:center; }
        .watermark { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0.06;z-index:0;pointer-events:none; }
        .watermark img { width:500px;height:500px;object-fit:contain; }
        .certificate-name { color:#00008B;font-size:2.2rem;font-weight:bold;margin:20px 0;text-transform:uppercase;letter-spacing:1px; }
        .certificate-actions { display:flex;gap:10px;justify-content:center;margin-top:20px; }
        * { box-sizing: border-box; }

        @media (max-width: 768px) {
            .main-tabs, .sub-tabs { flex-direction: column; }
            .sub-tabs { max-width: 100%; }
            .export-buttons { flex-direction: column; width: 100%; }
            .export-btn { width: 100%; justify-content: center; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            table { display: block; overflow-x: auto; font-size: 0.8rem; }
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
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] == 'no_certificates'): ?>
        <div class="error-message"><strong>No Certificates Available:</strong> No trainees are eligible for certificates in the selected date range.</div>
    <?php endif; ?>
    <?php if (isset($_GET['success']) && $_GET['success'] == 'certificates_generated'): ?>
        <div class="success-message"><strong>Success!</strong> Certificates have been generated successfully.</div>
    <?php endif; ?>

    <form method="GET" class="date-filter">
        <div><label for="date_from">From:</label><input type="date" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>"></div>
        <div><label for="date_to">To:</label><input type="date" id="date_to" name="date_to" value="<?php echo $dateTo; ?>"></div>
        <div><button type="submit">Apply Filter</button></div>
        <div style="margin-left: auto;"><a href="reports_monitoring.php" class="export-btn" style="background-color:#95a5a6;text-decoration:none;">Reset Filters</a></div>
    </form>

    <div class="main-tabs">
        <button class="main-tab active" data-tab="outcomes">Trainee Outcomes</button>
        <button class="main-tab" data-tab="trainees">Trainee Masterlist</button>
        <button class="main-tab" data-tab="certificates">Certificate Generation</button>
        <button class="main-tab" data-tab="attendance">Attendance Tracking</button>
        <button class="main-tab" data-tab="evaluation">Trainer Evaluation</button>
    </div>

    <!-- ── Trainee Outcomes ── -->
    <div id="outcomes" class="tab-content active">
        <div class="section-header">
            <h2><i class="fas fa-user-graduate"></i> Trainee Outcomes Tracking</h2>
            <div class="export-buttons">
                <button class="export-btn export-pdf" onclick="exportReport('outcomes','pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            </div>
        </div>
        <?php if ($traineeOutcomesData && $traineeOutcomesData->num_rows > 0): ?>
        <table>
            <thead><tr><th>Full Name</th><th>Program Enrolled</th><th>Status</th><th>Certification</th><th>Assessment</th><th>Date Completed</th></tr></thead>
            <tbody>
            <?php while ($row = $traineeOutcomesData->fetch_assoc()): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['program_enrolled']); ?></td>
                <td><span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo $row['status']; ?></span></td>
                <td><span class="status-badge <?php echo $row['certification']=='Certified'?'status-complete':($row['certification']=='Pending'?'status-pending':'status-dropped'); ?>"><?php echo $row['certification']; ?></span></td>
                <td><span class="status-badge <?php echo $row['assessment']=='passed'?'status-complete':'status-pending'; ?>"><?php echo $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set'; ?></span></td>
                <td><?php echo $row['completion_date'] ?: 'In Progress'; ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?><div class="no-data"><i class="fas fa-user-graduate"></i><p>No trainee outcome data found for the selected date range.</p></div><?php endif; ?>
    </div>

    <!-- ── Trainee Masterlist ── -->
    <div id="trainees" class="tab-content">
        <div class="section-header">
            <h2><i class="fas fa-users"></i> Trainee Masterlist</h2>
            <div class="export-buttons">
                <button class="export-btn export-pdf" onclick="exportReport('trainees','pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            </div>
        </div>
        <?php
        if ($traineesDetailedData && $traineesDetailedData->num_rows > 0):
            $maleCount = $femaleCount = 0;
            $data = [];
            while ($row = $traineesDetailedData->fetch_assoc()) {
                $data[] = $row;
                if ($row['gender'] == 'Male') $maleCount++;
                if ($row['gender'] == 'Female') $femaleCount++;
            }
            $totalCount = count($data);
        ?>
        <div class="stats-grid">
            <div class="stat-card"><i class="fas fa-male stat-icon-1"></i><h3><?php echo $maleCount; ?></h3><p>Total Male</p></div>
            <div class="stat-card"><i class="fas fa-female stat-icon-2"></i><h3><?php echo $femaleCount; ?></h3><p>Total Female</p></div>
            <div class="stat-card"><i class="fas fa-users stat-icon-3"></i><h3><?php echo $totalCount; ?></h3><p>Grand Total</p></div>
        </div>
        <div style="width:100%;overflow-x:auto;margin-top:20px;">
        <table style="min-width:2500px;font-size:0.75rem;">
            <thead><tr>
                <th>FULL NAME</th>
                <th>HOUSE/STREET</th><th>BARANGAY</th><th>MUNICIPALITY</th><th>CITY</th>
                <th>GENDER</th><th>GENDER SPECIFY</th><th>CIVIL STATUS</th><th>AGE</th>
                <th>CONTACT</th><th>EMAIL</th><th>EMPLOYMENT</th><th>EDUCATION</th>
                <th>EDUC SPECIFY</th><th>TRAININGS</th><th>TOOLKIT</th>
                <th>APPLICANT TYPE</th><th>NC HOLDER</th><th>ENROLLED PROGRAM</th>
                <th>STATUS</th><th>REG DATE</th>
            </tr></thead>
            <tbody>
            <?php foreach ($data as $row):
                $ncHolder = '';
                if (!empty($row['nc_holder'])) { $nc = json_decode($row['nc_holder'], true); if (is_array($nc)) $ncHolder = implode(', ', $nc); }
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['house_street']); ?></td>
                <td><?php echo htmlspecialchars($row['barangay']); ?></td>
                <td><?php echo htmlspecialchars($row['municipality']); ?></td>
                <td><?php echo htmlspecialchars($row['city']); ?></td>
                <td><?php echo htmlspecialchars($row['gender']); ?></td>
                <td><?php echo htmlspecialchars($row['gender_specify'] != '1' ? $row['gender_specify'] : ''); ?></td>
                <td><?php echo htmlspecialchars($row['civil_status']); ?></td>
                <td><?php echo $row['age']; ?></td>
                <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td><span class="status-badge <?php echo $row['employment_status']=='Employed'?'status-complete':($row['employment_status']=='Self-employed'?'status-ongoing':'status-pending'); ?>"><?php echo htmlspecialchars($row['employment_status']); ?></span></td>
                <td><?php echo htmlspecialchars($row['education']); ?></td>
                <td><?php echo htmlspecialchars($row['education_specify'] != '1' ? $row['education_specify'] : ''); ?></td>
                <td><?php echo htmlspecialchars(substr($row['trainings_attended'] ?? '', 0, 30)) . (strlen($row['trainings_attended'] ?? '') > 30 ? '...' : ''); ?></td>
                <td><?php echo htmlspecialchars($row['toolkit_received']); ?></td>
                <td><?php $types = json_decode($row['applicant_type'], true); echo htmlspecialchars(implode(', ', $types ?? [])); ?></td>
                <td><?php echo htmlspecialchars($ncHolder); ?></td>
                <td><?php if ($row['enrolled_program']): ?><span class="status-badge status-complete"><?php echo htmlspecialchars($row['enrolled_program']); ?></span><?php else: ?><span class="status-badge status-pending">Not enrolled</span><?php endif; ?></td>
                <td><span class="status-badge <?php echo $row['enrollment_status']=='completed'?'status-complete':($row['enrollment_status']=='ongoing'?'status-ongoing':'status-pending'); ?>"><?php echo $row['enrollment_status'] ? ucfirst($row['enrollment_status']) : 'Not Enrolled'; ?></span></td>
                <td><?php echo $row['registration_date']; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="summary-section">
            <h3>Summary Report:</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                <p><strong>Total Male:</strong> <?php echo $maleCount; ?></p>
                <p><strong>Total Female:</strong> <?php echo $femaleCount; ?></p>
                <p><strong>Grand Total:</strong> <?php echo $totalCount; ?></p>
                <p><strong>Period:</strong> <?php echo date('F d, Y', strtotime($dateFrom)); ?> to <?php echo date('F d, Y', strtotime($dateTo)); ?></p>
            </div>
        </div>
        <?php else: ?><div class="no-data"><i class="fas fa-users"></i><p>No trainee data found for the selected date range.</p></div><?php endif; ?>
    </div>

    <!-- ── Certificates ── -->
    <div id="certificates" class="tab-content">
        <div class="section-header">
            <h2><i class="fas fa-certificate"></i> Certificate of Completion Generation</h2>
            <div class="export-buttons">
                <?php if ($certificateData && $certificateData->num_rows > 0): ?>
                <button class="export-btn export-certificate" onclick="generateBulkCertificates(event, this)"><i class="fas fa-download"></i> Generate All Certificates</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ($certificateData && $certificateData->num_rows > 0):
            $programStats = []; $eligibleData = [];
            $certificateData->data_seek(0);
            while ($row = $certificateData->fetch_assoc()) {
                $eligibleData[] = $row;
                $pn = $row['program_name'];
                if (!isset($programStats[$pn])) $programStats[$pn] = 0;
                $programStats[$pn]++;
            }
            $totalCertificates = count($eligibleData);
            $mostCommonProgram = ''; $maxCount = 0;
            foreach ($programStats as $p => $c) { if ($c > $maxCount) { $maxCount = $c; $mostCommonProgram = $p; } }
        ?>
        <div class="certificate-stats">
            <div class="certificate-stat-card"><i class="fas fa-certificate"></i><h3><?php echo $totalCertificates; ?></h3><p>Total Eligible Certificates</p></div>
            <div class="certificate-stat-card"><i class="fas fa-graduation-cap"></i><h3><?php echo count($programStats); ?></h3><p>Programs Completed</p></div>
            <div class="certificate-stat-card"><i class="fas fa-chart-bar"></i><h3><?php echo $maxCount; ?></h3><p>Most Completed: <?php echo substr($mostCommonProgram, 0, 20) . (strlen($mostCommonProgram) > 20 ? '...' : ''); ?></p></div>
        </div>
        <div class="certificate-container" style="margin-bottom:30px;">
            <div class="watermark"><img src="/trainee/SLOGO.jpg" alt="" onerror="this.style.display='none';"></div>
            <div class="decorative-border"></div>
            <div class="inner-border"></div>
            <div class="certificate-content">
                <div class="logos-row">
                    <div class="logo-item"><img src="/trainee/SMBLOGO.jpg" alt="" onerror="this.style.display='none';"></div>
                    <div class="logo-item"><img src="/trainee/SLOGO.jpg" alt="" onerror="this.style.display='none';"></div>
                    <div class="logo-item"><img src="/trainee/TESDALOGO.png" alt="" onerror="this.style.display='none';"></div>
                </div>
                <div class="header-top">MUNICIPALITY OF SANTA MARIA, BULACAN</div>
                <div class="cooperation">IN COOPERATION WITH</div>
                <div class="tesda">TECHNICAL EDUCATION & SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN</div>
                <div class="training-center">SANTA MARIA LIVELIHOOD TRAINING CENTER</div>
                <div class="certificate-title"><h1>CERTIFICATE OF TRAINING</h1></div>
                <div class="awarded-to"><p>is awarded to</p></div>
                <div class="trainee-name-container"><h3 class="certificate-name">[TRAINEE NAME]</h3></div>
                <div class="completion-text"><p>For having satisfactorily completed the</p></div>
                <div class="training-name-container"><div class="training-name">[PROGRAM NAME]</div></div>
                <div class="given-date"><p>Given this [DATE] at Santa Maria Livelihood Training and</p><p>Employment Center, Santa Maria, Bulacan.</p></div>
                <div class="signatures">
                    <div class="signatures-row">
                        <div class="left-signatures">
                            <div class="signature-block"><div class="signature-line"></div><div class="signature-name">ZENAIDA S. MANINGAS</div><div class="signature-title">PESO Manager</div></div>
                            <div class="signature-block"><div class="signature-line"></div><div class="signature-name">ROBERTO B. PEREZ</div><div class="signature-title">Municipal Vice Mayor</div></div>
                            <div class="signature-block"><div class="signature-line"></div><div class="signature-name">BARTOLOME R. RAMOS</div><div class="signature-title">Municipal Mayor</div></div>
                        </div>
                        <div class="photo-signature-section"><div class="photo-box"></div><div class="photo-signature-line"></div><div class="photo-signature-label">Signature</div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="certificate-actions">
            <button class="export-btn export-certificate" onclick="generateBulkCertificates(event,this)" style="padding:12px 24px;font-size:1rem;"><i class="fas fa-download"></i> Generate All Certificates (<?php echo $totalCertificates; ?> files)</button>
        </div>
        <?php endif; ?>
        <h3 style="margin-top:30px;color:var(--primary-color);">Trainees Certificate Status</h3>
        <?php if ($allCompletedData && $allCompletedData->num_rows > 0): ?>
        <table>
            <thead><tr><th>#</th><th>Trainee Name</th><th>Program</th><th>Completion Date</th><th>Assessment</th><th>Feedback</th><th>Status</th></tr></thead>
            <tbody>
            <?php $counter = 1; $allCompletedData->data_seek(0); while ($row = $allCompletedData->fetch_assoc()): ?>
            <tr>
                <td><?php echo $counter++; ?></td>
                <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['program_name']); ?></td>
                <td><?php echo $row['completion_date']; ?></td>
                <td><span class="status-badge <?php echo $row['assessment']=='passed'?'status-complete':'status-dropped'; ?>"><?php echo $row['assessment'] ? ucfirst($row['assessment']) : 'Not Set'; ?></span></td>
                <td><span class="status-badge <?php echo $row['has_feedback']=='Yes'?'status-complete':'status-pending'; ?>"><?php echo $row['has_feedback']; ?></span></td>
                <td><?php $sc = ['Eligible'=>'eligibility-eligible','Missing Feedback'=>'eligibility-missing','Assessment Not Passed'=>'eligibility-not-passed']; ?>
                    <span class="status-badge <?php echo $sc[$row['eligibility_status']] ?? 'eligibility-missing-both'; ?>"><?php echo $row['eligibility_status']; ?></span></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <div class="summary-section">
            <h3>Certificate Eligibility Summary:</h3>
            <?php
            $allCompletedData->data_seek(0);
            $el=$mf=$np=$mb=0;
            while ($row = $allCompletedData->fetch_assoc()) {
                switch($row['eligibility_status']) { case 'Eligible': $el++; break; case 'Missing Feedback': $mf++; break; case 'Assessment Not Passed': $np++; break; default: $mb++; }
            }
            ?>
            <p><strong>Total Completed:</strong> <?php echo $el+$mf+$np+$mb; ?></p>
            <p><strong>Eligible:</strong> <?php echo $el; ?></p>
            <p><strong>Missing Feedback:</strong> <?php echo $mf; ?></p>
            <p><strong>Assessment Not Passed:</strong> <?php echo $np; ?></p>
            <p><strong>Missing Both:</strong> <?php echo $mb; ?></p>
        </div>
        <?php else: ?><div class="no-data"><i class="fas fa-certificate"></i><p>No trainees have completed their programs in the selected date range.</p></div><?php endif; ?>
    </div>

    <!-- ── Attendance ── -->
    <div id="attendance" class="tab-content">
        <div class="section-header">
            <h2><i class="fas fa-calendar-check"></i> Attendance Tracking</h2>
            <div class="export-buttons">
                <button class="export-btn export-pdf" onclick="exportReport('attendance','pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            </div>
        </div>
        <div style="margin-bottom:30px;">
            <div class="sub-tabs">
                <button class="sub-tab active" data-subtab="trainer-attendance"><i class="fas fa-chalkboard-teacher"></i> Trainer Attendance</button>
                <button class="sub-tab" data-subtab="trainee-attendance"><i class="fas fa-user-graduate"></i> Trainee Attendance</button>
            </div>
            <div id="trainer-attendance" class="sub-tab-content active">
                <div style="margin-top:25px;">
                    <?php if ($trainerAttendanceData && $trainerAttendanceData->num_rows > 0): ?>
                    <table>
                        <thead><tr><th>Trainer Name</th><th>Program(s) Handled</th><th>Days Present</th><th>Last Attendance</th><th>Attendance Status</th></tr></thead>
                        <tbody>
                        <?php while ($row = $trainerAttendanceData->fetch_assoc()):
                            $d=$row['days_present']; $s=$d>=20?'Regular':($d>=10?'Irregular':($d>0?'Poor':'No Attendance')); $sc=$d>=20?'status-complete':($d>=10?'status-ongoing':($d>0?'status-dropped':'status-pending'));
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td><?php echo !empty($row['programs_handled']) ? htmlspecialchars($row['programs_handled']) : '<span class="status-badge status-pending">No program assigned</span>'; ?></td>
                            <td><?php echo $d; ?> days</td>
                            <td><?php echo $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record'; ?></td>
                            <td><span class="status-badge <?php echo $sc; ?>"><?php echo $s; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?><div class="no-data"><i class="fas fa-chalkboard-teacher"></i><p>No trainer attendance data found.</p></div><?php endif; ?>
                </div>
            </div>
            <div id="trainee-attendance" class="sub-tab-content">
                <div style="margin-top:25px;">
                    <?php if ($traineeAttendanceData && $traineeAttendanceData->num_rows > 0): ?>
                    <table>
                        <thead><tr><th>Trainee Name</th><th>Program</th><th>Attendance %</th><th>Days Present</th><th>Last Attendance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php while ($row = $traineeAttendanceData->fetch_assoc()):
                            $pct=$row['attendance_percentage']; $s=$pct>=80?'Good':($pct>=60?'Fair':'Poor'); $sc=$pct>=80?'status-complete':($pct>=60?'status-ongoing':'status-dropped');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['program']); ?></td>
                            <td class="<?php echo $pct>=80?'attendance-high':($pct>=60?'attendance-medium':'attendance-low'); ?>"><?php echo $pct; ?>%</td>
                            <td><?php echo $row['days_present']; ?> days</td>
                            <td><?php echo $row['last_attendance'] ? date('M d, Y', strtotime($row['last_attendance'])) : 'No record'; ?></td>
                            <td><span class="status-badge <?php echo $sc; ?>"><?php echo $s; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?><div class="no-data"><i class="fas fa-user-graduate"></i><p>No trainee attendance data found.</p></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Evaluation ── -->
    <div id="evaluation" class="tab-content">
        <div class="section-header">
            <h2><i class="fas fa-clipboard-check"></i> Trainer Evaluation & Performance</h2>
            <div class="export-buttons">
                <button class="export-btn export-pdf" onclick="exportReport('evaluation','pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            </div>
        </div>
        <?php if ($trainerEvaluationData && $trainerEvaluationData->num_rows > 0): ?>
        <table>
            <thead><tr><th>Trainer Name</th><th>Program</th><th>Start Date</th><th>Trainees Assigned</th><th>Slots Available</th><th>Utilization Rate</th></tr></thead>
            <tbody>
            <?php while ($row = $trainerEvaluationData->fetch_assoc()):
                $util = $row['slots_available'] > 0 ? round(($row['trainees_assigned']/$row['slots_available'])*100,1) : 0;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['trainer_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['program']); ?></td>
                <td><?php echo $row['start_date']; ?></td>
                <td><?php echo $row['trainees_assigned']; ?></td>
                <td><?php echo $row['slots_available']; ?></td>
                <td class="<?php echo $util>=80?'attendance-high':($util>=60?'attendance-medium':'attendance-low'); ?>"><?php echo $util; ?>%</td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?><div class="no-data"><i class="fas fa-clipboard-check"></i><p>No trainer evaluation data found for the selected date range.</p></div><?php endif; ?>
    </div>

</div><!-- /container -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Main tabs
    document.querySelectorAll('.main-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab).classList.add('active');
        });
    });

    // Sub tabs
    document.querySelectorAll('.sub-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.sub-tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.subtab).classList.add('active');
        });
    });

    // Auto-switch to certificates tab if URL says so
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'certificates') {
        document.querySelector('.main-tab[data-tab="certificates"]')?.click();
    }
});

function exportReport(tab, format) {
    const dateFrom  = document.getElementById('date_from').value;
    const dateTo    = document.getElementById('date_to').value;
    const activeSubTab = document.querySelector('.sub-tab.active');
    if (activeSubTab && tab === 'attendance') {
        window.location.href = `reports_monitoring.php?export=${format}&tab=${tab}&subtab=${activeSubTab.dataset.subtab}&date_from=${dateFrom}&date_to=${dateTo}`;
    } else {
        window.location.href = `reports_monitoring.php?export=${format}&tab=${tab}&date_from=${dateFrom}&date_to=${dateTo}`;
    }
}

function generateBulkCertificates(event, btn) {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo   = document.getElementById('date_to').value;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Certificates...';
    btn.disabled = true;
    window.open(`generate_bulk_certificates.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
    setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 5000);
}
</script>

</body>
</html>