USE `bulwark_core`;

/* Update the bulwark database version number */
UPDATE `x_settings` SET `so_value_tx` = '1.0.4' WHERE `so_name_vc` = 'dbversion';

/* Expand DNS target for DKIM sizing */
ALTER TABLE `x_dns` CHANGE `dn_target_vc` `dn_target_vc` VARCHAR(2000) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL;

/* add vhost vh_ssl_tx column after vh_obasedir_in x_vhosts */
ALTER TABLE `x_vhosts` ADD COLUMN `vh_ssl_tx` text NULL AFTER `vh_obasedir_in`;

/* add vhost vh_ssl_port_in column after x_ssl_tx */
ALTER TABLE `x_vhosts` ADD COLUMN `vh_ssl_port_in` INT(6) NULL AFTER `vh_ssl_tx`;

/* Enter new tables to x_settings bulwark database for apache_admin vhost SSL*/
INSERT INTO `x_settings` ( `so_name_vc`, `so_cleanname_vc`, `so_value_tx`, `so_defvalues_tx`, `so_desc_tx`, `so_module_vc`, `so_usereditable_en` ) VALUES ('panel_ssl_tx', 'Bulwark Panel SSL Config', null, null, 'Bulwark SSL settings and certs', 'Bulwark Config', true); 