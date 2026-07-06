<?php

class module_controller extends ctrl_module
{
    static $ok;
    static $error;
    static $new_token;
    static $session_loaded = false;

    // ── Helpers de rol ───────────────────────────────────────────────────────

    private static function currentRole(): array
    {
        $u   = ctrl_users::GetUserDetail();
        $gid = (int)($u['usergroupid'] ?? 3);
        return [
            'uid'         => (int)($u['userid']   ?? 0),
            'username'    => (string)($u['username'] ?? ''),
            'gid'         => $gid,
            'is_admin'    => $gid === 1,
            'is_reseller' => $gid === 2,
            'is_user'     => $gid === 3,
        ];
    }

    // Registra una acción en la tabla x_api_audit.
    // Nunca lanza excepción para no interrumpir la operación principal.
    private static function audit(string $action, string $target = '', string $detail = ''): void
    {
        global $zdbh;
        $r  = self::currentRole();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        try {
            $zdbh->prepare(
                "INSERT INTO x_api_audit (aa_actor_vc, aa_action_vc, aa_target_vc, aa_detail_tx, aa_ip_vc, aa_ts)
                 VALUES (:actor, :action, :target, :detail, :ip, NOW())"
            )->execute([
                ':actor'  => $r['username'],
                ':action' => $action,
                ':target' => $target,
                ':detail' => ($detail !== '' ? $detail : null),
                ':ip'     => $ip,
            ]);
        } catch (Throwable $e) {}
    }

