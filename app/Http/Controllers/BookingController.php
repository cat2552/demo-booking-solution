<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BookingController extends Controller
{
    public function index(Request $request) {
        return Booking::where('user_id', $request->user()->id)->orderByRaw('starts_at DESC')->paginate(20);
    }

    public function store(StoreBookingRequest $request) {
        try {
            return DB::transaction(function () use ($request) {
                $room = Room::findOrFail($request->room_id);
                Room::where('id', $room->id)->lockForUpdate()->first();

                $conflict = Booking::where('room_id', $room->id)
                    ->where('starts_at', '<', $request->endsAt())
                    ->where('ends_at', '>', $request->startsAt())
                    ->exists();

                if ($conflict) return response()->json(['error' => 'Комната занята'], 409);

                $booking = Booking::create([
                    'room_id' => $room->id,
                    'user_id' => $request->user()->id,
                    'title' => $request->title,
                    'starts_at' => $request->startsAt(),
                    'ends_at' => $request->endsAt(),
                ]);

                // ВОЗВРАЩАЕМ ЛОГИРОВАНИЕ
                Log::channel('bookings')->info('Booking created', [
                    'booking_id' => $booking->id,
                    'user_id' => $booking->user_id,
                    'room_id' => $booking->room_id,
                ]);

                return response()->json($booking, 201);
            });
        } catch (\Exception $e) {
            // Также логируем ошибку, чтобы знать, почему упал запрос
            Log::error("Booking creation failed: " . $e->getMessage());
            if (str_contains($e->getMessage(), 'exclusion constraint')) {
                return response()->json(['error' => 'Комната занята'], 409);
            }
            return response()->json(['error' => 'Ошибка сервера'], 500);
        }
    }

    public function destroy(Request $request, $id) {
        try {
            $booking = Booking::findOrFail($id);
            if ($booking->user_id !== $request->user()->id) return response()->json(['error' => 'Forbidden'], 403);
            $booking->delete();
            return response()->noContent();
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Not Found'], 404);
        }
    }

    public function today(Room $room) {
        $timezone = $room->office->timezone;
        return $room->bookings()
            ->whereDate('starts_at', \Carbon\Carbon::today($timezone))
            ->get()
            ->map(fn ($b) => ['time' => $b->starts_at->setTimezone($timezone)->format('H:i') . ' – ' . $b->ends_at->setTimezone($timezone)->format('H:i'), 'author' => $b->user->name]);
    }
}
