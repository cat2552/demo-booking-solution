<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use App\Exceptions\RoomUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    /**
     * Внедряем сервис через конструктор.
     * Теперь вся бизнес-логика создания брони инкапсулирована в BookingService.
     */
    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * Список броней текущего пользователя.
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);

        return response()->json($bookings);
    }

    /**
     * Создание бронирования.
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        try {
            // ИСПОЛЬЗУЕМ СЕРВИС: Теперь контроллер не создает запись напрямую,
            // а делегирует это сервису. Это решает проблему «мертвого кода».
            $booking = $this->bookingService->createBooking(
                $request->user()->id,
                $request->validated()
            );

            Log::channel('bookings')->info('Booking created', [
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
            ]);

            return response()->json($booking, 201);

        } catch (RoomUnavailableException $e) {
            // Перехватываем кастомное исключение из сервиса (вызванное триггером БД)
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Логируем неожиданную ошибку и возвращаем чистый 500-й ответ
            Log::error("Critical error during booking: " . $e->getMessage());
            return response()->json(['error' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    /**
     * Отмена бронирования.
     */
    public function destroy(Request $request, $id): Response
    {
        try {
            $booking = Booking::findOrFail($id);

            if ($booking->user_id !== $request->user()->id) {
                return response()->json(['error' => 'Вы можете отменить только свои бронирования'], 403);
            }

            $booking->delete();
            return response()->noContent();

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Бронирование не найдено'], 404);
        }
    }

    /**
     * Брони комнаты на сегодня.
     */
    public function today(Room $room): JsonResponse
    {
        $timezone = $room->office->timezone;
        $today = CarbonImmutable::today($timezone);

        $bookings = $room->bookings()
            ->whereDate('starts_at', $today)
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at->setTimezone($timezone)->format('H:i') . ' – ' . $b->ends_at->setTimezone($timezone)->format('H:i'),
                'author' => $b->user->name,
            ]);

        return response()->json($bookings);
    }
}
