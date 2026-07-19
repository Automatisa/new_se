/*
 * sentora_maillimit_helper — cuenta los envíos de correo por cuenta de hosting y hora.
 *
 * Fase B del endurecimiento (ver SOLUCIONES.md FIX-159): en la Fase A el wrapper de sendmail
 * (sentora_mail_limit.sh) hablaba con Redis usando una credencial que la propia cuenta de
 * hosting podía leer. Eso permitía a un PHP infectado, con esa credencial, incrementar el
 * contador de OTRA cuenta (griefing). Aquí ese contacto con Redis se mueve a este binario
 * setgid (grupo 'maillimit'):
 *
 *   - Se instala 2755 root:maillimit. Al ejecutarlo, el proceso adquiere egid=maillimit y
 *     puede leer la credencial (/var/bulwark/mail_limits/redis_pass, 640 root:maillimit).
 *     La cuenta de hosting, con su propio gid, NO puede leer esa credencial.
 *   - La cuenta que se cuenta se deduce del uid REAL (getuid → h_<cuenta>), infalsificable.
 *     El caller no puede indicar otra cuenta => no hay griefing.
 *   - Por ser setgid, el kernel prohíbe ptrace/core del proceso al mismo uid real, así que
 *     la credencial no se puede extraer de memoria.
 *   - Habla el protocolo RESP directamente por socket: la contraseña nunca aparece en argv
 *     ni en el entorno de un proceso propiedad del inquilino.
 *
 * Hace AUTH + INCR + EXPIRE de sentora:maillimit:<cuenta>:<YYYYMMDDHH> e imprime el contador
 * resultante. Ante cualquier problema sale 0 sin imprimir (fail-open: el correo pasa).
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <pwd.h>
#include <time.h>
#include <ctype.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>

#define CRED_FILE "/var/bulwark/mail_limits/redis_pass"
#define RUSER     "maillimit"
#define TTL       "3700"

/* fail-open: cualquier fallo => salir 0 sin imprimir (el wrapper deja pasar el correo). */
static void bail(void) { _exit(0); }

static int read_cred(char *buf, size_t n) {
    FILE *f = fopen(CRED_FILE, "r");
    if (!f) return 0;
    if (!fgets(buf, (int)n, f)) { fclose(f); return 0; }
    fclose(f);
    buf[strcspn(buf, "\r\n")] = 0;
    return buf[0] != 0;
}

int main(void) {
    struct passwd *pw = getpwuid(getuid());
    if (!pw || !pw->pw_name) bail();
    const char *u = pw->pw_name;
    if (strncmp(u, "h_", 2) != 0) bail();          /* solo cuentas de hosting */
    const char *acct = u + 2;
    if (!*acct) bail();
    for (const char *p = acct; *p; ++p)
        if (!(islower((unsigned char)*p) || isdigit((unsigned char)*p) || *p == '_' || *p == '-'))
            bail();

    char pass[256];
    if (!read_cred(pass, sizeof pass)) bail();

    time_t t = time(NULL);
    struct tm tmv;
    localtime_r(&t, &tmv);
    char hour[16];
    strftime(hour, sizeof hour, "%Y%m%d%H", &tmv);

    char key[128];
    int kl = snprintf(key, sizeof key, "sentora:maillimit:%s:%s", acct, hour);
    if (kl <= 0 || kl >= (int)sizeof key) bail();

    /* Pipeline RESP: AUTH <user> <pass> / INCR <key> / EXPIRE <key> 3700 */
    char cmd[1024];
    int n = snprintf(cmd, sizeof cmd,
        "*3\r\n$4\r\nAUTH\r\n$%zu\r\n%s\r\n$%zu\r\n%s\r\n"
        "*2\r\n$4\r\nINCR\r\n$%d\r\n%s\r\n"
        "*3\r\n$6\r\nEXPIRE\r\n$%d\r\n%s\r\n$4\r\n" TTL "\r\n",
        strlen(RUSER), RUSER, strlen(pass), pass, kl, key, kl, key);
    if (n <= 0 || n >= (int)sizeof cmd) bail();

    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) bail();
    struct sockaddr_in sa;
    memset(&sa, 0, sizeof sa);
    sa.sin_family = AF_INET;
    sa.sin_port = htons(6379);
    sa.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    struct timeval tv = { 2, 0 };
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof tv);
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv);
    if (connect(fd, (struct sockaddr *)&sa, sizeof sa) != 0) { close(fd); bail(); }
    if (write(fd, cmd, (size_t)n) != n) { close(fd); bail(); }

    /* Leer respuestas hasta tener 3 líneas CRLF (AUTH, INCR, EXPIRE) o timeout. */
    char resp[512];
    size_t got = 0;
    ssize_t r;
    while (got < sizeof(resp) - 1 && (r = read(fd, resp + got, sizeof(resp) - 1 - got)) > 0) {
        got += (size_t)r;
        int crlf = 0;
        for (size_t i = 0; i + 1 < got; i++)
            if (resp[i] == '\r' && resp[i + 1] == '\n') crlf++;
        if (crlf >= 3) break;
    }
    close(fd);
    resp[got] = 0;

    if (resp[0] == '-') bail();                     /* AUTH falló => fail-open */
    char *nl = strstr(resp, "\r\n");
    if (!nl) bail();
    char *incr = nl + 2;                            /* respuesta a INCR: ":<n>\r\n" */
    if (*incr != ':') bail();
    long count = strtol(incr + 1, NULL, 10);
    if (count < 0) bail();
    printf("%ld\n", count);
    return 0;
}
