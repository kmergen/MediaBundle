<?php

namespace Kmergen\MediaBundle\Command;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Service\MediaDeleteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'kmergen:media:cleanup-temp',
    description: 'Löscht temporäre Uploads und leere, nicht zugeordnete Alben.',
)]
class CleanupTempMediaCommand extends Command
{
    private string $publicDir;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaDeleteService $mediaDeleteService,
        private readonly Filesystem $filesystem,
        KernelInterface $kernel
    ) {
        $this->publicDir = $kernel->getProjectDir() . '/public';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Wie alt müssen Temp-Dateien sein (in Stunden)?', 24);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = (int) $input->getOption('hours');
        $limitDate = new \DateTime(sprintf('-%d hours', $hours));

        $io->title("Starte Bereinigung (Älter als $hours Stunden)...");

        // 1. Veraltete Temporäre Medien finden
        $mediaRepo = $this->em->getRepository(Media::class);
        $qb = $mediaRepo->createQueryBuilder('m')
            ->where('m.tempKey IS NOT NULL')
            ->andWhere('m.createdAt < :limit')
            ->setParameter('limit', $limitDate);

        /** @var Media[] $tempMedias */
        $tempMedias = $qb->getQuery()->getResult();
        $deletedMediaCount = 0;
        
        // Wir merken uns die Alben-IDs, um sie später zu prüfen
        $affectedAlbumIds = [];

        foreach ($tempMedias as $media) {
            if ($media->getAlbum()) {
                $affectedAlbumIds[$media->getAlbum()->getId()] = true;
            }
            
            // Nutzt deinen existierenden Service (löscht File + DB Eintrag)
            // false = kein flush zwischendurch für Performance
            $this->mediaDeleteService->deleteMedia($media, false);
            $deletedMediaCount++;
        }

        // Einmal flushen, damit die Media-Einträge weg sind
        $this->em->flush();
        $io->text("$deletedMediaCount temporäre Bilder gelöscht.");

        // 2. Leere Alben prüfen & löschen
        // Wir prüfen alle Alben, aus denen wir gerade etwas gelöscht haben, 
        // und zusätzlich können wir generell leere Alben suchen (optional)
        
        $deletedAlbumCount = 0;
        $keptAlbumCount = 0;

        foreach (array_keys($affectedAlbumIds) as $albumId) {
            // Album neu laden (um sicherzugehen, dass Collection aktuell ist)
            $album = $this->em->getRepository(MediaAlbum::class)->find($albumId);
            
            if (!$album) continue;

            // Wenn das Album noch Bilder enthält (z.B. permanente), überspringen
            if ($album->getMedia()->count() > 0) {
                continue;
            }

            // VERSUCH: Album löschen
            try {
                // Wir müssen hier einzeln flushen, um den Constraint-Error abzufangen
                $this->em->remove($album);
                $this->em->flush();
                
                // Wenn wir hier sind, hat die DB das Löschen erlaubt (kein Breeder referenziert es)
                // -> Ordner physisch löschen
                $albumDir = $this->publicDir . '/uploads/' . $albumId;
                if ($this->filesystem->exists($albumDir)) {
                    $this->filesystem->remove($albumDir);
                }
                
                $deletedAlbumCount++;
                
            } catch (\Exception $e) {
                // Das Album wird noch von einer Entity (Breeder) benutzt!
                // DB Rollback für dieses Item (EntityManager zurücksetzen ist tricky, 
                // aber da wir einzeln flushen, ist die Exception meist DBAL Level).
                
                // Wir müssen den EntityManager "resetten" oder sicherstellen, dass er offen bleibt.
                // Bei ConstraintViolation bleibt der EM meist offen, aber die Transaktion ist fehlgeschlagen.
                // In Symfony Commands ist das oft okay, wenn wir neu ansetzen.
                
                // Da der EM nach einer Exception geschlossen sein könnte:
                if (!$this->em->isOpen()) {
                    $this->em = $this->em->create($this->em->getConnection(), $this->em->getConfiguration());
                }
                
                $keptAlbumCount++;
            }
        }

        $io->success(sprintf(
            "Fertig. %d Bilder gelöscht. %d verwaiste Alben gelöscht (%d behalten weil in Benutzung).",
            $deletedMediaCount,
            $deletedAlbumCount,
            $keptAlbumCount
        ));

        return Command::SUCCESS;
    }
}