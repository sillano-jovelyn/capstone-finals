<?php
session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: /login.php?redirectTo=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (strtolower($_SESSION['role']) !== 'trainer') {
    header('Location: /login.php');
    exit;
}

$user_id  = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'Trainer';
$role     = $_SESSION['role'];

require_once __DIR__ . '/../db.php';

// ============================================================
// DB FUNCTIONS
// ============================================================

// NEW: Function to check if user has feedback for a program
function hasUserFeedback($conn, $user_id, $program_id) {
    $s = $conn->prepare("SELECT COUNT(*) as count FROM feedback WHERE user_id = ? AND program_id = ?");
    $s->bind_param("si", $user_id, $program_id);
    $s->execute();
    $result = $s->get_result()->fetch_assoc();
    return ($result['count'] ?? 0) > 0;
}

function getTrainerProgram($conn, $trainer_name) {
    $s = $conn->prepare("SELECT p.* FROM programs p WHERE p.trainer=? AND p.archived=0 ORDER BY p.created_at DESC LIMIT 1");
    $s->bind_param("s", $trainer_name); $s->execute();
    $program = $s->get_result()->fetch_assoc();
    if (!$program) return null;
    $program['scheduleStart'] = date('Y-m-d', strtotime($program['scheduleStart']));
    $program['scheduleEnd']   = date('Y-m-d', strtotime($program['scheduleEnd']));
    $c = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) as t FROM enrollments e WHERE e.program_id=? AND e.enrollment_status='approved' AND (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))");
    $c->bind_param("i", $program['id']); $c->execute();
    $program['total_trainees'] = $c->get_result()->fetch_assoc()['t'] ?? 0;
    return $program;
}

// MODIFIED: Updated to include pending feedback in ongoing counts
function getTraineeCountsByStatus($conn, $program_id) {
    $out = ['total'=>0, 'ongoing'=>0, 'certified'=>0, 'failed'=>0, 'dropout'=>0, 'pending_feedback'=>0];
    
    // Total (all non-rejected)
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e WHERE e.program_id=? AND e.enrollment_status!='rejected'");
    $s->bind_param("i", $program_id); $s->execute();
    $out['total'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Ongoing (includes both regular ongoing AND pending feedback)
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e 
                        WHERE e.program_id=? AND e.enrollment_status='approved' 
                        AND (
                            (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                            OR 
                            ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = e.program_id))
                        )");
    $s->bind_param("i", $program_id); $s->execute();
    $out['ongoing'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Certified (ONLY if they have feedback)
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e JOIN trainees t ON e.user_id = t.user_id WHERE e.program_id=? AND (e.assessment='Passed' OR e.enrollment_status='certified') AND EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = e.program_id)");
    $s->bind_param("i", $program_id); $s->execute();
    $out['certified'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Pending Feedback (still track for informational purposes)
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e WHERE e.program_id=? AND (e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = e.program_id)");
    $s->bind_param("i", $program_id); $s->execute();
    $out['pending_feedback'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Failed
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e WHERE e.program_id=? AND e.assessment='Failed' AND e.enrollment_status!='rejected'");
    $s->bind_param("i", $program_id); $s->execute();
    $out['failed'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Dropout
    $s = $conn->prepare("SELECT COUNT(DISTINCT e.user_id) c FROM enrollments e WHERE e.program_id=? AND e.enrollment_status='rejected'");
    $s->bind_param("i", $program_id); $s->execute();
    $out['dropout'] = $s->get_result()->fetch_assoc()['c'] ?? 0;
    
    return $out;
}

function calculateProgramDuration($start,$end,$dur,$unit) {
    if ($start && $end) { $s=new DateTime($start);$e=new DateTime($end); return $s->diff($e)->days+1; }
    if ($dur && $unit) { if($unit==='days')return$dur; if($unit==='weeks')return$dur*7; if($unit==='months')return$dur*30; if($unit==='years')return$dur*365; }
    return 40;
}
function isTodayWithinProgramSchedule($s,$e) {
    $t=new DateTime('today'); $sd=new DateTime($s); $ed=new DateTime($e);
    $sd->setTime(0,0,0); $ed->setTime(23,59,59);
    return ($t>=$sd && $t<=$ed);
}
function hasProgramStarted($s) { $t=new DateTime('today'); $sd=new DateTime($s); $sd->setTime(0,0,0); return $t>=$sd; }
function hasProgramEnded($e)   { $t=new DateTime('today'); $ed=new DateTime($e); $ed->setTime(23,59,59); return $t>$ed; }
function getCurrentProgramDay($s) {
    $t=new DateTime('today'); $sd=new DateTime($s); $sd->setTime(0,0,0);
    if ($t<$sd) return 0;
    return $sd->diff($t)->days+1;
}

// MODIFIED: Enhanced to include pending feedback in ongoing
function getTraineesByTrainerProgram($conn, $program_id, $filter='ongoing') {
    $today = date('Y-m-d');
    $q = "SELECT e.id as enrollment_id, e.applied_at, e.enrollment_status, e.attendance, e.assessment, e.failure_notes,
            t.id, t.user_id, t.fullname, t.firstname, t.lastname, t.contact_number, t.email,
            t.barangay, t.municipality, t.city, t.gender, t.age, t.education, t.failure_notes_copy,
            p.name as program_name, p.scheduleStart, p.scheduleEnd, p.duration, p.durationUnit,
            (SELECT COUNT(*) FROM attendance_records ar WHERE ar.enrollment_id=e.id AND ar.status='present') as days_attended,
            (SELECT ar2.status FROM attendance_records ar2 WHERE ar2.enrollment_id=e.id AND ar2.attendance_date=? LIMIT 1) as today_attendance_status,
            (SELECT ar3.marked_by FROM attendance_records ar3 WHERE ar3.enrollment_id=e.id AND ar3.attendance_date=? LIMIT 1) as today_marked_by,
            (SELECT COUNT(*) > 0 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = p.id) as has_feedback
          FROM enrollments e
          JOIN trainees t ON e.user_id = t.user_id
          JOIN programs p ON e.program_id = p.id
          WHERE e.program_id = ?";
    
    if ($filter === 'ongoing') {
        // Include both:
        // 1. Regular ongoing trainees (not passed/failed)
        // 2. Trainees who passed but haven't submitted feedback yet (pending feedback)
        $q .= " AND e.enrollment_status='approved' AND (
                    (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                    OR 
                    ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = p.id))
                )";
    } elseif ($filter === 'certified') {
        // Only show as certified if they have feedback AND are passed/certified
        $q .= " AND (e.assessment='Passed' OR e.enrollment_status='certified')";
        $q .= " AND EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = p.id)";
    } elseif ($filter === 'failed') {
        $q .= " AND e.assessment='Failed' AND e.enrollment_status!='rejected'";
    } elseif ($filter === 'dropout') {
        $q .= " AND e.enrollment_status='rejected'";
    } else {
        // Default to ongoing (same as above)
        $q .= " AND e.enrollment_status='approved' AND (
                    (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                    OR 
                    ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = p.id))
                )";
    }
    
    $q .= " ORDER BY t.fullname ASC";
    
    $s = $conn->prepare($q); 
    $s->bind_param("ssi", $today, $today, $program_id); 
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($rows as &$r) {
        $r['total_days'] = calculateProgramDuration($r['scheduleStart'], $r['scheduleEnd'], $r['duration'], $r['durationUnit']);
        
        // Override assessment/status if no feedback but marked as passed
        if (($r['assessment'] === 'Passed' || $r['enrollment_status'] === 'certified') && !$r['has_feedback']) {
            $r['assessment_display'] = 'Pending Feedback';
            $r['assessment_class'] = 'assessment-pending';
            $r['status_display'] = 'ongoing'; // Changed from 'pending_feedback' to 'ongoing'
            $r['needs_feedback'] = true;
        } else {
            $r['assessment_display'] = $r['assessment'] ?? 'Not yet graded';
            $r['status_display'] = $r['enrollment_status'] === 'rejected' ? 'dropout' : 
                                  (($r['assessment'] === 'Passed' || $r['enrollment_status'] === 'certified') ? 'certified' : 
                                  ($r['assessment'] === 'Failed' ? 'failed' : 'ongoing'));
        }
    }
    return $rows;
}

