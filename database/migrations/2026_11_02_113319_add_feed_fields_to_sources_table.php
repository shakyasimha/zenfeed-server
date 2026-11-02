<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            //
            if (! Schema::hasColumn('sources', 'feed_url')) {
                $table->text('feed_url')->nullable()->after('url');
            }

            if (! Schema::hasColumn('sources', 'selector_rules')) {
                $table->json('selector_rules')->nullable()->after('type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            //
            $table->dropColumn(['feed_url', 'selector_rules']);
        });
    }
};
