<script>
document.documentElement.classList.add('js');
const STATES=['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','District of Columbia','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];

/* Header turns solid once the hero scrolls away */
const hdr=document.getElementById('hdr');
const onScroll=()=>hdr.classList.toggle('solid',scrollY>40);addEventListener('scroll',onScroll,{passive:true});onScroll();

/* Reveal: elements start visible; only those below the fold get the entrance */
(()=>{const els=[...document.querySelectorAll('.rv')];if(!('IntersectionObserver'in window)||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.remove('pre');io.unobserve(e.target)}}),{rootMargin:'0px 0px -8% 0px'});
 els.forEach(el=>{if(el.getBoundingClientRect().top>innerHeight){el.classList.add('pre');io.observe(el)}})})();

/* Hero: a C≡O molecule in a drifting field of invisible gas */
(()=>{const cv=document.getElementById('mol');if(!window.THREE)return;
 let r;try{r=new THREE.WebGLRenderer({canvas:cv,antialias:true,alpha:true})}catch(e){return}
 r.setPixelRatio(Math.min(devicePixelRatio,2));
 const sc=new THREE.Scene(),cam=new THREE.PerspectiveCamera(30,1,.1,100);cam.position.set(0,0,19);
 sc.add(new THREE.AmbientLight(0x6f7f90,.35));
 const key=new THREE.DirectionalLight(0xffffff,1.1);key.position.set(-4,6,8);sc.add(key);
 const rim=new THREE.DirectionalLight(0x7fb0ff,.9);rim.position.set(6,-2,-6);sc.add(rim);
 const glow=new THREE.PointLight(0xff8070,1.3,14);glow.position.set(3,0,3);sc.add(glow);
 const mol=new THREE.Group();
 const cMat=new THREE.MeshStandardMaterial({color:0x1b232c,metalness:.35,roughness:.28});
 const oMat=new THREE.MeshStandardMaterial({color:0x9e1016,metalness:.25,roughness:.38,emissive:0x1c0203,emissiveIntensity:.6});
 const bMat=new THREE.MeshStandardMaterial({color:0xe6ecf2,metalness:.15,roughness:.35,emissive:0x2a3440,emissiveIntensity:.4});
 const C=new THREE.Mesh(new THREE.SphereGeometry(1.15,64,64),cMat);C.position.x=-1.6;
 const O=new THREE.Mesh(new THREE.SphereGeometry(1.0,64,64),oMat);O.position.x=1.6;
 mol.add(C,O);
 for(let k=0;k<3;k++){const a=k/3*Math.PI*2,b=new THREE.Mesh(new THREE.CylinderGeometry(.06,.06,3.0,24),bMat);
   b.rotation.z=Math.PI/2;b.position.set(0,Math.cos(a)*.3,Math.sin(a)*.3);mol.add(b)}
 sc.add(mol);
 const N=1600,pos=new Float32Array(N*3),vel=new Float32Array(N);
 for(let i=0;i<N;i++){pos[i*3]=(Math.random()-.5)*26;pos[i*3+1]=(Math.random()-.5)*14;pos[i*3+2]=(Math.random()-.5)*12-2;vel[i]=.003+Math.random()*.008}
 const g=new THREE.BufferGeometry();g.setAttribute('position',new THREE.BufferAttribute(pos,3));
 const pts=new THREE.Points(g,new THREE.PointsMaterial({color:0x9fb3c6,size:.045,transparent:true,opacity:.55,depthWrite:false}));sc.add(pts);
 let mx=0,my=0;addEventListener('pointermove',e=>{mx=e.clientX/innerWidth-.5;my=e.clientY/innerHeight-.5},{passive:true});
 const fit=()=>{const w=cv.clientWidth,h=cv.clientHeight;r.setSize(w,h,false);cam.aspect=w/h;cam.updateProjectionMatrix();
   const wide=w>900;const half=Math.tan(cam.fov*Math.PI/360)*cam.position.z*cam.aspect;const vh=Math.tan(cam.fov*Math.PI/360)*cam.position.z;if(wide){mol.position.set(half*.62,-1.2,0);mol.scale.setScalar(Math.min(1,w/1440)*.95)}else{const px=(x,y)=>[-half+x*2*half/w,vh-y*2*vh/h];const [X,Y]=px(w-78,52);mol.position.set(X,Y,0);mol.scale.setScalar(.3)}};
 addEventListener('resize',fit);fit();
 const still=matchMedia('(prefers-reduced-motion: reduce)').matches;let t=0,vis=true;
 new IntersectionObserver(e=>{vis=e[0].isIntersecting}).observe(cv);
 (function loop(){requestAnimationFrame(loop);if(!vis)return;t+=still?0:.006;
   mol.rotation.y=t*1.6+mx*.6;mol.rotation.x=Math.sin(t*.7)*.25+my*.4;mol.rotation.z=Math.sin(t*.5)*.12;
   if(!still)for(let i=0;i<N;i++){pos[i*3]+=vel[i];pos[i*3+1]+=Math.sin(t*2+i)*.0015;if(pos[i*3]>13)pos[i*3]=-13}
   g.attributes.position.needsUpdate=true;glow.intensity=2+Math.sin(t*3)*.4;r.render(sc,cam)})();
})();

