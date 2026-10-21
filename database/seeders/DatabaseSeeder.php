<?php
namespace Database\Seeders;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Donation;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder {
 public function run():void{
 $admin=User::firstOrCreate(['email'=>'admin@benefund.test'],['name'=>'Admin BeneFund','password'=>'Password123!','role'=>'admin']);
 $owner=User::firstOrCreate(['email'=>'fundraiser@benefund.test'],['name'=>'Yayasan Peduli Negeri','password'=>'Password123!','role'=>'fundraiser']);
 $donor=User::firstOrCreate(['email'=>'donor@benefund.test'],['name'=>'Ahmad Rizki','password'=>'Password123!','role'=>'donor']);
 $items=[['Bantu Korban Banjir Sumatera','Bencana','Sumatera Utara',500000000,'https://images.unsplash.com/photo-1547683905-f686c993aae5?w=1200'],['Beasiswa untuk Anak Yatim Berprestasi','Pendidikan','Medan',80000000,'https://images.unsplash.com/photo-1509062522246-3755977927d7?w=1200'],['Renovasi Masjid Al-Ikhlas','Masjid','Yogyakarta',300000000,'https://images.unsplash.com/photo-1591604466107-ec97de577aff?w=1200'],['Bantuan Pengobatan Anak','Kesehatan','Jakarta',175000000,'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?w=1200'],['Pembangunan Panti Asuhan Harapan','Panti Asuhan','Bandung',450000000,'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=1200'],['Penghijauan Hutan untuk Masa Depan','Lingkungan','Kalimantan',125000000,'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=1200']];
 foreach($items as $i=>$x){$c=Campaign::firstOrCreate(['title'=>$x[0]],['user_id'=>$owner->id,'category'=>$x[1],'location'=>$x[2],'target'=>$x[3],'image_url'=>$x[4],'description'=>'Bersama kita dapat memberikan dampak nyata bagi masyarakat. Dana akan digunakan sesuai kebutuhan program dan dilaporkan secara terbuka kepada seluruh donatur. Setiap kontribusi sangat berarti untuk membantu penerima manfaat.','deadline'=>now()->addDays(30+$i*5),'status'=>'approved']);if(!$c->donations()->exists())Donation::create(['user_id'=>$donor->id,'campaign_id'=>$c->id,'amount'=>100000*($i+1),'method'=>'qris','status'=>'paid','paid_at'=>now()]);}
 }
}
