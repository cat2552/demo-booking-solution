<?php

namespace App\Http\Requests;

use App\Models\Room;
use App\ValueObjects\BookingInterval;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const string TIME_FORMAT_MESSAGE = 'Время должно быть в формате ISO 8601 со смещением, например 2026-11-10T10:00:00+03:00';

    // Строгий Regex для ISO 8601: YYYY-MM-DDTHH:mm:ss с обязательным смещением (Z или +/-HH:mm)
    private const string ISO_8601_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d)$/';

    private ?Room $room = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Базовая валидация типов и форматов.
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['bail', 'required', 'string', $this->validateIso8601(...)],
            'ends_at' => ['bail', 'required', 'string', $this->validateIso8601(...)],
        ];
    }

    /**
     * Проверка формата даты на соответствие ISO 8601 со смещением.
     */
    private function validateIso8601(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match(self::ISO_8601_REGEX, $value)) {
            $fail(self::TIME_FORMAT_MESSAGE);
            return;
        }

        try {
            // Дополнительная проверка на реальность даты (чтобы отсечь например 2026-02-30)
            $date = CarbonImmutable::parse($value);
            if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                $fail(self::TIME_FORMAT_MESSAGE);
            }
        } catch (\Throwable) {
            $fail(self::TIME_FORMAT_MESSAGE);
        }
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату',
            'room_id.exists' => 'Такой комнаты нет',
            'title.required' => 'Укажите название встречи',
            'starts_at.required' => 'Укажите время начала',
            'ends_at.required' => 'Укажите время окончания',
        ];
    }

    /**
     * Сложная бизнес-валидация через Value Object.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Если базовые правила (required, date) уже провалились — не запускаем бизнес-логику
                if ($validator->errors()->hasAny(['room_id', 'starts_at', 'ends_at'])) {
                    return;
                }

                // Создаем Value Object, который инкапсулирует правила бронирования
                $interval = new BookingInterval(
                    $this->startsAt(),
                    $this->endsAt(),
                    $this->room()->office->timezone
                );

                // Просим VO провалидировать самого себя
                $result = $interval->validate();

                if (!$result->isValid) {
                    // Переносим ошибки из VO в валидатор Laravel
                    foreach ($result->errors as $field => $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('ends_at'))->utc();
    }

    public function room(): Room
    {
        return $this->room ??= Room::with('office')->findOrFail($this->integer('room_id'));
    }
}
