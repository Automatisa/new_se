-- Bulwark Core schema dump 2026-06-30
-- FreeBSD + MariaDB

CREATE DATABASE IF NOT EXISTS `bulwark_core` DEFAULT CHARACTER SET utf8mb3;
USE `bulwark_core`;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `x_accounts`;
CREATE TABLE `x_accounts` (
  `ac_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `ac_user_vc` varchar(50) DEFAULT NULL,
  `ac_pass_vc` varchar(255) DEFAULT NULL,
  `ac_email_vc` varchar(250) DEFAULT NULL,
  `ac_reseller_fk` int(6) DEFAULT NULL,
  `ac_package_fk` int(6) DEFAULT NULL,
  `ac_group_fk` int(6) DEFAULT NULL,
  `ac_usertheme_vc` varchar(45) DEFAULT NULL,
  `ac_usercss_vc` varchar(45) DEFAULT NULL,
  `ac_enabled_in` int(1) DEFAULT 1,
  `ac_lastlogon_ts` int(30) DEFAULT NULL,
  `ac_notice_tx` text DEFAULT NULL,
  `ac_resethash_tx` text DEFAULT NULL,
  `ac_passsalt_vc` varchar(255) DEFAULT NULL,
  `ac_catorder_vc` varchar(255) DEFAULT NULL,
  `ac_created_ts` int(30) DEFAULT NULL,
  `ac_deleted_ts` int(30) DEFAULT NULL,
  `ac_api_allowed_in` tinyint(1) NOT NULL DEFAULT 0,
  `ac_api_self_in` tinyint(1) NOT NULL DEFAULT 1,
  `ac_api_revoked_in` tinyint(1) NOT NULL DEFAULT 0,
  `ac_api_revoked_by` int(11) DEFAULT NULL,
  `ac_api_revoked_by_gid` tinyint(1) DEFAULT NULL,
  `ac_suspended_in` int(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ac_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_aliases`;
CREATE TABLE `x_aliases` (
  `al_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `al_acc_fk` int(6) DEFAULT NULL,
  `al_address_vc` varchar(255) DEFAULT NULL,
  `al_destination_vc` varchar(255) DEFAULT NULL,
  `al_created_ts` int(30) DEFAULT NULL,
  `al_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`al_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_api_audit`;
CREATE TABLE `x_api_audit` (
  `aa_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `aa_actor_vc` varchar(64) NOT NULL DEFAULT '',
  `aa_action_vc` varchar(128) NOT NULL DEFAULT '',
  `aa_target_vc` varchar(128) NOT NULL DEFAULT '',
  `aa_detail_tx` text DEFAULT NULL,
  `aa_ip_vc` varchar(45) NOT NULL DEFAULT '',
  `aa_ts` datetime NOT NULL,
  PRIMARY KEY (`aa_id_pk`),
  KEY `idx_ts` (`aa_ts`),
  KEY `idx_actor` (`aa_actor_vc`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_api_log`;
CREATE TABLE `x_api_log` (
  `al_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `al_token_fk` int(10) unsigned DEFAULT NULL,
  `al_method_vc` varchar(8) NOT NULL DEFAULT '',
  `al_resource_vc` varchar(256) NOT NULL DEFAULT '',
  `al_ip_vc` varchar(45) NOT NULL DEFAULT '',
  `al_status_in` smallint(6) NOT NULL DEFAULT 0,
  `al_ts` datetime NOT NULL,
  PRIMARY KEY (`al_id_pk`),
  KEY `idx_token` (`al_token_fk`),
  KEY `idx_ts` (`al_ts`)
) ENGINE=InnoDB AUTO_INCREMENT=222 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_api_tokens`;
CREATE TABLE `x_api_tokens` (
  `at_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `at_name_vc` varchar(128) NOT NULL,
  `at_creator_vc` varchar(64) NOT NULL DEFAULT '',
  `at_token_vc` varchar(64) DEFAULT NULL,
  `at_token_hash_vc` varchar(64) DEFAULT NULL,
  `at_scope_vc` varchar(32) NOT NULL DEFAULT 'admin',
  `at_user_fk` int(10) unsigned DEFAULT NULL,
  `at_enabled_in` tinyint(1) NOT NULL DEFAULT 1,
  `at_created_ts` datetime NOT NULL,
  `at_lastused_ts` datetime DEFAULT NULL,
  `at_last_ip_vc` varchar(45) DEFAULT NULL,
  `at_allowed_ip_vc` varchar(45) DEFAULT NULL,
  `at_expires_ts` datetime DEFAULT NULL,
  `at_deleted_ts` datetime DEFAULT NULL,
  PRIMARY KEY (`at_id_pk`),
  UNIQUE KEY `uq_token_hash` (`at_token_hash_vc`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_autoip`;
CREATE TABLE `x_autoip` (
  `ai_id_pk` int(6) NOT NULL DEFAULT 0,
  `ai_script_vc` varchar(255) DEFAULT NULL,
  `ai_email_vc` varchar(255) DEFAULT NULL,
  `ai_command_vc` varchar(255) DEFAULT NULL,
  `ai_newip_vc` varchar(50) DEFAULT NULL,
  `ai_oldip_vc` varchar(50) DEFAULT NULL,
  `ai_enabled_in` int(1) DEFAULT 1,
  `ai_lastupdate_ts` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ai_id_pk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
-- Fila semilla (id 1): sin ella ListAutoIPSettings() devuelve vacío y el módulo
-- AutoIP oculta el formulario (Server IP + Sync DNS + Sync Vhost).
INSERT INTO `x_autoip` (`ai_id_pk`, `ai_script_vc`, `ai_email_vc`, `ai_command_vc`, `ai_newip_vc`, `ai_oldip_vc`, `ai_enabled_in`, `ai_lastupdate_ts`) VALUES
('1', NULL, NULL, NULL, NULL, NULL, '0', NULL);

DROP TABLE IF EXISTS `x_bandwidth`;
CREATE TABLE `x_bandwidth` (
  `bd_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `bd_acc_fk` int(6) DEFAULT NULL,
  `bd_month_in` int(6) DEFAULT NULL,
  `bd_transamount_bi` bigint(20) DEFAULT NULL,
  `bd_diskamount_bi` bigint(20) DEFAULT NULL,
  `bd_diskover_in` int(6) DEFAULT NULL,
  `bd_diskcheck_in` int(6) DEFAULT NULL,
  `bd_transover_in` int(6) DEFAULT NULL,
  `bd_transcheck_in` int(6) DEFAULT NULL,
  PRIMARY KEY (`bd_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_cronjobs`;
CREATE TABLE `x_cronjobs` (
  `ct_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `ct_acc_fk` int(6) DEFAULT NULL,
  `ct_script_vc` varchar(255) DEFAULT NULL,
  `ct_timing_vc` varchar(255) DEFAULT NULL,
  `ct_fullpath_vc` varchar(255) DEFAULT NULL,
  `ct_description_tx` text DEFAULT NULL,
  `ct_created_ts` int(30) DEFAULT NULL,
  `ct_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`ct_id_pk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_distlists`;
CREATE TABLE `x_distlists` (
  `dl_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `dl_acc_fk` int(6) DEFAULT NULL,
  `dl_address_vc` varchar(255) DEFAULT NULL,
  `dl_created_ts` int(30) DEFAULT NULL,
  `dl_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`dl_id_pk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_distlistusers`;
CREATE TABLE `x_distlistusers` (
  `du_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `du_distlist_fk` int(6) DEFAULT NULL,
  `du_address_vc` varchar(255) DEFAULT NULL,
  `du_created_ts` int(30) DEFAULT NULL,
  `du_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`du_id_pk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_dns`;
CREATE TABLE `x_dns` (
  `dn_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `dn_acc_fk` int(6) DEFAULT NULL,
  `dn_name_vc` varchar(255) DEFAULT NULL,
  `dn_vhost_fk` int(6) DEFAULT NULL,
  `dn_type_vc` varchar(50) DEFAULT NULL,
  `dn_host_vc` varchar(100) DEFAULT NULL,
  `dn_ttl_in` int(30) DEFAULT NULL,
  `dn_target_vc` varchar(2000) DEFAULT NULL,
  `dn_texttarget_tx` text DEFAULT NULL,
  `dn_priority_in` int(50) DEFAULT NULL,
  `dn_weight_in` int(50) DEFAULT NULL,
  `dn_port_in` int(50) DEFAULT NULL,
  `dn_created_ts` int(30) DEFAULT NULL,
  `dn_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`dn_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=212 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_dns_create`;
CREATE TABLE `x_dns_create` (
  `dc_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `dc_acc_fk` int(6) DEFAULT NULL,
  `dc_type_vc` varchar(50) DEFAULT NULL,
  `dc_host_vc` varchar(100) DEFAULT NULL,
  `dc_ttl_in` int(30) DEFAULT NULL,
  `dc_target_vc` varchar(255) DEFAULT NULL,
  `dc_priority_in` int(50) DEFAULT NULL,
  `dc_weight_in` int(50) DEFAULT NULL,
  `dc_port_in` int(50) DEFAULT NULL,
  PRIMARY KEY (`dc_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
-- Plantilla por defecto de una zona nueva (dc_acc_fk=0). doCreateDefaultRecords()
-- reemplaza :IP: (server_ip), :DOMAIN: (nombre del dominio), :NS1:/:NS2: (nameservers
-- compartidos del panel). El registro DKIM (default._domainkey) lo añade el código.
INSERT INTO `x_dns_create` (`dc_id_pk`, `dc_acc_fk`, `dc_type_vc`, `dc_host_vc`, `dc_ttl_in`, `dc_target_vc`, `dc_priority_in`, `dc_weight_in`, `dc_port_in`) VALUES
('1', '0', 'NS',    '@',       172800, ':NS1:', NULL, NULL, NULL),
('2', '0', 'NS',    '@',       172800, ':NS2:', NULL, NULL, NULL),
('3', '0', 'A',     '@',       3600,   ':IP:', NULL, NULL, NULL),
('4', '0', 'A',     'www',     3600,   ':IP:', NULL, NULL, NULL),
('5', '0', 'A',     'mail',    3600,   ':IP:', NULL, NULL, NULL),
('6', '0', 'MX',    '@',       3600,   'mail.:DOMAIN:', 10, NULL, NULL),
('7', '0', 'TXT',   '@',       3600,   'v=spf1 a mx ip4::IP: ~all', NULL, NULL, NULL),
('8', '0', 'TXT',   '_dmarc',  3600,   'v=DMARC1; p=none; rua=mailto:postmaster@:DOMAIN:; fo=1', NULL, NULL, NULL),
('9', '0', 'CAA',   '@',       3600,   '0 issue "letsencrypt.org"', NULL, NULL, NULL),
('10','0', 'CAA',   '@',       3600,   '0 issuewild "letsencrypt.org"', NULL, NULL, NULL);

DROP TABLE IF EXISTS `x_dns_dnssec`;
CREATE TABLE `x_dns_dnssec` (
  `dd_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `dd_vhost_fk` int(11) NOT NULL,
  `dd_enabled_in` tinyint(4) NOT NULL DEFAULT 0,
  `dd_ds_txt` text DEFAULT NULL,
  `dd_keytag_in` int(11) DEFAULT NULL,
  `dd_enabled_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`dd_id_pk`),
  UNIQUE KEY `uq_dd_vhost` (`dd_vhost_fk`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Cluster DNS (Fase 2): nodos del cluster. El nodo local es nd_is_self_in=1.
DROP TABLE IF EXISTS `x_dns_nodes`;
CREATE TABLE `x_dns_nodes` (
  `nd_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `nd_name_vc` varchar(255) NOT NULL COMMENT 'hostname del nodo, p.ej. panel2.dominio.com',
  `nd_ip_vc` varchar(45) NOT NULL COMMENT 'IP PUBLICA del nodo (la que va en los registros A del DNS: ns/panel)',
  `nd_sync_ip_vc` varchar(45) DEFAULT NULL COMMENT 'IP de SINCRONIZACION (tunel WireGuard) para API+AXFR entre nodos; NULL = usar la publica',
  `nd_api_url_vc` varchar(255) DEFAULT NULL COMMENT 'base URL de su API, p.ej. https://panel2.dominio.com/bin/api.php',
  `nd_api_token_vc` varchar(128) DEFAULT NULL COMMENT 'token Bearer para llamar a su API (scope read)',
  `nd_is_self_in` tinyint(1) NOT NULL DEFAULT 0,
  `nd_enabled_in` tinyint(1) NOT NULL DEFAULT 1,
  `nd_cert_pin_vc` varchar(120) DEFAULT NULL COMMENT 'Pin TLS del peer (sha256//BASE64 de su SPKI) para dns_cluster_tls_verify=pin; capturado TOFU',
  `nd_last_sync_ts` int(11) DEFAULT NULL,
  `nd_created_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`nd_id_pk`),
  UNIQUE KEY `uq_node_name` (`nd_name_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Cluster DNS: zonas que sirve cada peer como primary (para declararlas secondary aquí).
DROP TABLE IF EXISTS `x_dns_remote_zones`;
CREATE TABLE `x_dns_remote_zones` (
  `rz_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `rz_node_fk` int(11) NOT NULL COMMENT 'FK a x_dns_nodes (el peer que es primary de esta zona)',
  `rz_domain_vc` varchar(255) NOT NULL,
  `rz_seen_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`rz_id_pk`),
  UNIQUE KEY `uq_node_domain` (`rz_node_fk`, `rz_domain_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Multi-IP (Fase 1): inventario de IPs del servidor/cluster. Ver preconf/migrations/0002_x_ips.sql.
DROP TABLE IF EXISTS `x_ips`;
CREATE TABLE `x_ips` (
  `ip_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address_vc` varchar(45) NOT NULL COMMENT 'IPv4 o IPv6',
  `ip_node_fk` int(11) DEFAULT NULL COMMENT 'FK a x_dns_nodes (nodo del cluster); NULL = este servidor',
  `ip_reseller_fk` int(10) DEFAULT NULL COMMENT 'FK a x_accounts del reseller al que se asigna; NULL = pool admin',
  `ip_shared_in` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = compartible por varios dominios; 0 = dedicada',
  `ip_enabled_in` tinyint(1) NOT NULL DEFAULT 1,
  `ip_is_primary_in` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'IP principal del servidor (no es alias)',
  `ip_ptr_vc` varchar(255) DEFAULT NULL COMMENT 'rDNS/PTR informativo',
  `ip_notes_vc` varchar(255) DEFAULT NULL,
  `ip_created_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`ip_id_pk`),
  UNIQUE KEY `ip_address_uq` (`ip_address_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_domain_php`;
CREATE TABLE `x_domain_php` (
  `dp_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `dp_vhost_fk` int(11) NOT NULL,
  `dp_upload_max_vc` varchar(20) NOT NULL DEFAULT '50M',
  `dp_post_max_vc` varchar(20) NOT NULL DEFAULT '50M',
  `dp_memory_limit_vc` varchar(20) NOT NULL DEFAULT '128M',
  `dp_max_exec_in` int(11) NOT NULL DEFAULT 30,
  `dp_max_input_in` int(11) NOT NULL DEFAULT 60,
  `dp_display_errors_in` tinyint(4) NOT NULL DEFAULT 0,
  `dp_php_version_vc` varchar(4) NOT NULL DEFAULT '',
  `dp_timezone_vc` varchar(64) NOT NULL DEFAULT '',
  `dp_max_input_vars_in` int(11) NOT NULL DEFAULT 1000,
  `dp_opcache_in` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`dp_id_pk`),
  UNIQUE KEY `uq_dp_vhost` (`dp_vhost_fk`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_faqs`;
CREATE TABLE `x_faqs` (
  `fq_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `fq_acc_fk` int(6) DEFAULT NULL,
  `fq_question_tx` text DEFAULT NULL,
  `fq_answer_tx` text DEFAULT NULL,
  `fq_global_in` int(1) DEFAULT NULL,
  `fq_created_ts` int(30) DEFAULT NULL,
  `fq_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`fq_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_forwarders`;
CREATE TABLE `x_forwarders` (
  `fw_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `fw_acc_fk` int(6) DEFAULT NULL,
  `fw_address_vc` varchar(255) DEFAULT NULL,
  `fw_destination_vc` varchar(255) DEFAULT NULL,
  `fw_keepmessage_in` int(1) DEFAULT 1,
  `fw_created_ts` int(30) DEFAULT NULL,
  `fw_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`fw_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_ftpaccounts`;
CREATE TABLE `x_ftpaccounts` (
  `ft_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `ft_acc_fk` int(6) DEFAULT NULL,
  `ft_user_vc` varchar(50) DEFAULT NULL,
  `ft_directory_vc` varchar(255) DEFAULT NULL,
  `ft_access_vc` varchar(20) DEFAULT NULL,
  `ft_password_vc` varchar(255) DEFAULT NULL,
  `ft_created_ts` int(6) DEFAULT NULL,
  `ft_deleted_ts` int(6) DEFAULT NULL,
  PRIMARY KEY (`ft_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_fw_auto_banned`;
CREATE TABLE `x_fw_auto_banned` (
  `fa_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `fa_ip_vc` varchar(49) NOT NULL,
  `fa_jail_vc` varchar(64) NOT NULL DEFAULT 'sshguard',
  `fa_service_vc` varchar(32) NOT NULL DEFAULT '',
  `fa_port_in` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fa_since_ts` int(11) NOT NULL DEFAULT 0,
  `fa_active_in` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`fa_id_pk`),
  UNIQUE KEY `uk_ip` (`fa_ip_vc`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_fw_blocked`;
CREATE TABLE `x_fw_blocked` (
  `fb_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `fb_ip_vc` varchar(49) NOT NULL,
  `fb_reason_vc` varchar(255) NOT NULL DEFAULT '',
  `fb_added_by` int(11) NOT NULL DEFAULT 0,
  `fb_added_ts` int(11) NOT NULL DEFAULT 0,
  `fb_active_in` tinyint(4) NOT NULL DEFAULT 1,
  `fb_deleted_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`fb_id_pk`),
  UNIQUE KEY `uk_ip` (`fb_ip_vc`,`fb_deleted_ts`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_fw_login_attempts`;
CREATE TABLE `x_fw_login_attempts` (
  `la_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `la_ip_vc` varchar(49) NOT NULL,
  `la_user_vc` varchar(64) NOT NULL DEFAULT '',
  `la_ts_in` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`la_id_pk`),
  KEY `idx_ip_ts` (`la_ip_vc`,`la_ts_in`),
  KEY `idx_ts` (`la_ts_in`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_fw_rules`;
CREATE TABLE `x_fw_rules` (
  `fr_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `fr_action_en` enum('block','pass') NOT NULL DEFAULT 'block',
  `fr_proto_vc` varchar(10) NOT NULL DEFAULT 'tcp',
  `fr_direction_en` enum('in','out','any') NOT NULL DEFAULT 'in',
  `fr_src_vc` varchar(49) NOT NULL DEFAULT 'any',
  `fr_port_in` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fr_port_max_in` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fr_desc_vc` varchar(255) NOT NULL DEFAULT '',
  `fr_order_in` smallint(5) unsigned NOT NULL DEFAULT 100,
  `fr_enabled_in` tinyint(4) NOT NULL DEFAULT 1,
  `fr_added_ts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fr_id_pk`),
  UNIQUE KEY `uk_rule` (`fr_proto_vc`,`fr_direction_en`,`fr_src_vc`,`fr_port_in`,`fr_port_max_in`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Reglas de cortafuegos por defecto (apertura mínima de un servidor de hosting).
-- fw_admin las aplica al anchor pf "bulwark_rules" (bin/fw_rules_apply.sh). Sin
-- ellas, con "block in all" en pf.conf, el panel mostraría el cortafuegos vacío.
INSERT INTO `x_fw_rules`
    (`fr_action_en`, `fr_proto_vc`, `fr_direction_en`, `fr_src_vc`, `fr_port_in`, `fr_port_max_in`, `fr_desc_vc`, `fr_order_in`, `fr_enabled_in`, `fr_added_ts`)
VALUES
  ('pass', 'tcp',  'in', 'any', 22,    0,     'SSH (administración)',        10,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 80,    0,     'HTTP',                        20,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 443,   0,     'HTTPS',                       30,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 53,    0,     'DNS (TCP)',                   40,  1, UNIX_TIMESTAMP()),
  ('pass', 'udp',  'in', 'any', 53,    0,     'DNS (UDP)',                   50,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 21,    0,     'FTP control',                 60,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 49152, 65534, 'FTP pasivo (datos)',          70,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 25,    0,     'SMTP',                        80,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 587,   0,     'SMTP submission',             90,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 465,   0,     'SMTPS',                      100,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 143,   0,     'IMAP',                       110,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 993,   0,     'IMAPS',                      120,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 110,   0,     'POP3',                       130,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 995,   0,     'POP3S',                      140,  1, UNIX_TIMESTAMP()),
  ('pass', 'icmp', 'in', 'any', 0,     0,     'ICMP (ping/diagnóstico)',    150,  1, UNIX_TIMESTAMP()),
  ('pass', 'udp',  'in', 'any', 123,   0,     'NTP (sincronización hora)',  160,  1, UNIX_TIMESTAMP());

DROP TABLE IF EXISTS `x_fw_whitelist`;
CREATE TABLE `x_fw_whitelist` (
  `fw_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `fw_ip_vc` varchar(49) NOT NULL,
  `fw_reason_vc` varchar(255) NOT NULL DEFAULT '',
  `fw_added_by` int(11) NOT NULL DEFAULT 0,
  `fw_added_ts` int(11) NOT NULL DEFAULT 0,
  `fw_deleted_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`fw_id_pk`),
  UNIQUE KEY `uk_ip` (`fw_ip_vc`,`fw_deleted_ts`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `x_groups`;
CREATE TABLE `x_groups` (
  `ug_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `ug_name_vc` varchar(20) DEFAULT NULL,
  `ug_notes_tx` text DEFAULT NULL,
  `ug_reseller_fk` int(6) DEFAULT NULL,
  PRIMARY KEY (`ug_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_htaccess`;
CREATE TABLE `x_htaccess` (
  `ht_id_pk` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ht_acc_fk` int(6) DEFAULT NULL,
  `ht_user_vc` varchar(10) DEFAULT NULL,
  `ht_dir_vc` varchar(255) DEFAULT NULL,
  `ht_created_ts` int(30) DEFAULT NULL,
  `ht_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`ht_id_pk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_htpasswd_file`;
CREATE TABLE `x_htpasswd_file` (
  `x_htpasswd_file_id` int(11) NOT NULL AUTO_INCREMENT,
  `x_htpasswd_file_target` varchar(255) NOT NULL,
  `x_htpasswd_file_message` varchar(255) NOT NULL,
  `x_htpasswd_file_created` int(11) NOT NULL,
  `x_htpasswd_file_deleted` int(11) DEFAULT NULL,
  `x_htpasswd_bulwark_user_id` int(11) NOT NULL,
  PRIMARY KEY (`x_htpasswd_file_id`),
  UNIQUE KEY `x_htpasswd_file_target` (`x_htpasswd_file_target`),
  KEY `x_htpasswd_file_x_htpasswd_bulwark_user_id_idx` (`x_htpasswd_bulwark_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_htpasswd_mapper`;
CREATE TABLE `x_htpasswd_mapper` (
  `x_htpasswd_mapper_id` int(11) NOT NULL AUTO_INCREMENT,
  `x_htpasswd_file_id` int(11) NOT NULL,
  `x_htpasswd_user_id` int(11) NOT NULL,
  PRIMARY KEY (`x_htpasswd_mapper_id`),
  KEY `x_htpasswd_mapper_x_htpasswd_file_id_idx` (`x_htpasswd_file_id`),
  KEY `x_htpasswd_mapper_x_htpasswd_user_id_idx` (`x_htpasswd_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_htpasswd_user`;
CREATE TABLE `x_htpasswd_user` (
  `x_htpasswd_user_id` int(11) NOT NULL AUTO_INCREMENT,
  `x_htpasswd_user_username` varchar(255) NOT NULL,
  `x_htpasswd_user_password` varchar(255) NOT NULL,
  `x_htpasswd_user_created` int(11) NOT NULL,
  `x_htpasswd_user_deleted` int(11) DEFAULT NULL,
  `x_htpasswd_bulwark_user_id` int(11) NOT NULL,
  PRIMARY KEY (`x_htpasswd_user_id`),
  UNIQUE KEY `x_htpasswd_user_username` (`x_htpasswd_user_username`),
  UNIQUE KEY `x_htpasswd_user_password` (`x_htpasswd_user_password`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_logs`;
CREATE TABLE `x_logs` (
  `lg_id_pk` int(9) unsigned NOT NULL AUTO_INCREMENT,
  `lg_user_fk` int(6) NOT NULL DEFAULT 1,
  `lg_code_vc` varchar(10) DEFAULT NULL,
  `lg_module_vc` varchar(25) DEFAULT NULL,
  `lg_detail_tx` text DEFAULT NULL,
  `lg_stack_tx` text DEFAULT NULL,
  `lg_when_ts` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lg_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=19679 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_mailboxes`;
CREATE TABLE `x_mailboxes` (
  `mb_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `mb_acc_fk` int(6) DEFAULT NULL,
  `mb_address_vc` varchar(255) DEFAULT NULL,
  `mb_enabled_in` int(1) DEFAULT 1,
  `mb_created_ts` int(30) DEFAULT NULL,
  `mb_deleted_ts` int(30) DEFAULT NULL,
  `mb_antispam_in` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=antispam activo, 0=desactivado',
  `mb_spam_score` decimal(4,1) DEFAULT NULL COMMENT 'Umbral personal de spam (NULL=usar global)',
  `mb_spam_action` varchar(10) DEFAULT NULL COMMENT 'tag|junk|reject|NULL (NULL=usar global)',
  PRIMARY KEY (`mb_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Listas blancas/negras antispam por buzón (módulo antispam)
DROP TABLE IF EXISTS `x_antispam_lists`;
CREATE TABLE `x_antispam_lists` (
  `al_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `al_mailbox_fk` int(10) unsigned NOT NULL,
  `al_address_vc` varchar(255) NOT NULL,
  `al_type_vc` enum('white','black') NOT NULL DEFAULT 'white',
  `al_created_ts` int(30) unsigned DEFAULT NULL,
  PRIMARY KEY (`al_id_pk`),
  KEY `al_mailbox_fk` (`al_mailbox_fk`),
  UNIQUE KEY `al_mailbox_address` (`al_mailbox_fk`, `al_address_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_modcats`;
CREATE TABLE `x_modcats` (
  `mc_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `mc_name_vc` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`mc_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_modules`;
CREATE TABLE `x_modules` (
  `mo_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `mo_category_fk` int(6) NOT NULL DEFAULT 1,
  `mo_name_vc` varchar(200) NOT NULL,
  `mo_version_in` int(10) DEFAULT NULL,
  `mo_folder_vc` varchar(255) DEFAULT NULL,
  `mo_type_en` enum('user','system','modadmin','lang') NOT NULL DEFAULT 'user',
  `mo_desc_tx` text DEFAULT NULL,
  `mo_installed_ts` int(30) DEFAULT NULL,
  `mo_enabled_en` enum('true','false') NOT NULL DEFAULT 'true',
  `mo_updatever_vc` varchar(10) DEFAULT NULL,
  `mo_updateurl_tx` text DEFAULT NULL,
  PRIMARY KEY (`mo_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_mysql_databases`;
CREATE TABLE `x_mysql_databases` (
  `my_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `my_acc_fk` int(6) DEFAULT NULL,
  `my_name_vc` varchar(40) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `my_usedspace_bi` bigint(50) DEFAULT 0,
  `my_created_ts` int(30) DEFAULT NULL,
  `my_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`my_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_mysql_dbmap`;
CREATE TABLE `x_mysql_dbmap` (
  `mm_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `mm_acc_fk` int(6) DEFAULT NULL,
  `mm_user_fk` int(6) DEFAULT NULL,
  `mm_database_fk` int(6) DEFAULT NULL,
  PRIMARY KEY (`mm_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_mysql_users`;
CREATE TABLE `x_mysql_users` (
  `mu_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `mu_acc_fk` int(6) DEFAULT NULL,
  `mu_name_vc` varchar(40) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `mu_database_fk` int(6) DEFAULT NULL,
  `mu_access_vc` varchar(40) DEFAULT NULL,
  `mu_pass_vc` varchar(255) NOT NULL DEFAULT '',
  `mu_created_ts` int(30) DEFAULT NULL,
  `mu_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`mu_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_packages`;
CREATE TABLE `x_packages` (
  `pk_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `pk_name_vc` varchar(30) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `pk_reseller_fk` int(6) DEFAULT NULL,
  `pk_enablephp_in` int(1) DEFAULT 0,
  `pk_created_ts` int(30) DEFAULT NULL,
  `pk_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`pk_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Control de migraciones incrementales (esquema BD + configuraciones). Cada migración de
-- preconf/migrations/ (NNNN_*.sql o NNNN_*.sh) se aplica UNA vez y se registra aquí. En una
-- instalación nueva se marcan todas como aplicadas (baseline), porque bulwark_core.sql ya trae el
-- esquema al día; en una actualización (git pull) se aplican solo las nuevas.
DROP TABLE IF EXISTS `x_migrations`;
CREATE TABLE `x_migrations` (
  `mg_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mg_name_vc` varchar(191) NOT NULL,
  `mg_applied_ts` int(11) NOT NULL,
  PRIMARY KEY (`mg_id_pk`),
  UNIQUE KEY `uq_mg_name` (`mg_name_vc`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_permissions`;
CREATE TABLE `x_permissions` (
  `pe_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `pe_group_fk` int(6) DEFAULT NULL,
  `pe_module_fk` int(6) DEFAULT NULL,
  PRIMARY KEY (`pe_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_profiles`;
CREATE TABLE `x_profiles` (
  `ud_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `ud_user_fk` int(6) DEFAULT NULL,
  `ud_fullname_vc` varchar(100) DEFAULT NULL,
  `ud_language_vc` varchar(10) DEFAULT 'en',
  `ud_group_fk` int(6) DEFAULT NULL,
  `ud_package_fk` int(6) DEFAULT NULL,
  `ud_address_tx` text DEFAULT NULL,
  `ud_postcode_vc` varchar(20) DEFAULT NULL,
  `ud_phone_vc` varchar(20) DEFAULT NULL,
  `ud_created_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`ud_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_quotas`;
CREATE TABLE `x_quotas` (
  `qt_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `qt_package_fk` int(6) DEFAULT NULL,
  `qt_domains_in` int(6) DEFAULT 0,
  `qt_subdomains_in` int(6) DEFAULT 0,
  `qt_parkeddomains_in` int(6) DEFAULT 0,
  `qt_mailboxes_in` int(6) DEFAULT 0,
  `qt_fowarders_in` int(6) DEFAULT 0,
  `qt_distlists_in` int(6) DEFAULT 0,
  `qt_ftpaccounts_in` int(6) DEFAULT 0,
  `qt_mysql_in` int(6) DEFAULT 0,
  `qt_cronjobs_in` int(6) DEFAULT 0,
  `qt_php_memory_vc` varchar(10) DEFAULT '128M',
  `qt_php_upload_vc` varchar(10) DEFAULT '50M',
  `qt_php_post_vc` varchar(10) DEFAULT '50M',
  `qt_php_exec_in` int(5) DEFAULT 30,
  `qt_php_maxinput_in` int(5) DEFAULT 60,
  `qt_maxproc_in` int(6) DEFAULT 100,
  `qt_maxmem_vc` varchar(10) DEFAULT '1G',
  `qt_pcpu_in` int(4) DEFAULT 0,
  `qt_backups_in` int(11) NOT NULL DEFAULT 0,
  `qt_backups_remote_in` int(11) NOT NULL DEFAULT 0,
  `qt_dbquota_in` int(11) NOT NULL DEFAULT 0,
  `qt_diskspace_bi` bigint(20) DEFAULT 0,
  `qt_bandwidth_bi` bigint(20) DEFAULT 0,
  `qt_bwenabled_in` int(1) DEFAULT 0,
  `qt_dlenabled_in` int(1) DEFAULT 0,
  `qt_totalbw_fk` int(30) DEFAULT NULL,
  `qt_minbw_fk` int(30) DEFAULT NULL,
  `qt_maxcon_fk` int(30) DEFAULT NULL,
  `qt_filesize_fk` int(30) DEFAULT NULL,
  `qt_filespeed_fk` int(30) DEFAULT NULL,
  `qt_filetype_vc` varchar(30) NOT NULL DEFAULT '*',
  `qt_modified_in` int(1) DEFAULT 0,
  `qt_dedicatedips_in` int(11) NOT NULL DEFAULT 0 COMMENT 'Multi-IP: nº máx de IPs dedicadas por paquete (-1=ilimitado)',
  PRIMARY KEY (`qt_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Destino remoto de copias de seguridad por cuenta (Fase 2). La contraseña va CIFRADA
-- (AES-256-GCM) en bd_pass_tx; la clave se guarda fuera de la BD en cnf/backup.key.
DROP TABLE IF EXISTS `x_backup_destinations`;
CREATE TABLE `x_backup_destinations` (
  `bd_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `bd_acc_fk` int(11) NOT NULL,
  `bd_type_vc` varchar(10) NOT NULL DEFAULT 'ftps',
  `bd_host_vc` varchar(255) DEFAULT NULL,
  `bd_port_in` int(11) NOT NULL DEFAULT 21,
  `bd_user_vc` varchar(255) DEFAULT NULL,
  `bd_pass_tx` text,
  `bd_path_vc` varchar(255) NOT NULL DEFAULT '/',
  `bd_tlsverify_in` int(1) NOT NULL DEFAULT 2,
  `bd_certsha_vc` varchar(120) DEFAULT NULL,
  `bd_enabled_in` int(1) NOT NULL DEFAULT 0,
  `bd_laststatus_vc` varchar(255) DEFAULT NULL,
  `bd_last_ts` int(11) DEFAULT NULL,
  `bd_created_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`bd_id_pk`),
  KEY `bd_acc_fk` (`bd_acc_fk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Programación de copias automáticas POR CUENTA (el usuario la configura desde backupmgr).
-- Una fila por cuenta. El daemon (OnDaemonRun) encola las vencidas y las procesa por bloques.
DROP TABLE IF EXISTS `x_backup_schedule`;
CREATE TABLE `x_backup_schedule` (
  `bs_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `bs_acc_fk` int(11) NOT NULL,
  `bs_enabled_in` int(1) NOT NULL DEFAULT 0,
  `bs_freq_vc` varchar(10) NOT NULL DEFAULT 'daily',
  `bs_hour_in` int(2) NOT NULL DEFAULT 3,
  `bs_dow_in` int(1) NOT NULL DEFAULT 1,
  `bs_dom_in` int(2) NOT NULL DEFAULT 1,
  `bs_dest_vc` varchar(10) NOT NULL DEFAULT 'local',
  `bs_last_run_ts` int(11) DEFAULT NULL,
  `bs_next_run_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`bs_id_pk`),
  UNIQUE KEY `bs_acc` (`bs_acc_fk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Cola/spool de copias pendientes de ejecutar. El daemon procesa N por tick (backup_batch_size).
DROP TABLE IF EXISTS `x_backup_queue`;
CREATE TABLE `x_backup_queue` (
  `bq_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `bq_acc_fk` int(11) NOT NULL,
  `bq_mode_vc` varchar(10) NOT NULL DEFAULT 'local',
  `bq_status_vc` varchar(10) NOT NULL DEFAULT 'pending',
  `bq_enqueued_ts` int(11) NOT NULL,
  `bq_started_ts` int(11) DEFAULT NULL,
  `bq_finished_ts` int(11) DEFAULT NULL,
  `bq_attempts_in` int(11) NOT NULL DEFAULT 0,
  `bq_message_tx` text,
  PRIMARY KEY (`bq_id_pk`),
  KEY `bq_status` (`bq_status_vc`),
  KEY `bq_acc` (`bq_acc_fk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Registro persistente de operaciones de copia (local/remota/prueba) por cuenta. Se muestra en
-- el módulo backupmgr con paginación y se conservan como máximo los últimos 100 por cuenta.
DROP TABLE IF EXISTS `x_backup_log`;
CREATE TABLE `x_backup_log` (
  `bl_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `bl_acc_fk` int(11) NOT NULL,
  `bl_ts_in` int(11) NOT NULL,
  `bl_action_vc` varchar(20) DEFAULT NULL,
  `bl_dest_vc` varchar(160) DEFAULT NULL,
  `bl_file_vc` varchar(200) DEFAULT NULL,
  `bl_size_in` bigint(20) DEFAULT 0,
  `bl_attempts_in` int(11) DEFAULT 0,
  `bl_result_vc` varchar(8) DEFAULT NULL,
  `bl_message_tx` text,
  `bl_duration_in` int(11) DEFAULT 0,
  PRIMARY KEY (`bl_id_pk`),
  KEY `bl_acc_fk` (`bl_acc_fk`,`bl_id_pk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Estado de bloqueo de escritura por cuota de BD por cuenta (evita re-aplicar grants cada
-- ciclo del daemon). mq_blocked_in=1 => escritura revocada por superar qt_dbquota_in.
DROP TABLE IF EXISTS `x_mysql_quota_state`;
CREATE TABLE `x_mysql_quota_state` (
  `mq_acc_fk` int(11) NOT NULL,
  `mq_blocked_in` int(1) NOT NULL DEFAULT 0,
  `mq_size_bi` bigint(20) DEFAULT 0,
  `mq_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`mq_acc_fk`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_settings`;
CREATE TABLE `x_settings` (
  `so_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `so_name_vc` varchar(50) DEFAULT NULL,
  `so_cleanname_vc` varchar(50) DEFAULT NULL,
  `so_value_tx` text DEFAULT NULL,
  `so_defvalues_tx` text DEFAULT NULL,
  `so_desc_tx` text DEFAULT NULL,
  `so_module_vc` varchar(50) DEFAULT NULL,
  `so_usereditable_en` enum('true','false') DEFAULT 'false',
  PRIMARY KEY (`so_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_translations`;
CREATE TABLE `x_translations` (
  `tr_id_pk` int(11) NOT NULL AUTO_INCREMENT,
  `tr_en_tx` text DEFAULT NULL,
  `tr_de_tx` text DEFAULT NULL,
  PRIMARY KEY (`tr_id_pk`)
) ENGINE=InnoDB AUTO_INCREMENT=177 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `x_vhosts`;
CREATE TABLE `x_vhosts` (
  `vh_id_pk` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `vh_acc_fk` int(6) DEFAULT NULL,
  `vh_name_vc` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `vh_directory_vc` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `vh_type_in` int(1) DEFAULT 1,
  `vh_active_in` int(1) DEFAULT 0,
  `vh_obasedir_in` int(1) DEFAULT 1,
  `vh_ssl_tx` text DEFAULT NULL,
  `vh_ssl_port_in` int(6) DEFAULT NULL,
  `vh_forcessl_in` tinyint(1) NOT NULL DEFAULT 1,
  `vh_custom_tx` text DEFAULT NULL,
  `vh_custom_port_in` int(6) DEFAULT NULL,
  `vh_custom_ip_vc` varchar(45) DEFAULT NULL,
  `vh_custom_ip6_vc` varchar(45) DEFAULT NULL COMMENT 'Multi-IP: IPv6 dedicada del dominio (doble pila)',
  `vh_portforward_in` int(1) DEFAULT NULL,
  `vh_soaserial_vc` char(10) DEFAULT 'AAAAMMDDSS',
  `vh_enabled_in` int(1) DEFAULT 1,
  `vh_created_ts` int(30) DEFAULT NULL,
  `vh_deleted_ts` int(30) DEFAULT NULL,
  PRIMARY KEY (`vh_id_pk`)
) ENGINE=MyISAM AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;


-- Data for configuration tables

TRUNCATE TABLE `x_modcats`;
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('1', 'Account Information');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('2', 'Server Admin');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('3', 'Advanced');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('4', 'Database Management');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('5', 'Domain Management');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('6', 'Mail');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('7', 'Reseller');
INSERT INTO `x_modcats` (`mc_id_pk`, `mc_name_vc`) VALUES ('8', 'File Management');

TRUNCATE TABLE `x_modules`;
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('1', '3', 'PHPInfo', '200', 'phpinfo', 'user', 'PHPInfo provides you with information regarding the version of PHP running on this system as well as installed PHP extensions and configuration details.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('3', '7', 'Shadowing', '200', 'shadowing', 'user', 'From here you can shadow any of your client\'s accounts, this enables you to automatically login as the user which enables you to offer remote help by seeing what they see!', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('4', '2', 'Bulwark Config', '200', 'bulwarkconfig', 'user', 'Changes made here affect the entire Bulwark configuration, please double check everything before saving changes.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('6', '2', 'Updates', '200', 'updates', 'user', 'Check to see if there are any available updates to your version of the Bulwark software.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('8', '4', 'phpMyAdmin', '200', 'phpmyadmin', 'user', 'phpMyAdmin is a web based tool that enables you to manage your Bulwark MySQL databases via. the web.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('9', '1', 'My Account', '200', 'my_account', 'user', 'Current personal details that you have provided us with, We ask that you keep these upto date in case we require to contact you regarding your hosting package.\r\n', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('10', '6', 'WebMail', '200', 'webmail', 'user', 'Webmail is a convenient way for you to check your email accounts online without the need to configure an email client.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('11', '1', 'Change Password', '200', 'password_assistant', 'user', 'Change your current control panel password.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('12', '3', 'Backup', '200', 'backupmgr', 'user', 'The backup manager module enables you to backup your entire hosting account including all your MySQL&reg; databases.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('14', '3', 'Service Status', '200', 'services', 'user', 'Here you can check the current status of our services and see what services are up and running and which are down and not.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('15', '5', 'Domains', '200', 'domains', 'user', 'This module enables you to add or configure domain web hosting on your account.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('16', '5', 'Parked Domains', '200', 'parked_domains', 'user', 'Domain parking refers to the registration of an Internet domain name without that domain being used to provide services such as e-mail or a website. If you have any domains that you are not using, then simply park them!', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('17', '5', 'Sub Domains', '200', 'sub_domains', 'user', 'This module enables you to add or configure domain web hosting on your account.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('18', '2', 'Module Admin', '200', 'moduleadmin', 'user', 'Administer or configure modules registered with module admin', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('19', '7', 'Manage Clients', '200', 'manage_clients', 'user', 'The account manager enables you to view, update and create client accounts.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('20', '7', 'Package Manager', '200', 'packages', 'user', 'Welcome to the Package Manager, using this module enables you to create and manage existing reseller packages on your Bulwark hosting account.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('22', '3', 'Cron Manager', '200', 'cron', 'user', 'Here you can configure PHP scripts to run automatically at different time intervals.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('24', '4', 'MySQL Database', '200', 'mysql_databases', 'user', 'MySQL&reg; databases are used by many PHP applications such as forums and ecommerce systems, below you can manage and create MySQL&reg; databases.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('25', '1', 'Usage Viewer', '200', 'usage_viewer', 'user', 'The account usage screen enables you to see exactly what you are currently using on your hosting package.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('26', '8', 'FTP Accounts', '200', 'ftp_management', 'user', 'Using this module you can create FTP accounts which will enable you and any other accounts you create to have the ability to upload and manage files on your hosting space.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('27', '3', 'FAQ\'s', '200', 'faqs', 'user', 'Please find a list of the most common questions from users, if you are unable to find a solution to your problem below please then contact your hosting provider. Simply click on the FAQ below to view the solution.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('28', '0', 'Apache Config', '200', 'apache_admin', 'modadmin', 'This module enables you to configure Apache Vhost settings for your hosting accounts.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('29', '5', 'DNS Manager', '200', 'dns_manager', 'user', NULL, NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('30', '0', 'DNS Config', '200', 'dns_admin', 'modadmin', 'This module enables you to configure DNS settings for the DNS Manager', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('31', '7', 'Manage Groups', '200', 'manage_groups', 'user', 'Manage user groups to enable greater control over module permission.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('32', '6', 'Mailboxes', '200', 'mailboxes', 'user', 'Using this module you have the ability to create IMAP and POP3 Mailboxes.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('33', '6', 'Forwards', '200', 'forwarders', 'user', 'Using this module you have the ability to create mail forwarders.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('34', '6', 'Distribution Lists', '200', 'distlists', 'user', 'This module enables you to create and manage email distribution groups.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('35', '6', 'Aliases', '200', 'aliases', 'user', 'Using this module you have the ability to create alias mailboxes to existing accounts.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('36', '0', 'Mail Config', '200', 'mail_admin', 'modadmin', 'This module enables you to configure your mail options', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('39', '4', 'MySQL Users', '200', 'mysql_users', 'user', 'MySQL&reg; Users allows you to add users and permissions to your MySQL&reg; databases.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('40', '0', 'FTP Config', '200', 'ftp_admin', 'modadmin', 'This module enables you to configure FTP settings for your hosting accounts.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('41', '0', 'Backup Config', '200', 'backup_admin', 'modadmin', 'This module enables you to configure Backup settings for your hosting accounts.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('42', '7', 'Client Notice Manager', '200', 'client_notices', 'user', 'Enables resellers to set global notices for their clients.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('46', '7', 'Theme Manager', '200', 'theme_manager', 'user', 'Enables the reseller to set themes configurations for their clients.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('47', '3', 'Webalizer Stats', '200', 'webalizer_stats', 'user', 'You can view many statistics such as visitor infomation, bandwidth used, referal infomation and most viewed pages etc. Web stats are based on Domains and sub-domains so to view web stats for a particular domain or subdomain use the drop-down menu to select the domain or sub-domain you want to view web stats for.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('48', '3', 'Protected Directories', '200', 'protected_directories', 'user', 'Password protect your web applications and directories.', NULL, 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('49', '2', 'AutoIP Updater', '102', 'autoip', 'user', 'Automatically update your control panel IP address.', '1701028845', 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('60', '2', 'Let\'s Encrypt Status', '100', 'le_admin', 'user', 'Estado de emisiones y certificados Let\'s Encrypt de todo el servidor (solo administradores).', '1701028845', 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('61', '6', 'IMAP Migration (imapsync)', '100', 'imapsync', 'user', 'Migracion de cuentas de correo IMAP externas hacia el panel (cada usuario migra su correo; ajustes solo admin).', '1701028845', 'true', NULL, '');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('50', '3', 'View Logs', '101', 'user_logviewer', 'user', 'Allows user to view several logs of their sites', '1701028845', 'true', NULL, 'http://zppy-repo.mach-hosting.com/repo/user_logviewer.xml');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('51', '5', 'Sencrypt SSL', '201', 'sencrypt', 'user', 'Add or remove Let\'s Encrypt SSL certificates for your domains and sub domains', '1701028845', 'true', NULL, 'http://zppy-repo.mach-hosting.com/testing/sencrypt.zpp');
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('52', '2', 'API Manager', '100', 'api_manager', 'user', 'Gestiona los tokens de acceso a la API REST y activa o desactiva la API.', '1782474766', 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('53', '2', 'System Logs', '100', 'system_log', 'user', 'View, filter and manage internal Bulwark system logs. Configure retention period, purge and export as CSV.', NULL, 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('54', '2', 'Firewall Admin', '103', 'fw_admin', 'user', 'Gestión de cortafuegos pf y SSHGuard. Bloqueo de IPs (IPv4/IPv6/CIDR), lista blanca y bans automáticos.', '1782745660', 'true', NULL, NULL);
-- Módulos de correo/antivirus: se registran aquí (el instalador no ejecuta los install.sql de módulo)
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('55', '6', 'Antispam', '100', 'antispam', 'user', 'Gestiona el filtrado de spam por buzón (listas blancas/negras y umbral).', '1782745660', 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('56', '6', 'Antispam Admin', '100', 'antispam_admin', 'modadmin', 'Administración global de antispam (rspamd): umbral, acción y ajustes.', '1782745660', 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('57', '6', 'ClamAV Admin', '100', 'clamav_admin', 'modadmin', 'ClamAV antivirus: protección email, escaneo de buzones y actualización de firmas.', '1782745660', 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('58', '6', 'Antivirus', '100', 'clamav_user', 'user', 'Analiza tus buzones de correo en busca de virus con ClamAV.', '1782745660', 'true', NULL, NULL);
INSERT INTO `x_modules` (`mo_id_pk`, `mo_category_fk`, `mo_name_vc`, `mo_version_in`, `mo_folder_vc`, `mo_type_en`, `mo_desc_tx`, `mo_installed_ts`, `mo_enabled_en`, `mo_updatever_vc`, `mo_updateurl_tx`) VALUES ('59', '2', 'Hosting Users', '100', 'hosting_users', 'system', 'Módulo de sistema: gestiona los usuarios de hosting mediante hooks (crear/borrar cliente, daemon).', '1782745660', 'true', NULL, NULL);

TRUNCATE TABLE `x_settings`;
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('6', 'dbversion', 'Bulwark version', '2.1.0', NULL, 'Database Version', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('7', 'bulwark_root', 'Bulwark root path', '/usr/local/bulwark/', NULL, 'Bulwark Web Root', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('8', 'module_icons_pr', 'Icons per Row', '10', NULL, 'Set the number of icons to display before beginning a new line.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('10', 'Bulwark_df', 'Date Format', 'H:i jS M Y T', NULL, 'Set the date format used by modules.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('13', 'servicechk_to', 'Service Check Timeout', '10', NULL, 'Service Check Timeout', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('14', 'root_drive', 'Root Drive', '/', NULL, 'The root drive where Bulwark is installed.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('16', 'php_exer', 'PHP executable', 'php', NULL, 'PHP Executable', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('17', 'temp_dir', 'Temp Directory', '/var/bulwark/temp/', NULL, 'Global temp directory.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('19', 'update_url', 'Sentora Update API URL', 'http://api.sentora.org/latestversion.json', NULL, 'Sentora Update URL', 'Sentora Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('21', 'server_ip', 'Server IP Address', '192.168.1.109', NULL, 'If set this will use this manually entered server IP address which is the prefered method for use behind a firewall.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('148', 'server_ip6', 'Server IPv6 Address', '', NULL, 'IPv6 primaria del servidor (doble pila). Si se define, el panel escucha tambien en [IPv6]:puerto. Vacio = panel solo por IPv4.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('22', 'zip_exe', 'ZIP Exe', 'zip', NULL, 'Path to the ZIP Executable', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('24', 'disable_hostsen', 'Disable auto HOSTS file entry', 'false', 'true|false', 'Disable Host Entries', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('25', 'latestzpversion', 'Cached version of latest bulwark version', '2.0.0', NULL, 'This is used for caching the latest version of Bulwark.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('26', 'logmode', 'Debug logging mode', 'db', 'db|file|email', 'The default mode to log all errors in.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('27', 'logfile', 'Bulwark Log file', '/var/bulwark/logs/bulwark.log', NULL, 'If logging is set to \'file\' mode this is the path to the log file that is to be used by Bulwark.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('28', 'apikey', 'XMWS API Key', '82d19584899d47b68a5e0835592c7dfe', NULL, 'The secret API key for the server.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('29', 'email_from_address', 'From Address', 'bulwark@localhost', NULL, 'The email address to appear in the From field of emails sent by Bulwark.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('30', 'email_from_name', 'From Name', 'Bulwark Server', NULL, 'The name to appear in the From field of emails sent by Bulwark.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('31', 'email_smtp', 'Use SMTP', 'false', 'true|false', 'Use SMTP server to send emails from. (true/false)', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('32', 'smtp_auth', 'Use AUTH', 'false', 'true|false', 'SMTP requires authentication. (true/false)', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('33', 'smtp_server', 'SMTP Server', '', NULL, 'The address of the SMTP server.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('34', 'smtp_port', 'SMTP Port', '465', NULL, 'The port address of the SMTP server (usually 25)', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('35', 'smtp_username', 'SMTP User', '', NULL, 'Username for authentication on the SMTP server.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('36', 'smtp_password', 'SMTP Pass', '', NULL, 'Password for authentication on the SMTP server.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('37', 'smtp_secure', 'SMTP Auth method', 'false', 'false|ssl|tls', 'If specified will attempt to use encryption to connect to the server, if \'false\' this is disabled. Available options: false, ssl, tls', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('38', 'daemon_lastrun', 'Daemon timeing cache', '1782840960', NULL, 'Timestamp of when the daemon last ran.', NULL, 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('39', 'daemon_dayrun', 'Daemon timeing cache', '1782821220', NULL, NULL, NULL, 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('40', 'daemon_weekrun', 'Daemon timeing cache', '1782643800', NULL, NULL, NULL, 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('41', 'daemon_monthrun', 'Daemon timeing cache', '1782643800', NULL, NULL, NULL, 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('42', 'purge_bu', 'Purge Backups', 'true', 'true|false', 'Limpieza por ANTIGUEDAD (borra copias con mas de "Purge Date" dias). Complementa la retencion por CANTIDAD del paquete (qt_backups_in); util para cuentas inactivas. (true/false)', 'Backup Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('43', 'purge_date', 'Purge Date', '30', NULL, 'Time in days backups are safe from being deleted. After days have elapsed, older backups will be deleted on Daemon Day Run', 'Backup Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('44', 'disk_bu', 'Disk Backups', 'true', 'true|false', 'Permitir a los clientes crear/guardar copias MANUALES en disco desde su panel (backupmgr). (true/false)', 'Backup Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('45', 'schedule_bu', 'Daily Backups', 'false', 'true|false', 'Interruptor MAESTRO de las copias automaticas por cliente (el programador por cuenta de Backup Config). Si es false no se ejecuta ninguna copia programada. (true/false)', 'Backup Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'backup_remote_retries', 'Remote backup retries', '3', NULL, 'Numero de reintentos de la subida remota (FTPS) de las copias', 'Backup Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'backup_remote_retrydelay', 'Remote backup retry delay', '5', NULL, 'Espera base en segundos entre reintentos de la subida remota (backoff lineal)', 'Backup Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'backup_disk_floor_mb', 'Backup disk floor MB', '1024', NULL, 'Margen minimo de disco libre (MB) que debe quedar tras generar el .zip temporal de una copia; si no hay, la copia se pospone para no llenar el HD', 'Backup Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'backup_batch_size', 'Backup batch size', '2', NULL, 'Numero de copias automaticas a procesar por cada ejecucion del daemon (cada 5 min), para no colapsar el servidor', 'Backup Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES
(NULL,'ratelimit_enabled','Ratelimit enabled','1',NULL,'Activar el rate-limit de salida (anti-spam)','Antispam','false'),
(NULL,'ratelimit_max_rcpt','Ratelimit max rcpt','100',NULL,'Maximo destinatarios por correo','Antispam','false'),
(NULL,'ratelimit_user_rate','Ratelimit user rate','300',NULL,'Correos por hora por usuario autenticado','Antispam','false'),
(NULL,'ratelimit_user_burst','Ratelimit user burst','300',NULL,'Rafaga permitida','Antispam','false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL,'ratelimit_domain_rate','Ratelimit domain rate','200',NULL,'Correos por hora por dominio del remitente (cubre correo local PHP mail())','Antispam','false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'distlist_max_members', 'Distlist max members', '50', NULL, 'Maximo de miembros (direcciones) por lista de distribucion, para evitar abuso/spam', 'Mail Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'maillimit_per_hour', 'Mail limit per hour', '200', NULL, 'Limite duro de correos/hora por cuenta de hosting via sendmail_path (0=ilimitado)', 'Antispam', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES (NULL, 'system_mail_to', 'System mail destination', '', NULL, 'Buzon al que se reenvia el correo del sistema (root/postmaster); vacio = entrega local en /var/mail/root', 'Mail Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('46', 'ftp_db', 'FTP Database', 'bulwark_proftpd', NULL, 'The name of the ftp server database', 'FTP Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('47', 'ftp_php', 'FTP PHP', 'proftpd.php', NULL, 'Name of PHP to include when adding FTP data.', 'FTP Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('50', 'ftp_config_file', 'FTP Config File', '/usr/local/etc/bulwark/proftpd/proftpd-mysql.conf', NULL, 'The path to the configuration file if applicable.', 'FTP Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('51', 'mailserver_db', 'Mailserver Database', 'bulwark_postfix', NULL, 'The name of the mail server database', 'Mail Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('52', 'hmailserver_et', 'Hmail Encryption Type', '2', NULL, 'Type of encryption uses for hMailServer passwords', 'Mail Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('53', 'max_mail_size', 'Max Mailbox Size', '200', NULL, 'Maximum size in megabytes allowed for mailboxes. Default = 200', 'Mail Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('54', 'mailserver_php', 'Mailserver PHP', 'postfix.php', NULL, 'Name of PHP to include when adding mailbox data.', 'Mail Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('55', 'remove_orphan', 'Remove Orphans', 'true', 'true|false', 'When domains are deleted, also delete all mailboxes for that domain when the daemon runs. (true/false)', 'Mail Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('56', 'named_dir', 'Named Directory', '/usr/local/etc/bulwark/bind/etc/', NULL, 'Path to the directory where named.conf is stored', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('57', 'named_conf', 'Named Config', 'named.conf', NULL, 'Named configuration file', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('58', 'zone_dir', 'Zone Directory', '/usr/local/etc/bulwark/bind/zones/', NULL, 'Path to where DNS zone files are stored', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('59', 'refresh_ttl', 'SOA Refesh TTL', '21600', NULL, 'Global refresh TTL.  Default = 21600 (6 hours)', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('60', 'retry_ttl', 'SOA Retry TTL', '3600', NULL, 'Global retry TTL. Default = 3600 (1 hour)', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('61', 'expire_ttl', 'SOA Expire TTL', '604800', NULL, 'Global expire TTL. Default = 86400 (1 day)', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('62', 'minimum_ttl', 'SOA Minimum TTL', '86400', NULL, 'Global minimum TTL. Default = 86400 (1 day)', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('63', 'custom_ip', 'Allow Custom IP', 'true', 'true|false', 'Allow users to change IP settings in A records. If set to false, IP is locked to server IP setting in Bulwark Config', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('66', 'allow_xfer', 'Allow Zone Transfers', 'trusted-servers', NULL, 'Setting to restrict zone transfers in setting: allow-transfer {}; Default = all', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('67', 'allowed_types', 'Allowed Record Types', 'A AAAA CNAME MX TXT SRV SPF NS CAA NAPTR SSHFP TLSA URI', NULL, 'Types of records allowed seperated by a space. Default = A AAAA CNAME MX TXT SRV SPF NS', 'DNS Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('68', 'bind_log', 'Bind Log', '/var/bulwark/logs/bind/bind.log', NULL, 'Path and name of the Bind Log', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('69', 'hosted_dir', 'Vhosts Directory', '/var/bulwark/hostdata/', NULL, 'Virtual host directory', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('70', 'disable_hostsen', 'Disable HOSTS file entries', 'false', 'true|false', 'Disable host entries', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('71', 'apache_vhost', 'Apache VHOST Conf', '/usr/local/etc/bulwark/apache/httpd-vhosts.conf', NULL, 'The full system path and filename of the Apache VHOST configuration name.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('72', 'php_handler', 'PHP Handler', '<FilesMatch \"\\.php$\">\n    SetHandler \"proxy:unix:/var/run/php-fpm/www.sock|fcgi://localhost/\"\n</FilesMatch>', NULL, 'The PHP Handler.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('73', 'cgi_handler', 'CGI Handler', 'ScriptAlias /cgi-bin/ \"/_cgi-bin/\"\r\n<location /cgi-bin>\r\nAddHandler cgi-script .cgi .pl\r\nOptions +ExecCGI -Indexes\r\n</location>', NULL, 'The CGI Handler.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('74', 'global_vhcustom', 'Global VHost Entry', NULL, NULL, 'Extra directives for all apache vhost\'s.', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('75', 'static_dir', 'Static Pages Directory', '/usr/local/bulwark/etc/static/', NULL, 'The Bulwark static directory, used for storing welcome pages etc. etc.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('76', 'parking_path', 'Vhost Parking Path', '/usr/local/bulwark/etc/static/parking/', NULL, 'The path to the parking website, this will be used by all clients.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('79', 'upload_temp_dir', 'Upload Temp Directory', '/var/bulwark/temp/', NULL, 'The path to the Apache Upload directory (with trailing slash)', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('80', 'apache_port', 'Apache Port', '80', NULL, 'Apache service port', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('81', 'dir_index', 'Directory Indexes', 'DirectoryIndex index.html index.htm index.php index.asp index.aspx index.jsp index.jspa index.shtml index.shtm', NULL, 'Directory Index', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('83', 'openbase_seperator', 'Open Base Seperator', ':', NULL, 'Seperator flag used in open_base_directory setting', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('84', 'openbase_temp', 'Open Base Temp Directory', '/var/bulwark/temp/', NULL, 'Temp directory used in open_base_directory setting', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('85', 'access_log_format', 'Access Log Format', 'combined', 'combined|common', 'Log format for the Apache access log', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('86', 'bandwidth_log_format', 'Bandwidth Log Format', 'common', 'combined|common', 'Log format for the Apache bandwidth log', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('87', 'global_zpcustom', 'Global Bulwark Entry', NULL, NULL, 'Extra directives for Bulwark default vhost.', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('88', 'use_openbase', 'Use Open Base Dir', 'true', 'true|false', 'Enable openbase directory for all vhosts', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('90', 'bulwark_domain', 'Bulwark Domain', 'panel.pulpo.com', NULL, 'Domain that the control panel is installed under.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('91', 'log_dir', 'Log Directory', '/var/bulwark/logs/', NULL, 'Root path to directory log folders', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('92', 'apache_changed', 'Apache Changed', '1782818760', 'true|false', 'If set, Apache Config daemon hook will write the vhost config file changes.', 'Apache Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('94', 'apache_allow_disabled', 'Allow Disabled', 'false', 'true|false', 'Allow webhosts to remain active even if a user has been disabled.', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('95', 'apache_budir', 'VHost Backup Dir', '/var/bulwark/backups/', NULL, 'Directory that vhost.conf backups are stored.', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('96', 'apache_purgebu', 'Purge Backups', 'true', 'true|false', 'Old backups are deleted after the date set in Puge Date', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('97', 'apache_purge_date', 'Purge Date', '7', NULL, 'Time in days that vhost backups are safe from deletion', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('98', 'apache_backup', 'VHost Backup', 'true', 'true|false', 'Backup vhost file before a new one is written', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('102', 'apache_sn', 'Apache Service Name', 'apache24', NULL, 'Service name used to handle Apache service control', 'Apache Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('103', 'daemon_exer', NULL, '/usr/local/bulwark/bin/daemon.php', NULL, 'Path to the Bulwark daemon', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('104', 'daemon_timing', NULL, '0 * * * *', NULL, 'Cron time for when to run the Bulwark daemon', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('105', 'cron_file', 'Cron File', '/var/bulwark/cron/www.cron', NULL, 'Fichero crontab generado por el panel (staging www-writable, instalado en cron via doas)', 'Cron Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('107', 'mysqldump_exe', 'MySQL Dump', 'mysqldump', NULL, 'Path to MySQL dump', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('108', 'dns_hasupdates', 'DNS Updated', NULL, NULL, NULL, NULL, 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('109', 'named_checkconf', 'Named CheckConfig', '/usr/local/bin/named-checkconf', NULL, 'Path to named-checkconf bind utility.', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('110', 'named_checkzone', 'Named CheckZone', '/usr/local/bin/named-checkzone', NULL, 'Path to named-checkzone bind utility.', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('111', 'named_compilezone', 'Named CompileZone', '/usr/local/bin/named-compilezone', NULL, '	Path to named-compilezone bind utility.', 'DNS Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('112', 'mailer_type', 'Mail method', 'mail', 'mail|smtp', 'Method to use when sending emails out. (mail = PHP Mail())', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('113', 'daemon_run_interval', 'Number of seconds between each daemon execution', '300', NULL, 'The total number of seconds between each daemon run (default 300 = 5 mins)', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('114', 'debug_mode', 'Bulwark Debug Mode', 'prod', 'dev|prod', 'Whether or not to show PHP debug errors,warnings and notices', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('115', 'password_minlength', 'Min Password Length', '10', NULL, 'Minimum length required for new passwords', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('119', 'cron_reload_user', 'Cron Reload User', 'www', NULL, 'Cron reload apache user in Linux', 'Cron Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('120', 'login_csfr', 'Remote Login Forms', 'false', 'false|true', 'Disables CSFR protection on the login form to enable remote login forms.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('121', 'bulwark_port', 'Bulwark Apache Port', '443', NULL, 'Bulwark Apache panel port (change will be pending until next daemon run)', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('122', 'welcome_message', 'Custom e-mail Welcome Message', 'Hi {{fullname}},\nWe are pleased to inform you that your new hosting account is now active!\nYou can access your web hosting control panel using this link: {{controlpanelurl}}\nYour username and password is as follows:\nUsername: {{username}}\nPassword: {{password}}\nMany thanks,\nThe management', NULL, 'Here you can edit the Welcme Message e-mail', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('123', 'panel_ssl_tx', 'Bulwark Panel SSL Config', 'SSLEngine On\nSSLProtocol all -SSLv3 -TLSv1 -TLSv1.1\nSSLCipherSuite ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384\nSSLCertificateFile /usr/local/etc/bulwark/panel/recovery/selfsigned.crt\nSSLCertificateKeyFile /usr/local/etc/bulwark/panel/recovery/selfsigned.key', NULL, 'Bulwark SSL settings and certs', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('125', 'api_rest_enabled', 'REST API habilitada', 'false', NULL, 'Activa o desactiva el endpoint REST /bin/api.php', 'api_manager', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('126', 'api_disabled_message', 'Mensaje API desactivada', '', NULL, 'Mensaje mostrado cuando la API global está desactivada. Vacío = mensaje por defecto.', 'api_manager', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('127', 'log_retention_days', 'Log Retention Days', '30', NULL, 'Días que se conservan los logs internos del panel en x_logs antes de ser eliminados automáticamente.', 'Bulwark Config', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('128', 'fw_pf_enabled', 'PF Enabled', '1', NULL, 'Habilitar pf', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('129', 'fw_sshguard_enabled', 'SSHGuard Enabled', '1', NULL, 'Habilitar SSHGuard', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('130', 'fw_ban_time', 'Ban Time', '3600', NULL, 'Tiempo de ban SSHGuard (segundos)', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('131', 'fw_max_retry', 'Max Retry', '5', NULL, 'Intentos antes del ban', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('132', 'fw_find_time', 'Find Time', '600', NULL, 'Ventana de deteccion (segundos)', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('133', 'fw_status_json_path', 'Status JSON', '/var/bulwark/logs/fw_status.json', NULL, 'Ruta JSON estado cortafuegos', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('134', 'fw_login_max', 'Login Max Attempts', '5', NULL, 'Intentos de login fallidos antes de bloquear la IP', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('135', 'fw_login_window', 'Login Window (s)', '600', NULL, 'Ventana de tiempo en segundos para contar intentos fallidos', 'fw_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('136', 'antispam_score', 'Antispam Score', '6.0', NULL, 'Umbral global de puntuación de spam', 'antispam_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('137', 'antispam_action', 'Antispam Action', 'junk', NULL, 'Acción global por defecto (tag|junk|reject)', 'antispam_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('138', 'antispam_enabled', 'Antispam Enabled', 'true', NULL, 'Activar antispam globalmente', 'antispam_admin', 'false');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('139', 'panel_force_https', 'Forzar HTTPS en el panel', 'false', 'true|false', 'Redirige el acceso al panel de HTTP a HTTPS (excluye el challenge ACME de Lets Encrypt). Al guardar, el daemon regenera Apache.', 'Bulwark Config', 'true');
-- Nameservers compartidos del panel (modelo HestiaCP). Los fija el instalador; los
-- usan las zonas de todos los dominios (NS y SOA). Editables desde Bulwark Config.
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('140', 'dns_provider_domain', 'Dominio proveedor (DNS)', '', NULL, 'Dominio autoritativo del servidor (p.ej. tudominio.com) cuya zona base contiene ns1/ns2, panel y correo.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('141', 'dns_ns1', 'Nameserver 1', '', NULL, 'Hostname del primer nameserver (p.ej. ns1.tudominio.com). Se usa en NS y SOA de todas las zonas.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('142', 'dns_ns2', 'Nameserver 2', '', NULL, 'Hostname del segundo nameserver (p.ej. ns2.tudominio.com).', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('143', 'dns_ns1_ip', 'IP del nameserver 1', '', NULL, 'IP a la que apunta ns1 (registro A en la zona base). Por defecto la IP del servidor.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('144', 'dns_ns2_ip', 'IP del nameserver 2', '', NULL, 'IP a la que apunta ns2 (registro A en la zona base). En multi-servidor, la IP del segundo nodo.', 'Bulwark Config', 'true');
-- Cluster DNS (Fase 2): clave TSIG compartida del cluster (formato "nombre secreto_base64").
-- La genera el instalador (tsig-keygen). Se usa en allow-transfer/masters entre nodos.
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('145', 'dns_tsig_key', 'Clave TSIG del cluster DNS', '', NULL, 'Clave compartida hmac-sha256 para AXFR entre nodos (nombre y secreto). No compartir fuera del cluster.', 'Bulwark Config', 'true');
-- API de cluster DNS: interruptor propio (independiente del kill-switch de la API de
-- usuarios) y token compartido de los nodos. Desactivar la API de usuarios NO afecta a esto.
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('146', 'dns_cluster_enabled', 'Cluster DNS activado', 'false', 'true|false', 'Activa la API dedicada del cluster DNS (sync de zonas entre nodos). Independiente de la API de usuarios.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('147', 'dns_cluster_token', 'Token del cluster DNS', '', NULL, 'Secreto compartido que autentica las llamadas entre nodos del cluster. No compartir fuera del cluster.', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('177', 'dns_cluster_tls_verify', 'Verificacion TLS entre nodos', 'off', 'off|pin|ca', 'Como valida el canal de control (API) del cluster el certificado del peer: off=sin verificar (dev/LAN); pin=fija la clave publica del peer (autofirmado, corta MITM continuo); ca=verificacion completa contra la CA propia (dns_cluster_ca_file).', 'Bulwark Config', 'true');
INSERT INTO `x_settings` (`so_id_pk`, `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en`) VALUES ('178', 'dns_cluster_ca_file', 'CA propia del cluster (fichero)', '', NULL, 'Ruta al bundle PEM de la CA propia que firma los certs de los nodos (para dns_cluster_tls_verify=ca). Vacio = usar el almacen de CAs del sistema.', 'Bulwark Config', 'true');

TRUNCATE TABLE `x_groups`;
INSERT INTO `x_groups` (`ug_id_pk`, `ug_name_vc`, `ug_notes_tx`, `ug_reseller_fk`) VALUES ('1', 'Administrators', 'The main administration group, this group allows access to all areas of Bulwark.', '1');
INSERT INTO `x_groups` (`ug_id_pk`, `ug_name_vc`, `ug_notes_tx`, `ug_reseller_fk`) VALUES ('2', 'Resellers', 'Resellers have the ability to manage, create and maintain user accounts within Bulwark.', '1');
INSERT INTO `x_groups` (`ug_id_pk`, `ug_name_vc`, `ug_notes_tx`, `ug_reseller_fk`) VALUES ('3', 'Users', 'Users have basic access to Bulwark.', '1');

-- Cuenta administradora inicial (zadmin). La contraseña/salt los fija el instalador
-- con bin/setzadmin (UPDATE ... WHERE ac_user_vc='zadmin'); aquí solo el registro base.
TRUNCATE TABLE `x_accounts`;
INSERT INTO `x_accounts` (`ac_id_pk`, `ac_user_vc`, `ac_pass_vc`, `ac_email_vc`, `ac_reseller_fk`, `ac_package_fk`, `ac_group_fk`, `ac_usertheme_vc`, `ac_usercss_vc`, `ac_enabled_in`, `ac_passsalt_vc`) VALUES
('1', 'zadmin', '', 'postmaster@localhost', '0', '1', '1', 'Bulwark_Default', '', '1', '');
TRUNCATE TABLE `x_profiles`;
INSERT INTO `x_profiles` (`ud_id_pk`, `ud_user_fk`, `ud_fullname_vc`, `ud_language_vc`, `ud_group_fk`, `ud_package_fk`) VALUES
('1', '1', 'Master Administrator', 'en', '1', '1');

-- Permisos por grupo (grupo -> módulo). El volcado original perdió estas filas y
-- sin ellas el menú lateral queda casi vacío. Se generan por nombre de carpeta
-- (independiente de los IDs de módulo, que varían entre forks).
-- Los módulos propios (fw_admin, antispam, api_manager, clamav...) se auto-conceden
-- en su install.sql al registrarse.
TRUNCATE TABLE `x_permissions`;
-- Administradores (grupo 1): acceso a TODOS los módulos registrados.
INSERT INTO `x_permissions` (`pe_group_fk`, `pe_module_fk`)
  SELECT '1', `mo_id_pk` FROM `x_modules`;
-- Usuarios (grupo 3): solo módulos de cliente (nunca módulos de administración).
INSERT INTO `x_permissions` (`pe_group_fk`, `pe_module_fk`)
  SELECT '3', `mo_id_pk` FROM `x_modules` WHERE `mo_folder_vc` IN (
    'my_account','password_assistant','domains','sub_domains','parked_domains',
    'mailboxes','forwarders','aliases','distlists','ftp_management',
    'mysql_databases','mysql_users','protected_directories','cron','backupmgr',
    'usage_viewer','webalizer_stats','phpinfo','phpmyadmin','faqs','webmail',
    'sencrypt','dns_manager','user_logviewer','antispam','clamav_user','imapsync');
-- Resellers (grupo 2): lo de usuario + gestión de clientes/paquetes/grupos.
INSERT INTO `x_permissions` (`pe_group_fk`, `pe_module_fk`)
  SELECT '2', `mo_id_pk` FROM `x_modules` WHERE `mo_folder_vc` IN (
    'my_account','password_assistant','domains','sub_domains','parked_domains',
    'mailboxes','forwarders','aliases','distlists','ftp_management',
    'mysql_databases','mysql_users','protected_directories','cron','backupmgr',
    'usage_viewer','webalizer_stats','phpinfo','phpmyadmin','faqs','webmail',
    'sencrypt','dns_manager','user_logviewer',
    'antispam','clamav_user',
    'manage_clients','packages','shadowing','client_notices','manage_groups','imapsync');

TRUNCATE TABLE `x_quotas`;
INSERT INTO `x_quotas` (`qt_id_pk`, `qt_package_fk`, `qt_domains_in`, `qt_subdomains_in`, `qt_parkeddomains_in`, `qt_mailboxes_in`, `qt_fowarders_in`, `qt_distlists_in`, `qt_ftpaccounts_in`, `qt_mysql_in`, `qt_cronjobs_in`, `qt_php_memory_vc`, `qt_php_upload_vc`, `qt_php_post_vc`, `qt_php_exec_in`, `qt_php_maxinput_in`, `qt_diskspace_bi`, `qt_bandwidth_bi`, `qt_bwenabled_in`, `qt_dlenabled_in`, `qt_totalbw_fk`, `qt_minbw_fk`, `qt_maxcon_fk`, `qt_filesize_fk`, `qt_filespeed_fk`, `qt_filetype_vc`, `qt_modified_in`) VALUES ('1', '1', '-1', '-1', '-1', '-1', '-1', '-1', '-1', '-1', '0', '2G', '1G', '1G', '300', '600', '0', '0', '0', '0', NULL, NULL, NULL, NULL, NULL, '*', '1');
INSERT INTO `x_quotas` (`qt_id_pk`, `qt_package_fk`, `qt_domains_in`, `qt_subdomains_in`, `qt_parkeddomains_in`, `qt_mailboxes_in`, `qt_fowarders_in`, `qt_distlists_in`, `qt_ftpaccounts_in`, `qt_mysql_in`, `qt_cronjobs_in`, `qt_php_memory_vc`, `qt_php_upload_vc`, `qt_php_post_vc`, `qt_php_exec_in`, `qt_php_maxinput_in`, `qt_diskspace_bi`, `qt_bandwidth_bi`, `qt_bwenabled_in`, `qt_dlenabled_in`, `qt_totalbw_fk`, `qt_minbw_fk`, `qt_maxcon_fk`, `qt_filesize_fk`, `qt_filespeed_fk`, `qt_filetype_vc`, `qt_modified_in`) VALUES ('2', '2', '0', '0', '0', '0', '0', '0', '0', '0', '0', '128M', '50M', '50M', '30', '60', '0', '0', '0', '0', NULL, NULL, NULL, NULL, NULL, '*', '1');
INSERT INTO `x_quotas` (`qt_id_pk`, `qt_package_fk`, `qt_domains_in`, `qt_subdomains_in`, `qt_parkeddomains_in`, `qt_mailboxes_in`, `qt_fowarders_in`, `qt_distlists_in`, `qt_ftpaccounts_in`, `qt_mysql_in`, `qt_cronjobs_in`, `qt_php_memory_vc`, `qt_php_upload_vc`, `qt_php_post_vc`, `qt_php_exec_in`, `qt_php_maxinput_in`, `qt_diskspace_bi`, `qt_bandwidth_bi`, `qt_bwenabled_in`, `qt_dlenabled_in`, `qt_totalbw_fk`, `qt_minbw_fk`, `qt_maxcon_fk`, `qt_filesize_fk`, `qt_filespeed_fk`, `qt_filetype_vc`, `qt_modified_in`) VALUES ('3', '3', '0', '0', '0', '0', '0', '0', '0', '0', '0', '128M', '50M', '50M', '30', '60', '0', '0', '0', '0', NULL, NULL, NULL, NULL, NULL, '*', '0');
-- Multi-IP: paquete de administración (1) sin límite de IPs dedicadas; el resto 0 (default).
UPDATE `x_quotas` SET `qt_dedicatedips_in` = -1 WHERE `qt_package_fk` = 1;

TRUNCATE TABLE `x_packages`;
INSERT INTO `x_packages` (`pk_id_pk`, `pk_name_vc`, `pk_reseller_fk`, `pk_enablephp_in`, `pk_created_ts`, `pk_deleted_ts`) VALUES ('1', 'Administration', '1', '1', '1781805540', NULL);
INSERT INTO `x_packages` (`pk_id_pk`, `pk_name_vc`, `pk_reseller_fk`, `pk_enablephp_in`, `pk_created_ts`, `pk_deleted_ts`) VALUES ('2', 'Demo', '1', '0', '1781805540', NULL);
INSERT INTO `x_packages` (`pk_id_pk`, `pk_name_vc`, `pk_reseller_fk`, `pk_enablephp_in`, `pk_created_ts`, `pk_deleted_ts`) VALUES ('3', 'ventas', '7', '1', '1781901353', NULL);

TRUNCATE TABLE `x_translations`;
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('44', 'Webmail is a convenient way for you to check your email accounts online without the need to configure an email client.', 'Webmail ist ein bequemer Weg für Sie, Ihre E-Mail-Konten online zu überprüfen, ohne dass eine E-Mail-Client zu konfigurieren.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('45', 'Launch Webmail', 'Starten Sie WebMail');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('56', 'PHPInfo provides you with information regarding the version of PHP running on this system as well as installed PHP extentsions and configuration details.', 'PHPInfo bietet Ihnen Informationen über die PHP-Version auf dem System, sowie PHP installiert extentsions und Konfigurationsmöglichkeiten.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('67', 'From here you can shadow any of your client\'s accounts, this enables you to automatically login as the user which enables you to offer remote help by seeing what they see!', 'Von hier aus können alle Ihre Kunden-Accounts können Schatten, ermöglicht Ihnen dies automatisch, wenn der Benutzer mit dem Sie remote helfen zu sehen, was sie sehen, anbieten zu können login!');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('68', 'My Account', 'Meine Konto');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('69', 'Change Password', 'Kennwort ändern');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('70', 'Shadowing', 'Schatten');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('71', 'Bulwark Config', 'Config Bulwark');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('72', 'Bulwark News', 'Bulwark Aktuelles');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('73', 'Updates', 'Aktualisierung');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('74', 'Report Bug', 'Fehler melden');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('75', 'Account', 'Konto');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('76', 'Module Admin', 'Modul Admin');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('77', 'Backup', 'Sicherungskopie');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('78', 'Network Tools', 'Netzwerk-Tools');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('79', 'Service Status', 'Service Status');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('80', 'PHPInfo', 'PHPInfo');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('81', 'phpMyAdmin', 'phpMyAdmin');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('82', 'Domains', 'Domains');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('83', 'Sub Domains', 'Sub Domains');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('84', 'Parked Domains', 'geparkte Domains');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('85', 'Manage Clients', 'Verwalten Kunden');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('86', 'Package Manager', 'Paket Manager');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('87', 'Server', 'Server');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('88', 'Database', 'Datenbank');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('89', 'Advanced', 'Fortgeschritten');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('90', 'Mail', 'Post');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('91', 'Reseller', 'Wiederverkäufer');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('92', 'Account Information', 'Account Informationen');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('93', 'Server Admin', 'Server Admin');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('94', 'Database Management', 'Datenbank Verwalten');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('95', 'Domain Management', 'Verwalten von Domains');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('97', 'Check to see if there are any available updates to your version of the Bulwark software.', 'Prüfen Sie, ob es irgendwelche verfügbaren Aktualisierungen für Ihre Version des Bulwark Software.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('98', 'If you have found a bug with Bulwark you can report it here.', 'Did you mean: If you have found a bug with CPanel you can report it here.\r\nWenn Sie einen Fehler mit Bulwark gefunden haben, können Sie ihn hier melden.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('99', 'phpMyAdmin is a web based tool that enables you to manage your Bulwark MySQL databases via. the web.', 'phpMyAdmin ist ein webbasiertes Tool, das Sie zu Ihrem Bulwark MySQL-Datenbanken via verwalten können. im Internet.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('100', 'Current personal details that you have provided us with, We ask that you keep these upto date in case we require to contact you regarding your hosting package.', 'Aktuelle persönlichen Daten, die Sie uns mit vorgesehen ist, bitten wir Sie, diese zu halten bis zu Datum, falls wir mit Ihnen Kontakt aufnehmen über Ihre Hosting-Paket erfordern.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('101', 'Webmail is a convenient way for you to check your email accounts online without the need to configure an email client.', 'Webmail ist ein bequemer Weg für Sie, Ihre E-Mail-Konten online zu überprüfen, ohne dass eine E-Mail-Client zu konfigurieren.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('102', 'Change your current control panel password.', 'Ändern Sie Ihre aktuelle Bedienfeld oder MySQL-Kennwort.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('103', 'The backup manager module enables you to backup your entire hosting account including all your MySQL&reg; databases.', 'Der Backup-Manager-Modul ermöglicht es Ihnen, Ihre gesamte Hosting-Account inklusive aller Ihrer MySQL &reg; Datenbank-Backup.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('104', 'You can use the tools below to diagnose issues or to simply test connectivity to other servers or sites around the globe.', 'Sie können die folgenden Tools verwenden, um Probleme zu diagnostizieren oder einfach testen Verbindung mit anderen Servern oder Websites rund um den Globus.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('105', 'Here you can check the current status of our services and see what services are up and running and which are down and not.', 'Hier können Sie den aktuellen Status unserer Dienstleistungen und sehen, welche Dienste vorhanden sind und laufen, und die nach unten und es nicht sind.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('106', 'This module enables you to add or configure domain web hosting on your account.', 'Dieses Modul ermöglicht es Ihnen, hinzuzufügen oder zu konfigurieren Domain Hosting auf Ihrem Konto.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('107', 'Domain parking refers to the registration of an Internet domain name without that domain being used to provide services such as e-mail or a website. If you have any domains that you are not using, then simply park them!', 'Domain-Parking bezieht sich auf die Registrierung von Internet Domain-Namen ohne diese Domäne verwendet, um Dienste wie E-Mail oder eine Webseite bereitzustellen. Wenn Sie alle Domains, die Sie nicht haben, dann einfach parken sie!');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('108', 'This module enables you to add or configure domain web hosting on your account.', 'Dieses Modul ermöglicht es Ihnen, hinzuzufügen oder zu konfigurieren Domain Hosting auf Ihrem Konto.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('109', 'Administer or configure modules registered with module admin', 'Verwalten oder zu konfigurieren Module mit Modul admin registriert');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('110', 'The account manager enables you to view, update and create client accounts.', 'Die Account-Manager ermöglicht es Ihnen, anzuzeigen, zu aktualisieren und erstellen Kundenkonten.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('111', 'Welcome to the Package Manager, using this module enables you to create and manage existing reseller packages on your Bulwark hosting account.', 'Willkommen auf der Paket-Manager, mit diesem Modul ermöglicht Ihnen die Erstellung und Verwaltung von bestehenden Reseller-Pakete auf Ihrem Bulwark Hosting-Account.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('112', 'Gives you access to your files with drag-and-drop, multiple file uploading, text editing, zip support.', 'Ermöglicht den Zugriff auf Ihre Dateien mit Drag-and-drop, multiple Datei-Upload, Textbearbeitung, zip unterstützen.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('113', 'Secure FTP Applet is a JAVA based FTP client component that runs within your web browser. It is designed to let non-technical users exchange data securely with an FTP server.', 'Secure FTP Applet ist eine Java-basierte FTP-Client-Komponente, die in Ihrem Web-Browser läuft. Es wurde entwickelt, um nicht-technische Anwender den Datenaustausch secureiy lassen mit einem FTP-Server.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('114', 'Full name', 'Vollständiger Name');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('115', 'Email Address', 'E-Mail Adresse');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('116', 'Phone Number', 'Telefonnummer');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('117', 'Choose Language', 'Sprache wählen');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('118', 'Postal Address', 'Postanschrift');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('119', 'Postal Code', 'Postleitzahl');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('120', 'Current personal details that you have provided us with, We ask that you keep these upto date in case we require to contact you regarding your hosting package.', 'Aktuelle persönlichen Daten, die Sie uns mit vorgesehen ist, bitten wir Sie, diese zu halten bis zu Datum, falls wir mit Ihnen Kontakt aufnehmen über Ihre Hosting-Paket erfordern.');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('121', 'Changes to your account settings have been saved successfully!', 'Änderungen an Ihrem Konto-Einstellungen wurden erfolgreich gespeichert!');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('122', 'Update Account', 'Aktualisierung Konto');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('123', 'Enter your account details', 'Geben Sie Ihre Kontodaten');
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('125', 'Home', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('126', 'File', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('127', 'FTP Accounts', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('128', 'Client Notice Manager', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('129', 'Manage Groups', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('130', 'Theme Manager', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('131', 'Aliases', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('132', 'Distribution Lists', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('133', 'Forwards', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('134', 'Mailboxes', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('135', 'WebMail', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('136', 'Domain', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('137', 'DNS Manager', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('138', 'Sencrypt SSL', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('139', 'MySQL Database', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('140', 'MySQL Users', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('141', 'Cron Manager', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('142', 'FAQ\\\\\\\'s', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('143', 'Protected Directories', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('144', 'View Logs', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('145', 'Webalizer Stats', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('146', 'Admin', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('147', 'AutoIP Updater', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('148', 'phpSysInfo', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('149', 'Usage Viewer', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('150', 'Unlimited', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('151', 'Current personal details that you have provided us with, We ask that you keep these upto date in case we require to contact you regarding your hosting package.\r\n', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('152', 'Account Info', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('153', 'Account Usage', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('154', 'Server Information', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('155', 'Domain Information', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('156', 'Username', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('157', 'Package name', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('158', 'Account type', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('159', 'Last Logon', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('160', 'Disk Quota', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('161', 'Bandwidth Quota', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('162', 'Used', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('163', 'Max', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('164', 'Sub-domains', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('165', 'Email Accounts', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('166', 'Email Forwarders', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('167', 'MySQL&reg; databases', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('168', 'Your IP', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('169', 'Server IP', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('170', 'Server OS', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('171', 'Apache Version', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('172', 'PHP Version', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('173', 'MySQL Version', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('174', 'Bulwark Version', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('175', 'Server uptime', NULL);
INSERT INTO `x_translations` (`tr_id_pk`, `tr_en_tx`, `tr_de_tx`) VALUES ('176', 'FAQ\\\'s', NULL);


SET FOREIGN_KEY_CHECKS=1;
