<?php

namespace Kmergen\MediaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Kmergen\MediaBundle\Entity\Media;

/**
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Media::class);
  }

  /**
   * Findet temporäre Medien, die älter als $date sind.
   * @return iterable<Media>
   */
  public function findOldTempMedia(\DateTimeInterface $limitDate): iterable
  {
    return $this->createQueryBuilder('m')
      ->where('m.tempKey IS NOT NULL')
      ->andWhere('m.createdAt < :limit')
      ->setParameter('limit', $limitDate)
      ->getQuery()
      ->toIterable(); // toIterable() ist speicherschonender bei vielen Datensätzen
  }

  /**
   * Holt alle Medien eines Albums.
   * Ersetzt getEntityMedia().
   */
  public function findByAlbum(int $albumId, array $orderBy = ['position' => 'ASC']): array
  {
    $qb = $this->createQueryBuilder('m')
      ->where('m.album = :albumId')
      ->setParameter('albumId', $albumId);

    foreach ($orderBy as $field => $direction) {
      $qb->addOrderBy('m.' . $field, $direction);
    }

    return $qb->getQuery()->getResult();
  }

  /**
   * Performantes Batch-Update für Positionen (bleibt fast gleich, nur Typsicherheit erhöht)
   */
  public function updateMediaPositions(array $positions): void
  {
    if (empty($positions)) {
      return;
    }

    $conn = $this->getEntityManager()->getConnection();
    $sql = "UPDATE media SET position = CASE id ";

    foreach ($positions as $mediaId => $position) {
      if (!is_numeric($mediaId) || !is_numeric($position)) {
        throw new \InvalidArgumentException('Invalid input data: Media ID and position must be numeric.');
      }
      $sql .= "WHEN :id_$mediaId THEN :position_$mediaId ";
    }

    $sql .= "END WHERE id IN (" . implode(',', array_keys($positions)) . ")";

    $stmt = $conn->prepare($sql);

    foreach ($positions as $mediaId => $position) {
      $stmt->bindValue(":id_$mediaId", $mediaId);
      $stmt->bindValue(":position_$mediaId", $position);
    }

    $stmt->executeStatement();
  }
}
