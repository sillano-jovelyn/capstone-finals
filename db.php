<?php
$host = "db5019042279.hosting-data.io";
$user = "dbu3867777";
$pass = "JOVELYN-QSL:4vXzL-HLSRL!";
$dbname = "dbs14985503";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>