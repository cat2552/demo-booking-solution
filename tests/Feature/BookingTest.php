<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Room $roomMoscow;
    protected Room $roomNovosibirsk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $officeMoscow = Office::create(['name' => 'Москва', 'timezone' => 'Europe/Moscow']);
        $this->roomMoscow = Room::create(['name' => 'Арбат', 'office_id' => $officeMoscow->id, 'capacity' => 10]);

        $officeNsk = Office::create(['name' => 'Новосибирск', 'timezone' => 'Asia/Novosibirsk']);
        $this->roomNovosibirsk = Room::create(['name' => 'Обь', 'office_id' => $officeNsk->id, 'capacity' => 10]);
    }

    public function test_can_create_valid_booking()
    {
        // Используем завтрашний день, чтобы избежать ошибки "в прошлом"
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0);
        $end = $start->copy()->addHour();

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Valid Meeting',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', ['title' => 'Valid Meeting']);
    }

    public function test_cannot_overlap_bookings()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0)->utc();
        $end = $start->copy()->addHour();

        Booking::create([
            'room_id' => $this->roomMoscow->id,
            'user_id' => $this->user->id,
            'title' => 'Existing',
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Overlapping',
            'starts_at' => $start->copy()->addMinutes(30)->toIso8601String(),
            'ends_at' => $end->copy()->addMinutes(30)->toIso8601String(),
        ]);

        $response->assertStatus(409);
        // После нарушения ограничения соединение остаётся рабочим (SAVEPOINT в сервисе).
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_can_book_touching_slots()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0)->utc();
        $end = $start->copy()->addHour();

        Booking::create([
            'room_id' => $this->roomMoscow->id,
            'user_id' => $this->user->id,
            'title' => 'Meeting 1',
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Meeting 2',
            'starts_at' => $end->toIso8601String(),
            'ends_at' => $end->copy()->addHour()->toIso8601String(),
        ]);

        $response->assertStatus(201);
    }

    public function test_validates_duration_too_short()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0);
        $end = $start->copy()->addMinutes(10);

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Too Short',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_validates_duration_too_long()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0);
        $end = $start->copy()->addHours(5);

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Too Long',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_validates_step_15_minutes()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(7)->setSecond(0);
        $end = $start->copy()->addHour();

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Bad Step',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_validates_working_hours_local_time()
    {
        $response1 = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Too Early',
            'starts_at' => Carbon::tomorrow('Europe/Moscow')->setHour(8)->toIso8601String(),
            'ends_at' => Carbon::tomorrow('Europe/Moscow')->setHour(9)->toIso8601String(),
        ]);
        $response1->assertStatus(422);

        $response2 = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomNovosibirsk->id,
            'title' => 'Too Late',
            'starts_at' => Carbon::tomorrow('Asia/Novosibirsk')->setHour(20)->addMinutes(30)->toIso8601String(),
            'ends_at' => Carbon::tomorrow('Asia/Novosibirsk')->setHour(21)->toIso8601String(),
        ]);
        $response2->assertStatus(422);
    }

    public function test_cannot_book_in_the_past()
    {
        $start = Carbon::now()->subDays(1)->setHour(10);
        $end = $start->copy()->addHour();

        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'Past Meeting',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_delete_others_booking()
    {
        $otherUser = User::factory()->create();
        $booking = Booking::create([
            'room_id' => $this->roomMoscow->id,
            'user_id' => $otherUser->id,
            'title' => 'Other Person Meeting',
            'starts_at' => Carbon::tomorrow('Europe/Moscow')->setHour(10),
            'ends_at' => Carbon::tomorrow('Europe/Moscow')->setHour(11),
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/bookings/{$booking->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_cannot_access_without_token()
    {
        $response = $this->postJson('/api/bookings', [
            'room_id' => $this->roomMoscow->id,
            'title' => 'No Token',
            'starts_at' => Carbon::tomorrow('Europe/Moscow')->setHour(10)->toIso8601String(),
            'ends_at' => Carbon::tomorrow('Europe/Moscow')->setHour(11)->toIso8601String(),
        ]);

        $response->assertStatus(401);
    }

    public function test_database_rejects_overlapping_booking_bypassing_service()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0)->utc();

        Booking::create([
            'room_id' => $this->roomMoscow->id,
            'user_id' => $this->user->id,
            'title' => 'Existing',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
        ]);

        try {
            Booking::create([
                'room_id' => $this->roomMoscow->id,
                'user_id' => $this->user->id,
                'title' => 'Overlapping',
                'starts_at' => $start->copy()->addMinutes(30),
                'ends_at' => $start->copy()->addMinutes(90),
            ]);
            $this->fail('Ограничение bookings_no_overlap не сработало');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    public function test_database_allows_touching_bookings_and_other_rooms()
    {
        $start = Carbon::tomorrow('Europe/Moscow')->setHour(10)->setMinute(0)->setSecond(0)->utc();
        $end = $start->copy()->addHour();

        foreach ([
            [$this->roomMoscow, $start, $end],
            [$this->roomMoscow, $end, $end->copy()->addHour()],
            [$this->roomNovosibirsk, $start, $end],
        ] as [$room, $startsAt, $endsAt]) {
            Booking::create([
                'room_id' => $room->id,
                'user_id' => $this->user->id,
                'title' => 'Meeting',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        }

        $this->assertDatabaseCount('bookings', 3);
    }
}
