<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION check_booking_overlap()
            RETURNS TRIGGER AS $$
            BEGIN
                -- БЛОКИРОВКА: Заставляем текущий запрос подождать,
                -- если другая транзакция сейчас меняет эту же комнату.
                PERFORM 1 FROM rooms WHERE id = NEW.room_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM bookings
                    WHERE room_id = NEW.room_id
                    AND (NEW.id IS NULL OR id != NEW.id)
                    AND starts_at < NEW.ends_at
                    AND ends_at > NEW.starts_at
                ) THEN
                    RAISE EXCEPTION 'Room is already booked for the selected time';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER trigger_check_booking_overlap
            BEFORE INSERT OR UPDATE ON bookings
            FOR EACH ROW EXECUTE FUNCTION check_booking_overlap();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trigger_check_booking_overlap ON bookings;');
        DB::statement('DROP FUNCTION IF EXISTS check_booking_overlap();');
    }
};
