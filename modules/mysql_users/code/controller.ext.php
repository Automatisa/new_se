<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
class module_controller extends ctrl_module
{

    static $alreadyexists;
    static $dbalreadyadded;
    static $blank;
	static $blankpassword;
    static $badname;
    static $badpass;
	static $badpasswordlength;
    static $rootabuse;
    static $badIP;
    static $accessConflict;
    static $ok;
    static $newPassword = null;

    /**
     * The 'worker' methods.
     */
	# tg - Set user name input max characters minus prefix
    static function getMaxCharAllowed()
    {
        global $zdbh;
        global $controller;
		
        $currentuser = ctrl_users::GetUserDetail($uid);

        if ($username < 33)
		{
			$username = $currentuser['username'] . "_";
			$prefixsize = strlen($username);
			$maxChar = 32-$prefixsize;

            return $maxChar;
        } else {
            return false;
        }
    }

    static function CleanOrphanDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_dbmap WHERE mm_user_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $rowdbSql = "SELECT * FROM x_mysql_databases WHERE my_id_pk=:id AND my_deleted_ts IS NULL";
                $find = $zdbh->prepare($rowdbSql);
                $find->bindParam(':id', $rowmysql['mm_database_fk']);
                $find->execute();
                $rowdb = $find->fetch();

