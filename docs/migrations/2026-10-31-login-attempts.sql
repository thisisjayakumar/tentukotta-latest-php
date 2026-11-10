-- Login throttling (Task 11).
-- Run on staging/production before deploying the throttling code.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `username` VARCHAR(255) NOT NULL DEFAULT '',
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `login_attempts_ip_time` (`ip`, `attempted_at`),
  KEY `login_attempts_username_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
