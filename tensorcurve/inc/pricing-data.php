<?php
if(!defined('ABSPATH'))exit;
/**
 * Pricing snapshot access.
 *
 * The theme ships a bundled snapshot in assets/data/pricing-snapshot.json.
 * When automatic refresh is enabled (default), the same file is fetched from
 * the published feed URL (the GitHub repository by default), cached in a
 * transient and validated before use. Any failure falls back to the last good
 * copy, then to the bundled file, so the Pricing Lab never renders empty.
 */
define('TC_PRICING_DEFAULT_FEED','https://raw.githubusercontent.com/tensorcurve/web/main/tensorcurve/assets/data/pricing-snapshot.json');
define('TC_PRICING_CACHE_TTL',6*HOUR_IN_SECONDS);
define('TC_PRICING_RETRY_TTL',30*MINUTE_IN_SECONDS);

function tc_pricing_feed_url(){
 $url=get_theme_mod('tc_pricing_feed_url','');
 $url=$url?esc_url_raw(trim($url)):TC_PRICING_DEFAULT_FEED;
 return apply_filters('tc_pricing_feed_url',$url);
}
function tc_pricing_auto_enabled(){return (bool)apply_filters('tc_pricing_auto_enabled',get_theme_mod('tc_pricing_auto',true));}

function tc_pricing_valid($data){
 if(!is_array($data)||empty($data['gpus'])||!is_array($data['gpus'])||empty($data['discounts'])||empty($data['checked_on']))return false;
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$data['checked_on']))return false;
 foreach(array('H100','H200','A100') as $key){
  if(empty($data['gpus'][$key]['rates'])||empty($data['gpus'][$key]['model']))return false;
  foreach(array('1','3','6','12') as $t){
   if(!isset($data['gpus'][$key]['rates'][$t])||!isset($data['discounts'][$t]))return false;
   $rate=(float)$data['gpus'][$key]['rates'][$t];if($rate<=0||$rate>1000)return false;
  }
 }
 return true;
}
function tc_pricing_bundled(){
 static $bundled=null;if($bundled!==null)return $bundled;
 $raw=@file_get_contents(get_template_directory().'/assets/data/pricing-snapshot.json');
 $data=$raw?json_decode($raw,true):null;
 $bundled=tc_pricing_valid($data)?$data:array();
 return $bundled;
}
function tc_pricing_fetch(){
 $response=wp_remote_get(tc_pricing_feed_url(),array('timeout'=>8,'headers'=>array('Accept'=>'application/json','User-Agent'=>'TensorCurve/'.TC_VERSION.'; '.home_url('/'))));
 if(is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)!==200)return null;
 $data=json_decode(wp_remote_retrieve_body($response),true);
 return tc_pricing_valid($data)?$data:null;
}
function tc_pricing_data(){
 static $data=null;if($data!==null)return $data;
 if(!tc_pricing_auto_enabled())return $data=tc_pricing_bundled();
 $cached=get_transient('tc_pricing_snapshot');
 if(is_array($cached)&&tc_pricing_valid($cached))return $data=$cached;
 $fresh=tc_pricing_fetch();
 if($fresh){
  $bundled=tc_pricing_bundled();
  // Never step backwards: a feed older than the bundled file means the bundle is newer.
  if($bundled&&strcmp((string)$bundled['checked_on'],(string)$fresh['checked_on'])>0)$fresh=$bundled;
  set_transient('tc_pricing_snapshot',$fresh,TC_PRICING_CACHE_TTL);
  update_option('tc_pricing_last_good',$fresh,false);
  return $data=$fresh;
 }
 $last=get_option('tc_pricing_last_good');
 $fallback=(is_array($last)&&tc_pricing_valid($last))?$last:tc_pricing_bundled();
 if($fallback)set_transient('tc_pricing_snapshot',$fallback,TC_PRICING_RETRY_TTL);
 return $data=$fallback;
}
function tc_pricing_date($data=null,$format='j M Y'){
 $data=$data?:tc_pricing_data();$date=isset($data['checked_on'])?(string)$data['checked_on']:'';
 $ts=$date?strtotime($date.' 12:00:00 UTC'):false;
 return $ts?gmdate($format,$ts):$date;
}
function tc_pricing_rate($gpu,$term,$data=null){
 $data=$data?:tc_pricing_data();
 return isset($data['gpus'][$gpu]['rates'][(string)$term])?(float)$data['gpus'][$gpu]['rates'][(string)$term]:null;
}
function tc_pricing_provider($key,$data=null){
 $data=$data?:tc_pricing_data();
 if(empty($data['providers'][$key])||!is_array($data['providers'][$key]))return null;
 $p=$data['providers'][$key];if(empty($p['name'])||empty($p['gpus'])||!is_array($p['gpus']))return null;
 return $p;
}
function tc_pricing_usd($value,$places=4){return '$'.number_format((float)$value,$places,'.',',');}
function tc_pricing_refresh_note(){return tc_pricing_auto_enabled()?__('Refreshed daily from the public source','tensorcurve'):__('No automatic refresh','tensorcurve');}
function tc_pricing_download_url($format='json'){return add_query_arg('tc_pricing',$format==='csv'?'csv':'json',home_url('/'));}

/** Serve the current snapshot at /?tc_pricing=json or /?tc_pricing=csv so downloads never go stale. */
function tc_pricing_download(){
 if(!isset($_GET['tc_pricing']))return;
 $format=sanitize_key(wp_unslash($_GET['tc_pricing']));$data=tc_pricing_data();
 if(!$data){status_header(503);nocache_headers();exit;}
 nocache_headers();header('Cache-Control: public, max-age=3600');header('X-Robots-Tag: noindex');
 if($format==='csv'){
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: inline; filename="pricing-snapshot.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,array('provider','gpu','vram_gb','gpu_count','cpus','ram_gb','region','guaranteed_start','term_months','base_usd_per_hour','discount','calculated_usd_per_gpu_hour','checked_on','source_url','classification'));
  foreach($data['gpus'] as $g)foreach(array('1','3','6','12') as $t)fputcsv($out,array($data['provider']??'',$g['model']??'',$g['vram_gb']??'',$g['gpu_count']??1,$g['cpus']??'',$g['ram_gb']??'','not specified','not verified',$t,$g['base_usd']??'',$data['discounts'][$t]??'',$g['rates'][$t]??'',$data['checked_on']??'',$data['source_url']??'',$data['classification']??''));
  fclose($out);exit;
 }
 header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: inline; filename="pricing-snapshot.json"');
 echo wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;
}
add_action('template_redirect','tc_pricing_download',0);

/** Admin: clear the cache from Appearance → TensorCurve Setup. */
function tc_pricing_flush_cache(){delete_transient('tc_pricing_snapshot');}
add_action('customize_save_after','tc_pricing_flush_cache');
add_action('switch_theme','tc_pricing_flush_cache');
