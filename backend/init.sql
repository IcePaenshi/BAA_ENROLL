-- School Portal Database Schema
-- Roles: admin, registrar, student, parent
-- Subjects: English, Science, Mathematics, History, Bible
-- Fees: 38000-45000 yearly (monthly proportional)
-- Students: John (Grade 7), Maria (Grade 9)
-- Every student has a linked parent account with access to info and payables

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  class VARCHAR(50) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS parents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS student_parent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  parent_id INT NOT NULL,
  UNIQUE KEY (student_id, parent_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS student_subject (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  UNIQUE KEY (student_id, subject_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  grade INT NOT NULL,
  term VARCHAR(50),
  recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS fees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  monthly_fee DECIMAL(10, 2) NOT NULL,
  yearly_fee DECIMAL(10, 2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (student_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  amount DECIMAL(10, 2) NOT NULL,
  payment_type VARCHAR(20) NOT NULL,
  payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) DEFAULT 'completed',
  next_due_date DATE,
  set_by_user_id INT DEFAULT NULL,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (set_by_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(500) NOT NULL,
  expires_at INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Roles
INSERT INTO roles (name) VALUES ('admin') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO roles (name) VALUES ('registrar') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO roles (name) VALUES ('student') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO roles (name) VALUES ('parent') ON DUPLICATE KEY UPDATE name=name;

-- Users (admin, registrar, 2 students, 2 parents)
INSERT INTO users (username, password, role_id) VALUES ('admin', 'adminpass', (SELECT id FROM roles WHERE name='admin')) ON DUPLICATE KEY UPDATE username=username;
INSERT INTO users (username, password, role_id) VALUES ('registrar', 'registrarpass', (SELECT id FROM roles WHERE name='registrar')) ON DUPLICATE KEY UPDATE username=username;
INSERT INTO users (username, password, role_id) VALUES ('john_user', 'johnpass', (SELECT id FROM roles WHERE name='student')) ON DUPLICATE KEY UPDATE username=username;
INSERT INTO users (username, password, role_id) VALUES ('maria_user', 'mariapass', (SELECT id FROM roles WHERE name='student')) ON DUPLICATE KEY UPDATE username=username;
INSERT INTO users (username, password, role_id) VALUES ('john_parent_user', 'parentpass', (SELECT id FROM roles WHERE name='parent')) ON DUPLICATE KEY UPDATE username=username;
INSERT INTO users (username, password, role_id) VALUES ('maria_parent_user', 'parentpass', (SELECT id FROM roles WHERE name='parent')) ON DUPLICATE KEY UPDATE username=username;

-- Students: John (Grade 7), Maria (Grade 9)
INSERT INTO students (user_id, name, class) VALUES ((SELECT id FROM users WHERE username='john_user'), 'John Doe', 'Grade 7') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO students (user_id, name, class) VALUES ((SELECT id FROM users WHERE username='maria_user'), 'Maria Gomez', 'Grade 9') ON DUPLICATE KEY UPDATE name=name;

-- Parents: John's parent and Maria's parent
INSERT INTO parents (user_id, name, phone) VALUES ((SELECT id FROM users WHERE username='john_parent_user'), 'John Parent', '555-0100') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO parents (user_id, name, phone) VALUES ((SELECT id FROM users WHERE username='maria_parent_user'), 'Maria Parent', '555-0200') ON DUPLICATE KEY UPDATE name=name;

-- Link students to parents (each student has a parent)
INSERT INTO student_parent (student_id, parent_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM parents WHERE name='John Parent')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_parent (student_id, parent_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM parents WHERE name='Maria Parent')) ON DUPLICATE KEY UPDATE student_id=student_id;

-- Subjects: English, Science, Mathematics, History, Bible
INSERT INTO subjects (name) VALUES ('English') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO subjects (name) VALUES ('Science') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO subjects (name) VALUES ('Mathematics') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO subjects (name) VALUES ('History') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO subjects (name) VALUES ('Bible') ON DUPLICATE KEY UPDATE name=name;

-- Link John (Grade 7) to subjects
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='English')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='Mathematics')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='Science')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='Bible')) ON DUPLICATE KEY UPDATE student_id=student_id;

-- Link Maria (Grade 9) to subjects
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='English')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='Mathematics')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='History')) ON DUPLICATE KEY UPDATE student_id=student_id;
INSERT INTO student_subject (student_id, subject_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='Bible')) ON DUPLICATE KEY UPDATE student_id=student_id;

-- Sample grades (visible only after payment satisfied)
INSERT INTO grades (student_id, subject_id, grade, term) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='English'), 78, 'Term 1') ON DUPLICATE KEY UPDATE grade=grade;
INSERT INTO grades (student_id, subject_id, grade, term) VALUES ((SELECT id FROM students WHERE name='John Doe'), (SELECT id FROM subjects WHERE name='Mathematics'), 85, 'Term 1') ON DUPLICATE KEY UPDATE grade=grade;
INSERT INTO grades (student_id, subject_id, grade, term) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='Mathematics'), 88, 'Term 1') ON DUPLICATE KEY UPDATE grade=grade;
INSERT INTO grades (student_id, subject_id, grade, term) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), (SELECT id FROM subjects WHERE name='History'), 92, 'Term 1') ON DUPLICATE KEY UPDATE grade=grade;

-- Fees: Yearly 38000 and 45000 with proportional monthly
-- John: 38000 yearly = 3166.67 monthly
-- Maria: 45000 yearly = 3750.00 monthly
INSERT INTO fees (student_id, monthly_fee, yearly_fee) VALUES ((SELECT id FROM students WHERE name='John Doe'), 3166.67, 38000.00) ON DUPLICATE KEY UPDATE monthly_fee=monthly_fee, yearly_fee=yearly_fee;
INSERT INTO fees (student_id, monthly_fee, yearly_fee) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), 3750.00, 45000.00) ON DUPLICATE KEY UPDATE monthly_fee=monthly_fee, yearly_fee=yearly_fee;

-- Payments: Next due date set by admin/registrar
-- John: paid, next due in 30 days
INSERT INTO payments (student_id, amount, payment_type, status, next_due_date, set_by_user_id) VALUES ((SELECT id FROM students WHERE name='John Doe'), 3166.67, 'monthly', 'completed', DATE_ADD(CURDATE(), INTERVAL 30 DAY), (SELECT id FROM users WHERE username='registrar')) ON DUPLICATE KEY UPDATE payment_date=payment_date;

-- Maria: pending payment, due today
INSERT INTO payments (student_id, amount, payment_type, status, next_due_date, set_by_user_id) VALUES ((SELECT id FROM students WHERE name='Maria Gomez'), 0.00, 'monthly', 'pending', CURDATE(), (SELECT id FROM users WHERE username='registrar')) ON DUPLICATE KEY UPDATE payment_date=payment_date;