                if (!$rowdb) {

                }
            }
            return true;
        } else {
            return false;
        }
    }

    static function ListUsers($uid)
    {
        global $zdbh;
        // Remove deleted databases from MySQL userlist...
        self::CleanOrphanDatabases($uid);
        $sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $uid);
            $res = array();
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                // Fetch linked database names instead of just the count.
                $dbq = $zdbh->prepare(
                    "SELECT d.my_name_vc FROM x_mysql_databases d
                     JOIN x_mysql_dbmap m ON d.my_id_pk = m.mm_database_fk
                     WHERE m.mm_user_fk=:mysql AND d.my_deleted_ts IS NULL"
                );
                $dbq->bindParam(':mysql', $rowmysql['mu_id_pk']);
                $dbq->execute();
                $dbnames = $dbq->fetchAll(PDO::FETCH_COLUMN, 0);

                if ($rowmysql['mu_access_vc'] == "%") {
                    $access = "ANY";
                } else {
                    $access = $rowmysql['mu_access_vc'];
                }
                array_push($res, array(
                    'userid'     => $rowmysql['mu_id_pk'],
                    'username'   => $rowmysql['mu_name_vc'],
                    'dbpassword' => $rowmysql['mu_pass_vc'],
                    'totaldb'    => count($dbnames),
                    'dbnames'    => !empty($dbnames) ? implode(', ', array_map('htmlspecialchars', $dbnames)) : '&mdash;',
                    'accesshtml' => $access,
                    'access'     => $rowmysql['mu_access_vc'],
                ));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDatabases($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_mysql_databases WHERE my_acc_fk=:userid AND my_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $uid);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                array_push($res, array('mysqlid' => $rowmysql['my_id_pk'],
                    'mysqlname' => $rowmysql['my_name_vc']));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListUserDatabases($uid)
    {
        global $zdbh;
        // IDOR/fuga: filtrar los mapeos por la cuenta actual (mm_acc_fk); sin esto, con el id de
        // un usuario MySQL ajeno se listaban las BD mapeadas de otro cliente.
        $owner = (int) ctrl_users::GetUserDetail()['userid'];
        $sql = "SELECT * FROM x_mysql_dbmap WHERE mm_user_fk=:userid AND mm_acc_fk=:owner";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $uid);
        $numrows->bindValue(':owner', $owner, PDO::PARAM_INT);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':userid', $uid);
            $sql->bindValue(':owner', $owner, PDO::PARAM_INT);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:database AND my_deleted_ts IS NULL");
                $numrows->bindParam(':database', $rowmysql['mm_database_fk']);
                $numrows->execute();
                $rowdb = $numrows->fetch();
                if ($rowdb) {
                    array_push($res, array('mmid' => $rowmysql['mm_id_pk'],
                        'mmaccount' => $rowmysql['mm_acc_fk'],
                        'mmuserid' => $rowmysql['mm_user_fk'],
                        'mmdbid' => $rowmysql['mm_database_fk'],
                        'mmdbname' => $rowdb['my_name_vc']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentUser($mid)
    {
        global $zdbh;
        // IDOR/fuga: filtrar por dueño; ?other=<id ajeno> mostraba el usuario MySQL de otro.
        $uid = (int) ctrl_users::GetUserDetail()['userid'];
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mid AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':mid', $mid);
        $numrows->bindValue(':uid', $uid, PDO::PARAM_INT);
        $numrows->execute();

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mid AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
            $res = array();
            $sql->bindParam(':mid', $mid);
            $sql->bindValue(':uid', $uid, PDO::PARAM_INT);
            $sql->execute();
            while ($rowmysql = $sql->fetch()) {
                array_push($res, array(
                    'userid'   => $rowmysql['mu_id_pk'],
                    'username' => $rowmysql['mu_name_vc'],
                    'access'   => $rowmysql['mu_access_vc'],
                ));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ExecuteCreateUser($uid, $username, $database, $access)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        // Check for spaces and remove if found...
        $username = strtolower(str_replace(' ', '', $username));
		// tg - Add prefix to DB user name
		$username = $currentuser['username'] . "_" . $username;
        // If errors are found, then exit before creating user...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($username, $database, $access))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateDatabaseUser');
        $password = fs_director::GenerateRandomPassword(16, 4);
        self::$newPassword = $password; // displayed once in success message, never stored plain
        $hashedPw = password_hash($password, PASSWORD_BCRYPT);
        // Create user in MySQL
        $sql = $zdbh->prepare("CREATE USER :username@:access;");
        $sql->bindParam(':username', $username);
        $sql->bindParam(':access', $access);
        $sql->execute();
        // Set MySQL password for new user — same exec() pattern as ExecuteResetPassword.
        $usernameEscC = str_replace("'", "''", (string)$username);
        $accessEscC   = str_replace("'", "''", (string)$access);
        $zdbh->exec("ALTER USER '$usernameEscC'@'$accessEscC' IDENTIFIED BY " . $zdbh->quote($password));
        // Get the database name from the ID...
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:database AND my_deleted_ts IS NULL");
        $numrows->bindParam(':database', $database);
        $numrows->execute();
        $rowdb = $numrows->fetch();
        // Remove all priveledges to all databases
        $sql = $zdbh->prepare("GRANT USAGE ON *.* TO :username@:access");
        $sql->bindParam(':username', $username);
        $sql->bindParam(':access', $access);
        $sql->execute();
        // Grant privileges for new user to the assigned database...
        // Security fix (June 2026): same pattern as ExecuteAddDB — build the
        // GRANT query as a plain string and execute via exec(). Use the
        // backtick-doubling escape (correct for MySQL identifier context),
        // not the single-quote escape that mysqlRealEscapeString() does.
        // All three values are whitelist-validated upstream by
        // IsValidUserName / IsValidAccessHost / admin-created database names.
        $my_name_vc   = str_replace('`', '``', (string)$rowdb['my_name_vc']);
        $usernameEsc  = str_replace('`', '``', (string)$username);
        $accessEsc    = str_replace('`', '``', (string)$access);
        $grant_sql = "GRANT ALL PRIVILEGES ON `$my_name_vc`.* TO `$usernameEsc`@`$accessEsc`";
        $zdbh->exec($grant_sql);
        $zdbh->exec("FLUSH PRIVILEGES");
        // Add user to Bulwark database...
        $sql = $zdbh->prepare("INSERT INTO x_mysql_users (
								mu_acc_fk,
								mu_name_vc,
								mu_database_fk,
								mu_pass_vc,
								mu_access_vc,
								mu_created_ts) VALUES (
								:userid,
								:username,
								:database,
								:password,
								:access,
								:time)");
        $sql->bindParam(':userid', $uid);
        $sql->bindParam(':username', $username);
        $sql->bindParam(':database', $database);
        $sql->bindParam(':password', $hashedPw); // bcrypt hash — never plain text
        $sql->bindParam(':access', $access);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        // Get the new users id...
        //$rowuser = $zdbh->query("SELECT * FROM x_mysql_users WHERE mu_name_vc='" . $username . "' AND mu_acc_fk=" . $uid . " AND mu_deleted_ts IS NULL")->fetch();
        // Must filter by access too: same username can have multiple host entries.
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_name_vc=:username AND mu_acc_fk=:userid AND mu_access_vc=:access AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':username', $username);
        $numrows->bindParam(':userid', $uid);
        $numrows->bindParam(':access', $access);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        // Add database to Bulwark user account...
        self::ExecuteAddDB($uid, $rowuser['mu_id_pk'], $database);
        runtime_hook::Execute('OnAfterCreateDatabaseUser');
        self::$ok = true;
        return true;
    }

    static function CheckCreateForErrors($username, $database, $access)
    {
        global $zdbh;
        // Check to make sure the user name is not blank before we go any further...
        if ($username == '') {
            self::$blank = true;
            return false;
        }
        // Check to make sure the user name is not blank before we go any further...
        if ($username == 'root') {
            self::$rootabuse = true;
            return false;
        }
        // Check to make sure the user name is not blank before we go any further...
        if ($database == '') {
            self::$blank = true;
            return false;
        }
        // Duplicate check: username+host pair (same username with a different host is valid
        // in MySQL — each user@host is a separate account, e.g. user@% and user@192.168.1.1).
        $sql = "SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc=:access AND mu_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':username', $username);
        $numrows->bindParam(':access', $access);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = true;
                return false;
            }
        }
        // Check actual MySQL server for the same username+host pair.
        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :username AND host = :access)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':username', $username);
        $numrows->bindParam(':access', $access);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = true;
                return false;
            }
        }
        // Check for invalid username
        if (!self::IsValidUserName($username)) {
            self::$badname = true;
            return false;
        }
        // Prevent ANY(%) + specific-IP contradiction for the same username.
        // In MariaDB, if user@% exists, specific-IP entries are reachable from any IP
        // anyway (% wins on connectivity). Mixed entries create password confusion
        // because each account has its own password.
        if ($access === '%') {
            $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc != '%' AND mu_deleted_ts IS NULL");
            $chk->bindParam(':username', $username);
            $chk->execute();
            if ($chk->fetchColumn() > 0) {
                self::$accessConflict = true;
                return false;
            }
        } else {
            $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc='%' AND mu_deleted_ts IS NULL");
            $chk->bindParam(':username', $username);
            $chk->execute();
            if ($chk->fetchColumn() > 0) {
                self::$accessConflict = true;
                return false;
            }
        }
        // Check for invalid access host (security fix, June 2026).
        // Whitelist-validate mu_access_vc to block SQL injection in
        // ExecuteRemoveDB's REVOKE statement (which concatenates mu_access_vc
        // inside a single-quoted SQL string). The previous check only
        // accepted '%', 'localhost' or "any valid IP" but did not reject
        // SQL/shell metacharacters.
        if (!self::IsValidAccessHost($access)) {
            self::$badIP = true;
            return false;
        }
        return true;
    }

    static function CheckAddForErrors($userid, $database)
    {
        global $zdbh;
        // Check to make sure the database isnt already added...
        //$result = $zdbh->query("SELECT * FROM x_mysql_dbmap WHERE mm_database_fk=" . $database . " AND mm_user_fk=" . $userid . "")->fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_dbmap WHERE mm_database_fk=:database AND mm_user_fk=:userid");
        $numrows->bindParam(':database', $database);
        $numrows->bindParam(':userid', $userid);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            self::$dbalreadyadded = true;
            return false;
        }
        return true;
    }

    static function ExecuteDeleteUser($mu_id_pk)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteDatabaseUser');
        // IDOR FIX: el usuario MySQL debe pertenecer al usuario autenticado.
        $currentuser = ctrl_users::GetUserDetail();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:mu_id_pk AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':mu_id_pk', $mu_id_pk);
        $numrows->bindValue(':uid', (int)$currentuser['userid'], PDO::PARAM_INT);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        if (!$rowuser) { return false; }

        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :name)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':name', $rowuser['mu_name_vc']);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                //drop user
                $sql = $zdbh->prepare("DROP USER :name@:access;");
                $sql->bindParam(':name', $rowuser['mu_name_vc']);
                $sql->bindParam(':access', $rowuser['mu_access_vc']);
                $sql->execute();
                //flush privileges
                $sql = $zdbh->prepare("FLUSH PRIVILEGES");
                $sql->execute();
            }
        }
        $sql = $zdbh->prepare("
			UPDATE x_mysql_users
			SET mu_deleted_ts = :time
			WHERE mu_id_pk = :mu_id_pk");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':mu_id_pk', $mu_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
			DELETE FROM x_mysql_dbmap
			WHERE mm_user_fk = :mu_id_pk");
        $sql->bindParam(':mu_id_pk', $mu_id_pk);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteDatabaseUser');
        self::$ok = true;
        return true;
    }

    static function ExecuteAddDB($uid, $myuserid, $dbid)
    {
        global $zdbh;
        if (fs_director::CheckForEmptyValue(self::CheckAddForErrors($myuserid, $dbid))) {
            return false;
        }
        if (!isset($uid) || $uid == NULL || $uid == '') {
            $currentuser = ctrl_users::GetUserDetail();
            $uid = $currentuser['userid'];
        }
        runtime_hook::Execute('OnBeforeAddDatabaseAccess');
        // IDOR FIX (crítico): la BD y el usuario MySQL deben ser AMBOS del usuario actual;
        // si no, un cliente podría GRANT a su usuario acceso a la base de datos de OTRO.
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:dbid AND my_acc_fk=:uid AND my_deleted_ts IS NULL");
        $numrows->bindParam(':dbid', $dbid);
        $numrows->bindValue(':uid', (int)$uid, PDO::PARAM_INT);
        $numrows->execute();
        $rowdb = $numrows->fetch();

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->bindValue(':uid', (int)$uid, PDO::PARAM_INT);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        if (!$rowdb || !$rowuser) { return false; }

        // Apply varun-naharia fix (security fix, June 2026).
        // The previous code called prepare() with bindParam() against named
        // placeholders that didn't actually exist in the query string (the
        // variables are already interpolated at prepare-time inside the
        // backtick-quoted identifiers). PDO silently ignored the binds and
        // the prepared statement had nothing to substitute, so a missing
        // privilege grant produced a blank page in the UI (issue #410).
        //
        // The fix: build the GRANT query as a plain string and execute it
        // via exec(). Identifier escape inside backtick context is achieved
        // by doubling the backtick character (MySQL identifier escape rule).
        // All three values are whitelist-validated upstream:
        //   - my_name_vc   from x_mysql_databases (admin-created)
        //   - mu_name_vc   from x_mysql_users.mu_name_vc (IsValidUserName)
        //   - mu_access_vc from x_mysql_users.mu_access_vc (IsValidAccessHost)
        $my_name_vc   = str_replace('`', '``', (string)$rowdb['my_name_vc']);
        $mu_name_vc   = str_replace('`', '``', (string)$rowuser['mu_name_vc']);
        $mu_access_vc = str_replace('`', '``', (string)$rowuser['mu_access_vc']);
        $grant_sql = "GRANT ALL PRIVILEGES ON `$my_name_vc`.* TO `$mu_name_vc`@`$mu_access_vc`";
        $zdbh->exec($grant_sql);
        $zdbh->exec("FLUSH PRIVILEGES");
        $sql2 = $zdbh->prepare("
			INSERT INTO x_mysql_dbmap (
							mm_acc_fk,
							mm_user_fk,
							mm_database_fk) VALUES (
							:uid,
							:myuserid,
							:dbid
                                                        )");
        $sql2->bindParam(':uid', $uid);
        $sql2->bindParam(':myuserid', $myuserid);
        $sql2->bindParam(':dbid', $dbid);
        $sql2->execute();
        runtime_hook::Execute('OnAfterAddDatabaseAccess');
        self::$ok = true;
        return true;
    }

    static function ExecuteRemoveDB($myuserid, $mapid)
    { // <-- mmid = dbmaps
        global $zdbh;
        runtime_hook::Execute('OnBeforeRemoveDatabaseAccess');

        // IDOR FIX: el mapeo de acceso (usuario<->BD) debe pertenecer al usuario autenticado.
        $currentuser = ctrl_users::GetUserDetail();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_dbmap WHERE mm_id_pk=:mapid AND mm_acc_fk=:uid");
        $numrows->bindParam(':mapid', $mapid);
        $numrows->bindValue(':uid', (int)$currentuser['userid'], PDO::PARAM_INT);
        $numrows->execute();
        $rowdbmap = $numrows->fetch();
        if (!$rowdbmap) { return false; }

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_id_pk=:mm_database_fk AND my_deleted_ts IS NULL");
        $numrows->bindParam(':mm_database_fk', $rowdbmap['mm_database_fk']);
        $numrows->execute();
        $rowdb = $numrows->fetch();

        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->execute();
        $rowuser = $numrows->fetch();

        // Security fix (June 2026): rewrite REVOKE without raw concatenation.
        // The previous code built the query by concatenating mu_access_vc
        // inside a single-quoted SQL string with no escape at all. With the
        // field no longer whitelist-validated (prior to this commit),
        // setting mu_access_vc to e.g. "x' OR '1'='1" produced:
        //     REVOKE ... FROM 'user'@'x' OR '1'='1'
        // altering the statement semantically — exploitable SQL injection.
        //
        // The fix: build the query as a plain string and execute via exec(),
        // with proper MySQL escape rules:
        //   - identifier context (backticks): double the backtick char
        //   - string context     (quotes):    double the quote char
        // All three values are whitelist-validated upstream by
        // IsValidUserName / IsValidAccessHost / admin-created database names.
        $my_name_vc   = str_replace('`', '``', (string)$rowdb['my_name_vc']);
        $mu_name_vc   = str_replace("'", "''", (string)$rowuser['mu_name_vc']);
        $mu_access_vc = str_replace("'", "''", (string)$rowuser['mu_access_vc']);
        $revoke_sql = "REVOKE ALL PRIVILEGES ON `$my_name_vc`.* FROM '$mu_name_vc'@'$mu_access_vc'";
        $zdbh->exec($revoke_sql);
        $zdbh->exec("FLUSH PRIVILEGES");

        $sql = $zdbh->prepare("DELETE FROM x_mysql_dbmap WHERE mm_id_pk=:mapid AND mm_user_fk=:myuserid");
        $sql->bindParam(':mapid', $mapid);
        $sql->bindParam(':myuserid', $myuserid);
        $sql->execute();

        runtime_hook::Execute('OnAfterRemoveDatabaseAccess');
        self::$ok = true;
        return true;
    }

    static function ExecuteResetPassword($myuserid, $password)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeResetDatabasePassword');
        // IDOR FIX: el usuario MySQL debe pertenecer al usuario autenticado.
        $currentuser = ctrl_users::GetUserDetail();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->bindValue(':uid', (int)$currentuser['userid'], PDO::PARAM_INT);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        if (!$rowuser) { return false; }

        // If errors are found, then exit before resetting password...
        if (fs_director::CheckForEmptyValue(self::CheckPasswordForErrors($password))) {
            return false;
        }
        $sql = "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = :mu_name_vc)";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':mu_name_vc', $rowuser['mu_name_vc']);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                // ALTER USER with exec() + proper escaping — avoids broken string
                // version comparison ("12.x" <= "5.7" is TRUE in PHP) that
                // previously routed to the removed SET PASSWORD syntax.
                $usernameEsc = str_replace("'", "''", (string)$rowuser['mu_name_vc']);
                $accessEsc   = str_replace("'", "''", (string)$rowuser['mu_access_vc']);
                $zdbh->exec("ALTER USER '$usernameEsc'@'$accessEsc' IDENTIFIED BY " . $zdbh->quote($password));
                $zdbh->exec("FLUSH PRIVILEGES");
                $resetHashedPw = password_hash($password, PASSWORD_BCRYPT);
                $sql = $zdbh->prepare("UPDATE x_mysql_users SET mu_pass_vc=:password WHERE mu_id_pk=:myuserid");
                $sql->bindParam(':password', $resetHashedPw);
                $sql->bindParam(':myuserid', $myuserid);
                $sql->execute();
            }
        }
        runtime_hook::Execute('OnAfterResetDatabasePassword');
        self::$ok = true;
        return true;
    }

    static function CheckPasswordForErrors($password)
    {
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$blankpassword = TRUE;
            return false;
        }
		// Check for password length...
		if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
			self::$badpasswordlength = true;
			return false;
		}
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            return false;
        }
        return true;
    }

    static function IsValidUserName($username)
    {
		# tg - Added regx [a-z\d_] to allow underscore
        if (!preg_match('/^[a-z\d][a-z\d-][a-z\d_]{0,62}$/i', $username) || preg_match('/-$/', $username)) {
            return false;
        } else {
			# tg - Updated user name max size check
            if (strlen($username) < 33) {
                // Enforce the MySQL username limit! (http://dev.mysql.com/doc/refman/4.1/en/user-names.html)
                return true;
            }
            return false;
        }
    }

    /**
     * Whitelist validator for the MySQL access host (x_mysql_users.mu_access_vc).
     *
     * Background (security fix, June 2026): prior to this fix, the value of
     * `inAccessIP` / `inAccess` (mu_access_vc) was stored verbatim without
     * validation. CheckCreateForErrors() only allowed "%", "localhost" or a
     * "valid IP" via sys_monitoring::IsAnyValidIP, but nothing rejected shell
     * metacharacters or SQL-relevant characters such as single quotes. As a
     * result, ExecuteRemoveDB's REVOKE statement (which concatenates
     * mu_access_vc inside a single-quoted SQL string) was exploitable for
     * SQL injection — the SQL state `... FROM 'user'@'<attacker>'\`` is
     * trivially escaped by inserting a single quote into mu_access_vc.
     *
     * The fix: enforce a strict character whitelist before persistence. Only
     * DNS hostnames, IPv4 dotted-quad, IPv6 (with optional `%zone`), and the
     * sentinels `%` and `localhost` are permitted. Anything containing SQL
     * string delimiters, backticks, semicolons, parentheses, whitespace,
     * backslashes, etc. is rejected.
     *
     * @param mixed $host
     * @return bool
     */
    public static function IsValidAccessHost($host)
    {
        if (!is_string($host) || $host === '') {
            return false;
        }
        // Length cap (DNS limit).
        if (strlen($host) > 255) {
            return false;
        }
        // Hard blacklist: SQL metacharacters and shell metacharacters.
        // DNS names / IPs / IPv6 never legitimately contain these.
        if (preg_match('/[\s\'"`;\\\\\/(){}<>&|]/', $host)) {
            return false;
        }
        // Special sentinel "%" — "match any host" in MySQL GRANT/REVOKE.
        if ($host === '%') {
            return true;
        }
        // Case-insensitive "localhost".
        if (strcasecmp($host, 'localhost') === 0) {
            return true;
        }
        // IPv4 dotted-quad: 1-3 digits, 4 groups.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
        // IPv6 (with optional %zone for link-local): strip zone if present.
        if (strpos($host, '%') !== false) {
            $h = substr($host, 0, strpos($host, '%'));
        } else {
            $h = $host;
        }
        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        // Otherwise must be a DNS hostname: labels of [a-z0-9-], separated
        // by dots, neither label starting nor ending with '-'.
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?)*$/', $host)) {
            return false;
        }
        return true;
    }

	static function IsValidPassword($password)
    {
        return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/', $password) || preg_match('/-$/', $password) == 1;
		//return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
    }
	

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function doCreateUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if ($formvars['inAccess'] == 1) {
            $access = "%";
        } else {
            $access = $formvars['inAccessIP'];
        }
        if (self::ExecuteCreateUser($currentuser['userid'], $formvars['inUserName'], $formvars['inDatabase'], $access))
            return true;
        return false;
    }

    static function doEditUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListUsers($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
        }
        return;
    }

    static function doAddDB()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddDB($currentuser['userid'], $formvars['inUser'], $formvars['inDatabase']))
            return true;
        return false;
    }

    static function doRemoveDB()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListUserDatabases($formvars['inUser']) as $row) {
            if (isset($formvars['inRemove_' . $row['mmid'] . ''])) {
                if (self::ExecuteRemoveDB($formvars['inUser'], $formvars['inRemove_' . $row['mmid'] . ''])) {
                    return true;
                } else {
                    return false;
                }
            }
        }
        return false;
    }

    static function doConfirmDeleteUser()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteDeleteUser($formvars['inDelete']))
            return true;
        return false;
    }

    static function doResetPW()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteResetPassword($formvars['inUser'], $formvars['inResetPW']))
            return true;
        return false;
    }

    static function getUserList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListUsers($currentuser['userid']);
    }

    static function getDatabaseList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDatabases($currentuser['userid']);
    }

    static function getEditDatabaseList()
    {
        global $controller, $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $myuserid = $controller->GetControllerRequest('URL', 'other');
        if (!$myuserid) return false;
        $sql = "SELECT d.my_id_pk, d.my_name_vc FROM x_mysql_databases d
                WHERE d.my_acc_fk=:userid AND d.my_deleted_ts IS NULL
                AND d.my_id_pk NOT IN (
                    SELECT mm_database_fk FROM x_mysql_dbmap WHERE mm_user_fk=:myuserid
                )
                ORDER BY d.my_name_vc";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->execute();
        $res = array();
        while ($row = $numrows->fetch()) {
            array_push($res, array('mysqlid' => $row['my_id_pk'], 'mysqlname' => $row['my_name_vc']));
        }
        return !empty($res) ? $res : false;
    }

    static function getUserDatabaseList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListUserDatabases($controller->GetControllerRequest('URL', 'other'));
    }

    static function getisDeleteUser($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete MySQL Account.
        // This shall avoid exposing mysql username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_id_pk=:editedUsrID AND mu_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
		
    }

    static function getisEditUser($uid = null)
    {
		
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit MySQL Account.
        // This shall avoid exposing mysql username based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_mysql_users WHERE mu_acc_fk=:userid AND mu_id_pk=:editedUsrID AND mu_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['other']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }
		
        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    static function getisCreateUser()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    static function getCurrentUserName()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return $currentuser['username'];
    }

    static function getEditCurrentUserName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentUser($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['username'];
        } else {
            return "";
        }
    }

    static function getEditCurrentUserID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentUser($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['userid'];
        } else {
            return "";
        }
    }

    static function ExecuteEditAccess($myuserid, $newAccess)
    {
        global $zdbh;
        if (!self::IsValidAccessHost($newAccess)) {
            self::$badIP = true;
            return false;
        }
        // IDOR FIX: el usuario MySQL debe pertenecer al usuario autenticado.
        $currentuser = ctrl_users::GetUserDetail();
        $numrows = $zdbh->prepare("SELECT * FROM x_mysql_users WHERE mu_id_pk=:myuserid AND mu_acc_fk=:uid AND mu_deleted_ts IS NULL");
        $numrows->bindParam(':myuserid', $myuserid);
        $numrows->bindValue(':uid', (int)$currentuser['userid'], PDO::PARAM_INT);
        $numrows->execute();
        $rowuser = $numrows->fetch();
        if (!$rowuser) return false;

        $oldAccess = $rowuser['mu_access_vc'];
        $username  = $rowuser['mu_name_vc'];

        if ($oldAccess === $newAccess) {
            self::$ok = true;
            return true;
        }
        // Prevent ANY/specific-IP contradiction when changing access.
        if ($newAccess === '%') {
            $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc != '%' AND mu_id_pk != :myuserid AND mu_deleted_ts IS NULL");
            $chk->bindParam(':username', $username);
            $chk->bindParam(':myuserid', $myuserid);
            $chk->execute();
            if ($chk->fetchColumn() > 0) {
                self::$accessConflict = true;
                return false;
            }
        } else {
            $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc='%' AND mu_id_pk != :myuserid AND mu_deleted_ts IS NULL");
            $chk->bindParam(':username', $username);
            $chk->bindParam(':myuserid', $myuserid);
            $chk->execute();
            if ($chk->fetchColumn() > 0) {
                self::$accessConflict = true;
                return false;
            }
        }
        // Reject if the target username+host already exists
        $check = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc=:username AND mu_access_vc=:access AND mu_id_pk != :myuserid AND mu_deleted_ts IS NULL");
        $check->bindParam(':username', $username);
        $check->bindParam(':access', $newAccess);
        $check->bindParam(':myuserid', $myuserid);
        $check->execute();
        if ($check->fetchColumn() > 0) {
            self::$alreadyexists = true;
            return false;
        }
        // RENAME USER keeps all MySQL privileges intact
        $usernameEsc  = str_replace("'", "''", $username);
        $oldAccessEsc = str_replace("'", "''", $oldAccess);
        $newAccessEsc = str_replace("'", "''", $newAccess);
        $zdbh->exec("RENAME USER '$usernameEsc'@'$oldAccessEsc' TO '$usernameEsc'@'$newAccessEsc'");
        $zdbh->exec("FLUSH PRIVILEGES");
        $sql = $zdbh->prepare("UPDATE x_mysql_users SET mu_access_vc=:newAccess WHERE mu_id_pk=:myuserid");
        $sql->bindParam(':newAccess', $newAccess);
        $sql->bindParam(':myuserid', $myuserid);
        $sql->execute();
        self::$ok = true;
        return true;
    }

    static function doEditAccess()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $newAccess = ($formvars['inNewAccess'] == 1) ? '%' : $formvars['inNewAccessIP'];
        if (self::ExecuteEditAccess($formvars['inUser'], $newAccess))
            return true;
        return false;
    }

    static function getEditCurrentUserAccess()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentUser($controller->GetControllerRequest('URL', 'other'));
            if ($current && isset($current[0]['access'])) {
                return $current[0]['access'] === '%' ? 'ANY (%)' : htmlspecialchars($current[0]['access'], ENT_QUOTES, 'UTF-8');
            }
        }
        return '';
    }

    static function getMysqlUsagepChart()
    {
        return '<img src="' . ui_tpl_assetfolderpath::Template() . 'img/misc/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
    }

    static function getMinPassLength()
    {
        $minpasswordlength = ctrl_options::GetSystemOption('password_minlength');
        $trylength = 9;
        if ($trylength < $minpasswordlength) {
            $uselength = $minpasswordlength;
        } else {
            $uselength = $trylength;
        }
        return $uselength;
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a user name and select a database to create your MySQL user."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$rootabuse)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot create a user named 'root'! This attempt has been logged and the system administrator notified!."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A MySQL username with that name already appears to exsist."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL user name is not valid. Please enter a valid MySQL user name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your MySQL password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$blankpassword)) {
            return ui_sysmessage::shout(ui_language::translate("You entered blank a password. Please retry and enter a valid password."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badIP)) {
            return ui_sysmessage::shout(ui_language::translate("The IP address is not valid. Please enter a valid IP address."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$accessConflict)) {
            return ui_sysmessage::shout(ui_language::translate("Access conflict: you cannot mix 'Any IP' (%) with specific IPs for the same MySQL username. Delete the specific-IP entries first before setting Any, or do not add Any if you want to restrict to specific IPs."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$dbalreadyadded)) {
            return ui_sysmessage::shout(ui_language::translate("That database has already been added to this user."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            if (self::$newPassword !== null) {
                $pw = htmlspecialchars(self::$newPassword, ENT_QUOTES, 'UTF-8');
                return ui_sysmessage::shout(
                    ui_language::translate("MySQL user created successfully.") .
                    " <strong>" . ui_language::translate("Password") . ": " . $pw . "</strong> &mdash; " .
                    ui_language::translate("Save it now, it will not be shown again."),
                    "zannounceok"
                );
            }
            return ui_sysmessage::shout(ui_language::translate("Changes to your MySQL users have been saved successfully!"), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}
