<?php

namespace FA\GraphQL\Fa\Service;

/** What a child process left behind: its output, its exit code, and whether it was stopped. */
final class ReportRun
{
    public string $stdout = '';
    public string $stderr = '';
    public ?int $exitCode = null;
    public bool $timedOut = false;
}
