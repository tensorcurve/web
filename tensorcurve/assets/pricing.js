'use strict';
const data=window.TENSORCURVE_PRICING,terms=[1,3,6,12],starts=[0,1,3,6];
const state={gpu:'H100',region:'unspecified',start:0,term:3};
const $=id=>document.getElementById(id);
const usd=(n,d=4)=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',minimumFractionDigits:d,maximumFractionDigits:d}).format(n);
const rate=(start,term)=>start===0?Number(data.gpus[state.gpu].rates[String(term)]):null;
const termName=n=>`${n} month${n===1?'':'s'}`;
function render(){
 const g=data.gpus[state.gpu],selected=rate(state.start,state.term),known=selected!==null;
 $('median').innerHTML=known?`${usd(selected)}<span class="metric-unit">/ GPU-hr</span>`:'—';
 $('selection').textContent=`${g.model} ${g.vram_gb}GB · ${g.cpus} CPUs · ${g.ram_gb}GB RAM`;
 $('discount').textContent=known?`${(Number(data.discounts[state.term])*100).toFixed(0)}%`:'—';
 $('premium').textContent='—';
 $('contract').textContent=known?usd(selected*720*state.term,2):'—';
 $('costnote').textContent=known?`1 GPU × ${720*state.term} hours · Planning only`:'No price for this future start';
 $('quote-subtitle').textContent=`${g.model} · ${termName(state.term)} · Checked ${data.checked_on}`;
 $('quote-rows').innerHTML=`<tr><td><div class="provider"><span class="provider-icon">V</span><div><a href="https://verda.com/pricing">Verda ↗</a><small>Official public tariff</small></div></div></td><td>${known?'Not guaranteed':`+${state.start} months: not published`}</td><td>${termName(state.term)}</td><td class="numeric rate">${known?usd(selected):'—'}</td><td class="numeric">${known?usd(selected*720*state.term,2):'—'}</td><td><span class="data-type">${known?'Calculated':'Missing'}</span></td></tr>`;
 renderCurve();renderMatrix();
}
function renderCurve(){
 if(state.start!==0){$('curve').innerHTML='<p class="panel-note">No forward-start prices are published in this dataset. Select “Public tariff” to view calculated commitment rates.</p>';$('curve').setAttribute('aria-label','No published price for selected future start.');return;}
 const w=600,h=250,left=54,right=24,top=20,bottom=42;
 const values=terms.map(t=>rate(0,t)),min=Math.floor(Math.min(...values)*.95*100)/100,max=Math.ceil(Math.max(...values)*1.05*100)/100;
 const x=t=>left+(t-1)/11*(w-left-right),y=r=>top+(max-r)/(max-min)*(h-top-bottom);
 let s=`<svg viewBox="0 0 ${w} ${h}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><style>text{font-family:system-ui,sans-serif;font-size:12px;fill:#61766a}</style>`;
 for(let i=0;i<5;i++){const value=min+(max-min)*i/4,py=y(value);s+=`<line x1="${left}" y1="${py}" x2="${w-right}" y2="${py}" stroke="#dce3df"/><text x="${left-10}" y="${py+4}" text-anchor="end">${value.toFixed(2)}</text>`;}
 terms.forEach(t=>s+=`<text x="${x(t)}" y="${h-17}" text-anchor="middle">${t}M</text>`);
 s+=`<polyline points="${terms.map(t=>`${x(t)},${y(rate(0,t))}`).join(' ')}" fill="none" stroke="#176858" stroke-width="3"/>`;
 terms.forEach(t=>s+=`<circle cx="${x(t)}" cy="${y(rate(0,t))}" r="${t===state.term?5:3}" fill="#176858"/>`);
 $('curve').innerHTML=s+'</svg>';$('curve').setAttribute('aria-label',`Calculated Verda ${state.gpu} commitment curve: ${terms.map(t=>`${termName(t)} ${usd(rate(0,t))}`).join(', ')} per GPU-hour. Vertical axis does not start at zero. Exact values are in the adjacent matrix.`);
}
function renderMatrix(){
 let s='<span class="axis">START ↓</span>'+terms.map(t=>`<span class="axis">${t}M</span>`).join('');
 starts.forEach(start=>{s+=`<span class="axis rowlabel">${start===0?'Tariff*':`+${start}M`}</span>`;terms.forEach(term=>{const value=rate(start,term),selected=start===state.start&&term===state.term;s+=`<button type="button" class="${selected?'selected':''}" style="background:${value===null?'#66766f':'#176858'}" data-start="${start}" data-term="${term}" aria-pressed="${selected}" aria-label="${start===0?'Public tariff, start not guaranteed':`Start plus ${start} months`}, ${termName(term)}, ${value===null?'not published in dataset':usd(value)+' per GPU-hour, calculated'}">${value===null?'—':usd(value)}</button>`;});});$('matrix').innerHTML=s;
}
function update(next){Object.assign(state,next);for(const k of ['gpu','region','start','term'])$(k).value=String(state[k]);render();}
for(const k of ['gpu','region','start','term'])$(k).addEventListener('change',e=>update({[k]:k==='start'||k==='term'?Number(e.target.value):e.target.value}));
$('matrix').addEventListener('click',event=>{const b=event.target.closest('button[data-start]');if(!b)return;update({start:Number(b.dataset.start),term:Number(b.dataset.term)});document.querySelector(`button[data-start="${state.start}"][data-term="${state.term}"]`).focus({preventScroll:true});});
render();
