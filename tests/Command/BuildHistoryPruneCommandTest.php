<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Build\Edit\EditHistory;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class BuildHistoryPruneCommandTest extends KernelTestCase
{
    public function testItDropsExpiredEventsAndReportsHowMany(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();

        $build = self::getContainer()->get(BuildRepository::class)->create(new BuildDocumentReader()->read('{"name":"Build"}'), '0.5.5');
        $history = self::getContainer()->get(EditHistory::class);
        $expired = $history->record($build, 'passive.allocate', ['id' => 'a']);
        $history->record($build, 'passive.allocate', ['id' => 'b']);
        $entityManager->flush();

        $entityManager->getConnection()->executeStatement(
            'UPDATE build_event SET created_at = ? WHERE id = ?',
            [new \DateTimeImmutable('-31 days')->format('Y-m-d H:i:s'), $expired->getId()],
        );

        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $tester = new CommandTester(new Application($kernel)->find('app:build:history:prune'));
        $tester->execute(['--days' => '30']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());

        $count = $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM build_event');
        self::assertSame(1, is_numeric($count) ? (int) $count : 0);
    }
}
