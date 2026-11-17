'use strict';
document.addEventListener('DOMContentLoaded',()=>{
    const plan=document.querySelector('#registration-plan'),months=document.querySelector('#registration-months');
    function periods(){if(!plan||!months)return;const option=plan.selectedOptions[0],old=months.value;months.innerHTML=`${Number(option.dataset.monthly)>0?'<option value="1">Bulanan</option>':''}${Number(option.dataset.yearly)>0?'<option value="12">Tahunan</option>':''}`;if([...months.options].some(o=>o.value===old))months.value=old;window.SearchSelect?.enhance();}
    plan?.addEventListener('change',periods);periods();window.SearchSelect?.enhance();
    document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
});
