<?php

namespace Mautic\CoreBundle\Doctrine\GeneratedColumn;

interface GeneratedColumnInterface
{
    /**
     * @return string
     */
    public function getTableName();

    /**
     * @return string
     */
    public function getColumnName();

    /**
     * @param string $indexColumn
     */
    public function addIndexColumn($indexColumn);

    /**
     * If set then the line chart queries will use this column for the time unit instead of the original.
     *
     * @param string $originalDateColumn
     * @param string $timeUnit
     */
    public function setOriginalDateColumn($originalDateColumn, $timeUnit);

    /**
     * @return string
     */
    public function getOriginalDateColumn();

    /**
     * @return string
     */
    public function getTimeUnit();

    /**
     * @return string
     */
    public function getAlterTableSql();

    public function getAddColumnSql(): string;

    public function getAddIndexSql(): string;

    /**
     * @return string
     */
    public function getColumnDefinition();

    /**
     * @return array
     */
    public function getIndexColumns();

    /**
     * @return string
     */
    public function getIndexName();

    public function getFilterDateColumn(): ?string;
}
