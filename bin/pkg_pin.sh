#!/bin/sh
# pkg_pin.sh — Pinning de paquetes críticos del stack (módulo updates).
#
# PROBLEMA: en la rama 'latest' de pkg, los paquetes de nombre SIN versión (dovecot, redis, ...)
# pueden SALTAR DE MAYOR con un 'pkg upgrade' normal, rompiendo la estructura de config o el
# formato de datos → el servicio no arranca tras el upgrade (p.ej. mail caído para todos).
# Los paquetes con la mayor en el NOMBRE (php84, bind920, apache24, mysql84) NO tienen este
# problema: 'pkg upgrade' ya sólo les mueve subversiones. Por eso aquí sólo se gestionan los
# primeros.
#
# MODELO: se mantienen los gestionados con 'pkg lock' (congelados). Este helper:
#   - deja pasar SUBVERSIONES (misma mayor): unlock -> upgrade -> relock  (parches de seguridad).
#   - RETIENE saltos de MAYOR hasta que el admin pulsa "Verificar y actualizar" en el panel.
#
# Uso:  pkg_pin.sh check | lock-all | auto-sub | verify-major <pkg>
#   check         Clasifica los gestionados (al día / subversión / mayor) → pkgpins.json. Sin red extra.
#   lock-all      pkg lock a todos los gestionados instalados (lo llama el instalador).
#   auto-sub      Aplica en 2º plano las subversiones disponibles (lo llama el daemon diario).
#   verify-major  Salta de mayor UN paquete (unlock->upgrade->relock) + migración de preconf.
#
# SEGURIDAD: sólo se tocan los paquetes de MANAGED (whitelist única, aquí). Cualquier otro nombre
# se rechaza, aunque el nombre venga validado también en el lado PHP (privilege::run).

set -u
umask 022

OUT_DIR="/var/bulwark/updates"
PINS="$OUT_DIR/pkgpins.json"
RUN="$OUT_DIR/running"
RES="$OUT_DIR/last_result"
LOG="$OUT_DIR/last_action.log"
MIGRATE="/usr/local/bulwark/bin/preconf_migrate.sh"
mkdir -p "$OUT_DIR"; chown root:www "$OUT_DIR" 2>/dev/null || true; chmod 755 "$OUT_DIR"

# Paquetes gestionados: "nombre:profundidad_de_mayor" (nº de componentes dotted que definen la
# mayor que NO debe saltar sola). Nombre EXACTO de pkg(8) (variantes -mysql en Bulwark).
# Elección de la profundidad por paquete (ajustable):
#   dovecot-mysql:2  -> 2.3 : el salto 2.3->2.4 rompe la config (dovecot es estricto).
#   proftpd:2        -> 1.3 : la serie 1.3.x es estable; 1.3->1.4 sería el salto de mayor.
#   redis:1          -> 8   : cambios de formato/datos entre mayores (7->8).
#   postfix-mysql:1  -> 3   : la serie 3.x es estable; 3->4 sería el salto.
#   rspamd:1         -> 4   : subversiones 4.x automáticas (parches); 4->5 se retiene.
#   opendkim:1       -> 2   : muy estable; sólo se retiene 2->3.
MANAGED="dovecot-mysql:2 proftpd:2 redis:1 postfix-mysql:1 rspamd:1 opendkim:1"

managed_depth() {   # $1 pkg -> imprime depth y 0 si gestionado; 1 si no
    for e in $MANAGED; do
        [ "${e%:*}" = "$1" ] && { echo "${e#*:}"; return 0; }
    done
    return 1
}

major() {           # $1 version, $2 depth -> mayor (quita revisión _N/,N y recorta a depth componentes)
    echo "$1" | sed -E 's/[_,].*$//' | cut -d. -f1-"$2"
}

candidate() {       # $1 pkg -> versión candidata más alta del repo (o vacío)
    pkg rquery %v "$1" 2>/dev/null | sort -V | tail -1
}

classify() {        # $1 inst, $2 cand, $3 depth -> uptodate|subversion|major
    [ -n "$2" ] || { echo uptodate; return; }
    [ "$(pkg version -t "$1" "$2" 2>/dev/null)" = "<" ] || { echo uptodate; return; }
    if [ "$(major "$1" "$3")" = "$(major "$2" "$3")" ]; then echo subversion; else echo major; fi
}