function recalculateAttendancePercentage($conn, $eid) {
    $s=$conn->prepare("SELECT e.*,p.scheduleStart,p.scheduleEnd,p.duration,p.durationUnit FROM enrollments e JOIN programs p ON e.program_id=p.id WHERE e.id=?");
    $s->bind_param("i",$eid); $s->execute();
    $e=$s->get_result()->fetch_assoc();
    if (!$e) return false;
    $total=calculateProgramDuration($e['scheduleStart'],$e['scheduleEnd'],$e['duration'],$e['durationUnit']);
    $as=$conn->prepare("SELECT COUNT(*) c FROM attendance_records WHERE enrollment_id=? AND status='present'");
    $as->bind_param("i",$eid); $as->execute();
    $attended=$as->get_result()->fetch_assoc()['c']??0;
    $pct=$total>0?min(100,($attended/$total)*100):0;
    $u=$conn->prepare("UPDATE enrollments SET attendance=? WHERE id=?");
    $u->bind_param("di",$pct,$eid); $u->execute();
    return $pct;
}

/**
 * Mark attendance for ONE enrollment on ONE date.
 * Returns false (and does NOT insert) if a record for that date already exists.
 */
function markDailyAttendance($conn, $eid, $date, $status, $marked_by) {
    // Guard: ineligible statuses
    $g=$conn->prepare("SELECT assessment,enrollment_status FROM enrollments WHERE id=?");
    $g->bind_param("i",$eid); $g->execute();
    $e=$g->get_result()->fetch_assoc();
    if (!$e) return false;
    if (in_array($e['enrollment_status'],['certified','rejected']) || in_array($e['assessment'],['Passed','Failed'])) return false;

    // Guard: already marked today — NEVER overwrite
    $ck=$conn->prepare("SELECT id FROM attendance_records WHERE enrollment_id=? AND attendance_date=? LIMIT 1");
    $ck->bind_param("is",$eid,$date); $ck->execute();
    if ($ck->get_result()->num_rows>0) return false; // already marked, skip silently

    // Insert
    $ins=$conn->prepare("INSERT INTO attendance_records (enrollment_id,attendance_date,status,marked_by,created_at) VALUES (?,?,?,?,NOW())");
    $ins->bind_param("isss",$eid,$date,$status,$marked_by);
    $ok=$ins->execute();
    if ($ok) recalculateAttendancePercentage($conn,$eid);
    return $ok;
}

function markAllTraineesAttendanceToday($conn, $program_id, $status, $marked_by, $schedStart, $schedEnd) {
    $today=date('Y-m-d');
    if (!isTodayWithinProgramSchedule($schedStart,$schedEnd)) return ['success_count'=>0,'error_count'=>0,'message'=>'Outside program schedule'];
    if (!hasProgramStarted($schedStart))                      return ['success_count'=>0,'error_count'=>0,'message'=>'Program has not started yet'];

    // Unmarked ongoing only (including pending feedback)
    $s=$conn->prepare("SELECT e.id as eid FROM enrollments e LEFT JOIN attendance_records ar ON e.id=ar.enrollment_id AND ar.attendance_date=? 
                       WHERE e.program_id=? AND e.enrollment_status='approved' 
                       AND (
                           (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                           OR 
                           ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = e.program_id))
                       ) 
                       AND ar.id IS NULL");
    $s->bind_param("si",$today,$program_id); $s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);

    // Count already-marked ongoing
    $sk=$conn->prepare("SELECT COUNT(*) c FROM enrollments e JOIN attendance_records ar ON e.id=ar.enrollment_id 
                        WHERE e.program_id=? AND e.enrollment_status='approved' 
                        AND (
                            (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                            OR 
                            ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = e.user_id AND f.program_id = e.program_id))
                        ) 
                        AND ar.attendance_date=?");
    $sk->bind_param("is",$program_id,$today); $sk->execute();
    $skipped=$sk->get_result()->fetch_assoc()['c']??0;

    $ok=0; $err=0;
    foreach ($rows as $r) { if (markDailyAttendance($conn,$r['eid'],$today,$status,$marked_by)) $ok++; else $err++; }
    return ['success_count'=>$ok,'error_count'=>$err,'skipped_count'=>$skipped,'total_unmarked'=>count($rows)];
}

// MODIFIED: Updated to check for feedback before marking as Passed
function updateTraineeAssessment($conn, $eid, $assessment, $failure_notes=null) {
    $s=$conn->prepare("SELECT user_id, program_id FROM enrollments WHERE id=?");
    $s->bind_param("i",$eid); $s->execute();
    $e=$s->get_result()->fetch_assoc(); 
    if (!$e) return false;
    
    $uid=$e['user_id'];
    $pid=$e['program_id'];
    
    // Check if user has feedback when trying to mark as Passed/Certified
    if ($assessment === 'Passed') {
        $hasFeedback = hasUserFeedback($conn, $uid, $pid);
        if (!$hasFeedback) {
            error_log("Cannot mark as Passed/Certified: User $uid has no feedback for program $pid");
            return false;
        }
    }
    
    $estat=($assessment==='Passed')?'certified':'approved';
    $conn->begin_transaction();
    try {
        if ($failure_notes) { 
            $u=$conn->prepare("UPDATE enrollments SET assessment=?,enrollment_status=?,failure_notes=? WHERE id=?"); 
            $u->bind_param("sssi",$assessment,$estat,$failure_notes,$eid); 
        }
        else { 
            $u=$conn->prepare("UPDATE enrollments SET assessment=?,enrollment_status=?,failure_notes=NULL WHERE id=?"); 
            $u->bind_param("ssi",$assessment,$estat,$eid); 
        }
        $u->execute();
        
        if ($assessment==='Failed' && $failure_notes) {
            $ck=$conn->prepare("SELECT failure_notes_copy FROM trainees WHERE user_id=?");
            $ck->bind_param("s",$uid); $ck->execute();
            $tr=$ck->get_result()->fetch_assoc();
            $fn="=== [".date('F j, Y')."] ===\n".$failure_notes;
            $nn=($tr&&!empty($tr['failure_notes_copy']))?$tr['failure_notes_copy']."\n\n".$fn:$fn;
            $uu=$conn->prepare("UPDATE trainees SET failure_notes_copy=? WHERE user_id=?");
            $uu->bind_param("ss",$nn,$uid); $uu->execute();
        }
        $conn->commit(); 
        return true;
    } catch (Exception $ex) { 
        $conn->rollback(); 
        error_log($ex->getMessage()); 
        return false; 
    }
}

