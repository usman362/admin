-- migrate.sql
-- Run this on your existing database to add new features
-- Safe to run — uses IF NOT EXISTS and INSERT IGNORE

USE osint_dashboard;

-- -----------------------------------------------
-- 1. Users table (normal users, not admin)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    INDEX idx_email (email)
);

-- -----------------------------------------------
-- 2. Dynamic Risk Types table
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS risk_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(20) DEFAULT '#6b7280',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default risk types
INSERT IGNORE INTO risk_types (name, color) VALUES
('Security',     '#8b5cf6'),
('Financial',    '#f97316'),
('Supply Chain', '#06b6d4'),
('Geopolitical', '#ec4899'),
('Other',        '#6b7280');

-- -----------------------------------------------
-- 3. Dynamic Risk Levels table
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS risk_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(20) DEFAULT '#6b7280',
    sort_order INT DEFAULT 99,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default risk levels
INSERT IGNORE INTO risk_levels (name, color, sort_order) VALUES
('High',   '#ef4444', 1),
('Medium', '#f59e0b', 2),
('Low',    '#22c55e', 3);

-- -----------------------------------------------
-- 4. Change keywords/alerts risk_type and risk_level
--    from ENUM to VARCHAR so dynamic values work
-- -----------------------------------------------
ALTER TABLE keywords 
    MODIFY COLUMN risk_type VARCHAR(100) DEFAULT 'Security',
    MODIFY COLUMN risk_level VARCHAR(100) DEFAULT 'Medium';

ALTER TABLE alerts
    MODIFY COLUMN risk_type VARCHAR(100) DEFAULT 'Other',
    MODIFY COLUMN risk_level VARCHAR(100) DEFAULT 'Medium';
