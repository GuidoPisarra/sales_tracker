<?php

namespace App\Command;

use App\Service\Entorno;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Manda por mail un backup de la base como adjunto (copia fuera del VPS sin
 * contratar almacenamiento). Solo acepta backups cifrados con gpg: el dump tiene
 * los datos de todos los clientes y no puede viajar en texto plano.
 *
 * Lo llama scripts/backup-db.sh cuando backup.conf tiene BACKUP_EMAIL. El
 * archivo llega por stdin porque el backup es de root y el comando corre como
 * www-data (para no dejar archivos de root en var/):
 *   php bin/console app:backup:enviar yo@mail.com --nombre=archivo.sql.gz.gpg < archivo.sql.gz.gpg
 */
class EnviarBackupCommand extends Command
{
    protected static $defaultName = 'app:backup:enviar';
    protected static $defaultDescription = 'Manda por mail un backup cifrado de la base';

    // Gmail rechaza adjuntos de más de 25 MB (y el adjunto crece ~33% al codificarse).
    private const MAX_BYTES = 18 * 1024 * 1024;

    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        parent::__construct();
        $this->mailer = $mailer;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('para', InputArgument::REQUIRED, 'Email (o varios separados por coma) al que se manda')
            ->addArgument('archivo', InputArgument::OPTIONAL, 'Backup a mandar; si se omite se lee de stdin', '-')
            ->addOption('nombre', null, InputOption::VALUE_REQUIRED, 'Nombre del adjunto (obligatorio si se lee de stdin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $archivo = (string) $input->getArgument('archivo');
        $nombre = (string) ($input->getOption('nombre') ?: ($archivo !== '-' ? basename($archivo) : ''));
        if ($nombre === '') {
            $output->writeln('<error>Falta --nombre (el archivo llega por stdin)</error>');
            return Command::FAILURE;
        }
        $contenido = $archivo === '-' ? stream_get_contents(STDIN) : @file_get_contents($archivo);
        if ($contenido === false || $contenido === '') {
            $output->writeln('<error>No se pudo leer el backup</error>');
            return Command::FAILURE;
        }

        // gzip empieza con 1f 8b: si llega así, el backup no está cifrado.
        if (!str_ends_with($nombre, '.gpg') || str_starts_with($contenido, "\x1f\x8b")) {
            $output->writeln('<error>El backup no está cifrado: cargá BACKUP_GPG_PASS en scripts/backup.conf. No se manda.</error>');
            return Command::FAILURE;
        }
        $bytes = strlen($contenido);
        if ($bytes > self::MAX_BYTES) {
            $output->writeln(sprintf('<error>El backup pesa %.1f MB: no entra en un mail. Pasá a otro destino (RCLONE_DESTINO).</error>', $bytes / 1048576));
            return Command::FAILURE;
        }

        $para = array_values(array_filter(array_map('trim', explode(',', (string) $input->getArgument('para')))));
        $tamano = $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', ceil($bytes / 1024));
        $email = (new Email())
            ->from(Address::create(Entorno::get('MAIL_FROM', 'Kontia <no-responder@ventas-xpress.com.ar>')))
            ->to(...$para)
            ->subject('[Kontia] Backup de la base ' . date('Y-m-d'))
            ->text("Backup diario de la base de Kontia ($nombre, $tamano), cifrado con BACKUP_GPG_PASS.\n\n"
                . "Para restaurarlo:\n"
                . "  gpg -d $nombre | gunzip | mysql --defaults-extra-file=/root/.my.cnf saleTracker\n\n"
                . "Ojo: eso pisa la base. Para mirarlo sin riesgo, restauralo en una base aparte.\n")
            ->attach($contenido, $nombre, 'application/octet-stream');

        try {
            $this->mailer->send($email);
        } catch (\Throwable $th) {
            $output->writeln('<error>No se pudo mandar el mail: ' . $th->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln("Backup enviado a " . implode(', ', $para) . " ($nombre, $tamano)");
        return Command::SUCCESS;
    }
}
