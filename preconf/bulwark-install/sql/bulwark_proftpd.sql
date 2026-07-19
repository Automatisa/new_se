-- bulwark_proftpd schema dump 2026-06-30

CREATE DATABASE IF NOT EXISTS `bulwark_proftpd` DEFAULT CHARACTER SET utf8mb3;
USE `bulwark_proftpd`;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `ftpgroup`;
CREATE TABLE `ftpgroup` (
  `groupname` varchar(16) NOT NULL DEFAULT '',
  `gid` smallint(6) NOT NULL DEFAULT 5500,
  `members` varchar(16) NOT NULL DEFAULT '',
  KEY `groupname` (`groupname`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='ProFTP group table';

DROP TABLE IF EXISTS `ftpquotalimits`;
CREATE TABLE `ftpquotalimits` (
  `name` varchar(30) DEFAULT NULL,
  `quota_type` enum('user','group','class','all') NOT NULL DEFAULT 'user',
  `per_session` enum('false','true') NOT NULL DEFAULT 'false',
  `limit_type` enum('soft','hard') NOT NULL DEFAULT 'soft',
  `bytes_in_avail` int(10) unsigned NOT NULL DEFAULT 0,
  `bytes_out_avail` int(10) unsigned NOT NULL DEFAULT 0,
  `bytes_xfer_avail` int(10) unsigned NOT NULL DEFAULT 0,
  `files_in_avail` int(10) unsigned NOT NULL DEFAULT 0,
  `files_out_avail` int(10) unsigned NOT NULL DEFAULT 0,
  `files_xfer_avail` int(10) unsigned NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `ftpquotatallies`;
CREATE TABLE `ftpquotatallies` (
  `name` varchar(30) NOT NULL DEFAULT '',
  `quota_type` enum('user','group','class','all') NOT NULL DEFAULT 'user',
  `bytes_in_used` int(10) unsigned NOT NULL DEFAULT 0,
  `bytes_out_used` int(10) unsigned NOT NULL DEFAULT 0,
  `bytes_xfer_used` int(10) unsigned NOT NULL DEFAULT 0,
  `files_in_used` int(10) unsigned NOT NULL DEFAULT 0,
  `files_out_used` int(10) unsigned NOT NULL DEFAULT 0,
  `files_xfer_used` int(10) unsigned NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

DROP TABLE IF EXISTS `ftpuser`;
CREATE TABLE `ftpuser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `userid` varchar(50) NOT NULL DEFAULT '',
  `passwd` varchar(128) NOT NULL,
  `uid` smallint(6) NOT NULL DEFAULT 0,
  `gid` smallint(6) NOT NULL DEFAULT 0,
  `homedir` varchar(255) NOT NULL DEFAULT '',
  `shell` varchar(16) NOT NULL DEFAULT '/sbin/nologin',
  `count` int(11) NOT NULL DEFAULT 0,
  `accessed` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='ProFTP user table';


SET FOREIGN_KEY_CHECKS=1;
