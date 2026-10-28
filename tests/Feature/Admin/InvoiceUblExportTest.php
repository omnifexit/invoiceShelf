<?php

use App\Models\Address;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\TaxType;
use App\Models\User;
use App\Services\UblInvoiceGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->company = $user->companies()->first();
    $this->withHeaders([
        'company' => $this->company->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

function ublPrepareParties(Company $company): Customer
{
    $company->update(['vat_id' => 'NL123456789B01']);

    Address::factory()->create([
        'company_id' => $company->id,
        'customer_id' => null,
        'user_id' => null,
        'type' => null,
        'address_street_1' => 'Kerkstraat 1',
        'address_street_2' => null,
        'city' => 'Amsterdam',
        'zip' => '1011AA',
        'state' => null,
        'country_id' => Country::where('code', 'NL')->value('id'),
    ]);

    $customer = Customer::factory()->create([
        'company_id' => $company->id,
        'name' => 'Klant',
        'company_name' => 'Klant BV',
        'tax_id' => 'BE0123456789',
    ]);

    Address::factory()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'user_id' => null,
        'type' => Address::BILLING_TYPE,
        'address_street_1' => 'Rue 2',
        'address_street_2' => null,
        'city' => 'Brussel',
        'zip' => '1000',
        'state' => null,
        'country_id' => Country::where('code', 'BE')->value('id'),
    ]);

    return $customer;
}

function ublTaxType(Company $company, float $percent, int $compound = 0): TaxType
{
    return TaxType::factory()->create([
        'company_id' => $company->id,
        'percent' => $percent,
        'compound_tax' => $compound,
    ]);
}

/**
 * Lines: ['price', 'quantity', 'discount_val', 'total', 'tax', 'percent' (null for no tax)].
 * Invoice taxes: list of percentages, for tax_per_item = NO.
 */
function ublCreateInvoice(Customer $customer, array $invoiceAttrs, array $lines, array $invoiceTaxes = []): Invoice
{
    $currencyId = Currency::where('code', 'EUR')->value('id');

    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $customer->company_id,
        'customer_id' => $customer->id,
        'currency_id' => $currencyId,
        'tax_per_item' => 'YES',
        'discount_per_item' => 'YES',
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'notes' => null,
    ], $invoiceAttrs));

    foreach ($lines as $line) {
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $customer->company_id,
            'name' => $line['name'] ?? 'Item',
            'description' => $line['description'] ?? null,
            'price' => $line['price'],
            'quantity' => $line['quantity'],
            'discount_type' => 'fixed',
            'discount' => $line['discount_val'] / 100,
            'discount_val' => $line['discount_val'],
            'total' => $line['total'],
            'tax' => $line['tax'],
        ]);

        foreach ((array) ($line['percent'] ?? []) as $percent) {
            $type = ublTaxType(Company::find($customer->company_id), $percent, $line['compound'] ?? 0);
            Tax::factory()->create([
                'invoice_item_id' => $item->id,
                'tax_type_id' => $type->id,
                'percent' => $percent,
                'amount' => $line['tax'],
                'compound_tax' => $line['compound'] ?? 0,
                'currency_id' => $currencyId,
            ]);
        }
    }

    foreach ($invoiceTaxes as $percent) {
        $type = ublTaxType(Company::find($customer->company_id), $percent);
        Tax::factory()->create([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => null,
            'tax_type_id' => $type->id,
            'percent' => $percent,
            'amount' => $invoice->tax,
            'compound_tax' => 0,
            'currency_id' => $currencyId,
        ]);
    }

    return $invoice;
}

function ublSimpleInvoice(Customer $customer, array $invoiceAttrs = []): Invoice
{
    return ublCreateInvoice($customer, array_merge([
        'sub_total' => 10000,
        'tax' => 2100,
        'total' => 12100,
        'due_amount' => 12100,
    ], $invoiceAttrs), [
        ['price' => 5000, 'quantity' => 2, 'discount_val' => 0, 'total' => 10000, 'tax' => 2100, 'percent' => 21],
    ]);
}

function ublParse(string $content): SimpleXMLElement
{
    $xml = simplexml_load_string($content);
    expect($xml)->toBeInstanceOf(SimpleXMLElement::class);

    $xml->registerXPathNamespace('inv', UblInvoiceGenerator::NAMESPACE_INVOICE);
    $xml->registerXPathNamespace('cac', UblInvoiceGenerator::NAMESPACE_CAC);
    $xml->registerXPathNamespace('cbc', UblInvoiceGenerator::NAMESPACE_CBC);

    return $xml;
}

