<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StaffTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'max:2048'],
        ];
    }
}
