<?php
/**
 * Antispam module — user view
 * Per-mailbox spam filtering via rspamd + Redis.
 */

class module_controller extends ctrl_module
{
    const REDIS_HOST = '127.0.0.1';
    const REDIS_PORT = 6379;

    static $ok_msg;
    static $err_msg;

    private static function redirectToList()
    {
        header('Location: ./?module=antispam');
        exit;
    }

    // ----------------------------------------------------------------
    // Redis helper
    // ----------------------------------------------------------------

    private static function redis()
    {
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            if (!@$r->connect(self::REDIS_HOST, self::REDIS_PORT, 2)) {
                $r = null;
                throw new RuntimeException('Cannot connect to Redis');
            }
            $rp = @file_get_contents('/usr/local/bulwark/cnf/redis.pass');
            if ($rp !== false && trim($rp) !== '') { try { $r->auth(['panel', trim($rp)]); } catch (Exception $e) {} }
        }
        return $r;
    }

    // ----------------------------------------------------------------
    // Data helpers
    // ----------------------------------------------------------------

    static function GetUserMailboxes()
    {
        global $zdbh;
        $user = ctrl_users::GetUserDetail();
        $sql  = $zdbh->prepare(
            'SELECT mb_id_pk, mb_address_vc, mb_antispam_in, mb_spam_score, mb_spam_action
             FROM x_mailboxes
             WHERE mb_acc_fk = :uid AND mb_deleted_ts IS NULL
             ORDER BY mb_address_vc'
        );
        $sql->bindParam(':uid', $user['userid'], PDO::PARAM_INT);
        $sql->execute();
        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }

    static function GetMailboxById($id)
    {
        global $zdbh;
        $user = ctrl_users::GetUserDetail();
        $sql  = $zdbh->prepare(
            'SELECT mb_id_pk, mb_address_vc, mb_antispam_in, mb_spam_score, mb_spam_action
             FROM x_mailboxes
             WHERE mb_id_pk = :id AND mb_acc_fk = :uid AND mb_deleted_ts IS NULL'
        );
        $sql->bindParam(':id',  $id,            PDO::PARAM_INT);
        $sql->bindParam(':uid', $user['userid'], PDO::PARAM_INT);
        $sql->execute();
        return $sql->fetch(PDO::FETCH_ASSOC);
    }

    static function GetAntispamLists($mailbox_id, $type)
    {
        global $zdbh;
        $sql = $zdbh->prepare(
            'SELECT al_id_pk, al_address_vc FROM x_antispam_lists
             WHERE al_mailbox_fk = :mid AND al_type_vc = :type ORDER BY al_address_vc'
        );
        $sql->bindParam(':mid',  $mailbox_id, PDO::PARAM_INT);
        $sql->bindParam(':type', $type,       PDO::PARAM_STR);
        $sql->execute();
        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----------------------------------------------------------------
    // Sync DB → Redis
    // ----------------------------------------------------------------

    private static function SyncToRedis($mailbox_id)
    {
        global $zdbh;
        $sql = $zdbh->prepare(
            'SELECT mb_address_vc, mb_antispam_in, mb_spam_score, mb_spam_action
             FROM x_mailboxes WHERE mb_id_pk = :id AND mb_deleted_ts IS NULL'
        );
        $sql->bindParam(':id', $mailbox_id, PDO::PARAM_INT);
        $sql->execute();
        $mb = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$mb) return;

        try {
            $email = $mb['mb_address_vc'];
            $r     = self::redis();
            $r->hMSet('bulwark:antispam:' . $email, [
                'enabled' => (int)$mb['mb_antispam_in'],
                'score'   => $mb['mb_spam_score'] ?? '',
                'action'  => $mb['mb_spam_action'] ?? '',
            ]);

            foreach (['white', 'black'] as $type) {
                $wkey = 'bulwark:antispam:' . $type[0] . 'l:' . $email;
                $r->del($wkey);
                $sql2 = $zdbh->prepare(
                    'SELECT al_address_vc FROM x_antispam_lists
                     WHERE al_mailbox_fk = :mid AND al_type_vc = :type'
                );
                $sql2->bindParam(':mid',  $mailbox_id, PDO::PARAM_INT);
                $sql2->bindParam(':type', $type,        PDO::PARAM_STR);
                $sql2->execute();
                $addrs = $sql2->fetchAll(PDO::FETCH_COLUMN);
                if ($addrs) {
                    $r->sAddArray($wkey, $addrs);
                }
            }
        } catch (Exception $e) {
            error_log('antispam: Redis sync failed: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // Actions
    // ----------------------------------------------------------------

    static function doSelectMailbox()
    {
        // No-op: Configure ahora usa enlace GET, no POST
    }

    static function doToggleAntispam()
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;
        $id      = (int)$controller->GetControllerRequest('FORM', 'inMailboxId');
        $enabled = (int)$controller->GetControllerRequest('FORM', 'inEnabled');
        $mb      = self::GetMailboxById($id);
        if (!$mb) { self::$err_msg = 'Mailbox not found.'; return; }

        $sql = $zdbh->prepare(
            'UPDATE x_mailboxes SET mb_antispam_in = :en WHERE mb_id_pk = :id'
        );
        $sql->bindParam(':en', $enabled, PDO::PARAM_INT);
        $sql->bindParam(':id', $id,      PDO::PARAM_INT);
        $sql->execute();
        self::SyncToRedis($id);
        self::$ok_msg = $enabled ? 'Antispam enabled.' : 'Antispam disabled.';
    }

    static function doSaveSettings()
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;
        $id     = (int)$controller->GetControllerRequest('FORM', 'inMailboxId');
        $score  = $controller->GetControllerRequest('FORM', 'inScore');
        $action = $controller->GetControllerRequest('FORM', 'inAction');
        $mb     = self::GetMailboxById($id);
        if (!$mb) { self::$err_msg = 'Mailbox not found.'; return; }

        $score  = ($score === '' || $score === null) ? null : min(20, max(1, (float)$score));
        $action = in_array($action, ['tag', 'junk', 'reject']) ? $action : null;

        $sql = $zdbh->prepare(
            'UPDATE x_mailboxes SET mb_spam_score = :sc, mb_spam_action = :ac WHERE mb_id_pk = :id'
        );
        $sql->bindParam(':sc', $score,  PDO::PARAM_STR);
        $sql->bindParam(':ac', $action, PDO::PARAM_STR);
        $sql->bindParam(':id', $id,     PDO::PARAM_INT);
        $sql->execute();
        self::SyncToRedis($id);
        $_SESSION['antispam_ok'] = 'Settings saved.';
        self::redirectToList();
    }

    static function doAddToList()
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;
        $id      = (int)$controller->GetControllerRequest('FORM', 'inMailboxId');
        $address = trim($controller->GetControllerRequest('FORM', 'inAddress'));
        $type    = $controller->GetControllerRequest('FORM', 'inType');
        $mb      = self::GetMailboxById($id);
        if (!$mb) { self::$err_msg = 'Mailbox not found.'; return; }
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) && !preg_match('/^@[a-z0-9.\-]+\.[a-z]{2,}$/i', $address)) {
            self::$err_msg = 'Invalid email or domain (use @domain.com for full domains).';
            return;
        }
        if (!in_array($type, ['white', 'black'])) { self::$err_msg = 'Invalid list type.'; return; }

        $sql = $zdbh->prepare(
            'INSERT IGNORE INTO x_antispam_lists (al_mailbox_fk, al_address_vc, al_type_vc, al_created_ts)
             VALUES (:mid, :addr, :type, :ts)'
        );
        $sql->bindParam(':mid',  $id,      PDO::PARAM_INT);
        $sql->bindParam(':addr', $address, PDO::PARAM_STR);
        $sql->bindParam(':type', $type,    PDO::PARAM_STR);
        $ts = time();
        $sql->bindParam(':ts',   $ts,      PDO::PARAM_INT);
        $sql->execute();
        self::SyncToRedis($id);
        $_SESSION['antispam_ok'] = ucfirst($type) . 'list updated.';
        self::redirectToList();
    }

    static function doRemoveFromList()
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;
        $list_id = (int)$controller->GetControllerRequest('FORM', 'inListId');
        $mb_id   = (int)$controller->GetControllerRequest('FORM', 'inMailboxId');
        $mb      = self::GetMailboxById($mb_id);
        if (!$mb) { self::$err_msg = 'Mailbox not found.'; return; }

        $sql = $zdbh->prepare(
            'DELETE FROM x_antispam_lists WHERE al_id_pk = :lid AND al_mailbox_fk = :mid'
        );
        $sql->bindParam(':lid', $list_id, PDO::PARAM_INT);
        $sql->bindParam(':mid', $mb_id,   PDO::PARAM_INT);
        $sql->execute();
        self::SyncToRedis($mb_id);
        $_SESSION['antispam_ok'] = 'Entry removed.';
        self::redirectToList();
    }

    // ----------------------------------------------------------------
    // Template getters
    // ----------------------------------------------------------------

    static function getResult()
    {
        if (self::$err_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$err_msg), 'zannounceerror');
        if (self::$ok_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$ok_msg), 'zannounceok');
        if (!empty($_SESSION['antispam_ok'])) {
            $msg = $_SESSION['antispam_ok'];
            unset($_SESSION['antispam_ok']);
            return ui_sysmessage::shout(ui_language::translate($msg), 'zannounceok');
        }
        return '';
    }

    static function getSelectedMailboxId()
    {
        // GET: enlace Configure directo. POST: formularios dentro del panel.
        $id = isset($_GET['inMailboxId'])  ? (int)$_GET['inMailboxId']  :
             (isset($_POST['inMailboxId']) ? (int)$_POST['inMailboxId'] : 0);
        if ($id && self::GetMailboxById($id)) return $id;
        return 0;
    }

    static function getSelectedMailbox()
    {
        $id = self::getSelectedMailboxId();
        if (!$id) return false;
        return self::GetMailboxById($id);
    }

    static function getSelectedMailboxAddress()
    {
        $mb = self::getSelectedMailbox();
        return $mb ? htmlspecialchars($mb['mb_address_vc'], ENT_QUOTES, 'UTF-8') : '';
    }

    static function getMailboxList()
    {
        $rows = self::GetUserMailboxes();
        if (!$rows) return false;
        $csrf = self::getCSFR_Tag();
        $out  = [];
        foreach ($rows as $r) {
            $en  = (int)$r['mb_antispam_in'];
            $id  = (int)$r['mb_id_pk'];
            $out[] = [
                'id'           => $id,
                'address'      => htmlspecialchars($r['mb_address_vc'], ENT_QUOTES, 'UTF-8'),
                'panel_class'  => 'card mb-2' . ($en ? ' border-success' : ''),
                'status_badge' => $en
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-default">Inactive</span>',
                'toggle_form'  => '<form method="post" action="./?module=antispam&action=ToggleAntispam" style="display:inline;">'
                                . '<input type="hidden" name="inMailboxId" value="' . $id . '">'
                                . '<input type="hidden" name="inEnabled" value="' . ($en ? '0' : '1') . '">'
                                . $csrf
                                . '<button type="submit" class="btn btn-sm ' . ($en ? 'btn-danger' : 'btn-success') . '"><i class="bi bi-toggle-on me-1"></i>'
                                . ($en ? 'Disable' : 'Enable')
                                . '</button></form>',
                'configure_form' => '<a href="./?module=antispam&inMailboxId=' . $id . '" class="btn btn-sm btn-secondary">Configure</a>',
            ];
        }
        return $out;
    }

    static function getWhitelist()
    {
        $id = self::getSelectedMailboxId();
        if (!$id) return false;
        $rows = self::GetAntispamLists($id, 'white');
        if (!$rows) return false;
        $csrf = self::getCSFR_Tag();
        return array_map(function($r) use ($id, $csrf) {
            return [
                'al_id_pk'     => (int)$r['al_id_pk'],
                'al_address_vc'=> htmlspecialchars($r['al_address_vc'], ENT_QUOTES, 'UTF-8'),
                'remove_form'  => '<form method="post" action="./?module=antispam&action=RemoveFromList" style="display:inline;">'
                                . '<input type="hidden" name="inListId" value="' . (int)$r['al_id_pk'] . '">'
                                . '<input type="hidden" name="inMailboxId" value="' . (int)$id . '">'
                                . $csrf
                                . '<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Remove</button></form>',
            ];
        }, $rows);
    }

    static function getBlacklist()
    {
        $id = self::getSelectedMailboxId();
        if (!$id) return false;
        $rows = self::GetAntispamLists($id, 'black');
        if (!$rows) return false;
        $csrf = self::getCSFR_Tag();
        return array_map(function($r) use ($id, $csrf) {
            return [
                'al_id_pk'     => (int)$r['al_id_pk'],
                'al_address_vc'=> htmlspecialchars($r['al_address_vc'], ENT_QUOTES, 'UTF-8'),
                'remove_form'  => '<form method="post" action="./?module=antispam&action=RemoveFromList" style="display:inline;">'
                                . '<input type="hidden" name="inListId" value="' . (int)$r['al_id_pk'] . '">'
                                . '<input type="hidden" name="inMailboxId" value="' . (int)$id . '">'
                                . $csrf
                                . '<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Remove</button></form>',
            ];
        }, $rows);
    }

    static function getGlobalScore()
    {
        return ctrl_options::GetSystemOption('antispam_score') ?: '6.0';
    }

    static function getGlobalAction()
    {
        return ctrl_options::GetSystemOption('antispam_action') ?: 'junk';
    }

    static function getScoreInput()
    {
        $mb     = self::getSelectedMailbox();
        $score  = $mb ? htmlspecialchars((string)($mb['mb_spam_score'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
        $global = htmlspecialchars(self::getGlobalScore(), ENT_QUOTES, 'UTF-8');
        return '<input type="number" step="0.5" min="1" max="20" name="inScore" class="form-control"'
             . ' value="' . $score . '" placeholder="Global (' . $global . ')">';
    }

    static function getActionSelect()
    {
        $mb  = self::getSelectedMailbox();
        $cur = $mb ? ($mb['mb_spam_action'] ?? '') : '';
        $global = htmlspecialchars(self::getGlobalAction(), ENT_QUOTES, 'UTF-8');
        $opts = ['tag' => 'Tag subject [SPAM]', 'junk' => 'Move to Junk', 'reject' => 'Reject message'];
        $html = '<select name="inAction" class="form-control">'
              . '<option value="">Global default (' . $global . ')</option>';
        foreach ($opts as $val => $label) {
            $sel   = ($cur === $val) ? ' selected' : '';
            $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        return $html . '</select>';
    }
}
