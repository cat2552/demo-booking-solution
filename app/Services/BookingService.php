<?php

namespace App\Services;

use App\Exceptions\BookingValidationException;
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

        // проверка БЛ
        $validationResult = $interval->validate();
        if (!$validationResult->isValid) {
            throw new BookingValidationException($validationResult->errors);
        }

        try {
            return DB::transaction(function () use ($userId, $room, $interval, $data) {
                Room::where('id', $room->id)->lockForUpdate()->first();

                $overlap = Booking::where('room_id', $room->id)
                    ->where(function ($query) use ($interval) {
                        $query->where('starts_at', '<', $interval->endsAt)
                            ->where('ends_at', '>', $interval->startsAt);
                    })
                    ->exists();

                if ($overlap) {
                    throw new RoomUnavailableException();
                }
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
}