do_check() {
    pkg update -q >/dev/null 2>&1
    TMP="$PINS.tmp.$$"
    {
        printf '{\n  "checked_ts": %s,\n  "packages": [\n' "$(date +%s)"
        first=1
        for e in $MANAGED; do
            p="${e%:*}"; d="${e#*:}"
            inst=$(pkg query %v "$p" 2>/dev/null)
            [ -n "$inst" ] || continue          # no instalado en este servidor
            lk=$(pkg query %k "$p" 2>/dev/null); [ "$lk" = "1" ] && lk=true || lk=false
            cand=$(candidate "$p")
            state=$(classify "$inst" "$cand" "$d")
            [ $first -eq 1 ] || printf ',\n'; first=0
            printf '    {"pkg":"%s","installed":"%s","candidate":"%s","major":"%s","locked":%s,"state":"%s"}' \
                   "$p" "$inst" "${cand:-$inst}" "$(major "$inst" "$d")" "$lk" "$state"
        done
        printf '\n  ]\n}\n'
    } > "$TMP"
    mv "$TMP" "$PINS"; chown root:www "$PINS" 2>/dev/null || true; chmod 644 "$PINS"
}

do_lock_all() {
    for e in $MANAGED; do
        p="${e%:*}"
        pkg query %v "$p" >/dev/null 2>&1 && pkg lock -y -q "$p" >/dev/null 2>&1
    done
}

upgrade_one() {     # $1 pkg -> unlock -> upgrade -> relock (respeta el pin)
    pkg unlock -y -q "$1" >/dev/null 2>&1
    pkg upgrade -y "$1"; rc=$?
    pkg lock -y -q "$1" >/dev/null 2>&1
    return $rc
}

case "${1:-}" in
  check)
      do_check
      ;;

  lock-all)
      do_lock_all
      do_check
      ;;

  auto-sub)
      # Aplica subversiones de TODOS los gestionados que estén en estado 'subversion'.
      printf 'pin' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"
      {
          pkg update -q >/dev/null 2>&1
          n=0
          for e in $MANAGED; do
              p="${e%:*}"; d="${e#*:}"
              inst=$(pkg query %v "$p" 2>/dev/null); [ -n "$inst" ] || continue
              cand=$(candidate "$p")
              [ "$(classify "$inst" "$cand" "$d")" = "subversion" ] || continue
              echo "== $p: subversión $inst -> $cand"
              upgrade_one "$p"
              n=$((n+1))
          done
          printf 'pin|0|%s|%s' "$(date +%s)" "$n" > "$RES"
          chmod 644 "$RES" 2>/dev/null || true; chown root:www "$RES" 2>/dev/null || true
          do_check
          rm -f "$RUN"
      } > "$LOG" 2>&1 &
      chmod 644 "$LOG" 2>/dev/null || true; chown root:www "$LOG" 2>/dev/null || true
      ;;

  verify-major)
      p="${2:-}"
      d=$(managed_depth "$p") || { echo "pkg_pin: paquete no gestionado: '$p'" >&2; exit 2; }
      printf 'pin' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"
      {
          inst=$(pkg query %v "$p" 2>/dev/null)
          echo "== Verificar y actualizar MAYOR: $p (instalado $inst)"
          upgrade_one "$p"; rc=$?
          new=$(pkg query %v "$p" 2>/dev/null)
          echo "== nueva versión: $new (rc=$rc)"
          if [ "$rc" -eq 0 ] && [ -x "$MIGRATE" ]; then
              echo "== migración de preconf: $p $(major "$new" "$d")"
              "$MIGRATE" "$p" "$(major "$new" "$d")" 2>&1
          fi
          printf 'pin|%s|%s|%s' "$rc" "$(date +%s)" "$p" > "$RES"
          chmod 644 "$RES" 2>/dev/null || true; chown root:www "$RES" 2>/dev/null || true
          do_check
          rm -f "$RUN"
      } > "$LOG" 2>&1 &
      chmod 644 "$LOG" 2>/dev/null || true; chown root:www "$LOG" 2>/dev/null || true
      ;;

  *)
      echo "uso: $0 check|lock-all|auto-sub|verify-major <pkg>" >&2
      exit 1
      ;;
esac
exit 0
