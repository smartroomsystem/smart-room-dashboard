-- ============================================================
-- Smart Room Climate Control Database
-- ============================================================

CREATE DATABASE IF NOT EXISTS smart_room_db    
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE smart_room_db;

-- ============================================================
-- ACCOUNTS / ADMINS TABLE
-- ============================================================
-- Added 'role' column to differentiate accounts safely.
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE, -- Added UNIQUE to safeguard duplicate emails
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user', -- Tracks account access levels
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SENSOR HISTORY TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS sensor_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature DECIMAL(5,1) NOT NULL,
    fan_status ENUM('ON','OFF') NOT NULL,
    system_status ENUM('NORMAL','SAKTO','HIGH TEMP') NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- DEFAULT ADMIN ACCOUNT
-- ============================================================
-- Username: admin
-- Password: admin123
-- Role: admin
-- ============================================================

INSERT INTO admins (username, password, email, role)
VALUES (
    'admin',
    '$2y$12$uuRbTUxMeeZjF3/xPLJom.XsLJ.i8MZSdvgHr1r6sl7KshUJsQGoS',
    'your_email@gmail.com',
    'admin'
)
ON DUPLICATE KEY UPDATE
password = VALUES(password),
email = VALUES(email),
role = VALUES(role);

-- ============================================================
-- OTP TOKENS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS otp_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ACCOUNT ACTIVITY LOGS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS account_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;