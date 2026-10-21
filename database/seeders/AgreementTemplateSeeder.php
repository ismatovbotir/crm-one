<?php

namespace Database\Seeders;

use App\Actions\Agreements\ManageAgreementTemplates;
use App\Enums\AgreementKind;
use App\Models\AgreementTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Default agreement templates (Spec 06 7.8) in uz/ru/en: supply agreement for an order, framework
 * supply agreement and addendum. Idempotent (skips templates whose code exists); published through
 * ManageAgreementTemplates so the lint and sanitizer run. Finance should review the texts with the
 * company lawyer before go-live.
 */
class AgreementTemplateSeeder extends Seeder
{
    public function run(ManageAgreementTemplates $manage): void
    {
        $publisher = User::query()->where('role', 'finance')->first() ?? User::query()->orderBy('id')->first();

        if ($publisher === null) {
            return;
        }

        foreach ($this->templates() as $code => $definition) {
            if (AgreementTemplate::query()->where('code', $code)->exists()) {
                continue;
            }

            $template = $manage->create($code, $definition['name'], $definition['kind'], $publisher);

            foreach ($definition['texts'] as $locale => $text) {
                $draft = $manage->saveDraft($template, $locale, $text['body'], $text['clauses']);
                $manage->publish($draft, $publisher);
            }
        }
    }

    /**
     * @return array<string, array{name: string, kind: AgreementKind, texts: array<string, array{body: string, clauses: list<array{code: string, label: string, default: bool}>}>}>
     */
    protected function templates(): array
    {
        return [
            'order-standard' => [
                'name' => 'Supply agreement (order)',
                'kind' => AgreementKind::Order,
                'texts' => [
                    'uz' => ['body' => $this->orderUz(), 'clauses' => [['code' => 'penalty', 'label' => 'Kechiktirish uchun penya', 'default' => true], ['code' => 'confidentiality', 'label' => 'Maxfiylik', 'default' => false]]],
                    'ru' => ['body' => $this->orderRu(), 'clauses' => [['code' => 'penalty', 'label' => 'Пеня за просрочку', 'default' => true], ['code' => 'confidentiality', 'label' => 'Конфиденциальность', 'default' => false]]],
                    'en' => ['body' => $this->orderEn(), 'clauses' => [['code' => 'penalty', 'label' => 'Late payment penalty', 'default' => true], ['code' => 'confidentiality', 'label' => 'Confidentiality', 'default' => false]]],
                ],
            ],
            'framework-standard' => [
                'name' => 'Framework supply agreement',
                'kind' => AgreementKind::Framework,
                'texts' => [
                    'uz' => ['body' => $this->frameworkUz(), 'clauses' => []],
                    'ru' => ['body' => $this->frameworkRu(), 'clauses' => []],
                    'en' => ['body' => $this->frameworkEn(), 'clauses' => []],
                ],
            ],
            'addendum-standard' => [
                'name' => 'Addendum',
                'kind' => AgreementKind::Addendum,
                'texts' => [
                    'uz' => ['body' => '<h1>Qo‘shimcha kelishuv № {{ agreement.number }}</h1><p>{{ company.city }}, {{ agreement.date }}</p><p>{{ company.legal_name }} (Yetkazib beruvchi) va {{ customer.legal_name }} (Xaridor) {{ parent.number }}-sonli shartnomaga ({{ parent.date }}) quyidagilar haqida kelishdilar:</p><p>1. ________________________________________________</p><p>2. Shartnomaning boshqa shartlari o‘zgarishsiz qoladi.</p><p>3. Ushbu kelishuv imzolangan kundan kuchga kiradi va shartnomaning ajralmas qismi hisoblanadi.</p>', 'clauses' => []],
                    'ru' => ['body' => '<h1>Дополнительное соглашение № {{ agreement.number }}</h1><p>{{ company.city }}, {{ agreement.date }}</p><p>{{ company.legal_name }} (Поставщик) и {{ customer.legal_name }} (Покупатель) к договору № {{ parent.number }} от {{ parent.date }} договорились о следующем:</p><p>1. ________________________________________________</p><p>2. Остальные условия договора остаются без изменений.</p><p>3. Настоящее соглашение вступает в силу с даты подписания и является неотъемлемой частью договора.</p>', 'clauses' => []],
                    'en' => ['body' => '<h1>Addendum No. {{ agreement.number }}</h1><p>{{ company.city }}, {{ agreement.date }}</p><p>{{ company.legal_name }} (Supplier) and {{ customer.legal_name }} (Buyer) agree as follows to agreement No. {{ parent.number }} of {{ parent.date }}:</p><p>1. ________________________________________________</p><p>2. All other terms of the agreement remain unchanged.</p><p>3. This addendum enters into force on signature and forms an integral part of the agreement.</p>', 'clauses' => []],
                ],
            ],
        ];
    }

