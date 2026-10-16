<?php
// ================================================================
//  CANAOPTICALCLINIC — config/env.php
//  Loads variables from a root-level .env file into getenv()/$_ENV, for
//  hosts (e.g. Hostinger shared hosting) that don't inject environment
//  variables into the PHP process the way Railway does. No-op if .env
//  doesn't exist (Railway/local dev keep working exactly as before) or
//  if a variable is already set in the real environment — real env vars
//  always win over .env, so this never overrides platform config.
// ================================================================

if (!defined('ENV_LOADED')) {
    define('ENV_LOADED', true);

    $envFile = __DIR__ . '/../.env';

    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip a single matching pair of surrounding quotes, if present.
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")
            )) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}
