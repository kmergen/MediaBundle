<?php

namespace Kmergen\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'kmergen:media:cleanup',
    description: 'Bereinigt physische Ordner von MediaAlben, die nicht mehr in der Datenbank existieren.',
)]
class CleanupOrphanedAlbumsCommand extends Command
{
    private string $uploadsDir;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Filesystem $filesystem,
        KernelInterface $kernel
    ) {
        $this->uploadsDir = Path::join($kernel->getProjectDir(), 'public/uploads');
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->filesystem->exists($this->uploadsDir)) {
            $io->warning(sprintf('Upload Verzeichnis "%s" existiert nicht. Nichts zu tun.', $this->uploadsDir));
            return Command::SUCCESS;
        }

        $io->title('Suche nach verwaisten Media-Ordnern...');

        // 1. Alle Ordner im Dateisystem finden (mit Symfony Finder)
        $finder = new Finder();
        $finder->directories()
            ->in($this->uploadsDir)
            ->depth(0); // Nur direkte Unterordner von /uploads

        $folderIds = [];
        $skippedFolders = 0;

        foreach ($finder as $directory) {
            $name = $directory->getFilename();

            // Sicherheitscheck: Ist der Ordnername eine ID (ganze Zahl)?
            if (ctype_digit($name)) {
                $folderIds[] = (int) $name;
            } else {
                // Ordner wie "tmp", "avatar" etc. ignorieren wir
                $skippedFolders++;
            }
        }

        if (empty($folderIds)) {
            $io->success("Keine numerischen Album-Ordner gefunden. Alles sauber.");
            return Command::SUCCESS;
        }

        $io->text(sprintf('%d Album-Ordner im Dateisystem gefunden.', count($folderIds)));

        // 2. Performance-Optimierung: Alle existierenden IDs aus der Datenbank holen
        // Anstatt für jeden Ordner eine Query zu machen, holen wir alle IDs auf einmal.
        // Das ist extrem schnell und spart tausende Queries.

        $qb = $this->em->getRepository(MediaAlbum::class)->createQueryBuilder('a');
        $qb->select('a.id');

        // Wir holen nur IDs, die auch im Dateisystem gefunden wurden (optional, aber spart Speicher)
        // Bei sehr vielen Ordnern (>10.000) könnte IN() langsam werden, dann lieber ohne WHERE.
        // Für normale Mengen ist IN() super.
        $qb->where('a.id IN (:ids)')
            ->setParameter('ids', $folderIds);

        // getSingleColumnResult liefert uns ein flaches Array [1, 2, 5, ...]
        $existingDbIds = $qb->getQuery()->getSingleColumnResult();

        // 3. Vergleichen: Welche Ordner sind NICHT in der Datenbank?
        // array_diff gibt alle Einträge aus Array 1 zurück, die in Array 2 fehlen.
        $orphanedIds = array_diff($folderIds, $existingDbIds);

        $countOrphans = count($orphanedIds);

        if ($countOrphans === 0) {
            $io->success('Keine verwaisten Ordner gefunden. Dateisystem und Datenbank sind synchron.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Lösche %d verwaiste Ordner...', $countOrphans));

        // 4. Löschen
        $deletedCount = 0;
        $errorCount = 0;

        foreach ($orphanedIds as $id) {
            $fullPath = Path::join($this->uploadsDir, (string)$id);

            try {
                $this->filesystem->remove($fullPath);

                if ($io->isVerbose()) {
                    $io->text("Gelöscht: $fullPath");
                }
                $deletedCount++;
            } catch (\Exception $e) {
                $io->error("Fehler beim Löschen von $fullPath: " . $e->getMessage());
                $errorCount++;
            }
        }

        // Abschlussmeldung
        $msg = sprintf(
            'Bereinigung abgeschlossen. %d Ordner geprüft. %d verwaiste Ordner gelöscht.',
            count($folderIds),
            $deletedCount
        );

        if ($errorCount > 0) {
            $io->warning("$msg ($errorCount Fehler aufgetreten)");
        } else {
            $io->success($msg);
        }

        return Command::SUCCESS;
    }
}
