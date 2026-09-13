<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\ClassFromAscendancy;
use App\Entity\Build;
use App\Interchange\BuildDocumentReader;
use App\Interchange\BuildDocumentWriter;
use App\Interchange\InvalidBuildDocument;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BuildController extends AbstractController
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly EntityManagerInterface $entityManager,
        private readonly BuildDocumentReader $reader,
        private readonly BuildDocumentWriter $writer,
        private readonly EditorContext $context,
        private readonly ClassFromAscendancy $classes,
        #[Autowire('%app.default_game_version%')]
        private readonly string $defaultGameVersion,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('build/new.html.twig');
    }

    #[Route('/builds', name: 'app_build_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $json = $this->submittedJson($request);

        if (null === $json) {
            return $this->render('build/new.html.twig', ['error' => 'Choose a .build file or paste its JSON.'], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        try {
            $document = $this->reader->read($json);
        } catch (InvalidBuildDocument $e) {
            return $this->render('build/new.html.twig', ['error' => $e->getMessage()], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $build = $this->builds->create($document, $this->defaultGameVersion);
        $this->classes->fillIn($build);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_build_edit', [
            'slug' => $build->getShareSlug(),
            'token' => (string) $build->getEditToken(),
        ]);
    }

    #[Route('/b/{slug}', name: 'app_build_show', requirements: ['slug' => '[0-9a-zA-Z]{22}'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        return $this->render('build/show.html.twig', ['build' => $this->mustFind($slug)]);
    }

    #[Route('/b/{slug}/export', name: 'app_build_export', requirements: ['slug' => '[0-9a-zA-Z]{22}'], methods: ['GET'])]
    public function export(string $slug): Response
    {
        $build = $this->mustFind($slug);

        $response = new Response($this->writer->write($build->toDocument()));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $this->fileName($build)));

        return $response;
    }

    #[Route('/b/{slug}/edit/{token}', name: 'app_build_edit', requirements: ['slug' => '[0-9a-zA-Z]{22}', 'token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function edit(string $slug, string $token): Response
    {
        $build = $this->mustFind($slug);

        if (!$this->builds->isEditableWith($build, $token)) {
            throw $this->createNotFoundException();
        }

        return $this->render('build/edit.html.twig', $this->context->of($build, $token));
    }

    #[Route('/b/{slug}/edit/{token}/update', name: 'app_build_update', requirements: ['slug' => '[0-9a-zA-Z]{22}', 'token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function update(Request $request, string $slug, string $token): Response
    {
        $build = $this->mustFind($slug);

        if (!$this->builds->isEditableWith($build, $token)) {
            throw $this->createNotFoundException();
        }

        $json = $this->submittedJson($request);

        if (null === $json) {
            return $this->render('build/edit.html.twig', $this->context->of($build, $token, 'Choose a .build file or paste its JSON.'), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        try {
            $build->applyDocument($this->reader->read($json));
            $this->classes->fillIn($build);
        } catch (InvalidBuildDocument $e) {
            return $this->render('build/edit.html.twig', $this->context->of($build, $token, $e->getMessage()), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_build_edit', ['slug' => $slug, 'token' => $token]);
    }

    private function submittedJson(Request $request): ?string
    {
        $file = $request->files->get('build');
        if ($file instanceof UploadedFile) {
            $contents = file_get_contents($file->getPathname());

            return false === $contents ? null : $contents;
        }

        $pasted = trim((string) $request->request->get('json', ''));

        return '' === $pasted ? null : $pasted;
    }

    private function mustFind(string $slug): Build
    {
        return $this->builds->findOneByShareSlug($slug) ?? throw $this->createNotFoundException();
    }

    private function fileName(Build $build): string
    {
        $stem = preg_replace('/[^A-Za-z0-9_-]+/', '-', $build->getName());

        return trim((string) $stem, '-').'.build';
    }
}
