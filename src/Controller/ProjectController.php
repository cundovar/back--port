<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Security\AdminTokenGuard;
use App\Service\GitHubRepoActivityService;
use App\Service\OpenAiBulletinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController
{
    #[Route('/api/projects', methods: ['GET'])]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        $projects = $em->getRepository(Project::class)->findBy(
            ['status' => Project::PUBLIC_STATUSES],
            ['featured' => 'DESC', 'sortOrder' => 'ASC', 'updatedAt' => 'DESC'],
        );
        $data = array_map(fn (Project $project) => $this->mapProject($project), $projects);

        return new JsonResponse($data);
    }

    #[Route('/api/admin/projects', methods: ['GET'])]
    public function listAdmin(Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);
        $projects = $em->getRepository(Project::class)->findBy(
            [],
            ['featured' => 'DESC', 'sortOrder' => 'ASC', 'updatedAt' => 'DESC'],
        );
        $data = array_map(fn (Project $project) => $this->mapProject($project), $projects);

        return new JsonResponse($data);
    }

    #[Route('/api/projects/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getProject(int $id, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        return new JsonResponse($this->mapProject($project));
    }

    #[Route('/api/projects/{slug}', methods: ['GET'])]
    public function getProjectBySlug(string $slug, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        if (!in_array($project->getStatus(), Project::PUBLIC_STATUSES, true)) {
            throw new NotFoundHttpException('Project not published');
        }

        return new JsonResponse($this->mapProject($project));
    }

    #[Route('/api/admin/projects', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $payload = $this->parseJson($request);

        $project = new Project();
        $this->hydrateProject($project, $payload);
        $em->persist($project);
        $em->flush();

        return new JsonResponse(['id' => $project->getId()]);
    }

    #[Route('/api/admin/projects/{id}', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        $payload = $this->parseJson($request);
        $this->hydrateProject($project, $payload);
        $project->touch();
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/projects/{id}', methods: ['DELETE'])]
    public function delete(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        $em->remove($project);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/projects/{id}/image', methods: ['POST'])]
    public function uploadImage(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard
    ): JsonResponse {
        $guard->assertAdmin($request);
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Missing file');
        }

        $this->validateUploadedImage($file);

        $uploadsDir = __DIR__ . '/../../public/uploads/projects';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $extension = $this->getImageExtension($file);
        $filename = sprintf('project_%d_%s.%s', $project->getId(), bin2hex(random_bytes(6)), $extension);
        $file->move($uploadsDir, $filename);

        $project->setImageUrl('/uploads/projects/' . $filename);
        $project->touch();
        $em->flush();

        return new JsonResponse(['imageUrl' => $project->getImageUrl()]);
    }

    #[Route('/api/admin/projects/{id}/bulletin', methods: ['POST'])]
    public function generateBulletin(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AdminTokenGuard $guard,
        GitHubRepoActivityService $activityService,
        OpenAiBulletinService $bulletinService
    ): JsonResponse {
        $guard->assertAdmin($request);
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
        }

        $commits = $activityService->fetchRecentCommits($project->getRepoUrl(), 5);
        $bulletin = $bulletinService->generateBulletin(
            $project->getName(),
            $project->getStack(),
            $project->getSummary(),
            $commits
        );

        $project->setBulletin($bulletin);
        $project->touch();
        $em->flush();

        return new JsonResponse([
            'bulletin' => $project->getBulletin(),
        ]);
    }

    private function parseJson(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }
        return $payload;
    }

    private function hydrateProject(Project $project, array $payload): void
    {
        $project->setName((string) ($payload['name'] ?? ''));
        $project->setSlug((string) ($payload['slug'] ?? $this->slugify((string) ($payload['name'] ?? ''))));
        $project->setStack((string) ($payload['stack'] ?? ''));
        $project->setSummary((string) ($payload['summary'] ?? ''));
        $project->setBulletin((string) ($payload['bulletin'] ?? ''));
        $project->setSiteUrl((string) ($payload['siteUrl'] ?? ''));
        $repoUrl = isset($payload['repoUrl']) ? trim((string) $payload['repoUrl']) : '';
        $project->setRepoUrl($repoUrl !== '' ? $repoUrl : null);
        $project->setStatus((string) ($payload['status'] ?? Project::STATUS_DRAFT));
        $project->setImageUrl(isset($payload['imageUrl']) ? (string) $payload['imageUrl'] : null);
        $project->setDuration(isset($payload['duration']) ? (string) $payload['duration'] : null);
        $project->setClientProblem(isset($payload['clientProblem']) ? (string) $payload['clientProblem'] : null);
        $project->setMission(isset($payload['mission']) ? (string) $payload['mission'] : null);
        $project->setSolution(isset($payload['solution']) ? (string) $payload['solution'] : null);
        $project->setOutcomes(is_array($payload['outcomes'] ?? null) ? $payload['outcomes'] : []);
        $project->setServiceTags(is_array($payload['serviceTags'] ?? null) ? $payload['serviceTags'] : []);
        $project->setFeatured((bool) ($payload['featured'] ?? false));
        $project->setSortOrder((int) ($payload['sortOrder'] ?? 0));
    }

    private function mapProject(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'slug' => $project->getSlug(),
            'name' => $project->getName(),
            'stack' => $project->getStack(),
            'summary' => $project->getSummary(),
            'bulletin' => $project->getBulletin(),
            'siteUrl' => $project->getSiteUrl(),
            'repoUrl' => $project->getRepoUrl(),
            'imageUrl' => $project->getImageUrl(),
            'duration' => $project->getDuration(),
            'status' => $project->getStatus(),
            'clientProblem' => $project->getClientProblem(),
            'mission' => $project->getMission(),
            'solution' => $project->getSolution(),
            'outcomes' => $project->getOutcomes(),
            'serviceTags' => $project->getServiceTags(),
            'featured' => $project->isFeatured(),
            'sortOrder' => $project->getSortOrder(),
        ];
    }

    private function validateUploadedImage(UploadedFile $file): void
    {
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new BadRequestHttpException('File too large (max 5MB)');
        }

        $mimeType = $file->getMimeType();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new BadRequestHttpException('Invalid image type');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new BadRequestHttpException('Invalid file extension');
        }

        if (!getimagesize($file->getPathname())) {
            throw new BadRequestHttpException('Invalid image file');
        }
    }

    private function getImageExtension(UploadedFile $file): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        return $mimeToExt[$file->getMimeType()] ?? 'jpg';
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}
