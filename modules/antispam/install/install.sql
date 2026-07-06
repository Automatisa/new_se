-- Antispam module installation SQL
-- Adds antispam columns to x_mailboxes and creates x_antispam_lists

ALTER TABLE x_mailboxes
    ADD COLUMN IF NOT EXISTS mb_antispam_in   tinyint(1)    NOT NULL DEFAULT 1     COMMENT '1=antispam enabled, 0=disabled',
    ADD COLUMN IF NOT EXISTS mb_spam_score    decimal(4,1)  DEFAULT NULL            COMMENT 'Personal spam score threshold (NULL=use global)',
    ADD COLUMN IF NOT EXISTS mb_spam_action   varchar(10)   DEFAULT NULL            COMMENT 'tag|junk|reject|NULL (NULL=use global)';

CREATE TABLE IF NOT EXISTS `x_antispam_lists` (
    `al_id_pk`       int(10) unsigned NOT NULL AUTO_INCREMENT,
    `al_mailbox_fk`  int(10) unsigned NOT NULL,
    `al_address_vc`  varchar(255)     NOT NULL,
    `al_type_vc`     enum('white','black') NOT NULL DEFAULT 'white',
    `al_created_ts`  int(30) unsigned DEFAULT NULL,
    PRIMARY KEY (`al_id_pk`),
    KEY `al_mailbox_fk` (`al_mailbox_fk`),
    UNIQUE KEY `al_mailbox_address` (`al_mailbox_fk`, `al_address_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Register user module
INSERT IGNORE INTO x_modules (mo_category_fk, mo_name_vc, mo_version_in, mo_folder_vc, mo_type_en, mo_desc_tx, mo_installed_ts, mo_enabled_en)
VALUES (6, 'Antispam', 100, 'antispam', 'user', 'Manage spam filtering per mailbox.', UNIX_TIMESTAMP(), 'true');

-- Grant to all groups
INSERT IGNORE INTO x_permissions (pe_group_fk, pe_module_fk)
SELECT g.ug_id_pk, m.mo_id_pk FROM x_groups g, x_modules m WHERE m.mo_folder_vc = 'antispam';

-- Register admin module
INSERT IGNORE INTO x_modules (mo_category_fk, mo_name_vc, mo_version_in, mo_folder_vc, mo_type_en, mo_desc_tx, mo_installed_ts, mo_enabled_en)
VALUES (6, 'Antispam Admin', 100, 'antispam_admin', 'modadmin', 'Global antispam administration.', UNIX_TIMESTAMP(), 'true');

-- Grant antispam_admin only to Administrators group (same pattern as apache_admin, dns_admin)
INSERT IGNORE INTO x_permissions (pe_group_fk, pe_module_fk)
SELECT 1, mo_id_pk FROM x_modules WHERE mo_folder_vc = 'antispam_admin';

-- Default global antispam settings (required for SetSystemOption UPDATE to work)
INSERT IGNORE INTO x_settings (so_name_vc, so_value_tx) VALUES
    ('antispam_score',   '6.0'),
    ('antispam_action',  'junk'),
    ('antispam_enabled', 'true');
