<?php

namespace App\Command;

use App\Repository\BaseRepository;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aplica en orden los archivos migrations/NNNN_nombre.sql que todavía no se
 * corrieron en esta base, y los anota en la tabla "migraciones".
 *
 * Seguimos con PDO a mano (sin Doctrine), así que las migraciones son SQL puro.
 * Para que se puedan correr sobre una base que ya tiene parte de los cambios
 * (por ejemplo producción, donde algunas columnas o índices ya existían), se
 * saltean los errores de "ya existe": columna (1060), índice (1061) y tabla (1050).
 *
 * Uso: php bin/console app:migraciones [--estado]
 */
class MigracionesCommand extends Command
{
    protected static $defaultName = 'app:migraciones';
    protected static $defaultDescription = 'Aplica las migraciones SQL pendientes';

    private const ERRORES_YA_EXISTE = [1050, 1060, 1061];

    private BaseRepository $repo;
    private string $dir;

    public function __construct(BaseRepository $repo, string $projectDir)
    {
        parent::__construct();
        $this->repo = $repo;
        $this->dir = $projectDir . '/migrations';
    }

    protected function configure(): void
    {
        $this->addOption('estado', null, InputOption::VALUE_NONE, 'Solo lista qué migraciones faltan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->repo->get_bbdd();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migraciones (
            version VARCHAR(100) PRIMARY KEY,
            aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $aplicadas = $pdo->query('SELECT version FROM migraciones')->fetchAll(\PDO::FETCH_COLUMN);
        $archivos = glob($this->dir . '/*.sql') ?: [];
        sort($archivos);

        $pendientes = array_filter($archivos, fn ($f) => !in_array(basename($f, '.sql'), $aplicadas, true));
        if (!$pendientes) {
            $output->writeln('No hay migraciones pendientes.');
            return Command::SUCCESS;
        }

        foreach ($pendientes as $archivo) {
            $version = basename($archivo, '.sql');
            if ($input->getOption('estado')) {
                $output->writeln('Pendiente: ' . $version);
                continue;
            }
            $output->writeln('Aplicando ' . $version . '...');
            foreach (self::sentencias((string) file_get_contents($archivo)) as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    $codigo = (int) ($e->errorInfo[1] ?? 0);
                    if (!in_array($codigo, self::ERRORES_YA_EXISTE, true)) {
                        $output->writeln('<error>Falló: ' . $e->getMessage() . '</error>');
                        $output->writeln($sql);
                        return Command::FAILURE;
                    }
                    $output->writeln('  (ya existía, se saltea) ' . strtok($sql, "\n"), OutputInterface::VERBOSITY_VERBOSE);
                }
            }
            $stmt = $pdo->prepare('INSERT INTO migraciones (version) VALUES (:v)');
            $stmt->execute([':v' => $version]);
        }

        $output->writeln('Listo.');
        return Command::SUCCESS;
    }

    /**
     * Separa un archivo en sentencias: cada una termina en ";" al final de una
     * línea. Se quitan los comentarios "--" de línea completa.
     *
     * @return string[]
     */
    public static function sentencias(string $sql): array
    {
        $lineas = array_filter(
            preg_split('/\R/', $sql),
            fn ($l) => !preg_match('/^\s*--/', $l)
        );
        $partes = preg_split('/;\s*(\R|$)/', implode("\n", $lineas));
        return array_values(array_filter(array_map('trim', $partes), fn ($s) => $s !== ''));
    }
}
