<?php
if(!defined('ABSPATH'))exit;
/**
 * GPU price tracker pages: one page per GPU (H100, H200, A100) rendered by
 * page-tracker.php from the daily snapshot and the daily history file.
 */
function tc_tracker_gpus(){
 return array(
  'H100'=>array('slug'=>'h100-pricing','name'=>'NVIDIA H100','title'=>'H100 rental price per hour','excerpt'=>'NVIDIA H100 GPU rental prices compared across Verda, Together AI, Hyperstack, Lambda and Microsoft Azure: on-demand, spot and commitment-term rates per GPU-hour, refreshed daily from public price pages.'),
  'H200'=>array('slug'=>'h200-pricing','name'=>'NVIDIA H200','title'=>'H200 rental price per hour','excerpt'=>'NVIDIA H200 GPU rental prices compared across suppliers that publish them: on-demand and commitment-term rates per GPU-hour, refreshed daily from public price pages.'),
  'A100'=>array('slug'=>'a100-pricing','name'=>'NVIDIA A100','title'=>'A100 rental price per hour','excerpt'=>'NVIDIA A100 80GB GPU rental prices compared across suppliers that publish them: on-demand, spot and commitment-term rates per GPU-hour, refreshed daily from public price pages.'),
 );
}
function tc_tracker_gpu($post=null){
 $post=get_post($post);if(!$post)return 'H100';
 $meta=strtoupper((string)get_post_meta($post->ID,'_tc_gpu',true));if(isset(tc_tracker_gpus()[$meta]))return $meta;
 foreach(tc_tracker_gpus() as $key=>$g){if(stripos($post->post_name,strtolower($key))!==false||stripos($post->post_title,$key)!==false)return $key;}
 return 'H100';
}
function tc_tracker_url($gpu){$ids=get_option('tc_setup_pages',array());$slug=tc_tracker_gpus()[$gpu]['slug']??'';return $slug?tc_page_url($slug):'';}
function tc_tracker_any_url(){foreach(array_keys(tc_tracker_gpus()) as $g){if($u=tc_tracker_url($g))return $u;}return '';}

/** Provider rows for one GPU: Verda (calculated) first, then comparison providers. */
function tc_tracker_rows($gpu,$data=null){
 $data=$data?:tc_pricing_data();$rows=array();
 if(!empty($data['gpus'][$gpu])){
  $g=$data['gpus'][$gpu];$terms=array();foreach(array('1','3','6','12') as $t)if(isset($g['rates'][$t]))$terms[$t]=(float)$g['rates'][$t];
  $rows[]=array('key'=>'verda','name'=>$data['provider']??'Verda','model'=>$g['model'].' '.$g['vram_gb'].'GB · 1 GPU · '.$g['cpus'].' CPUs · '.$g['ram_gb'].'GB RAM','on_demand'=>(float)$g['base_usd'],'spot'=>isset($g['spot_usd'])?(float)$g['spot_usd']:null,'reserved_from'=>null,'terms'=>$terms,'term_note'=>'Calculated: base × (1 − published discount)','unit'=>'USD per GPU-hour, 1-GPU instance','url'=>$data['source_url']??'https://verda.com/pricing','checked_on'=>$data['checked_on']??'','status'=>'ok','tier'=>'self-service','kind'=>'calculated');
 }
 foreach(array('together','hyperstack','lambda','azure') as $key){
  $p=tc_pricing_provider($key,$data);if(!$p||empty($p['gpus'][$gpu]['on_demand_usd']))continue;$h=$p['gpus'][$gpu];
  $terms=array();foreach((array)($h['term_rates']??array()) as $m=>$r)$terms[(string)$m]=(float)$r;ksort($terms,SORT_NUMERIC);
  $note='';if(!empty($h['term_buckets']))$note='Published by duration bucket: '.implode(', ',array_map(function($m,$b){return $b.' → '.$m.' mo';},array_keys((array)$h['term_buckets']),(array)$h['term_buckets']));
  elseif($terms)$note='Published reservation terms';elseif(!empty($h['reserved_from_usd']))$note='Reserved: starting-from price, term not published';else $note='No term schedule published';
  $spot=isset($h['spot_usd'])?(float)$h['spot_usd']:(isset($h['preemptible_usd'])?(float)$h['preemptible_usd']:null);
  $model=$h['model'].(isset($h['vram_gb'])&&$h['vram_gb']?' '.$h['vram_gb'].'GB':'').(!empty($h['gpu_count'])&&$h['gpu_count']>1?' · '.$h['gpu_count'].' GPUs per VM':'').(isset($h['cpus'])?' · '.$h['cpus'].' CPUs':'').(isset($h['max_cpus_per_gpu'])?' · up to '.$h['max_cpus_per_gpu'].' CPUs/GPU':'').(isset($h['vcpus'])&&$h['vcpus']?' · '.$h['vcpus'].' vCPUs':'');
  $rows[]=array('key'=>$key,'name'=>$p['name'],'model'=>$model,'on_demand'=>(float)$h['on_demand_usd'],'spot'=>$spot,'reserved_from'=>!empty($h['reserved_from_usd'])?(float)$h['reserved_from_usd']:null,'terms'=>$terms,'term_note'=>$note,'unit'=>$p['unit']??'','url'=>$p['source_url']??'','checked_on'=>$p['checked_on']??'','status'=>(string)($p['status']??'ok'),'tier'=>$p['tier']??'self-service','kind'=>'published');
 }
 return $rows;
}

