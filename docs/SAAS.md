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
     `subscription_authorized_payment`) y **Pagos** (`payment`, para el pago
     mes a mes).
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

### 5.5 Dos formas de pagar

En "Elegir plan" el titular elige:

- **Débito automático** (preapproval): Mercado Pago cobra solo cada mes. El email
  del pagador tiene que ser el de su cuenta de Mercado Pago.
- **Pago mes a mes** (Checkout Pro, migración 0010): un pago único por mes, con
  dinero en cuenta, tarjeta, etc. Cada pago aprobado suma un mes desde el
  vencimiento (o desde el fin de la prueba si todavía la tiene), así quien paga
  antes no pierde días. 3 días antes y el día que vence le llega un mail; vencido,
  tiene `DIAS_GRACIA_MORA` días y después queda en solo lectura. Si tiene el
  débito activo, primero tiene que cancelarlo.

Los pagos mes a mes llegan por el evento **Pagos** del webhook; la conciliación
diaria además trae los pagos de los últimos 3 días (sirve en local, sin túnel:
`php bin/console app:suscripciones:conciliar`). Igual, al volver de Mercado Pago a
Mi plan el front llama a `/api/suscripcion/sincronizar` y el plan se actualiza solo. Una devolución no descuenta el
mes: si se devuelve un pago, ajustar la fecha desde el panel de administración.

El cobro solo se ofrece en la web. La app Android muestra el estado del plan
pero no tiene botón de pago (Google Play exige su propio sistema de cobro para
suscripciones dentro de la app).

## 6. Cron

Copiar `scripts/crontab.ejemplo` con `crontab -e`, ajustando la ruta. Incluye:

- backup diario de la base (`scripts/backup-db.sh`),
- conciliación con Mercado Pago, avisos de fin de prueba y de vencimiento del pago mes a mes,
  cada hora (además, Mi plan sincroniza su negocio al volver de Mercado Pago)
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
| POST `/api/suscripcion/checkout` | titular | Link al checkout de Mercado Pago (`plan`, `modo` = `debito` o `mensual`, `email_pago` opcional) |
| POST `/api/suscripcion/sincronizar` | logueado | Trae ya de Mercado Pago el estado de su negocio (`payment_id` opcional); lo llama Mi plan al volver de pagar |
| POST `/api/suscripcion/cancelar` | titular | Cancela el débito automático |
| POST `/api/webhooks/mercadopago` | Mercado Pago | Avisos de suscripción y cobros |

Además, todas las rutas que recibían el id del negocio en la URL tienen una
versión sin ese parámetro (el negocio sale del token). Las viejas siguen
funcionando para las apps Android sin actualizar.

Respuestas nuevas a manejar en el front:

- **402** con `datos.codigo = "limite_plan"` y `datos.recurso`: se alcanzó un límite del plan.
- **402** con `datos.codigo = "solo_lectura"`: prueba vencida, mora o cancelada.
- **401** en el login con el mensaje "Confirmá tu email...": cuenta sin verificar.

## 7 bis. Lo nuevo de la rama `saas`

Variables nuevas en el `.env` (ver `.env.dist`):

- `LEGAL_TITULAR`, `LEGAL_CUIT`, `LEGAL_PROVINCIA`: datos que muestran Términos y Privacidad.
- `SOPORTE_EMAIL`, `SOPORTE_WHATSAPP`: botón de Ayuda de la app y pie de los mails.
- `AVISAR_ERRORES`: 1 manda un mail a `ADMIN_EMAILS` cuando la API da un error 500 (uno por hora por error).
- `ADMIN_EMAILS` ahora también define quién ve el **panel de administración** (`/admin` en el front).
- `DIAS_PRUEBA` por defecto pasa a 7.
- `CORS_ALLOW_ORIGIN` tiene que incluir la app y la web de Vercel (ver el ejemplo).

| Método y ruta | Quién | Qué hace |
| --- | --- | --- |
| GET `/api/publico/info` | público | Titular, CUIT, provincia, soporte y días de prueba |
| POST `/api/publico/solicitud` | público | Botón de arrepentimiento / baja: registra el pedido, avisa a `ADMIN_EMAILS` y devuelve el número de trámite |
| GET `/api/onboarding` | logueado | Primeros pasos: productos, primera venta, empleados, catálogo |
| GET `/api/admin/resumen` | admin Kontia | Métricas: negocios, pruebas, pagando, ingreso mensual, altas, conversión, bajas |
| GET `/api/admin/negocios?q=&estado=` | admin Kontia | Todos los negocios con plan, estado, titular y último pago |
| GET `/api/admin/negocios/{id}` | admin Kontia | Detalle con pagos y actividad |
| POST `/api/admin/negocios/{id}/prueba` | admin Kontia | Suma días de prueba |
| POST `/api/admin/negocios/{id}/plan` | admin Kontia | Cambia el plan a mano (`sin_limites` = de por vida) |
| POST `/api/admin/negocios/{id}/cancelar` | admin Kontia | Cancela el débito en Mercado Pago y la suscripción |
| GET `/api/admin/solicitudes` | admin Kontia | Pedidos de baja y arrepentimiento |

## 7 ter. Fotos de productos (catálogo online)

