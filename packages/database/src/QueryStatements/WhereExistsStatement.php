<?php

namespace Tempest\Database\QueryStatements;

use Tempest\Database\Builder\WhereOperator;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatement;
use Tempest\Support\Arr\ImmutableArray;

use function Tempest\Support\str;

final readonly class WhereExistsStatement implements QueryStatement
{
    public function __construct(
        public string $relatedTable,
        public string $relatedModelName,
        public string $condition,
        public ?JoinStatement $joinStatement = null,
        private ImmutableArray $innerWheres = new ImmutableArray(),
        private bool $negate = false,
        private bool $useCount = false,
        private WhereOperator $operator = WhereOperator::GREATER_THAN_OR_EQUAL,
        private int $count = 1,
        public ?self $targetExists = null,
        private bool $materialize = false,
    ) {}

    public function compile(DatabaseDialect $dialect): string
    {
        if ($this->useCount) {
            $subquery = $this->compileSubquery($dialect, 'COUNT(*)');
            $statement = "({$subquery}) {$this->operator->value} {$this->count}";
        } else {
            $keyword = $this->negate ? 'NOT EXISTS' : 'EXISTS';
            $statement = "{$keyword} ({$this->compileSubquery($dialect, '1')})";
        }

        return match ($dialect) {
            DatabaseDialect::POSTGRESQL => str_replace('`', '"', $statement),
            default => $statement,
        };
    }

    private function compileSubquery(DatabaseDialect $dialect, string $projection): string
    {
        $whereClause = str(string: $this->condition);

        if ($this->targetExists instanceof WhereExistsStatement) {
            // Keep the target table out of the scope that references the outer owner.
            $target = new self(
                relatedTable: $this->targetExists->relatedTable,
                relatedModelName: $this->targetExists->relatedModelName,
                condition: $this->targetExists->condition,
                innerWheres: new ImmutableArray([new WhereGroupStatement($this->innerWheres)]),
                materialize: $this->targetExists->materialize,
            );

            if ($this->useCount) {
                $projection = "COALESCE(SUM(({$target->compileSubquery($dialect, 'COUNT(*)')})), 0)";
            } else {
                $whereClause = $whereClause->append(suffix: ' AND ' . $target->compile($dialect));
            }
        } elseif ($this->innerWheres->isNotEmpty()) {
            $compiled = $this->innerWheres
                ->map(map: fn (
                    QueryStatement $where,
                ) => $where->compile(dialect: $dialect))
                ->filter(
                    filter: fn (
                        string $compiled,
                    ) => $compiled !== '',
                )
                ->implode(glue: ' ')
                ->toString();

            if ($compiled !== '') {
                $whereClause = $whereClause->append(suffix: " AND {$compiled}");
            }
        }

        $fromClause = $this->relatedTable;

        if ($this->materialize && $dialect === DatabaseDialect::MYSQL) {
            $alias = '`target_table`';
            $fromClause = sprintf(
                '(SELECT /*+ NO_MERGE() */ * FROM %s) AS %s',
                $this->relatedTable,
                $alias,
            );
            $whereClause = $whereClause->replace(
                search: $this->relatedTable . '.',
                replace: $alias . '.',
            );
        }

        if ($this->joinStatement instanceof JoinStatement) {
            $fromClause .= ' ' . $this->joinStatement->compile(dialect: $dialect);
        }

        return "SELECT {$projection} FROM {$fromClause} WHERE {$whereClause}";
    }
}
