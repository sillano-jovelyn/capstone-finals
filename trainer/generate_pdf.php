<?php
// ============================================
// SIMPLE PDF GENERATOR - WITH LOGO SA RIGHT
// ============================================
session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
    header('HTTP/1.0 403 Forbidden');
    die('Access Denied');
}

// Database connection
require_once __DIR__ . '/../db.php';

if (!$conn) {
    die('Database connection failed');
}

// Get enrollment ID
$enrollment_id = isset($_GET['enrollment_id']) ? intval($_GET['enrollment_id']) : 0;

if (!$enrollment_id) {
    die('No enrollment ID provided');
}

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Include TCPDF
require_once(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php');

// Get program data
$program = $conn->query("
    SELECT p.*, t.fullname as trainer_name
    FROM enrollments e
    JOIN programs p ON e.program_id = p.id
    LEFT JOIN users t ON p.trainer = t.fullname
    WHERE e.id = $enrollment_id
")->fetch_assoc();

if (!$program) {
    die('Program not found');
}

$program_id = $program['id'];

// Get all trainees in the program
$trainees = $conn->query("
    SELECT t.fullname, 
           ac.practical_score, 
           ac.project_score, 
           ac.oral_score, 
           ac.oral_max_score
    FROM enrollments e
    JOIN trainees t ON e.user_id = t.user_id
    LEFT JOIN assessment_components ac ON e.id = ac.enrollment_id
    WHERE e.program_id = $program_id
    AND e.enrollment_status IN ('approved', 'completed')
    ORDER BY t.fullname ASC
");

// Create PDF
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

// ============================================
// LOGO SA RIGHT SIDE
// ============================================
$logo_path = __DIR__ . '/SLOGO.jpg'; // Direct path sa trainer folder
if (file_exists($logo_path)) {
    // I-place ang logo sa right side (x=250, y=10, width=30, height=30)
    $pdf->Image($logo_path, 250, 10, 30, 30, 'JPG');
} else {
    // Optional: log kung hindi mahanap ang logo
    error_log("Logo not found at: " . $logo_path);
}

// ============================================
// MUNICIPAL HEADER (naka-center)
// ============================================
$pdf->SetY(15); // Adjust Y position para hindi mag-overlap sa logo
$pdf->SetFont('helvetica', '', 11);

$html = '
<div style="text-align: center; margin-bottom: 15px;">
    <div style="font-size: 18px; font-weight: bold;">MUNICIPALITY OF SANTA MARIA</div>
    <div style="font-size: 13px; margin-bottom: 5px;">Province of Bulacan</div>
    <div style="font-size: 16px; font-weight: bold; margin-top: 10px;">TRAINING ASSESSMENT REPORT</div>
    <hr style="border-top: 1px solid black;">
</div>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(2);

// Program Info
$html = '
<table cellpadding="4" style="width: 100%; margin-bottom: 10px;">
    <tr>
        <td width="15%"><strong>Program:</strong></td>
        <td width="35%">' . htmlspecialchars($program['name']) . '</td>
        <td width="15%"><strong>Trainer:</strong></td>
        <td width="35%">' . htmlspecialchars($program['trainer_name'] ?? 'N/A') . '</td>
    </tr>
    <tr>
        <td><strong>Schedule:</strong></td>
        <td>' . date('M d, Y', strtotime($program['scheduleStart'])) . ' - ' . date('M d, Y', strtotime($program['scheduleEnd'])) . '</td>
        <td><strong>Date:</strong></td>
        <td>' . date('F d, Y') . '</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(2);

// Assessment Table
$html = '
<table border="1" cellpadding="5" style="border-collapse: collapse;">
    <thead>
        <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th width="8%">No.</th>
            <th width="32%">TRAINEE NAME</th>
            <th width="15%">PRACTICAL (100)</th>
            <th width="15%">PROJECT (100)</th>
            <th width="15%">ORAL</th>
            <th width="15%">TOTAL</th>
        </tr>
    </thead>
    <tbody>';

$count = 1;
$program_total = 0;

while ($t = $trainees->fetch_assoc()) {
    $practical = $t['practical_score'] ?? 0;
    $project = $t['project_score'] ?? 0;
    $oral = $t['oral_score'] ?? 0;
    $oral_max = $t['oral_max_score'] ?? 100;
    $total = $practical + $project + $oral;
    
    $program_total += $total;
    
    $html .= '
        <tr>
            <td align="center">' . $count++ . '</td>
            <td>' . htmlspecialchars($t['fullname']) . '</td>
            <td align="center">' . $practical . '</td>
            <td align="center">' . $project . '</td>
            <td align="center">' . $oral . '/' . $oral_max . '</td>
            <td align="center">' . $total . '</td>
        </tr>';
}

$html .= '
        <tr style="background-color: #f2f2f2; font-weight: bold;">
            <td colspan="5" align="right">PROGRAM TOTAL:</td>
            <td align="center">' . $program_total . '</td>
        </tr>
    </tbody>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(10);

// Signatures
$html = '
<table cellpadding="5" style="width: 100%; margin-top: 20px;">
    <tr>
        <td width="33%" align="center">
            <div style="border-bottom: 1px solid black; width: 80%; margin: 0 auto; height: 25px;"></div>
            <div style="margin-top: 5px; font-weight: bold;">' . htmlspecialchars($_SESSION['fullname'] ?? 'Trainer') . '</div>
            <div style="font-size: 10px;">Trainer</div>
        </td>
        <td width="33%" align="center">
            <div style="border-bottom: 1px solid black; width: 80%; margin: 0 auto; height: 25px;"></div>
            <div style="margin-top: 5px; font-weight: bold;">ZENAIDA S. MANINGAS</div>
            <div style="font-size: 10px;">PESO Manager</div>
        </td>
        <td width="33%" align="center">
            <div style="border-bottom: 1px solid black; width: 80%; margin: 0 auto; height: 25px;"></div>
            <div style="margin-top: 5px; font-weight: bold;">BARTOLOME R. RAMOS</div>
            <div style="font-size: 10px;">Municipal Mayor</div>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$filename = 'Assessment_Report_' . str_replace(' ', '_', $program['name']) . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>