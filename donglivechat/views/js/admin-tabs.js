(function(){
 function init(){
  var root=document.getElementById('dongchat-admin');if(!root)return;
  var buttons=Array.prototype.slice.call(root.querySelectorAll('[data-dc-tab]'));
  var panels=Array.prototype.slice.call(root.querySelectorAll('[data-dc-panel]'));
  function show(name){
   buttons.forEach(function(b){var active=b.getAttribute('data-dc-tab')===name;b.classList.toggle('dc-tab-active',active);b.setAttribute('aria-selected',String(active));});
   panels.forEach(function(p){var active=p.getAttribute('data-dc-panel')===name;p.hidden=!active;p.style.display=active?'block':'none';});
   if(name==='analytics'&&typeof window.dongchatLoadHistory==='function')window.dongchatLoadHistory();
  }
  buttons.forEach(function(b){if(b.tagName.toLowerCase()==='a')return; b.addEventListener('click',function(e){e.preventDefault();show(b.getAttribute('data-dc-tab'));});});
  show(root.getAttribute('data-open-tab')||'live');
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
