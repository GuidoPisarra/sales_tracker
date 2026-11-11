-- Apertura y cierre de caja (opcional) y catálogo online compartible (pedido de Guido, 2026-11-11).

-- Configuración por negocio de funciones opcionales.
CREATE TABLE IF NOT EXISTS negocio_config (
    id_negocio INT NOT NULL PRIMARY KEY,
    -- Apertura y cierre de caja: apagado hasta que el administrador lo activa.
    caja_activa TINYINT(1) NOT NULL DEFAULT 0,
    -- Catálogo online: link público /c/<slug> con los productos y pedido por WhatsApp.
    catalogo_activo TINYINT(1) NOT NULL DEFAULT 0,
    catalogo_slug VARCHAR(40) NULL,
    catalogo_titulo VARCHAR(120) NULL,
    catalogo_mensaje VARCHAR(300) NULL,
    catalogo_whatsapp VARCHAR(30) NULL,
    catalogo_sucursal INT NOT NULL DEFAULT 1,
    catalogo_mostrar_stock TINYINT(1) NOT NULL DEFAULT 0,
    catalogo_sin_stock TINYINT(1) NOT NULL DEFAULT 0,
    modificado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_negocio_config_slug (catalogo_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un turno de caja por sucursal: se abre con un monto inicial y se cierra con lo contado.
-- "abierta" vale 1 mientras está abierto y NULL al cerrarlo: el índice único impide
-- que haya dos turnos abiertos a la vez en la misma sucursal.
CREATE TABLE IF NOT EXISTS caja_turno (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    abierta TINYINT(1) NULL DEFAULT 1,
    abierta_por INT NULL,
    apertura DATETIME NOT NULL,
    monto_inicial DECIMAL(12,2) NOT NULL DEFAULT 0,
    observacion_apertura VARCHAR(300) NULL,
    cerrada_por INT NULL,
    cierre DATETIME NULL,
    esperado_efectivo DECIMAL(12,2) NULL,
    contado_efectivo DECIMAL(12,2) NULL,
    diferencia DECIMAL(12,2) NULL,
    observacion_cierre VARCHAR(300) NULL,
    UNIQUE KEY uq_caja_turno_abierta (id_negocio, sucursal, abierta),
    KEY idx_caja_turno_negocio_apertura (id_negocio, apertura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Plata que entra o sale de la caja sin ser una venta (cambio, retiro para el banco, pago a un proveedor...).
CREATE TABLE IF NOT EXISTS caja_movimiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_turno INT NOT NULL,
    id_negocio INT NOT NULL,
    tipo VARCHAR(10) NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    motivo VARCHAR(200) NULL,
    usuario INT NULL,
    fecha DATETIME NOT NULL,
    KEY idx_caja_movimiento_turno (id_turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