// MODIFIED: Updated to check feedback before bulk marking as passed
function markAllTraineesPassed($conn, $program_id, $marked_by) {
    $s = $conn->prepare("SELECT e.id as eid, t.user_id FROM enrollments e 
                         JOIN trainees t ON e.user_id = t.user_id 
                         WHERE e.program_id=? AND e.enrollment_status='approved' 
                         AND (
                             (e.assessment IS NULL OR e.assessment='' OR e.assessment='Not yet graded' OR e.assessment='Pending' OR e.assessment NOT IN ('Passed','Failed'))
                             OR 
                             ((e.assessment='Passed' OR e.enrollment_status='certified') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = e.program_id))
                         )");
    $s->bind_param("i", $program_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $ok = 0;
    $err = 0;
    $skipped = 0;
    
    foreach ($rows as $r) { 
        // Check if user has feedback before marking as passed
        if (hasUserFeedback($conn, $r['user_id'], $program_id)) {
            if (updateTraineeAssessment($conn, $r['eid'], 'Passed', null)) {
                $ok++; 
            } else {
                $err++;
            }
        } else {
            $skipped++;
        }
    }
    
    return [
        'success_count' => $ok, 
        'error_count' => $err,
        'skipped_count' => $skipped
    ];
}

function markTraineeAsDropout($conn, $eid, $reason, $marked_by) {
    $conn->begin_transaction();
    try {
        $u=$conn->prepare("UPDATE enrollments SET enrollment_status='rejected',failure_notes=?,assessment='Failed' WHERE id=?");
        $u->bind_param("si",$reason,$eid); $u->execute();
        $g=$conn->prepare("SELECT user_id FROM enrollments WHERE id=?"); $g->bind_param("i",$eid); $g->execute();
        $e=$g->get_result()->fetch_assoc();
        if ($e) {
            $uid=$e['user_id'];
            $fn="=== [DROPOUT - ".date('F j, Y')."] ===\nReason: ".$reason."\nMarked by: ".$marked_by;
            $ck=$conn->prepare("SELECT failure_notes_copy FROM trainees WHERE user_id=?"); $ck->bind_param("s",$uid); $ck->execute();
            $tr=$ck->get_result()->fetch_assoc();
            $nn=($tr&&!empty($tr['failure_notes_copy']))?$tr['failure_notes_copy']."\n\n".$fn:$fn;
            $uu=$conn->prepare("UPDATE trainees SET failure_notes_copy=? WHERE user_id=?"); $uu->bind_param("ss",$nn,$uid); $uu->execute();
        }
        $conn->commit(); return true;
    } catch (Exception $ex) { $conn->rollback(); error_log($ex->getMessage()); return false; }
}

// MODIFIED: Updated to include feedback info
function getTraineeDetails($conn, $uid, $pid) {
    $s=$conn->prepare("SELECT e.id as enrollment_id,e.applied_at,e.enrollment_status,e.attendance,e.assessment,e.failure_notes,
                      t.*,p.name as program_name,p.scheduleStart,p.scheduleEnd,p.duration,p.durationUnit,
                      (SELECT COUNT(*) > 0 FROM feedback f WHERE f.user_id = t.user_id AND f.program_id = p.id) as has_feedback
                      FROM enrollments e 
                      JOIN trainees t ON e.user_id=t.user_id 
                      JOIN programs p ON e.program_id=p.id 
                      WHERE t.user_id=? AND e.program_id=?");
    $s->bind_param("si",$uid,$pid); $s->execute();
    return $s->get_result()->fetch_assoc();
}

// ============================================================
// AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action=$_POST['action']??'';

    if ($action==='mark_daily_attendance') {
        $eid   = intval($_POST['enrollment_id']);
        $status= trim($_POST['status']);
        $today = date('Y-m-d');

        $g=$conn->prepare("SELECT e.assessment,e.enrollment_status,p.scheduleStart,p.scheduleEnd FROM enrollments e JOIN programs p ON e.program_id=p.id WHERE e.id=?");
        $g->bind_param("i",$eid); $g->execute();
        $e=$g->get_result()->fetch_assoc();
        if (!$e)                                          { echo json_encode(['success'=>false,'message'=>'Enrollment not found']); exit; }
        if (in_array($e['enrollment_status'],['certified','rejected'])||$e['assessment']==='Passed') { echo json_encode(['success'=>false,'message'=>'Trainee not eligible for attendance.']); exit; }
        if ($e['assessment']==='Failed')                  { echo json_encode(['success'=>false,'message'=>'Trainee has failed.']); exit; }
        if (!hasProgramStarted($e['scheduleStart']))      { echo json_encode(['success'=>false,'message'=>'Program has not started yet.']); exit; }
        if (!isTodayWithinProgramSchedule($e['scheduleStart'],$e['scheduleEnd'])) { echo json_encode(['success'=>false,'message'=>'Today is outside program schedule.']); exit; }
        if (hasProgramEnded($e['scheduleEnd']))           { echo json_encode(['success'=>false,'message'=>'Program has already ended.']); exit; }

        // Check if already marked — return special flag
        $ck=$conn->prepare("SELECT id,status FROM attendance_records WHERE enrollment_id=? AND attendance_date=? LIMIT 1");
        $ck->bind_param("is",$eid,$today); $ck->execute();
        $existing=$ck->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['success'=>false,'already_marked'=>true,'current_status'=>$existing['status'],'message'=>'Already marked as '.ucfirst($existing['status']).' for today. Cannot change until tomorrow.']);
            exit;
        }

        $ok=markDailyAttendance($conn,$eid,$today,$status,$fullname);
        echo json_encode(['success'=>$ok,'message'=>$ok?'Attendance marked':'Failed to mark attendance']);
        exit;
    }

    if ($action==='mark_all_present_today'||$action==='mark_all_absent_today') {
        $pid   = intval($_POST['program_id']);
        $status= ($action==='mark_all_present_today')?'present':'absent';
        $pg=$conn->prepare("SELECT scheduleStart,scheduleEnd FROM programs WHERE id=?"); $pg->bind_param("i",$pid); $pg->execute();
        $prog=$pg->get_result()->fetch_assoc();
        if (!$prog) { echo json_encode(['success'=>false,'message'=>'Program not found']); exit; }
        $r=markAllTraineesAttendanceToday($conn,$pid,$status,$fullname,$prog['scheduleStart'],$prog['scheduleEnd']);
        if (isset($r['message'])) { echo json_encode(['success'=>false,'message'=>$r['message']]); exit; }
        echo json_encode(['success'=>true,'success_count'=>$r['success_count'],'error_count'=>$r['error_count'],'skipped_count'=>$r['skipped_count']??0,'total_unmarked'=>$r['total_unmarked']??0]);
        exit;
    }

    if ($action==='get_trainee_details') {
        $uid=trim($_POST['trainee_user_id']); $pid=intval($_POST['program_id']);
        $t=getTraineeDetails($conn,$uid,$pid);
        if ($t) {
            $as=$conn->prepare("SELECT COUNT(*) total,SUM(status='present') present_days,SUM(status='absent') absent_days FROM attendance_records WHERE enrollment_id=?");
            $as->bind_param("i",$t['enrollment_id']); $as->execute();
            $t['attendance_stats']=$as->get_result()->fetch_assoc();
        }
        echo json_encode(['success'=>true,'trainee'=>$t]); exit;
    }

    if ($action==='update_assessment') {
        $eid=intval($_POST['enrollment_id']); $ass=trim($_POST['assessment']); $fn=trim($_POST['failure_notes']??'');
        if (!in_array($ass,['Passed','Failed'])) { echo json_encode(['success'=>false,'message'=>'Invalid assessment']); exit; }
        $ok=updateTraineeAssessment($conn,$eid,$ass,$fn?:null);
        echo json_encode(['success'=>$ok,'message'=>$ok?'Updated!':'Failed to update.']); exit;
    }

    if ($action==='mark_all_passed') {
        $pid=intval($_POST['program_id']);
        $r=markAllTraineesPassed($conn,$pid,$fullname);
        echo json_encode(['success'=>true,'success_count'=>$r['success_count'],'error_count'=>$r['error_count'],'skipped_count'=>$r['skipped_count']]); exit;
    }

    if ($action==='mark_as_dropout') {
        $eid=intval($_POST['enrollment_id']); $reason=trim($_POST['dropout_reason']??'');
        if (strlen($reason)<10) { echo json_encode(['success'=>false,'message'=>'Reason too short (min 10 chars)']); exit; }
        $ok=markTraineeAsDropout($conn,$eid,$reason,$fullname);
        echo json_encode(['success'=>$ok,'message'=>$ok?'Marked as dropout':'Failed']); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ============================================================
// PAGE DATA
// ============================================================
$trainer_program = getTrainerProgram($conn, $fullname);
$program_id      = $trainer_program['id'] ?? 0;
$current_filter  = $_GET['filter'] ?? 'ongoing';
$trainees        = $program_id>0 ? getTraineesByTrainerProgram($conn,$program_id,$current_filter) : [];
$trainee_counts  = $program_id>0 ? getTraineeCountsByStatus($conn,$program_id) : ['total'=>0,'ongoing'=>0,'certified'=>0,'failed'=>0,'dropout'=>0,'pending_feedback'=>0];

$program_started=$program_ended=$within_schedule=false;
$current_program_day=$total_program_days=0;
if ($trainer_program) {
    $program_started     = hasProgramStarted($trainer_program['scheduleStart']);
    $program_ended       = hasProgramEnded($trainer_program['scheduleEnd']);
    $within_schedule     = isTodayWithinProgramSchedule($trainer_program['scheduleStart'],$trainer_program['scheduleEnd']);
    $current_program_day = getCurrentProgramDay($trainer_program['scheduleStart']);
    $total_program_days  = calculateProgramDuration($trainer_program['scheduleStart'],$trainer_program['scheduleEnd'],$trainer_program['duration'],$trainer_program['durationUnit']);
    $current_program_day = min($current_program_day,$total_program_days);
}

$present_count=$absent_count=$unmarked_count=0;
foreach ($trainees as $tr) {
    if ($tr['today_attendance_status']==='present')     $present_count++;
    elseif ($tr['today_attendance_status']==='absent')  $absent_count++;
    else                                                 $unmarked_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Participant Records - Trainer Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',sans-serif;background:#f9fafb;color:#333;line-height:1.6;}
.header{background:#344152;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:60px;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.header-left{display:flex;align-items:center;gap:12px;}
.logo{width:40px;height:40px;}
.system-name{font-weight:600;font-size:18px;}
.profile-container{position:relative;}
.profile-btn{background:none;border:none;color:#fff;display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 12px;border-radius:6px;transition:.2s;}
.profile-btn:hover{background:rgba(255,255,255,.1);}
.profile-dropdown{display:none;position:absolute;right:0;top:100%;margin-top:8px;width:192px;background:#fff;color:#000;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:9999;overflow:hidden;}
.profile-dropdown.show{display:block !important;}
.dropdown-item{display:block;padding:12px 16px;text-decoration:none;color:#333;transition:.2s;border:none;background:none;width:100%;text-align:left;cursor:pointer;font-size:14px;}
.dropdown-item:hover{background:#f3f4f6;}
.logout-btn{color:#dc2626;}
.logout-btn:hover{background:#fee2e2;}
.main-container{display:flex;min-height:calc(100vh - 60px);}
.sidebar{width:256px;background:#344152;min-height:calc(100vh - 60px);padding:16px;}
.sidebar-btn{width:100%;padding:12px 16px;border-radius:8px;margin-bottom:8px;text-align:left;font-weight:600;transition:.2s;border:none;cursor:pointer;color:#fff;background:#344152;display:flex;align-items:center;gap:10px;text-decoration:none;}
.sidebar-btn:hover{background:#3d4d62;}
.sidebar-btn.active{background:#4a5568;}
.main-content{flex:1;padding:32px;background:#f9fafb;}
.page-header{background:#fff;padding:20px 24px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:24px;}
.page-title{font-size:28px;font-weight:700;color:#1a1a1a;margin:0;}
.status-banner{padding:15px 24px;border-radius:12px;margin-bottom:24px;text-align:center;font-weight:600;font-size:16px;box-shadow:0 4px 6px rgba(0,0,0,.1);display:flex;align-items:center;justify-content:center;gap:10px;}
.status-banner.not-started{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;}
.status-banner.in-progress{background:linear-gradient(135deg,#10b981,#059669);color:#fff;}
.status-banner.ended{background:linear-gradient(135deg,#6b7280,#4b5563);color:#fff;}
.status-banner.outside-schedule{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;}
.today-banner{background:linear-gradient(135deg,#4A90E2,#357ABD);color:#fff;padding:15px 24px;border-radius:12px;margin-bottom:24px;text-align:center;font-weight:600;font-size:18px;box-shadow:0 4px 6px rgba(0,0,0,.1);display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;}
.filter-container{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px 24px;margin-bottom:24px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;}
.filter-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.filter-label{font-weight:600;color:#555;font-size:14px;}
.filter-btn{padding:10px 20px;border:2px solid #e0e0e0;background:#fff;border-radius:8px;cursor:pointer;font-weight:500;font-size:14px;color:#666;transition:.3s;}
.filter-btn:hover{border-color:#4A90E2;color:#4A90E2;}
.filter-btn.active{background:#4A90E2;color:#fff;border-color:#4A90E2;}
.table-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
.table-header{background:#4A90E2;color:#fff;padding:16px 24px;font-size:18px;font-weight:600;}
.table-container{overflow-x:auto;max-height:600px;}
table{width:100%;border-collapse:collapse;}
thead{background:#f8f9fa;position:sticky;top:0;z-index:10;}
th{padding:14px 16px;text-align:left;font-weight:600;color:#333;font-size:13px;text-transform:uppercase;border-bottom:2px solid #e0e0e0;}
td{padding:14px 16px;border-bottom:1px solid #f0f0f0;font-size:14px;color:#555;}
tbody tr:hover{background:#f8f9fa;}
.status-badge{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;display:inline-block;}
.status-ongoing{background:#fff3cd;color:#856404;}
.status-certified{background:#d4edda;color:#155724;}
.status-failed{background:#f8d7da;color:#721c24;}
.status-dropout{background:#8b5d5d;color:#fff;}
.assessment-badge{padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;}
.assessment-not-graded{background:#e2e8f0;color:#4a5568;}
.assessment-passed{background:#d4edda;color:#155724;}
.assessment-failed{background:#f8d7da;color:#721c24;}
.assessment-pending{background:#fff3cd;color:#856404;}

/* TODAY'S STATUS badge */
.att-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;}
.att-badge.present{background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;}
.att-badge.absent{background:#fee2e2;color:#7f1d1d;border:1.5px solid #fca5a5;}
.att-badge.unmarked{background:#f3f4f6;color:#6b7280;border:1.5px solid #d1d5db;}

.progress-bar{width:100%;height:8px;background:#e0e0e0;border-radius:10px;overflow:hidden;}
.progress-fill{height:100%;background:linear-gradient(90deg,#4A90E2,#357ABD);border-radius:10px;transition:width .3s;}
.progress-text{font-size:12px;color:#666;margin-top:4px;font-weight:500;}
.btn{padding:7px 14px;border:none;border-radius:6px;cursor:pointer;font-weight:500;display:inline-flex;align-items:center;gap:5px;transition:.2s;text-decoration:none;font-size:13px;}
.btn-primary{background:#4A90E2;color:#fff;} .btn-primary:hover{background:#357ABD;}
.btn-success{background:#28a745;color:#fff;} .btn-success:hover{background:#218838;}
.btn-danger{background:#dc3545;color:#fff;}  .btn-danger:hover{background:#c82333;}
.btn-warning{background:#ffc107;color:#212529;} .btn-warning:hover{background:#e0a800;}
.btn-outline{background:transparent;border:1px solid #4A90E2;color:#4A90E2;} .btn-outline:hover{background:#4A90E2;color:#fff;}
.btn:disabled,.btn.disabled{opacity:.45;cursor:not-allowed !important;pointer-events:none;}

/* ATTENDANCE ACTION BUTTONS */
.attendance-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}

.att-btn{
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 13px;border:none;border-radius:5px;
    font-size:12px;font-weight:700;cursor:pointer;
    transition:all .18s;
}

/* Default (unmarked) states */
.att-btn.present{background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;}
.att-btn.absent {background:#fee2e2;color:#7f1d1d;border:1.5px solid #fca5a5;}
.att-btn.present:hover:not(:disabled):not(.locked){background:#a7f3d0;}
.att-btn.absent:hover:not(:disabled):not(.locked) {background:#fecaca;}

/* ACTIVE = recorded status */
.att-btn.present.active{background:#059669;color:#fff;border-color:#047857;}
.att-btn.absent.active {background:#dc2626;color:#fff;border-color:#b91c1c;}

/* LOCKED - attendance already saved for today */
.att-btn.locked {
    pointer-events: none !important;
    cursor: default !important;
    opacity: 0.65;
}

.att-btn.locked:not(.active) {
    opacity: 0.35;
    filter: grayscale(80%);
}

.att-btn.locked.active {
    opacity: 1;
    filter: none;
    box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.3);
}

/* Disabled buttons shouldn't show pointer */
.att-btn:disabled {
    cursor: not-allowed !important;
    opacity: 0.65;
}

/* Label shown below buttons after locking */
.lock-label {
    display: block;
    font-size: 10.5px;
    color: #9ca3af;
    margin-top: 6px;
    font-style: italic;
    line-height: 1.3;
    background: #f3f4f6;
    padding: 2px 8px;
    border-radius: 4px;
    text-align: center;
}

.lock-label i {
    margin-right: 3px;
    font-size: 9px;
}

.attendance-summary{display:flex;gap:15px;margin-top:10px;flex-wrap:wrap;}
.summary-item{padding:10px 15px;border-radius:8px;background:#f8f9fa;min-width:120px;text-align:center;}
.summary-label{font-size:12px;color:#6b7280;margin-bottom:4px;}
.summary-value{font-size:18px;font-weight:700;color:#1f2937;}

.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px;}
.modal-content{background:#fff;padding:30px;border-radius:12px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto;}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #e0e0e0;}
.modal-header h3{color:#1a1a1a;font-size:20px;margin:0;}
.close-modal{background:none;border:none;font-size:24px;cursor:pointer;color:#999;}
.detail-row{margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f0f0f0;}
.detail-row:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.detail-label{font-weight:600;color:#555;margin-bottom:4px;font-size:14px;}
.detail-value{color:#333;font-size:15px;}
.empty-state{text-align:center;padding:60px 20px;color:#999;}
.empty-state i{font-size:48px;margin-bottom:16px;color:#ddd;}
@media(max-width:768px){.main-container{flex-direction:column;}.sidebar{width:100%;min-height:auto;display:flex;overflow-x:auto;}.sidebar-btn{flex:1;margin-bottom:0;margin-right:8px;}.main-content{padding:16px;}}

/* Bulk Assessment Button Styles */
.btn-bulk {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-size: 14px;
}
.btn-bulk:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102,126,234,0.4);
}
.btn-bulk i {
    font-size: 16px;
}

/* Feedback Required Badge */
.feedback-required {
    background: #cff3ff;
    color: #0369a1;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 5px;
}
</style>
</head>
<body>

<header class="header">
  <div class="header-left">
    <img src="/css/logo.png" alt="Logo" class="logo">
    <span class="system-name">Livelihood Enrollments &amp; Monitoring System</span>
  </div>
  <div style="display:flex;align-items:center;gap:24px;">
    <div class="profile-container">
      <button class="profile-btn" id="profileBtn">
        <i class="fas fa-user-circle"></i>
        <span><?= htmlspecialchars($fullname) ?></span>
        <i class="fas fa-chevron-down"></i>
      </button>
      <div class="profile-dropdown" id="profileDropdown">
        <a href="trainer.php" class="dropdown-item"><i class="fas fa-user"></i> View Profile</a>
        <button class="dropdown-item logout-btn" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</button>
      </div>
    </div>
  </div>
</header>

<div class="main-container">
  <aside class="sidebar">
    <a href="/trainer/dashboard" class="sidebar-btn"><i class="fas fa-home"></i> Dashboard</a>
    <a href="/trainer/trainer_participants"  class="sidebar-btn active"><i class="fas fa-users"></i> Trainer Participants</a>
  </aside>

  <main class="main-content">

    <?php if ($trainer_program): ?>
      <?php if (!$program_started): ?>
        <div class="status-banner not-started"><i class="fas fa-clock"></i>
          <span>Program starts on <?= (new DateTime($trainer_program['scheduleStart']))->format('F j, Y') ?></span></div>
      <?php elseif ($program_ended): ?>
        <div class="status-banner ended"><i class="fas fa-calendar-times"></i>
          <span>Program ended on <?= (new DateTime($trainer_program['scheduleEnd']))->format('F j, Y') ?></span></div>
      <?php elseif (!$within_schedule): ?>
        <div class="status-banner outside-schedule"><i class="fas fa-calendar-day"></i>
          <span>Today is outside program schedule (<?= (new DateTime($trainer_program['scheduleStart']))->format('M j').' – '.(new DateTime($trainer_program['scheduleEnd']))->format('M j, Y') ?>)</span></div>
      <?php else: ?>
        <div class="status-banner in-progress"><i class="fas fa-calendar-check"></i>
          <span>Program in progress: Day <?= $current_program_day ?> of <?= $total_program_days ?></span></div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="today-banner">
      <i class="fas fa-calendar-day"></i>
      <span>Today: <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-header">
      <h1 class="page-title"><i class="fas fa-users"></i> Trainer Records</h1>
      <?php if ($trainer_program): ?>
      <div class="attendance-summary">
        <div class="summary-item"><div class="summary-label">Program</div><div class="summary-value"><?= htmlspecialchars($trainer_program['name']) ?></div></div>
        <div class="summary-item"><div class="summary-label">Schedule</div><div class="summary-value"><?= (new DateTime($trainer_program['scheduleStart']))->format('M d').' – '.(new DateTime($trainer_program['scheduleEnd']))->format('M d, Y') ?></div></div>

      </div>
      <?php endif; ?>
    </div>

    <div class="filter-container">
      <div class="filter-group">
        <span class="filter-label">Status:</span>
        <button class="filter-btn <?= $current_filter==='ongoing'   ?'active':'' ?>" onclick="changeFilter('ongoing')"><i class="fas fa-circle"></i> Ongoing (<?= $trainee_counts['ongoing'] ?>)</button>
        <button class="filter-btn <?= $current_filter==='certified' ?'active':'' ?>" onclick="changeFilter('certified')"><i class="fas fa-certificate"></i> Certified (<?= $trainee_counts['certified'] ?>)</button>
        <button class="filter-btn <?= $current_filter==='failed'    ?'active':'' ?>" onclick="changeFilter('failed')"><i class="fas fa-times-circle"></i> Failed (<?= $trainee_counts['failed'] ?>)</button>
        <button class="filter-btn <?= $current_filter==='dropout'   ?'active':'' ?>" onclick="changeFilter('dropout')"><i class="fas fa-user-times"></i> Dropout (<?= $trainee_counts['dropout'] ?>)</button>
      </div>
      <div class="filter-group">
        <span class="filter-label">Quick Actions:</span>
        <button class="btn btn-success" onclick="bulkAttendance('present')" <?= (!$program_started||$program_ended||!$within_schedule)?'disabled':'' ?>>
          <i class="fas fa-check-circle"></i> Mark All Present</button>
        <button class="btn btn-danger"  onclick="bulkAttendance('absent')"  <?= (!$program_started||$program_ended||!$within_schedule)?'disabled':'' ?>>
          <i class="fas fa-times-circle"></i> Mark All Absent</button>
        
        <!-- UPDATED BUTTON: Bulk Assessment (no auto-mark) -->
        <a href="bulk_comprehensive_assessment.php?program_id=<?= $program_id ?>&tab=practical" class="btn-bulk">
          <i class="fas fa-users-cog"></i> Bulk Comprehensive Assessment
        </a>
      </div>
    </div>

    <div class="table-card">
      <div class="table-header">
        <i class="fas fa-table"></i> Real-time Attendance — <?= date('M d, Y') ?>
        <?php if ($trainer_program): ?><span style="font-size:14px;font-weight:400;margin-left:8px;">Program: <?= htmlspecialchars($trainer_program['name']) ?></span><?php endif; ?>
      </div>
      <div class="table-container">
      <?php if (count($trainees)>0): ?>
        <table>
          <thead>
            <tr>
              <th>Name</th><th>Program</th><th>Attendance %</th><th>Days</th>
              <th>Assessment</th><th>Status</th><th>Today's Status</th><th>Mark Attendance</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($trainees as $tr):
            $eid        = $tr['enrollment_id'];
            $days_att   = $tr['days_attended']??0;
            $total_d    = $tr['total_days']??1;
            $pct        = $tr['attendance']??0;

            // Determine status and assessment display
            if (isset($tr['needs_feedback']) && $tr['needs_feedback']) {
                $st = 'ongoing'; // Changed from 'pending-feedback' to 'ongoing'
                $ass_display = 'Pending Feedback';
                $ass_cls = 'assessment-pending';
                $is_cert = false;
                $is_drop = false;
                $is_failed = false;
                $status_display_text = 'Ongoing (Pending Feedback)';
            } else {
                $is_drop = ($tr['enrollment_status'] === 'rejected');
                $is_cert = ($tr['assessment'] === 'Passed' || $tr['enrollment_status'] === 'certified');
                $is_failed = ($tr['assessment'] === 'Failed');
                
                if ($is_drop) {
                    $st = 'dropout';
                    $status_display_text = 'Dropout';
                } elseif ($is_cert) {
                    $st = 'certified';
                    $status_display_text = 'Certified';
                } elseif ($is_failed) {
                    $st = 'failed';
                    $status_display_text = 'Failed';
                } else {
                    $st = 'ongoing';
                    $status_display_text = 'Ongoing';
                }
                
                $ass_display = $tr['assessment'] ?? 'Not yet graded';
                
                if ($ass_display === 'Passed' || $tr['enrollment_status'] === 'certified') $ass_cls = 'assessment-passed';
                elseif ($ass_display === 'Failed') $ass_cls = 'assessment-failed';
                elseif ($ass_display === 'Pending') $ass_cls = 'assessment-pending';
                else $ass_cls = 'assessment-not-graded';
            }

            $today_att  = $tr['today_attendance_status']??null;
            $is_marked  = ($today_att==='present' || $today_att==='absent');
            $att_disp = $today_att ?? 'unmarked';

            // Can attendance be marked for this trainee at all?
            $can_mark = $program_started && $within_schedule && !$program_ended
                        && !$is_cert && !$is_drop && !$is_failed;
          ?>
          <tr id="row-<?= $eid ?>"
              data-eid="<?= $eid ?>"
              data-certified="<?= $is_cert?'true':'false' ?>"
              data-dropout="<?= $is_drop?'true':'false' ?>"
              data-failed="<?= $is_failed?'true':'false' ?>"
              data-pending-feedback="<?= isset($tr['needs_feedback'])?'true':'false' ?>"
              data-assessment="<?= htmlspecialchars($ass_display) ?>"
              data-estatus="<?= htmlspecialchars($tr['enrollment_status']) ?>"
              data-marked="<?= $is_marked?'true':'false' ?>"
              data-att-status="<?= $att_disp ?>">

            <td>
              <strong><?= htmlspecialchars($tr['fullname']) ?></strong>
              <?php if (isset($tr['needs_feedback']) && $tr['needs_feedback']): ?>
                <span class="feedback-required"><i class="fas fa-exclamation-circle"></i> Feedback Required</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($tr['program_name']) ?></td>
            <td>
              <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
              <div class="progress-text"><?= round($pct,1) ?>%</div>
            </td>
            <td><?= $days_att ?> / <?= $total_d ?></td>
            <td>
              <span class="assessment-badge <?= $ass_cls ?>" id="ass-<?= $eid ?>"><?= htmlspecialchars($ass_display) ?></span>
              <?php if ($tr['failure_notes']): ?>
                <div style="font-size:11px;color:#9ca3af;margin-top:3px;cursor:pointer;" onclick="showNotes('<?= htmlspecialchars(addslashes($tr['failure_notes'])) ?>')">
                  <i class="fas fa-sticky-note"></i> Notes
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="status-badge status-<?= $st ?>" id="stbadge-<?= $eid ?>">
                <?= $status_display_text ?>
              </span>
            </td>

            <!-- TODAY'S STATUS -->
            <td>
              <span class="att-badge <?= $att_disp ?>" id="attbadge-<?= $eid ?>">
                <?php if ($today_att==='present'): ?>
                  <i class="fas fa-check-circle"></i> Present
                <?php elseif ($today_att==='absent'): ?>
                  <i class="fas fa-times-circle"></i> Absent
                <?php else: ?>
                  <i class="fas fa-clock"></i> Unmarked
                <?php endif; ?>
              </span>
              <?php if ($is_marked && !empty($tr['today_marked_by'])): ?>
                <div style="font-size:10px;color:#9ca3af;margin-top:3px;">
                  <i class="fas fa-user-check"></i> <?= htmlspecialchars($tr['today_marked_by']) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- MARK ATTENDANCE -->
            <td id="attcell-<?= $eid ?>">
              <?php if ($can_mark): ?>
                <div class="attendance-actions" id="actions-<?= $eid ?>">
                  <!-- Present button -->
                  <button
                    class="att-btn present <?= $is_marked ? 'locked' : ''; ?> <?= ($today_att === 'present') ? 'active' : '' ?>"
                    <?= $is_marked ? 'disabled' : '' ?>
                    onclick="<?= $is_marked ? '' : "markAtt($eid, 'present')" ?>"
                    title="<?= $is_marked ? '🔒 Already marked for today' : 'Mark as Present' ?>">
                    <i class="fas fa-check"></i> Present
                  </button>

                  <!-- Absent button -->
                  <button
                    class="att-btn absent <?= $is_marked ? 'locked' : ''; ?> <?= ($today_att === 'absent') ? 'active' : '' ?>"
                    <?= $is_marked ? 'disabled' : '' ?>
                    onclick="<?= $is_marked ? '' : "markAtt($eid, 'absent')" ?>"
                    title="<?= $is_marked ? '🔒 Already marked for today' : 'Mark as Absent' ?>">
                    <i class="fas fa-times"></i> Absent
                  </button>
                </div>

                <?php if ($is_marked): ?>
                  <span class="lock-label">
                    <i class="fas fa-lock"></i> 
                    Marked as <?= ucfirst($today_att) ?>
                  </span>
                <?php endif; ?>

              <?php else: ?>
                <div style="font-size:11px;color:#9ca3af;">
                  <?php if (isset($tr['needs_feedback']) && $tr['needs_feedback']): ?>
                    <i class="fas fa-clock"></i> Awaiting Feedback
                  <?php elseif ($is_cert):   ?>
                    <i class="fas fa-certificate"></i> Certified
                  <?php elseif ($is_drop):     ?>
                    <i class="fas fa-user-times"></i> Dropout
                  <?php elseif ($is_failed): ?>
                    <i class="fas fa-times-circle"></i> Failed
                  <?php elseif (!$program_started): ?>
                    <i class="fas fa-clock"></i> Not started
                  <?php elseif ($program_ended):    ?>
                    <i class="fas fa-calendar-times"></i> Ended
                  <?php elseif (!$within_schedule): ?>
                    <i class="fas fa-calendar-day"></i> Off-schedule
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- ACTIONS -->
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <button class="btn btn-outline" onclick="viewDetails('<?= $tr['user_id'] ?>',<?= $program_id ?>)">
                  <i class="fas fa-info-circle"></i> Details</button>
                <button class="btn btn-warning" onclick="location.href='comprehensive_assessment.php?enrollment_id=<?= $eid ?>&program_id=<?= $program_id ?>'">
                  <i class="fas fa-clipboard-check"></i>Comprehensive Assessment</button>
                <button class="btn btn-danger" onclick="doDropout(<?= $eid ?>)"
                  <?= ($is_drop||$is_cert||$is_failed||isset($tr['needs_feedback']))?'disabled':'' ?>>
                  <i class="fas fa-user-times"></i> Dropout</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-users-slash"></i>
          <h3>No Participants Found</h3>
          <p><?= !$trainer_program ? 'Not assigned to any program.' : 'No participants match the selected filter.' ?></p>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<!-- Trainee Details Modal -->
<div id="detailModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-user"></i> Trainee Details</h3>
      <button class="close-modal" onclick="closeModal()">&times;</button>
    </div>
    <div id="detailBody"></div>
  </div>
</div>

<script>
// ── constants from PHP ──────────────────────────────────────
const PROG_STARTED = <?= $program_started?'true':'false' ?>;
const PROG_ENDED   = <?= $program_ended  ?'true':'false' ?>;
const IN_SCHEDULE  = <?= $within_schedule?'true':'false' ?>;
const PROG_ID      = <?= intval($program_id) ?>;

// ── init ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  console.log('DOM loaded - Initializing profile dropdown');
  
  const btn = document.getElementById('profileBtn');
  const dd = document.getElementById('profileDropdown');
  
  console.log('Profile button:', btn);
  console.log('Profile dropdown:', dd);
  
  if (btn && dd) {
    // Toggle dropdown when clicking the profile button
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      console.log('Profile button clicked');
      dd.classList.toggle('show');
      console.log('Dropdown classes:', dd.className);
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => { 
      if (!btn.contains(e.target) && !dd.contains(e.target)) {
        dd.classList.remove('show'); 
      }
    });
    
    // Prevent dropdown from closing when clicking inside it
    dd.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  } else {
    console.error('Profile button or dropdown not found!');
  }

  // Logout functionality
  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      const r = await Swal.fire({
        title: 'Logout',
        text: 'Are you sure you want to logout?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Logout',
        cancelButtonText: 'Cancel'
      });
      
      if (r.isConfirmed) { 
        try {
          await fetch('/logout.php', { method: 'POST', credentials: 'same-origin' });
        } catch(_) {}
        window.location.href = '/login.php'; 
      }
    });
  }

});

// ── helpers ─────────────────────────────────────────────────
function changeFilter(f) { const p=new URLSearchParams(location.search); p.set('filter',f); location.href='?'+p; }

function schedGuard() {
  if (!PROG_STARTED) { Swal.fire({icon:'warning',title:'Not Started',text:'Program has not started yet.',timer:2500,showConfirmButton:false}); return false; }
  if (PROG_ENDED)    { Swal.fire({icon:'warning',title:'Ended',      text:'Program has already ended.',  timer:2500,showConfirmButton:false}); return false; }
  if (!IN_SCHEDULE)  { Swal.fire({icon:'warning',title:'Off-Schedule',text:'Today is outside program schedule.',timer:2500,showConfirmButton:false}); return false; }
  return true;
}

// ── individual attendance ────────────────────────────────────
async function markAtt(eid, status) {
  if (!schedGuard()) return;

  const row       = document.getElementById(`row-${eid}`);
  const isMarked  = row.getAttribute('data-marked') === 'true';
  const curStatus = row.getAttribute('data-att-status');
  const isCert    = row.getAttribute('data-certified') === 'true';
  const isDrop    = row.getAttribute('data-dropout') === 'true';
  const isFailed  = row.getAttribute('data-failed') === 'true';
  const pendingFeedback = row.getAttribute('data-pending-feedback') === 'true';
  const assess    = row.getAttribute('data-assessment');

  if (isCert) { 
    Swal.fire({icon:'info', title:'Certified', text:'This trainee is already certified.', timer:2000, showConfirmButton:false}); 
    return; 
  }
  if (isFailed) { 
    Swal.fire({icon:'info', title:'Failed', text:'This trainee has failed.', timer:2000, showConfirmButton:false}); 
    return; 
  }
  if (isDrop) { 
    Swal.fire({icon:'info', title:'Dropout', text:'This trainee has dropped out.', timer:2000, showConfirmButton:false}); 
    return; 
  }
  if (pendingFeedback) {
    Swal.fire({icon:'info', title:'Pending Feedback', text:'This trainee is awaiting feedback submission.', timer:2000, showConfirmButton:false}); 
    return;
  }

  // If already marked, show info and return
  if (isMarked) {
    const lbl = curStatus === 'present' ? '✅ Present' : '❌ Absent';
    Swal.fire({
      icon: 'info', 
      title: '🔒 Already Marked for Today',
      html: `Attendance recorded as <strong>${lbl}</strong>.<br>
            <small style="color:#9ca3af;">Cannot change until tomorrow.</small>`,
      confirmButtonColor: '#4A90E2'
    });
    return;
  }

  const cap = status.charAt(0).toUpperCase() + status.slice(1);
  const res = await Swal.fire({
    title: 'Mark Attendance', 
    html: `Mark as <strong>${cap}</strong> for today?`, 
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: status === 'present' ? '#059669' : '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: `Mark as ${cap}`, 
    cancelButtonText: 'Cancel'
  });
  
  if (!res.isConfirmed) return;

  try {
    const r = await fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `ajax=1&action=mark_daily_attendance&enrollment_id=${eid}&status=${status}`
    });
    const d = await r.json();

    if (d.success) {
      await Swal.fire({
        icon: 'success',
        title: `Marked as ${cap}`,
        html: `<small style="color:#9ca3af;">🔒 Reloading...</small>`,
        timer: 1000,
        showConfirmButton: false
      });
      location.reload(); // Reload to ensure everything is consistent

    } else if (d.already_marked) {
      // Another session marked first
      const lbl2 = d.current_status === 'present' ? '✅ Present' : '❌ Absent';
      Swal.fire({
        icon: 'info', 
        title: 'Already Marked',
        html: `Another session recorded <strong>${lbl2}</strong> for today.<br><small style="color:#9ca3af;">Reloading…</small>`,
        timer: 2500,
        showConfirmButton: false
      }).then(() => location.reload());

    } else {
      Swal.fire({
        icon: 'warning', 
        title: 'Cannot Mark', 
        text: d.message || 'Failed to mark attendance.', 
        confirmButtonColor: '#4A90E2'
      });
    }
  } catch(e) {
    console.error(e);
    Swal.fire({
      icon: 'error', 
      title: 'Network Error', 
      text: 'Could not reach server.', 
      timer: 2000, 
      showConfirmButton: false
    });
  }
}

// ── bulk attendance ──────────────────────────────────────────
async function bulkAttendance(status) {
  if (!schedGuard()) return;

  let unmarked=0, alrPresent=0, alrAbsent=0, ineligible=0;
  document.querySelectorAll('tr[data-eid]').forEach(row=>{
    const cert   = row.getAttribute('data-certified')==='true';
    const drop   = row.getAttribute('data-dropout')==='true';
    const failed = row.getAttribute('data-failed')==='true';
    const pending = row.getAttribute('data-pending-feedback')==='true';
    const marked = row.getAttribute('data-marked')==='true';
    const cur    = row.getAttribute('data-att-status');
    if (cert||drop||failed||pending) ineligible++;
    else if (marked) { if (cur==='present') alrPresent++; else alrAbsent++; }
    else unmarked++;
  });

  const label = status==='present'?'✅ Present':'❌ Absent';

  if (unmarked===0) {
    Swal.fire({icon:'info',title:'🔒 All Eligible Trainees Already Marked',
      html:`<div style="text-align:center;line-height:2.5;">
        <strong style="font-size:22px;color:#059669;">${alrPresent}</strong><span style="color:#6b7280;"> Present &nbsp;|&nbsp; </span>
        <strong style="font-size:22px;color:#dc2626;">${alrAbsent}</strong><span style="color:#6b7280;"> Absent</span><br>
        <small style="color:#9ca3af;">🔒 Resets automatically tomorrow.</small></div>`,
      confirmButtonColor:'#4A90E2'});
    return;
  }

  const confirm=await Swal.fire({
    title:'Bulk Mark Attendance',
    html:`<div style="text-align:left;">
      Mark <strong>${unmarked}</strong> unmarked trainee${unmarked!==1?'s':''} as <strong>${label}</strong>?<br><br>
      <div style="background:#f8f9fa;padding:12px;border-radius:8px;font-size:13px;line-height:2.2;">
        <span style="color:#059669;">✅ Already Present: <strong>${alrPresent}</strong></span> — skipped (kept)<br>
        <span style="color:#dc2626;">❌ Already Absent: <strong>${alrAbsent}</strong></span>  — skipped (kept)<br>
        <span style="color:#9ca3af;">⛔ Ineligible: <strong>${ineligible}</strong></span>      — skipped<br>
        <span style="font-weight:700;">📋 Will be marked: <strong>${unmarked}</strong></span>
      </div><br>
      <small style="color:#9ca3af;">Already-marked trainees are never changed.</small>
    </div>`,
    icon:'question',showCancelButton:true,
    confirmButtonColor:status==='present'?'#059669':'#dc2626',cancelButtonColor:'#6b7280',
    confirmButtonText:`Mark ${unmarked} as ${status.charAt(0).toUpperCase()+status.slice(1)}`,cancelButtonText:'Cancel'
  });
  if (!confirm.isConfirmed) return;

  Swal.fire({title:'Processing…',html:`Marking <strong>${unmarked}</strong> trainees as ${label}…`,allowOutsideClick:false,didOpen:()=>Swal.showLoading()});

  try {
    const act=status==='present'?'mark_all_present_today':'mark_all_absent_today';
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`ajax=1&action=${act}&program_id=${PROG_ID}`});
    const d=await r.json();
    Swal.close();
    if (d.success) {
      await Swal.fire({icon:'success',title:'Bulk Attendance Complete',
        html:`<div style="text-align:left;line-height:2.2;">
          ✅ Marked: <strong>${d.success_count}</strong> as ${label}<br>
          ⏭️ Skipped (already marked): <strong>${d.skipped_count??alrPresent+alrAbsent}</strong><br>
          ${d.error_count>0?`❌ Errors: <strong>${d.error_count}</strong><br>`:''}
          <br><small style="color:#9ca3af;">🔒 Reloading...</small></div>`,
        timer: 1500,
        showConfirmButton: false
      });
      location.reload();
    } else {
      Swal.fire({icon:'error',title:'Error',text:d.message||'Operation failed.',confirmButtonColor:'#4A90E2'});
    }
  } catch(e) {
    Swal.fire({icon:'error',title:'Network Error',text:'Could not reach server.',timer:2000,showConfirmButton:false});
  }
}

// ── show notes ──────────────────────────────────────────────
function showNotes(notes) {
  Swal.fire({title:'Notes',html:`<div style="text-align:left;white-space:pre-line;background:#f8f9fa;padding:12px;border-radius:6px;">${notes}</div>`,showCloseButton:true,showConfirmButton:false,width:'580px'});
}

// ── dropout ─────────────────────────────────────────────────
async function doDropout(eid) {
  const {value:reason}=await Swal.fire({title:'Mark as Dropout',input:'textarea',inputLabel:'Reason for dropout:',inputPlaceholder:'Provide detailed reason…',showCancelButton:true,confirmButtonText:'Mark as Dropout',confirmButtonColor:'#dc3545',cancelButtonText:'Cancel',inputValidator:v=>(!v||v.trim().length<10)?'Min 10 characters required':undefined});
  if (reason===undefined) return;
  const c=await Swal.fire({title:'Confirm Dropout',html:`This will move the trainee to Dropout and mark as Failed. Cannot be undone.`,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',cancelButtonColor:'#6b7280',confirmButtonText:'Yes, dropout',cancelButtonText:'Cancel'});
  if (!c.isConfirmed) return;
  Swal.fire({title:'Processing…',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
  try {
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`ajax=1&action=mark_as_dropout&enrollment_id=${eid}&dropout_reason=${encodeURIComponent(reason)}`});
    const d=await r.json(); Swal.close();
    if (d.success) { await Swal.fire({icon:'success',title:'Done!',text:'Trainee marked as dropout.',timer:2000,showConfirmButton:false}); location.reload(); }
    else Swal.fire({icon:'error',title:'Error',text:d.message||'Failed.',confirmButtonColor:'#4A90E2'});
  } catch(e) { Swal.fire({icon:'error',title:'Network Error',text:'Could not reach server.',timer:2000,showConfirmButton:false}); }
}

// ── view details ─────────────────────────────────────────────
function viewDetails(uid, pid) {
  const modal=document.getElementById('detailModal');
  const body =document.getElementById('detailBody');
  body.innerHTML='<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
  modal.style.display='flex';
  fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`ajax=1&action=get_trainee_details&trainee_user_id=${uid}&program_id=${pid}`})
  .then(r=>r.json()).then(d=>{
    if (!d.success){body.innerHTML='<p style="color:red;text-align:center;">Error loading details.</p>';return;}
    const t=d.trainee; const s=t.attendance_stats||{};
    
    let sk, sl;
    if (t.enrollment_status==='rejected') {
      sk='dropout'; sl='Dropout';
    } else if (t.assessment==='Passed' || t.enrollment_status==='certified') {
      if (t.has_feedback) {
        sk='certified'; sl='Certified';
      } else {
        sk='ongoing'; sl='Ongoing (Pending Feedback)';
      }
    } else if (t.assessment==='Failed') {
      sk='failed'; sl='Failed';
    } else {
      sk='ongoing'; sl='Ongoing';
    }
    
    body.innerHTML=`
      <div class="detail-row"><div class="detail-label">Full Name</div><div class="detail-value">${t.fullname}</div></div>
      <div class="detail-row"><div class="detail-label">Email</div><div class="detail-value">${t.email||'N/A'}</div></div>
      <div class="detail-row"><div class="detail-label">Contact</div><div class="detail-value">${t.contact_number||'N/A'}</div></div>
      <div class="detail-row"><div class="detail-label">Gender / Age</div><div class="detail-value">${t.gender||'N/A'} / ${t.age||'N/A'}</div></div>
      <div class="detail-row"><div class="detail-label">Address</div><div class="detail-value">${[t.barangay,t.municipality,t.city].filter(Boolean).join(', ')||'N/A'}</div></div>
      <div class="detail-row"><div class="detail-label">Education</div><div class="detail-value">${t.education||'N/A'}</div></div>
      <div class="detail-row"><div class="detail-label">Program</div><div class="detail-value">${t.program_name}</div></div>
      <div class="detail-row"><div class="detail-label">Applied</div><div class="detail-value">${new Date(t.applied_at).toLocaleDateString()}</div></div>
      <div class="detail-row">
        <div class="detail-label">Attendance</div>
        <div class="detail-value" style="display:flex;gap:20px;margin-top:4px;">
          <div style="text-align:center;"><strong style="font-size:20px;color:#059669;">${s.present_days||0}</strong><br><small style="color:#6b7280;">Present</small></div>
          <div style="text-align:center;"><strong style="font-size:20px;color:#dc2626;">${s.absent_days||0}</strong><br><small style="color:#6b7280;">Absent</small></div>
          <div style="text-align:center;"><strong style="font-size:20px;color:#3b82f6;">${s.total||0}</strong><br><small style="color:#6b7280;">Total</small></div>
        </div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Attendance %</div>
        <div class="detail-value">
          <div class="progress-bar"><div class="progress-fill" style="width:${t.attendance||0}%"></div></div>
          <div class="progress-text">${parseFloat(t.attendance||0).toFixed(1)}%</div>
        </div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Assessment</div>
        <div class="detail-value">${t.assessment||'Not yet graded'}</div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Feedback Status</div>
        <div class="detail-value">
          ${t.has_feedback 
            ? '<span style="color:#059669;"><i class="fas fa-check-circle"></i> Feedback Submitted</span>' 
            : '<span style="color:#dc2626;"><i class="fas fa-exclamation-circle"></i> No Feedback Yet</span>'}
        </div>
      </div>
      ${t.failure_notes?`<div class="detail-row"><div class="detail-label">Failure/Dropout Notes</div><div class="detail-value" style="background:#f8f9fa;padding:10px;border-radius:6px;border-left:4px solid #ef4444;white-space:pre-line;font-size:13px;">${t.failure_notes}</div></div>`:''}
      ${t.failure_notes_copy?`<div class="detail-row"><div class="detail-label">Notes Archive</div><div class="detail-value" style="background:#fff3cd;padding:12px;border-radius:6px;border-left:4px solid #ffc107;white-space:pre-line;font-size:12px;color:#856404;max-height:180px;overflow-y:auto;">${t.failure_notes_copy}</div></div>`:''}
      <div class="detail-row"><div class="detail-label">Status</div><div class="detail-value"><span class="status-badge status-${sk}">${sl}</span></div></div>
    `;
  }).catch(()=>{body.innerHTML='<p style="color:red;text-align:center;">Error loading details.</p>';});
}
function closeModal(){document.getElementById('detailModal').style.display='none';}
window.onclick=e=>{if(e.target===document.getElementById('detailModal'))closeModal();};
</script>
</body>
</html>