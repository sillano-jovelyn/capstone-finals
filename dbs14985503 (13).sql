-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: db5019042279.hosting-data.io
-- Generation Time: Apr 12, 2026 at 04:20 PM
-- Server version: 10.11.14-MariaDB-log
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dbs14985503`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`o14985503`@`%` PROCEDURE `sp_auto_archive_ended_programs` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_program_id INT;
    DECLARE v_program_name VARCHAR(255);
    
    -- Find active programs that have ended (scheduleEnd is past) and aren't archived yet
    DECLARE cur_ended_programs CURSOR FOR 
    SELECT id, name
    FROM programs 
    WHERE status = 'active'
      AND scheduleEnd IS NOT NULL
      AND scheduleEnd < CURDATE()
      AND (is_archived = 0 OR is_archived IS NULL)
      AND NOT EXISTS (
        SELECT 1 FROM archive_programs ap 
        WHERE ap.original_id = programs.id
      )
    ORDER BY scheduleEnd ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur_ended_programs;
    
    read_loop: LOOP
        FETCH cur_ended_programs INTO v_program_id, v_program_name;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Insert into archive_programs
        INSERT INTO archive_programs (
            original_id,
            name,
            duration,
            durationUnit,
            scheduleStart,
            scheduleEnd,
            trainer_id,
            trainer,
            slotsAvailable,
            category_id,
            duration_unit,
            total_slots,
            show_on_index,
            other_trainer,
            status,
            archived_at
        )
        SELECT 
            id,
            name,
            duration,
            COALESCE(durationUnit, 'Days'),
            scheduleStart,
            scheduleEnd,
            trainer_id,
            trainer,
            slotsAvailable,
            category_id,
            COALESCE(duration_unit, durationUnit, 'Days'),
            COALESCE(total_slots, slotsAvailable),
            COALESCE(show_on_index, 0),
            COALESCE(other_trainer, '-'),
            'archived',
            NOW()
        FROM programs
        WHERE id = v_program_id;
        
        -- Mark as archived in main table
        UPDATE programs 
        SET is_archived = 1,
            archived = 1,
            status = 'deactivated',
            updated_at = CURRENT_TIMESTAMP()
        WHERE id = v_program_id;
        
    END LOOP;
    
    CLOSE cur_ended_programs;
END$$

--
-- Functions
--
CREATE DEFINER=`o14985503`@`%` FUNCTION `fn_get_user_archived_count` (`p_user_id` INT) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_count INT;
    
    SELECT COUNT(DISTINCT original_program_id) INTO v_count
    FROM archived_history
    WHERE user_id = p_user_id;
    
    RETURN v_count;
END$$

CREATE DEFINER=`o14985503`@`%` FUNCTION `fn_is_program_archived` (`p_program_id` INT) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_is_archived TINYINT(1) DEFAULT 0;
    
    SELECT 
        CASE 
            WHEN EXISTS (SELECT 1 FROM archive_programs WHERE original_id = p_program_id) THEN 1
            ELSE 0 
        END INTO v_is_archived;
    
    RETURN v_is_archived;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archive`
--

CREATE TABLE `archive` (
  `id` int(11) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration` int(11) NOT NULL DEFAULT 0,
  `duration_unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Days',
  `schedule_start` date DEFAULT NULL,
  `schedule_end` date DEFAULT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  `trainer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slots_available` int(11) NOT NULL DEFAULT 0,
  `total_slots` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `other_trainer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `status` enum('active','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'archived',
  `archived_at` datetime DEFAULT current_timestamp(),
  `category_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_history`
--

CREATE TABLE `archived_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `original_program_id` int(11) DEFAULT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `feedback_id` int(11) DEFAULT NULL,
  `archive_program_id` int(11) DEFAULT NULL,
  `program_name` varchar(255) NOT NULL,
  `program_duration` int(11) NOT NULL,
  `program_duration_unit` varchar(50) NOT NULL DEFAULT 'Days',
  `program_schedule_start` date DEFAULT NULL,
  `program_schedule_end` date DEFAULT NULL,
  `program_trainer_id` int(11) DEFAULT NULL,
  `program_trainer_name` varchar(255) DEFAULT NULL,
  `program_category_id` int(11) DEFAULT NULL,
  `program_total_slots` int(11) DEFAULT 0,
  `program_slots_available` int(11) DEFAULT 0,
  `program_other_trainer` text DEFAULT NULL,
  `program_show_on_index` tinyint(1) DEFAULT 0,
  `enrollment_status` enum('pending','approved','rejected','revision_needed','completed') DEFAULT 'pending',
  `enrollment_applied_at` datetime DEFAULT NULL,
  `enrollment_completed_at` datetime DEFAULT NULL,
  `enrollment_attendance` int(11) DEFAULT 0,
  `enrollment_approval_status` enum('pending','approved','rejected','revision_needed','completed') DEFAULT 'pending',
  `enrollment_approved_by` int(11) DEFAULT NULL,
  `enrollment_approved_date` datetime DEFAULT NULL,
  `enrollment_assessment` varchar(255) DEFAULT NULL,
  `trainer_expertise_rating` int(11) DEFAULT NULL,
  `trainer_communication_rating` int(11) DEFAULT NULL,
  `trainer_methods_rating` int(11) DEFAULT NULL,
  `trainer_requests_rating` int(11) DEFAULT NULL,
  `trainer_questions_rating` int(11) DEFAULT NULL,
  `trainer_instructions_rating` int(11) DEFAULT NULL,
  `trainer_prioritization_rating` int(11) DEFAULT NULL,
  `trainer_fairness_rating` int(11) DEFAULT NULL,
  `program_knowledge_rating` int(11) DEFAULT NULL,
  `program_process_rating` int(11) DEFAULT NULL,
  `program_environment_rating` int(11) DEFAULT NULL,
  `program_algorithms_rating` int(11) DEFAULT NULL,
  `program_preparation_rating` int(11) DEFAULT NULL,
  `system_technology_rating` int(11) DEFAULT NULL,
  `system_workflow_rating` int(11) DEFAULT NULL,
  `system_instructions_rating` int(11) DEFAULT NULL,
  `system_answers_rating` int(11) DEFAULT NULL,
  `system_performance_rating` int(11) DEFAULT NULL,
  `feedback_comments` text DEFAULT NULL,
  `feedback_submitted_at` datetime DEFAULT NULL,
  `saved_signatories` text DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archive_trigger` enum('program_ended','program_moved_to_archive','enrollment_completed','manual') DEFAULT 'program_moved_to_archive',
  `archive_source` enum('archive_programs_table','direct_from_programs') DEFAULT 'archive_programs_table',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `archived_history`
--

INSERT INTO `archived_history` (`id`, `user_id`, `original_program_id`, `enrollment_id`, `feedback_id`, `archive_program_id`, `program_name`, `program_duration`, `program_duration_unit`, `program_schedule_start`, `program_schedule_end`, `program_trainer_id`, `program_trainer_name`, `program_category_id`, `program_total_slots`, `program_slots_available`, `program_other_trainer`, `program_show_on_index`, `enrollment_status`, `enrollment_applied_at`, `enrollment_completed_at`, `enrollment_attendance`, `enrollment_approval_status`, `enrollment_approved_by`, `enrollment_approved_date`, `enrollment_assessment`, `trainer_expertise_rating`, `trainer_communication_rating`, `trainer_methods_rating`, `trainer_requests_rating`, `trainer_questions_rating`, `trainer_instructions_rating`, `trainer_prioritization_rating`, `trainer_fairness_rating`, `program_knowledge_rating`, `program_process_rating`, `program_environment_rating`, `program_algorithms_rating`, `program_preparation_rating`, `system_technology_rating`, `system_workflow_rating`, `system_instructions_rating`, `system_answers_rating`, `system_performance_rating`, `feedback_comments`, `feedback_submitted_at`, `saved_signatories`, `archived_at`, `archive_trigger`, `archive_source`, `updated_at`) VALUES
(20, 92, 120, 135, 21, NULL, 'Cookery', 2, 'Days', '2026-02-27', '2026-02-28', 96, 'Sam', 12, 2, 0, '-', 0, 'completed', '2026-02-27 00:22:27', NULL, 100, 'pending', NULL, NULL, 'Passed', 4, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 'jhgfdgfrghbjnkj', '2026-02-28 00:39:56', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-02-28 05:39:56', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(27, 156, 106, 109, 17, NULL, 'Bookkeeping', 1, 'Days', '2026-02-04', '2026-02-04', 147, 'ashily ', 5, 12, 7, '-', 1, 'completed', '2026-02-04 00:52:31', '2026-02-04 01:40:57', 100, 'pending', NULL, NULL, 'Passed', 4, 5, 4, 5, 5, 4, 4, 5, 5, 5, 5, 3, 3, 3, 3, 3, 3, 3, 'very good', '2026-02-04 01:40:57', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-02-04 06:40:57', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(31, 146, 105, 99, 15, NULL, 'Cookery', 1, 'Days', '2026-02-02', '2026-02-02', 96, 'Sam', 12, 2, 1, '-', 1, 'completed', '2026-02-02 01:28:43', '2026-02-02 07:40:48', 100, 'pending', NULL, NULL, 'Passed', 5, 5, 5, 4, 4, 4, 4, 4, 5, 5, 5, 5, 5, 4, 4, 4, 4, 4, '', '2026-02-02 07:40:48', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-02-02 12:40:48', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(32, 146, 106, 104, 18, NULL, 'Bookkeeping', 1, 'Days', '2026-02-04', '2026-02-04', 147, 'ashily ', 5, 12, 7, '-', 1, 'completed', '2026-02-03 21:22:29', '2026-02-04 01:41:19', 100, 'pending', NULL, NULL, 'Passed', 4, 4, 5, 5, 5, 5, 3, 3, 3, 5, 5, 5, 4, 3, 4, 4, 4, 4, 'ertytrtyiiuytr', '2026-02-04 01:41:19', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-02-04 06:41:19', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(36, 165, 124, 152, 22, NULL, 'Automotive', 2, 'Days', '2026-03-13', '2026-03-14', 101, 'adi hue', 7, 3, 3, '0', 0, 'completed', '2026-03-12 10:53:35', '2026-03-13 04:15:47', 50, 'pending', NULL, NULL, 'Passed', 5, 5, 5, 5, 5, 5, 5, 5, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, NULL, '2026-03-13 10:27:42', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-14 02:22:08', 'program_ended', 'direct_from_programs', '2026-04-09 05:16:38'),
(37, 161, 124, 153, 36, NULL, 'Automotive', 2, 'Days', '2026-03-13', '2026-03-14', 101, 'adi hue', 7, 3, 3, '0', 0, 'completed', '2026-03-12 11:23:31', '2026-03-13 22:03:12', 50, 'pending', NULL, NULL, 'Passed', 5, 5, 5, 5, 5, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5, NULL, '2026-03-13 22:03:12', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-14 02:22:08', 'program_ended', 'direct_from_programs', '2026-04-09 05:16:38'),
(38, 161, 127, 155, 37, NULL, 'Beauty Care Services', 2, 'Days', '2026-03-14', '2026-03-15', 147, 'ashily ', 11, 5, 3, '-', 1, 'completed', '2026-03-13 22:37:37', '2026-03-14 11:11:32', 50, 'pending', NULL, NULL, 'Passed', 5, 5, 5, 4, 5, 4, 5, 5, 5, 3, 4, 2, 4, 5, 5, 5, 4, 4, '', '2026-03-14 00:51:09', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-14 04:06:15', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(45, 146, 130, 164, 43, NULL, 'Candle, Soap, and Perfume Making', 1, 'Days', '2026-03-23', '2026-03-23', NULL, 'lucia garcia', 6, 2, 0, '-', 0, 'completed', '2026-03-22 04:39:36', '2026-03-23 22:50:57', 100, 'pending', NULL, NULL, 'Passed', 3, 4, 3, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, '', '2026-03-23 22:50:57', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-23 13:32:27', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(46, 150, 129, 166, 48, NULL, 'Barista Training', 2, 'Days', '2026-03-23', '2026-03-24', 158, 'Eimarie clair', 1, 15, 13, '-', 1, 'completed', '2026-03-22 04:55:17', '2026-03-24 01:28:11', 50, 'pending', NULL, NULL, 'Passed', 5, 5, 4, 5, 4, 5, 4, 5, 4, 4, 4, 4, 5, 5, 5, 5, 4, 5, '', '2026-03-24 01:28:11', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-23 13:43:48', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(47, 170, 129, 163, 47, NULL, 'Barista Training', 2, 'Days', '2026-03-23', '2026-03-24', 158, 'Eimarie clair', 1, 15, 13, '-', 1, 'completed', '2026-03-22 03:48:41', '2026-03-24 01:26:38', 50, 'pending', NULL, NULL, 'Passed', 4, 4, 4, 4, 5, 4, 5, 5, 4, 5, 4, 5, 5, 5, 5, 5, 5, 5, 'goodjob', '2026-03-24 01:26:38', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-23 13:45:33', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(48, 151, 130, 165, 46, NULL, 'Candle, Soap, and Perfume Making', 1, 'Days', '2026-03-23', '2026-03-23', NULL, 'lucia garcia', 6, 2, 0, '-', 0, 'completed', '2026-03-22 04:40:32', '2026-03-23 23:44:50', 100, 'pending', NULL, NULL, 'Passed', 5, 4, 5, 4, 5, 4, 4, 4, 4, 4, 5, 5, 4, 5, 5, 4, 4, 4, '', '2026-03-23 23:44:50', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-23 14:22:56', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(49, 173, 126, 175, 53, NULL, 'Bread & Pastry', 9, 'Days', '2026-04-02', '2026-04-10', 96, 'Sam', 12, 15, 12, '-', 1, 'completed', '2026-03-24 21:50:23', '2026-04-09 13:04:01', 0, 'pending', NULL, NULL, 'Passed', 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, '', '2026-04-09 13:04:01', '[{\"signatory_name\":\"ZENAIDA S. MANINGAS\",\"signatory_title\":\"PESO Manager\"},{\"signatory_name\":\"JOHN SANTOS\",\"signatory_title\":\"Municipal Vice Mayor\"},{\"signatory_name\":\"ALKIR DOE\",\"signatory_title\":\"Municipal Mayor\"}]', '2026-03-25 02:06:22', 'enrollment_completed', 'direct_from_programs', '2026-04-09 17:04:01'),
(50, 146, 128, 173, 49, NULL, 'ICT Software Developer', 1, 'Days', '2026-03-25', '2026-03-25', 149, 'Yen Ashitrid', 10, 5, 2, '-', 1, 'completed', '2026-03-24 20:16:06', '2026-03-24 22:35:47', 100, 'pending', NULL, NULL, 'Passed', 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, '', '2026-03-24 22:35:47', '[\r\n    {\"signatory_name\": \"ZENAIDA S. MANINGAS\", \"signatory_title\": \"PESO Manager\"},\r\n    {\"signatory_name\": \"ROBERTO B. PEREZ\", \"signatory_title\": \"Municipal Vice Mayor\"},\r\n    {\"signatory_name\": \"BARTOLOME R. RAMOS\", \"signatory_title\": \"Municipal Mayor\"}\r\n]', '2026-03-25 02:34:03', 'enrollment_completed', 'direct_from_programs', '2026-04-09 05:16:38'),
(51, 161, 128, 172, NULL, NULL, '0', 1, '0', '2026-03-25', '2026-03-25', 149, 'Yen Ashitrid', 10, 5, 5, '0', 1, '', '2026-03-24 20:13:36', '2026-03-24 22:34:03', 0, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-02 10:20:32', 'program_ended', 'direct_from_programs', '2026-04-02 10:20:32'),
(52, 151, 128, 174, NULL, NULL, '0', 1, '0', '2026-03-25', '2026-03-25', 149, 'Yen Ashitrid', 10, 5, 5, '0', 1, '', '2026-03-24 20:18:12', '2026-03-24 22:34:03', 100, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-02 10:20:32', 'program_ended', 'direct_from_programs', '2026-04-02 10:20:32'),
(53, 161, 132, 178, 50, NULL, 'ICT NETWORKING', 1, 'Days', '2026-04-09', '2026-04-09', 97, 'haniie yoon', 10, 5, 2, '-', 1, 'completed', '2026-04-09 00:05:44', '2026-04-09 00:35:49', 100, 'pending', NULL, NULL, 'Passed', 5, 4, 4, 5, 4, 4, 4, 5, 4, 4, 5, 5, 4, 3, 4, 4, 4, 4, '', '2026-04-09 00:35:49', '[{\"signatory_name\":\"ZENAIDA S. MANINGAS\",\"signatory_title\":\"PESO Manager\"},{\"signatory_name\":\"JOHN DOE\",\"signatory_title\":\"Municipal Vice Mayor\"},{\"signatory_name\":\"JANE DOE\",\"signatory_title\":\"Municipal Mayor\"}]', '2026-04-09 04:29:28', 'enrollment_completed', 'direct_from_programs', '2026-04-09 15:14:17'),
(54, 175, 134, 182, NULL, NULL, 'Barista Training', 3, 'Days', '2026-04-09', '2026-04-11', 158, 'Eimarie clair', 1, 15, 13, '-', 1, '', '2026-04-09 11:59:02', '2026-04-09 13:26:48', 33, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[{\"signatory_name\":\"ZENAIDA S. MANINGAS\",\"signatory_title\":\"PESO Manager\"},{\"signatory_name\":\"JOHN SANTOS\",\"signatory_title\":\"Municipal Vice Mayor\"},{\"signatory_name\":\"ALKIR DOE\",\"signatory_title\":\"Municipal Mayor\"}]', '2026-04-09 16:04:59', 'enrollment_completed', 'direct_from_programs', '2026-04-09 17:26:48'),
(55, 175, 134, 184, NULL, NULL, 'Barista Training', 3, 'Days', '2026-04-09', '2026-04-11', 158, 'Eimarie clair', 1, 15, 12, '-', 1, '', '2026-04-09 13:28:28', '2026-04-09 14:56:28', 33, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-09 18:01:23', 'enrollment_completed', 'direct_from_programs', '2026-04-09 18:56:28'),
(56, 165, 133, 186, NULL, NULL, 'Beauty Care Services', 2, 'Days', '2026-04-10', '2026-04-11', 147, 'ashily ', 11, 5, 0, '-', 0, 'completed', '2026-04-09 18:41:47', '2026-04-09 18:51:39', 50, 'pending', NULL, NULL, 'Passed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[{\"signatory_name\":\"ZENAIDA S. MANINGAS\",\"signatory_title\":\"PESO Manager\"},{\"signatory_name\":\"JOHN SANTOS\",\"signatory_title\":\"Municipal Vice Mayor\"},{\"signatory_name\":\"ALKIR DOE\",\"signatory_title\":\"Municipal Mayor\"}]', '2026-04-09 22:51:39', 'enrollment_completed', 'direct_from_programs', '2026-04-10 02:13:52'),
(57, 161, 133, 183, 54, NULL, 'Beauty Care Services', 2, 'Days', '2026-04-10', '2026-04-11', 147, 'ashily ', 11, 5, 0, '-', 0, 'completed', '2026-04-09 12:25:02', '2026-04-11 03:55:49', 50, 'pending', NULL, NULL, 'Passed', 4, 4, 4, 4, 4, 4, 4, 4, 2, 2, 3, 2, 3, 3, 3, 3, 2, 2, '', '2026-04-11 03:55:49', '[{\"signatory_name\":\"ZENAIDA S. MANINGAS\",\"signatory_title\":\"PESO Manager\"},{\"signatory_name\":\"JOHN SANTOS\",\"signatory_title\":\"Municipal Vice Mayor\"},{\"signatory_name\":\"ALKIR DOE\",\"signatory_title\":\"Municipal Mayor\"}]', '2026-04-09 22:51:39', 'enrollment_completed', 'direct_from_programs', '2026-04-11 07:55:49'),
(58, 150, 126, 176, NULL, NULL, '0', 9, '0', '2026-04-02', '2026-04-10', 96, 'Sam', 12, 15, 14, '0', 0, '', '2026-03-24 21:52:22', NULL, 0, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-12 07:35:46', 'program_ended', 'direct_from_programs', '2026-04-12 07:35:46'),
(59, 170, 131, 177, NULL, NULL, '0', 6, '0', '2026-04-05', '2026-04-10', 169, 'Lexis ', 2, 8, 7, '0', 0, '', '2026-04-05 10:58:21', NULL, 0, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-12 07:35:46', 'program_ended', 'direct_from_programs', '2026-04-12 07:35:46'),
(60, 176, 133, 181, NULL, NULL, '0', 2, '0', '2026-04-10', '2026-04-11', 147, 'ashily ', 11, 5, 5, '0', 0, '', '2026-04-09 11:38:07', '2026-04-09 18:51:39', 50, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-12 07:35:46', 'program_ended', 'direct_from_programs', '2026-04-12 07:35:46'),
(61, 175, 134, 185, NULL, NULL, '0', 3, '0', '2026-04-09', '2026-04-11', 158, 'Eimarie clair', 1, 15, 15, '0', 1, '', '2026-04-09 14:58:29', '2026-04-09 15:04:35', 33, 'pending', NULL, NULL, 'Failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-12 07:35:46', 'program_ended', 'direct_from_programs', '2026-04-12 07:35:46');

-- --------------------------------------------------------

--
-- Table structure for table `archived_users`
--

CREATE TABLE `archived_users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `specialization` text DEFAULT NULL,
  `other_programs` text DEFAULT NULL,
  `archived_at` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `archived_users`
--

INSERT INTO `archived_users` (`id`, `fullname`, `email`, `password`, `role`, `program`, `created_at`, `date_created`, `status`, `reset_token`, `reset_expires`, `specialization`, `other_programs`, `archived_at`) VALUES
(99, 'Herm, Rom B.', 'qweasnder.1234@gmail.com', '$2y$10$Yd9m3t7oGuybQqRQ4R4iaeHZ4n.v7Eab0o7igC0MsEDJXOzsaIbdC', 'trainee', NULL, NULL, '2025-12-08 07:11:00', 'Active', NULL, NULL, NULL, NULL, '2026-03-12 12:17:25'),
(152, 'Hermo, Romilyn B.', 'romilynhermo.0526@gmail.com', '$2y$10$axYgid.D9LZKwU8U/eNGuOHEz9PHkJM/a/1osVW3fOgVFGUSaJNpu', 'trainee', NULL, NULL, '2026-02-04 00:24:09', 'Active', NULL, NULL, NULL, NULL, '2026-03-12 12:56:39'),
(155, 'victoria, Pau A.', 'paulo.vicroria@bpc.edu.ph', '$2y$10$JEJzhvCyMn5tR3lLMuLym.60r6cXhvLLf8r6bkD45eXc.JemoTz0.', 'trainee', NULL, NULL, '2026-02-04 00:33:55', 'Active', NULL, NULL, NULL, NULL, '2026-03-12 12:16:01'),
(157, 'Hermo, Romilyn B.', 'qweasnder.0526@gmail.com', '$2y$10$Wj/J.CXRfPVM1B.6JcKDC.g1PZxxV8/zCCKiSYLa42ROpRY93rCT6', 'trainee', NULL, NULL, '2026-02-04 01:42:54', 'Active', NULL, NULL, NULL, NULL, '2026-03-12 12:56:05');

-- --------------------------------------------------------

--
-- Table structure for table `archived_users_history`
--

CREATE TABLE `archived_users_history` (
  `id` int(11) NOT NULL,
  `original_user_id` int(11) NOT NULL,
  `user_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`user_data`)),
  `trainee_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trainee_data`)),
  `archived_history_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`archived_history_ids`)),
  `archived_history_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`archived_history_data`)),
  `fullname` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_by` int(11) DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `status` enum('archived','restored','permanently_deleted') DEFAULT 'archived'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `archived_users_history`
