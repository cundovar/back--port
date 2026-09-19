<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ProjectController;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ProjectControllerTest extends TestCase
{
    public function testListReturnsOnlyPublicSymfonyProjectContract(): void
    {
        $project = new Project();
        $this->setProjectId($project, 42);
        $project->setSlug('portfolio-missions');
        $project->setName('Portfolio missions');
        $project->setStack('Symfony, Vue, IA');
        $project->setSummary('Refonte orientee missions.');
        $project->setBulletin('Contrat API stable.');
        $project->setSiteUrl('https://example.com');
        $project->setRepoUrl(null);
        $project->setImageUrl(null);
        $project->setDuration(null);
        $project->setStatus(Project::STATUS_IN_PROGRESS);
        $project->setClientProblem('Positionnement trop technique.');
        $project->setMission('Clarifier les missions.');
        $project->setSolution('Structure orientee services.');
        $project->setOutcomes([]);
        $project->setServiceTags(['automation', 'ia']);
        $project->setFeatured(true);
        $project->setSortOrder(10);

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                ['status' => Project::PUBLIC_STATUSES],
                ['featured' => 'DESC', 'sortOrder' => 'ASC', 'updatedAt' => 'DESC'],
            )
            ->willReturn([$project]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Project::class)
            ->willReturn($repository);

        $response = (new ProjectController())->list($entityManager);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            [
                'id' => 42,
                'slug' => 'portfolio-missions',
                'name' => 'Portfolio missions',
                'stack' => 'Symfony, Vue, IA',
                'summary' => 'Refonte orientee missions.',
                'bulletin' => 'Contrat API stable.',
                'siteUrl' => 'https://example.com',
                'repoUrl' => null,
                'imageUrl' => null,
                'duration' => null,
                'status' => 'in_progress',
                'clientProblem' => 'Positionnement trop technique.',
                'mission' => 'Clarifier les missions.',
                'solution' => 'Structure orientee services.',
                'outcomes' => [],
                'serviceTags' => ['automation', 'ia'],
                'featured' => true,
                'sortOrder' => 10,
            ],
        ], $payload);
        self::assertArrayNotHasKey('progress', $payload[0]);
    }

    private function setProjectId(Project $project, int $id): void
    {
        $property = new ReflectionProperty(Project::class, 'id');
        $property->setValue($project, $id);
    }
}
