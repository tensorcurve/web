<?php if(!defined("ABSPATH"))exit;
$data=tc_pricing_data();$terms=array(1,3,6,12);$values=array();foreach($terms as $t){$v=tc_pricing_rate('H100',$t,$data);if($v!==null)$values[$t]=$v;}
if(count($values)<4)return;
$gpu=$data['gpus']['H100'];$checked=tc_pricing_date($data);$source=isset($data['source_url'])?$data['source_url']:'https://verda.com/pricing';
// Comparison reference lines: other suppliers' published H100 prices (no term schedule published, so flat lines).
$refs=array();$palette=array('hyperstack'=>'#3b5f8a','lambda'=>'#a35d2a');
foreach(array('hyperstack','lambda') as $key){
 $p=tc_pricing_provider($key,$data);if(!$p||empty($p['gpus']['H100']['on_demand_usd']))continue;
 $h=$p['gpus']['H100'];$color=$palette[$key];$name=$p['name'];
 $refs[]=array('label'=>$name.' on-demand','value'=>(float)$h['on_demand_usd'],'color'=>$color,'dash'=>'7 5','url'=>$p['source_url']);
 if(!empty($h['reserved_from_usd']))$refs[]=array('label'=>$name.' reserved from','value'=>(float)$h['reserved_from_usd'],'color'=>$color,'dash'=>'2 4','url'=>$p['source_url']);
}
$all=array_merge(array_values($values),array_map(function($r){return $r['value'];},$refs));
$lo=floor((min($all)-0.05)*10)/10;$hi=ceil((max($all)+0.05)*10)/10;if($hi-$lo<0.4){$hi=$lo+0.4;}
$h_svg=$refs?300:265;$top=60;$bottom=$h_svg-60;$xs=array(1=>60,3=>185,6=>310,12=>435);$y=function($v)use($lo,$hi,$top,$bottom){return $top+($hi-$v)/($hi-$lo)*($bottom-$top);};
$label=sprintf('Calculated Verda H100 rates: one month %s dollars, three months %s, six months %s, twelve months %s per GPU-hour. Vertical axis runs from %s to %s dollars.',number_format($values[1],4),number_format($values[3],4),number_format($values[6],4),number_format($values[12],4),number_format($lo,1),number_format($hi,1));
foreach($refs as $r)$label.=sprintf(' %s: %s dollars, shown as a flat reference line because no term schedule is published.',$r['label'],number_format($r['value'],2));
$ticks=$refs?array($hi,$lo+($hi-$lo)*2/3,$lo+($hi-$lo)/3,$lo):array($hi,($hi+$lo)/2,$lo);
?><figure class="editorial-chart"><div class="chart-kicker"><span>H100 COMMITMENT CURVE</span><span>USD / GPU-hour</span></div><svg viewBox="0 0 500 <?php echo (int)$h_svg; ?>" role="img" aria-label="<?php echo esc_attr($label); ?>"><?php
foreach($ticks as $tick){$py=$y($tick);echo '<line x1="45" y1="'.esc_attr(round($py,2)).'" x2="465" y2="'.esc_attr(round($py,2)).'" stroke="#d7e2df"/><text x="12" y="'.esc_attr(round($py+5,2)).'">'.esc_html(number_format($tick,1)).'</text>';}
foreach($refs as $r){$py=round($y($r['value']),2);echo '<line class="ref" x1="45" y1="'.esc_attr($py).'" x2="465" y2="'.esc_attr($py).'" stroke="'.esc_attr($r['color']).'" stroke-width="2" stroke-dasharray="'.esc_attr($r['dash']).'"><title>'.esc_html($r['label'].' '.tc_pricing_usd($r['value'],2)).'</title></line>';}
$points=array();foreach($terms as $t)$points[]=$xs[$t].','.round($y($values[$t]),2);
echo '<polyline points="'.esc_attr(implode(' ',$points)).'" fill="none" stroke="#176858" stroke-width="3"/>';
foreach($terms as $t){$px=$xs[$t];$py=round($y($values[$t]),2);echo '<circle cx="'.$px.'" cy="'.esc_attr($py).'" r="5" fill="#176858"/><text class="value" x="'.$px.'" y="'.esc_attr($py-14).'" text-anchor="middle">'.esc_html(tc_pricing_usd($values[$t])).'</text><text x="'.$px.'" y="'.esc_attr($h_svg-25).'" text-anchor="middle">'.esc_html($t.' month'.($t===1?'':'s')).'</text>';}
?></svg><?php if($refs): ?><div class="chart-legend"><span><i style="--c:#176858;--d:none"></i>Verda term rates (calculated)</span><?php foreach($refs as $r): ?><span><i style="--c:<?php echo esc_attr($r['color']); ?>;--d:<?php echo $r['dash']==='7 5'?'dashed':'dotted'; ?>"></i><?php echo esc_html($r['label']); ?> <b><?php echo esc_html(tc_pricing_usd($r['value'],2)); ?></b></span><?php endforeach; ?></div><?php endif; ?><figcaption>Verda · <?php echo esc_html($gpu['model'].' '.$gpu['vram_gb'].'GB'); ?> · Checked <?php echo esc_html($checked); ?><br>Calculated from <a href="<?php echo esc_url($source); ?>">published base + discounts</a>. Equal term spacing; vertical axis does not start at zero. Region and start availability unverified.<?php if($refs): ?><br>Reference lines: published H100 SXM on-demand rates from <?php $links=array();foreach(array('hyperstack','lambda') as $key){$p=tc_pricing_provider($key,$data);if($p)$links[]='<a href="'.esc_url($p['source_url']).'">'.esc_html($p['name']).'</a>';}echo implode(' and ',$links); ?> (1-GPU instance or per-GPU VM). Neither publishes a term-discount schedule, so no curve is derived. Configurations differ; see the buyer guide before comparing.<?php endif; ?></figcaption></figure>