--

INSERT INTO `archived_users_history` (`id`, `original_user_id`, `user_data`, `trainee_data`, `archived_history_ids`, `archived_history_data`, `fullname`, `email`, `role`, `archived_at`, `archived_by`, `restored_at`, `restored_by`, `status`) VALUES
(1, 150, '{\"id\":\"150\",\"fullname\":\"SILLANO, JOVELYN C.\",\"email\":\"vell88091@gmail.com\",\"password\":\"$2y$10$kDq4VRDYpMf\\/6x9mJBN1l.\\/lv4.qS\\/Lo9lA.mR7MxrWQHIZljeNoK\",\"role\":\"trainee\",\"date_created\":\"2026-02-01 03:20:24\",\"status\":\"Active\",\"program\":null,\"specialization\":null,\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.782043\",\"pin_longitude\":\"120.878094\",\"pin_radius\":\"43\",\"pin_location_name\":\"LEMS ADMIN\"}', '{\"id\":\"32\",\"user_id\":\"150\",\"fullname\":\"SILLANO, JOVELYN C.\",\"address\":\"1145 Purok 1, Guyong, Sta. Maria, Bulacan\",\"gender\":\"Female\",\"civil_status\":\"Married\",\"birthday\":\"2003-06-11\",\"age\":\"22\",\"contact_number\":\"09973267461\",\"employment_status\":\"Employed\",\"education\":\"College Level \\/ Graduate\",\"failure_notes_copy\":null,\"trainings_attended\":\"NA\",\"toolkit_received\":\"NA\",\"valid_id\":\"[\\\"SCANNED_ID_1769934024_0.jpg\\\"]\",\"valid_id_files\":null,\"voters_certificate\":\"[\\\"SCANNED_CERT_1769934024_0.jpg\\\"]\",\"detected_id_types\":\"[]\",\"voters_cert_files\":null,\"email\":\"vell88091@gmail.com\",\"password\":\"$2y$10$kDq4VRDYpMf\\/6x9mJBN1l.\\/lv4.qS\\/Lo9lA.mR7MxrWQHIZljeNoK\",\"email_verified\":\"1\",\"verification_token\":null,\"token_expiry\":null,\"created_at\":\"2026-02-01 03:20:24\",\"lastname\":\"SILLANO\",\"firstname\":\"JOVELYN\",\"middleinitial\":\"C\",\"house_street\":\"1145 Purok 1\",\"barangay\":\"Guyong\",\"municipality\":\"Sta. Maria\",\"city\":\"Bulacan\",\"gender_specify\":\"\",\"education_specify\":\"\",\"role\":\"trainee\",\"valid_id_links\":null,\"voters_cert_links\":null,\"special_categories\":\"[\\\"4Ps Beneficiary\\\"]\",\"special_categories_specify\":\"\",\"applicant_type\":\"\",\"nc_holder\":\"yes\"}', '[\"1\",\"8\",\"16\"]', NULL, 'SILLANO, JOVELYN C.', 'vell88091@gmail.com', 'trainee', '2026-03-12 17:32:24', 2, '2026-03-12 17:33:23', 2, 'restored'),
(2, 156, '{\"id\":\"156\",\"fullname\":\"Gatchalian, Migs G.\",\"email\":\"miggatchalian@gmail.com\",\"password\":\"$2y$10$bh2d2gJIUMf.Q2m6Mwqswue8vd88rpdYb0orI8yP1z791GXuAOKUK\",\"role\":\"trainee\",\"date_created\":\"2026-02-04 00:50:25\",\"status\":\"Active\",\"program\":null,\"specialization\":null,\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.782043\",\"pin_longitude\":\"120.878094\",\"pin_radius\":\"43\",\"pin_location_name\":\"LEMS ADMIN\"}', '{\"id\":\"38\",\"user_id\":\"156\",\"fullname\":\"Gatchalian, Migs G.\",\"address\":\"1234, Poblacion, Sta. Maria, Bulacan\",\"gender\":\"Male\",\"civil_status\":\"Single\",\"birthday\":\"1956-02-11\",\"age\":\"69\",\"contact_number\":\"09560511944\",\"employment_status\":\"Employed\",\"education\":\"College Level \\/ Graduate\",\"failure_notes_copy\":null,\"trainings_attended\":\"NA\",\"toolkit_received\":\"NA\",\"valid_id\":\"[\\\"SCANNED_ID_1770184225_0.jpg\\\"]\",\"valid_id_files\":null,\"voters_certificate\":\"[\\\"SCANNED_CERT_1770184225_0.jpg\\\"]\",\"detected_id_types\":\"[]\",\"voters_cert_files\":null,\"email\":\"miggatchalian@gmail.com\",\"password\":\"$2y$10$bh2d2gJIUMf.Q2m6Mwqswue8vd88rpdYb0orI8yP1z791GXuAOKUK\",\"email_verified\":\"1\",\"verification_token\":null,\"token_expiry\":null,\"created_at\":\"2026-02-04 00:50:25\",\"lastname\":\"Gatchalian\",\"firstname\":\"Migs\",\"middleinitial\":\"G\",\"house_street\":\"1234\",\"barangay\":\"Poblacion\",\"municipality\":\"Sta. Maria\",\"city\":\"Bulacan\",\"gender_specify\":\"\",\"education_specify\":\"\",\"role\":\"trainee\",\"valid_id_links\":null,\"voters_cert_links\":null,\"special_categories\":\"[]\",\"special_categories_specify\":\"\",\"applicant_type\":\"\",\"nc_holder\":\"yes\"}', '[\"9\"]', '[{\"id\":\"9\",\"user_id\":\"156\",\"original_program_id\":\"106\",\"enrollment_id\":\"109\",\"feedback_id\":\"17\",\"archive_program_id\":null,\"program_name\":\"Bookkeeping\",\"program_duration\":\"1\",\"program_duration_unit\":\"Days\",\"program_schedule_start\":\"2026-02-04\",\"program_schedule_end\":\"2026-02-04\",\"program_trainer_id\":\"147\",\"program_trainer_name\":\"ashily \",\"program_category_id\":\"5\",\"program_total_slots\":\"12\",\"program_slots_available\":\"7\",\"program_other_trainer\":\"-\",\"program_show_on_index\":\"1\",\"enrollment_status\":\"completed\",\"enrollment_applied_at\":\"2026-02-04 00:52:31\",\"enrollment_completed_at\":\"2026-02-04 01:40:57\",\"enrollment_attendance\":\"100\",\"enrollment_approval_status\":\"pending\",\"enrollment_approved_by\":null,\"enrollment_approved_date\":null,\"enrollment_assessment\":\"Passed\",\"trainer_expertise_rating\":\"4\",\"trainer_communication_rating\":\"5\",\"trainer_methods_rating\":\"4\",\"trainer_requests_rating\":\"5\",\"trainer_questions_rating\":\"5\",\"trainer_instructions_rating\":\"4\",\"trainer_prioritization_rating\":\"4\",\"trainer_fairness_rating\":\"5\",\"program_knowledge_rating\":\"5\",\"program_process_rating\":\"5\",\"program_environment_rating\":\"5\",\"program_algorithms_rating\":\"3\",\"program_preparation_rating\":\"3\",\"system_technology_rating\":\"3\",\"system_workflow_rating\":\"3\",\"system_instructions_rating\":\"3\",\"system_answers_rating\":\"3\",\"system_performance_rating\":\"3\",\"feedback_comments\":\"very good\",\"feedback_submitted_at\":\"2026-02-04 01:40:57\",\"archived_at\":\"2026-02-04 01:40:57\",\"archive_trigger\":\"enrollment_completed\",\"archive_source\":\"direct_from_programs\"}]', 'Gatchalian, Migs G.', 'miggatchalian@gmail.com', 'trainee', '2026-03-12 17:56:49', 2, '2026-03-12 17:57:43', 2, 'restored'),
(3, 102, '{\"id\":\"102\",\"fullname\":\"Gatchalian, Migs G.\",\"email\":\"migggatchalian@gmail.com\",\"password\":\"$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa\\/jZwHh.dJtDj0hgVfYzS\",\"role\":\"trainee\",\"date_created\":\"2025-12-12 00:33:58\",\"status\":\"Active\",\"program\":null,\"specialization\":null,\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.782043\",\"pin_longitude\":\"120.878094\",\"pin_radius\":\"43\",\"pin_location_name\":\"LEMS ADMIN\"}', '{\"id\":\"10\",\"user_id\":\"102\",\"fullname\":\"Gatchalian, Migs G.\",\"address\":\"123, Santisima, Malolos, Bulacan\",\"gender\":\"Male\",\"civil_status\":\"Single\",\"birthday\":\"1998-04-30\",\"age\":\"27\",\"contact_number\":\"09050460776\",\"employment_status\":\"Employed\",\"education\":\"College Level \\/ Graduate\",\"failure_notes_copy\":null,\"trainings_attended\":\"N\\/A\",\"toolkit_received\":\"N\\/A\",\"valid_id\":\"[\\\"VALIDID_1765517638_693ba9468f04d_0.jpeg\\\"]\",\"valid_id_files\":null,\"voters_certificate\":\"[\\\"RESIDENCY_1765517638_693ba9468f209_0.jpeg\\\"]\",\"detected_id_types\":\"[]\",\"voters_cert_files\":null,\"email\":\"migggatchalian@gmail.com\",\"password\":\"$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa\\/jZwHh.dJtDj0hgVfYzS\",\"email_verified\":\"1\",\"verification_token\":null,\"token_expiry\":null,\"created_at\":\"2025-12-12 00:33:58\",\"lastname\":\"Gatchalian\",\"firstname\":\"Migs\",\"middleinitial\":\"G\",\"house_street\":\"123\",\"barangay\":\"Santisima\",\"municipality\":\"Malolos\",\"city\":\"Bulacan\",\"gender_specify\":\"\",\"education_specify\":\"\",\"role\":\"trainee\",\"valid_id_links\":null,\"voters_cert_links\":null,\"special_categories\":null,\"special_categories_specify\":null,\"applicant_type\":\"\",\"nc_holder\":\"yes\"}', '[]', '[]', 'Gatchalian, Migs G.', 'migggatchalian@gmail.com', 'trainee', '2026-03-12 18:09:53', 2, '2026-03-12 18:12:28', 2, 'restored'),
(4, 102, '{\"id\":\"102\",\"fullname\":\"Gatchalian, Migs G.\",\"email\":\"migggatchalian@gmail.com\",\"password\":\"$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa\\/jZwHh.dJtDj0hgVfYzS\",\"role\":\"trainee\",\"date_created\":\"2025-12-12 00:33:58\",\"status\":\"Active\",\"program\":null,\"specialization\":null,\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.782043\",\"pin_longitude\":\"120.878094\",\"pin_radius\":\"43\",\"pin_location_name\":\"LEMS ADMIN\"}', '{\"id\":\"48\",\"user_id\":\"102\",\"fullname\":\"Gatchalian, Migs G.\",\"address\":\"123, Santisima, Malolos, Bulacan\",\"gender\":\"Male\",\"civil_status\":\"Single\",\"birthday\":\"1998-04-30\",\"age\":\"27\",\"contact_number\":\"09050460776\",\"employment_status\":\"Employed\",\"education\":\"College Level \\/ Graduate\",\"failure_notes_copy\":null,\"trainings_attended\":\"N\\/A\",\"toolkit_received\":\"N\\/A\",\"valid_id\":\"[\\\"VALIDID_1765517638_693ba9468f04d_0.jpeg\\\"]\",\"valid_id_files\":null,\"voters_certificate\":\"[\\\"RESIDENCY_1765517638_693ba9468f209_0.jpeg\\\"]\",\"detected_id_types\":\"[]\",\"voters_cert_files\":null,\"email\":\"migggatchalian@gmail.com\",\"password\":\"$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa\\/jZwHh.dJtDj0hgVfYzS\",\"email_verified\":\"1\",\"verification_token\":null,\"token_expiry\":null,\"created_at\":\"2025-12-12 00:33:58\",\"lastname\":\"Gatchalian\",\"firstname\":\"Migs\",\"middleinitial\":\"G\",\"house_street\":\"123\",\"barangay\":\"Santisima\",\"municipality\":\"Malolos\",\"city\":\"Bulacan\",\"gender_specify\":\"\",\"education_specify\":\"\",\"role\":\"trainee\",\"valid_id_links\":null,\"voters_cert_links\":null,\"special_categories\":null,\"special_categories_specify\":null,\"applicant_type\":\"\",\"nc_holder\":\"yes\"}', '[]', '[]', 'Gatchalian, Migs G.', 'migggatchalian@gmail.com', 'trainee', '2026-03-12 18:12:59', 2, '2026-03-12 18:15:53', 2, 'restored'),
(6, 146, '{\"id\":\"146\",\"fullname\":\"Cruz, Jonalyn F.\",\"email\":\"hyunj904@gmail.com\",\"password\":\"$2y$10$xccw.aIIeVrJyIEkN6JeiuYL.fWxjevbFQgkveRNt1wjzKsE75Bay\",\"role\":\"trainee\",\"date_created\":\"2026-01-23 20:45:27\",\"status\":\"Active\",\"program\":null,\"specialization\":null,\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.782043\",\"pin_longitude\":\"120.878094\",\"pin_radius\":\"43\",\"pin_location_name\":\"LEMS ADMIN\"}', '{\"id\":\"30\",\"user_id\":\"146\",\"fullname\":\"Cruz, Jonalyn F.\",\"address\":\"1205 palikod treet, Lalakhan, Sta. Maria, Bulacan\",\"gender\":\"Female\",\"civil_status\":\"Married\",\"birthday\":\"2006-02-17\",\"age\":\"19\",\"contact_number\":\"09544514452\",\"employment_status\":\"Unemployed\",\"education\":\"Vocational\",\"failure_notes_copy\":null,\"trainings_attended\":\"NA\",\"toolkit_received\":\"NA\",\"valid_id\":\"[\\\"valid_id_146_1771288300.jpg\\\"]\",\"valid_id_files\":null,\"voters_certificate\":\"[\\\"SCANNED_CERT_1769219127_0.jpg\\\"]\",\"detected_id_types\":\"[]\",\"voters_cert_files\":null,\"email\":\"hyunj904@gmail.com\",\"password\":\"$2y$10$xccw.aIIeVrJyIEkN6JeiuYL.fWxjevbFQgkveRNt1wjzKsE75Bay\",\"email_verified\":\"1\",\"verification_token\":null,\"token_expiry\":null,\"created_at\":\"2026-01-23 20:45:27\",\"lastname\":\"Cruz\",\"firstname\":\"Jonalyn\",\"middleinitial\":\"F\",\"house_street\":\"1205 palikod treet\",\"barangay\":\"Lalakhan\",\"municipality\":\"Sta. Maria\",\"city\":\"Bulacan\",\"gender_specify\":\"\",\"education_specify\":\"\",\"role\":\"trainee\",\"valid_id_links\":null,\"voters_cert_links\":null,\"special_categories\":null,\"special_categories_specify\":null,\"applicant_type\":\"\",\"nc_holder\":\"yes\"}', '[\"6\",\"10\",\"14\"]', '[{\"id\":\"6\",\"user_id\":\"146\",\"original_program_id\":\"105\",\"enrollment_id\":\"99\",\"feedback_id\":\"15\",\"archive_program_id\":null,\"program_name\":\"Cookery\",\"program_duration\":\"1\",\"program_duration_unit\":\"Days\",\"program_schedule_start\":\"2026-02-02\",\"program_schedule_end\":\"2026-02-02\",\"program_trainer_id\":\"96\",\"program_trainer_name\":\"Sam\",\"program_category_id\":\"12\",\"program_total_slots\":\"2\",\"program_slots_available\":\"1\",\"program_other_trainer\":\"-\",\"program_show_on_index\":\"1\",\"enrollment_status\":\"completed\",\"enrollment_applied_at\":\"2026-02-02 01:28:43\",\"enrollment_completed_at\":\"2026-02-02 07:40:48\",\"enrollment_attendance\":\"100\",\"enrollment_approval_status\":\"pending\",\"enrollment_approved_by\":null,\"enrollment_approved_date\":null,\"enrollment_assessment\":\"Passed\",\"trainer_expertise_rating\":\"5\",\"trainer_communication_rating\":\"5\",\"trainer_methods_rating\":\"5\",\"trainer_requests_rating\":\"4\",\"trainer_questions_rating\":\"4\",\"trainer_instructions_rating\":\"4\",\"trainer_prioritization_rating\":\"4\",\"trainer_fairness_rating\":\"4\",\"program_knowledge_rating\":\"5\",\"program_process_rating\":\"5\",\"program_environment_rating\":\"5\",\"program_algorithms_rating\":\"5\",\"program_preparation_rating\":\"5\",\"system_technology_rating\":\"4\",\"system_workflow_rating\":\"4\",\"system_instructions_rating\":\"4\",\"system_answers_rating\":\"4\",\"system_performance_rating\":\"4\",\"feedback_comments\":\"\",\"feedback_submitted_at\":\"2026-02-02 07:40:48\",\"archived_at\":\"2026-02-02 07:40:48\",\"archive_trigger\":\"enrollment_completed\",\"archive_source\":\"direct_from_programs\"},{\"id\":\"10\",\"user_id\":\"146\",\"original_program_id\":\"106\",\"enrollment_id\":\"104\",\"feedback_id\":\"18\",\"archive_program_id\":null,\"program_name\":\"Bookkeeping\",\"program_duration\":\"1\",\"program_duration_unit\":\"Days\",\"program_schedule_start\":\"2026-02-04\",\"program_schedule_end\":\"2026-02-04\",\"program_trainer_id\":\"147\",\"program_trainer_name\":\"ashily \",\"program_category_id\":\"5\",\"program_total_slots\":\"12\",\"program_slots_available\":\"7\",\"program_other_trainer\":\"-\",\"program_show_on_index\":\"1\",\"enrollment_status\":\"completed\",\"enrollment_applied_at\":\"2026-02-03 21:22:29\",\"enrollment_completed_at\":\"2026-02-04 01:41:19\",\"enrollment_attendance\":\"100\",\"enrollment_approval_status\":\"pending\",\"enrollment_approved_by\":null,\"enrollment_approved_date\":null,\"enrollment_assessment\":\"Passed\",\"trainer_expertise_rating\":\"4\",\"trainer_communication_rating\":\"4\",\"trainer_methods_rating\":\"5\",\"trainer_requests_rating\":\"5\",\"trainer_questions_rating\":\"5\",\"trainer_instructions_rating\":\"5\",\"trainer_prioritization_rating\":\"3\",\"trainer_fairness_rating\":\"3\",\"program_knowledge_rating\":\"3\",\"program_process_rating\":\"5\",\"program_environment_rating\":\"5\",\"program_algorithms_rating\":\"5\",\"program_preparation_rating\":\"4\",\"system_technology_rating\":\"3\",\"system_workflow_rating\":\"4\",\"system_instructions_rating\":\"4\",\"system_answers_rating\":\"4\",\"system_performance_rating\":\"4\",\"feedback_comments\":\"ertytrtyiiuytr\",\"feedback_submitted_at\":\"2026-02-04 01:41:19\",\"archived_at\":\"2026-02-04 01:41:19\",\"archive_trigger\":\"enrollment_completed\",\"archive_source\":\"direct_from_programs\"},{\"id\":\"14\",\"user_id\":\"146\",\"original_program_id\":\"112\",\"enrollment_id\":\"121\",\"feedback_id\":\"19\",\"archive_program_id\":null,\"program_name\":\"Cookery\",\"program_duration\":\"1\",\"program_duration_unit\":\"Days\",\"program_schedule_start\":\"2026-02-17\",\"program_schedule_end\":\"2026-02-17\",\"program_trainer_id\":\"96\",\"program_trainer_name\":\"Sam\",\"program_category_id\":\"12\",\"program_total_slots\":\"2\",\"program_slots_available\":\"0\",\"program_other_trainer\":\"-\",\"program_show_on_index\":\"0\",\"enrollment_status\":\"completed\",\"enrollment_applied_at\":\"2026-02-16 20:09:16\",\"enrollment_completed_at\":\"2026-02-16 20:13:36\",\"enrollment_attendance\":\"100\",\"enrollment_approval_status\":\"pending\",\"enrollment_approved_by\":null,\"enrollment_approved_date\":null,\"enrollment_assessment\":\"Passed\",\"trainer_expertise_rating\":\"5\",\"trainer_communication_rating\":\"5\",\"trainer_methods_rating\":\"3\",\"trainer_requests_rating\":\"4\",\"trainer_questions_rating\":\"4\",\"trainer_instructions_rating\":\"3\",\"trainer_prioritization_rating\":\"4\",\"trainer_fairness_rating\":\"5\",\"program_knowledge_rating\":\"4\",\"program_process_rating\":\"3\",\"program_environment_rating\":\"3\",\"program_algorithms_rating\":\"3\",\"program_preparation_rating\":\"4\",\"system_technology_rating\":\"5\",\"system_workflow_rating\":\"4\",\"system_instructions_rating\":\"3\",\"system_answers_rating\":\"4\",\"system_performance_rating\":\"5\",\"feedback_comments\":\"\",\"feedback_submitted_at\":\"2026-02-16 20:13:36\",\"archived_at\":\"2026-02-16 20:13:36\",\"archive_trigger\":\"enrollment_completed\",\"archive_source\":\"direct_from_programs\"}]', 'Cruz, Jonalyn F.', 'hyunj904@gmail.com', 'trainee', '2026-03-12 18:22:37', 2, '2026-03-12 19:08:13', 2, 'restored'),
(10, 171, '{\"id\":\"171\",\"fullname\":\"lucia garcia\",\"email\":\"yensillano@gmail.com\",\"password\":\"$2y$10$Dfyqz4jR\\/LKr.4A7T3qDOeIQm\\/MyD\\/j0slURf0EeLOiFv3YIiQhu.\",\"role\":\"trainer\",\"date_created\":\"2026-03-09 07:19:09\",\"status\":\"Active\",\"program\":\"\",\"specialization\":\"Healthcare\",\"other_programs\":null,\"allow_multiple_programs\":\"0\",\"must_reset\":\"0\",\"reset_token\":null,\"reset_expires\":null,\"full_name\":null,\"is_available\":\"1\",\"assigned_count\":\"0\",\"max_programs\":\"1\",\"pin_latitude\":\"14.836537\",\"pin_longitude\":\"120.847034\",\"pin_radius\":\"100\",\"pin_location_name\":\"Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines\"}', NULL, '[]', '[]', 'lucia garcia', 'yensillano@gmail.com', 'trainer', '2026-04-10 16:44:05', 2, NULL, NULL, 'archived');

