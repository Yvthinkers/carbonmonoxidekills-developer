/* Carbon Monoxide Kills — shared behaviour for every page */
document.documentElement.classList.add('js');
const STATES=['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','District of Columbia','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];
const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];

/* Header turns solid once the page scrolls */
(()=>{const h=$('#hdr');if(!h)return;const f=()=>h.classList.toggle('solid',scrollY>40||h.dataset.solid==='1');addEventListener('scroll',f,{passive:true});f()})();

/* Desktop menu: hover on mouse, click/Enter on touch and keyboard, Escape closes */
(()=>{const items=$$('.ni.has-mega'),scrim=$('#scrim');if(!items.length)return;
 let t;const fine=matchMedia('(hover:hover) and (pointer:fine)').matches;
 const close=(except)=>{items.forEach(i=>{if(i!==except){i.classList.remove('open');$('.nl',i).setAttribute('aria-expanded','false')}});if(!except&&scrim)scrim.classList.remove('on')};
 const open=i=>{close(i);i.classList.add('open');$('.nl',i).setAttribute('aria-expanded','true');scrim&&scrim.classList.add('on')};
 items.forEach(i=>{const b=$('.nl',i);
  b.addEventListener('click',()=>i.classList.contains('open')?close():open(i));
  if(fine){i.addEventListener('mouseenter',()=>{clearTimeout(t);t=setTimeout(()=>open(i),90)});i.addEventListener('mouseleave',()=>{clearTimeout(t);t=setTimeout(()=>close(),180)})}
  i.addEventListener('focusout',e=>{if(!i.contains(e.relatedTarget))setTimeout(()=>{if(!i.contains(document.activeElement))i.classList.remove('open'),b.setAttribute('aria-expanded','false'),!$('.ni.open')&&scrim&&scrim.classList.remove('on')},0)})});
 addEventListener('keydown',e=>{if(e.key==='Escape'){const o=$('.ni.open');close();o&&$('.nl',o).focus()}});
 scrim&&scrim.addEventListener('click',()=>close());
 addEventListener('scroll',()=>{if(!fine)close()},{passive:true})})();

/* Mobile drawer */
(()=>{const d=$('#drawer'),b=$('.burger');if(!d||!b)return;
 const set=on=>{d.classList.toggle('on',on);d.toggleAttribute('inert',!on);d.setAttribute('aria-hidden',!on);b.setAttribute('aria-expanded',on);document.body.classList.toggle('locked',on);if(on)$('.x',d).focus();else b.focus()};
 d.setAttribute('inert','');b.addEventListener('click',()=>set(true));$('.x',d).addEventListener('click',()=>set(false));
 addEventListener('keydown',e=>{if(e.key==='Escape'&&d.classList.contains('on'))set(false)});
 addEventListener('resize',()=>{if(innerWidth>1180&&d.classList.contains('on'))set(false)})})();

/* Reveal: content is visible at rest; only elements below the fold get the entrance */
(()=>{const els=$$('.rv');if(!('IntersectionObserver'in window)||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.remove('pre');io.unobserve(e.target)}}),{rootMargin:'0px 0px -8% 0px'});
 els.forEach(el=>{if(el.getBoundingClientRect().top>innerHeight){el.classList.add('pre');io.observe(el)}})})();

/* Guide pages: highlight the section being read */
(()=>{const links=$$('.toc a');if(!links.length||!('IntersectionObserver'in window))return;
 const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)links.forEach(l=>l.classList.toggle('on',l.getAttribute('href')==='#'+e.target.id))}),{rootMargin:'-20% 0px -70% 0px'});
 links.forEach(a=>{const s=document.querySelector(a.getAttribute('href'));s&&io.observe(s)})})();

