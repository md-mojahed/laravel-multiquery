<?php

namespace Mojahed\Binary;

use Mojahed\Exceptions\MultiQueryException;

class BinaryExecutor
{
    public function execute(string $binaryPath, array $args, int $timeout = 30): array
    {
        if (!file_exists($binaryPath)) {
            throw MultiQueryException::binaryNotFound($binaryPath);
        }

        // build command using only escapeshellarg for safety
        $command = escapeshellarg($binaryPath);
        foreach ($args as $flag => $values) {
            foreach ((array) $values as $value) {
                $command .= ' ' . escapeshellarg("--{$flag}") . ' ' . escapeshellarg((string) $value);
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw MultiQueryException::executionFailed('proc_open failed');
        }

        fclose($pipes[0]);

        // enforce timeout
        $startTime = time();
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error  = '';

        while (true) {
            $status = proc_get_status($process);

            $output .= stream_get_contents($pipes[1]);
            $error  .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((time() - $startTime) >= $timeout) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($process, 9);
                proc_close($process);
                throw MultiQueryException::executionFailed("binary timed out after {$timeout}s");
            }

            usleep(10000); // 10ms poll
        }

        // read any remaining output
        $output .= stream_get_contents($pipes[1]);
        $error  .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (empty($output)) {
            throw MultiQueryException::executionFailed(
                $error ?: 'binary produced no output'
            );
        }

        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw MultiQueryException::executionFailed('invalid JSON from binary: ' . $output);
        }

        return $decoded;
    }
}