/* Instrument */
(()=>{const s=document.getElementById('ppm'),v=document.getElementById('ppmv'),n=document.getElementById('needle'),d=document.getElementById('verdict');
 const B=x=>x<9?['#6aa58a','Clean air','Typical clean air','Normal background levels are a few parts per million.']
  :x<35?['#d9c25a','Elevated','Above the outdoor 8-hour limit','Not an emergency, but a reason to find and fix the source.']
  :x<70?['#ff9a52','Silent risk','Below most alarm thresholds','Many alarms stay quiet here. Months at this level can cause headaches, fatigue and lasting harm.']
  :x<150?['#ff7a3d','Alarm range','Alarm sounds within 60–240 minutes','Leave the building and call 911 or the fire department.']
  :x<400?['#ff5a3a','Dangerous','Alarm within 10–50 minutes','Get everyone outside now. Symptoms can come on fast.']
  :['#e23d32','Life-threatening','Alarm within 4–15 minutes','Get out immediately and call 911.'];
 const u=()=>{const x=+s.value,[c,chip,h,p]=B(x);v.textContent=x;n.style.left=(x/400*100)+'%';
  d.innerHTML=`<span class="chip" style="color:${c}">${chip}</span><h3>${h}</h3><p>${p}</p>`};s.addEventListener('input',u);u()})();

/* Case review (concept: answers stay on this page) */
(()=>{const f=document.getElementById('eval'),A={};let i=0;
 const S=[{k:'safe',t:'First — is anyone still being exposed?',h:'Your safety comes before anything else.',o:[['no','No, everyone is safe now','We have left, or the source has been fixed'],['yes','Yes, or I’m not sure','Symptoms continue or the alarm keeps sounding']]},
  {k:'kind',t:'What kind of exposure was it?',h:'Both kinds can lead to a claim.',o:[['once','A single event','Often with an alarm, 911 call or hospital visit'],['ongoing','Repeated or long-term','Symptoms over weeks or months that ease away from home'],['death','Someone died','A family member lost their life']]},
  {k:'where',t:'Where did it happen?',h:'This shows who may be responsible.',o:[['rental','A home I rent',''],['hotel','A hotel or vacation rental',''],['work','At work',''],['other','Somewhere else','Own home, vehicle, boat, event']]},
  {k:'state',t:'Which state?',h:'Laws and time limits differ by state.',sel:1}];
 const bar=()=>`<div class="bar" aria-hidden="true">${S.map((_,n)=>`<i class="${n<=i?'on':''}"></i>`).join('')}</div>`;
 const draw=()=>{const s=S[i];
  if(!s){f.innerHTML=`<p class="label ember">Free case review</p>${bar()}<h3>Thank you. Your review continues here.</h3><p class="hint">On the live site, the next questions cover symptoms, medical visits and evidence, then how to reach you. Rob’s team replies within one business day.</p><p class="warn" style="border-color:var(--ember);color:var(--text)">${[A.kind,A.where,A.state].filter(Boolean).join(' · ')}</p><div class="foot"><button type="button" class="back" id="rs">Start again</button></div>`;
   document.getElementById('rs').onclick=()=>{i=0;for(const k in A)delete A[k];draw()};return}
  if(s.k==='safe'&&A.safe==='yes'){f.innerHTML=`<p class="label ember">Free case review</p>${bar()}<h3 style="color:var(--danger)">Please get to fresh air and call 911 now.</h3><p class="warn">Take everyone outside and don’t go back in until the fire department says it is safe. Seek medical care even if you start to feel better. We’ll be here when you’re safe.</p><div class="foot"><button type="button" class="back" id="ok">I’m safe now — continue →</button></div>`;
   document.getElementById('ok').onclick=()=>{A.safe='no';i=1;draw()};return}
  const body=s.sel?`<label class="sr" for="evs">State</label><select id="evs"><option value="">Choose your state</option>${STATES.map(x=>`<option${A.state===x?' selected':''}>${x}</option>`).join('')}</select>`
   :`<div class="opts">${s.o.map(([v,t,d])=>`<button type="button" class="opt" data-v="${v}" data-t="${t}" aria-pressed="${A[s.k+'v']===v}"><span class="r" aria-hidden="true"></span><span><b>${t}</b>${d?`<span>${d}</span>`:''}</span></button>`).join('')}</div>`;
  f.innerHTML=`<p class="label ember">Free case review · step ${i+1} of ${S.length}</p>${bar()}<h3>${s.t}</h3><p class="hint">${s.h}</p>${body}<div class="foot"><small>Free · confidential · no obligation</small><span style="display:flex;gap:18px;align-items:center">${i?'<button type="button" class="back" id="bk">Back</button>':''}<button class="btn btn-ember" type="submit">${i===S.length-1?'See next steps':'Continue'} <span class="arr">→</span></button></span></div>`;
  f.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{A[s.k+'v']=b.dataset.v;A[s.k]=b.dataset.t;f.querySelectorAll('.opt').forEach(x=>x.setAttribute('aria-pressed',x===b))});
  const bk=document.getElementById('bk');if(bk)bk.onclick=()=>{i--;draw()}};
 f.addEventListener('submit',e=>{e.preventDefault();const s=S[i];
  if(s.sel){const v=document.getElementById('evs').value;if(!v)return document.getElementById('evs').focus();A.state=v}
  else if(!A[s.k+'v'])return f.querySelector('.opt')?.focus();
  if(s.k==='safe'&&A.safev==='yes'){A.safe='yes';return draw()}
  i++;draw()});
 draw()})();

/* States */
(()=>{const l=document.getElementById('slist'),q=document.getElementById('stateq'),n=document.getElementById('snone');
 const d=()=>{const t=q.value.trim().toLowerCase(),m=STATES.filter(s=>s.toLowerCase().includes(t));l.innerHTML=m.map(s=>`<a href="#review">${s}</a>`).join('');n.hidden=!!m.length};q.addEventListener('input',d);d()})();
</script>
