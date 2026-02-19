<?php

class bastille_manager_MwExecParallel
{
    private $commands = [];
    private $results = [];
    private $executionTime = 0;

    public function __construct(array $commands = [])
    {
        $this->commands = $commands;
    }

    public function executeOriginal()
    {
        $this->results = [];
        $processes = [];
        $start_time = microtime(true);
        foreach ($this->commands as $key => $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                $processes[$key] = ['proc' => $process, 'pipes' => $pipes];
            }
        }
        foreach ($processes as $key => $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            $return_code = proc_close($item['proc']);
            $this->results[$key] = ['stdout' => $stdout, 'stderr' => $stderr, 'return_code' => $return_code];
        }
        $this->executionTime = round((microtime(true) - $start_time) * 1000, 2);
        return $this->results;
    }

    public function executeWithSelect()
    {
        $this->results = [];
        $processes = [];
        $pipes_map = [];
        $read_set = [];
        $start_time = microtime(true);
        foreach ($this->commands as $key => $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                stream_set_blocking($pipes[1], false);
                $processes[$key] = $process;
                $pipes_map[$key] = $pipes;
                $read_set[$key] = $pipes[1];
                $this->results[$key] = ['stdout' => '', 'stderr' => '', 'return_code' => -1];
            }
        }
        while (!empty($read_set)) {
            $changed = $read_set;
            $write = $except = null;
            if (stream_select($changed, $write, $except, 1) > 0) {
                foreach ($changed as $key => $pipe) {
                    $data = fread($pipe, 8192);
                    if ($data) {
                        $this->results[$key]['stdout'] .= $data;
                    } else {
                        unset($read_set[$key]);
                    }
                }
            } else {
                break;
            }
        }
        foreach ($processes as $key => $proc) {
            fclose($pipes_map[$key][1]);
            fclose($pipes_map[$key][2]);
            $this->results[$key]['return_code'] = proc_close($proc);
        }
        $this->executionTime = round((microtime(true) - $start_time) * 1000, 2);
        return $this->results;
    }

    public function getMs()
    {
        return $this->executionTime . "ms";
    }
}