/** Daily history: fetched from the feed's sibling CSV, cached, bundled fallback. Returns rows of assoc arrays. */
function tc_pricing_history(){
 static $rows=null;if($rows!==null)return $rows;
 $parse=function($csv){$out=array();$lines=preg_split('/\r?\n/',trim((string)$csv));if(count($lines)<2)return $out;$head=str_getcsv(array_shift($lines));foreach($lines as $l){if($l==='')continue;$c=str_getcsv($l);if(count($c)!==count($head))continue;$out[]=array_combine($head,$c);}return $out;};
 $bundled=function()use($parse){$raw=@file_get_contents(get_template_directory().'/assets/data/pricing-history.csv');return $raw?$parse($raw):array();};
 if(!tc_pricing_auto_enabled())return $rows=$bundled();
 $cached=get_transient('tc_pricing_history');if(is_array($cached)&&$cached)return $rows=$cached;
 $url=preg_replace('/pricing-snapshot\.json(\?.*)?$/','pricing-history.csv',tc_pricing_feed_url());
 $fresh=array();if($url&&$url!==tc_pricing_feed_url()){$r=wp_remote_get($url,array('timeout'=>8));if(!is_wp_error($r)&&(int)wp_remote_retrieve_response_code($r)===200)$fresh=$parse(wp_remote_retrieve_body($r));}
 $local=$bundled();
 if(count($fresh)>=count($local)){set_transient('tc_pricing_history',$fresh,TC_PRICING_CACHE_TTL);update_option('tc_pricing_history_last_good',$fresh,false);return $rows=$fresh;}
 $last=get_option('tc_pricing_history_last_good');$rows=(is_array($last)&&count($last)>=count($local))?$last:$local;set_transient('tc_pricing_history',$rows,TC_PRICING_RETRY_TTL);return $rows;
}
/** On-demand series per provider for a GPU: [provider => [date => price]] plus sorted date list. */
function tc_tracker_series($gpu){
 $series=array();$dates=array();$excluded=array();$data=tc_pricing_data();
 foreach((array)($data['providers']??array()) as $p){if(!empty($p['tier'])&&$p['tier']==='hyperscaler'&&!empty($p['name']))$excluded[$p['name']]=$p['name'];}
 foreach(tc_pricing_history() as $r){
  if(($r['gpu']??'')!==$gpu)continue;if(isset($excluded[$r['provider']??'']))continue;$t=$r['term_months']??'';
  $is_od=($t==='on-demand')||($r['provider']==='Verda'&&$t==='1');
  if(!$is_od)continue;
  $price=$r['provider']==='Verda'?(float)$r['base_usd_per_hour']:(float)$r['calculated_usd_per_gpu_hour'];
  if($price<=0)continue;$series[$r['provider']][$r['checked_on']]=$price;$dates[$r['checked_on']]=1;
 }
 ksort($dates);foreach($series as &$s)ksort($s);unset($s);
 return array('series'=>$series,'dates'=>array_keys($dates),'excluded'=>array_values($excluded));
}
function tc_tracker_provider_color($name){
 $map=array('Verda'=>'#176858','Together AI'=>'#a35d2a','Hyperstack'=>'#3b5f8a','Lambda'=>'#7a5c9e','Microsoft Azure'=>'#5f6f7a');return $map[$name]??'#61766a';
}

/** SEO: meta description from the page excerpt (already handled by tc_seo) plus Dataset structured data. */
function tc_tracker_jsonld(){
 if(!is_page_template('page-tracker.php')||tc_has_seo_plugin())return;
 $gpu=tc_tracker_gpu();$g=tc_tracker_gpus()[$gpu];$data=tc_pricing_data();
 $ld=array('@context'=>'https://schema.org','@type'=>'Dataset','name'=>$g['name'].' GPU rental price tracker','description'=>$g['excerpt'],'url'=>get_permalink(),'license'=>'https://www.gnu.org/licenses/gpl-2.0.html','isAccessibleForFree'=>true,'creator'=>array('@type'=>'Organization','name'=>get_bloginfo('name')),'dateModified'=>$data['checked_on']??'','temporalCoverage'=>(tc_pricing_history()?(tc_pricing_history()[0]['checked_on']??'').'/'.($data['checked_on']??''):''),'variableMeasured'=>'USD per GPU-hour','distribution'=>array(array('@type'=>'DataDownload','encodingFormat'=>'application/json','contentUrl'=>tc_pricing_download_url('json')),array('@type'=>'DataDownload','encodingFormat'=>'text/csv','contentUrl'=>tc_pricing_download_url('csv'))));
 echo '<script type="application/ld+json">'.wp_json_encode($ld,JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_SLASHES).'</script>'."\n";
}
add_action('wp_head','tc_tracker_jsonld',6);
function tc_tracker_flush(){delete_transient('tc_pricing_history');}
add_action('customize_save_after','tc_tracker_flush');

