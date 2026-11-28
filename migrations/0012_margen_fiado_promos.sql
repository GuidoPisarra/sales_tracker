-- Reportes de margen, recordatorio de fiado por WhatsApp y promos (pedido de Guido, 2026-11-28).

-- 1. Margen: el costo del producto en el momento de la venta. Las ventas viejas quedan
--    en NULL y el reporte usa el costo actual del producto.
ALTER TABLE sales_product ADD COLUMN cost DECIMAL(12,2) NULL;

-- 2. Fiado: texto del recordatorio y cuándo se le recordó por última vez a cada cliente.
ALTER TABLE negocio_config ADD COLUMN fiado_mensaje VARCHAR(500) NULL;
ALTER TABLE clientes ADD COLUMN recordado_en DATETIME NULL;

-- 3. Promos. tipo:
--    'nxm'        lleva N, paga M (2x1, 3x2...) del mismo producto.
--    'porcentaje' X% de descuento.
--    'cantidad'   llevando N o más unidades del mismo producto, X% de descuento en todas.
--    Aplica a todos los productos o a los de promo_producto. dias = días de la semana
--    (1 lunes ... 7 domingo, separados por coma); NULL = todos.
CREATE TABLE IF NOT EXISTS promo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    tipo VARCHAR(12) NOT NULL,
    lleva INT NULL,
    paga INT NULL,
    porcentaje DECIMAL(5,2) NULL,
    todos_productos TINYINT(1) NOT NULL DEFAULT 0,
    desde DATE NULL,
    hasta DATE NULL,
    dias VARCHAR(20) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_promo_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promo_producto (
    id_promo INT NOT NULL,
    id_product INT NOT NULL,
    PRIMARY KEY (id_promo, id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
