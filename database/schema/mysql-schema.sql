/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `attendance_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_breaks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `attendance_log_id` bigint unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_breaks_tenant_id_foreign` (`tenant_id`),
  KEY `attendance_breaks_attendance_log_id_ended_at_index` (`attendance_log_id`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_correction_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_correction_audits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `attendance_log_id` bigint unsigned DEFAULT NULL,
  `request_id` bigint unsigned NOT NULL,
  `original_check_in` datetime DEFAULT NULL,
  `original_check_out` datetime DEFAULT NULL,
  `corrected_check_in` datetime DEFAULT NULL,
  `corrected_check_out` datetime DEFAULT NULL,
  `original_late_minutes` smallint unsigned DEFAULT NULL,
  `corrected_late_minutes` smallint unsigned DEFAULT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `employee_reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_reason` text COLLATE utf8mb4_unicode_ci,
  `decision` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_correction_audits_tenant_id_request_id_index` (`tenant_id`,`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_correction_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_correction_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `attendance_log_id` bigint unsigned DEFAULT NULL,
  `work_date` date NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_check_in` datetime DEFAULT NULL,
  `requested_check_out` datetime DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_by` bigint unsigned NOT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_correction_requests_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `attendance_correction_requests_employee_id_work_date_index` (`employee_id`,`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_location_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_location_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `attendance_log_id` bigint unsigned NOT NULL,
  `event_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `accuracy_meters` int unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acknowledgement_id` bigint unsigned DEFAULT NULL,
  `captured_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_location_event` (`attendance_log_id`,`event_type`),
  KEY `attendance_location_records_employee_id_foreign` (`employee_id`),
  KEY `attendance_location_records_acknowledgement_id_foreign` (`acknowledgement_id`),
  KEY `attendance_location_employee` (`tenant_id`,`employee_id`,`captured_at`),
  CONSTRAINT `attendance_location_records_acknowledgement_id_foreign` FOREIGN KEY (`acknowledgement_id`) REFERENCES `gps_attendance_acknowledgements` (`id`),
  CONSTRAINT `attendance_location_records_attendance_log_id_foreign` FOREIGN KEY (`attendance_log_id`) REFERENCES `attendance_logs` (`id`),
  CONSTRAINT `attendance_location_records_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `attendance_location_records_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_location_retention_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_location_retention_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `retention_days` smallint unsigned NOT NULL,
  `cutoff_at` timestamp NOT NULL,
  `deleted_count` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_location_retention_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `attendance_location_retention_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_location_view_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_location_view_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `subject` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_location_view_events_actor_id_foreign` (`actor_id`),
  KEY `attendance_location_view_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `attendance_location_view_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `attendance_location_view_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `work_date` date NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `work_mode` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'office',
  `check_in_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_as` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `minutes` smallint unsigned DEFAULT NULL,
  `late_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `check_out_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recovery_note` text COLLATE utf8mb4_unicode_ci,
  `check_in_latitude` decimal(10,7) DEFAULT NULL,
  `check_in_longitude` decimal(10,7) DEFAULT NULL,
  `check_in_distance_meters` int unsigned DEFAULT NULL,
  `check_out_latitude` decimal(10,7) DEFAULT NULL,
  `check_out_longitude` decimal(10,7) DEFAULT NULL,
  `check_out_distance_meters` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_logs_employee_id_work_date_unique` (`employee_id`,`work_date`),
  KEY `attendance_logs_tenant_id_foreign` (`tenant_id`),
  KEY `attendance_logs_location_id_foreign` (`location_id`),
  CONSTRAINT `attendance_logs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_logs_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `attendance_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_onboarding_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_onboarding_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `submission_id` bigint unsigned DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `candidate_onboarding_events_submission_id_foreign` (`submission_id`),
  KEY `candidate_onboarding_events_actor_id_foreign` (`actor_id`),
  KEY `candidate_onboarding_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `candidate_onboarding_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `candidate_onboarding_events_submission_id_foreign` FOREIGN KEY (`submission_id`) REFERENCES `candidate_submissions` (`id`),
  CONSTRAINT `candidate_onboarding_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_onboarding_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_onboarding_forms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `notice_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candidate_onboarding_forms_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `candidate_onboarding_forms_public_token_unique` (`public_token`),
  KEY `candidate_onboarding_forms_updated_by_foreign` (`updated_by`),
  CONSTRAINT `candidate_onboarding_forms_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `candidate_onboarding_forms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_retention_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_retention_audits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `reference` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_id` bigint unsigned DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `event_count` int unsigned NOT NULL,
  `document_count` int unsigned NOT NULL,
  `files_removed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `candidate_retention_audits_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_submission_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_submission_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `submission_id` bigint unsigned NOT NULL,
  `category` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candidate_documents_category` (`submission_id`,`category`),
  KEY `candidate_submission_documents_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `candidate_submission_documents_submission_id_foreign` FOREIGN KEY (`submission_id`) REFERENCES `candidate_submissions` (`id`),
  CONSTRAINT `candidate_submission_documents_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_submission_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_submission_operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `submission_id` bigint unsigned NOT NULL,
  `payment_received` tinyint(1) NOT NULL DEFAULT '0',
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `training_agreement_sent` tinyint(1) NOT NULL DEFAULT '0',
  `work_day` date DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candidate_submission_operations_submission_id_unique` (`submission_id`),
  KEY `candidate_submission_operations_tenant_id_foreign` (`tenant_id`),
  KEY `candidate_submission_operations_updated_by_foreign` (`updated_by`),
  CONSTRAINT `candidate_submission_operations_submission_id_foreign` FOREIGN KEY (`submission_id`) REFERENCES `candidate_submissions` (`id`),
  CONSTRAINT `candidate_submission_operations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `candidate_submission_operations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidate_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `form_id` bigint unsigned NOT NULL,
  `reference` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `first_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_last4` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `university_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `employment_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_location` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hourly_rate` decimal(8,2) NOT NULL,
  `hours_per_week` tinyint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `experience` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsibility_1` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsibility_2` text COLLATE utf8mb4_unicode_ci,
  `responsibility_3` text COLLATE utf8mb4_unicode_ci,
  `responsibility_4` text COLLATE utf8mb4_unicode_ci,
  `responsibility_5` text COLLATE utf8mb4_unicode_ci,
  `responsibility_6` text COLLATE utf8mb4_unicode_ci,
  `bank_name_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_last4` char(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `routing_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `routing_last4` char(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_authorization` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `i94_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `i94_last4` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ead_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ead_last4` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_authorization_expires_on` date NOT NULL,
  `ssn_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ssn_last4` char(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_role` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `goals` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `oversight` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `evaluation_methods` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `notice_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consented_at` timestamp NOT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_note` text COLLATE utf8mb4_unicode_ci,
  `change_request` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candidate_submissions_reference_unique` (`reference`),
  KEY `candidate_submissions_form_id_foreign` (`form_id`),
  KEY `candidate_submissions_reviewed_by_foreign` (`reviewed_by`),
  KEY `candidate_submissions_tenant_id_email_hash_index` (`tenant_id`,`email_hash`),
  KEY `candidate_submissions_status` (`tenant_id`,`status`,`created_at`),
  CONSTRAINT `candidate_submissions_form_id_foreign` FOREIGN KEY (`form_id`) REFERENCES `candidate_onboarding_forms` (`id`),
  CONSTRAINT `candidate_submissions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `candidate_submissions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_admin_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_admin_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invited_by` bigint unsigned NOT NULL,
  `role_name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `accepted_user_id` bigint unsigned DEFAULT NULL,
  `active_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_open_key` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_admin_invitations_token_hash_unique` (`token_hash`),
  UNIQUE KEY `company_admin_invitations_active_key_unique` (`active_key`),
  UNIQUE KEY `company_admin_invitations_company_open_key_unique` (`company_open_key`),
  KEY `company_admin_invitations_invited_by_foreign` (`invited_by`),
  KEY `company_admin_invitations_accepted_user_id_foreign` (`accepted_user_id`),
  KEY `company_admin_invitations_tenant_id_email_index` (`tenant_id`,`email`),
  CONSTRAINT `company_admin_invitations_accepted_user_id_foreign` FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `company_admin_invitations_invited_by_foreign` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`),
  CONSTRAINT `company_admin_invitations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_attendance_setting_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_attendance_setting_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_attendance_setting_events_actor_id_foreign` (`actor_id`),
  KEY `company_attendance_setting_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `company_attendance_setting_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `company_attendance_setting_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_attendance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_attendance_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `gps_capture_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `notice_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_attendance_settings_tenant_id_unique` (`tenant_id`),
  KEY `company_attendance_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `company_attendance_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `company_attendance_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_legal_profile_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_legal_profile_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_values` json DEFAULT NULL,
  `updated_values` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_legal_profile_events_actor_id_foreign` (`actor_id`),
  KEY `company_legal_profile_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `company_legal_profile_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `company_legal_profile_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_legal_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_legal_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `legal_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legal_address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `registration_details` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signatory_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signatory_designation` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hr_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `updated_by` bigint unsigned DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `signature_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `signature_active` tinyint(1) NOT NULL DEFAULT '0',
  `signature_version` int unsigned NOT NULL DEFAULT '0',
  `signature_uploaded_by` bigint unsigned DEFAULT NULL,
  `signature_uploaded_at` timestamp NULL DEFAULT NULL,
  `signature_width` smallint unsigned NOT NULL DEFAULT '140',
  `signature_height` smallint unsigned NOT NULL DEFAULT '48',
  `signature_align` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'left',
  `signature_gap_before` smallint unsigned NOT NULL DEFAULT '8',
  `signature_gap_after` smallint unsigned NOT NULL DEFAULT '6',
  `country` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'india',
  `letterhead_confirmed_by` bigint unsigned DEFAULT NULL,
  `letterhead_confirmed_at` timestamp NULL DEFAULT NULL,
  `letterhead_confirmed_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_legal_profiles_tenant_id_country_unique` (`tenant_id`,`country`),
  KEY `company_legal_profiles_updated_by_foreign` (`updated_by`),
  KEY `company_legal_profiles_verified_by_foreign` (`verified_by`),
  CONSTRAINT `company_legal_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `company_legal_profiles_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `company_legal_profiles_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_lifecycle_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_lifecycle_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `subject_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_lifecycle_events_actor_id_foreign` (`actor_id`),
  KEY `company_lifecycle_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `company_lifecycle_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `company_lifecycle_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_payroll_setting_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_payroll_setting_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_payroll_setting_events_actor_id_foreign` (`actor_id`),
  KEY `company_payroll_setting_events_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_payroll_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_payroll_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_payroll_settings_tenant_id_unique` (`tenant_id`),
  KEY `company_payroll_settings_updated_by_foreign` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `departments_parent_id_foreign` (`parent_id`),
  CONSTRAINT `departments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `departments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `designations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `designations_tenant_id_name_unique` (`tenant_id`,`name`),
  CONSTRAINT `designations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_category_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_category_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `category_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_key` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_category_settings_tenant_id_category_key_unique` (`tenant_id`,`category_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_types_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `document_types_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `category` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `review_status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_note` text COLLATE utf8mb4_unicode_ci,
  `replaces_document_id` bigint unsigned DEFAULT NULL,
  `reference` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `employee_documents_tenant_id_foreign` (`tenant_id`),
  KEY `employee_documents_employee_id_foreign` (`employee_id`),
  KEY `employee_documents_document_type_id_foreign` (`document_type_id`),
  KEY `employee_documents_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `employee_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_documents_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_documents_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_record_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_record_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `employee_record_events_tenant_id_employee_id_index` (`tenant_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_skills` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_skills_tenant_id_foreign` (`tenant_id`),
  KEY `employee_skills_employee_id_foreign` (`employee_id`),
  CONSTRAINT `employee_skills_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_skills_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `employee_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `family_info` text COLLATE utf8mb4_unicode_ci,
  `emergency_contact` text COLLATE utf8mb4_unicode_ci,
  `social_links` text COLLATE utf8mb4_unicode_ci,
  `department_id` bigint unsigned DEFAULT NULL,
  `designation_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `manager_id` bigint unsigned DEFAULT NULL,
  `shift_id` bigint unsigned DEFAULT NULL,
  `employment_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full_time',
  `work_mode` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'office',
  `holiday_calendar` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'india',
  `date_of_joining` date NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `pan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uan` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `esi_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_tenant_id_employee_code_unique` (`tenant_id`,`employee_code`),
  UNIQUE KEY `employees_tenant_id_email_unique` (`tenant_id`,`email`),
  UNIQUE KEY `employees_user_id_unique` (`user_id`),
  KEY `employees_department_id_foreign` (`department_id`),
  KEY `employees_designation_id_foreign` (`designation_id`),
  KEY `employees_location_id_foreign` (`location_id`),
  KEY `employees_manager_id_foreign` (`manager_id`),
  KEY `employees_shift_id_foreign` (`shift_id`),
  CONSTRAINT `employees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_designation_id_foreign` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employment_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employment_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employment_histories_tenant_id_foreign` (`tenant_id`),
  KEY `employment_histories_employee_id_foreign` (`employee_id`),
  CONSTRAINT `employment_histories_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employment_histories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exit_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exit_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `resignation_date` date NOT NULL,
  `last_working_day` date NOT NULL,
  `notice_days` smallint unsigned NOT NULL DEFAULT '0',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exit_requests_tenant_id_foreign` (`tenant_id`),
  KEY `exit_requests_employee_id_foreign` (`employee_id`),
  KEY `exit_requests_approved_by_foreign` (`approved_by`),
  CONSTRAINT `exit_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exit_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exit_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exit_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exit_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `exit_request_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assignee_role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exit_tasks_tenant_id_foreign` (`tenant_id`),
  KEY `exit_tasks_exit_request_id_foreign` (`exit_request_id`),
  KEY `exit_tasks_assigned_user_id_foreign` (`assigned_user_id`),
  CONSTRAINT `exit_tasks_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exit_tasks_exit_request_id_foreign` FOREIGN KEY (`exit_request_id`) REFERENCES `exit_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exit_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `category` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `spent_on` date NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note` text COLLATE utf8mb4_unicode_ci,
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expenses_tenant_id_foreign` (`tenant_id`),
  KEY `expenses_employee_id_foreign` (`employee_id`),
  KEY `expenses_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `expenses_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expenses_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `phase` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `features_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `followup_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `followup_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `owner_employee_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `university` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `follow_up_on` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `followup_candidates_tenant_id_email_unique` (`tenant_id`,`email`),
  KEY `followup_candidates_owner_employee_id_foreign` (`owner_employee_id`),
  KEY `followup_candidates_created_by_foreign` (`created_by`),
  KEY `followup_candidates_updated_by_foreign` (`updated_by`),
  KEY `followup_candidates_tenant_id_owner_employee_id_status_index` (`tenant_id`,`owner_employee_id`,`status`),
  CONSTRAINT `followup_candidates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `followup_candidates_owner_employee_id_foreign` FOREIGN KEY (`owner_employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `followup_candidates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `followup_candidates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `followup_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `followup_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `candidate_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `followup_events_candidate_id_foreign` (`candidate_id`),
  KEY `followup_events_actor_id_foreign` (`actor_id`),
  KEY `followup_events_tenant_id_candidate_id_index` (`tenant_id`,`candidate_id`),
  CONSTRAINT `followup_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `followup_events_candidate_id_foreign` FOREIGN KEY (`candidate_id`) REFERENCES `followup_candidates` (`id`),
  CONSTRAINT `followup_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `followup_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `followup_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `candidate_id` bigint unsigned NOT NULL,
  `remind_at` datetime NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_by` bigint unsigned DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `followup_reminders_candidate_id_foreign` (`candidate_id`),
  KEY `followup_reminders_created_by_foreign` (`created_by`),
  KEY `followup_reminders_completed_by_foreign` (`completed_by`),
  KEY `followup_reminders_tenant_id_status_remind_at_index` (`tenant_id`,`status`,`remind_at`),
  CONSTRAINT `followup_reminders_candidate_id_foreign` FOREIGN KEY (`candidate_id`) REFERENCES `followup_candidates` (`id`),
  CONSTRAINT `followup_reminders_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `followup_reminders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `followup_reminders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `generated_letters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `generated_letters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `letter_template_id` bigint unsigned DEFAULT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `generated_letters_tenant_id_foreign` (`tenant_id`),
  KEY `generated_letters_employee_id_foreign` (`employee_id`),
  KEY `generated_letters_letter_template_id_foreign` (`letter_template_id`),
  KEY `generated_letters_generated_by_foreign` (`generated_by`),
  CONSTRAINT `generated_letters_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `generated_letters_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `generated_letters_letter_template_id_foreign` FOREIGN KEY (`letter_template_id`) REFERENCES `letter_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `generated_letters_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gps_attendance_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gps_attendance_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `notice_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acknowledged_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gps_ack_employee_version` (`tenant_id`,`employee_id`,`notice_version`),
  KEY `gps_attendance_acknowledgements_employee_id_foreign` (`employee_id`),
  KEY `gps_attendance_acknowledgements_user_id_foreign` (`user_id`),
  CONSTRAINT `gps_attendance_acknowledgements_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `gps_attendance_acknowledgements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `gps_attendance_acknowledgements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gratuity_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gratuity_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `min_years` tinyint unsigned NOT NULL DEFAULT '5',
  `days_per_year` tinyint unsigned NOT NULL DEFAULT '15',
  `divisor_days` tinyint unsigned NOT NULL DEFAULT '26',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gratuity_policies_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `gratuity_policies_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holiday_work_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `holiday_work_authorizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `work_date` date NOT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `holiday_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `authorized_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `holiday_work_employee_date_kind` (`employee_id`,`work_date`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `holidays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `calendar` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'india',
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `holidays_tenant_id_foreign` (`tenant_id`),
  KEY `holidays_location_id_foreign` (`location_id`),
  CONSTRAINT `holidays_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `holidays_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'company',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `publish_at` timestamp NULL DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `attachment_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_announcements_tenant_id_status_publish_at_index` (`tenant_id`,`status`,`publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_cases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `reference` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `candidate_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `candidate_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'awaiting_student',
  `student_submitted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_cases_tenant_id_reference_unique` (`tenant_id`,`reference`),
  KEY `i983_cases_created_by_foreign` (`created_by`),
  KEY `i983_cases_tenant_id_status_created_at_index` (`tenant_id`,`status`,`created_at`),
  CONSTRAINT `i983_cases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_cases_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_certifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `party` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `printed_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `certified_on` date DEFAULT NULL,
  `signature_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_certifications_case_id_party_unique` (`case_id`,`party`),
  KEY `i983_certifications_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `i983_certifications_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_certifications_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_corrections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `requested_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `i983_corrections_tenant_id_foreign` (`tenant_id`),
  KEY `i983_corrections_requested_by_foreign` (`requested_by`),
  KEY `i983_corrections_case_id_resolved_at_index` (`case_id`,`resolved_at`),
  CONSTRAINT `i983_corrections_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_corrections_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_corrections_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` int unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `i983_documents_case_id_foreign` (`case_id`),
  KEY `i983_documents_created_by_foreign` (`created_by`),
  KEY `i983_documents_tenant_id_case_id_index` (`tenant_id`,`case_id`),
  CONSTRAINT `i983_documents_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_documents_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_employers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_employers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `legal_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suite` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zip` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ein_ciphertext` text COLLATE utf8mb4_unicode_ci,
  `employee_count` int unsigned DEFAULT NULL,
  `naics` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opt_hours` tinyint unsigned DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `salary_frequency` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_compensation` text COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_employers_case_id_unique` (`case_id`),
  KEY `i983_employers_tenant_id_foreign` (`tenant_id`),
  KEY `i983_employers_updated_by_foreign` (`updated_by`),
  CONSTRAINT `i983_employers_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_employers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `i983_employers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_evaluations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `narrative` text COLLATE utf8mb4_unicode_ci,
  `student_signed_on` date DEFAULT NULL,
  `employer_signed_on` date DEFAULT NULL,
  `student_signature_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_signature_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `i983_evaluations_tenant_id_foreign` (`tenant_id`),
  KEY `i983_evaluations_created_by_foreign` (`created_by`),
  KEY `i983_evaluations_case_id_kind_completed_at_index` (`case_id`,`kind`,`completed_at`),
  CONSTRAINT `i983_evaluations_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_evaluations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_evaluations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `actor_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `i983_events_case_id_foreign` (`case_id`),
  KEY `i983_events_actor_id_foreign` (`actor_id`),
  KEY `i983_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `i983_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_events_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_field_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_field_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `field_name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_value` text COLLATE utf8mb4_unicode_ci,
  `new_value` text COLLATE utf8mb4_unicode_ci,
  `actor_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `i983_field_revisions_tenant_id_foreign` (`tenant_id`),
  KEY `i983_field_revisions_actor_id_foreign` (`actor_id`),
  KEY `i983_field_revisions_case_id_created_at_index` (`case_id`,`created_at`),
  CONSTRAINT `i983_field_revisions_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_field_revisions_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_field_revisions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_links_token_hash_unique` (`token_hash`),
  KEY `i983_links_tenant_id_foreign` (`tenant_id`),
  KEY `i983_links_created_by_foreign` (`created_by`),
  KEY `i983_links_case_id_expires_at_index` (`case_id`,`expires_at`),
  CONSTRAINT `i983_links_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_links_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `i983_links_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_signatories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_signatories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `signatory_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signatory_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_mime` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_width` int unsigned NOT NULL,
  `image_height` int unsigned NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_signatories_tenant_id_unique` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_student_certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_student_certifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `link_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `printed_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acknowledged` tinyint(1) NOT NULL,
  `statements_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `certified_on` date NOT NULL,
  `certified_at` timestamp NOT NULL,
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `upload_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_student_certifications_case_id_version_unique` (`case_id`,`version`),
  KEY `i983_student_certifications_signature_sha256_index` (`signature_sha256`),
  KEY `i983_student_certifications_upload_sha256_index` (`upload_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `surname` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `given_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `middle_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_recommending` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_degree` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sevis_school_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dso_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dso_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dso_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sevis_id_ciphertext` text COLLATE utf8mb4_unicode_ci,
  `opt_start` date DEFAULT NULL,
  `opt_end` date DEFAULT NULL,
  `major_cip` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `degree_level` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `degree_awarded_on` date DEFAULT NULL,
  `prior_degree` tinyint(1) DEFAULT NULL,
  `ead_ciphertext` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_students_case_id_unique` (`case_id`),
  KEY `i983_students_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `i983_students_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_students_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `i983_training_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `i983_training_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `case_id` bigint unsigned NOT NULL,
  `student_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_address` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_title` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `student_role` text COLLATE utf8mb4_unicode_ci,
  `goals` text COLLATE utf8mb4_unicode_ci,
  `oversight` text COLLATE utf8mb4_unicode_ci,
  `measures` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `i983_training_plans_case_id_unique` (`case_id`),
  KEY `i983_training_plans_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `i983_training_plans_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `i983_cases` (`id`),
  CONSTRAINT `i983_training_plans_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `allocated` decimal(5,1) NOT NULL DEFAULT '0.0',
  `used` decimal(5,1) NOT NULL DEFAULT '0.0',
  `pending` decimal(5,1) NOT NULL DEFAULT '0.0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_balances_employee_id_leave_type_id_year_unique` (`employee_id`,`leave_type_id`,`year`),
  KEY `leave_balances_tenant_id_foreign` (`tenant_id`),
  KEY `leave_balances_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `leave_balances_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_balances_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_balances_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accrual` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yearly',
  `applicable_after_days` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_policies_tenant_id_foreign` (`tenant_id`),
  KEY `leave_policies_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `leave_policies_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_policies_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_request_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_request_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `leave_request_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_request_events_tenant_id_foreign` (`tenant_id`),
  KEY `leave_request_events_user_id_foreign` (`user_id`),
  KEY `leave_request_events_leave_request_id_created_at_index` (`leave_request_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` decimal(5,1) NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewer_note` text COLLATE utf8mb4_unicode_ci,
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_requests_tenant_id_foreign` (`tenant_id`),
  KEY `leave_requests_employee_id_foreign` (`employee_id`),
  KEY `leave_requests_leave_type_id_foreign` (`leave_type_id`),
  KEY `leave_requests_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `leave_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `days_per_year` decimal(5,1) NOT NULL DEFAULT '0.0',
  `is_paid` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_types_tenant_id_code_unique` (`tenant_id`,`code`),
  CONSTRAINT `leave_types_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `letter_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `letter_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `letter_templates_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `letter_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_recoveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_recoveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `loan_id` bigint unsigned NOT NULL,
  `payroll_item_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `recovered_on` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_recoveries_tenant_id_foreign` (`tenant_id`),
  KEY `loan_recoveries_loan_id_foreign` (`loan_id`),
  KEY `loan_recoveries_payroll_item_id_foreign` (`payroll_item_id`),
  CONSTRAINT `loan_recoveries_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_recoveries_payroll_item_id_foreign` FOREIGN KEY (`payroll_item_id`) REFERENCES `payroll_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_recoveries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'loan',
  `principal` decimal(12,2) NOT NULL,
  `balance` decimal(12,2) NOT NULL,
  `emi` decimal(12,2) NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `disbursed_on` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loans_tenant_id_foreign` (`tenant_id`),
  KEY `loans_employee_id_foreign` (`employee_id`),
  CONSTRAINT `loans_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loans_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `location_networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_networks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `cidr` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `location_networks_location_id_cidr_unique` (`location_id`,`cidr`),
  KEY `location_networks_tenant_id_foreign` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `ip_address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `verification_radius_meters` int unsigned NOT NULL DEFAULT '150',
  `gps_verification_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `map_label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `locations_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `locations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_permissions_permission_id_foreign` (`permission_id`),
  KEY `model_has_permissions_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letter_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letter_corrections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `offer_letter_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_values` json DEFAULT NULL,
  `updated_values` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offer_letter_corrections_tenant_id_offer_letter_id_index` (`tenant_id`,`offer_letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letter_downloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letter_downloads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `offer_letter_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offer_letter_downloads_offer_letter_id_foreign` (`offer_letter_id`),
  KEY `offer_letter_downloads_user_id_foreign` (`user_id`),
  KEY `offer_letter_downloads_tenant_id_offer_letter_id_index` (`tenant_id`,`offer_letter_id`),
  CONSTRAINT `offer_letter_downloads_offer_letter_id_foreign` FOREIGN KEY (`offer_letter_id`) REFERENCES `offer_letters` (`id`),
  CONSTRAINT `offer_letter_downloads_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `offer_letter_downloads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letter_template_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letter_template_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `country` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `letterhead_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint unsigned DEFAULT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offer_letter_template_versions_template_id_version_unique` (`template_id`,`version`),
  KEY `offer_letter_template_versions_letterhead_id_foreign` (`letterhead_id`),
  KEY `offer_letter_template_versions_created_by_foreign` (`created_by`),
  KEY `offer_letter_template_versions_submitted_by_foreign` (`submitted_by`),
  KEY `offer_letter_template_versions_approved_by_foreign` (`approved_by`),
  KEY `offer_letter_template_versions_tenant_id_country_status_index` (`tenant_id`,`country`,`status`),
  CONSTRAINT `offer_letter_template_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `offer_letter_template_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `offer_letter_template_versions_letterhead_id_foreign` FOREIGN KEY (`letterhead_id`) REFERENCES `offer_letterheads` (`id`),
  CONSTRAINT `offer_letter_template_versions_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `offer_letter_template_versions_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `offer_letter_templates` (`id`),
  CONSTRAINT `offer_letter_template_versions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letter_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letter_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `country` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_version` int unsigned NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offer_letter_templates_created_by_foreign` (`created_by`),
  KEY `offer_letter_templates_tenant_id_country_status_index` (`tenant_id`,`country`,`status`),
  CONSTRAINT `offer_letter_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `offer_letter_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letterheads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letterheads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `country` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL,
  `source_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `background_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `page_size` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'A4',
  `margin_top` smallint unsigned NOT NULL DEFAULT '120',
  `margin_right` smallint unsigned NOT NULL DEFAULT '48',
  `margin_bottom` smallint unsigned NOT NULL DEFAULT '56',
  `margin_left` smallint unsigned NOT NULL DEFAULT '48',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `page_width` decimal(8,2) DEFAULT NULL,
  `page_height` decimal(8,2) DEFAULT NULL,
  `header_height` smallint unsigned DEFAULT NULL,
  `footer_height` smallint unsigned DEFAULT NULL,
  `printed_legal_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `printed_address` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offer_letterheads_tenant_id_country_version_unique` (`tenant_id`,`country`,`version`),
  KEY `offer_letterheads_uploaded_by_foreign` (`uploaded_by`),
  KEY `offer_letterheads_tenant_id_country_status_index` (`tenant_id`,`country`,`status`),
  CONSTRAINT `offer_letterheads_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `offer_letterheads_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_letters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_letters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned DEFAULT NULL,
  `position_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `employee_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monthly_salary` decimal(12,2) NOT NULL,
  `annual_ctc` decimal(12,2) NOT NULL,
  `joining_date` date NOT NULL,
  `notice_months` tinyint unsigned NOT NULL DEFAULT '3',
  `hr_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hr_signature` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `version` int unsigned NOT NULL DEFAULT '1',
  `preview_body` text COLLATE utf8mb4_unicode_ci,
  `issued_body` text COLLATE utf8mb4_unicode_ci,
  `issued_values` text COLLATE utf8mb4_unicode_ci,
  `issued_at` timestamp NULL DEFAULT NULL,
  `issued_by` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replaces_letter_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `country` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'india',
  `reference_code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_version_id` bigint unsigned DEFAULT NULL,
  `employee_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compensation_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hourly_rate` decimal(12,2) DEFAULT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `hra` decimal(12,2) DEFAULT NULL,
  `special_allowance` decimal(12,2) DEFAULT NULL,
  `probation_months` tinyint unsigned DEFAULT NULL,
  `department_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_location` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employment_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `work_mode` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `working_hours` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notice_days` smallint unsigned DEFAULT NULL,
  `date_of_issue` date DEFAULT NULL,
  `designation_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reporting_manager_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employee_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offer_notes` text COLLATE utf8mb4_unicode_ci,
  `salutation` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candidate_address` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `offer_letters_tenant_id_employee_id_index` (`tenant_id`,`employee_id`),
  KEY `offer_letters_tenant_id_country_status_index` (`tenant_id`,`country`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `onboarding_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `assignee_role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onboarding_tasks_tenant_id_foreign` (`tenant_id`),
  KEY `onboarding_tasks_onboarding_id_foreign` (`onboarding_id`),
  KEY `onboarding_tasks_assigned_user_id_foreign` (`assigned_user_id`),
  CONSTRAINT `onboarding_tasks_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onboarding_tasks_onboarding_id_foreign` FOREIGN KEY (`onboarding_id`) REFERENCES `onboardings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onboarding_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_template_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_template_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `onboarding_template_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `assignee_role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_days` smallint unsigned NOT NULL DEFAULT '3',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onboarding_template_tasks_tenant_id_foreign` (`tenant_id`),
  KEY `onboarding_template_tasks_onboarding_template_id_foreign` (`onboarding_template_id`),
  CONSTRAINT `onboarding_template_tasks_onboarding_template_id_foreign` FOREIGN KEY (`onboarding_template_id`) REFERENCES `onboarding_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onboarding_template_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onboarding_templates_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `onboarding_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboardings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboardings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `onboarding_template_id` bigint unsigned DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `welcomed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `onboardings_employee_id_unique` (`employee_id`),
  KEY `onboardings_tenant_id_foreign` (`tenant_id`),
  KEY `onboardings_onboarding_template_id_foreign` (`onboarding_template_id`),
  CONSTRAINT `onboardings_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onboardings_onboarding_template_id_foreign` FOREIGN KEY (`onboarding_template_id`) REFERENCES `onboarding_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onboardings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `overtime_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `overtime_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `attendance_log_id` bigint unsigned DEFAULT NULL,
  `work_date` date NOT NULL,
  `minutes` smallint unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `overtime_entries_tenant_id_foreign` (`tenant_id`),
  KEY `overtime_entries_employee_id_foreign` (`employee_id`),
  KEY `overtime_entries_attendance_log_id_foreign` (`attendance_log_id`),
  KEY `overtime_entries_approved_by_foreign` (`approved_by`),
  CONSTRAINT `overtime_entries_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `overtime_entries_attendance_log_id_foreign` FOREIGN KEY (`attendance_log_id`) REFERENCES `attendance_logs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `overtime_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `overtime_entries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `overtime_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `overtime_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `overtime_entry_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `overtime_events_overtime_entry_id_foreign` (`overtime_entry_id`),
  KEY `overtime_events_actor_id_foreign` (`actor_id`),
  KEY `overtime_events_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `overtime_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `overtime_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `threshold_minutes` smallint unsigned NOT NULL DEFAULT '30',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `overtime_rules_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `overtime_rules_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `payroll_run_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_events_tenant_id_foreign` (`tenant_id`),
  KEY `payroll_events_user_id_foreign` (`user_id`),
  KEY `payroll_events_payroll_run_id_created_at_index` (`payroll_run_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `payroll_run_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `basic` decimal(12,2) NOT NULL DEFAULT '0.00',
  `hra` decimal(12,2) NOT NULL DEFAULT '0.00',
  `special_allowance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `working_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `present_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `leave_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `wfh_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `lop_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `overtime_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reimbursement` decimal(12,2) NOT NULL DEFAULT '0.00',
  `perk_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gratuity_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `lop_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pf_employee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pf_employer` decimal(12,2) NOT NULL DEFAULT '0.00',
  `esi_employee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `esi_employer` decimal(12,2) NOT NULL DEFAULT '0.00',
  `lwf` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tds` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loan_recovery` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gross` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_earnings` decimal(12,2) DEFAULT NULL,
  `professional_tax` decimal(12,2) DEFAULT NULL,
  `other_deductions` decimal(12,2) DEFAULT NULL,
  `payslip_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payslip_error` text COLLATE utf8mb4_unicode_ci,
  `import_supplied` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_items_run_employee_unique` (`payroll_run_id`,`employee_id`),
  KEY `payroll_items_tenant_id_foreign` (`tenant_id`),
  KEY `payroll_items_employee_id_foreign` (`employee_id`),
  CONSTRAINT `payroll_items_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_items_payroll_run_id_foreign` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `payroll_item_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_on` date DEFAULT NULL,
  `method` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_payments_payroll_item_id_unique` (`payroll_item_id`),
  KEY `payroll_payments_tenant_id_foreign` (`tenant_id`),
  KEY `payroll_payments_recorded_by_foreign` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `month` tinyint unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `calculated_by` bigint unsigned DEFAULT NULL,
  `processed_by` bigint unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `calculated_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_note` text COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `warnings` json DEFAULT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calculated',
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` bigint unsigned DEFAULT NULL,
  `payslip_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_rows` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_runs_tenant_id_year_month_unique` (`tenant_id`,`year`,`month`),
  KEY `payroll_runs_processed_by_foreign` (`processed_by`),
  KEY `payroll_runs_calculated_by_foreign` (`calculated_by`),
  KEY `payroll_runs_reviewed_by_foreign` (`reviewed_by`),
  KEY `payroll_runs_approved_by_foreign` (`approved_by`),
  CONSTRAINT `payroll_runs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_runs_calculated_by_foreign` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_runs_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_runs_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `perk_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `perk_claims` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `perk_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payroll_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `perk_claims_tenant_id_foreign` (`tenant_id`),
  KEY `perk_claims_perk_id_foreign` (`perk_id`),
  KEY `perk_claims_employee_id_foreign` (`employee_id`),
  CONSTRAINT `perk_claims_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `perk_claims_perk_id_foreign` FOREIGN KEY (`perk_id`) REFERENCES `perks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `perk_claims_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `perks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `perks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `annual_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `perks_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `perks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plan_feature`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_feature` (
  `plan_id` bigint unsigned NOT NULL,
  `feature_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`plan_id`,`feature_id`),
  KEY `plan_feature_feature_id_foreign` (`feature_id`),
  CONSTRAINT `plan_feature_feature_id_foreign` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_feature_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `poll_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `poll_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `poll_id` bigint unsigned NOT NULL,
  `label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `poll_options_tenant_id_foreign` (`tenant_id`),
  KEY `poll_options_poll_id_foreign` (`poll_id`),
  CONSTRAINT `poll_options_poll_id_foreign` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_options_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `poll_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `poll_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `poll_id` bigint unsigned NOT NULL,
  `poll_option_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `poll_votes_poll_id_user_id_unique` (`poll_id`,`user_id`),
  KEY `poll_votes_tenant_id_foreign` (`tenant_id`),
  KEY `poll_votes_poll_option_id_foreign` (`poll_option_id`),
  KEY `poll_votes_user_id_foreign` (`user_id`),
  CONSTRAINT `poll_votes_poll_id_foreign` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_votes_poll_option_id_foreign` FOREIGN KEY (`poll_option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_votes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_votes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `polls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `polls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `question` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `closes_on` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `polls_tenant_id_foreign` (`tenant_id`),
  KEY `polls_employee_id_foreign` (`employee_id`),
  CONSTRAINT `polls_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `polls_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `praised_employee_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `posts_tenant_id_foreign` (`tenant_id`),
  KEY `posts_employee_id_foreign` (`employee_id`),
  KEY `posts_praised_employee_id_foreign` (`praised_employee_id`),
  CONSTRAINT `posts_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `posts_praised_employee_id_foreign` FOREIGN KEY (`praised_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `posts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_assignment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_assignment_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `subject_user_id` bigint unsigned DEFAULT NULL,
  `role_name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_assignment_events_actor_id_foreign` (`actor_id`),
  KEY `role_assignment_events_subject_user_id_foreign` (`subject_user_id`),
  KEY `role_assignment_events_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_tenant_id_name_guard_name_unique` (`tenant_id`,`name`,`guard_name`),
  KEY `roles_team_foreign_key_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `salary_structure_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_structure_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `salary_structure_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effective_from` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `salary_structure_events_tenant_id_foreign` (`tenant_id`),
  KEY `salary_structure_events_user_id_foreign` (`user_id`),
  KEY `salary_structure_events_salary_structure_id_created_at_index` (`salary_structure_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `salary_structures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_structures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `basic` decimal(12,2) NOT NULL,
  `hra` decimal(12,2) NOT NULL DEFAULT '0.00',
  `special_allowance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `effective_from` date NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `salary_structures_employee_id_effective_from_unique` (`employee_id`,`effective_from`),
  KEY `salary_structures_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `salary_structures_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `salary_structures_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shift_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `shift_id` bigint unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shift_assignments_tenant_id_foreign` (`tenant_id`),
  KEY `shift_assignments_employee_id_foreign` (`employee_id`),
  KEY `shift_assignments_shift_id_foreign` (`shift_id`),
  CONSTRAINT `shift_assignments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_assignments_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_assignments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shifts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `expected_minutes` smallint unsigned DEFAULT NULL,
  `grace_minutes` smallint unsigned NOT NULL DEFAULT '10',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shifts_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `shifts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statutory_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `statutory_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `pf_wage_ceiling` decimal(12,2) NOT NULL DEFAULT '15000.00',
  `pf_employee_rate` decimal(6,4) NOT NULL DEFAULT '0.1200',
  `pf_employer_rate` decimal(6,4) NOT NULL DEFAULT '0.1200',
  `esi_wage_ceiling` decimal(12,2) NOT NULL DEFAULT '21000.00',
  `esi_employee_rate` decimal(6,4) NOT NULL DEFAULT '0.0075',
  `esi_employer_rate` decimal(6,4) NOT NULL DEFAULT '0.0325',
  `lwf_amount` decimal(12,2) NOT NULL DEFAULT '20.00',
  `standard_deduction` decimal(12,2) NOT NULL DEFAULT '75000.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `statutory_settings_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `statutory_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_tenant_id_foreign` (`tenant_id`),
  KEY `subscriptions_plan_id_foreign` (`plan_id`),
  CONSTRAINT `subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `subscriptions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_declarations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_declarations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `financial_year` smallint unsigned NOT NULL,
  `regime` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `section` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `declared_amount` decimal(12,2) NOT NULL,
  `approved_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `proof_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tax_declarations_tenant_id_foreign` (`tenant_id`),
  KEY `tax_declarations_employee_id_foreign` (`employee_id`),
  CONSTRAINT `tax_declarations_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tax_declarations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `logo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Kolkata',
  `week_off` json NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `offer_approval_required` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `ticket_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_comments_tenant_id_foreign` (`tenant_id`),
  KEY `ticket_comments_user_id_foreign` (`user_id`),
  KEY `ticket_comments_ticket_id_created_at_index` (`ticket_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `ticket_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_events_ticket_id_foreign` (`ticket_id`),
  KEY `ticket_events_actor_id_foreign` (`actor_id`),
  KEY `ticket_events_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `assignee_id` bigint unsigned DEFAULT NULL,
  `category` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `priority` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `attachment_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tickets_tenant_id_foreign` (`tenant_id`),
  KEY `tickets_employee_id_foreign` (`employee_id`),
  KEY `tickets_assignee_id_foreign` (`assignee_id`),
  CONSTRAINT `tickets_assignee_id_foreign` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usa_offer_designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usa_offer_designations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `designation_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_key` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsibilities` json NOT NULL,
  `factory_responsibilities` json NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_builtin` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usa_offer_designations_tenant_id_designation_key_unique` (`tenant_id`,`designation_key`),
  UNIQUE KEY `usa_offer_designations_tenant_id_name_unique` (`tenant_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usa_offer_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usa_offer_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `company_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_line` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city_line` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_line` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `e_verify` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `federal_id` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hr_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hr_title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hr_phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `letterhead_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `letterhead_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usa_offer_profiles_tenant_id_unique` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usa_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usa_offers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `designation_id` bigint unsigned DEFAULT NULL,
  `designation_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsibilities` json NOT NULL,
  `candidate_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `candidate_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candidate_phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candidate_address` text COLLATE utf8mb4_unicode_ci,
  `pay_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pay_amount` decimal(12,2) NOT NULL,
  `working_hours` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_arrangement` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `university` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `issue_date` date NOT NULL,
  `profile_snapshot` json DEFAULT NULL,
  `template_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `employment_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usa_offers_tenant_id_status_index` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `is_platform_admin` tinyint(1) NOT NULL DEFAULT '0',
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invite_token` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invited_at` timestamp NULL DEFAULT NULL,
  `invite_accepted_at` timestamp NULL DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `api_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_expires_at` timestamp NULL DEFAULT NULL,
  `invite_sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_invite_token_unique` (`invite_token`),
  UNIQUE KEY `users_api_token_unique` (`api_token`),
  KEY `users_tenant_id_index` (`tenant_id`),
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wfh_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wfh_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `work_date` date NOT NULL,
  `reason` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wfh_requests_employee_id_work_date_unique` (`employee_id`,`work_date`),
  KEY `wfh_requests_tenant_id_foreign` (`tenant_id`),
  KEY `wfh_requests_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `wfh_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wfh_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wfh_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_task_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_task_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_task_events_task_id_foreign` (`task_id`),
  KEY `work_task_events_actor_id_foreign` (`actor_id`),
  KEY `work_task_events_tenant_id_task_id_index` (`tenant_id`,`task_id`),
  CONSTRAINT `work_task_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `work_task_events_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `work_tasks` (`id`),
  CONSTRAINT `work_task_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `assignee_employee_id` bigint unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `start_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `expected_on` date DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `created_by` bigint unsigned DEFAULT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_tasks_assignee_employee_id_foreign` (`assignee_employee_id`),
  KEY `work_tasks_created_by_foreign` (`created_by`),
  KEY `work_tasks_assigned_by_foreign` (`assigned_by`),
  KEY `work_tasks_completed_by_foreign` (`completed_by`),
  KEY `work_tasks_tenant_id_assignee_employee_id_status_index` (`tenant_id`,`assignee_employee_id`,`status`),
  KEY `work_tasks_tenant_id_due_on_index` (`tenant_id`,`due_on`),
  CONSTRAINT `work_tasks_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `work_tasks_assignee_employee_id_foreign` FOREIGN KEY (`assignee_employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `work_tasks_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `work_tasks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `work_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_10_19_023112_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_10_19_100000_create_hrms_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_10_19_120000_create_remaining_phase_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_10_19_130000_add_holiday_calendars',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_10_19_140000_add_office_wifi_and_wfh',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_10_19_140000_add_payroll_day_counts',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_10_19_160000_add_profile_details_and_tickets',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_10_19_200000_add_office_networks_and_attendance_breaks',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_10_19_210000_add_holiday_status_and_leave_events',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_10_19_220000_add_payroll_workflow_tables',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_10_19_230000_add_role_assignment_events',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_10_19_240000_add_company_payroll_settings',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_10_19_250000_add_help_desk_overtime_and_audit_tables',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_10_19_260000_point_ticket_assignee_at_users',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_10_19_270000_convert_workflow_tables_to_innodb',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_10_20_160000_extend_employee_onboarding_documents',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_10_20_180000_add_offer_letters',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_10_20_200000_add_employee_record_event_note',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_10_21_150000_add_office_location_verification',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_10_22_120000_add_route_map_gps_capture',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_10_20_120000_add_company_lifecycle_events',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_10_20_140000_add_company_admin_invitations',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_10_21_120000_repair_company_lifecycle_subject_email',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_10_23_200000_add_candidate_onboarding',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_10_30_140000_store_attendance_verification_coordinates',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_10_30_160000_add_offer_letter_templates',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_10_30_180000_add_followups_tasks_and_birthdays',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_10_30_200000_add_appointment_letter_layout_and_signatures',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_10_30_210000_scope_legal_profiles_by_country',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_10_30_220000_add_offer_letter_notice_days_and_snapshots',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_11_02_120000_add_offer_approval_setting',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_11_02_180000_add_offer_salutation_and_letterhead_confirmation',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_11_02_200000_add_payroll_import_snapshots',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_11_02_210000_add_employee_invitation_expiry',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_11_02_220000_add_attendance_corrections',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_11_02_230000_add_letterhead_identity_and_offer_corrections',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_11_02_240000_add_hr_announcements',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_11_02_250000_add_document_catalog',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_11_02_260000_add_usa_offer_designations',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_11_02_270000_add_usa_offer_stationery',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_11_02_280000_add_i983_cases',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_11_02_290000_add_i983_corrections',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_11_02_300000_add_i983_document_versions',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_11_02_310000_add_i983_signatures',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_11_03_120000_add_offer_candidate_address_and_usa_employment_type',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_11_03_160000_add_i983_student_certifications',41);
