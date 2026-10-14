<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\FunctionCall;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\ExplainStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\ShowStatement;
use PhpMyAdmin\SqlParser\Statements\WithStatement;
use PhpMyAdmin\SqlParser\Token;

/**
 * Accepts one read-only statement by inspecting the parser tree, then rebuilds it.
 *
 * The original text is never executed. A parse error or an unrecognized shape is rejected.
 */
final class ExplorerQueryGuard
{
    public const MAX_ROWS = 1000;

    /** @var list<string> */
    private const SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    /** @var list<string> */
    private const FUNCTIONS = [
        'SLEEP',
        'BENCHMARK',
        'GET_LOCK',
        'RELEASE_LOCK',
        'LOAD_FILE',
        'UPDATEXML',
        'EXTRACTVALUE',
        'LOAD_EXTENSION',
        'WRITEFILE',
        'READFILE',
        'GTID_SUBSET',
        'NAME_CONST',
    ];

    public function __construct(private readonly ExplorerPolicy $policy)
    {
    }

    public function prepare(string $sql, string $currentDatabase): PreparedExplorerQuery
    {
        $hash = $this->fingerprint($sql);
        try {
            return $this->parse($sql, $currentDatabase, $hash);
        } catch (ExplorerQueryRejectedException $exception) {
            if ($exception->queryHash() !== null) {
                throw $exception;
            }

            throw new ExplorerQueryRejectedException($exception->getMessage(), $exception->category(), $hash);
        }
    }

    private function parse(string $sql, string $currentDatabase, string $hash): PreparedExplorerQuery
    {
        if (trim($sql) === '' || strlen($sql) > 8000) {
            throw new ExplorerQueryRejectedException();
        }
        try {
            $parser = new Parser($sql);
        } catch (\Throwable) {
            throw new ExplorerQueryRejectedException();
        }
        if ($parser->errors !== [] || count($parser->statements) !== 1) {
            throw new ExplorerQueryRejectedException();
        }
        $statement = $parser->statements[0];
        $this->rejectUserVariables($sql);

        if ($statement instanceof SelectStatement) {
            $rowCap = $this->acceptSelect($statement, $currentDatabase, true);

            return $this->prepared('select', $statement->build(), $hash, null, null, $rowCap);
        }
        if ($statement instanceof WithStatement) {
            $rowCap = $this->acceptWith($statement, $currentDatabase, true);

            return $this->prepared('select', $statement->build(), $hash, null, null, $rowCap);
        }
        if ($statement instanceof ExplainStatement) {
            return $this->acceptExplain($statement, $currentDatabase, $hash);
        }
        if ($statement instanceof ShowStatement) {
            return $this->acceptShow($statement, $currentDatabase, $hash);
        }

        throw new ExplorerQueryRejectedException();
    }

    private function acceptWith(WithStatement $statement, string $currentDatabase, bool $cap): int
    {
        foreach ($statement->withers as $wither) {
            $body = $wither->statement;
            if (!$body instanceof Parser || $body->errors !== [] || count($body->statements) !== 1 || !$body->statements[0] instanceof SelectStatement) {
                throw new ExplorerQueryRejectedException();
            }
            $this->acceptSelect($body->statements[0], $currentDatabase, false);
        }
        $outer = $statement->cteStatementParser;
        if (!$outer instanceof Parser || $outer->errors !== [] || count($outer->statements) !== 1 || !$outer->statements[0] instanceof SelectStatement) {
            throw new ExplorerQueryRejectedException();
        }

        return $this->acceptSelect($outer->statements[0], $currentDatabase, $cap);
    }

    private function acceptSelect(SelectStatement $statement, string $currentDatabase, bool $cap): int
    {
        if ($statement->into !== null || $statement->procedure !== null || !empty($statement->index_hints) || !empty($statement->partition)) {
            throw new ExplorerQueryRejectedException();
        }
        $this->rejectOptions($statement->options);
        $this->rejectOptions($statement->end_options);
        $rowCap = $cap ? $this->cap($statement) : 0;
        $this->walk($statement->expr, $currentDatabase);
        $this->walk($statement->from, $currentDatabase);
        $this->walk($statement->join, $currentDatabase);
        $this->walk($statement->where, $currentDatabase);
        $this->walk($statement->group, $currentDatabase);
        $this->walk($statement->having, $currentDatabase);
        $this->walk($statement->order, $currentDatabase);
        foreach ($this->asList($statement->union) as $union) {
            if (!is_array($union) || !isset($union[1]) || !$union[1] instanceof SelectStatement) {
                throw new ExplorerQueryRejectedException();
            }
            $this->acceptSelect($union[1], $currentDatabase, false);
        }

        return $rowCap;
    }

