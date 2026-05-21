CREATE DATABASE IF NOT EXISTS joshi_enterprise;
USE joshi_enterprise;

-- USERS TABLE
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','salesman') NOT NULL
);

-- PRODUCTS TABLE
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  image VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SALES MAIN TABLE
CREATE TABLE sales (
  id BIGINT PRIMARY KEY,
  customer VARCHAR(100) NOT NULL,
  mobile VARCHAR(20) DEFAULT NULL,
  total DECIMAL(10,2) NOT NULL,
  date_time DATETIME NOT NULL,
  salesman VARCHAR(50) NOT NULL
);

-- SALES ITEMS TABLE
CREATE TABLE sale_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(100) NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
  CONSTRAINT fk_sale_product FOREIGN KEY (product_id) REFERENCES products(id)
);

-- USERS (same as frontend)
INSERT INTO users (username, password_hash, role) VALUES
('admin',    SHA2('admin123',256), 'admin'),
('salesman', SHA2('sales123',256), 'salesman'),
('pinak',    SHA2('123',256),      'salesman'),
('parth',    SHA2('123',256),      'salesman');
