<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Initial reference data (Spec 03 section 24.7): industries, currencies, contact positions,
 * supplier categories and the regions/cities of Uzbekistan with names in uz, ru and en.
 * Installed once by migration `2026_10_19_114835`; afterwards the lists are edited in
 * Settings > Reference lists. `install()` is idempotent (existing codes are kept).
 */
final class ReferenceSeed
{
    /**
     * @return array<string, array<string, array{0: string, 1: string, 2: string}>> list => code => [uz, ru, en]
     */
    public static function lists(): array
    {
        return [
            'industry' => [
                'retail' => ['Chakana savdo', 'Розничная торговля', 'Retail'],
                'wholesale' => ['Ulgurji savdo', 'Оптовая торговля', 'Wholesale'],
                'manufacturing' => ['Ishlab chiqarish', 'Производство', 'Manufacturing'],
                'construction' => ['Qurilish', 'Строительство', 'Construction'],
                'healthcare' => ['Sog‘liqni saqlash', 'Здравоохранение', 'Healthcare'],
                'education' => ['Ta’lim', 'Образование', 'Education'],
                'government' => ['Davlat sektori', 'Государственный сектор', 'Government'],
                'logistics' => ['Logistika va transport', 'Логистика и транспорт', 'Logistics & transport'],
                'it_telecom' => ['IT va telekommunikatsiya', 'IT и телеком', 'IT & telecom'],
                'finance' => ['Moliya', 'Финансы', 'Finance'],
                'agriculture' => ['Qishloq xo‘jaligi', 'Сельское хозяйство', 'Agriculture'],
                'hospitality' => ['Mehmonxona va restoranlar', 'Гостиницы и рестораны', 'Hospitality'],
                'other' => ['Boshqa', 'Другое', 'Other'],
            ],
            'currency' => [
                'UZS' => ['O‘zbek so‘mi', 'Узбекский сум', 'Uzbek sum'],
                'USD' => ['AQSh dollari', 'Доллар США', 'US dollar'],
                'EUR' => ['Yevro', 'Евро', 'Euro'],
                'RUB' => ['Rossiya rubli', 'Российский рубль', 'Russian rouble'],
                'CNY' => ['Xitoy yuani', 'Китайский юань', 'Chinese yuan'],
            ],
            'contact_position' => [
                'director' => ['Direktor', 'Директор', 'Director'],
                'chief_accountant' => ['Bosh hisobchi', 'Главный бухгалтер', 'Chief accountant'],
                'purchase_manager' => ['Xarid menejeri', 'Менеджер по закупкам', 'Purchase manager'],
                'logistics_manager' => ['Logistika menejeri', 'Менеджер по логистике', 'Logistics manager'],
                'engineer' => ['Muhandis', 'Инженер', 'Engineer'],
                'owner' => ['Egasi', 'Владелец', 'Owner'],
            ],
            'supplier_category' => [
                'manufacturer' => ['Ishlab chiqaruvchi', 'Производитель', 'Manufacturer'],
                'distributor' => ['Distribyutor', 'Дистрибьютор', 'Distributor'],
                'service_provider' => ['Xizmat ko‘rsatuvchi', 'Поставщик услуг', 'Service provider'],
                'customs_broker' => ['Bojxona brokeri', 'Таможенный брокер', 'Customs broker'],
                'forwarder' => ['Ekspeditor', 'Экспедитор', 'Forwarder'],
                'carrier' => ['Tashuvchi', 'Перевозчик', 'Carrier'],
            ],
        ];
    }

