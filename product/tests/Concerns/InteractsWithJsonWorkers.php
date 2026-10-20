<?php

namespace Tests\Concerns;

use Tests\TestCase;

/** @mixin TestCase */
trait InteractsWithJsonWorkers
{
    /** @param list<string> $command
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startJsonWorker(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker
     * @return array<string, mixed>
     */
    private function finishJsonWorker(array &$worker, int $timeoutSeconds, string $timeoutMessage): array
    {
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $out = stream_get_contents($worker['pipes'][1]);
            $err = stream_get_contents($worker['pipes'][2]);
            $stdout .= is_string($out) ? $out : '';
            $stderr .= is_string($err) ? $err : '';
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->fail($timeoutMessage);
            }
            usleep(10_000);
        } while (true);

        $stdout .= (string) stream_get_contents($worker['pipes'][1]);
        $stderr .= (string) stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $closeCode = proc_close($worker['process']);
        if ($exitCode === -1) {
            $exitCode = $closeCode;
        }
        unset($worker['process'], $worker['pipes']);
        $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker */
    private function terminateWorkerProcess(array $worker): void
    {
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
    }
}
