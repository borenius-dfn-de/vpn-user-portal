<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Exception\MigrationException;
use Vpn\Portal\Migration;

/**
 * @internal
 * @coversNothing
 */
final class MigrationTest extends TestCase
{
    private string $schemaDir;
    private PDO $dbh;

    protected function setUp(): void
    {
        $this->schemaDir = sprintf('%s/schema', __DIR__);
        $this->dbh = new PDO('sqlite::memory:');
    }

    public function testInit(): void
    {
        Migration::run($this->dbh, $this->schemaDir, '2018010101', true);
        static::assertSame('2018010101', Migration::getCurrentVersion($this->dbh));
    }

    public function testInitNotAllowed(): void
    {
        static::expectException(MigrationException::class);
        static::expectExceptionMessage('server configuration only allows manual database initialization/migration');
        Migration::run($this->dbh, $this->schemaDir, '2018010101', false);
    }

    public function testSimpleMigration(): void
    {
        Migration::run($this->dbh, $this->schemaDir, '2018010101', true);
        static::assertSame('2018010101', Migration::getCurrentVersion($this->dbh));
        $this->dbh->exec('INSERT INTO foo (a) VALUES(3)');

        Migration::run($this->dbh, $this->schemaDir, '2018010102', true);
        static::assertSame('2018010102', Migration::getCurrentVersion($this->dbh));
        $sth = $this->dbh->query('SELECT * FROM foo');
        static::assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testMultiMigration(): void
    {
        Migration::run($this->dbh, $this->schemaDir, '2018010101', true);
        static::assertSame('2018010101', Migration::getCurrentVersion($this->dbh));
        $this->dbh->exec('INSERT INTO foo (a) VALUES(3)');
        Migration::run($this->dbh, $this->schemaDir, '2018010103', true);
        static::assertSame('2018010103', Migration::getCurrentVersion($this->dbh));
        $sth = $this->dbh->query('SELECT * FROM foo');
        static::assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                    'c' => null,
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testNoVersion(): void
    {
        // we have a database without versioning, but we want to bring it
        // under version control, we can't run init as that would install the
        // version table...
        $this->dbh->exec('CREATE TABLE foo (a INTEGER NOT NULL)');
        $this->dbh->exec('INSERT INTO foo (a) VALUES(3)');
        static::assertNull(Migration::getCurrentVersion($this->dbh));
        Migration::run($this->dbh, $this->schemaDir, '2018010101', true);
        static::assertSame('2018010101', Migration::getCurrentVersion($this->dbh));
        $sth = $this->dbh->query('SELECT * FROM foo');
        static::assertSame(
            [
                [
                    'a' => '3',
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testFailingUpdate(): void
    {
        Migration::run($this->dbh, $this->schemaDir, '2018020201', true);
        static::assertSame('2018020201', Migration::getCurrentVersion($this->dbh));

        try {
            Migration::run($this->dbh, $this->schemaDir, '2018020202', true);
            static::fail();
        } catch (PDOException $e) {
            static::assertSame('2018020201', Migration::getCurrentVersion($this->dbh));
        }
    }

    public function testWithForeignKeys(): void
    {
        $this->dbh->exec('PRAGMA foreign_keys = ON');
        Migration::run($this->dbh, $this->schemaDir, '2018010101', true);
        static::assertSame('2018010101', Migration::getCurrentVersion($this->dbh));
        $this->dbh->exec('INSERT INTO foo (a) VALUES(3)');

        Migration::run($this->dbh, $this->schemaDir, '2018010102', true);
        static::assertSame('2018010102', Migration::getCurrentVersion($this->dbh));
        $sth = $this->dbh->query('SELECT * FROM foo');
        static::assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
        // make sure FK are back on again
        $sth = $this->dbh->query('PRAGMA foreign_keys');
        static::assertSame('1', $sth->fetchColumn(0));
        $sth->closeCursor();
    }
}
