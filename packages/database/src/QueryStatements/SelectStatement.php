<?php

namespace Tempest\Database\QueryStatements;

use Tempest\Database\Builder\TableDefinition;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatement;
use Tempest\Support\Arr\ImmutableArray;

final class SelectStatement implements QueryStatement, HasWhereStatements
{
    public function __construct(
        public TableDefinition $table,
        public ImmutableArray $fields = new ImmutableArray(),
        public ImmutableArray $join = new ImmutableArray(),
        public ImmutableArray $where = new ImmutableArray(),
        public ImmutableArray $orderBy = new ImmutableArray(),
        public ImmutableArray $groupBy = new ImmutableArray(),
        public ImmutableArray $having = new ImmutableArray(),
        public ?int $limit = null,
        public ?int $offset = null,
        public ImmutableArray $raw = new ImmutableArray(),
    ) {}

    public function withJoin(JoinStatement $join): self
    {
        $clone = clone $this;

        $clone->join[] = $join;

        return $clone;
    }

    public function withFields(ImmutableArray $fields): self
    {
        $clone = clone $this;

        $clone->fields = $this->fields->append(...$fields);

        return $clone;
    }

    public function compile(DatabaseDialect $dialect): string
    {
        $columns = '*';

        if ($this->fields->isNotEmpty()) {
            $compiledColumns = [];

            foreach ($this->fields as $field) {
                if ($field instanceof FieldStatement) {
                    $compiledColumns[] = $field->compile($dialect);

                    continue;
                }

                foreach (self::splitFields((string) $field) as $splitField) {
                    $compiledColumns[] = new FieldStatement($splitField)->compile($dialect);
                }
            }

            $columns = implode(', ', $compiledColumns);
        }

        $query = [
            'SELECT ' . $columns,
            'FROM ' . $this->table,
        ];

        if ($this->join->isNotEmpty()) {
            $query[] = $this->join
                ->map(fn (JoinStatement $join) => $join->compile($dialect))
                ->implode(' ');
        }

        if ($this->where->isNotEmpty()) {
            $query[] = 'WHERE ' . $this->where
                ->map(fn (WhereStatement|WhereGroupStatement|WhereExistsStatement $where) => $where->compile($dialect))
                ->filter(fn (string $compiled) => $compiled !== '')
                ->implode(' ');
        }

        if ($this->groupBy->isNotEmpty()) {
            $query[] = 'GROUP BY ' . $this->groupBy
                ->map(fn (GroupByStatement $groupBy) => $groupBy->compile($dialect))
                ->implode(', ');
        }

        if ($this->having->isNotEmpty()) {
            $query[] = 'HAVING ' . $this->having
                ->map(fn (HavingStatement $having) => $having->compile($dialect))
                ->implode(' ');
        }

        if ($this->orderBy->isNotEmpty()) {
            $query[] = 'ORDER BY ' . $this->orderBy
                ->map(fn (OrderByStatement|RawStatement $orderBy) => $orderBy->compile($dialect))
                ->implode(', ');
        }

        if ($this->limit !== null) {
            $query[] = 'LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $query[] = 'OFFSET ' . $this->offset;
        }

        if ($this->raw->isNotEmpty()) {
            $query[] = $this->raw
                ->map(fn (RawStatement $raw) => $raw->compile($dialect))
                ->implode(' ')
                ->toString();
        }

        return implode(' ', $query);
    }

    /** @return list<string> */
    public static function splitFields(string $fields): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;

        // shortcut: handles standard quotes and parentheses, extend for dialect-specific literal syntax when needed
        for ($i = 0, $length = strlen($fields); $i < $length; $i++) {
            $character = $fields[$i];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
            } elseif (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim(substr($fields, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($fields, $start));

        return $parts;
    }

    public function __clone(): void
    {
        $this->table = clone $this->table;
        $this->fields = clone $this->fields;
        $this->join = clone $this->join;
        $this->where = clone $this->where;
        $this->orderBy = clone $this->orderBy;
        $this->groupBy = clone $this->groupBy;
        $this->having = clone $this->having;
        $this->raw = clone $this->raw;
    }
}
