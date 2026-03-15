<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
include __DIR__ . '/../db.php';

// Add this function definition
function getCurrentDateTime() {
    return date('Y-m-d H:i:s');
}

// Debug: Check if database connection works
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
} else {
    error_log("Database connection successful");
    
    // AUTO-FIX ALL PROGRAM SLOTS ON EVERY PAGE LOAD
    $fixed_count = autoFixAllProgramSlots($conn);
    if ($fixed_count > 0) {
        error_log("Auto-fixed $fixed_count program slot inconsistencies");
    }
}


// Function to get the correct slots column name
function getSlotsColumnName($conn) {
    // Check for both columns
    $check_columns_sql = "SHOW COLUMNS FROM programs";
    $column_result = $conn->query($check_columns_sql);
    $columns = [];
    while ($row = $column_result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Prefer total_slots if it exists
    if (in_array('total_slots', $columns)) {
        return 'total_slots';
    }
    // Fallback to slotsAvailable
    elseif (in_array('slotsAvailable', $columns)) {
        return 'slotsAvailable';
    }
    // Final fallback
    else {
        return 'slotsAvailable';
    }
}

// Function to get both slot values and validate them
function getProgramSlots($conn, $program_id) {
    $sql = "SELECT 
                COALESCE(total_slots, 0) as total_slots,
                COALESCE(slotsAvailable, 0) as slots_available
            FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $slots = $result->fetch_assoc();
    $stmt->close();
    
    // If total_slots is 0 but we have slots_available, set total_slots = slots_available
    if ($slots['total_slots'] == 0 && $slots['slots_available'] > 0) {
        $slots['total_slots'] = $slots['slots_available'];
        
        // Update the database to fix this
        $update_sql = "UPDATE programs SET total_slots = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $slots['slots_available'], $program_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        error_log("Fixed total_slots for program $program_id: Set to {$slots['slots_available']} (was 0)");
    }
    
    return [
        'total_slots' => $slots['total_slots'] ?? 0,
        'slots_available' => $slots['slots_available'] ?? 0
    ];
}

// Function to get accurate available slots with auto-fix
function getAvailableSlots($conn, $program_id) {
    // Get program slot information
    $slot_data = getProgramSlots($conn, $program_id);
    $total_slots = $slot_data['total_slots'];
    
    // Get actual enrolled count
    $enrolled_count = getEnrollmentCount($conn, $program_id);
    
    // Calculate available slots
    $available_slots = $total_slots - $enrolled_count;
    
    // Ensure available slots is never negative
    $available_slots = max(0, $available_slots);
    
    // If database slots_available doesn't match calculated value, auto-fix it
    if ($slot_data['slots_available'] != $available_slots) {
        // Update the database to sync
        $sql = "UPDATE programs SET slotsAvailable = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $available_slots, $program_id);
        $stmt->execute();
        $stmt->close();
        
        error_log("Auto-fixed slots for program $program_id: Database had {$slot_data['slots_available']}, calculated $available_slots (Total: $total_slots, Enrolled: $enrolled_count)");
    }
    
    return $available_slots;
}

// Function to auto-fix all program slot inconsistencies on page load
function autoFixAllProgramSlots($conn) {
    // Get all active programs
    $sql = "SELECT id FROM programs WHERE status = 'active'";
    $result = $conn->query($sql);
    $fixed_count = 0;
    $total_programs = 0;
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $program_id = $row['id'];
            $total_programs++;
            
            // Get current data
            $slot_data = getProgramSlots($conn, $program_id);
            $enrolled_count = getEnrollmentCount($conn, $program_id);
            
            // Calculate correct available slots
            $correct_available = max(0, $slot_data['total_slots'] - $enrolled_count);
            
            // Check if slotsAvailable is wrong
            if ($slot_data['slots_available'] != $correct_available) {
                // Fix it
                $update_sql = "UPDATE programs SET slotsAvailable = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $correct_available, $program_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                error_log("Auto-fixed program $program_id: slotsAvailable was {$slot_data['slots_available']}, now $correct_available");
                $fixed_count++;
            }
            
            // Also check if total_slots is less than enrolled count
            if ($slot_data['total_slots'] < $enrolled_count) {
                $new_total = $enrolled_count; // Set total slots to at least enrolled count
                $update_sql = "UPDATE programs SET total_slots = ?, slotsAvailable = 0 WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $new_total, $program_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                error_log("Auto-fixed program $program_id: total_slots was {$slot_data['total_slots']} (less than enrolled $enrolled_count), now $new_total");
                $fixed_count++;
            }
        }
    }
    
    return $fixed_count;
}

// Function to sync slots when updating
function syncProgramSlots($conn, $program_id, $new_total_slots) {
    // Get current enrollment count
    $enrolled_count = getEnrollmentCount($conn, $program_id);
    
    // Ensure new total slots is not less than current enrollments
    if ($new_total_slots < $enrolled_count) {
        error_log("WARNING: Cannot set total slots ($new_total_slots) lower than current enrollments ($enrolled_count) for program $program_id");
        $new_total_slots = $enrolled_count;
    }
    
    // Calculate available slots
    $available_slots = $new_total_slots - $enrolled_count;
    
    // Update both columns to keep them in sync
    $sql = "UPDATE programs SET 
            total_slots = ?,
            slotsAvailable = ?,
            updated_at = ? 
            WHERE id = ?";
    
    $current_time = getCurrentDateTime();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisi", $new_total_slots, $available_slots, $current_time, $program_id);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        error_log("Synced slots for program $program_id: total_slots=$new_total_slots, slotsAvailable=$available_slots, enrolled=$enrolled_count");
    } else {
        error_log("Failed to sync slots for program $program_id: " . $conn->error);
    }
    
    return $result;
}

// Function to get enrollment count for a program
function getEnrollmentCount($conn, $program_id) {
    $sql = "SELECT COUNT(*) as count FROM enrollments 
            WHERE program_id = ? AND enrollment_status IN ('approved', 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    return $count;
}

// Function to check if a program has active enrollments
function hasActiveEnrollments($conn, $program_id) {
    $sql = "SELECT COUNT(*) as count FROM enrollments 
            WHERE program_id = ? AND enrollment_status = 'approved'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    return $count > 0;
}

// Function to delete ALL enrollments for a program
function deleteAllProgramEnrollments($conn, $program_id) {
    $sql = "DELETE FROM enrollments WHERE program_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $result = $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($result) {
        error_log("Deleted ALL $affected_rows enrollment(s) for program ID $program_id");
        
        // After deleting enrollments, update slotsAvailable to match total_slots
        $update_sql = "UPDATE programs SET slotsAvailable = total_slots WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $program_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        error_log("Failed to delete enrollments for program ID $program_id: " . $conn->error);
    }
    
    return $result;
}

// Function to reset enrollment status to 'pending'
function resetProgramEnrollments($conn, $program_id) {
    $sql = "UPDATE enrollments SET enrollment_status = 'pending' WHERE program_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($result) {
        error_log("Reset $affected_rows enrollment(s) to 'pending' for program ID $program_id");
    } else {
        error_log("Failed to reset enrollments for program ID $program_id: " . $conn->error);
    }
    
    return $result;
}

// Function to get category specialization from program_categories table
function getCategorySpecialization($conn, $category_id) {
    if (!$category_id) return null;
    
    // Get specialization from program_categories table
    $sql = "SELECT specialization FROM program_categories WHERE id = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $category = $result->fetch_assoc();
    $stmt->close();
    
    return $category ? $category['specialization'] : null;
}

// Function to get trainers by specialization - Show only exact match, hide busy trainers BUT include current program's trainer
function getTrainersBySpecialization($conn, $specialization, $program_id = null) {
    $trainers = [];
    
    // First, get all busy trainers (those with active programs)
    $busy_trainers = getBusyTrainers($conn);
    
    // If we're editing a program, remove its current trainer from busy trainers list
    $current_trainer_id = null;
    if ($program_id) {
        // Get current program's trainer
        $current_trainer_sql = "SELECT trainer_id FROM programs WHERE id = ? AND trainer_id IS NOT NULL";
        $current_stmt = $conn->prepare($current_trainer_sql);
        $current_stmt->bind_param("i", $program_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        if ($current_row = $current_result->fetch_assoc()) {
            $current_trainer_id = $current_row['trainer_id'];
            // Remove current trainer from busy trainers array
            if ($current_trainer_id) {
                $busy_trainers = array_filter($busy_trainers, function($id) use ($current_trainer_id) {
                    return $id != $current_trainer_id;
                });
            }
        }
        $current_stmt->close();
    }
    
    if ($specialization === null || $specialization === '') {
        // If category has NO specialization, show ONLY available trainers with NO specialization
        $sql = "SELECT id, fullname, email, specialization FROM users 
                WHERE role = 'trainer' AND status = 'Active' 
                AND (specialization IS NULL OR specialization = '')";
        
        // Exclude busy trainers (except current program's trainer)
        if (!empty($busy_trainers)) {
            $placeholders = str_repeat('?,', count($busy_trainers) - 1) . '?';
            $sql .= " AND id NOT IN ($placeholders)";
        }
        
        $sql .= " ORDER BY fullname";
        
        if (!empty($busy_trainers)) {
            $stmt = $conn->prepare($sql);
            $types = str_repeat('i', count($busy_trainers));
            $stmt->bind_param($types, ...$busy_trainers);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        } else {
            $result = $conn->query($sql);
        }
    } else {
        // Get available trainers with EXACTLY matching specialization ONLY
        // Use BINARY comparison for exact match including case
        $sql = "SELECT id, fullname, email, specialization FROM users 
                WHERE role = 'trainer' AND status = 'Active' 
                AND BINARY specialization = ?"; // BINARY ensures exact match including case
        
        // Exclude busy trainers (except current program's trainer)
        if (!empty($busy_trainers)) {
            $placeholders = str_repeat('?,', count($busy_trainers) - 1) . '?';
            $sql .= " AND id NOT IN ($placeholders)";
        }
        
        $sql .= " ORDER BY fullname";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            error_log("Prepare failed for trainers by specialization: " . $conn->error);
            return $trainers;
        }
        
        // Bind parameters: first specialization, then all busy trainer IDs
        if (!empty($busy_trainers)) {
            $types = 's' . str_repeat('i', count($busy_trainers));
            $params = array_merge([$specialization], $busy_trainers);
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("s", $specialization);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $trainers[] = $row;
        }
    }
    
    // If editing and current trainer exists, add them to the list if not already there
    if ($program_id && $current_trainer_id) {
        // Check if current trainer is already in the list
        $found = false;
        foreach ($trainers as $trainer) {
            if ($trainer['id'] == $current_trainer_id) {
                $found = true;
                break;
            }
        }
        
        // If not found, fetch and add current trainer
        if (!$found) {
            $fetch_sql = "SELECT id, fullname, email, specialization FROM users 
                         WHERE id = ? AND role = 'trainer' AND status = 'Active'";
            $fetch_stmt = $conn->prepare($fetch_sql);
            $fetch_stmt->bind_param("i", $current_trainer_id);
            $fetch_stmt->execute();
            $fetch_result = $fetch_stmt->get_result();
            if ($current_trainer = $fetch_result->fetch_assoc()) {
                $trainers[] = $current_trainer;
                // Sort trainers by name
                usort($trainers, function($a, $b) {
                    return strcmp($a['fullname'], $b['fullname']);
                });
            }
            $fetch_stmt->close();
        }
    }
    
    return $trainers;
}


// OPTION 1: Archive programs on their end date (not day after)
function deactivatePastPrograms($conn) {
    // Use TODAY for comparison so programs ending today get archived
    $today = date('Y-m-d');
    
    error_log("=== AUTO-ARCHIVE CHECK STARTED at " . date('Y-m-d H:i:s') . " ===");
    error_log("Today's date for comparison: $today");
    
    // Find active programs where scheduleEnd is today or earlier
    $sql = "SELECT p.*, pc.name as category_name 
            FROM programs p 
            LEFT JOIN program_categories pc ON p.category_id = pc.id 
            WHERE p.status = 'active' 
            AND DATE(p.scheduleEnd) < ?";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $archived_count = 0;
    
    while ($program = $result->fetch_assoc()) {
        error_log("Found expired program: '{$program['name']}' (ID: {$program['id']})");
        
        if (archiveProgramDirect($conn, $program['id'])) {
            $archived_count++;
            error_log("SUCCESS: Archived program ID: {$program['id']}");
        } else {
            error_log("FAILED: Could not archive program ID: {$program['id']}");
        }
    }
    
    $stmt->close();
    error_log("Auto-archived $archived_count program(s)");
    error_log("=== AUTO-ARCHIVE CHECK COMPLETED ===");
    
    return $archived_count;
}

