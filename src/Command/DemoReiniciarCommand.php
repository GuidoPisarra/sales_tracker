<?php

namespace App\Command;

use App\Service\DemoService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tarea diaria (cron, de noche): deja como nuevo el negocio de ejemplo de
 * "Probar sin registrarme". Si no existe, lo crea.
 */
class DemoReiniciarCommand extends Command
{
    protected static $defaultName = 'app:demo:reiniciar';
    protected static $defaultDescription = 'Reinicia el negocio de ejemplo de "Probar sin registrarme"';

    public function __construct(private DemoService $demo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $usuario = $this->demo->reiniciar();
        $output->writeln('Negocio de ejemplo reiniciado (id_negocio ' . $usuario['id_negocio'] . ', ' . DemoService::email() . ').');
        return Command::SUCCESS;
    }
}
