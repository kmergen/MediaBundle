<?php

namespace Kmergen\MediaBundle\Command;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
    description: 'Löscht temporäre Uploads und leere Alben, die von keiner anderen Entity mehr referenziert werden.',
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

        $io->title("Starte Bereinigung (Temp-Files älter als $hours Stunden)...");

        // -----------------------------------------------------------------
        // SCHRITT 1: Veraltete Temporäre Medien finden und löschen
        // -----------------------------------------------------------------

        $mediaRepo = $this->em->getRepository(Media::class);

        // Wir laden das Album ('a') direkt mit (addSelect).
        // Das verhindert, dass wir die ID verlieren, wenn das Media-Objekt gelöscht wird.
        $qb = $mediaRepo->createQueryBuilder('m')
            ->leftJoin('m.album', 'a')
            ->addSelect('a')
            ->where('m.tempKey IS NOT NULL')
            ->andWhere('m.createdAt < :limit')
            ->setParameter('limit', $limitDate);

        // toIterable() spart Speicher bei vielen Datensätzen
        $tempMedias = $qb->getQuery()->toIterable();

        $deletedMediaCount = 0;
        $affectedAlbumIds = [];

        foreach ($tempMedias as $media) {
            /** @var Media $media */

            // WICHTIG: Album-ID sichern, BEVOR wir das Media-Objekt löschen!
            $album = $media->getAlbum();
            if ($album) {
                $affectedAlbumIds[$album->getId()] = true;
            }

            // Datei + DB Eintrag löschen (ohne Flush für Performance)
            $this->mediaDeleteService->deleteMedia($media, false);
            $deletedMediaCount++;
        }

        // Jetzt alle Änderungen an den Medien in die DB schreiben
        $this->em->flush();
        $io->text("$deletedMediaCount temporäre Bilder gelöscht.");

        // -----------------------------------------------------------------
        // SCHRITT 2: Automatische Erkennung verknüpfter Tabellen
        // -----------------------------------------------------------------

        // Wir suchen automatisch alle Tabellen (Project, Breeder etc.), die Alben benutzen
        $referringTables = $this->getReferringTables();

        if ($io->isVerbose()) {
            $tableNames = array_map(fn($t) => $t['table'], $referringTables);
            $io->text('Prüfe Referenzen in Tabellen: ' . implode(', ', $tableNames));
        }

        // -----------------------------------------------------------------
        // SCHRITT 3: Leere Alben prüfen & löschen
        // -----------------------------------------------------------------

        $deletedAlbumCount = 0;
        $keptAlbumCount = 0;

        // DB-Connection für schnelle SQL Abfragen
        $conn = $this->em->getConnection();

        foreach (array_keys($affectedAlbumIds) as $albumId) {

            // A) Ist das Album wirklich leer (keine Media Files)?
            // (Es könnten ja noch permanente Bilder drin sein, die wir nicht gelöscht haben)
            $mediaCount = (int) $conn->fetchOne('SELECT COUNT(id) FROM media WHERE album_id = :id', ['id' => $albumId]);

            if ($mediaCount > 0) {
                continue; // Album ist nicht leer -> überspringen
            }

            // B) Wird das Album noch von irgendeiner Projekt-Entity benutzt?
            $isUsed = false;
            foreach ($referringTables as $ref) {
                // SQL: SELECT COUNT(*) FROM breeder WHERE media_album_id = 123
                $sql = sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $ref['table'], $ref['column']);

                try {
                    $usageCount = (int) $conn->fetchOne($sql, ['id' => $albumId]);
                    if ($usageCount > 0) {
                        $isUsed = true;
                        break; // Gefunden! Album wird benutzt -> behalten.
                    }
                } catch (\Exception $e) {
                    // Tabelle existiert evtl. nicht oder Spalte anders -> ignorieren
                }
            }

            if ($isUsed) {
                // Das Album ist leer, gehört aber noch zu einem Projekt/Breeder.
                // Wir behalten es, damit die Verknüpfung nicht verloren geht.
                $keptAlbumCount++;
                continue;
            }

            // C) Album ist leer UND gehört niemandem -> Löschen
            try {
                // Wir nutzen DBAL (SQL), damit der EntityManager bei Fehlern nicht geschlossen wird
                $conn->delete('media_album', ['id' => $albumId]);

                // Physischen Ordner löschen
                $albumDir = $this->publicDir . '/uploads/' . $albumId;
                if ($this->filesystem->exists($albumDir)) {
                    $this->filesystem->remove($albumDir);
                }

                $deletedAlbumCount++;

                // Cache bereinigen: Doctrine mitteilen, dass dieses Album weg ist
                $albumRef = $this->em->getReference(MediaAlbum::class, $albumId);
                if ($albumRef) {
                    $this->em->detach($albumRef);
                }
            } catch (ForeignKeyConstraintViolationException $e) {
                // Sollte dank Check (B) nicht passieren, aber sicherheitshalber:
                $keptAlbumCount++;
            } catch (\Exception $e) {
                $io->warning("Fehler beim Löschen von Album ID $albumId: " . $e->getMessage());
            }
        }

        $io->success(sprintf(
            "Fertig. %d Bilder gelöscht. %d verwaiste Alben gelöscht (%d behalten, da leer aber in Benutzung).",
            $deletedMediaCount,
            $deletedAlbumCount,
            $keptAlbumCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Sucht automatisch alle Tabellen und Spalten im gesamten Projekt,
     * die eine Foreign Key Beziehung zum MediaAlbum haben.
     */
    private function getReferringTables(): array
    {
        $referringTables = [];
        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $classMetadata) {
            // Die Media-Entity selbst überspringen wir (wird über media-count geprüft)
            if ($classMetadata->getName() === Media::class) {
                continue;
            }

            // Wir prüfen alle Relationen dieser Entity
            foreach ($classMetadata->getAssociationMappings() as $mapping) {

                // Wenn das Ziel der Relation "MediaAlbum" ist...
                if ($mapping['targetEntity'] === MediaAlbum::class) {

                    // ... und hier der Foreign Key liegt (Owning Side)
                    if (isset($mapping['joinColumns']) && !empty($mapping['joinColumns'])) {

                        $referringTables[] = [
                            'table' => $classMetadata->getTableName(),
                            'column' => $mapping['joinColumns'][0]['name'] // z.B. media_album_id
                        ];
                    }
                }
            }
        }

        return $referringTables;
    }
}
