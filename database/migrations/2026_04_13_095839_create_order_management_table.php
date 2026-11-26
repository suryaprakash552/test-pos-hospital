<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderManagementTable extends Migration
{
    public function up()
    {
        Schema::create('order_management', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');

            $table->unsignedBigInteger('product_id');
            $table->string('product_name');

            $table->string('rack');
            $table->string('shelf');

            $table->double('lower_qty')->default(0);
            $table->double('upper_qty')->default(0);

            $table->date('from_date');
            $table->date('to_date');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_management');
    }
}
