(function(){
'use strict';
const qs=(s,c=document)=>c.querySelector(s), qsa=(s,c=document)=>Array.from(c.querySelectorAll(s));

const root=qs('#wcar-report-root');
if(root){const rid=root.dataset.reportId||'report',key='wcar_columns_'+rid,toggles=qsa('.wcar-column-toggle',root),table=qs('.wcar-report-table',root);if(toggles.length&&table){let hidden=[];try{hidden=JSON.parse(localStorage.getItem(key)||'[]');}catch(e){} const apply=()=>{toggles.forEach((t,i)=>{const hide=!t.checked;qsa('tr',table).forEach(tr=>{const cell=tr.children[i];if(cell)cell.style.display=hide?'none':'';});});};toggles.forEach((t,i)=>{t.checked=!hidden.includes(i);t.addEventListener('change',()=>{const h=toggles.map((x,j)=>x.checked?null:j).filter(x=>x!==null);localStorage.setItem(key,JSON.stringify(h));apply();});});apply();}}

const printBtn=qs('#wcar-print'); if(printBtn) printBtn.addEventListener('click',()=>window.print());
qsa('.wcar-confirm-delete').forEach(a=>a.addEventListener('click',e=>{if(!window.confirm((window.WCARAdmin&&WCARAdmin.confirmDelete)||'Delete this item?'))e.preventDefault();}));
qsa('.wcar-table-search').forEach(input=>input.addEventListener('input',()=>{const table=input.closest('.wcar-card').querySelector('table');const term=input.value.toLowerCase();qsa('tbody tr',table).forEach(tr=>tr.style.display=tr.textContent.toLowerCase().includes(term)?'':'none');}));
const dataEl=qs('#wcar-chart-data'); if(dataEl){let data={};try{data=JSON.parse(dataEl.textContent||'{}');}catch(e){} qsa('.wcar-chart').forEach(canvas=>draw(canvas,data[canvas.dataset.chart]||[],canvas.dataset.chart));}
function draw(canvas,rows,type){
    const dpr=window.devicePixelRatio||1,w=canvas.clientWidth||600,h=260;
    canvas.width=w*dpr;canvas.height=h*dpr;
    const c=canvas.getContext('2d');c.scale(dpr,dpr);c.clearRect(0,0,w,h);
    if(!rows.length){c.fillText((window.WCARAdmin&&WCARAdmin.noData)||'No data',20,30);return;}
    const labels=[],values=[];
    rows.forEach(r=>{
        const currency=r.currency?' '+r.currency:'';
        let value=0;
        if(type==='trend'){labels.push((r.period||'')+currency);value=Number(r.net_sales||0);}
        else if(type==='status'){labels.push((r.status||'')+currency);value=Number(r.orders||0);}
        else if(type==='products'){labels.push(((r.product||'').slice(0,18))+currency);value=Number(r.net_sales||0);}
        else{labels.push((r.customer_segment||'')+' '+(r.registration||'')+currency);value=Number(r.customers||0);}
        values.push(Number.isFinite(value)?value:0);
    });
    let low=0,high=0;
    values.forEach(value=>{low=Math.min(low,value);high=Math.max(high,value);});
    const range=high-low||1,pad=36,plotW=w-pad*2,plotH=h-pad*2;
    const yFor=value=>pad+((high-value)/range)*plotH;
    const zeroY=yFor(0);
    c.strokeStyle='#8c8f94';c.lineWidth=1;c.beginPath();c.moveTo(pad,pad);c.lineTo(pad,h-pad);c.moveTo(pad,zeroY);c.lineTo(w-pad,zeroY);c.stroke();
    if(type==='trend'&&values.length>1){
        c.strokeStyle='#2271b1';c.lineWidth=2;c.beginPath();
        values.forEach((value,i)=>{const x=pad+(i/(values.length-1))*plotW,y=yFor(value);i?c.lineTo(x,y):c.moveTo(x,y);});c.stroke();
    }else{
        const bw=Math.max(4,plotW/values.length*.65);
        values.forEach((value,i)=>{const x=pad+(i+.5)*plotW/values.length-bw/2,y=yFor(value);c.fillStyle='#2271b1';c.fillRect(x,Math.min(y,zeroY),bw,Math.abs(zeroY-y));});
    }
    c.fillStyle='#50575e';c.font='10px sans-serif';
    const step=Math.max(1,Math.ceil(labels.length/8));
    labels.forEach((label,i)=>{if(i%step)return;const x=pad+(i+(type==='trend'?0:.5))*plotW/Math.max(type==='trend'?labels.length-1:labels.length,1);c.save();c.translate(x,h-pad+10);c.rotate(-.35);c.fillText(label,0,0);c.restore();});
}
})();
