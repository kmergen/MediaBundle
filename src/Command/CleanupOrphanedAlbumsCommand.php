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
        // Wir gehen davon aus, dass der 'public' Ordner im Project root liegt.
        // Falls dein Bundle konfigurierbare Pfade hat, müsstest du hier den Parameter injecten.
        // Für den Moment bleiben wir beim Standard:
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

        $io->title('Starte Bereinigung verwaister Media-Alben...');

        // 1. Alle Ordner im Upload-Verzeichnis holen
        $finder = new \DirectoryIterator($this->uploadsDir);
        $deletedCount = 0;
        $scannedCount = 0;

        foreach ($finder as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isDir()) {
                continue;
            }

            $folderName = $fileInfo->getFilename();

            // Sicherheitscheck: Ist der Ordnername eine ID (ganze Zahl)?
            if (!ctype_digit($folderName)) {
                // Wir ignorieren Ordner wie "tmp", "avatars" etc., die keine ID sind
                continue;
            }

            $scannedCount++;
            $albumId = (int) $folderName;

            // 2. Prüfen, ob Album in DB existiert
            $album = $this->em->getRepository(MediaAlbum::class)->find($albumId);

            if (!$album) {
                // 3. Album existiert nicht mehr -> Ordner wegwerfen
                $fullPath = $fileInfo->getPathname();
                try {
                    $this->filesystem->remove($fullPath);
                    if ($io->isVerbose()) {
                        $io->text("Gelöscht: Verwaister Album-Ordner ID #$albumId");
                    }
                    $deletedCount++;
                } catch (\Exception $e) {
                    $io->error("Konnte Ordner $fullPath nicht löschen: " . $e->getMessage());
                }
            }
        }

        $io->success(sprintf(
            'Bereinigung abgeschlossen. %d Ordner gescannt, %d verwaiste Alben gelöscht.', 
            $scannedCount, 
            $deletedCount
        ));

        return Command::SUCCESS;
    }
}