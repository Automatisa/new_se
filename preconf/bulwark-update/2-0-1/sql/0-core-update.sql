USE `bulwark_core`;

/* Update the bulwark database version number */
UPDATE `x_settings` SET `so_value_tx` = '2.0.1' WHERE `so_name_vc` = 'dbversion';