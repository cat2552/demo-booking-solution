<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\ValueObjects\BookingInterval;
use App\Exceptions\RoomUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Carbon\CarbonImmutable;

class BookingService
{
    public function createBooking(int $userId, array $data): Booking
    {
        $room = Room::with('office')->findOrFail($data['room_id']);

        $interval = new BookingInterval(
            CarbonImmutable::parse($data['starts_at'])->utc(),
            CarbonImmutable::parse($data['ends_at'])->utc(),
            $room->office->timezone
        );

        // Бизнес-валидация через Value Object
        $this->validateInterval($interval, $room);

        try {
            return DB::transaction(function () use ($userId, $room, $interval, $data) {
                // Блокировка комнаты для предотвращения гонки
                Room::where('id', $room->id)->lockForUpdate()->first();

                return Booking::create([
                    'room_id' => $room->id,
                    'user_id' => $userId,
                    'title' => $data['title'],
                    'starts_at' => $interval->startsAt,
                    'ends_at' => $interval->endsAt,
                ]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === 'P0001' || str_contains($e->getMessage(), 'Room is already booked')) {
                throw new RoomUnavailableException();
            }
            throw $e;
        }
    }

    private function validateInterval(BookingInterval $interval, Room $room): void
    {
        // 1. Проверка на пересечение с другими бронями
        // Используем максимально простой и надежный SQL запрос
        $overlap = Booking::where('room_id', $room->id)
            ->where(function ($query) use ($interval) {
                $query->where('starts_at', '<', $interval->endsAt)
                      ->where('ends_at', '>', $interval->startsAt);
            })
            ->exists();

        if ($overlap) {
            throw new RoomUnavailableException();
        }

        if ($interval->isPast()) {
            throw new \InvalidArgumentException('Нельзя забронировать комнату на прошедшее время.');
        }

        if (!$interval->isOnGrid()) {
            throw new \InvalidArgumentException('Время должно быть кратно 15 минутам.');
        }

        $duration = $interval->getDurationInMinutes();
        if ($duration < 15 || $duration > Booking::MAX_DURATION_MINUTES) {
            throw new \InvalidArgumentException('Длительность должна быть от 15 минут до 4 часов.');
        }

        if (!$interval->isWithinWorkingHours()) {
            throw new \InvalidArgumentException('Бронирование должно укладываться в рабочее время офиса.');
        }
    }
}
