<?php
// Worker (tick) de imapsync — corre cada ciclo del daemon (root). Reconcilia los trabajos 'running'
// (si su proceso murió sin finalizar, los marca error) y lanza los 'queued' EN SEGUNDO PLANO hasta el
// tope de concurrencia. El límite por cuenta/día se aplica al encolar (doLaunch); aquí solo concurrencia.
if (ui_module::CheckModuleEnabled('IMAP Migration (imapsync)')) {
    global $zdbh;
    $rundir = '/var/bulwark/run/imapsync/';
    $maxc = (int)ctrl_options::GetSystemOption('imapsync_max_concurrent');
    if ($maxc <= 0) { $maxc = 2; }

    // Reconciliar: un 'running' cuyo PID ya no vive y sigue 'running' -> el runner murió sin cerrar.
    foreach ($zdbh->query("SELECT ij_id_pk FROM x_imapsync_jobs WHERE ij_status_vc='running' AND ij_deleted_ts IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pidf = $rundir . (int)$id . '.pid';
        $alive = false;
        if (is_file($pidf)) {
            $pid = (int)@file_get_contents($pidf);
            if ($pid > 0) { $o = array(); $rc = 0; @exec('kill -0 ' . $pid . ' 2>/dev/null', $o, $rc); $alive = ($rc === 0); }
        }
        if (!$alive) {
            $zdbh->prepare("UPDATE x_imapsync_jobs SET ij_status_vc='error', ij_error_tx='el proceso terminó sin finalizar', ij_updated_ts=UNIX_TIMESTAMP() WHERE ij_id_pk=:id AND ij_status_vc='running'")->execute(array(':id' => (int)$id));
            @unlink($pidf);
        }
    }

    // Lanzar encolados hasta el tope de concurrencia (en segundo plano; el daemon no se bloquea).
    $running = (int)$zdbh->query("SELECT COUNT(*) FROM x_imapsync_jobs WHERE ij_status_vc='running' AND ij_deleted_ts IS NULL")->fetchColumn();
    $slots = $maxc - $running;
    if ($slots > 0) {
        foreach ($zdbh->query("SELECT ij_id_pk FROM x_imapsync_jobs WHERE ij_status_vc='queued' AND ij_deleted_ts IS NULL ORDER BY ij_id_pk ASC LIMIT " . (int)$slots)->fetchAll(PDO::FETCH_COLUMN) as $id) {
            @exec('nohup /usr/local/bin/php -q /usr/local/bulwark/bin/imapsync_run.php ' . (int)$id . ' >/dev/null 2>&1 &');
            echo "imapsync: lanzado trabajo #" . (int)$id . fs_filehandler::NewLine();
        }
    }
}