    private function acceptExplain(ExplainStatement $statement, string $currentDatabase, string $hash): PreparedExplorerQuery
    {
        $alias = strtoupper($statement->statementAlias);
        if (str_contains($alias, 'ANALYZE') || $statement->connectionId !== null) {
            throw new ExplorerQueryRejectedException();
        }
        $this->rejectOptions($statement->options);
        if ($statement->explainedDatabase !== null) {
            $this->database((string) $statement->explainedDatabase, $currentDatabase);
        }
        if ($statement->bodyParser instanceof Parser) {
            if ($statement->bodyParser->errors !== [] || count($statement->bodyParser->statements) !== 1) {
                throw new ExplorerQueryRejectedException();
            }
            $body = $statement->bodyParser->statements[0];
            if ($body instanceof SelectStatement) {
                $rowCap = $this->acceptSelect($body, $currentDatabase, true);
            } elseif ($body instanceof WithStatement) {
                $rowCap = $this->acceptWith($body, $currentDatabase, true);
            } else {
                throw new ExplorerQueryRejectedException();
            }

            return $this->prepared('explain', $statement->build(), $hash, null, null, $rowCap);
        }
        if ($statement->explainedTable === null || $alias === 'EXPLAIN') {
            throw new ExplorerQueryRejectedException();
        }
        $table = $this->bare((string) $statement->explainedTable);
        $column = $statement->explainedColumn === null ? null : $this->bare((string) $statement->explainedColumn);
        $this->object($table);
        if ($column !== null) {
            $this->object($column);
        }

        return $this->prepared('describe', 'DESCRIBE', $hash, $table, $column, self::MAX_ROWS);
    }

    private function acceptShow(ShowStatement $statement, string $currentDatabase, string $hash): PreparedExplorerQuery
    {
        $words = [];
        foreach ($statement->unknown as $token) {
            if ($token->type === Token::TYPE_WHITESPACE || $token->type === Token::TYPE_COMMENT || $token->type === Token::TYPE_DELIMITER) {
                continue;
            }
            $words[] = $token;
        }
        if ($words === [] || strtoupper((string) $words[0]->token) !== 'SHOW') {
            throw new ExplorerQueryRejectedException();
        }
        array_shift($words);
        if ($words !== [] && strtoupper((string) $words[0]->token) === 'FULL') {
            array_shift($words);
        }
        if ($words === []) {
            throw new ExplorerQueryRejectedException();
        }
        $kind = strtoupper($words[0]->token);
        array_shift($words);
        if ($kind === 'TABLES') {
            $this->optionalDatabase($words, $currentDatabase);

            return $this->prepared('show_tables', 'SHOW TABLES', $hash, null, null, self::MAX_ROWS);
        }
        if ($kind === 'COLUMNS' || $kind === 'FIELDS') {
            if (count($words) < 2 || strtoupper((string) $words[0]->token) !== 'FROM') {
                throw new ExplorerQueryRejectedException();
            }
            $table = $this->bare((string) $words[1]->token);
            $this->object($table);
            $this->optionalDatabase(array_slice($words, 2), $currentDatabase);

            return $this->prepared('show_columns', 'SHOW COLUMNS', $hash, $table, null, self::MAX_ROWS);
        }

        throw new ExplorerQueryRejectedException();
    }

    /**
     * @param list<Token> $words
     */
    private function optionalDatabase(array $words, string $currentDatabase): void
    {
        if ($words === []) {
            return;
        }
        if (count($words) === 2 && strtoupper((string) $words[0]->token) === 'FROM') {
            $this->database((string) $words[1]->token, $currentDatabase);

            return;
        }

        throw new ExplorerQueryRejectedException();
    }

    private function cap(SelectStatement $statement): int
    {
        $rowCount = self::MAX_ROWS;
        $offset = 0;
        if ($statement->limit instanceof Limit) {
            $requested = (int) $statement->limit->rowCount;
            $offset = (int) $statement->limit->offset;
            if ($offset < 0 || $offset > 100000) {
                throw new ExplorerQueryRejectedException();
            }
            if ($requested > 0 && $requested < $rowCount) {
                $rowCount = $requested;
            }
        }
        $statement->limit = new Limit($rowCount + 1, $offset);

        return $rowCount;
    }

