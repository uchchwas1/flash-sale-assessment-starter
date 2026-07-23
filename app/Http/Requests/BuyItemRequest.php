<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuyItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // max:100 matches the column width so a too-long id can never be
            'user_id' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Normalise whitespace before validation so "user_1 " and "user_1" are the
     * same buyer (and a whitespace-only id fails `required`).
     */
    protected function prepareForValidation(): void
    {
        $userId = $this->input('user_id');

        if (is_string($userId)) {
            $this->merge(['user_id' => trim($userId)]);
        }
    }

    /**
     * The validated, normalised user identifier.
     */
    public function userId(): string
    {
        return (string) $this->validated('user_id');
    }
}
