<?php

namespace FA\GraphQL\Fa\Service;

/**
 * Runs a child process from an argument array — never a shell string, so nothing in
 * an argument is interpreted — reading stdout and stderr together (a child filling
 * one pipe while the parent waits on the other would deadlock), and stopping it at
 * the deadline.
 */
class ReportRunner
{
    /** @param string[] $argv */
    public function run(array $argv, int $timeoutSeconds): ReportRun
    {
        $run = new ReportRun();
        $pipes = [];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $run->stderr = 'The report process could not be started.';
            return $run;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSeconds;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $run->timedOut = true;
                break;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1000000)) === false) {
                break;
            }
            foreach ($open as $i => $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk !== false && $chunk !== '') {
                    if ($i === 1) {
                        $run->stdout .= $chunk;
                    } else {
                        $run->stderr .= $chunk;
                    }
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$i]);
                }
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        if ($run->timedOut) {
            proc_terminate($process, 9);
        }
        $status = proc_close($process);
        $run->exitCode = $run->timedOut ? null : $status;
        return $run;
    }
}
