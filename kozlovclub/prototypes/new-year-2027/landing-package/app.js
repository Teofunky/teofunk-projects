'use strict';
const events = [
 {id:4,time:'14:00',period:'day',stage:'The Beat',address:'Маросейка, 9/2',title:'New Year’s Jazz Brunch',subtitle:'Новогодний джазовый бранч',genre:'Джаз · Бранч',price:'3 000 ₽',priceNote:'депозит на человека',booking:true,description:'Поздний завтрак и джазовые хиты с бэндом Павла Тимофеева.',detail:'Места за столиками бронируются по телефону. Депозит — 3 000 ₽ на человека. В меню — европейские блюда и широкий выбор напитков.',url:'new-years-jazz-brunch-30'},
 {id:1,time:'18:00',period:'evening',stage:'Мясницкая, 15',address:'Вход через Lions Head Pub',title:'Celentano Show',subtitle:'Итальянский Новый год',genre:'Поп · Рок',price:'от 3 500 ₽',priceNote:'билет на концерт',booking:false,description:'Песни Челентано и рождественские хиты в исполнении Алекса Абры.',detail:'На сцене: Алекс Абра, Лев Кушнир, Юрий Псянчин, Сергей Пахомов, Михаил Алдунин и Пётр Афанасьев. Концерт проходит на сцене Unplugged, Мясницкая, 15.',url:'celentano-show-italyanskij-novyj-god'},
 {id:5,time:'19:00',period:'evening',stage:'Мансарда',address:'Маросейка, 9/2',title:'Светлана Жаворонкова',subtitle:'& Acoustic Soul',genre:'Соул · Блюз · Фолк',price:'от 3 500 ₽',priceNote:'билет на концерт',booking:false,description:'Светлана Жаворонкова и Acoustic Soul исполнят песни Боба Дилана, Джони Митчелл и рождественскую классику в акустическом звучании.',detail:'Светлана Жаворонкова — вокал; Владимир Воеводин — кахон и перкуссия; Дмитрий Честных — бас; Антон Ильин — клавишные; Николай Сарабьянов — гитара; Антон Котиков — флейта.',url:'novyj-god-so-svetlanoj-zhavoronkovoj-acoustic-soul'},
 {id:2,time:'19:30',period:'evening',stage:'The Beat',address:'Маросейка, 9/2',title:'JazzPlay & Саша Магерова',subtitle:'Новогодний вечер',genre:'Джаз · Фанк · Соул',price:'от 5 000 ₽',priceNote:'депозит на человека',booking:true,description:'Саша Магерова и JazzPlay исполнят знакомые новогодние песни, добавив к праздничным мелодиям джазовые импровизации и ритмы фанка.',detail:'Депозит на человека: у бара — 5 000 ₽; за высокими столами — 6 000 ₽; за низкими — 7 000 ₽. Бронирование заранее по телефону клуба.',url:'jazzplay-sasha-magerova-3'},
 {id:3,time:'20:00',period:'evening',stage:'Главная сцена',address:'Маросейка, 9/2',title:'Нани Ева',subtitle:'«Сказочный Новый год»',genre:'Джаз · Соул · Поп',price:'от 3 500 ₽',priceNote:'билет на концерт',booking:false,description:'Нани Ева споёт свои песни, в которых соул звучит на русском языке, а мягкий джазовый грув соседствует с мелодиями поп-музыки.',detail:'На сцене: Нани Ева — вокал; Евгений Лебедев — клавишные; Кирилл Афонин — бас-гитара; Александр Ахмаев — ударные; Влад Савенков — гитара.',url:'nani-eva-skazochnyj-novyj-god'},
 {id:0,time:'20:30',period:'evening',stage:'Мясницкая, 15',address:'Вход через Lions Head Pub',title:'Алекс Абра',subtitle:'Sinatra Christmas',genre:'Джаз · Рождественская классика',price:'от 3 500 ₽',priceNote:'билет на концерт',booking:false,description:'Алекс Абра исполнит песни Синатры и рождественскую классику в сопровождении джазового ансамбля. В программе — «Let It Snow» и «Mr Sandman».',detail:'На сцене: Алекс Абра — вокал; Лев Кушнир — клавишные; Юрий Псянчин — саксофон; Владимир Кольцов-Круглов — контрабас; Пётр Афанасьев — ударные. Мясницкая, 15.',url:'aleks-abra-sinatra-christmas-novogodnee-dzhazovoe-shou'},
 {id:6,time:'23:00',period:'night',stage:'Мансарда',address:'Маросейка, 9/2',title:'DJ KOSHL',subtitle:'Новогодняя ночь',genre:'Диско · Фанк · Соул',price:'10 000 ₽',priceNote:'с человека · до 04:00',booking:true,description:'Встречаем Новый год на Мансарде под диско, фанк и джаз-хаус KOSHL. За столиками подадут праздничный ужин, музыка будет звучать до 04:00.',detail:'За столиком — новогодний сет на выбор. У бара — депозит 10 000 ₽ на кухню и напитки. Бронируйте места заранее по телефону.',image:'dj-koshl-2026.png',bookingPage:true,url:'novogodnyaya-noch-s-dj-koshl-2'},
 {id:7,time:'23:00',period:'night',stage:'The Beat',address:'Маросейка, 9/2',title:'DJ Tower',subtitle:'New Year Power',genre:'Диско · Хаус',price:'10 000 ₽',priceNote:'с человека · до 04:00',booking:true,description:'Алексей Платонов из Moscow Disco Club проведёт новогоднюю ночь за пультом The Beat. В программе — диско и хаус до 04:00, за столиками — гастрономический сет.',detail:'За столиком — новогодний сет на выбор. У бара — депозит 10 000 ₽ на кухню и напитки. Бронируйте места заранее по телефону.',image:'dj-tower.jpeg',url:null}
];
const grid = document.getElementById('events');
grid.innerHTML = events.map((e,i)=>`<article class="event-card" data-period="${e.period}" data-stage="${e.stage}" aria-labelledby="event-${e.id}-title"><a class="event-image" style="display:block" href="${e.url?`https://kozlovclub.ru/event/${e.url}/`:'#night'}" ${e.url?'target="_blank" rel="noopener"':''} aria-label="${e.title}: подробнее о программе"><img src="assets/${e.image||`event-${e.id}.webp`}" width="800" height="600" loading="lazy" alt="${e.title}"><span class="time">${e.time}</span></a><div class="event-meta"><span>${e.stage}</span><span>${e.genre}</span></div><h3 id="event-${e.id}-title">${e.title}</h3><p class="event-subtitle">${e.subtitle}</p><p class="event-desc">${e.description}</p><div class="event-bottom"><div class="event-price">${e.price}<small>${e.priceNote}</small></div><a class="button" href="${e.booking&&!e.bookingPage?'tel:+74952961200':'https://kozlovclub.ru/event/'+e.url+'/'}" ${e.booking&&!e.bookingPage?'':'target="_blank" rel="noopener"'} aria-label="${e.booking?(e.bookingPage?'Забронировать место':'Позвонить для бронирования'):'Купить билет'}: ${e.title}">${e.booking?'Забронировать':'Купить билет'} <span>↗</span></a></div><details class="event-detail"><summary>${e.booking?'Депозит и подробности':'О концерте и площадке'}</summary><p>${e.detail}<br><br>${e.address}. Начало 31 декабря 2026 в ${e.time}.<br><a href="${e.url?`https://kozlovclub.ru/event/${e.url}/`:'#night'}" ${e.url?'target="_blank" rel="noopener"':''}>Подробнее о программе ↗</a></p></details></article>`).join('');
let selectedPeriod='all';
const tabs=[...document.querySelectorAll('[data-period]')].filter(e=>e.tagName==='BUTTON');
function filterEvents(){const stage=document.getElementById('stage').value;let count=0;document.querySelectorAll('.event-card').forEach(card=>{const visible=(selectedPeriod==='all'||card.dataset.period===selectedPeriod)&&(stage==='all'||card.dataset.stage===stage);card.hidden=!visible;if(visible)count++});document.getElementById('result-count').textContent=`${count} ${count===1?'событие':count>1&&count<5?'события':'событий'} · 31 декабря`;document.getElementById('empty').hidden=count>0;requestAnimationFrame(alignCards);}
tabs.forEach(tab=>tab.addEventListener('click',()=>{selectedPeriod=tab.dataset.period;tabs.forEach(b=>{b.classList.toggle('active',b===tab);b.setAttribute('aria-pressed',String(b===tab))});filterEvents()}));
document.getElementById('stage').addEventListener('change',filterEvents);
document.getElementById('reset').addEventListener('click',()=>{selectedPeriod='all';document.getElementById('stage').value='all';tabs.forEach(b=>{const active=b.dataset.period==='all';b.classList.toggle('active',active);b.setAttribute('aria-pressed',String(active))});filterEvents()});
// Moscow midnight is an explicit instant, independent of the visitor's timezone.
const newYearAt=Date.parse('2027-01-01T00:00:00+03:00');
function countdownParts(now){const total=Math.max(0,Math.floor((newYearAt-now)/60000));return {days:Math.floor(total/1440),hours:Math.floor(total/60)%24,minutes:total%60,finished:now>=newYearAt}}
function plural(n,one,few,many){return n%10===1&&n%100!==11?one:n%10>=2&&n%10<=4&&(n%100<12||n%100>14)?few:many}
function updateCountdown(){const t=countdownParts(Date.now());document.getElementById('count-days').textContent=String(t.days).padStart(2,'0');document.getElementById('count-hours').textContent=String(t.hours).padStart(2,'0');document.getElementById('count-minutes').textContent=String(t.minutes).padStart(2,'0');document.getElementById('label-days').textContent=plural(t.days,'день','дня','дней');document.getElementById('label-hours').textContent=plural(t.hours,'час','часа','часов');document.getElementById('label-minutes').textContent=plural(t.minutes,'минута','минуты','минут');document.getElementById('countdown-title').textContent=t.finished?'С Новым годом!':'До Нового года осталось'}
updateCountdown();setInterval(updateCountdown,1000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)updateCountdown()});
// Rigid image motion only: translation, uniform scale and in-plane rotation. No perspective distortion.
const reduceMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
const track=document.querySelector('.hero-track'),scene=document.querySelector('.hero-sticky');
let paused=reduceMotion.matches;
const motionButton=document.getElementById('motion');
function clamp(v,a=0,b=1){return Math.max(a,Math.min(b,v))}
function updateButton(){motionButton.setAttribute('aria-pressed',String(paused));motionButton.setAttribute('aria-label',paused?'Включить анимацию':'Остановить анимацию');motionButton.innerHTML=paused?'▶ <span>Анимация</span>':'Ⅱ <span>Анимация</span>';scene.classList.toggle('motion-paused',paused)}
function onScroll(){
 const r=track.getBoundingClientRect(),range=track.offsetHeight-scene.offsetHeight;
 if(paused&&!reduceMotion.matches)return;
 const raw=reduceMotion.matches?0:clamp(-r.top/Math.max(1,range));
 const p=raw*raw*(3-2*raw),s=clamp((p-.25)/.6),mobile=innerWidth<=600;
 scene.style.setProperty('--intro-opacity',String(1-clamp(p*2.5)));
 scene.style.setProperty('--intro-visibility',p>.4?'hidden':'visible');
 scene.style.setProperty('--intro-y',`${-p*45}px`);
 scene.style.setProperty('--tree-opacity',String(1-clamp(p*1.8)));
 scene.style.setProperty('--tree-y',`${-p*(mobile?85:120)}px`);
 scene.style.setProperty('--toy-x',`${p*innerWidth*(mobile?.09:.025)}px`);
 scene.style.setProperty('--toy-y',`${p*(mobile?165:10)}px`);
 scene.style.setProperty('--toy-scale',String(1+p*(mobile?.16:.24)));
 scene.style.setProperty('--toy-tilt',`${-3+p*8+Math.sin(p*Math.PI)*1.2}deg`);
 scene.style.setProperty('--second-opacity',String(s));
 scene.style.setProperty('--second-visibility',s>.02?'visible':'hidden');
 scene.style.setProperty('--second-y',`${(1-s)*35}px`);
}
motionButton.addEventListener('click',()=>{paused=!paused;updateButton();onScroll()});reduceMotion.addEventListener('change',()=>{paused=reduceMotion.matches;updateButton();onScroll()});let scrollQueued=false;addEventListener('scroll',()=>{if(!scrollQueued){scrollQueued=true;requestAnimationFrame(()=>{onScroll();scrollQueued=false})}},{passive:true});addEventListener('resize',onScroll);updateButton();onScroll();