/** Data-driven "at a glance" sentences for one GPU: unique per page and refreshed with the data. */
function tc_tracker_glance($gpu){
 $data=tc_pricing_data();$rows=tc_tracker_rows($gpu,$data);$out=array();if(!$rows)return $out;
 $usd=function($v,$d=2){return tc_pricing_usd($v,$d);};$pct=function($a,$b){return $b>0?round(($a-$b)/$b*100,1):0;};
 $self=array_filter($rows,function($r){return $r['tier']!=='hyperscaler';});$hyper=array_filter($rows,function($r){return $r['tier']==='hyperscaler';});
 // 1) On-demand range among self-service suppliers.
 if(count($self)>=2){
  usort($self,function($a,$b){return $a['on_demand']<=>$b['on_demand'];});$lo=reset($self);$hi=end($self);
  $out[]=sprintf('Among %d self-service suppliers publishing an on-demand %s rate, the lowest is %s at %s per GPU-hour and the highest is %s at %s, a spread of %s%%.',count($self),$gpu,$lo['name'],$usd($lo['on_demand']),$hi['name'],$usd($hi['on_demand']),number_format($pct($hi['on_demand'],$lo['on_demand']),1));
 }elseif(count($self)===1){$r=reset($self);$out[]=sprintf('Only one self-service supplier we track, %s, publishes an on-demand %s rate: %s per GPU-hour.',$r['name'],$gpu,$usd($r['on_demand']));}
 // 2) Best published commitment rate.
 $best=null;foreach($rows as $r){foreach($r['terms'] as $m=>$v){if($r['tier']==='hyperscaler')continue;if($best===null||$v<$best['v'])$best=array('v'=>$v,'m'=>(int)$m,'r'=>$r);}}
 if($best){$m=$best['m'];$term=$m>=12&&$m%12===0?($m/12).'-year':$m.'-month';$out[]=sprintf('The lowest published commitment rate is %s at %s per GPU-hour for a %s term, %s%% below its own on-demand price.',$best['r']['name'],$usd($best['v']),$term,number_format(abs($pct($best['v'],$best['r']['on_demand'])),1));}
 // 3) Hyperscaler comparison.
 foreach($hyper as $r){$long=$r['terms']?end($r['terms']):null;$lm=$r['terms']?array_key_last($r['terms']):null;$cheapest=$self?min(array_map(function($x){return $x['on_demand'];},$self)):null;
  $s=sprintf('%s lists %s at %s per GPU-hour on-demand',$r['name'],$gpu,$usd($r['on_demand']));if($cheapest)$s.=sprintf(', %.1f× the cheapest self-service on-demand rate',$r['on_demand']/$cheapest);if($long!==null)$s.=sprintf('; its longest published reservation (%s) brings that to %s',((int)$lm>=12?((int)$lm/12).'-year':$lm.'-month'),$usd($long));$out[]=$s.'.';}
 // 4) Premium versus H100 at suppliers publishing both.
 if($gpu!=='H100'){$h100=tc_tracker_rows('H100',$data);$by=array();foreach($h100 as $r)$by[$r['key']]=$r['on_demand'];$parts=array();
  foreach($rows as $r){if(isset($by[$r['key']])&&$by[$r['key']]>0){$d=$pct($r['on_demand'],$by[$r['key']]);$parts[]=sprintf('%s %s%s%%',$r['name'],$d>=0?'+':'−',number_format(abs($d),1));}}
  if($parts)$out[]=sprintf('Relative to the same supplier’s H100 on-demand rate, %s is priced at %s.',$gpu,implode(', ',$parts));}
 // 5) Movement over the collected history.
 $hist=tc_tracker_series($gpu);$dates=$hist['dates'];$n=count($dates);
 if($n>=2){$moved=array();$flat=array();foreach($hist['series'] as $name=>$s){$first=reset($s);$last=end($s);$changes=0;$prev=null;foreach($s as $v){if($prev!==null&&abs($v-$prev)>0.00001)$changes++;$prev=$v;}
   if(abs($last-$first)>0.00001)$moved[]=sprintf('%s moved from %s to %s (%s%s%%, %d change%s)',$name,$usd($first),$usd($last),$last>=$first?'+':'−',number_format(abs($pct($last,$first)),1),$changes,$changes===1?'':'s');else $flat[]=$name;}
  $s=sprintf('Over the %d days collected so far (%s to %s), ',$n,tc_pricing_date(array('checked_on'=>$dates[0])),tc_pricing_date(array('checked_on'=>$dates[$n-1])));
  if($moved)$s.=implode('; ',$moved).($flat?'; ':'.');if($flat)$s.=implode(', ',$flat).' '.(count($flat)===1?'has':'have').' not changed'.($moved?'.':' the published on-demand rate.');$out[]=$s;}
 return $out;
}
