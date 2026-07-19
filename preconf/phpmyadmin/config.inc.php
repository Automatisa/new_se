<?php

###############################################
# PHPMyAdmin Configuration for Bulwark        #
###############################################

/*
 * Secret used to encrypt cookies — must be a random string of 32+ chars.
 * Generated during Bulwark install: openssl rand -base64 32
 */
$cfg['blowfish_secret'] = '!PHPMYADMIN_SECRET!';

/*
 * Servers configuration
 */
$i = 0;
$i++;

/* Authentication type */
$cfg['Servers'][$i]['auth_type'] = 'cookie';

/* Server parameters — use Unix socket for localhost (faster, no TCP overhead) */
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['connect_type'] = 'socket';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;

/*
 * Directories for saving/loading files from server
 */
$cfg['UploadDir'] = '/var/bulwark/temp/';
$cfg['SaveDir'] = '/var/bulwark/temp/';

/*
 * Bulwark-specific restrictions
 */
$cfg['ShowCreateDb'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['AllowUserDropDatabase'] = false;
$cfg['PmaNoRelation_DisableWarning'] = true;
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|sys|performance_schema)$';
$cfg['ShowServerInfo'] = false;
$cfg['LoginCookieValidity'] = 1800;
