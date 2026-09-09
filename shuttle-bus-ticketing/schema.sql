CREATE DATABASE IF NOT EXISTS shuttle_bus_db;
USE shuttle_bus_db;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  id_number VARCHAR(20) NULL,
  faculty VARCHAR(150) NULL,
  date_of_birth DATE NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed admin account: admin@example.com / admin123
-- Change this password immediately in any real deployment.
INSERT INTO users (name, email, password_hash, is_admin) VALUES
('Admin', 'admin@example.com', '$2y$10$HI3gLmyD4OGmfNLAGUIL8.eBhhKu5nzL7wTDws.6mUNO9V44kyM5q', 1);

CREATE TABLE routes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_name VARCHAR(100) NOT NULL,
  origin VARCHAR(100) NOT NULL,
  destination VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  total_seats INT NOT NULL DEFAULT 40,
  image_url VARCHAR(500) NULL
);

INSERT INTO routes (route_name, origin, destination, price, total_seats, image_url) VALUES
('Campus - City Centre Express', 'Main Campus', 'City Centre', 3.00, 40, '/uploads/sample-city-express.jpg'),
('Campus - LRT Shuttle', 'Main Campus', 'LRT Station', 2.00, 30, '/uploads/sample-lrt-shuttle.jpg'),
('Campus - Hostel Loop', 'Main Campus', 'Student Hostel', 0.00, 25, '/uploads/sample-hostel-loop.jpg');

-- A route (e.g. "Campus - City Centre Express") can run several times a day.
-- Each of those individual departure times is a "trip" - the actual
-- bookable unit. All trips of a route share the same bus/price/capacity
-- (those live on the route row), so a trip only needs to record when it
-- leaves.
CREATE TABLE trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_id INT NOT NULL,
  departure_time VARCHAR(20) NOT NULL,
  FOREIGN KEY (route_id) REFERENCES routes(id),
  UNIQUE KEY uniq_route_departure (route_id, departure_time)
);

INSERT INTO trips (route_id, departure_time) VALUES
(1, '08:00'),
(2, '09:30'),
(3, '17:30'),
(3, '19:00');

CREATE TABLE tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  trip_id INT NOT NULL,
  travel_date DATE NOT NULL,
  seat_quantity INT NOT NULL DEFAULT 1,
  total_price DECIMAL(10,2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  INDEX idx_tickets_travel_trip (travel_date, trip_id, seat_quantity),
  INDEX idx_tickets_user_trip_date (user_id, trip_id, travel_date)
);

CREATE TABLE testimonials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  route_id INT NOT NULL,
  comment TEXT NOT NULL,
  rating TINYINT NOT NULL DEFAULT 5,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (route_id) REFERENCES routes(id)
);

CREATE TABLE contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PHP sessions are stored here instead of on local disk, so that any EC2
-- instance behind an ALB/ASG can read a session written by a different
-- instance. See auth.php's DbSessionHandler.
CREATE TABLE sessions (
  id VARCHAR(128) PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  last_activity INT NOT NULL,
  INDEX idx_last_activity (last_activity)
);
