# Kontia como SaaS: puesta en marcha del backend

Esta guía cubre lo que hay que configurar en el VPS para usar el registro
self-service, el ingreso con Google, los planes y el cobro con Mercado Pago.
Todo corre en el VPS actual, sin Docker.

## 1. Antes del primer deploy

1. Copiar `.env.dist` a `.env` (o completar el `.env` que ya existe) con las
   variables nuevas: `APP_URL_FRONT`, `MAIL_FROM`, `MAILER_DSN`,
   `GOOGLE_CLIENT_IDS`, `TURNSTILE_SECRET`/`TURNSTILE_SITE_KEY`, `DIAS_PRUEBA`,
   `PLAN_PRUEBA`, `DIAS_GRACIA_MORA`, `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`,
   `MP_BACK_URL`, `ADMIN_EMAILS` (a quién avisar por mail de altas, planes, cobros y
   cancelaciones; varios separados por coma).
   **Ojo:** si existe `.env.local.php` (lo genera `composer dump-env`), Symfony
   usa ese archivo e ignora el `.env`. Regeneralo con `composer dump-env prod`
   después de agregar las variables, o renombralo para que se lea el `.env`.
2. Hacer un backup manual de la base.
3. Correr `./scripts/deploy.sh mejoras-back` (o la rama que corresponda). El
   script hace backup, instala dependencias, corre `app:migraciones` y limpia caché.

### Qué cambia en la base (migración 0002)

- `user`: columnas para verificación de email, recuperación de clave y Google.
  Los usuarios existentes quedan con el email verificado.
- `plan_suscripcion`: precio y límites. Los valores son de ejemplo y se cambian
  con un `UPDATE` (NULL = ilimitado, 0 = no incluido).
- `suscripcion`: una por negocio. **Los negocios existentes quedan activos,
  sin vencimiento y exentos de límites** (`exento_limites = 1`) hasta que
  activen un plan pago. Nadie pierde acceso al deployar.
- Tablas nuevas: `pago_suscripcion`, `webhook_evento`, `uso_ia`, `auditoria`.

La migración 0001 solo crea tablas que no existan: en producción no cambia nada.

## 2. Mail

Hace falta un proveedor transaccional para los mails de verificación y de
recuperación de clave (Brevo, Resend, Amazon SES, o el SMTP del hosting):

```
MAILER_DSN=smtp://usuario:clave@smtp-relay.brevo.com:587
MAIL_FROM="Kontia <no-responder@ventas-xpress.com.ar>"
```

Configurar SPF y DKIM del dominio para que no lleguen a spam.

## 3. Ingreso con Google

1. En Google Cloud Console > APIs y servicios > Credenciales, crear un
   "ID de cliente de OAuth" tipo **Aplicación web**.
2. En "Orígenes de JavaScript autorizados" agregar la URL del front web
   (por ejemplo `https://app.ventas-xpress.com.ar` y `http://localhost:8100`).
3. Poner el Client ID en `GOOGLE_CLIENT_IDS` del back y en
   `googleClientId` del `environment` del front. Si más adelante se suma
   Android nativo, se agrega su Client ID separado por coma.

## 4. Captcha del registro (opcional)

Crear un widget en Cloudflare Turnstile y completar `TURNSTILE_SITE_KEY` y
`TURNSTILE_SECRET`. Vacío = registro sin captcha (igual tiene límite por IP).

## 5. Mercado Pago

### 5.1 Credenciales

1. Entrar a https://www.mercadopago.com.ar/developers/panel con la cuenta que
   va a **cobrar** y crear una aplicación ("Pagos online", producto
   **Suscripciones**).
2. En la aplicación > **Credenciales de producción**: completar los datos del
   negocio (rubro, sitio web `https://kontia-app.vercel.app`) para activarlas y
   copiar el **Access Token** (`APP_USR-...`) a `MP_ACCESS_TOKEN`.
   Las credenciales de prueba (`TEST-...`) solo sirven con usuarios de prueba.

### 5.2 Webhook

1. En la aplicación > **Webhooks** > Configurar notificaciones > **Modo productivo**:
   - URL: `https://www.ventas-xpress.com.ar/api/webhooks/mercadopago`
   - Eventos: **Planes y suscripciones** (`subscription_preapproval` y
     `subscription_authorized_payment`).
