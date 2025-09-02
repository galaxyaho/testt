/*
  # Complete Auto Checkout System for L.P.S.T Hotel

  1. New Tables
    - `auto_checkout_logs` - Logs all auto checkout activities
    - `system_settings` - System configuration for auto checkout
    - Updates to `bookings` table for auto checkout support

  2. Security
    - Proper indexes for performance
    - Error handling and logging

  3. Features
    - Daily auto checkout at configurable time
    - Manual testing capability
    - Comprehensive logging
    - Proper timezone handling
*/

-- Create auto_checkout_logs table
CREATE TABLE IF NOT EXISTS `auto_checkout_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) UNSIGNED DEFAULT NULL,
  `resource_id` int(11) UNSIGNED NOT NULL,
  `resource_name` varchar(100) NOT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `checkout_date` date NOT NULL,
  `checkout_time` time NOT NULL,
  `status` enum('success','failed') DEFAULT 'success',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checkout_date` (`checkout_date`),
  KEY `idx_resource` (`resource_id`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create system_settings table
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto checkout columns to bookings table if they don't exist
ALTER TABLE `bookings` 
ADD COLUMN IF NOT EXISTS `auto_checkout_processed` tinyint(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `actual_checkout_date` date DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `actual_checkout_time` time DEFAULT NULL;

-- Add index for auto checkout performance
ALTER TABLE `bookings` 
ADD INDEX IF NOT EXISTS `idx_bookings_auto_checkout` (`status`, `auto_checkout_processed`, `check_in`);

-- Insert default auto checkout settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '30', 'Grace period in minutes before auto checkout'),
('auto_checkout_rate_room', '100', 'Hourly rate for rooms in auto checkout'),
('auto_checkout_rate_hall', '500', 'Hourly rate for halls in auto checkout'),
('manual_checkout_enabled', '1', 'Allow manual checkout testing'),
('debug_mode', '1', 'Enable debug logging for auto checkout')
ON DUPLICATE KEY UPDATE 
`setting_value` = CASE 
  WHEN `setting_key` = 'auto_checkout_enabled' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_time' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'timezone' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'checkout_grace_minutes' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_rate_room' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_rate_hall' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'manual_checkout_enabled' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'debug_mode' AND `setting_value` = '' THEN VALUES(`setting_value`)
  ELSE `setting_value`
END;

-- Create activity_logs table if not exists
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_type` varchar(50) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial activity log
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'Enhanced auto checkout system initialized successfully');