function ublXml(Invoice $invoice): SimpleXMLElement
{
    return ublParse(get("api/v1/invoices/{$invoice->id}/ubl")->assertOk()->getContent());
}

function ublValue(SimpleXMLElement $xml, string $path): ?string
{
    $nodes = $xml->xpath($path);

    return $nodes ? (string) $nodes[0] : null;
}

function ublCents(string $amount): int
{
    $negative = str_starts_with($amount, '-');
    [$units, $decimals] = explode('.', ltrim($amount, '-'));
    $cents = ((int) $units) * 100 + (int) str_pad($decimals, 2, '0');

    return $negative ? -$cents : $cents;
}

function ublAmount(SimpleXMLElement $xml, string $path): int
{
    $value = ublValue($xml, $path);
    expect($value)->not->toBeNull("missing {$path}");

    return ublCents($value);
}

/**
 * @return list<int>
 */
function ublAmounts(SimpleXMLElement $xml, string $path): array
{
    return array_map(fn ($node) => ublCents((string) $node), $xml->xpath($path));
}

function ublAssertTotalsAddUp(SimpleXMLElement $xml): void
{
    $total = '/inv:Invoice/cac:LegalMonetaryTotal/';
    $lineExtension = ublAmount($xml, $total.'cbc:LineExtensionAmount');
    $allowanceTotal = ublValue($xml, $total.'cbc:AllowanceTotalAmount') !== null ? ublAmount($xml, $total.'cbc:AllowanceTotalAmount') : 0;
    $prepaid = ublValue($xml, $total.'cbc:PrepaidAmount') !== null ? ublAmount($xml, $total.'cbc:PrepaidAmount') : 0;
    $taxExclusive = ublAmount($xml, $total.'cbc:TaxExclusiveAmount');
    $taxInclusive = ublAmount($xml, $total.'cbc:TaxInclusiveAmount');
    $payable = ublAmount($xml, $total.'cbc:PayableAmount');
    $taxTotal = ublAmount($xml, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount');

    expect(array_sum(ublAmounts($xml, '/inv:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount')))->toBe($lineExtension);
    expect(array_sum(ublAmounts($xml, '/inv:Invoice/cac:AllowanceCharge[cbc:ChargeIndicator="false"]/cbc:Amount')))->toBe($allowanceTotal);
    expect($lineExtension - $allowanceTotal)->toBe($taxExclusive);
    expect(array_sum(ublAmounts($xml, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount')))->toBe($taxExclusive);
    expect(array_sum(ublAmounts($xml, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount')))->toBe($taxTotal);
    expect($taxExclusive + $taxTotal)->toBe($taxInclusive);
    expect($taxInclusive - $prepaid)->toBe($payable);

    foreach ($xml->xpath('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
        $taxable = ublCents((string) $subtotal->children(UblInvoiceGenerator::NAMESPACE_CBC)->TaxableAmount);
        $tax = ublCents((string) $subtotal->children(UblInvoiceGenerator::NAMESPACE_CBC)->TaxAmount);
        $basisPoints = (int) str_replace('.', '', (string) $subtotal->children(UblInvoiceGenerator::NAMESPACE_CAC)->TaxCategory->children(UblInvoiceGenerator::NAMESPACE_CBC)->Percent);

        $n = abs($taxable * $basisPoints);
        $expected = intdiv($n * 2 + 10000, 20000);
        expect($tax)->toBe($taxable < 0 ? -$expected : $expected);
    }
}

/**
 * @return array<string, array{taxable: int, tax: int}>
 */
function ublSubtotals(SimpleXMLElement $xml): array
{
    $result = [];
    foreach ($xml->xpath('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
        $cbc = $subtotal->children(UblInvoiceGenerator::NAMESPACE_CBC);
        $category = $subtotal->children(UblInvoiceGenerator::NAMESPACE_CAC)->TaxCategory->children(UblInvoiceGenerator::NAMESPACE_CBC);
        $result[$category->ID.'/'.$category->Percent] = [
            'taxable' => ublCents((string) $cbc->TaxableAmount),
            'tax' => ublCents((string) $cbc->TaxAmount),
        ];
    }

    return $result;
}

test('downloads a structurally valid UBL invoice', function () {
    $invoice = ublSimpleInvoice(ublPrepareParties($this->company));

    $response = get("api/v1/invoices/{$invoice->id}/ubl")->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/xml');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('.xml');

    $xml = ublParse($response->getContent());
    $cbc = $xml->children(UblInvoiceGenerator::NAMESPACE_CBC);

    expect($xml->getName())->toBe('Invoice');
    expect(dom_import_simplexml($xml)->namespaceURI)->toBe(UblInvoiceGenerator::NAMESPACE_INVOICE);
    expect((string) $cbc->CustomizationID)->toBe(UblInvoiceGenerator::CUSTOMIZATION_ID);
    expect((string) $cbc->ProfileID)->toBe(UblInvoiceGenerator::PROFILE_ID);
    expect((string) $cbc->ID)->toBe($invoice->invoice_number);
    expect((string) $cbc->IssueDate)->toBe(Carbon::parse($invoice->invoice_date)->format('Y-m-d'));
    expect((string) $cbc->InvoiceTypeCode)->toBe('380');
    expect((string) $cbc->DocumentCurrencyCode)->toBe('EUR');
    expect((string) $cbc->BuyerReference)->not->toBe('');

    $expectedOrder = [
        'CustomizationID', 'ProfileID', 'ID', 'IssueDate', 'DueDate', 'InvoiceTypeCode', 'Note',
        'DocumentCurrencyCode', 'BuyerReference', 'AccountingSupplierParty', 'AccountingCustomerParty',
        'PaymentTerms', 'AllowanceCharge', 'TaxTotal', 'LegalMonetaryTotal', 'InvoiceLine',
    ];
    $children = array_map(fn ($node) => $node->localName, iterator_to_array(
        (function () use ($xml) {
            foreach (dom_import_simplexml($xml)->childNodes as $node) {
                if ($node instanceof DOMElement) {
                    yield $node;
                }
            }
        })(),
        false
    ));
    $present = array_values(array_unique($children));
    expect($present)->toBe(array_values(array_intersect($expectedOrder, $present)));
    foreach (['CustomizationID', 'ProfileID', 'ID', 'IssueDate', 'InvoiceTypeCode', 'DocumentCurrencyCode', 'BuyerReference', 'AccountingSupplierParty', 'AccountingCustomerParty', 'TaxTotal', 'LegalMonetaryTotal', 'InvoiceLine'] as $required) {
        expect($present)->toContain($required);
    }

    $amounts = $xml->xpath('//*[contains(local-name(), "Amount")]');
    expect($amounts)->not->toBeEmpty();
    foreach ($amounts as $amount) {
        expect((string) $amount['currencyID'])->toBe('EUR');
        expect((string) $amount)->toMatch('/^-?\d+\.\d{2}$/');
    }

    expect($xml->xpath('//*[not(*) and normalize-space(text())=""]'))->toBeEmpty();
});

test('includes seller and buyer with address and VAT number', function () {
    $invoice = ublSimpleInvoice(ublPrepareParties($this->company));

    $xml = ublXml($invoice);

    $seller = '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/';
    expect(ublValue($xml, $seller.'cbc:EndpointID'))->toBe('NL123456789B01');
    expect(ublValue($xml, $seller.'cbc:EndpointID/@schemeID'))->toBe('9944');
    expect(ublValue($xml, $seller.'cac:PartyTaxScheme/cbc:CompanyID'))->toBe('NL123456789B01');
    expect(ublValue($xml, $seller.'cac:PartyTaxScheme/cac:TaxScheme/cbc:ID'))->toBe('VAT');
    expect(ublValue($xml, $seller.'cac:PartyLegalEntity/cbc:RegistrationName'))->toBe($this->company->name);
    expect(ublValue($xml, $seller.'cac:PostalAddress/cbc:StreetName'))->toBe('Kerkstraat 1');
    expect(ublValue($xml, $seller.'cac:PostalAddress/cbc:CityName'))->toBe('Amsterdam');
    expect(ublValue($xml, $seller.'cac:PostalAddress/cbc:PostalZone'))->toBe('1011AA');
    expect(ublValue($xml, $seller.'cac:PostalAddress/cac:Country/cbc:IdentificationCode'))->toBe('NL');

    $buyer = '/inv:Invoice/cac:AccountingCustomerParty/cac:Party/';
    expect(ublValue($xml, $buyer.'cbc:EndpointID'))->toBe('BE0123456789');
    expect(ublValue($xml, $buyer.'cbc:EndpointID/@schemeID'))->toBe('9925');
    expect(ublValue($xml, $buyer.'cac:PartyTaxScheme/cbc:CompanyID'))->toBe('BE0123456789');
    expect(ublValue($xml, $buyer.'cac:PartyLegalEntity/cbc:RegistrationName'))->toBe('Klant BV');
    expect(ublValue($xml, $buyer.'cac:PostalAddress/cbc:StreetName'))->toBe('Rue 2');
    expect(ublValue($xml, $buyer.'cac:PostalAddress/cbc:CityName'))->toBe('Brussel');
    expect(ublValue($xml, $buyer.'cac:PostalAddress/cbc:PostalZone'))->toBe('1000');
    expect(ublValue($xml, $buyer.'cac:PostalAddress/cac:Country/cbc:IdentificationCode'))->toBe('BE');
});

test('normalizes a VAT number with spaces and dots', function () {
    $customer = ublPrepareParties($this->company);
    $this->company->update(['vat_id' => 'nl 1234.56789 b01']);

    $xml = ublXml(ublSimpleInvoice($customer));

    expect(ublValue($xml, '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID'))->toBe('NL123456789B01');
    expect(ublValue($xml, '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'))->toBe('NL123456789B01');
});

test('splits VAT per rate with line discounts and totals that add up', function () {
    $customer = ublPrepareParties($this->company);

    $invoice = ublCreateInvoice($customer, [
        'sub_total' => 31997,
        'discount_val' => 0,
        'tax' => 5475,
        'total' => 37472,
        'due_amount' => 37472,
    ], [
        ['name' => 'A', 'price' => 10000, 'quantity' => 2, 'discount_val' => 1000, 'total' => 19000, 'tax' => 3990, 'percent' => 21],
        ['name' => 'B', 'price' => 1999, 'quantity' => 3, 'discount_val' => 0, 'total' => 5997, 'tax' => 540, 'percent' => 9],
        ['name' => 'C', 'price' => 5000, 'quantity' => 1, 'discount_val' => 500, 'total' => 4500, 'tax' => 945, 'percent' => 21],
        ['name' => 'D', 'price' => 2500, 'quantity' => 1, 'discount_val' => 0, 'total' => 2500, 'tax' => 0, 'percent' => null],
    ]);

    $xml = ublXml($invoice);

    expect(ublSubtotals($xml))->toBe([
        'S/21.00' => ['taxable' => 23500, 'tax' => 4935],
        'S/9.00' => ['taxable' => 5997, 'tax' => 540],
        'Z/0.00' => ['taxable' => 2500, 'tax' => 0],
    ]);
    expect(ublAmount($xml, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'))->toBe(5475);

    $total = '/inv:Invoice/cac:LegalMonetaryTotal/';
    expect(ublAmount($xml, $total.'cbc:LineExtensionAmount'))->toBe(31997);
    expect(ublAmount($xml, $total.'cbc:TaxExclusiveAmount'))->toBe(31997);
    expect(ublAmount($xml, $total.'cbc:TaxInclusiveAmount'))->toBe(37472);
    expect(ublAmount($xml, $total.'cbc:PayableAmount'))->toBe(37472);
    expect(ublValue($xml, $total.'cbc:AllowanceTotalAmount'))->toBeNull();

    $lines = $xml->xpath('/inv:Invoice/cac:InvoiceLine');
    expect($lines)->toHaveCount(4);

    $lineA = $lines[0]->children(UblInvoiceGenerator::NAMESPACE_CAC);
    expect((string) $lineA->AllowanceCharge->children(UblInvoiceGenerator::NAMESPACE_CBC)->ChargeIndicator)->toBe('false');
    expect((string) $lineA->AllowanceCharge->children(UblInvoiceGenerator::NAMESPACE_CBC)->Amount)->toBe('10.00');
    expect(isset($lines[1]->children(UblInvoiceGenerator::NAMESPACE_CAC)->AllowanceCharge))->toBeFalse();

    foreach ($lines as $line) {
        $cbc = $line->children(UblInvoiceGenerator::NAMESPACE_CBC);
        $cac = $line->children(UblInvoiceGenerator::NAMESPACE_CAC);
        $price = ublCents((string) $cac->Price->children(UblInvoiceGenerator::NAMESPACE_CBC)->PriceAmount);
        $quantityHundredths = ublCents((string) $cbc->InvoicedQuantity);
        $discount = isset($cac->AllowanceCharge)
            ? ublCents((string) $cac->AllowanceCharge->children(UblInvoiceGenerator::NAMESPACE_CBC)->Amount)
            : 0;

        expect((string) $cbc->InvoicedQuantity->attributes()['unitCode'])->toBe('C62');
        expect(ublCents((string) $cbc->LineExtensionAmount))
            ->toBe(intdiv($price * $quantityHundredths + 50, 100) - $discount);
    }

    ublAssertTotalsAddUp($xml);
});

test('applies a document discount with a single VAT rate', function () {
    $customer = ublPrepareParties($this->company);

    $invoice = ublCreateInvoice($customer, [
        'tax_per_item' => 'NO',
        'discount_per_item' => 'NO',
        'sub_total' => 10000,
        'discount_val' => 1000,
        'tax' => 1890,
        'total' => 10890,
        'due_amount' => 10890,
    ], [
        ['price' => 6000, 'quantity' => 1, 'discount_val' => 0, 'total' => 6000, 'tax' => 0],
        ['price' => 4000, 'quantity' => 1, 'discount_val' => 0, 'total' => 4000, 'tax' => 0],
    ], [21]);

    $xml = ublXml($invoice);

    $allowances = $xml->xpath('/inv:Invoice/cac:AllowanceCharge');
    expect($allowances)->toHaveCount(1);
    expect(ublValue($xml, '/inv:Invoice/cac:AllowanceCharge/cbc:ChargeIndicator'))->toBe('false');
    expect(ublValue($xml, '/inv:Invoice/cac:AllowanceCharge/cbc:AllowanceChargeReasonCode'))->toBe('95');
    expect(ublValue($xml, '/inv:Invoice/cac:AllowanceCharge/cbc:Amount'))->toBe('10.00');
    expect(ublValue($xml, '/inv:Invoice/cac:AllowanceCharge/cac:TaxCategory/cbc:ID'))->toBe('S');
    expect(ublValue($xml, '/inv:Invoice/cac:AllowanceCharge/cac:TaxCategory/cbc:Percent'))->toBe('21.00');

    $total = '/inv:Invoice/cac:LegalMonetaryTotal/';
    expect(ublValue($xml, $total.'cbc:AllowanceTotalAmount'))->toBe('10.00');
    expect(ublValue($xml, $total.'cbc:TaxExclusiveAmount'))->toBe('90.00');
    expect(ublSubtotals($xml))->toBe(['S/21.00' => ['taxable' => 9000, 'tax' => 1890]]);
    expect(ublValue($xml, $total.'cbc:PayableAmount'))->toBe('108.90');

    ublAssertTotalsAddUp($xml);
});

test('spreads a document discount over multiple VAT rates', function () {
    $customer = ublPrepareParties($this->company);

    $invoice = ublCreateInvoice($customer, [
        'discount_per_item' => 'NO',
        'sub_total' => 4000,
        'discount_val' => 1000,
        'tax' => 541,
        'total' => 3541,
        'due_amount' => 3541,
    ], [
        ['price' => 3000, 'quantity' => 1, 'discount_val' => 0, 'total' => 3000, 'tax' => 473, 'percent' => 21],
        ['price' => 1000, 'quantity' => 1, 'discount_val' => 0, 'total' => 1000, 'tax' => 68, 'percent' => 9],
    ]);

    $xml = ublXml($invoice);

    $allowances = array_map(fn ($node) => [
        (string) $node->children(UblInvoiceGenerator::NAMESPACE_CBC)->Amount,
        (string) $node->children(UblInvoiceGenerator::NAMESPACE_CAC)->TaxCategory->children(UblInvoiceGenerator::NAMESPACE_CBC)->ID,
        (string) $node->children(UblInvoiceGenerator::NAMESPACE_CAC)->TaxCategory->children(UblInvoiceGenerator::NAMESPACE_CBC)->Percent,
    ], $xml->xpath('/inv:Invoice/cac:AllowanceCharge'));

    expect($allowances)->toBe([
        ['7.50', 'S', '21.00'],
        ['2.50', 'S', '9.00'],
    ]);
    expect(ublValue($xml, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount'))->toBe('10.00');
    expect(ublSubtotals($xml))->toBe([
        'S/21.00' => ['taxable' => 2250, 'tax' => 473],
        'S/9.00' => ['taxable' => 750, 'tax' => 68],
    ]);
    expect(ublAmount($xml, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe(3541);

    ublAssertTotalsAddUp($xml);
});

test('reports a partial payment as prepaid amount', function () {
    $invoice = ublSimpleInvoice(ublPrepareParties($this->company), ['due_amount' => 4100]);

    $xml = ublXml($invoice);

    expect(ublValue($xml, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount'))->toBe('80.00');
    expect(ublValue($xml, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('41.00');

    ublAssertTotalsAddUp($xml);
});

test('adds payment terms when the invoice has no due date', function () {
    $invoice = ublSimpleInvoice(ublPrepareParties($this->company), ['due_date' => null]);

    $xml = ublXml($invoice);

    expect(ublValue($xml, '/inv:Invoice/cbc:DueDate'))->toBeNull();
    expect(ublValue($xml, '/inv:Invoice/cac:PaymentTerms/cbc:Note'))->not->toBeNull();
});

test('returns an error instead of XML when data is missing', function (Closure $breakData, array $expectedErrors) {
    $customer = ublPrepareParties($this->company);
    $invoice = ublSimpleInvoice($customer);

    $breakData($this->company, $customer, $invoice);

    $response = get("api/v1/invoices/{$invoice->id}/ubl")->assertStatus(422);

    expect($response->headers->get('content-type'))->not->toContain('xml');
    expect($response->json('error'))->toBe('invoices.ubl.errors.'.$expectedErrors[0]);
    foreach ($expectedErrors as $error) {
        expect($response->json('ubl_errors'))->toContain('invoices.ubl.errors.'.$error);
    }
})->with([
    'company without VAT number' => [
        fn (Company $company) => $company->update(['vat_id' => null]),
        ['seller_vat_missing'],
    ],
    'customer without VAT number' => [
        fn (Company $company, Customer $customer) => $customer->update(['tax_id' => null]),
        ['buyer_vat_missing'],
    ],
    'customer without billing address' => [
        fn (Company $company, Customer $customer) => Address::where('customer_id', $customer->id)->delete(),
        ['buyer_address_missing'],
    ],
    'company without address' => [
        fn (Company $company) => Address::where('company_id', $company->id)->whereNull('customer_id')->delete(),
        ['seller_address_missing'],
    ],
    'invalid company VAT number' => [
        fn (Company $company) => $company->update(['vat_id' => '123456']),
        ['seller_vat_invalid'],
    ],
    'several problems at once' => [
        function (Company $company, Customer $customer) {
            $company->update(['vat_id' => null]);
            Address::where('customer_id', $customer->id)->delete();
        },
        ['seller_vat_missing', 'buyer_address_missing'],
    ],
    'compound tax' => [
        fn (Company $company, Customer $customer, Invoice $invoice) => Tax::whereIn('invoice_item_id', $invoice->items()->pluck('id'))->update(['compound_tax' => 1]),
        ['compound_tax_unsupported'],
    ],
    'two taxes on one line' => [
        function (Company $company, Customer $customer, Invoice $invoice) {
            $type = ublTaxType($company, 9);
            Tax::factory()->create([
                'invoice_item_id' => $invoice->items()->first()->id,
                'tax_type_id' => $type->id,
                'percent' => 9,
                'amount' => 900,
                'compound_tax' => 0,
            ]);
        },
        ['multiple_taxes_per_line'],
    ],
    'stored sub total does not match the lines' => [
        fn (Company $company, Customer $customer, Invoice $invoice) => $invoice->update(['sub_total' => 9999]),
        ['totals_mismatch'],
    ],
]);

test('cannot export an invoice of another company', function () {
    $customer = ublPrepareParties($this->company);
    $invoice = ublSimpleInvoice($customer);

    $otherCompany = Company::factory()->create();
    $invoice->update(['company_id' => $otherCompany->id]);

    get("api/v1/invoices/{$invoice->id}/ubl")->assertForbidden();
});
