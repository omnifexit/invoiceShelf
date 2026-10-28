<?php

namespace App\Services;

use App\Exceptions\UblExportException;
use App\Models\Address;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use Carbon\Carbon;
use XMLWriter;

/**
 * Renders an invoice as a UBL 2.1 document following EN 16931 and
 * Peppol BIS Billing 3.0. All amounts are calculated in integer cents and
 * only formatted with two decimals when written, so the totals add up exactly.
 */
class UblInvoiceGenerator
{
    public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    public const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public const NAMESPACE_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const NAMESPACE_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const NAMESPACE_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Peppol electronic address schemes (EAS) for VAT numbers, by country prefix.
     */
    public const VAT_ENDPOINT_SCHEMES = [
        'AT' => '9914', 'BE' => '9925', 'BG' => '9926', 'CY' => '9928', 'CZ' => '9929',
        'DE' => '9930', 'EE' => '9931', 'ES' => '9920', 'FR' => '9957', 'GB' => '9932',
        'GR' => '9933', 'HR' => '9934', 'HU' => '9910', 'IE' => '9935', 'IT' => '0211',
        'LT' => '9937', 'LU' => '9938', 'LV' => '9939', 'MT' => '9943', 'NL' => '9944',
        'PL' => '9945', 'PT' => '9946', 'RO' => '9947', 'SI' => '9949', 'SK' => '9950',
    ];

    private const DISCOUNT_REASON_CODE = '95';

    private const DISCOUNT_REASON = 'Discount';

    /**
     * @throws UblExportException when required data is missing or inconsistent
     */
    public function generate(Invoice $invoice): string
    {
        $errors = [];

        $seller = $this->sellerParty(Company::find($invoice->company_id), $errors);
        $buyer = $this->buyerParty($invoice->customer_id ? Customer::find($invoice->customer_id) : null, $errors);

        $currency = Currency::whereKey($invoice->currency_id)->value('code');
        if (empty($currency)) {
            $errors[] = UblExportException::CURRENCY_MISSING;
        }

        $items = InvoiceItem::where('invoice_id', $invoice->id)->orderBy('id')->get();
        if ($items->isEmpty()) {
            $errors[] = UblExportException::NO_ITEMS;
        }

        $lines = $this->lines($invoice, $items->all(), $errors);

        if ($errors !== []) {
            throw new UblExportException(array_values(array_unique($errors)));
        }

        $totals = $this->totals($invoice, $lines);

        if ($totals['lineExtension'] !== (int) $invoice->sub_total
            || abs($totals['taxTotal'] - (int) $invoice->tax) > count($lines)) {
            throw new UblExportException([UblExportException::TOTALS_MISMATCH]);
        }

        return $this->write($invoice, (string) $currency, $seller, $buyer, $lines, $totals);
    }