// UPDATED: Function to archive a program directly with better error handling
function archiveProgramDirect($conn, $program_id) {
    error_log("=== STARTING ARCHIVE PROCESS FOR PROGRAM ID: $program_id at " . date('Y-m-d H:i:s') . " ===");
    
    // Validate connection
    if (!$conn || !is_object($conn) || !method_exists($conn, 'begin_transaction')) {
        error_log("ERROR: Invalid database connection");
        return false;
    }
    
    // Validate program_id
    if (!is_numeric($program_id) || $program_id <= 0) {
        error_log("ERROR: Invalid program ID: $program_id");
        return false;
    }
    
    $conn->begin_transaction();
    
    try {
        // STEP 1: Get program data
        $program = getProgramData($conn, $program_id);
        if (!$program) {
            $conn->rollback();
            return false;
        }
        
        // STEP 2: Archive enrollments and feedback
        $enrollment_count = archiveProgramEnrollmentsAndFeedback($conn, $program_id, $program);
        if ($enrollment_count === false) {
            $conn->rollback();
            return false;
        }
        error_log("Successfully archived $enrollment_count enrollment(s) with feedback to archived_history");
        
        // STEP 3: Delete enrollments
        $deleted_enrollments = deleteEnrollments($conn, $program_id);
        if ($deleted_enrollments === false) {
            $conn->rollback();
            return false;
        }
        
        // STEP 4: Archive program
        $archive_id = archiveProgramToArchiveTable($conn, $program);
        if ($archive_id === false) {
            $conn->rollback();
            return false;
        }
        
        // STEP 5: Delete from active programs
        $delete_result = deleteProgramFromActive($conn, $program_id);
        if ($delete_result === false) {
            $conn->rollback();
            return false;
        }
        
        // STEP 6: Record history
        recordHistory($conn, $program_id, $program);
        
        // Commit transaction
        $conn->commit();
        
        error_log("=== COMPLETED ARCHIVE PROCESS FOR PROGRAM ID: $program_id ===");
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Exception during archive process: " . $e->getMessage());
        error_log("Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Get program data from database
 */
function getProgramData($conn, $program_id) {
    $sql = "SELECT p.*, pc.name as category_name 
            FROM programs p 
            LEFT JOIN program_categories pc ON p.category_id = pc.id 
            WHERE p.id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for program select: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $program_id);
    
    if (!$stmt->execute()) {
        error_log("Execute failed for program select: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $program = $result->fetch_assoc();
    $stmt->close();
    
    if (!$program) {
        error_log("Program not found with ID: $program_id");
        return false;
    }
    
    error_log("Found program to archive: '{$program['name']}' (ID: $program_id)");
    return $program;
}

/**
 * Delete enrollments from enrollments table
 */
function deleteEnrollments($conn, $program_id) {
    error_log("Deleting enrollments from enrollments table...");
    
    $sql = "DELETE FROM enrollments WHERE program_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for delete enrollments: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $program_id);
    
    if (!$stmt->execute()) {
        error_log("Execute failed for delete enrollments: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    error_log("Deleted $affected_rows enrollment(s) from enrollments table");
    return $affected_rows;
}

/**
 * Archive program to archive_programs table
 */
function archiveProgramToArchiveTable($conn, $program) {
    error_log("Archiving program to archive_programs table...");
    
    // Check if archive_programs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'archive_programs'");
    if ($table_check->num_rows == 0) {
        error_log("ERROR: archive_programs table does not exist!");
        return false;
    }
    
    // Get archive_programs table structure
    $check_archive_sql = "SHOW COLUMNS FROM archive_programs";
    $archive_result = $conn->query($check_archive_sql);
    if (!$archive_result) {
        error_log("Failed to get archive_programs structure: " . $conn->error);
        return false;
    }
    
    $archive_columns = [];
    while ($row = $archive_result->fetch_assoc()) {
        $archive_columns[$row['Field']] = true;
    }
    
    error_log("Archive table has " . count($archive_columns) . " columns");
    
    // Prepare data for insertion
    $insert_data = prepareArchiveData($program, $archive_columns);
    if (empty($insert_data['columns'])) {
        error_log("ERROR: No matching columns found between programs and archive_programs");
        return false;
    }
    
    // Build and execute insert query
    $sql = "INSERT INTO archive_programs (" . implode(', ', $insert_data['columns']) . ") 
            VALUES (" . implode(', ', $insert_data['placeholders']) . ")";
    
    error_log("Archive INSERT SQL: $sql");
    error_log("Archive INSERT types: {$insert_data['types']}");
    error_log("Archive INSERT values: " . print_r($insert_data['values'], true));
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for archive insert: " . $conn->error);
        return false;
    }
    
    // Bind all parameters
    if (!empty($insert_data['types'])) {
        $stmt->bind_param($insert_data['types'], ...$insert_data['values']);
    }
    
    if (!$stmt->execute()) {
        error_log("Archive insertion failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $inserted_id = $stmt->insert_id;
    $stmt->close();
    
    error_log("SUCCESS: Program inserted into archive_programs with ID: $inserted_id");
    return $inserted_id;
}

/**
 * Prepare data for archiving
 */
function prepareArchiveData($program, $archive_columns) {
    $columns = [];
    $placeholders = [];
    $values = [];
    $types = '';
    
    $archived_at = date('Y-m-d H:i:s');
    
    // Column mapping from source to destination
    $column_mapping = [
        'id' => 'original_id',
        'name' => 'name',
        'category_id' => 'category_id',
        'duration' => 'duration',
        'scheduleStart' => 'scheduleStart',
        'scheduleEnd' => 'scheduleEnd',
        'trainer_id' => 'trainer_id',
        'trainer' => 'trainer',
        'slotsAvailable' => 'slotsAvailable',
        'total_slots' => 'total_slots',
        'show_on_index' => 'show_on_index',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at'
    ];
    
    // Add archived_at first if column exists
    if (isset($archive_columns['archived_at'])) {
        $columns[] = 'archived_at';
        $placeholders[] = '?';
        $values[] = $archived_at;
        $types .= 's';
        error_log("  Adding column: archived_at = $archived_at");
    }
    
    // Add other columns that exist in both tables
    foreach ($column_mapping as $source_col => $dest_col) {
        if (isset($program[$source_col]) && isset($archive_columns[$dest_col])) {
            $columns[] = $dest_col;
            $placeholders[] = '?';
            $values[] = $program[$source_col];
            
            // Determine the correct type for binding
            if (in_array($source_col, ['id', 'category_id', 'duration', 'slotsAvailable', 'total_slots', 'show_on_index', 'trainer_id'])) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
            
            error_log("  Adding column: $dest_col = {$program[$source_col]}");
        }
    }
    
    // Add category_name if column exists
    if (isset($archive_columns['category_name']) && isset($program['category_name'])) {
        $columns[] = 'category_name';
        $placeholders[] = '?';
        $values[] = $program['category_name'];
        $types .= 's';
        error_log("  Adding column: category_name = {$program['category_name']}");
    }
    
    return [
        'columns' => $columns,
        'placeholders' => $placeholders,
        'values' => $values,
        'types' => $types
    ];
}

/**
 * Delete program from active programs table
 */
function deleteProgramFromActive($conn, $program_id) {
    error_log("Deleting from programs table...");
    
    $sql = "DELETE FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for delete: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $program_id);
    
    if (!$stmt->execute()) {
        error_log("Delete from programs failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($affected_rows == 0) {
        error_log("Warning: No rows affected when deleting program ID: $program_id");
    } else {
        error_log("SUCCESS: Program deleted from active programs table (affected rows: $affected_rows)");
    }
    
    return $affected_rows;
}

/**
 * Record history for the archived program
 */
function recordHistory($conn, $program_id, $program) {
    error_log("Recording history...");
    
    // Assuming recordProgramHistory function exists and handles its own errors
    return recordProgramHistory(
        $conn, 
        $program_id, 
        'auto_archived', 
        'Program auto-archived due to schedule end date: ' . $program['scheduleEnd'], 
        null, 
        $program['name']
    );
}


// NEW FUNCTION: Archive program enrollments and feedback to archived_history
function archiveProgramEnrollmentsAndFeedback($conn, $program_id, $program_data) {
    error_log("Archiving enrollments and feedback for program ID: $program_id");
    
    $count = 0;
    
    // First, get all enrollments for this program
    $enrollments_sql = "SELECT e.*, u.fullname as user_name 
                        FROM enrollments e 
                        LEFT JOIN users u ON e.user_id = u.id 
                        WHERE e.program_id = ?";
    $enrollments_stmt = $conn->prepare($enrollments_sql);
    
    if (!$enrollments_stmt) {
        error_log("Prepare failed for enrollments query: " . $conn->error);
        return false;
    }
    
    $enrollments_stmt->bind_param("i", $program_id);
    $enrollments_stmt->execute();
    $enrollments_result = $enrollments_stmt->get_result();
    
    while ($enrollment = $enrollments_result->fetch_assoc()) {
        $enrollment_id = $enrollment['id'];
        $user_id = $enrollment['user_id'];
        
        error_log("Processing enrollment ID: $enrollment_id for user ID: $user_id");
        
        // CHECK IF THIS ENROLLMENT ALREADY EXISTS IN archived_history
        $check_sql = "SELECT id FROM archived_history WHERE enrollment_id = ? AND user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        
        if ($check_stmt) {
            $check_stmt->bind_param("ii", $enrollment_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                error_log("Enrollment ID $enrollment_id for user ID $user_id already exists in archived_history. Skipping...");
                $check_stmt->close();
                continue; // Skip this enrollment if it already exists
            }
            $check_stmt->close();
        } else {
            error_log("Prepare failed for duplicate check query: " . $conn->error);
            // Continue with the insertion attempt even if check fails
        }
        
        // Get feedback for this user and program (if exists)
        $feedback_sql = "SELECT * FROM feedback WHERE user_id = ? AND program_id = ?";
        $feedback_stmt = $conn->prepare($feedback_sql);
        $feedback_stmt->bind_param("ii", $user_id, $program_id);
        $feedback_stmt->execute();
        $feedback_result = $feedback_stmt->get_result();
        $feedback = $feedback_result->fetch_assoc();
        $feedback_stmt->close();
        
        // Prepare data for archived_history
        $archived_at = getCurrentDateTime();
        $program_name = $program_data['name'];
        $program_duration = $program_data['duration'];
        $program_duration_unit = isset($program_data['duration_unit']) ? $program_data['duration_unit'] : 
                                (isset($program_data['durationUnit']) ? $program_data['durationUnit'] : 'Days');
        $program_schedule_start = $program_data['scheduleStart'];
        $program_schedule_end = $program_data['scheduleEnd'];
        $program_trainer_id = $program_data['trainer_id'];
        $program_trainer_name = $program_data['trainer'];
        $program_category_id = $program_data['category_id'];
        $program_total_slots = isset($program_data['total_slots']) ? $program_data['total_slots'] : 
                              (isset($program_data['slotsAvailable']) ? $program_data['slotsAvailable'] : 0);
        $program_slots_available = $program_data['slotsAvailable'];
        $program_other_trainer = isset($program_data['other_trainer']) ? $program_data['other_trainer'] : '-';
        $program_show_on_index = isset($program_data['show_on_index']) ? $program_data['show_on_index'] : 0;
        
        // FIX 1: Use the actual enrollment status from the enrollments table
        // Don't default to 'completed' - use what's actually in the database
        $enrollment_status = $enrollment['enrollment_status'] ?? $enrollment['status'] ?? 'pending';
        
        // Also check if there's a 'status' field (some tables use different column names)
        if (!isset($enrollment['enrollment_status']) && isset($enrollment['status'])) {
            $enrollment_status = $enrollment['status'];
        }
        
        $enrollment_applied_at = $enrollment['applied_at'] ?? $enrollment['created_at'] ?? null;
        $enrollment_completed_at = $enrollment['completed_at'] ?? null;
        $enrollment_attendance = $enrollment['attendance'] ?? 0;
        $enrollment_approval_status = $enrollment['approval_status'] ?? 'pending';
        $enrollment_approved_by = $enrollment['approved_by'] ?? null;
        $enrollment_approved_date = $enrollment['approved_date'] ?? null;
        $enrollment_assessment = $enrollment['assessment'] ?? null;
        
        // Feedback data (if exists)
        $feedback_id = $feedback ? $feedback['id'] : null;
        $trainer_expertise_rating = $feedback ? $feedback['trainer_expertise'] : null;
        $trainer_communication_rating = $feedback ? $feedback['trainer_communication'] : null;
        $trainer_methods_rating = $feedback ? $feedback['trainer_methods'] : null;
        $trainer_requests_rating = $feedback ? $feedback['trainer_requests'] : null;
        $trainer_questions_rating = $feedback ? $feedback['trainer_questions'] : null;
        $trainer_instructions_rating = $feedback ? $feedback['trainer_instructions'] : null;
        $trainer_prioritization_rating = $feedback ? $feedback['trainer_prioritization'] : null;
        $trainer_fairness_rating = $feedback ? $feedback['trainer_fairness'] : null;
        $program_knowledge_rating = $feedback ? $feedback['program_knowledge'] : null;
        $program_process_rating = $feedback ? $feedback['program_process'] : null;
        $program_environment_rating = $feedback ? $feedback['program_environment'] : null;
        $program_algorithms_rating = $feedback ? $feedback['program_algorithms'] : null;
        $program_preparation_rating = $feedback ? $feedback['program_preparation'] : null;
        $system_technology_rating = $feedback ? $feedback['system_technology'] : null;
        $system_workflow_rating = $feedback ? $feedback['system_workflow'] : null;
        $system_instructions_rating = $feedback ? $feedback['system_instructions'] : null;
        $system_answers_rating = $feedback ? $feedback['system_answers'] : null;
        $system_performance_rating = $feedback ? $feedback['system_performance'] : null;
        $feedback_comments = $feedback ? $feedback['additional_comments'] : null;
        
        // FIX 2: Get the actual submitted_at from feedback table
        $feedback_submitted_at = $feedback ? $feedback['submitted_at'] : null;
        
        // If feedback_submitted_at is empty but feedback exists, try alternative field names
        if ($feedback && empty($feedback_submitted_at)) {
            $feedback_submitted_at = $feedback['created_at'] ?? $feedback['feedback_date'] ?? null;
        }
        
        // FIX 3: Determine the correct archive_trigger
        // Check if this enrollment was completed to set the appropriate trigger
        $archive_trigger = 'program_ended'; // default
        
        // If enrollment status is 'completed', use 'enrollment_completed' as trigger
        if ($enrollment_status === 'completed') {
            $archive_trigger = 'enrollment_completed';
        } 
        // Also check if there's feedback submitted (another indicator of completion)
        else if ($feedback_submitted_at !== null) {
            $archive_trigger = 'enrollment_completed';
        }
        // Check if we're archiving because program moved to archive
        else if (isset($program_data['is_being_archived']) && $program_data['is_being_archived'] === true) {
            $archive_trigger = 'program_moved_to_archive';
        }
        
        $archive_source = 'direct_from_programs';
        
        // Log the values for debugging
        error_log("Archiving enrollment ID $enrollment_id - Status: $enrollment_status, Trigger: $archive_trigger, Feedback Submitted: " . ($feedback_submitted_at ?? 'null'));
        
        // Insert into archived_history
        $insert_sql = "INSERT INTO archived_history (
            user_id, original_program_id, enrollment_id, feedback_id,
            program_name, program_duration, program_duration_unit,
            program_schedule_start, program_schedule_end,
            program_trainer_id, program_trainer_name, program_category_id,
            program_total_slots, program_slots_available, program_other_trainer,
            program_show_on_index,
            enrollment_status, enrollment_applied_at, enrollment_completed_at,
            enrollment_attendance, enrollment_approval_status,
            enrollment_approved_by, enrollment_approved_date, enrollment_assessment,
            trainer_expertise_rating, trainer_communication_rating, trainer_methods_rating,
            trainer_requests_rating, trainer_questions_rating, trainer_instructions_rating,
            trainer_prioritization_rating, trainer_fairness_rating,
            program_knowledge_rating, program_process_rating, program_environment_rating,
            program_algorithms_rating, program_preparation_rating,
            system_technology_rating, system_workflow_rating, system_instructions_rating,
            system_answers_rating, system_performance_rating,
            feedback_comments, feedback_submitted_at,
            archived_at, archive_trigger, archive_source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        
        if (!$insert_stmt) {
            error_log("Prepare failed for archived_history insert: " . $conn->error);
            continue;
        }
        
        // FIX 4: Fix the bind_param type string - need to count parameters correctly
        // Based on your table structure, here's the corrected type string:
        // i=integer, s=string, d=double
        $types = "iiiiisissisiisiiississssiiiiiiiiiiiiiiiiiiiisss";
        
        // Make sure all variables are defined (they should be from above)
        $insert_stmt->bind_param(
            $types,
            $user_id,
            $program_id,
            $enrollment_id,
            $feedback_id,
            $program_name,
            $program_duration,
            $program_duration_unit,
            $program_schedule_start,
            $program_schedule_end,
            $program_trainer_id,
            $program_trainer_name,
            $program_category_id,
            $program_total_slots,
            $program_slots_available,
            $program_other_trainer,
            $program_show_on_index,
            $enrollment_status,
            $enrollment_applied_at,
            $enrollment_completed_at,
            $enrollment_attendance,
            $enrollment_approval_status,
            $enrollment_approved_by,
            $enrollment_approved_date,
            $enrollment_assessment,
            $trainer_expertise_rating,
            $trainer_communication_rating,
            $trainer_methods_rating,
            $trainer_requests_rating,
            $trainer_questions_rating,
            $trainer_instructions_rating,
            $trainer_prioritization_rating,
            $trainer_fairness_rating,
            $program_knowledge_rating,
            $program_process_rating,
            $program_environment_rating,
            $program_algorithms_rating,
            $program_preparation_rating,
            $system_technology_rating,
            $system_workflow_rating,
            $system_instructions_rating,
            $system_answers_rating,
            $system_performance_rating,
            $feedback_comments,
            $feedback_submitted_at,
            $archived_at,
            $archive_trigger,
            $archive_source
        );
        
        if ($insert_stmt->execute()) {
            $count++;
            error_log("Successfully archived enrollment ID $enrollment_id with status '$enrollment_status' and trigger '$archive_trigger'");
        } else {
            error_log("Failed to archive enrollment ID $enrollment_id: " . $insert_stmt->error);
        }
        
        $insert_stmt->close();
    }
    
    $enrollments_stmt->close();
    
    error_log("Total archived to archived_history: $count record(s)");
    return $count;
}



// Handle GET requests for fetching program data (for reactivate/restore)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['get_program'])) {
        $id = intval($_GET['get_program']);
        $sql = "SELECT p.*, pc.name as category_name, pc.specialization as category_specialization FROM programs p 
                LEFT JOIN program_categories pc ON p.category_id = pc.id 
                WHERE p.id = ?";
      
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $program = $result->fetch_assoc();
        
        // Calculate enrolled count and available slots
        if ($program) {
            $program['enrolled_count'] = getEnrollmentCount($conn, $program['id']);
            $slot_data = getProgramSlots($conn, $program['id']);
            $program['totalSlots'] = $slot_data['total_slots'];
            $program['available_slots'] = getAvailableSlots($conn, $program['id']);
        }
        
        header('Content-Type: application/json');
        echo json_encode($program);
        exit;
    }

    if (isset($_GET['get_archived_program'])) {
        $id = intval($_GET['get_archived_program']);
        $sql = "SELECT * FROM archive_programs WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $program = $result->fetch_assoc();
        
        // Convert archived program to format compatible with edit form
        if ($program) {
            // Use total_slots if available, otherwise slotsAvailable
            $program['totalSlots'] = isset($program['total_slots']) ? $program['total_slots'] : 
                                     (isset($program['slotsAvailable']) ? $program['slotsAvailable'] : 0);
            // Set status to 'active' since we're restoring
            $program['status'] = 'active';
            // Ensure trainer_id is properly set (it might be NULL in archive)
            if (!isset($program['trainer_id']) || $program['trainer_id'] === null) {
                $program['trainer_id'] = '';
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($program);
        exit;
    }
    
    // Get trainers for category/specialization with debug logging
    if (isset($_GET['get_trainers_for_category'])) {
        error_log("=== GET TRAINERS REQUEST ===");
        error_log("Category ID: " . $_GET['category_id']);
        error_log("Program ID: " . ($_GET['program_id'] ?? 'null'));
        
        $category_id = intval($_GET['category_id']);
        $program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : null;
        
        // Get category specialization
        $specialization = getCategorySpecialization($conn, $category_id);
        error_log("Category Specialization: " . ($specialization ? $specialization : 'NULL'));
        
        // Get trainers with matching specialization (busy trainers are excluded except current)
        $trainers = getTrainersBySpecialization($conn, $specialization, $program_id);
        error_log("Found " . count($trainers) . " trainers");
        
        // Get busy trainers for reference
        $busy_trainers = getBusyTrainers($conn);
        
        // Get trainers already in this category (excluding current program)
        $category_busy_trainers = getTrainersInSameCategory($conn, $category_id, $program_id);
        
        $result = [
            'trainers' => $trainers,
            'busy_trainers' => $busy_trainers,
            'category_busy_trainers' => $category_busy_trainers,
            'category_specialization' => $specialization
        ];
        
        error_log("=== END GET TRAINERS ===");
        
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    
    // Get program history/feedback
    if (isset($_GET['get_program_history'])) {
        $id = intval($_GET['get_program_history']);
        $sql = "SELECT * FROM program_history WHERE program_id = ? OR archived_program_id = ? ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
        
        header('Content-Type: application/json');
        echo json_encode($history);
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST START ===");
    error_log("POST data: " . print_r($_POST, true));
    
    if (isset($_POST['action'])) {
        error_log("Processing action: " . $_POST['action']);
        
        $success = false;
        
        switch ($_POST['action']) {
            case 'add_program':
                $success = addProgram($conn, $_POST);
                break;
            case 'edit_program':
                $success = editProgram($conn, $_POST);
                break;
            case 'archive_program':
                error_log("ARCHIVE PROGRAM REQUEST - ID: " . $_POST['id']);
                $success = archiveProgram($conn, $_POST['id']);
                error_log("Archive result: " . ($success ? 'SUCCESS' : 'FAILED'));
                break;
            case 'restore_program':
                // For restore, bypass the normal editProgram enrollment check
                $success = restoreProgram($conn, $_POST);
                break;
            case 'delete_program':
                $success = deleteProgram($conn, $_POST['id']);
                break;
            case 'toggle_show_on_index':
                $success = toggleShowOnIndex($conn, $_POST['id'], $_POST['show_on_index']);
                break;
            case 'reactivate_program':
                // First edit the program, then reactivate it
                $success = editProgram($conn, $_POST);
                if ($success) {
                    // Now update status to active with cleanup option
                    $reactivateData = $_POST;
                    $reactivateData['cleanup_enrollments'] = $_POST['cleanup_enrollments'] ?? 'keep';
                    $success = reactivateProgram($conn, $reactivateData);
                }
                break;
            case 'add_category':
                $category_name = trim($_POST['category_name']);
                
                if (!empty($category_name)) {
                    // Check if category already exists
                    $check_sql = "SELECT id FROM program_categories WHERE name = ? AND status = 'active'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $category_name);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        echo json_encode(['success' => true, 'category_id' => $row['id'], 'category_name' => $category_name]);
                        exit;
                    }
                    
                    $category_id = addCategory($conn, $category_name);
                    if ($category_id) {
                        echo json_encode(['success' => true, 'category_id' => $category_id, 'category_name' => $category_name]);
                        exit;
                    }
                }
                echo json_encode(['success' => false]);
                exit;
            case 'get_trainers_in_category':
                $category_id = $_POST['category_id'];
                $exclude_program_id = $_POST['exclude_program_id'] ?? null;
                $trainers = getTrainersInSameCategory($conn, $category_id, $exclude_program_id);
                echo json_encode(['trainers' => $trainers]);
                exit;
            case 'get_busy_trainers':
                // Get all trainers who have ANY active program assigned
                $busy_trainers = getBusyTrainers($conn);
                echo json_encode(['busy_trainers' => $busy_trainers]);
                exit;
            case 'check_duplicate_program':
                $program_name = trim($_POST['program_name']);
                $program_id = $_POST['program_id'] ?? null;
                
                if (empty($program_name)) {
                    echo json_encode(['is_duplicate' => false]);
                    exit;
                }
                
                // Check for duplicate program name globally
                if ($program_id) {
                    $check_sql = "SELECT id, name, category_id FROM programs WHERE LOWER(name) = LOWER(?) AND id != ? AND status = 'active'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("si", $program_name, $program_id);
                } else {
                    $check_sql = "SELECT id, name, category_id FROM programs WHERE LOWER(name) = LOWER(?) AND status = 'active'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $program_name);
                }
                
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $duplicates = [];
                    while ($row = $result->fetch_assoc()) {
                        $duplicates[] = $row;
                    }
                    
                    // Get category names for better error message
                    $messages = [];
                    foreach ($duplicates as $dup) {
                        $cat_sql = "SELECT name FROM program_categories WHERE id = ?";
                        $cat_stmt = $conn->prepare($cat_sql);
                        $cat_stmt->bind_param("i", $dup['category_id']);
                        $cat_stmt->execute();
                        $cat_result = $cat_stmt->get_result();
                        if ($cat_row = $cat_result->fetch_assoc()) {
                            $messages[] = "Program ID {$dup['id']} in '{$cat_row['name']}' category";
                        } else {
                            $messages[] = "Program ID {$dup['id']} (Unknown category)";
                        }
                        $cat_stmt->close();
                    }
                    
                    echo json_encode([
                        'is_duplicate' => true,
                        'message' => 'Already exists: ' . implode(', ', $messages)
                    ]);
                } else {
                    echo json_encode(['is_duplicate' => false]);
                }
                
                $check_stmt->close();
                exit;
        }
        
        error_log("Operation result: " . ($success ? 'SUCCESS' : 'FAILED'));
        
        if ($success) {
            $_SESSION['message'] = 'Operation completed successfully!';
            error_log("Success message set");
        } else {
            if (!isset($_SESSION['error'])) {
                $_SESSION['error'] = 'Operation failed!';
            }
            $_SESSION['debug'] = 'Check server logs for details';
            error_log("Error message set");
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    error_log("=== POST REQUEST END ===");
}

// deleteProgram function - Fixed to properly delete from archive_programs
function deleteProgram($conn, $id) {
    error_log("=== DELETE PROGRAM REQUEST ===");
    error_log("Attempting to delete archived program ID: $id");
    
    // First check if program exists in archive
    $check_sql = "SELECT id, name FROM archive_programs WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        error_log("Prepare failed for archive check: " . $conn->error);
        return false;
    }
    
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        error_log("Program not found in archive: ID $id");
        $check_stmt->close();
        return false;
    }
    
    $program = $check_result->fetch_assoc();
    $check_stmt->close();
    
    error_log("Found program to delete: " . $program['name']);
    
    // Record history before deletion
    recordProgramHistory($conn, $id, 'permanently_deleted', 'Program permanently deleted from archive', null, $program['name']);
    
    // Delete from archive_programs
    $sql = "DELETE FROM archive_programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for delete: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $id);
    $result = $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($result && $affected_rows > 0) {
        error_log("Successfully deleted archived program ID: $id - {$program['name']}");
        error_log("=== DELETE PROGRAM SUCCESS ===");
        return true;
    } else {
        error_log("Failed to delete archived program ID: $id - " . ($stmt ? $stmt->error : 'Unknown error'));
        error_log("=== DELETE PROGRAM FAILED ===");
        return false;
    }
}

// reactivateProgram function - now returns boolean as expected
function reactivateProgram($conn, $data) {
    // Log function call
    error_log("=== REACTIVATE FUNCTION CALLED ===");
    error_log("Raw data: " . print_r($data, true));
    
    // Validate input
    if (!isset($data['id']) || empty($data['id'])) {
        error_log("ERROR: No valid ID provided!");
        $_SESSION['error'] = 'No valid program ID provided';
        return false;
    }
    
    $id = filter_var($data['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        error_log("ERROR: Invalid ID format: " . $data['id']);
        $_SESSION['error'] = 'Invalid program ID format';
        return false;
    }
    
    error_log("Program ID to reactivate: $id");
    
    // Check current status and existence
    $checkSql = "SELECT id, name, status FROM programs WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    if (!$checkStmt) {
        error_log("ERROR: Prepare check failed: " . $conn->error);
        $_SESSION['error'] = 'Database prepare failed';
        return false;
    }
    
    $checkStmt->bind_param("i", $id);
    if (!$checkStmt->execute()) {
        error_log("ERROR: Execute check failed: " . $checkStmt->error);
        $checkStmt->close();
        $_SESSION['error'] = 'Database query failed';
        return false;
    }
    
    $result = $checkStmt->get_result();
    $current = $result->fetch_assoc();
    $checkStmt->close();
    
    if (!$current) {
        error_log("ERROR: Program ID $id not found in database!");
        $_SESSION['error'] = 'Program not found';
        return false;
    }
    
    error_log("Current program data: " . print_r($current, true));
    
    // Check if already active
    if ($current['status'] === 'active') {
        error_log("WARNING: Program ID $id is already active");
        $_SESSION['error'] = 'Program is already active';
        return false;
    }
    
    // Perform the update
    $current_time = getCurrentDateTime();
    $updateSql = "UPDATE programs SET status = 'active', updated_at = ? WHERE id = ? AND status != 'active'";
    $updateStmt = $conn->prepare($updateSql);
    
    if (!$updateStmt) {
        error_log("ERROR: Prepare update failed: " . $conn->error);
        $_SESSION['error'] = 'Database prepare failed';
        return false;
    }
    
    $updateStmt->bind_param("si", $current_time, $id);
    $executeResult = $updateStmt->execute();
    
    if (!$executeResult) {
        error_log("ERROR: Execute update failed: " . $updateStmt->error);
        $updateStmt->close();
        $_SESSION['error'] = 'Failed to update program';
        return false;
    }
    
    $affectedRows = $updateStmt->affected_rows;
    $updateStmt->close();
    
    error_log("Execute result: " . ($executeResult ? 'true' : 'false'));
    error_log("Affected rows: $affectedRows");
    
    // Verify update
    $verifyStmt = $conn->prepare($checkSql);
    $verifyStmt->bind_param("i", $id);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();
    $after = $verifyResult->fetch_assoc();
    $verifyStmt->close();
    
    error_log("After update: " . print_r($after, true));
    
    if ($affectedRows > 0) {
        error_log("SUCCESS: Program ID $id reactivated successfully");
        
        // Record history
        recordProgramHistory($conn, $id, 'reactivated', 'Program reactivated');
        
        // Also need to sync slots for reactivated program
        if (isset($data['slotsAvailable'])) {
            syncProgramSlots($conn, $id, $data['slotsAvailable']);
        }
        
        return true;
    } else {
        error_log("WARNING: No rows affected. Program may already be active or not exist.");
        $_SESSION['error'] = 'Program was not updated (may already be active)';
        return false;
    }
}

// Toggle show_on_index status with timezone fix
function toggleShowOnIndex($conn, $id, $show_on_index) {
    $current_time = getCurrentDateTime();
    $sql = "UPDATE programs SET show_on_index = ?, updated_at = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for toggle_show_on_index: " . $conn->error);
        return false;
    }
    
    $show_value = $show_on_index === 'true' ? 1 : 0;
    $stmt->bind_param("isi", $show_value, $current_time, $id);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        $status = $show_value ? 'shown' : 'hidden';
        recordProgramHistory($conn, $id, 'visibility_changed', "Program $status on index page");
        error_log("Successfully toggled show_on_index for program ID $id to $show_value at $current_time");
    } else {
        error_log("Failed to toggle show_on_index for program ID $id: " . $conn->error);
    }
    
    return $result;
}

// Function to add new category with specialization auto-filled from name
function addCategory($conn, $categoryName) {
    error_log("addCategory called: " . $categoryName);
    
    if (empty($categoryName)) {
        error_log("addCategory: Empty category name");
        return false;
    }
    
    // Check if category already exists
    $check_sql = "SELECT id FROM program_categories WHERE name = ? AND status = 'active'";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        error_log("Prepare failed for category check: " . $conn->error);
        return false;
    }
    
    $check_stmt->bind_param("s", $categoryName);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $check_stmt->close();
        error_log("Category already exists with ID: " . $row['id']);
        return $row['id'];
    }
    $check_stmt->close();
    
    // Use category name as specialization
    $specialization = $categoryName;
    
    // Insert new category with specialization
    $sql = "INSERT INTO program_categories (name, specialization, is_default, status) VALUES (?, ?, 0, 'active')";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for category insert: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("ss", $categoryName, $specialization);
    $result = $stmt->execute();
    
    if ($result) {
        $category_id = $stmt->insert_id;
        $stmt->close();
        error_log("New category added with ID: " . $category_id);
        return $category_id;
    } else {
        error_log("Failed to add category: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

// Function to get trainers already assigned to same category
function getTrainersInSameCategory($conn, $category_id, $exclude_program_id = null) {
    $trainers = [];
    
    $sql = "SELECT DISTINCT trainer_id FROM programs 
            WHERE category_id = ? 
            AND trainer_id IS NOT NULL 
            AND trainer_id != '' 
            AND status = 'active'";
    
    if ($exclude_program_id) {
        $sql .= " AND id != ?";
    }
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for trainers in category: " . $conn->error);
        return $trainers;
    }
    
    if ($exclude_program_id) {
        $stmt->bind_param("ii", $category_id, $exclude_program_id);
    } else {
        $stmt->bind_param("i", $category_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $trainers[] = (int)$row['trainer_id'];
    }
    
    $stmt->close();
    return $trainers;
}

// Function to get all trainers who have ANY active program assigned
function getBusyTrainers($conn) {
    $busy_trainers = [];
    
    $sql = "SELECT DISTINCT trainer_id FROM programs 
            WHERE trainer_id IS NOT NULL 
            AND trainer_id != '' 
            AND status = 'active'";
    
    $result = $conn->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $busy_trainers[] = (int)$row['trainer_id'];
        }
    } else {
        error_log("Error fetching busy trainers: " . $conn->error);
    }
    
    return $busy_trainers;
}

// Function to get trainers with their category experience and specialization
function getTrainersWithCategoryExperience($conn) {
    $trainers = [];
    
    $sql = "SELECT 
                u.id, 
                u.fullname, 
                u.email, 
                u.specialization,
                (SELECT COUNT(*) FROM programs p 
                 WHERE p.trainer_id = u.id 
                 AND p.status = 'active') as active_programs,
                GROUP_CONCAT(DISTINCT pc.name) as experienced_categories,
                GROUP_CONCAT(DISTINCT pc.specialization) as experienced_specializations,
                GROUP_CONCAT(DISTINCT p.category_id) as experienced_category_ids
            FROM users u 
            LEFT JOIN programs p ON u.id = p.trainer_id AND p.status = 'active'
            LEFT JOIN program_categories pc ON p.category_id = pc.id
            WHERE u.role = 'trainer' AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY u.fullname";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Convert experienced categories to array
            $row['experienced_categories_array'] = $row['experienced_categories'] ? explode(',', $row['experienced_categories']) : [];
            $row['experienced_specializations_array'] = $row['experienced_specializations'] ? explode(',', $row['experienced_specializations']) : [];
            $row['experienced_category_ids_array'] = $row['experienced_category_ids'] ? explode(',', $row['experienced_category_ids']) : [];
            $trainers[] = $row;
        }
    } else {
        error_log("Error fetching trainers with category experience: " . $conn->error);
    }
    
    return $trainers;
}

// Function to record program history/feedback with enrollment protection
function recordProgramHistory($conn, $program_id, $action, $description, $user_id = null, $program_name = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    $current_time = getCurrentDateTime();
    
    // Determine if we're dealing with a deletion or archival action
    $is_archived_action = ($action === 'archived' || $action === 'auto_archived' 
                          || $action === 'permanently_deleted' || $action === 'deleted');
    
    // FIX: Create separate variables for binding to avoid reference issues
    $bind_program_id = $program_id;
    $bind_archived_id = $is_archived_action ? $program_id : null;
    $bind_user_id = $user_id;
    $bind_action = $action;
    $bind_description = $description;
    $bind_current_time = $current_time;
    
    $sql = "INSERT INTO program_history (
                program_id, 
                archived_program_id, 
                user_id, 
                action, 
                description, 
                program_name_backup,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for program history: " . $conn->error);
        return false;
    }
    
    // Include program_name in backup for reference after deletion
    $bind_program_name = $program_name ?? getProgramName($conn, $program_id);
    
    $stmt->bind_param(
        "iiissss", 
        $bind_program_id,      // program_id
        $bind_archived_id,     // archived_program_id (null for non-archived)
        $bind_user_id,         // user_id
        $bind_action,          // action
        $bind_description,     // description
        $bind_program_name,    // program_name_backup
        $bind_current_time     // created_at
    );
    
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        error_log("Recorded program history: Program ID $program_id, Action: $action");
        
        // If this is a deletion, update related records to use archived_program_id
        if ($is_archived_action) {
            preserveFeedbackReferences($conn, $program_id, $program_name);
        }
    } else {
        error_log("Failed to record program history: " . $conn->error);
    }
    
    return $result;
}

