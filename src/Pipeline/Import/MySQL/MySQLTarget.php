<?php

/**
 * This file contains the MySQLTarget class.
 *
 * SPDX-FileCopyrightText: Copyright 2025 Framna Netherlands B.V., Zwolle, The Netherlands
 * SPDX-License-Identifier: MIT
 */

namespace Pipeline\Import\MySQL;

use Lunr\Gravity\Exceptions\QueryException;
use Lunr\Gravity\MySQL\MySQLAccessObject;
use Lunr\Gravity\MySQL\MySQLConnection;
use Lunr\Gravity\MySQL\MySQLDMLQueryBuilder;
use Lunr\Gravity\MySQL\MySQLQueryEscaper;
use Pipeline\Common\Node;
use Pipeline\Import\ContentRangeInterface;
use Pipeline\Import\DataDiffCategory;
use Pipeline\Import\Exceptions\DatabaseException;
use Pipeline\Import\ImportTargetInterface;
use Pipeline\Import\MySQL\Ranges\IdentifierRange;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * MySQL as import target.
 *
 * @phpstan-import-type ProcessedItem from Node
 * @phpstan-import-type SplitUpdateData from ImportTargetInterface
 * @phpstan-import-type UniqueKey from ImportTargetInterface
 */
class MySQLTarget extends MySQLAccessObject implements ImportTargetInterface
{

    /**
     * Define the table for data import
     * @var string
     */
    protected string $table;

    /**
     * Active shared query builder.
     * @var MySQLDMLQueryBuilder|null
     */
    protected MySQLDMLQueryBuilder|null $builder;

    /**
     * Keys to identify data by.
     * @var string[]
     */
    protected array $identifierKeys;

    /**
     * Upsert batch size
     * @var int
     */
    protected int $batchSize;

    /**
     * Well-Known Text (WKT) types.
     * @var string[]
     */
    protected const WKT_TYPES = [
        'POINT',
        'LINESTRING',
        'POLYGON',
        'MULTIPOINT',
        'MULTILINESTRING',
        'MULTIPOLYGON',
        'GEOMETRYCOLLECTION',
        'GEOMETRY',
    ];

    /**
     * Constructor.
     *
     * @param MySQLConnection $connection Shared instance of a database connection class
     * @param LoggerInterface $logger     Shared instance of a Logger class
     *
     * @return void
     */
    public function __construct(MySQLConnection $connection, LoggerInterface $logger)
    {
        parent::__construct($connection, $logger);

        $this->batchSize = 50_000;
        $this->builder   = NULL;
    }

    /**
     * Destructor.
     *
     * @return void
     */
    public function __destruct()
    {
        parent::__destruct();

        $this->builder = NULL;
    }

    /**
     * Set target to import data to.
     *
     * @param string $target Target name
     *
     * @return void
     */
    public function setTarget(string $target): void
    {
        $this->table = $target;
    }

    /**
     * Set batch size for the upsert
     *
     * @param int $size Upsert batch size
     *
     * @return void
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = $size;
    }

    /**
     * Get the currently active query builder.
     *
     * @return MySQLDMLQueryBuilder|null Query Builder object, or NULL is none is active
     */
    public function getQueryBuilder(): MySQLDMLQueryBuilder|null
    {
        return $this->builder;
    }

    /**
     * Get the query escaper.
     *
     * @return MySQLQueryEscaper Query escaper object
     */
    public function getQueryEscaper(): MySQLQueryEscaper
    {
        return $this->escaper;
    }

    /**
     * Verify the table to import to is set.
     *
     * @return void
     */
    protected function verifyTable(): void
    {
        if (!isset($this->table))
        {
            throw new UnexpectedValueException('No table defined to import to!');
        }
    }

