-- Conteo de stock desde el celular (pedido de Guido, 2026-11-26).
-- Alguien recorre el local escaneando y la app manda lo contado. El conteo queda
-- "pendiente" hasta que el administrador lo aplica (o lo descarta). Si lo manda el
-- administrador, puede aplicarlo en el momento.

CREATE TABLE IF NOT EXISTS conteo_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    -- pendiente | aplicado | descartado
    estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    -- Qué se contó, en palabras: "Todo el local", "Proveedor X", "remera"...
    alcance VARCHAR(120) NULL,
    observacion VARCHAR(300) NULL,
    creado_por INT NULL,
    creado DATETIME NOT NULL,
    resuelto_por INT NULL,
    resuelto DATETIME NULL,
    KEY idx_conteo_negocio_estado (id_negocio, estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un renglón por producto contado. "sistema" es el stock que tenía el producto al
-- mandar el conteo; al aplicar se suma la diferencia (contado - sistema) al stock
-- actual, así no se pisan las ventas hechas entre el conteo y la aprobación.
CREATE TABLE IF NOT EXISTS conteo_stock_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_conteo INT NOT NULL,
    id_producto INT NOT NULL,
    descripcion VARCHAR(255) NULL,
    codigo VARCHAR(100) NULL,
    sistema INT NOT NULL,
    contado INT NOT NULL,
    costo DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    KEY idx_conteo_item_conteo (id_conteo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