// UPDATED: preserveFeedbackReferences function
function preserveFeedbackReferences($conn, $program_id, $program_name) {
    // Update feedback table to preserve program info before it's deleted
    $sql = "UPDATE feedback 
            SET archived_program_id = ?,
                program_name_backup = ?,
                archived_at = ?
            WHERE program_id = ?";
    
    $archived_at = getCurrentDateTime();
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for feedback preservation: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("issi", $program_id, $program_name, $archived_at, $program_id);
    $result = $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($result) {
        error_log("Preserved feedback for archived program ID: $program_id ($affected_rows records)");
    } else {
        error_log("Failed to preserve feedback for program ID $program_id: " . $conn->error);
    }
    
    return $result;
}

// UPDATED: preserveEnrollmentReferences function
function preserveEnrollmentReferences($conn, $program_id, $program_name) {
    // Update enrollments table to preserve program info
    // Preserve ALL enrollments (not just completed/approved) to maintain history
    $sql = "UPDATE enrollments 
            SET archived_program_id = ?,
                program_name_backup = ?
            WHERE program_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for enrollment preservation: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("isi", $program_id, $program_name, $program_id);
    $result = $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($result) {
        error_log("Preserved enrollment references for archived program ID: $program_id ($affected_rows records)");
    } else {
        error_log("Failed to preserve enrollment references for program ID $program_id: " . $conn->error);
    }
    
    return $result;
}

