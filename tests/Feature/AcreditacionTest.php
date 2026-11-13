<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcreditacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'filesystems.disks.acreditados.root' => storage_path('framework/testing/acreditados-'.uniqid()),
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('empresas', function (Blueprint $table) {
            $table->increments('id_empresa');
            $table->string('nit')->nullable();
            $table->string('usuario')->nullable();
            $table->string('pass')->nullable();
            $table->string('nombre_empresa')->nullable();
            $table->boolean('es_activo')->nullable();
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->increments('id_feria');
            $table->string('nombre_feria');
            $table->string('codigo_contrato')->nullable();
            $table->string('tipo_credenciales')->nullable();
            $table->date('inicio')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('acreditacion_empresa_habilitada')->default(false);
        });
        Schema::create('contrato', function (Blueprint $table) {
            $table->increments('id_contrato');
            $table->unsignedInteger('id_feria');
            $table->unsignedInteger('id_empresa');
            $table->integer('codigo_contrato');
            $table->integer('total_stands');
            $table->integer('nro_credenciales')->default(0);
            $table->integer('tipo_credenciales')->nullable();
            $table->unsignedInteger('stand_1')->nullable();
            $table->decimal('metraje_total', 8, 2)->nullable();
        });
        Schema::create('empresa_evento_credenciales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->unsignedInteger('id_contrato')->nullable();
            $table->string('tipo_credencial');
            $table->integer('tipo_credenciales')->nullable();
            $table->integer('cantidad_credenciales');
            $table->boolean('estado');
            $table->boolean('visible_en_plataforma')->default(true);
        });
        Schema::create('cred_adicionales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('feria');
            $table->unsignedInteger('id_empresa')->nullable();
            $table->unsignedInteger('contrato')->nullable();
            $table->unsignedInteger('id_empresa_evento_credencial')->nullable();
            $table->integer('cant_adic');
            $table->string('tipo_movimiento');
        });
        Schema::create('expositores', function (Blueprint $table) {
            $table->increments('id_exp');
            $table->string('ci')->unique();
            $table->string('tipo_documento')->nullable();
            $table->string('nombre')->nullable();
            $table->string('email')->nullable();
            $table->string('com_email')->nullable();
            $table->string('celular')->nullable();
            $table->string('exp')->nullable();
            $table->string('foto')->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();
        });
        Schema::create('credenciales', function (Blueprint $table) {
            $table->increments('id_credencial');
            $table->string('cod_credencial');
            $table->unsignedInteger('id_expositor');
            $table->unsignedInteger('id_contrato')->nullable();
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->string('com_medio')->nullable();
            $table->string('com_dias')->nullable();
            $table->integer('tipo_credencial');
            $table->integer('modalidad_credencial')->nullable();
            $table->integer('estado_credencial');
            $table->integer('ingreso')->nullable();
            $table->timestamps();
        });
        Schema::create('stands', function (Blueprint $table) {
            $table->increments('id_stand');
            $table->unsignedInteger('id_pabellon')->nullable();
            $table->string('numero_stand')->nullable();
        });
        Schema::create('pabellones', function (Blueprint $table) {
            $table->increments('id_pabellon');
            $table->string('nombre_pabellon')->nullable();
        });

        DB::table('empresas')->insert([
            'id_empresa' => 1,
            'nombre_empresa' => 'Empresa de prueba',
        ]);
        DB::table('eventos')->insert([
            'id_feria' => 10,
            'nombre_feria' => 'Feria de prueba',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => true,
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            [
                'id' => 1,
                'id_empresa' => 1,
                'id_feria' => 10,
                'tipo_credencial' => 'Expositor',
                'tipo_credenciales' => 2,
                'cantidad_credenciales' => 2,
                'estado' => true,
            ],
            [
                'id' => 2,
                'id_empresa' => 1,
                'id_feria' => 10,
                'tipo_credencial' => 'Expositor',
                'tipo_credenciales' => 1,
                'cantidad_credenciales' => 2,
                'estado' => true,
            ],
        ]);
        DB::table('cred_adicionales')->insert([
            'feria' => 10,
            'id_empresa' => 1,
            'id_empresa_evento_credencial' => 2,
            'cant_adic' => 1,
            'tipo_movimiento' => 'disminucion',
        ]);
        DB::table('expositores')->insert([
            [
                'id_exp' => 1,
                'ci' => '12345',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Persona de prueba',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_exp' => 2,
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('credenciales')->insert([
            'id_credencial' => 1,
            'cod_credencial' => 'ACR-10-1',
            'id_expositor' => 1,
            'id_empresa' => 1,
            'id_feria' => 10,
            'tipo_credencial' => 1,
            'modalidad_credencial' => 2,
            'estado_credencial' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_summary_counts_direct_credentials_once_and_applies_direct_decreases(): void
    {
        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos/10/resumen');

        $response->assertOk()
            ->assertJsonPath('data.tipos.0.asignadas', 3)
            ->assertJsonPath('data.tipos.0.utilizadas', 1)
            ->assertJsonPath('data.tipos.0.disponibles', 2)
            ->assertJsonPath('data.tipos.0.registros.0.modalidad_credencial', 'Física')
            ->assertJsonPath('data.tipos.0.registros.0.credenciales_utilizadas', 1)
            ->assertJsonPath('data.tipos.0.registros.0.credenciales_disponibles', 1)
            ->assertJsonPath('data.tipos.0.registros.1.credenciales_utilizadas', 0)
            ->assertJsonPath('data.tipos.0.registros.1.credenciales_disponibles', 1)
            ->assertJsonCount(1, 'data.tipos.0.registros.0.acreditados')
            ->assertJsonCount(0, 'data.tipos.0.registros.1.acreditados');
    }

    public function test_accreditation_uses_remaining_capacity_across_direct_assignments_of_same_type(): void
    {
        DB::table('expositores')->where('id_exp', 2)->update(['foto' => 'images/fotos/acreditado_existente.jpg']);

        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->post('/acreditaciones/eventos/10/acreditados', [
                'id_vinculacion' => 2,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
            ]);

        $response->assertCreated();
        $this->assertSame(2, DB::table('credenciales')->where('id_feria', 10)->count());
        $this->assertSame('images/fotos/acreditado_existente.jpg', DB::table('expositores')->where('id_exp', 2)->value('foto'));
    }

    public function test_new_accreditation_saves_uploaded_photo_to_the_shared_photo_disk(): void
    {
        Storage::fake('acreditados');
        Storage::fake('acreditados_anterior');

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->post('/acreditaciones/eventos/10/acreditados', [
                'id_vinculacion' => 2,
                'tipo_credencial' => 'Expositor',
                'ci' => '99999',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Persona nueva',
                'foto' => UploadedFile::fake()->image('persona.jpg'),
            ])
            ->assertCreated();

        $rutaFoto = DB::table('expositores')->where('ci', '99999')->value('foto');
        $filename = basename($rutaFoto);

        $this->assertStringStartsWith('images/fotos/acreditado_', $rutaFoto);
        Storage::disk('acreditados')->assertExists($filename);
        Storage::disk('acreditados_anterior')->assertMissing($filename);
    }

    public function test_existing_expositor_without_photo_must_upload_one_before_accreditation(): void
    {
        Log::spy();

        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->postJson('/acreditaciones/eventos/10/acreditados', [
                'id_vinculacion' => 2,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Nombre que no debe guardarse',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['foto']);
        $this->assertSame(1, DB::table('credenciales')->where('id_feria', 10)->count());
        $this->assertSame('Otra persona', DB::table('expositores')->where('id_exp', 2)->value('nombre'));
        $this->assertNull(DB::table('expositores')->where('id_exp', 2)->value('foto'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Acreditación rechazada para la empresa Empresa de prueba (ID 1) durante validación de reglas de acreditación.'
            && $context['operacion'] === 'App\Http\Controllers\AcreditacionController@acreditar'
            && $context['ruta'] === '/acreditaciones/eventos/{idFeria}/acreditados'
            && $context['etapa'] === 'validación de reglas de acreditación'
            && $context['id_empresa'] === 1
            && $context['nombre_empresa'] === 'Empresa de prueba'
            && $context['id_feria'] === 10
            && $context['id_vinculacion'] === 2
            && $context['tipo_credencial'] === 'Expositor'
            && in_array('foto', $context['campos_con_error'], true)
            && isset($context['errores_por_campo']['foto']));
    }

    public function test_photo_route_can_read_a_photo_from_the_previous_storage_path(): void
    {
        $filename = 'acreditado_123e4567-e89b-12d3-a456-426614174000.jpg';
        DB::table('expositores')->where('id_exp', 1)->update(['foto' => 'images/fotos/'.$filename]);
        Storage::fake('acreditados_anterior');
        Storage::disk('acreditados_anterior')->put($filename, 'photo-content');

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->get('/acreditaciones/fotos/'.$filename)
            ->assertOk();
    }

    public function test_company_with_event_access_can_view_a_registered_expositor_photo(): void
    {
        $filename = 'acreditado_123e4567-e89b-12d3-a456-426614174001.jpg';
        DB::table('expositores')->where('id_exp', 2)->update(['foto' => 'images/fotos/'.$filename]);
        Storage::fake('acreditados');
        Storage::disk('acreditados')->put($filename, 'photo-content');

        $empresa = Empresa::query()->findOrFail(1);
        $this->actingAs($empresa, 'empresa')
            ->get('/acreditaciones/fotos/'.$filename.'?id_feria=10')
            ->assertOk();

        $this->actingAs($empresa, 'empresa')
            ->get('/acreditaciones/fotos/'.$filename.'?id_feria=11')
            ->assertNotFound();
    }

    public function test_required_field_validation_errors_are_written_to_laravel_log(): void
    {
        Log::spy();

        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->postJson('/acreditaciones/eventos/10/acreditados', [
                'id_vinculacion' => 2,
                'tipo_credencial' => 'Expositor',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ci', 'tipo_documento', 'nombre']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Acreditación rechazada para la empresa Empresa de prueba (ID 1) durante validación de datos de solicitud.'
            && $context['operacion'] === 'App\Http\Controllers\AcreditacionController@acreditar'
            && $context['ruta'] === '/acreditaciones/eventos/{idFeria}/acreditados'
            && $context['etapa'] === 'validación de datos de solicitud'
            && $context['id_empresa'] === 1
            && $context['nombre_empresa'] === 'Empresa de prueba'
            && $context['id_feria'] === 10
            && in_array('ci', $context['campos_con_error'], true)
            && in_array('tipo_documento', $context['campos_con_error'], true)
            && in_array('nombre', $context['campos_con_error'], true)
            && isset($context['errores_por_campo']['ci']));
    }

    public function test_event_19_forces_all_contract_credentials_to_digital(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 19,
            'nombre_feria' => 'Evento 19',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => true,
        ]);
        DB::table('contrato')->insert([
            [
                'id_contrato' => 19,
                'id_feria' => 19,
                'id_empresa' => 1,
                'codigo_contrato' => 19,
                'total_stands' => 1,
                'nro_credenciales' => 2,
                'tipo_credenciales' => 2,
            ],
            [
                'id_contrato' => 20,
                'id_feria' => 19,
                'id_empresa' => 1,
                'codigo_contrato' => 20,
                'total_stands' => 1,
                'nro_credenciales' => 2,
                'tipo_credenciales' => 2,
            ],
        ]);
        DB::table('credenciales')->insert([
            'id_credencial' => 2,
            'cod_credencial' => 'ACR-19-19',
            'id_expositor' => 1,
            'id_contrato' => 19,
            'id_empresa' => 1,
            'id_feria' => 19,
            'tipo_credencial' => 1,
            'modalidad_credencial' => 2,
            'estado_credencial' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $empresa = Empresa::query()->findOrFail(1);
        $this->actingAs($empresa, 'empresa')
            ->getJson('/acreditaciones/eventos/19/resumen')
            ->assertOk()
            ->assertJsonPath('data.tipos.0.registros.0.modalidad_credencial', 'Digital')
            ->assertJsonPath('data.tipos.0.registros.0.acreditados.0.modalidad_credencial', 'Digital')
            ->assertJsonPath('data.tipos.0.registros.1.modalidad_credencial', 'Digital');

        DB::table('expositores')->where('id_exp', 2)->update(['foto' => 'images/fotos/acreditado_existente.jpg']);
        $this->actingAs($empresa, 'empresa')
            ->post('/acreditaciones/eventos/19/acreditados', [
                'id_contrato' => 20,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
            ])
            ->assertCreated();

        $this->assertSame(
            1,
            (int) DB::table('credenciales')->where('id_feria', 19)->where('id_contrato', 20)->value('modalidad_credencial')
        );
    }

    public function test_accreditation_event_list_only_includes_events_enabled_by_intranet(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 11,
            'nombre_feria' => 'Evento cerrado',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => false,
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            'id' => 11,
            'id_empresa' => 1,
            'id_feria' => 11,
            'tipo_credencial' => 'Expositor',
            'tipo_credenciales' => 2,
            'cantidad_credenciales' => 2,
            'estado' => true,
        ]);
        DB::table('contrato')->insert([
            'id_contrato' => 11,
            'id_feria' => 11,
            'id_empresa' => 1,
            'codigo_contrato' => 11,
            'total_stands' => 1,
            'nro_credenciales' => 2,
            'tipo_credenciales' => 2,
        ]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_feria', 10);
    }

    public function test_hidden_direct_link_is_not_available_for_platform_accreditation(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 11,
            'nombre_feria' => 'Evento con tipo oculto',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => true,
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            'id' => 11,
            'id_empresa' => 1,
            'id_feria' => 11,
            'tipo_credencial' => 'Expositor',
            'cantidad_credenciales' => 2,
            'estado' => true,
            'visible_en_plataforma' => false,
        ]);
        $empresa = Empresa::query()->findOrFail(1);

        $this->actingAs($empresa, 'empresa')
            ->getJson('/acreditaciones/eventos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_feria', 10);

        $this->actingAs($empresa, 'empresa')
            ->getJson('/acreditaciones/eventos/11/resumen')
            ->assertNotFound();

        $this->actingAs($empresa, 'empresa')
            ->postJson('/acreditaciones/eventos/11/acreditados', [
                'id_vinculacion' => 11,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Persona no autorizada',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_vinculacion']);
    }

    public function test_visible_direct_link_is_included_alongside_contract_of_same_type(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 11,
            'nombre_feria' => 'Evento con asignación mixta',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => true,
        ]);
        DB::table('contrato')->insert([
            'id_contrato' => 11,
            'id_feria' => 11,
            'id_empresa' => 1,
            'codigo_contrato' => 11,
            'total_stands' => 1,
            'nro_credenciales' => 3,
            'tipo_credenciales' => 2,
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            [
                'id' => 11,
                'id_empresa' => 1,
                'id_feria' => 11,
                'id_contrato' => 11,
                'tipo_credencial' => 'Expositor',
                'cantidad_credenciales' => 3,
                'estado' => true,
                'visible_en_plataforma' => false,
            ],
            [
                'id' => 12,
                'id_empresa' => 1,
                'id_feria' => 11,
                'id_contrato' => null,
                'tipo_credencial' => 'Expositor',
                'cantidad_credenciales' => 2,
                'estado' => true,
                'visible_en_plataforma' => true,
            ],
        ]);
        DB::table('cred_adicionales')->insert([
            [
                'feria' => 11,
                'id_empresa' => null,
                'contrato' => 11,
                'id_empresa_evento_credencial' => null,
                'cant_adic' => 1,
                'tipo_movimiento' => 'adicion',
            ],
            [
                'feria' => 11,
                'id_empresa' => null,
                'contrato' => 11,
                'id_empresa_evento_credencial' => 11,
                'cant_adic' => 1,
                'tipo_movimiento' => 'adicion',
            ],
        ]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos/11/resumen')
            ->assertOk()
            ->assertJsonPath('data.tipos.0.asignadas', 7)
            ->assertJsonCount(2, 'data.tipos.0.registros')
            ->assertJsonPath('data.tipos.0.registros.0.id_contrato', 11)
            ->assertJsonPath('data.tipos.0.registros.0.credenciales_asignadas', 5)
            ->assertJsonPath('data.tipos.0.registros.1.id_contrato', null);

        DB::table('empresa_evento_credenciales')->where('id', 12)->update(['visible_en_plataforma' => false]);
        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos/11/resumen')
            ->assertOk()
            ->assertJsonPath('data.tipos.0.asignadas', 5)
            ->assertJsonCount(1, 'data.tipos.0.registros');
    }

    public function test_contract_link_remains_visible_when_its_visibility_flag_is_false(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 11,
            'nombre_feria' => 'Evento con contrato',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
            'acreditacion_empresa_habilitada' => true,
        ]);
        DB::table('contrato')->insert([
            'id_contrato' => 11,
            'id_feria' => 11,
            'id_empresa' => 1,
            'codigo_contrato' => 11,
            'total_stands' => 1,
            'nro_credenciales' => 2,
            'tipo_credenciales' => 2,
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            'id' => 11,
            'id_empresa' => 1,
            'id_feria' => 11,
            'id_contrato' => 11,
            'tipo_credencial' => 'Expositor',
            'cantidad_credenciales' => 2,
            'estado' => true,
            'visible_en_plataforma' => false,
        ]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos/11/resumen')
            ->assertOk()
            ->assertJsonPath('data.tipos.0.registros.0.id_contrato', 11);

        Storage::fake('acreditados');
        Storage::fake('acreditados_anterior');
        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->post('/acreditaciones/eventos/11/acreditados', [
                'id_contrato' => 11,
                'id_vinculacion' => 11,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Acreditación con contrato',
                'foto' => UploadedFile::fake()->image('acreditado.jpg'),
            ])
            ->assertCreated();
    }

    public function test_company_cannot_create_accreditations_for_a_closed_event(): void
    {
        DB::table('eventos')->where('id_feria', 10)->update(['acreditacion_empresa_habilitada' => false]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->postJson('/acreditaciones/eventos/10/acreditados', [
                'id_contrato' => 10,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Persona nueva',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['evento']);

        $this->assertSame(1, DB::table('credenciales')->where('id_feria', 10)->count());
    }
}