    protected function orderUz(): string
    {
        return <<<'HTML'
<h1>Mahsulot yetkazib berish shartnomasi № {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }}, keyingi o‘rinlarda «Yetkazib beruvchi», {{ signatory.basis }} asosida faoliyat yurituvchi {{ signatory.position }} {{ signatory.name }} nomidan, bir tomondan, va {{ customer.legal_name }}, keyingi o‘rinlarda «Xaridor», {{ customer.signatory_basis }} asosida faoliyat yurituvchi {{ customer.signatory_position }} {{ customer.signatory_name }} nomidan, ikkinchi tomondan, quyidagilar haqida ushbu shartnomani tuzdilar.</p>
<h2>1. Shartnoma predmeti</h2>
<p>1.1. Yetkazib beruvchi {{ order.number }}-sonli buyurtmaga muvofiq quyidagi tovarlar va xizmatlarni yetkazib berish, Xaridor esa ularni qabul qilib, haqini to‘lash majburiyatini oladi:</p>
{{ order.lines_table }}
<h2>2. Narx va to‘lov tartibi</h2>
<p>2.1. Shartnomaning umumiy summasi {{ order.total }} ({{ order.total_in_words }}), shu jumladan QQS {{ order.vat }}.</p>
<p>2.2. To‘lov shartlari: {{ terms.payment }}</p>
<h2>3. Yetkazib berish</h2>
<p>3.1. Yetkazib berish shartlari: {{ terms.delivery }}</p>
<p>3.2. Yetkazib berish manzili: {{ order.delivery_address }}</p>
<h2>4. Kafolat</h2>
<p>4.1. {{ terms.warranty }}</p>
<h2>5. Tomonlarning javobgarligi</h2>
<p>5.1. Tomonlar o‘z majburiyatlarini bajarmaganlik yoki lozim darajada bajarmaganlik uchun O‘zbekiston Respublikasi qonunchiligiga muvofiq javobgar bo‘ladilar.</p>
{{#clause:penalty}}<p>5.2. To‘lov muddati kechiktirilganda Xaridor kechiktirilgan har bir kun uchun to‘lanmagan summaning 0,1 foizi miqdorida, biroq to‘lanmagan summaning 10 foizidan ko‘p bo‘lmagan penya to‘laydi.</p>{{/clause}}
{{#clause:confidentiality}}<p>5.3. Tomonlar ushbu shartnoma bo‘yicha olingan ma’lumotlarni uchinchi shaxslarga oshkor qilmaslik majburiyatini oladilar.</p>{{/clause}}
<h2>6. Nizolarni hal qilish</h2>
<p>6.1. Nizolar muzokaralar yo‘li bilan, kelishuvga erishilmaganda esa O‘zbekiston Respublikasi iqtisodiy sudida hal qilinadi.</p>
<h2>7. Amal qilish muddati</h2>
<p>7.1. Shartnoma imzolangan kundan kuchga kiradi va {{ agreement.ends_on }} gacha, majburiyatlar to‘liq bajarilgunga qadar amal qiladi.</p>
HTML;
    }

    protected function orderRu(): string
    {
        return <<<'HTML'
<h1>Договор поставки № {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }}, именуемое в дальнейшем «Поставщик», в лице {{ signatory.position }} {{ signatory.name }}, действующего на основании {{ signatory.basis }}, с одной стороны, и {{ customer.legal_name }}, именуемое в дальнейшем «Покупатель», в лице {{ customer.signatory_position }} {{ customer.signatory_name }}, действующего на основании {{ customer.signatory_basis }}, с другой стороны, заключили настоящий договор о нижеследующем.</p>
<h2>1. Предмет договора</h2>
<p>1.1. Поставщик обязуется поставить, а Покупатель принять и оплатить товары и услуги согласно заказу № {{ order.number }}:</p>
{{ order.lines_table }}
<h2>2. Цена и порядок оплаты</h2>
<p>2.1. Общая сумма договора составляет {{ order.total }} ({{ order.total_in_words }}), в том числе НДС {{ order.vat }}.</p>
<p>2.2. Условия оплаты: {{ terms.payment }}</p>
<h2>3. Поставка</h2>
<p>3.1. Условия поставки: {{ terms.delivery }}</p>
<p>3.2. Адрес поставки: {{ order.delivery_address }}</p>
<h2>4. Гарантия</h2>
<p>4.1. {{ terms.warranty }}</p>
<h2>5. Ответственность сторон</h2>
<p>5.1. За неисполнение или ненадлежащее исполнение обязательств стороны несут ответственность в соответствии с законодательством Республики Узбекистан.</p>
{{#clause:penalty}}<p>5.2. При просрочке оплаты Покупатель уплачивает пеню в размере 0,1% от неоплаченной суммы за каждый день просрочки, но не более 10% от неоплаченной суммы.</p>{{/clause}}
{{#clause:confidentiality}}<p>5.3. Стороны обязуются не раскрывать третьим лицам сведения, полученные в рамках настоящего договора.</p>{{/clause}}
<h2>6. Разрешение споров</h2>
<p>6.1. Споры решаются путём переговоров, а при недостижении согласия — в экономическом суде Республики Узбекистан.</p>
<h2>7. Срок действия</h2>
<p>7.1. Договор вступает в силу с даты подписания и действует до {{ agreement.ends_on }}, а в части обязательств — до их полного исполнения.</p>
HTML;
    }

    protected function orderEn(): string
    {
        return <<<'HTML'
<h1>Supply agreement No. {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }} (the “Supplier”), represented by {{ signatory.position }} {{ signatory.name }}, acting on the basis of {{ signatory.basis }}, and {{ customer.legal_name }} (the “Buyer”), represented by {{ customer.signatory_position }} {{ customer.signatory_name }}, acting on the basis of {{ customer.signatory_basis }}, have concluded this agreement as follows.</p>
<h2>1. Subject</h2>
<p>1.1. The Supplier shall deliver, and the Buyer shall accept and pay for, the goods and services under order No. {{ order.number }}:</p>
{{ order.lines_table }}
<h2>2. Price and payment</h2>
<p>2.1. The total value of the agreement is {{ order.total }} ({{ order.total_in_words }}), including VAT of {{ order.vat }}.</p>
<p>2.2. Payment terms: {{ terms.payment }}</p>
<h2>3. Delivery</h2>
<p>3.1. Delivery terms: {{ terms.delivery }}</p>
<p>3.2. Delivery address: {{ order.delivery_address }}</p>
<h2>4. Warranty</h2>
<p>4.1. {{ terms.warranty }}</p>
<h2>5. Liability</h2>
<p>5.1. The parties are liable for non-performance or improper performance in accordance with the laws of the Republic of Uzbekistan.</p>
{{#clause:penalty}}<p>5.2. For late payment the Buyer pays a penalty of 0.1% of the unpaid amount per day of delay, capped at 10% of the unpaid amount.</p>{{/clause}}
{{#clause:confidentiality}}<p>5.3. The parties shall not disclose to third parties information received under this agreement.</p>{{/clause}}
<h2>6. Disputes</h2>
<p>6.1. Disputes are settled by negotiation or, failing agreement, by the economic court of the Republic of Uzbekistan.</p>
<h2>7. Term</h2>
<p>7.1. The agreement enters into force on signature and remains in force until {{ agreement.ends_on }}, and for outstanding obligations until they are fully performed.</p>
HTML;
    }

    protected function frameworkUz(): string
    {
        return <<<'HTML'
<h1>Mahsulot yetkazib berish to‘g‘risidagi ramkaviy shartnoma № {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }} («Yetkazib beruvchi») {{ signatory.position }} {{ signatory.name }} nomidan va {{ customer.legal_name }} («Xaridor») {{ customer.signatory_position }} {{ customer.signatory_name }} nomidan ushbu shartnomani tuzdilar.</p>
<h2>1. Predmet</h2>
<p>1.1. Yetkazib beruvchi Xaridorning tasdiqlangan buyurtmalariga muvofiq tovarlar va xizmatlarni yetkazib beradi. Har bir buyurtmada nomenklatura, miqdor, narx va muddatlar ko‘rsatiladi.</p>
<h2>2. Narx va to‘lov</h2>
<p>2.1. Narxlar har bir tasdiqlangan buyurtmada belgilanadi. To‘lov buyurtmada ko‘rsatilgan shartlarda amalga oshiriladi.</p>
<h2>3. Amal qilish muddati</h2>
<p>3.1. Shartnoma {{ agreement.starts_on }} dan {{ agreement.ends_on }} gacha amal qiladi.</p>
HTML;
    }

    protected function frameworkRu(): string
    {
        return <<<'HTML'
<h1>Рамочный договор поставки № {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }} («Поставщик») в лице {{ signatory.position }} {{ signatory.name }} и {{ customer.legal_name }} («Покупатель») в лице {{ customer.signatory_position }} {{ customer.signatory_name }} заключили настоящий договор.</p>
<h2>1. Предмет</h2>
<p>1.1. Поставщик поставляет товары и услуги по подтверждённым заказам Покупателя. В каждом заказе указываются номенклатура, количество, цена и сроки.</p>
<h2>2. Цена и оплата</h2>
<p>2.1. Цены определяются в каждом подтверждённом заказе. Оплата производится на условиях, указанных в заказе.</p>
<h2>3. Срок действия</h2>
<p>3.1. Договор действует с {{ agreement.starts_on }} по {{ agreement.ends_on }}.</p>
HTML;
    }

    protected function frameworkEn(): string
    {
        return <<<'HTML'
<h1>Framework supply agreement No. {{ agreement.number }}</h1>
<p>{{ company.city }}, {{ agreement.date }}</p>
<p>{{ company.legal_name }} (the “Supplier”), represented by {{ signatory.position }} {{ signatory.name }}, and {{ customer.legal_name }} (the “Buyer”), represented by {{ customer.signatory_position }} {{ customer.signatory_name }}, have concluded this agreement.</p>
<h2>1. Subject</h2>
<p>1.1. The Supplier delivers goods and services under the Buyer’s confirmed orders. Each order states the items, quantities, prices and dates.</p>
<h2>2. Price and payment</h2>
<p>2.1. Prices are set in each confirmed order. Payment is made on the terms stated in the order.</p>
<h2>3. Term</h2>
<p>3.1. The agreement is in force from {{ agreement.starts_on }} to {{ agreement.ends_on }}.</p>
HTML;
    }
}
