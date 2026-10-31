<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Worker\ActivityResetter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Webf\TemporalBundle\Worker\ActivityResetterInterface;

/**
 * Clears every Doctrine manager, rolls back transactions left open, reconnects lost database connections and resets closed
 * entity managers. Connections that are not open are left closed.
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

        $this->resetConnection($manager->getConnection());

        if (!$manager->isOpen()) {
            $this->managerRegistry->resetManager($name);
        }
    }

    /**
     * @throws DBALException
     */
    private function resetConnection(Connection $connection): void
    {
        // An activity that failed in the middle of a transaction leaves it open, so the next activities would write into a
        // transaction that nobody commits. Closing drops every nesting level at once and makes the server roll back. A
        // `rollBack()` would only undo one level, and it would throw if the connection is already lost. No ping is needed
        // after it because the next query opens a new connection.
        if ($connection->isTransactionActive()) {
            $connection->close();

            return;
        }

        // A connection that is not open is not pinged, like Messenger does. Pinging it would open a connection for
        // activities that do not use the database, and make all of them fail while the database is down.
        if (!$connection->isConnected()) {
            return;
        }

        try {
            $this->ping($connection);
        } catch (DBALException) {
            // The server may have closed the connection after a long idle time, the next query reconnects.
            $connection->close();
            $this->ping($connection);
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
