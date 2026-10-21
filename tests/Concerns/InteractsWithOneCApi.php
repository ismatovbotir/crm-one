<?php

namespace Tests\Concerns;

use App\Enums\IntegrationAbility;
use App\Models\IntegrationToken;
use App\Models\Setting;
use App\Services\OneC\OneCSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;

/**
 * Builders for the 1C inbound API tests: tokens, envelopes and records shaped exactly like
 * Spec 02 section 9 (decimal strings, lowercase GUIDs, ISO datetimes with offset).
 */
trait InteractsWithOneCApi
{
    protected string $plainToken = '';

    protected ?IntegrationToken $token = null;

    protected function enableInbound(bool $enabled = true): void
    {
        Setting::putMany([OneCSettings::INBOUND_ENABLED_KEY => $enabled ? '1' : '0']);
    }

    /**
     * @param  list<string>|null  $abilities
     * @param  list<string>|null  $ips
     */
    protected function issueToken(?array $abilities = null, ?array $ips = null, bool $withSecret = false): IntegrationToken
    {
        $issued = IntegrationToken::issue('1C test', $abilities ?? IntegrationAbility::values(), $ips, 30, $withSecret);
        $this->plainToken = $issued['plain'];
        $this->token = $issued['token'];

        return $issued['token'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function onec(string $method, string $path, array $body = [], ?string $token = null, array $headers = []): TestResponse
    {
        $token ??= $this->plainToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', ...$headers])
            ->json($method, '/api/v1/integration/1c'.$path, $body);
    }

    /**
     * @param  array<string, mixed>  $sections
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    protected function envelope(array $sections, string $mode = 'delta', ?array $snapshot = null): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'mode' => $mode,
            'sent_at' => '2026-10-16T14:05:00+05:00',
            'snapshot' => $snapshot,
            'source' => ['infobase' => 'UT_UZ', 'exchange_node' => 'CRM', 'message_no' => 1],
            ...$sections,
        ];
    }

    protected function guid(string $seed): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'crm-test:'.$seed)->toString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function unitRecord(string $code = '796', string $name = 'pcs', array $overrides = []): array
    {
        return ['id' => $this->guid('unit-'.$code), 'code' => $code, 'name' => $name, 'is_deleted' => false, 'updated_at' => '2026-10-01T10:00:00+05:00', ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupRecord(string $key, ?string $parentKey = null, array $overrides = []): array
    {
        return [
            'id' => $this->guid('group-'.$key), 'code' => strtoupper($key), 'name' => 'Group '.$key,
            'parent_id' => $parentKey === null ? null : $this->guid('group-'.$parentKey),
            'is_deleted' => false, 'updated_at' => '2026-10-01T10:00:00+05:00', ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemRecord(string $code, array $overrides = []): array
    {
        return [
            'id' => $this->guid('item-'.$code), 'code' => $code, 'article' => 'ART-'.$code, 'name' => 'Item '.$code,
            'full_name' => 'Item '.$code.' full', 'description' => null, 'type' => 'product', 'group_id' => null,
            'unit_id' => $this->guid('unit-796'), 'vat_rate' => '12', 'is_deleted' => false, 'version' => 'AAAAAwAAAAA=',
            'updated_at' => '2026-10-16T09:12:44+05:00', ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function warehouseRecord(string $key = 'tas', array $overrides = []): array
    {
        return ['id' => $this->guid('wh-'.$key), 'code' => strtoupper($key), 'name' => 'Warehouse '.$key, 'is_deleted' => false, 'updated_at' => '2026-01-10T09:00:00+05:00', ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stockRecord(string $itemCode, string $quantity, string $warehouse = 'tas', string $reserved = '0.000'): array
    {
        return ['item_id' => $this->guid('item-'.$itemCode), 'warehouse_id' => $this->guid('wh-'.$warehouse), 'quantity' => $quantity, 'reserved' => $reserved];
    }

    /**
     * Sends units + the given items so later price/stock tests have something to reference.
     *
     * @param  list<string>  $codes
     */
    protected function seedItemsThroughApi(array $codes): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => array_map(fn (string $code) => $this->itemRecord($code), $codes),
        ]))->assertStatus(202);
    }
}