    /**
     * Format an amount in cents as a decimal string with two decimals.
     */
    public static function formatAmount(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate a percentage (in basis points) of an amount in cents, rounded
     * half away from zero, using integer arithmetic only.
     */
    public static function percentOfCents(int $cents, int $basisPoints): int
    {
        $n = $cents * $basisPoints;
        $q = intdiv(abs($n) * 2 + 10000, 20000);

        return $n < 0 ? -$q : $q;
    }

    /**
     * Split an amount over the keys in proportion to their weights (largest
     * remainder method). The parts always add up to exactly the amount.
     *
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount);
        $absWeights = array_map(fn (int $w) => abs($w), $weights);
        $sum = array_sum($absWeights);

        $result = array_fill_keys(array_keys($weights), 0);

        if ($sum === 0) {
            $result[array_key_first($weights)] = $amount;

            return $result;
        }

        $remainders = [];
        $allocated = 0;
        foreach ($absWeights as $key => $weight) {
            $result[$key] = intdiv($abs * $weight, $sum);
            $remainders[$key] = ($abs * $weight) % $sum;
            $allocated += $result[$key];
        }

        $keys = array_keys($remainders);
        usort($keys, function ($a, $b) use ($remainders, $keys) {
            return [$remainders[$b], array_search($a, $keys, true)] <=> [$remainders[$a], array_search($b, $keys, true)];
        });

        $left = $abs - $allocated;
        foreach ($keys as $key) {
            if ($left <= 0) {
                break;
            }
            $result[$key]++;
            $left--;
        }

        return array_map(fn (int $part) => $part * $sign, $result);
    }

    /**
     * The address of the company itself; customer addresses also carry a company_id.
     */
    public function companyAddress(Company $company): ?Address
    {
        return Address::where('company_id', $company->id)
            ->whereNull('customer_id')
            ->first();
    }

    public function customerBillingAddress(Customer $customer): ?Address
    {
        return Address::where('customer_id', $customer->id)
            ->where('type', Address::BILLING_TYPE)
            ->first();
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function sellerParty(?Company $company, array &$errors): array
    {
        $name = trim((string) $company?->name);
        if ($name === '') {
            $errors[] = UblExportException::SELLER_NAME_MISSING;
        }

        $vat = $this->checkVat(
            $company?->vat_id,
            $errors,
            UblExportException::SELLER_VAT_MISSING,
            UblExportException::SELLER_VAT_INVALID,
            UblExportException::SELLER_COUNTRY_UNSUPPORTED
        );

        $address = $this->addressData($company ? $this->companyAddress($company) : null);
        if ($address === null) {
            $errors[] = UblExportException::SELLER_ADDRESS_MISSING;
        }

        return ['name' => $name, 'vat' => $vat, 'address' => $address, 'email' => null];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function buyerParty(?Customer $customer, array &$errors): array
    {
        if ($customer === null) {
            $errors[] = UblExportException::BUYER_MISSING;

            return [];
        }

        $name = trim((string) ($customer->company_name ?: $customer->name));
        if ($name === '') {
            $errors[] = UblExportException::BUYER_NAME_MISSING;
        }

        $vat = $this->checkVat(
            $customer->tax_id,
            $errors,
            UblExportException::BUYER_VAT_MISSING,
            UblExportException::BUYER_VAT_INVALID,
            UblExportException::BUYER_COUNTRY_UNSUPPORTED
        );

        $address = $this->addressData($this->customerBillingAddress($customer));
        if ($address === null) {
            $errors[] = UblExportException::BUYER_ADDRESS_MISSING;
        }

        return ['name' => $name, 'vat' => $vat, 'address' => $address, 'email' => $customer->email];
    }

    /**
     * Validate a VAT number and resolve its Peppol endpoint scheme.
     *
     * @param  list<string>  $errors
     * @return array{id: string, scheme: string}|null
     */
    private function checkVat(?string $value, array &$errors, string $missing, string $invalid, string $unsupported): ?array
    {
        if (trim((string) $value) === '') {
            $errors[] = $missing;

            return null;
        }

        $vat = $this->normalizeVat($value);
        if (! preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $vat)) {
            $errors[] = $invalid;

            return null;
        }

        $country = substr($vat, 0, 2);
        $country = $country === 'EL' ? 'GR' : $country;

        if (! isset(self::VAT_ENDPOINT_SCHEMES[$country])) {
            $errors[] = $unsupported;

            return null;
        }

        return ['id' => $vat, 'scheme' => self::VAT_ENDPOINT_SCHEMES[$country]];
    }

    private function normalizeVat(?string $value): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', (string) $value));
    }

    /**
     * The postal address fields, or null when street, city, postal code or country is missing.
     *
     * @return array<string, string>|null
     */
    private function addressData(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $data = [
            'street' => trim((string) $address->address_street_1),
            'additional_street' => trim((string) $address->address_street_2),
            'city' => trim((string) $address->city),
            'zip' => trim((string) $address->zip),
            'state' => trim((string) $address->state),
            'country' => strtoupper(trim((string) Country::whereKey($address->country_id)->value('code'))),
        ];

        foreach (['street', 'city', 'zip', 'country'] as $required) {
            if ($data[$required] === '') {
                return null;
            }
        }

        return $data;
    }

    /**
     * Determine the single VAT rate of every invoice line.
     *
     * @param  list<InvoiceItem>  $items
     * @param  list<string>  $errors
     * @return list<array{item: InvoiceItem, bp: int, category: string, key: string}>
     */
    private function lines(Invoice $invoice, array $items, array &$errors): array
    {
        $perItem = $invoice->tax_per_item === 'YES';

        $invoiceBp = $perItem ? 0 : $this->taxRate(
            Tax::where('invoice_id', $invoice->id)->whereNull('invoice_item_id')->get()->all(),
            $errors
        );

        $itemTaxes = $perItem
            ? Tax::whereIn('invoice_item_id', array_map(fn (InvoiceItem $item) => $item->id, $items))->orderBy('id')->get()
            : collect();

        $lines = [];
        foreach ($items as $item) {
            $bp = $perItem
                ? $this->taxRate($itemTaxes->where('invoice_item_id', $item->id)->values()->all(), $errors)
                : $invoiceBp;

            $category = $bp > 0 ? 'S' : 'Z';

            $lines[] = ['item' => $item, 'bp' => $bp, 'category' => $category, 'key' => $category.':'.$bp];
        }

        return $lines;
    }

    /**
     * @param  array<int, Tax>  $taxes
     * @param  list<string>  $errors
     */
    private function taxRate(array $taxes, array &$errors): int
    {
        foreach ($taxes as $tax) {
            if ($tax->compound_tax) {
                $errors[] = UblExportException::COMPOUND_TAX_UNSUPPORTED;
            }
        }

        if (count($taxes) > 1) {
            $errors[] = UblExportException::MULTIPLE_TAXES_PER_LINE;
        }

        $tax = reset($taxes);

        return $tax ? (int) round((float) $tax->percent * 100) : 0;
    }

    /**
     * @param  list<array{item: InvoiceItem, bp: int, category: string, key: string}>  $lines
     * @return array<string, mixed>
     */
    private function totals(Invoice $invoice, array $lines): array
    {
        $categories = [];
        foreach ($lines as $line) {
            $key = $line['key'];
            $categories[$key] ??= ['category' => $line['category'], 'bp' => $line['bp'], 'lineTotal' => 0];
            $categories[$key]['lineTotal'] += (int) $line['item']->total;
        }

        uksort($categories, fn ($a, $b) => [$categories[$b]['bp'], $a] <=> [$categories[$a]['bp'], $b]);

        $docDiscount = (int) ($invoice->discount_val ?? 0);
        $allowances = self::allocate($docDiscount, array_map(fn ($c) => $c['lineTotal'], $categories));

        $taxTotal = 0;
        foreach ($categories as $key => $category) {
            $categories[$key]['allowance'] = $allowances[$key];
            $categories[$key]['taxable'] = $category['lineTotal'] - $allowances[$key];
            $categories[$key]['tax'] = self::percentOfCents($categories[$key]['taxable'], $category['bp']);
            $taxTotal += $categories[$key]['tax'];
        }

        $lineExtension = array_sum(array_map(fn ($line) => (int) $line['item']->total, $lines));
        $taxExclusive = $lineExtension - $docDiscount;
        $taxInclusive = $taxExclusive + $taxTotal;
        $prepaid = max(0, (int) $invoice->total - (int) $invoice->due_amount);

        return [
            'categories' => $categories,
            'lineExtension' => $lineExtension,
            'allowanceTotal' => $docDiscount,
            'taxExclusive' => $taxExclusive,
            'taxTotal' => $taxTotal,
            'taxInclusive' => $taxInclusive,
            'prepaid' => $prepaid,
            'payable' => $taxInclusive - $prepaid,
        ];
    }

    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @param  list<array{item: InvoiceItem, bp: int, category: string, key: string}>  $lines
     * @param  array<string, mixed>  $totals
     */
    private function write(Invoice $invoice, string $currency, array $seller, array $buyer, array $lines, array $totals): string
    {
        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');

        $w->startElement('Invoice');
        $w->writeAttribute('xmlns', self::NAMESPACE_INVOICE);
        $w->writeAttribute('xmlns:cac', self::NAMESPACE_CAC);
        $w->writeAttribute('xmlns:cbc', self::NAMESPACE_CBC);

        $this->element($w, 'cbc:CustomizationID', self::CUSTOMIZATION_ID);
        $this->element($w, 'cbc:ProfileID', self::PROFILE_ID);
        $this->element($w, 'cbc:ID', (string) $invoice->invoice_number);
        $this->element($w, 'cbc:IssueDate', Carbon::parse($invoice->invoice_date)->format('Y-m-d'));
        $this->element($w, 'cbc:DueDate', $invoice->due_date ? Carbon::parse($invoice->due_date)->format('Y-m-d') : null);
        $this->element($w, 'cbc:InvoiceTypeCode', '380');
        $this->element($w, 'cbc:Note', $this->plainText($invoice->notes));
        $this->element($w, 'cbc:DocumentCurrencyCode', $currency);
        $this->element($w, 'cbc:BuyerReference', (string) ($invoice->reference_number ?: $invoice->invoice_number));

        $w->startElement('cac:AccountingSupplierParty');
        $this->party($w, $seller);
        $w->endElement();

        $w->startElement('cac:AccountingCustomerParty');
        $this->party($w, $buyer);
        $w->endElement();

        if (! $invoice->due_date && $totals['payable'] > 0) {
            $w->startElement('cac:PaymentTerms');
            $this->element($w, 'cbc:Note', 'Payment due upon receipt');
            $w->endElement();
        }

        foreach ($totals['categories'] as $category) {
            if ($category['allowance'] !== 0) {
                $this->allowance($w, $category['allowance'], $currency, $category);
            }
        }

        $w->startElement('cac:TaxTotal');
        $this->amount($w, 'cbc:TaxAmount', $totals['taxTotal'], $currency);
        foreach ($totals['categories'] as $category) {
            $w->startElement('cac:TaxSubtotal');
            $this->amount($w, 'cbc:TaxableAmount', $category['taxable'], $currency);
            $this->amount($w, 'cbc:TaxAmount', $category['tax'], $currency);
            $this->taxCategory($w, 'cac:TaxCategory', $category['category'], $category['bp']);
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('cac:LegalMonetaryTotal');
        $this->amount($w, 'cbc:LineExtensionAmount', $totals['lineExtension'], $currency);
        $this->amount($w, 'cbc:TaxExclusiveAmount', $totals['taxExclusive'], $currency);
        $this->amount($w, 'cbc:TaxInclusiveAmount', $totals['taxInclusive'], $currency);
        if ($totals['allowanceTotal'] !== 0) {
            $this->amount($w, 'cbc:AllowanceTotalAmount', $totals['allowanceTotal'], $currency);
        }
        if ($totals['prepaid'] > 0) {
            $this->amount($w, 'cbc:PrepaidAmount', $totals['prepaid'], $currency);
        }
        $this->amount($w, 'cbc:PayableAmount', $totals['payable'], $currency);
        $w->endElement();

        foreach ($lines as $index => $line) {
            $this->invoiceLine($w, $index + 1, $line, $currency);
        }

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    /**
     * @param  array{item: InvoiceItem, bp: int, category: string, key: string}  $line
     */
    private function invoiceLine(XMLWriter $w, int $id, array $line, string $currency): void
    {
        $item = $line['item'];
        $price = (int) $item->price;
        $quantity = (float) $item->quantity;

        $w->startElement('cac:InvoiceLine');
        $this->element($w, 'cbc:ID', (string) $id);
        $this->element($w, 'cbc:InvoicedQuantity', number_format($quantity, 2, '.', ''), ['unitCode' => 'C62']);
        $this->amount($w, 'cbc:LineExtensionAmount', (int) $item->total, $currency);

        if ((int) $item->discount_val !== 0) {
            $this->allowance($w, (int) $item->discount_val, $currency);
        }

        $w->startElement('cac:Item');
        $this->element($w, 'cbc:Description', $this->plainText($item->description));
        $this->element($w, 'cbc:Name', (string) $item->name);
        $this->taxCategory($w, 'cac:ClassifiedTaxCategory', $line['category'], $line['bp']);
        $w->endElement();

        $w->startElement('cac:Price');
        $this->amount($w, 'cbc:PriceAmount', $price, $currency);
        $w->endElement();

        $w->endElement();
    }

    /**
     * @param  array<string, mixed>  $party
     */
    private function party(XMLWriter $w, array $party): void
    {
        $address = $party['address'];

        $w->startElement('cac:Party');
        $this->element($w, 'cbc:EndpointID', $party['vat']['id'], ['schemeID' => $party['vat']['scheme']]);

        $w->startElement('cac:PartyName');
        $this->element($w, 'cbc:Name', $party['name']);
        $w->endElement();

        $w->startElement('cac:PostalAddress');
        $this->element($w, 'cbc:StreetName', $address['street']);
        $this->element($w, 'cbc:AdditionalStreetName', $address['additional_street']);
        $this->element($w, 'cbc:CityName', $address['city']);
        $this->element($w, 'cbc:PostalZone', $address['zip']);
        $this->element($w, 'cbc:CountrySubentity', $address['state']);
        $w->startElement('cac:Country');
        $this->element($w, 'cbc:IdentificationCode', $address['country']);
        $w->endElement();
        $w->endElement();

        $w->startElement('cac:PartyTaxScheme');
        $this->element($w, 'cbc:CompanyID', $party['vat']['id']);
        $this->taxScheme($w);
        $w->endElement();

        $w->startElement('cac:PartyLegalEntity');
        $this->element($w, 'cbc:RegistrationName', $party['name']);
        $w->endElement();

        if (! empty($party['email'])) {
            $w->startElement('cac:Contact');
            $this->element($w, 'cbc:ElectronicMail', (string) $party['email']);
            $w->endElement();
        }

        $w->endElement();
    }

    /**
     * @param  array<string, mixed>|null  $category
     */
    private function allowance(XMLWriter $w, int $cents, string $currency, ?array $category = null): void
    {
        $w->startElement('cac:AllowanceCharge');
        $this->element($w, 'cbc:ChargeIndicator', 'false');
        $this->element($w, 'cbc:AllowanceChargeReasonCode', self::DISCOUNT_REASON_CODE);
        $this->element($w, 'cbc:AllowanceChargeReason', self::DISCOUNT_REASON);
        $this->amount($w, 'cbc:Amount', $cents, $currency);
        if ($category !== null) {
            $this->taxCategory($w, 'cac:TaxCategory', $category['category'], $category['bp']);
        }
        $w->endElement();
    }

    private function taxCategory(XMLWriter $w, string $name, string $category, int $basisPoints): void
    {
        $w->startElement($name);
        $this->element($w, 'cbc:ID', $category);
        $this->element($w, 'cbc:Percent', $this->formatPercent($basisPoints));
        $this->taxScheme($w);
        $w->endElement();
    }

    private function taxScheme(XMLWriter $w): void
    {
        $w->startElement('cac:TaxScheme');
        $this->element($w, 'cbc:ID', 'VAT');
        $w->endElement();
    }

    private function amount(XMLWriter $w, string $name, int $cents, string $currency): void
    {
        $this->element($w, $name, self::formatAmount($cents), ['currencyID' => $currency]);
    }

    /**
     * Write an element, or nothing when the value is empty (Peppol R008).
     *
     * @param  array<string, string>  $attributes
     */
    private function element(XMLWriter $w, string $name, ?string $value, array $attributes = []): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $w->startElement($name);
        foreach ($attributes as $attribute => $attributeValue) {
            $w->writeAttribute($attribute, $attributeValue);
        }
        $w->text($value);
        $w->endElement();
    }

    private function formatPercent(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    private function plainText(?string $value): ?string
    {
        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }
}
