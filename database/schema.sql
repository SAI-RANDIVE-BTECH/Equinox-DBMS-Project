CREATE DATABASE IF NOT EXISTS equinox_dbms;
USE equinox_dbms;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS login_history;
DROP TABLE IF EXISTS user_reviews;
DROP TABLE IF EXISTS trust_history;
DROP TABLE IF EXISTS trust_scores;
DROP TABLE IF EXISTS service_contracts;
DROP TABLE IF EXISTS skill_listings;
DROP TABLE IF EXISTS pool_contributions;
DROP TABLE IF EXISTS community_pools;
DROP TABLE IF EXISTS transactions_master;
DROP TABLE IF EXISTS equinox_cards;
DROP TABLE IF EXISTS payment_audit_logs;
DROP TABLE IF EXISTS payment_orders;
DROP TABLE IF EXISTS wallets_equinox;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS user_kyc_docs;
DROP TABLE IF EXISTS users_profile;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users_profile (
  user_id CHAR(36) PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  avatar_url TEXT NULL,
  phone_number VARCHAR(15) NULL UNIQUE,
  date_of_birth DATE NOT NULL,
  kyc_status ENUM('PENDING', 'VERIFIED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
  trust_score INT NOT NULL DEFAULT 50,
  location_city VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_trust_score CHECK (trust_score BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE user_settings (
  user_id CHAR(36) PRIMARY KEY,
  theme ENUM('DARK', 'LIGHT') NOT NULL DEFAULT 'DARK',
  notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
  language VARCHAR(10) NOT NULL DEFAULT 'en',
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_kyc_docs (
  doc_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  doc_type VARCHAR(50) NOT NULL,
  doc_url TEXT NOT NULL,
  verified_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wallets_equinox (
  wallet_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL UNIQUE,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('ACTIVE', 'DORMANT', 'FROZEN') NOT NULL DEFAULT 'DORMANT',
  activated_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_wallet_balance CHECK (balance >= 0)
) ENGINE=InnoDB;

CREATE TABLE payment_orders (
  payment_order_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  payment_purpose ENUM('WALLET_ACTIVATION', 'CARD_ISSUANCE') NOT NULL,
  amount_inr DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  payment_status ENUM('PENDING', 'PAID', 'FAILED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
  fulfillment_status ENUM('PENDING', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'PENDING',
  gateway_order_id VARCHAR(60) NOT NULL UNIQUE,
  gateway_payment_id VARCHAR(60) NULL UNIQUE,
  gateway_signature VARCHAR(255) NULL,
  gateway_status VARCHAR(40) NULL,
  receipt VARCHAR(80) NOT NULL UNIQUE,
  resource_reference CHAR(36) NULL,
  metadata_json JSON NULL,
  failure_reason VARCHAR(255) NULL,
  paid_at TIMESTAMP NULL DEFAULT NULL,
  fulfilled_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_order_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_payment_amount_positive CHECK (amount_inr > 0)
) ENGINE=InnoDB;

CREATE TABLE payment_audit_logs (
  audit_id CHAR(36) PRIMARY KEY,
  payment_order_id CHAR(36) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  payload_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_audit_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(payment_order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE equinox_cards (
  card_id CHAR(36) PRIMARY KEY,
  wallet_id CHAR(36) NOT NULL,
  issued_via_payment_order_id CHAR(36) NULL,
  card_number VARCHAR(19) NOT NULL UNIQUE,
  cvv_hash VARCHAR(255) NOT NULL,
  expiry_date VARCHAR(5) NOT NULL,
  card_name VARCHAR(30) NOT NULL DEFAULT 'Primary',
  is_frozen TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_card_wallet FOREIGN KEY (wallet_id) REFERENCES wallets_equinox(wallet_id) ON DELETE CASCADE,
  CONSTRAINT fk_card_payment_order FOREIGN KEY (issued_via_payment_order_id) REFERENCES payment_orders(payment_order_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE transactions_master (
  transaction_id CHAR(36) PRIMARY KEY,
  sender_wallet CHAR(36) NULL,
  receiver_wallet CHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  transaction_type ENUM('P2P', 'DEPOSIT', 'POOL', 'CONTRACT', 'REFUND') NOT NULL,
  status ENUM('SUCCESS', 'FAILED', 'PENDING') NOT NULL DEFAULT 'SUCCESS',
  note TEXT NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  device_fingerprint VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_sender FOREIGN KEY (sender_wallet) REFERENCES wallets_equinox(wallet_id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_receiver FOREIGN KEY (receiver_wallet) REFERENCES wallets_equinox(wallet_id) ON DELETE CASCADE,
  CONSTRAINT chk_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE community_pools (
  pool_id CHAR(36) PRIMARY KEY,
  creator_id CHAR(36) NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  target_amount DECIMAL(12,2) NOT NULL,
  raised_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  deadline DATETIME NOT NULL,
  status ENUM('ACTIVE', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pool_creator FOREIGN KEY (creator_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_pool_target_positive CHECK (target_amount > 0)
) ENGINE=InnoDB;

CREATE TABLE pool_contributions (
  contrib_id CHAR(36) PRIMARY KEY,
  pool_id CHAR(36) NOT NULL,
  donor_wallet CHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contrib_pool FOREIGN KEY (pool_id) REFERENCES community_pools(pool_id) ON DELETE CASCADE,
  CONSTRAINT fk_contrib_wallet FOREIGN KEY (donor_wallet) REFERENCES wallets_equinox(wallet_id) ON DELETE CASCADE,
  CONSTRAINT chk_pool_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE skill_listings (
  skill_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  skill_name VARCHAR(50) NOT NULL,
  description TEXT NULL,
  rate_per_hour INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_skill_rate_positive CHECK (rate_per_hour > 0)
) ENGINE=InnoDB;

CREATE TABLE service_contracts (
  contract_id CHAR(36) PRIMARY KEY,
  provider_id CHAR(36) NOT NULL,
  consumer_id CHAR(36) NOT NULL,
  skill_id CHAR(36) NOT NULL,
  hours INT NOT NULL,
  total_eq DECIMAL(12,2) NOT NULL,
  status ENUM('OPEN', 'IN_PROGRESS', 'COMPLETED', 'DISPUTED') NOT NULL DEFAULT 'OPEN',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contract_provider FOREIGN KEY (provider_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_consumer FOREIGN KEY (consumer_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_skill FOREIGN KEY (skill_id) REFERENCES skill_listings(skill_id) ON DELETE CASCADE,
  CONSTRAINT chk_contract_hours_positive CHECK (hours > 0),
  CONSTRAINT chk_contract_total_positive CHECK (total_eq > 0)
) ENGINE=InnoDB;

CREATE TABLE trust_scores (
  user_id CHAR(36) PRIMARY KEY,
  current_score INT NOT NULL DEFAULT 50,
  total_sent INT NOT NULL DEFAULT 0,
  total_received INT NOT NULL DEFAULT 0,
  completed_contracts INT NOT NULL DEFAULT 0,
  last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trust_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_current_score CHECK (current_score BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE trust_history (
  log_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  score_delta INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trust_history_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_reviews (
  review_id CHAR(36) PRIMARY KEY,
  reviewer_id CHAR(36) NOT NULL,
  subject_id CHAR(36) NOT NULL,
  contract_id CHAR(36) NULL,
  rating INT NOT NULL,
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reviewer_user FOREIGN KEY (reviewer_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_subject_user FOREIGN KEY (subject_id) REFERENCES users_profile(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_review_contract FOREIGN KEY (contract_id) REFERENCES service_contracts(contract_id) ON DELETE SET NULL,
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  notif_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(100) NOT NULL,
  body TEXT NOT NULL,
  notif_type VARCHAR(30) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_history (
  log_id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  device_fingerprint VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  login_status VARCHAR(20) NOT NULL DEFAULT 'SUCCESS',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users_profile(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_wallet_user ON wallets_equinox(user_id);
CREATE INDEX idx_payment_user_status ON payment_orders(user_id, payment_status, created_at);
CREATE INDEX idx_payment_purpose_status ON payment_orders(payment_purpose, fulfillment_status, created_at);
CREATE INDEX idx_payment_gateway_order ON payment_orders(gateway_order_id);
CREATE INDEX idx_payment_gateway_payment ON payment_orders(gateway_payment_id);
CREATE INDEX idx_cards_wallet ON equinox_cards(wallet_id);
CREATE INDEX idx_txn_sender ON transactions_master(sender_wallet);
CREATE INDEX idx_txn_receiver ON transactions_master(receiver_wallet);
CREATE INDEX idx_txn_created ON transactions_master(created_at);
CREATE INDEX idx_notif_user ON notifications(user_id, is_read);
CREATE INDEX idx_login_user ON login_history(user_id, created_at);

DELIMITER $$

CREATE TRIGGER enforce_card_limit
BEFORE INSERT ON equinox_cards
FOR EACH ROW
BEGIN
  DECLARE card_count INT;
  SELECT COUNT(*) INTO card_count FROM equinox_cards WHERE wallet_id = NEW.wallet_id;
  IF card_count >= 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Maximum 3 cards allowed per wallet';
  END IF;
END$$

CREATE TRIGGER update_pool_raised
AFTER INSERT ON pool_contributions
FOR EACH ROW
BEGIN
  UPDATE community_pools
  SET raised_amount = raised_amount + NEW.amount
  WHERE pool_id = NEW.pool_id;
END$$

CREATE TRIGGER close_pool_on_target
BEFORE UPDATE ON community_pools
FOR EACH ROW
BEGIN
  IF NEW.raised_amount >= NEW.target_amount AND NEW.status = 'ACTIVE' THEN
    SET NEW.status = 'COMPLETED';
  END IF;
END$$

DROP PROCEDURE IF EXISTS recalculate_trust_score$$
CREATE PROCEDURE recalculate_trust_score(IN p_user_id CHAR(36))
BEGIN
  DECLARE v_sent INT DEFAULT 0;
  DECLARE v_received INT DEFAULT 0;
  DECLARE v_avg DECIMAL(10,2) DEFAULT 3.0;
  DECLARE v_contracts INT DEFAULT 0;
  DECLARE v_days INT DEFAULT 0;
  DECLARE v_score INT DEFAULT 50;
  DECLARE v_previous INT DEFAULT 50;

  SELECT COUNT(*) INTO v_sent
  FROM transactions_master t
  JOIN wallets_equinox w ON t.sender_wallet = w.wallet_id
  WHERE w.user_id = p_user_id AND t.status = 'SUCCESS';

  SELECT COUNT(*) INTO v_received
  FROM transactions_master t
  JOIN wallets_equinox w ON t.receiver_wallet = w.wallet_id
  WHERE w.user_id = p_user_id AND t.status = 'SUCCESS';

  SELECT COALESCE(AVG(rating), 3.0) INTO v_avg
  FROM user_reviews
  WHERE subject_id = p_user_id;

  SELECT COUNT(*) INTO v_contracts
  FROM service_contracts
  WHERE (provider_id = p_user_id OR consumer_id = p_user_id)
    AND status = 'COMPLETED';

  SELECT COALESCE(DATEDIFF(CURRENT_DATE, DATE(created_at)), 0) INTO v_days
  FROM users_profile
  WHERE user_id = p_user_id;

  SELECT COALESCE((SELECT current_score FROM trust_scores WHERE user_id = p_user_id), 50) INTO v_previous;

  SET v_score = LEAST(100, GREATEST(0, ROUND(
      LEAST(v_sent, 40) * 0.625 +
      LEAST(v_received, 40) * 0.375 +
      (v_avg / 5.0) * 35 +
      LEAST(v_contracts, 10) * 1.5 +
      LEAST(v_days / 365, 1) * 10
  )));

  INSERT INTO trust_scores (user_id, current_score, total_sent, total_received, completed_contracts, last_updated)
  VALUES (p_user_id, v_score, v_sent, v_received, v_contracts, CURRENT_TIMESTAMP)
  ON DUPLICATE KEY UPDATE
    current_score = VALUES(current_score),
    total_sent = VALUES(total_sent),
    total_received = VALUES(total_received),
    completed_contracts = VALUES(completed_contracts),
    last_updated = CURRENT_TIMESTAMP;

  UPDATE users_profile
  SET trust_score = v_score
  WHERE user_id = p_user_id;

  INSERT INTO trust_history (log_id, user_id, score_delta, reason)
  VALUES (UUID(), p_user_id, v_score - v_previous, 'Trust score recalculated');
END$$

CREATE TRIGGER recalc_trust_on_review
AFTER INSERT ON user_reviews
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(NEW.subject_id);
END$$

CREATE TRIGGER notify_on_transaction
AFTER INSERT ON transactions_master
FOR EACH ROW
BEGIN
  DECLARE receiver_user_id CHAR(36);

  SELECT user_id INTO receiver_user_id
  FROM wallets_equinox
  WHERE wallet_id = NEW.receiver_wallet;

  IF receiver_user_id IS NOT NULL THEN
    INSERT INTO notifications (notif_id, user_id, title, body, notif_type)
    VALUES (
      UUID(),
      receiver_user_id,
      CASE
        WHEN NEW.transaction_type = 'DEPOSIT' THEN 'Wallet Activated'
        WHEN NEW.transaction_type = 'CONTRACT' THEN 'Contract Settled'
        WHEN NEW.transaction_type = 'POOL' THEN 'Pool Contribution Received'
        ELSE 'Eq Received'
      END,
      CASE
        WHEN NEW.transaction_type = 'DEPOSIT' THEN 'Your wallet activation payment was fulfilled and 500 Eq has been credited.'
        WHEN NEW.transaction_type = 'CONTRACT' THEN CONCAT(NEW.amount, ' Eq settled for a completed service contract.')
        WHEN NEW.transaction_type = 'POOL' THEN CONCAT(NEW.amount, ' Eq was recorded against a community pool contribution.')
        ELSE CONCAT(NEW.amount, ' Eq has been received in your wallet.')
      END,
      'PAYMENT'
    );
  END IF;
END$$

DROP PROCEDURE IF EXISTS activate_wallet$$
CREATE PROCEDURE activate_wallet(
  IN p_user_id CHAR(36),
  IN p_latitude DECIMAL(9,6),
  IN p_longitude DECIMAL(9,6)
)
BEGIN
  DECLARE v_wallet_id CHAR(36);
  DECLARE v_status VARCHAR(20);
  DECLARE v_error TEXT DEFAULT 'Activation failed';

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
    ROLLBACK;
    SELECT 0 AS success, v_error AS error;
  END;

  START TRANSACTION;

  SELECT wallet_id, status INTO v_wallet_id, v_status
  FROM wallets_equinox
  WHERE user_id = p_user_id
  FOR UPDATE;

  IF v_wallet_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet not found';
  END IF;

  IF v_status = 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet already activated';
  END IF;

  UPDATE wallets_equinox
  SET balance = 500.00,
      status = 'ACTIVE',
      activated_at = CURRENT_TIMESTAMP,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = v_wallet_id;

  INSERT INTO transactions_master (
    transaction_id, sender_wallet, receiver_wallet, amount, transaction_type, status, note, latitude, longitude
  ) VALUES (
    UUID(), NULL, v_wallet_id, 500.00, 'DEPOSIT', 'SUCCESS',
    'Onboarding payment successful. 500 Eq credited after wallet activation.', p_latitude, p_longitude
  );

  INSERT INTO trust_scores (user_id, current_score, last_updated)
  VALUES (p_user_id, 50, CURRENT_TIMESTAMP)
  ON DUPLICATE KEY UPDATE last_updated = CURRENT_TIMESTAMP;

  COMMIT;

  SELECT 1 AS success, v_wallet_id AS wallet_id, 500.00 AS balance, 'Wallet activated' AS message;
END$$

DROP PROCEDURE IF EXISTS transfer_eq$$
CREATE PROCEDURE transfer_eq(
  IN p_sender_wallet CHAR(36),
  IN p_receiver_wallet CHAR(36),
  IN p_amount DECIMAL(12,2),
  IN p_note TEXT,
  IN p_latitude DECIMAL(9,6),
  IN p_longitude DECIMAL(9,6)
)
BEGIN
  DECLARE v_sender_balance DECIMAL(12,2);
  DECLARE v_sender_status VARCHAR(20);
  DECLARE v_receiver_status VARCHAR(20);
  DECLARE v_error TEXT DEFAULT 'Transfer failed';

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
    ROLLBACK;
    SELECT 0 AS success, v_error AS error;
  END;

  START TRANSACTION;

  SELECT balance, status INTO v_sender_balance, v_sender_status
  FROM wallets_equinox
  WHERE wallet_id = p_sender_wallet
  FOR UPDATE;

  SELECT status INTO v_receiver_status
  FROM wallets_equinox
  WHERE wallet_id = p_receiver_wallet
  FOR UPDATE;

  IF p_sender_wallet = p_receiver_wallet THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot transfer to your own wallet';
  END IF;

  IF p_amount <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amount must be positive';
  END IF;

  IF v_sender_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sender wallet not found';
  END IF;

  IF v_receiver_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receiver wallet not found';
  END IF;

  IF v_sender_status <> 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sender wallet is not active';
  END IF;

  IF v_receiver_status <> 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receiver wallet is not active';
  END IF;

  IF v_sender_balance < p_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient balance';
  END IF;

  UPDATE wallets_equinox
  SET balance = balance - p_amount,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = p_sender_wallet;

  UPDATE wallets_equinox
  SET balance = balance + p_amount,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = p_receiver_wallet;

  INSERT INTO transactions_master (
    transaction_id, sender_wallet, receiver_wallet, amount, transaction_type, status, note, latitude, longitude
  ) VALUES (
    UUID(), p_sender_wallet, p_receiver_wallet, p_amount, 'P2P', 'SUCCESS', p_note, p_latitude, p_longitude
  );

  COMMIT;

  SELECT 1 AS success, p_amount AS amount_transferred, 'Transfer complete' AS message;
END$$

DROP PROCEDURE IF EXISTS contribute_pool$$
CREATE PROCEDURE contribute_pool(
  IN p_donor_wallet CHAR(36),
  IN p_pool_id CHAR(36),
  IN p_amount DECIMAL(12,2)
)
BEGIN
  DECLARE v_balance DECIMAL(12,2);
  DECLARE v_status VARCHAR(20);
  DECLARE v_pool_status VARCHAR(20);
  DECLARE v_error TEXT DEFAULT 'Contribution failed';

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
    ROLLBACK;
    SELECT 0 AS success, v_error AS error;
  END;

  START TRANSACTION;

  SELECT balance, status INTO v_balance, v_status
  FROM wallets_equinox
  WHERE wallet_id = p_donor_wallet
  FOR UPDATE;

  SELECT status INTO v_pool_status
  FROM community_pools
  WHERE pool_id = p_pool_id
  FOR UPDATE;

  IF p_amount <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amount must be positive';
  END IF;

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet not found';
  END IF;

  IF v_pool_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pool not found';
  END IF;

  IF v_pool_status <> 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pool is not active';
  END IF;

  IF v_status <> 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet must be active';
  END IF;

  IF v_balance < p_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient balance';
  END IF;

  UPDATE wallets_equinox
  SET balance = balance - p_amount,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = p_donor_wallet;

  INSERT INTO pool_contributions (contrib_id, pool_id, donor_wallet, amount)
  VALUES (UUID(), p_pool_id, p_donor_wallet, p_amount);

  INSERT INTO transactions_master (
    transaction_id, sender_wallet, receiver_wallet, amount, transaction_type, status, note
  ) VALUES (
    UUID(), p_donor_wallet, p_donor_wallet, p_amount, 'POOL', 'SUCCESS',
    CONCAT('Contribution recorded for pool ', p_pool_id)
  );

  COMMIT;

  SELECT 1 AS success, p_amount AS amount_contributed, 'Pool contribution complete' AS message;
END$$

DROP PROCEDURE IF EXISTS settle_contract$$
CREATE PROCEDURE settle_contract(
  IN p_contract_id CHAR(36),
  IN p_consumer_wallet CHAR(36),
  IN p_provider_wallet CHAR(36)
)
BEGIN
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_status VARCHAR(20);
  DECLARE v_consumer_id CHAR(36);
  DECLARE v_provider_id CHAR(36);
  DECLARE v_consumer_wallet_owner CHAR(36);
  DECLARE v_provider_wallet_owner CHAR(36);
  DECLARE v_consumer_balance DECIMAL(12,2);
  DECLARE v_consumer_status VARCHAR(20);
  DECLARE v_provider_status VARCHAR(20);
  DECLARE v_error TEXT DEFAULT 'Contract settlement failed';

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
    ROLLBACK;
    SELECT 0 AS success, v_error AS error;
  END;

  START TRANSACTION;

  SELECT consumer_id, provider_id, total_eq, status
  INTO v_consumer_id, v_provider_id, v_total, v_status
  FROM service_contracts
  WHERE contract_id = p_contract_id
  FOR UPDATE;

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contract not found';
  END IF;

  IF v_status = 'COMPLETED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contract already settled';
  END IF;

  IF v_status = 'DISPUTED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disputed contract cannot be settled';
  END IF;

  SELECT user_id, balance, status INTO v_consumer_wallet_owner, v_consumer_balance, v_consumer_status
  FROM wallets_equinox
  WHERE wallet_id = p_consumer_wallet
  FOR UPDATE;

  SELECT user_id, status INTO v_provider_wallet_owner, v_provider_status
  FROM wallets_equinox
  WHERE wallet_id = p_provider_wallet
  FOR UPDATE;

  IF v_consumer_wallet_owner <> v_consumer_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consumer wallet mismatch';
  END IF;

  IF v_provider_wallet_owner <> v_provider_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider wallet mismatch';
  END IF;

  IF v_consumer_status <> 'ACTIVE' OR v_provider_status <> 'ACTIVE' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Both wallets must be active';
  END IF;

  IF v_consumer_balance < v_total THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consumer has insufficient balance';
  END IF;

  UPDATE wallets_equinox
  SET balance = balance - v_total,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = p_consumer_wallet;

  UPDATE wallets_equinox
  SET balance = balance + v_total,
      updated_at = CURRENT_TIMESTAMP
  WHERE wallet_id = p_provider_wallet;

  UPDATE service_contracts
  SET status = 'COMPLETED'
  WHERE contract_id = p_contract_id;

  INSERT INTO transactions_master (
    transaction_id, sender_wallet, receiver_wallet, amount, transaction_type, status, note
  ) VALUES (
    UUID(), p_consumer_wallet, p_provider_wallet, v_total, 'CONTRACT', 'SUCCESS',
    CONCAT('Contract settlement completed for contract ', p_contract_id)
  );

  CALL recalculate_trust_score(v_consumer_id);
  CALL recalculate_trust_score(v_provider_id);

  COMMIT;

  SELECT 1 AS success, v_total AS settled_amount, 'Contract settled successfully' AS message;
END$$

DELIMITER ;
