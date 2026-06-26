/* Rayetun MediaNest — Lightweight Lightbox (no dependencies) */
(function(){
	'use strict';
	var lb=null, imgs=[], idx=0;

	function open(galleryId, clickedHref){
		var triggers=document.querySelectorAll('.rmn-lightbox-trigger[data-gallery="'+galleryId+'"]');
		imgs=Array.from(triggers).map(function(t){
			return {src:t.getAttribute('href'),caption:t.getAttribute('data-caption')||''};
		});
		idx=imgs.findIndex(function(im){return im.src===clickedHref;});
		if(idx<0)idx=0;

		if(!lb)build();
		document.body.style.overflow='hidden';
		document.body.appendChild(lb);
		setTimeout(function(){lb.classList.add('is-open');},10);
		show(idx);
		document.addEventListener('keydown',onKey);
	}

	function build(){
		lb=document.createElement('div');
		lb.className='rmn-lb';
		lb.setAttribute('role','dialog');
		lb.setAttribute('aria-modal','true');

		var inner=document.createElement('div');
		inner.className='rmn-lb-inner';

		var img=document.createElement('img');
		img.className='rmn-lb-img';
		img.alt='';
		inner.appendChild(img);

		var cap=document.createElement('div');
		cap.className='rmn-lb-caption';
		inner.appendChild(cap);
		lb.appendChild(inner);

		var close=document.createElement('button');
		close.className='rmn-lb-close';
		close.innerHTML='&times;';
		close.setAttribute('aria-label','Close');
		close.addEventListener('click',function(e){e.stopPropagation();closeLb();});
		lb.appendChild(close);

		var prev=document.createElement('button');
		prev.className='rmn-lb-nav rmn-lb-prev';
		prev.innerHTML='&#8249;';
		prev.setAttribute('aria-label','Previous');
		prev.addEventListener('click',function(e){e.stopPropagation();go(-1);});
		lb.appendChild(prev);

		var next=document.createElement('button');
		next.className='rmn-lb-nav rmn-lb-next';
		next.innerHTML='&#8250;';
		next.setAttribute('aria-label','Next');
		next.addEventListener('click',function(e){e.stopPropagation();go(1);});
		lb.appendChild(next);

		var dots=document.createElement('div');
		dots.className='rmn-lb-dots';
		lb.appendChild(dots);

		lb.addEventListener('click',function(e){if(e.target===lb)closeLb();});
		lb._img=img;lb._cap=cap;lb._dots=dots;lb._prev=prev;lb._next=next;
	}

	function show(i){
		idx=(i+imgs.length)%imgs.length;
		lb._img.src=imgs[idx].src;
		lb._cap.textContent=imgs[idx].caption;
		lb._cap.style.display=imgs[idx].caption?'':'none';

		/* nav visibility */
		lb._prev.style.display=imgs.length>1?'':'none';
		lb._next.style.display=imgs.length>1?'':'none';

		/* dots (max 10) */
		if(imgs.length>1&&imgs.length<=20){
			lb._dots.innerHTML='';
			imgs.forEach(function(_,j){
				var d=document.createElement('span');
				d.className='rmn-lb-dot'+(j===idx?' is-active':'');
				d.addEventListener('click',function(e){e.stopPropagation();show(j);});
				lb._dots.appendChild(d);
			});
		}
	}

	function go(dir){show(idx+dir);}

	function closeLb(){
		lb.classList.remove('is-open');
		document.body.style.overflow='';
		document.removeEventListener('keydown',onKey);
		setTimeout(function(){if(lb.parentNode)lb.parentNode.removeChild(lb);},260);
	}

	function onKey(e){
		if(e.key==='Escape')closeLb();
		else if(e.key==='ArrowRight')go(1);
		else if(e.key==='ArrowLeft')go(-1);
	}

	document.addEventListener('click',function(e){
		var trigger=e.target.closest&&e.target.closest('.rmn-lightbox-trigger');
		if(!trigger)return;
		e.preventDefault();
		open(trigger.getAttribute('data-gallery'),trigger.getAttribute('href'));
	});
})();
