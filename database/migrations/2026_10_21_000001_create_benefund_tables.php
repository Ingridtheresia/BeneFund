<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('users',function(Blueprint $t){$t->string('role')->default('donor');});
  Schema::create('campaigns',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('title');$t->string('category');$t->string('location')->nullable();$t->text('description');$t->unsignedBigInteger('target');$t->date('deadline');$t->string('image_url')->nullable();$t->string('status')->default('pending');$t->timestamps();});
  Schema::create('donations',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('campaign_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('amount');$t->string('method');$t->text('message')->nullable();$t->boolean('anonymous')->default(false);$t->string('status')->default('pending');$t->timestamp('paid_at')->nullable();$t->timestamps();});
  Schema::create('campaign_updates',function(Blueprint $t){$t->id();$t->foreignId('campaign_id')->constrained()->cascadeOnDelete();$t->string('title');$t->text('body');$t->timestamps();});
  Schema::create('expenses',function(Blueprint $t){$t->id();$t->foreignId('campaign_id')->constrained()->cascadeOnDelete();$t->string('title');$t->unsignedBigInteger('amount');$t->text('description')->nullable();$t->date('spent_at');$t->timestamps();});
  Schema::create('favorites',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('campaign_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->unique(['user_id','campaign_id']);});
 }
 public function down():void{Schema::dropIfExists('favorites');Schema::dropIfExists('expenses');Schema::dropIfExists('campaign_updates');Schema::dropIfExists('donations');Schema::dropIfExists('campaigns');Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('role'));}
};
