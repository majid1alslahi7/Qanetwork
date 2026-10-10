<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class NetworkProductImportApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin'])]
    #[TestWith(['network_owner'])]
    public function test_import_creates_scoped_inactive_products_with_exact_prices_and_selected_source(string $role): void
    {
        [$user, $network] = $this->catalog($role);
        Sanctum::actingAs($user, ['account', $role]);
        $source = $network->connections()->create(['driver' => 'stored_cards', 'name' => 'Inventory']);
        $response = $this->post($this->path($network, $role), ['file' => UploadedFile::fake()->createWithContent('products.csv', "name,external_product_id,face_value,data_limit_bytes,duration_minutes,display_name\nDaily,00001,250.1250,10485760,60,Daily offer\nWeekly,week,1000,,,\n"), 'fulfillment_connection_id' => $source->id], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.imported_count', 2)->assertJsonPath('data.network_id', $network->id);
        $this->assertCount(2, $response->json('data.product_ids'));
        $daily = NetworkProduct::query()->where('external_product_id', '00001')->sole();
        $this->assertSame('250.1250', $daily->face_value);
        $this->assertSame($source->id, $daily->fulfillment_connection_id);
        $this->assertSame('inactive', $daily->status);
        $this->assertSame(10485760, $daily->data_limit_bytes);
        $this->assertDatabaseCount('pricing_rules', 0);
        $this->assertDatabaseCount('audit_events', 2);
    }

    #[TestWith(["name,external_product_id,face_value\nGood,new,100\nBad,other,0\n"])]
    #[TestWith(["name,external_product_id,face_value\nGood,new,100\nBad,new,200\n"])]
    #[TestWith(["name,external_product_id,face_value,status\nGood,new,100,active\n"])]
    #[TestWith(["name,external_product_id,face_value\nGood,new,100.00001\n"])]
    public function test_invalid_rows_and_privileged_headers_roll_back_the_whole_file(string $contents): void
    {
        [$user, $network] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $this->post($this->path($network), ['file' => UploadedFile::fake()->createWithContent('products.csv', $contents)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('network_products', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_existing_identifier_is_never_overwritten_and_foreign_network_or_source_is_rejected(): void
    {
        [$user, $network] = $this->catalog();
        [, $foreign] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $network->products()->create(['code' => 'existing', 'name' => 'Existing', 'external_product_id' => 'day', 'face_value' => '90', 'currency_code' => 'YER']);
        $file = fn () => UploadedFile::fake()->createWithContent('products.csv', "name,external_product_id,face_value\nNew,new,100\nExisting,day,200\n");
        $this->post($this->path($network), ['file' => $file()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('network_products', 1);
        $this->assertSame('90.0000', NetworkProduct::query()->sole()->face_value);
        $this->post($this->path($foreign), ['file' => $file()], ['Accept' => 'application/json'])->assertNotFound();
        $source = $foreign->connections()->create(['driver' => 'stored_cards', 'name' => 'Foreign']);
        $this->post($this->path($network), ['file' => $file(), 'fulfillment_connection_id' => $source->id], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('fulfillment_connection_id');
    }

    public function test_import_requires_login_and_owner_ability(): void
    {
        [$user, $network] = $this->catalog();
        $this->postJson($this->path($network))->assertUnauthorized();
        Sanctum::actingAs($user, ['account']);
        $this->postJson($this->path($network))->assertForbidden();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $this->postJson($this->path($network))->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_xlsx_product_prices_remain_decimal_strings_and_formulas_are_rejected(): void
    {
        [$user, $network] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>external_product_id</t></is></c><c r="C1" t="inlineStr"><is><t>face_value</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Day</t></is></c><c r="B2" t="inlineStr"><is><t>00001</t></is></c><c r="C2"><v>250.125</v></c></row>';
        $this->post($this->path($network), ['file' => $this->xlsx($rows)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('250.1250', NetworkProduct::query()->sole()->face_value);
        $this->assertSame('00001', NetworkProduct::query()->sole()->external_product_id);
        $rows = str_replace('<v>250.125</v>', '<f>100+150</f><v>250</v>', $rows);
        $this->post($this->path($network), ['file' => $this->xlsx($rows)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('network_products', 1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_hotspot_import_maps_actual_issuance_limits_for_selected_or_default_source(bool $explicitSource): void
    {
        [$user, $network] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $source = $network->connections()->create(['driver' => 'mikrotik_hotspot', 'name' => 'Hotspot', 'is_primary' => true]);
        $payload = ['file' => UploadedFile::fake()->createWithContent('products.csv', "name,external_product_id,face_value,data_limit_bytes,duration_minutes\nDay,day,200,10485760,60\n")];
        if ($explicitSource) {
            $payload['fulfillment_connection_id'] = $source->id;
        }
        $this->post($this->path($network), $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(['hotspot' => ['limit_bytes_total' => 10485760, 'limit_uptime_seconds' => 3600]], NetworkProduct::query()->sole()->metadata);
    }

    #[TestWith([''])]
    #[TestWith(['5256001'])]
    public function test_hotspot_missing_or_excessive_limits_roll_back_valid_preceding_rows(string $duration): void
    {
        [$user, $network] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $network->connections()->create(['driver' => 'mikrotik_hotspot', 'name' => 'Hotspot', 'is_primary' => true]);
        $file = UploadedFile::fake()->createWithContent('products.csv', "name,external_product_id,face_value,duration_minutes\nDay,day,200,60\nInvalid,invalid,100,$duration\n");
        $this->post($this->path($network), ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('network_products', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /** @return array{User, Network} */
    private function catalog(string $role = 'network_owner'): array
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Import owner']);
        $owner->user_id = $role === 'network_owner' ? $user->id : null;
        $owner->status = 'active';
        $owner->save();

        return [$user, $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Import network', 'currency_code' => 'YER'])];
    }

    private function path(Network $network, string $role = 'network_owner'): string
    {
        return '/api/v1/'.($role === 'admin' ? 'admin' : 'owner').'/networks/'.$network->id.'/products/import';
    }

    private function xlsx(string $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa-products-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('products.xlsx', $contents);
    }
}
