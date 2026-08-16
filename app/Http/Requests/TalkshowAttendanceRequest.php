<?php

namespace App\Http\Requests;

class TalkshowAttendanceRequest extends StaffTicketRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'override_prerequisite' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }
}
