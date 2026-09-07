export function normalizeSite(value, local=false) {
  const u=new URL(value.trim());
  if(u.username||u.password||u.search||u.hash) throw Error('Inserisci solo l’indirizzo del sito, senza credenziali, query o frammenti.');
  if(u.protocol!=='https:' && !(local&&u.protocol==='http:'&&['localhost','127.0.0.1','[::1]'].includes(u.hostname))) throw Error('Usa HTTPS oppure abilita ambiente locale per un indirizzo loopback.');
  return u.href.replace(/\/+$/,'');
}
export function manifestFor(site, local=false) {
  const u=new URL(normalizeSite(site,local));
  return {name:'Figma Elementor Multimodal',id:'fem-private-import',api:'1.0.0',editorType:['figma'],main:'code.js',ui:'ui.html',documentAccess:'dynamic-page',
    networkAccess:{allowedDomains:u.protocol==='https:'?[u.origin]:['none'],...(u.protocol==='http:'?{devAllowedDomains:[u.origin]}:{})}};
}
