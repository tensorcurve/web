<?php if(!defined("ABSPATH"))exit;
$data=tc_pricing_data();$terms=array(1,3,6,12);$values=array();foreach($terms as $t){$v=tc_pricing_rate('H100',$t,$data);if($v!==null)$values[$t]=$v;}
if(count($values)<4)return;
$gpu=$data['gpus']['H100'];$checked=tc_pricing_date($data);$source=isset($data['source_url'])?$data['source_url']:'https://verda.com/pricing';
$lo=floor((min($values)-0.05)*10)/10;$hi=ceil((max($values)+0.05)*10)/10;if($hi-$lo<0.4){$hi=$lo+0.4;}
$top=60;$bottom=205;$xs=array(1=>60,3=>185,6=>310,12=>435);$y=function($v)use($lo,$hi,$top,$bottom){return $top+($hi-$v)/($hi-$lo)*($bottom-$top);};
$label=sprintf('Calculated Verda H100 rates: one month %s dollars, three months %s, six months %s, twelve months %s per GPU-hour. Vertical axis runs from %s to %s dollars.',number_format($values[1],4),number_format($values[3],4),number_format($values[6],4),number_format($values[12],4),number_format($lo,1),number_format($hi,1));
?><figure class="editorial-chart"><div class="chart-kicker"><span>H100 COMMITMENT CURVE</span><span>USD / GPU-hour</span></div><svg viewBox="0 0 500 265" role="img" aria-label="<?php echo esc_attr($label); ?>"><?php
foreach(array($hi,($hi+$lo)/2,$lo) as $tick){$py=$y($tick);echo '<line x1="45" y1="'.esc_attr(round($py,2)).'" x2="465" y2="'.esc_attr(round($py,2)).'" stroke="#d7e2df"/><text x="12" y="'.esc_attr(round($py+5,2)).'">'.esc_html(number_format($tick,1)).'</text>';}
$points=array();foreach($terms as $t)$points[]=$xs[$t].','.round($y($values[$t]),2);
echo '<polyline points="'.esc_attr(implode(' ',$points)).'" fill="none" stroke="#176858" stroke-width="3"/>';
foreach($terms as $t){$px=$xs[$t];$py=round($y($values[$t]),2);echo '<circle cx="'.$px.'" cy="'.esc_attr($py).'" r="5" fill="#176858"/><text class="value" x="'.$px.'" y="'.esc_attr($py-14).'" text-anchor="middle">'.esc_html(tc_pricing_usd($values[$t])).'</text><text x="'.$px.'" y="240" text-anchor="middle">'.esc_html($t.' month'.($t===1?'':'s')).'</text>';}
?></svg><figcaption>Verda · <?php echo esc_html($gpu['model'].' '.$gpu['vram_gb'].'GB'); ?> · Checked <?php echo esc_html($checked); ?><br>Calculated from <a href="<?php echo esc_url($source); ?>">published base + discounts</a>. Equal term spacing; vertical axis does not start at zero. Region and start availability unverified.</figcaption></figure>
