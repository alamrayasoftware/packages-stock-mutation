<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockMutationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stock_mutation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_id');
            $table->date('date');
            $table->integer('qty')->default(0);
            $table->integer('used')->default(0);
            $table->string('mutation_reference')->nullable();
            $table->string('trx_reference')->nullable();
            $table->integer('hpp')->nullable();
            $table->enum('type', ['in', 'out']);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('stock_id')->references('id')->on('stock')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('stock_mutation');
    }
}
