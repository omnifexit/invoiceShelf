<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:xml',
                'max:10240',
            ],
        ];
    }

    public function getStatementContents(): string
    {
        return (string) $this->file('file')->get();
    }

    public function getStatementFileName(): string
    {
        return $this->file('file')->getClientOriginalName();
    }
}
