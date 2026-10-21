<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Dev/demo stand-in for the 1C catalog feed (Spec 02 I-2..I-4) until the inbound API exists:
 * units, a 3-level item group tree, items, price types with dated prices, warehouses and stock.
 * Idempotent (matched by code / SKU). Never runs in production.
 */
class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping demo catalog in production: the catalog comes from 1C.');

            return;
        }

        // One transaction: atomic and much faster on SQLite.
        DB::transaction(fn () => $this->seedDemoData());
    }

    private function seedDemoData(): void
    {

        $syncedAt = now()->subMinutes(7);

        $units = [];

        foreach ([
            ['796', 'pcs'], ['166', 'kg'], ['112', 'l'], ['006', 'm'], ['055', 'm2'], ['356', 'h'], ['839', 'set'], ['362', 'month'],
        ] as [$code, $name]) {
            $units[$name] = Unit::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'precision' => (int) (config('onec.okei_precision')[$code] ?? 0),
                'is_active' => true,
                'external_id' => $this->guid('unit-'.$code),
                'external_code' => $code,
                'synced_at' => $syncedAt,
            ]);
        }

        $category = fn (string $key, string $name, ?Category $parent = null, int $order = 0): Category => Category::query()->updateOrCreate(
            ['external_id' => $this->guid('group-'.$key)],
            ['name' => $name, 'parent_id' => $parent?->id, 'sort_order' => $order, 'is_active' => true, 'external_code' => strtoupper($key), 'synced_at' => $syncedAt],
        );

        $networking = $category('net', 'Networking', null, 10);
        $routers = $category('net-routers', 'Routers', $networking, 10);
        $switches = $category('net-switches', 'Switches', $networking, 20);
        $managed = $category('net-switches-managed', 'Managed switches', $switches, 10);
        $cables = $category('cab', 'Cables and accessories', null, 20);
        $fibre = $category('cab-fibre', 'Fibre optic', $cables, 10);
        $copper = $category('cab-copper', 'Copper', $cables, 20);
        $services = $category('srv', 'Services', null, 30);
        $installation = $category('srv-install', 'Installation', $services, 10);
        $support = $category('srv-support', 'Support contracts', $services, 20);

        $tashkent = Warehouse::query()->updateOrCreate(['external_id' => $this->guid('wh-tas')], ['name' => 'Main warehouse Tashkent', 'is_active' => true, 'external_code' => '000000001', 'synced_at' => $syncedAt]);
        $samarkand = Warehouse::query()->updateOrCreate(['external_id' => $this->guid('wh-skd')], ['name' => 'Samarkand branch', 'is_active' => true, 'external_code' => '000000002', 'synced_at' => $syncedAt]);

        // [sku, article, name, category, unit, type, retail price (UZS, incl. VAT), vat bp]
        $catalog = [
            ['00-00001001', 'RX-200', 'Industrial router RX-200', $routers, 'pcs', ItemType::Product, '3640000.00', 1200],
            ['00-00001002', 'RX-500', 'Industrial router RX-500 LTE', $routers, 'pcs', ItemType::Product, '6250000.00', 1200],
            ['00-00001003', 'HR-10', 'Home office router HR-10', $routers, 'pcs', ItemType::Product, '890000.00', 1200],
            ['00-00002001', 'SW-8U', 'Unmanaged switch 8 ports', $switches, 'pcs', ItemType::Product, '410000.00', 1200],
            ['00-00002002', 'SW-24M', 'Managed switch 24 ports', $managed, 'pcs', ItemType::Product, '5480000.00', 1200],
            ['00-00002003', 'SW-48M', 'Managed switch 48 ports PoE', $managed, 'pcs', ItemType::Product, '11900000.00', 1200],
            ['00-00003001', 'FO-SM', 'Fibre optic cable, single-mode', $fibre, 'm', ItemType::Product, '4700.00', 1200],
            ['00-00003002', 'FO-MM', 'Fibre optic cable, multi-mode', $fibre, 'm', ItemType::Product, '5200.00', 1200],
            ['00-00003003', 'FO-PC', 'Fibre patch cord LC-LC 3 m', $fibre, 'pcs', ItemType::Product, '38000.00', 1200],
            ['00-00004001', 'UTP-6', 'UTP cable Cat. 6', $copper, 'm', ItemType::Product, '3100.00', 1200],
            ['00-00004002', 'RJ45-100', 'RJ45 connectors, box of 100', $copper, 'set', ItemType::Product, '96000.00', 1200],
            ['00-00004003', 'CU-BULK', 'Copper scrap, bulk', $copper, 'kg', ItemType::Product, '98000.00', 0],
            ['00-00005001', null, 'Installation and configuration', $installation, 'h', ItemType::Service, '180000.00', 1200],
            ['00-00005002', null, 'Site survey', $installation, 'h', ItemType::Service, '150000.00', 1200],
            ['00-00006001', null, 'Support contract, standard', $support, 'month', ItemType::Service, '1500000.00', 1200],
            ['00-00006002', null, 'Support contract, premium 24/7', $support, 'month', ItemType::Service, '4200000.00', 1200],
            ['00-00007001', 'OLD-RT', 'Legacy router (discontinued)', $routers, 'pcs', ItemType::Product, '500000.00', 1200],
        ];

        // Demo accounting codes (Spec 02 BR-I.13a; some items left empty on purpose) and serial-tracked items (BR-I.13b).
        $codes = [
            '00-00001001' => ['41.01-1001', '08517620001000000', '1503778'],
            '00-00001002' => ['41.01-1002', '08517620001000000', '1503778'],
            '00-00002002' => ['41.01-2002', '08517620002000000', '1503778'],
            '00-00003001' => ['41.01-3001', '08544700001000000', '1517498'],
            '00-00005001' => [null, '10101001001000000', null],
        ];
        $serialized = ['00-00001001', '00-00001002', '00-00002002', '00-00002003'];

        foreach ($catalog as $index => [$sku, $article, $name, $group, $unit, $type, $retailPrice, $vat]) {
            [$accountantCode, $classCode, $packageCode] = $codes[$sku] ?? [null, null, null];

            $item = Item::withTrashed()->updateOrCreate(['sku' => $sku], [
                'accountant_code' => $accountantCode,
                'class_code' => $classCode,
                'package_code' => $packageCode,
                'is_serialized' => in_array($sku, $serialized, true),
                'article' => $article,
                'name' => $name,
                'type' => $type,
                'category_id' => $group->id,
                'unit_id' => $units[$unit]->id,
                'tax_rate_bp' => $vat,
                'is_vat_exempt' => $vat === 0,
                'is_active' => $sku !== '00-00007001',
                'external_id' => $this->guid('item-'.$sku),
                'external_code' => $sku,
                'synced_at' => $syncedAt,
            ]);

            // Rev. 4: one sale price (retail, incl. VAT) and one purchase price (net, about 70% of net sale).
            $retailMinor = (int) bcmul($retailPrice, '100', 0);
            $netMinor = (int) bcdiv(bcmul((string) $retailMinor, '10000', 0), (string) (10000 + $vat), 0);
            $purchaseMinor = (int) bcdiv(bcmul((string) $netMinor, '70', 0), '100', 0);
            $item->forceFill([
                'sale_price_minor' => $retailMinor,
                'sale_currency' => 'UZS',
                'prices_include_vat' => true,
                'purchase_price_minor' => $type === ItemType::Product ? $purchaseMinor : null,
                'purchase_currency' => $type === ItemType::Product ? 'UZS' : null,
            ])->save();

            if ($type === ItemType::Product) {
                $this->stock($item, $tashkent, (string) (($index * 37) % 240 + 5), (string) ($index % 3 * 2), $syncedAt, $purchaseMinor);

                if ($index % 2 === 0) {
                    $this->stock($item, $samarkand, $index === 6 ? '-3' : (string) (($index * 11) % 60), '0', $syncedAt, (int) bcdiv(bcmul((string) $purchaseMinor, '105', 0), '100', 0));
                }
            }
        }
    }

    private function guid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://crm.test/demo/'.$key)->toString();
    }

    /**
     * Stock row with its total stock cost (rev. 4): quantity x unit cost (warehouses cost slightly differently).
     */
    private function stock(Item $item, Warehouse $warehouse, string $quantity, string $reserved, mixed $asOf, int $unitCostMinor): void
    {
        ItemStock::query()->updateOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $warehouse->id],
            [
                'quantity' => $quantity, 'reserved' => $reserved, 'as_of' => $asOf, 'received_at' => $asOf,
                'cost_minor' => (int) bcmul($quantity, (string) $unitCostMinor, 0), 'cost_currency' => 'UZS',
            ],
        );
    }
}