    // Devuelve la fila del token si el actor actual tiene permiso sobre él.
    private static function ownedTokenRow(int $id): ?array
    {
        global $zdbh;
        $r = self::currentRole();

        if ($r['is_admin']) {
            $sql = $zdbh->prepare(
                "SELECT at_id_pk, at_name_vc, at_enabled_in FROM x_api_tokens
                  WHERE at_id_pk = :id AND at_deleted_ts IS NULL"
            );
            $sql->execute([':id' => $id]);
        } elseif ($r['is_reseller']) {
            $sql = $zdbh->prepare(
                "SELECT t.at_id_pk, t.at_name_vc, t.at_enabled_in
                   FROM x_api_tokens t
                   LEFT JOIN x_accounts a ON a.ac_id_pk = t.at_user_fk
                  WHERE t.at_id_pk = :id AND t.at_deleted_ts IS NULL
                    AND (t.at_creator_vc = :uname OR a.ac_reseller_fk = :uid)"
            );
            $sql->execute([':id' => $id, ':uname' => $r['username'], ':uid' => $r['uid']]);
        } else {
            $sql = $zdbh->prepare(
                "SELECT at_id_pk, at_name_vc, at_enabled_in FROM x_api_tokens
                  WHERE at_id_pk = :id AND at_deleted_ts IS NULL AND at_user_fk = :uid"
            );
            $sql->execute([':id' => $id, ':uid' => $r['uid']]);
        }

        $row = $sql->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── PRG helpers ──────────────────────────────────────────────────────────

    private static function redirectBack(): never
    {
        session_write_close();
        header('Location: ./?module=api_manager');
        exit;
    }

    private static function flashOk(string $msg): void    { $_SESSION['api_mgr_ok']    = $msg; }
    private static function flashError(string $msg): void { $_SESSION['api_mgr_error'] = $msg; }

    private static function flashToken(string $token): void
    {
        $_SESSION['api_mgr_token'] = $token;
    }

    private static function initSession(): void
    {
        if (self::$session_loaded) return;
        self::$session_loaded = true;

        if (!empty($_SESSION['api_mgr_token'])) {
            self::$new_token = $_SESSION['api_mgr_token'];
            unset($_SESSION['api_mgr_token']);
        }
        if (!empty($_SESSION['api_mgr_ok'])) {
            self::$ok = $_SESSION['api_mgr_ok'];
            unset($_SESSION['api_mgr_ok']);
        }
        if (!empty($_SESSION['api_mgr_error'])) {
            self::$error = $_SESSION['api_mgr_error'];
            unset($_SESSION['api_mgr_error']);
        }
    }

    // ── Getters de rol para el template ──────────────────────────────────────

    public static function getIsAdmin(): bool    { return self::currentRole()['is_admin']; }
    public static function getIsReseller(): bool { return self::currentRole()['is_reseller']; }
    public static function getIsUser(): bool     { return self::currentRole()['is_user']; }

    public static function getShowGlobalToggle(): bool { return self::getIsAdmin(); }
    public static function getShowBindField(): bool    { return !self::currentRole()['is_user']; }

    // ── Getters de estado global ──────────────────────────────────────────────

    public static function getApiEnabled(): bool
    {
        return ctrl_options::GetSystemOption('api_rest_enabled') === 'true';
    }

    public static function getApiDisabled(): bool
    {
        return !self::getApiEnabled();
    }

    public static function getApiEnabledText(): string
    {
        return self::getApiEnabled()
            ? '<span class="badge bg-success">Habilitada</span>'
            : '<span class="badge bg-danger">Deshabilitada</span>';
    }

    public static function getApiDisabledMessage(): string
    {
        $msg = ctrl_options::GetSystemOption('api_disabled_message') ?? '';
        return htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    }

    public static function getScopeOptionsHtml(): string
    {
        $r = self::currentRole();
        $all = [
            'admin'    => 'admin — acceso completo + sistema',
            'reseller' => 'reseller — gestión de sus usuarios y dominios',
            'write'    => 'write — recursos propios: lectura y escritura',
            'read'     => 'read — solo lectura (GET)',
        ];
        if ($r['is_admin'])        $allowed = ['admin', 'reseller', 'write', 'read'];
        elseif ($r['is_reseller']) $allowed = ['reseller', 'write', 'read'];
        else                       $allowed = ['write', 'read'];

        $html = '';
        foreach ($allowed as $val) {
            $html .= '<option value="' . $val . '">' . htmlspecialchars($all[$val], ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
        }
        return $html;
    }

    public static function getNewToken(): string
    {
        self::initSession();
        return (string)(self::$new_token ?? '');
    }

    // ── Getters del propio estado de delegación (para reseller/usuario) ───────

    private static function getSelfRow(): ?array
    {
        global $zdbh;
        $r = self::currentRole();
        if ($r['is_admin']) return null;
        $sql = $zdbh->prepare(
            "SELECT ac_api_allowed_in, ac_api_self_in, ac_api_revoked_in
               FROM x_accounts WHERE ac_id_pk = :uid LIMIT 1"
        );
        $sql->execute([':uid' => $r['uid']]);
        return $sql->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getSelfApiRevoked(): bool
    {
        $row = self::getSelfRow();
        return $row ? (bool)(int)$row['ac_api_revoked_in'] : false;
    }

    public static function getSelfApiAllowed(): bool
    {
        if (self::currentRole()['is_admin']) return true;
        $row = self::getSelfRow();
        if (!$row || (bool)(int)$row['ac_api_revoked_in']) return false;
        return (bool)(int)$row['ac_api_allowed_in'];
    }

    public static function getSelfApiEnabled(): bool
    {
        if (self::currentRole()['is_admin']) return true;
        $row = self::getSelfRow();
        return $row ? (bool)(int)$row['ac_api_self_in'] : true;
    }

    public static function getSelfApiAllowedText(): string
    {
        if (self::currentRole()['is_admin']) return '<span class="badge bg-success">Administrador</span>';
        $row = self::getSelfRow();
        if (!$row) return '<span class="badge bg-danger">Error</span>';
        if ((bool)(int)$row['ac_api_revoked_in'])  return '<span class="badge bg-danger">Revocado por administrador</span>';
        if ((bool)(int)$row['ac_api_allowed_in'])  return '<span class="badge bg-success">Concedido</span>';
        return '<span class="badge bg-default">No concedido</span>';
    }

    public static function getSelfApiEnabledText(): string
    {
        if (self::getSelfApiRevoked()) return '<span class="badge bg-danger">Revocado</span>';
        return self::getSelfApiEnabled()
            ? '<span class="badge bg-success">Activado</span>'
            : '<span class="badge bg-warning">Desactivado por ti</span>';
    }

    public static function getShowDelegationSection(): bool
    {
        $r = self::currentRole();
        return $r['is_admin'] || $r['is_reseller'];
    }

    // El formulario de creación de tokens NO se muestra si la cuenta está revocada o sin permiso
    public static function getShowTokenForm(): bool
    {
        return self::currentRole()['is_admin'] || self::getSelfApiAllowed();
    }

    // El toggle propio NO se muestra si el acceso está revocado o no concedido
    public static function getShowSelfToggle(): bool
    {
        if (self::currentRole()['is_admin']) return false;
        return self::getSelfApiAllowed() && !self::getSelfApiRevoked();
    }

    // ── Getters de listados de delegación ────────────────────────────────────

    // Construye las 3 celdas de acción pre-generadas para la vista de admin:
    //   btn_toggle   = Autorizar / Quitar acceso (vacío si revocado)
    //   btn_revoke   = Revocar API (visible si NO está revocado)
    //   btn_unrevoke = Levantar revocación (visible si está revocado)
    private static function adminActionButtons(int $uid, bool $revoked, bool $allowed): array
    {
        if ($revoked) {
            return [
                'btn_toggle'   => '',
                'btn_revoke'   => '',
                'btn_unrevoke' => '<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>Levantar revocaci&#243;n</button>',
            ];
        }
        $confirm = "return confirm('\\u00bfRevocar la API a esta cuenta y toda su jerarqu\\u00eda?\\nEsta acci\\u00f3n desactiva todos sus tokens activos.')";
        return [
            'btn_toggle'   => $allowed
                ? '<button class="btn btn-sm btn-secondary" type="submit"><i class="bi bi-dash-circle me-1"></i>Quitar acceso</button>'
                : '<button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-circle me-1"></i>Autorizar</button>',
            'btn_revoke'   => '<button class="btn btn-sm btn-danger" type="submit" onclick="' . $confirm . '"><i class="bi bi-slash-circle me-1"></i>Revocar API</button>',
            'btn_unrevoke' => '',
        ];
    }

    public static function getResellersWithApiStatus(): array
    {
        global $zdbh;
        if (!self::currentRole()['is_admin']) return [];

        $sql = $zdbh->prepare(
            "SELECT ac_id_pk, ac_user_vc, ac_api_allowed_in, ac_api_revoked_in
               FROM x_accounts
              WHERE ac_group_fk = 2 AND ac_deleted_ts IS NULL
              ORDER BY ac_user_vc"
        );
        $sql->execute();

        $rows = [];
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $uid     = (int)$row['ac_id_pk'];
            $revoked = (bool)(int)$row['ac_api_revoked_in'];
            $allowed = !$revoked && (bool)(int)$row['ac_api_allowed_in'];

            if ($revoked) {
                $status = '<span class="badge bg-danger">Revocado</span>';
            } elseif ($allowed) {
                $status = '<span class="badge bg-success">Autorizado</span>';
            } else {
                $status = '<span class="badge bg-default">Sin acceso</span>';
            }

            $btns   = self::adminActionButtons($uid, $revoked, $allowed);
            $rows[] = ['id' => $uid, 'username' => htmlspecialchars($row['ac_user_vc'], ENT_QUOTES, 'UTF-8')] + ['status_label' => $status] + $btns;
        }
        return $rows;
    }

    public static function getUsersWithApiStatus(): array
    {
        global $zdbh;
        if (!self::currentRole()['is_admin']) return [];

        $sql = $zdbh->prepare(
            "SELECT a.ac_id_pk, a.ac_user_vc, a.ac_api_allowed_in, a.ac_api_self_in,
                    a.ac_api_revoked_in,
                    r.ac_user_vc AS reseller_vc
               FROM x_accounts a
               LEFT JOIN x_accounts r ON r.ac_id_pk = a.ac_reseller_fk
              WHERE a.ac_group_fk = 3 AND a.ac_deleted_ts IS NULL
              ORDER BY a.ac_user_vc"
        );
        $sql->execute();

        $rows = [];
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $uid     = (int)$row['ac_id_pk'];
            $revoked = (bool)(int)$row['ac_api_revoked_in'];
            $allowed = !$revoked && (bool)(int)$row['ac_api_allowed_in'];
            $self_on = (bool)(int)$row['ac_api_self_in'];

            if ($revoked) {
                $status = '<span class="badge bg-danger">Revocado</span>';
            } elseif (!$allowed) {
                $status = '<span class="badge bg-default">Sin acceso</span>';
            } elseif (!$self_on) {
                $status = '<span class="badge bg-warning">Auto-desactivado</span>';
            } else {
                $status = '<span class="badge bg-success">Autorizado</span>';
            }

            $btns   = self::adminActionButtons($uid, $revoked, $allowed);
            $rows[] = [
                'id'       => $uid,
                'username' => htmlspecialchars($row['ac_user_vc'], ENT_QUOTES, 'UTF-8'),
                'reseller' => $row['reseller_vc'] ? htmlspecialchars($row['reseller_vc'], ENT_QUOTES, 'UTF-8') : '&mdash;',
                'status_label' => $status,
            ] + $btns;
        }
        return $rows;
    }

    // Reseller: sus clientes. Los resellers NO pueden revocar, solo autorizar/quitar.
    // Si el cliente está revocado por admin (gid=1) se muestra bloqueado.
    public static function getClientsWithApiStatus(): array
    {
        global $zdbh;
        $r = self::currentRole();
        if (!$r['is_reseller']) return [];

        $sql = $zdbh->prepare(
            "SELECT ac_id_pk, ac_user_vc, ac_api_allowed_in, ac_api_self_in,
                    ac_api_revoked_in, ac_api_revoked_by_gid
               FROM x_accounts
              WHERE ac_reseller_fk = :uid AND ac_group_fk = 3 AND ac_deleted_ts IS NULL
              ORDER BY ac_user_vc"
        );
        $sql->execute([':uid' => $r['uid']]);

        $rows = [];
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $revoked       = (bool)(int)$row['ac_api_revoked_in'];
            $revoked_by_admin = $revoked && (int)($row['ac_api_revoked_by_gid'] ?? 0) === 1;
            $allowed       = !$revoked && (bool)(int)$row['ac_api_allowed_in'];
            $self_on       = (bool)(int)$row['ac_api_self_in'];

            if ($revoked_by_admin) {
                $status     = '<span class="badge bg-danger">Revocado (admin)</span>';
                $btn_toggle = '';
            } elseif (!$allowed) {
                $status     = '<span class="badge bg-default">Sin acceso</span>';
                $btn_toggle = '<button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-circle me-1"></i>Autorizar</button>';
            } elseif (!$self_on) {
                $status     = '<span class="badge bg-warning">Auto-desactivado</span>';
                $btn_toggle = '<button class="btn btn-sm btn-secondary" type="submit"><i class="bi bi-dash-circle me-1"></i>Quitar acceso</button>';
            } else {
                $status     = '<span class="badge bg-success">Autorizado</span>';
                $btn_toggle = '<button class="btn btn-sm btn-secondary" type="submit"><i class="bi bi-dash-circle me-1"></i>Quitar acceso</button>';
            }

            $rows[] = [
                'id'           => (int)$row['ac_id_pk'],
                'username'     => htmlspecialchars($row['ac_user_vc'], ENT_QUOTES, 'UTF-8'),
                'status_label' => $status,
                'btn_toggle'   => $btn_toggle,
            ];
        }
        return $rows;
    }