    private function walk(mixed $node, string $currentDatabase): void
    {
        if (is_array($node)) {
            foreach ($node as $item) {
                $this->walk($item, $currentDatabase);
            }

            return;
        }
        if ($node instanceof Expression) {
            $this->expression($node, $currentDatabase);

            return;
        }
        if ($node instanceof Condition) {
            $this->condition($node, $currentDatabase);

            return;
        }
        if ($node instanceof FunctionCall) {
            if (!is_string($node->name)) {
                throw new ExplorerQueryRejectedException();
            }
            $this->functionName($node->name);
            $this->walk($node->parameters, $currentDatabase);

            return;
        }
        if (is_object($node) && property_exists($node, 'expr')) {
            $this->walk($node->expr, $currentDatabase);
        }
        if (is_object($node) && property_exists($node, 'on')) {
            $this->walk($node->on, $currentDatabase);
        }
    }

    private function expression(Expression $expression, string $currentDatabase): void
    {
        if (is_string($expression->database) && $expression->database !== '') {
            $this->database($expression->database, $currentDatabase);
        }
        if (is_string($expression->table) && $expression->table !== '') {
            $this->object($this->bare($expression->table));
        }
        if (is_string($expression->column) && $expression->column !== '' && $expression->column !== '*' && !$this->quotedLiteral($expression->expr)) {
            $this->object($this->bare($expression->column));
        }
        if (is_string($expression->function) && $expression->function !== '') {
            $this->functionName($expression->function);
        } elseif ($expression->function instanceof FunctionCall) {
            $this->walk($expression->function, $currentDatabase);
        }
        if (is_string($expression->expr) && $expression->expr !== '' && $expression->expr !== '*') {
            $this->namesIn($expression->expr);
        }
        if (is_string($expression->subquery) && $expression->subquery !== '') {
            if (!in_array(strtoupper($expression->subquery), ['SELECT', 'WITH'], true)) {
                throw new ExplorerQueryRejectedException();
            }
            $this->embedded((string) $expression->expr, $currentDatabase);
        }
    }

    private function condition(Condition $condition, string $currentDatabase): void
    {
        if ($condition->isOperator || $condition->expr === '') {
            return;
        }
        $this->namesIn($condition->expr);
        $this->embedded($condition->expr, $currentDatabase);
    }

    private function quotedLiteral(mixed $expr): bool
    {
        if (!is_string($expr) || $expr === '') {
            return false;
        }
        try {
            $lexer = new Lexer($expr);
        } catch (\Throwable) {
            return false;
        }
        $meaningful = [];
        foreach ($lexer->list->tokens as $token) {
            if ($token->type === Token::TYPE_WHITESPACE || $token->type === Token::TYPE_COMMENT || $token->type === Token::TYPE_DELIMITER) {
                continue;
            }
            $meaningful[] = $token;
        }

        return count($meaningful) === 1 && $meaningful[0]->type === Token::TYPE_STRING;
    }

    private function namesIn(string $expr): void
    {
        try {
            $lexer = new Lexer($expr);
        } catch (\Throwable) {
            throw new ExplorerQueryRejectedException();
        }
        foreach ($lexer->list->tokens as $token) {
            if (
                $token->type === Token::TYPE_STRING
                || $token->type === Token::TYPE_NUMBER
                || $token->type === Token::TYPE_COMMENT
                || $token->type === Token::TYPE_WHITESPACE
                || $token->type === Token::TYPE_OPERATOR
                || $token->type === Token::TYPE_DELIMITER
            ) {
                continue;
            }
            $name = $token->value;
            $this->object($this->bare(is_string($name) && $name !== '' ? $name : (string) $token->token));
        }
    }

