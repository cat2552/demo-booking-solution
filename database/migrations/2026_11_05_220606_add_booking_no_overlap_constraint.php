<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Запрещает пересечение броней одной комнаты на уровне PostgreSQL.
     *
     * Полуоткрытый интервал '[)' разрешает встречи «впритык» (10:00–11:00 и 11:00–12:00).
     * btree_gist нужен, чтобы использовать оператор равенства для room_id в GiST-индексе.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                room_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
    }
};
