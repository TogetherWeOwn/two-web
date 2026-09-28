<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TOG-8707: featured images rendered with an empty alt attribute, so
        // screen-reader visitors got nothing. The description lives beside the
        // URL it describes; nullable because rows saved before this column
        // existed (and rows with no image at all) have no alt to carry — the
        // render sites fall back to the row title instead (see
        // FeaturedContent::imageAltText), so every <img> still gets a
        // non-empty alt without rewriting history.
        Schema::table('featured_contents', function (Blueprint $table) {
            $table->string('image_alt')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('featured_contents', function (Blueprint $table) {
            $table->dropColumn('image_alt');
        });
    }
};
