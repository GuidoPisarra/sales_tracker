<?php

namespace App\Command;

use App\Service\Entorno;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chequeo rápido de la configuración de Mercado Pago en el servidor:
 * que estén las variables y que el Access Token funcione.
 * Uso: APP_ENV=prod php bin/console app:mp:verificar
 */
class VerificarMercadoPagoCommand extends Command
{
    protected static $defaultName = 'app:mp:verificar';
    protected static $defaultDescription = 'Verifica las variables y el Access Token de Mercado Pago';

    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        parent::__construct();
        $this->http = $http;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ok = true;
        foreach (['MP_ACCESS_TOKEN', 'MP_WEBHOOK_SECRET', 'MP_BACK_URL', 'APP_URL_FRONT'] as $variable) {
            $valor = Entorno::get($variable);
            if ($valor === '') {
                $output->writeln("<error>Falta $variable</error>");
                $ok = false;
            } else {
                $mostrar = str_contains($variable, 'TOKEN') || str_contains($variable, 'SECRET') ? substr($valor, 0, 8) . '…' : $valor;
                $output->writeln("$variable = $mostrar");
            }
        }
        $token = Entorno::get('MP_ACCESS_TOKEN');
        if ($token === '') {
            return Command::FAILURE;
        }
        if (str_starts_with($token, 'TEST-')) {
            $output->writeln('<comment>El Access Token es de prueba: no cobra de verdad.</comment>');
        }
        try {
            $r = $this->http->request('GET', 'https://api.mercadopago.com/users/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 20,
            ]);
            $datos = $r->toArray(false);
            if ($r->getStatusCode() !== 200) {
                $output->writeln('<error>Mercado Pago rechazó el Access Token (' . $r->getStatusCode() . '): ' . ($datos['message'] ?? '') . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('Cuenta de Mercado Pago: ' . ($datos['nickname'] ?? '?') . ' (id ' . ($datos['id'] ?? '?') . ', ' . ($datos['site_id'] ?? '?') . ')');
        } catch (\Throwable $th) {
            $output->writeln('<error>No se pudo conectar con Mercado Pago: ' . $th->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($ok ? '<info>Mercado Pago está listo.</info>' : '<comment>Completá las variables que faltan.</comment>');
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