// Helper function to get program name
function getProgramName($conn, $program_id) {
    $sql = "SELECT name FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['name'];
    }
    
    return "Unknown Program";
}

// addProgram function with corrected parameter binding
function addProgram($conn, $data) {
    error_log("=== addProgram START ===");
    error_log("Received data: " . print_r($data, true));
    
    // Validate required fields
    $required_fields = ['name', 'scheduleStart', 'scheduleEnd', 'slotsAvailable', 'category_id'];
    $missing_fields = [];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    // Trainer is now required (can be empty string for "No Trainer")
    if (!isset($data['trainer_id'])) {
        $missing_fields[] = 'trainer_id';
    }
    
    if (!empty($missing_fields)) {
        error_log("Missing required fields: " . implode(', ', $missing_fields));
        $_SESSION['error'] = "Missing required fields: " . implode(', ', $missing_fields);
        return false;
    }
    
    $programName = trim($data['name']);
    $totalSlots = max(1, intval($data['slotsAvailable']));
    $category_id = $data['category_id'];
    $trainer_id = $data['trainer_id'] === '' ? NULL : $data['trainer_id']; // Convert empty string to NULL
    
    // Validate program name - empty/whitespace-only check
    if (empty($programName)) {
        error_log("Program name cannot be empty or just whitespace");
        $_SESSION['error'] = "Program name cannot be empty or contain only spaces!";
        return false;
    }
    
    // Validate program name length
    if (strlen($programName) > 255) {
        error_log("Program name is too long: " . strlen($programName) . " characters");
        $_SESSION['error'] = "Program name must be 255 characters or less!";
        return false;
    }
    
    // Validate trainer selection with exact specialization matching
    if ($trainer_id !== NULL) {
        // Check if trainer exists and is active
        $trainer_check_sql = "SELECT id, fullname, specialization FROM users WHERE id = ? AND role = 'trainer' AND status = 'Active'";
        $trainer_check_stmt = $conn->prepare($trainer_check_sql);
        $trainer_check_stmt->bind_param("i", $trainer_id);
        $trainer_check_stmt->execute();
        $trainer_result = $trainer_check_stmt->get_result();
        
        if ($trainer_result->num_rows === 0) {
            error_log("Invalid trainer selected: " . $trainer_id);
            $_SESSION['error'] = "Invalid trainer selected!";
            $trainer_check_stmt->close();
            return false;
        }
        
        // Check if trainer specialization matches category specialization EXACTLY
        $category_specialization = getCategorySpecialization($conn, $category_id);
        $trainer_data = $trainer_result->fetch_assoc();
        $trainer_check_stmt->close();
        
        // If category has a specialization, trainer must have EXACTLY the same specialization
        if ($category_specialization) {
            if (!$trainer_data['specialization'] || $trainer_data['specialization'] !== $category_specialization) {
                error_log("Trainer specialization ({$trainer_data['specialization']}) doesn't match category specialization ($category_specialization)");
                $_SESSION['error'] = "Trainer specialization doesn't match the category's specialization! Required: " . htmlspecialchars($category_specialization);
                return false;
            }
        } else {
            // If category has NO specialization, trainer must also have NO specialization
            if ($trainer_data['specialization'] && $trainer_data['specialization'] !== '') {
                error_log("Category has no specialization but trainer has specialization: {$trainer_data['specialization']}");
                $_SESSION['error'] = "This category has no specialization. Please select a general trainer.";
                return false;
            }
        }
    }
    
    // Validate start date - must be at least 7 days from today
    $scheduleStart = $data['scheduleStart'];
    $today = new DateTime();
    $minStartDate = (new DateTime())->modify('+7 days')->format('Y-m-d');
    $startDate = new DateTime($scheduleStart);
    
    if ($startDate < $today->modify('+7 days')->modify('-1 day')) { // -1 day to allow exact 7 days
        error_log("Schedule start must be at least 7 days from today. Selected: $scheduleStart, Minimum: $minStartDate");
        $_SESSION['error'] = "Schedule start must be at least 7 days from today! Minimum date is $minStartDate.";
        return false;
    }
    
    // Handle new category creation
    if ($category_id === 'new' && !empty($data['new_category_name'])) {
        $new_category_id = addCategory($conn, $data['new_category_name']);
        if ($new_category_id) {
            $category_id = $new_category_id;
        } else {
            return false;
        }
    }
    
    // ========== ENHANCED DUPLICATE CHECK - GLOBALLY UNIQUE NAME ==========
    // VALIDATION: Check for duplicate program name globally (case-insensitive)
    $check_duplicate_sql = "SELECT id, name, category_id FROM programs WHERE LOWER(name) = LOWER(?) AND status = 'active'";
    $check_stmt = $conn->prepare($check_duplicate_sql);
    if (!$check_stmt) {
        error_log("Prepare failed for duplicate check: " . $conn->error);
        return false;
    }
    
    $check_stmt->bind_param("s", $programName);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $duplicates = [];
        while ($duplicate = $check_result->fetch_assoc()) {
            $duplicates[] = $duplicate;
        }
        
        // Get category names for better error message
        $category_names = [];
        foreach ($duplicates as $dup) {
            $cat_sql = "SELECT name FROM program_categories WHERE id = ?";
            $cat_stmt = $conn->prepare($cat_sql);
            $cat_stmt->bind_param("i", $dup['category_id']);
            $cat_stmt->execute();
            $cat_result = $cat_stmt->get_result();
            if ($cat_row = $cat_result->fetch_assoc()) {
                $category_names[] = "ID {$dup['id']} in category '{$cat_row['name']}'";
            } else {
                $category_names[] = "ID {$dup['id']} (Unknown category)";
            }
            $cat_stmt->close();
        }
        
        error_log("Duplicate program found globally: Case-insensitive match for '$programName' (Found in: " . implode(', ', $category_names) . ")");
        $_SESSION['error'] = "A program with the name '{$duplicates[0]['name']}' already exists!<br>Found: " . implode(', ', $category_names) . "<br>Program names must be unique across all categories.";
        $check_stmt->close();
        return false;
    }
    $check_stmt->close();
    // ========== ENHANCED DUPLICATE CHECK END ==========
    
    // Calculate duration
    $duration = 1;
    if (isset($data['duration']) && !empty($data['duration'])) {
        $duration = intval($data['duration']);
    } else {
        try {
            $start = new DateTime($data['scheduleStart']);
            $end = new DateTime($data['scheduleEnd']);
            $duration = $start->diff($end)->days + 1;
        } catch (Exception $e) {
            error_log("Date parsing error: " . $e->getMessage());
            $duration = 1;
        }
    }
    
    // Get trainer name if trainer is assigned
    $trainer_name = '';
    
    if ($trainer_id) {
        // With the updated filtering, busy trainers are already excluded
        // But we still need to check if trainer is already assigned to same category
        $existing_trainers = getTrainersInSameCategory($conn, $category_id);
        if (in_array($trainer_id, $existing_trainers)) {
            error_log("Trainer $trainer_id is already assigned to a program in category $category_id");
            $_SESSION['error'] = 'This trainer is already assigned to another program in the same category!';
            return false;
        }
        
        // Fetch trainer name from users table
        $trainer_sql = "SELECT fullname FROM users WHERE id = ? AND role = 'trainer'";
        $trainer_stmt = $conn->prepare($trainer_sql);
        $trainer_stmt->bind_param("i", $trainer_id);
        $trainer_stmt->execute();
        $trainer_result = $trainer_stmt->get_result();
        if ($trainer_row = $trainer_result->fetch_assoc()) {
            $trainer_name = $trainer_row['fullname'];
        }
        $trainer_stmt->close();
    }
    
    // Show on index is always true by default (option removed from UI)
    $show_on_index = 1; // Always visible on index page
    
    // Get current datetime for created_at and updated_at
    $current_time = getCurrentDateTime();
    
    // Build SQL with created_at and updated_at - CORRECTED: We have 12 columns, 13 values (including 'active')
    $sql = "INSERT INTO programs (name, category_id, duration, scheduleStart, scheduleEnd, trainer_id, trainer, total_slots, slotsAvailable, show_on_index, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)";
    
    error_log("SQL: " . $sql);
    error_log("Params: name={$data['name']}, category_id=$category_id, duration=$duration, scheduleStart={$data['scheduleStart']}, scheduleEnd={$data['scheduleEnd']}, trainer_id=$trainer_id, trainer='$trainer_name', total_slots=$totalSlots, slotsAvailable=$totalSlots, show_on_index=$show_on_index, created_at=$current_time, updated_at=$current_time");
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    // Correct parameter binding - we have 12 parameters (13th is the literal 'active')
    // Parameters: 1-12: s, i, i, s, s, i, s, i, i, i, s, s
    // Note: 'active' is a literal string in the SQL, not a parameter
    
    if ($trainer_id === NULL) {
        // Handle NULL trainer_id
        $stmt->bind_param(
            "siissisiiiss",  // 12 parameters
            $programName,           // s
            $category_id,           // i
            $duration,              // i
            $data['scheduleStart'], // s
            $data['scheduleEnd'],   // s
            $trainer_id,            // s (NULL as string)
            $trainer_name,          // s
            $totalSlots,            // i
            $totalSlots,            // i
            $show_on_index,         // i
            $current_time,          // s
            $current_time           // s
        );
    } else {
        // Handle integer trainer_id
        $stmt->bind_param(
            "siissisiiiss",  // 12 parameters
            $programName,           // s
            $category_id,           // i
            $duration,              // i
            $data['scheduleStart'], // s
            $data['scheduleEnd'],   // s
            $trainer_id,            // i
            $trainer_name,          // s
            $totalSlots,            // i
            $totalSlots,            // i
            $show_on_index,         // i
            $current_time,          // s
            $current_time           // s
        );
    }
    
    $result = $stmt->execute();
    
    if ($result) {
        $insert_id = $stmt->insert_id;
        error_log("SUCCESS: Program added with ID: " . $insert_id);
        
        // Record history
        recordProgramHistory($conn, $insert_id, 'created', 'New program created');
        
        // Sync the slots after insertion
        syncProgramSlots($conn, $insert_id, $totalSlots);
        
        // Verify the insertion
        $verify_sql = "SELECT * FROM programs WHERE id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("i", $insert_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $program_data = $verify_result->fetch_assoc();
        $verify_stmt->close();
        
        error_log("Verified program data: " . print_r($program_data, true));
        error_log("Created at: " . ($program_data['created_at'] ?? 'N/A'));
        error_log("Updated at: " . ($program_data['updated_at'] || 'N/A'));
    } else {
        error_log("FAILED: " . $stmt->error);
    }
    
    $stmt->close();
    error_log("=== addProgram END ===");
    return $result;
}

// editProgram function with enrollment check - UPDATED TO ALLOW RESTORE
function editProgram($conn, $data) {
    error_log("editProgram called with data: " . print_r($data, true));
    
    if (empty($data['id']) || empty($data['name']) || empty($data['scheduleStart']) || empty($data['scheduleEnd']) || empty($data['slotsAvailable']) || empty($data['category_id'])) {
        error_log("Missing required fields for edit");
        return false;
    }
    
    $programId = $data['id'];
    
    // CHECK IF THIS IS A RESTORE ACTION - ALLOW EDITING FOR RESTORE
    $isRestoreAction = isset($data['action']) && $data['action'] === 'restore_program';
    
    // Only check enrollments if NOT a restore action
    if (!$isRestoreAction) {
        $enrollmentCount = getEnrollmentCount($conn, $programId);
        if ($enrollmentCount > 0) {
            error_log("Cannot edit program ID $programId - has $enrollmentCount enrollment(s)");
            $_SESSION['error'] = "Cannot edit program: There are active enrollments. Please archive the program instead.";
            return false;
        }
    } else {
        error_log("Allowing edit for restore action (bypassing enrollment check)");
    }
    
    // Rest of the function remains the same...
    // Trainer is now required (but can be empty string for "No Trainer")
    if (!isset($data['trainer_id'])) {
        error_log("Missing trainer_id field for edit");
        return false;
    }
    
    $programName = trim($data['name']);
    $totalSlots = max(1, intval($data['slotsAvailable']));
    $category_id = $data['category_id'];
    $trainer_id = $data['trainer_id'] === '' ? NULL : $data['trainer_id']; // Convert empty string to NULL

    
    // Validate program name - empty/whitespace-only check
    if (empty($programName)) {
        error_log("Program name cannot be empty or just whitespace");
        $_SESSION['error'] = "Program name cannot be empty or contain only spaces!";
        return false;
    }
    
    // Validate program name length
    if (strlen($programName) > 255) {
        error_log("Program name is too long: " . strlen($programName) . " characters");
        $_SESSION['error'] = "Program name must be 255 characters or less!";
        return false;
    }
    
    // Validate trainer selection with exact specialization matching
    if ($trainer_id !== NULL) {
        // Check if trainer exists and is active
        $trainer_check_sql = "SELECT id, fullname, specialization FROM users WHERE id = ? AND role = 'trainer' AND status = 'Active'";
        $trainer_check_stmt = $conn->prepare($trainer_check_sql);
        $trainer_check_stmt->bind_param("i", $trainer_id);
        $trainer_check_stmt->execute();
        $trainer_result = $trainer_check_stmt->get_result();
        
        if ($trainer_result->num_rows === 0) {
            error_log("Invalid trainer selected: " . $trainer_id);
            $_SESSION['error'] = "Invalid trainer selected!";
            $trainer_check_stmt->close();
            return false;
        }
        
        // Check if trainer specialization matches category specialization EXACTLY
        $category_specialization = getCategorySpecialization($conn, $category_id);
        $trainer_data = $trainer_result->fetch_assoc();
        $trainer_check_stmt->close();
        
        // If category has a specialization, trainer must have EXACTLY the same specialization
        if ($category_specialization) {
            if (!$trainer_data['specialization'] || $trainer_data['specialization'] !== $category_specialization) {
                error_log("Trainer specialization ({$trainer_data['specialization']}) doesn't match category specialization ($category_specialization)");
                $_SESSION['error'] = "Trainer specialization doesn't match the category's specialization! Required: " . htmlspecialchars($category_specialization);
                return false;
            }
        } else {
            // If category has NO specialization, trainer must also have NO specialization
            if ($trainer_data['specialization'] && $trainer_data['specialization'] !== '') {
                error_log("Category has no specialization but trainer has specialization: {$trainer_data['specialization']}");
                $_SESSION['error'] = "This category has no specialization. Please select a general trainer.";
                return false;
            }
        }
    }
    
    if ($category_id === 'new' && !empty($data['new_category_name'])) {
        $new_category_id = addCategory($conn, $data['new_category_name']);
        if ($new_category_id) {
            $category_id = $new_category_id;
        } else {
            return false;
        }
    }
    
    // ========== ENHANCED DUPLICATE CHECK - GLOBALLY UNIQUE NAME ==========
    // VALIDATION: Check for duplicate program name globally (excluding current program, case-insensitive)
    $check_duplicate_sql = "SELECT id, name, category_id FROM programs WHERE LOWER(name) = LOWER(?) AND id != ? AND status = 'active'";
    $check_stmt = $conn->prepare($check_duplicate_sql);
    if (!$check_stmt) {
        error_log("Prepare failed for duplicate check: " . $conn->error);
        return false;
    }
    
    $check_stmt->bind_param("si", $programName, $programId);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $duplicates = [];
        while ($duplicate = $check_result->fetch_assoc()) {
            $duplicates[] = $duplicate;
        }
        
        // Get category names for better error message
        $category_names = [];
        foreach ($duplicates as $dup) {
            $cat_sql = "SELECT name FROM program_categories WHERE id = ?";
            $cat_stmt = $conn->prepare($cat_sql);
            $cat_stmt->bind_param("i", $dup['category_id']);
            $cat_stmt->execute();
            $cat_result = $cat_stmt->get_result();
            if ($cat_row = $cat_result->fetch_assoc()) {
                $category_names[] = "ID {$dup['id']} in category '{$cat_row['name']}'";
            } else {
                $category_names[] = "ID {$dup['id']} (Unknown category)";
            }
            $cat_stmt->close();
        }
        
        error_log("Duplicate program found globally: Case-insensitive match for '$programName' (Found in: " . implode(', ', $category_names) . ") excluding ID $programId");
        $_SESSION['error'] = "A program with the name '{$duplicates[0]['name']}' already exists!<br>Found: " . implode(', ', $category_names) . "<br>Program names must be unique across all categories.";
        $check_stmt->close();
        return false;
    }
    $check_stmt->close();
    // ========== ENHANCED DUPLICATE CHECK END ==========
    
    if (isset($data['duration']) && !empty($data['duration'])) {
        $duration = intval($data['duration']);
    } else {
        $start = new DateTime($data['scheduleStart']);
        $end = new DateTime($data['scheduleEnd']);
        $duration = $start->diff($end)->days + 1;
    }
    
    // Get trainer name if trainer is assigned
    $trainer_name = '';
    
    if ($trainer_id) {
        // Get current program's trainer to check if we're changing it
        $current_sql = "SELECT trainer_id FROM programs WHERE id = ?";
        $current_stmt = $conn->prepare($current_sql);
        $current_stmt->bind_param("i", $programId);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_program = $current_result->fetch_assoc();
        $current_stmt->close();
        
        $current_trainer_id = $current_program['trainer_id'] ?? null;
        
        // If changing trainers, check if new trainer is already in same category
        if ($current_trainer_id !== $trainer_id) {
            // Also check if trainer is already assigned to same category (excluding current program)
            $existing_trainers = getTrainersInSameCategory($conn, $category_id, $programId);
            if (in_array($trainer_id, $existing_trainers)) {
                error_log("Trainer $trainer_id is already assigned to another program in category $category_id");
                $_SESSION['error'] = 'This trainer is already assigned to another program in the same category!';
                return false;
            }
        }
        
        // Fetch trainer name from users table
        $trainer_sql = "SELECT fullname FROM users WHERE id = ? AND role = 'trainer'";
        $trainer_stmt = $conn->prepare($trainer_sql);
        $trainer_stmt->bind_param("i", $trainer_id);
        $trainer_stmt->execute();
        $trainer_result = $trainer_stmt->get_result();
        if ($trainer_row = $trainer_result->fetch_assoc()) {
            $trainer_name = $trainer_row['fullname'];
        }
        $trainer_stmt->close();
    }
    
    // Get current enrollment count to ensure we don't set totalSlots lower than current enrollments
    $current_enrollments = getEnrollmentCount($conn, $programId);
    if ($totalSlots < $current_enrollments) {
        error_log("Cannot set total slots ($totalSlots) lower than current enrollments ($current_enrollments)");
        $totalSlots = $current_enrollments;
    }
    
    // Get current show_on_index value from database (preserve existing value)
    $current_sql = "SELECT show_on_index FROM programs WHERE id = ?";
    $current_stmt = $conn->prepare($current_sql);
    $current_stmt->bind_param("i", $programId);
    $current_stmt->execute();
    $current_result = $current_stmt->get_result();
    $current_data = $current_result->fetch_assoc();
    $show_on_index = $current_data['show_on_index'] ?? 1; // Preserve existing value, default to 1
    $current_stmt->close();
    
    // Get current datetime for updated_at
    $current_time = getCurrentDateTime();
    
    $sql = "UPDATE programs SET name=?, category_id=?, duration=?, scheduleStart=?, scheduleEnd=?, 
            trainer_id=?, trainer=?, total_slots=?, slotsAvailable=?, show_on_index=?, updated_at=? 
            WHERE id=?";
    
    error_log("Executing SQL: " . $sql);
    error_log("Trainer ID: " . $trainer_id . ", Trainer Name: " . $trainer_name);
    error_log("Updated at: " . $current_time);
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    // Correct parameter binding for edit - 12 parameters
    if ($trainer_id === NULL) {
        $stmt->bind_param(
            "siissisiiisi",  // 12 parameters
            $programName,
            $category_id,
            $duration,
            $data['scheduleStart'],
            $data['scheduleEnd'],
            $trainer_id,      // s (NULL as string)
            $trainer_name,
            $totalSlots,
            $totalSlots,
            $show_on_index,
            $current_time,
            $programId
        );
    } else {
        $stmt->bind_param(
            "siissisiiisi",  // 12 parameters
            $programName,
            $category_id,
            $duration,
            $data['scheduleStart'],
            $data['scheduleEnd'],
            $trainer_id,      // i
            $trainer_name,
            $totalSlots,
            $totalSlots,
            $show_on_index,
            $current_time,
            $programId
        );
    }
    
    $result = $stmt->execute();
    $stmt->close();
    
    // Record history
    if ($result) {
        recordProgramHistory($conn, $programId, 'updated', 'Program details updated');
        syncProgramSlots($conn, $programId, $totalSlots);
        error_log("Program updated successfully: " . $programId);
        
        // Verify the update
        $verify_sql = "SELECT updated_at FROM programs WHERE id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("i", $programId);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $updated_data = $verify_result->fetch_assoc();
        $verify_stmt->close();
        error_log("Verified updated_at: " . ($updated_data['updated_at'] ?? 'N/A'));
    } else {
        error_log("Failed to update program: " . $stmt->error);
    }
    
    return $result;
}

