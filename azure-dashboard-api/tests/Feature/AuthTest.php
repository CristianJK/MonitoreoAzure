<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected const PAT = 'pat-personal-de-ana-1234567890';

    protected function fakeAzure(array $failing = []): void
    {
        $ok = fn (string $pattern, array $body) => in_array($pattern, $failing, true)
            ? Http::response(['message' => 'TF400813: no autorizado'], 401)
            : Http::response($body);

        Http::fake([
            '*/_apis/connectionData*' => $ok('connectionData', ['authenticatedUser' => [
                'id' => 'id-ana',
                'providerDisplayName' => 'Ana',
                'properties' => ['Account' => ['$value' => 'Ana@Empresa.com']],
            ]]),
            '*/_apis/projects/*' => $ok('projects', ['id' => 'p1']),
            '*/_apis/wit/workitemtypes*' => $ok('workitemtypes', ['value' => []]),
            '*/_apis/git/repositories*' => $ok('repositories', ['value' => []]),
            '*/_apis/build/builds*' => $ok('builds', ['value' => []]),
        ]);
    }

    public function test_login_with_valid_pat_creates_user_and_session(): void
    {
        $this->fakeAzure();

        $this->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/login', ['pat' => self::PAT])
            ->assertOk()
            ->assertJsonPath('data.uniqueName', 'ana@empresa.com')
            ->assertJsonPath('data.isAdmin', true);

        $user = User::firstWhere('azure_id', 'id-ana');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::PAT, $user->azure_pat);

        // Se guarda cifrado y nunca se devuelve
        $this->assertNotSame(self::PAT, DB::table('users')->value('azure_pat'));
        $this->assertArrayNotHasKey('azure_pat', $user->toArray());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'connectionData')
            && $request->header('Authorization')[0] === $this->basicAuth(self::PAT));
    }

    public function test_invalid_pat_is_rejected(): void
    {
        $this->fakeAzure(['connectionData']);

        $this->postJson('/api/login', ['pat' => self::PAT])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pat');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_pat_without_required_scopes_is_rejected(): void
    {
        $this->fakeAzure(['repositories', 'builds']);

        $this->postJson('/api/login', ['pat' => self::PAT])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pat.0', 'Al PAT le faltan permisos o no tienes acceso al proyecto: Code (Read), Build (Read).');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->fakeAzure(['connectionData']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['pat' => self::PAT])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['pat' => self::PAT])->assertTooManyRequests();
    }

    public function test_logout_forgets_pat_unless_schedule_is_enabled(): void
    {
        $user = $this->actingAsAzureUser();
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->assertNull($user->fresh()->azure_pat);

        // Con la ejecución programada activa el PAT se conserva para el job
        $beto = $this->actingAsAzureUser(['azure_id' => 'id-beto', 'email' => 'beto@empresa.com', 'agent_schedule_enabled' => true]);
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertNotNull($beto->fresh()->azure_pat);
    }

    public function test_session_is_closed_when_azure_rejects_the_pat(): void
    {
        $user = $this->actingAsAzureUser();
        Http::fake([
            '*/_apis/wit/wiql*' => Http::response(['message' => 'TF400813: no autorizado'], 401),
        ]);

        $this->getJson('/api/work-items')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'azure_pat_invalid');

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->azure_pat);
    }

    public function test_user_can_enable_scheduled_agent(): void
    {
        $user = $this->actingAsAzureUser();

        $this->patchJson('/api/me', ['agent_schedule_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.agentScheduleEnabled', true);

        $this->assertTrue($user->fresh()->agent_schedule_enabled);
    }
}
