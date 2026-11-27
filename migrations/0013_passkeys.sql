-- Ingreso con Face ID / huella desde el navegador (llaves de acceso, WebAuthn).
-- Es la forma de tener "huella" en iPhone (PWA); la app de Android usa la del sistema.
CREATE TABLE IF NOT EXISTS usuario_passkey (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    rp_id VARCHAR(255) NOT NULL,
    credencial VARCHAR(255) NOT NULL,
    clave_publica TEXT NOT NULL,
    contador INT UNSIGNED NOT NULL DEFAULT 0,
    nombre VARCHAR(100) NULL,
    creada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usada DATETIME NULL,
    UNIQUE KEY uniq_passkey_credencial (credencial),
    KEY idx_passkey_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Desafíos de un solo uso (5 minutos) para registrar o ingresar.
CREATE TABLE IF NOT EXISTS passkey_desafio (
    id CHAR(32) PRIMARY KEY,
    desafio VARBINARY(64) NOT NULL,
    id_usuario INT NULL,
    rp_id VARCHAR(255) NOT NULL,
    expira DATETIME NOT NULL,
    KEY idx_passkey_desafio_expira (expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
