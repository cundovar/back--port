<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuotePricingController;
use App\Entity\AdminUser;
use App\Entity\QuotePricingConfiguration;
use App\Repository\QuotePricingConfigurationRepository;
use App\Security\AdminTokenGuard;
use App\Service\QuotePricingCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class QuotePricingControllerTest extends TestCase
{
    private QuotePricingConfiguration $configuration;

    protected function setUp(): void
    {
        $this->configuration = new QuotePricingConfiguration();
        $this->configuration->setCatalog(QuotePricingCatalog::defaultCatalog());
    }

    public function testPublicCatalogIsReadableWithoutSession(): void
    {
        $response = $this->controller()->getPublicCatalog();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);

        self::assertCount(5, $body['catalog']['offers']);
        self::assertSame(1, $body['version']);
        // Public payload carries no admin metadata.
        self::assertArrayNotHasKey('updatedAt', $body);
    }

    public function testAdminReadIsDeniedWithoutSession(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->getAdminCatalog(new Request(), $this->denyingGuard());
    }

    public function testAdminUpdateIsDeniedWithoutSession(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->updateCatalog(new Request(), $em, $this->denyingGuard());
    }

    public function testValidCatalogIsSavedAndVersionBumped(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][0]['minimumAmount'] = 400;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $response = $this->controller()->updateCatalog(
            $this->jsonRequest(['catalog' => $catalog]),
            $em,
            $this->allowingGuard(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, json_decode((string) $response->getContent(), true)['version']);
        self::assertSame(400, $this->configuration->getCatalog()['offers'][0]['variants'][0]['minimumAmount']);
    }

    public function testInvalidCatalogReturns422AndLeavesActiveGridUntouched(): void
    {
        $catalog = QuotePricingCatalog::defaultCatalog();
        $catalog['offers'][0]['variants'][0]['minimumAmount'] = 9999;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $response = $this->controller()->updateCatalog(
            $this->jsonRequest(['catalog' => $catalog]),
            $em,
            $this->allowingGuard(),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_catalog', $body['error']);
        self::assertNotEmpty($body['errors']);

        self::assertSame(350, $this->configuration->getCatalog()['offers'][0]['variants'][0]['minimumAmount']);
        self::assertSame(1, $this->configuration->getVersion());
    }

    public function testDeeplyMalformedCatalogReturns422NotAServerError(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $response = $this->controller()->updateCatalog(
            $this->jsonRequest(['catalog' => [
                'offers' => ['une chaîne', ['key' => 'x', 'variants' => 'pas une liste', 'options' => 7]],
                'adjustments' => 'cassé',
            ]]),
            $em,
            $this->allowingGuard(),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_catalog', $body['error']);
        self::assertNotEmpty($body['errors']);
    }

    public function testMalformedPayloadIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->controller()->updateCatalog(
            $this->jsonRequest(['nope' => true]),
            $this->createStub(EntityManagerInterface::class),
            $this->allowingGuard(),
        );
    }

    private function controller(): QuotePricingController
    {
        $repository = $this->createStub(QuotePricingConfigurationRepository::class);
        $repository->method('getActive')->willReturn($this->configuration);

        return new QuotePricingController($repository, new QuotePricingCatalog());
    }

    private function jsonRequest(array $payload): Request
    {
        return Request::create('/api/admin/quote-pricing', 'PUT', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function allowingGuard(): AdminTokenGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new AdminUser('admin@example.com', 'hash'));

        return new AdminTokenGuard($security);
    }

    private function denyingGuard(): AdminTokenGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return new AdminTokenGuard($security);
    }
}
