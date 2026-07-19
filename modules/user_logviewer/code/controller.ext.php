<?php
/**
*
* @package user_logviewer
* @version 102
* @author Simon Mora - samt2497 / extended by new_bulwark
*
*/

class module_controller extends ctrl_module
{
	static $notmine;
	static $notfile;

	static $CurrentLogFile;
	static $PreviewBuffer;

	static $preview;
	static $pbuffer;
	static $preview_lines = 50;

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	static function downloadFile($filepath) {
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename($filepath));
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($filepath));
		readfile($filepath);
		die();
	}

	static function ListVhosts($uid = 0) {
		global $zdbh;
		if ($uid == 0) {
			$sql = "SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
			$numrows = $zdbh->prepare($sql);
		} else {
			$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in != 3 ORDER BY vh_name_vc ASC";
			$numrows = $zdbh->prepare($sql);
			$numrows->bindParam(':uid', $uid);
		}
		$numrows->execute();
		if ($numrows->fetchColumn() <> 0) {
			if ($uid == 0) {
				$sql = $zdbh->prepare($sql);
			} else {
				$sql = $zdbh->prepare($sql);
				$sql->bindParam(':uid', $uid);
			}
			$res = array();
			$sql->execute();
			while ($rowdomains = $sql->fetch()) {
				array_push($res, array(
					'uid'       => $rowdomains['vh_acc_fk'],
					'name'      => $rowdomains['vh_name_vc'],
					'directory' => $rowdomains['vh_directory_vc'],
					'active'    => $rowdomains['vh_active_in'],
					'id'        => $rowdomains['vh_id_pk'],
				));
			}
			return $res;
		} else {
			return false;
		}
	}

	// ----------------------------------------------------------------
	// Site log helpers (access/error)
	// ----------------------------------------------------------------

	static function tailCustom($filepath, $lines = 1, $adaptive = true) {
		$f = @fopen($filepath, "rb");
		if ($f === false) return false;
		if (!$adaptive) $buffer = 4096;
		else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
		fseek($f, -1, SEEK_END);
		if (fread($f, 1) != "\n") $lines -= 1;
		$output = '';
		$chunk  = '';
		while (ftell($f) > 0 && $lines >= 0) {
			$seek = min(ftell($f), $buffer);
			fseek($f, -$seek, SEEK_CUR);
			$output = ($chunk = fread($f, $seek)) . $output;
			fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
			$lines -= substr_count($chunk, "\n");
		}
		while ($lines++ < 0) {
			$output = (substr($output, strpos($output, "\n") + 1));
		}
		fclose($f);
		return trim($output);
	}

	static function getLogAccessList() {
		$currentuser = ctrl_users::GetUserDetail();
		$res     = array();
		$domains = self::ListVhosts($currentuser['userid']);
		if (!fs_director::CheckForEmptyValue($domains)) {
			foreach ($domains as $row) {
				$paths    = ctrl_options::GetVhostPaths($currentuser['username'], $row['directory']);
				$filepath = $paths['logs'] . '/' . $row['name'] . '-access.log';
				$fsize    = file_exists($filepath) ? fs_director::ShowHumanFileSize(filesize($filepath)) : 'Not Found';
				$res[] = array(
					'name'      => $row['name'],
					'directory' => $row['directory'],
					'active'    => $row['active'],
					'filepath'  => basename($filepath),
					'fsize'     => $fsize,
					'id'        => $row['id'],
				);
			}
			return $res;
		} else {
			return false;
		}
	}

	static function getLogErrorList() {
		$currentuser = ctrl_users::GetUserDetail();
		$res     = array();
		$domains = self::ListVhosts($currentuser['userid']);
		if (!fs_director::CheckForEmptyValue($domains)) {
			foreach ($domains as $row) {
				$paths    = ctrl_options::GetVhostPaths($currentuser['username'], $row['directory']);
				$filepath = $paths['logs'] . '/' . $row['name'] . '-error.log';
				$fsize    = file_exists($filepath) ? fs_director::ShowHumanFileSize(filesize($filepath)) : 'Not Found';
				$res[] = array(
					'name'      => $row['name'],
					'directory' => $row['directory'],
					'active'    => $row['active'],
					'filepath'  => basename($filepath),
					'fsize'     => $fsize,
					'id'        => $row['id'],
				);
			}
			return $res;
		} else {
			return false;
		}
	}

	static function getisPreviewLog() {
		return !fs_director::CheckForEmptyValue(self::$preview);
	}

	static function getCurrentLogFile() {
		return self::$CurrentLogFile;
	}

	static function getPreviewBuffer() {
		return self::$PreviewBuffer;
	}

	static function ActionProcess($mode) {
		runtime_csfr::Protect();
		$currentuser = ctrl_users::GetUserDetail();
		global $controller;
		global $zdbh;
		$id = $controller->GetControllerRequest('FORM', 'inPreview');
		if ($id <= 0) {
			$id       = $controller->GetControllerRequest('FORM', 'inDownload');
			$download = true;
		}
		$uid   = $currentuser['userid'];
		$query = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_id_pk=:id AND vh_deleted_ts IS NULL");
		$query->bindParam(':uid', $uid);
		$query->bindParam(':id',  $id);
		$query->execute();
		if ($data = $query->fetch()) {
			$vhpaths = ctrl_options::GetVhostPaths($currentuser['username'], $data['vh_directory_vc']);
			if ($mode === 'access') {
				$filepath = $vhpaths['logs'] . '/' . $data['vh_name_vc'] . '-access.log';
			} elseif ($mode === 'phperror') {
				$filepath = $vhpaths['logs'] . '/php-error.log';
			} else {
				$filepath = $vhpaths['logs'] . '/' . $data['vh_name_vc'] . '-error.log';
			}
			self::$preview = true;
			if (file_exists($filepath)) {
				if (!empty($download)) {
					self::downloadFile($filepath);
				} else {
					self::$CurrentLogFile = basename($filepath);
					self::$PreviewBuffer  = self::tailCustom($filepath, self::$preview_lines);
				}
			} else {
				self::$notfile = true;
			}
		} else {
			self::$notmine = true;
		}
	}

	static function doErrorLogActions() {
		self::ActionProcess('error');
	}

	static function doAccessLogActions() {
		self::ActionProcess('access');
	}

	static function doPhpErrorLogActions() {
		self::ActionProcess('phperror');
	}

	// ----------------------------------------------------------------
	// PHP error log (FPM: php-error.log por dominio)
	// ----------------------------------------------------------------

	static function getLogPhpErrorList() {
		$currentuser = ctrl_users::GetUserDetail();
		$res     = array();
		$domains = self::ListVhosts($currentuser['userid']);
		if (!fs_director::CheckForEmptyValue($domains)) {
			foreach ($domains as $row) {
				$paths    = ctrl_options::GetVhostPaths($currentuser['username'], $row['directory']);
				$filepath = $paths['logs'] . '/php-error.log';
				$fsize    = file_exists($filepath) ? fs_director::ShowHumanFileSize(filesize($filepath)) : 'Not Found';
				$res[] = array(
					'name'      => $row['name'],
					'directory' => $row['directory'],
					'active'    => $row['active'],
					'filepath'  => basename($filepath),
					'fsize'     => $fsize,
					'id'        => $row['id'],
				);
			}
			return $res;
		} else {
			return false;
		}
	}

	// ----------------------------------------------------------------
	// Result messages
	// ----------------------------------------------------------------

	static function getResult() {
		if (!fs_director::CheckForEmptyValue(self::$notmine)) {
			return ui_sysmessage::shout(ui_language::translate("Unable to get log preview, this domain does not belong to you."), "zannounceerror");
		}
		if (!fs_director::CheckForEmptyValue(self::$notfile)) {
			return ui_sysmessage::shout(ui_language::translate("The log does not exit yet."), "zannounceerror");
		}
		return;
	}
}
