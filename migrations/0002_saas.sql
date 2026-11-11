-- Kontia como SaaS: registro self-service, ingreso con Google, planes con
-- límites, suscripciones con Mercado Pago y auditoría.
-- Las columnas e índices que ya existan se saltean (ver app:migraciones).

-- Usuarios: verificación de email, recuperación de clave y Google.
ALTER TABLE user ADD COLUMN email_verificado TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE user ADD COLUMN token_verificacion VARCHAR(64) NULL;
ALTER TABLE user ADD COLUMN token_reset VARCHAR(64) NULL;
ALTER TABLE user ADD COLUMN token_reset_expira DATETIME NULL;
ALTER TABLE user ADD COLUMN google_id VARCHAR(64) NULL;
ALTER TABLE user ADD COLUMN acepto_terminos_en DATETIME NULL;
ALTER TABLE user ADD COLUMN creado_en DATETIME NULL;
CREATE UNIQUE INDEX uniq_user_google_id ON user (google_id);
CREATE INDEX idx_user_token_verificacion ON user (token_verificacion);
CREATE INDEX idx_user_token_reset ON user (token_reset);

-- Planes: precio y límites. NULL en un límite = ilimitado.
ALTER TABLE plan_suscripcion ADD COLUMN codigo VARCHAR(30) NULL;
ALTER TABLE plan_suscripcion ADD COLUMN precio_mensual DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE plan_suscripcion ADD COLUMN max_sucursales INT NULL;
ALTER TABLE plan_suscripcion ADD COLUMN max_usuarios INT NULL;
ALTER TABLE plan_suscripcion ADD COLUMN max_productos INT NULL;
ALTER TABLE plan_suscripcion ADD COLUMN max_mensajes_ia INT NULL;
ALTER TABLE plan_suscripcion ADD COLUMN cuentas_corrientes TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE plan_suscripcion ADD COLUMN publico TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE plan_suscripcion ADD COLUMN orden INT NOT NULL DEFAULT 0;

INSERT INTO plan_suscripcion (nombre) SELECT 'Starter' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plan_suscripcion WHERE LOWER(nombre) LIKE '%starter%');
INSERT INTO plan_suscripcion (nombre) SELECT 'Pro' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plan_suscripcion WHERE LOWER(nombre) = 'pro');
INSERT INTO plan_suscripcion (nombre) SELECT 'Premium' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plan_suscripcion WHERE LOWER(nombre) LIKE '%premium%');
INSERT INTO plan_suscripcion (nombre) SELECT 'Fundador' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plan_suscripcion WHERE LOWER(nombre) LIKE '%fundador%');

-- Valores de ejemplo del plan: se cambian desde la base sin tocar código.
UPDATE plan_suscripcion SET codigo = 'starter', precio_mensual = 15000, max_sucursales = 1, max_usuarios = 2, max_productos = 500, max_mensajes_ia = 0, cuentas_corrientes = 0, publico = 1, orden = 1 WHERE LOWER(nombre) LIKE '%starter%' AND codigo IS NULL;
UPDATE plan_suscripcion SET codigo = 'pro', precio_mensual = 30000, max_sucursales = 3, max_usuarios = 5, max_productos = 5000, max_mensajes_ia = 100, cuentas_corrientes = 1, publico = 1, orden = 2 WHERE LOWER(nombre) = 'pro' AND codigo IS NULL;
UPDATE plan_suscripcion SET codigo = 'premium', precio_mensual = 55000, max_sucursales = NULL, max_usuarios = NULL, max_productos = NULL, max_mensajes_ia = 1000, cuentas_corrientes = 1, publico = 1, orden = 3 WHERE LOWER(nombre) LIKE '%premium%' AND codigo IS NULL;
UPDATE plan_suscripcion SET codigo = 'fundador', precio_mensual = 0, max_sucursales = NULL, max_usuarios = NULL, max_productos = NULL, max_mensajes_ia = 1000, cuentas_corrientes = 1, publico = 0, orden = 4 WHERE LOWER(nombre) LIKE '%fundador%' AND codigo IS NULL;
CREATE UNIQUE INDEX uniq_plan_codigo ON plan_suscripcion (codigo);

-- Una suscripción por negocio (id_negocio agrupa todas sus sucursales).
CREATE TABLE IF NOT EXISTS suscripcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    id_plan INT NOT NULL,
    estado VARCHAR(20) NOT NULL,
    prueba_hasta DATETIME NULL,
    periodo_hasta DATETIME NULL,
    en_mora_desde DATETIME NULL,
    cancelada_en DATETIME NULL,
    mp_preapproval_id VARCHAR(64) NULL,
    id_plan_pendiente INT NULL,
    aviso_prueba_enviado TINYINT(1) NOT NULL DEFAULT 0,
    -- 1 = cliente anterior al SaaS: no se le aplican límites ni bloqueos
    -- hasta que pase a un plan pago (se pone en 0 al activar el débito).
    exento_limites TINYINT(1) NOT NULL DEFAULT 0,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_suscripcion_negocio (id_negocio),
    KEY idx_suscripcion_preapproval (mp_preapproval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Los negocios que ya existen quedan activos, sin vencimiento y sin límites,
-- con el plan que tenían (o Fundador si no tenían ninguno). Nadie pierde acceso.
INSERT INTO suscripcion (id_negocio, id_plan, estado, exento_limites)
SELECT n.id_negocio,
       COALESCE(MAX(n.id_plan), (SELECT id FROM plan_suscripcion WHERE codigo = 'fundador' LIMIT 1)),
       'activa', 1
FROM negocio n
WHERE NOT EXISTS (SELECT 1 FROM suscripcion s WHERE s.id_negocio = n.id_negocio)
GROUP BY n.id_negocio;

CREATE TABLE IF NOT EXISTS pago_suscripcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    mp_id VARCHAR(64) NOT NULL,
    monto DECIMAL(12,2) NOT NULL DEFAULT 0,
    estado VARCHAR(30) NOT NULL,
    fecha DATETIME NOT NULL,
    detalle TEXT NULL,
    UNIQUE KEY uniq_pago_mp_id (mp_id),
    KEY idx_pago_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Para no procesar dos veces el mismo aviso de Mercado Pago.
CREATE TABLE IF NOT EXISTS webhook_evento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proveedor VARCHAR(30) NOT NULL,
    evento_id VARCHAR(128) NOT NULL,
    recibido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webhook (proveedor, evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uso_ia (
    id_negocio INT NOT NULL,
    periodo CHAR(7) NOT NULL,
    mensajes INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id_negocio, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NULL,
    usuario_id INT NULL,
    accion VARCHAR(60) NOT NULL,
    detalle TEXT NULL,
    ip VARCHAR(45) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auditoria_negocio_fecha (id_negocio, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Índices por negocio en tablas viejas que quizá no los tengan.
CREATE INDEX idx_product_negocio_sucursal ON product (id_negocio, sucursal);
CREATE INDEX idx_sales_product_negocio_fecha ON sales_product (id_negocio, sale_product_date);
CREATE INDEX idx_clientes_id_negocio ON clientes (id_negocio);
CREATE INDEX idx_ctacte_id_negocio ON ctacte (id_negocio);
CREATE INDEX idx_negocio_id_negocio ON negocio (id_negocio);
CREATE INDEX idx_user_id_negocio ON user (id_negocio);
