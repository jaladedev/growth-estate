<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        DB::statement("
            ALTER TABLE lands
            ALTER COLUMN coordinates
            TYPE geometry(Point, 4326)
            USING ST_SetSRID(
                ST_MakePoint(
                    coordinates[0],
                    coordinates[1]
                ),
                4326
            )
        ");
    }

    public function down()
    {
        DB::statement("
            ALTER TABLE lands
            ALTER COLUMN coordinates
            TYPE point
            USING POINT(
                ST_X(coordinates),
                ST_Y(coordinates)
            )
        ");
    }
};
