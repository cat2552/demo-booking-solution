<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const string TIME_FORMAT_MESSAGE = 'Время должно быть в формате ISO 8601 со смещением, например 2026-11-10T10:00:00+03:00';
    private const string ISO_8601_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d)$/';
    private ?Room $room = null;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['bail', 'required', 'string', $this->validateIso8601(...)],
            'ends_at' => ['bail', 'required', 'string', $this->validateIso8601(...)],
        ];
    }

    private function validateIso8601(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match(self::ISO_8601_REGEX, $value)) {
            $fail(self::TIME_FORMAT_MESSAGE);
            return;
        }
        try {
            $date = CarbonImmutable::parse($value);
            if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) { $fail(self::TIME_FORMAT_MESSAGE); }
        } catch (\Throwable) { $fail(self::TIME_FORMAT_MESSAGE); }
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['room_id', 'starts_at', 'ends_at'])) return;
                $start = $this->startsAt();
                $end = $this->endsAt();
                $office = $this->room()->office;

                if ($start->isBefore(CarbonImmutable::now()->subMinute())) {
                    $validator->errors()->add('starts_at', 'Нельзя забронировать комнату на прошедшее время.');
                }
                if ($start->minute % Booking::STEP_MINUTES !== 0 || $end->minute % Booking::STEP_MINUTES !== 0) {
                    $validator->errors()->add('starts_at', 'Время должно быть кратно ' . Booking::STEP_MINUTES . ' минутам.');
                }
                if ($start->diffInMinutes($end) < 15 || $start->diffInMinutes($end) > Booking::MAX_DURATION_MINUTES) {
                    $validator->errors()->add('ends_at', 'Длительность должна быть от 15 минут до 4 часов.');
                }
                $localStart = $start->setTimezone($office->timezone);
                $localEnd = $end->setTimezone($office->timezone);
                if ($localStart->toDateString() !== $localEnd->toDateString() ||
                    $localStart->hour < 9 || $localEnd->hour >= 20 || ($localEnd->hour == 20 && $localEnd->minute > 0)) {
                    $validator->errors()->add('starts_at', 'Бронь должна укладываться в рабочее время офиса.');
                }
            },
        ];
    }

    public function startsAt(): CarbonImmutable { return CarbonImmutable::parse($this->input('starts_at'))->utc(); }
    public function endsAt(): CarbonImmutable { return CarbonImmutable::parse($this->input('ends_at'))->utc(); }
    public function room(): Room { return $this->room ??= Room::with('office')->findOrFail($this->integer('room_id')); }
}
