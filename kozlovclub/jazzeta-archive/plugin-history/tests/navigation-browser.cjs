/* Shared header and reader sizing: disposable localhost only. */
const {chromium}=require('playwright');
const fs=require('fs'),cp=require('child_process'),path=require('path');
const root=process.env.ES_TEST_ROOT,base=process.env.ES_TEST_BASE_URL,output=process.env.ES_TEST_OUTPUT;
if(!root||!output||process.env.ES_TEST_ALLOW_RESET!=='1'||new URL(base).hostname!=='127.0.0.1')throw Error('Disposable localhost settings required');
const php=code=>cp.execFileSync(process.env.ES_TEST_PHP,['-r',`require ${JSON.stringify(process.env.ES_TEST_BOOTSTRAP)};if(wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1')exit(2);${code}`],{encoding:'utf8'});
let passed=0;const ok=(v,n)=>{if(!v)throw Error('FAIL '+n);passed++;console.log('PASS '+n);};
const setup=JSON.parse(php(`$menu=wp_get_nav_menu_object('main-menu');if($menu)throw new Exception('Test requires no existing main-menu');$id=wp_create_nav_menu('main-menu');$p=wp_update_nav_menu_item($id,0,['menu-item-title'=>'О клубе','menu-item-url'=>'#','menu-item-status'=>'publish']);wp_update_nav_menu_item($id,0,['menu-item-title'=>'Для прессы','menu-item-url'=>home_url('/?page=press'),'menu-item-parent-id'=>$p,'menu-item-status'=>'publish']);foreach(['3D тур','FAQ','Меню','Контакты'] as $title)wp_update_nav_menu_item($id,0,['menu-item-title'=>$title,'menu-item-url'=>home_url('/?section='.rawurlencode($title)),'menu-item-status'=>'publish']);echo wp_json_encode(['menu'=>$id,'locations'=>get_theme_mod('nav_menu_locations'),'logo'=>get_theme_file_path('assets/images/logo-kozlovclub.png')]);`));
const logo=path.join(root,'runtime/release033/logo-kozlovclub.png');let wroteLogo=false;
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.ES_TEST_CHROME,headless:true,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(String(e)));
  await page.goto(base+'/gazeta/2026-10-20/');
  ok(await page.locator('.es-club-logo').isVisible(),'club brand fallback visible without theme logo');
  ok(await page.locator('.es-club-menu>li').count()===5,'existing named WordPress menu reused without assigning theme location');
  ok(await page.locator('.es-club-home').getAttribute('href')===base+'/','prominent home link targets main site');
  ok(await page.locator('.es-footer-home').getAttribute('href')===base+'/','footer provides another route to main site');
  await page.getByText('О клубе',{exact:true}).click();ok(await page.getByRole('link',{name:'Для прессы',exact:true}).isVisible(),'club submenu opens with native details');
  await page.keyboard.press('Escape');ok(!(await page.getByRole('link',{name:'Для прессы',exact:true}).isVisible()),'Escape closes club submenu');
  if(fs.existsSync(logo)&&!fs.existsSync(setup.logo)){fs.mkdirSync(path.dirname(setup.logo),{recursive:true});fs.copyFileSync(logo,setup.logo);wroteLogo=true;await page.reload();ok(await page.locator('.es-club-logo img').evaluate(e=>e.complete&&e.naturalWidth>0),'existing theme logo loads');}
  const font=selector=>page.locator(selector).first().evaluate(e=>parseFloat(getComputedStyle(e).fontSize));
  const initialLead=await font('.hero-dek'),initialHeadline=await font('.hero h2'),initialHeader=await font('.es-club-home');
  await page.getByRole('button',{name:'Увеличить размер текста',exact:true}).click();
  ok((await font('.hero-dek'))>initialLead&&(await font('.hero h2'))>initialHeadline,'A+ enlarges front-page lead and headline');
  ok(await page.locator('#resetFont').innerText()==='110%','visible size indicator reports 110 percent');
  ok(await font('.es-club-home')===initialHeader,'reader size leaves club navigation stable');
  await page.reload();ok(await page.locator('#resetFont').innerText()==='110%'&&(await font('.hero-dek'))>initialLead,'reader preference survives reload');
  await page.getByRole('button',{name:'Уменьшить размер текста',exact:true}).click();ok(Math.abs((await font('.hero-dek'))-initialLead)<.1,'A- restores front-page text size');
  const article=await page.locator('.hero h2 a').getAttribute('href');await page.goto(article);ok(await page.locator('.dlg-body p').count()>=2&&await page.locator('.dlg-body li').count()===2,'editor round trip preserves paragraphs alongside a list');const body=await font('.dlg-body p'),intro=await font('.dlg-intro');
  await page.locator('#bigger').click();ok((await font('.dlg-body p'))>body&&(await font('.dlg-intro'))>intro,'A+ enlarges full article and introduction');
  await page.locator('#resetFont').click();ok(Math.abs((await font('.dlg-body p'))-body)<.1,'100 percent button resets article reading size');
  await page.goto(base+'/gazeta/2026-10-20/');
  for(const width of [320,390,768,1440]){
   await page.setViewportSize({width,height:1000});await page.locator('#resetFont').click();
   for(let i=0;i<4;i++)await page.locator('#bigger').click();
   ok(await page.locator('#bigger').isDisabled()&&await page.locator('#resetFont').innerText()==='140%','upper size limit and feedback '+width+'px');
   ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no overflow at maximum font size '+width+'px');
   const box=await page.locator('.es-club-home').boundingBox();ok(box&&box.x>=0&&box.x+box.width<=width+1&&box.height>=44,'home button visible with touch target '+width+'px');
  }
  await page.setViewportSize({width:1440,height:1000});await page.locator('#resetFont').click();await page.screenshot({path:path.join(output,'header-desktop.png')});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(output,'header-mobile.png')});
  await page.locator('#bigger').click();await page.locator('#bigger').click();await page.screenshot({path:path.join(output,'reader-mobile-120.png')});
  await page.locator('#resetFont').click();await page.locator('#smaller').click();ok(await page.locator('#smaller').isDisabled()&&await page.locator('#resetFont').innerText()==='90%','lower size limit is explicit');
  const nojs=await browser.newContext({javaScriptEnabled:false});const staticPage=await nojs.newPage();await staticPage.goto(base+'/gazeta/2026-10-20/');await staticPage.getByText('О клубе',{exact:true}).click();ok(await staticPage.getByRole('link',{name:'Для прессы',exact:true}).isVisible(),'shared menu works without JavaScript');
  ok(errors.length===0,'no JavaScript errors in navigation or reading controls');
  console.log(`TOTAL ${passed} navigation/reader assertions passed`);
 }finally{await browser.close();if(wroteLogo)fs.unlinkSync(setup.logo);php(`wp_delete_nav_menu(${Number(setup.menu)});`);}
})().catch(e=>{console.error(e);process.exit(1)});
