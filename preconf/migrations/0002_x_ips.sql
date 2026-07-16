-- 0002_x_ips.sql — Inventario de IPs del servidor/cluster (multi-IP, Fase 1).
-- Registra las IPs disponibles, a qué nodo pertenecen, a qué reseller están asignadas
-- (NULL = pool del admin) y si son compartidas o dedicadas. La asignación IP->dominio va
-- por x_vhosts.vh_ip_fk (migración aparte). Idempotente.

CREATE TABLE IF NOT EXISTS `x_ips` (
  `ip_id_pk` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address_vc` varchar(45) NOT NULL COMMENT 'IPv4 o IPv6',
  `ip_node_fk` int(11) DEFAULT NULL COMMENT 'FK a x_dns_nodes (nodo del cluster); NULL = este servidor',
  `ip_reseller_fk` int(10) DEFAULT NULL COMMENT 'FK a x_accounts del reseller al que se asigna; NULL = pool admin',
  `ip_shared_in` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = compartible por varios dominios; 0 = dedicada (un dominio)',
  `ip_enabled_in` tinyint(1) NOT NULL DEFAULT 1,
  `ip_is_primary_in` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'IP principal del servidor (no es alias; no se quita del SO)',
  `ip_ptr_vc` varchar(255) DEFAULT NULL COMMENT 'rDNS/PTR (informativo; lo fija el proveedor de la IP)',
  `ip_notes_vc` varchar(255) DEFAULT NULL,
  `ip_created_ts` int(11) DEFAULT NULL,
  PRIMARY KEY (`ip_id_pk`),
  UNIQUE KEY `ip_address_uq` (`ip_address_vc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Sembrar la IP actual del servidor (server_ip) como IP PRIMARIA del pool, si aún no está.
INSERT INTO x_ips (ip_address_vc, ip_is_primary_in, ip_shared_in, ip_enabled_in, ip_notes_vc, ip_created_ts)
SELECT s.so_value_tx, 1, 1, 1, 'IP principal del servidor (sembrada por migración)', UNIX_TIMESTAMP()
FROM x_settings s
WHERE s.so_name_vc = 'server_ip'
  AND s.so_value_tx <> ''
  AND NOT EXISTS (SELECT 1 FROM x_ips i WHERE i.ip_address_vc = s.so_value_tx);
