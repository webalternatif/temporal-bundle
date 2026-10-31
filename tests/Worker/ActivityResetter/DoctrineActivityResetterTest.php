<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Worker\ActivityResetter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webf\TemporalBundle\Worker\ActivityResetter\DoctrineActivityResetter;

// Helpers return mocks so that each test only sets the expectations it is about.
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(DoctrineActivityResetter::class)]
final class DoctrineActivityResetterTest extends TestCase
{
    public function test_open_entity_managers_with_a_working_connection_are_only_cleared(): void
    {
        $connection = $this->createConnection();
        $connection->expects($this->once())->method('executeQuery')->with('SELECT 1');
        $connection->expects($this->never())->method('close');

        $manager = $this->createEntityManager($connection, isOpen: true);
        $manager->expects($this->once())->method('clear');

        $registry = $this->createRegistry(['default' => $manager]);
        $registry->expects($this->never())->method('resetManager');

        (new DoctrineActivityResetter($registry))->reset();
    }

    public function test_connections_that_are_not_open_are_not_pinged(): void
    {
        $connection = $this->createConnection(isConnected: false);
        $connection->expects($this->never())->method('executeQuery');
        $connection->expects($this->never())->method('close');

        $registry = $this->createRegistry(['default' => $this->createEntityManager($connection, isOpen: true)]);

        (new DoctrineActivityResetter($registry))->reset();
    }

    public function test_lost_connections_are_reconnected(): void
    {
        $events = [];
        $connection = $this->createConnection();
        $connection
            ->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function () use (&$events): Result {
                $events[] = 'query';
                if (1 === count($events)) {
                    throw $this->createDbalException();
                }

                return $this->createStub(Result::class);
            });
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'close';
            });

        $registry = $this->createRegistry(['default' => $this->createEntityManager($connection, isOpen: true)]);

        (new DoctrineActivityResetter($registry))->reset();

        static::assertSame(['query', 'close', 'query'], $events);
    }

    public function test_connections_left_in_a_transaction_are_closed_instead_of_pinged(): void
    {
        $connection = $this->createConnection();
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('executeQuery');

        $registry = $this->createRegistry(['default' => $this->createEntityManager($connection, isOpen: true)]);

        (new DoctrineActivityResetter($registry))->reset();
    }

    public function test_exception_is_thrown_when_the_connection_cannot_be_reestablished(): void
    {
        $exception = $this->createDbalException();

        $connection = $this->createConnection();
        $connection->method('executeQuery')->willThrowException($exception);

        $registry = $this->createRegistry(['default' => $this->createEntityManager($connection, isOpen: true)]);

        $this->expectExceptionObject($exception);

        (new DoctrineActivityResetter($registry))->reset();
    }

    public function test_closed_entity_managers_are_reset(): void
    {
        $registry = $this->createRegistry([
            'closed' => $this->createEntityManager($this->createConnection(), isOpen: false),
            'open' => $this->createEntityManager($this->createConnection(), isOpen: true),
        ]);
        $registry->expects($this->once())->method('resetManager')->with('closed');

        (new DoctrineActivityResetter($registry))->reset();
    }

    public function test_other_object_managers_are_only_cleared(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects($this->once())->method('clear');

        $registry = $this->createRegistry(['default' => $manager]);
        $registry->expects($this->never())->method('resetManager');

        (new DoctrineActivityResetter($registry))->reset();
    }

    private function createDbalException(): DBALException
    {
        // A stub rather than an anonymous class because `Doctrine\DBAL\Exception` is a class in DBAL 3 and an interface in
        // DBAL 4.
        return $this->createStub(DBALException::class);
    }

    private function createConnection(bool $isConnected = true): Connection&MockObject
    {
        // A stub rather than a concrete platform because SQLitePlatform is named SqlitePlatform before DBAL 3.7, which
        // the PSR-4 autoloader cannot find on case-sensitive filesystems.
        $platform = $this->createConfiguredStub(AbstractPlatform::class, ['getDummySelectSQL' => 'SELECT 1']);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('isConnected')->willReturn($isConnected);

        return $connection;
    }

    private function createEntityManager(Connection $connection, bool $isOpen): EntityManagerInterface&MockObject
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn($isOpen);

        return $manager;
    }

    /**
     * @param array<string, ObjectManager> $managers
     */
    private function createRegistry(array $managers): ManagerRegistry&MockObject
    {
        $registry = $this->createMock(ManagerRegistry::class);
        // Keys are the manager names, values are their service ids.
        $registry
            ->method('getManagerNames')
            ->willReturn(array_combine(
                array_keys($managers),
                array_map(static fn(string $name): string => 'doctrine.orm.' . $name, array_keys($managers)),
            ));
        $registry->method('getManager')->willReturnCallback(static fn(string $name): ObjectManager => $managers[$name]);

        return $registry;
    }
}