    /**
     * Get the keys that form the identifier.
     *
     * @return string[] List of keys
     */
    public function getIdentifierKeys(): array
    {
        $this->verifyTable();

        $query  = 'SHOW COLUMNS FROM ' . $this->table . ' WHERE `Key` = \'PRI\'';
        $result = $this->db->query($query);

        try
        {
            /**
             * 'Field' will always return a list of valid field names
             * @var string[] $list
             */
            $list = $this->result_column($result, 'Field');
        }
        catch (QueryException $e)
        {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        $this->identifierKeys = $list;

        return $this->identifierKeys;
    }

    /**
     * Get the keys that form the age/version information.
     *
     * @return string[] List of keys
     */
    public function getTimeKeys(): array
    {
        $this->verifyTable();

        $result = $this->db->query('SHOW FULL COLUMNS FROM ' . $this->table . ' WHERE `Comment` = \'TIME_KEY\'');

        try
        {
            /**
             * 'Field' will always return a list of valid field names
             * @var string[] $list
             */
            $list = $this->result_column($result, 'Field');
        }
        catch (QueryException $e)
        {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        return $list;
    }

    /**
     * Get the keys for any present unique constraints.
     *
     * @return UniqueKey[] List of indexes and their keys
     */
    public function getUniqueKeys(): array
    {
        $this->verifyTable();

        $builder = $this->db->get_new_dml_query_builder_object(FALSE);

        $builder->select('`k`.`CONSTRAINT_NAME` AS `name`, GROUP_CONCAT(`k`.`COLUMN_NAME`) AS `keys`')
                ->from($this->escaper->table('information_schema.KEY_COLUMN_USAGE', 'k'))
                ->join($this->escaper->table('information_schema.TABLE_CONSTRAINTS', 't'))
                ->on($this->escaper->column('k.CONSTRAINT_SCHEMA'), $this->escaper->column('t.CONSTRAINT_SCHEMA'))
                ->on($this->escaper->column('k.TABLE_NAME'), $this->escaper->column('t.TABLE_NAME'))
                ->on($this->escaper->column('k.CONSTRAINT_NAME'), $this->escaper->column('t.CONSTRAINT_NAME'))
                ->join($this->escaper->table('information_schema.COLUMNS', 'c'))
                ->on($this->escaper->column('k.CONSTRAINT_SCHEMA'), $this->escaper->column('c.TABLE_SCHEMA'))
                ->on($this->escaper->column('k.TABLE_NAME'), $this->escaper->column('c.TABLE_NAME'))
                ->on($this->escaper->column('k.COLUMN_NAME'), $this->escaper->column('c.COLUMN_NAME'))
                ->where($this->escaper->column('k.CONSTRAINT_SCHEMA'), $this->escaper->value($this->db->get_database()))
                ->where($this->escaper->column('k.TABLE_NAME'), $this->escaper->value($this->table))
                ->where($this->escaper->column('t.CONSTRAINT_TYPE'), $this->escaper->value('UNIQUE'))
                ->where($this->escaper->column('c.EXTRA'), $this->escaper->value('STORED GENERATED'), '!=')
                ->group_by($this->escaper->column('k.CONSTRAINT_NAME'));

        $result = $this->db->query($builder->get_select_query());

        try
        {
            /** @var list<array{name: string, keys: string}> $keys */
            $keys = $this->result_array($result);
        }
        catch (QueryException $e)
        {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        foreach ($keys as &$set)
        {
            $set['keys'] = explode(',', $set['keys']);
        }

        unset($set);

        /** @var UniqueKey[] $keys */
        return $keys;
    }

    /**
     * Update information.
     *
     * @param SplitUpdateData         $data   New information
     * @param ContentRangeInterface[] $ranges Data subset specifiers
     *
     * @return int Number of affected items
     */
    public function updateData(array $data, array $ranges = []): int
    {
        $this->verifyTable();

        $this->db->begin_transaction();

        $merged = array_merge($data[DataDiffCategory::New->value], $data[DataDiffCategory::Updated->value]);

        unset($data[DataDiffCategory::New->value], $data[DataDiffCategory::Updated->value]);

        try
        {
            $replaced = $this->replaceData($merged);
            $deleted  = $this->deleteData($data[DataDiffCategory::Obsolete->value], $ranges);
        }
        catch (QueryException $e)
        {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        $this->db->end_transaction();

        return $replaced + $deleted;
    }

    /**
     * Escape a data item.
     *
     * @param ProcessedItem $item Data item
     *
     * @return ProcessedItem Escaped data item
     */
    protected function escapeItem(array $item): array
    {
        $escapedItem = [];

        foreach ($item as $key => $value)
        {
            switch (gettype($value))
            {
                case 'integer':
                    $escapedItem[$key] = $this->escaper->intvalue($value);
                    break;
                case 'double':
                    $escapedItem[$key] = $this->escaper->floatvalue($value);
                    break;
                case 'boolean':
                    $escapedItem[$key] = $this->escaper->intvalue($value);
                    break;
                case 'NULL':
                    $escapedItem[$key] = NULL;
                    break;
                default:
                    foreach (static::WKT_TYPES as &$shape)
                    {
                        if (strpos($value, $shape . '(') === 0)
                        {
                            $escapedItem[$key] = $this->escaper->geovalue($value);
                            break 2;
                        }
                    }

                    unset($shape);

                    $escapedItem[$key] = $this->escaper->value($value);
                    break;
            }
        }

        return $escapedItem;
    }

    /**
     * Change the select columns to convert a certain type of datatype
     *
     * @param string[] $columns Array of column names to selects
     *
     * @return string[] Array of columns
     */
    protected function escapeColumns(array $columns): array
    {
        /** @var array<string, array{Field: string, Type: string}> $result */
        $result = $this->indexed_result_array($this->db->query('SHOW FIELDS FROM ' . $this->table), 'Field');

        $escapedColumns = [];

        foreach ($columns as &$column)
        {
            if (!array_key_exists($column, $result))
            {
                continue;
            }

            $escapedColumn = $this->escaper->result_column($column);

            if (in_array(strtoupper($result[$column]['Type']), static::WKT_TYPES))
            {
                $escapedColumn = 'ST_AsText(' . $escapedColumn . ') AS ' . $escapedColumn;
            }

            $escapedColumns[] = $escapedColumn;
        }

        unset($column);

        return $escapedColumns;
    }

    /**
     * Get current data.
     *
     * @param string[]|null           $fields Array of field names to select
     * @param ContentRangeInterface[] $ranges Data subset specifiers
     *
     * @return ProcessedItem[] Array of items
     */
    public function getData(?array $fields = NULL, array $ranges = []): array
    {
        $this->verifyTable();

        $this->builder = $this->db->get_new_dml_query_builder_object(FALSE);

        try
        {
            if (!is_null($fields))
            {
                $this->builder->select(implode(',', $this->escapeColumns($fields)));
            }
        }
        catch (QueryException $e)
        {
            $this->builder = NULL;

            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        $this->builder->from($this->escaper->table($this->table));

        foreach ($ranges as $range)
        {
            if ($range->isEmpty() === TRUE)
            {
                continue;
            }

            $this->builder->start_where_group();

            $range->apply();

            $this->builder->end_where_group();

            // overwrite potential dangling or
            $this->builder->and();
        }

        $query = $this->db->query($this->builder->get_select_query());

        $this->builder = NULL;

        try
        {
            return $this->result_array($query);
        }
        catch (QueryException $e)
        {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Insert new and update existing information.
     *
     * @param ProcessedItem[] $data New and changed information
     *
     * @return int Number of affected items
     */
    protected function replaceData(array &$data): int
    {
        if (empty($data))
        {
            return 0;
        }

        $columnCount = count($data[0]);
        $columns     = array_keys($data[0]);
        $mismatch    = FALSE;

        $escaped = [];
        $index   = 0;

        // Memory efficient foreach
        while ($item = array_shift($data))
        {
            $index++;
            $batch = (int) ($index / $this->batchSize);

            if ($index % $this->batchSize === 0)
            {
                $batch -= 1;
            }

            $escaped[$batch][] = $this->escapeItem($item);

            $itemColumnCount = count($item);
            $itemColumns     = array_keys($item);

            if ($itemColumns == $columns)
            {
                continue;
            }

            $mismatch = TRUE;

            if ($columnCount > $itemColumnCount)
            {
                $columnDiff = json_encode(array_values(array_diff($columns, $itemColumns)));
            }
            else
            {
                $columnDiff = json_encode(array_values(array_diff($itemColumns, $columns)));
            }

            $this->logger->warning("Column mismatch for item ($columnCount vs $itemColumnCount): $columnDiff");
        }

        if ($mismatch === TRUE)
        {
            throw new RuntimeException('Items do not have uniform structure!');
        }

        $columns = array_keys($escaped[0][0]);

        $updateColumns = array_map(function ($a) { return "`$a` = `new`.`$a`"; }, $columns);

        $rows = 0;

        foreach ($escaped as $batch)
        {
            $builder = $this->db->get_new_dml_query_builder_object(FALSE);

            $builder->into($this->escaper->table($this->table))
                    ->column_names(array_map(fn($column) => $this->escaper->column($column), $columns))
                    ->values($batch)
                    ->row_alias($this->escaper->row_alias('new'))
                    ->on_duplicate_key_update(implode(', ', $updateColumns));

            $result = $this->db->query($builder->get_insert_query());

            $result = $this->result_retry($result);

            $rows += (int) $this->get_affected_rows($result);
        }

        return $rows;
    }

    /**
     * Deletes the given data items.
     *
     * @param ProcessedItem[]         $data   New information
     * @param ContentRangeInterface[] $ranges Data subset specifiers
     *
     * @return int Number of affected items
     */
    protected function deleteData(array &$data, array $ranges = []): int
    {
        if (empty($data))
        {
            return 0;
        }

        $this->builder = $this->db->get_new_dml_query_builder_object(FALSE);

        $this->builder->from($this->escaper->table($this->table));

        $this->builder->start_where_group();

        while ($item = array_shift($data))
        {
            $this->builder->start_where_group();
            foreach ($this->identifierKeys as $identifier)
            {
                if (is_bool($item[$identifier]))
                {
                    $this->builder->where($this->escaper->column($identifier), (string) $this->escaper->intvalue($item[$identifier]));
                }
                else
                {
                    $this->builder->where($this->escaper->column($identifier), $this->escaper->value($item[$identifier]));
                }
            }

            $this->builder->end_where_group()
                          ->or();
        }

        $this->builder->end_where_group()
                      ->and();

        foreach ($ranges as $range)
        {
            if ($range->isEmpty() === TRUE)
            {
                continue;
            }

            // We already filter by identifier above, don't need to do it again
            if ($range instanceof IdentifierRange)
            {
                continue;
            }

            $this->builder->start_where_group();

            $range->apply();

            $this->builder->end_where_group();

            // overwrite potential dangling or
            $this->builder->and();
        }

        $result = $this->db->query($this->builder->get_delete_query());

        $result = $this->result_retry($result);

        $this->builder = NULL;

        return (int) $this->get_affected_rows($result);
    }

}

?>
