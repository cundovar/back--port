<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ContentController;
use App\Entity\Content;
use App\Entity\AdminUser;
use App\Security\AdminTokenGuard;
use App\Service\ContentSchemaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;

final class ContentControllerTest extends TestCase
{
    public function testGetContentReturnsStoredPayload(): void
    {
        $content = new Content();
        $content->setPayload([
            'hero' => [
                'title' => 'Mission automation IA',
            ],
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('find')
            ->with(1)
            ->willReturn($content);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Content::class)
            ->willReturn($repository);

        $response = (new ContentController())->getContent($entityManager, new ContentSchemaService());

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(['title' => 'Mission automation IA'], $payload['hero']);
        self::assertSame([], $payload['services']);
        self::assertSame([], $payload['problems']);
        self::assertSame([], $payload['process']);
        self::assertSame([], $payload['expertise']);
        self::assertArrayNotHasKey('availability', $payload);
    }

    public function testGetContentReturnsEmptyObjectWhenNoContentExists(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('find')
            ->with(1)
            ->willReturn(null);
        $repository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([], ['updatedAt' => 'DESC'])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Content::class)
            ->willReturn($repository);

        $response = (new ContentController())->getContent($entityManager, new ContentSchemaService());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'services' => [],
                'problems' => [],
                'process' => [],
                'expertise' => [],
            ],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testUpdateContentReturnsFieldErrorsWithoutPersistingInvalidPayload(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $request = new Request([], [], [], [], [], [], json_encode([
            'services' => [
                [
                    'title' => 'Automation',
                    'deliverables' => 'workflow',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer test-token');

        $response = (new ContentController())->updateContent(
            $request,
            $entityManager,
            $this->adminGuard(),
            new ContentSchemaService(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertEqualsCanonicalizing(
            [
                ['path' => 'services.0.deliverables', 'message' => 'Expected an array of strings.'],
            ],
            json_decode((string) $response->getContent(), true)['errors'],
        );
    }

    public function testUpdateContentRejectsMalformedServiceFaqsWithoutPersisting(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $request = new Request([], [], [], [], [], [], json_encode([
            'services' => [
                [
                    'title' => 'Automation',
                    'faqs' => [
                        ['question' => 'Question sans reponse'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer test-token');

        $response = (new ContentController())->updateContent(
            $request,
            $entityManager,
            $this->adminGuard(),
            new ContentSchemaService(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                ['path' => 'services.0.faqs', 'message' => 'Expected an array of question and answer objects.'],
            ],
            json_decode((string) $response->getContent(), true)['errors'],
        );
    }

    public function testUpdateContentRejectsMalformedServicePricingWithoutPersisting(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $request = new Request([], [], [], [], [], [], json_encode([
            'services' => [
                [
                    'title' => 'Automation',
                    'pricing' => ['standard' => 900],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer test-token');

        $response = (new ContentController())->updateContent(
            $request,
            $entityManager,
            $this->adminGuard(),
            new ContentSchemaService(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                ['path' => 'services.0.pricing', 'message' => 'Expected an object with string price fields.'],
            ],
            json_decode((string) $response->getContent(), true)['errors'],
        );
    }

    private function adminGuard(): AdminTokenGuard
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new AdminUser('test@example.com', 'hash'));

        return new AdminTokenGuard($security);
    }
}
