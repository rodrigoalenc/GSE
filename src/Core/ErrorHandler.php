<?php

declare(strict_types=1);

final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log('fatal_error', $error['message'], $error['file'], (int) $error['line'], 'FatalError');

                if (!headers_sent()) {
                    self::render500();
                }
            }
        });
    }

    public static function handleException(Throwable $exception): never
    {
        self::log('uncaught_exception', $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception::class);
        self::render500($exception);
    }

    public static function render500(?Throwable $exception = null): never
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $statusCode = 500;
        $heading = 'Erro interno do servidor';
        $message = 'O sistema está temporariamente indisponível. Tente novamente mais tarde.';
        $requestId = RequestContext::requestId();
        $returnPath = 'login';
        $technicalDetail = Config::isDevelopment() && $exception !== null
            ? $exception::class . ': ' . $exception->getMessage()
            : null;
        require (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/src/Views/errors/standalone.php';
        exit;
    }

    private static function log(string $event, string $message, string $file, int $line, string $type): void
    {
        TechnicalLogger::error($event, [
            'type' => $type,
            'message' => $message,
            'file' => basename($file),
            'line' => $line,
        ]);
    }
}