-- --------------------------------------------------------

--
-- Table structure for table `archive_programs`
--

CREATE TABLE `archive_programs` (
  `id` int(11) NOT NULL,
  `original_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `duration` int(11) NOT NULL DEFAULT 0,
  `durationUnit` varchar(50) NOT NULL DEFAULT 'Days',
  `scheduleStart` date DEFAULT NULL,
  `scheduleEnd` date DEFAULT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  `trainer` varchar(255) DEFAULT NULL,
  `slotsAvailable` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `other_trainer` text DEFAULT '-',
  `status` enum('active','archived') DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `duration_unit` varchar(50) DEFAULT 'Days',
  `is_archived` tinyint(1) DEFAULT 0,
  `total_slots` int(11) DEFAULT 0,
  `show_on_index` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `archive_programs`
--

INSERT INTO `archive_programs` (`id`, `original_id`, `name`, `duration`, `durationUnit`, `scheduleStart`, `scheduleEnd`, `trainer_id`, `trainer`, `slotsAvailable`, `created_at`, `updated_at`, `other_trainer`, `status`, `archived_at`, `category_id`, `duration_unit`, `is_archived`, `total_slots`, `show_on_index`) VALUES
(114, 110, 'BASIC COMPUTER LITERACY', 1, 'Days', '2026-02-09', '2026-02-09', 147, 'ashily ', 15, '2026-01-30 00:58:30', '2026-02-08 06:26:46', '-', 'active', '2026-02-09 03:25:18', 5, 'Days', 0, 15, 0),
(123, 101, 'ict', 15, 'Days', '2026-02-12', '2026-02-26', 97, 'haniie yoon', 35, '2026-02-01 15:29:33', '2026-02-21 04:54:12', '-', 'active', '2026-02-26 03:14:54', 10, 'Days', 0, 35, 0),
(124, 100, 'Candle, Soap, and Perfume Making', 20, 'Days', '2026-02-08', '2026-02-27', 149, 'Yen Ashitrid', 25, '2026-02-01 06:58:20', '2026-02-17 01:01:44', '-', 'active', '2026-02-27 00:07:48', 6, 'Days', 0, 25, 1),
(127, 120, 'Cookery', 2, 'Days', '2026-02-27', '2026-02-28', 96, 'Sam', 2, '2026-02-02 03:47:31', '2026-03-02 10:23:32', '-', 'active', '2026-03-02 05:23:32', 12, 'Days', 0, 2, 0),
(130, 124, 'Automotive', 2, 'Days', '2026-03-13', '2026-03-14', 101, 'adi hue', 3, '2026-02-04 06:46:38', '2026-03-14 02:22:02', '-', 'active', '2026-03-13 22:22:08', 7, 'Days', 0, 3, 0),
(134, 130, 'Candle, Soap, and Perfume Making', 1, 'Days', '2026-03-23', '2026-03-23', 171, 'lucia garcia', 2, '2026-03-12 10:45:46', '2026-03-24 05:35:55', '-', 'active', '2026-03-24 01:35:55', 6, 'Days', 0, 2, 0),
(136, 128, 'ICT Software Developer', 1, 'Days', '2026-03-25', '2026-03-25', 149, 'Yen Ashitrid', 5, '2026-03-16 05:47:59', '2026-04-02 10:20:32', '-', 'active', '2026-04-02 06:20:32', 10, 'Days', 0, 5, 1),
(139, 126, 'Bread & Pastry', 9, 'Days', '2026-04-02', '2026-04-10', 96, 'Sam', 14, '2026-03-12 16:13:15', '2026-04-09 03:18:33', '-', 'active', '2026-04-12 03:35:46', 12, 'Days', 0, 15, 0),
(140, 131, 'Bookkeeping', 6, 'Days', '2026-04-05', '2026-04-10', 169, 'Lexis ', 7, '2026-03-03 09:36:20', '2026-04-08 10:59:35', '-', 'active', '2026-04-12 03:35:46', 2, 'Days', 0, 8, 0),
(141, 133, 'Beauty Care Services', 2, 'Days', '2026-04-10', '2026-04-11', 147, 'ashily ', 5, '2026-02-01 04:13:32', '2026-04-10 13:57:45', '-', 'active', '2026-04-12 03:35:46', 11, 'Days', 0, 5, 0),
(142, 134, 'Barista Training', 3, 'Days', '2026-04-09', '2026-04-11', 158, 'Eimarie clair', 15, '2026-02-26 08:20:38', '2026-04-10 13:57:45', '-', 'active', '2026-04-12 03:35:46', 1, 'Days', 0, 15, 1);

--
-- Triggers `archive_programs`
--
DELIMITER $$
CREATE TRIGGER `trg_archive_history_on_program_archive` AFTER INSERT ON `archive_programs` FOR EACH ROW BEGIN
    -- Only trigger if this is actually an archived program (original_id is not null)
    IF NEW.original_id IS NOT NULL THEN
        -- Archive all enrollments and feedback for this program
        INSERT INTO archived_history (
            user_id,
            original_program_id,
            enrollment_id,
            feedback_id,
            archive_program_id,
            
            -- Program data (from archive_programs)
            program_name,
            program_duration,
            program_duration_unit,
            program_schedule_start,
            program_schedule_end,
            program_trainer_id,
            program_trainer_name,
            program_category_id,
            program_total_slots,
            program_slots_available,
            program_other_trainer,
            program_show_on_index,
            
            -- Enrollment data
            enrollment_status,
            enrollment_applied_at,
            enrollment_completed_at,
            enrollment_attendance,
            enrollment_approval_status,
            enrollment_approved_by,
            enrollment_approved_date,
            enrollment_assessment,
            
            -- Feedback data
            trainer_expertise_rating,
            trainer_communication_rating,
            trainer_methods_rating,
            trainer_requests_rating,
            trainer_questions_rating,
            trainer_instructions_rating,
            trainer_prioritization_rating,
            trainer_fairness_rating,
            program_knowledge_rating,
            program_process_rating,
            program_environment_rating,
            program_algorithms_rating,
            program_preparation_rating,
            system_technology_rating,
            system_workflow_rating,
            system_instructions_rating,
            system_answers_rating,
            system_performance_rating,
            feedback_comments,
            feedback_submitted_at,
            
            archive_trigger,
            archive_source
        )
        SELECT DISTINCT
            e.user_id,
            e.program_id,
            e.id AS enrollment_id,
            f.id AS feedback_id,
            NEW.id AS archive_program_id,
            
            -- Program data from archive_programs
            NEW.name,
            NEW.duration,
            COALESCE(NEW.duration_unit, NEW.durationUnit, 'Days'),
            NEW.scheduleStart,
            NEW.scheduleEnd,
            NEW.trainer_id,
            NEW.trainer,
            NEW.category_id,
            COALESCE(NEW.total_slots, 0),
            COALESCE(NEW.slotsAvailable, 0),
            COALESCE(NEW.other_trainer, '-'),
            COALESCE(NEW.show_on_index, 0),
            
            -- Enrollment data
            COALESCE(e.enrollment_status, 'pending'),
            e.applied_at,
            e.completed_at,
            COALESCE(e.attendance, 0),
            COALESCE(e.approval_status, 'pending'),
            e.approved_by,
            e.approved_date,
            e.assessment,
            
            -- Feedback data (if exists)
            f.trainer_expertise,
            f.trainer_communication,
            f.trainer_methods,
            f.trainer_requests,
            f.trainer_questions,
            f.trainer_instructions,
            f.trainer_prioritization,
            f.trainer_fairness,
            f.program_knowledge,
            f.program_process,
            f.program_environment,
            f.program_algorithms,
            f.program_preparation,
            f.system_technology,
            f.system_workflow,
            f.system_instructions,
            f.system_answers,
            f.system_performance,
            f.additional_comments,
            f.submitted_at,
            
            'program_moved_to_archive',
            'archive_programs_table'
        FROM enrollments e
        LEFT JOIN feedback f ON e.user_id = f.user_id AND e.program_id = f.program_id
        WHERE e.program_id = NEW.original_id
          -- Only archive if not already archived for this program archive
          AND NOT EXISTS (
            SELECT 1 FROM archived_history ah 
            WHERE ah.enrollment_id = e.id 
            AND ah.archive_trigger = 'program_moved_to_archive'
            AND ah.archive_program_id = NEW.id
          );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `assessment_components`
--

CREATE TABLE `assessment_components` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `practical_score` int(11) DEFAULT NULL,
  `practical_max_score` int(11) DEFAULT 100,
  `practical_passed` tinyint(1) DEFAULT NULL,
  `practical_date` date DEFAULT NULL,
  `practical_notes` text DEFAULT NULL,
  `practical_skills` text DEFAULT NULL,
  `written_score` int(11) DEFAULT NULL,
  `written_max_score` int(11) DEFAULT 100,
  `written_passed` tinyint(1) DEFAULT NULL,
  `written_date` date DEFAULT NULL,
  `written_notes` text DEFAULT NULL,
  `written_topics` text DEFAULT NULL,
  `project_title` varchar(255) DEFAULT NULL,
  `project_description` text DEFAULT NULL,
  `project_grade` enum('Excellent','Satisfactory','Needs Improvement','Failed') DEFAULT NULL,
  `project_passed` tinyint(1) DEFAULT NULL,
  `project_date` date DEFAULT NULL,
  `project_photo_path` varchar(255) DEFAULT NULL,
  `project_notes` text DEFAULT NULL,
  `project_instruction` text DEFAULT NULL,
  `project_rubrics` text DEFAULT NULL,
  `project_title_override` varchar(255) DEFAULT NULL,
  `project_visible_to_trainee` tinyint(1) DEFAULT 0,
  `oral_rating` enum('Excellent','Satisfactory','Needs Improvement','Failed') DEFAULT NULL,
  `oral_passed` tinyint(1) DEFAULT NULL,
  `oral_date` date DEFAULT NULL,
  `oral_notes` text DEFAULT NULL,
  `oral_questions_visible_to_trainee` tinyint(1) DEFAULT 0,
  `oral_answers` text DEFAULT NULL,
  `overall_result` enum('Passed','Failed') DEFAULT NULL,
  `assessed_by` varchar(255) DEFAULT NULL,
  `assessed_at` timestamp NULL DEFAULT current_timestamp(),
  `project_submitted_by_trainee` tinyint(1) DEFAULT 0,
  `project_submitted_at` datetime DEFAULT NULL,
  `oral_submitted_by_trainee` tinyint(1) DEFAULT 0,
  `oral_submitted_at` datetime DEFAULT NULL,
  `oral_questions_set` tinyint(1) DEFAULT 0,
  `oral_questions_set_at` datetime DEFAULT NULL,
  `oral_score` decimal(5,2) DEFAULT NULL,
  `oral_max_score` int(11) DEFAULT 100,
  `project_score` decimal(5,2) DEFAULT NULL,
  `project_total_max` int(11) DEFAULT 100,
  `project_max_score` int(11) DEFAULT 100,
  `practical_total_score` decimal(5,2) DEFAULT 0.00,
  `overall_total_score` decimal(5,2) DEFAULT 0.00,
  `oral_questions_finalized` tinyint(1) DEFAULT 0,
  `oral_questions_finalized_at` datetime DEFAULT NULL,
  `oral_questions` text DEFAULT NULL,
  `practical_skills_grading` text DEFAULT NULL,
  `is_finalized` tinyint(1) DEFAULT 0,
  `practical_skills_saved` tinyint(1) DEFAULT 0,
  `oral_questions_saved` tinyint(1) DEFAULT 0,
  `reset_count` int(11) DEFAULT 0,
  `last_reset_at` datetime DEFAULT NULL,
  `reset_history` text DEFAULT NULL,
  `reset_version` int(11) DEFAULT 0,
  `passing_percentage` int(11) DEFAULT 75,
  `passing_percentage_validated` tinyint(1) DEFAULT 1,
  `practical_passing_percentage` decimal(5,2) DEFAULT 75.00,
  `project_passing_percentage` decimal(5,2) DEFAULT 75.00,
  `oral_passing_percentage` decimal(5,2) DEFAULT 75.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent') NOT NULL,
  `marked_by` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issued_date` datetime DEFAULT current_timestamp(),
  `pdf_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatory`
--

CREATE TABLE `certificate_signatory` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `certificate_unique_id` varchar(100) NOT NULL,
  `barcode_data` text DEFAULT NULL,
  `original_signatory` text DEFAULT NULL,
  `edited_signatory` text DEFAULT NULL,
  `signatory_name` varchar(255) DEFAULT NULL,
  `signatory_title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatory_settings`
--

CREATE TABLE `certificate_signatory_settings` (
  `id` int(11) NOT NULL,
  `signatory_name` varchar(255) NOT NULL,
  `signatory_title` varchar(255) NOT NULL,
  `signatory_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certificate_signatory_settings`
--

INSERT INTO `certificate_signatory_settings` (`id`, `signatory_name`, `signatory_title`, `signatory_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ZENAIDA S. MANINGAS', 'PESO Manager', 1, 1, '2026-04-09 17:00:10', '2026-04-09 17:00:10'),
(2, 'JOHN SANTOS', 'Municipal Vice Mayor', 2, 1, '2026-04-09 17:00:10', '2026-04-09 17:00:10'),
(3, 'ALKIR DOE', 'Municipal Mayor', 3, 1, '2026-04-09 17:00:10', '2026-04-09 17:00:10');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatory_templates`
--

CREATE TABLE `certificate_signatory_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `signatory_name` varchar(255) NOT NULL,
  `signatory_title` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certificate_signatory_templates`
--

INSERT INTO `certificate_signatory_templates` (`id`, `template_name`, `signatory_name`, `signatory_title`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Default Signatory 1', 'ZENAIDA S. MANINGAS', 'PESO Manager', 0, '2026-04-08 14:08:14', '2026-04-09 02:03:51'),
(2, 'Default Signatory 2', 'ROBERTO B. PEREZ', 'Municipal Vice Mayor', 0, '2026-04-08 14:08:14', '2026-04-09 02:03:51'),
(3, 'Default Signatory 3', 'BARTOLOME R. RAMOS', 'Municipal Mayor', 0, '2026-04-08 14:08:14', '2026-04-09 02:03:51');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `archived_program_id` int(11) DEFAULT NULL,
  `program_name_backup` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'approved',
  `attendance` int(11) DEFAULT 0,
  `applied_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `enrollment_status` enum('pending','approved','rejected','revision_needed','completed') DEFAULT 'pending',
  `enrollment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `assessment` varchar(255) DEFAULT NULL,
  `failed_reason` text DEFAULT NULL,
  `failure_notes` text DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','revision_needed','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `waiting_list_position` int(11) DEFAULT NULL,
  `priority_level` enum('low','medium','high') DEFAULT 'medium',
  `revision_requests_id` int(11) DEFAULT NULL,
  `assessment_status` enum('Pending','In Progress','Completed') DEFAULT 'Pending',
  `assessment_completed_at` datetime DEFAULT NULL,
  `overall_result` varchar(20) DEFAULT NULL,
  `completion_date` datetime DEFAULT NULL,
  `assessed_by` varchar(255) DEFAULT NULL,
  `assessed_at` datetime DEFAULT NULL,
  `assessment_result` varchar(10) DEFAULT NULL,
  `results` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `enrollments`
--
DELIMITER $$
CREATE TRIGGER `trg_archive_history_on_enrollment_completed` AFTER UPDATE ON `enrollments` FOR EACH ROW BEGIN
    -- Check if enrollment status changed to completed
    IF (OLD.enrollment_status != 'completed' AND NEW.enrollment_status = 'completed') OR
       (OLD.approval_status != 'completed' AND NEW.approval_status = 'completed') THEN
        
        -- Get archive_program_id if program is archived
        SET @archive_program_id = NULL;
        SELECT id INTO @archive_program_id 
        FROM archive_programs 
        WHERE original_id = NEW.program_id 
        LIMIT 1;
        
        -- Archive this completed enrollment
        INSERT INTO archived_history (
            user_id,
            original_program_id,
            enrollment_id,
            feedback_id,
            archive_program_id,
            
            -- Program data
            program_name,
            program_duration,
            program_duration_unit,
            program_schedule_start,
            program_schedule_end,
            program_trainer_id,
            program_trainer_name,
            program_category_id,
            program_total_slots,
            program_slots_available,
            program_other_trainer,
            program_show_on_index,
            
            -- Enrollment data
            enrollment_status,
            enrollment_applied_at,
            enrollment_completed_at,
            enrollment_attendance,
            enrollment_approval_status,
            enrollment_approved_by,
            enrollment_approved_date,
            enrollment_assessment,
            
            -- Feedback data
            trainer_expertise_rating,
            trainer_communication_rating,
            trainer_methods_rating,
            trainer_requests_rating,
            trainer_questions_rating,
            trainer_instructions_rating,
            trainer_prioritization_rating,
            trainer_fairness_rating,
            program_knowledge_rating,
            program_process_rating,
            program_environment_rating,
            program_algorithms_rating,
            program_preparation_rating,
            system_technology_rating,
            system_workflow_rating,
            system_instructions_rating,
            system_answers_rating,
            system_performance_rating,
            feedback_comments,
            feedback_submitted_at,
            
            archive_trigger,
            archive_source
        )
        SELECT 
            NEW.user_id,
            NEW.program_id,
            NEW.id,
            f.id,
            @archive_program_id,
            
            -- Get program data
            COALESCE(ap.name, p.name),
            COALESCE(ap.duration, p.duration),
            COALESCE(ap.duration_unit, ap.durationUnit, p.duration_unit, p.durationUnit, 'Days'),
            COALESCE(ap.scheduleStart, p.scheduleStart),
            COALESCE(ap.scheduleEnd, p.scheduleEnd),
            COALESCE(ap.trainer_id, p.trainer_id),
            COALESCE(ap.trainer, p.trainer),
            COALESCE(ap.category_id, p.category_id),
            COALESCE(ap.total_slots, p.total_slots, 0),
            COALESCE(ap.slotsAvailable, p.slotsAvailable, 0),
            COALESCE(ap.other_trainer, p.other_trainer, '-'),
            COALESCE(ap.show_on_index, p.show_on_index, 0),
            
            -- Enrollment data
            NEW.enrollment_status,
            NEW.applied_at,
            NEW.completed_at,
            COALESCE(NEW.attendance, 0),
            NEW.approval_status,
            NEW.approved_by,
            NEW.approved_date,
            NEW.assessment,
            
            -- Feedback data
            f.trainer_expertise,
            f.trainer_communication,
            f.trainer_methods,
            f.trainer_requests,
            f.trainer_questions,
            f.trainer_instructions,
            f.trainer_prioritization,
            f.trainer_fairness,
            f.program_knowledge,
            f.program_process,
            f.program_environment,
            f.program_algorithms,
            f.program_preparation,
            f.system_technology,
            f.system_workflow,
            f.system_instructions,
            f.system_answers,
            f.system_performance,
            f.additional_comments,
            f.submitted_at,
            
            'enrollment_completed',
            CASE WHEN @archive_program_id IS NOT NULL THEN 'archive_programs_table' ELSE 'direct_from_programs' END
        FROM programs p
        LEFT JOIN archive_programs ap ON p.id = ap.original_id
        LEFT JOIN feedback f ON NEW.user_id = f.user_id AND NEW.program_id = f.program_id
        WHERE p.id = NEW.program_id
          -- Only insert if not already archived
          AND NOT EXISTS (
            SELECT 1 FROM archived_history ah 
            WHERE ah.enrollment_id = NEW.id
            AND ah.archive_trigger = 'enrollment_completed'
          );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `faqs`
--

CREATE TABLE `faqs` (
  `id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faqs`
--

INSERT INTO `faqs` (`id`, `question`, `answer`) VALUES
(1, 'How do I enroll in a livelihood program?', 'You can register by creating an account and filling out the enrollment form.'),
(2, 'Is my personal information secure?', 'Yes. All data is stored securely and handled based on municipal data privacy guidelines.'),
(3, 'How can I track my training progress?', 'After logging in, you can view your training status, attendance, and updates from your training progress page.');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `archived_program_id` int(11) DEFAULT NULL,
  `program_name_backup` varchar(255) DEFAULT NULL,
  `trainer_expertise` int(11) DEFAULT NULL,
  `trainer_communication` int(11) DEFAULT NULL,
  `trainer_methods` int(11) DEFAULT NULL,
  `trainer_requests` int(11) DEFAULT NULL,
  `trainer_questions` int(11) DEFAULT NULL,
  `trainer_instructions` int(11) DEFAULT NULL,
  `trainer_prioritization` int(11) DEFAULT NULL,
  `trainer_fairness` int(11) DEFAULT NULL,
  `program_knowledge` int(11) DEFAULT NULL,
  `program_process` int(11) DEFAULT NULL,
  `program_environment` int(11) DEFAULT NULL,
  `program_algorithms` int(11) DEFAULT NULL,
  `program_preparation` int(11) DEFAULT NULL,
  `system_technology` int(11) DEFAULT NULL,
  `system_workflow` int(11) DEFAULT NULL,
  `system_instructions` int(11) DEFAULT NULL,
  `system_answers` int(11) DEFAULT NULL,
  `system_performance` int(11) DEFAULT NULL,
  `additional_comments` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `program_id`, `enrollment_id`, `archived_program_id`, `program_name_backup`, `trainer_expertise`, `trainer_communication`, `trainer_methods`, `trainer_requests`, `trainer_questions`, `trainer_instructions`, `trainer_prioritization`, `trainer_fairness`, `program_knowledge`, `program_process`, `program_environment`, `program_algorithms`, `program_preparation`, `system_technology`, `system_workflow`, `system_instructions`, `system_answers`, `system_performance`, `additional_comments`, `submitted_at`, `archived_at`) VALUES
(21, 92, 120, NULL, 120, 'Cookery', 4, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 'jhgfdgfrghbjnkj', '2026-02-28 05:39:56', '2026-03-02 10:23:32'),
(22, 165, 124, NULL, 124, 'Automotive', 5, 5, 5, 5, 5, 5, 5, 5, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, 'goodjob', '2026-03-13 08:15:47', '2026-03-14 02:22:08'),
(36, 161, 124, NULL, 124, 'Automotive', 5, 5, 5, 5, 5, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5, '', '2026-03-14 02:03:12', '2026-03-14 02:22:08'),
(37, 161, 127, NULL, 127, 'Beauty Care Services', 5, 5, 5, 4, 5, 4, 5, 5, 5, 3, 4, 2, 4, 5, 5, 5, 4, 4, '', '2026-03-14 04:51:09', '2026-03-15 08:11:28'),
(38, 146, 127, NULL, 127, 'Beauty Care Services', 5, 5, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 'goodjob', '2026-03-14 15:14:29', '2026-03-15 08:11:28'),
(43, 146, 130, NULL, 130, 'Candle, Soap, and Perfume Making', 3, 4, 3, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, '', '2026-03-24 02:50:57', '2026-03-24 05:35:55'),
(46, 151, 130, NULL, 130, 'Candle, Soap, and Perfume Making', 5, 4, 5, 4, 5, 4, 4, 4, 4, 4, 5, 5, 4, 5, 5, 4, 4, 4, '', '2026-03-24 03:44:50', '2026-03-24 05:35:55'),
(47, 170, 129, NULL, 129, 'Barista Training', 4, 4, 4, 4, 5, 4, 5, 5, 4, 5, 4, 5, 5, 5, 5, 5, 5, 5, 'goodjob', '2026-03-24 05:26:38', '2026-03-25 00:07:10'),
(48, 150, 129, NULL, 129, 'Barista Training', 5, 5, 4, 5, 4, 5, 4, 5, 4, 4, 4, 4, 5, 5, 5, 5, 4, 5, '', '2026-03-24 05:28:11', '2026-03-25 00:07:10'),
(49, 146, 128, NULL, 128, 'ICT Software Developer', 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, '', '2026-03-25 02:35:47', '2026-04-02 10:20:32'),
(50, 161, 132, NULL, 132, 'ICT NETWORKING', 5, 4, 4, 5, 4, 4, 4, 5, 4, 4, 5, 5, 4, 3, 4, 4, 4, 4, '', '2026-04-09 04:35:49', '2026-04-09 11:21:20'),
(53, 173, 126, NULL, 126, 'Bread & Pastry', 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, '', '2026-04-09 17:04:01', '2026-04-12 07:35:46'),
(54, 161, 133, NULL, 133, 'Beauty Care Services', 4, 4, 4, 4, 4, 4, 4, 4, 2, 2, 3, 2, 3, 3, 3, 3, 2, 2, '', '2026-04-11 07:55:49', '2026-04-12 07:35:46');

--
-- Triggers `feedback`
--
DELIMITER $$
CREATE TRIGGER `trg_after_feedback_insert` AFTER INSERT ON `feedback` FOR EACH ROW BEGIN
    UPDATE archived_history ah
    SET 
        ah.trainer_expertise_rating = NEW.trainer_expertise,
        ah.trainer_communication_rating = NEW.trainer_communication,
        ah.trainer_methods_rating = NEW.trainer_methods,
        ah.trainer_requests_rating = NEW.trainer_requests,
        ah.trainer_questions_rating = NEW.trainer_questions,
        ah.trainer_instructions_rating = NEW.trainer_instructions,
        ah.trainer_prioritization_rating = NEW.trainer_prioritization,
        ah.trainer_fairness_rating = NEW.trainer_fairness,
        ah.program_knowledge_rating = NEW.program_knowledge,
        ah.program_process_rating = NEW.program_process,
        ah.program_environment_rating = NEW.program_environment,
        ah.program_algorithms_rating = NEW.program_algorithms,
        ah.program_preparation_rating = NEW.program_preparation,
        ah.system_technology_rating = NEW.system_technology,
        ah.system_workflow_rating = NEW.system_workflow,
        ah.system_instructions_rating = NEW.system_instructions,
        ah.system_answers_rating = NEW.system_answers,
        ah.system_performance_rating = NEW.system_performance,
        ah.feedback_comments = NEW.additional_comments,
        ah.feedback_submitted_at = NEW.submitted_at,
        ah.feedback_id = NEW.id
    WHERE ah.enrollment_id = NEW.enrollment_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `related_id`, `related_type`, `is_read`, `created_at`) VALUES
(1, 151, 'danger', 'Enrollment Rejected', 'Your enrollment for \'Dressmaking\' has been rejected. Reason: mjhgyft', 111, 'enrollment', 1, '2026-02-11 07:05:03'),
(3, 151, 'danger', 'Enrollment Rejected', 'Your enrollment for \'automotive\' has been rejected. Reason: l,', 114, 'enrollment', 1, '2026-02-11 07:43:19'),
(4, 151, 'warning', 'Revision Required', 'Your enrollment for \'automotive\' requires revision. Reason: jhfjyh', 115, 'enrollment', 1, '2026-02-11 08:07:09'),
(5, 151, 'warning', 'Revision Required', 'Your enrollment for \'automotive\' requires revision. Reason: jhfjyh', 115, 'enrollment', 1, '2026-02-11 08:32:28'),
(14, 92, 'success', 'Enrollment Approved', 'Your enrollment for \'Cookery\' has been approved.', 124, 'enrollment', 1, '2026-02-21 04:59:36'),
(15, 92, 'success', 'Enrollment Approved', 'Your enrollment for \'Cookery\' has been approved.', 125, 'enrollment', 1, '2026-02-21 09:32:12'),
(16, 2, 'info', 'New Program Application', 'A trainee has applied for program: automotive', NULL, NULL, 1, '2026-02-22 04:04:06'),
(18, 2, 'info', 'New Program Application', 'A trainee has applied for program: Candle, Soap, and Perfume Making', NULL, NULL, 1, '2026-02-22 04:08:59'),
(20, 92, 'success', 'Enrollment Approved', 'Your enrollment for \'Cookery\' has been approved.', 128, 'enrollment', 1, '2026-02-24 06:44:32'),
(22, 92, 'success', 'Enrollment Approved', 'Your enrollment for \'Cookery\' has been approved.', 134, 'enrollment', 1, '2026-02-27 05:14:01'),
(23, 92, 'success', 'Enrollment Approved', 'Your enrollment for \'Cookery\' has been approved.', 135, 'enrollment', 1, '2026-02-27 05:31:32'),
(24, 170, 'success', 'Enrollment Approved', 'Your enrollment for \'Bookkeeping\' has been approved.', 141, 'enrollment', 1, '2026-03-03 10:53:20'),
(25, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 140, 'enrollment', 1, '2026-03-03 13:55:51'),
(26, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 136, 'enrollment', 1, '2026-03-03 13:55:58'),
(27, 170, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 142, 'enrollment', 1, '2026-03-04 16:49:28'),
(30, 161, 'application', 'Application Submitted', 'Your application for program \'Automotive\' has been submitted successfully and is pending approval.', 151, 'enrollment', 1, '2026-03-12 14:52:43'),
(31, 165, 'application', 'Application Submitted', 'Your application for program \'Automotive\' has been submitted successfully and is pending approval.', 152, 'enrollment', 1, '2026-03-12 14:53:35'),
(32, 165, 'warning', 'Revision Required', 'Your enrollment for \'Automotive\' requires revision. Please check your email and update your profile/documents.', 152, 'enrollment', 1, '2026-03-12 14:57:14'),
(33, 161, 'danger', 'Enrollment Rejected', 'Your enrollment for \'Automotive\' has been rejected. Reason: the submmition of doc is already 3 times revision', 151, 'enrollment', 1, '2026-03-12 14:57:52'),
(35, 161, 'application', 'Application Submitted', 'Your application for program \'Automotive\' has been submitted successfully and is pending approval.', 153, 'enrollment', 1, '2026-03-12 15:23:31'),
(36, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Automotive\' has been approved.', 153, 'enrollment', 1, '2026-03-12 15:24:17'),
(37, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'Automotive\' has been approved.', 152, 'enrollment', 1, '2026-03-12 15:24:23'),
(38, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 155, 'enrollment', 1, '2026-03-14 02:37:50'),
(39, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 155, 'enrollment', 1, '2026-03-14 02:37:53'),
(40, 170, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 154, 'enrollment', 1, '2026-03-14 03:20:38'),
(41, 161, 'warning', 'Revision Required', 'Your enrollment for \'Candle, Soap, and Perfume Making\' requires revision. Please check your email and update your profile/documents.', 160, 'enrollment', 1, '2026-03-15 08:34:19'),
(42, 2, 'info', 'Revision Completed', 'Kier Hans has updated their profile. Enrollment is now pending review.', 160, 'enrollment', 1, '2026-03-15 08:40:17'),
(43, 161, 'warning', 'Revision Required', 'Your enrollment for \'Candle, Soap, and Perfume Making\' requires revision. Please check your email and update your profile/documents.', 160, 'enrollment', 1, '2026-03-15 08:43:10'),
(44, 2, 'info', 'Revision Completed', 'Kier Hans has updated their profile. Enrollment is now pending review.', 160, 'enrollment', 1, '2026-03-15 08:44:16'),
(45, 161, 'warning', 'Revision Required', 'Your enrollment for \'Candle, Soap, and Perfume Making\' requires revision. Please check your email and update your profile/documents.', 160, 'enrollment', 1, '2026-03-15 08:44:56'),
(46, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Candle, Soap, and Perfume Making\' has been approved.', 160, 'enrollment', 1, '2026-03-15 08:46:20'),
(47, 165, 'application', 'Application Submitted', 'Your application for program \'ICT Software Developer\' has been submitted successfully and is pending approval.', 161, 'enrollment', 1, '2026-03-16 05:52:38'),
(48, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT Software Developer\' has been approved.', 161, 'enrollment', 1, '2026-03-16 05:53:43'),
(49, 146, 'success', 'Enrollment Approved', 'Your enrollment for \'Candle, Soap, and Perfume Making\' has been approved.', 162, 'enrollment', 1, '2026-03-19 02:59:05'),
(50, 170, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 163, 'enrollment', 1, '2026-03-22 07:50:17'),
(51, 146, 'success', 'Enrollment Approved', 'Your enrollment for \'Candle, Soap, and Perfume Making\' has been approved.', 164, 'enrollment', 1, '2026-03-22 08:40:05'),
(52, 151, 'success', 'Enrollment Approved', 'Your enrollment for \'Candle, Soap, and Perfume Making\' has been approved.', 165, 'enrollment', 1, '2026-03-22 08:41:05'),
(53, 150, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 166, 'enrollment', 1, '2026-03-22 08:55:40'),
(54, 165, 'application', 'Application Submitted', 'Your application for program \'Bread & Pastry\' has been submitted successfully and is pending approval.', 168, 'enrollment', 1, '2026-03-23 15:07:26'),
(55, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 168, 'enrollment', 1, '2026-03-23 15:07:51'),
(56, 146, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 170, 'enrollment', 1, '2026-03-24 05:30:35'),
(57, 146, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 170, 'enrollment', 1, '2026-03-24 05:30:40'),
(58, 161, 'warning', 'Revision Required', 'Your enrollment for \'Bread & Pastry\' requires revision. Please check your email and update your profile/documents.', 169, 'enrollment', 1, '2026-03-24 11:21:34'),
(59, 161, 'warning', 'Revision Required', 'Your enrollment for \'Bread & Pastry\' requires revision. Please check your email and update your profile/documents.', 169, 'enrollment', 1, '2026-03-24 11:41:06'),
(60, 161, 'danger', 'Enrollment Rejected', 'Your enrollment for \'Bread & Pastry\' has been rejected. Reason: multiple revision', 169, 'enrollment', 1, '2026-03-24 11:53:41'),
(61, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 171, 'enrollment', 1, '2026-03-24 12:04:13'),
(62, 151, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT Software Developer\' has been approved.', 174, 'enrollment', 1, '2026-03-25 00:19:59'),
(63, 146, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT Software Developer\' has been approved.', 173, 'enrollment', 0, '2026-03-25 00:20:05'),
(64, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT Software Developer\' has been approved.', 172, 'enrollment', 1, '2026-03-25 00:20:11'),
(65, 150, 'application', 'Application Submitted', 'Your application for program \'Bread & Pastry\' has been submitted successfully and is pending approval.', 176, 'enrollment', 1, '2026-03-25 01:52:22'),
(66, 173, 'warning', 'Revision Required', 'Your enrollment for \'Bread & Pastry\' requires revision. Please check your email and update your profile/documents.', 175, 'enrollment', 1, '2026-03-25 01:54:31'),
(67, 173, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 175, 'enrollment', 1, '2026-03-25 01:59:44'),
(68, 170, 'success', 'Enrollment Approved', 'Your enrollment for \'Bookkeeping\' has been approved.', 177, 'enrollment', 0, '2026-04-05 14:59:17'),
(69, 150, 'success', 'Enrollment Approved', 'Your enrollment for \'Bread & Pastry\' has been approved.', 176, 'enrollment', 1, '2026-04-09 00:04:59'),
(70, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT NETWORKING\' has been approved.', 179, 'enrollment', 1, '2026-04-09 04:12:39'),
(71, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT NETWORKING\' has been approved.', 179, 'enrollment', 1, '2026-04-09 04:12:41'),
(72, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'ICT NETWORKING\' has been approved.', 178, 'enrollment', 1, '2026-04-09 04:12:47'),
(73, 175, 'application', 'Application Submitted', 'Your application for program \'Beauty Care Services\' has been submitted successfully and is pending approval.', 180, 'enrollment', 1, '2026-04-09 15:14:21'),
(74, 175, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 180, 'enrollment', 1, '2026-04-09 15:51:20'),
(75, 175, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 182, 'enrollment', 0, '2026-04-09 15:59:30'),
(76, 175, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 182, 'enrollment', 0, '2026-04-09 15:59:32'),
(77, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 183, 'enrollment', 1, '2026-04-09 16:25:45'),
(78, 161, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 183, 'enrollment', 1, '2026-04-09 16:25:48'),
(79, 175, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 184, 'enrollment', 0, '2026-04-09 17:29:04'),
(80, 175, 'success', 'Enrollment Approved', 'Your enrollment for \'Barista Training\' has been approved.', 185, 'enrollment', 0, '2026-04-09 18:59:05'),
(81, 165, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 186, 'enrollment', 1, '2026-04-09 22:42:40'),
(82, 176, 'success', 'Enrollment Approved', 'Your enrollment for \'Beauty Care Services\' has been approved.', 181, 'enrollment', 1, '2026-04-09 22:42:46');

-- --------------------------------------------------------

--
-- Table structure for table `oral_questions`
--

CREATE TABLE `oral_questions` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `max_score` int(11) DEFAULT 25,
  `score` decimal(5,2) DEFAULT NULL,
  `answer` text DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `oral_questions`
--

INSERT INTO `oral_questions` (`id`, `enrollment_id`, `question`, `max_score`, `score`, `answer`, `order_index`, `created_at`, `updated_at`) VALUES
(1, 162, 'New Question 1', 25, NULL, NULL, 0, '2026-03-19 11:31:38', '2026-03-19 11:31:38'),
(2, 162, 'New Question 3', 25, NULL, NULL, 1, '2026-03-19 11:31:38', '2026-03-19 11:31:38'),
(3, 162, 'New Question 4', 25, NULL, NULL, 2, '2026-03-19 11:31:38', '2026-03-19 11:31:38');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `permission_name`, `description`, `created_at`) VALUES
(1, 'full_access', 'Full system access', '2025-12-10 03:51:44'),
(2, 'manage_users', 'Manage users and their roles', '2025-12-10 03:51:44'),
(3, 'manage_programs', 'Create, edit, and delete training programs', '2025-12-10 03:51:44'),
(4, 'view_reports', 'View and generate reports', '2025-12-10 03:51:44'),
(5, 'enrollment_only', 'Enroll in programs only', '2025-12-10 03:51:44'),
(6, 'create_content', 'Create and manage training content', '2025-12-10 03:51:44'),
(7, 'system_settings', 'Access system settings', '2025-12-10 03:51:44'),
(8, 'backup_restore', 'Backup and restore database', '2025-12-10 03:51:44'),
(9, 'manage_roles', 'Manage roles and permissions', '2025-12-10 03:51:44');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `duration` int(11) NOT NULL DEFAULT 0,
  `durationUnit` varchar(50) NOT NULL DEFAULT 'Days',
  `scheduleStart` date DEFAULT NULL,
  `scheduleEnd` date DEFAULT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  `trainer` varchar(255) DEFAULT NULL,
  `slotsAvailable` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `other_trainer` text DEFAULT '-',
  `status` enum('active','deactivated') DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `duration_unit` varchar(50) DEFAULT 'Days',
  `is_archived` tinyint(1) DEFAULT 0,
  `total_slots` int(11) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `show_on_index` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `name`, `duration`, `durationUnit`, `scheduleStart`, `scheduleEnd`, `trainer_id`, `trainer`, `slotsAvailable`, `created_at`, `updated_at`, `other_trainer`, `status`, `archived_at`, `category_id`, `duration_unit`, `is_archived`, `total_slots`, `archived`, `show_on_index`) VALUES
(135, 'Tailoring', 10, 'Days', '2026-04-17', '2026-04-26', 101, 'adi hue', 5, '2026-04-10 15:22:05', '2026-04-10 15:22:05', '-', 'active', NULL, 14, 'Days', 0, 5, 0, 1),
(136, 'Perfume Making', 15, 'Days', '2026-04-22', '2026-05-06', 174, 'drea ly', 10, '2026-04-10 15:23:16', '2026-04-10 15:23:16', '-', 'active', NULL, 15, 'Days', 0, 10, 0, 1);

--
-- Triggers `programs`
--
DELIMITER $$
CREATE TRIGGER `trg_archive_history_on_program_status_change` AFTER UPDATE ON `programs` FOR EACH ROW BEGIN
    -- Check if program was just archived (is_archived changed from 0 to 1)
    IF (OLD.is_archived = 0 OR OLD.is_archived IS NULL) AND NEW.is_archived = 1 THEN
        
        -- First, check if program already exists in archive_programs
        IF NOT EXISTS (SELECT 1 FROM archive_programs WHERE original_id = OLD.id) THEN
            -- Insert into archive_programs
            INSERT INTO archive_programs (
                original_id,
                name,
                duration,
                durationUnit,
                scheduleStart,
                scheduleEnd,
                trainer_id,
                trainer,
                slotsAvailable,
                category_id,
                duration_unit,
                total_slots,
                show_on_index,
                other_trainer,
                status,
                archived_at
            )
            VALUES (
                OLD.id,
                OLD.name,
                OLD.duration,
                COALESCE(OLD.durationUnit, 'Days'),
                OLD.scheduleStart,
                OLD.scheduleEnd,
                OLD.trainer_id,
                OLD.trainer,
                OLD.slotsAvailable,
                OLD.category_id,
                COALESCE(OLD.duration_unit, OLD.durationUnit, 'Days'),
                COALESCE(OLD.total_slots, OLD.slotsAvailable),
                COALESCE(OLD.show_on_index, 0),
                COALESCE(OLD.other_trainer, '-'),
                'archived',
                NOW()
            );
            
            -- The trigger on archive_programs will handle the history archiving
        ELSE
            -- Program already in archive_programs, archive history directly
            INSERT INTO archived_history (
                user_id,
                original_program_id,
                enrollment_id,
                feedback_id,
                archive_program_id,
                
                -- Program data
                program_name,
                program_duration,
                program_duration_unit,
                program_schedule_start,
                program_schedule_end,
                program_trainer_id,
                program_trainer_name,
                program_category_id,
                program_total_slots,
                program_slots_available,
                program_other_trainer,
                program_show_on_index,
                
                -- Enrollment data
                enrollment_status,
                enrollment_applied_at,
                enrollment_completed_at,
                enrollment_attendance,
                enrollment_approval_status,
                enrollment_approved_by,
                enrollment_approved_date,
                enrollment_assessment,
                
                -- Feedback data
                trainer_expertise_rating,
                trainer_communication_rating,
                trainer_methods_rating,
                trainer_requests_rating,
                trainer_questions_rating,
                trainer_instructions_rating,
                trainer_prioritization_rating,
                trainer_fairness_rating,
                program_knowledge_rating,
                program_process_rating,
                program_environment_rating,
                program_algorithms_rating,
                program_preparation_rating,
                system_technology_rating,
                system_workflow_rating,
                system_instructions_rating,
                system_answers_rating,
                system_performance_rating,
                feedback_comments,
                feedback_submitted_at,
                
                archive_trigger,
                archive_source
            )
            SELECT DISTINCT
                e.user_id,
                e.program_id,
                e.id AS enrollment_id,
                f.id AS feedback_id,
                ap.id AS archive_program_id,
                
                -- Program data from the original program
                OLD.name,
                OLD.duration,
                COALESCE(OLD.duration_unit, OLD.durationUnit, 'Days'),
                OLD.scheduleStart,
                OLD.scheduleEnd,
                OLD.trainer_id,
                OLD.trainer,
                OLD.category_id,
                COALESCE(OLD.total_slots, OLD.slotsAvailable),
                OLD.slotsAvailable,
                COALESCE(OLD.other_trainer, '-'),
                COALESCE(OLD.show_on_index, 0),
                
                -- Enrollment data
                COALESCE(e.enrollment_status, 'pending'),
                e.applied_at,
                e.completed_at,
                COALESCE(e.attendance, 0),
                COALESCE(e.approval_status, 'pending'),
                e.approved_by,
                e.approved_date,
                e.assessment,
                
                -- Feedback data
                f.trainer_expertise,
                f.trainer_communication,
                f.trainer_methods,
                f.trainer_requests,
                f.trainer_questions,
                f.trainer_instructions,
                f.trainer_prioritization,
                f.trainer_fairness,
                f.program_knowledge,
                f.program_process,
                f.program_environment,
                f.program_algorithms,
                f.program_preparation,
                f.system_technology,
                f.system_workflow,
                f.system_instructions,
                f.system_answers,
                f.system_performance,
                f.additional_comments,
                f.submitted_at,
                
                'program_ended',
                'direct_from_programs'
            FROM enrollments e
            LEFT JOIN feedback f ON e.user_id = f.user_id AND e.program_id = f.program_id
            LEFT JOIN archive_programs ap ON ap.original_id = OLD.id
            WHERE e.program_id = OLD.id
              AND NOT EXISTS (
                SELECT 1 FROM archived_history ah 
                WHERE ah.enrollment_id = e.id 
                AND ah.original_program_id = OLD.id
              );
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_show_on_index` BEFORE UPDATE ON `programs` FOR EACH ROW BEGIN
    IF NEW.slotsAvailable <= 0 THEN
        SET NEW.show_on_index = 0;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `program_categories`
--

CREATE TABLE `program_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_default` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_categories`
--

INSERT INTO `program_categories` (`id`, `name`, `specialization`, `description`, `created_at`, `is_default`, `status`, `updated_at`) VALUES
(1, 'Food and Beverages', 'Food and Beverages', NULL, '2025-11-23 11:03:30', 0, 'active', '2026-01-25 04:36:41'),
(2, 'Livelihood Programs', 'Livelihood Programs', 'Income-generating and skills development programs', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(3, 'Agricultural Training', 'Agricultural Training', 'Farming, livestock, and agricultural skills', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(5, 'Entrepreneurship', 'Entrepreneurship', 'Business development and entrepreneurship', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(6, 'Handicrafts', 'Handicrafts', 'Traditional and modern handicraft making', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(7, 'Services', 'Services', 'Service-oriented skills training', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(8, 'Educational Programs', 'Educational Programs', 'Academic and educational programs', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(9, 'Community Development', 'Community Development', 'Community-based development programs', '2025-11-23 11:06:02', 1, 'active', '2026-01-25 04:36:41'),
(10, 'Techonology', 'Techonology', 'A category dedicated to innovations, digital tools, modern devices, and advancements that shape the future. Includes topics like software, hardware, AI, gadgets, and emerging tech trends', '2025-12-08 05:03:18', 0, 'active', '2026-01-25 04:36:41'),
(11, 'Cosmetics', 'Cosmetics', NULL, '2025-12-11 01:43:51', 0, 'active', '2026-01-25 04:36:41'),
(12, 'food processing', 'food processing', NULL, '2026-01-24 16:28:59', 0, 'active', '2026-01-25 04:38:03'),
(13, 'Healthcare', 'Healthcare', NULL, '2026-03-09 09:44:18', 0, 'active', '2026-03-09 09:44:18'),
(14, 'Sewing & Creative Skills', 'Sewing & Creative Skills', '.', '2026-04-10 14:12:30', 0, 'active', '2026-04-10 15:13:02'),
(15, 'Home-Based & Small Business Production', 'Home-Based & Small Business Production', '.', '2026-04-10 15:20:26', 0, 'active', '2026-04-10 15:20:26');

--
-- Triggers `program_categories`
--
DELIMITER $$
CREATE TRIGGER `auto_fill_specialization` BEFORE INSERT ON `program_categories` FOR EACH ROW BEGIN
    IF NEW.specialization IS NULL THEN
        SET NEW.specialization = NEW.name;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `program_history`
--

CREATE TABLE `program_history` (
  `id` int(11) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `archived_program_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `program_name_backup` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `program_history`
--

INSERT INTO `program_history` (`id`, `program_id`, `archived_program_id`, `user_id`, `action`, `description`, `program_name_backup`, `created_at`) VALUES
(1, 97, 97, 2, 'archived', 'Program manually archived', NULL, '2026-02-01 06:41:31'),
(2, 95, NULL, 2, 'updated', 'Program details updated', NULL, '2026-02-01 06:50:36'),
(3, 98, NULL, 2, 'restored', 'Program restored from archive', NULL, '2026-02-01 06:50:36'),
(4, 98, NULL, 2, 'visibility_changed', 'Program hidden on index page', NULL, '2026-02-01 06:50:42'),
(5, 98, 98, 2, 'archived', 'Program manually archived', NULL, '2026-02-01 06:50:45'),
(6, 96, NULL, 2, 'visibility_changed', 'Program shown on index page', NULL, '2026-02-01 06:50:57'),
(7, 96, NULL, 2, 'visibility_changed', 'Program hidden on index page', NULL, '2026-02-01 06:51:00'),
(8, 95, 95, 2, 'archived', 'Program manually archived', NULL, '2026-02-01 06:52:05'),
(9, 97, NULL, 2, 'updated', 'Program details updated', NULL, '2026-02-01 06:52:54'),
(10, 99, NULL, 2, 'restored', 'Program restored from archive', NULL, '2026-02-01 06:52:54'),
(11, 99, NULL, 2, 'updated', 'Program details updated', NULL, '2026-02-01 06:53:56'),
(12, 99, NULL, 2, 'visibility_changed', 'Program shown on index page', NULL, '2026-02-01 06:54:00'),
(13, 100, NULL, 2, 'created', 'New program created', NULL, '2026-02-01 06:58:20'),
(14, 100, NULL, 2, 'visibility_changed', 'Program shown on index page', NULL, '2026-02-01 06:58:37'),
(15, 88, NULL, 2, 'updated', 'Program details updated', NULL, '2026-02-01 07:27:29'),
(16, 88, NULL, 2, 'visibility_changed', 'Program shown on index page', NULL, '2026-02-01 07:27:36'),
(17, 88, 88, 2, 'archived', 'Program manually archived', NULL, '2026-02-01 08:11:59'),
(18, 99, NULL, 2, 'updated', 'Program details updated', 'Unknown Program', '2026-02-01 23:41:54'),
(19, 1, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-01 23:41:54'),
(20, 1, 1, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-01 23:42:09'),
(21, 102, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-02 00:02:02'),
(22, 102, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-02 00:02:20'),
(23, 102, 102, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 00:03:14'),
(24, 102, 102, 2, 'permanently_deleted', 'Program permanently deleted from archive', 'ict', '2026-02-02 02:17:50'),
(25, 103, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-02 02:18:11'),
(26, 103, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-02 02:18:16'),
(27, 103, 103, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 02:27:59'),
(28, 103, 103, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 02:28:17'),
(29, 103, 103, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 02:47:58'),
(30, 103, 103, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 02:48:09'),
(31, 103, 103, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 02:48:26'),
(32, 103, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Cookery', '2026-02-02 03:41:40'),
(33, 103, 103, 2, 'permanently_deleted', 'Program permanently deleted from archive', 'Cookery', '2026-02-02 03:41:54'),
(34, 104, 104, 2, 'permanently_deleted', 'Program permanently deleted from archive', 'Cookery', '2026-02-02 03:42:53'),
(35, 105, 105, 2, 'permanently_deleted', 'Program permanently deleted from archive', 'Cookery', '2026-02-02 03:47:25'),
(36, 104, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-02 03:52:28'),
(37, 104, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-02 03:52:37'),
(38, 104, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Cookery', '2026-02-02 06:23:31'),
(39, 104, 104, 2, 'archived', 'Program manually archived', 'Cookery', '2026-02-02 06:23:35'),
(40, 105, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-02 06:23:46'),
(41, 105, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-02 06:28:17'),
(42, 105, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-02 12:28:28'),
(43, 96, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Dressmaking', '2026-02-02 12:42:29'),
(44, 105, 105, 2, 'auto_archived', 'Program auto-archived due to schedule end date', 'Unknown Program', '2026-02-03 12:06:17'),
(45, 101, NULL, 2, 'visibility_changed', 'Program shown on index page', 'ict', '2026-02-03 14:36:58'),
(46, 101, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'ict', '2026-02-03 14:37:21'),
(47, 106, NULL, 2, 'restored', 'Program restored from archive', 'Bookkeeping', '2026-02-03 16:25:22'),
(48, 106, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bookkeeping', '2026-02-03 16:25:29'),
(49, 106, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bookkeeping', '2026-02-04 02:12:58'),
(50, 106, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bookkeeping', '2026-02-04 02:13:47'),
(51, 107, NULL, 2, 'created', 'New program created', 'automotive', '2026-02-04 06:46:38'),
(52, 107, NULL, 2, 'visibility_changed', 'Program shown on index page', 'automotive', '2026-02-04 06:47:04'),
(53, 108, NULL, 2, 'restored', 'Program restored from archive', 'BASIC COMPUTER LITERACY', '2026-02-08 03:58:15'),
(54, 108, NULL, 2, 'visibility_changed', 'Program shown on index page', 'BASIC COMPUTER LITERACY', '2026-02-08 03:58:21'),
(55, 108, 108, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-08', 'BASIC COMPUTER LITERACY', '2026-02-08 06:23:11'),
(56, 109, NULL, 2, 'restored', 'Program restored from archive', 'BASIC COMPUTER LITERACY', '2026-02-08 06:26:15'),
(57, 109, 109, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-08', 'BASIC COMPUTER LITERACY', '2026-02-08 06:26:16'),
(58, 110, NULL, 2, 'restored', 'Program restored from archive', 'BASIC COMPUTER LITERACY', '2026-02-08 06:26:46'),
(59, 110, 110, 0, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-09', 'BASIC COMPUTER LITERACY', '2026-02-09 08:25:18'),
(60, 111, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-10 06:46:29'),
(61, 111, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-10 06:46:36'),
(62, 111, 111, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-11', 'Cookery', '2026-02-11 06:56:45'),
(63, 96, 96, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-15', 'Dressmaking', '2026-02-17 01:01:44'),
(64, 112, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-17 01:08:42'),
(65, 112, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-17 01:08:49'),
(66, 112, 112, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-17', 'Cookery', '2026-02-19 20:52:41'),
(67, 113, NULL, 2, 'restored', 'Program restored from archive', 'Dressmaking', '2026-02-19 20:53:16'),
(68, 113, NULL, 2, 'updated', 'Program details updated', 'Dressmaking', '2026-02-19 20:55:01'),
(69, 113, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Dressmaking', '2026-02-19 20:55:09'),
(70, 113, 113, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-20', 'Dressmaking', '2026-02-21 04:52:27'),
(71, 114, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-21 04:53:12'),
(72, 114, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-21 04:53:15'),
(73, 101, NULL, 2, 'visibility_changed', 'Program shown on index page', 'ict', '2026-02-21 04:53:56'),
(74, 101, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'ict', '2026-02-21 04:54:12'),
(75, 114, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-21 08:29:31'),
(76, 114, NULL, 2, 'updated', 'Program details updated', 'Cookery', '2026-02-21 09:29:06'),
(77, 114, 114, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-21', 'Cookery', '2026-02-21 09:29:06'),
(78, 115, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-21 09:29:37'),
(79, 115, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-21 09:29:41'),
(80, 115, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-21 10:09:21'),
(81, 115, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Cookery', '2026-02-21 10:09:50'),
(82, 115, 115, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-22', 'Cookery', '2026-02-24 05:24:45'),
(83, 116, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-24 05:25:10'),
(84, 116, 116, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-24', 'Cookery', '2026-02-24 05:25:10'),
(85, 117, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-24 05:25:35'),
(86, 117, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-24 05:25:45'),
(87, 117, 117, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-25', 'Cookery', '2026-02-26 01:20:57'),
(88, 101, 101, 0, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-26', 'ict', '2026-02-26 08:14:54'),
(89, 118, NULL, 2, 'created', 'New program created', 'Barista Training', '2026-02-26 08:20:38'),
(90, 118, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Barista Training', '2026-02-26 08:20:43'),
(91, 118, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Barista Training', '2026-02-26 08:20:51'),
(92, 118, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Barista Training', '2026-02-26 08:22:56'),
(93, 100, 100, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-27', 'Candle, Soap, and Perfume Making', '2026-02-27 05:07:48'),
(94, 119, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-27 05:08:45'),
(95, 119, 119, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-27', 'Cookery', '2026-02-27 05:08:45'),
(96, 120, NULL, 2, 'restored', 'Program restored from archive', 'Cookery', '2026-02-27 05:09:02'),
(97, 120, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-27 05:09:05'),
(98, 120, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Cookery', '2026-02-27 05:21:59'),
(99, 107, 107, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-02', 'automotive', '2026-03-02 10:23:32'),
(100, 120, 120, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-02-28', 'Cookery', '2026-03-02 10:23:32'),
(101, 121, NULL, 2, 'created', 'New program created', 'Bookkeeping', '2026-03-03 09:36:20'),
(102, 121, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bookkeeping', '2026-03-03 09:36:28'),
(103, 118, 118, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-09', 'Barista Training', '2026-03-09 09:37:19'),
(104, 121, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bookkeeping', '2026-03-10 02:55:03'),
(105, 121, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bookkeeping', '2026-03-10 02:55:09'),
(106, 121, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bookkeeping', '2026-03-10 02:55:37'),
(107, 121, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bookkeeping', '2026-03-10 02:55:41'),
(108, 122, NULL, 2, 'created', 'New program created', 'ICT NETWORKING', '2026-03-11 05:13:36'),
(109, 122, NULL, 2, 'visibility_changed', 'Program shown on index page', 'ICT NETWORKING', '2026-03-11 05:13:56'),
(110, 122, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'ICT NETWORKING', '2026-03-12 10:35:55'),
(111, 122, NULL, 2, 'visibility_changed', 'Program shown on index page', 'ICT NETWORKING', '2026-03-12 10:35:59'),
(112, 123, NULL, 2, 'created', 'New program created', 'Candle, Soap, and Perfume Making', '2026-03-12 10:45:46'),
(113, 123, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Candle, Soap, and Perfume Making', '2026-03-12 12:36:46'),
(114, 124, NULL, 2, 'restored', 'Program restored from archive', 'Automotive', '2026-03-12 12:40:23'),
(115, 124, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Automotive', '2026-03-12 12:40:29'),
(116, 124, NULL, 2, 'updated', 'Program details updated', 'Automotive', '2026-03-12 12:42:39'),
(117, 124, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Automotive', '2026-03-12 12:43:20'),
(118, 124, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Automotive', '2026-03-12 14:50:00'),
(119, 124, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Automotive', '2026-03-12 15:23:06'),
(120, 124, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Automotive', '2026-03-12 15:24:43'),
(121, 125, NULL, 2, 'created', 'New program created', 'Bread & Pastry', '2026-03-12 16:13:15'),
(122, 125, NULL, 2, 'updated', 'Program details updated', 'Bread & Pastry', '2026-03-12 16:13:33'),
(123, 125, 125, 2, 'archived', 'Program manually archived', 'Bread & Pastry', '2026-03-12 16:13:40'),
(124, 126, NULL, 2, 'restored', 'Program restored from archive', 'Bread & Pastry', '2026-03-12 16:13:57'),
(125, 126, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bread & Pastry', '2026-03-12 16:14:06'),
(126, 126, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bread & Pastry', '2026-03-12 16:14:33'),
(127, 127, NULL, 2, 'restored', 'Program restored from archive', 'Beauty Care Services', '2026-03-13 07:47:08'),
(128, 127, NULL, 170, 'updated', 'Program details updated', 'Beauty Care Services', '2026-03-13 07:48:37'),
(129, 124, 124, 2, 'archived', 'Program manually archived', 'Automotive', '2026-03-14 02:22:08'),
(130, 121, 121, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-15', 'Bookkeeping', '2026-03-15 08:11:28'),
(131, 127, 127, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-15', 'Beauty Care Services', '2026-03-15 08:11:28'),
(132, 128, NULL, 2, 'created', 'New program created', 'ICT Software Developer', '2026-03-16 05:47:59'),
(133, 123, 123, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-21', 'Candle, Soap, and Perfume Making', '2026-03-22 07:44:13'),
(134, 129, NULL, 2, 'restored', 'Program restored from archive', 'Barista Training', '2026-03-22 07:44:52'),
(135, 129, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Barista Training', '2026-03-22 07:47:40'),
(136, 129, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Barista Training', '2026-03-22 07:47:44'),
(137, 129, NULL, 2, 'updated', 'Program details updated', 'Barista Training', '2026-03-22 07:48:29'),
(138, 130, NULL, 2, 'restored', 'Program restored from archive', 'Candle, Soap, and Perfume Making', '2026-03-22 08:35:15'),
(139, 130, NULL, 2, 'updated', 'Program details updated', 'Candle, Soap, and Perfume Making', '2026-03-22 08:37:04'),
(140, 130, 130, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-23', 'Candle, Soap, and Perfume Making', '2026-03-24 05:35:55'),
(141, 122, NULL, 2, 'updated', 'Program details updated', 'ICT NETWORKING', '2026-03-24 05:40:50'),
(142, 122, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'ICT NETWORKING', '2026-03-24 05:41:13'),
(143, 126, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bread & Pastry', '2026-03-24 14:40:50'),
(144, 126, NULL, 2, 'visibility_changed', 'Program shown on index page', 'Bread & Pastry', '2026-03-24 14:40:59'),
(145, 128, NULL, 2, 'updated', 'Program details updated', 'ICT Software Developer', '2026-03-25 00:04:42'),
(146, 122, NULL, 2, 'updated', 'Program details updated', 'ICT NETWORKING', '2026-03-25 00:06:10'),
(147, 122, NULL, 2, 'visibility_changed', 'Program shown on index page', 'ICT NETWORKING', '2026-03-25 00:07:03'),
(148, 129, 129, 2, 'archived', 'Program manually archived', 'Barista Training', '2026-03-25 00:07:10'),
(149, 128, 128, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-03-25', 'ICT Software Developer', '2026-04-02 10:20:32'),
(150, 131, NULL, 2, 'restored', 'Program restored from archive', 'Bookkeeping', '2026-04-05 14:55:56'),
(151, 122, 122, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-04-06', 'ICT NETWORKING', '2026-04-08 10:59:22'),
(152, 126, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bread & Pastry', '2026-04-08 10:59:34'),
(153, 131, NULL, 2, 'visibility_changed', 'Program hidden on index page', 'Bookkeeping', '2026-04-08 10:59:35'),
(154, 132, NULL, 2, 'restored', 'Program restored from archive', 'ICT NETWORKING', '2026-04-09 03:19:08'),
(155, 132, 132, 2, 'archived', 'Program manually archived', 'ICT NETWORKING', '2026-04-09 11:21:20'),
(156, 133, NULL, 2, 'restored', 'Program restored from archive', 'Beauty Care Services', '2026-04-09 15:12:18'),
(157, 133, NULL, 2, 'updated', 'Program details updated', 'Beauty Care Services', '2026-04-09 15:13:29'),
(158, 134, NULL, 2, 'restored', 'Program restored from archive', 'Barista Training', '2026-04-09 15:56:40'),
(159, 138, 138, 2, 'permanently_deleted', 'Program permanently deleted from archive', 'ICT NETWORKING', '2026-04-10 14:43:28'),
(160, 135, NULL, 2, 'created', 'New program created', 'Tailoring', '2026-04-10 15:22:05'),
(161, 136, NULL, 2, 'created', 'New program created', 'Perfume Making', '2026-04-10 15:23:16'),
(162, 126, 126, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-04-10', 'Bread & Pastry', '2026-04-12 07:35:46'),
(163, 131, 131, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-04-10', 'Bookkeeping', '2026-04-12 07:35:46'),
(164, 133, 133, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-04-11', 'Beauty Care Services', '2026-04-12 07:35:46'),
(165, 134, 134, 2, 'auto_archived', 'Program auto-archived due to schedule end date: 2026-04-11', 'Barista Training', '2026-04-12 07:35:46');

-- --------------------------------------------------------

--
-- Table structure for table `program_oral_questions`
--

CREATE TABLE `program_oral_questions` (
  `id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `max_score` int(11) DEFAULT 25,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `program_oral_questions`
--

INSERT INTO `program_oral_questions` (`id`, `program_id`, `question`, `max_score`, `order_index`, `created_at`) VALUES
(1, 118, 'New Question 1', 25, 0, '2026-03-03 21:48:09'),
(2, 118, 'New Question 2', 25, 1, '2026-03-03 21:48:09'),
(3, 118, 'New Question 3', 25, 2, '2026-03-03 21:48:09'),
(4, 118, 'New Question 4', 25, 3, '2026-03-03 21:48:09'),
(5, 118, 'New Question 5', 25, 4, '2026-03-03 21:48:09'),
(6, 121, 'New Question 1', 25, 0, '2026-03-03 23:31:04'),
(7, 121, 'New Question 2', 25, 1, '2026-03-03 23:31:04'),
(8, 121, 'New Question 3', 25, 2, '2026-03-03 23:31:04'),
(9, 121, 'New Question 4', 25, 3, '2026-03-03 23:31:04'),
(10, 124, 'Why is safety important in an automotive workshop?', 25, 0, '2026-03-13 03:51:51'),
(11, 124, 'Can you name at least three basic hand tools used in automotive work?', 25, 1, '2026-03-13 03:51:51'),
(12, 124, 'What is the function of the battery in a car?', 15, 2, '2026-03-13 03:51:51'),
(13, 124, 'What should you do if you do not understand a task?', 35, 3, '2026-03-13 03:51:51'),
(17, 127, 'What are the key steps involved in performing a facial?', 30, 0, '2026-03-14 09:50:51'),
(18, 127, 'How do you determine the right foundation shade for a client?', 20, 1, '2026-03-14 09:50:51'),
(19, 127, 'Can you explain the differences between various hair cutting techniques?', 50, 2, '2026-03-14 09:50:51'),
(20, 123, 'New Question 1', 25, 0, '2026-03-19 02:57:38'),
(21, 123, 'New Question 2', 25, 1, '2026-03-19 02:57:38');

-- --------------------------------------------------------

--
-- Table structure for table `program_practical_skills`
--

CREATE TABLE `program_practical_skills` (
  `id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `skill_name` varchar(255) NOT NULL,
  `max_score` int(11) DEFAULT 20,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `program_practical_skills`
--

INSERT INTO `program_practical_skills` (`id`, `program_id`, `skill_name`, `max_score`, `order_index`, `created_at`) VALUES
(1, 118, 'New Skill 1', 20, 0, '2026-03-03 21:46:20'),
(2, 118, 'New Skill 2', 20, 1, '2026-03-03 21:46:20'),
(3, 118, 'New Skill 3', 20, 2, '2026-03-03 21:46:20'),
(4, 118, 'New Skill 4', 20, 3, '2026-03-03 21:46:20'),
(5, 118, 'New Skill 5', 20, 4, '2026-03-03 21:46:20'),
(9, 124, 'Basic Automotive Safety', 25, 0, '2026-03-13 03:44:36'),
(10, 124, 'Proper use of personal protective equipment (PPE)', 20, 1, '2026-03-13 03:44:36'),
(11, 124, 'Basic vehicle parts identification', 15, 2, '2026-03-13 03:44:36'),
(12, 124, 'Checking engine oil level', 10, 3, '2026-03-13 03:44:36'),
(13, 124, 'Tool cleaning and storage', 30, 4, '2026-03-13 03:44:36'),
(29, 127, 'Skincare Techniques', 20, 0, '2026-03-14 09:11:24'),
(30, 127, 'Makeup Application', 20, 1, '2026-03-14 09:11:24'),
(31, 127, 'Hair Care and Styling', 10, 2, '2026-03-14 09:11:24'),
(32, 127, 'Nail Care', 20, 3, '2026-03-14 09:11:24'),
(33, 127, 'Body Treatments', 20, 4, '2026-03-14 09:11:24'),
(39, 123, 'New Skill 1', 20, 0, '2026-03-21 14:21:46'),
(40, 123, 'New Skill 2', 20, 1, '2026-03-21 14:21:46'),
(43, 130, 'New Skill', 20, 0, '2026-03-23 13:15:10'),
(44, 130, 'New Skill2', 20, 1, '2026-03-23 13:15:10'),
(45, 128, 'Frontend', 20, 0, '2026-03-25 02:29:41'),
(46, 128, 'Backend', 20, 1, '2026-03-25 02:29:41'),
(47, 128, 'Database', 20, 2, '2026-03-25 02:29:41'),
(57, 134, 'Basic Knife Skills', 20, 0, '2026-04-09 19:00:08'),
(58, 134, 'New Skill', 20, 1, '2026-04-09 19:00:08'),
(59, 134, 'New Skill', 20, 2, '2026-04-09 19:00:08'),
(60, 134, 'New Skill', 20, 3, '2026-04-09 19:00:08'),
(61, 133, 'Basic Knife Skills', 20, 0, '2026-04-09 22:46:01'),
(62, 133, 'New Skill', 20, 1, '2026-04-09 22:46:01'),
(63, 133, 'New Skill', 20, 2, '2026-04-09 22:46:01'),
(64, 133, 'New Skill', 20, 3, '2026-04-09 22:46:01');

-- --------------------------------------------------------

--
-- Table structure for table `reset_history`
--

CREATE TABLE `reset_history` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `reset_type` enum('practical','oral','both') NOT NULL,
  `reset_version` int(11) NOT NULL,
  `reset_reason` text DEFAULT NULL,
  `reset_by` varchar(255) DEFAULT NULL,
  `reset_at` timestamp NULL DEFAULT current_timestamp(),
  `previous_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `reset_history`
--

INSERT INTO `reset_history` (`id`, `enrollment_id`, `reset_type`, `reset_version`, `reset_reason`, `reset_by`, `reset_at`, `previous_count`) VALUES
(1, 162, 'oral', 1, '', 'lucia garcia', '2026-03-19 05:14:41', 8);

-- --------------------------------------------------------

--
-- Table structure for table `revision_requests`
--

CREATE TABLE `revision_requests` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `edit_token` varchar(100) NOT NULL,
  `token_expiry` datetime NOT NULL,
  `status` enum('pending','completed','expired') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'Full system access and control', '2025-12-10 03:51:44', '2025-12-10 03:51:44'),
(2, 'trainer', 'Training and content management access', '2025-12-10 03:51:44', '2025-12-10 03:51:44'),
(3, 'trainee', 'Limited access for training participants', '2025-12-10 03:51:44', '2025-12-10 03:51:44');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`) VALUES
(1, 1, 8, '2025-12-10 03:51:44'),
(2, 1, 6, '2025-12-10 03:51:44'),
(3, 1, 5, '2025-12-10 03:51:44'),
(4, 1, 1, '2025-12-10 03:51:44'),
(5, 1, 3, '2025-12-10 03:51:44'),
(6, 1, 9, '2025-12-10 03:51:44'),
(7, 1, 2, '2025-12-10 03:51:44'),
(8, 1, 7, '2025-12-10 03:51:44'),
(9, 1, 4, '2025-12-10 03:51:44'),
(10, 2, 3, '2025-12-10 03:51:44'),
(11, 2, 4, '2025-12-10 03:51:44'),
(12, 2, 6, '2025-12-10 03:51:44'),
(13, 3, 5, '2025-12-10 03:51:44');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `status` enum('active','logged_out','expired') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `login_time`, `logout_time`, `ip_address`, `user_agent`, `status`, `created_at`) VALUES
(3, 92, '2025-11-25 20:16:10', '2025-11-25 21:23:34', '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-26 01:16:10'),
(6, 92, '2025-11-26 00:18:37', '2025-11-26 01:46:51', '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-26 05:18:37'),
(7, 92, '2025-11-26 02:20:21', NULL, '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-26 07:20:21'),
(9, 92, '2025-11-26 02:54:19', NULL, '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-26 07:54:19'),
(10, 92, '2025-11-26 19:01:53', '2025-11-26 19:03:59', '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-27 00:01:53'),
(11, 92, '2025-11-26 19:11:23', NULL, '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-27 00:11:23'),
(12, 92, '2025-11-27 19:41:56', NULL, '121.58.231.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-28 00:41:56'),
(13, 92, '2025-11-28 20:58:42', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 01:58:42'),
(14, 92, '2025-11-28 21:23:26', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 02:23:26'),
(15, 92, '2025-11-28 21:52:04', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 02:52:04'),
(16, 92, '2025-11-28 22:04:51', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:04:51'),
(17, 92, '2025-11-28 22:09:43', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:09:43'),
(18, 92, '2025-11-28 22:19:15', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:19:15'),
(19, 92, '2025-11-28 22:32:22', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:32:22'),
(20, 92, '2025-11-28 22:32:41', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:32:41'),
(21, 92, '2025-11-28 22:32:54', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:32:54'),
(22, 92, '2025-11-28 22:46:36', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 03:46:36'),
(23, 92, '2025-11-29 00:26:13', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 05:26:13'),
(24, 92, '2025-11-29 00:26:29', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 05:26:29'),
(25, 92, '2025-11-29 00:43:41', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 05:43:41'),
(26, 92, '2025-11-29 01:04:12', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-11-29 06:04:12'),
(27, 92, '2025-11-29 02:51:08', '2025-11-29 04:01:15', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 07:51:08'),
(28, 92, '2025-11-29 04:01:22', '2025-11-29 04:30:21', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 09:01:22'),
(29, 92, '2025-11-29 04:44:51', '2025-11-29 04:50:53', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 09:44:51'),
(30, 92, '2025-11-29 05:02:35', '2025-11-29 05:02:39', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:02:35'),
(31, 92, '2025-11-29 05:10:22', '2025-11-29 05:10:26', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:10:22'),
(32, 92, '2025-11-29 05:15:47', '2025-11-29 05:15:51', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:15:47'),
(33, 92, '2025-11-29 05:29:27', '2025-11-29 05:29:31', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:29:27'),
(34, 92, '2025-11-29 05:38:06', '2025-11-29 05:38:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:38:06'),
(35, 92, '2025-11-29 05:44:22', '2025-11-29 05:44:26', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 10:44:22'),
(36, 92, '2025-11-29 15:41:33', '2025-11-29 15:41:46', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 20:41:33'),
(37, 92, '2025-11-29 16:03:28', '2025-11-29 16:03:33', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 21:03:28'),
(38, 92, '2025-11-29 16:10:50', '2025-11-29 16:10:57', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-11-29 21:10:50'),
(42, 92, '2025-12-05 01:53:24', '2025-12-05 01:55:11', '122.53.131.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-05 06:53:24'),
(43, 96, '2025-12-05 01:58:59', NULL, '122.53.131.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-12-05 06:58:59'),
(44, 2, '2025-12-06 15:44:31', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 20:44:31'),
(45, 2, '2025-12-06 15:44:31', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '', '2025-12-06 20:44:31'),
(46, 2, '2025-12-06 15:45:38', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 20:45:38'),
(47, 2, '2025-12-06 15:45:38', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '', '2025-12-06 20:45:38'),
(48, 2, '2025-12-06 15:46:57', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 20:46:57'),
(49, 2, '2025-12-06 15:46:57', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '', '2025-12-06 20:46:57'),
(50, 2, '2025-12-06 15:47:20', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 20:47:20'),
(51, 2, '2025-12-06 15:47:20', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '', '2025-12-06 20:47:20'),
(52, 2, '2025-12-06 16:27:59', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:27:59'),
(53, 2, '2025-12-06 16:28:28', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:28:28'),
(54, 2, '2025-12-06 16:29:12', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:29:12'),
(55, 2, '2025-12-06 16:32:41', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:32:41'),
(56, 2, '2025-12-06 16:41:25', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:41:25'),
(57, 2, '2025-12-06 16:41:44', '2025-12-06 16:48:10', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:41:44'),
(58, 2, '2025-12-06 16:48:10', '2025-12-06 16:48:41', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:48:10'),
(59, 2, '2025-12-06 16:48:41', '2025-12-06 16:49:11', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:48:41'),
(60, 2, '2025-12-06 16:49:11', '2025-12-06 16:49:38', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:49:11'),
(61, 2, '2025-12-06 16:49:38', '2025-12-06 16:50:51', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:49:38'),
(62, 92, '2025-12-06 16:50:11', '2025-12-06 16:50:31', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-06 21:50:11'),
(63, 2, '2025-12-06 16:50:51', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-12-06 21:50:51'),
(64, 2, '2025-12-06 16:56:25', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-12-06 21:56:25'),
(65, 2, '2025-12-06 17:09:11', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-12-06 22:09:11'),
(66, 92, '2025-12-06 19:48:58', '2025-12-06 20:43:35', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 00:48:58'),
(67, 92, '2025-12-06 20:44:37', '2025-12-06 20:46:34', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 01:44:37'),
(68, 92, '2025-12-06 20:46:40', '2025-12-06 21:18:33', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 01:46:40'),
(69, 92, '2025-12-06 21:18:39', '2025-12-06 21:18:50', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 02:18:39'),
(70, 92, '2025-12-06 21:19:04', '2025-12-06 21:39:01', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 02:19:04'),
(71, 92, '2025-12-06 21:39:11', '2025-12-06 22:03:56', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'logged_out', '2025-12-07 02:39:11'),
(72, 92, '2025-12-06 22:11:19', NULL, '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', 'active', '2025-12-07 03:11:19'),
(73, 92, '2025-12-08 03:52:25', '2025-12-08 04:15:20', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-08 08:52:25'),
(75, 92, '2025-12-08 07:32:30', '2025-12-08 07:33:19', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2025-12-08 12:32:30'),
(76, 92, '2025-12-08 07:35:18', '2025-12-08 07:57:27', '136.158.62.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2025-12-08 12:35:18'),
(77, 92, '2025-12-08 07:40:34', '2025-12-08 07:43:01', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-08 12:40:34'),
(78, 92, '2025-12-08 07:44:03', '2025-12-08 07:52:40', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-08 12:44:03'),
(82, 92, '2025-12-11 22:29:14', NULL, '2405:8d40:4003:c6dc:4d0:cc77:4e04:2dab', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'active', '2025-12-12 03:29:14'),
(84, 92, '2025-12-11 23:59:28', '2025-12-12 00:03:44', '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-12 04:59:28'),
(85, 92, '2025-12-12 00:11:48', '2025-12-12 00:12:47', '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-12 05:11:48'),
(86, 92, '2025-12-12 00:13:58', '2025-12-12 00:14:31', '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-12 05:13:58'),
(87, 92, '2025-12-12 00:15:56', '2025-12-12 00:16:48', '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-12 05:15:56'),
(91, 92, '2025-12-12 01:16:22', '2025-12-12 01:18:06', '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'logged_out', '2025-12-12 06:16:22'),
(93, 92, '2025-12-12 01:59:58', NULL, '175.176.24.98', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'active', '2025-12-12 06:59:58'),
(94, 92, '2026-01-03 11:55:15', NULL, '2001:fd8:ba04:9b00:e188:7992:cb24:4255', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'active', '2026-01-03 16:55:15'),
(95, 92, '2026-01-06 08:38:09', '2026-01-06 08:38:22', '2001:fd8:ba04:9b00:8179:f1f0:d8c8:7314', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2026-01-06 13:38:09'),
(96, 92, '2026-01-09 21:51:59', '2026-01-09 21:52:47', '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-10 02:51:59'),
(97, 92, '2026-01-09 21:56:00', NULL, '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-10 02:56:00'),
(98, 92, '2026-01-09 22:12:43', '2026-01-09 22:20:01', '103.187.29.235', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-10 03:12:43'),
(99, 92, '2026-01-10 23:37:37', NULL, '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-11 04:37:37'),
(100, 92, '2026-01-11 04:20:48', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-11 09:20:48'),
(101, 92, '2026-01-11 05:21:21', '2026-01-11 05:29:02', '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-11 10:21:21'),
(102, 92, '2026-01-11 05:31:07', '2026-01-11 07:09:11', '103.187.29.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-11 10:31:07'),
(103, 92, '2026-01-11 20:49:48', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-12 01:49:48'),
(104, 92, '2026-01-11 22:40:43', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-12 03:40:43'),
(105, 92, '2026-01-12 05:42:24', '2026-01-12 05:43:14', '2001:fd8:ba04:9b00:43e:87b1:1525:1d45', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2026-01-12 10:42:24'),
(106, 92, '2026-01-12 10:31:09', '2026-01-12 10:45:55', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-12 15:31:09'),
(107, 92, '2026-01-12 10:46:59', NULL, '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-12 15:46:59'),
(108, 92, '2026-01-13 00:06:43', '2026-01-13 00:09:56', '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-13 05:06:43'),
(109, 92, '2026-01-13 00:46:39', '2026-01-13 00:48:16', '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-13 05:46:39'),
(110, 92, '2026-01-13 00:50:22', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-13 05:50:22'),
(111, 92, '2026-01-14 06:18:15', NULL, '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-14 11:18:15'),
(112, 92, '2026-01-14 07:28:54', '2026-01-14 08:03:50', '138.84.79.32', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2026-01-14 12:28:54'),
(113, 92, '2026-01-14 08:45:28', '2026-01-14 08:48:38', '2001:fd8:ba04:9b00:b42b:af7:dd19:b1c', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2026-01-14 13:45:28'),
(115, 92, '2026-01-14 20:58:59', '2026-01-14 20:59:29', '2001:fd8:ba04:9b00:b99c:33e8:4af:b30c', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'logged_out', '2026-01-15 01:58:59'),
(118, 92, '2026-01-16 23:53:41', '2026-01-16 23:57:36', '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-17 04:53:41'),
(119, 92, '2026-01-16 23:58:56', '2026-01-16 23:59:52', '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-17 04:58:56'),
(120, 92, '2026-01-17 00:05:14', '2026-01-17 00:05:51', '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-17 05:05:14'),
(121, 92, '2026-01-17 00:07:44', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-17 05:07:44'),
(122, 92, '2026-01-17 01:32:14', NULL, '103.187.29.235', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-17 06:32:14'),
(124, 92, '2026-01-17 06:37:28', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-17 11:37:28'),
(125, 92, '2026-01-17 08:14:21', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-17 13:14:21'),
(126, 92, '2026-01-17 09:30:37', NULL, '103.187.29.232', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36', 'active', '2026-01-17 14:30:37'),
(128, 92, '2026-01-17 23:30:17', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-18 04:30:17'),
(129, 92, '2026-01-17 23:58:47', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-18 04:58:47'),
(136, 92, '2026-01-19 00:27:01', NULL, '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 05:27:01'),
(137, 92, '2026-01-19 00:57:16', NULL, '103.187.29.233', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 05:57:16'),
(138, 92, '2026-01-19 01:22:49', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 06:22:49'),
(139, 92, '2026-01-19 04:43:42', NULL, '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 09:43:42'),
(145, 92, '2026-01-19 09:03:00', '2026-01-19 09:06:09', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-19 14:03:00'),
(146, 92, '2026-01-19 09:07:55', NULL, '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 14:07:55'),
(147, 92, '2026-01-19 10:14:15', '2026-01-19 10:15:03', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-19 15:14:15'),
(148, 92, '2026-01-19 10:15:26', NULL, '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-19 15:15:26'),
(154, 92, '2026-01-21 04:40:41', NULL, '122.52.234.124', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-21 09:40:41'),
(155, 92, '2026-01-21 04:54:10', '2026-01-21 04:54:42', '122.52.234.124', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-21 09:54:10'),
(159, 92, '2026-01-21 22:26:59', NULL, '103.187.29.238', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-22 03:26:59'),
(164, 2, '2026-01-23 19:13:26', '2026-01-23 19:17:14', '2001:fd8:ba08:9400:f1a8:732d:b595:b165', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'logged_out', '2026-01-24 00:13:26'),
(169, 92, '2026-01-23 22:29:12', NULL, '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-24 03:29:12'),
(170, 92, '2026-01-24 00:46:59', '2026-01-24 01:59:56', '122.3.107.187', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-24 05:46:59'),
(172, 92, '2026-01-24 04:25:21', '2026-01-24 04:29:45', '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-24 09:25:21'),
(173, 92, '2026-01-24 04:25:40', '2026-01-24 04:27:06', '2001:fd8:ba08:9400:ad40:90ce:af6b:f56a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'logged_out', '2026-01-24 09:25:40'),
(174, 92, '2026-01-24 04:28:52', '2026-01-24 04:29:43', '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-24 09:28:52'),
(175, 96, '2026-01-24 04:49:54', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-24 09:49:54'),
(176, 92, '2026-01-24 04:50:28', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-24 09:50:28'),
(177, 92, '2026-01-24 04:51:10', '2026-01-24 04:53:23', '2001:fd8:ba08:9400:7c89:bbaa:5642:d1d8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'logged_out', '2026-01-24 09:51:10'),
(179, 92, '2026-01-24 05:02:14', NULL, '2001:fd8:ba08:9400:894:d43:64f0:3807', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'active', '2026-01-24 10:02:14'),
(180, 92, '2026-01-24 05:04:02', '2026-01-24 05:24:11', '2001:fd8:ba08:9400:894:d43:64f0:3807', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'logged_out', '2026-01-24 10:04:02'),
(181, 92, '2026-01-24 08:45:17', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-24 13:45:17'),
(182, 92, '2026-01-24 09:19:39', NULL, '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-24 14:19:39'),
(184, 92, '2026-01-25 00:01:19', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-25 05:01:19'),
(185, 92, '2026-01-25 02:19:17', '2026-01-25 03:53:03', '124.105.246.30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-25 07:19:17'),
(186, 92, '2026-01-25 05:11:26', '2026-01-25 05:15:47', '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-25 10:11:26'),
(187, 92, '2026-01-25 05:36:18', NULL, '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-25 10:36:18'),
(190, 92, '2026-01-25 09:13:06', '2026-01-25 09:18:33', '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-25 14:13:06'),
(191, 92, '2026-01-25 09:44:36', NULL, '122.52.101.127', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-25 14:44:36'),
(196, 92, '2026-01-26 08:24:11', '2026-01-26 08:26:26', '103.187.29.232', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 'logged_out', '2026-01-26 13:24:11'),
(197, 92, '2026-01-26 08:30:31', '2026-01-26 08:33:05', '103.187.29.232', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 'logged_out', '2026-01-26 13:30:31'),
(198, 92, '2026-01-26 09:01:54', '2026-01-26 09:36:01', '122.54.119.179', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-26 14:01:54'),
(199, 92, '2026-01-26 10:21:08', '2026-01-26 10:21:48', '124.105.201.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-26 15:21:08'),
(203, 92, '2026-01-26 19:55:12', '2026-01-26 21:33:47', '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'logged_out', '2026-01-27 00:55:12'),
(207, 147, '2026-01-26 20:24:45', '2026-01-26 20:25:10', '2001:fd8:ba08:9400:dcc1:4877:d61d:f83e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 'logged_out', '2026-01-27 01:24:45'),
(220, 92, '2026-01-26 21:36:20', NULL, '103.187.29.232', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'active', '2026-01-27 02:36:20');

-- --------------------------------------------------------

--
-- Table structure for table `trainees`
--

CREATE TABLE `trainees` (
  `id` int(11) NOT NULL,
  `trainee_id` varchar(50) GENERATED ALWAYS AS (concat('LEMS-30135-',coalesce(`user_id`,''))) STORED,
  `user_id` int(11) DEFAULT NULL,
  `fullname` varchar(255) NOT NULL,
  `address` text NOT NULL,
  `gender` varchar(50) NOT NULL,
  `civil_status` varchar(50) NOT NULL,
  `birthday` date NOT NULL,
  `age` int(11) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `employment_status` varchar(50) NOT NULL,
  `education` varchar(100) NOT NULL,
  `failure_notes_copy` text DEFAULT NULL,
  `trainings_attended` text DEFAULT NULL,
  `toolkit_received` text DEFAULT NULL,
  `valid_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`valid_id`)),
  `valid_id_files` text DEFAULT NULL,
  `voters_certificate` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`voters_certificate`)),
  `detected_id_types` text DEFAULT '[]',
  `voters_cert_files` text DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(100) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `lastname` varchar(100) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `middleinitial` varchar(5) NOT NULL DEFAULT '1',
  `house_street` varchar(255) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `municipality` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `gender_specify` varchar(100) DEFAULT '1',
  `education_specify` varchar(100) DEFAULT '1',
  `role` enum('admin','trainer','trainee') NOT NULL DEFAULT 'trainee',
  `valid_id_links` text DEFAULT NULL,
  `voters_cert_links` text DEFAULT NULL,
  `special_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`special_categories`)),
  `special_categories_specify` varchar(100) DEFAULT NULL,
  `applicant_type` varchar(50) NOT NULL,
  `nc_holder` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trainees`
--

INSERT INTO `trainees` (`id`, `user_id`, `fullname`, `address`, `gender`, `civil_status`, `birthday`, `age`, `contact_number`, `employment_status`, `education`, `failure_notes_copy`, `trainings_attended`, `toolkit_received`, `valid_id`, `valid_id_files`, `voters_certificate`, `detected_id_types`, `voters_cert_files`, `email`, `password`, `email_verified`, `verification_token`, `token_expiry`, `created_at`, `lastname`, `firstname`, `middleinitial`, `house_street`, `barangay`, `municipality`, `city`, `gender_specify`, `education_specify`, `role`, `valid_id_links`, `voters_cert_links`, `special_categories`, `special_categories_specify`, `applicant_type`, `nc_holder`) VALUES
(8, 92, 'Alexis Mae F. Morcillo', '123 sta maria, bulacan, sta maria, bulacan', 'Female', 'Married', '2003-11-30', 22, '09574627476', 'Employed', 'Post Graduate', '=== [January 19, 2026] ===\nfailed to finish task\n\n=== [January 19, 2026] ===\nfailed to finish task, re take after 2 hours\n\n=== [January 27, 2026] ===\noiueygahsjnjsdbjzn\n\n=== [January 27, 2026] ===\nhdujjgjakgjkhfek\n\n=== [January 27, 2026] ===\niudfghkjfjghk\n\n=== [January 27, 2026] ===\nytrsdtfghgrfertghhtrth\n\n=== [January 29, 2026] ===\nhas,djghhghhdfsz\n\n=== [DROPOUT - January 30, 2026] ===\nReason: hcvbbfgvcvsdvcbvn\nMarked by: Sam', 'na', 'na', '[\"VALIDID_1764119747_692654c3bf478_0.jpg\"]', NULL, '[\"RESIDENCY_1764119747_692654c3bf546_0.jpg\"]', '[]', NULL, 'lexiambay@gmail.com', '$2y$10$MyepPInehlCIXZod4TbTf.YfU8F5MukpSn1gn3XXzNfW8fIxgLG96', 1, NULL, NULL, '2025-11-26 01:15:47', 'Morcillo', 'Alexis Mae', 'F', '123 sta maria', 'bulacan', 'sta maria', 'bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '', 'yes'),
(9, 99, 'Herm, Rom B.', '1234 sampaguita., Bulac, Santa maria, Bulacan', 'Female', 'Single', '2003-12-10', 21, '09123456789', 'Unemployed', 'College Level / Graduate', NULL, 'N/A', 'N/A', '[\"VALIDID_1765195860_6936c054429d9_0.pdf\"]', NULL, '[\"RESIDENCY_1765195860_6936c05442b5b_0.pdf\"]', '[]', NULL, 'qweasnder.1234@gmail.com', '$2y$10$Yd9m3t7oGuybQqRQ4R4iaeHZ4n.v7Eab0o7igC0MsEDJXOzsaIbdC', 1, NULL, NULL, '2025-12-08 12:11:00', 'Herm', 'Rom', 'B', '1234 sampaguita.', 'Bulac', 'Santa maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '', 'yes'),
(33, 151, 'sy, hanna m.', '1145, Bulac, Sta. Maria, Bulacan', 'Female', 'Married', '2003-06-11', 22, '09973267461', 'Unemployed', 'College Level / Graduate', NULL, 'NA', 'NA', '[\"valid_id_151_1770894104.jpg\"]', NULL, '[\"voters_certificate_151_1770889730.jpg\"]', '[]', NULL, 'wonderingjoy2@gmail.com', '$2y$10$u436ay0Bx.xC.PZpiCA/QOnHFYJnUpF44uIZxwGEX0o/MLFkGBRo2', 1, NULL, NULL, '2026-02-02 13:20:01', 'sy', 'hanna', 'm', '1145', 'Bulac', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, '[]', '', '', 'yes'),
(36, 154, 'Fulgencio, Pam NA.', '123, Parada, Sta. Maria, Bulacan', 'Female', 'Single', '1994-07-23', 31, '09154567897', 'Unemployed', 'College Level / Graduate', NULL, 'N/A', 'N/A', '[\"SCANNED_ID_1770183124_0.jpg\"]', NULL, '[\"SCANNED_CERT_1770183124_0.jpg\"]', '[]', NULL, 'AIVEEFULGENCIO26@GMAIL.COM', '$2y$10$1ANxl6C5O0YDIKYezHcHk.DVvn5i8XaF97IPUuvFae.6Zg7yviR26', 1, NULL, NULL, '2026-02-04 05:32:04', 'Fulgencio', 'Pam', 'NA', '123', 'Parada', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, '[]', '', '', 'yes'),
(37, 155, 'victoria, Pau A.', '143 Pagibig St, Bagbaguin, Sta. Maria, Bulacan', 'Male', 'Married', '2005-02-04', 21, '09983376337', 'Employed', 'Others', NULL, 'NA', 'NA', '[\"SCANNED_ID_1770183235_0.jpg\"]', NULL, '[\"SCANNED_CERT_1770183235_0.jpg\"]', '[]', NULL, 'paulo.vicroria@bpc.edu.ph', '$2y$10$JEJzhvCyMn5tR3lLMuLym.60r6cXhvLLf8r6bkD45eXc.JemoTz0.', 1, NULL, NULL, '2026-02-04 05:33:55', 'victoria', 'Pau', 'A', '143 Pagibig St', 'Bagbaguin', 'Sta. Maria', 'Bulacan', '', 'Kinder', 'trainee', NULL, NULL, '[]', '', '', 'yes'),
(40, 161, 'Kier Hans', '123 nducsdvc, Poblacion, Sta. Maria, Bulacan', 'Female', 'Married', '2003-11-22', 22, '09541357785', 'Employed', '', NULL, 'NA', 'NA', '[\"valid_id_161_1773564351.jpg\"]', NULL, '[\"voters_certificate_161_1774352721.jpg\"]', '[]', NULL, 'wonderingjoy3@gmail.com', '$2y$10$dbAQJrOxZ32Gsdnhyefb8.daVZXj/baPk.yz3uaGnow3j6xoo8JfS', 1, NULL, NULL, '2026-02-27 10:59:26', 'Hans', 'Kier', '', '123 nducsdvc', 'Poblacion', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"4Ps Beneficiary\"]', ''),
(44, 165, 'Geronimo-Cruz, Sofia', '1452, Silicawan, Sta. Maria, Bulacan', 'Female', 'Married', '2002-12-06', 23, '09584625551', 'Self-employed', 'College Level / Graduate', NULL, 'NA', 'NA', '[\"valid_id_165_1773327744.jpg\"]', NULL, '[\"SCANNED_CERT_1772263833_0.jpg\"]', '[]', NULL, 'wonderingjoy4@gmail.com', '$2y$10$vhor22azG6WH9Ahd3iPRW.RhFjITfeuo.JsI6n/Dgb1iMKE.8btqO', 1, NULL, NULL, '2026-02-28 07:30:33', 'Geronimo-Cruz', 'Sofia', '', '1452', 'Silicawan', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"PWD (Person With Disability)\",\"4Ps Beneficiary\"]', '[\"NC1\",\"NC2\"]'),
(45, 170, 'Ambay, Alex', '88 Luna Street, Mag-asawang Sapa, Sta. Maria, Bulacan', 'Female', 'Married', '2003-11-30', 22, '09081828388', 'Unemployed', 'High School Level / Graduate', NULL, 'NA', 'NA', '[\"SCANNED_ID_1772534920_0.jpg\"]', NULL, '[\"SCANNED_CERT_1772534920_0.jpg\"]', '[]', NULL, 'leximaeambay@gmail.com', '$2y$10$Qhw6KgS0/3WohQPZQYELzOjH0DzAdM9ttoMBGOf1cTlsRQJMsyvMS', 1, NULL, NULL, '2026-03-03 10:48:40', 'Ambay', 'Alex', '', '88 Luna Street', 'Mag-asawang Sapa', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"None\"]', '[\"None\"]'),
(46, 150, 'SILLANO, JOVELYN C.', '1145 Purok 1, Guyong, Sta. Maria, Bulacan', 'Female', 'Married', '2003-06-11', 22, '09973267461', 'Employed', 'College Level / Graduate', NULL, 'NA', 'NA', '[\"SCANNED_ID_1769934024_0.jpg\"]', NULL, '[\"SCANNED_CERT_1769934024_0.jpg\"]', '[]', NULL, 'vell88091@gmail.com', '$2y$10$kDq4VRDYpMf/6x9mJBN1l./lv4.qS/Lo9lA.mR7MxrWQHIZljeNoK', 1, NULL, NULL, '2026-02-01 08:20:24', 'SILLANO', 'JOVELYN', 'C', '1145 Purok 1', 'Guyong', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, '[\"4Ps Beneficiary\"]', '', '', 'yes'),
(47, 156, 'Gatchalian, Migs G.', '1234, Poblacion, Sta. Maria, Bulacan', 'Male', 'Single', '1956-02-11', 69, '09560511944', 'Employed', 'College Level / Graduate', NULL, 'NA', 'NA', '[\"SCANNED_ID_1770184225_0.jpg\"]', NULL, '[\"SCANNED_CERT_1770184225_0.jpg\"]', '[]', NULL, 'miggatchalian@gmail.com', '$2y$10$bh2d2gJIUMf.Q2m6Mwqswue8vd88rpdYb0orI8yP1z791GXuAOKUK', 1, NULL, NULL, '2026-02-04 05:50:25', 'Gatchalian', 'Migs', 'G', '1234', 'Poblacion', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, '[]', '', '', 'yes'),
(49, 102, 'Gatchalian, Migs G.', '123, Santisima, Malolos, Bulacan', 'Male', 'Single', '1998-04-30', 27, '09050460776', 'Employed', 'College Level / Graduate', NULL, 'N/A', 'N/A', '[\"VALIDID_1765517638_693ba9468f04d_0.jpeg\"]', NULL, '[\"RESIDENCY_1765517638_693ba9468f209_0.jpeg\"]', '[]', NULL, 'migggatchalian@gmail.com', '$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa/jZwHh.dJtDj0hgVfYzS', 1, NULL, NULL, '2025-12-12 05:33:58', 'Gatchalian', 'Migs', 'G', '123', 'Santisima', 'Malolos', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '', 'yes'),
(51, 153, 'Gatchalian, Miguel G.', 'B10 L22 Golden St, Bagbaguin, Sta. Maria, Bulacan', 'Male', 'Single', '1998-04-03', 27, '09560511944', 'Employed', 'College Level / Graduate', NULL, 'N/A', 'N/A', '[\"SCANNED_ID_1770182663_0.jpg\"]', NULL, '[\"SCANNED_CERT_1770182663_0.jpg\"]', '[]', NULL, 'miguel.gatchalian@bpc.edu.ph', '$2y$10$ouOuAjI9rCiQHj1moQ.m7OfR0xUZL6imFAPhdNa9dRwKFwiYpPEmm', 1, NULL, NULL, '2026-02-04 05:24:23', 'Gatchalian', 'Miguel', 'G', 'B10 L22 Golden St', 'Bagbaguin', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, '[]', '', '', 'yes'),
(52, 146, 'Cruz, Jonalyn F.', '1205 palikod treet, Lalakhan, Sta. Maria, Bulacan', 'Female', 'Married', '2006-02-17', 19, '09544514452', 'Unemployed', 'Vocational', NULL, 'NA', 'NA', '[\"valid_id_146_1771288300.jpg\"]', NULL, '[\"SCANNED_CERT_1769219127_0.jpg\"]', '[]', NULL, 'hyunj904@gmail.com', '$2y$10$xccw.aIIeVrJyIEkN6JeiuYL.fWxjevbFQgkveRNt1wjzKsE75Bay', 1, NULL, NULL, '2026-01-24 01:45:27', 'Cruz', 'Jonalyn', 'F', '1205 palikod treet', 'Lalakhan', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '', 'yes'),
(53, 173, 'gonzaga, toni-marie', 'happy street, Balasing, Sta. Maria, Bulacan', 'Female', 'Separated', '2003-11-30', 22, '09789455565', 'Employed', 'High School Level / Graduate', NULL, 'na', 'na', '[\"valid_id_173_1774403864.jpg\"]', NULL, '[\"SCANNED_CERT_1774403421_0.jpg\"]', '[]', NULL, 'ecc041351@gmail.com', '$2y$10$x/RA8nBgcbcSYgWP/WPCRutjEA8fyIUGVNcFTY1bCylV1.cp9JPyu', 1, NULL, NULL, '2026-03-25 01:50:21', 'gonzaga', 'toni-marie', '', 'happy street', 'Balasing', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"None\"]', '[\"None\"]'),
(54, 175, 'Alfonso, Carmella', 'Pasinaya Homes Ph3, Santa Clara, Sta. Maria, Bulacan', 'Female', 'Married', '1996-02-29', 30, '09123456789', 'Unemployed', 'High School Level / Graduate', NULL, 'NA', 'NA', '[\"UPLOADED_ID_1775747007_0.jpg\"]', NULL, '[\"SCANNED_CERT_1775747007_0.jpg\"]', '[]', NULL, 'yuyukim58@gmail.com', '$2y$10$wDAalwQSDxWUt9GXx7KobOrRulB5xWdcZN1F8uF9Tv3cqOmhIIuPy', 1, NULL, NULL, '2026-04-09 15:03:28', 'Alfonso', 'Carmella', '', 'Pasinaya Homes Ph3', 'Santa Clara', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"None\"]', '[\"None\"]'),
(55, 176, 'Siri-dy, Samantha-gil', '123 jbcuydsvcdys, Pulong Buhangin, Sta. Maria, Bulacan', 'Female', 'Single', '2006-11-24', 19, '09454854515', 'Employed', 'High School Level / Graduate', NULL, 'na', 'na', '[\"UPLOADED_ID_1775749085_0.jpg\"]', NULL, '[\"UPLOADED_CERT_1775749085_0.jpg\"]', '[]', NULL, 'youngtae991@gmail.com', '$2y$10$2/HF3MdCFpKJOnqViMO6sOY3uhwj5hsqKhsVoh1xOJwL0BhLlFv1m', 1, NULL, NULL, '2026-04-09 15:38:05', 'Siri-dy', 'Samantha-gil', '', '123 jbcuydsvcdys', 'Pulong Buhangin', 'Sta. Maria', 'Bulacan', '', '', 'trainee', NULL, NULL, NULL, NULL, '[\"None\"]', '[\"NC1\"]');

--
-- Triggers `trainees`
--
DELIMITER $$
CREATE TRIGGER `set_trainee_id_before_insert` BEFORE INSERT ON `trainees` FOR EACH ROW BEGIN
    SET NEW.trainee_id = CONCAT('LEMS-30135-', COALESCE(NEW.user_id, ''));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `set_trainee_id_before_update` BEFORE UPDATE ON `trainees` FOR EACH ROW BEGIN
    IF NEW.user_id IS NOT NULL AND NEW.user_id <> OLD.user_id THEN
        SET NEW.trainee_id = CONCAT('LEMS-30135-', COALESCE(NEW.user_id, ''));
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `trainee_oral_questions`
--

CREATE TABLE `trainee_oral_questions` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `max_score` int(11) DEFAULT 25,
  `score` decimal(5,2) DEFAULT NULL,
  `answer` text DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainee_practical_skills`
--

CREATE TABLE `trainee_practical_skills` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `skill_name` varchar(255) NOT NULL,
  `max_score` int(11) DEFAULT 20,
  `score` decimal(5,2) DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `is_hidden` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `reset_version` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `trainee_practical_skills`
--

INSERT INTO `trainee_practical_skills` (`id`, `enrollment_id`, `skill_name`, `max_score`, `score`, `order_index`, `is_hidden`, `created_at`, `reset_version`, `updated_at`) VALUES
(41, 162, 'New Skill 1', 20, '15.00', 0, 0, '2026-03-21 14:23:12', 0, '2026-03-21 14:23:45'),
(42, 162, 'New Skill 2', 20, '19.00', 1, 0, '2026-03-21 14:23:12', 0, '2026-03-21 14:23:45'),
(43, 160, 'New Skill 1', 20, '15.00', 0, 0, '2026-03-21 14:23:12', 0, '2026-03-21 14:23:45'),
(44, 160, 'New Skill 2', 20, '18.00', 1, 0, '2026-03-21 14:23:12', 0, '2026-03-21 14:23:45'),
(69, 164, 'New Skill', 20, '15.00', 0, 0, '2026-03-23 13:15:13', 0, '2026-03-23 13:29:07'),
(70, 164, 'New Skill2', 20, '19.00', 1, 0, '2026-03-23 13:15:13', 0, '2026-03-23 13:33:17'),
(71, 165, 'New Skill', 20, '20.00', 0, 0, '2026-03-23 13:15:13', 0, '2026-03-23 14:15:55'),
(72, 165, 'New Skill2', 20, '20.00', 1, 0, '2026-03-23 13:15:13', 0, '2026-03-23 14:15:55'),
(73, 166, 'New Skill 1', 20, '19.00', 0, 0, '2026-03-23 13:35:26', 0, '2026-03-23 13:35:57'),
(74, 166, 'New Skill 2', 20, '19.00', 1, 0, '2026-03-23 13:35:26', 0, '2026-03-23 13:43:11'),
(75, 166, 'New Skill 3', 20, '19.00', 2, 0, '2026-03-23 13:35:26', 0, '2026-03-23 13:43:11'),
(76, 166, 'New Skill 4', 20, '15.00', 3, 0, '2026-03-23 13:35:26', 0, '2026-03-23 13:43:11'),
(77, 163, 'New Skill 1', 20, '18.00', 0, 0, '2026-03-23 13:44:53', 0, '2026-03-23 14:13:12'),
(78, 163, 'New Skill 2', 20, '18.00', 1, 0, '2026-03-23 13:44:53', 0, '2026-03-23 14:13:12'),
(79, 163, 'New Skill 3', 20, '18.00', 2, 0, '2026-03-23 13:44:53', 0, '2026-03-23 14:13:19'),
(80, 175, 'bread msking', 20, '19.00', 0, 0, '2026-03-25 02:02:52', 0, '2026-03-25 02:03:47'),
(81, 175, 'New Skill 2', 20, '20.00', 1, 0, '2026-03-25 02:02:52', 0, '2026-03-25 02:03:47'),
(82, 175, 'New Skill 3', 20, '16.00', 2, 0, '2026-03-25 02:02:52', 0, '2026-03-25 02:03:47'),
(83, 175, 'New Skill 4', 20, '17.00', 3, 0, '2026-03-25 02:02:52', 0, '2026-03-25 02:03:47'),
(84, 173, 'Frontend', 20, '20.00', 0, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(85, 173, 'Backend', 20, '15.00', 1, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(86, 173, 'Database', 20, '20.00', 2, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(87, 172, 'Frontend', 20, '20.00', 0, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(88, 172, 'Backend', 20, '20.00', 1, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(89, 172, 'Database', 20, '15.00', 2, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(90, 174, 'Frontend', 20, '20.00', 0, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:30:52'),
(91, 174, 'Backend', 20, NULL, 1, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:29:50'),
(92, 174, 'Database', 20, NULL, 2, 0, '2026-03-25 02:29:50', 0, '2026-03-25 02:29:50'),
(93, 178, 'Network Configuration', 20, '15.00', 0, 0, '2026-04-09 04:22:58', 0, '2026-04-09 04:23:19'),
(94, 178, 'IP Addressing', 20, '20.00', 1, 0, '2026-04-09 04:22:58', 0, '2026-04-09 04:23:19'),
(95, 178, 'Network Protocols:', 20, '19.00', 2, 0, '2026-04-09 04:22:58', 0, '2026-04-09 04:23:19'),
(99, 182, 'f,gkjh', 20, '3.00', 0, 0, '2026-04-09 17:25:16', 0, '2026-04-09 17:25:38'),
(100, 182, 'tygjbnm', 20, '8.00', 1, 0, '2026-04-09 17:25:16', 0, '2026-04-09 17:25:38'),
(101, 182, 'iouiuyfg', 20, '9.00', 2, 0, '2026-04-09 17:25:16', 0, '2026-04-09 17:25:38'),
(108, 184, 'Basic Knife Skills', 20, '8.00', 0, 0, '2026-04-09 18:01:52', 0, '2026-04-09 18:02:07'),
(109, 184, 'New Skill', 20, '8.00', 1, 0, '2026-04-09 18:01:52', 0, '2026-04-09 18:02:07'),
(110, 184, 'New Skill', 20, '8.00', 2, 0, '2026-04-09 18:01:52', 0, '2026-04-09 18:02:07'),
(111, 185, 'Basic Knife Skills', 20, '8.00', 0, 0, '2026-04-09 19:00:12', 0, '2026-04-09 19:00:17'),
(112, 185, 'New Skill', 20, '8.00', 1, 0, '2026-04-09 19:00:12', 0, '2026-04-09 19:00:30'),
(113, 185, 'New Skill', 20, '8.00', 2, 0, '2026-04-09 19:00:12', 0, '2026-04-09 19:00:30'),
(114, 185, 'New Skill', 20, '8.00', 3, 0, '2026-04-09 19:00:12', 0, '2026-04-09 19:00:30'),
(115, 186, 'Basic Knife Skills', 20, '20.00', 0, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:40'),
(116, 186, 'New Skill', 20, '10.00', 1, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:40'),
(117, 186, 'New Skill', 20, '20.00', 2, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:40'),
(118, 186, 'New Skill', 20, '19.00', 3, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:40'),
(119, 183, 'Basic Knife Skills', 20, '12.00', 0, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:41'),
(120, 183, 'New Skill', 20, '11.00', 1, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:41'),
(121, 183, 'New Skill', 20, '2.00', 2, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:41'),
(122, 183, 'New Skill', 20, '15.00', 3, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:41'),
(123, 181, 'Basic Knife Skills', 20, '1.00', 0, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:41'),
(124, 181, 'New Skill', 20, NULL, 1, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:04'),
(125, 181, 'New Skill', 20, NULL, 2, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:04'),
(126, 181, 'New Skill', 20, NULL, 3, 0, '2026-04-09 22:46:04', 0, '2026-04-09 22:46:04');

-- --------------------------------------------------------

--
-- Table structure for table `trainer_attendance`
--

CREATE TABLE `trainer_attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `trainer_name` varchar(100) NOT NULL,
  `attendance_type` enum('Time In','Time Out') NOT NULL,
  `attendance_time` datetime NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `distance_from_office` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `trainer_attendance`
--

INSERT INTO `trainer_attendance` (`id`, `user_id`, `trainer_name`, `attendance_type`, `attendance_time`, `latitude`, `longitude`, `distance_from_office`, `created_at`) VALUES
(1, 96, 'Sam', 'Time In', '2025-12-07 00:53:51', '14.82215570', '121.02131420', '0.04', '2025-12-07 05:53:51'),
(7, 147, 'ashily ', 'Time In', '2026-02-02 09:28:00', '14.78185339', '120.87835994', '24.41', '2026-02-02 14:28:00'),
(8, 147, 'ashily ', 'Time In', '2026-02-03 07:09:23', '14.78183157', '120.87837796', '26.02', '2026-02-03 12:09:23'),
(9, 147, 'ashily ', 'Time Out', '2026-02-03 07:09:45', '14.78183157', '120.87837796', '26.02', '2026-02-03 12:09:45'),
(10, 96, 'Sam', 'Time In', '2026-02-03 21:39:18', '14.78186574', '120.87835581', '24.26', '2026-02-03 13:39:18'),
(11, 96, 'Sam', 'Time Out', '2026-02-03 21:39:43', '14.78186574', '120.87835581', '24.26', '2026-02-03 13:39:43'),
(14, 147, 'ashily', 'Time In', '2026-02-04 13:51:26', '14.86674811', '120.80690861', '45.13', '2026-02-04 05:51:26'),
(15, 96, 'Sam', 'Time In', '2026-02-26 08:31:09', '14.78191733', '120.87834626', '30.51', '2026-02-26 00:31:09'),
(16, 169, 'Lexis', 'Time In', '2026-03-10 22:41:02', '14.82256263', '121.01371279', '131.56', '2026-03-10 14:41:02'),
(17, 169, 'Lexis', 'Time Out', '2026-03-10 22:41:20', '14.82255861', '121.01369983', '130.14', '2026-03-10 14:41:20'),
(18, 101, 'adi hue', 'Time In', '2026-03-13 10:03:35', '14.78205886', '120.87818866', '37.99', '2026-03-13 02:03:35'),
(19, 101, 'adi hue', 'Time In', '2026-03-14 09:57:56', '14.78193144', '120.87832498', '49.57', '2026-03-14 01:57:56'),
(20, 147, 'ashily', 'Time In', '2026-03-14 10:58:07', '14.78192480', '120.87821660', '37.91', '2026-03-14 02:58:07'),
(21, 147, 'ashily', 'Time In', '2026-03-15 16:07:30', '14.78193763', '120.87823013', '39.39', '2026-03-15 08:07:30'),
(22, 147, 'ashily', 'Time Out', '2026-03-15 16:07:41', '14.78200176', '120.87831042', '48.77', '2026-03-15 08:07:41'),
(23, 172, 'Paulo Victoria', 'Time In', '2026-03-16 13:50:37', '14.86677820', '120.80714440', '44.02', '2026-03-16 05:50:37'),
(24, 96, 'Sam', 'Time In', '2026-04-08 19:03:32', '14.78161537', '120.87819024', '44.70', '2026-04-08 11:03:32'),
(25, 96, 'Sam', 'Time Out', '2026-04-08 19:05:07', '14.78194960', '120.87831801', '68.00', '2026-04-08 11:05:07'),
(26, 97, 'haniie yoon', 'Time In', '2026-04-09 12:14:54', '14.78188417', '120.87834733', '31.77', '2026-04-09 04:14:54');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','trainer','trainee') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'trainee',
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Active','Inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `program` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialization` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_programs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allow_multiple_programs` tinyint(1) DEFAULT 0,
  `must_reset` tinyint(1) DEFAULT 0,
  `reset_token` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `assigned_count` int(11) DEFAULT 0,
  `max_programs` int(11) DEFAULT 1,
  `pin_latitude` decimal(10,6) DEFAULT NULL,
  `pin_longitude` decimal(10,6) DEFAULT NULL,
  `pin_radius` int(11) DEFAULT 100,
  `pin_location_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `role`, `date_created`, `status`, `program`, `specialization`, `other_programs`, `allow_multiple_programs`, `must_reset`, `reset_token`, `reset_expires`, `full_name`, `is_available`, `assigned_count`, `max_programs`, `pin_latitude`, `pin_longitude`, `pin_radius`, `pin_location_name`) VALUES
(2, 'Lems Admin', 'lems.superadmn@gmail.com', '$2y$10$1L9lP0Zg9XI3nmTgqtEeEuw22/1W2orUdU63xWZ/K8YK53GjpN91W', 'admin', '2025-10-19 15:04:47', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(92, 'morcillo, alexis mae F.', 'lexiambay@gmail.com', '$2y$10$MyepPInehlCIXZod4TbTf.YfU8F5MukpSn1gn3XXzNfW8fIxgLG96', 'trainee', '2025-11-26 01:15:47', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(96, 'Sam', 'sillanojovelyn326@gmail.com', '$2y$10$d0XXi/lOjZahESUpFWxehey20So8rWinF6msVnwCOS3M34Dncfini', 'trainer', '2025-11-29 21:29:11', 'Active', 'cookeryy', 'food processing', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.781636', '120.877775', 100, 'Villas of Saint Honore, Bambang, Purok 1, Santa Ines, Bulakan, Bulacan, Central Luzon, 3017, Philippines'),
(97, 'haniie yoon', 'hannieyoon141@gmail.com', '$2y$10$J/AlrFsHNyYmRgKABIf8Te5eO2yYrj3BL9tYIKRQH7rtpzgeBpkYi', 'trainer', '2025-12-08 04:56:47', 'Active', '', 'Food and Beverages', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.781664', '120.878159', 100, 'Villas of Saint Honore, Bambang, Purok 1, Santa Ines, Bulakan, Bulacan, Central Luzon, 3017, Philippines'),
(101, 'adi hue', 'kanghyungka@gmail.com', '$2y$10$NNcgnG56WmSF5XIYz11./uIR.CdnVJFOtwWKrGPggIyEZ8qpofPdq', 'trainer', '2025-12-12 01:24:41', 'Active', '', 'Sewing & Creative Skills', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.836537', '120.847034', 100, 'Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines'),
(102, 'Gatchalian, Migs G.', 'migggatchalian@gmail.com', '$2y$10$fbwNXaOKcM6j4OxtANAPVO0D2lKIiuEVa/jZwHh.dJtDj0hgVfYzS', 'trainee', '2025-12-12 05:33:58', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(105, 'Desiree Robles', 'deserie.robles@bpc.edu.ph', '$2y$10$x8/zwtfImB0Olq70FB62NuPrWJ.0d/M.calAfpIdsAWSyxyc8DpRu', 'trainer', '2025-12-12 05:53:27', 'Active', '', 'Educational Programs', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.836537', '120.847034', 100, 'Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines'),
(146, 'Cruz, Jonalyn F.', 'hyunj904@gmail.com', '$2y$10$xccw.aIIeVrJyIEkN6JeiuYL.fWxjevbFQgkveRNt1wjzKsE75Bay', 'trainee', '2026-01-24 01:45:27', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(147, 'ashily ', 'wonderingjoy1@gmail.com', '$2y$10$0WNYzWuKhWSdYvPD1.RkFuGKvdPmPMLjRm8JIzRL4psw0VPM54NJq', 'trainer', '2026-01-24 02:57:32', 'Active', '', 'Cosmetics', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.915170', '120.766207', 100, 'Calumpit, Bulacan, Central Luzon, 3003, Philippines'),
(149, 'Yen Ashitrid', 'sillanojovelyn@gmail.com', '$2y$10$KM0tjNazpl.zNEZ.fVEEOO0maVcD0TZV/mm22nC1QN.WL6lR345P2', 'trainer', '2026-02-01 06:57:34', 'Active', '', 'Handicrafts', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.836537', '120.847034', 100, 'Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines'),
(150, 'SILLANO, JOVELYN C.', 'vell88091@gmail.com', '$2y$10$kDq4VRDYpMf/6x9mJBN1l./lv4.qS/Lo9lA.mR7MxrWQHIZljeNoK', 'trainee', '2026-02-01 08:20:24', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(151, 'sy, hanna m.', 'wonderingjoy2@gmail.com', '$2y$10$u436ay0Bx.xC.PZpiCA/QOnHFYJnUpF44uIZxwGEX0o/MLFkGBRo2', 'trainee', '2026-02-02 13:20:01', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(153, 'Gatchalian, Miguel G.', 'miguel.gatchalian@bpc.edu.ph', '$2y$10$ouOuAjI9rCiQHj1moQ.m7OfR0xUZL6imFAPhdNa9dRwKFwiYpPEmm', 'trainee', '2026-02-04 05:24:23', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(154, 'Fulgencio, Pam NA.', 'AIVEEFULGENCIO26@GMAIL.COM', '$2y$10$1ANxl6C5O0YDIKYezHcHk.DVvn5i8XaF97IPUuvFae.6Zg7yviR26', 'trainee', '2026-02-04 05:32:04', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(156, 'Gatchalian, Migs G.', 'miggatchalian@gmail.com', '$2y$10$bh2d2gJIUMf.Q2m6Mwqswue8vd88rpdYb0orI8yP1z791GXuAOKUK', 'trainee', '2026-02-04 05:50:25', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.782043', '120.878094', 43, 'LEMS ADMIN'),
(158, 'Eimarie clair', 'takoyakei03@gmail.com', '$2y$10$oF2wA9oZ/0/B58HTd5Gja.3yWh9PWExdoPunl9wHJ7JOzbfiBEr92', 'trainer', '2026-02-04 06:44:28', 'Active', '', 'Food and Beverages', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.841018', '120.873728', 100, 'Rosaryville Avenue, Violeta Village, Santa Cruz, Guiguinto, Bulacan, Central Luzon, 3015, Philippines'),
(161, 'Hans, Kier', 'wonderingjoy3@gmail.com', '$2y$10$dbAQJrOxZ32Gsdnhyefb8.daVZXj/baPk.yz3uaGnow3j6xoo8JfS', 'trainee', '2026-02-27 10:59:26', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(165, 'Geronimo-Cruz, Sofia', 'wonderingjoy4@gmail.com', '$2y$10$vhor22azG6WH9Ahd3iPRW.RhFjITfeuo.JsI6n/Dgb1iMKE.8btqO', 'trainee', '2026-02-28 07:30:33', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(169, 'Lexis ', 'alexismaemorcillo@gmail.com', '$2y$10$3GDkjUI90MOSX6W3exKu0eTX.RSSc/jh6R42nkOBkSqz2NcJvWrES', 'trainer', '2026-03-03 09:35:14', 'Active', '', 'Livelihood Programs', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.836537', '120.847034', 100, 'Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines'),
(170, 'Ambay, Alex', 'leximaeambay@gmail.com', '$2y$10$Qhw6KgS0/3WohQPZQYELzOjH0DzAdM9ttoMBGOf1cTlsRQJMsyvMS', 'trainee', '2026-03-03 10:48:40', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(172, 'Paulo Victoria', 'paulo.victoria@bpc.edu.ph', '$2y$10$TpWoIyMw7BQkMuTMdW1xw.EDxk29jo7CisSMbjqqk0vKIx/LpGC3i', 'trainer', '2026-03-16 05:46:36', 'Active', '', 'Techonology', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, '14.836537', '120.847034', 100, 'Santor, Niugan, Malolos, Bulacan, Central Luzon, 3015, Philippines'),
(173, 'gonzaga, toni-mariee', 'ecc041351@gmail.com', '$2y$10$x/RA8nBgcbcSYgWP/WPCRutjEA8fyIUGVNcFTY1bCylV1.cp9JPyu', 'trainee', '2026-03-25 01:50:21', 'Active', '', NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(174, 'drea ly', 'elycorre45@gmail.com', '$2y$10$m9x9ZR28KVCQQrGQ0wMR/.gdHihw9On8kCr/bbtZW01LSRCpqVwEO', 'trainer', '2026-04-05 05:23:01', 'Active', '', 'Home-Based & Small Business Production', NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(175, 'Alfonso, Carmella', 'yuyukim58@gmail.com', '$2y$10$wDAalwQSDxWUt9GXx7KobOrRulB5xWdcZN1F8uF9Tv3cqOmhIIuPy', 'trainee', '2026-04-09 15:03:28', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL),
(176, 'Siri-dy, Samantha-gil', 'youngtae991@gmail.com', '$2y$10$2/HF3MdCFpKJOnqViMO6sOY3uhwj5hsqKhsVoh1xOJwL0BhLlFv1m', 'trainee', '2026-04-09 15:38:05', 'Active', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 1, 0, 1, NULL, NULL, 100, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `created_at`) VALUES
(1, 2, 1, '2025-12-10 03:51:58');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_user_training_history`
-- (See below for the actual view)
--
CREATE TABLE `vw_user_training_history` (
`user_id` int(11)
,`user_name` varchar(100)
,`program_name` varchar(255)
,`start_date` varchar(10)
,`end_date` varchar(10)
,`program_trainer_name` varchar(255)
,`enrollment_status` enum('pending','approved','rejected','revision_needed','completed')
,`enrollment_completed_at` datetime
,`archived_at` timestamp
,`archive_trigger` enum('program_ended','program_moved_to_archive','enrollment_completed','manual')
,`feedback_comments` text
,`participation_status` varchar(10)
);

-- --------------------------------------------------------

--
-- Structure for view `vw_user_training_history`
--
DROP TABLE IF EXISTS `vw_user_training_history`;

CREATE ALGORITHM=UNDEFINED DEFINER=`o14985503`@`%` SQL SECURITY DEFINER VIEW `vw_user_training_history`  AS SELECT `ah`.`user_id` AS `user_id`, `u`.`fullname` AS `user_name`, `ah`.`program_name` AS `program_name`, date_format(`ah`.`program_schedule_start`,'%Y-%m-%d') AS `start_date`, date_format(`ah`.`program_schedule_end`,'%Y-%m-%d') AS `end_date`, `ah`.`program_trainer_name` AS `program_trainer_name`, `ah`.`enrollment_status` AS `enrollment_status`, `ah`.`enrollment_completed_at` AS `enrollment_completed_at`, `ah`.`archived_at` AS `archived_at`, `ah`.`archive_trigger` AS `archive_trigger`, `ah`.`feedback_comments` AS `feedback_comments`, CASE WHEN `ah`.`enrollment_status` = 'completed' THEN 'Completed' WHEN `ah`.`enrollment_status` = 'approved' THEN 'Attended' ELSE 'Registered' END AS `participation_status` FROM (`archived_history` `ah` join `users` `u` on(`ah`.`user_id` = `u`.`id`)) ORDER BY `ah`.`archived_at` DESCdesc ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `archive`
--
ALTER TABLE `archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_archive_status` (`status`);

--
-- Indexes for table `archived_history`
--
ALTER TABLE `archived_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_archived_history_user_id` (`user_id`),
  ADD KEY `idx_archived_history_program_id` (`original_program_id`),
  ADD KEY `idx_archived_history_enrollment_id` (`enrollment_id`),
  ADD KEY `idx_archived_history_feedback_id` (`feedback_id`),
  ADD KEY `idx_archived_history_archive_program_id` (`archive_program_id`),
  ADD KEY `idx_archived_history_archive_date` (`archived_at`),
  ADD KEY `idx_archived_history_archive_trigger` (`archive_trigger`),
  ADD KEY `idx_archived_history_user_program` (`user_id`,`original_program_id`),
  ADD KEY `idx_archived_history_program_category` (`program_category_id`),
  ADD KEY `fk_archived_history_trainer` (`program_trainer_id`),
  ADD KEY `fk_archived_history_approver` (`enrollment_approved_by`);

--
-- Indexes for table `archived_users`
--
ALTER TABLE `archived_users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `archived_users_history`
--
ALTER TABLE `archived_users_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_original_user_id` (`original_user_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_archived_at` (`archived_at`),
  ADD KEY `idx_archived_by` (`archived_by`),
  ADD KEY `idx_restored_by` (`restored_by`);

--
-- Indexes for table `archive_programs`
--
ALTER TABLE `archive_programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status_idx` (`status`),
  ADD KEY `category_id_idx` (`category_id`),
  ADD KEY `is_archived_idx` (`is_archived`);

--
-- Indexes for table `assessment_components`
--
ALTER TABLE `assessment_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`enrollment_id`,`attendance_date`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `certificate_signatory`
--
ALTER TABLE `certificate_signatory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_certificate_unique_id` (`certificate_unique_id`);

--
-- Indexes for table `certificate_signatory_settings`
--
ALTER TABLE `certificate_signatory_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `certificate_signatory_templates`
--
ALTER TABLE `certificate_signatory_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enrollments_user_id` (`user_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_enrollment_status` (`enrollment_status`),
  ADD KEY `idx_enrollment_revision` (`revision_requests_id`);

--
-- Indexes for table `faqs`
--
ALTER TABLE `faqs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `type` (`type`);

--
-- Indexes for table `oral_questions`
--
ALTER TABLE `oral_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_name` (`permission_name`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_programs_status` (`status`),
  ADD KEY `idx_programs_archived` (`is_archived`);

--
-- Indexes for table `program_categories`
--
ALTER TABLE `program_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `program_history`
--
ALTER TABLE `program_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_program_id` (`program_id`),
  ADD KEY `idx_archived_program_id` (`archived_program_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `program_oral_questions`
--
ALTER TABLE `program_oral_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `program_practical_skills`
--
ALTER TABLE `program_practical_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `reset_history`
--
ALTER TABLE `reset_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `revision_requests`
--
ALTER TABLE `revision_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `edit_token` (`edit_token`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `idx_revision_token` (`edit_token`),
  ADD KEY `idx_revision_status` (`status`),
  ADD KEY `idx_revision_expiry` (`token_expiry`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `trainees`
--
ALTER TABLE `trainees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `trainee_oral_questions`
--
ALTER TABLE `trainee_oral_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `trainee_practical_skills`
--
ALTER TABLE `trainee_practical_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `trainer_attendance`
--
ALTER TABLE `trainer_attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  ADD KEY `role_id` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `archived_history`
--
ALTER TABLE `archived_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `archived_users`
--
ALTER TABLE `archived_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=169;

--
-- AUTO_INCREMENT for table `archived_users_history`
--
ALTER TABLE `archived_users_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `archive_programs`
--
ALTER TABLE `archive_programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=143;

--
-- AUTO_INCREMENT for table `assessment_components`
--
ALTER TABLE `assessment_components`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificate_signatory`
--
ALTER TABLE `certificate_signatory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificate_signatory_settings`
--
ALTER TABLE `certificate_signatory_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `certificate_signatory_templates`
--
ALTER TABLE `certificate_signatory_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

--
-- AUTO_INCREMENT for table `faqs`
--
ALTER TABLE `faqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `oral_questions`
--
ALTER TABLE `oral_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `program_categories`
--
ALTER TABLE `program_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `program_history`
--
ALTER TABLE `program_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- AUTO_INCREMENT for table `program_oral_questions`
--
ALTER TABLE `program_oral_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `program_practical_skills`
--
ALTER TABLE `program_practical_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `reset_history`
--
ALTER TABLE `reset_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `revision_requests`
--
ALTER TABLE `revision_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=221;

--
-- AUTO_INCREMENT for table `trainees`
--
ALTER TABLE `trainees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `trainee_oral_questions`
--
ALTER TABLE `trainee_oral_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `trainee_practical_skills`
--
ALTER TABLE `trainee_practical_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=127;

--
-- AUTO_INCREMENT for table `trainer_attendance`
--
ALTER TABLE `trainer_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=177;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `archived_history`
--
ALTER TABLE `archived_history`
  ADD CONSTRAINT `fk_archived_history_approver` FOREIGN KEY (`enrollment_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_archived_history_archive_program` FOREIGN KEY (`archive_program_id`) REFERENCES `archive_programs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_archived_history_trainer` FOREIGN KEY (`program_trainer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_archived_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `archived_users_history`
--
ALTER TABLE `archived_users_history`
  ADD CONSTRAINT `fk_archived_users_history_archived_by` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_archived_users_history_restored_by` FOREIGN KEY (`restored_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `assessment_components`
--
ALTER TABLE `assessment_components`
  ADD CONSTRAINT `assessment_components_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `certificate_signatory`
--
ALTER TABLE `certificate_signatory`
  ADD CONSTRAINT `certificate_signatory_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `enrollments_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `enrollments_ibfk_4` FOREIGN KEY (`revision_requests_id`) REFERENCES `revision_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `enrollments_ibfk_5` FOREIGN KEY (`revision_requests_id`) REFERENCES `revision_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `enrollments_ibfk_6` FOREIGN KEY (`revision_requests_id`) REFERENCES `revision_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `enrollments_ibfk_7` FOREIGN KEY (`revision_requests_id`) REFERENCES `revision_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `enrollments_ibfk_8` FOREIGN KEY (`revision_requests_id`) REFERENCES `revision_requests` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `program_categories` (`id`);

--
-- Constraints for table `revision_requests`
--
ALTER TABLE `revision_requests`
  ADD CONSTRAINT `revision_requests_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `revision_requests_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trainee_oral_questions`
--
ALTER TABLE `trainee_oral_questions`
  ADD CONSTRAINT `trainee_oral_questions_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
