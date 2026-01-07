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
        $projects = $em->getRepository(Project::class)->findAll();
        $data = array_map(fn (Project $project) => $this->mapProject($project), $projects);

        return new JsonResponse($data);
    }

    #[Route('/api/projects/{id}', methods: ['GET'])]
    public function getProject(int $id, EntityManagerInterface $em): JsonResponse
    {
        $project = $em->getRepository(Project::class)->find($id);
        if (!$project) {
            throw new NotFoundHttpException('Project not found');
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

        $uploadsDir = __DIR__ . '/../../public/uploads/projects';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $extension = $file->getClientOriginalExtension();
        if ($extension === '') {
            $extension = 'bin';
        }
        $filename = sprintf('project_%d_%s.%s', $project->getId(), uniqid(), $extension);
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
        $project->setStack((string) ($payload['stack'] ?? ''));
        $project->setSummary((string) ($payload['summary'] ?? ''));
        $project->setBulletin((string) ($payload['bulletin'] ?? ''));
        $project->setSiteUrl((string) ($payload['siteUrl'] ?? ''));
        $repoUrl = isset($payload['repoUrl']) ? trim((string) $payload['repoUrl']) : '';
        $project->setRepoUrl($repoUrl !== '' ? $repoUrl : null);
        $project->setStatus((string) ($payload['status'] ?? 'wip'));
        $project->setImageUrl(isset($payload['imageUrl']) ? (string) $payload['imageUrl'] : null);
        $project->setDuration(isset($payload['duration']) ? (string) $payload['duration'] : null);
    }

    private function mapProject(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'stack' => $project->getStack(),
            'summary' => $project->getSummary(),
            'bulletin' => $project->getBulletin(),
            'siteUrl' => $project->getSiteUrl(),
            'repoUrl' => $project->getRepoUrl(),
            'imageUrl' => $project->getImageUrl(),
            'duration' => $project->getDuration(),
            'status' => $project->getStatus(),
        ];
    }
}
