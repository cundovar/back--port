<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuoteEstimateController;
use App\Entity\QuoteEstimate;
use App\Entity\AdminUser;
use App\Entity\QuotePricingConfiguration;
use App\Repository\QuoteEstimateRepository;
use App\Repository\QuotePricingConfigurationRepository;
use App\Security\AdminTokenGuard;
use App\Service\DeepSeekQuoteAnalysisService;
use App\Service\QuoteEstimateCalculator;
use App\Service\QuoteEstimateNotificationService;
use App\Service\QuotePricingCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class QuoteEstimateAdminControllerTest extends TestCase
{
    public function testListIsDeniedWithoutAdminSession(): void
    {
        $repository = $this->createMock(QuoteEstimateRepository::class);
        $repository->expects(self::never())->method('findForAdminList');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->listAdmin(new Request(), $repository, $this->denyingGuard());
    }

    public function testDetailIsDeniedWithoutAdminSession(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->getEstimate(1, new Request(), $em, $this->denyingGuard());
    }

    public function testUpdateIsDeniedWithoutAdminSession(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->updateEstimate(1, new Request(), $em, $this->denyingGuard());
    }

    public function testListReturnsLightweightRowsFilteredByStatus(): void
    {
        $repository = $this->createMock(QuoteEstimateRepository::class);
        $repository->expects(self::once())
            ->method('findForAdminList')
            ->with('new', 20, 0)
            ->willReturn([$this->estimate()]);
        $repository->expects(self::once())->method('countAll')->with('new')->willReturn(1);

        $request = Request::create('/api/admin/quote-estimates?status=new');
        $response = $this->controller()->listAdmin($request, $repository, $this->allowingGuard());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $body['total']);
        $row = $body['items'][0];
        self::assertSame('Jane Doe', $row['fullName']);
        self::assertArrayNotHasKey('answers', $row);
        self::assertArrayNotHasKey('summary', $row);
    }

    public function testListIgnoresAnUnknownStatusFilter(): void
    {
        $repository = $this->createMock(QuoteEstimateRepository::class);
        $repository->expects(self::once())
            ->method('findForAdminList')
            ->with(null, 20, 0)
            ->willReturn([]);
        $repository->method('countAll')->willReturn(0);

        $request = Request::create('/api/admin/quote-estimates?status=bogus');
        $this->controller()->listAdmin($request, $repository, $this->allowingGuard());
    }

    public function testDetailReturnsFullPayload(): void
    {
        $em = $this->entityManagerReturning($this->estimate());

        $response = $this->controller()->getEstimate(1, new Request(), $em, $this->allowingGuard());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame('Synthèse', $body['summary']);
        self::assertSame('nouveau', $body['answers']['projectStage']);
        self::assertSame(3, $body['pricingVersion']);
        self::assertSame('deepseek', $body['aiSource']);
        self::assertNotEmpty($body['calculationDetail']);
    }

    public function testListRowCarriesTheOfferAndPricingVersion(): void
    {
        $repository = $this->createStub(QuoteEstimateRepository::class);
        $repository->method('findForAdminList')->willReturn([$this->estimate()]);
        $repository->method('countAll')->willReturn(1);

        $response = $this->controller()->listAdmin(new Request(), $repository, $this->allowingGuard());
        $row = json_decode((string) $response->getContent(), true)['items'][0];

        self::assertSame('site-vitrine', $row['offerKey']);
        self::assertSame('wordpress-vitrine', $row['variantKey']);
        self::assertSame(3, $row['pricingVersion']);
    }

    public function testHistoricalAmountsSurviveACatalogChange(): void
    {
        $raisedCatalog = QuotePricingCatalog::defaultCatalog();
        $raisedCatalog['offers'][0]['variants'][1]['minimumAmount'] = 9000;
        $raisedCatalog['offers'][0]['variants'][1]['maximumAmount'] = 12000;

        $em = $this->entityManagerReturning($this->estimate());

        $response = $this->controller($raisedCatalog)->getEstimate(1, new Request(), $em, $this->allowingGuard());
        $body = json_decode((string) $response->getContent(), true);

        // The stored result is read back as-is: an admin price edit never rewrites history.
        self::assertSame(1500, $body['minimumAmount']);
        self::assertSame(3500, $body['maximumAmount']);
        self::assertSame(3, $body['pricingVersion']);
    }

    public function testDetailReturns404WhenMissing(): void
    {
        $em = $this->entityManagerReturning(null);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->getEstimate(404, new Request(), $em, $this->allowingGuard());
    }

    public function testUpdatePersistsStatusAndNotes(): void
    {
        $estimate = $this->estimate();
        $em = $this->entityManagerReturning($estimate);
        $em->expects(self::once())->method('flush');

        $request = Request::create(
            '/api/admin/quote-estimates/1',
            'PATCH',
            [],
            [],
            [],
            [],
            json_encode(['status' => 'qualified', 'notes' => 'Rappeler lundi'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->updateEstimate(1, $request, $em, $this->allowingGuard());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(QuoteEstimate::STATUS_QUALIFIED, $estimate->getStatus());
        self::assertNotNull($estimate->getQualifiedAt());
        self::assertSame('Rappeler lundi', $estimate->getNotes());
    }

    public function testUpdateRejectsUnknownStatus(): void
    {
        $em = $this->entityManagerReturning($this->estimate());
        $em->expects(self::never())->method('flush');

        $request = Request::create(
            '/api/admin/quote-estimates/1',
            'PATCH',
            [],
            [],
            [],
            [],
            json_encode(['status' => 'deleted'], JSON_THROW_ON_ERROR),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->updateEstimate(1, $request, $em, $this->allowingGuard());
    }

    private function controller(?array $catalog = null): QuoteEstimateController
    {
        $configuration = new QuotePricingConfiguration();
        $configuration->setCatalog($catalog ?? QuotePricingCatalog::defaultCatalog());

        $pricingRepository = $this->createStub(QuotePricingConfigurationRepository::class);
        $pricingRepository->method('getActive')->willReturn($configuration);

        return new QuoteEstimateController(
            new QuoteEstimateCalculator(new QuotePricingCatalog()),
            $this->createStub(DeepSeekQuoteAnalysisService::class),
            $this->createStub(QuoteEstimateNotificationService::class),
            $pricingRepository,
            $this->createStub(RateLimiterFactoryInterface::class),
            $this->createStub(RateLimiterFactoryInterface::class),
        );
    }

    private function estimate(): QuoteEstimate
    {
        $estimate = new QuoteEstimate();
        $estimate->setServiceKey('site-vitrine');
        $estimate->setOfferKey('site-vitrine');
        $estimate->setVariantKey('wordpress-vitrine');
        $estimate->setPricingVersion(3);
        $estimate->setAnswers([
            'offerLabel' => 'Présenter mon activité en ligne',
            'variantLabel' => 'Un site de plusieurs pages que vous pouvez modifier',
            'selectedOptions' => [['key' => 'prise-rdv', 'label' => 'Prise de rendez-vous en ligne']],
            'projectStage' => 'nouveau',
            'contentReadiness' => 'pret',
            'deadline' => 'normal',
            'projectDescription' => 'Site pour mon cabinet',
        ]);
        $estimate->setFullName('Jane Doe');
        $estimate->setEmail('jane@example.com');
        $estimate->setMinimumAmount(1500);
        $estimate->setMaximumAmount(3500);
        $estimate->setCalculationDetail([['label' => 'Base', 'impactMin' => 800, 'impactMax' => 2500]]);
        $estimate->setAiSummary('Synthèse');
        $estimate->setAiRecommendedScope(['Cadrer']);
        $estimate->setAiSource(QuoteEstimate::AI_SOURCE_DEEPSEEK);

        return $estimate;
    }

    private function entityManagerReturning(?QuoteEstimate $estimate): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($estimate);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(QuoteEstimate::class)->willReturn($repository);

        return $em;
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
