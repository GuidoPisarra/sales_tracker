<?php

namespace App\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IPC Nivel General Nacional (INDEC), vía la API de Series de Tiempo de datos.gob.ar. La fuente
 * devuelve la tasa de VARIACIÓN MENSUAL (ej. 0.088 = 8.8% ese mes), no un índice acumulado — hay
 * que componerla mes a mes para saber la inflación acumulada entre dos fechas. Se publica con
 * ~1 mes de atraso: el mes calendario actual normalmente todavía no tiene dato.
 */
class InflacionService
{
    private const CACHE_KEY = 'ipc_serie_mensual';
    private const CACHE_TTL = 86400;

    private $httpClient;
    private $cache;
    private $apiUrl;

    public function __construct(HttpClientInterface $httpClient, FilesystemAdapter $cache)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->apiUrl = $_ENV['IPC_API_URL'] ?? '';
    }

    /**
     * Inflación acumulada (compuesta) desde el mes SIGUIENTE a $fechaDesde hasta el último mes
     * publicado. Devuelve 0.0 si todavía no pasó ningún mes completo desde $fechaDesde, y null
     * si no hay datos disponibles (fuente caída, o $fechaDesde más vieja que la serie cacheada).
     */
    public function obtenerInflacionAcumulada(string $fechaDesde): ?float
    {
        $serie = $this->obtenerSerieCompleta();
        if (!$serie) {
            return null;
        }

        $mesDesde = (new \DateTime($fechaDesde, new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m');

        $acumulado = 1.0;
        foreach ($serie as $mes => $tasa) {
            if ($mes > $mesDesde) {
                $acumulado *= (1 + $tasa);
            }
        }

        return $acumulado - 1;
    }

    /**
     * @return array<string, float> "Y-m" => tasa de variación mensual, orden ascendente
     */
    private function obtenerSerieCompleta(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            return $item->get();
        }

        try {
            $response = $this->httpClient->request('GET', $this->apiUrl, ['timeout' => 15]);
            $datos = $response->toArray();
        } catch (\Throwable $th) {
            // Fuente externa caída: no tumbamos el listado de precios, el service deja los
            // campos de inflación en null en vez de devolver un 500.
            return [];
        }

        $serie = [];
        foreach ($datos['data'] ?? [] as $fila) {
            if (isset($fila[0], $fila[1])) {
                $mes = (new \DateTime($fila[0]))->format('Y-m');
                $serie[$mes] = (float) $fila[1];
            }
        }

        ksort($serie);

        $item->set($serie);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $serie;
    }
}
