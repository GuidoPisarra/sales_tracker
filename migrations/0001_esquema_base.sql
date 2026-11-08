-- Esquema base de Kontia, reconstruido a partir de las consultas del código.
-- En una base existente (producción) no cambia nada: todas las tablas usan
-- CREATE TABLE IF NOT EXISTS. Sirve para instalar desde cero y para los tests.

CREATE TABLE IF NOT EXISTS plan_suscripcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS negocio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    nombre VARCHAR(150) NOT NULL,
    domicilio VARCHAR(200) NULL,
    telefono VARCHAR(50) NULL,
    meses_aviso_precios_desactualizados INT NULL,
    id_plan INT NULL,
    KEY idx_negocio_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NULL,
    email VARCHAR(180) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(255) NULL,
    id_negocio INT NULL,
    sucursal INT NULL,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    asistente_ia TINYINT(1) NOT NULL DEFAULT 0,
    ultimo_ingreso DATETIME NULL,
    UNIQUE KEY uniq_user_email (email),
    KEY idx_user_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(50) NULL,
    calle VARCHAR(150) NULL,
    numero_calle VARCHAR(20) NULL,
    ciudad VARCHAR(100) NULL,
    id_negocio INT NOT NULL,
    KEY idx_proveedores_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    id_proveedor INT NULL,
    code VARCHAR(100) NULL,
    size VARCHAR(50) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    fecha_actualizado DATETIME NULL,
    barcode VARCHAR(100) NULL,
    codigo_interno VARCHAR(100) NULL,
    KEY idx_product_negocio_sucursal (id_negocio, sucursal),
    KEY idx_product_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_product INT NULL,
    id_negocio INT NOT NULL,
    KEY idx_sales_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_product (
    id_sales_product INT AUTO_INCREMENT PRIMARY KEY,
    id_sale INT NOT NULL,
    id_product INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_product_date DATETIME NOT NULL,
    type_payment VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    register TINYINT(1) NOT NULL DEFAULT 0,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL DEFAULT 1,
    id_persona INT NULL,
    usuario INT NULL,
    KEY idx_sales_product_negocio_fecha (id_negocio, sale_product_date),
    KEY idx_sales_product_id_sale (id_sale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    dni VARCHAR(20) NULL,
    apellido VARCHAR(100) NULL,
    nombre VARCHAR(100) NULL,
    telefono VARCHAR(50) NULL,
    limite_credito DECIMAL(12,2) NULL,
    KEY idx_clientes_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctacte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NULL,
    id_sale INT NULL,
    id_sale_product INT NULL,
    id_persona INT NOT NULL,
    precio_original DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio_actual DECIMAL(12,2) NOT NULL DEFAULT 0,
    id_negocio INT NOT NULL,
    sucursal INT NULL,
    fecha_venta DATETIME NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_ctacte_id_negocio (id_negocio),
    KEY idx_ctacte_id_persona (id_persona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_persona INT NOT NULL,
    id_ctacte INT NULL,
    entrega DECIMAL(12,2) NOT NULL DEFAULT 0,
    fecha DATETIME NOT NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_pagos_id_ctacte (id_ctacte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cambios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_producto_cambio INT NOT NULL,
    precio_producto_cambio DECIMAL(12,2) NOT NULL DEFAULT 0,
    id_producto_nuevo INT NOT NULL,
    precio_producto_nuevo DECIMAL(12,2) NOT NULL DEFAULT 0,
    id_negocio INT NOT NULL,
    fecha_cambio DATETIME NOT NULL,
    KEY idx_cambios_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense (
    id INT AUTO_INCREMENT PRIMARY KEY,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    id_sucursal INT NULL,
    date_expense DATE NOT NULL,
    id_negocio INT NOT NULL,
    KEY idx_expense_id_negocio (id_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    usuario_id INT NOT NULL DEFAULT 0,
    titulo VARCHAR(150) NOT NULL,
    mensaje TEXT NULL,
    tipo VARCHAR(50) NULL,
    leida TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notificaciones_negocio_usuario (id_negocio, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
