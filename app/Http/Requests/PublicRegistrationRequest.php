<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PublicRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $whatsapp = (string) $this->input('whatsapp', '');

        try {
            $whatsapp = app(PhoneNormalizer::class)->normalize($whatsapp);
        } catch (InvalidArgumentException) {
            $whatsapp = trim($whatsapp);
        }

        $optional = collect(['organization', 'job_title', 'city', 'address'])
            ->mapWithKeys(function (string $field) {
                $value = trim((string) $this->input($field, ''));

                return [$field => $value === '' ? null : $value];
            })
            ->all();

        $this->merge([
            'full_name' => trim((string) $this->input('full_name', '')),
            'whatsapp' => $whatsapp,
            'email' => mb_strtolower(trim((string) $this->input('email', ''))),
            'idempotency_key' => $this->header('Idempotency-Key'),
            ...$optional,
        ]);
    }

    public function rules(): array
    {
        /** @var Event|null $event */
        $event = $this->route('event');

        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'whatsapp' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'organization' => ['nullable', 'string', 'max:180'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'talkshow_ids' => ['sometimes', 'array'],
            'talkshow_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('talkshows', 'id')->where('event_id', $event?->id),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'whatsapp.required' => 'Nomor WhatsApp wajib diisi.',
            'whatsapp.regex' => 'Nomor WhatsApp tidak valid.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Alamat email tidak valid.',
            'talkshow_ids.*.exists' => 'Salah satu talkshow yang dipilih tidak tersedia untuk event ini.',
            'idempotency_key.required' => 'Permintaan registrasi tidak memiliki identitas pengaman.',
        ];
    }
}
