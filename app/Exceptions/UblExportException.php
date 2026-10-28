<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class UblExportException extends Exception
{
    public const SELLER_NAME_MISSING = 'seller_name_missing';

    public const SELLER_VAT_MISSING = 'seller_vat_missing';

    public const SELLER_VAT_INVALID = 'seller_vat_invalid';

    public const SELLER_ADDRESS_MISSING = 'seller_address_missing';

    public const SELLER_COUNTRY_UNSUPPORTED = 'seller_country_unsupported';

    public const BUYER_MISSING = 'buyer_missing';

    public const BUYER_NAME_MISSING = 'buyer_name_missing';

    public const BUYER_VAT_MISSING = 'buyer_vat_missing';

    public const BUYER_VAT_INVALID = 'buyer_vat_invalid';

    public const BUYER_ADDRESS_MISSING = 'buyer_address_missing';

    public const BUYER_COUNTRY_UNSUPPORTED = 'buyer_country_unsupported';

    public const CURRENCY_MISSING = 'currency_missing';

    public const NO_ITEMS = 'no_items';

    public const COMPOUND_TAX_UNSUPPORTED = 'compound_tax_unsupported';

    public const MULTIPLE_TAXES_PER_LINE = 'multiple_taxes_per_line';

    public const TOTALS_MISMATCH = 'totals_mismatch';

    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The invoice cannot be exported as UBL: '.implode(', ', $errors).'.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'invoices.ubl.errors.'.$this->errors[0],
            'message' => $this->getMessage(),
            'ubl_errors' => array_map(fn (string $code) => 'invoices.ubl.errors.'.$code, $this->errors),
        ], 422);
    }
}
