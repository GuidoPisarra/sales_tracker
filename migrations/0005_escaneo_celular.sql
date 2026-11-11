-- Escanear productos con el celular desde Caja (como WhatsApp Web).
-- La PC abre una sesión y muestra un QR con un token de un solo uso; el
-- celular lo escanea, recibe una clave propia y desde ahí manda cada código
-- de barras leído con la cámara. La PC los va levantando y los suma al carrito.
-- Del token y de la clave se guarda solo el hash (SHA-256).
CREATE TABLE IF NOT EXISTS escaneo_sesion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL,
    id_usuario INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    clave_hash CHAR(64) NULL,
    dispositivo VARCHAR(80) NULL,
    creada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vence_vinculo DATETIME NOT NULL,
    vinculada_en DATETIME NULL,
    vence_en DATETIME NULL,
    cerrada_en DATETIME NULL,
    UNIQUE KEY uq_escaneo_token (token_hash),
    UNIQUE KEY uq_escaneo_clave (clave_hash),
    KEY idx_escaneo_creada (creada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Códigos que manda el celular. La PC pide los que tengan id mayor al último que vio.
CREATE TABLE IF NOT EXISTS escaneo_codigo (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_sesion INT NOT NULL,
    codigo VARCHAR(100) NOT NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_escaneo_codigo_sesion (id_sesion, id),
    CONSTRAINT fk_escaneo_codigo_sesion FOREIGN KEY (id_sesion) REFERENCES escaneo_sesion (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
