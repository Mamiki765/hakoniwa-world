<?php

namespace App\Console\Commands;

use App\Application\Underground\UndergroundBalanceSimulator;
use App\Application\Underground\UndergroundBuildBalanceSimulator;
use App\Application\Underground\UndergroundReportSourceIdentity;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

final class UndergroundBalance extends Command
{
    protected $signature = 'underground:balance
        {--manifest=config/underground/balance/foundation-v0.json : Versioned balance manifest}
        {--seed-start= : Override the first non-negative seed}
        {--count= : Override the number of seeds}
        {--scenario= : Run or replay one scenario ID}
        {--replay-seed= : Emit one detailed deterministic combat result}
        {--commit-sha= : Exact source commit when Git metadata is unavailable}
        {--output= : Write JSON atomically to this path}';

    protected $description = 'Run the DB-free Underground combat balance laboratory';

    public function handle(
        UndergroundBalanceSimulator $simulator,
        UndergroundBuildBalanceSimulator $buildSimulator,
        UndergroundReportSourceIdentity $sourceIdentity,
    ): int {
        try {
            $manifestOption = $this->option('manifest');
            if (! is_string($manifestOption) || $manifestOption === '') {
                throw new InvalidArgumentException('The Underground balance manifest path is required.');
            }
            $manifestPath = $this->resolvePath($manifestOption);
            $contents = file_get_contents($manifestPath);
            if (! is_string($contents)) {
                throw new InvalidArgumentException("Unable to read Underground balance manifest [{$manifestOption}].");
            }
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new InvalidArgumentException('Underground balance manifest root must be an object.');
            }

            $scenario = $this->optionalString('scenario');
            $replaySeed = $this->optionalInteger('replay-seed');
            $selectedSimulator = ($manifest['combat_identity'] ?? null) === AlphaV1CombatRules::IDENTITY
                ? $buildSimulator
                : $simulator;
            if ($replaySeed !== null) {
                if ($scenario === null) {
                    throw new InvalidArgumentException('--scenario is required with --replay-seed.');
                }
                $report = $selectedSimulator->replay($manifest, $scenario, $replaySeed);
            } else {
                [$commitSha, $workingTreeDirty] = $this->gitIdentity(
                    $sourceIdentity,
                    $this->optionalString('commit-sha'),
                );
                $report = $selectedSimulator->run(
                    $manifest,
                    $contents,
                    hash('sha256', $contents),
                    str_replace('\\', '/', $manifestOption),
                    $commitSha,
                    $workingTreeDirty,
                    $this->optionalInteger('seed-start'),
                    $this->optionalInteger('count'),
                    $scenario,
                );
            }

            $json = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
            $output = $this->optionalString('output');
            if ($output !== null) {
                $this->writeAtomically($this->resolvePath($output), $json);
                $this->line("Wrote Underground report to {$output}.");
            } else {
                $this->output->write($json);
            }

            if ($replaySeed !== null) {
                return self::SUCCESS;
            }
            if (($report['laboratory_contract_passed'] ?? false) !== true) {
                $this->error('Underground laboratory invariants or scenario semantics were not met.');

                return self::FAILURE;
            }
            $experimentThresholdsPassed = $report['experiment_thresholds_passed'];
            if ($experimentThresholdsPassed === false) {
                $this->error('Optional Underground experiment thresholds were not met.');

                return self::FAILURE;
            }
            $this->info($experimentThresholdsPassed === true
                ? 'Underground laboratory contracts and optional experiment thresholds passed.'
                : 'Underground laboratory contracts passed; no experiment thresholds were configured.');

            return self::SUCCESS;
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Underground balance simulation failed unexpectedly.');

            return self::FAILURE;
        }
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("--{$key} must be a string.");
        }

        return $value;
    }

    private function optionalInteger(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new InvalidArgumentException("--{$key} must be a non-negative integer.");
        }

        return (int) $value;
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#\A(?:[A-Za-z]:[\\/]|/)#', $path) === 1) {
            return $path;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }

    /** @return array{string, bool|null} */
    private function gitIdentity(
        UndergroundReportSourceIdentity $sourceIdentity,
        ?string $explicitCommitSha,
    ): array {
        $head = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $head->setTimeout(5);
        $head->run();
        $detected = $head->isSuccessful() ? trim($head->getOutput()) : null;

        $status = new Process(['git', 'status', '--porcelain'], base_path());
        $status->setTimeout(5);
        $status->run();
        $dirty = $status->isSuccessful() ? trim($status->getOutput()) !== '' : null;
        $commitSha = $sourceIdentity->resolve($explicitCommitSha, $detected, $dirty);

        return [$commitSha, $dirty];
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException("Unable to create report directory [{$directory}].");
        }
        $temporary = $path.'.tmp.'.getmypid();
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new InvalidArgumentException("Unable to write temporary report [{$temporary}].");
        }
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new InvalidArgumentException("Unable to replace report [{$path}].");
        }
    }
}
