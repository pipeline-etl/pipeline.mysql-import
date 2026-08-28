<?php

/**
 * This file contains the MySQLTargetReplaceDataTest class.
 *
 * SPDX-FileCopyrightText: Copyright 2025 Framna Netherlands B.V., Zwolle, The Netherlands
 * SPDX-License-Identifier: MIT
 */

namespace Pipeline\Tests\Import\MySQL;

use Lunr\Gravity\Exceptions\DeadlockException;
use Lunr\Gravity\Tests\Helpers\DatabaseAccessObjectQueryTestTrait;

/**
 * This class contains the tests for the replaceData function of the MySQLTarget class.
 *
 * @covers Pipeline\Import\MySQL\MySQLTarget
 */
class MySQLTargetReplaceDataTest extends MySQLTargetTestCase
{

    use DatabaseAccessObjectQueryTestTrait;

    private const ENTITIES = [
        [
            'id'       => 'a1',
            'language' => 'en-US',
            'revision' => 3,
            'name'     => 'yeah',
            'valid'    => FALSE,
        ],
        [
            'id'       => 'b2',
            'language' => 'fr-FR',
            'revision' => 5,
            'name'     => 'he',
            'valid'    => TRUE,
        ],
        [
            'id'       => 'c3',
            'language' => 'de-DE',
            'revision' => 5,
            'name'     => 'he',
            'valid'    => FALSE,
        ],
        [
            'id'       => 'd4',
            'language' => 'nl-NL',
            'revision' => 5,
            'name'     => 'she',
            'valid'    => TRUE,
        ],
    ];

    /**
     * Unit Test Data Provider for entities data.
     *
     * @return array $entities entities data.
     */
    public static function entitiesDataProvider(): array
    {
        $entities = [];

        $entities[] = [ [ self::ENTITIES[0] ] ];
        $entities[] = [ self::ENTITIES ];

        return $entities;
    }

