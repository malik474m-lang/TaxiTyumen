-- ═══════════════════════════════════════════════════════════════════════════
-- TaxiTyumen / ServerHosting — схема MySQL 8 (совместимо с MariaDB 10.4+)
-- Порт схемы TaxiService (PostgreSQL/EF Core → MySQL/PDO)
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS users (
  id                CHAR(36) PRIMARY KEY,
  phone             VARCHAR(20)  NOT NULL UNIQUE,
  first_name        VARCHAR(60)  NOT NULL,
  last_name         VARCHAR(60)  NOT NULL,
  email             VARCHAR(120) NULL,
  password_hash     VARCHAR(255) NOT NULL,
  role              ENUM('client','driver','operator','admin') NOT NULL DEFAULT 'client',
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  is_blocked        TINYINT(1)   NOT NULL DEFAULT 0,
  block_reason      VARCHAR(255) NULL,
  rating            DOUBLE       NOT NULL DEFAULT 5,
  total_trips       INT          NOT NULL DEFAULT 0,
  is_phone_verified TINYINT(1)   NOT NULL DEFAULT 0,
  sms_code          VARCHAR(8)   NULL,
  sms_code_expiry   DATETIME     NULL,
  last_login_at     DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drivers (
  id                    CHAR(36) PRIMARY KEY,
  user_id               CHAR(36) NOT NULL UNIQUE,
  car_brand             VARCHAR(60) NOT NULL,
  car_model             VARCHAR(60) NOT NULL,
  car_color             VARCHAR(40) NOT NULL,
  license_plate         VARCHAR(20) NOT NULL,
  car_year              INT      NOT NULL DEFAULT 2020,
  driver_license        VARCHAR(50) NOT NULL DEFAULT '',
  is_verified           TINYINT(1) NOT NULL DEFAULT 0,
  status                ENUM('offline','available','on_route','in_trip','busy') NOT NULL DEFAULT 'offline',
  latitude              DOUBLE   NOT NULL DEFAULT 57.1522,
  longitude             DOUBLE   NOT NULL DEFAULT 65.5272,
  last_location_update  DATETIME NULL,
  completed_trips       INT      NOT NULL DEFAULT 0,
  cancelled_trips       INT      NOT NULL DEFAULT 0,
  total_earnings        DOUBLE   NOT NULL DEFAULT 0,
  today_earnings        DOUBLE   NOT NULL DEFAULT 0,
  balance               DOUBLE   NOT NULL DEFAULT 0,
  min_balance_for_orders DOUBLE  NOT NULL DEFAULT 100,
  rejection_penalty     DOUBLE   NOT NULL DEFAULT 0,
  current_order_id      CHAR(36) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tariffs (
  id                    CHAR(36) PRIMARY KEY,
  type                  ENUM('economy','comfort','business','minivan') NOT NULL UNIQUE,
  name                  VARCHAR(40)  NOT NULL,
  description           VARCHAR(200) NOT NULL DEFAULT '',
  base_fare             DOUBLE       NOT NULL,
  price_per_km          DOUBLE       NOT NULL,
  price_per_minute      DOUBLE       NOT NULL DEFAULT 0,
  minimum_fare          DOUBLE       NOT NULL,
  free_waiting_minutes  DOUBLE       NOT NULL DEFAULT 3,
  paid_waiting_per_minute DOUBLE     NOT NULL DEFAULT 0,
  night_multiplier      DOUBLE       NOT NULL DEFAULT 1,
  peak_multiplier       DOUBLE       NOT NULL DEFAULT 1,
  commission_percent    DOUBLE       NOT NULL DEFAULT 15,
  is_active             TINYINT(1)  NOT NULL DEFAULT 1,
  updated_at            DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id                   CHAR(36) PRIMARY KEY,
  order_number         VARCHAR(48) NOT NULL UNIQUE,
  client_id            CHAR(36) NULL,
  operator_id          CHAR(36) NULL,
  source               ENUM('client_app','operator_app') NOT NULL DEFAULT 'client_app',
  client_phone         VARCHAR(20) NULL,
  client_name          VARCHAR(100) NULL,
  driver_id            CHAR(36) NULL,
  pickup_address       VARCHAR(255) NOT NULL,
  pickup_latitude      DOUBLE NOT NULL,
  pickup_longitude     DOUBLE NOT NULL,
  pickup_entrance      VARCHAR(20) NULL,
  destination_address  VARCHAR(255) NULL,
  destination_latitude  DOUBLE NULL,
  destination_longitude DOUBLE NULL,
  tariff               ENUM('economy','comfort','business','minivan') NOT NULL DEFAULT 'economy',
  estimated_price      DOUBLE NOT NULL DEFAULT 0,
  final_price          DOUBLE NULL,
  estimated_distance   DOUBLE NULL,
  estimated_duration   INT NULL,
  route_geometry       MEDIUMTEXT NULL,
  payment_method       ENUM('cash','card','bonus') NOT NULL DEFAULT 'cash',
  status               ENUM('created','searching','driver_assigned','driver_en_route',
                            'driver_arrived','in_progress','completed','cancelled',
                            'no_driver_found') NOT NULL DEFAULT 'searching',
  escalated_at         DATETIME NULL,
  comment              VARCHAR(500) NULL,
  cancellation_reason  VARCHAR(255) NULL,
  passenger_count      INT NOT NULL DEFAULT 1,
  client_rating        INT NULL,
  driver_rating        INT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at          DATETIME NULL,
  driver_arrived_at    DATETIME NULL,
  trip_started_at      DATETIME NULL,
  completed_at         DATETIME NULL,
  cancelled_at         DATETIME NULL,
  INDEX (status), INDEX (client_id), INDEX (driver_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_options (
  id         CHAR(36) PRIMARY KEY,
  order_id   CHAR(36) NOT NULL,
  code       VARCHAR(40) NOT NULL,
  name       VARCHAR(100) NOT NULL,
  price      DOUBLE NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_rejections (
  id         CHAR(36) PRIMARY KEY,
  order_id   CHAR(36) NOT NULL,
  driver_id  CHAR(36) NOT NULL,
  reason     VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (order_id), INDEX (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balance_transactions (
  id            CHAR(36) PRIMARY KEY,
  driver_id     CHAR(36) NOT NULL,
  order_id      CHAR(36) NULL,
  type          ENUM('topup','commission','penalty') NOT NULL,
  amount        DOUBLE NOT NULL,
  balance_after DOUBLE NOT NULL,
  description   VARCHAR(200) NOT NULL DEFAULT '',
  created_by    VARCHAR(100) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id          CHAR(36) PRIMARY KEY,
  order_id    CHAR(36) NOT NULL,
  sender_id   CHAR(36) NOT NULL,
  sender_name VARCHAR(120) NOT NULL DEFAULT '',
  sender_role ENUM('client','driver','operator','admin') NOT NULL DEFAULT 'client',
  text        VARCHAR(1000) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operator_shifts (
  id          CHAR(36) PRIMARY KEY,
  operator_id CHAR(36) NOT NULL,
  started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at    DATETIME NULL,
  FOREIGN KEY (operator_id) REFERENCES users(id),
  INDEX (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operator_profiles (
  id             CHAR(36) PRIMARY KEY,
  user_id        CHAR(36) NOT NULL UNIQUE,
  scheme         ENUM('per_order','per_hour','per_day','fixed_monthly') NOT NULL DEFAULT 'per_order',
  rate_per_order DOUBLE NOT NULL DEFAULT 30,
  rate_per_hour  DOUBLE NOT NULL DEFAULT 150,
  rate_per_day   DOUBLE NOT NULL DEFAULT 1500,
  fixed_monthly  DOUBLE NOT NULL DEFAULT 30000,
  updated_at     DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auto_call_settings (
  id                      CHAR(36) PRIMARY KEY,
  enabled                 TINYINT(1) NOT NULL DEFAULT 1,
  escalate_after_minutes  INT NOT NULL DEFAULT 3,
  auto_assign_enabled     TINYINT(1) NOT NULL DEFAULT 0,
  auto_assign_radius_km   DOUBLE NOT NULL DEFAULT 5,
  last_tick_at            DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branding_settings (
  id                 CHAR(36) PRIMARY KEY,
  app                VARCHAR(20) NOT NULL UNIQUE,      -- client | driver | operator
  app_name           VARCHAR(60) NOT NULL,
  app_code           VARCHAR(60) NOT NULL,
  hero_title         VARCHAR(120) NOT NULL DEFAULT '',
  hero_subtitle      VARCHAR(300) NOT NULL DEFAULT '',
  logo_icon          VARCHAR(40) NOT NULL DEFAULT 'taxi',
  logo_path          VARCHAR(255) NULL,
  primary_color      VARCHAR(9) NOT NULL DEFAULT '#facc15',
  primary_text_color VARCHAR(9) NOT NULL DEFAULT '#0a0a0c',
  support_phone      VARCHAR(30) NULL,
  features           TEXT NOT NULL,
  updated_at         DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Дешёвый realtime-поллинг: клиенты спрашивают events.php?since=lastId
CREATE TABLE IF NOT EXISTS events (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  type       VARCHAR(20) NOT NULL,                      -- orders | drivers | chat | branding | autocall
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
