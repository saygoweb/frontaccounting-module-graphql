<?php

namespace FA\GraphQL\Db;

use FA\GraphQL\Fa\CompanyContext;

/**
 * The container's \PDO: a connection to one company's database that refuses to
 * run anything once the request's CompanyContext names another company.
 *
 * FaSession already refuses to open a second company in one request (spec §3.6).
 * This is the second lock: the PDO is built once per request, from whichever
 * company was open then, and a statement run on it for another company would read
 * or write the wrong company's database under the other's table prefix. It fails
 * closed, as an INTERNAL error.
 */
class CompanyPdo extends \PDO
{
    private int $company;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(int $company, string $dsn, string $user, string $password, array $options = [])
    {
        $this->company = $company;
        parent::__construct($dsn, $user, $password, $options);
    }

    public function company(): int
    {
        return $this->company;
    }

    /**
     * @param string $query
     * @param array<int, mixed> $options
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->assertCompany();

        return parent::prepare($query, $options);
    }

    /**
     * @param string $statement
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $this->assertCompany();

        return parent::exec($statement);
    }

    /**
     * @param string $query
     * @param int|null $fetchMode
     * @param mixed ...$fetchModeArgs
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $this->assertCompany();

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function beginTransaction()
    {
        $this->assertCompany();

        return parent::beginTransaction();
    }

    private function assertCompany(): void
    {
        if (!CompanyContext::isSet() || CompanyContext::company() !== $this->company) {
            throw new \LogicException(sprintf(
                'This connection is for company %d; the request is now for %s.',
                $this->company,
                CompanyContext::isSet() ? 'company ' . CompanyContext::company() : 'no company'
            ));
        }
    }
}
