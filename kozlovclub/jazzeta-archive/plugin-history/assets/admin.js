'use strict';
document.addEventListener('click', event => {
  const add=event.target.closest('.es-add-row');
  if(add){const box=add.closest('.es-repeater'),items=box.querySelector('.es-repeat-items');const index='n'+Date.now()+'_'+Math.floor(Math.random()*100000);const template=box.querySelector('template').innerHTML.replaceAll('__INDEX__',index);const holder=document.createElement('div');holder.innerHTML=template;const row=holder.firstElementChild;row.querySelectorAll('[id]').forEach((el,i)=>{const old=el.id;el.id=old+'-'+index+'-'+i;row.querySelectorAll('label').forEach(l=>{if(l.htmlFor===old)l.htmlFor=el.id;});});items.append(row);row.querySelector('input,textarea,select')?.focus();}
  const remove=event.target.closest('.es-remove-row');if(remove)remove.closest('.es-repeat-row').remove();
});

document.getElementById('es-choose-logo')?.addEventListener('click',()=>{if(!window.wp?.media)return;const frame=wp.media({title:'Логотип газеты',library:{type:'image'},multiple:false});frame.on('select',()=>{const chosen=frame.state().get('selection').first().toJSON();document.querySelector('[name="es_appearance[logo_id]"]').value=chosen.id;});frame.open();});
