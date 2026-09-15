<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuotePricingConfiguration;
use App\Service\QuotePricingCatalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuotePricingConfiguration>
 */
class QuotePricingConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuotePricingConfiguration::class);
    }

    /**
     * The table holds a single active row; the seed lets a fresh database work
     * before the migration data has been applied.
     */
    public function getActive(): QuotePricingConfiguration
    {
        $configuration = $this->findOneBy([], ['id' => 'ASC']);

        if (!$configuration) {
            $configuration = new QuotePricingConfiguration();
            $configuration->setCatalog(QuotePricingCatalog::defaultCatalog());
        }

        return $configuration;
    }
}