    // ── Getter de tokens ──────────────────────────────────────────────────────

    public static function getTokens(): array
    {
        global $zdbh;
        $r = self::currentRole();

        $base_select = "SELECT t.at_id_pk, t.at_name_vc, t.at_creator_vc,
                               t.at_scope_vc, t.at_user_fk, t.at_enabled_in,
                               t.at_created_ts, t.at_lastused_ts,
                               t.at_last_ip_vc, t.at_allowed_ip_vc, t.at_expires_ts,
                               a.ac_user_vc AS bound_user
                          FROM x_api_tokens t
                          LEFT JOIN x_accounts a ON a.ac_id_pk = t.at_user_fk";

        if ($r['is_admin']) {
            $sql = $zdbh->prepare($base_select . " WHERE t.at_deleted_ts IS NULL ORDER BY t.at_created_ts DESC");
            $sql->execute();
        } elseif ($r['is_reseller']) {
            $sql = $zdbh->prepare($base_select . "
                WHERE t.at_deleted_ts IS NULL
                  AND (t.at_creator_vc = :uname OR a.ac_reseller_fk = :uid)
                ORDER BY t.at_created_ts DESC");
            $sql->execute([':uname' => $r['username'], ':uid' => $r['uid']]);
        } else {
            $sql = $zdbh->prepare($base_select . "
                WHERE t.at_deleted_ts IS NULL AND t.at_user_fk = :uid
                ORDER BY t.at_created_ts DESC");
            $sql->execute([':uid' => $r['uid']]);
        }

        $scope_labels = [
            'read'     => '<span class="badge bg-default">read</span>',
            'write'    => '<span class="badge bg-info">write</span>',
            'reseller' => '<span class="badge bg-warning">reseller</span>',
            'admin'    => '<span class="badge bg-danger">admin</span>',
        ];

        $rows = [];
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $enabled    = (bool)(int)$row['at_enabled_in'];
            $scope      = $row['at_scope_vc'] ?? 'read';
            $allowed_ip = $row['at_allowed_ip_vc'];
            $last_ip    = $row['at_last_ip_vc'];
            $expires_ts = $row['at_expires_ts'];

            // Expiración
            if ($expires_ts === null) {
                $expires_html = '<span class="text-muted">Nunca</span>';
            } else {
                $exp_time = strtotime($expires_ts);
                $diff     = $exp_time - time();
                $exp_fmt  = htmlspecialchars(date('d/m/Y H:i', $exp_time), ENT_QUOTES, 'UTF-8');
                if ($diff < 0) {
                    $expires_html = '<span class="badge bg-danger" title="Expirado">&#9888; ' . $exp_fmt . '</span>';
                } elseif ($diff < 7 * 86400) {
                    $expires_html = '<span class="badge bg-warning">' . $exp_fmt . '</span>';
                } else {
                    $expires_html = '<span class="text-muted">' . $exp_fmt . '</span>';
                }
            }

            // IP autorizada
            if ($allowed_ip === null || in_array($allowed_ip, ['', '0.0.0.0', '::'], true)) {
                $ip_allowed_html = '<span class="text-muted">Cualquiera</span>';
            } else {
                $ip_allowed_html = '<code>' . htmlspecialchars($allowed_ip, ENT_QUOTES, 'UTF-8') . '</code>';
            }

            // Última IP: alerta si difiere de la autorizada
            if ($last_ip === null) {
                $ip_last_html = '<span class="text-muted">&mdash;</span>';
            } else {
                $last_ip_clean = htmlspecialchars($last_ip, ENT_QUOTES, 'UTF-8');
                $restricted    = ($allowed_ip !== null && !in_array($allowed_ip, ['', '0.0.0.0', '::'], true));
                if ($restricted && $last_ip !== $allowed_ip) {
                    $ip_last_html = '<code style="color:#c0392b" title="&#9888; La &#250;ltima IP no coincide con la autorizada">&#9888; ' . $last_ip_clean . '</code>';
                } else {
                    $ip_last_html = '<code>' . $last_ip_clean . '</code>';
                }
            }

            $rows[] = [
                'id'             => (int)$row['at_id_pk'],
                'name'           => htmlspecialchars($row['at_name_vc'],    ENT_QUOTES, 'UTF-8'),
                'creator'        => htmlspecialchars($row['at_creator_vc'], ENT_QUOTES, 'UTF-8'),
                'scope_label'    => $scope_labels[$scope] ?? htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'),
                'bound_user'     => $row['bound_user'] ? htmlspecialchars($row['bound_user'], ENT_QUOTES, 'UTF-8') : '&mdash;',
                'status_label'   => $enabled ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-warning">Desactivado</span>',
                'toggle_label'   => $enabled ? 'Desactivar' : 'Activar',
                'lastused'       => $row['at_lastused_ts'] ?? '&mdash;',
                'created'        => htmlspecialchars($row['at_created_ts'], ENT_QUOTES, 'UTF-8'),
                'ip_allowed_html'=> $ip_allowed_html,
                'ip_last_html'   => $ip_last_html,
                'expires_html'   => $expires_html,
            ];
        }
        return $rows;
    }

    // ── Getter de auditoría ───────────────────────────────────────────────────

    public static function getAuditLog(): array
    {
        global $zdbh;
        $r = self::currentRole();

        $sql = $zdbh->prepare(
            "SELECT aa_actor_vc, aa_action_vc, aa_target_vc, aa_detail_tx, aa_ip_vc, aa_ts
               FROM x_api_audit WHERE aa_actor_vc = :uname ORDER BY aa_ts DESC LIMIT 100"
        );
        $sql->execute([':uname' => $r['username']]);

        $rows = [];
        while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'ts'     => htmlspecialchars($row['aa_ts'],        ENT_QUOTES, 'UTF-8'),
                'actor'  => htmlspecialchars($row['aa_actor_vc'],  ENT_QUOTES, 'UTF-8'),
                'action' => htmlspecialchars($row['aa_action_vc'], ENT_QUOTES, 'UTF-8'),
                'target' => htmlspecialchars($row['aa_target_vc'], ENT_QUOTES, 'UTF-8'),
                'detail' => $row['aa_detail_tx'] ? htmlspecialchars($row['aa_detail_tx'], ENT_QUOTES, 'UTF-8') : '',
                'ip'     => htmlspecialchars($row['aa_ip_vc'],     ENT_QUOTES, 'UTF-8'),
            ];
        }
        return $rows;
    }

    // Elimina las entradas de auditoría propias del usuario actual.
    // Admin borra sus entradas, reseller las suyas, usuario las suyas.
    public static function doCleanAuditLog(): void
    {
        runtime_csfr::Protect();
        global $zdbh;

        $r = self::currentRole();
        $zdbh->prepare(
            "DELETE FROM x_api_audit WHERE aa_actor_vc = :uname"
        )->execute([':uname' => $r['username']]);

        self::audit('Registro de auditoría propio limpiado');
        self::flashOk('Tu registro de auditoría ha sido limpiado.');
        self::redirectBack();
    }

    // ── Mensajes flash ────────────────────────────────────────────────────────

    public static function getResult(): string
    {
        self::initSession();
        if (!empty(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate(self::$ok), 'zannounceok');
        }
        return '';
    }

    public static function getError(): string
    {
        self::initSession();
        if (!empty(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate(self::$error), 'zannounceerror');
        }
        return '';
    }

    // ── Acciones de configuración global ─────────────────────────────────────

    public static function doToggleApi(): void
    {
        runtime_csfr::Protect();
        global $zdbh;

        if (!self::currentRole()['is_admin']) {
            self::flashError('Solo los administradores pueden cambiar el estado global de la API.');
            self::redirectBack();
        }

        $new_val = self::getApiEnabled() ? 'false' : 'true';
        $zdbh->prepare(
            "UPDATE x_settings SET so_value_tx = :val WHERE so_name_vc = 'api_rest_enabled'"
        )->execute([':val' => $new_val]);

        $action = $new_val === 'true' ? 'API global activada' : 'API global desactivada';
        self::audit($action);
        self::flashOk($new_val === 'true' ? 'API REST habilitada.' : 'API REST deshabilitada.');
        self::redirectBack();
    }

    public static function doSaveApiMessage(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        if (!self::currentRole()['is_admin']) {
            self::flashError('Solo los administradores pueden editar este mensaje.');
            self::redirectBack();
        }

        $msg = trim((string)$controller->GetControllerRequest('FORM', 'inApiDisabledMessage'));
        if (strlen($msg) > 512) {
            self::flashError('El mensaje no puede superar 512 caracteres.');
            self::redirectBack();
        }

        $zdbh->prepare(
            "UPDATE x_settings SET so_value_tx = :msg WHERE so_name_vc = 'api_disabled_message'"
        )->execute([':msg' => $msg]);

        self::audit('Mensaje API actualizado', '', $msg !== '' ? 'Personalizado' : 'Restaurado por defecto');
        self::flashOk('Mensaje actualizado.');
        self::redirectBack();
    }

    // ── Acciones de delegación ────────────────────────────────────────────────

    // Autorizar / Quitar acceso (toggle ac_api_allowed_in).
    // Bloqueado si la cuenta está revocada.
    public static function doToggleAccountApi(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $r         = self::currentRole();
        $target_id = (int)$controller->GetControllerRequest('FORM', 'inTargetId');

        if ($target_id <= 0) {
            self::flashError('ID de cuenta no válido.');
            self::redirectBack();
        }

        if ($r['is_admin']) {
            $check = $zdbh->prepare(
                "SELECT ac_id_pk, ac_user_vc, ac_api_allowed_in, ac_api_revoked_in
                   FROM x_accounts
                  WHERE ac_id_pk = :id AND ac_group_fk IN (2, 3) AND ac_deleted_ts IS NULL"
            );
            $check->execute([':id' => $target_id]);
        } elseif ($r['is_reseller']) {
            $check = $zdbh->prepare(
                "SELECT ac_id_pk, ac_user_vc, ac_api_allowed_in, ac_api_revoked_in, ac_api_revoked_by_gid
                   FROM x_accounts
                  WHERE ac_id_pk = :id AND ac_reseller_fk = :uid AND ac_group_fk = 3 AND ac_deleted_ts IS NULL"
            );
            $check->execute([':id' => $target_id, ':uid' => $r['uid']]);
        } else {
            self::flashError('Sin permiso.');
            self::redirectBack();
        }

        $target = $check->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            self::flashError('Cuenta no encontrada o sin permiso.');
            self::redirectBack();
        }

        // Si está revocado, no se puede toggle (reseller no puede; admin usa Levantar revocación)
        if ((int)$target['ac_api_revoked_in'] === 1) {
            self::flashError('Esta cuenta está revocada. Usa "Levantar revocación" para restaurar el acceso.');
            self::redirectBack();
        }

        $new_allowed = 1 - (int)$target['ac_api_allowed_in'];
        $zdbh->prepare(
            "UPDATE x_accounts SET ac_api_allowed_in = :v WHERE ac_id_pk = :id"
        )->execute([':v' => $new_allowed, ':id' => $target_id]);

        $action = $new_allowed ? 'Acceso API autorizado' : 'Acceso API retirado';
        self::audit($action, $target['ac_user_vc']);
        self::flashOk('Permiso de API actualizado.');
        self::redirectBack();
    }

    // Solo admin: revoca una cuenta y en cascada toda su jerarquía.
    // Desactiva inmediatamente todos los tokens de las cuentas afectadas.
    public static function doRevokeAccountApi(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $r = self::currentRole();
        if (!$r['is_admin']) {
            self::flashError('Solo los administradores pueden revocar el acceso a la API.');
            self::redirectBack();
        }

        $target_id = (int)$controller->GetControllerRequest('FORM', 'inTargetId');
        if ($target_id <= 0) {
            self::flashError('ID no válido.');
            self::redirectBack();
        }

        $check = $zdbh->prepare(
            "SELECT ac_id_pk, ac_user_vc, ac_group_fk, ac_api_revoked_in
               FROM x_accounts WHERE ac_id_pk = :id AND ac_group_fk IN (2,3) AND ac_deleted_ts IS NULL"
        );
        $check->execute([':id' => $target_id]);
        $target = $check->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            self::flashError('Cuenta no encontrada.');
            self::redirectBack();
        }

        if ((int)$target['ac_api_revoked_in'] === 1) {
            self::flashError('Esta cuenta ya está revocada.');
            self::redirectBack();
        }

        // Revocar la cuenta objetivo
        $zdbh->prepare(
            "UPDATE x_accounts
                SET ac_api_revoked_in=1, ac_api_revoked_by=:actor, ac_api_revoked_by_gid=1
              WHERE ac_id_pk=:id"
        )->execute([':actor' => $r['uid'], ':id' => $target_id]);

        $cascade = 0;

        if ((int)$target['ac_group_fk'] === 2) {
            // Reseller: propagar revocación a sus clientes que no estén ya revocados por un admin
            $upd = $zdbh->prepare(
                "UPDATE x_accounts
                    SET ac_api_revoked_in=1, ac_api_revoked_by=:tid, ac_api_revoked_by_gid=2
                  WHERE ac_reseller_fk=:tid AND ac_deleted_ts IS NULL
                    AND NOT (ac_api_revoked_in=1 AND ac_api_revoked_by_gid=1)"
            );
            $upd->execute([':tid' => $target_id]);
            $cascade = $upd->rowCount();

            // Desactivar todos los tokens del reseller y sus clientes
            $zdbh->prepare(
                "UPDATE x_api_tokens t
                   JOIN x_accounts a ON a.ac_id_pk = t.at_user_fk
                    SET t.at_enabled_in = 0
                  WHERE (a.ac_id_pk = :tid OR a.ac_reseller_fk = :tid)
                    AND t.at_deleted_ts IS NULL AND t.at_enabled_in = 1"
            )->execute([':tid' => $target_id]);
        } else {
            // Usuario: desactivar solo sus tokens
            $zdbh->prepare(
                "UPDATE x_api_tokens SET at_enabled_in = 0
                  WHERE at_user_fk = :uid AND at_deleted_ts IS NULL AND at_enabled_in = 1"
            )->execute([':uid' => $target_id]);
        }

        $detail = $cascade > 0 ? "Cascada: {$cascade} cuentas afectadas" : '';
        self::audit('API revocada', $target['ac_user_vc'], $detail);

        $msg = 'API revocada para ' . htmlspecialchars($target['ac_user_vc'], ENT_QUOTES, 'UTF-8') . '.';
        if ($cascade > 0) $msg .= " Cascada: {$cascade} cuentas afectadas.";
        $msg .= ' Todos los tokens activos han sido desactivados.';
        self::flashOk($msg);
        self::redirectBack();
    }

    // Solo admin: levanta la revocación y restaura en cascada las cuentas afectadas.
    // Los tokens permanecen desactivados — el usuario debe reactivarlos manualmente.
    public static function doUnrevokeAccountApi(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $r = self::currentRole();
        if (!$r['is_admin']) {
            self::flashError('Solo los administradores pueden levantar una revocación.');
            self::redirectBack();
        }

        $target_id = (int)$controller->GetControllerRequest('FORM', 'inTargetId');
        if ($target_id <= 0) {
            self::flashError('ID no válido.');
            self::redirectBack();
        }

        $check = $zdbh->prepare(
            "SELECT ac_id_pk, ac_user_vc, ac_group_fk FROM x_accounts
              WHERE ac_id_pk = :id AND ac_deleted_ts IS NULL"
        );
        $check->execute([':id' => $target_id]);
        $target = $check->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            self::flashError('Cuenta no encontrada.');
            self::redirectBack();
        }

        // Levantar la revocación del objetivo
        $zdbh->prepare(
            "UPDATE x_accounts
                SET ac_api_revoked_in=0, ac_api_revoked_by=NULL, ac_api_revoked_by_gid=NULL
              WHERE ac_id_pk=:id"
        )->execute([':id' => $target_id]);

        $cascade = 0;

        if ((int)$target['ac_group_fk'] === 2) {
            // Reseller: restaurar clientes cuya revocación fue propagada por este reseller
            $upd = $zdbh->prepare(
                "UPDATE x_accounts
                    SET ac_api_revoked_in=0, ac_api_revoked_by=NULL, ac_api_revoked_by_gid=NULL
                  WHERE ac_reseller_fk=:tid AND ac_api_revoked_by=:tid AND ac_deleted_ts IS NULL"
            );
            $upd->execute([':tid' => $target_id]);
            $cascade = $upd->rowCount();
        }

        $detail = $cascade > 0 ? "Cascada restaurada: {$cascade} cuentas" : '';
        self::audit('Revocación API levantada', $target['ac_user_vc'], $detail);

        $msg = 'Revocación levantada para ' . htmlspecialchars($target['ac_user_vc'], ENT_QUOTES, 'UTF-8') . '.';
        if ($cascade > 0) $msg .= " {$cascade} cuentas descendientes restauradas.";
        $msg .= ' Los tokens permanecen desactivados hasta que cada usuario los reactive.';
        self::flashOk($msg);
        self::redirectBack();
    }

    // Reseller o usuario: activa/desactiva su propio acceso (ac_api_self_in).
    public static function doToggleSelfApi(): void
    {
        runtime_csfr::Protect();
        global $zdbh;

        $r = self::currentRole();
        if ($r['is_admin']) {
            self::flashError('Los administradores controlan la API mediante el ajuste global.');
            self::redirectBack();
        }
        if (self::getSelfApiRevoked()) {
            self::flashError('Tu acceso a la API está revocado por el administrador.');
            self::redirectBack();
        }
        if (!self::getSelfApiAllowed()) {
            self::flashError('Tu administrador no ha habilitado el acceso a la API para tu cuenta.');
            self::redirectBack();
        }

        $row = $zdbh->prepare("SELECT ac_api_self_in FROM x_accounts WHERE ac_id_pk = :uid LIMIT 1");
        $row->execute([':uid' => $r['uid']]);
        $current = (int)($row->fetchColumn() ?: 1);
        $new_val = 1 - $current;

        $zdbh->prepare(
            "UPDATE x_accounts SET ac_api_self_in = :v WHERE ac_id_pk = :uid"
        )->execute([':v' => $new_val, ':uid' => $r['uid']]);

        $action = $new_val ? 'Auto-activado acceso API' : 'Auto-desactivado acceso API';
        self::audit($action);
        self::flashOk('Tu estado de acceso a la API ha sido actualizado.');
        self::redirectBack();
    }

    // Deshabilitación masiva: admin→resellers|users; reseller→clients
    public static function doBulkDisableApi(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $r      = self::currentRole();
        $target = trim((string)$controller->GetControllerRequest('FORM', 'inBulkTarget'));

        if ($r['is_admin']) {
            if ($target === 'resellers') {
                $stmt = $zdbh->prepare("UPDATE x_accounts SET ac_api_allowed_in=0 WHERE ac_group_fk=2 AND ac_api_revoked_in=0");
                $stmt->execute();
                self::audit('Bulk: API deshabilitada', 'resellers', $stmt->rowCount() . ' cuentas');
                self::flashOk('API deshabilitada para todos los resellers sin revocación activa.');
            } elseif ($target === 'users') {
                $stmt = $zdbh->prepare("UPDATE x_accounts SET ac_api_allowed_in=0 WHERE ac_group_fk=3 AND ac_api_revoked_in=0");
                $stmt->execute();
                self::audit('Bulk: API deshabilitada', 'users', $stmt->rowCount() . ' cuentas');
                self::flashOk('API deshabilitada para todos los usuarios sin revocación activa.');
            } else {
                self::flashError('Destino no válido.');
            }
        } elseif ($r['is_reseller']) {
            if ($target === 'clients') {
                $stmt = $zdbh->prepare(
                    "UPDATE x_accounts SET ac_api_allowed_in=0
                      WHERE ac_reseller_fk=:uid AND ac_group_fk=3 AND ac_api_revoked_in=0"
                );
                $stmt->execute([':uid' => $r['uid']]);
                self::audit('Bulk: API deshabilitada', 'clients', $stmt->rowCount() . ' cuentas');
                self::flashOk('API deshabilitada para todos tus clientes sin revocación activa.');
            } else {
                self::flashError('Destino no válido.');
            }
        } else {
            self::flashError('Sin permiso.');
        }

        self::redirectBack();
    }

    // ── Acciones de token ─────────────────────────────────────────────────────

    public static function doCreateToken(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $r     = self::currentRole();

        // Verificar que la cuenta no está revocada ni bloqueada antes de crear tokens
        if (!$r['is_admin']) {
            if (self::getSelfApiRevoked()) {
                self::flashError('Tu acceso a la API está revocado por el administrador.');
                self::redirectBack();
            }
            if (!self::getSelfApiAllowed()) {
                self::flashError('Tu administrador no ha habilitado el acceso a la API para tu cuenta.');
                self::redirectBack();
            }
        }

        $name  = trim((string)$controller->GetControllerRequest('FORM', 'inTokenName'));
        $scope = trim((string)$controller->GetControllerRequest('FORM', 'inTokenScope'));

        if ($r['is_admin'])        $allowed_scopes = ['read', 'write', 'reseller', 'admin'];
        elseif ($r['is_reseller']) $allowed_scopes = ['read', 'write', 'reseller'];
        else                       $allowed_scopes = ['read', 'write'];

        if (!in_array($scope, $allowed_scopes, true)) {
            self::flashError('Scope no permitido para tu nivel de acceso.');
            self::redirectBack();
        }
        if ($name === '') {
            self::flashError('El nombre del token no puede estar vacío.');
            self::redirectBack();
        }
        if (strlen($name) > 128) {
            self::flashError('El nombre es demasiado largo (máx. 128 caracteres).');
            self::redirectBack();
        }

        // IP autorizada (opcional)
        $allowed_ip_raw = trim((string)$controller->GetControllerRequest('FORM', 'inTokenAllowedIp'));
        $allowed_ip     = null;
        if ($allowed_ip_raw !== '') {
            if (!filter_var($allowed_ip_raw, FILTER_VALIDATE_IP)) {
                self::flashError('La dirección IP autorizada no es válida.');
                self::redirectBack();
            }
            $allowed_ip = $allowed_ip_raw;
        }

        // Fecha de expiración (opcional)
        $expires_raw = trim((string)$controller->GetControllerRequest('FORM', 'inTokenExpires'));
        $expires_ts  = null;
        if ($expires_raw !== '') {
            $ts = strtotime($expires_raw);
            if ($ts === false || $ts <= time()) {
                self::flashError('La fecha de expiración no es válida o es anterior a la fecha actual.');
                self::redirectBack();
            }
            $expires_ts = date('Y-m-d H:i:s', $ts);
        }

        // Usuario vinculado
        $user_fk     = null;
        $user_fk_raw = trim((string)$controller->GetControllerRequest('FORM', 'inTokenUser'));

        if ($r['is_user']) {
            $user_fk = $r['uid'];
        } elseif ($user_fk_raw !== '' && $user_fk_raw !== '0') {
            $uq = $zdbh->prepare(
                "SELECT ac_id_pk, ac_reseller_fk FROM x_accounts
                  WHERE ac_user_vc = :u AND ac_deleted_ts IS NULL LIMIT 1"
            );
            $uq->execute([':u' => $user_fk_raw]);
            $urow = $uq->fetch(PDO::FETCH_ASSOC);
            if (!$urow) {
                self::flashError("Usuario '$user_fk_raw' no encontrado.");
                self::redirectBack();
            }
            if ($r['is_reseller'] && (int)$urow['ac_reseller_fk'] !== $r['uid']) {
                self::flashError('Solo puedes vincular tokens a tus propios clientes.');
                self::redirectBack();
            }
            $user_fk = (int)$urow['ac_id_pk'];
        }

        $token_plain = bin2hex(random_bytes(32));
        $token_hash  = hash('sha256', $token_plain);

        $sql = $zdbh->prepare(
            "INSERT INTO x_api_tokens
                (at_name_vc, at_creator_vc, at_token_hash_vc,
                 at_scope_vc, at_user_fk, at_allowed_ip_vc, at_expires_ts,
                 at_enabled_in, at_created_ts)
             VALUES (:name, :creator, :hash, :scope, :user_fk, :allowed_ip, :expires, 1, NOW())"
        );
        $sql->bindParam(':name',       $name);
        $sql->bindParam(':creator',    $r['username']);
        $sql->bindParam(':hash',       $token_hash);
        $sql->bindParam(':scope',      $scope);
        $sql->bindValue(':user_fk',    $user_fk,    $user_fk    !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $sql->bindValue(':allowed_ip', $allowed_ip, $allowed_ip !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $sql->bindValue(':expires',    $expires_ts, $expires_ts !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $sql->execute();

        $detail_parts = [];
        if ($allowed_ip)  $detail_parts[] = "IP: {$allowed_ip}";
        if ($expires_ts)  $detail_parts[] = "Expira: {$expires_ts}";
        self::audit('Token creado', "{$name} [{$scope}]", implode(', ', $detail_parts));
        self::flashToken($token_plain);
        self::flashOk('Token creado — cópialo ahora, no se volverá a mostrar.');
        self::redirectBack();
    }

    public static function doRotateToken(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $id = (int)$controller->GetControllerRequest('FORM', 'inTokenId');
        if ($id <= 0) {
            self::flashError('ID de token no válido.');
            self::redirectBack();
        }

        $row = self::ownedTokenRow($id);
        if (!$row) {
            self::flashError('Token no encontrado o sin permiso.');
            self::redirectBack();
        }

        $new_plain = bin2hex(random_bytes(32));
        $new_hash  = hash('sha256', $new_plain);

        $zdbh->prepare(
            "UPDATE x_api_tokens
                SET at_token_hash_vc=:hash, at_token_vc=NULL
              WHERE at_id_pk=:id AND at_deleted_ts IS NULL"
        )->execute([':hash' => $new_hash, ':id' => $id]);

        self::audit('Token rotado', $row['at_name_vc']);
        self::flashToken($new_plain);
        self::flashOk('Token rotado — cópialo ahora, el valor anterior ha quedado invalidado.');
        self::redirectBack();
    }

    public static function doRevokeToken(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $id = (int)$controller->GetControllerRequest('FORM', 'inTokenId');
        if ($id <= 0) {
            self::flashError('ID de token no válido.');
            self::redirectBack();
        }

        $row = self::ownedTokenRow($id);
        if (!$row) {
            self::flashError('Token no encontrado o sin permiso.');
            self::redirectBack();
        }

        $zdbh->prepare(
            "UPDATE x_api_tokens SET at_deleted_ts=NOW()
              WHERE at_id_pk=:id AND at_deleted_ts IS NULL"
        )->execute([':id' => $id]);

        self::audit('Token eliminado', $row['at_name_vc']);
        self::flashOk('Token eliminado.');
        self::redirectBack();
    }

    public static function doToggleToken(): void
    {
        runtime_csfr::Protect();
        global $controller, $zdbh;

        $id = (int)$controller->GetControllerRequest('FORM', 'inTokenId');
        if ($id <= 0) {
            self::flashError('ID de token no válido.');
            self::redirectBack();
        }

        $row = self::ownedTokenRow($id);
        if (!$row) {
            self::flashError('Token no encontrado o sin permiso.');
            self::redirectBack();
        }

        $new_state = 1 - (int)$row['at_enabled_in'];
        $zdbh->prepare(
            "UPDATE x_api_tokens SET at_enabled_in=:v
              WHERE at_id_pk=:id AND at_deleted_ts IS NULL"
        )->execute([':v' => $new_state, ':id' => $id]);

        $action = $new_state ? 'Token activado' : 'Token desactivado';
        self::audit($action, $row['at_name_vc']);
        self::flashOk('Estado del token actualizado.');
        self::redirectBack();
    }
}