/* CO level instrument */
(()=>{const s=$('#ppm');if(!s)return;const v=$('#ppmv'),n=$('#needle'),d=$('#verdict');
 const B=x=>x<9?['#6aa58a','Clean air','Typical clean air','Normal background levels are a few parts per million.']
  :x<35?['#d9c25a','Elevated','Above the outdoor 8-hour limit','Not an emergency, but a reason to find and fix the source.']
  :x<70?['#ff9a52','Silent risk','Below most alarm thresholds','Many alarms stay quiet here. Months at this level can cause headaches, fatigue and lasting harm.']
  :x<150?['#f0453c','Alarm range','Alarm sounds within 60–240 minutes','Leave the building and call 911 or the fire department.']
  :x<400?['#ff5a3a','Dangerous','Alarm within 10–50 minutes','Get everyone outside now. Symptoms can come on fast.']
  :['#e23d32','Life-threatening','Alarm within 4–15 minutes','Get out immediately and call 911.'];
 const u=()=>{const x=+s.value,[c,chip,h,p]=B(x);v.textContent=x;n.style.left=(x/400*100)+'%';
  d.innerHTML=`<span class="chip" style="color:${c}">${chip}</span><h3>${h}</h3><p>${p}</p>`};s.addEventListener('input',u);u()})();

/* State lists: the links are in the HTML (one page per state); typing filters them */
(()=>{const l=$('#slist');if(!l)return;const q=$('#stateq'),n=$('#snone');
 const d=()=>{const t=(q?q.value:'').trim().toLowerCase();let k=0;
  $$('a',l).forEach(a=>{const m=a.textContent.toLowerCase().includes(t);a.hidden=!m;if(m)k++});if(n)n.hidden=!!k};
 q&&q.addEventListener('input',d);d()})();

/* Case review (concept: answers stay in this browser) */
(()=>{const f=$('#eval');if(!f)return;const A={};let i=0;
 const pre=new URLSearchParams(location.search).get('state');if(pre&&STATES.includes(pre))A.state=pre;
 const S=[{k:'safe',t:'First — is anyone still being exposed?',h:'Your safety comes before anything else.',o:[['no','No, everyone is safe now','We have left, or the source has been fixed'],['yes','Yes, or I’m not sure','Symptoms continue or the alarm keeps sounding']]},
  {k:'kind',t:'What kind of exposure was it?',h:'Both kinds can lead to a claim.',o:[['once','A single event','Often with an alarm, 911 call or hospital visit'],['ongoing','Repeated or long-term','Symptoms over weeks or months that ease away from home'],['death','Someone died','A family member lost their life']]},
  {k:'where',t:'Where did it happen?',h:'This shows who may be responsible.',o:[['rental','A home I rent',''],['hotel','A hotel or vacation rental',''],['work','At work',''],['other','Somewhere else','Own home, vehicle, boat, event']]},
  {k:'state',t:'Which state?',h:'Laws and time limits differ by state.',sel:1}];
 const bar=()=>`<div class="bar" aria-hidden="true">${S.map((_,n)=>`<i class="${n<=i?'on':''}"></i>`).join('')}</div>`;
 const draw=()=>{const s=S[i];
  if(!s){f.innerHTML=`<p class="label ember">Free case review</p>${bar()}<h3>Thank you. Your review continues here.</h3><p class="hint">On the live site, the next questions cover symptoms, medical visits and evidence, then how to reach you. Rob’s team replies within one business day.</p><p class="warn" style="border-color:var(--ember);color:var(--text)">${[A.kind,A.where,A.state].filter(Boolean).join(' · ')}</p><div class="foot"><button type="button" class="back" id="rs">Start again</button></div>`;
   $('#rs').onclick=()=>{i=0;for(const k in A)delete A[k];draw()};return}
  if(s.k==='safe'&&A.safe==='yes'){f.innerHTML=`<p class="label ember">Free case review</p>${bar()}<h3 style="color:var(--danger)">Please get to fresh air and call 911 now.</h3><p class="warn">Take everyone outside and don’t go back in until the fire department says it is safe. Seek medical care even if you start to feel better. We’ll be here when you’re safe.</p><div class="foot"><button type="button" class="back" id="ok">I’m safe now — continue →</button></div>`;
   $('#ok').onclick=()=>{A.safe='no';i=1;draw()};return}
  const body=s.sel?`<label class="sr" for="evs">State</label><select id="evs"><option value="">Choose your state</option>${STATES.map(x=>`<option${A.state===x?' selected':''}>${x}</option>`).join('')}</select>`
   :`<div class="opts">${s.o.map(([v,t,d])=>`<button type="button" class="opt" data-v="${v}" data-t="${t}" aria-pressed="${A[s.k+'v']===v}"><span class="r" aria-hidden="true"></span><span><b>${t}</b>${d?`<span>${d}</span>`:''}</span></button>`).join('')}</div>`;
  f.innerHTML=`<p class="label ember">Free case review · step ${i+1} of ${S.length}</p>${bar()}<h3>${s.t}</h3><p class="hint">${s.h}</p>${body}<div class="foot"><small>Free · confidential · no obligation</small><span style="display:flex;gap:18px;align-items:center">${i?'<button type="button" class="back" id="bk">Back</button>':''}<button class="btn btn-ember" type="submit">${i===S.length-1?'See next steps':'Continue'} <span class="arr">→</span></button></span></div>`;
  $$('.opt',f).forEach(b=>b.onclick=()=>{A[s.k+'v']=b.dataset.v;A[s.k]=b.dataset.t;$$('.opt',f).forEach(x=>x.setAttribute('aria-pressed',x===b))});
  const bk=$('#bk');if(bk)bk.onclick=()=>{i--;draw()}};
 f.addEventListener('submit',e=>{e.preventDefault();const s=S[i];
  if(s.sel){const v=$('#evs').value;if(!v)return $('#evs').focus();A.state=v}
  else if(!A[s.k+'v'])return $('.opt',f)?.focus();
  if(s.k==='safe'&&A.safev==='yes'){A.safe='yes';return draw()}
  i++;draw()});
 draw()})();

