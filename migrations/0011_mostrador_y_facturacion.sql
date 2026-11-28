-- Mejoras de mostrador y factura electrónica ARCA (pedido de Guido, 2026-11-28):
-- stock mínimo por producto, pago mixto, ticket de venta y facturación electrónica.

-- 1. Stock mínimo: cuando el stock llega a este número, el producto pasa a "para reponer".
--    NULL = se usa el mínimo general del negocio (negocio_config.stock_minimo_general).
ALTER TABLE product ADD COLUMN stock_minimo INT NULL;
ALTER TABLE negocio_config ADD COLUMN stock_minimo_general INT NOT NULL DEFAULT 3;

-- 2. Pago mixto: una venta pagada con más de un medio (por ejemplo, parte en efectivo
--    y parte con tarjeta). Sus líneas en sales_product quedan con type_payment = 'mixto'
--    y el detalle de cuánto se pagó con cada medio va acá.
CREATE TABLE IF NOT EXISTS venta_pago (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    id_sale INT NOT NULL,
    forma VARCHAR(30) NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    KEY idx_venta_pago_venta (id_negocio, id_sale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Ticket de venta: datos que se imprimen arriba y abajo del ticket.
ALTER TABLE negocio_config ADD COLUMN ticket_titulo VARCHAR(120) NULL;
ALTER TABLE negocio_config ADD COLUMN ticket_direccion VARCHAR(200) NULL;
ALTER TABLE negocio_config ADD COLUMN ticket_telefono VARCHAR(40) NULL;
ALTER TABLE negocio_config ADD COLUMN ticket_pie VARCHAR(300) NULL;
-- Ancho del papel de la impresora térmica en milímetros (58 u 80).
ALTER TABLE negocio_config ADD COLUMN ticket_ancho SMALLINT NOT NULL DEFAULT 58;

-- 4. Factura electrónica ARCA (web service WSFEv1).
-- Datos fiscales y certificado de cada negocio. La clave privada se guarda cifrada.
CREATE TABLE IF NOT EXISTS negocio_fiscal (
    id_negocio INT NOT NULL PRIMARY KEY,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    -- 'homologacion' (pruebas de ARCA) o 'produccion'.
    entorno VARCHAR(12) NOT NULL DEFAULT 'homologacion',
    cuit VARCHAR(11) NULL,
    razon_social VARCHAR(150) NULL,
    -- 'monotributo', 'responsable_inscripto' o 'exento'.
    condicion_iva VARCHAR(30) NULL,
    domicilio VARCHAR(200) NULL,
    ingresos_brutos VARCHAR(40) NULL,
    inicio_actividades DATE NULL,
    punto_venta INT NULL,
    -- Alícuota de IVA de los productos (solo responsable inscripto): 21, 10.5, 27, 5, 2.5 o 0.
    alicuota_iva DECIMAL(4,1) NOT NULL DEFAULT 21.0,
    -- 'manual' (botón Facturar en cada venta) o 'automatico' (todas las ventas).
    modo VARCHAR(12) NOT NULL DEFAULT 'manual',
    clave_privada TEXT NULL,
    pedido_certificado TEXT NULL,
    certificado TEXT NULL,
    certificado_vence DATETIME NULL,
    modificado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ticket de acceso de ARCA (WSAA): dura 12 horas y no conviene pedir uno por factura.
CREATE TABLE IF NOT EXISTS arca_token (
    id_negocio INT NOT NULL,
    entorno VARCHAR(12) NOT NULL,
    servicio VARCHAR(20) NOT NULL,
    token TEXT NOT NULL,
    sign TEXT NOT NULL,
    vence DATETIME NOT NULL,
    PRIMARY KEY (id_negocio, entorno, servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comprobantes electrónicos. Se crean "pendiente" y pasan a "emitida" con el CAE.
-- Si ARCA no responde quedan pendientes y se reintentan solos (app:facturas:reintentar).
CREATE TABLE IF NOT EXISTS factura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    id_sale INT NOT NULL,
    entorno VARCHAR(12) NOT NULL,
    -- Código ARCA: 1 Factura A, 6 Factura B, 11 Factura C, 3/8/13 notas de crédito A/B/C.
    tipo_cbte SMALLINT NOT NULL,
    punto_venta INT NOT NULL,
    numero INT NULL,
    fecha DATE NOT NULL,
    -- Receptor: doc_tipo 80 = CUIT, 96 = DNI, 99 = consumidor final sin identificar.
    doc_tipo SMALLINT NOT NULL DEFAULT 99,
    doc_nro VARCHAR(11) NOT NULL DEFAULT '0',
    receptor_nombre VARCHAR(150) NULL,
    receptor_condicion_iva SMALLINT NOT NULL DEFAULT 5,
    importe_total DECIMAL(12,2) NOT NULL,
    importe_neto DECIMAL(12,2) NOT NULL,
    importe_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
    alicuota_iva DECIMAL(4,1) NULL,
    -- Nota de crédito: factura que anula.
    id_factura_asociada INT NULL,
    estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    cae VARCHAR(20) NULL,
    cae_vence DATE NULL,
    error VARCHAR(500) NULL,
    intentos INT NOT NULL DEFAULT 0,
    creado DATETIME NOT NULL,
    emitida DATETIME NULL,
    UNIQUE KEY uq_factura_numero (id_negocio, entorno, punto_venta, tipo_cbte, numero),
    KEY idx_factura_venta (id_negocio, id_sale),
    KEY idx_factura_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Qué planes incluyen factura electrónica (Starter no; Pro, Premium y Fundador sí).
ALTER TABLE plan_suscripcion ADD COLUMN facturacion TINYINT(1) NOT NULL DEFAULT 1;
UPDATE plan_suscripcion SET facturacion = 0 WHERE codigo = 'starter';
