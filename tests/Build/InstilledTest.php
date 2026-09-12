<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AddInstilled;
use App\Build\Edit\Command\RemoveInstilled;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class InstilledTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testDeclaringAndRemovingAnInstilledPassive(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();
        self::assertSame(['ignite_mitigation13'], $this->reload($build)->getInstilledPassives());

        $this->bus->dispatch(new RemoveInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();
        self::assertSame([], $this->reload($build)->getInstilledPassives());
    }

    public function testDeclaringTheSamePassiveTwiceKeepsOneEntry(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();

        self::assertSame(['ignite_mitigation13'], $this->reload($build)->getInstilledPassives());
    }

    public function testMoreThanOneMayBeDeclared(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'first_node'));
        $this->bus->dispatch(new AddInstilled($id, 'second_node'));
        $this->entityManager->clear();

        self::assertSame(['first_node', 'second_node'], $this->reload($build)->getInstilledPassives(), 'a unique amulet can carry more than one; the app does not police a limit it cannot verify');
    }

    public function testAnInstilledDeclarationNeverReachesTheExportedDocument(): void
    {
        $build = $this->build();
        $before = $build->toDocument();

        $this->bus->dispatch(new AddInstilled((int) $build->getId(), 'ignite_mitigation13'));
        $this->entityManager->clear();

        self::assertEquals($before, $this->reload($build)->toDocument());
    }

    private function reload(Build $build): Build
    {
        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function build(): Build
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $build = $this->builds->create(new BuildDocumentReader()->read($json), '0.5.5');
        $this->entityManager->flush();

        return $build;
    }
}
