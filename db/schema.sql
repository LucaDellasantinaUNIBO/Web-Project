SET FOREIGN_KEY_CHECKS = 0;
SET @tables_to_drop = (
    SELECT GROUP_CONCAT(CONCAT('`', table_name, '`'))
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
);
SET @drop_sql = IFNULL(CONCAT('DROP TABLE IF EXISTS ', @tables_to_drop), 'SELECT 1');
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    status ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    phone VARCHAR(30) DEFAULT NULL,
    wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    card_holder VARCHAR(120) DEFAULT NULL,
    card_last4 CHAR(4) DEFAULT NULL,
    card_expiry VARCHAR(7) DEFAULT NULL,
    card_fingerprint CHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    type ENUM('monolocale', 'bilocale', 'trilocale', 'posto_letto') NOT NULL,
    zone VARCHAR(120) NOT NULL,
    distance_km DECIMAL(4, 1) NOT NULL DEFAULT 0.0,
    price DECIMAL(8, 2) NOT NULL,
    deposit DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    size_sqm INT NOT NULL DEFAULT 0,
    beds INT NOT NULL DEFAULT 1,
    description TEXT NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('published', 'draft', 'archived') NOT NULL DEFAULT 'published',
    lat DECIMAL(10, 8) DEFAULT NULL,
    lng DECIMAL(11, 8) DEFAULT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_listing (user_id, listing_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
);

CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    type ENUM('visit', 'contact') NOT NULL DEFAULT 'visit',
    message TEXT NOT NULL,
    preferred_date DATE DEFAULT NULL,
    status ENUM('open', 'accepted', 'declined', 'closed') NOT NULL DEFAULT 'open',
    admin_notes TEXT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('topup', 'payment') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(200) NOT NULL,
    card_last4 CHAR(4) DEFAULT NULL,
    listing_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL
);

CREATE TABLE request_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    sender ENUM('user', 'admin') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

CREATE TABLE rentals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    months INT NOT NULL,
    monthly_cost DECIMAL(10, 2) NOT NULL,
    deposit DECIMAL(10, 2) NOT NULL,
    total_paid DECIMAL(10, 2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
);

CREATE TABLE change_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    entity VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id)
);
