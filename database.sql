-- OSINT Supplier Risk Dashboard Database Schema
-- Run this once to set up the database

CREATE DATABASE IF NOT EXISTS osint_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE osint_dashboard;

-- Alerts table
CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(255) NOT NULL,
    title TEXT NOT NULL,
    summary TEXT,
    supplier_id INT,
    supplier_name VARCHAR(255),
    risk_type ENUM('Security', 'Financial', 'Supply Chain', 'Geopolitical', 'Other') DEFAULT 'Other',
    risk_level ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
    alert_date DATETIME NOT NULL,
    link TEXT,
    keywords_matched TEXT,
    raw_data LONGTEXT,
    notified TINYINT(1) DEFAULT 0,
    dismissed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_risk_level (risk_level),
    INDEX idx_alert_date (alert_date),
    INDEX idx_supplier (supplier_id),
    INDEX idx_dismissed (dismissed)
);

-- Suppliers table
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    aliases TEXT COMMENT 'Comma-separated alternate names',
    category VARCHAR(100) DEFAULT 'General',
    country VARCHAR(100),
    criticality ENUM('Critical', 'High', 'Medium', 'Low') DEFAULT 'Medium',
    contact_email VARCHAR(255),
    notes TEXT,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Keywords table
CREATE TABLE IF NOT EXISTS keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(255) NOT NULL UNIQUE,
    risk_type ENUM('Security', 'Financial', 'Supply Chain', 'Geopolitical', 'Other') DEFAULT 'Security',
    risk_level ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sources table
CREATE TABLE IF NOT EXISTS sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('RSS', 'API', 'Scrape') DEFAULT 'RSS',
    url TEXT NOT NULL,
    active TINYINT(1) DEFAULT 1,
    last_fetched DATETIME,
    fetch_interval INT DEFAULT 60 COMMENT 'Minutes between fetches',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Cron log
CREATE TABLE IF NOT EXISTS cron_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    source_name VARCHAR(255),
    items_fetched INT DEFAULT 0,
    alerts_created INT DEFAULT 0,
    errors TEXT,
    duration_ms INT
);

-- -----------------------------------------------
-- Default Data
-- -----------------------------------------------

-- Default keywords
INSERT INTO keywords (keyword, risk_type, risk_level) VALUES
('breach', 'Security', 'High'),
('ransomware', 'Security', 'High'),
('cyberattack', 'Security', 'High'),
('data leak', 'Security', 'High'),
('vulnerability', 'Security', 'Medium'),
('exploit', 'Security', 'High'),
('zero-day', 'Security', 'High'),
('CVE', 'Security', 'Medium'),
('insolvency', 'Financial', 'High'),
('bankruptcy', 'Financial', 'High'),
('liquidation', 'Financial', 'High'),
('sanctions', 'Geopolitical', 'High'),
('export ban', 'Geopolitical', 'High'),
('trade restriction', 'Geopolitical', 'Medium'),
('supply disruption', 'Supply Chain', 'High'),
('shortage', 'Supply Chain', 'Medium'),
('recall', 'Supply Chain', 'Medium'),
('factory fire', 'Supply Chain', 'High'),
('shutdown', 'Supply Chain', 'Medium'),
('delay', 'Supply Chain', 'Low'),
('counterfeit', 'Supply Chain', 'High'),
('phishing', 'Security', 'Medium'),
('malware', 'Security', 'High'),
('DDoS', 'Security', 'Medium'),
('fraud', 'Financial', 'High')
ON DUPLICATE KEY UPDATE keyword=keyword;

-- Default sources
INSERT INTO sources (name, type, url, fetch_interval) VALUES
('CISA Alerts', 'RSS', 'https://www.cisa.gov/cybersecurity-advisories/all.xml', 30),
('NVD CVE Feed', 'RSS', 'https://nvd.nist.gov/feeds/xml/cve/misc/nvd-rss.xml', 60),
('Google News - Cybersecurity', 'RSS', 'https://news.google.com/rss/search?q=cybersecurity+breach+ransomware&hl=en-US&gl=US&ceid=US:en', 60),
('Google News - Supply Chain', 'RSS', 'https://news.google.com/rss/search?q=supply+chain+disruption+shortage&hl=en-US&gl=US&ceid=US:en', 60),
('Google News - Bankruptcy', 'RSS', 'https://news.google.com/rss/search?q=technology+company+bankruptcy+insolvency&hl=en-US&gl=US&ceid=US:en', 120),
('Bleeping Computer', 'RSS', 'https://www.bleepingcomputer.com/feed/', 60),
('Krebs on Security', 'RSS', 'https://krebsonsecurity.com/feed/', 60),
('SecurityWeek', 'RSS', 'https://feeds.feedburner.com/securityweek', 60),
('The Hacker News', 'RSS', 'https://feeds.feedburner.com/TheHackersNews', 60),
('US-CERT', 'RSS', 'https://www.us-cert.gov/ncas/all.xml', 30)
ON DUPLICATE KEY UPDATE name=name;

-- Default settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('email_notifications', '0', 'Enable email alerts for High risk items'),
('notification_email', '', 'Email address for alerts'),
('smtp_host', '', 'SMTP server host'),
('smtp_port', '587', 'SMTP port'),
('smtp_user', '', 'SMTP username'),
('smtp_pass', '', 'SMTP password'),
('alert_retention_days', '90', 'Days to keep alerts before auto-deletion'),
('min_risk_notify', 'High', 'Minimum risk level for email notifications'),
('dashboard_title', 'OSINT Supplier Risk Dashboard', 'Dashboard display title'),
('admin_password', '$2y$10$defaultHashedPasswordChangeThis', 'Admin panel password hash')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Default suppliers (sample)
INSERT INTO suppliers (name, aliases, category, criticality) VALUES
('Example Supplier Co', 'ESC, Example Corp', 'Electronics', 'High'),
('Global Components Ltd', 'GCL', 'Components', 'Medium'),
('Tech Parts Inc', 'TPI, TechParts', 'Hardware', 'Critical')
ON DUPLICATE KEY UPDATE name=name;
