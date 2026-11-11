-- Ventas sin conexión y ventas confirmadas "sin stock" (pedido de Guido, 2026-11-11).
-- El producto está en el mostrador aunque el sistema diga que no queda: esas ventas
-- no se rechazan. El stock queda en 0 (nunca negativo) y se anota acá cuánto faltó,
-- para que el administrador lo revise y corrija el stock.
CREATE TABLE IF NOT EXISTS stock_faltante (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    id_product INT NOT NULL,
    id_sale INT NOT NULL,
    cantidad_vendida INT NOT NULL,
    stock_antes INT NOT NULL,
    faltante INT NOT NULL,
    sin_conexion TINYINT(1) NOT NULL DEFAULT 0,
    usuario INT NULL,
    fecha_venta DATETIME NOT NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revisado TINYINT(1) NOT NULL DEFAULT 0,
    revisado_en DATETIME NULL,
    KEY idx_stock_faltante_negocio (id_negocio, revisado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
