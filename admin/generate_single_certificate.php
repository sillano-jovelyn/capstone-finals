<?php
// generate_single_certificate.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Database connection
$conn = null;

try {
    $db_file = __DIR__ . '/../db.php';
    
    if (!file_exists($db_file)) {
        throw new Exception("Database configuration file not found: " . $db_file);
    }
    
    require_once $db_file;
    
    if (!$conn || !($conn instanceof mysqli)) {
        throw new Exception("Database connection not established");
    }
    
    if (!$conn->ping()) {
        throw new Exception("Database connection lost");
    }
    
} catch (Exception $e) {
    error_log("Certificate Generator Error: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// Get parameters from URL
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;

// Validate parameters
if ($user_id <= 0 || $program_id <= 0) {
    http_response_code(400);
    die("Invalid parameters. Both user_id and program_id are required.");
}

// Fetch user and program data
$fullname = '';
$program_name = '';
$completion_date = date('F d, Y');

try {
    // Fetch user and program data
    $query = "SELECT 
                u.fullname,
                p.name AS program_name,
                DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date
              FROM users u
              JOIN enrollments e ON u.id = e.user_id
              JOIN programs p ON e.program_id = p.id
              WHERE u.id = ? AND p.id = ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $program_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $fullname = $row['fullname'] ?: 'NAME';
            $program_name = $row['program_name'] ?: 'NAME OF TRAINING';
            $completion_date = $row['completion_date'] ?: date('F d, Y');
        }
        $stmt->close();
    }
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Continue with default values
}

$conn->close();

// Format date to match "27th day of October 2025" format EXACTLY
$day = date('jS', strtotime($completion_date));
$month_year = date('F Y', strtotime($completion_date));
$formatted_date = $day . ' day of ' . $month_year;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        
        /* Print styles */
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
            <img src="/trainee/SLOGO.jpg" alt="Watermark" onerror="this.style.display='none';">
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
                <p>For having satisfactorily completed the <span class="hours-highlight">40 hours</span> of</p>
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
                            <!-- Photo will be inserted here dynamically if available -->
                            <div class="photo-placeholder">
                                <!-- Placeholder for photo -->
                            </div>
                        </div>
                        <div class="photo-signature-line"></div>
                        <div class="photo-signature-label">Signature</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Print functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-print after 1 second
            setTimeout(function() {
                window.print();
            }, 1000);
            
            // Ctrl+P to print
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }
            });
        });
        
        // Print button handler
        function printCertificate() {
            window.print();
        }
    </script>
</body>
</html>