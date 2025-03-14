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

  public function updateMediaPositions(array $positions): void
  {
    $conn = $this->getEntityManager()->getConnection();

    $sql = "UPDATE media SET position = CASE id ";

    foreach ($positions as $mediaId => $position) {
      if (!is_numeric($mediaId) || !is_numeric($position)) {
        throw new \InvalidArgumentException('Invalid input data');
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

  //    /**
  //     * @return Media[] Returns an array of Media objects
  //     */
  //    public function findByExampleField($value): array
  //    {
  //        return $this->createQueryBuilder('m')
  //            ->andWhere('m.exampleField = :val')
  //            ->setParameter('val', $value)
  //            ->orderBy('m.id', 'ASC')
  //            ->setMaxResults(10)
  //            ->getQuery()
  //            ->getResult()
  //        ;
  //    }

  //    public function findOneBySomeField($value): ?Media
  //    {
  //        return $this->createQueryBuilder('m')
  //            ->andWhere('m.exampleField = :val')
  //            ->setParameter('val', $value)
  //            ->getQuery()
  //            ->getOneOrNullResult()
  //        ;
  //    }
}
