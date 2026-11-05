<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;
use App\Models\Booking;
use App\Models\Office;

readonly class BookingInterval
{
    /**
     * Конструктор Value Object.
     *
     * @param CarbonImmutable $startsAt Время начала в UTC
     * @param CarbonImmutable $endsAt Время окончания в UTC
     * @param string $timezone Таймзона офиса (например, 'Europe/Moscow')
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string          $timezone
    ) {}

    /**
     * Основной метод валидации интервала.
     *
     * @return ValidationResult Объект с результатом и списком ошибок array<string, string>
     */
    public function validate(): ValidationResult
    {
        /** @var array<string, string> $errors */
        $errors = [];

        // 1. Проверка: не в прошлом ли время начала?
        if ($this->isPast()) {
            $errors['starts_at'] = 'Нельзя забронировать комнату на прошедшее время.';
        }

        // 2. Проверка: попадают ли начало и конец в 15-минутную сетку?
        if (!$this->isOnGrid()) {
            $errors['starts_at'] = $errors['starts_at'] ?? 'Время должно быть кратно ' . Booking::STEP_MINUTES . ' минутам.';
        }

        // 3. Проверка длительности (от 15 мин до 4 часов)
        $duration = $this->getDurationInMinutes();
        if ($duration < 15 || $duration > Booking::MAX_DURATION_MINUTES) {
            $errors['ends_at'] = 'Длительность бронирования должна быть от 15 минут до 4 часов.';
        }

        // 4. Проверка рабочих часов офиса (09:00 - 20:00)
        if (!$this->isWithinWorkingHours()) {
            $errors['starts_at'] = $errors['starts_at'] ?? "Бронь должна укладываться в рабочее время офиса ({$this->timezone})";
        }

        return empty($errors) ? ValidationResult::success() : ValidationResult::failure($errors);
    }

    /**
     * Рассчитывает длительность интервала в минутах.
     */
    public function getDurationInMinutes(): int
    {
        return $this->startsAt->diffInMinutes($this->endsAt);
    }

    /**
     * Проверяет, что время начала и конца кратно заданному шагу (15 мин).
     */
    public function isOnGrid(): bool
    {
        $step = Booking::STEP_MINUTES;

        return $this->startsAt->second === 0
            && $this->startsAt->micro === 0
            && $this->startsAt->minute % $step === 0
            && $this->endsAt->second === 0
            && $this->endsAt->micro === 0
            && $this->endsAt->minute % $step === 0;
    }

    /**
     * Проверяет, что интервал полностью укладывается в рабочие часы офиса.
     */
    public function isWithinWorkingHours(): bool
    {
        // Переводим UTC-время в локальную таймзону офиса
        $localStart = $this->startsAt->setTimezone($this->timezone);
        $localEnd = $this->endsAt->setTimezone($this->timezone);

        // Проверка: встреча не должна пересекать полночь
        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            return false;
        }

        // Создаем объекты-границы для конкретного дня встречи
        $open = $localStart->copy()->setTimeFromTimeString(Office::OPENS_AT);
        $close = $localEnd->copy()->setTimeFromTimeString(Office::CLOSES_AT);

        // Встреча валидна, если: начало >= 09:00 И конец <= 20:00
        return $localStart->gte($open) && $localEnd->lte($close);
    }

    /**
     * Проверяет, не находится ли время начала в прошлом.
     */
    public function isPast(): bool
    {
        // Допуск в 1 минуту, чтобы избежать ошибок синхронизации времени
        return $this->startsAt->isBefore(CarbonImmutable::now()->subMinute());
    }
}
