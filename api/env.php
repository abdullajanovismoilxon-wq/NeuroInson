<?php
function loadEnv($key) {
    static $env = null;
    if ($env === null) {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $env[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        $configFile = __DIR__ . '/config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (is_array($config)) {
                foreach ($config as $k => $v) {
                    if (is_string($v) && !isset($env[$k])) {
                        $env[$k] = $v;
                    } elseif (is_array($v)) {
                        if (!isset($env[$k])) {
                            $env[$k] = $v;
                        }
                    }
                }
            }
        }
    }
    return $env[$key] ?? null;
}
