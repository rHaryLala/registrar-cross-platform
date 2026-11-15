<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Toute erreur de l'API a la même forme (RFC 9457) et ne révèle ni trace ni chemin de fichier. */
class ApiErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_test/csrf', fn () => abort(419));
        Route::get('/api/_test/teapot', fn () => abort(418));
        Route::get('/api/_test/boom', fn () => throw new \RuntimeException('secret interne C:\\chemin\\du\\serveur'));
    }

    public function test_a_csrf_failure_is_a_clean_problem(): void
    {
        $this->getJson('/api/_test/csrf')
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf.mismatch')
            ->assertJsonPath('title', 'Session expirée')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('file');
    }

    public function test_other_http_errors_keep_their_status_in_the_same_shape(): void
    {
        $this->getJson('/api/_test/teapot')
            ->assertStatus(418)
            ->assertJsonPath('code', 'http.418')
            ->assertJsonMissingPath('trace');
    }

    public function test_an_unexpected_error_never_leaks_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $res = $this->getJson('/api/_test/boom')->assertStatus(500)->assertJsonPath('code', 'server_error');

        $this->assertStringNotContainsString('secret interne', $res->getContent());
        $this->assertStringNotContainsString('chemin', $res->getContent());
        $this->assertSame('application/problem+json', $res->headers->get('Content-Type'));
    }

    public function test_unknown_api_routes_return_a_problem(): void
    {
        $this->getJson('/api/v1/n-existe-pas')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }
}
