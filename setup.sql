-- ============================================================
-- E-Library System — Database Setup
-- Run this file once in phpMyAdmin (Import tab) or MySQL CLI.
-- ============================================================

-- InfinityFree/phpMyAdmin note:
--   On shared hosting you usually CAN'T create databases from SQL.
--   1) Create/select your database in the hosting control panel/phpMyAdmin.
--   2) Then import this file.
--
-- This script is safe to import multiple times:
--   - Tables are dropped (in FK-safe order)
--   - Then recreated
--   - Seeds use INSERT IGNORE

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Drop existing tables (FK-safe order)
DROP TABLE IF EXISTS tbl_reservations;
DROP TABLE IF EXISTS tbl_borrow;
DROP TABLE IF EXISTS tbl_books;
DROP TABLE IF EXISTS tbl_login;
DROP TABLE IF EXISTS tbl_adminreg;

-- ---- Admin accounts ----
CREATE TABLE IF NOT EXISTS tbl_adminreg (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  acct_name   VARCHAR(100) NOT NULL,
  gender      VARCHAR(10)  NOT NULL,
  username    VARCHAR(50)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Student / Staff accounts ----
CREATE TABLE IF NOT EXISTS tbl_login (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  acct_name     VARCHAR(100) NOT NULL,
  gender        VARCHAR(10)  NOT NULL,
  email         VARCHAR(191) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  role          ENUM('student','teacher') NOT NULL DEFAULT 'student',
  borrow_limit  INT NOT NULL DEFAULT 3,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verify_token_hash VARCHAR(64) DEFAULT NULL,
  email_verify_expires DATETIME DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Book catalogue ----
CREATE TABLE IF NOT EXISTS tbl_books (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  author      VARCHAR(150) NOT NULL,
  category    VARCHAR(100) NOT NULL DEFAULT 'General',
  isbn        VARCHAR(30)  DEFAULT NULL,
  quantity    INT          NOT NULL DEFAULT 1,
  description TEXT         DEFAULT NULL,
  cover_image VARCHAR(500) DEFAULT NULL,
  publisher   VARCHAR(200) DEFAULT NULL,
  pub_year    VARCHAR(10)  DEFAULT NULL,
  added_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Borrow / return ledger ----
CREATE TABLE IF NOT EXISTS tbl_borrow (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    INT NOT NULL,
  book_id       INT NOT NULL,
  borrow_date   DATETIME DEFAULT CURRENT_TIMESTAMP,
  return_date   DATETIME DEFAULT NULL,
  -- Status lifecycle:
  --   Pending Pickup (student requested) -> Borrowed (admin confirmed issue) -> Returned (admin confirmed return)
  status        ENUM('Pending Pickup','Borrowed','Returned') NOT NULL DEFAULT 'Pending Pickup',
	issue_otp_hash CHAR(64) DEFAULT NULL,
	issue_otp_expires DATETIME DEFAULT NULL,
	issue_confirmed TINYINT(1) NOT NULL DEFAULT 0,
	return_requested TINYINT(1) NOT NULL DEFAULT 0,
	return_otp_hash CHAR(64) DEFAULT NULL,
	return_otp_expires DATETIME DEFAULT NULL,
  FOREIGN KEY (student_id) REFERENCES tbl_login(id)  ON DELETE CASCADE,
  FOREIGN KEY (book_id)    REFERENCES tbl_books(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Reservations (hold / pickup) ----
-- Student can reserve a book even if it's currently unavailable (or to hold a copy).
-- Admin can mark it Ready for Pickup; student can pick up anytime (then it becomes a real borrow).
CREATE TABLE IF NOT EXISTS tbl_reservations (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  student_id       INT NOT NULL,
  book_id          INT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status           ENUM('Requested','Ready','Borrowed','Cancelled','Expired') NOT NULL DEFAULT 'Requested',
  ready_at         DATETIME DEFAULT NULL,
  ready_expires_at DATETIME DEFAULT NULL,
  pickup_confirmed_at DATETIME DEFAULT NULL,
  pickup_confirm_token_hash CHAR(64) DEFAULT NULL,
  pickup_confirm_token_expires DATETIME DEFAULT NULL,
  borrow_id        INT DEFAULT NULL,
  -- Note: we enforce “one active reservation per student+book” in application logic
  -- because MySQL doesn't support partial unique indexes.
  UNIQUE KEY uq_res_id (id),
  INDEX idx_res_student_book (student_id, book_id),
  INDEX idx_res_status (status),
  INDEX idx_res_ready_expires (ready_expires_at),
  INDEX idx_res_borrow_id (borrow_id),
  FOREIGN KEY (student_id) REFERENCES tbl_login(id) ON DELETE CASCADE,
  FOREIGN KEY (book_id) REFERENCES tbl_books(id) ON DELETE CASCADE,
  FOREIGN KEY (borrow_id) REFERENCES tbl_borrow(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Seed: default admin account (admin / admin123) ----
INSERT IGNORE INTO tbl_adminreg (acct_name, gender, username, password)
VALUES ('System Admin', 'Male', 'admin', 'admin123');

-- ---- Seed: sample books ----
INSERT IGNORE INTO tbl_books (id, title, author, category, isbn, quantity, description, cover_image, publisher, pub_year) VALUES
(1,'Introduction to Computing',   'John Smith',       'Technology',    '978-0-13-110362-7', 5, 'A beginner-friendly introduction to computer science fundamentals.', NULL, 'Pearson', '2020'),
(2,'Data Structures & Algorithms','Maria Garcia',     'Technology',    '978-0-262-03384-8', 3, 'Covers arrays, linked lists, trees, graphs, and common algorithms.', NULL, 'MIT Press', '2019'),
(3,'Philippine History',          'Jose Ramos',       'History',       '978-971-23-4567-8', 4, 'A comprehensive look at Philippine history from pre-colonial times to modern era.', NULL, 'Rex Book Store', '2018'),
(4,'English Grammar Essentials',  'Anna Cruz',        'Language',      '978-0-19-431132-0', 6, 'Master the rules of English grammar with clear examples and exercises.', NULL, 'Oxford University Press', '2021'),
(5,'Calculus Made Easy',          'Silvanus Thompson', 'Mathematics',  '978-0-312-18548-0', 3, 'Classic introduction to calculus for beginners.', NULL, 'St. Martin''s Press', '2014'),
(6,'General Biology',             'Elena Santos',      'Science',      '978-0-321-55823-7', 4, 'Covers cell biology, genetics, evolution, and ecology.', NULL, 'Pearson', '2022'),
(7,'Understanding Psychology',    'Robert Tan',        'Social Science','978-0-07-803520-3', 5, 'Introduction to key psychological concepts and theories.', NULL, 'McGraw-Hill', '2020'),
(8,'Creative Writing 101',        'Lisa Reyes',        'Language',     '978-0-14-028637-3', 3, 'Develop your creative writing skills through practical exercises.', NULL, 'Penguin', '2017'),
(9,'World Literature Anthology',  'Various Authors',   'Literature',   '978-0-393-91965-3', 2, 'A curated collection of influential works from around the globe.', NULL, 'W. W. Norton', '2019'),
(10,'Fundamentals of Accounting',  'Mark Lopez',        'Business',     '978-0-13-408926-1', 4, 'Learn the basics of financial and managerial accounting.', NULL, 'Pearson', '2021');