// UPDATED: archiveProgram function to store original ID
function archiveProgram($conn, $id) {
    error_log("=== ARCHIVE PROGRAM FUNCTION CALLED ===");
    error_log("Archiving program ID: $id");
    
    // Check if program has active enrollments
    if (hasActiveEnrollments($conn, $id)) {
        error_log("Program ID $id has active enrollments - cannot archive");
        $_SESSION['error'] = "Cannot archive program: There are active enrollments. Please handle enrollments first.";
        return false;
    }
    
    // First get the program data
    $sql = "SELECT p.*, pc.name as category_name FROM programs p LEFT JOIN program_categories pc ON p.category_id = pc.id WHERE p.id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $program = $result->fetch_assoc();
    $stmt->close();
    
    if (!$program) {
        error_log("Program not found with ID: " . $id);
        return false;
    }
    
    error_log("Found program to archive: " . $program['name']);
    
    // Record history before archiving
    recordProgramHistory($conn, $id, 'archived', 'Program manually archived', null, $program['name']);
    
    // STEP 1: Archive all enrollments and feedback to archived_history
    error_log("Step 1: Archiving enrollments and feedback to archived_history...");
    $enrollment_count = archiveProgramEnrollmentsAndFeedback($conn, $id, $program);
    
    if ($enrollment_count === false) {
        error_log("ERROR: Failed to archive enrollments and feedback");
        return false;
    }
    
    error_log("Successfully archived $enrollment_count enrollment(s) with feedback to archived_history");
    
    // STEP 2: Delete any enrollments from enrollments table
    deleteAllProgramEnrollments($conn, $id);
    
    // STEP 3: Archive to archive_programs table
    // Check archive_programs table structure and use appropriate columns
    $check_archive_sql = "SHOW COLUMNS FROM archive_programs";
    $archive_result = $conn->query($check_archive_sql);
    $archive_columns = [];
    while ($row = $archive_result->fetch_assoc()) {
        $archive_columns[$row['Field']] = true;
    }
    
    // Prepare columns and values for archive insertion
    $columns = [];
    $placeholders = [];
    $values = [];
    $types = '';
    
    // Map program columns to archive columns
    $column_mapping = [
        'id' => 'original_id', // Store original ID for reference
        'name' => 'name',
        'category_id' => 'category_id',
        'duration' => 'duration',
        'scheduleStart' => 'scheduleStart',
        'scheduleEnd' => 'scheduleEnd',
        'trainer_id' => 'trainer_id',
        'trainer' => 'trainer',
        'slotsAvailable' => 'slotsAvailable',
        'total_slots' => 'total_slots',
        'show_on_index' => 'show_on_index',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at'
    ];
    
    // Add archived_at timestamp
    $archived_at = getCurrentDateTime();
    if (isset($archive_columns['archived_at'])) {
        $columns[] = 'archived_at';
        $placeholders[] = '?';
        $values[] = $archived_at;
        $types .= 's';
    }
    
    // Add other columns
    foreach ($column_mapping as $source_col => $dest_col) {
        if (isset($program[$source_col]) && isset($archive_columns[$dest_col])) {
            $columns[] = $dest_col;
            $placeholders[] = '?';
            $values[] = $program[$source_col];
            
            // Determine the correct type for binding
            if (in_array($source_col, ['id', 'category_id', 'duration', 'slotsAvailable', 'total_slots', 'show_on_index', 'trainer_id'])) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }
    }
    
    if (empty($columns)) {
        error_log("No matching columns found between programs and archive_programs");
        return false;
    }
    
    $sql = "INSERT INTO archive_programs (" . implode(', ', $columns) . ") 
            VALUES (" . implode(', ', $placeholders) . ")";
    
    error_log("Archive SQL: " . $sql);
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for archive: " . $conn->error);
        return false;
    }
    
    // Bind all parameters
    $stmt->bind_param($types, ...$values);
    
    $archiveResult = $stmt->execute();
    $stmt->close();
    
    if (!$archiveResult) {
        error_log("Archive insertion failed: " . $conn->error);
        return false;
    }
    
    // Delete from programs table
    $sql = "DELETE FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed for delete: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $id);
    $deleteResult = $stmt->execute();
    $stmt->close();
    
    error_log("Archive result: " . ($archiveResult && $deleteResult ? 'SUCCESS' : 'FAILED'));
    return $archiveResult && $deleteResult;
}

// UPDATED: restoreProgram function to handle data directly without calling editProgram
function restoreProgram($conn, $data) {
    error_log("=== RESTORE PROGRAM FUNCTION CALLED ===");
    error_log("Restore data: " . print_r($data, true));
    
    $archive_id = $data['id']; // This is the archive_programs ID
    
    // Get archived program data
    $sql = "SELECT * FROM archive_programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $archive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $archived_program = $result->fetch_assoc();
    $stmt->close();
    
    if (!$archived_program) {
        error_log("Archived program not found with ID: " . $archive_id);
        return false;
    }
    
    // Use the data from the form, not from archive
    $programName = trim($data['name']);
    $totalSlots = max(1, intval($data['slotsAvailable']));
    $category_id = $data['category_id'];
    $trainer_id = isset($data['trainer_id']) && $data['trainer_id'] !== '' ? $data['trainer_id'] : NULL;
    
    // Validate program name
    if (empty($programName)) {
        error_log("Program name cannot be empty or just whitespace");
        $_SESSION['error'] = "Program name cannot be empty or contain only spaces!";
        return false;
    }
    
    // Get trainer name if trainer is assigned
    $trainer_name = '';
    if ($trainer_id) {
        $trainer_sql = "SELECT fullname FROM users WHERE id = ? AND role = 'trainer'";
        $trainer_stmt = $conn->prepare($trainer_sql);
        $trainer_stmt->bind_param("i", $trainer_id);
        $trainer_stmt->execute();
        $trainer_result = $trainer_stmt->get_result();
        if ($trainer_row = $trainer_result->fetch_assoc()) {
            $trainer_name = $trainer_row['fullname'];
        }
        $trainer_stmt->close();
    }
    
    // Get current datetime
    $current_time = getCurrentDateTime();
    
    // Calculate duration from dates
    $duration = 1;
    if (isset($data['duration']) && !empty($data['duration'])) {
        $duration = intval($data['duration']);
    } elseif (isset($data['scheduleStart']) && isset($data['scheduleEnd'])) {
        $start = new DateTime($data['scheduleStart']);
        $end = new DateTime($data['scheduleEnd']);
        $duration = $start->diff($end)->days + 1;
    }
    
    // Get show_on_index from archived program or default to 1
    $show_on_index = isset($archived_program['show_on_index']) ? $archived_program['show_on_index'] : 1;
    
    // FIX: Store the created_at value in a variable first
    $created_at = isset($archived_program['created_at']) ? $archived_program['created_at'] : $current_time;
    
    // Build the insert query
    $sql = "INSERT INTO programs (name, category_id, duration, scheduleStart, scheduleEnd, 
            trainer_id, trainer, total_slots, slotsAvailable, show_on_index, 
            status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)";
    
    error_log("Restore SQL: " . $sql);
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for restore insert: " . $conn->error);
        return false;
    }
    
    // Bind parameters - FIXED: Using variables instead of direct expressions
    if ($trainer_id === NULL) {
        $stmt->bind_param("siissisiiiss",
            $programName,
            $category_id,
            $duration,
            $data['scheduleStart'],
            $data['scheduleEnd'],
            $trainer_id,
            $trainer_name,
            $totalSlots,
            $totalSlots,
            $show_on_index,
            $created_at,          // Use variable instead of expression
            $current_time
        );
    } else {
        $stmt->bind_param("siissisiiiss",
            $programName,
            $category_id,
            $duration,
            $data['scheduleStart'],
            $data['scheduleEnd'],
            $trainer_id,
            $trainer_name,
            $totalSlots,
            $totalSlots,
            $show_on_index,
            $created_at,          // Use variable instead of expression
            $current_time
        );
    }
    
    $restoreResult = $stmt->execute();
    $newProgramId = $conn->insert_id;
    $stmt->close();
    
    if (!$restoreResult) {
        error_log("Restore insertion failed: " . $conn->error);
        return false;
    }
    
    error_log("Program restored with ID: $newProgramId");
    
    // Sync slots
    syncProgramSlots($conn, $newProgramId, $totalSlots);
    
    // Delete from archive_programs
    $sql = "DELETE FROM archive_programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $archive_id);
    $deleteResult = $stmt->execute();
    $stmt->close();
    
    // Record history
    recordProgramHistory($conn, $newProgramId, 'restored', 'Program restored from archive');
    
    error_log("Restore result: " . ($restoreResult && $deleteResult ? 'SUCCESS' : 'FAILED'));
    
    return $restoreResult && $deleteResult;
}

// NEW: Function to restore feedback references
function restoreFeedbackReferences($conn, $new_program_id, $original_program_id = null) {
    // If we have an original program ID, restore feedback linked to it
    if ($original_program_id) {
        $sql = "UPDATE feedback 
                SET program_id = ?, 
                    archived_program_id = NULL,
                    program_name_backup = NULL,
                    archived_at = NULL
                WHERE archived_program_id = ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed for feedback restoration: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("ii", $new_program_id, $original_program_id);
        $result = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        if ($result) {
            error_log("Restored $affected_rows feedback record(s) for program $new_program_id (was linked to archived program $original_program_id)");
        } else {
            error_log("Failed to restore feedback references: " . $conn->error);
        }
        
        return $result;
    }
    
    return true;
}

// NEW: Function to restore enrollment references
function restoreEnrollmentReferences($conn, $new_program_id, $original_program_id = null) {
    // If we have an original program ID, restore enrollments linked to it
    if ($original_program_id) {
        $sql = "UPDATE enrollments 
                SET program_id = ?, 
                    archived_program_id = NULL,
                    program_name_backup = NULL
                WHERE archived_program_id = ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed for enrollment restoration: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("ii", $new_program_id, $original_program_id);
        $result = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        if ($result) {
            error_log("Restored $affected_rows enrollment record(s) for program $new_program_id (was linked to archived program $original_program_id)");
        } else {
            error_log("Failed to restore enrollment references: " . $conn->error);
        }
        
        return $result;
    }
    
    return true;
}

// Run the auto-archive check for expired programs
deactivatePastPrograms($conn);



// Check archive_programs table
$check_sql = "SELECT COUNT(*) as count FROM archive_programs";
$result = $conn->query($check_sql);
if ($result) {
    $row = $result->fetch_assoc();
    error_log("Total programs in archive_programs table: " . $row['count']);
}

// Fetch categories with specialization
$categories = [];
$result = $conn->query("SELECT *, COALESCE(specialization, '') as specialization FROM program_categories WHERE status = 'active' ORDER BY is_default DESC, name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
} else {
    error_log("Error fetching categories: " . $conn->error);
}

// Fetch data with auto-fix for slot calculations
$programs = [];

$result = $conn->query("SELECT p.*, pc.name as category_name, pc.specialization as category_specialization 
                        FROM programs p 
                        LEFT JOIN program_categories pc ON p.category_id = pc.id 
                        WHERE p.status = 'active' 
                        ORDER BY p.created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Calculate current enrollments and available slots
        $row['enrolled_count'] = getEnrollmentCount($conn, $row['id']);
        
        // Get slot information using new function (this will auto-fix if needed)
        $slot_data = getProgramSlots($conn, $row['id']);
        $row['totalSlots'] = $slot_data['total_slots'];
        $row['available_slots'] = getAvailableSlots($conn, $row['id']);
        
        // Double-check if still out of sync (should be fixed by auto-fix functions)
        $calculated_available = max(0, $row['totalSlots'] - $row['enrolled_count']);
        $row['slots_out_of_sync'] = ($row['available_slots'] != $calculated_available);
        
        if ($row['slots_out_of_sync']) {
            error_log("CRITICAL: Program {$row['id']} still out of sync after auto-fix! Calculated: $calculated_available, Available: {$row['available_slots']}");
        }
        
        $programs[] = $row;
    }
    error_log("Fetched " . count($programs) . " active programs");
} else {
    error_log("Error fetching programs: " . $conn->error);
}

