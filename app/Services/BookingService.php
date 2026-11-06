<?php

namespace App\Services;

use App\Exceptions\BookingValidationException;
use App\Exceptions\RoomUnavailableException;
use App\Models\Booking;
use App\Models\Room;
use App\ValueObjects\BookingInterval;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /** SQLSTATE PostgreSQL: нарушение EXCLUDE-ограничения. */
    private const string EXCLUSION_VIOLATION = '23P01';

    public function createBooking(int $userId, array $data): Booking
    {
        $room = Room::with('office')->findOrFail($data['room_id']);

        $interval = new BookingInterval(
            CarbonImmutable::parse($data['starts_at'])->utc(),
            CarbonImmutable::parse($data['ends_at'])->utc(),
            $room->office->timezone
        );

        // проверка БЛ
        $validationResult = $interval->validate();
        if (! $validationResult->isValid) {
            throw new BookingValidationException($validationResult->errors);
        }

        try {
            return DB::transaction(fn (): Booking => Booking::create([
                'room_id' => $room->id,
                'user_id' => $userId,
                'title' => $data['title'],
                'starts_at' => $interval->startsAt,
                'ends_at' => $interval->endsAt,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === self::EXCLUSION_VIOLATION) {
                throw new RoomUnavailableException;
            }

            throw $e;
        }
    }
}