    /**
     * Test that replaceData() returns 0 if there is no entities to replace.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataWithNoDataReturnsZero(): void
    {
        $entities = [];

        $method = $this->getReflectionMethod('replaceData');

        $result = $method->invokeArgs($this->class, [ &$entities ]);

        $this->assertEquals(0, $result);
    }

    /**
     * Test that replaceData() constructs a correct SQL query.
     *
     * @param array $entities Parsed entities
     *
     * @dataProvider entitiesDataProvider
     * @covers       Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataConstructsCorrectQuery(array $entities): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->expectQuerySuccess();

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(0);

        if (count($entities) === 1)
        {
            $expectedFile = TEST_STATICS . '/sql/MySQLTarget/replaceData.sql';
        }
        else
        {
            $expectedFile = TEST_STATICS . '/sql/MySQLTarget/replaceData_multiple.sql';
        }

        $method = $this->getReflectionMethod('replaceData');

        $method->invokeArgs($this->class, [ &$entities ]);

        $sql = $this->realBuilder->get_insert_query();

        $this->assertSqlStringEqualsSqlFile($expectedFile, $sql);
    }

    /**
     * Test that replaceData() throws an exception in case of a query error.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataThrowsExceptionIfQueryFailed(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->expectQueryError();

        $context = [ 'query' => 'QUERY;', 'error' => 'Error!' ];

        $this->logger->expects($this->once())
                     ->method('error')
                     ->with('{query}; failed with error: {error}', $context);

        $this->expectException('Lunr\Gravity\Exceptions\QueryException');
        $this->expectExceptionMessage('Database query error!');

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $method->invokeArgs($this->class, [ &$entities ]);
    }

    /**
     * Test that replaceData() throws an exception in case of a query deadlock.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataThrowsExceptionOnDeadlock(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->db->shouldReceive('get_new_dml_query_builder_object')
                 ->once()
                 ->with(FALSE)
                 ->andReturn($this->realBuilder);

        $this->db->shouldReceive('query')
                 ->zeroOrMoreTimes()
                 ->andReturn($this->result);

        $this->result->shouldReceive('warnings')
                     ->once()
                     ->andReturn(NULL);

        $this->result->shouldReceive('has_failed')
                     ->once()
                     ->andReturn(TRUE);

        $this->result->shouldReceive('error_number')
                     ->once()
                     ->andReturn(1);

        $this->result->shouldReceive('error_message')
                     ->twice()
                     ->andReturn('Error!');

        $this->result->shouldReceive('query')
                     ->times(7)
                     ->andReturn('QUERY');

        $this->result->shouldReceive('has_deadlock')
                     ->times(6)
                     ->andReturn(TRUE);

        $this->result->shouldReceive('has_lock_timeout')
                     ->zeroOrMoreTimes()
                     ->andReturn(FALSE);

        $context = [ 'query' => 'QUERY', 'error' => 'Error!' ];

        $this->logger->expects($this->once())
                     ->method('error')
                     ->with('{query}; failed with error: {error}', $context);

        $this->expectException(DeadlockException::class);
        $this->expectExceptionMessage('Database query deadlock!');

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $method->invokeArgs($this->class, [ &$entities ]);
    }

    /**
     * Test that replaceData() throws an exception in case the column count of items mismatches.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataThrowsExceptionIfColumnCountHigher(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->db->shouldReceive('query')
                 ->never();

        $this->logger->expects($this->once())
                     ->method('warning')
                     ->with('Column mismatch for item (5 vs 6): ["content"]');

        $data = self::ENTITIES;

        $data[1]['content'] = 'foo';

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Items do not have uniform structure!');

        $method = $this->getReflectionMethod('replaceData');

        $method->invokeArgs($this->class, [ &$data ]);
    }

    /**
     * Test that replaceData() throws an exception in case the column count of items mismatches.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataThrowsExceptionIfColumnCountLower(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->db->shouldReceive('query')
                 ->never();

        $this->logger->expects($this->exactly(3))
                     ->method('warning')
                     ->with('Column mismatch for item (6 vs 5): ["content"]');

        $data = self::ENTITIES;

        $data[0]['content'] = 'foo';

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Items do not have uniform structure!');

        $method = $this->getReflectionMethod('replaceData');

        $method->invokeArgs($this->class, [ &$data ]);
    }

    /**
     * Test that replaceData() throws an exception in case the column count of items mismatches.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataThrowsExceptionIfColumnOrderDifferent(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->db->shouldReceive('query')
                 ->never();

        $this->logger->expects($this->once())
                     ->method('warning')
                     ->with('Column mismatch for item (5 vs 5): []');

        $data = [
            [
                'id'       => 'a1',
                'language' => 'en-US',
                'revision' => 3,
                'name'     => 'yeah',
                'valid'    => FALSE,
            ],
            [
                'id'       => 'b2',
                'revision' => 5,
                'language' => 'fr-FR',
                'name'     => 'he',
                'valid'    => TRUE,
            ],
        ];

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Items do not have uniform structure!');

        $method = $this->getReflectionMethod('replaceData');

        $method->invokeArgs($this->class, [ &$data ]);
    }

    /**
     * Test that replaceData() returns the number of affected rows on success.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataReturnsNumberOfAffectedRowsOnSuccessfulQuery(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->expectQuerySuccess();

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(10);

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $result = $method->invokeArgs($this->class, [ &$entities ]);

        $this->assertEquals(10, $result);
    }

    /**
     * Test that replaceData() returns the number of affected rows on success.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataReturnsNumberOfAffectedRowsAfterDeadlockRetry(): void
    {
        $this->setReflectionPropertyValue('table', 'table');

        $this->expectQuerySuccessAfterRetry();

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(10);

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $result = $method->invokeArgs($this->class, [ &$entities ]);

        $this->assertEquals(10, $result);
    }

    /**
     * Test that replaceData() returns the number of affected rows on success.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataReturnsNumberOfAffectedRowsFromMultipleBatches(): void
    {
        $this->setReflectionPropertyValue('table', 'table');
        $this->setReflectionPropertyValue('batchSize', 2);

        $this->db->shouldReceive('get_new_dml_query_builder_object')
                 ->twice()
                 ->with(FALSE)
                 ->andReturn($this->realBuilder);

        $this->db->shouldReceive('query')
                 ->twice()
                 ->andReturn($this->result);

        $this->result->shouldReceive('warnings')
                     ->twice()
                     ->andReturn(NULL);

        $this->result->shouldReceive('has_failed')
                     ->twice()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('has_deadlock')
                     ->twice()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('has_lock_timeout')
                     ->twice()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(2);

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(1);

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $result = $method->invokeArgs($this->class, [ &$entities ]);

        $this->assertEquals(3, $result);
    }

    /**
     * Test that replaceData() returns the number of affected rows on success.
     *
     * @covers Pipeline\Import\MySQL\MySQLTarget::replaceData
     */
    public function testReplaceDataReturnsNumberOfAffectedRowsFromMultipleBatchesWithRetry(): void
    {
        $this->setReflectionPropertyValue('table', 'table');
        $this->setReflectionPropertyValue('batchSize', 2);

        $this->db->shouldReceive('get_new_dml_query_builder_object')
                 ->twice()
                 ->with(FALSE)
                 ->andReturn($this->realBuilder);

        $this->db->shouldReceive('query')
                 ->times(4)
                 ->andReturn($this->result);

        $this->result->shouldReceive('warnings')
                     ->twice()
                     ->andReturn(NULL);

        $this->result->shouldReceive('has_failed')
                     ->twice()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('has_deadlock')
                     ->once()
                     ->andReturn(TRUE);

        $this->result->shouldReceive('has_deadlock')
                     ->once()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('has_deadlock')
                     ->once()
                     ->andReturn(TRUE);

        $this->result->shouldReceive('has_deadlock')
                     ->once()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('has_lock_timeout')
                     ->zeroOrMoreTimes()
                     ->andReturn(FALSE);

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(2);

        $this->result->shouldReceive('affected_rows')
                     ->once()
                     ->andReturn(1);

        $this->result->shouldReceive('query')
                     ->times(2)
                     ->andReturn('QUERY');

        $method = $this->getReflectionMethod('replaceData');

        $entities = self::ENTITIES;

        $result = $method->invokeArgs($this->class, [ &$entities ]);

        $this->assertEquals(3, $result);
    }

}

?>