Cada producto puede tener fotos que se ven en el catálogo online. El servidor
las convierte a WebP (1200 px + miniatura cuadrada de 400 px), borra los datos
EXIF (ubicación del celular) y guarda solo el nombre del archivo en la base.

Fotos por producto según el plan (`plan_suscripcion.max_fotos_producto`, migración
0009): Starter 1, Pro 3, Premium 5, Fundador 5. Los negocios sin límites (plan de
por vida) usan `FOTOS_MAX_SIN_LIMITES` (5). Si un negocio baja de plan no se borra
nada: el catálogo muestra solo las primeras.

Puesta en marcha en el VPS:

1. Confirmar GD con WebP: `php -r "print_r(gd_info());"` tiene que mostrar
   `[WebP Support] => 1` (si no: `sudo apt install php8.x-gd` y reiniciar Apache).
2. Carpeta: `sudo mkdir -p /var/www/fotos-kontia && sudo chown www-data:www-data /var/www/fotos-kontia`.
3. Apache: pegar `scripts/apache-fotos.conf` en el VirtualHost y recargar.
4. PHP: `upload_max_filesize = 10M` y `post_max_size = 12M` en el php.ini de Apache.
5. `.env`: `FOTOS_ALMACEN`, `FOTOS_DIR`, `FOTOS_URL_BASE` (ver `.env.dist`), y
   `composer dump-env prod` si se usa `.env.local.php`.
6. Cron: `app:fotos:limpiar` (ver `scripts/crontab.ejemplo`).
7. Backup: sumar `/var/www/fotos-kontia` a lo que se copia con rclone.

Pasar a Cloudflare R2 más adelante: crear el bucket con dominio público,
`rclone sync /var/www/fotos-kontia r2:kontia-fotos`, cargar `R2_*`,
`FOTOS_ALMACEN=r2` y `FOTOS_URL_BASE=https://<dominio del bucket>`, limpiar
caché y repetir el `rclone sync`. La base no cambia.

Baja definitiva de un negocio: `php bin/console app:fotos:limpiar --negocio=ID`.

| Método y ruta | Quién | Qué hace |
| --- | --- | --- |
| GET `/api/productos/{id}/fotos` | logueado | Fotos del producto, máximo del plan y nombre del plan |
| POST `/api/productos/{id}/fotos` | logueado | Sube una foto (multipart, campo `foto`, hasta 10 MB). 402 `limite_plan` / `fotos` si llegó al máximo |
| PUT `/api/productos/{id}/fotos/orden` | logueado | `{ids: [...]}`; la primera queda de portada |
| DELETE `/api/productos/{id}/fotos/{idFoto}` | logueado | Borra una foto |

El catálogo público (`/api/publico/catalogo/{slug}`) devuelve en cada producto
`fotos: [{url, mini, ancho, alto}]`, y `/api/catalogo/config` suma
`productos_sin_foto`. El panel de administración muestra `fotos_mb` por negocio.

## 8. Tests

```
composer install
php bin/console app:migraciones   # sobre la base de test
php bin/phpunit
```

Los tests usan MySQL (`kontia_test`, ver `.env.test`) y claves JWT de prueba en
`config/jwt/` (el CI las genera solo).

## Factura electrónica (ARCA)

Desde la migración 0014. Viene en Pro, Premium y Fundador (`plan_suscripcion.facturacion`).
Se habla con ARCA por SOAP "a mano" (HttpClient), así que **no hace falta ext-soap**; sí `openssl`.

Qué hace el administrador del negocio en Kontia (pantalla **Facturación electrónica**):

1. Carga CUIT, razón social, condición frente al IVA, punto de venta y domicilio.
2. Toca **Generar pedido de certificado** y copia el texto (CSR).
3. En ARCA con clave fiscal: *Administración de certificados digitales* → alias `kontia` → sube el pedido y descarga el `.crt`.
   Para pruebas (homologación) es el servicio *WSASS - Autogestión certificados homologación*.
4. En ARCA: *Administrador de relaciones de clave fiscal* → nueva relación → servicio *Facturación electrónica* (wsfe) → representante: el certificado `kontia`.
5. En ARCA: *Administración de puntos de venta y domicilios* → punto de venta nuevo del tipo **Factura electrónica - Monotributo / RECE - Web services**.
6. Pega el certificado en Kontia, toca **Probar conexión** y **Activar**.

Cambiar entre pruebas y producción descarta el certificado (son distintos).

En el VPS:

- `ARCA_CLAVE` en el `.env` (o deja la de `APP_SECRET`). No cambiarla después.
- Cron `app:facturas:reintentar` cada 10 minutos (ver `scripts/crontab.ejemplo`): reintenta las facturas que quedaron pendientes porque ARCA no respondió.

Reglas: monotributo y exento emiten Factura C; responsable inscripto emite B a consumidor final y A
a responsables inscriptos o monotributistas (con CUIT). Los precios incluyen IVA, con una sola
alícuota por negocio. Anular una venta facturada emite la nota de crédito. Si ARCA no responde, la
venta se guarda igual y el comprobante queda pendiente; antes de reintentar se consulta si ARCA ya
lo había emitido, para no duplicar.