    /**
     * Regions of Uzbekistan with their main cities/districts.
     *
     * @return array<string, array{names: array{0: string, 1: string, 2: string}, cities: array<string, array{0: string, 1: string, 2: string}>}>
     */
    public static function regions(): array
    {
        return [
            'tashkent_city' => ['names' => ['Toshkent shahri', 'город Ташкент', 'Tashkent city'], 'cities' => [
                'tashkent' => ['Toshkent', 'Ташкент', 'Tashkent'],
                'yunusobod' => ['Yunusobod tumani', 'Юнусабадский район', 'Yunusabad district'],
                'chilonzor' => ['Chilonzor tumani', 'Чиланзарский район', 'Chilanzar district'],
                'mirzo_ulugbek' => ['Mirzo Ulug‘bek tumani', 'Мирзо-Улугбекский район', 'Mirzo Ulugbek district'],
                'yakkasaroy' => ['Yakkasaroy tumani', 'Яккасарайский район', 'Yakkasaray district'],
                'shayxontohur' => ['Shayxontohur tumani', 'Шайхантахурский район', 'Shaykhantakhur district'],
                'olmazor' => ['Olmazor tumani', 'Алмазарский район', 'Almazar district'],
                'uchtepa' => ['Uchtepa tumani', 'Учтепинский район', 'Uchtepa district'],
                'mirobod' => ['Mirobod tumani', 'Мирабадский район', 'Mirabad district'],
                'sergeli' => ['Sergeli tumani', 'Сергелийский район', 'Sergeli district'],
                'bektemir' => ['Bektemir tumani', 'Бектемирский район', 'Bektemir district'],
                'yashnobod' => ['Yashnobod tumani', 'Яшнабадский район', 'Yashnabad district'],
                'yangihayot' => ['Yangihayot tumani', 'Янгихаётский район', 'Yangikhayot district'],
            ]],
            'tashkent_region' => ['names' => ['Toshkent viloyati', 'Ташкентская область', 'Tashkent region'], 'cities' => [
                'nurafshon' => ['Nurafshon', 'Нурафшан', 'Nurafshon'],
                'chirchiq' => ['Chirchiq', 'Чирчик', 'Chirchiq'],
                'olmaliq' => ['Olmaliq', 'Алмалык', 'Almalyk'],
                'angren' => ['Angren', 'Ангрен', 'Angren'],
                'bekobod' => ['Bekobod', 'Бекабад', 'Bekabad'],
                'yangiyol' => ['Yangiyo‘l', 'Янгиюль', 'Yangiyul'],
                'ohangaron' => ['Ohangaron', 'Ахангаран', 'Akhangaran'],
            ]],
            'karakalpakstan' => ['names' => ['Qoraqalpog‘iston Respublikasi', 'Республика Каракалпакстан', 'Republic of Karakalpakstan'], 'cities' => [
                'nukus' => ['Nukus', 'Нукус', 'Nukus'],
                'xojayli' => ['Xo‘jayli', 'Ходжейли', 'Khodjeyli'],
                'beruniy' => ['Beruniy', 'Беруни', 'Beruniy'],
                'tortkol' => ['To‘rtko‘l', 'Турткуль', 'Turtkul'],
            ]],
            'andijan' => ['names' => ['Andijon viloyati', 'Андижанская область', 'Andijan region'], 'cities' => [
                'andijan' => ['Andijon', 'Андижан', 'Andijan'],
                'asaka' => ['Asaka', 'Асака', 'Asaka'],
                'xonobod' => ['Xonobod', 'Ханабад', 'Khanabad'],
                'shahrixon' => ['Shahrixon', 'Шахрихан', 'Shakhrikhan'],
            ]],
            'bukhara' => ['names' => ['Buxoro viloyati', 'Бухарская область', 'Bukhara region'], 'cities' => [
                'bukhara' => ['Buxoro', 'Бухара', 'Bukhara'],
                'kogon' => ['Kogon', 'Каган', 'Kagan'],
                'gijduvon' => ['G‘ijduvon', 'Гиждуван', 'Gijduvan'],
            ]],
            'fergana' => ['names' => ['Farg‘ona viloyati', 'Ферганская область', 'Fergana region'], 'cities' => [
                'fergana' => ['Farg‘ona', 'Фергана', 'Fergana'],
                'kokand' => ['Qo‘qon', 'Коканд', 'Kokand'],
                'margilan' => ['Marg‘ilon', 'Маргилан', 'Margilan'],
                'quvasoy' => ['Quvasoy', 'Кувасай', 'Kuvasay'],
            ]],
            'jizzakh' => ['names' => ['Jizzax viloyati', 'Джизакская область', 'Jizzakh region'], 'cities' => [
                'jizzakh' => ['Jizzax', 'Джизак', 'Jizzakh'],
                'gallaorol' => ['G‘allaorol', 'Галляарал', 'Gallaaral'],
            ]],
            'kashkadarya' => ['names' => ['Qashqadaryo viloyati', 'Кашкадарьинская область', 'Kashkadarya region'], 'cities' => [
                'karshi' => ['Qarshi', 'Карши', 'Karshi'],
                'shahrisabz' => ['Shahrisabz', 'Шахрисабз', 'Shakhrisabz'],
                'kitob' => ['Kitob', 'Китаб', 'Kitab'],
                'guzor' => ['G‘uzor', 'Гузар', 'Guzar'],
            ]],
            'khorezm' => ['names' => ['Xorazm viloyati', 'Хорезмская область', 'Khorezm region'], 'cities' => [
                'urgench' => ['Urganch', 'Ургенч', 'Urgench'],
                'khiva' => ['Xiva', 'Хива', 'Khiva'],
            ]],
            'namangan' => ['names' => ['Namangan viloyati', 'Наманганская область', 'Namangan region'], 'cities' => [
                'namangan' => ['Namangan', 'Наманган', 'Namangan'],
                'chust' => ['Chust', 'Чуст', 'Chust'],
                'pop' => ['Pop', 'Пап', 'Pap'],
            ]],
            'navoi' => ['names' => ['Navoiy viloyati', 'Навоийская область', 'Navoi region'], 'cities' => [
                'navoi' => ['Navoiy', 'Навои', 'Navoi'],
                'zarafshon' => ['Zarafshon', 'Зарафшан', 'Zarafshan'],
                'uchquduq' => ['Uchquduq', 'Учкудук', 'Uchkuduk'],
            ]],
            'samarkand' => ['names' => ['Samarqand viloyati', 'Самаркандская область', 'Samarkand region'], 'cities' => [
                'samarkand' => ['Samarqand', 'Самарканд', 'Samarkand'],
                'kattakurgan' => ['Kattaqo‘rg‘on', 'Каттакурган', 'Kattakurgan'],
                'urgut' => ['Urgut', 'Ургут', 'Urgut'],
            ]],
            'surkhandarya' => ['names' => ['Surxondaryo viloyati', 'Сурхандарьинская область', 'Surkhandarya region'], 'cities' => [
                'termez' => ['Termiz', 'Термез', 'Termez'],
                'denov' => ['Denov', 'Денау', 'Denau'],
                'sherobod' => ['Sherobod', 'Шерабад', 'Sherabad'],
            ]],
            'syrdarya' => ['names' => ['Sirdaryo viloyati', 'Сырдарьинская область', 'Syrdarya region'], 'cities' => [
                'gulistan' => ['Guliston', 'Гулистан', 'Gulistan'],
                'yangiyer' => ['Yangiyer', 'Янгиер', 'Yangiyer'],
                'shirin' => ['Shirin', 'Ширин', 'Shirin'],
            ]],
        ];
    }

