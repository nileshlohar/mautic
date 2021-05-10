<?php

namespace Mautic\CoreBundle\Doctrine\GeneratedColumn;

final class GeneratedColumn implements GeneratedColumnInterface
{
    /**
     * @var string
     */
    private $tableName;

    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * @var string
     */
    private $columnName;

    /**
     * @var string
     */
    private $columnType;

    /**
     * @var string
     */
    private $as;

    /**
     * @var bool
     */
    private $stored = false;

    /**
     * @var string
     */
    private $originalDateColumn;

    /**
     * @var string
     */
    private $timeUnit;

    /**
     * @var array
     */
    private $indexColumns = [];

    /**
     * @var ?string
     */
    private $filterDateColumn;

    /**
     * @param string $tableName
     * @param string $columnName
     * @param string $columnType
     * @param string $as
     */
    public function __construct($tableName, $columnName, $columnType, $as)
    {
        $this->as             = $as;
        $this->tableName      = $tableName;
        $this->indexColumns[] = $columnName;
        $this->tablePrefix    = MAUTIC_TABLE_PREFIX;
        $this->columnName     = $columnName;
        $this->columnType     = $columnType;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->tablePrefix.$this->tableName;
    }

    /**
     * @return string
     */
    public function getColumnName()
    {
        return $this->columnName;
    }

    public function setStored(bool $stored): void
    {
        $this->stored = $stored;
    }

    /**
     * @param string $indexColumn
     */
    public function addIndexColumn($indexColumn)
    {
        $this->indexColumns[] = $indexColumn;
    }

    public function prependIndexColumn(string $indexColumn): void
    {
        array_unshift($this->indexColumns, $indexColumn);
    }

    /**
     * If set then the line chart queries will use this column for the time unit instead of the original.
     *
     * @param string $originalDateColumn
     * @param string $timeUnit
     */
    public function setOriginalDateColumn($originalDateColumn, $timeUnit)
    {
        $this->originalDateColumn = $originalDateColumn;
        $this->timeUnit           = $timeUnit;
    }

    /**
     * @return string
     */
    public function getOriginalDateColumn()
    {
        return $this->originalDateColumn;
    }

    /**
     * @return string
     */
    public function getTimeUnit()
    {
        return $this->timeUnit;
    }

    /**
     * @return string
     */
    public function getAlterTableSql()
    {
        return "ALTER TABLE {$this->getTableName()} {$this->getAddColumnSql()};
            ALTER TABLE {$this->getTableName()} {$this->getAddIndexSql()}";
    }

    public function getAddColumnSql(): string
    {
        return "ADD {$this->getColumnName()} {$this->getColumnDefinition()}";
    }

    public function getAddIndexSql(): string
    {
        return "ADD INDEX `{$this->getIndexName()}`({$this->indexColumnsToString()})";
    }

    /**
     * @return string
     */
    public function getColumnDefinition()
    {
        $stored = $this->stored ? ' STORED' : '';

        return "{$this->columnType} AS ({$this->as}){$stored} COMMENT '(DC2Type:generated)'";
    }

    /**
     * @return array
     */
    public function getIndexColumns()
    {
        return $this->indexColumns;
    }

    /**
     * @return string
     */
    public function getIndexName()
    {
        return $this->tablePrefix.$this->indexColumnsToString('_');
    }

    public function getFilterDateColumn(): ?string
    {
        return $this->filterDateColumn;
    }

    public function setFilterDateColumn(?string $filterDateColumn): void
    {
        $this->filterDateColumn = $filterDateColumn;
    }

    /**
     * @param string $separator
     *
     * @return string
     */
    private function indexColumnsToString($separator = ', ')
    {
        return implode($separator, $this->indexColumns);
    }
}
