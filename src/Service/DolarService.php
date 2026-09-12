<?php

namespace App\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class DolarService
{
    private const CACHE_KEY = 'dolar_oficial_serie';
    private const CACHE_TTL = 86400;

    private $httpClient;
    private $cache;
    private $apiUrl;

    public function __construct(HttpClientInterface $httpClient, FilesystemAdapter $cache)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->apiUrl = $_ENV['DOLAR_API_URL'] ?? '';
    }

    public function obtenerCotizacionHoy(): ?float
    {
        $hoy = (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        return $this->obtenerCotizacionEnFecha($hoy);
    }

    public function obtenerCotizacionEnFecha(string $fecha): ?float
    {
        $serie = $this->obtenerSerieCompleta();
        if (!$serie) {
            return null;
        }

        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $dia = new \DateTime($fecha, $timezone);

        for ($i = 0; $i < 10; $i++) {
            $clave = $dia->format('Y-m-d');
            if (isset($serie[$clave])) {
                return $serie[$clave];
            }
            $dia->modify('-1 day');
        }

        return null;
    }

    /**
     * @return array<string, float> fecha (Y-m-d) => cotización de venta
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

            return [];
        }

        $serie = [];
        foreach ($datos as $fila) {
            if (isset($fila['fecha'], $fila['venta'])) {
                $serie[$fila['fecha']] = (float) $fila['venta'];
            }
        }

        $item->set($serie);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $serie;
    }
}
