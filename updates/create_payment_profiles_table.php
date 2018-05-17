<?php namespace OFFLINE\Mall\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreatePaymentProfilesTable extends Migration
{
    public function up()
    {
        Schema::create('offline_mall_payment_profiles', function(Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('customer_id')->unsigned()->nullable()->index();
            $table->integer('payment_method_id')->unsigned()->nullable()->index();
            $table->string('vendor_id')->nullable();
            $table->text('profile_data')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('card_country')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('offline_mall_payment_profiles');
    }
}