    private function embedded(string $expr, string $currentDatabase): void
    {
        try {
            $lexer = new Lexer($expr);
        } catch (\Throwable) {
            throw new ExplorerQueryRejectedException();
        }
        $tokens = $lexer->list->tokens;
        $depth = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->value === '(') {
                ++$depth;
                continue;
            }
            if ($token->value === ')') {
                --$depth;
                continue;
            }
            $keyword = strtoupper((string) $token->keyword);
            if ($token->type !== Token::TYPE_KEYWORD || $depth <= 0) {
                continue;
            }
            if (in_array($keyword, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE', 'CALL', 'SET', 'LOAD', 'HANDLER'], true)) {
                throw new ExplorerQueryRejectedException();
            }
            if ($keyword !== 'SELECT' && $keyword !== 'WITH') {
                continue;
            }
            $startDepth = $depth;
            $parts = [];
            for ($cursor = $index; $cursor < $count; ++$cursor) {
                $current = $tokens[$cursor];
                if ($current->value === '(') {
                    ++$depth;
                } elseif ($current->value === ')') {
                    --$depth;
                    if ($depth < $startDepth) {
                        break;
                    }
                }
                $parts[] = (string) $current->token;
                $index = $cursor;
            }
            $this->parseFragment(implode('', $parts), $currentDatabase);
        }
    }

    private function parseFragment(string $sql, string $currentDatabase): void
    {
        try {
            $parser = new Parser($sql);
        } catch (\Throwable) {
            throw new ExplorerQueryRejectedException();
        }
        if ($parser->errors !== [] || count($parser->statements) !== 1) {
            throw new ExplorerQueryRejectedException();
        }
        $statement = $parser->statements[0];
        if ($statement instanceof SelectStatement) {
            $this->acceptSelect($statement, $currentDatabase, false);

            return;
        }
        if ($statement instanceof WithStatement) {
            $this->acceptWith($statement, $currentDatabase, false);

            return;
        }

        throw new ExplorerQueryRejectedException();
    }

    private function database(string $name, string $currentDatabase): void
    {
        $bare = $this->bare($name);
        if ($this->systemSchema($bare) || $currentDatabase === '' || strcasecmp($bare, $currentDatabase) !== 0) {
            throw new ExplorerQueryRejectedException('The query references protected data.');
        }
    }

    private function object(string $name): void
    {
        if ($name === '' || $name === '*') {
            return;
        }
        if ($this->systemSchema($name) || str_starts_with(strtolower($name), 'sqlite_')) {
            throw new ExplorerQueryRejectedException('The query references protected data.');
        }
        if (ExplorerPolicy::identifier($name) && (!$this->policy->tableVisible($name) || !$this->policy->columnVisible($name))) {
            throw new ExplorerQueryRejectedException('The query references protected data.');
        }
    }

    private function functionName(string $name): void
    {
        if (in_array(strtoupper($this->bare($name)), self::FUNCTIONS, true)) {
            throw new ExplorerQueryRejectedException();
        }
    }

    private function rejectOptions(mixed $options): void
    {
        if (!$options instanceof OptionsArray) {
            return;
        }
        foreach ($options->options as $option) {
            $label = strtoupper(is_scalar($option) ? (string) $option : (string) json_encode($option));
            if (
                str_contains($label, 'FOR UPDATE')
                || str_contains($label, 'SHARE')
                || str_contains($label, 'MAX_STATEMENT_TIME')
                || str_contains($label, 'HIGH_PRIORITY')
                || str_contains($label, 'ANALYZE')
            ) {
                throw new ExplorerQueryRejectedException();
            }
        }
    }

    private function rejectUserVariables(string $sql): void
    {
        try {
            $lexer = new Lexer($sql);
        } catch (\Throwable) {
            throw new ExplorerQueryRejectedException();
        }
        foreach ($lexer->list->tokens as $token) {
            if (str_starts_with(ltrim($this->tokenText($token->token)), '@')) {
                throw new ExplorerQueryRejectedException();
            }
        }
    }

    private function prepared(string $kind, string $sql, string $hash, ?string $table, ?string $column, int $rowCap): PreparedExplorerQuery
    {
        if (trim($sql) === '') {
            throw new ExplorerQueryRejectedException();
        }

        return new PreparedExplorerQuery($kind, $sql, $hash, $table, $rowCap, $column);
    }

    private function fingerprint(string $sql): string
    {
        $parts = [];
        try {
            $lexer = new Lexer($sql);
            foreach ($lexer->list->tokens as $token) {
                if ($token->type === Token::TYPE_COMMENT || $token->type === Token::TYPE_WHITESPACE) {
                    continue;
                }
                if ($token->type === Token::TYPE_STRING || $token->type === Token::TYPE_NUMBER) {
                    $parts[] = '?';
                    continue;
                }
                $parts[] = strtolower((string) $token->token);
            }
        } catch (\Throwable) {
            $parts = ['unparsed'];
        }

        return hash('sha256', implode(' ', $parts));
    }

    private function tokenText(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private function bare(string $name): string
    {
        $name = trim($name);
        if (strlen($name) >= 2) {
            $quote = $name[0];
            if (($quote === '`' || $quote === '"' || $quote === "'") && str_ends_with($name, $quote)) {
                return substr($name, 1, -1);
            }
        }

        return $name;
    }

    private function systemSchema(string $name): bool
    {
        return in_array(strtolower($name), self::SYSTEM_SCHEMAS, true);
    }
}
