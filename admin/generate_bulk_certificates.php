<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = null;

try {
    $db_file = __DIR__ . '/../db.php';
    if (!file_exists($db_file)) throw new Exception("Database config not found");
    require_once $db_file;
    if (!$conn || !($conn instanceof mysqli)) throw new Exception("DB connection not established");
    if (!$conn->ping()) throw new Exception("DB connection lost");
} catch (Exception $e) {
    http_response_code(500);
    die("Database connection error.");
}

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';

if (empty($date_from) || empty($date_to)) {
    http_response_code(400);
    die("Both date_from and date_to are required.");
}

// Fetch all eligible completed enrollments within the date range
// (from both active enrollments and archived_history)
$query = "
    SELECT 
        u.id AS user_id,
        u.fullname,
        p.name AS program_name,
        DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date,
        e.completed_at AS completed_at_raw,
        'active' AS source
    FROM users u
    JOIN enrollments e ON u.id = e.user_id
    JOIN programs p ON e.program_id = p.id
    WHERE e.enrollment_status = 'completed'
        AND e.assessment = 'passed'
        AND DATE(e.completed_at) BETWEEN ? AND ?
        AND u.role = 'trainee'
        AND EXISTS (
            SELECT 1 FROM feedback f
            WHERE f.user_id = u.id AND f.program_id = p.id
        )

    UNION

    SELECT 
        ah.user_id,
        u.fullname COLLATE utf8mb4_unicode_ci,
        ah.program_name COLLATE utf8mb4_unicode_ci AS program_name,
        DATE_FORMAT(ah.enrollment_completed_at, '%M %d, %Y') AS completion_date,
        ah.enrollment_completed_at AS completed_at_raw,
        'archived' AS source
    FROM archived_history ah
    JOIN users u ON ah.user_id = u.id
    WHERE ah.enrollment_status = 'completed'
        AND LOWER(ah.enrollment_assessment) = 'passed'
        AND DATE(ah.enrollment_completed_at) BETWEEN ? AND ?
        AND ah.feedback_id IS NOT NULL
        AND ah.program_name != '0'
        AND ah.program_name != ''

    ORDER BY fullname ASC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query error: " . $conn->error);
}

$stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
$stmt->execute();
$result = $stmt->get_result();
$certificates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

if (empty($certificates)) {
    die("
        <div style='font-family: Arial, sans-serif; text-align: center; padding: 60px;'>
            <h2 style='color: #c0392b;'>No Results Found</h2>
            <p style='color: #555;'>No completed enrollments found for the selected date range:<br>
            <strong>" . htmlspecialchars($date_from) . "</strong> to <strong>" . htmlspecialchars($date_to) . "</strong></p>
            <p style='color: #777; font-size: 14px;'>To be eligible, trainees must have:<br>
            1) Completed status &nbsp; 2) Passed assessment &nbsp; 3) Submitted feedback</p>
            <a href='javascript:window.close()' style='color: #2d8b8e;'>Close this window</a>
        </div>
    ");
}