2. Guardar y copiar la **clave secreta** que muestra a `MP_WEBHOOK_SECRET`.
3. Botón "Simular notificación": como el id es de mentira, el back responde
   **500** (firma bien, pero Mercado Pago no conoce ese id). Un **401** quiere
   decir que la clave secreta no coincide.

### 5.3 Variables en el VPS

```
MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=...
MP_BACK_URL=https://kontia-app.vercel.app/mi-plan
```

Después de editar el `.env`:

```
APP_ENV=prod php composer.phar dump-env prod
APP_ENV=prod php bin/console cache:clear
chown -R www-data:www-data var
APP_ENV=prod php bin/console app:mp:verificar
```

`app:mp:verificar` muestra qué variables faltan y con qué cuenta de Mercado
Pago quedó conectado el Access Token.

### 5.4 Prueba real

- No se puede pagar con la misma cuenta que cobra: usar otra cuenta de Mercado
  Pago (un familiar, otro email).
- Registrar un comercio de prueba en la web, ir a **Mi plan**, elegir Starter.
  Si la cuenta de Mercado Pago tiene otro email que el de Kontia, escribirlo en
  el cuadro que aparece (Mercado Pago solo deja pagar con ese email).
- Al volver, Mi plan dice "esperando la confirmación"; en uno o dos minutos el
  webhook deja el plan **activo** y llega el mail a `ADMIN_EMAILS`.
- Después cancelar desde Mi plan y devolver el cobro desde la actividad de
  Mercado Pago.
- Si algo falla, mirar `var/log` / el log de errores de Apache (`Webhook MP` y
  `Checkout MP`) y la tabla `webhook_evento`.

El cobro solo se ofrece en la web. La app Android muestra el estado del plan
pero no tiene botón de pago (Google Play exige su propio sistema de cobro para
suscripciones dentro de la app).

## 6. Cron

Copiar `scripts/crontab.ejemplo` con `crontab -e`, ajustando la ruta. Incluye:

- backup diario de la base (`scripts/backup-db.sh`),
- conciliación con Mercado Pago y avisos de fin de prueba
  (`php bin/console app:suscripciones:conciliar`),
- prueba de restore mensual (`scripts/probar-restore.sh`).

Para los backups, crear `scripts/backup.conf` (no se sube al repo) con
`BD_NOMBRE`, `MYSQL_CNF` (archivo `.my.cnf` con permisos 600),
`BACKUP_DIR`, `BACKUP_GPG_PASS` y `RCLONE_DESTINO` para copiar fuera del VPS
(Cloudflare R2, Backblaze B2 o S3).

## 7. Endpoints nuevos (para el front)

| Método y ruta | Quién | Qué hace |
| --- | --- | --- |
| GET `/api/registro/config` | público | Client ID de Google, site key del captcha, días de prueba |
| POST `/api/registro` | público | Alta de comercio + titular + prueba |
| POST `/api/registro/verificar` | público | Confirma el email con el token del mail |
| POST `/api/registro/reenviar` | público | Reenvía el mail de verificación |
| POST `/api/usuario/olvide-clave` | público | Manda el mail para cambiar la clave |
| POST `/api/usuario/restablecer-clave` | público | Cambia la clave con el token del mail |
| POST `/api/usuario/google` | público | Entra o crea la cuenta con Google |
| GET `/api/planes` | público | Planes a la venta |
| GET `/api/suscripcion` | logueado | Mi plan: estado, uso, planes y pagos |
| POST `/api/suscripcion/checkout` | titular | Link al checkout de Mercado Pago (`plan`, `email_pago` opcional) |
| POST `/api/suscripcion/cancelar` | titular | Cancela el débito automático |
| POST `/api/webhooks/mercadopago` | Mercado Pago | Avisos de suscripción y cobros |

Además, todas las rutas que recibían el id del negocio en la URL tienen una
versión sin ese parámetro (el negocio sale del token). Las viejas siguen
funcionando para las apps Android sin actualizar.

Respuestas nuevas a manejar en el front:

- **402** con `datos.codigo = "limite_plan"` y `datos.recurso`: se alcanzó un límite del plan.
- **402** con `datos.codigo = "solo_lectura"`: prueba vencida, mora o cancelada.
- **401** en el login con el mensaje "Confirmá tu email...": cuenta sin verificar.

## 8. Tests

```
composer install
php bin/console app:migraciones   # sobre la base de test
php bin/phpunit
```

Los tests usan MySQL (`kontia_test`, ver `.env.test`) y claves JWT de prueba en
`config/jwt/` (el CI las genera solo).