/* Enquiry forms (concept: nothing is sent) */
$$('form.enquiry').forEach(f=>{const st=$('select[name=state]',f);if(st)st.innerHTML='<option value="">Choose a state</option>'+STATES.map(s=>`<option>${s}</option>`).join('');
 f.addEventListener('submit',e=>{e.preventDefault();const bad=$$('[required]',f).find(x=>!x.value.trim());if(bad)return bad.focus();
  f.innerHTML=`<p class="label ember">Received</p><h2 style="margin-top:14px">Thank you.</h2><p class="done" style="margin-top:20px">On the live site this goes straight to Rob’s team, who usually reply the same day and always within 24 hours.</p>`})});

/* Case review form (posts to /case-review.php): conditional fields, server error message, spam timer */
(()=>{const f=$('#case-form');if(!f)return;
 $('#cf-started',f).value=Date.now();
 const by=$('#cf-by',f),other=$('#cf-by-other-wrap',f),blood=$$('[data-blood]',f);
 const sync=()=>{other.hidden=by.value!=='Other';const yes=($('input[name=blood_test]:checked',f)||{}).value==='Yes';blood.forEach(b=>b.hidden=!yes)};
 by.addEventListener('change',sync);$$('input[name=blood_test]',f).forEach(r=>r.addEventListener('change',sync));sync();
 const msg={missing:'Please fill in all required fields.',consent:'Please tick the box to agree that we may contact you.',phone:'Please check the telephone number.',
  date:'Please check the date of the incident.',ppm:'The reading should be a number of parts per million.',rate:'Too many forms sent from this connection. Please call us on 1-888-414-8680.',
  server:'Sorry, the form could not be sent. Please call us on 1-888-414-8680.'};
 const err=new URLSearchParams(location.search).get('error'),box=$('#cf-error',f);
 if(err&&msg[err]){box.textContent=msg[err];box.hidden=false}
 f.addEventListener('submit',e=>{if(!f.checkValidity()){e.preventDefault();f.reportValidity()}})})();