    /** Installs the lists and places that are missing (keeps existing rows). */
    public static function install(): void
    {
        $now = now();

        foreach (self::lists() as $list => $items) {
            $sort = 0;

            foreach ($items as $code => [$uz, $ru, $en]) {
                $sort += 10;
                DB::table('reference_items')->insertOrIgnore([
                    'list' => $list, 'code' => $code, 'names' => json_encode(['uz' => $uz, 'ru' => $ru, 'en' => $en], JSON_UNESCAPED_UNICODE),
                    'is_active' => true, 'sort_order' => $sort, 'source' => 'seed', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $regionSort = 0;

        foreach (self::regions() as $code => $region) {
            $regionSort += 10;
            DB::table('geo_places')->insertOrIgnore([
                'kind' => 'region', 'code' => $code, 'parent_id' => null, 'country' => 'UZ',
                'names' => json_encode(array_combine(['uz', 'ru', 'en'], $region['names']), JSON_UNESCAPED_UNICODE), 'sort_order' => $regionSort, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $regionId = DB::table('geo_places')->where('kind', 'region')->where('code', $code)->value('id');
            $citySort = 0;

            foreach ($region['cities'] as $cityCode => $names) {
                $citySort += 10;
                DB::table('geo_places')->insertOrIgnore([
                    'kind' => 'city', 'code' => $code.'.'.$cityCode, 'parent_id' => $regionId, 'country' => 'UZ',
                    'names' => json_encode(array_combine(['uz', 'ru', 'en'], $names), JSON_UNESCAPED_UNICODE), 'sort_order' => $citySort, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
