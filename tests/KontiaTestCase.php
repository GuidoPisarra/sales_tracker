<?php

namespace App\Tests;

use App\Command\MigracionesCommand;
use App\Repository\BaseRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base para tests funcionales contra MySQL (base kontia_test, ver .env.test).
 * Antes de cada test vacía las tablas y deja la base lista para sembrar datos.
 */
abstract class KontiaTestCase extends WebTestCase
{
    private static bool $migrado = false;

    protected KernelBrowser $client;

    private const TABLAS = [
        'negocio', 'user', 'proveedores', 'product', 'sales', 'sales_product', 'clientes',
        'ctacte', 'pagos', 'cambios', 'expense', 'notificaciones', 'suscripcion',
        'pago_suscripcion', 'webhook_evento', 'uso_ia', 'auditoria', 'venta_clave',
    ];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $pdo = $this->pdo();

        if (!self::$migrado) {
            $pdo->exec('DROP DATABASE IF EXISTS kontia_test');
            $pdo->exec('CREATE DATABASE kontia_test');
            $pdo->exec('USE kontia_test');
            $raiz = dirname(__DIR__) . '/migrations';
            $archivos = glob($raiz . '/*.sql');
            sort($archivos);
            foreach ($archivos as $archivo) {
                foreach (MigracionesCommand::sentencias(file_get_contents($archivo)) as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (\PDOException $e) {
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1050, 1060, 1061], true)) {
                            throw $e;
                        }
                    }
                }
            }
            self::$migrado = true;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLAS as $tabla) {
            $pdo->exec("TRUNCATE TABLE `$tabla`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function pdo(): \PDO
    {
        return (new BaseRepository())->get_bbdd();
    }

    protected function idPlan(string $codigo): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM plan_suscripcion WHERE codigo = :c');
        $stmt->execute([':c' => $codigo]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Crea un negocio con sus sucursales y una suscripción.
     */
    protected function crearNegocio(int $idNegocio, array $sucursales = [1], string $plan = 'premium', string $estado = 'activa', ?string $pruebaHasta = null): void
    {
        $pdo = $this->pdo();
        foreach ($sucursales as $s) {
            $pdo->prepare('INSERT INTO negocio (id_negocio, sucursal, nombre) VALUES (?, ?, ?)')
                ->execute([$idNegocio, $s, "Negocio $idNegocio suc $s"]);
        }
        $pdo->prepare('INSERT INTO suscripcion (id_negocio, id_plan, estado, prueba_hasta) VALUES (?, ?, ?, ?)')
            ->execute([$idNegocio, $this->idPlan($plan), $estado, $pruebaHasta]);
    }

    protected function crearUsuario(string $email, int $idNegocio, bool $titular = true, string $clave = 'Clave1234', int $sucursal = 1, bool $verificado = true): int
    {
        $rol = $titular ? '["ROLE_TITULAR"]' : '["ROLE_USER"]';
        $this->pdo()->prepare('INSERT INTO user (name, email, password, role, id_negocio, sucursal, eliminado, email_verificado) VALUES (?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute(['Usuario ' . $email, $email, password_hash($clave, PASSWORD_DEFAULT), $rol, $idNegocio, $sucursal, $verificado ? 1 : 0]);
        return (int) $this->pdo()->lastInsertId();
    }

    protected function insertar(string $sql, array $params = []): int
    {
        $this->pdo()->prepare($sql)->execute($params);
        return (int) $this->pdo()->lastInsertId();
    }

    protected function tokenPara(string $email): string
    {
        $provider = static::getContainer()->get('App\Security\UsuariosProvider');
        $usuario = $provider->loadUserByIdentifier($email);
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($usuario);
    }

    protected function pedir(string $metodo, string $url, ?array $body = null, ?string $token = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($metodo, $url, [], [], $headers, $body !== null ? json_encode($body) : null);
        $respuesta = $this->client->getResponse();
        return [$respuesta->getStatusCode(), json_decode((string) $respuesta->getContent(), true)];
    }
}
