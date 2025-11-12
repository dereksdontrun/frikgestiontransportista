CREATE TABLE IF NOT EXISTS `lafrips_frikgestiontransportista_carrier_rates` (
  `id_rate` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `id_carrier_reference` INT(10) NOT NULL,
  `carrier_name` VARCHAR(64) NOT NULL,
  `country_iso` CHAR(2) NOT NULL,
  `weight_min_kg` DECIMAL(10,3) NOT NULL,
  `weight_max_kg` DECIMAL(10,3) NOT NULL,
  `avg_price_eur` DECIMAL(10,2) NOT NULL,  
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `date_upd` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_band` (`carrier_name`,`country_iso`,`weight_min_kg`,`weight_max_kg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `lafrips_frikgestiontransportista_order_queue` (
  `id_queue` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `id_order` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
  `reason` VARCHAR(255) NULL,
  `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` DATETIME NULL,
  UNIQUE KEY `uniq_order` (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `lafrips_frikgestiontransportista_decision_log` (
  `id_log` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `id_order` INT UNSIGNED NOT NULL,
  `country_iso` CHAR(2) NOT NULL,
  `weight_kg` DECIMAL(10,3) NOT NULL,
  `total_paid_eur` DECIMAL(10,2) NOT NULL,
  `id_carrier_reference_before` INT(10) NOT NULL,
  `carrier_before` VARCHAR(64) NOT NULL,
  `id_carrier_reference_after` INT(10) NOT NULL,
  `carrier_after` VARCHAR(64) NOT NULL,
  `price_selected_eur` DECIMAL(10,2) NOT NULL,
  `engine` ENUM('rules','gpt') NOT NULL,
  `criteria` VARCHAR(255) NOT NULL,
  `explanations_json` MEDIUMTEXT NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;