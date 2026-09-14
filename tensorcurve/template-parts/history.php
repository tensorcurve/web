<?php if(!defined("ABSPATH"))exit;
$gpu_key=isset($args['gpu'])?strtoupper($args['gpu']):'H100';$hist=isset($args['series'])?$args['series']:tc_tracker_series($gpu_key);
$series=$hist['series'];$dates=$hist['dates'];if(!$series||!$dates)return;
$all=array();foreach($series as $s)foreach($s as $v)$all[]=$v;
$lo=floor((min($all)*0.96)*10)/10;$hi=ceil((max($all)*1.04)*10)/10;if($hi-$lo<0.5)$hi=$lo+0.5;
$w=500;$h=280;$top=24;$bottom=$h-48;$left=52;$right=470;$n=count($dates);
$x=function($i)use($left,$right,$n){return $n>1?$left+14+$i*(($right-$left-28)/($n-1)):($left+$right)/2;};
$y=function($v)use($lo,$hi,$top,$bottom){return $top+($hi-$v)/($hi-$lo)*($bottom-$top);};
$idx=array_flip($dates);$first=$dates[0];$last=$dates[$n-1];
$fmtd=function($d){$t=strtotime($d.' 12:00:00 UTC');return $t?gmdate('j M',$t):$d;};
$label=$gpu_key.' on-demand price history per GPU-hour, '.$fmtd($first).' to '.$fmtd($last).' ('.$n.' day'.($n===1?'':'s').'). ';
foreach($series as $name=>$s){$label.=$name.': latest '.number_format(end($s),2).', first '.number_format(reset($s),2).'. ';}
?><figure class="editorial-chart"><div class="chart-kicker"><span><?php echo esc_html($gpu_key); ?> ON-DEMAND · DAILY</span><span>USD / GPU-hour</span></div><svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" role="img" aria-label="<?php echo esc_attr($label); ?>"><?php
foreach(array($hi,($hi+$lo)/2,$lo) as $tick){$py=round($y($tick),2);echo '<line x1="'.$left.'" y1="'.$py.'" x2="'.$right.'" y2="'.$py.'" stroke="#d7e2df"/><text x="'.($left-8).'" y="'.($py+4).'" text-anchor="end">'.esc_html(number_format($tick,2)).'</text>';}
$ticks=$n<=6?range(0,$n-1):array(0,(int)floor(($n-1)/3),(int)floor(2*($n-1)/3),$n-1);
foreach(array_unique($ticks) as $i)echo '<text x="'.round($x($i),2).'" y="'.($h-22).'" text-anchor="middle">'.esc_html($fmtd($dates[$i])).'</text>';
$labels=array();
foreach($series as $name=>$s){$color=tc_tracker_provider_color($name);$pts=array();foreach($s as $d=>$v)$pts[]=round($x($idx[$d]),2).','.round($y($v),2);
 if(count($pts)>1)echo '<polyline points="'.esc_attr(implode(' ',$pts)).'" fill="none" stroke="'.esc_attr($color).'" stroke-width="2.5" stroke-linejoin="round"/>';
 foreach($s as $d=>$v)echo '<circle cx="'.round($x($idx[$d]),2).'" cy="'.round($y($v),2).'" r="'.(count($s)>30?2:3.5).'" fill="'.esc_attr($color).'"><title>'.esc_html($name.' · '.$d.' · '.tc_pricing_usd($v,2)).'</title></circle>';
 $lastv=end($s);$ld=array_key_last($s);$labels[]=array('x'=>round($x($idx[$ld])+8,2),'y'=>round($y($lastv),2),'color'=>$color,'text'=>$name.' '.tc_pricing_usd($lastv,2));}
usort($labels,function($a,$b){return $a['y']<=>$b['y'];});$prev=-999;foreach($labels as &$l){if($l['y']-$prev<14)$l['y']=$prev+14;$prev=$l['y'];}unset($l);
foreach($labels as $l)echo '<text class="value" style="fill:'.esc_attr($l['color']).'" x="'.esc_attr($l['x']).'" y="'.esc_attr($l['y']+4).'" text-anchor="start">'.esc_html($l['text']).'</text>';
?></svg><div class="chart-legend"><?php foreach($series as $name=>$s): ?><span><i style="--c:<?php echo esc_attr(tc_tracker_provider_color($name)); ?>"></i><?php echo esc_html($name); ?></span><?php endforeach; ?></div><figcaption>One observation per day per supplier; the line joins consecutive daily checks. <?php if($n<7): ?>History began <?php echo esc_html($fmtd($first)); ?>; <?php echo esc_html($n); ?> day<?php echo $n===1?'':'s'; ?> collected so far. The chart fills in as daily checks accumulate.<?php else: ?><?php echo esc_html($fmtd($first)); ?> to <?php echo esc_html($fmtd($last)); ?>, <?php echo esc_html($n); ?> days.<?php endif; ?> Verda is its published on-demand base; other suppliers are their published on-demand rate. A flat line means the public page did not change.<?php if(!empty($hist['excluded'])): ?> <?php echo esc_html(implode(', ',$hist['excluded'])); ?> is tracked but plotted separately in the table because its per-GPU price sits several dollars above this range.<?php endif; ?></figcaption></figure>