// Function to generate barcode data (simplified for smaller barcode)
function generateBarcodeData($user_id, $fullname, $program_name, $completion_date_raw, $source) {
    // Create a compact unique identifier
    $certificate_data = [
        'uid' => $user_id,
        'fn' => substr($fullname, 0, 30), // Truncate to keep barcode small
        'pg' => substr($program_name, 0, 40),
        'dt' => date('Ymd', strtotime($completion_date_raw)),
        'src' => $source == 'archived' ? 'a' : 'c',
        'cid' => uniqid()
    ];
    
    // Convert to JSON
    return json_encode($certificate_data);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Certificates — <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Add JsBarcode library for barcode generation -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        /* ===================== SCREEN STYLES ===================== */
        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 0;
            padding: 0;
            background: #ddd;
        }

        /* Floating download panel */
        .print-controls {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            text-align: center;
            background: white;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            font-family: Arial, sans-serif;
            min-width: 240px;
        }

        .print-controls h4 {
            margin: 0 0 6px 0;
            font-size: 14px;
            color: #333;
        }

        .print-controls .cert-count {
            font-size: 13px;
            color: #2d8b8e;
            font-weight: bold;
            margin-bottom: 14px;
        }

        .download-btn {
            background: #2d8b8e;
            color: white;
            border: none;
            padding: 12px 22px;
            font-size: 15px;
            border-radius: 8px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-weight: bold;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.2s;
        }

        .download-btn:hover {
            background: #246f72;
        }

        .print-controls .hint {
            font-size: 11px;
            color: #888;
            margin: 10px 0 0 0;
            line-height: 1.5;
        }

        .print-controls .hint strong {
            color: #555;
        }

        /* Page wrapper for screen spacing */
        .page-wrapper {
            padding: 30px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 30px;
        }

        /* ===================== CERTIFICATE STYLES ===================== */
        .certificate-container {
            width: 210mm;
            height: 297mm;
            background: #f5f0e8;
            position: relative;
            box-sizing: border-box;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            overflow: hidden;
            page-break-after: always;
        }

        /* Decorative outer border */
        .decorative-border {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            border: 35px solid transparent;
            border-image: repeating-linear-gradient(
                45deg,
                #2d8b8e 0px,   #2d8b8e 10px,
                #d4a574 10px,  #d4a574 20px,
                #2d8b8e 20px,  #2d8b8e 30px,
                #f5f0e8 30px,  #f5f0e8 40px
            ) 35;
            pointer-events: none;
            z-index: 2;
        }

        /* Inner decorative border */
        .inner-border {
            position: absolute;
            top: 20px; left: 20px; right: 20px; bottom: 20px;
            border: 15px solid;
            border-image: repeating-linear-gradient(
                0deg,
                #2d8b8e 0px,  #2d8b8e 3px,
                #d4a574 3px,  #d4a574 6px,
                #2d8b8e 6px,  #2d8b8e 9px,
                #f5f0e8 9px,  #f5f0e8 12px
            ) 15;
            pointer-events: none;
            z-index: 2;
        }

        /* Main content area */
        .certificate-content {
            position: absolute;
            width: 100%; height: 100%;
            top: 0; left: 0;
            padding: 50px 70px;
            z-index: 1;
            box-sizing: border-box;
        }

        /* Logos */
        .logos-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 30px;
            margin: 15px 0 20px 0;
        }

        .logo-item { width: 80px; height: 80px; }
        .logo-item img { width: 100%; height: 100%; object-fit: contain; }

        /* Header text */
        .header-top {
            text-align: center; font-size: 16px; font-weight: bold;
            color: black; margin: 15px 0 5px 0;
            text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2;
        }

        .cooperation {
            text-align: center; font-size: 14px; color: black; margin: 5px 0; line-height: 1.2;
        }

        .tesda {
            text-align: center; font-size: 14px; font-weight: bold;
            color: black; margin: 5px 0; text-transform: uppercase; line-height: 1.2;
        }

        .training-center {
            text-align: center; font-size: 20px; font-weight: bold;
            color: #2d8b8e; margin: 8px 0 35px 0;
            text-transform: uppercase; letter-spacing: 1px; line-height: 1.2;
        }

        /* Certificate title */
        .certificate-title { text-align: center; margin: 0 0 35px 0; }
        .certificate-title h1 {
            font-size: 48px; margin: 0; color: #2d8b8e;
            font-weight: bold; text-transform: uppercase;
            letter-spacing: 6px; line-height: 1;
        }

        /* Awarded to */
        .awarded-to { text-align: center; margin: 0 0 20px 0; }
        .awarded-to p { font-size: 18px; margin: 0; color: black; line-height: 1.3; }

        /* Trainee name */
        .trainee-name-container { text-align: center; margin: 0 0 25px 0; }
        .trainee-name {
            font-size: 48px; color: black; font-weight: bold;
            text-transform: uppercase; letter-spacing: 2px; line-height: 1.1;
        }

        /* Completion text */
        .completion-text { text-align: center; margin: 0 0 20px 0; }
        .completion-text p { font-size: 16px; margin: 0; color: black; line-height: 1.3; }

        /* Training/program name */
        .training-name-container { text-align: center; margin: 0 0 30px 0; }
        .training-name {
            font-size: 36px; color: black; font-weight: bold;
            text-transform: uppercase; letter-spacing: 2px; line-height: 1.1;
        }

        /* Date */
        .given-date { text-align: center; margin: 0 0 40px 0; }
        .given-date p { font-size: 16px; margin: 0; color: black; line-height: 1.4; }

        /* Signatures */
        .signatures {
            position: absolute;
            bottom: 60px; left: 70px; right: 70px;
        }

        .signatures-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .left-signatures { display: flex; flex-direction: column; gap: 35px; flex: 1; }

        .signature-block { text-align: center; }

        .signature-line {
            border-bottom: 2px solid black;
            width: 280px; margin: 0 auto 5px auto; height: 1px;
        }

        .signature-name {
            font-size: 15px; font-weight: bold; color: black;
            text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2;
        }

        .signature-title { font-size: 14px; color: black; margin: 3px 0 0 0; line-height: 1.2; }

        /* Photo section */
        .photo-signature-section {
            width: 180px; display: flex;
            flex-direction: column; align-items: center; margin-left: 40px;
        }

        .photo-box {
            width: 150px; height: 180px; border: 2px solid #888;
            background: white; display: flex;
            align-items: center; justify-content: center; margin-bottom: 10px;
        }

        .photo-signature-line { border-bottom: 2px solid black; width: 150px; margin: 5px 0; }
        .photo-signature-label { font-size: 12px; color: black; text-align: center; }

        /* Watermark */
        .watermark {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.06; z-index: 0; pointer-events: none;
        }
        .watermark img { width: 500px; height: 500px; object-fit: contain; }

        /* EXTREMELY HIDDEN BARCODE - Almost invisible */
        .hidden-barcode {
            position: absolute;
            bottom: 15px;
            right: 20px;
            width: 80px;
            height: 28px;
            opacity: 0.02;
            z-index: 10;
            pointer-events: none;
            transition: none;
        }
        
        .hidden-barcode svg {
            width: 100%;
            height: 100%;
        }
        
        /* Even more hidden on print - barely visible but scannable */
        @media print {
            .hidden-barcode {
                opacity: 0.03 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
        
        /* Ultra hidden certificate ID text - almost invisible */
        .certificate-id {
            position: absolute;
            bottom: 5px;
            left: 20px;
            font-size: 4px;
            color: #f5f0e8;
            font-family: monospace;
            opacity: 0.1;
            z-index: 10;
            letter-spacing: 0.2px;
        }

        /* ===================== PRINT STYLES ===================== */
        @media print {
            /* Hide everything that's not a certificate */
            .print-controls { display: none !important; }
            .page-wrapper { padding: 0; gap: 0; background: white; margin: 0; display: block; }

            body { 
                background: white; 
                margin: 0; 
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .certificate-container {
                width: 100vw;
                height: 99vh;
                max-height: 99vh;
                margin: 0;
                padding: 0;
                box-shadow: none;
                page-break-after: always;
                break-after: page;
                page-break-inside: avoid;
                break-inside: avoid;
                overflow: hidden;
                position: relative;
                display: block;
            }

            /* Last certificate — no trailing blank page */
            .certificate-container:last-child {
                page-break-after: avoid !important;
                break-after: avoid !important;
            }
            
            /* Ensure barcode is printed but extremely faint */
            .hidden-barcode {
                opacity: 0.03 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            .certificate-id {
                opacity: 0.05 !important;
                print-color-adjust: exact;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 220mm) {
            .certificate-container {
                transform: scale(0.95);
                margin: 10px auto;
            }
        }

        * { box-sizing: border-box; }
    </style>
</head>
<body>

<!-- Floating print/download panel -->
<div class="print-controls" id="printControls">
    <h4><i class="fas fa-qrcode"></i> Bulk Certificate Export</h4>
    <div class="cert-count">
        <i class="fas fa-certificate"></i> <?php echo count($certificates); ?> certificate<?php echo count($certificates) !== 1 ? 's' : ''; ?> ready
        <span style="display: block; font-size: 10px; margin-top: 4px;">with ultra-hidden barcodes (user_id embedded)</span>
    </div>
    <button class="download-btn" onclick="window.print()">
        <i class="fas fa-file-pdf"></i> Download as PDF
    </button>
    <p class="hint">
        <i class="fas fa-info-circle"></i> In the print dialog:<br>
        • Set <strong>Destination</strong> → <strong>Save as PDF</strong><br>
        • Set <strong>Margins</strong> → <strong>None</strong><br>
        • Enable <strong>Background graphics</strong><br>
        • Ultra-hidden barcodes contain User ID and certificate data
    </p>
</div>

<!-- Certificates -->
<div class="page-wrapper">
<?php 
$certificate_counter = 0;
foreach ($certificates as $index => $cert):
    $completion_date = $cert['completion_date'] ?: date('F d, Y');
    $day        = date('jS', strtotime($completion_date));
    $month_year = date('F Y', strtotime($completion_date));
    $formatted_date = $day . ' day of ' . $month_year;
    $fullname     = $cert['fullname']     ?: 'NAME';
    $program_name = $cert['program_name'] ?: 'NAME OF TRAINING';
    $user_id = $cert['user_id'];
    $source = $cert['source'];
    $completed_at_raw = $cert['completed_at_raw'];
    
    // Generate unique certificate ID
    $certificate_unique_id = 'LEMS-' . str_pad($user_id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd', strtotime($completed_at_raw));
    
    // Generate barcode data using user_id (simplified for smaller barcode)
    $barcode_data = generateBarcodeData($user_id, $fullname, $program_name, $completed_at_raw, $source);
    
    // Escape for JavaScript
    $barcode_data_js = addslashes($barcode_data);
    $barcode_id = 'barcode_' . $index . '_' . $user_id;
    $certificate_counter++;
?>

<div class="certificate-container" data-user-id="<?php echo $user_id; ?>" data-cert-id="<?php echo $certificate_unique_id; ?>">
    <!-- Watermark -->
    <div class="watermark">
        <img src="/trainee/SLOGO.jpg" alt="Watermark" onerror="this.style.display='none';">
    </div>

    <!-- Borders -->
    <div class="decorative-border"></div>
    <div class="inner-border"></div>

    <div class="certificate-content">

        <!-- Logos -->
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

        <!-- Header -->
        <div class="header-top">MUNICIPALITY OF SANTA MARIA, BULACAN</div>
        <div class="cooperation">IN COOPERATION WITH</div>
        <div class="tesda">TECHNICAL EDUCATION &amp; SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN</div>
        <div class="training-center">SANTA MARIA LIVELIHOOD TRAINING CENTER</div>

        <!-- Title -->
        <div class="certificate-title">
            <h1>CERTIFICATE OF TRAINING</h1>
        </div>

        <!-- Awarded to -->
        <div class="awarded-to">
            <p>is awarded to</p>
        </div>

        <!-- Trainee name -->
        <div class="trainee-name-container">
            <div class="trainee-name">
                <?php echo htmlspecialchars(strtoupper($fullname)); ?>
            </div>
        </div>

        <!-- Completion text -->
        <div class="completion-text">
            <p>For having satisfactorily completed the</p>
        </div>

        <!-- Program name -->
        <div class="training-name-container">
            <div class="training-name">
                <?php echo htmlspecialchars(strtoupper($program_name)); ?>
            </div>
        </div>

        <!-- Date -->
        <div class="given-date">
            <p>Given this <?php echo $formatted_date; ?> at Santa Maria Livelihood Training and</p>
            <p>Employment Center, Santa Maria, Bulacan.</p>
        </div>

        <!-- Signatures -->
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
                    <br>
                </div>
                <div class="photo-signature-section">
                    <div class="photo-box">
                        <!-- Photo placeholder -->
                    </div>
                    <div class="photo-signature-line"></div>
                    <div class="photo-signature-label">Signature</div>
                </div>
            </div>
        </div>

    </div><!-- /certificate-content -->
    
    <!-- ULTRA HIDDEN barcode section - almost completely invisible -->
    <div class="hidden-barcode">
        <svg id="<?php echo $barcode_id; ?>"></svg>
    </div>
    
    <!-- ULTRA HIDDEN certificate ID text - blends with background -->
    <div class="certificate-id">
        <?php echo htmlspecialchars($certificate_unique_id); ?> | User ID: <?php echo $user_id; ?>
    </div>
    
    <script>
        // Generate barcode for this certificate with user_id embedded
        (function() {
            try {
                var barcodeData = "<?php echo $barcode_data_js; ?>";
                var barcodeId = "<?php echo $barcode_id; ?>";
                var userId = "<?php echo $user_id; ?>";
                
                // Generate Code128 barcode with very thin lines for better hiding
                JsBarcode("#" + barcodeId, barcodeData, {
                    format: "CODE128",
                    width: 0.8,
                    height: 25,
                    displayValue: false,
                    margin: 0,
                    background: "transparent",
                    lineColor: "#d4a574" // Use a color that blends with certificate background
                });
                
                // Silent generation - no console logs in production
                if (window.console && false) {
                    console.log("Barcode generated for User ID: " + userId);
                }
            } catch(e) {
                // Silently fail - barcode not critical
            }
        })();
    </script>
</div>
<?php endforeach; ?>
</div>
<script>
    // Silent summary - no console output
    document.addEventListener('DOMContentLoaded', function() {
        // Barcodes are ready but hidden
    });
</script>

</body>
</html>