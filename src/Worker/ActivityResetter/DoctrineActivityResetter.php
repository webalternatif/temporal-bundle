<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Worker\ActivityResetter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Webf\TemporalBundle\Worker\ActivityResetterInterface;

/**
 * Clears every Doctrine manager, reconnects lost database connections and resets closed entity managers.
 *
 * This is the same as what Messenger does between two messages with its `doctrine_ping_connection` and
 * `doctrine_clear_entity_manager` middlewares.
 */
final readonly class DoctrineActivityResetter implements ActivityResetterInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
    ) {}

    /**
     * @throws DBALException when the database is still unreachable after a reconnection
     */
    #[\Override]
    public function reset(): void
    {
        foreach (array_keys($this->managerRegistry->getManagerNames()) as $name) {
            $this->resetManager($name);
        }
    }

    /**
     * @throws DBALException
     */
    private function resetManager(string $name): void
    {
        $manager = $this->managerRegistry->getManager($name);
        $manager->clear();

        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $connection = $manager->getConnection();

        try {
            $this->ping($connection);
        } catch (DBALException) {
            // The server may have closed the connection after a long idle time, the next query reconnects.
            $connection->close();
            $this->ping($connection);
        }

        if (!$manager->isOpen()) {
            $this->managerRegistry->resetManager($name);
        }
    }

    /**
     * @throws DBALException
     */
    private function ping(Connection $connection): void
    {
        $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
    }
}
