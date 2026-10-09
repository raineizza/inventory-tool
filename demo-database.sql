-- Demo shop database for trying the Stock-In Scanner locally (XAMPP / phpMyAdmin).
-- Laid out like a typical Laravel shop: products with a model number and a stock count.
-- Import it in phpMyAdmin, or run:  mysql -u root < demo-database.sql

CREATE DATABASE IF NOT EXISTS inventory_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_demo;

CREATE TABLE IF NOT EXISTS brands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  model_number VARCHAR(255) NULL,
  description TEXT NULL,
  category VARCHAR(255) NULL,
  price DECIMAL(12,2) NULL,
  stock INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO brands (id, name) VALUES (1, 'EDWARDS'), (2, 'HIKVISION'), (3, 'KIDDE');

INSERT IGNORE INTO products (id, brand_id, name, model_number, description, category, price, stock, created_at, updated_at) VALUES
  (1001, 1, 'Intelligent Photoelectric Smoke Detector', 'SIGA-OSD', 'Addressable smoke detector', 'FDAS', 378.95, 12, NOW(), NOW()),
  (1002, 1, 'Intelligent Heat Detector', 'SIGA-HRD', 'Addressable heat detector', 'FDAS', 315.80, 8, NOW(), NOW()),
  (1003, 1, 'Detector Mounting Base', 'SIGA-SB', 'Standard detector base', 'FDAS', 95.00, 40, NOW(), NOW()),
  (1004, 1, 'Single Input Module', 'SIGA-CT1', 'Addressable input module', 'FDAS', 950.00, 5, NOW(), NOW()),
  (1005, 1, 'Manual Pull Station', 'SIGA-278', 'Double-action pull station', 'FDAS', 1250.00, 3, NOW(), NOW()),
  (1006, 3, 'Horn Strobe, Red', 'HS-24-R', 'Wall-mount horn strobe', 'FDAS', 1800.00, 0, NOW(), NOW()),
  (1007, 2, '2 MP Fixed Bullet Network Camera', 'DS-2CD1023G0E-I', 'IP camera, 2.8 mm', 'CCTV - CAMERAS', 2450.00, 6, NOW(), NOW()),
  (1008, 2, '4 MP Dome Network Camera', 'DS-2CD1143G0-I', 'IP dome camera, 2.8 mm', 'CCTV - CAMERAS', 3100.00, 4, NOW(), NOW()),
  (1009, 2, '4-ch PoE Network Video Recorder', 'DS-7104NI-Q1/4P', 'NVR with 4 PoE ports', 'CCTV - DVR/ NVR', 5990.00, 2, NOW(), NOW()),
  (1010, 2, '2 TB Surveillance Hard Drive', 'HS-SSD-2T', 'Surveillance HDD', 'CCTV - HDD', 4200.00, 10, NOW(), NOW()),
  (1011, 2, '8-port PoE Switch', 'DS-3E0109P-E', 'Unmanaged PoE switch', 'CCTV - CORE SWITCH', 3800.00, 7, NOW(), NOW()),
  (1012, 3, 'Fire Extinguisher Bracket', 'KX-BRKT', 'Wall bracket', 'Brackets', 150.00, 25, NOW(), NOW());
