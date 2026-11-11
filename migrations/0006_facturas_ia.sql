-- Cargar facturas de proveedores con IA: se saca una foto (o se sube el PDF),
-- la IA lee los renglones y Kontia sugiere a qué producto va cada uno.

-- Lo que se aprendió al confirmar: este texto de la factura de este proveedor es
-- este producto (por su código). La próxima factura igual se empareja sola.
CREATE TABLE IF NOT EXISTS factura_alias (
    id_negocio INT NOT NULL,
    proveedor VARCHAR(100) NOT NULL,
    texto_hash CHAR(64) NOT NULL,
    codigo_producto VARCHAR(100) NOT NULL,
    actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_negocio, proveedor, texto_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- La sesión de "Escanear con el celular" también sirve para sacar la foto de la
-- factura con el celular: modo 'factura'.
ALTER TABLE escaneo_sesion ADD COLUMN modo VARCHAR(20) NOT NULL DEFAULT 'productos';

-- Fotos que manda el celular (se borran con la sesión).
CREATE TABLE IF NOT EXISTS escaneo_foto (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_sesion INT NOT NULL,
    mime VARCHAR(40) NOT NULL,
    datos LONGBLOB NOT NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_escaneo_foto_sesion (id_sesion, id),
    CONSTRAINT fk_escaneo_foto_sesion FOREIGN KEY (id_sesion) REFERENCES escaneo_sesion (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
