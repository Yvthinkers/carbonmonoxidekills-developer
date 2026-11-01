/* Homepage only: the 3D carbon monoxide molecule */
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

