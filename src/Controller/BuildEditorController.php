<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\Edit\CommandFactory;
use App\Build\Edit\InvalidEditCommand;
use App\Entity\Build;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * In-place editing. The build's own lifecycle — create, share, export,
 * replace the whole file — stays in BuildController.
 *
 * Every edit arrives as a form-encoded POST carrying an action and its
 * payload. That is deliberate: a plain <form> and the canvas controller's
 * fetch send the identical body, so the editor keeps working when the canvas
 * does not. What differs is only the answer — Turbo asks for streams, a
 * browser without JavaScript gets a redirect.
 */
final class BuildEditorController extends AbstractController
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function __construct(
        private readonly BuildRepository $builds,
        private readonly CommandFactory $commands,
        private readonly MessageBusInterface $bus,
        private readonly EditorContext $context,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/b/{slug}/edit/{token}/act', name: 'app_build_act', requirements: ['slug' => '[0-9a-zA-Z]{22}', 'token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function act(Request $request, string $slug, string $token): Response
    {
        $build = $this->mustEdit($slug, $token);
        $wantsStream = str_contains((string) $request->headers->get('Accept'), self::STREAM);

        try {
            $command = $this->commands->fromRequest((int) $build->getId(), (string) $request->request->get('action', ''), $request->request);
            $this->bus->dispatch($command);
        } catch (InvalidEditCommand $e) {
            return $this->refuse($build, $token, $e->getMessage(), $wantsStream, $request);
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();

            if (!$cause instanceof InvalidEditCommand) {
                throw $e;
            }

            return $this->refuse($build, $token, $cause->getMessage(), $wantsStream, $request);
        }

        $this->entityManager->refresh($build);

        if (!$wantsStream) {
            return $this->redirectToRoute('app_build_edit', $this->searchParams($build, $token, $request), Response::HTTP_SEE_OTHER);
        }

        return $this->streams($build, $token, null);
    }

    private function refuse(Build $build, string $token, string $message, bool $wantsStream, Request $request): Response
    {
        if (!$wantsStream) {
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_build_edit', $this->searchParams($build, $token, $request), Response::HTTP_SEE_OTHER);
        }

        return $this->streams($build, $token, $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The redirect after a POST lands on a fresh GET request, which has no
     * body to fall back to — so the search terms the `/act` form carried as
     * hidden fields have to be carried forward into the redirect's own query
     * string, or the search the user was looking at goes empty.
     *
     * @return array<string, string>
     */
    private function searchParams(Build $build, string $token, Request $request): array
    {
        $params = ['slug' => $build->getShareSlug(), 'token' => $token];

        foreach (['q', 'gem', 'support', 'unique', 'instilled', 'intervals', 'supports', 'stats'] as $key) {
            $value = $request->request->get($key);

            if (\is_string($value) && '' !== $value) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function streams(Build $build, string $token, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('build/_streams.html.twig', $this->context->of($build, $token, $error), new Response(status: $status));
        $response->headers->set('Content-Type', self::STREAM);

        return $response;
    }

    private function mustEdit(string $slug, string $token): Build
    {
        $build = $this->builds->findOneByShareSlug($slug) ?? throw $this->createNotFoundException();

        if (!$this->builds->isEditableWith($build, $token)) {
            throw $this->createNotFoundException();
        }

        return $build;
    }
}
