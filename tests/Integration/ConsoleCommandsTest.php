<?php

namespace Mayaram\LaravelOcr\Tests\Integration;

use Mayaram\LaravelOcr\Tests\TestCase;
use Mayaram\LaravelOcr\Models\DocumentTemplate;
use Illuminate\Support\Facades\Storage;

class ConsoleCommandsTest extends TestCase
{
    public function test_create_template_command()
    {
        $this->artisan('laravel-ocr:create-template', [
            'name' => 'Test Invoice Template',
            'type' => 'invoice'
        ])
        ->expectsQuestion('Template description (optional)', 'A test invoice template')
        ->assertExitCode(0);

        $template = DocumentTemplate::where('name', 'Test Invoice Template')->first();
        $this->assertNotNull($template);
        $this->assertEquals('invoice', $template->type);
        $this->assertEquals('A test invoice template', $template->description);
    }

    public function test_create_template_command_interactive()
    {
        $this->artisan('laravel-ocr:create-template', [
            'name' => 'Interactive Template',
            'type' => 'receipt',
            '--interactive' => true
        ])
        ->expectsQuestion('Template description (optional)', 'Interactive test')
        ->expectsQuestion('Field key (e.g., invoice_number) or "done" to finish', 'store_name')
        ->expectsQuestion('Field label (human-readable name)', 'Store Name')
        ->expectsChoice('Field type', 'string', ['string', 'numeric', 'date', 'currency', 'email', 'phone'])
        ->expectsConfirmation('Add a regex pattern for this field?', 'no')
        ->expectsConfirmation('Add validators for this field?', 'yes')
        ->expectsConfirmation('Is this field required?', 'yes')
        ->expectsConfirmation('Add length validation?', 'no')
        ->expectsQuestion('Field key (e.g., invoice_number) or "done" to finish', 'done')
        ->assertExitCode(0);

        $template = DocumentTemplate::where('name', 'Interactive Template')->first();
        $this->assertNotNull($template);
        $this->assertEquals(1, $template->fields->count());
        
        $field = $template->fields->first();
        $this->assertEquals('store_name', $field->key);
        $this->assertEquals('Store Name', $field->label);
        $this->assertTrue($field->validators['required']);
    }

    public function test_process_document_command()
    {
        $this->mockOCRManager();
        
        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('invoice'),
            '--type' => 'invoice',
            '--output' => 'json',
            '--no-interaction' => true
        ])
        ->assertExitCode(0);
    }

    public function test_process_document_command_with_template()
    {
        $this->mockOCRManager();
        $template = $this->createSampleTemplate();

        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('invoice'),
            '--template' => $template->id,
            '--save' => true,
            '--no-interaction' => true
        ])
        ->assertExitCode(0);

        // Verify document was saved to database
        $this->assertDatabaseHas('ocr_processed_documents', [
            'template_id' => $template->id,
        ]);
    }

    public function test_process_document_command_with_ai_cleanup()
    {
        $this->mockOCRManager();
        
        $this->artisan('laravel-ocr:process', [
            'document' => $this->getSampleDocument('poor-quality'),
            '--ai-cleanup' => true,
            '--output' => 'table',
            '--no-interaction' => true
        ])
        ->assertExitCode(0);
    }

    public function test_process_document_command_file_not_found()
    {
        $this->artisan('laravel-ocr:process', [
            'document' => 'non-existent.pdf'
        ])
        ->expectsOutput('Document not found: non-existent.pdf')
        ->assertExitCode(1);
    }

    public function test_doctor_command_passes_with_local_ocr_setup()
    {
        config()->set('laravel-ocr.default', 'tesseract');
        config()->set('laravel-ocr.drivers.tesseract.binary', '/bin/sh');
        config()->set('laravel-ocr.ai_cleanup.default_provider', 'ollama');
        config()->set('laravel-ocr.providers.ollama.url', 'http://localhost:11434');

        $this->artisan('laravel-ocr:doctor')
            ->expectsOutput('Laravel OCR doctor passed.')
            ->assertExitCode(0);
    }

    public function test_doctor_command_warns_when_ai_dependency_is_missing()
    {
        config()->set('laravel-ocr.default', 'google_vision');

        $this->app->bind(\Mayaram\LaravelOcr\Console\Commands\DoctorCommand::class, function ($app) {
            return new class extends \Mayaram\LaravelOcr\Console\Commands\DoctorCommand
            {
                protected function checkAiSupport(): array
                {
                    return [
                        'component' => 'AI Cleanup',
                        'status' => 'WARN',
                        'message' => 'Optional dependency missing. Install laravel/ai to enable AI cleanup.',
                    ];
                }
            };
        });

        $this->artisan('laravel-ocr:doctor')
            ->expectsOutput('Laravel OCR doctor completed with warnings.')
            ->assertExitCode(0);
    }

    protected function mockOCRManager()
    {
        $mock = \Mockery::mock(\Mayaram\LaravelOcr\Services\OCRManager::class);
        $mock->shouldReceive('extract')
            ->andReturn($this->mockOCRResponse());
        
        $this->app->instance('laravel-ocr', $mock);
        $this->app->instance(\Mayaram\LaravelOcr\Services\OCRManager::class, $mock);

        // Mock AICleanupService as well to avoid API key issues
        $aiMock = \Mockery::mock(\Mayaram\LaravelOcr\Services\AICleanupService::class);
        $aiMock->shouldReceive('cleanup')
            ->andReturnUsing(function($text) {
                return new \Mayaram\LaravelOcr\DTOs\OcrResult(
                    text: "Cleaned: " . $text,
                    confidence: 0.99,
                    bounds: [],
                    metadata: ['ai_cleaned' => true]
                );
            });
        $aiMock->shouldReceive('clean')
            ->andReturnUsing(function($data) {
                return $data;
            });
        $this->app->instance('laravel-ocr.ai-cleanup', $aiMock);
        $this->app->instance(\Mayaram\LaravelOcr\Services\AICleanupService::class, $aiMock);

        // Force a fresh resolve of DocumentParser with the new mocks
        $this->app->forgetInstance(\Mayaram\LaravelOcr\Services\DocumentParser::class);
        $this->app->forgetInstance('laravel-ocr.parser');
        
        $parser = $this->app->make(\Mayaram\LaravelOcr\Services\DocumentParser::class);
        $this->app->instance(\Mayaram\LaravelOcr\Services\DocumentParser::class, $parser);
        $this->app->instance('laravel-ocr.parser', $parser);
    }
}