// Equalise each content row within a visible pair, including after filtering.
function alignCards(){
 const cards=[...document.querySelectorAll('.event-card:not([hidden])')];
 const selectors=['.event-meta','h3','.event-subtitle','.event-desc','.event-detail summary'];
 cards.forEach(c=>selectors.forEach(s=>c.querySelector(s).style.minHeight=''));
 for(let i=0;i<cards.length;i+=2){const pair=cards.slice(i,i+2);selectors.forEach(s=>{const h=Math.max(...pair.map(c=>c.querySelector(s).getBoundingClientRect().height));pair.forEach(c=>c.querySelector(s).style.minHeight=h+'px')})}
}
document.fonts.ready.then(alignCards);addEventListener('resize',alignCards);alignCards();
const photoEls=[...document.querySelectorAll('.event-image,.set-photo')],table=document.querySelector('.dinner-table');
function ambientScroll(){
 const still=reduceMotion.matches||paused;
 photoEls.forEach(el=>{const r=el.getBoundingClientRect();if(r.bottom<0||r.top>innerHeight)return;const p=clamp((innerHeight-r.top)/(innerHeight+r.height));el.style.setProperty('--photo-scale',still?'1':String(1+.12*p));el.style.setProperty('--photo-y',still?'0px':(p-.5)*12+'px')});
 const r=table.getBoundingClientRect(),p=still?1:clamp((innerHeight-r.top)/(innerHeight*.7));table.style.setProperty('--dish-progress',p);
}
let ambientQueued=false;addEventListener('scroll',()=>{if(!ambientQueued){ambientQueued=true;requestAnimationFrame(()=>{ambientScroll();ambientQueued=false})}},{passive:true});addEventListener('resize',ambientScroll);motionButton.addEventListener('click',ambientScroll);ambientScroll();