$archivedPrograms = [];
$result = $conn->query("SELECT ap.*, pc.name as category_name, pc.specialization as category_specialization 
                        FROM archive_programs ap 
                        LEFT JOIN program_categories pc ON ap.category_id = pc.id 
                        ORDER BY ap.archived_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $archivedPrograms[] = $row;
    }
} else {
    error_log("Error fetching archived programs: " . $conn->error);
}

// Use the enhanced function to get trainers with category experience
$trainers = getTrainersWithCategoryExperience($conn);

include '../components/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Management</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 30px; background: white; padding: 15px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .nav-tab { padding: 12px 24px; border: none; background: #f7fafc; border-radius: 10px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }
        .nav-tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); }
        .content-section { display: none; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .content-section.active { display: block; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .filters { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; align-items: center; }
        .view-toggle { display: flex; gap: 8px; margin-left: auto; }
        .view-btn { padding: 10px; border: 2px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; font-size: 18px; }
        .view-btn.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-color: #667eea; }
        .view-btn:hover { border-color: #667eea; }
        .filter-select, .search-input { padding: 12px 15px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: all 0.3s ease; }
        .filter-select:focus, .search-input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .add-btn { background: linear-gradient(135deg, #48bb78, #38a169); color: white; border: none; padding: 12px 24px; border-radius: 10px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
        .add-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4); }
        .program-grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); }
        .program-grid.list-view { grid-template-columns: 1fr; }
        .program-grid.list-view .program-card { display: grid; grid-template-columns: 300px 1fr auto; align-items: center; gap: 30px; padding: 20px 30px; }
        .program-grid.list-view .program-header { margin-bottom: 0; display: flex; flex-direction: column; gap: 10px; }
        .program-grid.list-view .program-title { font-size: 1.1rem; }
        .program-grid.list-view .program-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; }
        .program-grid.list-view .detail-row { border-bottom: none; margin-bottom: 0; padding-bottom: 0; display: flex; flex-direction: column; gap: 4px; }
        .program-grid.list-view .detail-row span:first-child { font-size: 12px; color: #718096; font-weight: 600; }
        .program-grid.list-view .detail-row span:last-child { font-size: 14px; color: #2d3748; font-weight: 500; }
        .program-grid.list-view .program-actions { flex-direction: row; }
        .program-card { background: white; border: 2px solid #e2e8f0; border-radius: 15px; padding: 20px; transition: all 0.3s ease; cursor: pointer; }
        .program-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
        .program-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .program-title { font-size: 1.2rem; font-weight: 600; color: #2d3748; flex: 1; word-break: break-word; }
        .program-actions { display: flex; gap: 8px; }
        .action-btn { padding: 8px; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .edit-btn { background: #48bb78; color: white; }
        .archive-btn { background: #4299e1; color: white; }
        .restore-btn { background: #48bb78; color: white; }
        .delete-btn { background: #f56565; color: white; }
        .reactivate-btn { background: #ed8936; color: white; }
        .action-btn:hover { transform: scale(1.1); }
        .action-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .program-details { color: #4a5568; font-size: 14px; }
        .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #f7fafc; }
        .category-badge { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748; }
        .form-input { width: 100%; padding: 12px 15px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: all 0.3s ease; }
        .form-input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .form-actions { display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px; }
        .btn { padding: 12px 24px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-secondary { background: #a0aec0; color: white; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .empty-state { text-align: center; padding: 60px 20px; color: #718096; }
        .empty-state i { font-size: 4rem; margin-bottom: 20px; opacity: 0.5; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 10px; font-weight: 600; }
        .alert-success { background: #48bb78; color: white; }
        .alert-error { background: #f56565; color: white; }
        .alert-warning { background: #ed8936; color: white; }
        .new-category-group { 
            background: #f7fafc; 
            padding: 15px; 
            border-radius: 10px; 
            border: 2px dashed #cbd5e0; 
            margin-top: 10px;
            display: none; /* Hidden by default */
        }
        .new-category-group.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        .add-category-btn-container {
            margin-top: 10px;
            text-align: center;
        }
        .add-category-btn { 
            background: linear-gradient(135deg, #4299e1, #3182ce); 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 600; 
            transition: all 0.3s ease; 
            margin-top: 10px; 
            width: 100%;
        }
        .add-category-btn:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 5px 15px rgba(66, 153, 225, 0.4); 
        }
        .slot-info {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        .slot-warning {
            color: #e53e3e;
            font-weight: 600;
        }
        .slot-success {
            color: #38a169;
            font-weight: 600;
        }
        .trainer-disabled {
            color: #a0aec0 !important;
            background-color: #f7fafc !important;
            cursor: not-allowed !important;
        }
        .trainer-available {
            color: #38a169 !important;
            font-weight: 600;
        }
        .trainer-warning {
            color: #e53e3e;
            font-size: 12px;
            margin-top: 4px;
            padding: 10px;
            background: #fff5f5;
            border-radius: 8px;
            border-left: 4px solid #e53e3e;
        }
        .trainer-experienced {
            color: #4299e1;
            font-weight: 600;
        }
        .trainer-loading {
            color: #718096 !important;
            font-style: italic;
        }
        /* Switch button styles */
        .switch-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #48bb78;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .switch-label {
            font-size: 14px;
            color: #4a5568;
        }
        .switch-on {
            color: #48bb78;
            font-weight: 600;
        }
        .switch-off {
            color: #a0aec0;
        }
        .index-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .index-status-on {
            background-color: #c6f6d5;
            color: #22543d;
        }
        .index-status-off {
            background-color: #fed7d7;
            color: #742a2a;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-deactivated {
            background-color: #fed7d7;
            color: #742a2a;
        }
        .required-field::after {
            content: " *";
            color: #e53e3e;
        }
        .date-info {
            font-size: 11px;
            color: #718096;
            margin-top: 3px;
            font-style: italic;
        }
        /* History/Feedback Styles */
        .history-section {
            margin-top: 30px;
            background: #f7fafc;
            padding: 20px;
            border-radius: 15px;
        }
        .history-item {
            background: white;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 10px;
            border-left: 4px solid #4299e1;
        }
        .history-item.deleted {
            border-left-color: #e53e3e;
        }
        .history-item.archived {
            border-left-color: #ed8936;
        }
        .history-item.restored {
            border-left-color: #48bb78;
        }
        .history-item.updated {
            border-left-color: #4299e1;
        }
        .history-item.created {
            border-left-color: #38a169;
        }
        .history-date {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }
        .history-action {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        .history-description {
            font-size: 14px;
            color: #4a5568;
        }
        .view-history-btn {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 10px;
            font-size: 12px;
        }
        .view-history-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(66, 153, 225, 0.4);
        }
        @media (max-width: 768px) {
            .program-grid { grid-template-columns: 1fr; }
            .program-grid.list-view .program-card { grid-template-columns: 1fr; gap: 15px; }
            .program-grid.list-view .program-header, .program-grid.list-view .program-details { width: 100%; }
            .program-grid.list-view .program-details { grid-template-columns: 1fr; }
            .filters { flex-direction: column; }
            .view-toggle { margin-left: 0; width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Program Management</h1>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success"><?= $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['debug'])): ?>
            <div class="alert alert-warning">Debug: <?= $_SESSION['debug']; unset($_SESSION['debug']); ?></div>
        <?php endif; ?>

        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showSection('active-programs')"> Active Programs</button>
            <button class="nav-tab" onclick="showSection('archived-programs')"> Archived Programs</button>
        </div>

        <!-- Active Programs Section -->
        <div id="active-programs" class="content-section active">
            <div class="filters">
                <input type="text" class="search-input" id="searchInput" placeholder="🔍 Search program name...">
                <button class="add-btn" onclick="openProgramModal()"><span> Add New Program</span></button>
                <div class="view-toggle">
                    <button class="view-btn" onclick="toggleView('card')" id="cardViewBtn" title="Card View">▦</button>
                    <button class="view-btn active" onclick="toggleView('list')" id="listViewBtn" title="List View">☰</button>
                </div>
            </div>

            <div class="program-grid list-view" id="activeProgramsGrid">
                <?php foreach ($programs as $program): ?>
                <div class="program-card" onclick="viewProgram(<?= htmlspecialchars(json_encode($program), ENT_QUOTES, 'UTF-8') ?>)">
                    <div class="program-header">
                        <div>
                            <h3 class="program-title"><?= htmlspecialchars($program['name']) ?></h3>
                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 5px;">
                                <div class="category-badge"><?= !empty($program['category_name']) ? htmlspecialchars($program['category_name']) : 'Uncategorized' ?></div>
                                <div class="index-status <?= $program['show_on_index'] ? 'index-status-on' : 'index-status-off' ?>">
                                    <?= $program['show_on_index'] ? ' Visible' : ' Hidden' ?>
                                </div>
                            </div>
                            <div class="date-info">
                                Created: <?= date('M d, Y', strtotime($program['created_at'])) ?> | 
                                Updated: <?= date('M d, Y', strtotime($program['updated_at'])) ?>
                            </div>
                        </div>
                        <div class="program-actions">
                            <label class="switch" onclick="event.stopPropagation();">
                                <input type="checkbox" <?= $program['show_on_index'] ? 'checked' : '' ?> 
                                       onchange="toggleShowOnIndex(<?= $program['id'] ?>, this.checked)">
                                <span class="slider"></span>
                            </label>
                            <button class="action-btn edit-btn" 
                                    onclick="event.stopPropagation(); editProgram(<?= htmlspecialchars(json_encode($program), ENT_QUOTES, 'UTF-8') ?>)" 
                                    title="Edit"
                                    <?= $program['enrolled_count'] > 0 ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                                ✏️
                            </button>
                            <button class="action-btn archive-btn" onclick="event.stopPropagation(); archiveProgram(<?= $program['id'] ?>)" title="Archive">📦</button>
                        </div>
                    </div>
                    <div class="program-details">
                        <div class="detail-row"><span>Duration:</span><span><?= $program['duration'] ?> Days</span></div>
                        <div class="detail-row"><span>Schedule:</span><span><?= date('M d, Y', strtotime($program['scheduleStart'])) ?> - <?= date('M d, Y', strtotime($program['scheduleEnd'])) ?></span></div>
                        <div class="detail-row">
                            <span>Capacity:</span>
                            <span>
                                <strong><?= $program['enrolled_count'] ?></strong> Enrolled / 
                                <strong><?= $program['available_slots'] ?></strong> Available Slots
                                <div class="slot-info">(Total capacity: <?= $program['totalSlots'] ?>)</div>
                                <?php if ($program['slots_out_of_sync']): ?>
                                <div class="slot-warning">⚠️ Needs manual review!</div>
                                <?php elseif ($program['available_slots'] == 0): ?>
                                <div class="slot-warning">Fully booked</div>
                                <?php elseif ($program['available_slots'] <= 3): ?>
                                <div class="slot-warning">Only <?= $program['available_slots'] ?> slot(s) left!</div>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="detail-row"><span>Trainer:</span><span><?= !empty($program['trainer']) ? htmlspecialchars($program['trainer']) : '<em style="color: #a0aec0;">No trainer assigned</em>' ?></span></div>
                        <?php if (!empty($program['category_specialization'])): ?>
                        <div class="detail-row"><span>Specialization:</span><span><?= htmlspecialchars($program['category_specialization']) ?></span></div>
                        <?php endif; ?>
                    </div>
                   
                </div>
                <?php endforeach; ?>
                <?php if (empty($programs)): ?>
                <div class="empty-state"><div>📚</div><h3>No Active Programs</h3><p>Get started by adding your first program!</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Archived Programs Section -->
        <div id="archived-programs" class="content-section">
            <div class="filters">
                <input type="text" class="search-input" id="archiveSearch" placeholder="🔍 Search archived programs...">
                <div class="view-toggle">
                    <button class="view-btn" onclick="toggleArchiveView('card')" id="archiveCardViewBtn" title="Card View">▦</button>
                    <button class="view-btn active" onclick="toggleArchiveView('list')" id="archiveListViewBtn" title="List View">☰</button>
                </div>
            </div>

            <div class="program-grid list-view" id="archivedProgramsGrid">
                <?php foreach ($archivedPrograms as $program): ?>
                <div class="program-card">
                    <div class="program-header">
                        <div><h3 class="program-title"><?= htmlspecialchars($program['name']) ?></h3>
                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 5px;">
                                <div class="category-badge"><?= !empty($program['category_name']) ? htmlspecialchars($program['category_name']) : 'Uncategorized' ?></div>
                                <?php if (isset($program['show_on_index'])): ?>
                                <div class="index-status <?= $program['show_on_index'] ? 'index-status-on' : 'index-status-off' ?>">
                                    <?= $program['show_on_index'] ? ' Visible' : ' Hidden' ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="date-info">
                                Archived: <?= isset($program['archived_at']) ? date('M d, Y', strtotime($program['archived_at'])) : 'N/A' ?>
                            </div>
                        </div>
                        <div class="program-actions">
                            <button class="action-btn restore-btn" onclick="restoreProgram(<?= $program['id'] ?>)" title="Edit & Restore">↶</button>
                            <button class="action-btn delete-btn" onclick="deleteProgram(<?= $program['id'] ?>)" title="Delete">🗑️</button>
                        </div>
                    </div>
                    <div class="program-details">
                        <div class="detail-row"><span>Duration:</span><span><?= isset($program['duration']) ? $program['duration'] : 'N/A' ?> Days</span></div>
                        <div class="detail-row"><span>Schedule:</span><span><?php if (isset($program['scheduleStart']) && isset($program['scheduleEnd'])): ?><?= date('M d, Y', strtotime($program['scheduleStart'])) ?> - <?= date('M d, Y', strtotime($program['scheduleEnd'])) ?><?php else: ?>N/A<?php endif; ?></span></div>
                        <div class="detail-row"><span>Total Slots:</span><span><?= isset($program['total_slots']) ? $program['total_slots'] : (isset($program['totalSlots']) ? $program['totalSlots'] : (isset($program['slotsAvailable']) ? $program['slotsAvailable'] : 'N/A')) ?></span></div>
                        <div class="detail-row"><span>Trainer:</span><span><?= !empty($program['trainer']) ? htmlspecialchars($program['trainer']) : 'No trainer assigned' ?></span></div>
                        <?php if (!empty($program['category_specialization'])): ?>
                        <div class="detail-row"><span>Specialization:</span><span><?= htmlspecialchars($program['category_specialization']) ?></span></div>
                        <?php endif; ?>
                        <div class="detail-row"><span>Status:</span><span style="color: #718096;">Archived</span></div>
                    </div>
                   
                </div>
                <?php endforeach; ?>
                <?php if (empty($archivedPrograms)): ?>
                <div class="empty-state"><div>📦</div><h3>No Archived Programs</h3><p>Archived programs will appear here</p></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Debug Tools Section (Hidden by default) -->
        <div style="margin-top: 20px; padding: 10px; background: #f7fafc; border-radius: 10px; display: none;" id="debugSection">
            <h3>Debug Tools</h3>
            <button onclick="testDeleteFunction()" style="background: #e53e3e; color: white; padding: 10px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">
                Test Delete Function
            </button>
            <button onclick="testTrainerLoading()" style="background: #4299e1; color: white; padding: 10px; border: none; border-radius: 5px; cursor: pointer;">
                Test Trainer Loading
            </button>
        </div>
    </div>

    <!-- Program Form Modal -->
    <div id="programModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add New Program</h2>
            <form id="programForm" method="POST" action="">
                <input type="hidden" name="action" id="formAction" value="add_program">
                <input type="hidden" name="id" id="programId">
                <input type="hidden" name="cleanup_enrollments" id="cleanupEnrollments" value="keep">
                
                <div class="form-group">
                    <label class="form-label required-field">Program Title:</label>
                    <input type="text" name="name" id="programName" class="form-input" required>
                    <small style="color: #718096; font-size: 12px;">Maximum 255 characters. Cannot be empty or just spaces. Must be unique across all programs.</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">Category:</label>
                    <select name="category_id" id="programCategory" class="form-input" required onchange="toggleNewCategoryField(); updateTrainerOptions();">
                        <option value="">Select Category</option>
                        <optgroup label="Default Categories">
                            <?php $defaultCategories = array_filter($categories, function($cat) { return $cat['is_default']; });
                            foreach ($defaultCategories as $category): ?>
                                <option value="<?= $category['id'] ?>" data-specialization="<?= htmlspecialchars($category['specialization']) ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php $customCategories = array_filter($categories, function($cat) { return !$cat['is_default']; });
                        if (!empty($customCategories)): ?>
                        <optgroup label="Custom Categories">
                            <?php foreach ($customCategories as $category): ?>
                                <option value="<?= $category['id'] ?>" data-specialization="<?= htmlspecialchars($category['specialization']) ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <option value="new"> Add New Category</option>
                    </select>
                </div>
                
                <!-- NEW CATEGORY FORM -->
                <div id="newCategoryGroup" class="new-category-group">
                    <div class="form-group">
                        <label class="form-label required-field">New Category Name:</label>
                        <input type="text" name="new_category_name" id="newCategoryName" class="form-input" placeholder="Enter new category name" maxlength="255" required>
                    </div>
                    <div class="add-category-btn-container">
                        <button type="button" class="add-category-btn" onclick="addNewCategory()"> Add This Category</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">Duration (Days):</label>
                    <input type="number" name="duration" id="durationInput" class="form-input" min="1" required>
                    <small style="color: #718096; font-size: 12px;"> Will auto-update dates when changed</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">Start Date:</label>
                    <input type="date" name="scheduleStart" id="scheduleStart" class="form-input" required>
                    <small style="color: #718096; font-size: 12px;" id="startDateInfo">Must be at least 7 days from today</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">End Date:</label>
                    <input type="date" name="scheduleEnd" id="scheduleEnd" class="form-input" required>
                    <small style="color: #718096; font-size: 12px;">Calculated from start date + duration</small>
                </div>
                
                <!-- Show on index page option removed - all programs now visible by default -->
                
                <div class="form-group">
                    <label class="form-label required-field">Primary Trainer:</label>
                    <select name="trainer_id" id="programTrainer" class="form-input" required>
                        <option value="">No Trainer Assigned</option>
                        <!-- Trainer options will be dynamically populated -->
                    </select>
                    <div id="trainerWarning" class="trainer-warning" style="display: none;"></div>
                    <small style="color: #718096; font-size: 12px;">
                        • Only trainers with EXACT matching specialization are shown<br>
                        • Trainers with active programs are COMPLETELY HIDDEN<br>
                        • Trainers in same category (different program) are DISABLED<br>
                        • "No Trainer Assigned" is allowed
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">Total Slots Available:</label>
                    <input type="number" name="slotsAvailable" id="programSlots" class="form-input" min="1" required>
                    <small style="color: #718096; font-size: 12px;">Maximum number of participants allowed</small>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeProgramModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveButton">Save Program</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Store trainers data for JavaScript use
        const trainersData = <?= json_encode($trainers) ?>;

        function getCurrentDateTime() {
            const now = new Date();
            // Format as YYYY-MM-DD HH:MM:SS
            return now.toISOString().slice(0, 19).replace('T', ' ');
        }

// Or using Intl.DateTimeFormat for better formatting:
        function getCurrentDateTime() {
            return new Intl.DateTimeFormat('en-CA', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            }).format(new Date()).replace(',', '');
        }
                
        // Function to calculate minimum start date (7 days from today)
        function calculateMinStartDate() {
            const today = new Date();
            const minDate = new Date(today);
            minDate.setDate(today.getDate() + 7);
            return minDate.toISOString().split('T')[0];
        }
        
        // Function to view program history
        async function viewProgramHistory(programId) {
            try {
                const response = await fetch(`?get_program_history=${programId}`);
                const history = await response.json();
                
                if (!history || history.length === 0) {
                    Swal.fire('No History', 'No history found for this program.', 'info');
                    return;
                }
                
                let historyHtml = '<div style="text-align: left; max-height: 400px; overflow-y: auto;">';
                history.forEach(item => {
                    const date = new Date(item.created_at).toLocaleString();
                    let actionClass = '';
                    let actionText = '';
                    
                    switch(item.action) {
                        case 'created':
                            actionClass = 'created';
                            actionText = 'Created';
                            break;
                        case 'updated':
                            actionClass = 'updated';
                            actionText = 'Updated';
                            break;
                        case 'archived':
                        case 'auto_archived':
                            actionClass = 'archived';
                            actionText = 'Archived';
                            break;
                        case 'restored':
                            actionClass = 'restored';
                            actionText = 'Restored';
                            break;
                        case 'permanently_deleted':
                            actionClass = 'deleted';
                            actionText = 'Permanently Deleted';
                            break;
                        case 'visibility_changed':
                            actionClass = 'updated';
                            actionText = 'Visibility Changed';
                            break;
                        case 'reactivated':
                            actionClass = 'restored';
                            actionText = 'Reactivated';
                            break;
                        default:
                            actionClass = 'updated';
                            actionText = item.action;
                    }
                    
                    historyHtml += `
                        <div class="history-item ${actionClass}" style="margin-bottom: 10px; padding: 10px; border-left: 4px solid;">
                            <div class="history-date">${date}</div>
                            <div class="history-action"><strong>${actionText}</strong></div>
                            <div class="history-description">${item.description}</div>
                        </div>
                    `;
                });
                historyHtml += '</div>';
                
                Swal.fire({
                    title: 'Program History',
                    html: historyHtml,
                    width: 600,
                    showCloseButton: true,
                    showConfirmButton: false
                });
            } catch (error) {
                console.error('Error fetching program history:', error);
                Swal.fire('Error!', 'Failed to load program history.', 'error');
            }
        }
        
        // Function to delete a program from archive_programs
        async function deleteProgram(programId) {
            try {
                const { value: confirmDelete } = await Swal.fire({ 
                    title: 'Delete Program Permanently?', 
                    html: '<strong style="color: #e53e3e;">WARNING:</strong> This action cannot be undone!<br>The program will be permanently deleted from the archive.', 
                    icon: 'warning', 
                    showCancelButton: true, 
                    confirmButtonColor: '#e53e3e', 
                    cancelButtonColor: '#3085d6', 
                    confirmButtonText: 'Yes, Delete Permanently!',
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false
                });
                
                if (!confirmDelete) return; // User cancelled
                
                // Show loading state
                Swal.fire({
                    title: 'Deleting...',
                    text: 'Please wait while we delete the program',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                const formData = new FormData(); 
                formData.append('action', 'delete_program'); 
                formData.append('id', programId);
                
                const response = await fetch('', { 
                    method: 'POST', 
                    body: formData 
                });
                
                if (response.ok) { 
                    Swal.fire({
                        title: 'Deleted!',
                        text: 'Program has been permanently deleted.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    }); 
                    setTimeout(() => location.reload(), 1500); 
                } else { 
                    throw new Error('Delete failed');
                }
            } catch (error) { 
                console.error('Error:', error); 
                Swal.fire('Error!', 'Failed to delete program. Please check console for details.', 'error'); 
            }
        }
        
       // Function to restore 
     async function restoreProgram(programId) {
            try {
                const { value: confirmed } = await Swal.fire({
                    title: 'Restore Program',
                    html: `<div style="text-align: left; margin: 10px 0;">
                            <p><strong>Are you sure you want to restore this program?</strong></p>
                            <p>This will move the program back to the active programs list.</p>
                        </div>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#48bb78',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Restore',
                    cancelButtonText: 'Cancel',
                    focusConfirm: true
                });
                
                if (!confirmed) return; // User cancelled
                
                const response = await fetch(`?get_archived_program=${programId}`);
                const program = await response.json();
                
                if (!program || !program.id) {
                    Swal.fire('Error!', 'Failed to load program data.', 'error');
                    return;
                }
                
                // Open modal with restore flag
                openProgramModal(program, 'restore');
            } catch (error) {
                console.error('Error fetching program data:', error);
                Swal.fire('Error!', 'Failed to load program data.', 'error');
            }
        }

         // Function to archive a program with enrollment validation
             async function archiveProgram(programId) {
                    try {
                        // First check if program has enrollments
                        const response = await fetch(`?get_program=${programId}`);
                        const program = await response.json();
                        
                        if (program && program.enrolled_count && program.enrolled_count > 0) {
                            // Show warning about enrollments
                            await Swal.fire({ 
                                title: 'Cannot Archive Program', 
                                html: `This program has <strong>${program.enrolled_count} active enrollment(s)</strong>.<br><br>
                                    <strong>Options:</strong><br>
                                        Wait until all enrollments are completed`,
                                icon: 'warning',
                                showCancelButton: false,
                                confirmButtonColor: '#3085d6',
                                confirmButtonText: 'OK'
                            });
                            return;
                        }
                        
                        // No enrollments, proceed with archiving
                        const { value: confirmArchive } = await Swal.fire({ 
                            title: 'Archive Program?', 
                            text: 'This will move the program to the archive.', 
                            icon: 'question', 
                            showCancelButton: true, 
                            confirmButtonColor: '#3085d6', 
                            cancelButtonColor: '#d33', 
                            confirmButtonText: 'Yes, Archive!' 
                        });
                        
                        if (!confirmArchive) return;
                        
                        // Show loading state
                        Swal.fire({
                            title: 'Archiving...',
                            text: 'Please wait while we archive the program',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        const formData = new FormData(); 
                        formData.append('action', 'archive_program'); 
                        formData.append('id', programId);
                        
                        const archiveResponse = await fetch('', { 
                            method: 'POST', 
                            body: formData 
                        });
                        
                        if (archiveResponse.ok) { 
                            Swal.fire({
                                title: 'Archived!',
                                text: 'Program has been archived.',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            }); 
                            setTimeout(() => location.reload(), 1500); 
                        } else { 
                            throw new Error('Archive failed');
                        }
                    } catch (error) { 
                        console.error('Error:', error); 
                        Swal.fire('Error!', 'Failed to archive program.', 'error'); 
                    }
                }
        
        // Function to toggle show_on_index status
        async function toggleShowOnIndex(programId, isChecked) {
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_show_on_index');
                formData.append('id', programId);
                formData.append('show_on_index', isChecked);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    const statusElement = document.querySelector(`input[onchange="toggleShowOnIndex(${programId}, this.checked)"]`).closest('.program-card').querySelector('.index-status');
                    if (statusElement) {
                        statusElement.textContent = isChecked ? ' Visible' : ' Hidden';
                        statusElement.className = `index-status ${isChecked ? 'index-status-on' : 'index-status-off'}`;
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        text: `Program ${isChecked ? 'shown' : 'hidden'} on index page.`,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    throw new Error('Failed to update');
                }
            } catch (error) {
                console.error('Error toggling show_on_index:', error);
                Swal.fire('Error!', 'Failed to update program visibility.', 'error');
                const checkbox = document.querySelector(`input[onchange="toggleShowOnIndex(${programId}, this.checked)"]`);
                checkbox.checked = !isChecked;
            }
        }
        
        // editProgram function with enrollment check
        function editProgram(program) { 
            // Check if program has enrollments
            if (program.enrolled_count && program.enrolled_count > 0) {
                Swal.fire({
                    title: 'Cannot Edit Program',
                    html: `This program has <strong>${program.enrolled_count} enrollment(s)</strong>.<br><br>
                           <strong>Options:</strong><br>
                           • Wait until all enrollments are completed<br>
                           • Archive the program and create a new one`,
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            openProgramModal(program); 
        }
        
        // Function to update trainer options with better error handling
        async function updateTrainerOptions() {
            const categorySelect = document.getElementById('programCategory');
            const trainerSelect = document.getElementById('programTrainer');
            const trainerWarning = document.getElementById('trainerWarning');
            const programId = document.getElementById('programId').value;
            
            const categoryId = categorySelect.value;
            
            // Show loading state
            trainerSelect.innerHTML = '<option value="" class="trainer-loading">Loading available trainers...</option>';
            trainerWarning.style.display = 'none';
            
            if (categoryId === '' || categoryId === 'new') {
                trainerSelect.innerHTML = '<option value="">No Trainer Assigned</option>';
                trainerWarning.style.display = 'none';
                return;
            }
            
            try {
                // Get selected category option to check specialization
                const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                const categorySpecialization = selectedOption.getAttribute('data-specialization') || '';
                
                let url = `?get_trainers_for_category=1&category_id=${categoryId}`;
                if (programId) {
                    url += `&program_id=${programId}`;
                }
                
                console.log('Fetching trainers from:', url);
                
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Trainer data received:', data);
                
                if (!data || typeof data !== 'object') {
                    throw new Error('Invalid response data');
                }
                
                const { trainers = [], category_busy_trainers = [], category_specialization = '' } = data;
                
                // Clear and rebuild trainer dropdown
                trainerSelect.innerHTML = '<option value="">No Trainer Assigned</option>';
                
                // Group trainers
                const availableTrainers = [];
                const categoryBusyTrainersList = [];
                const currentTrainer = programId ? await getCurrentProgramTrainer(programId) : null;
                
                // Process trainers array
                if (Array.isArray(trainers) && trainers.length > 0) {
                    trainers.forEach(trainer => {
                        if (!trainer || !trainer.id) return; // Skip invalid entries
                        
                        const isCategoryBusy = Array.isArray(category_busy_trainers) && 
                                              category_busy_trainers.includes(parseInt(trainer.id));
                        const isCurrentTrainer = currentTrainer && 
                                               parseInt(trainer.id) === parseInt(currentTrainer.id);
                        
                        const trainerOption = {
                            id: trainer.id,
                            name: trainer.fullname || 'Unknown Trainer',
                            specialization: trainer.specialization || '',
                            categoryBusy: isCategoryBusy,
                            current: isCurrentTrainer
                        };
                        
                        // Trainers already busy in this category (but not current program's trainer)
                        if (isCategoryBusy && !isCurrentTrainer) {
                            categoryBusyTrainersList.push(trainerOption);
                        } else {
                            availableTrainers.push(trainerOption);
                        }
                    });
                }
                
                // Add current trainer as special option if not already in list
                if (currentTrainer && !availableTrainers.some(t => t.id == currentTrainer.id) && 
                    !categoryBusyTrainersList.some(t => t.id == currentTrainer.id)) {
                    availableTrainers.push({
                        id: currentTrainer.id,
                        name: currentTrainer.fullname,
                        specialization: currentTrainer.specialization || '',
                        categoryBusy: false,
                        current: true,
                        isCurrent: true
                    });
                }
                
                // Add available trainers first
                if (availableTrainers.length > 0) {
                    const optgroup = document.createElement('optgroup');
                    let labelText = '';
                    
                    if (category_specialization) {
                        labelText = ` Available ${category_specialization} Specialists (${availableTrainers.length})`;
                    } else {
                        labelText = ` Available General Trainers (${availableTrainers.length})`;
                    }
                    
                    optgroup.label = labelText;
                    
                    availableTrainers.forEach(trainer => {
                        const option = document.createElement('option');
                        option.value = trainer.id;
                        let displayName = trainer.name;
                        if (trainer.specialization) {
                            displayName += ` [${trainer.specialization}]`;
                        }
                        if (trainer.isCurrent) {
                            displayName += ' (Current Trainer)';
                        }
                        option.textContent = displayName;
                        option.setAttribute('data-trainer-name', trainer.name);
                        option.setAttribute('data-specialization', trainer.specialization);
                        if (!trainer.isCurrent) {
                            option.classList.add('trainer-available');
                        }
                        optgroup.appendChild(option);
                    });
                    trainerSelect.appendChild(optgroup);
                }
                
                // Add category busy trainers (disabled)
                if (categoryBusyTrainersList.length > 0) {
                    const optgroup = document.createElement('optgroup');
                    let labelText = '';
                    
                    if (category_specialization) {
                        labelText = `${category_specialization} Specialists already in this category (${categoryBusyTrainersList.length})`;
                    } else {
                        labelText = ` General Trainers already in this category (${categoryBusyTrainersList.length})`;
                    }
                    
                    optgroup.label = labelText;
                    
                    categoryBusyTrainersList.forEach(trainer => {
                        const option = document.createElement('option');
                        option.value = trainer.id;
                        let displayName = trainer.name;
                        if (trainer.specialization) {
                            displayName += ` [${trainer.specialization}]`;
                        }
                        displayName += ' (Already in this category)';
                        option.textContent = displayName;
                        option.disabled = true;
                        option.classList.add('trainer-disabled');
                        optgroup.appendChild(option);
                    });
                    trainerSelect.appendChild(optgroup);
                }
                
                // Show warning if no trainers are available
                if (availableTrainers.length === 0 || (availableTrainers.length === 1 && availableTrainers[0].isCurrent)) {
                    if (category_specialization) {
                        trainerWarning.innerHTML = `
                            ⚠️ No available ${category_specialization} specialists found.<br>
                            <strong>Requirements:</strong><br>
                            • Must have EXACT specialization: <strong>${category_specialization}</strong><br>
                            • Must not be assigned to any other program<br><br>
                            <strong>Options:</strong><br>
                            • Select 'No Trainer Assigned'<br>
                            • Add a new trainer with ${category_specialization} specialization<br>
                            • Change category to one without specialization
                        `;
                    } else {
                        trainerWarning.innerHTML = `
                            ⚠️ No available general trainers found.<br>
                            <strong>Requirements:</strong><br>
                            • Must have NO specialization (general trainer)<br>
                            • Must not be assigned to any other program<br><br>
                            <strong>Options:</strong><br>
                            • Select 'No Trainer Assigned'<br>
                            • Add a new general trainer<br>
                            • Change to a category with specialization
                        `;
                    }
                    trainerWarning.style.display = 'block';
                } else {
                    trainerWarning.style.display = 'none';
                }
                
                // Auto-select current trainer if editing
                if (currentTrainer && currentTrainer.id) {
                    trainerSelect.value = currentTrainer.id;
                }
                
            } catch (error) {
                console.error('Error fetching trainers:', error);
                trainerSelect.innerHTML = '<option value="">Error loading trainers</option>';
                trainerWarning.textContent = ' Error loading trainers. Please try again.';
                trainerWarning.style.display = 'block';
            }
        }
        
        // Helper function to get current program's trainer
        async function getCurrentProgramTrainer(programId) {
            try {
                if (!programId) return null;
                
                const response = await fetch(`?get_program=${programId}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const program = await response.json();
                
                if (program && program.trainer_id) {
                    return {
                        id: program.trainer_id,
                        fullname: program.trainer || 'Unknown Trainer',
                        specialization: program.category_specialization || ''
                    };
                }
                return null;
            } catch (error) {
                console.error('Error fetching current trainer:', error);
                return null;
            }
        }

        // Section Management
        function showSection(sectionId) {
            document.querySelectorAll('.content-section').forEach(section => section.classList.remove('active'));
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            event.target.classList.add('active');
        }

        function toggleNewCategoryField() {
            const categorySelect = document.getElementById('programCategory');
            const newCategoryGroup = document.getElementById('newCategoryGroup');
            const newCategoryInput = document.getElementById('newCategoryName');
            
            if (categorySelect.value === 'new') {
                newCategoryGroup.classList.add('active');
                newCategoryInput.required = true;
                newCategoryInput.value = '';
            } else {
                newCategoryGroup.classList.remove('active');
                newCategoryInput.required = false;
                newCategoryInput.value = '';
            }
        }

        // Function to add a new category
        function addNewCategory() {
            const categoryName = document.getElementById('newCategoryName').value.trim();
            
            if (!categoryName) {
                Swal.fire('Error!', 'Please enter a category name.', 'error');
                return;
            }
            
            if (categoryName.length > 255) {
                Swal.fire('Error!', 'Category name must be less than 255 characters.', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_category');
            formData.append('category_name', categoryName);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const categorySelect = document.getElementById('programCategory');
                    
                    let customOptgroup = null;
                    const optgroups = categorySelect.getElementsByTagName('optgroup');
                    
                    for (let i = 0; i < optgroups.length; i++) {
                        if (optgroups[i].label === 'Custom Categories') {
                            customOptgroup = optgroups[i];
                            break;
                        }
                    }
                    
                    if (!customOptgroup) {
                        customOptgroup = document.createElement('optgroup');
                        customOptgroup.label = 'Custom Categories';
                        const addNewOption = categorySelect.querySelector('option[value="new"]');
                        categorySelect.insertBefore(customOptgroup, addNewOption);
                    }
                    
                    const option = document.createElement('option');
                    option.value = data.category_id;
                    option.textContent = categoryName;
                    option.setAttribute('data-specialization', categoryName);
                    customOptgroup.appendChild(option);
                    
                    categorySelect.value = data.category_id;
                    document.getElementById('newCategoryGroup').classList.remove('active');
                    document.getElementById('newCategoryName').value = '';
                    
                    updateTrainerOptions();
                    
                    Swal.fire('Success!', 'Category added successfully!', 'success');
                } else {
                    Swal.fire('Error!', data.message || 'Failed to add category.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'Failed to add category.', 'error');
            });
        }

        // MODIFIED: Now accepts an action parameter for restore with date handling
        function openProgramModal(program = null, action = null) {
            const modal = document.getElementById('programModal');
            const form = document.getElementById('programForm');
            const title = document.getElementById('modalTitle');
            const saveButton = document.getElementById('saveButton');
            const cleanupEnrollments = document.getElementById('cleanupEnrollments');
            
            // Check if program has enrollments (for edit)
            if (program && program.enrolled_count && program.enrolled_count > 0) {
                Swal.fire({
                    title: 'Cannot Edit Program',
                    html: `This program has <strong>${program.enrolled_count} enrollment(s)</strong>.<br>
                           Programs with enrollments cannot be edited.<br><br>
                           <strong>Options:</strong><br>
                           • Wait until all enrollments are completed<br>
                           • Archive the program and create a new one`,
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                closeProgramModal();
                return;
            }
            
            // Set cleanup enrollments based on action
            if (program && program.cleanup_enrollments) {
                cleanupEnrollments.value = program.cleanup_enrollments;
            } else {
                cleanupEnrollments.value = action === 'restore' ? 'delete' : 'keep';
            }
            
            // Calculate today's date
            const today = new Date().toISOString().split('T')[0];
            const minStartDate = calculateMinStartDate();
            const startDateInput = document.getElementById('scheduleStart');
            const endDateInput = document.getElementById('scheduleEnd');
            const startDateInfo = document.getElementById('startDateInfo');
            
            // Reset the category form
            toggleNewCategoryField();
            
            if (program) {
                // Special handling for restore action
                if (action === 'restore') {
                    title.textContent = 'Edit & Restore Program';
                    document.getElementById('formAction').value = 'restore_program';
                    saveButton.textContent = 'Save & Restore';
                    
                    // For restore: set minimum dates to today
                    startDateInput.min = today;
                    endDateInput.min = today;
                    startDateInfo.textContent = 'Select today or a future date';
                    
                    // Auto-calculate duration from schedule dates
                    if (program.scheduleStart && program.scheduleEnd) {
                        const start = new Date(program.scheduleStart);
                        const end = new Date(program.scheduleEnd);
                        const diffTime = end - start;
                        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                        if (diffDays > 0) {
                            program.duration = diffDays;
                        }
                    }
                    
                } else {
                    title.textContent = 'Edit Program';
                    document.getElementById('formAction').value = 'edit_program';
                    saveButton.textContent = 'Save Program';
                    
                    // For normal edit: set minimum dates to today (cannot set past dates)
                    startDateInput.min = today;
                    endDateInput.min = today;
                    startDateInfo.textContent = 'Select today or a future date';
                }
                
                document.getElementById('programId').value = program.id;
                document.getElementById('programName').value = program.name;
                document.getElementById('programCategory').value = program.category_id || '';
                document.getElementById('durationInput').value = program.duration;
                document.getElementById('scheduleStart').value = program.scheduleStart;
                document.getElementById('scheduleEnd').value = program.scheduleEnd;
                document.getElementById('programSlots').value = program.totalSlots || program.slotsAvailable;
                
                // Set trainer value after a delay to ensure options are loaded
                setTimeout(() => {
                    updateTrainerOptions().then(() => {
                        const trainerSelect = document.getElementById('programTrainer');
                        if (program.trainer_id || program.trainer_id === '') {
                            trainerSelect.value = program.trainer_id;
                        }
                    });
                }, 100);
            } else {
                title.textContent = 'Add New Program';
                document.getElementById('formAction').value = 'add_program';
                saveButton.textContent = 'Save Program';
                form.reset();
                document.getElementById('programCategory').value = '';
                document.getElementById('programTrainer').value = '';
                
                // Set start date to minimum allowed (7 days from today)
                startDateInput.min = minStartDate;
                startDateInfo.textContent = `Must be at least 7 days from today (Minimum: ${minStartDate})`;
                document.getElementById('scheduleStart').value = minStartDate;
                calculateEndDate();
                
                // Update trainer options
                updateTrainerOptions();
            }
            
            modal.classList.add('active');
        }

        function calculateEndDate() {
            const startDate = document.getElementById('scheduleStart').value;
            const duration = parseInt(document.getElementById('durationInput').value) || 1;
            
            if (startDate && duration > 0) {
                const start = new Date(startDate);
                start.setDate(start.getDate() + duration - 1);
                const endDate = start.toISOString().split('T')[0];
                document.getElementById('scheduleEnd').value = endDate;
                document.getElementById('scheduleEnd').min = startDate;
            }
        }

        function calculateDurationFromDates() {
            const startDate = document.getElementById('scheduleStart').value;
            const endDate = document.getElementById('scheduleEnd').value;
            const durationInput = document.getElementById('durationInput');
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                // Ensure end date is not before start date
                if (end < start) {
                    Swal.fire('Error!', 'End date cannot be before start date!', 'error');
                    // Reset end date to start date
                    document.getElementById('scheduleEnd').value = startDate;
                    durationInput.value = 1;
                    return;
                }
                
                const diffTime = end - start;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                
                if (diffDays > 0) {
                    durationInput.value = diffDays;
                }
            }
        }

        function closeProgramModal() {
            document.getElementById('programModal').classList.remove('active');
            document.getElementById('newCategoryGroup').classList.remove('active');
            document.getElementById('newCategoryName').value = '';
        }

        function viewProgram(program) {
            const showOnIndexStatus = program.show_on_index == 1 ? 
                '<span style="color: #38a169; font-weight: 600;">✅ Visible on index page</span>' : 
                '<span style="color: #e53e3e; font-weight: 600;">❌ Hidden from index page</span>';
            
            let specializationHtml = '';
            if (program.category_specialization) {
                specializationHtml = `<p><strong>Specialization:</strong> ${program.category_specialization}</p>`;
            }
            
            Swal.fire({
                title: program.name,
                html: `<div style="text-align: left;">
                    <p><strong>Category:</strong> ${program.category_name || 'N/A'}</p>
                    ${specializationHtml}
                    <p><strong>Duration:</strong> ${program.duration} Days</p>
                    <p><strong>Schedule:</strong> ${new Date(program.scheduleStart).toLocaleDateString()} - ${new Date(program.scheduleEnd).toLocaleDateString()}</p>
                    <p><strong>Capacity:</strong> ${program.enrolled_count} enrolled / ${program.available_slots} available (Total: ${program.totalSlots})</p>
                    <p><strong>Trainer:</strong> ${program.trainer || 'No trainer assigned'}</p>
                    <p><strong>Show on Index:</strong> ${showOnIndexStatus}</p>
                    <p><strong>Created:</strong> ${new Date(program.created_at).toLocaleDateString()}</p>
                    <p><strong>Last Updated:</strong> ${new Date(program.updated_at).toLocaleDateString()}</p>
                    <p><strong>Status:</strong> ${program.status}</p>
                   
                </div>`,
                icon: 'info',
                confirmButtonText: 'Close',
                showCloseButton: true
            });
        }

        function toggleView(viewType) {
            const grid = document.getElementById('activeProgramsGrid');
            const cardBtn = document.getElementById('cardViewBtn');
            const listBtn = document.getElementById('listViewBtn');
            if (viewType === 'list') { 
                grid.classList.add('list-view'); 
                listBtn.classList.add('active'); 
                cardBtn.classList.remove('active'); 
            }
            else { 
                grid.classList.remove('list-view'); 
                cardBtn.classList.add('active'); 
                listBtn.classList.remove('active'); 
            }
        }

        function toggleArchiveView(viewType) {
            const grid = document.getElementById('archivedProgramsGrid');
            const cardBtn = document.getElementById('archiveCardViewBtn');
            const listBtn = document.getElementById('archiveListViewBtn');
            if (viewType === 'list') { 
                grid.classList.add('list-view'); 
                listBtn.classList.add('active'); 
                cardBtn.classList.remove('active'); 
            }
            else { 
                grid.classList.remove('list-view'); 
                cardBtn.classList.add('active'); 
                listBtn.classList.remove('active'); 
            }
        }

        document.getElementById('programModal').addEventListener('click', function(e) { 
            if (e.target === this) closeProgramModal(); 
        });

        // Search functionality
        document.getElementById('archiveSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('#archived-programs .program-card');
            cards.forEach(card => { 
                const title = card.querySelector('h3.program-title').textContent.toLowerCase();
                card.style.display = title.includes(searchTerm) ? 'block' : 'none'; 
            });
        });
        
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('#active-programs .program-card');
            cards.forEach(card => { 
                const title = card.querySelector('.program-title').textContent.toLowerCase(); 
                card.style.display = title.includes(searchTerm) ? 'block' : 'none'; 
            });
        });

        const today = new Date().toISOString().split('T')[0];
        const startDateInput = document.getElementById('scheduleStart');
        const endDateInput = document.getElementById('scheduleEnd');
        const durationInput = document.getElementById('durationInput');
        
        if (!durationInput.value) {
            durationInput.value = 1;
        }
        
        // Set minimum start date to 7 days from today for new programs
        const minStartDate = calculateMinStartDate();
        startDateInput.min = minStartDate; 
        endDateInput.min = today;
        
        durationInput.addEventListener('input', calculateEndDate);
        startDateInput.addEventListener('change', function() { 
            endDateInput.min = this.value; 
            
            // If end date is now before start date, adjust it
            const endDate = new Date(endDateInput.value);
            const startDate = new Date(this.value);
            
            if (endDate < startDate) {
                calculateEndDate();
            } else {
                calculateDurationFromDates();
            }
        });
        endDateInput.addEventListener('change', calculateDurationFromDates);

        // Prevent form submission on Enter key in modal inputs
        document.getElementById('programModal').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.type !== 'submit') {
                e.preventDefault();
            }
        });

        // FIXED FORM SUBMISSION HANDLER
        document.getElementById('programForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const programName = document.getElementById('programName').value.trim();
            const categoryId = document.getElementById('programCategory').value;
            const newCategoryName = document.getElementById('newCategoryName').value.trim();
            const trainerId = document.getElementById('programTrainer').value;
            const selectedTrainerOption = document.getElementById('programTrainer').options[document.getElementById('programTrainer').selectedIndex];
            const slots = document.getElementById('programSlots').value;
            const programId = document.getElementById('programId').value;
            const isEdit = programId ? true : false;
            const scheduleStart = document.getElementById('scheduleStart').value;
            const minStartDate = calculateMinStartDate();
            
            // ========== CLIENT-SIDE VALIDATION ==========
            // Validate program name
            if (!programName) {
                Swal.fire('Error!', 'Program name cannot be empty or contain only spaces!', 'error');
                document.getElementById('programName').focus();
                return;
            }
            
            if (programName.length > 255) {
                Swal.fire('Error!', 'Program name must be 255 characters or less!', 'error');
                document.getElementById('programName').focus();
                return;
            }
            
            // Validate start date based on action
            if (!isEdit) {
                // New program: must be at least 7 days from today
                if (scheduleStart < minStartDate) {
                    Swal.fire('Error!', `Schedule start must be at least 7 days from today! Minimum date is ${minStartDate}.`, 'error');
                    document.getElementById('scheduleStart').focus();
                    return;
                }
            }
            
            // Validate trainer selection - "No Trainer Assigned" is allowed (empty string)
            if (trainerId === undefined || trainerId === null) {
                Swal.fire('Error!', 'Please select a trainer or choose "No Trainer Assigned".', 'error');
                return;
            }
            
            // Check if selected trainer is disabled (only trainers in same category are disabled)
            if (trainerId && trainerId !== '' && selectedTrainerOption.disabled) {
                Swal.fire('Error!', 'This trainer is already assigned to another program in this category. Please select a different trainer or "No Trainer Assigned".', 'error');
                return;
            }
            
            if (!categoryId) {
                Swal.fire('Error!', 'Please select a category.', 'error');
                return;
            }
            
            if (categoryId === 'new' && !newCategoryName) {
                Swal.fire('Error!', 'Please enter a new category name.', 'error');
                return;
            }
            
            if (categoryId === 'new' && newCategoryName.length > 255) {
                Swal.fire('Error!', 'Category name must be 255 characters or less!', 'error');
                document.getElementById('newCategoryName').focus();
                return;
            }
            
            // Add basic validation for slots
            if (!slots || parseInt(slots) < 1) {
                Swal.fire('Error!', 'Please enter a valid number of slots (minimum 1).', 'error');
                document.getElementById('programSlots').focus();
                return;
            }
            // ========== END CLIENT-SIDE VALIDATION ==========
            
            // Show loading state
            const submitBtn = document.getElementById('saveButton');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Saving...';
            submitBtn.disabled = true;
            
            // Use regular form submission (not AJAX/fetch) to allow PHP redirect to work
            this.submit();
        });

        calculateEndDate();
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener for category change to update trainers
            document.getElementById('programCategory').addEventListener('change', function() {
                // Show loading state
                const trainerSelect = document.getElementById('programTrainer');
                trainerSelect.innerHTML = '<option value="" class="trainer-loading">Loading available trainers...</option>';
                
                // Small delay to ensure UI updates before fetching
                setTimeout(() => {
                    updateTrainerOptions();
                }, 100);
            });
            
            // Initialize trainer options
            updateTrainerOptions();
        });
        
        // Debug functions
        function testDeleteFunction() {
            const programId = prompt("Enter archived program ID to test delete:");
            if (programId) {
                deleteProgram(programId);
            }
        }
        
        function testTrainerLoading() {
            const categoryId = prompt("Enter category ID to test trainer loading:");
            if (categoryId) {
                const categorySelect = document.getElementById('programCategory');
                categorySelect.value = categoryId;
                updateTrainerOptions();
            }
        }
        
        // Show debug section with Ctrl+Shift+D
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                document.getElementById('debugSection').style.display = 'block';
            }
        });
    </script>
</body>
</html>