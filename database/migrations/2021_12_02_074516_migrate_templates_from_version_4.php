<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

class MigrateTemplatesFromVersion4 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $templates = Storage::disk('views')->files('/app/pdf/invoice');

        foreach ($templates as $key => $template) {
            $templateName = Str::before(basename($template), '.blade.php');
            $source = public_path("/assets/img/PDF/{$templateName}.png");
            if (! file_exists($source)) {
                continue;
            }
            if (! file_exists(resource_path("/static/img/PDF/{$templateName}.png"))) {
                @mkdir(public_path('/build/img/PDF'), 0775, true);
                @mkdir(resource_path('/static/img/PDF'), 0775, true);
                @copy($source, public_path("/build/img/PDF/{$templateName}.png"));
                @copy($source, resource_path("/static/img/PDF/{$templateName}.png"));
            }
        }

        $templates = Storage::disk('views')->files('/app/pdf/estimate');

        foreach ($templates as $key => $template) {
            $templateName = Str::before(basename($template), '.blade.php');
            $source = public_path("/assets/img/PDF/{$templateName}.png");
            if (! file_exists($source)) {
                continue;
            }
            if (! file_exists(resource_path("/static/img/PDF/{$templateName}.png"))) {
                @mkdir(public_path('/build/img/PDF'), 0775, true);
                @mkdir(resource_path('/static/img/PDF'), 0775, true);
                @copy($source, public_path("/build/img/PDF/{$templateName}.png"));
                @copy($source, resource_path("/static/img/PDF/{$templateName}.png"));
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
