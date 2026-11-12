-- =====================================================================
-- Klinikids — MySQL / MariaDB şeması (kendi sunucuda çalışan sürüm)
-- Kurulum sayfası (/api/kurulum) bu dosyayı otomatik çalıştırır.
-- Tekrar çalıştırmak zararsızdır (CREATE TABLE IF NOT EXISTS).
-- Zamanlar UTC olarak saklanır; arayüz Europe/Istanbul'a çevirir.
-- =====================================================================

CREATE TABLE IF NOT EXISTS users (
  id                   CHAR(36)     NOT NULL PRIMARY KEY,
  email                VARCHAR(255) NOT NULL UNIQUE,
  password_hash        VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  last_sign_in_at      DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token_hash  CHAR(64)  NOT NULL PRIMARY KEY,
  user_id     CHAR(36)  NOT NULL,
  created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME  NOT NULL,
  ip          VARCHAR(45) NULL,
  INDEX (user_id),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id      BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket  VARCHAR(64)  NOT NULL,
  ip      VARCHAR(45)  NOT NULL,
  at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (bucket, ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_settings (
  id                 TINYINT(1)   NOT NULL PRIMARY KEY DEFAULT 1,
  clinic_name        VARCHAR(255) NOT NULL DEFAULT 'Diş Kliniği',
  timezone           VARCHAR(64)  NOT NULL DEFAULT 'Europe/Istanbul',
  slot_step_minutes  INT NOT NULL DEFAULT 15  CHECK (slot_step_minutes BETWEEN 5 AND 120),
  min_notice_minutes INT NOT NULL DEFAULT 120 CHECK (min_notice_minutes >= 0),
  max_days_ahead     INT NOT NULL DEFAULT 60  CHECK (max_days_ahead BETWEEN 1 AND 365),
  auto_confirm       TINYINT(1) NOT NULL DEFAULT 0,
  CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO clinic_settings (id) VALUES (1);

-- Kliniğin genel çalışma günleri/saatleri (1 = Pazartesi … 7 = Pazar). Hekim saatleri bunun içinde kalır.
CREATE TABLE IF NOT EXISTS clinic_hours (
  weekday    TINYINT    NOT NULL PRIMARY KEY CHECK (weekday BETWEEN 1 AND 7),
  is_open    TINYINT(1) NOT NULL DEFAULT 1,
  open_time  TIME       NOT NULL DEFAULT '09:00:00',
  close_time TIME       NOT NULL DEFAULT '18:00:00',
  CHECK (open_time < close_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO clinic_hours (weekday, is_open, open_time, close_time) VALUES
  (1,1,'09:00:00','18:00:00'),(2,1,'09:00:00','18:00:00'),(3,1,'09:00:00','18:00:00'),(4,1,'09:00:00','18:00:00'),
  (5,1,'09:00:00','18:00:00'),(6,0,'10:00:00','17:00:00'),(7,0,'10:00:00','17:00:00');

-- Kliniğin tamamen kapalı olduğu günler (resmi tatil, bayram vb.) — tarihler dahil
CREATE TABLE IF NOT EXISTS clinic_closures (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  starts_on  DATE         NOT NULL,
  ends_on    DATE         NOT NULL,
  reason     VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (starts_on <= ends_on),
  INDEX (starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctors (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  full_name  VARCHAR(255) NOT NULL,
  title      VARCHAR(64)  NULL,
  specialty  VARCHAR(255) NULL,
  bio        TEXT         NULL,
  photo_url  TEXT         NULL,
  color      VARCHAR(16)  NOT NULL DEFAULT '#0EA5E9',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  clinic     VARCHAR(16)  NOT NULL DEFAULT 'ikisi' CHECK (clinic IN ('cocuk','yetiskin','ikisi')),
  profile    LONGTEXT     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id               CHAR(36)     NOT NULL PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  description      TEXT         NULL,
  duration_minutes INT          NOT NULL CHECK (duration_minutes BETWEEN 5 AND 480),
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  clinic           VARCHAR(16)  NOT NULL DEFAULT 'ikisi' CHECK (clinic IN ('cocuk','yetiskin','ikisi'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctor_services (
  doctor_id  CHAR(36) NOT NULL,
  service_id CHAR(36) NOT NULL,
  PRIMARY KEY (doctor_id, service_id),
  CONSTRAINT fk_ds_doc FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ds_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_profiles (
  user_id   CHAR(36)     NOT NULL PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  role      ENUM('admin','receptionist','doctor') NOT NULL,
  doctor_id CHAR(36)     NULL,
  CONSTRAINT fk_sp_user FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_sp_doc  FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS working_hours (
  id         CHAR(36) NOT NULL PRIMARY KEY,
  doctor_id  CHAR(36) NOT NULL,
  weekday    TINYINT  NOT NULL CHECK (weekday BETWEEN 1 AND 7),
  start_time TIME     NOT NULL,
  end_time   TIME     NOT NULL,
  CHECK (start_time < end_time),
  INDEX (doctor_id, weekday),
  CONSTRAINT fk_wh_doc FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_off (
  id         CHAR(36) NOT NULL PRIMARY KEY,
  doctor_id  CHAR(36) NOT NULL,
  starts_at  DATETIME NOT NULL,
  ends_at    DATETIME NOT NULL,
  reason     VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (starts_at < ends_at),
  INDEX (doctor_id, starts_at),
  CONSTRAINT fk_to_doc FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patients (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  full_name       VARCHAR(255) NOT NULL,
  phone           VARCHAR(20)  NOT NULL UNIQUE,
  email           VARCHAR(255) NULL,
  kvkk_consent_at DATETIME     NULL,
  notes           TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
  id                  CHAR(36) NOT NULL PRIMARY KEY,
  doctor_id           CHAR(36) NOT NULL,
  service_id          CHAR(36) NULL,
  custom_service_name VARCHAR(255) NULL,
  patient_id          CHAR(36) NOT NULL,
  starts_at           DATETIME NOT NULL,
  ends_at             DATETIME NOT NULL,
  status              ENUM('pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
  source              ENUM('online','phone','walk_in') NOT NULL DEFAULT 'phone',
  patient_note        TEXT NULL,
  staff_note          TEXT NULL,
  cancel_token        CHAR(36) NOT NULL UNIQUE,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (starts_at < ends_at),
  INDEX (doctor_id, starts_at),
  INDEX (patient_id),
  CONSTRAINT fk_ap_doc FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_ap_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ap_pat FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institutions (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  logo_path  VARCHAR(255) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS myths (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  clinic     VARCHAR(16) NOT NULL CHECK (clinic IN ('cocuk','yetiskin')),
  emoji      VARCHAR(16) NULL,
  myth       TEXT        NOT NULL,
  truth      TEXT        NOT NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active  TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (clinic, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctor_certificates (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  doctor_id  CHAR(36)     NOT NULL,
  title      VARCHAR(255) NOT NULL,
  issuer     VARCHAR(255) NULL,
  year       INT          NULL CHECK (year IS NULL OR year BETWEEN 1950 AND 2100),
  file_path  VARCHAR(255) NOT NULL,
  file_type  VARCHAR(8)   NOT NULL DEFAULT 'image' CHECK (file_type IN ('image','pdf')),
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (doctor_id, sort_order),
  CONSTRAINT fk_dc_doc FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  clinic     VARCHAR(16) NOT NULL CHECK (clinic IN ('cocuk','yetiskin')),
  question   TEXT        NOT NULL,
  answer     TEXT        NOT NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active  TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  clinic     VARCHAR(16) NOT NULL DEFAULT 'ikisi' CHECK (clinic IN ('cocuk','yetiskin','ikisi')),
  url        TEXT        NULL,
  file_path  VARCHAR(255) NULL,
  caption    VARCHAR(255) NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active  TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (url IS NOT NULL OR file_path IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_sections (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  slug       VARCHAR(64)  NULL UNIQUE,
  title      VARCHAR(255) NOT NULL,
  body       LONGTEXT     NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
