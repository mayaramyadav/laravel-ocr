<?php

namespace Mayaram\LaravelOcr\Console\Commands;

use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'laravel-ocr:doctor';

    protected $description = 'Check Laravel OCR runtime configuration and optional AI readiness';

    public function handle(): int
    {
        $checks = [
            $this->checkPhpVersion(),
            $this->checkDefaultDriver(),
            $this->checkTesseract(),
            $this->checkAiSupport(),
        ];

        $rows = array_map(function (array $check): array {
            return [$check['component'], $check['status'], $check['message']];
        }, $checks);

        $this->table(['Component', 'Status', 'Message'], $rows);

        $hasFailures = collect($checks)->contains(fn (array $check) => $check['status'] === 'FAIL');
        $hasWarnings = collect($checks)->contains(fn (array $check) => $check['status'] === 'WARN');

        if ($hasFailures) {
            $this->error('Laravel OCR doctor found blocking issues.');

            return 1;
        }

        if ($hasWarnings) {
            $this->warn('Laravel OCR doctor completed with warnings.');

            return 0;
        }

        $this->info('Laravel OCR doctor passed.');

        return 0;
    }

    protected function checkPhpVersion(): array
    {
        $supported = version_compare(PHP_VERSION, '8.2.0', '>=');

        return [
            'component' => 'PHP',
            'status' => $supported ? 'OK' : 'FAIL',
            'message' => 'Running PHP '.PHP_VERSION.'; package requires PHP 8.2+.',
        ];
    }

    protected function checkDefaultDriver(): array
    {
        $driver = (string) config('laravel-ocr.default', 'tesseract');
        $supportedDrivers = ['tesseract', 'google_vision', 'aws_textract', 'azure'];

        return [
            'component' => 'OCR Driver',
            'status' => in_array($driver, $supportedDrivers, true) ? 'OK' : 'FAIL',
            'message' => "Configured default driver: {$driver}",
        ];
    }

    protected function checkTesseract(): array
    {
        if ((string) config('laravel-ocr.default', 'tesseract') !== 'tesseract') {
            return [
                'component' => 'Tesseract',
                'status' => 'WARN',
                'message' => 'Tesseract not checked because it is not the active default driver.',
            ];
        }

        $configuredBinary = (string) config('laravel-ocr.drivers.tesseract.binary', 'tesseract');

        if ($this->isExecutableBinary($configuredBinary)) {
            return [
                'component' => 'Tesseract',
                'status' => 'OK',
                'message' => "Using executable binary: {$configuredBinary}",
            ];
        }

        $fallbackBinary = $this->findBinaryOnPath('tesseract');

        if ($fallbackBinary !== null) {
            return [
                'component' => 'Tesseract',
                'status' => 'WARN',
                'message' => "Configured binary not executable: {$configuredBinary}. Found tesseract on PATH at {$fallbackBinary}.",
            ];
        }

        return [
            'component' => 'Tesseract',
            'status' => 'FAIL',
            'message' => "Tesseract binary not found. Checked configured path: {$configuredBinary}",
        ];
    }

    protected function checkAiSupport(): array
    {
        if (! class_exists(\Laravel\Ai\Ai::class)) {
            return [
                'component' => 'AI Cleanup',
                'status' => 'WARN',
                'message' => 'Optional dependency missing. Install laravel/ai to enable AI cleanup.',
            ];
        }

        $provider = (string) config('laravel-ocr.ai_cleanup.default_provider', 'openai');
        $envKeyMap = [
            'anthropic' => 'ANTHROPIC_API_KEY',
            'azure' => 'AZURE_OPENAI_API_KEY',
            'cohere' => 'COHERE_API_KEY',
            'deepseek' => 'DEEPSEEK_API_KEY',
            'eleven' => 'ELEVENLABS_API_KEY',
            'gemini' => 'GEMINI_API_KEY',
            'groq' => 'GROQ_API_KEY',
            'jina' => 'JINA_API_KEY',
            'mistral' => 'MISTRAL_API_KEY',
            'ollama' => 'OLLAMA_API_KEY',
            'openai' => 'OPENAI_API_KEY',
            'openrouter' => 'OPENROUTER_API_KEY',
            'voyageai' => 'VOYAGEAI_API_KEY',
            'xai' => 'XAI_API_KEY',
        ];

        $envKey = $envKeyMap[$provider] ?? null;

        if ($provider === 'ollama') {
            $baseUrl = (string) config('laravel-ocr.providers.ollama.url', env('OLLAMA_BASE_URL', 'http://localhost:11434'));

            return [
                'component' => 'AI Cleanup',
                'status' => 'OK',
                'message' => "laravel/ai is installed. Provider: ollama. Endpoint: {$baseUrl}",
            ];
        }

        $hasCredential = $envKey !== null && filled(env($envKey));

        return [
            'component' => 'AI Cleanup',
            'status' => $hasCredential ? 'OK' : 'WARN',
            'message' => $hasCredential
                ? "laravel/ai is installed. Provider: {$provider}. Credential detected in {$envKey}."
                : "laravel/ai is installed. Provider: {$provider}. Missing credential: {$envKey}.",
        ];
    }

    protected function isExecutableBinary(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }

        return $this->findBinaryOnPath($binary) !== null;
    }

    protected function findBinaryOnPath(string $binary): ?string
    {
        $result = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
        $path = is_string($result) ? trim($result) : '';

        return $path !== '' ? $path : null;
    }
}
