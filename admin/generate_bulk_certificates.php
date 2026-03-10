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

// Fetch all completed enrollments within the date range
$query = "SELECT 
            u.fullname,
            p.name AS program_name,
            DATE_FORMAT(e.completed_at, '%M %d, %Y') AS completion_date
          FROM users u
          JOIN enrollments e ON u.id = e.user_id
          JOIN programs p ON e.program_id = p.id
          WHERE DATE(e.completed_at) BETWEEN ? AND ?
          ORDER BY u.fullname ASC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query error: " . $conn->error);
}

$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$result = $stmt->get_result();
$certificates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

if (empty($certificates)) {
    die("No completed enrollments found for the selected date range.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk Certificates</title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }

        .certificate-container {
            width: 210mm;
            height: 297mm;
            background: #f5f0e8;
            position: relative;
            box-sizing: border-box;
            margin: 0 auto;
            page-break-after: always;
        }

        .decorative-border {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            border: 35px solid transparent;
            border-image: repeating-linear-gradient(
                45deg,
                #2d8b8e 0px, #2d8b8e 10px,
                #d4a574 10px, #d4a574 20px,
                #2d8b8e 20px, #2d8b8e 30px,
                #f5f0e8 30px, #f5f0e8 40px
            ) 35;
            pointer-events: none;
            z-index: 2;
        }

        .inner-border {
            position: absolute;
            top: 20px; left: 20px; right: 20px; bottom: 20px;
            border: 15px solid;
            border-image: repeating-linear-gradient(
                0deg,
                #2d8b8e 0px, #2d8b8e 3px,
                #d4a574 3px, #d4a574 6px,
                #2d8b8e 6px, #2d8b8e 9px,
                #f5f0e8 9px, #f5f0e8 12px
            ) 15;
            pointer-events: none;
            z-index: 2;
        }

        .certificate-content {
            position: absolute;
            width: 100%; height: 100%;
            top: 0; left: 0;
            padding: 50px 70px;
            z-index: 1;
            box-sizing: border-box;
        }

        .logos-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 30px;
            margin: 15px 0 20px 0;
        }

        .logo-item { width: 80px; height: 80px; }
        .logo-item img { width: 100%; height: 100%; object-fit: contain; }

        .header-top {
            text-align: center; font-size: 16px; font-weight: bold;
            color: black; margin: 15px 0 5px 0;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .cooperation { text-align: center; font-size: 14px; color: black; margin: 5px 0; }

        .tesda {
            text-align: center; font-size: 14px; font-weight: bold;
            color: black; margin: 5px 0; text-transform: uppercase;
        }

        .training-center {
            text-align: center; font-size: 20px; font-weight: bold;
            color: #2d8b8e; margin: 8px 0 35px 0;
            text-transform: uppercase; letter-spacing: 1px;
        }

        .certificate-title { text-align: center; margin: 0 0 35px 0; }
        .certificate-title h1 {
            font-size: 48px; margin: 0; color: #2d8b8e;
            font-weight: bold; text-transform: uppercase;
            letter-spacing: 6px; line-height: 1;
        }

        .awarded-to { text-align: center; margin: 0 0 20px 0; }
        .awarded-to p { font-size: 18px; margin: 0; color: black; }

        .trainee-name-container { text-align: center; margin: 0 0 25px 0; }
        .trainee-name {
            font-size: 48px; color: black; font-weight: bold;
            text-transform: uppercase; letter-spacing: 2px; line-height: 1.1;
        }

        .completion-text { text-align: center; margin: 0 0 20px 0; }
        .completion-text p { font-size: 16px; margin: 0; color: black; }

        .training-name-container { text-align: center; margin: 0 0 30px 0; }
        .training-name {
            font-size: 36px; color: black; font-weight: bold;
            text-transform: uppercase; letter-spacing: 2px; line-height: 1.1;
        }

        .given-date { text-align: center; margin: 0 0 40px 0; }
        .given-date p { font-size: 16px; margin: 0; color: black; line-height: 1.4; }

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
            width: 280px; margin: 0 auto 5px auto;
        }

        .signature-name {
            font-size: 15px; font-weight: bold;
            color: black; text-transform: uppercase; letter-spacing: 0.5px;
        }

        .signature-title { font-size: 14px; color: black; margin: 3px 0 0 0; }

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

        .watermark {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.06; z-index: 0; pointer-events: none;
        }
        .watermark img { width: 500px; height: 500px; object-fit: contain; }

        @media print {
            body { background: white; }
            .certificate-container {
                margin: 0;
                box-shadow: none;
                page-break-after: always;
            }
            @page { size: A4 portrait; margin: 0; }
        }

        * { box-sizing: border-box; }
    </style>
</head>
<body>

<?php foreach ($certificates as $cert):
    $completion_date = $cert['completion_date'] ?: date('F d, Y');
    $day = date('jS', strtotime($completion_date));
    $month_year = date('F Y', strtotime($completion_date));
    $formatted_date = $day . ' day of ' . $month_year;
    $fullname = $cert['fullname'] ?: 'NAME';
    $program_name = $cert['program_name'] ?: 'NAME OF TRAINING';
?>

<div class="certificate-container">
    <div class="watermark">
        <img src="/trainee/SLOGO.jpg" alt="Watermark" onerror="this.style.display='none';">
    </div>
    <div class="decorative-border"></div>
    <div class="inner-border"></div>

    <div class="certificate-content">
        <div class="logos-row">
            <div class="logo-item"><img src="/trainee/SMBLOGO.jpg" alt="Santa Maria Logo" onerror="this.style.display='none';"></div>
            <div class="logo-item"><img src="/trainee/SLOGO.jpg" alt="Training Center Logo" onerror="this.style.display='none';"></div>
            <div class="logo-item"><img src="/trainee/TESDALOGO.png" alt="TESDA Logo" onerror="this.style.display='none';"></div>
        </div>

        <div class="header-top">MUNICIPALITY OF SANTA MARIA, BULACAN</div>
        <div class="cooperation">IN COOPERATION WITH</div>
        <div class="tesda">TECHNICAL EDUCATION & SKILLS DEVELOPMENT AUTHORITY (TESDA)-BULACAN</div>
        <div class="training-center">SANTA MARIA LIVELIHOOD TRAINING CENTER</div>

        <div class="certificate-title"><h1>CERTIFICATE OF TRAINING</h1></div>
        <div class="awarded-to"><p>is awarded to</p></div>

        <div class="trainee-name-container">
            <div class="trainee-name"><?php echo htmlspecialchars(strtoupper($fullname)); ?></div>
        </div>

        <div class="completion-text"><p>For having satisfactorily completed the</p></div>

        <div class="training-name-container">
            <div class="training-name"><?php echo htmlspecialchars(strtoupper($program_name)); ?></div>
        </div>

        <div class="given-date">
            <p>Given this <?php echo $formatted_date; ?> at Santa Maria Livelihood Training and</p>
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
                    <div class="photo-box"></div>
                    <div class="photo-signature-line"></div>
                    <div class="photo-signature-label">Signature</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endforeach; ?>

<script>
    window.addEventListener('load', function() {
        setTimeout(() => window.print(), 1000);
    });
</script>
</body>
</html>