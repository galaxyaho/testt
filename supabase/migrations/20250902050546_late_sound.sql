/*
  # Complete Auto Checkout System Setup

  1. New Tables
    - `auto_checkout_logs` - Logs all auto checkout activities
    - `system_settings` - System configuration for auto checkout
    - Updates to `bookings` table for auto checkout support

  2. Security
    - No RLS needed for internal system tables
    - Proper indexes for performance

  3. Features
    - Daily auto checkout at configurable time
    - Manual testing capability
    - Comprehensive logging
    - Proper timezone handling
*/

-- Create auto_checkout_logs table if not exists
CREATE TABLE IF NOT EXISTS auto_checkout_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NULL,
  resource_id INT UNSIGNED NOT NULL,
  resource_name VARCHAR(100) NOT NULL,
  guest_name VARCHAR(100) NULL,
  checkout_date DATE NOT NULL,
  checkout_time TIME NOT NULL,
  status ENUM('success','failed') DEFAULT 'success',
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_checkout_date (checkout_date),
  INDEX idx_resource (resource_id),
  INDEX idx_booking (booking_id),
  INDEX idx_status (status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create system_settings table if not exists
CREATE TABLE IF NOT EXISTS system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto checkout columns to bookings table if they don't exist
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'auto_checkout_processed'
  ) THEN
    ALTER TABLE bookings ADD COLUMN auto_checkout_processed TINYINT(1) DEFAULT 0;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'actual_checkout_date'
  ) THEN
    ALTER TABLE bookings ADD COLUMN actual_checkout_date DATE NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'actual_checkout_time'
  ) THEN
    ALTER TABLE bookings ADD COLUMN actual_checkout_time TIME NULL;
  END IF;
END $$;

-- Insert default auto checkout settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '30', 'Grace period in minutes before auto checkout'),
('auto_checkout_rate_room', '100', 'Hourly rate for rooms in auto checkout'),
('auto_checkout_rate_hall', '500', 'Hourly rate for halls in auto checkout')
ON DUPLICATE KEY UPDATE 
setting_value = CASE 
  WHEN setting_key = 'auto_checkout_enabled' AND setting_value = '' THEN VALUES(setting_value)
  WHEN setting_key = 'auto_checkout_time' AND setting_value = '' THEN VALUES(setting_value)
  WHEN setting_key = 'timezone' AND setting_value = '' THEN VALUES(setting_value)
  WHEN setting_key = 'checkout_grace_minutes' AND setting_value = '' THEN VALUES(setting_value)
  WHEN setting_key = 'auto_checkout_rate_room' AND setting_value = '' THEN VALUES(setting_value)
  WHEN setting_key = 'auto_checkout_rate_hall' AND setting_value = '' THEN VALUES(setting_value)
  ELSE setting_value
END;

-- Add indexes to bookings table for auto checkout performance
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_name = 'bookings' AND index_name = 'idx_bookings_auto_checkout'
  ) THEN
    ALTER TABLE bookings ADD INDEX idx_bookings_auto_checkout (status, auto_checkout_processed, check_in);
  END IF;
END $$;

-- Create activity_logs table if not exists (for system logging)
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  activity_type VARCHAR(50) NOT NULL,
  room_id INT NULL,
  guest_name VARCHAR(255) NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_type (activity_type),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial activity log
INSERT INTO activity_logs (activity_type, description) VALUES
('system', 'Enhanced auto checkout system initialized successfully');