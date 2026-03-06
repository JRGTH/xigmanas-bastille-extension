<?php
declare(strict_types=1);

/**
 * Parallel Command Execution Handler for Bastille Manager
 */
class BastilleManagerMwExecParallel
{
    private array $commands = [];
    private array $results = [];
    private float $executionTime = 0.0;

    /**
     * @param string[] $commands Array of shell commands to execute
     */
    public function __construct(array $commands = [])
    {
        $this->commands = $commands;
    }

    /**
     * Executes commands in parallel using proc_open (Sequential read)
     * @return array<mixed>
     */
    public function executeOriginal(): array
    {
        $this->results = [];
        $processes = [];
        $startTime = microtime(true);

        foreach ($this->commands as $key => $command) {
            $process = proc_open($command, [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w']  // stderr
            ], $pipes);

            if (is_resource($process)) {
                $processes[$key] = ['proc' => $process, 'pipes' => $pipes];
            }
        }

        foreach ($processes as $key => $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);

            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);

            $returnCode = proc_close($item['proc']);

            $this->results[$key] = [
                'stdout'      => $stdout ?: '',
                'stderr'      => $stderr ?: '',
                'return_code' => $returnCode
            ];
        }

        $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);
        return $this->results;
    }

    /**
     * Executes commands in parallel using stream_select for non-blocking I/O
     * @return array<mixed>
     */
    public function executeWithSelect(): array
    {
        $this->results = [];
        $processes = [];
        $pipesMap = [];
        $readSet = [];
        $startTime = microtime(true);

        foreach ($this->commands as $key => $command) {
            $process = proc_open($command, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ], $pipes);

            if (is_resource($process)) {
                stream_set_blocking($pipes[1], false);
                $processes[$key] = $process;
                $pipesMap[$key] = $pipes;
                $readSet[$key] = $pipes[1];
                $this->results[$key] = [
                    'stdout'      => '',
                    'stderr'      => '',
                    'return_code' => -1
                ];
            }
        }

        // Monitoring active pipes
        while (!empty($readSet)) {
            $changed = $readSet;
            $write = $except = null;

            // Wait up to 1 second for activity
            if (stream_select($changed, $write, $except, 1) > 0) {
                foreach ($changed as $key => $pipe) {
                    $data = fread($pipe, 8192);
                    if ($data) {
                        $this->results[$key]['stdout'] .= $data;
                    } else {
                        // Pipe is empty or closed
                        unset($readSet[$key]);
                    }
                }
            } else {
                // Timeout or no data
                break;
            }
        }

        foreach ($processes as $key => $proc) {
            if (isset($pipesMap[$key])) {
                fclose($pipesMap[$key][1]);
                fclose($pipesMap[$key][2]);
            }
            $this->results[$key]['return_code'] = proc_close($proc);
        }

        $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);
        return $this->results;
    }

    /**
     * Gets total execution time in ms
     * @return string
     */
    public function getMs(): string
    {
        return "{$this->executionTime}ms";
    }
}